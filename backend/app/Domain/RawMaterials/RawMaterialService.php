<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RawMaterialService
{
    public function __construct(
        private RawMaterialAuthorization $authorization,
        private AuthorizationMutationContext $contexts,
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
            $this->ensureCounter($actor->organization_id);
            $counter = DB::table('raw_material_code_counters')->where('organization_id', $actor->organization_id)
                ->where('prefix', 'SUR')->lockForUpdate()->firstOrFail();
            $next = $counter->last_value + 1;
            if ($next > 999999999) {
                throw new RawMaterialException(
                    'RAW_MATERIAL_CODE_CAPACITY_REACHED',
                    __('messages.raw_material_code_capacity_reached'),
                );
            }
            DB::table('raw_material_code_counters')->where('organization_id', $actor->organization_id)
                ->where('prefix', 'SUR')->update(['last_value' => $next, 'updated_at' => now()]);

            $materialId = Str::uuid()->toString();
            $revisionId = Str::uuid()->toString();
            $now = now();
            $code = 'SUR-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
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

    public function revise(User $actor, Location $context, string $materialId, array $input): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Edit);

        return $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $materialId, $input): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Edit);
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
                $materialId,
                $revisionId,
                $latestNo + 1,
                $input,
                $unitData,
                $aliases,
                $criticalChange,
                $input['change_reason'] ?? null,
                $baseRevision->allergen_state,
            );
            $this->audit($actor, $context, 'RAW_MATERIAL', $materialId, 'REVISION_CREATED', null, [
                'revision_id' => $revisionId,
                'revision_hash' => $revision['content_hash'],
                'previous_revision_id' => $baseRevisionId,
                'critical_change' => $criticalChange,
            ]);

            return $this->presentMaterial(DB::table('raw_materials')->where('id', $materialId)->first(), (object) $revision);
        }));
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
            DB::table('raw_materials')->where('id', $materialId)->update([
                'lifecycle_state' => $material->lifecycle_state === 'VERIFICATION' ? 'DRAFT' : $material->lifecycle_state,
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
            $revision = $this->insertRevision(
                $actor,
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
        string $materialId,
        string $revisionId,
        int $revisionNo,
        array $input,
        array $unitData,
        array $aliases,
        bool $critical,
        ?string $reason,
        string $allergenState = 'UNKNOWN',
    ): array {
        $payload = [
            'category_id' => $input['category_id'],
            'name' => trim($input['name']),
            'description' => $input['description'] ?? null,
            'base_unit_code' => $unitData['base_unit_code'],
            'base_unit_dimension' => $unitData['base_unit_dimension'],
            'allergen_state' => $allergenState,
        ];
        $hash = $this->hashPayload($payload, $unitData['units'], $aliases);
        $revision = [
            'id' => $revisionId,
            'organization_id' => $actor->organization_id,
            'raw_material_id' => $materialId,
            'revision_no' => $revisionNo,
            ...$payload,
            'critical_change' => $critical,
            'change_reason' => $reason,
            'created_by' => $actor->id,
            'created_at' => now(),
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

        return $revision;
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

        return $this->hashPayload($payload, $units, $aliases);
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

    private function ensureCounter(string $organizationId): void
    {
        DB::table('raw_material_code_counters')->insertOrIgnore([
            'organization_id' => $organizationId,
            'prefix' => 'SUR',
            'last_value' => 0,
            'updated_at' => now(),
        ]);
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
