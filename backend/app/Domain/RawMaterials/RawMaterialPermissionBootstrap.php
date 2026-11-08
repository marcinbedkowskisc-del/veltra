<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

final class RawMaterialPermissionBootstrap
{
    public function __construct(private AuthorizationMutationContext $contexts) {}

    /**
     * @param  list<string>  $permissions
     * @return list<array{id: string, user_id: string, permission: string}>
     */
    public function grantInitial(
        string $actorId,
        string $recipientId,
        array $permissions,
        string $reason,
        #[SensitiveParameter] string $password,
    ): array {
        $actor = User::with('organization')->find($actorId);
        $recipient = User::find($recipientId);
        $authorization = app(BusinessAuthorization::class);
        $permissions = array_values(array_unique($permissions));
        $allowed = array_map(fn (RawMaterialPermission $permission): string => $permission->value, RawMaterialPermission::cases());

        if (! $actor || ! $recipient || $actor->is($recipient)
            || $actor->organization_id !== $recipient->organization_id
            || ! $actor->is_active || $actor->email_verified_at === null
            || ! $actor->organization->is_active || ! $actor->organization->authorization_initialized
            || ! $recipient->is_active || $recipient->email_verified_at === null
            || ! $authorization->canManageAccount($actor, $recipient)
            || ! app(MembershipService::class)->available($actor)->exists()
            || ! app(MembershipService::class)->available($recipient)->exists()
            || ! RoleAssignment::effective()->where('organization_id', $actor->organization_id)
                ->where('user_id', $actor->id)->where('role', BusinessRole::Administrator)
                ->whereNull('location_id')->exists()
            || ! Hash::check($password, $actor->password)
            || $permissions === []
            || array_diff($permissions, $allowed) !== []
            || ! in_array(RawMaterialPermission::PermissionDelegate->value, $permissions, true)) {
            throw new AuthorizationException(__('messages.raw_material_bootstrap_denied'));
        }

        return $this->contexts->run(AuthorizationMutationScope::permissionBootstrap(), $actor->id, fn (): array => DB::transaction(function () use ($actor, $recipient, $permissions, $reason, $password, $authorization): array {
            $organization = Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $freshActor = User::with('organization')->find($actor->id);
            $freshRecipient = User::find($recipient->id);
            if (! $freshActor || ! $freshRecipient || ! $freshActor->is_active || $freshActor->email_verified_at === null
                || ! $organization->is_active || ! $organization->authorization_initialized
                || $freshRecipient->organization_id !== $organization->id || ! $freshRecipient->is_active
                || $freshRecipient->email_verified_at === null || $freshActor->is($freshRecipient)
                || ! $authorization->canManageAccount($freshActor, $freshRecipient)
                || ! Hash::check($password, $freshActor->password)
                || ! RoleAssignment::effective()->where('organization_id', $organization->id)
                    ->where('user_id', $freshActor->id)->where('role', BusinessRole::Administrator)
                    ->whereNull('location_id')->exists()
                || ! app(MembershipService::class)->available($freshActor)->exists()
                || ! app(MembershipService::class)->available($freshRecipient)->exists()
                || DB::table('organization_permission_grants')->where('organization_id', $organization->id)->exists()
                || DB::table('raw_material_audit_events')->where('organization_id', $organization->id)
                    ->where('event_code', 'PERMISSION_BOOTSTRAP')->exists()) {
                throw new AuthorizationException(__('messages.raw_material_bootstrap_denied'));
            }

            $operationId = Str::uuid()->toString();
            DB::table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $organization->id,
                'operation_id' => $operationId,
                'actor_user_id' => $freshActor->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $organization->id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $freshRecipient->id,
                    'permissions' => $permissions,
                    'reason' => $reason,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);

            $grants = [];
            foreach ($permissions as $permission) {
                $id = Str::uuid()->toString();
                DB::table('organization_permission_grants')->insert([
                    'id' => $id,
                    'organization_id' => $organization->id,
                    'user_id' => $freshRecipient->id,
                    'permission' => $permission,
                    'granted_by' => $freshActor->id,
                    'valid_from' => now(),
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
                $grants[] = ['id' => $id, 'user_id' => $freshRecipient->id, 'permission' => $permission];
            }

            return $grants;
        }));
    }
}
