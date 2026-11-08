<?php

namespace App\Domain\RawMaterials;

use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class RawMaterialCsvImportWorkflow
{
    private const STATUS_LABELS = [
        'NO_CHANGE' => 'BEZ ZMIAN',
        'INITIAL_METADATA_BASELINE' => 'BAZA POCZĄTKOWA METADANYCH',
        'METADATA_CHANGED' => 'ZMIANA DANYCH',
        'PRICE_CHANGED' => 'ZMIANA CENY',
        'PRICE_AND_METADATA_CHANGED' => 'ZMIANA CENY I DANYCH',
        'DATE_CONFLICT' => 'KONFLIKT DATY',
        'INVALID_ROW' => 'BŁĄD',
        'BATCH_REJECTED' => 'BŁĄD',
    ];

    public function __construct(
        private readonly RawMaterialCsvParser $parser,
        private readonly RawMaterialCsvImportPersistenceService $persistence,
        private readonly RawMaterialAuthorization $authorization,
        private readonly RawMaterialLocationSharePolicy $sharePolicy,
    ) {}

    public function upload(User $actor, Location $context, UploadedFile $file): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);

        $maxBytes = (int) config('raw_material_csv.max_file_bytes');
        if (! $file->isValid() || $file->getSize() > $maxBytes) {
            throw new RawMaterialException('RAW_MATERIAL_CSV_UPLOAD_INVALID', 'Nie udało się odczytać pliku CSV albo przekracza on dozwolony rozmiar.', 422);
        }
        $path = $file->getRealPath();
        $contents = is_string($path) && $path !== '' ? file_get_contents($path) : false;
        if (! is_string($contents)) {
            throw new RawMaterialException('RAW_MATERIAL_CSV_READ_FAILED', 'Nie udało się bezpiecznie odczytać przesłanego pliku CSV.', 422);
        }

        $rows = $this->parser->parse($contents);
        $preview = $this->buildPreview($actor, $context, $rows);
        $previewId = Str::uuid()->toString();
        $storageKey = $actor->organization_id.'/'.$actor->id.'/'.$previewId.'.csv';
        $disk = Storage::disk('raw_material_csv_imports');
        $this->pruneExpiredUploads();
        if (! $disk->put($storageKey, $contents)) {
            throw new RawMaterialException('RAW_MATERIAL_CSV_STORAGE_FAILED', 'Nie udało się bezpiecznie zapisać prywatnego pliku CSV.', 500);
        }
        $statusCounts = collect($preview['rows'])->countBy('status_code')->all();
        $this->persistence->recordPreviewAudit(
            $actor,
            $context,
            $previewId,
            hash('sha256', $contents),
            count($rows),
            $preview['can_commit'],
            $statusCounts,
        );
        $data = [
            'preview_id' => $previewId,
            'organization_id' => $actor->organization_id,
            'actor_user_id' => $actor->id,
            'storage_key' => $storageKey,
            'source_sha256' => hash('sha256', $contents),
            'parsed_rows' => $rows,
            'prepared_rows' => $preview['prepared_rows'],
            'snapshot_hash' => $preview['snapshot_hash'],
            'can_commit' => $preview['can_commit'],
            'rows' => $preview['rows'],
            'created_at' => now()->toIso8601String(),
            'committed_batch_id' => null,
            'commit_idempotency_key' => null,
        ];
        if (! Cache::put(
            $this->previewKey($actor, $previewId),
            $data,
            now()->addMinutes((int) config('raw_material_csv.preview_ttl_minutes')),
        )) {
            if (! $disk->delete($storageKey)) {
                throw new RawMaterialException('RAW_MATERIAL_CSV_STORAGE_CLEANUP_FAILED', 'Nie udało się usunąć pliku CSV po błędzie zapisu podglądu.', 500);
            }
            throw new RawMaterialException('RAW_MATERIAL_CSV_PREVIEW_STORAGE_FAILED', 'Nie udało się bezpiecznie zapisać podglądu importu.', 500);
        }

        return $this->presentPreview($data);
    }

    public function commit(
        User $actor,
        Location $context,
        string $previewId,
        string $idempotencyKey,
        bool $confirmed,
    ): array {
        if (! $confirmed) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CSV_CONFIRMATION_REQUIRED',
                'Zatwierdzenie importu wymaga jawnego potwierdzenia operatora.',
                422,
            );
        }
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);

        $key = $this->previewKey($actor, $previewId);
        try {
            return Cache::lock($key.':commit-lock', 30)->block(5, function () use (
                $actor,
                $context,
                $previewId,
                $idempotencyKey,
                $key,
            ): array {
                $data = Cache::get($key);
                if (! is_array($data)
                    || $data['organization_id'] !== $actor->organization_id
                    || $data['actor_user_id'] !== $actor->id) {
                    throw new RawMaterialException('RAW_MATERIAL_CSV_PREVIEW_NOT_FOUND', 'Podgląd importu wygasł albo nie istnieje.', 404);
                }

                if ($data['committed_batch_id'] !== null) {
                    if ($data['commit_idempotency_key'] !== $idempotencyKey) {
                        throw new RawMaterialException(
                            'RAW_MATERIAL_IDEMPOTENCY_CONFLICT',
                            'Podgląd został już zatwierdzony z innym kluczem idempotencji.',
                        );
                    }

                    return $this->result($actor, $context, $data['committed_batch_id']);
                }
                if (! $data['can_commit']) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CSV_PREVIEW_HAS_ERRORS',
                        'Nie można zatwierdzić importu zawierającego błędy lub konflikty. Popraw plik i wykonaj nowy podgląd.',
                        422,
                        ['preview_id' => $previewId],
                    );
                }

                $disk = Storage::disk('raw_material_csv_imports');
                if (! $disk->exists($data['storage_key'])) {
                    throw new RawMaterialException('RAW_MATERIAL_CSV_SOURCE_MISSING', 'Prywatny plik źródłowy podglądu nie jest już dostępny.', 410);
                }
                $contents = $disk->get($data['storage_key']);
                if (! is_string($contents)) {
                    throw new RawMaterialException('RAW_MATERIAL_CSV_SOURCE_READ_FAILED', 'Nie udało się odczytać prywatnego pliku CSV.', 410);
                }
                if (! hash_equals($data['source_sha256'], hash('sha256', $contents))) {
                    throw new RawMaterialException('RAW_MATERIAL_CSV_SOURCE_CHANGED', 'Suma kontrolna prywatnego pliku CSV nie jest zgodna z podglądem.', 409);
                }

                $result = DB::transaction(function () use ($actor, $context, $data, $idempotencyKey): array {
                    Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                    $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);

                    $fresh = $this->buildPreview($actor, $context, $data['parsed_rows']);
                    if (! $fresh['can_commit'] || ! hash_equals($data['snapshot_hash'], $fresh['snapshot_hash'])) {
                        throw new RawMaterialException(
                            'RAW_MATERIAL_CSV_PREVIEW_STALE',
                            'Dane składników lub historia cen zmieniły się od czasu podglądu. Wykonaj nowy podgląd przed zatwierdzeniem.',
                            409,
                            ['preview_id' => $data['preview_id']],
                        );
                    }

                    return $this->persistence->recordPreparedBatch(
                        $actor,
                        $context,
                        $data['source_sha256'],
                        $idempotencyKey,
                        $fresh['prepared_rows'],
                    );
                }, attempts: 1);

                $data['committed_batch_id'] = $result['batch_id'];
                $data['commit_idempotency_key'] = $idempotencyKey;
                if (! Cache::put($key, $data, now()->addMinutes((int) config('raw_material_csv.preview_ttl_minutes')))) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CSV_COMMIT_STATE_NOT_CACHED',
                        'Import zapisano, ale nie udało się zapisać stanu ponowienia. Ponów żądanie z tym samym kluczem Idempotency-Key.',
                        500,
                        ['batch_id' => $result['batch_id']],
                    );
                }

                return $result;
            });
        } catch (LockTimeoutException) {
            throw new RawMaterialException('RAW_MATERIAL_CSV_COMMIT_IN_PROGRESS', 'Ten podgląd jest już zatwierdzany. Spróbuj ponownie za chwilę.', 409);
        }
    }

    public function result(User $actor, Location $context, string $batchId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceView);
        $batch = DB::table('raw_material_import_batches')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $batchId)
            ->first();
        if (! $batch) {
            throw new RawMaterialException('RAW_MATERIAL_IMPORT_NOT_FOUND', 'Nie znaleziono wyniku importu w organizacji bieżącego użytkownika.', 404);
        }

        $rows = DB::table('raw_material_import_rows')
            ->where('organization_id', $actor->organization_id)
            ->where('batch_id', $batchId)
            ->orderBy('row_number')
            ->get();
        if (! $this->sharePolicy->isPrimaryContext($context)) {
            foreach ($rows->pluck('raw_material_id')->filter() as $materialId) {
                $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
            }
        }

        return [
            'batch_id' => $batch->id,
            'batch_status' => $batch->batch_status,
            'source_sha256' => $batch->source_sha256,
            'actor_user_id' => $batch->actor_user_id,
            'created_at' => $batch->created_at,
            'completed_at' => $batch->completed_at,
            'row_count' => (int) $batch->row_count,
            'rows' => $rows->map(fn (object $row): array => [
                'row_number' => (int) $row->row_number,
                'ingredient_id' => $row->ingredient_code,
                'ingredient_name' => $row->input_ingredient_name,
                'current_ingredient_name' => $row->current_ingredient_name,
                'status' => self::STATUS_LABELS[$row->result_code],
                'error_code' => $row->error_code,
                'error' => $row->error_detail,
                'price_net' => (string) $row->source_price_net,
                'normalized_net_pln_per_base_unit' => (string) $row->normalized_net_pln_per_base_unit,
                'vat_rate' => (string) $row->vat_rate,
                'unit' => $row->input_unit,
                'effective_date' => $row->effective_date,
                'verified_at' => $row->verified_at,
            ])->all(),
        ];
    }

    private function buildPreview(User $actor, Location $context, array $rows): array
    {
        $prepared = [];
        $snapshots = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $material = DB::table('raw_materials as materials')
                ->leftJoin('raw_material_revisions as revisions', function ($join): void {
                    $join->on('revisions.id', '=', 'materials.approved_revision_id')
                        ->on('revisions.organization_id', '=', 'materials.organization_id')
                        ->on('revisions.raw_material_id', '=', 'materials.id');
                })
                ->where('materials.organization_id', $actor->organization_id)
                ->where('materials.code', $row['ingredient_id'])
                ->select([
                    'materials.id',
                    'materials.lifecycle_state',
                    'materials.approved_revision_id',
                    'revisions.name',
                    'revisions.base_unit_code',
                    'revisions.base_unit_dimension',
                ])
                ->first();

            if ($material && ! $this->sharePolicy->isPrimaryContext($context)) {
                $this->sharePolicy->assertCanConsume($actor, $context, $material->id);
            }

            $normalPrice = '0';
            $revisionId = $material?->approved_revision_id;
            $error = null;
            $unitFactor = null;
            $unitDimension = match ($row['unit']) {
                'kg' => 'MASS',
                'l' => 'VOLUME',
                'szt.' => 'COUNT',
            };
            $revisionUnits = [];

            if (! $material) {
                $error = ['code' => 'UNKNOWN_INGREDIENT_ID', 'message' => 'Identyfikator SUR nie istnieje w organizacji bieżącego użytkownika.'];
            } elseif ($material->lifecycle_state !== 'ACTIVE' || $material->approved_revision_id === null) {
                $error = ['code' => 'INGREDIENT_NOT_ACTIVE', 'message' => 'Składnik nie ma aktywnej, zatwierdzonej rewizji.'];
            } elseif ($material->name !== $row['ingredient_name']) {
                $error = ['code' => 'INGREDIENT_NAME_MISMATCH', 'message' => 'Nazwa z pliku różni się dokładnie od nazwy bieżącej zatwierdzonej rewizji.'];
            } else {
                $revisionUnits = DB::table('raw_material_revision_units')
                    ->where('organization_id', $actor->organization_id)
                    ->where('revision_id', $revisionId)
                    ->orderBy('unit_code')
                    ->get(['unit_code', 'dimension', 'conversion_kind', 'to_base_factor'])
                    ->map(fn (object $unit): array => [
                        'unit_code' => $unit->unit_code,
                        'dimension' => $unit->dimension,
                        'conversion_kind' => $unit->conversion_kind,
                        'to_base_factor' => (string) $unit->to_base_factor,
                    ])->all();
                $sourceUnit = $row['unit'] === 'szt.' ? 'pc' : $row['unit'];
                $approvedUnit = collect($revisionUnits)->firstWhere('unit_code', $sourceUnit);
                if ($material->base_unit_dimension !== $unitDimension || ! $approvedUnit
                    || $approvedUnit['dimension'] !== $unitDimension
                    || ! in_array($approvedUnit['conversion_kind'], ['BASE', 'METRIC'], true)) {
                    $error = ['code' => 'UNIT_NOT_COMPATIBLE', 'message' => 'Jednostka z pliku nie jest zatwierdzoną jednostką tego samego wymiaru dla bieżącej rewizji.'];
                } else {
                    $unitFactor = $approvedUnit['to_base_factor'];
                    $normalPrice = (string) DB::scalar(
                        'SELECT raw_materials_round_half_even_v1(CAST(? AS numeric) / CAST(? AS numeric), 14)::text',
                        [$row['price_net'], $unitFactor],
                    );
                }
            }

            if ($material) {
                $snapshots[] = [
                    'row_number' => $index + 1,
                    'raw_material_id' => $material->id,
                    'lifecycle_state' => $material->lifecycle_state,
                    'revision_id' => $revisionId,
                    'revision_name' => $material->name,
                    'base_unit_code' => $material->base_unit_code,
                    'base_unit_dimension' => $material->base_unit_dimension,
                    'revision_units' => $revisionUnits,
                ];
            } else {
                $snapshots[] = ['row_number' => $index + 1, 'raw_material_id' => null];
            }
            if ($error !== null) {
                $errors[$index + 1] = $error;
            }
            $prepared[] = [
                'ingredient_id' => $row['ingredient_id'],
                'ingredient_name' => $row['ingredient_name'],
                'price_net' => $row['price_net'],
                'normalized_net_pln_per_base_unit' => $normalPrice,
                'vat_rate' => $row['vat_rate'],
                'unit' => $row['unit'],
                'effective_date' => $row['effective_date'],
            ];
        }

        $plans = $this->persistence->previewPreparedRows($actor, $prepared);
        $previewRows = [];
        foreach ($plans as $plan) {
            $number = $plan['row_number'];
            $input = $plan['input'];
            $error = $errors[$number] ?? ($plan['error_code'] === null ? null : [
                'code' => $plan['error_code'],
                'message' => $plan['error_detail'],
            ]);
            $resultCode = $error === null ? $plan['result_code'] : 'INVALID_ROW';
            $period = $plan['raw_material_id'] === null
                ? ['is_future' => false, 'is_backdated' => false, 'affected_until' => null]
                : $this->affectedPeriod($actor->organization_id, $plan['raw_material_id'], $input['effective_date']);
            $previewRows[] = [
                'row_number' => $number,
                'ingredient_id' => $input['ingredient_id'],
                'ingredient_name' => $input['ingredient_name'],
                'current_ingredient_name' => $plan['current_name'],
                'status_code' => $resultCode,
                'status' => self::STATUS_LABELS[$resultCode],
                'error_code' => $error['code'] ?? null,
                'error' => $error['message'] ?? null,
                'price_net' => $input['price_net'],
                'normalized_net_pln_per_base_unit' => $input['normalized_net_pln_per_base_unit'],
                'unit' => $input['unit'],
                'vat_rate' => $input['vat_rate'],
                'effective_date' => $input['effective_date'],
                ...$period,
            ];
        }

        $canCommit = $errors === [] && ! collect($plans)->contains(
            fn (array $plan): bool => in_array($plan['result_code'], ['DATE_CONFLICT', 'INVALID_ROW'], true),
        );
        $snapshotHash = hash('sha256', json_encode([
            'prepared_rows' => $prepared,
            'snapshots' => $snapshots,
            'plans' => $plans,
            'preview_rows' => $previewRows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'prepared_rows' => $prepared,
            'rows' => $previewRows,
            'can_commit' => $canCommit,
            'snapshot_hash' => $snapshotHash,
        ];
    }

    private function affectedPeriod(string $organizationId, string $materialId, string $effectiveDate): array
    {
        $nextPrice = DB::table('raw_material_reference_price_events')
            ->where('organization_id', $organizationId)
            ->where('raw_material_id', $materialId)
            ->whereDate('effective_from', '>', $effectiveDate)
            ->min('effective_from');
        $nextMetadata = DB::table('raw_material_import_metadata_events')
            ->where('organization_id', $organizationId)
            ->where('raw_material_id', $materialId)
            ->whereDate('effective_from', '>', $effectiveDate)
            ->min('effective_from');
        $next = collect([$nextPrice, $nextMetadata])->filter()->sort()->first();

        return [
            'is_future' => $effectiveDate > now()->toDateString(),
            'is_backdated' => $effectiveDate < now()->toDateString(),
            'affected_until' => $next,
        ];
    }

    private function presentPreview(array $data): array
    {
        return [
            'preview_id' => $data['preview_id'],
            'source_sha256' => $data['source_sha256'],
            'row_count' => count($data['rows']),
            'can_commit' => $data['can_commit'],
            'expires_at' => now()->addMinutes((int) config('raw_material_csv.preview_ttl_minutes'))->toIso8601String(),
            'rows' => $data['rows'],
        ];
    }

    private function previewKey(User $actor, string $previewId): string
    {
        return 'raw-material-csv-import:'.$actor->organization_id.':'.$actor->id.':'.$previewId;
    }

    private function pruneExpiredUploads(): void
    {
        $disk = Storage::disk('raw_material_csv_imports');
        $expiredBefore = now()->subMinutes((int) config('raw_material_csv.preview_ttl_minutes'))->getTimestamp();
        foreach ($disk->allFiles() as $path) {
            if ($disk->lastModified($path) < $expiredBefore) {
                $disk->delete($path);
            }
        }
    }
}
