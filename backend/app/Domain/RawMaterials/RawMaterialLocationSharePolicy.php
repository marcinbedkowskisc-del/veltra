<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RawMaterialLocationSharePolicy
{
    public function isPrimaryContext(Location $context): bool
    {
        return $this->hasResolvedPrimary($context->organization_id)
            && DB::table('locations')
                ->where('organization_id', $context->organization_id)
                ->where('id', $context->id)
                ->where('type', 'CENTRAL_KITCHEN')
                ->where('is_primary_central_kitchen', true)
                ->where('is_active', true)
                ->exists();
    }

    public function hasResolvedPrimary(string $organizationId): bool
    {
        return DB::table('organizations')
            ->where('id', $organizationId)
            ->where('is_active', true)
            ->where('primary_central_kitchen_unresolved', false)
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('locations as primary_kitchen')
                ->whereColumn('primary_kitchen.organization_id', 'organizations.id')
                ->where('primary_kitchen.type', 'CENTRAL_KITCHEN')
                ->where('primary_kitchen.is_primary_central_kitchen', true)
                ->where('primary_kitchen.is_active', true))
            ->exists();
    }

    public function canConsume(User $actor, Location $context, string $materialId): bool
    {
        if ($context->organization_id !== $actor->organization_id
            || ! DB::table('users')
                ->where('id', $actor->id)
                ->where('organization_id', $actor->organization_id)
                ->where('is_active', true)
                ->whereNotNull('email_verified_at')
                ->exists()
            || ! DB::table('locations')
                ->where('organization_id', $actor->organization_id)
                ->where('id', $context->id)
                ->where('is_active', true)
                ->exists()
            || ! DB::table('location_memberships')
                ->where('organization_id', $actor->organization_id)
                ->where('user_id', $actor->id)
                ->where('location_id', $context->id)
                ->where('is_active', true)
                ->exists()) {
            return false;
        }
        if (! $this->hasResolvedPrimary($actor->organization_id)) {
            return false;
        }

        $isBoss = app(BusinessAuthorization::class)->hasRole($actor, BusinessRole::Boss)
            && app(RawMaterialAuthorization::class)->canRead($actor, $context, RawMaterialPermission::View);
        $isPrimary = $isBoss || $this->isPrimaryContext($context);
        $query = DB::table('raw_materials as materials')
            ->where('materials.organization_id', $actor->organization_id)
            ->where('materials.id', $materialId)
            ->whereNull('materials.deleted_at')
            ->whereNotNull('materials.approved_revision_id')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('raw_material_revisions as current_revision')
                ->join('raw_material_revision_approval_events as current_approval', function ($join): void {
                    $join->on('current_approval.revision_id', '=', 'current_revision.id')
                        ->on('current_approval.organization_id', '=', 'current_revision.organization_id')
                        ->where('current_approval.outcome', 'APPROVED')
                        ->on('current_approval.content_hash', '=', 'current_revision.content_hash');
                })
                ->whereColumn('current_revision.id', 'materials.approved_revision_id')
                ->whereColumn('current_revision.raw_material_id', 'materials.id')
                ->whereColumn('current_revision.organization_id', 'materials.organization_id'));
        if (! $isBoss) {
            $query->where('materials.lifecycle_state', 'ACTIVE');
        }
        if (! $isPrimary) {
            $query
                ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                    ->from('raw_material_revisions as first_revision')
                    ->join('raw_material_revision_approval_events as first_approval', function ($join): void {
                        $join->on('first_approval.revision_id', '=', 'first_revision.id')
                            ->on('first_approval.organization_id', '=', 'first_revision.organization_id')
                            ->where('first_approval.outcome', 'APPROVED')
                            ->on('first_approval.content_hash', '=', 'first_revision.content_hash');
                    })
                    ->whereColumn('first_revision.raw_material_id', 'materials.id')
                    ->whereColumn('first_revision.organization_id', 'materials.organization_id')
                    ->where('first_revision.revision_no', 1))
                ->whereExists(fn (Builder $shareQuery) => $shareQuery->selectRaw('1')
                    ->from('raw_material_location_shares as shares')
                    ->whereColumn('shares.organization_id', 'materials.organization_id')
                    ->whereColumn('shares.raw_material_id', 'materials.id')
                    ->where('shares.location_id', $context->id)
                    ->whereNull('shares.revoked_at'));
        }

        return $query->exists();
    }

    public function assertCanConsume(User $actor, Location $context, string $materialId): void
    {
        if (! $this->canConsume($actor, $context, $materialId)) {
            throw new RawMaterialException('RAW_MATERIAL_NOT_FOUND', __('messages.resource_not_found'), 404);
        }
    }

    public function constrainVisibleMaterials(Builder $query, User $actor, Location $context): Builder
    {
        if (! $this->hasResolvedPrimary($actor->organization_id)) {
            return $query->whereRaw('false');
        }
        if ($this->isPrimaryContext($context)
            || (app(BusinessAuthorization::class)->hasRole($actor, BusinessRole::Boss)
                && app(RawMaterialAuthorization::class)->canRead($actor, $context, RawMaterialPermission::View))) {
            return $query;
        }

        return $query
            ->where('materials.lifecycle_state', 'ACTIVE')
            ->whereNotNull('materials.approved_revision_id')
            ->whereExists(fn (Builder $shareQuery) => $shareQuery->selectRaw('1')
                ->from('raw_material_location_shares as shares')
                ->whereColumn('shares.organization_id', 'materials.organization_id')
                ->whereColumn('shares.raw_material_id', 'materials.id')
                ->where('shares.location_id', $context->id)
                ->whereNull('shares.revoked_at'))
            ->whereExists(fn (Builder $approvalQuery) => $approvalQuery->selectRaw('1')
                ->from('raw_material_revisions as current_revision')
                ->join('raw_material_revision_approval_events as current_approval', function ($join): void {
                    $join->on('current_approval.revision_id', '=', 'current_revision.id')
                        ->on('current_approval.organization_id', '=', 'current_revision.organization_id')
                        ->where('current_approval.outcome', 'APPROVED')
                        ->on('current_approval.content_hash', '=', 'current_revision.content_hash');
                })
                ->whereColumn('current_revision.id', 'materials.approved_revision_id')
                ->whereColumn('current_revision.raw_material_id', 'materials.id')
                ->whereColumn('current_revision.organization_id', 'materials.organization_id'))
            ->whereExists(fn (Builder $approvalQuery) => $approvalQuery->selectRaw('1')
                ->from('raw_material_revisions as first_revision')
                ->join('raw_material_revision_approval_events as first_approval', function ($join): void {
                    $join->on('first_approval.revision_id', '=', 'first_revision.id')
                        ->on('first_approval.organization_id', '=', 'first_revision.organization_id')
                        ->where('first_approval.outcome', 'APPROVED')
                        ->on('first_approval.content_hash', '=', 'first_revision.content_hash');
                })
                ->whereColumn('first_revision.raw_material_id', 'materials.id')
                ->whereColumn('first_revision.organization_id', 'materials.organization_id')
                ->where('first_revision.revision_no', 1));
    }
}
