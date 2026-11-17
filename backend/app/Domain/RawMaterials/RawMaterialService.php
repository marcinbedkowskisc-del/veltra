<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentSetService;
use App\Domain\Sku\SkuAllocationService;
use App\Domain\Sku\SkuPrefix;
use App\Http\Requests\InstallationRequest;
use App\Http\Requests\RawMaterialAllergenDeclarationRequest;
use App\Http\Requests\RawMaterialDataRequest;
use App\Http\Requests\RawMaterialStorageVariantsRequest;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RawMaterialService
{
    public function __construct(
        private RawMaterialAuthorization $authorization,
        private RawMaterialLocationSharePolicy $sharePolicy,
        private AuthorizationMutationContext $contexts,
        private SkuAllocationService $skuAllocation,
        private RawMaterialCatalogValues $catalogValues,
        private RawMaterialSkuReservationService $reservations,
        private RawMaterialDocumentSetService $documentSets,
        private RawMaterialPricingService $pricing,
    ) {}

    public function categories(User $actor, Location $context): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);

        return DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
            ->orderBy('depth')->orderBy('name')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    public function administrationCategories(User $actor, Location $context, array $filters): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        $query = DB::table('raw_material_categories as categories')
            ->leftJoin('raw_material_categories as parents', function ($join): void {
                $join->on('parents.id', '=', 'categories.parent_id')
                    ->on('parents.organization_id', '=', 'categories.organization_id');
            })
            ->where('categories.organization_id', $actor->organization_id)
            ->select([
                'categories.id',
                'categories.organization_id',
                'categories.parent_id',
                'parents.name as parent_name',
                'categories.code',
                'categories.name',
                'categories.description',
                'categories.depth',
                'categories.archived_at',
                'categories.archived_by',
            ])
            ->selectRaw('EXISTS (
                SELECT 1 FROM raw_material_categories children
                WHERE children.organization_id = categories.organization_id
                    AND children.parent_id = categories.id
            ) AS has_children')
            ->selectSub(
                DB::table('raw_material_revisions')
                    ->selectRaw('COUNT(DISTINCT raw_material_id)')
                    ->whereColumn('organization_id', 'categories.organization_id')
                    ->whereColumn('category_id', 'categories.id'),
                'raw_material_count',
            );

        match ($filters['status'] ?? 'active') {
            'archived' => $query->whereNotNull('categories.archived_at'),
            'all' => null,
            default => $query->whereNull('categories.archived_at'),
        };

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function (Builder $where) use ($term): void {
                $where->whereRaw('categories.name ILIKE ?', [$term])
                    ->orWhereRaw('categories.description ILIKE ?', [$term]);
            });
        } elseif (array_key_exists('parent_id', $filters)) {
            $filters['parent_id'] === null
                ? $query->whereNull('categories.parent_id')
                : $query->where('categories.parent_id', $filters['parent_id']);
        } else {
            $query->whereNull('categories.parent_id');
        }

        $page = $query->orderBy('categories.name')->orderBy('categories.id')
            ->paginate((int) ($filters['per_page'] ?? 50), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $items = $page->getCollection()->map(function (object $category): array {
            $row = (array) $category;
            $row['depth'] = (int) $row['depth'];
            $row['has_children'] = in_array($row['has_children'], [true, 1, '1', 't', 'true'], true);
            $row['raw_material_count'] = (int) $row['raw_material_count'];
            $row['path'] = [$row['name']];

            return $row;
        })->all();

        if ($search !== '') {
            $items = $this->addCategoryPaths($actor, $items);
        }

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ];
    }

    public function createAdministrationCategory(User $actor, Location $context, array $input): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, function () use (
            $actor,
            $context,
            $input,
        ): array {
            return DB::transaction(function () use ($actor, $context, $input): array {
                Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                $this->assertAdministrationCategoryAccess($actor, $context);
                $categoryId = $this->insertCategory($actor, $context, $input);

                return $this->administrationCategory($actor, $categoryId);
            });
        });
    }

    public function updateAdministrationCategory(User $actor, Location $context, string $categoryId, array $input): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use (
            $actor,
            $context,
            $categoryId,
            $input,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrationCategoryAccess($actor, $context);
            $this->updateCategoryRecord($actor, $context, $categoryId, $input);

            return $this->administrationCategory($actor, $categoryId);
        }));
    }

    public function archiveAdministrationCategory(User $actor, Location $context, string $categoryId): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use (
            $actor,
            $context,
            $categoryId,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrationCategoryAccess($actor, $context);
            $category = DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
                ->where('id', $categoryId)->lockForUpdate()->first();
            if (! $category) {
                throw new RawMaterialException('RAW_MATERIAL_CATEGORY_NOT_FOUND', __('messages.resource_not_found'), 404);
            }
            if ($category->archived_at !== null) {
                throw new RawMaterialException('RAW_MATERIAL_CATEGORY_ALREADY_ARCHIVED', 'Kategoria jest już zarchiwizowana.', 409);
            }

            $hasActiveDescendant = DB::scalar(<<<'SQL'
                WITH RECURSIVE descendants(id) AS (
                    SELECT id FROM raw_material_categories
                    WHERE organization_id = ? AND parent_id = ?
                    UNION ALL
                    SELECT children.id FROM raw_material_categories children
                    JOIN descendants ON children.parent_id = descendants.id
                    WHERE children.organization_id = ?
                )
                SELECT EXISTS (
                    SELECT 1 FROM raw_material_categories categories
                    JOIN descendants ON descendants.id = categories.id
                    WHERE categories.organization_id = ? AND categories.archived_at IS NULL
                )
                SQL, [$actor->organization_id, $categoryId, $actor->organization_id, $actor->organization_id]);
            if ($hasActiveDescendant) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CATEGORY_HAS_ACTIVE_DESCENDANTS',
                    'Nie można zarchiwizować kategorii, ponieważ ma aktywnego potomka. Archiwizuj podkategorie od najniższego poziomu, bez kaskady.',
                    409,
                );
            }

            $beforeHash = $this->categoryStateHash($category);
            DB::table('raw_material_categories')->where('id', $categoryId)->update([
                'archived_at' => now(),
                'archived_by' => $actor->id,
                'updated_at' => now(),
            ]);
            $updated = DB::table('raw_material_categories')->where('id', $categoryId)->first();
            $this->audit($actor, $context, 'CATEGORY', $categoryId, 'CATEGORY_ARCHIVED', $beforeHash, [
                'code' => $category->code,
                'raw_material_count' => $this->categoryRawMaterialCount($actor, $categoryId),
            ], afterHashOverride: $this->categoryStateHash($updated));

            return $this->administrationCategory($actor, $categoryId);
        }));
    }

    public function restoreAdministrationCategory(User $actor, Location $context, string $categoryId): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use (
            $actor,
            $context,
            $categoryId,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrationCategoryAccess($actor, $context);
            $category = DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
                ->where('id', $categoryId)->lockForUpdate()->first();
            if (! $category) {
                throw new RawMaterialException('RAW_MATERIAL_CATEGORY_NOT_FOUND', __('messages.resource_not_found'), 404);
            }
            if ($category->archived_at === null) {
                throw new RawMaterialException('RAW_MATERIAL_CATEGORY_NOT_ARCHIVED', 'Kategoria jest już aktywna.', 409);
            }
            if ($category->parent_id !== null) {
                $parent = DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
                    ->where('id', $category->parent_id)->first();
                if (! $parent || $parent->archived_at !== null) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_CATEGORY_PARENT_ARCHIVED',
                        'Nie można przywrócić kategorii, dopóki jej bezpośrednia kategoria nadrzędna jest zarchiwizowana.',
                        409,
                    );
                }
            }

            $beforeHash = $this->categoryStateHash($category);
            DB::table('raw_material_categories')->where('id', $categoryId)->update([
                'archived_at' => null,
                'archived_by' => null,
                'updated_at' => now(),
            ]);
            $updated = DB::table('raw_material_categories')->where('id', $categoryId)->first();
            $this->audit($actor, $context, 'CATEGORY', $categoryId, 'CATEGORY_RESTORED', $beforeHash, [
                'code' => $category->code,
            ], afterHashOverride: $this->categoryStateHash($updated));

            return $this->administrationCategory($actor, $categoryId);
        }));
    }

    public function administrationUnits(User $actor, Location $context, array $filters): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);
        $query = DB::table('raw_material_units');
        match ($filters['status'] ?? 'active') {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->whereNull('archived_at'),
        };
        if (isset($filters['dimension'])) {
            $query->where('dimension', $filters['dimension']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function (Builder $where) use ($term): void {
                $where->whereRaw('name ILIKE ?', [$term])
                    ->orWhereRaw('symbol ILIKE ?', [$term])
                    ->orWhereRaw('code ILIKE ?', [$term]);
            });
        }

        $units = $query->orderBy('dimension')->orderBy('name')->orderBy('code')->get();
        $sameDimensionUnits = DB::table('raw_material_units')->get()->groupBy('dimension');
        $data = $units->map(function (object $unit) use ($sameDimensionUnits): array {
            $row = (array) $unit;
            $row['is_base'] = $unit->code === $unit->base_unit_code;
            $row['definition_locked'] = $this->administrationUnitHasReferences($unit->code);
            $row['reference_count'] = $this->administrationUnitReferenceCount($unit->code);
            $row['conversions'] = ($sameDimensionUnits->get($unit->dimension, collect()))
                ->where('code', '<>', $unit->code)
                ->map(fn (object $target): array => [
                    'unit_code' => $target->code,
                    'name' => $target->name,
                    'symbol' => $target->symbol,
                    'archived_at' => $target->archived_at,
                    'factor' => RawMaterialUnitRegistry::factorToBase(
                        (string) $unit->to_base_factor,
                        (string) $target->to_base_factor,
                    ),
                ])->values()->all();

            return $row;
        })->all();

        return ['data' => $data];
    }

    public function rawMaterialUnitOptions(User $actor, Location $context): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);

        return DB::table('raw_material_units')
            ->orderBy('dimension')->orderBy('name')->orderBy('code')
            ->get([
                'code',
                'name',
                'symbol',
                'dimension',
                'base_unit_code',
                'to_base_factor',
                'archived_at',
            ])
            ->map(fn (object $unit): array => [
                'code' => $unit->code,
                'name' => $unit->name,
                'symbol' => $unit->symbol,
                'dimension' => $unit->dimension,
                'base_unit_code' => $unit->base_unit_code,
                'to_base_factor' => (string) $unit->to_base_factor,
                'archived_at' => $unit->archived_at,
                'is_base' => $unit->code === $unit->base_unit_code,
            ])->all();
    }

    public function createAdministrationUnit(User $actor, Location $context, array $input): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use (
            $actor,
            $context,
            $input,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrationCategoryAccess($actor, $context);
            $this->assertCanonicalUnit($input['base_unit_code'], $input['dimension']);
            $factor = $this->normalizedUnitFactor($input['to_base_factor']);
            $id = Str::uuid()->toString();
            $now = now();
            try {
                DB::table('raw_material_units')->insert([
                    'id' => $id,
                    'code' => trim($input['code']),
                    'name' => trim($input['name']),
                    'symbol' => trim($input['symbol']),
                    'dimension' => $input['dimension'],
                    'base_unit_code' => $input['base_unit_code'],
                    'to_base_factor' => $factor,
                    'created_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) === '23505'
                    && str_contains($exception->getMessage(), 'raw_material_units_code_key')) {
                    throw ValidationException::withMessages([
                        'code' => ['Kod jednostki jest już używany.'],
                    ]);
                }
                throw $exception;
            }
            $unit = DB::table('raw_material_units')->where('id', $id)->first();
            $this->audit($actor, $context, 'RAW_UNIT', $id, 'UNIT_CREATED', null, [
                'code' => $unit->code,
                'dimension' => $unit->dimension,
            ], afterHashOverride: $this->administrationUnitStateHash($unit));

            return $this->administrationUnit($unit);
        }));
    }

    public function updateAdministrationUnit(User $actor, Location $context, string $unitId, array $input): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use (
            $actor,
            $context,
            $unitId,
            $input,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrationCategoryAccess($actor, $context);
            $unit = DB::table('raw_material_units')->where('id', $unitId)->lockForUpdate()->first();
            if (! $unit) {
                throw new RawMaterialException('RAW_MATERIAL_UNIT_NOT_FOUND', __('messages.resource_not_found'), 404);
            }
            if ($unit->archived_at !== null) {
                throw new RawMaterialException('RAW_MATERIAL_UNIT_ARCHIVED', 'Przywróć jednostkę przed jej edycją.', 409);
            }
            $this->assertCanonicalUnit($input['base_unit_code'], $input['dimension']);
            $factor = $this->normalizedUnitFactor($input['to_base_factor']);
            $definitionChanged = $unit->dimension !== $input['dimension']
                || $unit->base_unit_code !== $input['base_unit_code']
                || (string) $unit->to_base_factor !== $factor;
            if ($definitionChanged && $this->administrationUnitHasReferences($unit->code)) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_UNIT_DEFINITION_IN_USE',
                    'Nie można zmienić wymiaru ani przelicznika jednostki użytej w danych historycznych.',
                    409,
                );
            }

            $beforeHash = $this->administrationUnitStateHash($unit);
            DB::table('raw_material_units')->where('id', $unitId)->update([
                'name' => trim($input['name']),
                'symbol' => trim($input['symbol']),
                'dimension' => $input['dimension'],
                'base_unit_code' => $input['base_unit_code'],
                'to_base_factor' => $factor,
                'updated_at' => now(),
            ]);
            $updated = DB::table('raw_material_units')->where('id', $unitId)->first();
            $this->audit($actor, $context, 'RAW_UNIT', $unitId, 'UNIT_UPDATED', $beforeHash, [
                'code' => $unit->code,
                'definition_changed' => $definitionChanged,
            ], afterHashOverride: $this->administrationUnitStateHash($updated));

            return $this->administrationUnit($updated);
        }));
    }

    public function archiveAdministrationUnit(User $actor, Location $context, string $unitId): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use (
            $actor,
            $context,
            $unitId,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrationCategoryAccess($actor, $context);
            $unit = DB::table('raw_material_units')->where('id', $unitId)->lockForUpdate()->first();
            if (! $unit) {
                throw new RawMaterialException('RAW_MATERIAL_UNIT_NOT_FOUND', __('messages.resource_not_found'), 404);
            }
            if ($unit->code === $unit->base_unit_code) {
                throw new RawMaterialException('RAW_MATERIAL_BASE_UNIT_PROTECTED', 'Jednostki bazowej wymiaru nie można archiwizować.', 409);
            }
            if ($unit->archived_at !== null) {
                throw new RawMaterialException('RAW_MATERIAL_UNIT_ALREADY_ARCHIVED', 'Jednostka jest już zarchiwizowana.', 409);
            }

            $beforeHash = $this->administrationUnitStateHash($unit);
            DB::table('raw_material_units')->where('id', $unitId)->update([
                'archived_at' => now(),
                'archived_by' => $actor->id,
                'updated_at' => now(),
            ]);
            $updated = DB::table('raw_material_units')->where('id', $unitId)->first();
            $this->audit($actor, $context, 'RAW_UNIT', $unitId, 'UNIT_ARCHIVED', $beforeHash, [
                'code' => $unit->code,
                'reference_count' => $this->administrationUnitReferenceCount($unit->code),
            ], afterHashOverride: $this->administrationUnitStateHash($updated));

            return $this->administrationUnit($updated);
        }));
    }

    public function restoreAdministrationUnit(User $actor, Location $context, string $unitId): array
    {
        $this->assertAdministrationCategoryAccess($actor, $context);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use (
            $actor,
            $context,
            $unitId,
        ): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrationCategoryAccess($actor, $context);
            $unit = DB::table('raw_material_units')->where('id', $unitId)->lockForUpdate()->first();
            if (! $unit) {
                throw new RawMaterialException('RAW_MATERIAL_UNIT_NOT_FOUND', __('messages.resource_not_found'), 404);
            }
            if ($unit->archived_at === null) {
                throw new RawMaterialException('RAW_MATERIAL_UNIT_NOT_ARCHIVED', 'Jednostka jest już aktywna.', 409);
            }
            $this->assertCanonicalUnit($unit->base_unit_code, $unit->dimension);

            $beforeHash = $this->administrationUnitStateHash($unit);
            DB::table('raw_material_units')->where('id', $unitId)->update([
                'archived_at' => null,
                'archived_by' => null,
                'updated_at' => now(),
            ]);
            $updated = DB::table('raw_material_units')->where('id', $unitId)->first();
            $this->audit($actor, $context, 'RAW_UNIT', $unitId, 'UNIT_RESTORED', $beforeHash, [
                'code' => $unit->code,
            ], afterHashOverride: $this->administrationUnitStateHash($updated));

            return $this->administrationUnit($updated);
        }));
    }

    private function supplierIdsForEditor(User $actor, string $materialId): array
    {
        if (! $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::SupplierManage)
            || ! $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::SupplierView)) {
            return [];
        }

        return DB::table('raw_material_supplier_associations')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $materialId)
            ->orderBy('supplier_id')->pluck('supplier_id')->all();
    }

    private function syncSupplierAssociations(User $actor, Location $context, string $materialId, array $input): void
    {
        if (! array_key_exists('supplier_ids', $input)) {
            return;
        }
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::SupplierManage);
        $supplierIds = array_values(array_unique($input['supplier_ids']));
        $activeCount = DB::table('organization_suppliers')
            ->where('organization_id', $actor->organization_id)
            ->where('lifecycle_state', 'ACTIVE')
            ->whereIn('id', $supplierIds)->count();
        if ($activeCount !== count($supplierIds)) {
            throw ValidationException::withMessages([
                'supplier_ids' => ['Wybierz aktywnych dostawców z biblioteki bieżącej organizacji.'],
            ]);
        }

        $this->contexts->run(AuthorizationMutationScope::rawMaterialCommercial(), $actor->id, function () use (
            $actor,
            $materialId,
            $supplierIds,
        ): void {
            $query = DB::table('raw_material_supplier_associations')
                ->where('organization_id', $actor->organization_id)
                ->where('raw_material_id', $materialId);
            if ($supplierIds === []) {
                $query->delete();
            } else {
                $query->whereNotIn('supplier_id', $supplierIds)->delete();
                $existing = DB::table('raw_material_supplier_associations')
                    ->where('organization_id', $actor->organization_id)
                    ->where('raw_material_id', $materialId)
                    ->whereIn('supplier_id', $supplierIds)->pluck('supplier_id')->all();
                foreach (array_diff($supplierIds, $existing) as $supplierId) {
                    DB::table('raw_material_supplier_associations')->insert([
                        'id' => Str::uuid()->toString(),
                        'organization_id' => $actor->organization_id,
                        'raw_material_id' => $materialId,
                        'supplier_id' => $supplierId,
                        'created_by' => $actor->id,
                    ]);
                }
            }
        });
    }

    public function createCategory(User $actor, Location $context, array $input): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::CategoryManage);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $input): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::CategoryManage);

            return (array) DB::table('raw_material_categories')->where('id', $this->insertCategory($actor, $context, $input))->first();
        }));
    }

    public function renameCategory(User $actor, Location $context, string $categoryId, string $name): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::CategoryManage);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $categoryId, $name): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::CategoryManage);

            $this->updateCategoryRecord($actor, $context, $categoryId, ['name' => trim($name)], 'CATEGORY_RENAMED');

            return (array) DB::table('raw_material_categories')->where('id', $categoryId)->first();
        }));
    }

    private function assertAdministrationCategoryAccess(User $actor, Location $context): void
    {
        $authorization = app(BusinessAuthorization::class);
        if (! $authorization->eligible($actor, $context)
            || ! $authorization->hasRole($actor, BusinessRole::Administrator)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
    }

    private function assertCanonicalUnit(string $code, string $dimension): void
    {
        $base = DB::table('raw_material_units')->where('code', $code)->whereNull('archived_at')->first();
        if (! $base || $base->dimension !== $dimension || $base->code !== $base->base_unit_code
            || (string) $base->to_base_factor !== '1.000000000000') {
            throw ValidationException::withMessages([
                'base_unit_code' => ['Wybierz aktywną jednostkę bazową tego samego wymiaru.'],
            ]);
        }
    }

    private function normalizedUnitFactor(string $value): string
    {
        $result = DB::selectOne(
            'SELECT CAST(? AS numeric(24,12))::text AS factor, CAST(? AS numeric) > 0 AS positive',
            [$value, $value],
        );
        if (! $result->positive) {
            throw ValidationException::withMessages([
                'to_base_factor' => ['Współczynnik musi być dodatni.'],
            ]);
        }

        return $result->factor;
    }

    private function administrationUnitHasReferences(string $code): bool
    {
        return $this->administrationUnitReferenceCount($code) > 0;
    }

    private function administrationUnitReferenceCount(string $code): int
    {
        return (int) DB::scalar(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM raw_material_revisions WHERE base_unit_code = ?)
                + (SELECT COUNT(*) FROM raw_material_revision_units WHERE unit_code = ?)
                + (SELECT COUNT(*) FROM raw_material_catalog_import_rows WHERE base_unit_code = ?)
                + (SELECT COUNT(*) FROM supplier_product_revisions WHERE content_unit_code = ?)
                + (SELECT COUNT(*) FROM raw_material_reference_price_events WHERE pricing_input_unit_code = ?)
            SQL, [$code, $code, $code, $code, $code]);
    }

    private function administrationUnitStateHash(object $unit): string
    {
        return hash('sha256', json_encode([
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'symbol' => $unit->symbol,
            'dimension' => $unit->dimension,
            'base_unit_code' => $unit->base_unit_code,
            'to_base_factor' => (string) $unit->to_base_factor,
            'archived_at' => $unit->archived_at,
            'archived_by' => $unit->archived_by,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function administrationUnit(object $unit): array
    {
        return [
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'symbol' => $unit->symbol,
            'dimension' => $unit->dimension,
            'base_unit_code' => $unit->base_unit_code,
            'to_base_factor' => (string) $unit->to_base_factor,
            'archived_at' => $unit->archived_at,
            'archived_by' => $unit->archived_by,
            'is_base' => $unit->code === $unit->base_unit_code,
            'definition_locked' => $this->administrationUnitHasReferences($unit->code),
            'reference_count' => $this->administrationUnitReferenceCount($unit->code),
            'conversions' => [],
        ];
    }

    private function insertCategory(User $actor, Location $context, array $input): string
    {
        $parent = null;
        if (isset($input['parent_id'])) {
            $parent = DB::table('raw_material_categories')
                ->where('organization_id', $actor->organization_id)
                ->where('id', $input['parent_id'])
                ->first();
            if (! $parent) {
                throw new RawMaterialException('RAW_MATERIAL_CATEGORY_NOT_FOUND', __('messages.resource_not_found'), 404);
            }
            if ($parent->archived_at !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Nową podkategorię można dodać wyłącznie do aktywnej kategorii.'],
                ]);
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
        $description = isset($input['description']) ? trim($input['description']) : null;
        $description = $description === '' ? null : $description;
        try {
            DB::table('raw_material_categories')->insert([
                'id' => $id,
                'organization_id' => $actor->organization_id,
                'parent_id' => $parent?->id,
                'code' => $input['code'],
                'name' => trim($input['name']),
                'description' => $description,
                'depth' => $depth,
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505'
                && str_contains($exception->getMessage(), 'raw_material_categories_organization_id_code_key')) {
                throw ValidationException::withMessages([
                    'code' => ['Kod kategorii jest już używany w tej organizacji.'],
                ]);
            }
            throw $exception;
        }
        $category = DB::table('raw_material_categories')->where('id', $id)->first();
        $this->audit($actor, $context, 'CATEGORY', $id, 'CATEGORY_CREATED', null, [
            'code' => $input['code'],
            'name' => $category->name,
            'parent_id' => $category->parent_id,
        ], afterHashOverride: $this->categoryStateHash($category));

        return $id;
    }

    private function updateCategoryRecord(
        User $actor,
        Location $context,
        string $categoryId,
        array $input,
        string $eventCode = 'CATEGORY_UPDATED',
    ): object {
        $category = DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
            ->where('id', $categoryId)->lockForUpdate()->first();
        if (! $category) {
            throw new RawMaterialException('RAW_MATERIAL_CATEGORY_NOT_FOUND', __('messages.resource_not_found'), 404);
        }
        if ($category->archived_at !== null) {
            throw new RawMaterialException(
                'RAW_MATERIAL_CATEGORY_ARCHIVED',
                'Zarchiwizowanej kategorii nie można edytować. Najpierw ją przywróć.',
                409,
            );
        }

        $name = array_key_exists('name', $input) ? trim($input['name']) : $category->name;
        $description = array_key_exists('description', $input)
            ? (($input['description'] === null || trim($input['description']) === '') ? null : trim($input['description']))
            : $category->description;
        if ($name === $category->name && $description === $category->description) {
            return $category;
        }

        $beforeHash = $this->categoryStateHash($category);
        DB::table('raw_material_categories')->where('id', $categoryId)->update([
            'name' => $name,
            'description' => $description,
            'updated_at' => now(),
        ]);
        $updated = DB::table('raw_material_categories')->where('id', $categoryId)->first();
        $this->audit($actor, $context, 'CATEGORY', $categoryId, $eventCode, $beforeHash, [
            'code' => $category->code,
            'name_before' => $category->name,
            'name_after' => $name,
            'description_changed' => $description !== $category->description,
        ], afterHashOverride: $this->categoryStateHash($updated));

        return $updated;
    }

    private function administrationCategory(User $actor, string $categoryId): array
    {
        $category = DB::table('raw_material_categories as categories')
            ->leftJoin('raw_material_categories as parents', function ($join): void {
                $join->on('parents.id', '=', 'categories.parent_id')
                    ->on('parents.organization_id', '=', 'categories.organization_id');
            })
            ->where('categories.organization_id', $actor->organization_id)
            ->where('categories.id', $categoryId)
            ->select([
                'categories.id',
                'categories.parent_id',
                'parents.name as parent_name',
                'categories.code',
                'categories.name',
                'categories.description',
                'categories.depth',
                'categories.archived_at',
                'categories.archived_by',
            ])
            ->selectRaw('EXISTS (
                SELECT 1 FROM raw_material_categories children
                WHERE children.organization_id = categories.organization_id
                    AND children.parent_id = categories.id
            ) AS has_children')
            ->first();
        if (! $category) {
            throw new RawMaterialException('RAW_MATERIAL_CATEGORY_NOT_FOUND', __('messages.resource_not_found'), 404);
        }

        $row = (array) $category;
        $row['depth'] = (int) $row['depth'];
        $row['has_children'] = in_array($row['has_children'], [true, 1, '1', 't', 'true'], true);
        $row['raw_material_count'] = $this->categoryRawMaterialCount($actor, $categoryId);
        $row['path'] = $this->categoryPath($actor, $category);

        return $row;
    }

    private function categoryRawMaterialCount(User $actor, string $categoryId): int
    {
        return (int) DB::table('raw_material_revisions')->where('organization_id', $actor->organization_id)
            ->where('category_id', $categoryId)->distinct('raw_material_id')->count('raw_material_id');
    }

    private function addCategoryPaths(User $actor, array $categories): array
    {
        $ancestors = [];
        $parentIds = array_values(array_unique(array_filter(array_column($categories, 'parent_id'))));
        while ($parentIds !== []) {
            $parents = DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
                ->whereIn('id', $parentIds)->get(['id', 'parent_id', 'name']);
            $parentIds = [];
            foreach ($parents as $parent) {
                $ancestors[$parent->id] = $parent;
                if ($parent->parent_id !== null && ! isset($ancestors[$parent->parent_id])) {
                    $parentIds[] = $parent->parent_id;
                }
            }
            $parentIds = array_values(array_unique($parentIds));
        }

        foreach ($categories as &$category) {
            $path = [$category['name']];
            $parentId = $category['parent_id'];
            while ($parentId !== null && isset($ancestors[$parentId])) {
                array_unshift($path, $ancestors[$parentId]->name);
                $parentId = $ancestors[$parentId]->parent_id;
            }
            $category['path'] = $path;
        }

        return $categories;
    }

    private function categoryPath(User $actor, object $category): array
    {
        $ancestors = [];
        $parentId = $category->parent_id;
        while ($parentId !== null) {
            $parent = DB::table('raw_material_categories')->where('organization_id', $actor->organization_id)
                ->where('id', $parentId)->first(['id', 'parent_id', 'name']);
            if (! $parent) {
                break;
            }
            array_unshift($ancestors, $parent->name);
            $parentId = $parent->parent_id;
        }
        $ancestors[] = $category->name;

        return $ancestors;
    }

    private function categoryStateHash(object $category): string
    {
        return hash('sha256', json_encode([
            'id' => $category->id,
            'organization_id' => $category->organization_id,
            'parent_id' => $category->parent_id,
            'code' => $category->code,
            'name' => $category->name,
            'description' => $category->description,
            'depth' => $category->depth,
            'archived_at' => $category->archived_at,
            'archived_by' => $category->archived_by,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function list(User $actor, Location $context, array $filters): array
    {
        return $this->paginateCatalog($this->catalogQuery($actor, $context, $filters), $filters);
    }

    public function workbench(User $actor, Location $context, array $filters): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        if (! $this->sharePolicy->isPrimaryContext($context) || ! $this->canInspectWorkingRevision($actor)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
        if (isset($filters['supplier_id'])
            || in_array($filters['sort'] ?? 'name', ['reference_net_price', 'usable_unit_cost'], true)) {
            throw ValidationException::withMessages([
                'filters' => ['Widok szkiców nie udostępnia filtrów dostawców ani sortowania finansowego.'],
            ]);
        }

        return $this->paginateCatalog($this->buildCatalogQuery($actor, $context, $filters, working: true), $filters);
    }

    private function paginateCatalog(Builder $query, array $filters): array
    {
        $paginator = $query->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);
        $data = $paginator->getCollection()->map(fn (object $row, int $index): array => [
            'id' => $row->id,
            'code' => $row->code,
            'sku' => $row->code,
            'ordinal' => ($paginator->currentPage() - 1) * $paginator->perPage() + $index + 1,
            ...RawMaterialCatalogValues::present($row),
            'lifecycle_state' => $row->lifecycle_state,
            ...(property_exists($row, 'workflow_state') ? ['workflow_state' => $row->workflow_state] : []),
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
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function catalogQuery(User $actor, Location $context, array $filters, bool $withOrdinal = false): Builder
    {
        return $this->buildCatalogQuery($actor, $context, $filters, $withOrdinal);
    }

    private function buildCatalogQuery(User $actor, Location $context, array $filters, bool $withOrdinal = false, bool $working = false): Builder
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $query = DB::table('raw_materials as materials')
            ->leftJoin('raw_material_revisions as revisions', function ($join) use ($working): void {
                if ($working) {
                    $join->whereRaw('revisions.id = COALESCE(materials.working_revision_id, materials.pending_revision_id)');
                } else {
                    $join->on('revisions.id', '=', 'materials.approved_revision_id');
                }
                $join->on('revisions.organization_id', '=', 'materials.organization_id');
            })
            ->leftJoin('raw_material_categories as categories', 'categories.id', '=', 'revisions.category_id')
            ->where('materials.organization_id', $actor->organization_id)
            ->whereNull('materials.deleted_at')
            ->where('materials.lifecycle_state', '<>', 'ARCHIVED')
            ->select([
                'materials.id', 'materials.code', 'materials.lifecycle_state',
                'revisions.id as revision_id', 'revisions.revision_no', 'revisions.name', 'revisions.description',
                'revisions.base_unit_code', 'revisions.allergen_state',
                'categories.id as category_id', 'categories.name as category_name',
            ]);
        if ($working) {
            $query->whereNotNull('revisions.id')
                ->addSelect('revisions.nominal_quantity', 'revisions.usable_quantity')
                ->selectRaw('raw_materials_round_half_even_v1(revisions.usable_quantity * 100 / NULLIF(revisions.nominal_quantity, 0), 6)::text AS yield_percent')
                ->selectRaw("CASE WHEN materials.working_revision_id IS NOT NULL THEN 'DRAFT' ELSE 'VERIFICATION' END AS workflow_state");
        } else {
            $query->whereNotNull('materials.approved_revision_id');
            $this->catalogValues->select($query, $actor, $context);
            $this->sharePolicy->constrainVisibleMaterials($query, $actor, $context);
        }

        if (isset($filters['search'])) {
            $search = self::normalizeTerm($filters['search']);
            $query->where(function ($builder) use ($search, $filters): void {
                $builder->whereRaw('lower(revisions.name) LIKE ?', ['%'.addcslashes($search, '%_\\').'%'])
                    ->orWhere('materials.code', 'ilike', '%'.addcslashes($filters['search'], '%_\\').'%')
                    ->orWhereExists(fn ($aliases) => $aliases->selectRaw('1')->from('raw_material_aliases as aliases')
                        ->whereColumn('aliases.raw_material_id', 'materials.id')
                        ->where('aliases.organization_id', DB::raw('materials.organization_id'))
                        ->whereColumn('aliases.revision_id', 'revisions.id')
                        ->where('aliases.normalized_alias', 'like', '%'.addcslashes($search, '%_\\').'%'));
            });
        }
        if (isset($filters['category_id'])) {
            $query->where('revisions.category_id', $filters['category_id']);
        }
        if (isset($filters['lifecycle_state'])) {
            if ($working) {
                $query->whereRaw("CASE WHEN materials.working_revision_id IS NOT NULL THEN 'DRAFT' ELSE 'VERIFICATION' END = ?", [$filters['lifecycle_state']]);
            } else {
                $query->where('materials.lifecycle_state', $filters['lifecycle_state']);
            }
        }
        if (isset($filters['unit'])) {
            $query->where('revisions.base_unit_code', $filters['unit']);
        }
        if (isset($filters['supplier_id'])) {
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::SupplierView);
            $query->whereExists(fn ($products) => $products->selectRaw('1')
                ->from('supplier_products as products')
                ->join('organization_suppliers as suppliers', function ($join): void {
                    $join->on('suppliers.id', '=', 'products.supplier_id')
                        ->on('suppliers.organization_id', '=', 'products.organization_id');
                })
                ->join('supplier_product_revisions as product_revisions', function ($join): void {
                    $join->on('product_revisions.supplier_product_id', '=', 'products.id')
                        ->on('product_revisions.organization_id', '=', 'products.organization_id');
                })
                ->whereColumn('products.organization_id', 'materials.organization_id')
                ->whereColumn('products.raw_material_id', 'materials.id')
                ->where('products.supplier_id', $filters['supplier_id'])
                ->where('products.lifecycle_state', 'ACTIVE')->where('suppliers.lifecycle_state', 'ACTIVE')
                ->whereColumn('product_revisions.raw_material_revision_id', 'materials.approved_revision_id')
                ->whereRaw('product_revisions.revision_no = (SELECT max(latest.revision_no) FROM supplier_product_revisions latest
                    WHERE latest.supplier_product_id = products.id AND latest.organization_id = products.organization_id)'));
        }

        $sortColumns = [
            'code' => 'materials.code', 'sku' => 'materials.code', 'name' => 'revisions.name',
            'unit' => 'revisions.base_unit_code', 'nominal_quantity' => 'revisions.nominal_quantity',
            'usable_quantity' => 'revisions.usable_quantity', 'lifecycle_state' => 'materials.lifecycle_state',
        ];
        $sortKey = $filters['sort'] ?? 'name';
        if (in_array($sortKey, ['reference_net_price', 'usable_unit_cost'], true)) {
            if (($sortKey === 'reference_net_price' && ! $this->catalogValues->canReadPrice($actor))
                || ($sortKey === 'usable_unit_cost' && ! $this->catalogValues->canReadCost($actor, $context))) {
                throw new AuthorizationException(__('messages.unauthorized'));
            }
            $sort = $sortKey === 'reference_net_price' ? RawMaterialCatalogValues::packageExpression() : RawMaterialCatalogValues::costExpression();
        } else {
            $sort = $sortColumns[$sortKey] ?? throw ValidationException::withMessages(['sort' => ['Nieprawidłowe sortowanie katalogu.']]);
        }
        $direction = $filters['direction'] ?? 'asc';
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::withMessages(['direction' => ['Nieprawidłowy kierunek sortowania.']]);
        }

        if ($withOrdinal) {
            $query->selectRaw('row_number() over (ORDER BY '.$sort.' '.$direction.' NULLS LAST, materials.id) AS export_ordinal');
        }

        return $query->orderByRaw($sort.' '.$direction.' NULLS LAST')->orderBy('materials.id');
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

        $result = $this->presentMaterial($material, $revision);
        if (! $this->sharePolicy->isPrimaryContext($context) || ! $this->canInspectWorkingRevision($actor)
            || $revisionId !== ($material->working_revision_id ?? $material->pending_revision_id ?? $material->approved_revision_id)) {
            unset($result['version']);
        }
        $result += app(RawMaterialHistoryService::class)->metadata($actor, $context, $material);
        if ($revision !== null) {
            $query = DB::table('raw_materials as materials')
                ->join('raw_material_revisions as revisions', 'revisions.raw_material_id', '=', 'materials.id')
                ->where('materials.organization_id', $actor->organization_id)
                ->where('materials.id', $materialId)->where('revisions.id', $revision->id)
                ->select('revisions.base_unit_code');
            $this->catalogValues->select($query, $actor, $context);
            $result += RawMaterialCatalogValues::present($query->first());
        }

        return $result;
    }

    public function editor(User $actor, Location $context, string $materialId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        if (! $this->sharePolicy->isPrimaryContext($context)
            || ! $this->canInspectWorkingRevision($actor)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
        $material = $this->material($actor, $materialId);
        $revisionId = $material->working_revision_id ?? $material->pending_revision_id ?? $material->approved_revision_id;
        $revision = DB::table('raw_material_revisions')->where('id', $revisionId)->firstOrFail();
        $declaration = $this->allergenDeclarationForRevision($revision->id);
        if ($declaration !== null) {
            unset($declaration['attested_by'], $declaration['attested_at']);
        }

        $quantities = DB::table('raw_material_revisions')->where('id', $revisionId)
            ->select('nominal_quantity', 'usable_quantity', 'usable_quantity_explicit')
            ->selectRaw('raw_materials_round_half_even_v1(usable_quantity * 100 / NULLIF(nominal_quantity, 0), 6)::text AS yield_percent')
            ->first();

        return $this->presentMaterial($material, $revision)
            + RawMaterialCatalogValues::present($quantities)
            + app(RawMaterialHistoryService::class)->metadata($actor, $context, $material) + [
                'pricing_input' => $this->pricingInputForEditor($actor, $revision),
                'supplier_ids' => $this->supplierIdsForEditor($actor, $material->id),
                'allergen_declaration' => $declaration,
                'storage_variants' => $this->storageVariantsForRevision($revision->id),
            ];
    }

    public function completion(User $actor, Location $context, string $materialId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $inspectWorking = $this->sharePolicy->isPrimaryContext($context) && $this->canInspectWorkingRevision($actor);
        if (! $inspectWorking) {
            $this->show($actor, $context, $materialId);
        }
        $material = $this->material($actor, $materialId);
        $revisionId = $inspectWorking
            ? ($material->working_revision_id ?? $material->pending_revision_id ?? $material->approved_revision_id)
            : $material->approved_revision_id;
        $revision = DB::table('raw_material_revisions')->where('id', $revisionId)->firstOrFail();
        $problems = $this->approvalProblems($revision);
        $actions = $inspectWorking ? $this->availableActions($actor, $context, $material, $problems) : [];

        return [
            'lifecycle_state' => $material->lifecycle_state,
            'revision_id' => $revision->id,
            'version' => $inspectWorking ? $material->edit_version : null,
            'error_count' => count($problems['errors']),
            'missing_requirement_count' => count($problems['missing_requirements']),
            ...$problems,
            'eligible_for_next_action' => array_diff($actions, ['edit', 'edit_allergens', 'edit_storage']) !== [],
            'available_actions' => $actions,
        ];
    }

    public function validateEdits(User $actor, Location $context, string $materialId, array $input, string $section = 'basic'): array
    {
        $permission = match ($section) {
            'basic' => RawMaterialPermission::Edit,
            'allergens' => RawMaterialPermission::AllergenManage,
            'storage' => RawMaterialPermission::StorageManage,
            default => throw ValidationException::withMessages(['section' => ['Nieprawidłowa sekcja walidacji.']]),
        };
        $this->authorization->assertAllowed($actor, $context, $permission);
        $snapshot = $this->editor($actor, $context, $materialId);
        $report = $this->completion($actor, $context, $materialId);
        $errors = [];
        if (! $this->isEditable($this->material($actor, $materialId))) {
            $errors[] = [
                'code' => 'RAW_MATERIAL_REVISION_NOT_EDITABLE', 'field' => 'revision', 'section' => $section,
                'message' => __('messages.raw_material_revision_not_editable'),
            ];
        }
        try {
            if ($section === 'basic') {
                $this->validateForm(new RawMaterialDataRequest, $input);
                $base = DB::table('raw_material_revisions')->where('id', $snapshot['revision']['id'])->firstOrFail();
                $input = $this->mergeRevisionInput($base, $input, RawMaterialPermission::Edit);
                [$unitData] = $this->validateBasicData(
                    $actor,
                    $input,
                    $materialId,
                    $base->category_id,
                    $base->id,
                );
                $this->pricing->prepareRevisionInput($actor, $context, $input, $unitData, $base);
            } elseif ($section === 'allergens') {
                $this->validateForm(new RawMaterialAllergenDeclarationRequest, $input);
                $this->validateAllergenDeclaration($input);
                $report['missing_requirements'] = $input['status'] === 'UNKNOWN' ? [[
                    'code' => 'RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED', 'field' => 'allergen_declaration',
                    'section' => 'allergens', 'message' => __('messages.raw_material_allergen_declaration_required'),
                ]] : [];
                $report['missing_requirement_count'] = count($report['missing_requirements']);
            } else {
                $this->validateForm(new RawMaterialStorageVariantsRequest, $input);
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['code' => 'INVALID_VALUE', 'field' => $field, 'section' => $section, 'message' => $message];
                }
            }
        } catch (RawMaterialException $exception) {
            if ($exception->status !== 422) {
                throw $exception;
            }
            $errors[] = ['code' => $exception->errorCode, 'field' => $section === 'basic' ? 'name' : 'allergen_declaration', 'section' => $section, 'message' => $exception->getMessage()];
        }
        $report['errors'] = array_merge($report['errors'], $errors);
        $report['error_count'] = count($report['errors']);
        // A preview never authorizes a transition of the not-yet-saved revision.
        $report['eligible_for_next_action'] = false;
        $report['available_actions'] = [];

        return $report;
    }

    private function validateForm(InstallationRequest $form, array $input): void
    {
        $form->replace($input);
        $rules = $form->rules();
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['request' => [__('messages.unknown_request_fields', ['fields' => implode(', ', $unknown)])]]);
        }
        $validator = Validator::make($input, $rules);
        if (method_exists($form, 'after')) {
            $validator->after($form->after());
        }
        $validator->validate();
    }

    private function canInspectWorkingRevision(User $actor): bool
    {
        return $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::Edit)
            || $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::AuditView);
    }

    private function approvalProblems(object $revision): array
    {
        $missing = [];
        $errors = [];
        if ($revision->allergen_state !== 'DECLARED'
            || (in_array((int) $revision->content_hash_version, [2, 3], true)
                && ! $this->hasValidV2AllergenAttestation($revision))) {
            $missing[] = [
                'code' => 'RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED', 'field' => 'allergen_declaration',
                'section' => 'allergens', 'message' => __('messages.raw_material_allergen_declaration_required'),
            ];
        }
        if (! hash_equals($revision->content_hash, $this->hashFromDatabase($revision))) {
            $errors[] = [
                'code' => 'RAW_MATERIAL_REVISION_HASH_MISMATCH', 'field' => 'revision',
                'section' => 'basic', 'message' => __('messages.raw_material_revision_integrity_failed'),
            ];
        }

        return ['errors' => $errors, 'missing_requirements' => $missing];
    }

    private function availableActions(User $actor, Location $context, object $material, array $problems): array
    {
        $state = $material->lifecycle_state;
        $working = $material->working_revision_id !== null;
        $pending = $material->pending_revision_id !== null;
        $complete = $problems['errors'] === [] && $problems['missing_requirements'] === [];
        $editable = $this->isEditable($material);
        $revisionId = $material->working_revision_id ?? $material->pending_revision_id ?? $material->approved_revision_id;
        $hasAllergens = DB::table('raw_material_revision_allergen_attestations')->where('revision_id', $revisionId)->exists();
        $hasStorage = DB::table('raw_material_revision_storage_variants')->where('revision_id', $revisionId)->exists();
        $canCopyAllergens = ! $hasAllergens || $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::AllergenManage);
        $canCopyStorage = ! $hasStorage || $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::StorageManage);
        $candidates = [
            'edit' => [$editable && $canCopyAllergens && $canCopyStorage, RawMaterialPermission::Edit],
            'edit_allergens' => [$editable && $canCopyStorage, RawMaterialPermission::AllergenManage],
            'edit_storage' => [$editable && $canCopyAllergens, RawMaterialPermission::StorageManage],
            'submit' => [$working && in_array($state, ['DRAFT', 'ACTIVE'], true), RawMaterialPermission::Submit],
            'approve' => [$pending && in_array($state, ['VERIFICATION', 'ACTIVE'], true) && $complete, RawMaterialPermission::Approve],
            'return_for_changes' => [$pending && in_array($state, ['VERIFICATION', 'ACTIVE', 'BLOCKED'], true), RawMaterialPermission::Review],
            'block' => [$state === 'ACTIVE', RawMaterialPermission::Block],
            'unblock' => [$state === 'BLOCKED' && ! $pending && $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::Approve), RawMaterialPermission::Unblock],
            'archive' => [in_array($state, ['ACTIVE', 'BLOCKED'], true) && ! $working && ! $pending, RawMaterialPermission::Archive],
            'restore' => [$state === 'ARCHIVED' && $canCopyAllergens && $canCopyStorage, RawMaterialPermission::Restore],
        ];
        $actions = [];
        foreach ($candidates as $action => [$eligible, $permission]) {
            if ($eligible && $this->authorization->hasEffectiveGrant($actor, $permission)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    private function isEditable(object $material): bool
    {
        return in_array($material->lifecycle_state, ['DRAFT', 'ACTIVE'], true)
            && $material->pending_revision_id === null
            && ($material->lifecycle_state !== 'DRAFT' || $material->working_revision_id !== null);
    }

    private function validateBasicData(
        User $actor,
        array $input,
        ?string $materialId,
        ?string $existingCategoryId = null,
        ?string $existingRevisionId = null,
    ): array {
        $rules = (new RawMaterialDataRequest)->rules();
        unset($rules['sku_reservation_id'], $rules['allergen_declaration']);
        Validator::make($input, $rules)->validate();
        $this->requireCategory($actor, $input['category_id'], $existingCategoryId);
        $allowedArchivedCodes = [];
        if ($existingRevisionId !== null) {
            $existingRevision = DB::table('raw_material_revisions')->where('id', $existingRevisionId)->first();
            if ($existingRevision) {
                $allowedArchivedCodes = [
                    $existingRevision->base_unit_code,
                    ...DB::table('raw_material_revision_units')
                        ->where('revision_id', $existingRevisionId)->pluck('unit_code')->all(),
                ];
            }
        }
        $unitData = RawMaterialUnitRegistry::resolve(
            $input['base_unit_code'],
            $input['conversions'] ?? [],
            $allowedArchivedCodes,
        );
        RawMaterialCatalogValues::quantities($input);
        $aliases = $this->aliases($input['name'], $input['aliases'] ?? []);
        $this->assertNoDuplicateTerms($actor, $aliases, $materialId);

        return [$unitData, $aliases];
    }

    private function mergeRevisionInput(object $baseRevision, array $input, RawMaterialPermission $permission): array
    {
        if ($permission !== RawMaterialPermission::Edit) {
            $input = array_merge($this->revisionInputFor($baseRevision), $input);
        } elseif (! array_key_exists('nominal_quantity', $input) && ! array_key_exists('usable_quantity', $input)
            && $baseRevision->nominal_quantity !== null) {
            if ($input['base_unit_code'] !== $baseRevision->base_unit_code) {
                throw ValidationException::withMessages(['nominal_quantity' => ['Zmiana jednostki wymaga jawnego podania ilości.']]);
            }
            $input['nominal_quantity'] = (string) $baseRevision->nominal_quantity;
            $input['usable_quantity'] = (string) $baseRevision->usable_quantity;
            $input['_usable_quantity_explicit'] = (bool) $baseRevision->usable_quantity_explicit;
        } elseif (! array_key_exists('nominal_quantity', $input)
            && array_key_exists('usable_quantity', $input) && $baseRevision->nominal_quantity !== null) {
            $input['nominal_quantity'] = (string) $baseRevision->nominal_quantity;
        }
        if (! array_key_exists('allergen_declaration', $input)) {
            $input['allergen_declaration'] = $this->allergenDeclarationForRevision($baseRevision->id);
        }
        if (! array_key_exists('storage_variants', $input)) {
            $input['storage_variants'] = $this->storageVariantsForRevision($baseRevision->id);
        }

        return $input;
    }

    public function revisions(User $actor, Location $context, string $materialId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::AuditView);
        $isPrimary = $this->sharePolicy->isPrimaryContext($context);
        if (! $isPrimary) {
            $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
        }
        $this->material($actor, $materialId);

        $query = DB::table('raw_material_revisions')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $materialId);
        if (! $isPrimary) {
            $query->whereExists(fn ($materials) => $materials->selectRaw('1')
                ->from('raw_materials')
                ->whereColumn('raw_materials.organization_id', 'raw_material_revisions.organization_id')
                ->whereColumn('raw_materials.id', 'raw_material_revisions.raw_material_id')
                ->whereColumn('raw_materials.approved_revision_id', 'raw_material_revisions.id'));
        }

        return $query->orderByDesc('revision_no')->get()
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
        return $this->createMaterial($actor, $context, $input, false);
    }

    public function createApproved(User $actor, Location $context, array $input): array
    {
        if (! isset($input['sku_reservation_id'], $input['allergen_declaration'])) {
            throw ValidationException::withMessages(['allergen_declaration' => ['Wymagana jest deklaracja alergenów i rezerwacja numeru.']]);
        }
        $input['create_and_approve'] = true;

        return $this->createMaterial($actor, $context, $input, true);
    }

    private function assertCreateApprovedAllowed(User $actor, Location $context): void
    {
        $fresh = $actor->fresh();
        if (! $fresh || ! app(BusinessAuthorization::class)->hasRole($fresh, BusinessRole::Administrator)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
        foreach ([RawMaterialPermission::Create, RawMaterialPermission::AllergenManage, RawMaterialPermission::Submit, RawMaterialPermission::Approve] as $permission) {
            $this->authorization->assertAllowed($fresh, $context, $permission);
        }
    }

    private function prepareInputAllergens(User $actor, Location $context, array $input): array
    {
        if (! array_key_exists('allergen_declaration', $input)) {
            return $input;
        }
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::AllergenManage);
        $declaration = $input['allergen_declaration'];
        if (! is_array($declaration)) {
            throw ValidationException::withMessages(['allergen_declaration' => ['Nieprawidłowa deklaracja alergenów.']]);
        }
        $this->validateForm(new RawMaterialAllergenDeclarationRequest, $declaration);
        $this->validateAllergenDeclaration($declaration);
        if ($declaration['status'] === 'UNKNOWN') {
            $input['allergen_declaration'] = null;
            $input['allergen_state'] = 'UNKNOWN';
        }

        return $input;
    }

    private function createMaterial(User $actor, Location $context, array $input, bool $approve): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);
        if ($approve) {
            $this->assertCreateApprovedAllowed($actor, $context);
        }

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $input, $approve): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);
            if ($approve) {
                $this->assertCreateApprovedAllowed($actor, $context);
            }
            $prepared = $this->prepareInputAllergens($actor, $context, $input);
            if ($approve && ($prepared['allergen_declaration'] ?? null) === null) {
                throw ValidationException::withMessages(['allergen_declaration' => [__('messages.raw_material_allergen_declaration_required')]]);
            }
            $reservation = isset($input['sku_reservation_id'])
                ? $this->reservations->claim($actor, $context, $input['sku_reservation_id'], $input) : null;
            if ($reservation?->consumed_at !== null) {
                return json_decode($reservation->response_body, true, flags: JSON_THROW_ON_ERROR);
            }
            [$unitData, $aliases] = $this->validateBasicData($actor, $input, null);
            if (RawMaterialCatalogValues::quantities($input)['nominal_quantity'] === null) {
                throw ValidationException::withMessages([
                    'nominal_quantity' => ['Ilość nominalna opakowania jest wymagana.'],
                ]);
            }
            $pricingInputProvided = array_key_exists('pricing', $input);
            $input = $this->pricing->prepareRevisionInput($actor, $context, $input, $unitData, null);
            unset(
                $prepared['pricing_input_basis'],
                $prepared['pricing_input_amount'],
                $prepared['pricing_input_unit_code'],
            );
            if ($pricingInputProvided) {
                $prepared = array_replace($prepared, array_intersect_key($input, array_flip([
                    'pricing_input_basis',
                    'pricing_input_amount',
                    'pricing_input_unit_code',
                ])));
            }
            $materialId = $reservation?->id ?? Str::uuid()->toString();
            $revisionId = Str::uuid()->toString();
            $now = now();
            try {
                $code = $reservation?->sku ?? $this->skuAllocation->allocateNext($actor, SkuPrefix::Raw, $materialId);
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
                $prepared,
                $unitData,
                $aliases,
                false,
                $input['change_reason'] ?? null,
            );
            $this->syncSupplierAssociations($actor, $context, $materialId, $input);
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'CREATED', null, [
                'code' => $code,
                'revision_id' => $revisionId,
                'revision_hash' => $revision['content_hash'],
                'document_version_ids' => $this->documentSets->ids($actor->organization_id, $revisionId),
            ]);
            $response = $this->presentMaterial(DB::table('raw_materials')->where('id', $materialId)->first(), (object) $revision);
            if ($approve) {
                $submitted = $this->submit($actor, $context, $materialId, $response['version']);
                $response = $this->approve($actor, $context, $materialId, '', $submitted['version']);
            }
            if ($reservation !== null) {
                $this->reservations->consume($reservation->id, $input, $response);
            }

            return $response;
        }));
    }

    public function createImported(User $actor, Location $context, string $sku, array $input): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $sku, $input): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);
            $this->requireCategory($actor, $input['category_id']);
            $usableQuantityExplicit = array_key_exists('usable_quantity', $input) && $input['usable_quantity'] !== null;
            $quantities = RawMaterialCatalogValues::quantities($input);
            if ($quantities['nominal_quantity'] === null) {
                throw ValidationException::withMessages([
                    'nominal_quantity' => ['Ilość nominalna opakowania jest wymagana.'],
                ]);
            }
            $input['nominal_quantity'] = $quantities['nominal_quantity'];
            if ($usableQuantityExplicit) {
                $input['usable_quantity'] = $quantities['usable_quantity'];
            } else {
                unset($input['usable_quantity']);
            }
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
            $this->syncSupplierAssociations($actor, $context, $materialId, $input);
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

    public function revise(User $actor, Location $context, string $materialId, array $input, ?string $expectedVersion = null): array
    {
        return $this->reviseWithPermission($actor, $context, $materialId, $input, RawMaterialPermission::Edit, expectedVersion: $expectedVersion, prepareDeclaration: true);
    }

    public function reviseAllergenDeclaration(User $actor, Location $context, string $materialId, array $input, ?string $expectedVersion = null): array
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
            $expectedVersion,
        );
    }

    public function reviseStorageVariants(User $actor, Location $context, string $materialId, array $variants, ?string $expectedVersion = null): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::StorageManage);
        $this->validateForm(new RawMaterialStorageVariantsRequest, ['variants' => $variants]);

        return $this->reviseWithPermission(
            $actor,
            $context,
            $materialId,
            ['storage_variants' => $variants],
            RawMaterialPermission::StorageManage,
            'STORAGE_VARIANTS_CHANGED',
            $expectedVersion,
        );
    }

    public function allergenCatalog(User $actor, Location $context): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $currentPoolVersion = DB::table('raw_material_allergen_pool_versions')
            ->orderByDesc('version_number')->value('catalog_version_id');

        return DB::table('raw_material_allergen_catalog_versions')
            ->where('status', 'PUBLISHED')
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'published_at'])
            ->map(fn (object $version): array => [
                'id' => $version->id,
                'version_number' => (int) $version->version_number,
                'published_at' => $version->published_at,
                'is_current' => $version->id === $currentPoolVersion,
                'definitions' => DB::table('raw_material_allergen_definitions')
                    ->where('catalog_version_id', $version->id)
                    ->where('is_active', true)
                    ->orderBy('display_order')
                    ->get(['allergen_code', 'name_pl', 'display_order', 'canonical_allergen_id', 'source_codes'])
                    ->map(fn (object $definition): array => [
                        'allergen_code' => $definition->allergen_code,
                        'name_pl' => $definition->name_pl,
                        'display_order' => (int) $definition->display_order,
                        'canonical_allergen_id' => $definition->canonical_allergen_id,
                        'source_codes' => is_array($definition->source_codes)
                            ? $definition->source_codes
                            : json_decode($definition->source_codes, true, flags: JSON_THROW_ON_ERROR),
                    ])->all(),
            ])->all();
    }

    public function revisionStage2d(User $actor, Location $context, string $materialId, string $revisionId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::AuditView);
        $isPrimary = $this->sharePolicy->isPrimaryContext($context);
        if (! $isPrimary) {
            $this->sharePolicy->assertCanConsume($actor, $context, $materialId);
        }

        $revisionQuery = DB::table('raw_material_revisions')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $materialId)
            ->where('id', $revisionId)
            ->whereExists(fn ($materials) => $materials->selectRaw('1')
                ->from('raw_materials')
                ->whereColumn('raw_materials.organization_id', 'raw_material_revisions.organization_id')
                ->whereColumn('raw_materials.id', 'raw_material_revisions.raw_material_id')
                ->whereNull('raw_materials.deleted_at'));
        if (! $isPrimary) {
            $revisionQuery->whereExists(fn ($materials) => $materials->selectRaw('1')
                ->from('raw_materials')
                ->whereColumn('raw_materials.organization_id', 'raw_material_revisions.organization_id')
                ->whereColumn('raw_materials.id', 'raw_material_revisions.raw_material_id')
                ->whereColumn('raw_materials.approved_revision_id', 'raw_material_revisions.id'));
        }
        $revision = $revisionQuery
            ->first([
                'id',
                'revision_no',
                'allergen_state',
                'content_hash_version',
                'content_hash',
            ]);
        if (! $revision) {
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
                'definitions.source_codes',
                'declarations.exposure_kind',
            ])->map(fn (object $item): array => [
                'allergen_code' => $item->allergen_code,
                'name_pl' => $item->name_pl,
                'source_codes' => is_array($item->source_codes)
                    ? $item->source_codes
                    : json_decode($item->source_codes, true, flags: JSON_THROW_ON_ERROR),
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
        ?string $expectedVersion = null,
        bool $prepareDeclaration = false,
    ): array {
        $this->authorization->assertAllowed($actor, $context, $permission);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $input, $permission, $changeType, $expectedVersion, $prepareDeclaration): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, $permission);
            if ($prepareDeclaration) {
                $input = $this->prepareInputAllergens($actor, $context, $input);
            }
            $material = $this->material($actor, $materialId, true);
            RawMaterialVersion::assertCurrent($material, $expectedVersion);
            if (! $this->isEditable($material)) {
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
            $input = $this->mergeRevisionInput($baseRevision, $input, $permission);
            [$unitData, $aliases] = $this->validateBasicData(
                $actor,
                $input,
                $materialId,
                $baseRevision->category_id,
                $baseRevision->id,
            );
            $input = $this->pricing->prepareRevisionInput($actor, $context, $input, $unitData, $baseRevision);
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
                baseRevisionId: $baseRevisionId,
            );
            $this->syncSupplierAssociations($actor, $context, $materialId, $input);
            $metadata = [
                'revision_id' => $revisionId,
                'revision_hash' => $revision['content_hash'],
                'previous_revision_id' => $baseRevisionId,
                'critical_change' => $criticalChange,
                'document_version_ids' => $this->documentSets->ids($actor->organization_id, $revisionId),
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
            ...($revision->nominal_quantity === null ? [] : [
                'nominal_quantity' => (string) $revision->nominal_quantity,
                'usable_quantity' => (string) $revision->usable_quantity,
                '_usable_quantity_explicit' => (bool) $revision->usable_quantity_explicit,
            ]),
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

    public function submit(User $actor, Location $context, string $materialId, ?string $expectedVersion = null): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Submit);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $expectedVersion): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Submit);
            $material = $this->material($actor, $materialId, true);
            RawMaterialVersion::assertCurrent($material, $expectedVersion);
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

    public function returnForChanges(User $actor, Location $context, string $materialId, string $reason, ?string $expectedVersion = null): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Review);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason, $expectedVersion): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Review);
            $material = $this->material($actor, $materialId, true);
            RawMaterialVersion::assertCurrent($material, $expectedVersion);
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

    public function approve(User $actor, Location $context, string $materialId, string $reason, ?string $expectedVersion = null): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Approve);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason, $expectedVersion): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Approve);
            $material = $this->material($actor, $materialId, true);
            RawMaterialVersion::assertCurrent($material, $expectedVersion);
            if ($material->pending_revision_id === null
                || ! in_array($material->lifecycle_state, ['VERIFICATION', 'ACTIVE'], true)) {
                throw new RawMaterialException('RAW_MATERIAL_APPROVAL_INVALID', __('messages.raw_material_approval_invalid'));
            }
            $revision = DB::table('raw_material_revisions')->where('id', $material->pending_revision_id)->firstOrFail();
            $problems = $this->approvalProblems($revision);
            if ($problems['errors'] !== [] || $problems['missing_requirements'] !== []) {
                $problem = ($problems['errors'] ?: $problems['missing_requirements'])[0];
                throw new RawMaterialException($problem['code'], $problem['message'], $problems['errors'] !== [] ? 409 : 422);
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
                'reference_price' => $this->pricing->recordApprovedRevision(
                    $actor,
                    $context,
                    DB::table('raw_materials')->where('id', $materialId)->firstOrFail(),
                    $revision,
                    $operationId,
                ),
            ], $reason, $operationId);

            return $this->presentCurrent($actor, $materialId);
        }));
    }

    public function block(User $actor, Location $context, string $materialId, string $reason, ?string $expectedVersion = null): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Block);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason, $expectedVersion): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Block);
            $material = $this->material($actor, $materialId, true);
            RawMaterialVersion::assertCurrent($material, $expectedVersion);
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

    public function unblock(User $actor, Location $context, string $materialId, string $reason, ?string $expectedVersion = null): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Unblock);
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Approve);

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::rawMaterialUnblock(),
            $actor->id,
            $materialId,
            fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason, $expectedVersion): array {
                Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Unblock);
                $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Approve);
                $material = $this->material($actor, $materialId, true);
                RawMaterialVersion::assertCurrent($material, $expectedVersion);
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

    public function archive(User $actor, Location $context, string $materialId, string $reason, ?string $expectedVersion = null): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Archive);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason, $expectedVersion): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Archive);
            $material = $this->material($actor, $materialId, true);
            RawMaterialVersion::assertCurrent($material, $expectedVersion);
            $this->assertArchivable($material);
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

    public function validateBulkArchive(User $actor, Location $context, array $items): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Archive);
        $items = $this->validateBulkArchiveItems($items);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, function () use ($actor, $context, $items): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Archive);
            usort($items, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            foreach ($items as $item) {
                $material = $this->material($actor, $item['id'], true);
                RawMaterialVersion::assertCurrent($material, $item['version'] ?? null);
                $this->assertArchivable($material);
            }
            $operationId = Str::uuid()->toString();
            foreach ($items as $item) {
                $this->audit($actor, $context, 'RAW_MATERIAL', $item['id'], 'BULK_ARCHIVE_VALIDATED', null, [
                    'mutation_performed' => false, 'policy_status' => 'ALL_OR_NOTHING',
                ], operationId: $operationId);
            }

            return [
                'validated_count' => count($items),
                'mutation_available' => true,
                'mutation_performed' => false,
                'policy_status' => 'ALL_OR_NOTHING',
                'message' => 'Cała lista spełnia warunki archiwizacji. Walidacja nie zmieniła stanu surowców.',
            ];
        });
    }

    public function archiveBulk(User $actor, Location $context, array $items, string $reason): array
    {
        $items = $this->validateBulkArchiveItems($items);
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Archive);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $items, $reason): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Archive);
            usort($items, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

            $materials = DB::table('raw_materials')
                ->where('organization_id', $actor->organization_id)
                ->whereNull('deleted_at')
                ->whereIn('id', array_column($items, 'id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($materials->count() !== count($items)) {
                throw new RawMaterialException('RAW_MATERIAL_NOT_FOUND', __('messages.resource_not_found'), 404);
            }

            foreach ($items as $item) {
                $material = $materials->get($item['id']);
                RawMaterialVersion::assertCurrent($material, $item['version'] ?? null);
                $this->assertArchivable($material);
            }

            $operationId = Str::uuid()->toString();
            $archived = [];
            foreach ($items as $item) {
                $material = $materials->get($item['id']);
                $previous = $this->stateHash($material);
                DB::table('raw_materials')->where('id', $material->id)->update([
                    'lifecycle_state' => 'ARCHIVED',
                    'blocked_at' => null,
                    'blocked_by' => null,
                    'block_reason' => null,
                    'archived_at' => now(),
                    'archived_by' => $actor->id,
                    'updated_at' => now(),
                ]);
                $this->audit($actor, $context, 'RAW_MATERIAL', $material->id, 'ARCHIVED', $previous, [], $reason, $operationId);
                $updated = $this->material($actor, $material->id);
                $archived[] = [
                    'id' => $updated->id,
                    'lifecycle_state' => $updated->lifecycle_state,
                    'version' => $updated->edit_version,
                ];
            }

            return [
                'operation_id' => $operationId,
                'archived_count' => count($archived),
                'materials' => $archived,
            ];
        }));
    }

    public function deleteDraft(User $actor, Location $context, string $materialId, ?string $expectedVersion): void
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::DeleteDraft);

        $this->contexts->runForSubject(
            AuthorizationMutationScope::rawMaterialDraftDelete(),
            $actor->id,
            $materialId,
            fn () => DB::transaction(function () use ($actor, $context, $materialId, $expectedVersion): void {
                Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::DeleteDraft);
                $material = $this->material($actor, $materialId, true);
                RawMaterialVersion::assertCurrent($material, $expectedVersion);
                $this->assertDraftDeletable($material);

                $operationId = Str::uuid()->toString();
                $deletedAt = now();
                $afterHash = hash('sha256', json_encode([
                    'id' => $material->id,
                    'code' => $material->code,
                    'lifecycle_state' => $material->lifecycle_state,
                    'deleted_at' => $deletedAt->toISOString(),
                    'deleted_by' => $actor->id,
                    'deleted_operation_id' => $operationId,
                ], JSON_THROW_ON_ERROR));
                DB::table('raw_material_audit_events')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $actor->organization_id,
                    'operation_id' => $operationId,
                    'actor_user_id' => $actor->id,
                    'actor_location_id' => $context->id,
                    'subject_type' => 'RAW_MATERIAL',
                    'subject_id' => $material->id,
                    'event_code' => 'DRAFT_DELETED',
                    'outcome' => 'ACCEPTED',
                    'before_hash' => $this->stateHash($material),
                    'after_hash' => $afterHash,
                    'metadata' => json_encode([
                        'code' => $material->code,
                        'version' => $material->edit_version,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                DB::table('raw_materials')->where('id', $material->id)->update([
                    'deleted_at' => $deletedAt,
                    'deleted_by' => $actor->id,
                    'deleted_operation_id' => $operationId,
                    'updated_at' => $deletedAt,
                ]);
            }),
        );
    }

    private function assertDraftDeletable(object $material): void
    {
        if ($material->deleted_at !== null
            || $material->lifecycle_state !== 'DRAFT'
            || $material->approved_revision_id !== null
            || $material->pending_revision_id !== null
            || $material->working_revision_id === null
            || DB::table('raw_materials as materials')
                ->where('materials.organization_id', $material->organization_id)
                ->where('materials.id', $material->id)
                ->whereRaw('public.raw_materials_draft_has_dependencies(materials.organization_id, materials.id)')
                ->exists()) {
            throw new RawMaterialException(
                'RAW_MATERIAL_DRAFT_DELETE_INVALID',
                'Szkicu nie można usunąć, ponieważ nie spełnia warunków bezpiecznego usunięcia.',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function validateBulkArchiveItems(array $items): array
    {
        return Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:id,version'],
            'items.*.id' => ['required', 'uuid', 'distinct'],
            'items.*.version' => ['sometimes', 'nullable', 'string'],
        ])->validate()['items'];
    }

    private function assertArchivable(object $material): void
    {
        if (! in_array($material->lifecycle_state, ['ACTIVE', 'BLOCKED'], true)
            || $material->pending_revision_id !== null || $material->working_revision_id !== null) {
            throw new RawMaterialException('RAW_MATERIAL_ARCHIVE_INVALID', __('messages.raw_material_archive_invalid'));
        }
    }

    public function restore(User $actor, Location $context, string $materialId, string $reason, ?string $expectedVersion = null): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Restore);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $reason, $expectedVersion): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Restore);
            $material = $this->material($actor, $materialId, true);
            RawMaterialVersion::assertCurrent($material, $expectedVersion);
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
                'base_unit_code' => $approved->base_unit_code,
                ...($approved->nominal_quantity === null ? [] : [
                    'nominal_quantity' => (string) $approved->nominal_quantity,
                    'usable_quantity' => (string) $approved->usable_quantity,
                ]),
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
                baseRevisionId: $approved->id,
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
        ?string $baseRevisionId = null,
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
        $quantities = RawMaterialCatalogValues::quantities($input);
        $usableQuantityExplicit = (bool) ($input['_usable_quantity_explicit']
            ?? array_key_exists('usable_quantity', $input));
        unset($input['_usable_quantity_explicit']);
        if ($quantities['nominal_quantity'] !== null) {
            $payload += $quantities;
        }
        $pricingFields = [
            'usable_quantity_explicit' => $quantities['nominal_quantity'] !== null && $usableQuantityExplicit,
            'pricing_input_basis' => $input['pricing_input_basis'] ?? null,
            'pricing_input_amount' => $input['pricing_input_amount'] ?? null,
            'pricing_input_unit_code' => $input['pricing_input_unit_code'] ?? null,
        ];
        $hash = $this->hashPayloadV3(
            $payload + $pricingFields,
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
            ...$quantities,
            ...$pricingFields,
            'critical_change' => $critical,
            'change_reason' => $reason,
            'created_by' => $actor->id,
            'created_at' => $createdAt,
            'content_hash_version' => 3,
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

        $this->documentSets->save($actor, $context, $revisionId, $baseRevisionId, $input);

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
        if ($revision->nominal_quantity !== null) {
            $payload += [
                'nominal_quantity' => (string) $revision->nominal_quantity,
                'usable_quantity' => (string) $revision->usable_quantity,
            ];
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

        if ((int) $revision->content_hash_version === 2) {
            return $this->hashPayloadV2($payload, $units, $aliases, $allergenDeclaration, $storageVariants);
        }

        $payload += [
            'usable_quantity_explicit' => (bool) $revision->usable_quantity_explicit,
            'pricing_input_basis' => $revision->pricing_input_basis,
            'pricing_input_amount' => $revision->pricing_input_amount === null
                ? null
                : (string) $revision->pricing_input_amount,
            'pricing_input_unit_code' => $revision->pricing_input_unit_code,
        ];

        return $this->hashPayloadV3($payload, $units, $aliases, $allergenDeclaration, $storageVariants);
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

    private function hashPayloadV3(
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
            'version' => 3,
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

    private function requireCategory(User $actor, string $categoryId, ?string $existingCategoryId = null): object
    {
        $category = DB::table('raw_material_categories')
            ->where('organization_id', $actor->organization_id)->where('id', $categoryId)->first();
        if (! $category || ($category->archived_at !== null && $categoryId !== $existingCategoryId)) {
            throw ValidationException::withMessages([
                'category_id' => [__('messages.raw_material_category_invalid')],
            ]);
        }

        return $category;
    }

    private function material(User $actor, string $materialId, bool $lock = false): object
    {
        $query = DB::table('raw_materials')->where('organization_id', $actor->organization_id)
            ->where('id', $materialId)->whereNull('deleted_at');
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
                'nominal_quantity' => $revision->nominal_quantity,
                'usable_quantity' => $revision->usable_quantity,
                'usable_quantity_explicit' => (bool) ($revision->usable_quantity_explicit ?? false),
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
            'sku' => $material->code,
            'lifecycle_state' => $material->lifecycle_state,
            'version' => $material->edit_version,
            'approved_revision_id' => $material->approved_revision_id,
            'revision' => $revisionData,
        ];
    }

    private function pricingInputForEditor(User $actor, object $revision): ?array
    {
        if (! $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::ReferencePriceView)) {
            return null;
        }

        $event = DB::table('raw_material_reference_price_events')
            ->where('pricing_revision_id', $revision->id)->first();
        if ($revision->pricing_input_basis !== null && $event === null) {
            return [
                'basis' => $revision->pricing_input_basis,
                'amount' => (string) $revision->pricing_input_amount,
                'unit_code' => $revision->pricing_input_unit_code,
            ];
        }
        $event = DB::table('raw_material_reference_price_events')
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_id', $revision->raw_material_id)
            ->where('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('event_no')->first();
        if (! $event || $event->event_type !== 'PRICE_SET') {
            return null;
        }
        $basis = $event->pricing_input_basis;
        $amount = $event->pricing_input_amount;
        $unitCode = $event->pricing_input_unit_code;
        if ($basis === null && $event->net_pln_per_package !== null) {
            $basis = 'PACKAGE';
            $amount = $event->net_pln_per_package;
            $unitCode = null;
        }
        if (! in_array($basis, ['PACKAGE', 'NOMINAL_UNIT'], true)) {
            return null;
        }

        return [
            'basis' => $basis,
            'amount' => (string) $amount,
            'unit_code' => $unitCode,
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
