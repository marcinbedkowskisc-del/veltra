<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * @param  list<string>  $locationIds
     */
    public function bulkShareToLocations(
        User $actor,
        Location $context,
        string $materialId,
        array $locationIds,
    ): array {
        return $this->bulkShare($actor, $context, 'MATERIAL_TO_LOCATIONS', $materialId, $locationIds);
    }

    /**
     * @param  list<string>  $materialIds
     */
    public function bulkShareMaterialsToLocation(
        User $actor,
        Location $context,
        string $locationId,
        array $materialIds,
    ): array {
        return $this->bulkShare($actor, $context, 'MATERIALS_TO_LOCATION', $locationId, $materialIds);
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
        if (! DB::table('raw_materials')->where('organization_id', $actor->organization_id)
            ->where('id', $materialId)->whereNull('deleted_at')->exists()) {
            throw new RawMaterialException('RAW_MATERIAL_NOT_FOUND', __('messages.resource_not_found'), 404);
        }
    }

    private function assertMaterialReadyForShare(User $actor, string $materialId): void
    {
        $material = DB::table('raw_materials')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $materialId)
            ->whereNull('deleted_at')
            ->first();
        if (! $material || $this->materialShareRejectionReasons($material) !== []) {
            throw new RawMaterialException(
                'RAW_MATERIAL_SHARE_REQUIRES_APPROVED_ACTIVE_MATERIAL',
                __('messages.raw_material_share_requires_approved_active_material'),
                409,
            );
        }
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, mixed>
     */
    private function bulkShare(
        User $actor,
        Location $context,
        string $perspective,
        string $fixedTargetId,
        array $targets,
    ): array {
        [$actor, $context] = $this->authorizedAdministrator($actor, $context);
        $operationId = Str::uuid()->toString();
        $uniqueTargets = array_values(array_unique($targets));
        $seenTargets = [];
        $duplicates = [];
        foreach ($targets as $targetId) {
            if (isset($seenTargets[$targetId])) {
                $duplicates[] = $targetId;
            }
            $seenTargets[$targetId] = true;
        }

        try {
            $result = $this->mutationContexts->run(
                AuthorizationMutationScope::rawMaterialShare(),
                $actor->id,
                function () use (
                    $actor,
                    $context,
                    $perspective,
                    $fixedTargetId,
                    $uniqueTargets,
                    $duplicates,
                    $operationId,
                ): array {
                    $this->lockOrganization($actor);
                    [$actor, $context] = $this->authorizedAdministrator($actor, $context);
                    $this->assertKnownPrimary($actor);

                    if ($perspective === 'MATERIAL_TO_LOCATIONS') {
                        $materialIds = [$fixedTargetId];
                        $locationIds = $uniqueTargets;
                    } else {
                        $materialIds = $uniqueTargets;
                        $locationIds = [$fixedTargetId];
                    }

                    $materials = DB::table('raw_materials')
                        ->where('organization_id', $actor->organization_id)
                        ->whereNull('deleted_at')
                        ->whereIn('id', $materialIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                    $locations = DB::table('locations')
                        ->where('organization_id', $actor->organization_id)
                        ->whereIn('id', $locationIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    if ($materials->count() !== count($materialIds)
                        || $locations->count() !== count($locationIds)) {
                        throw new AuthorizationException(__('messages.unauthorized'));
                    }

                    $invalidTargets = [];
                    $pairs = [];
                    foreach ($uniqueTargets as $targetId) {
                        $materialId = $perspective === 'MATERIAL_TO_LOCATIONS' ? $fixedTargetId : $targetId;
                        $locationId = $perspective === 'MATERIAL_TO_LOCATIONS' ? $targetId : $fixedTargetId;
                        $reasons = [];
                        $location = $locations->get($locationId);
                        if (! $location->is_active) {
                            $reasons[] = 'LOCATION_INACTIVE';
                        }
                        $reasons = [...$reasons, ...$this->materialShareRejectionReasons($materials->get($materialId))];
                        $pair = ['material_id' => $materialId, 'location_id' => $locationId];
                        $pairs[] = ['pair' => $pair, 'reasons' => array_values(array_unique($reasons))];
                        if ($reasons !== []) {
                            $invalidTargets[] = [...$pair, 'reasons' => array_values(array_unique($reasons))];
                        }
                    }

                    if ($invalidTargets !== []) {
                        return [
                            'outcome' => 'REJECTED',
                            'data' => [
                                'operation_id' => $operationId,
                                'perspective' => $perspective,
                                'created' => [],
                                'already_active' => [],
                                'duplicates' => $duplicates,
                                'invalid_targets' => $invalidTargets,
                            ],
                            'audit_metadata' => [
                                'perspective' => $perspective,
                                'target_count' => count($uniqueTargets),
                                'invalid_targets' => $invalidTargets,
                            ],
                        ];
                    }

                    $activeShares = DB::table('raw_material_location_shares')
                        ->where('organization_id', $actor->organization_id)
                        ->whereIn('raw_material_id', $materialIds)
                        ->whereIn('location_id', $locationIds)
                        ->whereNull('revoked_at')
                        ->get(['raw_material_id', 'location_id'])
                        ->mapWithKeys(fn (object $share): array => [
                            $share->raw_material_id.':'.$share->location_id => true,
                        ]);
                    $created = [];
                    $alreadyActive = [];

                    foreach ($pairs as $entry) {
                        $pair = $entry['pair'];
                        $pairKey = $pair['material_id'].':'.$pair['location_id'];
                        if ($activeShares->has($pairKey)) {
                            $alreadyActive[] = $pair;

                            continue;
                        }

                        $shareId = Str::uuid()->toString();
                        $shareOperationId = Str::uuid()->toString();
                        DB::table('raw_material_location_shares')->insert([
                            'id' => $shareId,
                            'organization_id' => $actor->organization_id,
                            'raw_material_id' => $pair['material_id'],
                            'location_id' => $pair['location_id'],
                            'created_by' => $actor->id,
                            'created_context_location_id' => $context->id,
                            'created_operation_id' => $shareOperationId,
                        ]);
                        $this->audit(
                            $actor,
                            $context,
                            $shareId,
                            $pair['material_id'],
                            $pair['location_id'],
                            $shareOperationId,
                            'LOCATION_SHARE_CREATED',
                            $operationId,
                        );
                        $created[] = [...$pair, 'share_id' => $shareId];
                    }

                    $data = [
                        'operation_id' => $operationId,
                        'perspective' => $perspective,
                        'created' => $created,
                        'already_active' => $alreadyActive,
                        'duplicates' => $duplicates,
                        'invalid_targets' => [],
                    ];
                    $this->bulkAudit(
                        $actor,
                        $context,
                        $operationId,
                        'LOCATION_SHARE_BULK_COMPLETED',
                        'ACCEPTED',
                        [
                            'perspective' => $perspective,
                            'target_count' => count($uniqueTargets),
                            'created_count' => count($created),
                            'already_active' => $alreadyActive,
                            'duplicates' => $duplicates,
                        ],
                    );

                    return ['outcome' => 'ACCEPTED', 'data' => $data];
                },
            );
        } catch (AuthorizationException $exception) {
            $this->recordRejectedBulkAttempt(
                $actor,
                $context,
                $operationId,
                'UNAUTHORIZED_TARGET',
                ['perspective' => $perspective, 'target_count' => count($uniqueTargets)],
            );
            throw $exception;
        } catch (QueryException $exception) {
            $this->recordRejectedBulkAttempt(
                $actor,
                $context,
                $operationId,
                'DATABASE_WRITE_FAILED',
                ['perspective' => $perspective, 'target_count' => count($uniqueTargets)],
            );
            throw $exception;
        } catch (RawMaterialException $exception) {
            $this->recordRejectedBulkAttempt(
                $actor,
                $context,
                $operationId,
                $exception->errorCode,
                ['perspective' => $perspective, 'target_count' => count($uniqueTargets)],
            );
            throw $exception;
        }

        if ($result['outcome'] === 'REJECTED') {
            $this->recordRejectedBulkAttempt(
                $actor,
                $context,
                $operationId,
                'TARGET_VALIDATION_FAILED',
                $result['audit_metadata'],
            );
            unset($result['audit_metadata']);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function materialShareRejectionReasons(object $material): array
    {
        $reasons = [];
        if ($material->lifecycle_state !== 'ACTIVE') {
            $reasons[] = 'MATERIAL_NOT_ACTIVE';
        }

        if ($material->approved_revision_id === null) {
            $reasons[] = 'APPROVED_REVISION_MISSING';
        } else {
            $approvedRevision = DB::table('raw_material_revisions as revisions')
                ->where('revisions.organization_id', $material->organization_id)
                ->where('revisions.raw_material_id', $material->id)
                ->where('revisions.id', $material->approved_revision_id)
                ->whereExists(fn ($query) => $query->selectRaw('1')
                    ->from('raw_material_revision_approval_events as approvals')
                    ->whereColumn('approvals.organization_id', 'revisions.organization_id')
                    ->whereColumn('approvals.revision_id', 'revisions.id')
                    ->whereColumn('approvals.content_hash', 'revisions.content_hash')
                    ->where('approvals.outcome', 'APPROVED'))
                ->exists();
            if (! $approvedRevision) {
                $reasons[] = 'CURRENT_REVISION_NOT_APPROVED';
            }
        }

        $firstRevisionApproved = DB::table('raw_material_revisions as revisions')
            ->where('revisions.organization_id', $material->organization_id)
            ->where('revisions.raw_material_id', $material->id)
            ->where('revisions.revision_no', 1)
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('raw_material_revision_approval_events as approvals')
                ->whereColumn('approvals.organization_id', 'revisions.organization_id')
                ->whereColumn('approvals.revision_id', 'revisions.id')
                ->whereColumn('approvals.content_hash', 'revisions.content_hash')
                ->where('approvals.outcome', 'APPROVED'))
            ->exists();
        if (! $firstRevisionApproved) {
            $reasons[] = 'FIRST_REVISION_NOT_APPROVED';
        }

        return $reasons;
    }

    private function recordRejectedBulkAttempt(
        User $actor,
        Location $context,
        string $operationId,
        string $reason,
        array $metadata,
    ): void {
        try {
            $this->mutationContexts->run(
                AuthorizationMutationScope::rawMaterialShare(),
                $actor->id,
                fn () => $this->bulkAudit(
                    $actor,
                    $context,
                    $operationId,
                    'LOCATION_SHARE_BULK_REJECTED',
                    'REJECTED',
                    $metadata,
                    $reason,
                ),
            );
        } catch (Throwable $exception) {
            Log::critical('Nie udało się utrwalić audytu odrzuconej operacji bulk share RAW.', [
                'operation_id' => $operationId,
                'reason_code' => $reason,
                'exception' => $exception,
            ]);
            throw $exception;
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
        ?string $bulkOperationId = null,
    ): void {
        $metadata = [
            'raw_material_id' => $materialId,
            'location_id' => $targetLocationId,
        ];
        if ($bulkOperationId !== null) {
            $metadata['bulk_operation_id'] = $bulkOperationId;
        }

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
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    private function bulkAudit(
        User $actor,
        Location $context,
        string $operationId,
        string $eventCode,
        string $outcome,
        array $metadata,
        ?string $reasonCode = null,
    ): void {
        DB::table('raw_material_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => $operationId,
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'subject_type' => 'RAW_MATERIAL_SHARE_BULK',
            'subject_id' => $operationId,
            'event_code' => $eventCode,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
