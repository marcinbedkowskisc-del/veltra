<?php

namespace App\Domain\RawMaterials;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

final class RawMaterialPermissionUpgrade
{
    public function __construct(private AuthorizationMutationContext $contexts) {}

    /**
     * @param  list<string>  $permissions
     * @return array{operation_id: string, replayed: bool, grants: list<array{id: string, user_id: string, permission: string}>}
     */
    public function grant(
        string $actorId,
        string $recipientId,
        array $permissions,
        string $reason,
        string $idempotencyKey,
        #[SensitiveParameter] string $password,
    ): array {
        $actor = User::find($actorId);
        if (! $actor) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        $permissionValues = array_values(array_unique($permissions));
        sort($permissionValues, SORT_STRING);
        $allowed = array_map(
            static fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );

        if ($permissionValues === [] || array_diff($permissionValues, $allowed) !== []
            || trim($reason) === '' || mb_strlen(trim($reason)) > 500
            || ! preg_match('/\A[A-Za-z0-9._:-]{8,120}\z/', $idempotencyKey)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        $reason = trim($reason);
        $requestHash = hash('sha256', json_encode([
            'actor_user_id' => $actorId,
            'recipient_user_id' => $recipientId,
            'permissions' => $permissionValues,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $this->contexts->run(AuthorizationMutationScope::permissionUpgrade(), $actorId, fn (): array => DB::transaction(function () use (
            $actor,
            $actorId,
            $recipientId,
            $permissionValues,
            $reason,
            $idempotencyKey,
            $password,
            $requestHash,
        ): array {
            $organization = Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            $freshActor = User::with('organization')->find($actorId);
            $recipient = User::where('organization_id', $organization->id)->whereKey($recipientId)->first();

            if (! $freshActor || ! $recipient) {
                throw new AuthorizationException(__('messages.unauthorized'));
            }

            $this->assertAuthorizedAdministrator($freshActor, $organization, $password);

            $existing = DB::table('raw_material_permission_upgrade_operations')
                ->where('organization_id', $organization->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new RawMaterialException(
                        'RAW_MATERIAL_IDEMPOTENCY_CONFLICT',
                        'Klucz idempotencji został już użyty dla innego żądania.',
                    );
                }

                return $this->result($existing->id, true);
            }

            if ($freshActor->is($recipient) || $recipient->organization_id !== $organization->id
                || ! $recipient->is_active || $recipient->email_verified_at === null
                || ! app(MembershipService::class)->available($recipient)->exists()) {
                throw new AuthorizationException(__('messages.unauthorized'));
            }

            $delegateGrantIds = app(RawMaterialAuthorization::class)
                ->effectiveGrantIds($freshActor, RawMaterialPermission::PermissionDelegate);
            $authority = DB::table('organization_permission_grants')
                ->where('organization_id', $organization->id)
                ->whereIn('id', $delegateGrantIds)
                ->whereNull('delegation_source_id')
                ->orderByRaw('valid_until IS NULL DESC')
                ->orderByDesc('valid_until')
                ->orderBy('granted_at')
                ->first();
            if (! $authority) {
                throw new AuthorizationException(__('messages.unauthorized'));
            }

            $operationId = Str::uuid()->toString();
            $now = CarbonImmutable::now();
            DB::table('raw_material_permission_upgrade_operations')->insert([
                'id' => $operationId,
                'organization_id' => $organization->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'actor_user_id' => $freshActor->id,
                'recipient_user_id' => $recipient->id,
                'authority_grant_id' => $authority->id,
                'permissions' => json_encode($permissionValues, JSON_THROW_ON_ERROR),
                'reason' => $reason,
                'created_at' => $now,
            ]);
            DB::table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $organization->id,
                'operation_id' => $operationId,
                'actor_user_id' => $freshActor->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $organization->id,
                'event_code' => 'PERMISSION_UPGRADE',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'EXPLICIT_ADMIN_UPGRADE',
                'metadata' => json_encode([
                    'recipient_user_id' => $recipient->id,
                    'permissions' => $permissionValues,
                    'reason' => $reason,
                    'authority_grant_id' => $authority->id,
                    'idempotency_key' => $idempotencyKey,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);

            foreach ($permissionValues as $permission) {
                DB::table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $organization->id,
                    'user_id' => $recipient->id,
                    'permission' => $permission,
                    'granted_by' => $freshActor->id,
                    'valid_from' => $now,
                    'valid_until' => $authority->valid_until,
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'EXPLICIT_ADMIN_UPGRADE',
                ]);
            }

            return $this->result($operationId, false);
        }));
    }

    private function assertAuthorizedAdministrator(User $actor, Organization $organization, #[SensitiveParameter] string $password): void
    {
        if (! $actor->is_active || $actor->email_verified_at === null
            || ! $organization->is_active || ! $organization->authorization_initialized
            || ! app(MembershipService::class)->available($actor)->exists()
            || ! RoleAssignment::effective()->where('organization_id', $organization->id)
                ->where('user_id', $actor->id)->where('role', BusinessRole::Administrator)
                ->whereNull('location_id')->exists()
            || ! app(RawMaterialAuthorization::class)
                ->hasEffectiveGrant($actor, RawMaterialPermission::PermissionDelegate)
            || ! Hash::check($password, $actor->password)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
    }

    /**
     * @return array{operation_id: string, replayed: bool, grants: list<array{id: string, user_id: string, permission: string}>}
     */
    private function result(string $operationId, bool $replayed): array
    {
        $grants = DB::table('organization_permission_grants')
            ->where('bootstrap_operation_id', $operationId)
            ->orderBy('permission')
            ->get(['id', 'user_id', 'permission'])
            ->map(static fn (object $grant): array => [
                'id' => $grant->id,
                'user_id' => $grant->user_id,
                'permission' => $grant->permission,
            ])->all();

        return [
            'operation_id' => $operationId,
            'replayed' => $replayed,
            'grants' => $grants,
        ];
    }
}
