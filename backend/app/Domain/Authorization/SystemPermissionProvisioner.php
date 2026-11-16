<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocalStage6a1Instance;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SystemPermissionProvisioner
{
    public function __construct(
        private SystemPermissionManifestRegistry $registry,
        private LocalStage6a1Instance $instance,
        private SystemProvisioningRequestValidator $validator,
    ) {}

    /**
     * The approval digest is an explicit release selection, not an independent credential.
     * The deployment process and its migration-role access are the trusted authority.
     */
    public function releaseDigest(string $id, int $version): string
    {
        $manifest = $this->manifest($id, $version);
        $identity = $this->instance->verifyProvisioningTarget($manifest);

        return $this->digest($manifest, $identity);
    }

    public function execute(string $id, int $version, string $approval): string
    {
        $manifest = $this->manifest($id, $version);
        try {
            $identity = $this->instance->verifyProvisioningTarget($manifest);
        } catch (BusinessRuleViolation $exception) {
            Log::warning('Odrzucono provisioning: nie potwierdzono instalacji.', [
                'manifest_id' => $manifest->id, 'manifest_version' => $manifest->version,
            ]);
            throw $exception;
        }
        $operationId = (string) Str::uuid();

        try {
            $role = (string) env('APP_DB_MIGRATION_USERNAME', 'veltra_migrator');
            $sessionRole = (string) DB::scalar('SELECT session_user');
            if ($role === '' || $sessionRole !== $role
                || ! hash_equals($this->digest($manifest, $identity), $approval)) {
                throw new SystemPermissionProvisioningException('Odmowa: brak zatwierdzenia dokładnego wydania i instalacji.');
            }

            return DB::transaction(function () use ($manifest, $identity, $approval, $operationId): string {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    'system-permission:'.$manifest->id.':'.$manifest->version,
                ]);
                $this->assertSchemaCurrent();
                $organization = Organization::whereKey($manifest->organizationId)->lockForUpdate()->first();
                $administrator = User::whereKey($manifest->administratorId)->first();
                $location = Location::whereKey($manifest->locationId)->first();
                if (! $organization || ! $administrator || ! $location
                    || ! $manifest->matchesTarget(
                        DB::connection()->getDatabaseName(), $organization->id, $administrator->id,
                        $location->id, BusinessRole::Administrator,
                    )) {
                    throw new SystemPermissionProvisioningException('Odmowa: niezgodna organizacja, administrator lub lokalizacja.');
                }

                $permissions = $this->permissions($manifest);
                $this->validator->validate($organization, $administrator, $location, $manifest->module, $permissions);
                $this->registerManifest($manifest, $permissions);

                $existing = DB::table('system_permission_provisioning_operations')
                    ->where('manifest_id', $manifest->id)->where('manifest_version', $manifest->version)
                    ->where('organization_id', $organization->id)->where('recipient_user_id', $administrator->id)
                    ->first();
                if ($existing) {
                    $this->assertCompleted($manifest, $existing, $permissions);

                    return $existing->id;
                }

                if ($manifest->applicability === 'RECOVERY') {
                    $this->assertNoDelegatingAdministrator($organization, $administrator);
                }

                DB::table('system_permission_provisioning_operations')->insert([
                    'id' => $operationId,
                    'organization_id' => $organization->id,
                    'recipient_user_id' => $administrator->id,
                    'location_id' => $location->id,
                    'manifest_id' => $manifest->id,
                    'manifest_version' => $manifest->version,
                ]);
                $this->audit($manifest, $operationId, 'ACCEPTED', 'APPROVED_RELEASE', [
                    'approval_digest' => $approval,
                    'database_oid' => $identity['database_oid'],
                    'server_address' => $identity['server_address'],
                ]);
                foreach ($permissions as $permission) {
                    DB::table('organization_permission_grants')->insert([
                        'id' => (string) Str::uuid(),
                        'organization_id' => $organization->id,
                        'user_id' => $administrator->id,
                        'permission' => $permission,
                        'granted_by' => null,
                        'valid_from' => now(),
                        'delegation_source_id' => null,
                        'bootstrap_operation_id' => null,
                        'reason_code' => 'SYSTEM_PROVISIONING',
                        'grant_origin' => 'SYSTEM_PROVISIONING',
                        'system_provisioning_operation_id' => $operationId,
                        'system_manifest_id' => $manifest->id,
                        'system_manifest_version' => $manifest->version,
                    ]);
                }

                return $operationId;
            });
        } catch (SystemPermissionProvisioningException|AuthorizationException|ValidationException|QueryException $exception) {
            if ((string) DB::scalar('SELECT session_user') === (string) env('APP_DB_MIGRATION_USERNAME', 'veltra_migrator')
                && Organization::whereKey($manifest->organizationId)->exists()) {
                $this->audit($manifest, $operationId, 'REJECTED', 'PROVISIONING_REJECTED');
            } else {
                Log::warning('Odrzucono provisioning bez możliwości zapisu audytu instalacji.', [
                    'manifest_id' => $manifest->id, 'manifest_version' => $manifest->version,
                ]);
            }
            throw $exception;
        }
    }

    private function manifest(string $id, int $version): SystemPermissionManifest
    {
        $manifest = $this->registry->find($id, $version);
        if (! $manifest) {
            Log::warning('Odrzucono nieznany manifest systemowy.', ['manifest_id' => $id, 'version' => $version]);
            throw new SystemPermissionProvisioningException('Nieznany manifest lub wersja.');
        }
        try {
            $this->permissions($manifest);
        } catch (SystemPermissionProvisioningException $exception) {
            Log::warning('Odrzucono nieprawidłową treść manifestu systemowego.', [
                'manifest_id' => $id, 'version' => $version,
            ]);
            throw $exception;
        }

        return $manifest;
    }

    /** @return list<string> */
    private function permissions(SystemPermissionManifest $manifest): array
    {
        if ($manifest->module !== 'RAW_MATERIALS' || $manifest->eligibleRole !== BusinessRole::Administrator
            || $manifest->grantScope !== 'ORGANIZATION' || ! in_array($manifest->applicability, ['INSTALLATION', 'UPGRADE', 'RECOVERY'], true)
            || $manifest->permissions === []) {
            throw new SystemPermissionProvisioningException('Niedozwolony moduł, rola, zakres lub warunek manifestu.');
        }
        $permissions = [];
        foreach ($manifest->permissions as $permission) {
            if (! $permission instanceof RawMaterialPermission) {
                throw new SystemPermissionProvisioningException('Manifest zawiera niezarejestrowane uprawnienie.');
            }
            $permissions[] = $permission->value;
        }
        if (count($permissions) !== count(array_unique($permissions))) {
            throw new SystemPermissionProvisioningException('Manifest zawiera powtórzone uprawnienie.');
        }

        return $permissions;
    }

    private function assertNoDelegatingAdministrator(Organization $organization, User $recipient): void
    {
        $administratorIds = RoleAssignment::effective()->where('organization_id', $organization->id)
            ->where('role', BusinessRole::Administrator)->whereNull('location_id')
            ->where('user_id', '!=', $recipient->id)->pluck('user_id');
        $authorization = app(RawMaterialAuthorization::class);
        foreach (User::whereIn('id', $administratorIds)->where('organization_id', $organization->id)
            ->where('is_active', true)->whereNotNull('email_verified_at')->get() as $administrator) {
            if (! app(MembershipService::class)->available($administrator)->exists()) {
                continue;
            }
            $ids = $authorization->effectiveGrantIds($administrator, RawMaterialPermission::PermissionDelegate);
            if (DB::table('organization_permission_grants')->whereIn('id', $ids)
                ->whereNull('delegation_source_id')->exists()) {
                throw new SystemPermissionProvisioningException('Odmowa: istnieje administrator uprawniony do delegowania RAW.');
            }
        }
    }

    /** @param array{database_oid: string, server_address: string} $identity */
    private function digest(SystemPermissionManifest $manifest, array $identity): string
    {
        return hash('sha256', json_encode([
            'manifest_id' => $manifest->id,
            'manifest_version' => $manifest->version,
            'manifest_hash' => $manifest->contentHash(),
            'database_name' => $manifest->databaseName,
            'database_oid' => $identity['database_oid'],
            'server_address' => $identity['server_address'],
            'organization_id' => $manifest->organizationId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertSchemaCurrent(): void
    {
        $migrations = app('migrator')->getMigrationFiles(database_path('migrations'));
        $applied = DB::table('migrations')->pluck('migration')->all();
        if (array_diff(array_keys($migrations), $applied) !== []
            || ! in_array('2026_11_11_000048_pin_system_permission_manifest_content', $applied, true)) {
            throw new SystemPermissionProvisioningException('Schemat bazy nie odpowiada wersji aplikacji.');
        }
    }

    /** @param list<string> $permissions */
    private function registerManifest(SystemPermissionManifest $manifest, array $permissions): void
    {
        $row = DB::table('system_permission_manifests')->where('manifest_id', $manifest->id)
            ->where('version', $manifest->version)->first();
        if (! $row) {
            DB::table('system_permission_manifests')->insert([
                'manifest_id' => $manifest->id, 'version' => $manifest->version,
                'module_key' => $manifest->module, 'content_hash' => $manifest->contentHash(),
            ]);
            foreach ($permissions as $permission) {
                DB::table('system_permission_manifest_permissions')->insert([
                    'manifest_id' => $manifest->id, 'manifest_version' => $manifest->version,
                    'permission' => $permission,
                ]);
            }

            return;
        }
        $registered = DB::table('system_permission_manifest_permissions')
            ->where('manifest_id', $manifest->id)->where('manifest_version', $manifest->version)
            ->orderBy('permission')->pluck('permission')->all();
        $expected = $permissions;
        sort($expected);
        if ($row->module_key !== $manifest->module || ! is_string($row->content_hash)
            || ! hash_equals($row->content_hash, $manifest->contentHash()) || $registered !== $expected) {
            throw new SystemPermissionProvisioningException('Treść istniejącej wersji manifestu jest niezgodna.');
        }
    }

    /** @param list<string> $permissions */
    private function assertCompleted(SystemPermissionManifest $manifest, object $operation, array $permissions): void
    {
        $recorded = DB::table('organization_permission_grants')->where('system_provisioning_operation_id', $operation->id)
            ->where('grant_origin', 'SYSTEM_PROVISIONING')->orderBy('permission')->pluck('permission')->all();
        $expected = $permissions;
        sort($expected);
        $audit = DB::table('raw_material_audit_events')->where('organization_id', $manifest->organizationId)
            ->where('operation_id', $operation->id)->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')
            ->where('outcome', 'ACCEPTED')->whereNull('actor_user_id')->first();
        $metadata = $audit ? json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR) : null;
        if ($operation->location_id !== $manifest->locationId || $recorded !== $expected
            || ! is_array($metadata) || ($metadata['manifest_hash'] ?? null) !== $manifest->contentHash()) {
            throw new SystemPermissionProvisioningException('Historia operacji jest niekompletna lub niezgodna.');
        }
    }

    private function audit(
        SystemPermissionManifest $manifest,
        string $operationId,
        string $outcome,
        string $reason,
        array $extra = [],
    ): void {
        DB::table('raw_material_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $manifest->organizationId,
            'operation_id' => $operationId,
            'actor_user_id' => null,
            'subject_type' => 'ORGANIZATION',
            'subject_id' => $manifest->organizationId,
            'event_code' => 'SYSTEM_PERMISSION_PROVISIONING',
            'outcome' => $outcome,
            'reason_code' => $reason,
            'metadata' => json_encode([
                'manifest_id' => $manifest->id,
                'manifest_version' => $manifest->version,
                'manifest_hash' => $manifest->contentHash(),
                'recipient_user_id' => $manifest->administratorId,
                'location_id' => $manifest->locationId,
                'grant_scope' => $manifest->grantScope,
                'permissions' => $this->permissions($manifest),
                'actor_type' => 'SYSTEM_PROVISIONER',
                'actor_database_role' => (string) DB::scalar('SELECT session_user'),
                'reason' => $manifest->applicability === 'RECOVERY'
                    ? 'Odzyskanie uprawnień administratora do odbioru RAW Stage.'
                    : 'Jawne zatwierdzenie wydania uprawnień systemowych.',
                ...$extra,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
