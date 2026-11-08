<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RawMaterialLocationShareAdministration
{
    public function __construct(
        private readonly BusinessAuthorization $authorization,
        private readonly AuthorizationMutationContext $mutationContexts,
    ) {}

    public function list(User $actor, Location $context, string $materialId): array
    {
        [$actor, $context] = $this->authorizedAdministrator($actor, $context);
        $this->assertKnownPrimary($actor);
        $this->assertMaterialExists($actor, $materialId);

        return [
            'data' => DB::table('raw_material_location_shares as shares')
                ->join('locations', function ($join): void {
                    $join->on('locations.id', '=', 'shares.location_id')
                        ->on('locations.organization_id', '=', 'shares.organization_id');
                })
                ->where('shares.organization_id', $actor->organization_id)
                ->where('shares.raw_material_id', $materialId)
                ->orderBy('shares.created_at')->orderBy('shares.id')
                ->get([
                    'shares.id',
                    'shares.location_id',
                    'locations.name as location_name',
                    'shares.created_by',
                    'shares.created_at',
                    'shares.revoked_by',
                    'shares.revoked_at',
                    'shares.revocation_reason',
                ])
                ->map(fn (object $share): array => [
                    'id' => $share->id,
                    'location' => ['id' => $share->location_id, 'name' => $share->location_name],
                    'created_by' => $share->created_by,
                    'created_at' => $share->created_at,
                    'revoked_by' => $share->revoked_by,
                    'revoked_at' => $share->revoked_at,
                    'revocation_reason' => $share->revocation_reason,
                ])->all(),
        ];
    }

    public function share(
        User $actor,
        Location $context,
        string $materialId,
        string $targetLocationId,
    ): array {
        [$actor, $context] = $this->authorizedAdministrator($actor, $context);
        $operationId = Str::uuid()->toString();
        $shareId = Str::uuid()->toString();

        return $this->mutationContexts->run(
            AuthorizationMutationScope::rawMaterialShare(),
            $actor->id,
            function () use ($actor, $context, $materialId, $targetLocationId, $operationId, $shareId): array {
                $this->lockOrganization($actor);
                [$actor, $context] = $this->authorizedAdministrator($actor, $context);
                $this->assertKnownPrimary($actor);
                $this->assertMaterialReadyForShare($actor, $materialId);
                $target = Location::where('organization_id', $actor->organization_id)
                    ->whereKey($targetLocationId)->where('is_active', true)->first();
                if (! $target) {
                    throw new RawMaterialException('RAW_MATERIAL_SHARE_LOCATION_NOT_FOUND', __('messages.resource_not_found'), 404);
                }

                if (DB::table('raw_material_location_shares')
                    ->where('organization_id', $actor->organization_id)
                    ->where('raw_material_id', $materialId)
                    ->where('location_id', $target->id)
                    ->whereNull('revoked_at')->exists()) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_SHARE_ALREADY_ACTIVE',
                        __('messages.raw_material_share_already_active'),
                        409,
                    );
                }

                DB::table('raw_material_location_shares')->insert([
                    'id' => $shareId,
                    'organization_id' => $actor->organization_id,
                    'raw_material_id' => $materialId,
                    'location_id' => $target->id,
                    'created_by' => $actor->id,
                    'created_context_location_id' => $context->id,
                    'created_operation_id' => $operationId,
                ]);
                $this->audit($actor, $context, $shareId, $materialId, $target->id, $operationId, 'LOCATION_SHARE_CREATED');

                return ['data' => [
                    'id' => $shareId,
                    'raw_material_id' => $materialId,
                    'location_id' => $target->id,
                    'active' => true,
                ]];
            },
        );
    }

    public function revoke(
        User $actor,
        Location $context,
        string $materialId,
        string $targetLocationId,
        ?string $reason = null,
    ): array {
        [$actor, $context] = $this->authorizedAdministrator($actor, $context);
        $operationId = Str::uuid()->toString();

        return $this->mutationContexts->run(
            AuthorizationMutationScope::rawMaterialShare(),
            $actor->id,
            function () use ($actor, $context, $materialId, $targetLocationId, $reason, $operationId): array {
                $this->lockOrganization($actor);
                [$actor, $context] = $this->authorizedAdministrator($actor, $context);
                $this->assertKnownPrimary($actor);
                $this->assertMaterialExists($actor, $materialId);

                $share = DB::table('raw_material_location_shares')
                    ->where('organization_id', $actor->organization_id)
                    ->where('raw_material_id', $materialId)
                    ->where('location_id', $targetLocationId)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->first();
                if (! $share) {
                    throw new RawMaterialException('RAW_MATERIAL_SHARE_NOT_FOUND', __('messages.resource_not_found'), 404);
                }

                DB::table('raw_material_location_shares')->where('id', $share->id)->update([
                    'revoked_by' => $actor->id,
                    'revoked_context_location_id' => $context->id,
                    'revoked_operation_id' => $operationId,
                    'revoked_at' => now(),
                    'revocation_reason' => filled($reason) ? trim($reason) : null,
                ]);
                $this->audit($actor, $context, $share->id, $materialId, $targetLocationId, $operationId, 'LOCATION_SHARE_REVOKED');

                return ['data' => ['id' => $share->id, 'active' => false]];
            },
        );
    }

    private function authorizedAdministrator(User $actor, Location $context): array
    {
        $freshActor = User::with('organization')->find($actor->id);
        $freshContext = Location::find($context->id);
        if (! $freshActor || ! $freshContext
            || ! ($freshActor->organization?->is_active ?? false)
            || ! $freshActor->organization->authorization_initialized
            || ! $this->authorization->eligible($freshActor, $freshContext)
            || ! $this->authorization->hasRole($freshActor, BusinessRole::Administrator)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        return [$freshActor, $freshContext];
    }

    private function assertKnownPrimary(User $actor): void
    {
        $organization = $actor->organization()->first();
        if (! $organization || $organization->primary_central_kitchen_unresolved
            || ! $organization->primaryCentralKitchen()->where('is_active', true)->exists()) {
            throw new RawMaterialException(
                'RAW_MATERIAL_PRIMARY_CENTRAL_KITCHEN_UNRESOLVED',
                __('messages.primary_central_kitchen_unresolved'),
                409,
            );
        }
    }

    private function assertMaterialExists(User $actor, string $materialId): void
    {
        if (! DB::table('raw_materials')->where('organization_id', $actor->organization_id)->where('id', $materialId)->exists()) {
            throw new RawMaterialException('RAW_MATERIAL_NOT_FOUND', __('messages.resource_not_found'), 404);
        }
    }

    private function assertMaterialReadyForShare(User $actor, string $materialId): void
    {
        $ready = DB::table('raw_materials as materials')
            ->join('raw_material_revisions as current_revision', function ($join): void {
                $join->on('current_revision.id', '=', 'materials.approved_revision_id')
                    ->on('current_revision.raw_material_id', '=', 'materials.id')
                    ->on('current_revision.organization_id', '=', 'materials.organization_id');
            })
            ->where('materials.organization_id', $actor->organization_id)
            ->where('materials.id', $materialId)
            ->where('materials.lifecycle_state', 'ACTIVE')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('raw_material_revisions as first_revision')
                ->join('raw_material_revision_approval_events as approval', function ($join): void {
                    $join->on('approval.revision_id', '=', 'first_revision.id')
                        ->on('approval.organization_id', '=', 'first_revision.organization_id')
                        ->on('approval.content_hash', '=', 'first_revision.content_hash')
                        ->where('approval.outcome', 'APPROVED');
                })
                ->whereColumn('first_revision.raw_material_id', 'materials.id')
                ->whereColumn('first_revision.organization_id', 'materials.organization_id')
                ->where('first_revision.revision_no', 1))
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('raw_material_revision_approval_events as approval')
                ->whereColumn('approval.revision_id', 'current_revision.id')
                ->whereColumn('approval.organization_id', 'current_revision.organization_id')
                ->whereColumn('approval.content_hash', 'current_revision.content_hash')
                ->where('approval.outcome', 'APPROVED'))
            ->exists();
        if (! $ready) {
            throw new RawMaterialException(
                'RAW_MATERIAL_SHARE_REQUIRES_APPROVED_ACTIVE_MATERIAL',
                __('messages.raw_material_share_requires_approved_active_material'),
                409,
            );
        }
    }

    private function lockOrganization(User $actor): void
    {
        DB::table('organizations')->where('id', $actor->organization_id)->lockForUpdate()->firstOrFail();
    }

    private function audit(
        User $actor,
        Location $context,
        string $shareId,
        string $materialId,
        string $targetLocationId,
        string $operationId,
        string $eventCode,
    ): void {
        DB::table('raw_material_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => $operationId,
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'subject_type' => 'RAW_MATERIAL_SHARE',
            'subject_id' => $shareId,
            'event_code' => $eventCode,
            'outcome' => 'ACCEPTED',
            'metadata' => json_encode([
                'raw_material_id' => $materialId,
                'location_id' => $targetLocationId,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
