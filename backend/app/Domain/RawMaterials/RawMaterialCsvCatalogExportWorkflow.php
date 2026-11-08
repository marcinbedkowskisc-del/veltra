<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\Location;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class RawMaterialCsvCatalogExportWorkflow
{
    public function __construct(
        private readonly RawMaterialAuthorization $authorization,
        private readonly RawMaterialLocationSharePolicy $sharePolicy,
        private readonly AuthorizationMutationContext $mutationContexts,
    ) {}

    public function create(User $actor, Location $context): array
    {
        return $this->createExport($actor, $context, 'catalog');
    }

    public function createExtended(User $actor, Location $context): array
    {
        return $this->createExport($actor, $context, 'extended_raw_catalog');
    }

    public function createReferencePrices(User $actor, Location $context, string $asOfDate): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $asOfDate);
        if ($date === false || $date->format('Y-m-d') !== $asOfDate) {
            throw new RawMaterialException(
                'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_INVALID_DATE',
                'Data eksportu musi mieć dokładny format YYYY-MM-DD.',
                422,
            );
        }

        return $this->createExport($actor, $context, 'reference_prices', $asOfDate);
    }

    private function createExport(
        User $actor,
        Location $context,
        string $profile,
        ?string $asOfDate = null,
    ): array {
        $extended = $profile === 'extended_raw_catalog';
        $referencePrices = $profile === 'reference_prices';
        $permission = $referencePrices ? RawMaterialPermission::ReferencePriceView : RawMaterialPermission::View;
        $this->authorization->assertAllowed($actor, $context, $permission);
        $ttlMinutes = max(1, (int) config('raw_material_csv.export_ttl_minutes'));
        $this->pruneExpiredExports($ttlMinutes);

        $exportId = Str::uuid()->toString();
        $maxRows = max(0, (int) config('raw_material_csv.export_max_rows'));
        $query = DB::table('raw_materials as materials')
            ->join('raw_material_revisions as revisions', function ($join): void {
                $join->on('revisions.id', '=', 'materials.approved_revision_id')
                    ->on('revisions.raw_material_id', '=', 'materials.id')
                    ->on('revisions.organization_id', '=', 'materials.organization_id');
            })
            ->where('materials.organization_id', $actor->organization_id)
            ->where('materials.lifecycle_state', 'ACTIVE')
            ->orderBy('materials.code')
            ->limit($maxRows + 1);
        $this->sharePolicy->constrainVisibleMaterials($query, $actor, $context);
        $columns = match ($profile) {
            'extended_raw_catalog' => [
                'materials.code as sku',
                DB::raw("'RAW' as entity_type"),
                'revisions.name',
                'revisions.description',
                'categories.code as category_code',
                DB::raw("CASE categories.depth
                    WHEN 1 THEN categories.code
                    WHEN 2 THEN concat_ws('/', parents.code, categories.code)
                    WHEN 3 THEN concat_ws('/', roots.code, parents.code, categories.code)
                END as category_path_codes"),
                'revisions.base_unit_code',
                'revisions.revision_no as approved_revision_no',
            ],
            'reference_prices' => [
                'materials.code as sku',
                DB::raw("'RAW' as entity_type"),
                'revisions.name as approved_name',
                'categories.code as category_code',
                'revisions.base_unit_code',
            ],
            default => ['materials.code as ingredient_id', 'revisions.name as ingredient_name'],
        };
        if ($extended || $referencePrices) {
            $query
                ->join('raw_material_categories as categories', function ($join): void {
                    $join->on('categories.id', '=', 'revisions.category_id')
                        ->on('categories.organization_id', '=', 'revisions.organization_id');
                })
                ->leftJoin('raw_material_categories as parents', function ($join): void {
                    $join->on('parents.id', '=', 'categories.parent_id')
                        ->on('parents.organization_id', '=', 'categories.organization_id');
                })
                ->leftJoin('raw_material_categories as roots', function ($join): void {
                    $join->on('roots.id', '=', 'parents.parent_id')
                        ->on('roots.organization_id', '=', 'categories.organization_id');
                });
        }
        $query->select($columns)->addSelect('materials.id as raw_material_id');
        if ($referencePrices) {
            $query
                ->selectRaw(<<<'SQL'
                    (
                        SELECT jsonb_build_object(
                            'event_type', prices.event_type,
                            'effective_from', prices.effective_from::text,
                            'amount', prices.net_pln_per_base_unit::text
                        )::text
                        FROM raw_material_reference_price_events prices
                        WHERE prices.organization_id = materials.organization_id
                            AND prices.raw_material_id = materials.id
                            AND prices.effective_from <= ?::date
                        ORDER BY prices.effective_from DESC, prices.event_no DESC
                        LIMIT 1
                    ) AS reference_price_snapshot
                    SQL, [$asOfDate])
                ->selectRaw(<<<'SQL'
                    (
                        SELECT jsonb_build_object(
                            'vat_rate', metadata.vat_rate::text,
                            'input_unit', metadata.input_unit,
                            'effective_from', metadata.effective_from::text
                        )::text
                        FROM raw_material_import_metadata_events metadata
                        WHERE metadata.organization_id = materials.organization_id
                            AND metadata.raw_material_id = materials.id
                            AND metadata.effective_from <= ?::date
                        ORDER BY metadata.effective_from DESC, metadata.recorded_at DESC, metadata.id
                        LIMIT 1
                    ) AS metadata_snapshot
                    SQL, [$asOfDate]);
        }
        $materials = $query->get();

        if ($materials->count() > $maxRows) {
            $this->recordAudit($actor, $context, $exportId, $this->eventCode($profile, 'REJECTED'), 'REJECTED', [
                'export_profile' => $profile,
                'reason_code' => 'ROW_LIMIT_EXCEEDED',
                'row_limit' => $maxRows,
            ]);
            throw new RawMaterialException(
                $referencePrices ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_ROW_LIMIT' : 'RAW_MATERIAL_CSV_EXPORT_ROW_LIMIT',
                $referencePrices
                    ? 'Eksport cen referencyjnych przekracza skonfigurowany limit wierszy.'
                    : 'Eksport katalogu przekracza skonfigurowany limit wierszy.',
                413,
                ['row_limit' => $maxRows],
            );
        }

        $rejectedRecords = $materials->map(function (object $material) use ($profile, $extended, $referencePrices): ?array {
            $fields = match ($profile) {
                'extended_raw_catalog' => [
                    'sku' => $material->sku,
                    'name' => $material->name,
                    'description' => $material->description,
                ],
                'reference_prices' => [
                    'sku' => $material->sku,
                    'approved_name' => $material->approved_name,
                    'category_code' => $material->category_code,
                    'base_unit_code' => $material->base_unit_code,
                ] + $this->referencePriceFormulaFields($material),
                default => ['ingredient_name' => $material->ingredient_name],
            };
            $unsafeFields = array_keys(array_filter(
                $fields,
                fn (mixed $value): bool => is_string($value) && $this->isFormulaRisk($value),
            ));

            if ($unsafeFields === []) {
                return null;
            }

            return [
                'identifier' => $extended || $referencePrices ? $material->sku : $material->ingredient_id,
                'fields' => $unsafeFields,
            ];
        })->filter()->values()->all();
        if ($rejectedRecords !== []) {
            $identifierKey = $extended || $referencePrices ? 'skus' : 'ingredient_ids';
            $identifiers = array_column($rejectedRecords, 'identifier');
            $this->recordAudit($actor, $context, $exportId, $this->eventCode($profile, 'REJECTED'), 'REJECTED', [
                'export_profile' => $profile,
                'reason_code' => $extended || $referencePrices ? 'FORMULA_RISK_FIELD' : 'FORMULA_RISK_NAME',
                'rejected_records' => $rejectedRecords,
            ]);
            $message = $referencePrices
                ? 'Eksport odrzucono: zapisane dane katalogowe lub metadane mogą zostać wykonane jako formuły arkusza.'
                : ($extended
                    ? 'Eksport odrzucono: zatwierdzone dane katalogowe zawierają wartości grożące wykonaniem jako formuły arkusza.'
                    : 'Eksport odrzucono: zatwierdzone nazwy mogą zostać wykonane jako formuły arkusza. Wymagają obsługi następujące składniki.');
            throw new RawMaterialException(
                $referencePrices ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_FORMULA_RISK' : 'RAW_MATERIAL_CSV_EXPORT_FORMULA_RISK',
                $message,
                422,
                $extended || $referencePrices
                    ? ['rejected_records' => $rejectedRecords, $identifierKey => $identifiers]
                    : ['ingredient_ids' => $identifiers],
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
            $headers = match ($profile) {
                'extended_raw_catalog' => [
                    'sku',
                    'entity_type',
                    'name',
                    'description',
                    'category_code',
                    'category_path_codes',
                    'base_unit_code',
                    'approved_revision_no',
                ],
                'reference_prices' => [
                    'as_of_date',
                    'sku',
                    'entity_type',
                    'approved_name',
                    'category_code',
                    'base_unit_code',
                    'reference_price_net_pln_per_base_unit',
                    'reference_price_effective_from',
                    'reference_price_status',
                    'vat_rate_percent',
                    'vat_input_unit',
                    'metadata_effective_from',
                    'metadata_status',
                ],
                default => ['ingredient_id', 'ingredient_name'],
            };
            $this->writeCsvRow($stream, $headers);
            if (ftell($stream) > $maxExportBytes) {
                $this->recordAudit($actor, $context, $exportId, $this->eventCode($profile, 'REJECTED'), 'REJECTED', [
                    'export_profile' => $profile,
                    'reason_code' => 'FILE_SIZE_LIMIT_EXCEEDED',
                    'row_count' => 0,
                    'byte_limit' => $maxExportBytes,
                ]);
                throw new RawMaterialException(
                    $referencePrices ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_SIZE_LIMIT' : 'RAW_MATERIAL_CSV_EXPORT_SIZE_LIMIT',
                    $referencePrices
                        ? 'Eksport cen referencyjnych przekracza skonfigurowany limit rozmiaru pliku.'
                        : 'Eksport katalogu przekracza skonfigurowany limit rozmiaru pliku.',
                    413,
                    ['byte_limit' => $maxExportBytes],
                );
            }
            foreach ($materials as $material) {
                $fields = match ($profile) {
                    'extended_raw_catalog' => [
                        $material->sku,
                        $material->entity_type,
                        $material->name,
                        $material->description,
                        $material->category_code,
                        $material->category_path_codes,
                        $material->base_unit_code,
                        $material->approved_revision_no,
                    ],
                    'reference_prices' => $this->referencePriceFields($material, $asOfDate),
                    default => [$material->ingredient_id, $material->ingredient_name],
                };
                $this->writeCsvRow($stream, $fields);
                if (ftell($stream) > $maxExportBytes) {
                    $this->recordAudit($actor, $context, $exportId, $this->eventCode($profile, 'REJECTED'), 'REJECTED', [
                        'export_profile' => $profile,
                        'reason_code' => 'FILE_SIZE_LIMIT_EXCEEDED',
                        'row_count' => $materials->count(),
                        'byte_limit' => $maxExportBytes,
                    ]);
                    throw new RawMaterialException(
                        $referencePrices ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_SIZE_LIMIT' : 'RAW_MATERIAL_CSV_EXPORT_SIZE_LIMIT',
                        $referencePrices
                            ? 'Eksport cen referencyjnych przekracza skonfigurowany limit rozmiaru pliku.'
                            : 'Eksport katalogu przekracza skonfigurowany limit rozmiaru pliku.',
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
                'export_profile' => $profile,
                'storage_key' => $storageKey,
                'sha256' => $hash,
                'row_count' => $materials->count(),
                'raw_material_ids' => $materials->pluck('raw_material_id')->all(),
                'created_at' => now()->toIso8601String(),
            ];
            if ($asOfDate !== null) {
                $manifest['as_of_date'] = $asOfDate;
            }
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
            $this->recordAudit($actor, $context, $exportId, $this->eventCode($profile, 'CREATED'), 'ACCEPTED', [
                'export_profile' => $profile,
                'row_count' => $manifest['row_count'],
                'sha256' => $hash,
            ] + ($asOfDate === null ? [] : ['as_of_date' => $asOfDate]));
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

        $result = [
            'export_id' => $exportId,
            'row_count' => $manifest['row_count'],
            'sha256' => $hash,
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
            'download_url' => match ($profile) {
                'extended_raw_catalog' => '/api/v1/raw-materials/catalog-exports/extended/'.$exportId.'/download',
                'reference_prices' => '/api/v1/raw-materials/reference-price-exports/'.$exportId.'/download',
                default => '/api/v1/raw-materials/catalog-exports/'.$exportId.'/download',
            },
        ];
        if ($asOfDate !== null) {
            $result['as_of_date'] = $asOfDate;
        }

        return $result;
    }

    private function referencePriceFields(object $material, ?string $asOfDate): array
    {
        $priceState = $this->decodeSnapshot($material->reference_price_snapshot);
        $metadataState = $this->decodeSnapshot($material->metadata_snapshot);
        $hasPrice = $priceState !== null && $priceState['event_type'] === 'PRICE_SET';

        return [
            $this->requireAsOfDate($asOfDate),
            $material->sku,
            $material->entity_type,
            $material->approved_name,
            $material->category_code,
            $material->base_unit_code,
            $hasPrice ? $priceState['amount'] : '',
            $hasPrice ? $priceState['effective_from'] : '',
            $hasPrice ? 'KNOWN' : 'NO_REFERENCE_PRICE',
            $metadataState['vat_rate'] ?? '',
            $metadataState['input_unit'] ?? '',
            $metadataState['effective_from'] ?? '',
            $metadataState === null ? 'UNKNOWN' : 'KNOWN',
        ];
    }

    private function referencePriceFormulaFields(object $material): array
    {
        $metadataState = $this->decodeSnapshot($material->metadata_snapshot);

        return [
            'vat_rate_percent' => $metadataState['vat_rate'] ?? '',
            'vat_input_unit' => $metadataState['input_unit'] ?? '',
        ];
    }

    private function decodeSnapshot(?string $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('Snapshot historyczny CSV ma nieprawidłowy format.');
        }

        return $decoded;
    }

    private function requireAsOfDate(?string $asOfDate): string
    {
        if ($asOfDate === null) {
            throw new LogicException('Eksport cen referencyjnych wymaga daty as-of.');
        }

        return $asOfDate;
    }

    public function authorizeDownload(User $actor, Location $context, string $exportId, bool $extended = false): array
    {
        $profile = $extended ? 'extended_raw_catalog' : 'catalog';

        return $this->authorizeDownloadProfile($actor, $context, $exportId, $profile);
    }

    public function authorizeReferencePriceDownload(User $actor, Location $context, string $exportId): array
    {
        return $this->authorizeDownloadProfile($actor, $context, $exportId, 'reference_prices');
    }

    private function authorizeDownloadProfile(
        User $actor,
        Location $context,
        string $exportId,
        string $profile,
    ): array {
        $referencePrices = $profile === 'reference_prices';
        $permission = $referencePrices ? RawMaterialPermission::ReferencePriceView : RawMaterialPermission::View;
        $this->authorization->assertAllowed($actor, $context, $permission);
        $manifest = Cache::get($this->manifestKey($actor, $exportId));
        if (! is_array($manifest)
            || ($manifest['organization_id'] ?? null) !== $actor->organization_id
            || ($manifest['actor_user_id'] ?? null) !== $actor->id
            || ($manifest['location_id'] ?? null) !== $context->id
            || ($manifest['export_profile'] ?? null) !== $profile
            || ! is_array($manifest['raw_material_ids'] ?? null)) {
            throw new RawMaterialException(
                $referencePrices ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_NOT_FOUND' : 'RAW_MATERIAL_CSV_EXPORT_NOT_FOUND',
                $referencePrices
                    ? 'Nie znaleziono eksportu cen referencyjnych w bieżącym kontekście organizacji.'
                    : 'Nie znaleziono eksportu katalogu w bieżącym kontekście organizacji.',
                404,
            );
        }
        foreach ($manifest['raw_material_ids'] as $materialId) {
            if (! is_string($materialId)) {
                throw new RawMaterialException(
                    $referencePrices ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_NOT_FOUND' : 'RAW_MATERIAL_CSV_EXPORT_NOT_FOUND',
                    __('messages.resource_not_found'),
                    404,
                );
            }
            $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
        }

        $disk = Storage::disk('raw_material_csv_exports');
        if (! $disk->exists($manifest['storage_key'])) {
            throw new RawMaterialException(
                $referencePrices ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_NOT_FOUND' : 'RAW_MATERIAL_CSV_EXPORT_NOT_FOUND',
                $referencePrices
                    ? 'Plik prywatnego eksportu cen referencyjnych nie jest już dostępny.'
                    : 'Plik prywatnego eksportu nie jest już dostępny.',
                404,
            );
        }
        $actualHash = $this->sha256($disk->readStream($manifest['storage_key']));
        if (! hash_equals($manifest['sha256'], $actualHash)) {
            throw new RawMaterialException(
                $referencePrices
                    ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_INTEGRITY_FAILED'
                    : 'RAW_MATERIAL_CSV_EXPORT_INTEGRITY_FAILED',
                'Weryfikacja integralności pliku eksportu nie powiodła się.',
                500,
            );
        }

        $this->recordAudit($actor, $context, $exportId, $this->eventCode($profile, 'DOWNLOADED'), 'ACCEPTED', [
            'export_profile' => $profile,
            'row_count' => $manifest['row_count'],
            'sha256' => $actualHash,
        ] + (isset($manifest['as_of_date']) ? ['as_of_date' => $manifest['as_of_date']] : []));

        return [
            'storage_key' => $manifest['storage_key'],
            'filename' => match ($profile) {
                'extended_raw_catalog' => 'katalog-surowcow-rozszerzony-veltra.csv',
                'reference_prices' => 'ceny-referencyjne-vat-veltra.csv',
                default => 'katalog-surowcow-veltra.csv',
            },
        ];
    }

    private function eventCode(string $profile, string $event): string
    {
        $prefix = match ($profile) {
            'extended_raw_catalog' => 'CSV_CATALOG_EXTENDED_EXPORT_',
            'reference_prices' => 'CSV_REFERENCE_PRICE_EXPORT_',
            default => 'CSV_CATALOG_EXPORT_',
        };

        return $prefix.$event;
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
                'subject_type' => str_starts_with($eventCode, 'CSV_REFERENCE_PRICE_EXPORT_')
                    ? 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT'
                    : 'RAW_MATERIAL_CATALOG_EXPORT',
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
