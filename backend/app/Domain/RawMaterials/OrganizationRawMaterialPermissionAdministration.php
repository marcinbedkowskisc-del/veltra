<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationRawMaterialPermissionAdministration
{
    public function __construct(
        private RawMaterialAuthorization $authorization,
        private AuthorizationMutationContext $contexts,
    ) {}

    /**
     * @return array{id: string, user_id: string, permission: string, valid_from: string, valid_until: ?string}
     */
    public function grant(
        User $actor,
        Location $context,
        User $recipient,
        RawMaterialPermission $permission,
        string $reason,
        ?string $validFrom = null,
        ?string $validUntil = null,
    ): array {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::PermissionDelegate);
        if ($actor->is($recipient) || $recipient->organization_id !== $actor->organization_id
            || ! $recipient->is_active || $recipient->email_verified_at === null
            || ! app(BusinessAuthorization::class)->canManageAccount($actor, $recipient)
            || ! app(MembershipService::class)->available($recipient)->exists()
            || ! $permission->delegable()
            || ! $this->authorization->hasEffectiveGrant($actor, $permission)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        return $this->contexts->run(AuthorizationMutationScope::permissionGrant($permission), $actor->id, fn (): array => DB::transaction(function () use ($actor, $context, $recipient, $permission, $reason, $validFrom, $validUntil): array {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::PermissionDelegate);
            if ($actor->is($recipient) || $recipient->organization_id !== $actor->organization_id
                || ! $recipient->fresh()?->is_active || $recipient->fresh()?->email_verified_at === null
                || ! app(BusinessAuthorization::class)->canManageAccount($actor, $recipient->fresh())
                || ! app(MembershipService::class)->available($recipient->fresh())->exists()
                || ! $permission->delegable() || ! $this->authorization->hasEffectiveGrant($actor, $permission)) {
                throw new AuthorizationException(__('messages.unauthorized'));
            }

            $sourceIds = $this->authorization->effectiveGrantIds($actor, $permission);
            $delegateIds = $this->authorization->effectiveGrantIds($actor, RawMaterialPermission::PermissionDelegate);
            $source = DB::table('organization_permission_grants')->whereIn('id', $sourceIds)
                ->where('user_id', $actor->id)->orderBy('granted_at')->first();
            $delegateGrant = DB::table('organization_permission_grants')->whereIn('id', $delegateIds)
                ->where('user_id', $actor->id)->orderBy('granted_at')->first();

            if (! $source || ! $delegateGrant) {
                throw new AuthorizationException(__('messages.unauthorized'));
            }

            $grantId = Str::uuid()->toString();
            $now = CarbonImmutable::now();
            $requestedFrom = $validFrom ? CarbonImmutable::parse($validFrom) : $now;
            $requestedUntil = $validUntil ? CarbonImmutable::parse($validUntil) : null;
            foreach ([$source, $delegateGrant] as $authority) {
                if ($requestedFrom->lessThan(CarbonImmutable::parse($authority->valid_from))
                    || ($authority->valid_until !== null
                        && ($requestedUntil === null || $requestedUntil->greaterThan(CarbonImmutable::parse($authority->valid_until))))) {
                    throw new AuthorizationException(__('messages.raw_material_delegation_exceeds_authority'));
                }
            }
            DB::table('organization_permission_grants')->insert([
                'id' => $grantId,
                'organization_id' => $actor->organization_id,
                'user_id' => $recipient->id,
                'permission' => $permission->value,
                'granted_by' => $actor->id,
                'valid_from' => $requestedFrom,
                'valid_until' => $validUntil,
                'delegation_source_id' => $source->id,
                'bootstrap_operation_id' => null,
                'reason_code' => 'USER_PROVIDED',
            ]);

            $operationId = Str::uuid()->toString();
            DB::table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $actor->id,
                'actor_location_id' => $context->id,
                'subject_type' => 'ORGANIZATION_PERMISSION_GRANT',
                'subject_id' => $grantId,
                'event_code' => 'PERMISSION_GRANTED',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'USER_PROVIDED',
                'metadata' => json_encode([
                    'recipient_user_id' => $recipient->id,
                    'permission' => $permission->value,
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);

            return [
                'id' => $grantId,
                'user_id' => $recipient->id,
                'permission' => $permission->value,
                'valid_from' => $requestedFrom->toISOString(),
                'valid_until' => $validUntil,
            ];
        }));
    }

    public function revoke(
        User $actor,
        Location $context,
        string $grantId,
        string $reason,
    ): void {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::PermissionDelegate);
        $grant = DB::table('organization_permission_grants')
            ->where('organization_id', $actor->organization_id)
            ->where('id', $grantId)
            ->first();
        $permission = $grant ? RawMaterialPermission::tryFrom($grant->permission) : null;
        $isLegacyCompatibilityPermission = $grant?->permission === 'RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE';
        if (! $permission && ! $isLegacyCompatibilityPermission) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        $scope = $permission
            ? AuthorizationMutationScope::permissionRevoke($permission)
            : AuthorizationMutationScope::legacySupplierCompatibilityPermissionRevoke();
        $this->contexts->run($scope, $actor->id, function () use ($actor, $context, $grantId, $reason): void {
            DB::transaction(function () use ($actor, $context, $grantId, $reason): void {
                Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
                $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::PermissionDelegate);
                $grant = DB::table('organization_permission_grants')
                    ->where('organization_id', $actor->organization_id)
                    ->where('id', $grantId)
                    ->first();

                if (! $grant) {
                    throw new AuthorizationException(__('messages.unauthorized'));
                }
                $recipient = User::whereKey($grant->user_id)->first();
                if (! $recipient || ! app(BusinessAuthorization::class)->canManageAccount($actor, $recipient)) {
                    throw new AuthorizationException(__('messages.unauthorized'));
                }

                $alreadyRevoked = DB::table('organization_permission_grant_events')
                    ->where('grant_id', $grant->id)->where('event_type', 'REVOKED')->exists();
                if ($alreadyRevoked) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_PERMISSION_ALREADY_REVOKED',
                        __('messages.raw_material_permission_already_revoked'),
                    );
                }

                $operationId = Str::uuid()->toString();
                DB::table('organization_permission_grant_events')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $actor->organization_id,
                    'grant_id' => $grant->id,
                    'event_type' => 'REVOKED',
                    'actor_user_id' => $actor->id,
                    'reason_code' => 'USER_PROVIDED',
                ]);
                DB::table('raw_material_audit_events')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $actor->organization_id,
                    'operation_id' => $operationId,
                    'actor_user_id' => $actor->id,
                    'actor_location_id' => $context->id,
                    'subject_type' => 'ORGANIZATION_PERMISSION_GRANT',
                    'subject_id' => $grant->id,
                    'event_code' => 'PERMISSION_REVOKED',
                    'outcome' => 'ACCEPTED',
                    'reason_code' => 'USER_PROVIDED',
                    'metadata' => json_encode([
                        'user_id' => $grant->user_id,
                        'permission' => $grant->permission,
                        'reason' => $reason,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
            });
        });
    }
}
