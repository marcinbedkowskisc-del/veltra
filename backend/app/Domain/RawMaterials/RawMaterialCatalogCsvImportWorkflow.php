<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RawMaterialCatalogCsvImportWorkflow
{
    private const STATUS_LABELS = [
        'CREATE' => 'UTWORZENIE',
        'NO_CHANGE' => 'BEZ ZMIAN',
        'CONFLICT' => 'KONFLIKT',
        'INVALID' => 'BŁĄD',
    ];

    public function __construct(
        private readonly RawMaterialCatalogCsvParser $parser,
        private readonly RawMaterialService $rawMaterials,
        private readonly RawMaterialAuthorization $authorization,
        private readonly RawMaterialLocationSharePolicy $sharePolicy,
        private readonly AuthorizationMutationContext $contexts,
    ) {}

    public function upload(User $actor, Location $context, UploadedFile $file): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);

        $maxBytes = (int) config('raw_material_csv.catalog_max_file_bytes');
        if (! $file->isValid() || $file->getSize() > $maxBytes) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CATALOG_CSV_UPLOAD_INVALID',
                'Nie udało się odczytać pliku CSV albo przekracza on dozwolony rozmiar.',
                422,
            );
        }

        $path = $file->getRealPath();
        $contents = is_string($path) && $path !== '' ? file_get_contents($path) : false;
        if (! is_string($contents)) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CATALOG_CSV_READ_FAILED',
                'Nie udało się bezpiecznie odczytać przesłanego pliku CSV.',
                422,
            );
        }

        $rows = $this->parser->parse($contents);
        $preview = $this->buildPreview($actor, $rows);
        $previewId = Str::uuid()->toString();
        $sourceHash = hash('sha256', $contents);
        $storageKey = $this->storageKey($actor, $previewId);

        $this->pruneExpiredUploads();
        $disk = Storage::disk('raw_material_csv_imports');
        if (! $disk->put($storageKey, $contents)) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CATALOG_CSV_STORAGE_FAILED',
                'Nie udało się bezpiecznie zapisać prywatnego pliku katalogu.',
                500,
            );
        }

        $data = [
            'preview_id' => $previewId,
            'organization_id' => $actor->organization_id,
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'storage_key' => $storageKey,
            'source_sha256' => $sourceHash,
            'parsed_rows' => $rows,
            'prepared_rows' => $preview['prepared_rows'],
            'snapshot_hash' => $preview['snapshot_hash'],
            'can_commit' => $preview['can_commit'],
            'rows' => $preview['rows'],
        ];
        if (! Cache::put(
            $this->previewKey($actor, $previewId),
            $data,
            now()->addMinutes((int) config('raw_material_csv.catalog_preview_ttl_minutes')),
        )) {
            Cache::forget($this->previewKey($actor, $previewId));
            if (! $disk->delete($storageKey)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CATALOG_CSV_CLEANUP_FAILED',
                    'Nie udało się usunąć prywatnego pliku po błędzie zapisu podglądu.',
                    500,
                );
            }
            throw new RawMaterialException(
                'RAW_MATERIAL_CATALOG_CSV_PREVIEW_STORAGE_FAILED',
                'Nie udało się bezpiecznie zapisać podglądu importu katalogu.',
                500,
            );
        }

        $counts = collect($preview['rows'])->countBy('status_code')->all();
        $this->recordAudit(
            $actor,
            $context,
            $previewId,
            'RAW_MATERIAL_CATALOG_IMPORT_PREVIEW',
            'CATALOG_CSV_IMPORT_PREVIEWED',
            $preview['can_commit'] ? 'ACCEPTED' : 'REJECTED',
            [
                'source_sha256' => $sourceHash,
                'row_count' => count($rows),
                'status_counts' => $counts,
            ],
            $preview['can_commit'] ? null : 'CSV_VALIDATION_FAILED',
        );

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
                'RAW_MATERIAL_CATALOG_CSV_CONFIRMATION_REQUIRED',
                'Zatwierdzenie importu wymaga jawnego potwierdzenia operatora.',
                422,
            );
        }
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);

        $key = $this->previewKey($actor, $previewId);
        try {
            return Cache::lock($key.':commit-lock', 30)->block(5, function () use (
                $actor,
                $context,
                $previewId,
                $idempotencyKey,
                $key,
            ): array {
                $committed = $this->batchForPreview($actor, $previewId);
                if ($committed !== null) {
                    if ($committed->idempotency_key !== $idempotencyKey) {
                        throw new RawMaterialException(
                            'RAW_MATERIAL_CATALOG_CSV_IDEMPOTENCY_CONFLICT',
                            'Podgląd został już zatwierdzony z innym kluczem idempotencji.',
                            409,
                        );
                    }

                    return $this->presentBatch($actor, $committed->id);
                }

                $data = Cache::get($key);
                if (! is_array($data)
                    || $data['organization_id'] !== $actor->organization_id
                    || $data['actor_user_id'] !== $actor->id
                    || $data['actor_location_id'] !== $context->id) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CATALOG_CSV_PREVIEW_NOT_FOUND',
                        'Podgląd importu katalogu wygasł albo nie istnieje.',
                        404,
                    );
                }
                if (! $data['can_commit']) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CATALOG_CSV_PREVIEW_HAS_ERRORS',
                        'Nie można zatwierdzić importu katalogu zawierającego błędy lub konflikty.',
                        422,
                        ['preview_id' => $previewId],
                    );
                }

                $disk = Storage::disk('raw_material_csv_imports');
                if (! $disk->exists($data['storage_key'])) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CATALOG_CSV_SOURCE_MISSING',
                        'Prywatny plik źródłowy podglądu nie jest już dostępny.',
                        410,
                    );
                }
                $contents = $disk->get($data['storage_key']);
                if (! is_string($contents)) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CATALOG_CSV_SOURCE_READ_FAILED',
                        'Nie udało się odczytać prywatnego pliku CSV.',
                        410,
                    );
                }
                if (! hash_equals($data['source_sha256'], hash('sha256', $contents))) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CATALOG_CSV_SOURCE_CHANGED',
                        'Suma kontrolna pliku CSV nie jest zgodna z zapamiętanym podglądem.',
                        409,
                    );
                }

                $freshRows = $this->parser->parse($contents);
                $result = $this->persist(
                    $actor,
                    $context,
                    $data,
                    $freshRows,
                    $idempotencyKey,
                );

                return $result;
            });
        } catch (LockTimeoutException) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CATALOG_CSV_COMMIT_IN_PROGRESS',
                'Ten podgląd jest już zatwierdzany. Spróbuj ponownie za chwilę.',
                409,
            );
        }
    }

    public function result(User $actor, Location $context, string $batchId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);

        $batch = DB::table('raw_material_catalog_import_batches')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $batchId)
            ->first();
        if (! $batch) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CATALOG_IMPORT_NOT_FOUND',
                'Nie znaleziono wyniku importu katalogu w organizacji bieżącego użytkownika.',
                404,
            );
        }

        if (! $this->sharePolicy->isPrimaryContext($context)) {
            DB::table('raw_material_catalog_import_rows')
                ->where('organization_id', $actor->organization_id)
                ->where('batch_id', $batchId)
                ->orderBy('row_number')
                ->pluck('raw_material_id')
                ->each(fn (string $materialId) => $this->sharePolicy->assertCanConsume($actor, $context, $materialId));
        }

        return $this->presentBatch($actor, $batchId);
    }

    private function persist(
        User $actor,
        Location $context,
        array $data,
        array $rows,
        string $idempotencyKey,
    ): array {
        $requestHash = hash('sha256', $data['source_sha256']."\0".$data['snapshot_hash']);

        return DB::transaction(function () use (
            $actor,
            $context,
            $data,
            $rows,
            $idempotencyKey,
            $requestHash,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);

            $existingByKey = DB::table('raw_material_catalog_import_batches')
                ->where('organization_id', $actor->organization_id)
                ->where('actor_user_id', $actor->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingByKey) {
                if (! hash_equals($existingByKey->request_hash, $requestHash)) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CATALOG_CSV_IDEMPOTENCY_CONFLICT',
                        'Klucz idempotencji został już użyty dla innego pliku lub podglądu.',
                        409,
                    );
                }

                return $this->presentBatch($actor, $existingByKey->id);
            }

            $existingByPreview = $this->batchForPreview($actor, $data['preview_id']);
            if ($existingByPreview !== null) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CATALOG_CSV_IDEMPOTENCY_CONFLICT',
                    'Podgląd został już zatwierdzony z innym kluczem idempotencji.',
                    409,
                );
            }

            $fresh = $this->buildPreview($actor, $rows);
            if (! $fresh['can_commit'] || ! hash_equals($data['snapshot_hash'], $fresh['snapshot_hash'])) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CATALOG_CSV_PREVIEW_STALE',
                    'Katalog zmienił się od czasu podglądu. Wykonaj nowy podgląd przed zatwierdzeniem.',
                    409,
                    ['preview_id' => $data['preview_id']],
                );
            }

            $batchId = Str::uuid()->toString();
            $this->contexts->runForSubject(
                AuthorizationMutationScope::rawMaterialCsvImport(),
                $actor->id,
                $actor->organization_id,
                fn () => DB::table('raw_material_catalog_import_batches')->insert([
                    'id' => $batchId,
                    'organization_id' => $actor->organization_id,
                    'actor_user_id' => $actor->id,
                    'actor_location_id' => $context->id,
                    'preview_id' => $data['preview_id'],
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'source_sha256' => $data['source_sha256'],
                    'batch_status' => 'COMMITTED',
                    'row_count' => count($fresh['prepared_rows']),
                ]),
            );

            foreach ($fresh['prepared_rows'] as $row) {
                $materialId = $row['raw_material_id'];
                if ($row['status_code'] === 'CREATE') {
                    $material = $this->rawMaterials->createImported(
                        $actor,
                        $context,
                        $row['sku'],
                        [
                            'category_id' => $row['category_id'],
                            'name' => $row['name'],
                            'description' => $row['description'],
                            'base_unit_code' => $row['base_unit_code'],
                            'nominal_quantity' => $row['nominal_quantity'],
                            ...(! $row['usable_quantity_provided'] ? [] : [
                                'usable_quantity' => $row['usable_quantity'],
                            ]),
                        ],
                    );
                    $materialId = $material['id'];
                }

                $this->contexts->runForSubject(
                    AuthorizationMutationScope::rawMaterialCsvImport(),
                    $actor->id,
                    $actor->organization_id,
                    fn () => DB::table('raw_material_catalog_import_rows')->insert([
                        'id' => Str::uuid()->toString(),
                        'organization_id' => $actor->organization_id,
                        'batch_id' => $batchId,
                        'row_number' => $row['row_number'],
                        'raw_material_id' => $materialId,
                        'sku' => $row['sku'],
                        'entity_type' => $row['entity_type'],
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'base_unit_code' => $row['base_unit_code'],
                        'category_code' => $row['category_code'],
                        'nominal_quantity' => $row['nominal_quantity'],
                        'usable_quantity' => $row['usable_quantity'],
                        'usable_quantity_explicit' => $row['usable_quantity_provided'],
                        'result_code' => $row['status_code'] === 'CREATE' ? 'CREATED' : 'NO_CHANGE',
                    ]),
                );
            }

            $this->recordAudit(
                $actor,
                $context,
                $batchId,
                'RAW_MATERIAL_CATALOG_IMPORT_BATCH',
                'CATALOG_CSV_IMPORT_COMMITTED',
                'ACCEPTED',
                [
                    'preview_id' => $data['preview_id'],
                    'source_sha256' => $data['source_sha256'],
                    'request_hash' => $requestHash,
                    'row_count' => count($fresh['prepared_rows']),
                ],
            );

            return $this->presentBatch($actor, $batchId);
        }, attempts: 1);
    }

    private function buildPreview(User $actor, array $rows): array
    {
        $categoryCodes = array_values(array_unique(array_filter(
            array_column($rows, 'category_code'),
            fn (string $code): bool => $code !== '',
        )));
        $categories = $categoryCodes === [] ? collect() : DB::table('raw_material_categories')
            ->where('organization_id', $actor->organization_id)
            ->whereIn('code', $categoryCodes)
            ->get(['id', 'code'])
            ->keyBy('code');

        $validSkus = [];
        foreach ($rows as $row) {
            if (preg_match('/\ARAW-[0-9]{6,9}\z/', $row['sku']) === 1) {
                $validSkus[] = $row['sku'];
            }
        }
        $validSkus = array_values(array_unique($validSkus));
        $assignments = $validSkus === [] ? collect() : DB::table('organization_sku_assignments')
            ->where('organization_id', $actor->organization_id)
            ->whereIn('sku', $validSkus)
            ->get(['sku', 'entity_type', 'entity_id', 'prefix', 'sequence_number', 'allocation_source'])
            ->keyBy('sku');
        $materials = $validSkus === [] ? collect() : DB::table('raw_materials as materials')
            ->leftJoin('raw_material_revisions as revisions', function ($join): void {
                $join->on(
                    'revisions.id',
                    '=',
                    DB::raw('COALESCE(materials.working_revision_id, materials.pending_revision_id, materials.approved_revision_id)'),
                )->on('revisions.organization_id', '=', 'materials.organization_id')
                    ->on('revisions.raw_material_id', '=', 'materials.id');
            })
            ->leftJoin('raw_material_categories as categories', function ($join): void {
                $join->on('categories.id', '=', 'revisions.category_id')
                    ->on('categories.organization_id', '=', 'materials.organization_id');
            })
            ->where('materials.organization_id', $actor->organization_id)
            ->whereIn('materials.code', $validSkus)
            ->get([
                'materials.id',
                'materials.code',
                'materials.lifecycle_state',
                'materials.deleted_at',
                'materials.working_revision_id',
                'materials.pending_revision_id',
                'materials.approved_revision_id',
                'revisions.id as revision_id',
                'revisions.name',
                'revisions.description',
                'revisions.base_unit_code',
                'revisions.nominal_quantity',
                'revisions.usable_quantity',
                'categories.code as category_code',
            ])
            ->keyBy('code');

        $preparedRows = [];
        $previewRows = [];
        $snapshots = [];
        $canCommit = true;

        foreach ($rows as $row) {
            $error = $this->validateRow($row);
            if ($error === null) {
                try {
                    $quantities = RawMaterialCatalogValues::quantities([
                        'base_unit_code' => $row['base_unit_code'],
                        'nominal_quantity' => $row['nominal_quantity'],
                        ...($row['usable_quantity'] === null ? [] : [
                            'usable_quantity' => $row['usable_quantity'],
                        ]),
                    ]);
                    $row = [...$row, ...$quantities];
                } catch (ValidationException $exception) {
                    $error = [
                        'code' => 'QUANTITY_INVALID',
                        'message' => $exception->validator->errors()->first(),
                    ];
                }
            }
            $category = $categories->get($row['category_code']);
            if ($error === null && $category === null) {
                $error = [
                    'code' => 'CATEGORY_NOT_FOUND',
                    'message' => "Kod kategorii {$row['category_code']} nie istnieje w organizacji bieżącego użytkownika.",
                ];
            }
            if ($error === null && $row['duplicate_row_numbers'] !== []) {
                $numbers = implode(', ', $row['duplicate_row_numbers']);
                $error = [
                    'code' => 'DUPLICATE_SKU',
                    'message' => "SKU {$row['sku']} powtarza się w wierszach {$numbers}.",
                ];
            }

            $assignment = $assignments->get($row['sku']);
            $material = $materials->get($row['sku']);
            $statusCode = 'INVALID';
            $materialId = null;
            if ($error === null) {
                if ($material?->deleted_at !== null) {
                    $statusCode = 'CONFLICT';
                    $error = [
                        'code' => 'TOMBSTONED_MATERIAL',
                        'message' => "SKU {$row['sku']} należy do trwale usuniętego szkicu i nie może zostać ponownie użyty.",
                    ];
                } elseif ($assignment !== null || $material !== null) {
                    $matchesAssignment = $assignment !== null
                        && $assignment->entity_type === 'RAW'
                        && $material !== null
                        && $assignment->entity_id === $material->id;
                    $matchesAttributes = $matchesAssignment
                        && $material->revision_id !== null
                        && $material->name === $row['name']
                        && $material->description === $row['description']
                        && $material->base_unit_code === $row['base_unit_code']
                        && $material->nominal_quantity === $row['nominal_quantity']
                        && $material->usable_quantity === $row['usable_quantity']
                        && $material->category_code === $row['category_code'];
                    if ($matchesAttributes) {
                        $statusCode = 'NO_CHANGE';
                        $materialId = $material->id;
                    } else {
                        $statusCode = 'CONFLICT';
                        $error = [
                            'code' => 'SKU_CONFLICT',
                            'message' => "SKU {$row['sku']} jest już przypisany do innego rekordu albo ma inne atrybuty.",
                        ];
                    }
                } else {
                    if (preg_match('/\ARAW-(?!000000)[0-9]{6}\z/', $row['sku']) !== 1) {
                        $error = ['code' => 'SKU_INVALID', 'message' => 'Nowy SKU RAW musi mieć dokładnie sześć cyfr w zakresie 000001–999999.'];
                    } else {
                        $statusCode = 'CREATE';
                    }
                }
            }

            if ($error !== null) {
                $canCommit = false;
            } else {
                $preparedRows[] = [
                    ...$row,
                    'category_id' => $category->id,
                    'status_code' => $statusCode,
                    'raw_material_id' => $materialId,
                ];
            }

            $snapshots[] = [
                'row_number' => $row['row_number'],
                'category_id' => $category?->id,
                'assignment' => $assignment === null ? null : [
                    'entity_type' => $assignment->entity_type,
                    'entity_id' => $assignment->entity_id,
                    'prefix' => $assignment->prefix,
                    'sequence_number' => (int) $assignment->sequence_number,
                    'allocation_source' => $assignment->allocation_source,
                ],
                'material' => $material === null ? null : [
                    'id' => $material->id,
                    'lifecycle_state' => $material->lifecycle_state,
                    'revision_id' => $material->revision_id,
                    'name' => $material->name,
                    'description' => $material->description,
                    'base_unit_code' => $material->base_unit_code,
                    'nominal_quantity' => $material->nominal_quantity,
                    'usable_quantity' => $material->usable_quantity,
                    'category_code' => $material->category_code,
                ],
                'error_code' => $error['code'] ?? null,
                'status_code' => $statusCode,
            ];
            $previewRows[] = [
                'row_number' => $row['row_number'],
                'sku' => $row['sku'],
                'entity_type' => $row['entity_type'],
                'name' => $row['name'],
                'description' => $row['description'],
                'base_unit_code' => $row['base_unit_code'],
                'category_code' => $row['category_code'],
                'nominal_quantity' => $row['nominal_quantity'],
                'usable_quantity' => $row['usable_quantity'],
                'status_code' => $statusCode,
                'status' => self::STATUS_LABELS[$statusCode],
                'error_code' => $error['code'] ?? null,
                'error' => $error['message'] ?? null,
            ];
        }

        $snapshotHash = hash('sha256', json_encode([
            'rows' => $rows,
            'snapshots' => $snapshots,
            'prepared_rows' => $preparedRows,
            'preview_rows' => $previewRows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'prepared_rows' => $preparedRows,
            'rows' => $previewRows,
            'can_commit' => $canCommit,
            'snapshot_hash' => $snapshotHash,
        ];
    }

    private function validateRow(array $row): ?array
    {
        if ($row['sku'] === '') {
            return ['code' => 'SKU_REQUIRED', 'message' => 'Pole SKU jest wymagane.'];
        }
        if (preg_match('/\ARAW-[0-9]{6,9}\z/', $row['sku']) !== 1) {
            return ['code' => 'SKU_INVALID', 'message' => 'SKU musi mieć format RAW-000001; istniejące historyczne numery pozostają obsługiwane.'];
        }
        if ($row['entity_type'] !== 'RAW') {
            return ['code' => 'ENTITY_TYPE_UNSUPPORTED', 'message' => 'W tej wersji import obsługuje wyłącznie entity_type RAW.'];
        }
        if (trim($row['name']) === '' || mb_strlen($row['name'], 'UTF-8') > 200) {
            return ['code' => 'NAME_INVALID', 'message' => 'Nazwa jest pusta albo przekracza 200 znaków.'];
        }
        if (! in_array($row['base_unit_code'], ['mg', 'g', 'kg', 'ml', 'l', 'pc'], true)) {
            return ['code' => 'BASE_UNIT_INVALID', 'message' => 'Jednostka bazowa nie jest obsługiwana dla RAW.'];
        }
        if ($row['nominal_quantity'] === null) {
            return ['code' => 'NOMINAL_QUANTITY_REQUIRED', 'message' => 'Ilość nominalna opakowania jest wymagana.'];
        }
        if (preg_match('/\A[A-Z0-9_-]{1,50}\z/', $row['category_code']) !== 1) {
            return ['code' => 'CATEGORY_CODE_INVALID', 'message' => 'Kod kategorii jest pusty albo ma nieprawidłowy format.'];
        }
        if ($row['duplicate_row_numbers'] !== []) {
            $numbers = implode(', ', $row['duplicate_row_numbers']);

            return [
                'code' => 'DUPLICATE_SKU',
                'message' => "SKU {$row['sku']} powtarza się w wierszach {$numbers}.",
            ];
        }

        return null;
    }

    private function presentPreview(array $data): array
    {
        return [
            'preview_id' => $data['preview_id'],
            'source_sha256' => $data['source_sha256'],
            'row_count' => count($data['rows']),
            'can_commit' => $data['can_commit'],
            'expires_at' => now()->addMinutes((int) config('raw_material_csv.catalog_preview_ttl_minutes'))->toIso8601String(),
            'rows' => $data['rows'],
        ];
    }

    private function presentBatch(User $actor, string $batchId): array
    {
        $batch = DB::table('raw_material_catalog_import_batches')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $batchId)
            ->first();
        if (! $batch) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CATALOG_IMPORT_NOT_FOUND',
                'Nie znaleziono zapisanego batcha importu katalogu.',
                404,
            );
        }

        $rows = DB::table('raw_material_catalog_import_rows')
            ->where('organization_id', $actor->organization_id)
            ->where('batch_id', $batchId)
            ->orderBy('row_number')
            ->get();

        return [
            'batch_id' => $batch->id,
            'batch_status' => $batch->batch_status,
            'preview_id' => $batch->preview_id,
            'source_sha256' => $batch->source_sha256,
            'actor_user_id' => $batch->actor_user_id,
            'created_at' => $batch->created_at,
            'row_count' => (int) $batch->row_count,
            'rows' => $rows->map(fn (object $row): array => [
                'row_number' => (int) $row->row_number,
                'sku' => $row->sku,
                'entity_type' => $row->entity_type,
                'name' => $row->name,
                'description' => $row->description,
                'base_unit_code' => $row->base_unit_code,
                'category_code' => $row->category_code,
                'nominal_quantity' => $row->nominal_quantity,
                'usable_quantity' => $row->usable_quantity,
                'usable_quantity_provided' => (bool) $row->usable_quantity_explicit,
                'status' => $row->result_code === 'CREATED' ? 'UTWORZENIE' : 'BEZ ZMIAN',
            ])->all(),
        ];
    }

    private function batchForPreview(User $actor, string $previewId): ?object
    {
        return DB::table('raw_material_catalog_import_batches')
            ->where('organization_id', $actor->organization_id)
            ->where('actor_user_id', $actor->id)
            ->where('preview_id', $previewId)
            ->first();
    }

    private function recordAudit(
        User $actor,
        Location $context,
        string $operationId,
        string $subjectType,
        string $eventCode,
        string $outcome,
        array $metadata,
        ?string $reasonCode = null,
    ): void {
        $this->contexts->runForSubject(
            AuthorizationMutationScope::rawMaterialCsvImport(),
            $actor->id,
            $actor->organization_id,
            fn () => $this->insertAudit(
                $actor,
                $context,
                $operationId,
                $subjectType,
                $eventCode,
                $outcome,
                $metadata,
                $reasonCode,
            ),
        );
    }

    private function insertAudit(
        User $actor,
        Location $context,
        string $operationId,
        string $subjectType,
        string $eventCode,
        string $outcome,
        array $metadata,
        ?string $reasonCode = null,
    ): void {
        DB::table('raw_materials_commercial_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => $operationId,
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'subject_type' => $subjectType,
            'subject_id' => $operationId,
            'event_code' => $eventCode,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function previewKey(User $actor, string $previewId): string
    {
        return 'raw-material-catalog-csv-import:'.$actor->organization_id.':'.$actor->id.':'.$previewId;
    }

    private function storageKey(User $actor, string $previewId): string
    {
        return 'catalog-imports/'.$actor->organization_id.'/'.$actor->id.'/'.$previewId.'.csv';
    }

    private function pruneExpiredUploads(): void
    {
        $disk = Storage::disk('raw_material_csv_imports');
        $expiredBefore = now()->subMinutes((int) config('raw_material_csv.catalog_preview_ttl_minutes'))->getTimestamp();
        foreach ($disk->allFiles('catalog-imports') as $path) {
            if ($disk->lastModified($path) < $expiredBefore && ! $disk->delete($path)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CATALOG_CSV_CLEANUP_FAILED',
                    'Nie udało się usunąć wygasłego pliku prywatnego importu katalogu.',
                    500,
                );
            }
        }
    }
}
