<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class RawMaterialCsvCatalogExportWorkflow
{
    public function __construct(
        private readonly RawMaterialAuthorization $authorization,
        private readonly AuthorizationMutationContext $mutationContexts,
    ) {}

    public function create(User $actor, Location $context): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $ttlMinutes = max(1, (int) config('raw_material_csv.export_ttl_minutes'));
        $this->pruneExpiredExports($ttlMinutes);

        $exportId = Str::uuid()->toString();
        $maxRows = max(0, (int) config('raw_material_csv.export_max_rows'));
        $materials = DB::table('raw_materials as materials')
            ->join('raw_material_revisions as revisions', function ($join): void {
                $join->on('revisions.id', '=', 'materials.approved_revision_id')
                    ->on('revisions.raw_material_id', '=', 'materials.id')
                    ->on('revisions.organization_id', '=', 'materials.organization_id');
            })
            ->where('materials.organization_id', $actor->organization_id)
            ->where('materials.lifecycle_state', 'ACTIVE')
            ->orderBy('materials.code')
            ->limit($maxRows + 1)
            ->get(['materials.code as ingredient_id', 'revisions.name as ingredient_name']);

        if ($materials->count() > $maxRows) {
            $this->recordAudit($actor, $context, $exportId, 'CSV_CATALOG_EXPORT_REJECTED', 'REJECTED', [
                'reason_code' => 'ROW_LIMIT_EXCEEDED',
                'row_limit' => $maxRows,
            ]);
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_EXPORT_ROW_LIMIT',
                'Eksport katalogu przekracza skonfigurowany limit wierszy.',
                413,
                ['row_limit' => $maxRows],
            );
        }

        $unsafeIdentifiers = $materials
            ->filter(fn (object $material): bool => $this->isFormulaRisk($material->ingredient_name))
            ->pluck('ingredient_id')
            ->values()
            ->all();
        if ($unsafeIdentifiers !== []) {
            $this->recordAudit($actor, $context, $exportId, 'CSV_CATALOG_EXPORT_REJECTED', 'REJECTED', [
                'reason_code' => 'FORMULA_RISK_NAME',
                'ingredient_ids' => $unsafeIdentifiers,
            ]);
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_EXPORT_FORMULA_RISK',
                'Eksport odrzucono: zatwierdzone nazwy mogą zostać wykonane jako formuły arkusza. Wymagają obsługi następujące składniki.',
                422,
                ['ingredient_ids' => $unsafeIdentifiers],
            );
        }

        $maxExportBytes = max(1, (int) config('raw_material_csv.export_max_bytes'));
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_EXPORT_CREATE_FAILED',
                'Nie udało się przygotować prywatnego pliku eksportu.',
                500,
            );
        }

        try {
            $this->writeCsvRow($stream, ['ingredient_id', 'ingredient_name']);
            if (ftell($stream) > $maxExportBytes) {
                $this->recordAudit($actor, $context, $exportId, 'CSV_CATALOG_EXPORT_REJECTED', 'REJECTED', [
                    'reason_code' => 'FILE_SIZE_LIMIT_EXCEEDED',
                    'row_count' => 0,
                    'byte_limit' => $maxExportBytes,
                ]);
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_EXPORT_SIZE_LIMIT',
                    'Eksport katalogu przekracza skonfigurowany limit rozmiaru pliku.',
                    413,
                    ['byte_limit' => $maxExportBytes],
                );
            }
            foreach ($materials as $material) {
                $this->writeCsvRow($stream, [$material->ingredient_id, $material->ingredient_name]);
                if (ftell($stream) > $maxExportBytes) {
                    $this->recordAudit($actor, $context, $exportId, 'CSV_CATALOG_EXPORT_REJECTED', 'REJECTED', [
                        'reason_code' => 'FILE_SIZE_LIMIT_EXCEEDED',
                        'row_count' => $materials->count(),
                        'byte_limit' => $maxExportBytes,
                    ]);
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CSV_EXPORT_SIZE_LIMIT',
                        'Eksport katalogu przekracza skonfigurowany limit rozmiaru pliku.',
                        413,
                        ['byte_limit' => $maxExportBytes],
                    );
                }
            }

            rewind($stream);
            $disk = Storage::disk('raw_material_csv_exports');
            $storageKey = $actor->organization_id.'/'.$actor->id.'/'.$exportId.'.csv';
            $partialKey = $storageKey.'.partial';
            try {
                if (! $disk->put($partialKey, $stream)) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CSV_EXPORT_STORAGE_FAILED',
                        'Nie udało się zapisać prywatnego pliku eksportu.',
                        500,
                    );
                }
                if (! $disk->move($partialKey, $storageKey)) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CSV_EXPORT_STORAGE_FAILED',
                        'Nie udało się opublikować prywatnego pliku eksportu.',
                        500,
                    );
                }
            } finally {
                if ($disk->exists($partialKey) && ! $disk->delete($partialKey)) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CSV_EXPORT_CLEANUP_FAILED',
                        'Nie udało się usunąć niekompletnego pliku eksportu.',
                        500,
                    );
                }
            }
        } finally {
            fclose($stream);
        }

        $disk = Storage::disk('raw_material_csv_exports');
        $storageKey = $actor->organization_id.'/'.$actor->id.'/'.$exportId.'.csv';
        try {
            $hash = $this->sha256($disk->readStream($storageKey));
            $manifest = [
                'export_id' => $exportId,
                'organization_id' => $actor->organization_id,
                'actor_user_id' => $actor->id,
                'location_id' => $context->id,
                'storage_key' => $storageKey,
                'sha256' => $hash,
                'row_count' => $materials->count(),
                'created_at' => now()->toIso8601String(),
            ];
            $manifestKey = $this->manifestKey($actor, $exportId);
            if (! Cache::put(
                $manifestKey,
                $manifest,
                now()->addMinutes($ttlMinutes),
            )) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_EXPORT_METADATA_FAILED',
                    'Nie udało się bezpiecznie zapisać metadanych eksportu.',
                    500,
                );
            }
            $this->recordAudit($actor, $context, $exportId, 'CSV_CATALOG_EXPORT_CREATED', 'ACCEPTED', [
                'row_count' => $manifest['row_count'],
                'sha256' => $hash,
            ]);
        } catch (Throwable $exception) {
            Cache::forget($this->manifestKey($actor, $exportId));
            if (! $disk->delete($storageKey)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_EXPORT_CLEANUP_FAILED',
                    'Eksport nie został udostępniony, ale nie udało się usunąć jego prywatnego pliku.',
                    500,
                );
            }
            throw $exception;
        }

        return [
            'export_id' => $exportId,
            'row_count' => $manifest['row_count'],
            'sha256' => $hash,
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
            'download_url' => '/api/v1/raw-materials/catalog-exports/'.$exportId.'/download',
        ];
    }

    public function authorizeDownload(User $actor, Location $context, string $exportId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $manifest = Cache::get($this->manifestKey($actor, $exportId));
        if (! is_array($manifest)
            || ($manifest['organization_id'] ?? null) !== $actor->organization_id
            || ($manifest['actor_user_id'] ?? null) !== $actor->id
            || ($manifest['location_id'] ?? null) !== $context->id) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_EXPORT_NOT_FOUND',
                'Nie znaleziono eksportu katalogu w bieżącym kontekście organizacji.',
                404,
            );
        }

        $disk = Storage::disk('raw_material_csv_exports');
        if (! $disk->exists($manifest['storage_key'])) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_EXPORT_NOT_FOUND',
                'Plik prywatnego eksportu nie jest już dostępny.',
                404,
            );
        }
        $actualHash = $this->sha256($disk->readStream($manifest['storage_key']));
        if (! hash_equals($manifest['sha256'], $actualHash)) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_EXPORT_INTEGRITY_FAILED',
                'Weryfikacja integralności pliku eksportu nie powiodła się.',
                500,
            );
        }

        $this->recordAudit($actor, $context, $exportId, 'CSV_CATALOG_EXPORT_DOWNLOADED', 'ACCEPTED', [
            'row_count' => $manifest['row_count'],
            'sha256' => $actualHash,
        ]);

        return [
            'storage_key' => $manifest['storage_key'],
            'filename' => 'katalog-surowcow-veltra.csv',
        ];
    }

    private function recordAudit(
        User $actor,
        Location $context,
        string $exportId,
        string $eventCode,
        string $outcome,
        array $metadata,
    ): void {
        $this->mutationContexts->runForSubject(
            AuthorizationMutationScope::rawMaterialCommercial(),
            $actor->id,
            $exportId,
            fn () => DB::table('raw_materials_commercial_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'operation_id' => $exportId,
                'actor_user_id' => $actor->id,
                'actor_location_id' => $context->id,
                'subject_type' => 'RAW_MATERIAL_CATALOG_EXPORT',
                'subject_id' => $exportId,
                'event_code' => $eventCode,
                'outcome' => $outcome,
                'reason_code' => $outcome === 'REJECTED' ? ($metadata['reason_code'] ?? 'EXPORT_REJECTED') : null,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]),
        );
    }

    private function writeCsvRow(mixed $stream, array $fields): void
    {
        if (fputcsv($stream, $fields, ';', '"', '', "\n") === false) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_EXPORT_CREATE_FAILED',
                'Nie udało się poprawnie zakodować wiersza eksportu CSV.',
                500,
            );
        }
    }

    private function isFormulaRisk(string $name): bool
    {
        return preg_match('/\A[\p{Z}\s]*[=+\-@]/u', $name) === 1
            || preg_match('/\A[\p{Z}\s]*[\x00-\x1F\x7F]/u', $name) === 1;
    }

    private function sha256(mixed $stream): string
    {
        if (! is_resource($stream)) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_EXPORT_STORAGE_FAILED',
                'Nie udało się odczytać prywatnego pliku eksportu.',
                500,
            );
        }

        try {
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) === false) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_EXPORT_STORAGE_FAILED',
                    'Nie udało się zweryfikować prywatnego pliku eksportu.',
                    500,
                );
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function manifestKey(User $actor, string $exportId): string
    {
        return 'raw-material-csv-catalog-export:'.$actor->organization_id.':'.$actor->id.':'.$exportId;
    }

    private function pruneExpiredExports(int $ttlMinutes): void
    {
        $disk = Storage::disk('raw_material_csv_exports');
        $expiredBefore = now()->subMinutes($ttlMinutes)->getTimestamp();
        foreach ($disk->allFiles() as $path) {
            if ($disk->lastModified($path) < $expiredBefore && ! $disk->delete($path)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CSV_EXPORT_CLEANUP_FAILED',
                    'Nie udało się usunąć wygasłego pliku prywatnego eksportu.',
                    500,
                );
            }
        }
    }
}
