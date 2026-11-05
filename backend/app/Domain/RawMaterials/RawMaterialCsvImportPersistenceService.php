<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RawMaterialCsvImportPersistenceService
{
    private const OPERATION_CODE = 'raw-material.csv-import';

    private const STATUS_LABELS = [
        'NO_CHANGE' => 'BEZ ZMIAN',
        'INITIAL_METADATA_BASELINE' => 'BAZA POCZĄTKOWA METADANYCH',
        'METADATA_CHANGED' => 'ZMIANA DANYCH',
        'PRICE_CHANGED' => 'ZMIANA CENY',
        'PRICE_AND_METADATA_CHANGED' => 'ZMIANA CENY I DANYCH',
        'DATE_CONFLICT' => 'KONFLIKT DATY',
        'INVALID_ROW' => 'BŁĘDY',
        'BATCH_REJECTED' => 'BŁĘDY',
    ];

    public function __construct(
        private readonly RawMaterialAuthorization $authorization,
        private readonly AuthorizationMutationContext $contexts,
    ) {}

    /**
     * Persists rows that have already been parsed and unit-normalized by the caller.
     *
     * @param  list<array{
     *     ingredient_id:string,
     *     ingredient_name:string,
     *     price_net:string,
     *     normalized_net_pln_per_base_unit:string,
     *     vat_rate:string,
     *     unit:string,
     *     effective_date:string
     * }>  $rows
     */
    public function recordPreparedBatch(
        User $actor,
        Location $context,
        string $sourceSha256,
        string $idempotencyKey,
        array $rows,
    ): array {
        $this->validateRequest($sourceSha256, $idempotencyKey, $rows);
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);

        $requestHash = hash_hmac(
            'sha256',
            json_encode([
                'source_sha256' => $sourceSha256,
                'rows' => $this->canonicalize($rows),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string) config('app.key'),
        );

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::rawMaterialCsvImport(),
            $actor->id,
            $actor->organization_id,
            fn (): array => DB::transaction(function () use (
                $actor,
                $context,
                $sourceSha256,
                $idempotencyKey,
                $requestHash,
                $rows,
            ): array {
                Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);

                $prior = DB::table('raw_materials_commercial_idempotency')
                    ->where('organization_id', $actor->organization_id)
                    ->where('actor_user_id', $actor->id)
                    ->where('operation_code', self::OPERATION_CODE)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($prior) {
                    if (! hash_equals($prior->request_hash, $requestHash)) {
                        throw new RawMaterialException(
                            'RAW_MATERIAL_IDEMPOTENCY_CONFLICT',
                            'Klucz idempotencji został już użyty dla innego żądania.',
                        );
                    }

                    $batchId = json_decode((string) $prior->response_body, true, flags: JSON_THROW_ON_ERROR)['batch_id'];

                    return $this->presentBatch($actor->organization_id, $batchId);
                }

                $plans = $this->planRows($actor, $rows);
                $rejected = collect($plans)->contains(
                    fn (array $plan): bool => in_array($plan['result_code'], ['DATE_CONFLICT', 'INVALID_ROW'], true),
                );
                $batchId = Str::uuid()->toString();
                if (! $rejected) {
                    $plans = $this->assignHistoryIds($plans, $batchId, $actor);
                }
                $responseReference = ['batch_id' => $batchId];

                DB::table('raw_materials_commercial_idempotency')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $actor->organization_id,
                    'actor_user_id' => $actor->id,
                    'operation_code' => self::OPERATION_CODE,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_body' => json_encode($responseReference, JSON_THROW_ON_ERROR),
                ]);

                DB::table('raw_material_import_batches')->insert([
                    'id' => $batchId,
                    'organization_id' => $actor->organization_id,
                    'actor_user_id' => $actor->id,
                    'idempotency_record_id' => DB::table('raw_materials_commercial_idempotency')
                        ->where('organization_id', $actor->organization_id)
                        ->where('actor_user_id', $actor->id)
                        ->where('operation_code', self::OPERATION_CODE)
                        ->where('idempotency_key', $idempotencyKey)
                        ->value('id'),
                    'operation_code' => self::OPERATION_CODE,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'source_sha256' => $sourceSha256,
                    'batch_status' => $rejected ? 'REJECTED' : 'COMMITTED',
                    'row_count' => count($plans),
                ]);

                if (! $rejected) {
                    $this->writePriceAndMetadataHistory($plans, $batchId, $actor);
                }
                $this->writeRows($plans, $batchId, $actor->organization_id, $rejected);
                $this->writeAudit($actor, $context, $batchId, $sourceSha256, count($plans), $rejected);

                return $this->presentBatch($actor->organization_id, $batchId);
            }, attempts: 1),
        );
    }

    public function previewPreparedRows(User $actor, array $rows): array
    {
        $this->validateRequest(str_repeat('a', 64), 'preview-only-key-0001', $rows);

        return $this->planRows($actor, $rows);
    }

    public function recordPreviewAudit(
        User $actor,
        Location $context,
        string $previewId,
        string $sourceSha256,
        int $rowCount,
        bool $canCommit,
        array $statusCounts,
    ): void {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $sourceSha256) || $rowCount < 1) {
            throw new InvalidArgumentException('Dane audytu podglądu CSV są nieprawidłowe.');
        }
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::ReferencePriceManage);

        $this->contexts->runForSubject(
            AuthorizationMutationScope::rawMaterialCsvImport(),
            $actor->id,
            $actor->organization_id,
            fn () => DB::table('raw_materials_commercial_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'operation_id' => $previewId,
                'actor_user_id' => $actor->id,
                'actor_location_id' => $context->id,
                'subject_type' => 'RAW_MATERIAL_IMPORT_PREVIEW',
                'subject_id' => $previewId,
                'event_code' => 'CSV_IMPORT_PREVIEWED',
                'outcome' => $canCommit ? 'ACCEPTED' : 'REJECTED',
                'reason_code' => $canCommit ? null : 'CSV_VALIDATION_FAILED',
                'metadata' => json_encode([
                    'source_sha256' => $sourceSha256,
                    'row_count' => $rowCount,
                    'can_commit' => $canCommit,
                    'status_counts' => $statusCounts,
                ], JSON_THROW_ON_ERROR),
            ]),
        );
    }

    private function validateRequest(string $sourceSha256, string $idempotencyKey, array $rows): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $sourceSha256)) {
            throw new InvalidArgumentException('SHA-256 pliku CSV musi zawierać 64 małe znaki szesnastkowe.');
        }
        if (! preg_match('/\A[A-Za-z0-9._:-]{8,120}\z/', $idempotencyKey)) {
            throw new InvalidArgumentException('Klucz idempotencji ma nieprawidłowy format.');
        }
        if (! array_is_list($rows) || $rows === []) {
            throw new InvalidArgumentException('Import musi zawierać co najmniej jeden przygotowany wiersz.');
        }

        $expectedKeys = [
            'ingredient_id',
            'ingredient_name',
            'price_net',
            'normalized_net_pln_per_base_unit',
            'vat_rate',
            'unit',
            'effective_date',
        ];
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row) || array_diff(array_keys($row), $expectedKeys) !== []
                || array_diff($expectedKeys, array_keys($row)) !== []) {
                throw new InvalidArgumentException('Przygotowany wiersz importu ma nieprawidłowy kształt.');
            }
            if (! is_string($row['ingredient_id']) || ! preg_match('/\A(?:RAW|SUR)-[0-9]{6,9}\z/', $row['ingredient_id'])
                || ! is_string($row['ingredient_name']) || trim($row['ingredient_name']) === ''
                || mb_strlen($row['ingredient_name'], 'UTF-8') > 200) {
                throw new InvalidArgumentException('Identyfikator lub nazwa składnika ma nieprawidłowy format.');
            }
            $this->assertDecimal($row['price_net'], 14, 'Cena wejściowa');
            $this->assertDecimal($row['normalized_net_pln_per_base_unit'], 14, 'Cena znormalizowana');
            $this->assertDecimal($row['vat_rate'], 2, 'Stawka VAT');
            if (! DB::scalar('SELECT CAST(? AS numeric) < 100000000000000000', [$row['price_net']])
                || ! DB::scalar(
                    'SELECT CAST(? AS numeric) < 100000000000000000',
                    [$row['normalized_net_pln_per_base_unit']],
                )
                || ! DB::scalar('SELECT CAST(? AS numeric) BETWEEN 0 AND 100', [$row['vat_rate']])) {
                throw new InvalidArgumentException('Cena lub stawka VAT wykracza poza dopuszczalny zakres.');
            }
            if (! in_array($row['unit'], ['kg', 'l', 'szt.'], true)) {
                throw new InvalidArgumentException('Jednostka wejściowa musi mieć wartość kg, l albo szt.');
            }
            if (! is_string($row['effective_date'])) {
                throw new InvalidArgumentException('Data efektywności musi mieć format YYYY-MM-DD.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $row['effective_date']);
            if ($date === false || $date->format('Y-m-d') !== $row['effective_date']) {
                throw new InvalidArgumentException('Data efektywności musi mieć format YYYY-MM-DD.');
            }
        }
    }

    private function assertDecimal(mixed $value, int $scale, string $label): void
    {
        if (! is_string($value)
            || ! preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,'.$scale.'})?\z/', $value)) {
            throw new InvalidArgumentException($label.' musi być nieujemną liczbą dziesiętną o dozwolonej precyzji.');
        }
    }

    private function planRows(User $actor, array $rows): array
    {
        $occurrences = [];
        foreach ($rows as $index => $row) {
            $occurrences[$row['ingredient_id']][] = $index;
        }

        $plans = [];
        foreach ($rows as $index => $row) {
            $plan = [
                'row_number' => $index + 1,
                'input' => $row,
                'raw_material_id' => null,
                'current_name' => null,
                'result_code' => 'INVALID_ROW',
                'error_code' => null,
                'error_detail' => null,
                'metadata_event' => null,
                'price_event' => null,
                'effective_metadata_state_id' => null,
                'effective_reference_price_event_id' => null,
                'created_reference_price_event_id' => null,
            ];
            if (count($occurrences[$row['ingredient_id']]) > 1) {
                $plan['error_code'] = 'DUPLICATE_INGREDIENT_ID';
                $plan['error_detail'] = 'Identyfikator składnika występuje w pliku więcej niż raz.';
                $plans[] = $plan;

                continue;
            }

            $material = DB::table('raw_materials')
                ->where('organization_id', $actor->organization_id)
                ->where('code', $row['ingredient_id'])
                ->first();
            if (! $material) {
                $plan['error_code'] = 'UNKNOWN_INGREDIENT_ID';
                $plan['error_detail'] = 'Identyfikator SUR nie istnieje w organizacji bieżącego użytkownika.';
                $plans[] = $plan;

                continue;
            }
            $plan['raw_material_id'] = $material->id;

            $revision = $material->approved_revision_id === null ? null : DB::table('raw_material_revisions')
                ->where('organization_id', $actor->organization_id)
                ->where('raw_material_id', $material->id)
                ->where('id', $material->approved_revision_id)
                ->first();
            $plan['current_name'] = $revision?->name;
            if ($material->lifecycle_state !== 'ACTIVE' || $revision === null) {
                $plan['error_code'] = 'INGREDIENT_NOT_ACTIVE';
                $plan['error_detail'] = 'Składnik nie ma aktywnej, zatwierdzonej rewizji.';
                $plans[] = $plan;

                continue;
            }
            if ($revision->name !== $row['ingredient_name']) {
                $plan['error_code'] = 'INGREDIENT_NAME_MISMATCH';
                $plan['error_detail'] = 'Nazwa z pliku różni się od nazwy bieżącej zatwierdzonej rewizji.';
                $plans[] = $plan;

                continue;
            }

            $plan['result_code'] = 'NO_CHANGE';
            $plan['error_code'] = null;
            $plan['error_detail'] = null;
            if (! $this->isNormalized($row['normalized_net_pln_per_base_unit'])) {
                $plan['result_code'] = 'INVALID_ROW';
                $plan['error_code'] = 'NORMALIZED_PRICE_PRECISION';
                $plan['error_detail'] = 'Cena znormalizowana przekracza precyzję strumienia ceny referencyjnej.';
                $plans[] = $plan;

                continue;
            }
            $plans[] = $this->compareWithHistory($plan, $actor->organization_id);
        }

        return $plans;
    }

    private function isNormalized(string $value): bool
    {
        return (bool) DB::scalar(
            'SELECT CAST(? AS numeric) = raw_materials_round_half_even_v1(CAST(? AS numeric), 14)',
            [$value, $value],
        );
    }

    private function compareWithHistory(array $plan, string $organizationId): array
    {
        $materialId = $plan['raw_material_id'];
        $input = $plan['input'];
        $sameDatePrices = DB::table('raw_material_reference_price_events')
            ->where('organization_id', $organizationId)
            ->where('raw_material_id', $materialId)
            ->whereDate('effective_from', $input['effective_date'])
            ->orderBy('event_no')
            ->get();
        $sameDateMetadata = DB::table('raw_material_import_metadata_events')
            ->where('organization_id', $organizationId)
            ->where('raw_material_id', $materialId)
            ->whereDate('effective_from', $input['effective_date'])
            ->first();

        if ($sameDatePrices->count() > 1) {
            return $this->dateConflict($plan, 'MULTIPLE_PRICE_EVENTS_SAME_DATE',
                'Historia ceny zawiera więcej niż jedno zdarzenie dla tej daty.');
        }
        if ($sameDatePrices->isNotEmpty()) {
            $sameDatePrice = $sameDatePrices->first();
            $same = $sameDatePrice->event_type === 'PRICE_SET'
                && $this->numericEquals(
                    (string) $sameDatePrice->net_pln_per_base_unit,
                    $input['normalized_net_pln_per_base_unit'],
                );
            if (! $same) {
                return $this->dateConflict($plan, 'PRICE_STATE_EXISTS_ON_DATE',
                    'Dla tej daty istnieje inne zdarzenie ceny referencyjnej.');
            }
        }
        if ($sameDateMetadata
            && (! $this->numericEquals((string) $sameDateMetadata->vat_rate, $input['vat_rate'])
                || $sameDateMetadata->input_unit !== $input['unit'])) {
            return $this->dateConflict($plan, 'METADATA_STATE_EXISTS_ON_DATE',
                'Dla tej daty istnieje inny stan VAT lub jednostki wejściowej.');
        }

        $priceState = DB::table('raw_material_reference_price_events')
            ->where('organization_id', $organizationId)
            ->where('raw_material_id', $materialId)
            ->whereDate('effective_from', '<=', $input['effective_date'])
            ->orderByDesc('effective_from')
            ->orderByDesc('event_no')
            ->first();
        $plan['effective_reference_price_event_id'] = $priceState?->id;
        $priceChanged = $priceState === null || $priceState->event_type !== 'PRICE_SET'
            || ! $this->numericEquals(
                (string) $priceState->net_pln_per_base_unit,
                $input['normalized_net_pln_per_base_unit'],
            );

        $metadataState = DB::table('raw_material_import_metadata_events')
            ->where('organization_id', $organizationId)
            ->where('raw_material_id', $materialId)
            ->whereDate('effective_from', '<=', $input['effective_date'])
            ->orderByDesc('effective_from')
            ->orderByDesc('recorded_at')
            ->first();
        $plan['effective_metadata_state_id'] = $metadataState?->id;
        $initialBaseline = $metadataState === null;
        $metadataChanged = $metadataState !== null
            && (! $this->numericEquals((string) $metadataState->vat_rate, $input['vat_rate'])
                || $metadataState->input_unit !== $input['unit']);
        if ($sameDateMetadata) {
            $metadataChanged = false;
        }

        if ($initialBaseline) {
            $plan['result_code'] = 'INITIAL_METADATA_BASELINE';
            $plan['metadata_event'] = 'INITIAL_BASELINE';
        } elseif ($metadataChanged && $priceChanged) {
            $plan['result_code'] = 'PRICE_AND_METADATA_CHANGED';
            $plan['metadata_event'] = 'METADATA_CHANGE';
        } elseif ($metadataChanged) {
            $plan['result_code'] = 'METADATA_CHANGED';
            $plan['metadata_event'] = 'METADATA_CHANGE';
        } elseif ($priceChanged) {
            $plan['result_code'] = 'PRICE_CHANGED';
        } else {
            $plan['result_code'] = 'NO_CHANGE';
        }

        if ($priceChanged) {
            $plan['price_event'] = [
                'effective_from' => $input['effective_date'],
                'net_pln_per_base_unit' => $input['normalized_net_pln_per_base_unit'],
            ];
        }

        return $plan;
    }

    private function dateConflict(array $plan, string $errorCode, string $detail): array
    {
        $plan['result_code'] = 'DATE_CONFLICT';
        $plan['error_code'] = $errorCode;
        $plan['error_detail'] = $detail;

        return $plan;
    }

    private function numericEquals(string $left, string $right): bool
    {
        return (bool) DB::scalar('SELECT CAST(? AS numeric) = CAST(? AS numeric)', [$left, $right]);
    }

    private function assignHistoryIds(array $plans, string $batchId, User $actor): array
    {
        $nextEventNumbers = [];
        foreach ($plans as &$plan) {
            if ($plan['error_code'] !== null) {
                continue;
            }

            $materialId = $plan['raw_material_id'];
            if ($plan['price_event'] !== null) {
                $nextEventNumbers[$materialId] ??= (int) DB::table('raw_material_reference_price_events')
                    ->where('organization_id', $actor->organization_id)
                    ->where('raw_material_id', $materialId)
                    ->max('event_no');
                $plan['price_event'] = [
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $actor->organization_id,
                    'raw_material_id' => $materialId,
                    'event_no' => ++$nextEventNumbers[$materialId],
                    'event_type' => 'PRICE_SET',
                    ...$plan['price_event'],
                    'reason_code' => 'CSV_IMPORT',
                    'recorded_by' => $actor->id,
                    'operation_id' => $batchId,
                ];
                $plan['created_reference_price_event_id'] = $plan['price_event']['id'];
                $plan['effective_reference_price_event_id'] = $plan['price_event']['id'];
            }
            if ($plan['metadata_event'] !== null) {
                $plan['effective_metadata_state_id'] = Str::uuid()->toString();
            }
        }
        unset($plan);

        return $plans;
    }

    private function writePriceAndMetadataHistory(array &$plans, string $batchId, User $actor): void
    {
        foreach ($plans as &$plan) {
            if ($plan['error_code'] !== null) {
                continue;
            }
            if ($plan['price_event'] !== null) {
                DB::table('raw_material_reference_price_events')->insert($plan['price_event']);
            }
        }
        unset($plan);

        foreach ($plans as &$plan) {
            if ($plan['error_code'] !== null || $plan['metadata_event'] === null) {
                continue;
            }
            DB::table('raw_material_import_metadata_events')->insert([
                'id' => $plan['effective_metadata_state_id'],
                'organization_id' => $actor->organization_id,
                'raw_material_id' => $plan['raw_material_id'],
                'batch_id' => $batchId,
                'source_row_number' => $plan['row_number'],
                'effective_from' => $plan['input']['effective_date'],
                'event_type' => $plan['metadata_event'],
                'vat_rate' => $plan['input']['vat_rate'],
                'input_unit' => $plan['input']['unit'],
                'effective_reference_price_event_id' => $plan['effective_reference_price_event_id'],
                'recorded_by' => $actor->id,
            ]);
        }
        unset($plan);
    }

    private function writeRows(array $plans, string $batchId, string $organizationId, bool $rejected): void
    {
        foreach ($plans as $plan) {
            $resultCode = $plan['result_code'];
            $errorCode = $plan['error_code'];
            $errorDetail = $plan['error_detail'];
            if ($rejected && ! in_array($resultCode, ['DATE_CONFLICT', 'INVALID_ROW'], true)) {
                $resultCode = 'BATCH_REJECTED';
                $errorCode = 'BATCH_ATOMIC_REJECTION';
                $errorDetail = 'Batch odrzucono atomowo; żadne zmiany ceny ani metadanych nie zostały zapisane.';
            }
            DB::table('raw_material_import_rows')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $organizationId,
                'batch_id' => $batchId,
                'row_number' => $plan['row_number'],
                'raw_material_id' => $plan['raw_material_id'],
                'ingredient_code' => $plan['input']['ingredient_id'],
                'input_ingredient_name' => $plan['input']['ingredient_name'],
                'current_ingredient_name' => $plan['current_name'],
                'source_price_net' => $plan['input']['price_net'],
                'normalized_net_pln_per_base_unit' => $plan['input']['normalized_net_pln_per_base_unit'],
                'vat_rate' => $plan['input']['vat_rate'],
                'input_unit' => $plan['input']['unit'],
                'effective_date' => $plan['input']['effective_date'],
                'result_code' => $resultCode,
                'error_code' => $errorCode,
                'error_detail' => $errorDetail,
                'effective_metadata_state_id' => $plan['effective_metadata_state_id'],
                'effective_reference_price_event_id' => $plan['effective_reference_price_event_id'],
                'created_reference_price_event_id' => $rejected ? null : $plan['created_reference_price_event_id'],
            ]);
        }
    }

    private function writeAudit(
        User $actor,
        Location $context,
        string $batchId,
        string $sourceSha256,
        int $rowCount,
        bool $rejected,
    ): void {
        DB::table('raw_materials_commercial_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => $batchId,
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'subject_type' => 'RAW_MATERIAL_IMPORT_BATCH',
            'subject_id' => $batchId,
            'event_code' => $rejected ? 'CSV_IMPORT_REJECTED' : 'CSV_IMPORT_COMMITTED',
            'outcome' => $rejected ? 'REJECTED' : 'ACCEPTED',
            'reason_code' => $rejected ? 'CSV_VALIDATION_FAILED' : null,
            'metadata' => json_encode([
                'source_sha256' => $sourceSha256,
                'row_count' => $rowCount,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function presentBatch(string $organizationId, string $batchId): array
    {
        $batch = DB::table('raw_material_import_batches')
            ->where('organization_id', $organizationId)
            ->where('id', $batchId)
            ->first();
        if (! $batch) {
            throw new RawMaterialException('RAW_MATERIAL_IMPORT_NOT_FOUND', 'Nie znaleziono zapisanego batcha importu.', 404);
        }

        $rows = DB::table('raw_material_import_rows')
            ->where('organization_id', $organizationId)
            ->where('batch_id', $batchId)
            ->orderBy('row_number')
            ->get();

        return [
            'batch_id' => $batch->id,
            'batch_status' => $batch->batch_status,
            'source_sha256' => $batch->source_sha256,
            'actor_user_id' => $batch->actor_user_id,
            'organization_id' => $batch->organization_id,
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
                'effective_metadata_state_id' => $row->effective_metadata_state_id,
                'effective_reference_price_event_id' => $row->effective_reference_price_event_id,
                'created_reference_price_event_id' => $row->created_reference_price_event_id,
            ])->all(),
        ];
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
