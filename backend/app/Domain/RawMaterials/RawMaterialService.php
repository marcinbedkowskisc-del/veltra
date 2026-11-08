<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Sku\SkuAllocationService;
use App\Domain\Sku\SkuPrefix;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RawMaterialService
{
    public function __construct(
        private RawMaterialAuthorization $authorization,
        private RawMaterialLocationSharePolicy $sharePolicy,
        private AuthorizationMutationContext $contexts,
        private SkuAllocationService $skuAllocation,
    ) {}

    public function categories(User $actor, Location $context): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);

        return DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
            ->orderBy('depth')->orderBy('name')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    public function createCategory(User $actor, Location $context, array $input): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::CategoryManage);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $input): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::CategoryManage);
            $parent = null;
            if (isset($input['parent_id'])) {
                $parent = DB::table('raw_material_categories')
                    ->where('organization_id', $actor->organization_id)->where('id', $input['parent_id'])->first();
                if (! $parent) {
                    throw new RawMaterialException('RAW_MATERIAL_CATEGORY_NOT_FOUND', __('messages.resource_not_found'), 404);
                }
            }
            $depth = $parent ? $parent->depth + 1 : 1;
            if ($depth > 3) {
                throw ValidationException::withMessages([
                    'parent_id' => [__('messages.raw_material_category_depth_exceeded')],
                ]);
            }
            $id = Str::uuid()->toString();
            $now = now();
            DB::table('raw_material_categories')->insert([
                'id' => $id,
                'organization_id' => $actor->organization_id,
                'parent_id' => $parent?->id,
                'code' => $input['code'],
                'name' => trim($input['name']),
                'depth' => $depth,
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit($actor, $context, 'CATEGORY', $id, 'CATEGORY_CREATED', null, ['code' => $input['code']]);

            return (array) DB::table('raw_material_categories')->where('id', $id)->first();
        }));
    }

    public function renameCategory(User $actor, Location $context, string $categoryId, string $name): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::CategoryManage);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $categoryId, $name): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::CategoryManage);
            $category = DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
                ->where('id', $categoryId)->lockForUpdate()->first();
            if (! $category) {
                throw new RawMaterialException('RAW_MATERIAL_CATEGORY_NOT_FOUND', __('messages.resource_not_found'), 404);
            }
            DB::table('raw_material_categories')->where('id', $categoryId)->update(['name' => trim($name), 'updated_at' => now()]);
            $this->audit($actor, $context, 'CATEGORY', $categoryId, 'CATEGORY_RENAMED', null, ['code' => $category->code]);

            return (array) DB::table('raw_material_categories')->where('id', $categoryId)->first();
        }));
    }

    public function list(User $actor, Location $context, array $filters): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $query = DB::table('raw_materials as materials')
            ->leftJoin('raw_material_revisions as revisions', function ($join): void {
                $join->on('revisions.id', '=', 'materials.approved_revision_id')
                    ->on('revisions.organization_id', '=', 'materials.organization_id');
            })
            ->leftJoin('raw_material_categories as categories', 'categories.id', '=', 'revisions.category_id')
            ->where('materials.organization_id', $actor->organization_id)
            ->whereNotNull('materials.approved_revision_id')
            ->select([
                'materials.id', 'materials.code', 'materials.lifecycle_state',
                'revisions.id as revision_id', 'revisions.revision_no', 'revisions.name',
                'revisions.base_unit_code', 'revisions.allergen_state',
                'categories.id as category_id', 'categories.name as category_name',
            ]);
        $this->sharePolicy->constrainVisibleMaterials($query, $actor, $context);

        if (isset($filters['search'])) {
            $search = self::normalizeTerm($filters['search']);
            $query->where(function ($builder) use ($search, $filters): void {
                $builder->whereRaw('lower(revisions.name) LIKE ?', ['%'.addcslashes($search, '%_\\').'%'])
                    ->orWhere('materials.code', 'ilike', '%'.addcslashes($filters['search'], '%_\\').'%')
                    ->orWhereExists(fn ($aliases) => $aliases->selectRaw('1')->from('raw_material_aliases as aliases')
                        ->whereColumn('aliases.raw_material_id', 'materials.id')
                        ->where('aliases.organization_id', DB::raw('materials.organization_id'))
                        ->whereColumn('aliases.revision_id', 'materials.approved_revision_id')
                        ->where('aliases.normalized_alias', 'like', '%'.addcslashes($search, '%_\\').'%'));
            });
        }
        if (isset($filters['category_id'])) {
            $query->where('revisions.category_id', $filters['category_id']);
        }
        if (isset($filters['lifecycle_state'])) {
            $query->where('materials.lifecycle_state', $filters['lifecycle_state']);
        }

        $sortColumns = ['code' => 'materials.code', 'name' => 'revisions.name', 'lifecycle_state' => 'materials.lifecycle_state'];
        $sort = $sortColumns[$filters['sort'] ?? 'code'];
        $direction = $filters['direction'] ?? 'asc';
        $paginator = $query->orderBy($sort, $direction)->orderBy('materials.id')
            ->paginate($filters['per_page'] ?? 20);
        $data = $paginator->getCollection()->map(fn (object $row): array => [
            'id' => $row->id,
            'code' => $row->code,
            'lifecycle_state' => $row->lifecycle_state,
            'revision' => $row->revision_id === null ? null : [
                'id' => $row->revision_id,
                'revision_no' => $row->revision_no,
                'name' => $row->name,
                'base_unit_code' => $row->base_unit_code,
                'allergen_state' => $row->allergen_state,
                'category' => $row->category_id === null ? null : ['id' => $row->category_id, 'name' => $row->category_name],
            ],
        ])->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function show(User $actor, Location $context, string $materialId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        if (! $this->sharePolicy->isPrimaryContext($context)) {
            $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
        }
        $material = $this->material($actor, $materialId);
        $revisionId = $material->approved_revision_id;
        if ($revisionId === null) {
            $canInspectDraft = $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::Edit)
                || $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::AuditView);
            if (! $canInspectDraft) {
                throw new RawMaterialException('RAW_MATERIAL_NOT_FOUND', __('messages.resource_not_found'), 404);
            }
            $revisionId = $material->working_revision_id ?? $material->pending_revision_id;
        }
        $revision = $revisionId ? DB::table('raw_material_revisions')->where('id', $revisionId)->first() : null;

        return $this->presentMaterial($material, $revision);
    }

    public function revisions(User $actor, Location $context, string $materialId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::AuditView);
        if (! $this->sharePolicy->isPrimaryContext($context)) {
            $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
        }
        $this->material($actor, $materialId);

        return DB::table('raw_material_revisions')->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $materialId)->orderByDesc('revision_no')->get()
            ->map(function (object $revision): array {
                return [
                    'id' => $revision->id,
                    'revision_no' => $revision->revision_no,
                    'name' => $revision->name,
                    'category_id' => $revision->category_id,
                    'base_unit_code' => $revision->base_unit_code,
                    'allergen_state' => $revision->allergen_state,
                    'critical_change' => (bool) $revision->critical_change,
                    'created_at' => $revision->created_at,
                    'approvals' => DB::table('raw_material_revision_approval_events')
                        ->where('revision_id', $revision->id)->orderBy('occurred_at')->get()
                        ->map(fn (object $event): array => [
                            'outcome' => $event->outcome,
                            'actor_user_id' => $event->actor_user_id,
                            'occurred_at' => $event->occurred_at,
                            'reason' => $event->reason,
                        ])->all(),
                ];
            })->all();
    }

    public function create(User $actor, Location $context, array $input): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $input): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);
            $this->requireCategory($actor, $input['category_id']);
            $unitData = RawMaterialUnitRegistry::resolve($input['base_unit_code'], $input['conversions'] ?? []);
            $aliases = $this->aliases($input['name'], $input['aliases'] ?? []);
            $this->assertNoDuplicateTerms($actor, $aliases, null);
            $materialId = Str::uuid()->toString();
            $revisionId = Str::uuid()->toString();
            $now = now();
            try {
                $code = $this->skuAllocation->allocateNext($actor, SkuPrefix::Raw, $materialId);
            } catch (\InvalidArgumentException $exception) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CODE_CAPACITY_REACHED',
                    __('messages.raw_material_code_capacity_reached'),
                );
            }
            DB::table('raw_materials')->insert([
                'id' => $materialId,
                'organization_id' => $actor->organization_id,
                'code' => $code,
                'lifecycle_state' => 'DRAFT',
                'working_revision_id' => $revisionId,
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $revision = $this->insertRevision(
                $actor,
                $context,
                $materialId,
                $revisionId,
                1,
                $input,
                $unitData,
                $aliases,
                false,
                $input['change_reason'] ?? null,
            );
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'CREATED', null, [
                'code' => $code,
                'revision_id' => $revisionId,
                'revision_hash' => $revision['content_hash'],
            ]);

            return $this->presentMaterial(DB::table('raw_materials')->where('id', $materialId)->first(), (object) $revision);
        }));
    }

    public function createImported(User $actor, Location $context, string $sku, array $input): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $sku, $input): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);
            $this->requireCategory($actor, $input['category_id']);
            $unitData = RawMaterialUnitRegistry::resolve($input['base_unit_code'], []);
            $materialId = Str::uuid()->toString();
            $revisionId = Str::uuid()->toString();
            $now = now();

            try {
                $this->skuAllocation->reserveImported($actor, SkuPrefix::Raw, $sku, $materialId);
            } catch (\InvalidArgumentException $exception) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CATALOG_IMPORT_SKU_INVALID',
                    'Importowany SKU nie odpowiada formatowi kategorii RAW.',
                    422,
                );
            } catch (\DomainException $exception) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CATALOG_IMPORT_SKU_CONFLICT',
                    'Importowany SKU został już użyty albo zarezerwowany w organizacji.',
                    409,
                );
            }

            DB::table('raw_materials')->insert([
                'id' => $materialId,
                'organization_id' => $actor->organization_id,
                'code' => $sku,
                'lifecycle_state' => 'DRAFT',
                'working_revision_id' => $revisionId,
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $revision = $this->insertRevision(
                $actor,
                $context,
                $materialId,
                $revisionId,
                1,
                $input,
                $unitData,
                [],
                false,
                'Import katalogu CSV',
                'UNKNOWN',
                true,
            );
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'CREATED', null, [
                'code' => $sku,
                'revision_id' => $revisionId,
                'revision_hash' => $revision['content_hash'],
                'source' => 'CATALOG_CSV_IMPORT',
            ]);

            return $this->presentMaterial(
                DB::table('raw_materials')->where('id', $materialId)->first(),
                (object) $revision,
            );
        }));
    }

    public function revise(User $actor, Location $context, string $materialId, array $input): array
    {
        return $this->reviseWithPermission($actor, $context, $materialId, $input, RawMaterialPermission::Edit);
    }

    public function reviseAllergenDeclaration(User $actor, Location $context, string $materialId, array $input): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::AllergenManage);
        $this->validateAllergenDeclaration($input);
        $isUnknown = $input['status'] === 'UNKNOWN';

        return $this->reviseWithPermission(
            $actor,
            $context,
            $materialId,
            [
                'allergen_declaration' => $isUnknown ? null : $input,
                'allergen_state' => $isUnknown ? 'UNKNOWN' : 'DECLARED',
            ],
            RawMaterialPermission::AllergenManage,
            'ALLERGEN_DECLARATION_CHANGED',
        );
    }

    public function reviseStorageVariants(User $actor, Location $context, string $materialId, array $variants): array
    {
        return $this->reviseWithPermission(
            $actor,
            $context,
            $materialId,
            ['storage_variants' => $variants],
            RawMaterialPermission::StorageManage,
            'STORAGE_VARIANTS_CHANGED',
        );
    }

    public function allergenCatalog(User $actor, Location $context): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);

        return DB::table('raw_material_allergen_catalog_versions')
            ->where('status', 'PUBLISHED')
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'published_at'])
            ->map(fn (object $version): array => [
                'id' => $version->id,
                'version_number' => (int) $version->version_number,
                'published_at' => $version->published_at,
                'definitions' => DB::table('raw_material_allergen_definitions')
                    ->where('catalog_version_id', $version->id)
                    ->where('is_active', true)
                    ->orderBy('display_order')
                    ->get(['allergen_code', 'name_pl', 'display_order'])
                    ->map(fn (object $definition): array => [
                        'allergen_code' => $definition->allergen_code,
                        'name_pl' => $definition->name_pl,
                        'display_order' => (int) $definition->display_order,
                    ])->all(),
            ])->all();
    }

    public function revisionStage2d(User $actor, Location $context, string $materialId, string $revisionId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::AuditView);
        if (! $this->sharePolicy->isPrimaryContext($context)) {
            $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
        }
        $material = DB::table('raw_materials')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $materialId)
            ->first(['id']);
        $revision = DB::table('raw_material_revisions')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $materialId)
            ->where('id', $revisionId)
            ->first([
                'id',
                'revision_no',
                'allergen_state',
                'content_hash_version',
                'content_hash',
            ]);
        if (! $material || ! $revision) {
            throw new RawMaterialException('RAW_MATERIAL_REVISION_NOT_FOUND', __('messages.resource_not_found'), 404);
        }

        $attestation = DB::table('raw_material_revision_allergen_attestations as attestations')
            ->join('raw_material_allergen_catalog_versions as versions', 'versions.id', '=', 'attestations.catalog_version_id')
            ->where('attestations.revision_id', $revisionId)
            ->select([
                'attestations.attestation_status',
                'attestations.catalog_version_id',
                'attestations.attested_by',
                'attestations.attested_at',
                'versions.version_number',
            ])->first();
        $items = DB::table('raw_material_revision_allergen_declarations as declarations')
            ->join('raw_material_allergen_definitions as definitions', function ($join): void {
                $join->on('definitions.catalog_version_id', '=', 'declarations.catalog_version_id')
                    ->on('definitions.allergen_code', '=', 'declarations.allergen_code');
            })
            ->where('declarations.revision_id', $revisionId)
            ->orderBy('declarations.allergen_code')
            ->get([
                'declarations.allergen_code',
                'definitions.name_pl',
                'declarations.exposure_kind',
            ])->map(fn (object $item): array => [
                'allergen_code' => $item->allergen_code,
                'name_pl' => $item->name_pl,
                'exposure_kind' => $item->exposure_kind,
            ])->all();

        return [
            'revision_id' => $revision->id,
            'revision_no' => (int) $revision->revision_no,
            'content_hash_version' => (int) $revision->content_hash_version,
            'content_hash' => $revision->content_hash,
            'allergen_declaration' => $attestation === null
                ? [
                    'status' => $revision->allergen_state === 'UNKNOWN' ? 'UNKNOWN' : 'LEGACY_DECLARED',
                    'catalog_version_id' => null,
                    'catalog_version_number' => null,
                    'attested_by' => null,
                    'attested_at' => null,
                    'items' => [],
                ]
                : [
                    'status' => $attestation->attestation_status,
                    'catalog_version_id' => $attestation->catalog_version_id,
                    'catalog_version_number' => (int) $attestation->version_number,
                    'attested_by' => $attestation->attested_by,
                    'attested_at' => $attestation->attested_at,
                    'items' => $items,
                ],
            'storage_variants' => DB::table('raw_material_revision_storage_variants')
                ->where('organization_id', $actor->organization_id)
                ->where('revision_id', $revisionId)
                ->orderBy('variant_code')
                ->get([
                    'variant_code',
                    'name_pl',
                    'storage_type',
                    'temperature_min_c',
                    'temperature_max_c',
                    'humidity_min_percent',
                    'humidity_max_percent',
                    'conditions',
                    'is_default',
                ])
                ->map(fn (object $variant): array => [
                    'variant_code' => $variant->variant_code,
                    'name_pl' => $variant->name_pl,
                    'storage_type' => $variant->storage_type,
                    'temperature_min_c' => (string) $variant->temperature_min_c,
                    'temperature_max_c' => (string) $variant->temperature_max_c,
                    'humidity_min_percent' => $variant->humidity_min_percent === null
                        ? null : (string) $variant->humidity_min_percent,
                    'humidity_max_percent' => $variant->humidity_max_percent === null
                        ? null : (string) $variant->humidity_max_percent,
                    'conditions' => $variant->conditions,
                    'is_default' => $this->canonicalBoolean($variant->is_default),
                ])->all(),
        ];
    }

    private function reviseWithPermission(
        User $actor,
        Location $context,
        string $materialId,
        array $input,
        RawMaterialPermission $permission,
        ?string $changeType = null,
    ): array {
        $this->authorization->assertAllowed($actor, $context, $permission);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $input, $permission, $changeType): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, $permission);
            $material = $this->material($actor, $materialId, true);
            if (! in_array($material->lifecycle_state, ['DRAFT', 'ACTIVE'], true)
                || $material->pending_revision_id !== null
                || ($material->lifecycle_state === 'DRAFT' && $material->working_revision_id === null)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_REVISION_NOT_EDITABLE',
                    __('messages.raw_material_revision_not_editable'),
                );
            }
            $baseRevisionId = $material->working_revision_id ?? $material->approved_revision_id;
            if ($baseRevisionId === null) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_REVISION_NOT_EDITABLE',
                    __('messages.raw_material_revision_not_editable'),
                );
            }
            $baseRevision = DB::table('raw_material_revisions')
                ->where('organization_id', $actor->organization_id)
                ->where('raw_material_id', $materialId)
                ->where('id', $baseRevisionId)
                ->first();
            if (! $baseRevision) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_REVISION_NOT_EDITABLE',
                    __('messages.raw_material_revision_not_editable'),
                );
            }
            if ($permission !== RawMaterialPermission::Edit) {
                $input = array_merge($this->revisionInputFor($baseRevision), $input);
            }
            if (! array_key_exists('allergen_declaration', $input)) {
                $input['allergen_declaration'] = $this->allergenDeclarationForRevision($baseRevision->id);
            }
            if (! array_key_exists('storage_variants', $input)) {
                $input['storage_variants'] = $this->storageVariantsForRevision($baseRevision->id);
            }
            $this->requireCategory($actor, $input['category_id']);
            $unitData = RawMaterialUnitRegistry::resolve($input['base_unit_code'], $input['conversions'] ?? []);
            $aliases = $this->aliases($input['name'], $input['aliases'] ?? []);
            $this->assertNoDuplicateTerms($actor, $aliases, $materialId);
            $latestNo = (int) DB::table('raw_material_revisions')->where('raw_material_id', $materialId)->max('revision_no');
            $revisionId = Str::uuid()->toString();
            DB::table('raw_materials')->where('id', $materialId)->update([
                'working_revision_id' => $revisionId,
                'updated_at' => now(),
            ]);
            $criticalChange = $material->lifecycle_state === 'ACTIVE';
            $revision = $this->insertRevision(
                $actor,
                $context,
                $materialId,
                $revisionId,
                $latestNo + 1,
                $input,
                $unitData,
                $aliases,
                $criticalChange,
                $input['change_reason'] ?? null,
                $input['allergen_state'] ?? $baseRevision->allergen_state,
            );
            $metadata = [
                'revision_id' => $revisionId,
                'revision_hash' => $revision['content_hash'],
                'previous_revision_id' => $baseRevisionId,
                'critical_change' => $criticalChange,
            ];
            if ($changeType !== null) {
                $metadata['change_type'] = $changeType;
            }
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'REVISION_CREATED', null, $metadata);

            return $this->presentMaterial(DB::table('raw_materials')->where('id', $materialId)->first(), (object) $revision);
        }));
    }

    private function revisionInputFor(object $revision): array
    {
        return [
            'category_id' => $revision->category_id,
            'name' => $revision->name,
            'description' => $revision->description,
            'base_unit_code' => $revision->base_unit_code,
            'conversions' => DB::table('raw_material_revision_units')
                ->where('revision_id', $revision->id)
                ->where('unit_code', '<>', $revision->base_unit_code)
                ->orderBy('unit_code')
                ->get(['unit_code', 'to_base_factor', 'basis'])
                ->map(fn (object $unit): array => [
                    'unit_code' => $unit->unit_code,
                    'to_base_factor' => (string) $unit->to_base_factor,
                    'basis' => $unit->basis,
                ])->all(),
            'aliases' => DB::table('raw_material_aliases')
                ->where('revision_id', $revision->id)
                ->where('normalized_alias', '<>', self::normalizeTerm($revision->name))
                ->orderBy('normalized_alias')
                ->pluck('alias')
                ->all(),
            'allergen_declaration' => $this->allergenDeclarationForRevision($revision->id),
            'storage_variants' => $this->storageVariantsForRevision($revision->id),
        ];
    }

    private function validateAllergenDeclaration(array $input): void
    {
        $status = $input['status'] ?? null;
        $items = $input['items'] ?? [];
        if (! in_array($status, ['UNKNOWN', 'DECLARED', 'NO_KNOWN_ALLERGENS'], true)
            || ! is_array($items)
            || ($status === 'UNKNOWN' && (($input['catalog_version_id'] ?? null) !== null || $items !== []))
            || ($status === 'DECLARED' && $items === [])
            || ($status === 'NO_KNOWN_ALLERGENS' && $items !== [])) {
            throw ValidationException::withMessages([
                'status' => [__('messages.raw_material_allergen_declaration_invalid')],
            ]);
        }

        if ($input['status'] === 'UNKNOWN') {
            return;
        }

        if (! isset($input['catalog_version_id'])) {
            throw ValidationException::withMessages([
                'catalog_version_id' => [__('messages.raw_material_allergen_catalog_unavailable')],
            ]);
        }

        $versionExists = DB::table('raw_material_allergen_catalog_versions')
            ->where('id', $input['catalog_version_id'])
            ->where('status', 'PUBLISHED')
            ->exists();
        if (! $versionExists) {
            throw new RawMaterialException(
                'RAW_MATERIAL_ALLERGEN_CATALOG_UNAVAILABLE',
                __('messages.raw_material_allergen_catalog_unavailable'),
                422,
            );
        }

        $codes = array_column($items, 'allergen_code');
        if (count($codes) !== count(array_unique($codes))) {
            throw ValidationException::withMessages([
                'items' => [__('messages.raw_material_allergen_declaration_invalid')],
            ]);
        }
        if ($codes !== [] && DB::table('raw_material_allergen_definitions')
            ->where('catalog_version_id', $input['catalog_version_id'])
            ->where('is_active', true)
            ->whereIn('allergen_code', $codes)
            ->count() !== count($codes)) {
            throw new RawMaterialException(
                'RAW_MATERIAL_ALLERGEN_DEFINITION_UNAVAILABLE',
                __('messages.raw_material_allergen_definition_unavailable'),
                422,
            );
        }
    }

    public function submit(User $actor, Location $context, string $materialId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Submit);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Submit);
            $material = $this->material($actor, $materialId, true);
            if ($material->working_revision_id === null || ! in_array($material->lifecycle_state, ['DRAFT', 'ACTIVE'], true)) {
                throw new RawMaterialException('RAW_MATERIAL_SUBMISSION_INVALID', __('messages.raw_material_submission_invalid'));
            }
            $previous = $this->stateHash($material);
            $state = $material->lifecycle_state === 'DRAFT' ? 'VERIFICATION' : 'ACTIVE';
            DB::table('raw_materials')->where('id', $materialId)->update([
                'lifecycle_state' => $state,
                'pending_revision_id' => $material->working_revision_id,
                'working_revision_id' => null,
                'updated_at' => now(),
            ]);
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'SUBMITTED', $previous, [
                'revision_id' => $material->working_revision_id,
            ]);

            return $this->presentCurrent($actor, $materialId);
        }));
    }

    public function returnForChanges(User $actor, Location $context, string $materialId, string $reason): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Review);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Review);
            $material = $this->material($actor, $materialId, true);
            if ($material->pending_revision_id === null
                || ! in_array($material->lifecycle_state, ['VERIFICATION', 'ACTIVE', 'BLOCKED'], true)) {
                throw new RawMaterialException('RAW_MATERIAL_REVIEW_INVALID', __('messages.raw_material_review_invalid'));
            }
            $revision = DB::table('raw_material_revisions')->where('id', $material->pending_revision_id)->firstOrFail();
            $previous = $this->stateHash($material);
            $operationId = Str::uuid()->toString();
            DB::table('raw_material_revision_approval_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'revision_id' => $revision->id,
                'operation_id' => $operationId,
                'outcome' => 'RETURNED',
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'content_hash' => $revision->content_hash,
            ]);
            $returnedState = $material->lifecycle_state === 'VERIFICATION'
                ? ($material->approved_revision_id === null ? 'DRAFT' : 'ACTIVE')
                : $material->lifecycle_state;
            DB::table('raw_materials')->where('id', $materialId)->update([
                'lifecycle_state' => $returnedState,
                'pending_revision_id' => null,
                'working_revision_id' => $revision->id,
                'updated_at' => now(),
            ]);
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'RETURNED', $previous, [
                'revision_id' => $revision->id,
            ], $reason, $operationId);

            return $this->presentCurrent($actor, $materialId);
        }));
    }

    public function approve(User $actor, Location $context, string $materialId, string $reason): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Approve);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Approve);
            $material = $this->material($actor, $materialId, true);
            if ($material->pending_revision_id === null
                || ! in_array($material->lifecycle_state, ['VERIFICATION', 'ACTIVE'], true)) {
                throw new RawMaterialException('RAW_MATERIAL_APPROVAL_INVALID', __('messages.raw_material_approval_invalid'));
            }
            $revision = DB::table('raw_material_revisions')->where('id', $material->pending_revision_id)->firstOrFail();
            if ($revision->allergen_state !== 'DECLARED') {
                throw new RawMaterialException(
                    'RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED',
                    __('messages.raw_material_allergen_declaration_required'),
                    422,
                );
            }
            if ((int) $revision->content_hash_version === 2
                && ! $this->hasValidV2AllergenAttestation($revision)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED',
                    __('messages.raw_material_allergen_declaration_required'),
                    422,
                );
            }
            if (! hash_equals($revision->content_hash, $this->hashFromDatabase($revision))) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_REVISION_HASH_MISMATCH',
                    __('messages.raw_material_revision_integrity_failed'),
                    409,
                );
            }
            $previous = $this->stateHash($material);
            $operationId = Str::uuid()->toString();
            DB::table('raw_material_revision_approval_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'revision_id' => $revision->id,
                'operation_id' => $operationId,
                'outcome' => 'APPROVED',
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'content_hash' => $revision->content_hash,
            ]);
            DB::table('raw_materials')->where('id', $materialId)->update([
                'lifecycle_state' => 'ACTIVE',
                'approved_revision_id' => $revision->id,
                'pending_revision_id' => null,
                'working_revision_id' => null,
                'updated_at' => now(),
            ]);
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'APPROVED', $previous, [
                'revision_id' => $revision->id,
                'content_hash' => $revision->content_hash,
            ], $reason, $operationId);

            return $this->presentCurrent($actor, $materialId);
        }));
    }

    public function block(User $actor, Location $context, string $materialId, string $reason): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Block);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Block);
            $material = $this->material($actor, $materialId, true);
            if ($material->lifecycle_state !== 'ACTIVE') {
                throw new RawMaterialException('RAW_MATERIAL_BLOCK_INVALID', __('messages.raw_material_block_invalid'));
            }
            $previous = $this->stateHash($material);
            DB::table('raw_materials')->where('id', $materialId)->update([
                'lifecycle_state' => 'BLOCKED',
                'blocked_at' => now(),
                'blocked_by' => $actor->id,
                'block_reason' => $reason,
                'updated_at' => now(),
            ]);
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'BLOCKED', $previous, [], $reason);

            return $this->presentCurrent($actor, $materialId);
        }));
    }

    public function unblock(User $actor, Location $context, string $materialId, string $reason): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Unblock);
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Approve);

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::rawMaterialUnblock(),
            $actor->id,
            $materialId,
            fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason): array {
                Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Unblock);
                $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Approve);
                $material = $this->material($actor, $materialId, true);
                if ($material->lifecycle_state !== 'BLOCKED' || $material->pending_revision_id !== null) {
                    throw new RawMaterialException('RAW_MATERIAL_UNBLOCK_INVALID', __('messages.raw_material_unblock_invalid'));
                }
                $previous = $this->stateHash($material);
                $unblocked = clone $material;
                $unblocked->lifecycle_state = 'ACTIVE';
                $unblocked->blocked_at = null;
                $unblocked->blocked_by = null;
                $unblocked->block_reason = null;
                $afterHash = $this->stateHash($unblocked);
                $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'UNBLOCKED', $previous, [], $reason, null, $afterHash);
                DB::table('raw_materials')->where('id', $materialId)->update([
                    'lifecycle_state' => 'ACTIVE',
                    'blocked_at' => null,
                    'blocked_by' => null,
                    'block_reason' => null,
                    'updated_at' => now(),
                ]);

                return $this->presentCurrent($actor, $materialId);
            }));
    }

    public function archive(User $actor, Location $context, string $materialId, string $reason): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Archive);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Archive);
            $material = $this->material($actor, $materialId, true);
            if (! in_array($material->lifecycle_state, ['ACTIVE', 'BLOCKED'], true)
                || $material->pending_revision_id !== null || $material->working_revision_id !== null) {
                throw new RawMaterialException('RAW_MATERIAL_ARCHIVE_INVALID', __('messages.raw_material_archive_invalid'));
            }
            $previous = $this->stateHash($material);
            DB::table('raw_materials')->where('id', $materialId)->update([
                'lifecycle_state' => 'ARCHIVED',
                'blocked_at' => null,
                'blocked_by' => null,
                'block_reason' => null,
                'archived_at' => now(),
                'archived_by' => $actor->id,
                'updated_at' => now(),
            ]);
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'ARCHIVED', $previous, [], $reason);

            return $this->presentCurrent($actor, $materialId);
        }));
    }

    public function restore(User $actor, Location $context, string $materialId, string $reason): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Restore);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Restore);
            $material = $this->material($actor, $materialId, true);
            if ($material->lifecycle_state !== 'ARCHIVED') {
                throw new RawMaterialException('RAW_MATERIAL_RESTORE_INVALID', __('messages.raw_material_restore_invalid'));
            }
            $approved = DB::table('raw_material_revisions')->where('id', $material->approved_revision_id)->firstOrFail();
            $latestNo = (int) DB::table('raw_material_revisions')->where('raw_material_id', $materialId)->max('revision_no');
            $revisionId = Str::uuid()->toString();
            DB::table('raw_materials')->where('id', $materialId)->update([
                'working_revision_id' => $revisionId,
                'updated_at' => now(),
            ]);
            $input = [
                'category_id' => $approved->category_id,
                'name' => $approved->name,
                'description' => $approved->description,
            ];
            $unitData = [
                'base_unit_code' => $approved->base_unit_code,
                'base_unit_dimension' => $approved->base_unit_dimension,
                'units' => DB::table('raw_material_revision_units')->where('revision_id', $approved->id)
                    ->orderBy('unit_code')->get()->map(fn (object $unit): array => [
                        'unit_code' => $unit->unit_code,
                        'dimension' => $unit->dimension,
                        'conversion_kind' => $unit->conversion_kind,
                        'to_base_factor' => (string) $unit->to_base_factor,
                        'basis' => $unit->basis,
                    ])->all(),
            ];
            $aliases = DB::table('raw_material_aliases')->where('revision_id', $approved->id)
                ->orderBy('normalized_alias')->get()->map(fn (object $alias): array => [
                    'alias' => $alias->alias,
                    'normalized_alias' => $alias->normalized_alias,
                ])->all();
            $input['allergen_declaration'] = $this->allergenDeclarationForRevision($approved->id);
            $input['storage_variants'] = $this->storageVariantsForRevision($approved->id);
            $revision = $this->insertRevision(
                $actor,
                $context,
                $materialId,
                $revisionId,
                $latestNo + 1,
                $input,
                $unitData,
                $aliases,
                true,
                $reason,
                $approved->allergen_state,
            );
            $previous = $this->stateHash($material);
            DB::table('raw_materials')->where('id', $materialId)->update([
                'lifecycle_state' => 'VERIFICATION',
                'pending_revision_id' => $revisionId,
                'working_revision_id' => null,
                'archived_at' => null,
                'archived_by' => null,
                'updated_at' => now(),
            ]);
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'RESTORE_SUBMITTED', $previous, [
                'revision_id' => $revisionId,
                'content_hash' => $revision['content_hash'],
            ], $reason);

            return $this->presentCurrent($actor, $materialId);
        }));
    }

    private function insertRevision(
        User $actor,
        Location $context,
        string $materialId,
        string $revisionId,
        int $revisionNo,
        array $input,
        array $unitData,
        array $aliases,
        bool $critical,
        ?string $reason,
        string $allergenState = 'UNKNOWN',
        bool $preserveExactName = false,
    ): array {
        $createdAt = now();
        $stage2d = $this->prepareStage2dSnapshot(
            $input['allergen_declaration'] ?? null,
            $input['storage_variants'] ?? [],
            $actor,
            $allergenState,
            $createdAt,
        );
        if ($stage2d['attestation'] !== null) {
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::AllergenManage);
        }
        if ($stage2d['storage_rows'] !== []) {
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::StorageManage);
        }
        $payload = [
            'category_id' => $input['category_id'],
            'name' => $preserveExactName ? $input['name'] : trim($input['name']),
            'description' => $input['description'] ?? null,
            'base_unit_code' => $unitData['base_unit_code'],
            'base_unit_dimension' => $unitData['base_unit_dimension'],
            'allergen_state' => $stage2d['attestation'] === null ? $allergenState : 'DECLARED',
        ];
        $hash = $this->hashPayloadV2(
            $payload,
            $unitData['units'],
            $aliases,
            $stage2d['allergen_declaration'],
            $stage2d['storage_variants'],
        );
        $revision = [
            'id' => $revisionId,
            'organization_id' => $actor->organization_id,
            'raw_material_id' => $materialId,
            'revision_no' => $revisionNo,
            ...$payload,
            'critical_change' => $critical,
            'change_reason' => $reason,
            'created_by' => $actor->id,
            'created_at' => $createdAt,
            'content_hash_version' => 2,
            'content_hash' => $hash,
        ];
        DB::table('raw_material_revisions')->insert($revision);

        foreach ($unitData['units'] as $unit) {
            DB::table('raw_material_revision_units')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'revision_id' => $revisionId,
                ...$unit,
            ]);
        }
        foreach ($aliases as $alias) {
            DB::table('raw_material_aliases')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'raw_material_id' => $materialId,
                'revision_id' => $revisionId,
                'alias' => $alias['alias'],
                'normalized_alias' => $alias['normalized_alias'],
            ]);
        }
        if ($stage2d['attestation'] !== null) {
            $this->contexts->runForSubject(
                AuthorizationMutationScope::rawMaterialAllergenManage(),
                $actor->id,
                $actor->organization_id,
                function () use ($actor, $revisionId, $stage2d): void {
                    DB::table('raw_material_revision_allergen_attestations')->insert([
                        'revision_id' => $revisionId,
                        'organization_id' => $actor->organization_id,
                        ...$stage2d['attestation'],
                    ]);
                    foreach ($stage2d['declarations'] as $declaration) {
                        DB::table('raw_material_revision_allergen_declarations')->insert([
                            'revision_id' => $revisionId,
                            'organization_id' => $actor->organization_id,
                            'catalog_version_id' => $stage2d['attestation']['catalog_version_id'],
                            ...$declaration,
                        ]);
                    }
                },
            );
        }
        if ($stage2d['storage_rows'] !== []) {
            $this->contexts->runForSubject(
                AuthorizationMutationScope::rawMaterialStorageManage(),
                $actor->id,
                $actor->organization_id,
                function () use ($actor, $revisionId, $stage2d): void {
                    foreach ($stage2d['storage_rows'] as $storageVariant) {
                        DB::table('raw_material_revision_storage_variants')->insert([
                            'id' => Str::uuid()->toString(),
                            'revision_id' => $revisionId,
                            'organization_id' => $actor->organization_id,
                            ...$storageVariant,
                        ]);
                    }
                },
            );
        }

        return $revision;
    }

    private function hasValidV2AllergenAttestation(object $revision): bool
    {
        $attestation = DB::table('raw_material_revision_allergen_attestations as attestations')
            ->join('raw_material_allergen_catalog_versions as versions', 'versions.id', '=', 'attestations.catalog_version_id')
            ->join('users', function ($join): void {
                $join->on('users.id', '=', 'attestations.attested_by')
                    ->on('users.organization_id', '=', 'attestations.organization_id');
            })
            ->where('attestations.revision_id', $revision->id)
            ->where('attestations.organization_id', $revision->organization_id)
            ->where('versions.status', 'PUBLISHED')
            ->select([
                'attestations.catalog_version_id',
                'attestations.attestation_status',
                'attestations.attested_at',
            ])
            ->first();

        if ($attestation === null) {
            return false;
        }

        $attestedAt = Carbon::parse($attestation->attested_at);
        if ($attestedAt->gt(now())) {
            return false;
        }

        $declarations = DB::table('raw_material_revision_allergen_declarations as declarations')
            ->leftJoin('raw_material_allergen_definitions as definitions', function ($join): void {
                $join->on('definitions.catalog_version_id', '=', 'declarations.catalog_version_id')
                    ->on('definitions.allergen_code', '=', 'declarations.allergen_code');
            })
            ->where('declarations.revision_id', $revision->id)
            ->where('declarations.organization_id', $revision->organization_id)
            ->where('declarations.catalog_version_id', $attestation->catalog_version_id);
        $declarationCount = (clone $declarations)->count();
        $hasInvalidDeclaration = (clone $declarations)
            ->where(function ($query): void {
                $query->whereNull('definitions.allergen_code')
                    ->orWhere('definitions.is_active', false);
            })
            ->exists();

        return match ($attestation->attestation_status) {
            'DECLARED' => $revision->allergen_state === 'DECLARED'
                && $declarationCount > 0
                && ! $hasInvalidDeclaration,
            'NO_KNOWN_ALLERGENS' => $revision->allergen_state === 'DECLARED'
                && $declarationCount === 0,
            default => false,
        };
    }

    private function hashFromDatabase(object $revision): string
    {
        $units = DB::table('raw_material_revision_units')->where('revision_id', $revision->id)->orderBy('unit_code')
            ->get()->map(fn (object $unit): array => [
                'unit_code' => $unit->unit_code,
                'dimension' => $unit->dimension,
                'conversion_kind' => $unit->conversion_kind,
                'to_base_factor' => (string) $unit->to_base_factor,
                'basis' => $unit->basis,
            ])->all();
        $aliases = DB::table('raw_material_aliases')->where('revision_id', $revision->id)->orderBy('normalized_alias')
            ->get()->map(fn (object $alias): array => [
                'alias' => $alias->alias,
                'normalized_alias' => $alias->normalized_alias,
            ])->all();
        $payload = [
            'category_id' => $revision->category_id,
            'name' => $revision->name,
            'description' => $revision->description,
            'base_unit_code' => $revision->base_unit_code,
            'base_unit_dimension' => $revision->base_unit_dimension,
            'allergen_state' => $revision->allergen_state,
        ];

        if ((int) $revision->content_hash_version === 1) {
            return $this->hashPayload($payload, $units, $aliases);
        }

        $attestation = DB::table('raw_material_revision_allergen_attestations as attestations')
            ->join('raw_material_allergen_catalog_versions as versions', 'versions.id', '=', 'attestations.catalog_version_id')
            ->where('attestations.revision_id', $revision->id)
            ->select([
                'attestations.attestation_status',
                'attestations.catalog_version_id',
                'attestations.attested_by',
                'attestations.attested_at',
                'versions.version_number',
            ])->first();
        $declarations = DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $revision->id)
            ->orderBy('allergen_code')
            ->get()->map(fn (object $declaration): array => [
                'allergen_code' => $declaration->allergen_code,
                'exposure_kind' => $declaration->exposure_kind,
            ])->all();
        $allergenDeclaration = $attestation === null
            ? [
                'status' => $revision->allergen_state === 'UNKNOWN' ? 'UNKNOWN' : 'LEGACY_DECLARED',
                'catalog_version_number' => null,
                'attested_by' => null,
                'attested_at' => null,
                'items' => [],
            ]
            : [
                'status' => $attestation->attestation_status,
                'catalog_version_number' => (int) $attestation->version_number,
                'attested_by' => $attestation->attested_by,
                'attested_at' => $this->canonicalTimestamp($attestation->attested_at),
                'items' => $declarations,
            ];
        $storageVariants = DB::table('raw_material_revision_storage_variants')
            ->where('revision_id', $revision->id)
            ->orderBy('variant_code')
            ->get()->map(fn (object $variant): array => $this->canonicalStorageVariant((array) $variant))->all();

        return $this->hashPayloadV2($payload, $units, $aliases, $allergenDeclaration, $storageVariants);
    }

    private function hashPayload(array $payload, array $units, array $aliases): string
    {
        ksort($payload);
        usort($units, fn (array $left, array $right): int => $left['unit_code'] <=> $right['unit_code']);
        usort($aliases, fn (array $left, array $right): int => $left['normalized_alias'] <=> $right['normalized_alias']);

        return hash('sha256', json_encode([
            'payload' => $payload,
            'units' => $units,
            'aliases' => $aliases,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function hashPayloadV2(
        array $payload,
        array $units,
        array $aliases,
        array $allergenDeclaration,
        array $storageVariants,
    ): string {
        ksort($payload);
        usort($units, fn (array $left, array $right): int => $left['unit_code'] <=> $right['unit_code']);
        usort($aliases, fn (array $left, array $right): int => $left['normalized_alias'] <=> $right['normalized_alias']);
        usort($allergenDeclaration['items'], fn (array $left, array $right): int => [$left['allergen_code'], $left['exposure_kind']] <=> [$right['allergen_code'], $right['exposure_kind']]);
        usort($storageVariants, fn (array $left, array $right): int => $left['variant_code'] <=> $right['variant_code']);

        return hash('sha256', json_encode([
            'version' => 2,
            'payload' => $payload,
            'units' => $units,
            'aliases' => $aliases,
            'allergen_declaration' => $allergenDeclaration,
            'storage_variants' => $storageVariants,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function prepareStage2dSnapshot(
        ?array $allergenDeclaration,
        array $storageVariants,
        User $actor,
        string $allergenState,
        Carbon $revisionCreatedAt,
    ): array {
        $attestation = null;
        $declarations = [];
        if ($allergenDeclaration === null) {
            $canonicalDeclaration = [
                'status' => $allergenState === 'UNKNOWN' ? 'UNKNOWN' : 'LEGACY_DECLARED',
                'catalog_version_number' => null,
                'attested_by' => null,
                'attested_at' => null,
                'items' => [],
            ];
        } else {
            $status = $allergenDeclaration['status'] ?? null;
            if (! in_array($status, ['DECLARED', 'NO_KNOWN_ALLERGENS'], true)) {
                throw new \InvalidArgumentException('Nieprawidłowy stan atestu alergenowego.');
            }
            $catalogVersionId = $allergenDeclaration['catalog_version_id'] ?? null;
            $catalogVersionNumber = DB::table('raw_material_allergen_catalog_versions')
                ->where('id', $catalogVersionId)->where('status', 'PUBLISHED')->value('version_number');
            if ($catalogVersionNumber === null) {
                throw new \InvalidArgumentException('Atest wymaga opublikowanej wersji słownika alergenów.');
            }
            $items = $allergenDeclaration['items'] ?? [];
            if (! is_array($items)
                || ($status === 'DECLARED' && $items === [])
                || ($status === 'NO_KNOWN_ALLERGENS' && $items !== [])) {
                throw new \InvalidArgumentException('Pozycje atestu alergenowego nie odpowiadają jego stanowi.');
            }

            $attestedBy = $allergenDeclaration['attested_by'] ?? $actor->id;
            $attestedAt = $allergenDeclaration['attested_at'] ?? $revisionCreatedAt;
            $canonicalDeclaration = [
                'status' => $status,
                'catalog_version_number' => (int) $catalogVersionNumber,
                'attested_by' => $attestedBy,
                'attested_at' => $this->canonicalTimestamp($attestedAt),
                'items' => [],
            ];
            $attestation = [
                'catalog_version_id' => $catalogVersionId,
                'attestation_status' => $status,
                'attested_by' => $attestedBy,
                'attested_at' => Carbon::parse($attestedAt)->utc()->format('Y-m-d H:i:s.uP'),
            ];
            foreach ($items as $item) {
                $declaration = [
                    'allergen_code' => $item['allergen_code'],
                    'exposure_kind' => $item['exposure_kind'],
                ];
                $declarations[] = $declaration;
                $canonicalDeclaration['items'][] = $declaration;
            }
        }

        $canonicalStorageVariants = [];
        $storageRows = [];
        foreach ($storageVariants as $variant) {
            $storageRow = [
                'variant_code' => $variant['variant_code'],
                'name_pl' => trim($variant['name_pl']),
                'storage_type' => trim($variant['storage_type']),
                'temperature_min_c' => $this->canonicalDecimal($variant['temperature_min_c']),
                'temperature_max_c' => $this->canonicalDecimal($variant['temperature_max_c']),
                'humidity_min_percent' => ($variant['humidity_min_percent'] ?? null) === null
                    ? null : $this->canonicalDecimal($variant['humidity_min_percent']),
                'humidity_max_percent' => ($variant['humidity_max_percent'] ?? null) === null
                    ? null : $this->canonicalDecimal($variant['humidity_max_percent']),
                'conditions' => $variant['conditions'] ?? null,
                'is_default' => $this->canonicalBoolean($variant['is_default'] ?? false),
            ];
            $storageRows[] = $storageRow;
            $canonicalStorageVariants[] = $storageRow;
        }

        return [
            'attestation' => $attestation,
            'declarations' => $declarations,
            'allergen_declaration' => $canonicalDeclaration,
            'storage_rows' => $storageRows,
            'storage_variants' => $canonicalStorageVariants,
        ];
    }

    private function allergenDeclarationForRevision(string $revisionId): ?array
    {
        $attestation = DB::table('raw_material_revision_allergen_attestations')
            ->where('revision_id', $revisionId)->first();
        if (! $attestation) {
            return null;
        }

        return [
            'status' => $attestation->attestation_status,
            'catalog_version_id' => $attestation->catalog_version_id,
            'attested_by' => $attestation->attested_by,
            'attested_at' => $attestation->attested_at,
            'items' => DB::table('raw_material_revision_allergen_declarations')
                ->where('revision_id', $revisionId)
                ->orderBy('allergen_code')
                ->get()->map(fn (object $declaration): array => [
                    'allergen_code' => $declaration->allergen_code,
                    'exposure_kind' => $declaration->exposure_kind,
                ])->all(),
        ];
    }

    private function storageVariantsForRevision(string $revisionId): array
    {
        return DB::table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisionId)
            ->orderBy('variant_code')
            ->get()->map(fn (object $variant): array => [
                'variant_code' => $variant->variant_code,
                'name_pl' => $variant->name_pl,
                'storage_type' => $variant->storage_type,
                'temperature_min_c' => (string) $variant->temperature_min_c,
                'temperature_max_c' => (string) $variant->temperature_max_c,
                'humidity_min_percent' => $variant->humidity_min_percent === null
                    ? null : (string) $variant->humidity_min_percent,
                'humidity_max_percent' => $variant->humidity_max_percent === null
                    ? null : (string) $variant->humidity_max_percent,
                'conditions' => $variant->conditions,
                'is_default' => $this->canonicalBoolean($variant->is_default),
            ])->all();
    }

    private function canonicalStorageVariant(array $variant): array
    {
        return [
            'variant_code' => $variant['variant_code'],
            'name_pl' => $variant['name_pl'],
            'storage_type' => $variant['storage_type'],
            'temperature_min_c' => $this->canonicalDecimal($variant['temperature_min_c']),
            'temperature_max_c' => $this->canonicalDecimal($variant['temperature_max_c']),
            'humidity_min_percent' => $variant['humidity_min_percent'] === null
                ? null : $this->canonicalDecimal($variant['humidity_min_percent']),
            'humidity_max_percent' => $variant['humidity_max_percent'] === null
                ? null : $this->canonicalDecimal($variant['humidity_max_percent']),
            'conditions' => $variant['conditions'],
            'is_default' => $this->canonicalBoolean($variant['is_default']),
        ];
    }

    private function canonicalDecimal(string|int|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function canonicalBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function canonicalTimestamp(\DateTimeInterface|string $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function aliases(string $name, array $input): array
    {
        $result = [];
        foreach ([$name, ...$input] as $alias) {
            $alias = trim($alias);
            $normalized = self::normalizeTerm($alias);
            if ($normalized === '' || isset($result[$normalized])) {
                continue;
            }
            $result[$normalized] = ['alias' => $alias, 'normalized_alias' => $normalized];
        }

        return array_values($result);
    }

    private function assertNoDuplicateTerms(User $actor, array $aliases, ?string $sameMaterial): void
    {
        foreach ($aliases as $alias) {
            $exists = DB::table('raw_material_aliases')->where('organization_id', $actor->organization_id)
                ->where('normalized_alias', $alias['normalized_alias'])
                ->when($sameMaterial, fn ($query) => $query->where('raw_material_id', '<>', $sameMaterial))
                ->exists();
            if ($exists) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_DUPLICATE_TERM',
                    __('messages.raw_material_duplicate_term', ['term' => $alias['alias']]),
                    422,
                );
            }
        }
    }

    private function requireCategory(User $actor, string $categoryId): object
    {
        $category = DB::table('raw_material_categories')
            ->where('organization_id', $actor->organization_id)->where('id', $categoryId)->first();
        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => [__('messages.raw_material_category_invalid')],
            ]);
        }

        return $category;
    }

    private function material(User $actor, string $materialId, bool $lock = false): object
    {
        $query = DB::table('raw_materials')->where('organization_id', $actor->organization_id)
            ->where('id', $materialId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $material = $query->first();
        if (! $material) {
            throw new RawMaterialException('RAW_MATERIAL_NOT_FOUND', __('messages.resource_not_found'), 404);
        }

        return $material;
    }

    private function presentMaterial(object $material, ?object $revision): array
    {
        $revisionData = null;
        if ($revision) {
            $revisionData = [
                'id' => $revision->id,
                'revision_no' => $revision->revision_no,
                'name' => $revision->name,
                'description' => $revision->description,
                'category_id' => $revision->category_id,
                'base_unit_code' => $revision->base_unit_code,
                'base_unit_dimension' => $revision->base_unit_dimension,
                'allergen_state' => $revision->allergen_state,
                'units' => DB::table('raw_material_revision_units')->where('revision_id', $revision->id)
                    ->orderBy('unit_code')->get()->map(fn (object $unit): array => [
                        'unit_code' => $unit->unit_code,
                        'dimension' => $unit->dimension,
                        'conversion_kind' => $unit->conversion_kind,
                        'to_base_factor' => (string) $unit->to_base_factor,
                        'basis' => $unit->basis,
                    ])->all(),
                'aliases' => DB::table('raw_material_aliases')->where('revision_id', $revision->id)
                    ->orderBy('normalized_alias')->pluck('alias')->all(),
            ];
        }

        return [
            'id' => $material->id,
            'code' => $material->code,
            'lifecycle_state' => $material->lifecycle_state,
            'approved_revision_id' => $material->approved_revision_id,
            'revision' => $revisionData,
        ];
    }

    private function presentCurrent(User $actor, string $materialId): array
    {
        $material = $this->material($actor, $materialId);
        $revisionId = $material->approved_revision_id ?? $material->working_revision_id;
        $revision = $revisionId ? DB::table('raw_material_revisions')->where('id', $revisionId)->first() : null;

        return $this->presentMaterial($material, $revision);
    }

    private function audit(
        User $actor,
        Location $context,
        string $subjectType,
        ?string $subjectId,
        string $eventCode,
        ?string $beforeHash,
        array $metadata,
        ?string $reason = null,
        ?string $operationId = null,
        ?string $afterHashOverride = null,
    ): void {
        $metadata += $reason === null ? [] : ['reason' => $reason];
        $afterHash = $afterHashOverride ?? ($subjectType === 'RAW_MATERIAL' && $subjectId !== null
            ? $this->stateHash($this->material($actor, $subjectId))
            : hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        DB::table('raw_material_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => $operationId ?? Str::uuid()->toString(),
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event_code' => $eventCode,
            'outcome' => 'ACCEPTED',
            'reason_code' => $reason === null ? null : 'USER_PROVIDED',
            'before_hash' => $beforeHash,
            'after_hash' => $afterHash,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function stateHash(object $material): string
    {
        return hash('sha256', json_encode([
            'id' => $material->id,
            'code' => $material->code,
            'lifecycle_state' => $material->lifecycle_state,
            'approved_revision_id' => $material->approved_revision_id,
            'pending_revision_id' => $material->pending_revision_id,
            'working_revision_id' => $material->working_revision_id,
        ], JSON_THROW_ON_ERROR));
    }

    private static function normalizeTerm(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value), 'UTF-8');
    }
}
