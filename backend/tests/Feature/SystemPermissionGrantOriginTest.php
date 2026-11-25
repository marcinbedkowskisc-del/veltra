<?php

namespace Tests\Feature;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;

class SystemPermissionGrantOriginTest extends AuthorizationTestCase
{
    private User $actor;

    private User $recipient;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->location = $this->kitchen();
        $this->actor = $this->provisionedUser('origin-actor@example.test', BusinessRole::Administrator);
        $this->recipient = $this->provisionedUser('origin-recipient@example.test', BusinessRole::CentralKitchenChef, $this->location);
    }

    public function test_invalid_origin_and_mixed_user_origin_are_rejected(): void
    {
        $this->rejectsConstraint('organization_permission_grants_origin_check', function (Connection $db): void {
            $this->insertGrant($db, [
                'grant_origin' => 'UNKNOWN',
                'granted_by' => $this->actor->id,
            ]);
        });

        $this->rejectsConstraint('organization_permission_grants_origin_check', function (Connection $db): void {
            $operation = (string) Str::uuid();
            $this->audit($db, $operation, 'PERMISSION_BOOTSTRAP', $this->actor->id);
            $this->insertGrant($db, [
                'grant_origin' => 'USER_DELEGATION',
                'granted_by' => $this->actor->id,
                'bootstrap_operation_id' => $operation,
                'system_provisioning_operation_id' => (string) Str::uuid(),
                'system_manifest_id' => 'fixture',
                'system_manifest_version' => 1,
            ]);
        });
    }

    public function test_user_origin_still_rejects_self_grants(): void
    {
        $this->rejectsConstraint('organization_permission_grants_check1', function (Connection $db): void {
            $operation = (string) Str::uuid();
            $this->audit($db, $operation, 'PERMISSION_BOOTSTRAP', $this->recipient->id);
            $this->insertGrant($db, [
                'granted_by' => $this->recipient->id,
                'bootstrap_operation_id' => $operation,
            ]);
        });
    }

    public function test_system_origin_rejects_a_user_grantor_or_missing_operation(): void
    {
        $this->rejectsConstraint('organization_permission_grants_origin_check', function (Connection $db): void {
            $operation = $this->systemFixture($db, createOperation: true);
            $this->insertGrant($db, [
                ...$this->systemOrigin($operation),
                'granted_by' => $this->actor->id,
            ]);
        });

        $this->rejectsConstraint('organization_permission_grants_system_operation_fkey', function (Connection $db): void {
            $operation = $this->systemFixture($db, createOperation: false);
            $this->insertGrant($db, $this->systemOrigin($operation));
        });
    }

    public function test_system_origin_requires_the_registered_permission_and_correct_target(): void
    {
        $this->rejectsConstraint('organization_permission_grants_system_permission_fkey', function (Connection $db): void {
            $operation = $this->systemFixture($db, createOperation: true, registerPermission: false);
            $this->insertGrant($db, $this->systemOrigin($operation));
        });

        $this->rejectsConstraint('organization_permission_grants_system_operation_fkey', function (Connection $db): void {
            $operation = $this->systemFixture($db, createOperation: true, operationUser: $this->actor->id);
            $this->insertGrant($db, $this->systemOrigin($operation));
        });
    }

    public function test_runtime_cannot_write_provisioning_registry_or_operations(): void
    {
        foreach (['system_permission_manifests', 'system_permission_manifest_permissions', 'system_permission_provisioning_operations'] as $table) {
            $privileges = DB::selectOne(
                "SELECT has_table_privilege(current_user, ?, 'INSERT,UPDATE,DELETE,TRUNCATE') AS can_write",
                ["public.{$table}"],
            );
            $this->assertFalse($privileges->can_write);
        }
    }

    public function test_signed_runtime_mutation_cannot_insert_a_system_grant(): void
    {
        try {
            app(AuthorizationMutationContext::class)->run(
                AuthorizationMutationScope::permissionBootstrap(),
                null,
                fn () => $this->insertGrant(DB::connection(), $this->systemOrigin((string) Str::uuid())),
            );
            $this->fail('A runtime mutation must not create a system grant.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0]);
            $this->assertStringContainsString('unavailable to the runtime role', $exception->getMessage());
        }
    }

    public function test_system_grant_requires_matching_audit_and_raw_manifest(): void
    {
        $this->rejectsDatabase('23514', 'matching accepted audit', function (Connection $db): void {
            $this->insertGrant($db, $this->systemOrigin((string) Str::uuid()));
        });

        $this->rejectsDatabase('23514', 'does not belong to RAW_MATERIALS', function (Connection $db): void {
            $operation = $this->systemFixture($db, createOperation: true, module: 'OTHER_MODULE');
            $this->insertGrant($db, $this->systemOrigin($operation));
        });
    }

    public function test_provisioning_operation_requires_registered_manifest_version_and_cannot_be_replayed(): void
    {
        $this->rejectsDatabase('23503', 'foreign key constraint', function (Connection $db): void {
            $db->table('system_permission_provisioning_operations')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $this->recipient->organization_id,
                'recipient_user_id' => $this->recipient->id,
                'location_id' => $this->location->id,
                'manifest_id' => 'unregistered',
                'manifest_version' => 1,
            ]);
        });

        $this->rejectsDatabase('23505', 'duplicate key value', function (Connection $db): void {
            $this->systemFixture($db, createOperation: true);
            $db->table('system_permission_provisioning_operations')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $this->recipient->organization_id,
                'recipient_user_id' => $this->recipient->id,
                'location_id' => $this->location->id,
                'manifest_id' => 'fixture',
                'manifest_version' => 1,
            ]);
        });
    }

    public function test_used_manifest_version_cannot_be_extended_or_rewritten(): void
    {
        $this->rejectsDatabase('23514', 'cannot be extended', function (Connection $db): void {
            $this->systemFixture($db, createOperation: true);
            $db->table('system_permission_manifest_permissions')->insert([
                'manifest_id' => 'fixture', 'manifest_version' => 1, 'permission' => 'RAW_MATERIALS_CREATE',
            ]);
        });

        $this->rejectsDatabase('23514', 'append-only', function (Connection $db): void {
            $this->systemFixture($db, createOperation: true);
            $db->table('system_permission_manifests')->where('manifest_id', 'fixture')->update(['module_key' => 'OTHER']);
        });
    }

    public function test_migration_is_forward_only_and_direct_replay_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_11_11_000047_add_system_permission_grant_origin.php');
        try {
            $migration->down();
            $this->fail('The migration must not roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }

        DB::setDefaultConnection('pgsql_setup');
        try {
            try {
                $migration->up();
                $this->fail('Direct migration replay must not silently alter the existing schema.');
            } catch (QueryException $exception) {
                $this->assertSame('42P07', $exception->errorInfo[0]);
            }
            $this->assertNotNull(DB::scalar("SELECT to_regclass('public.system_permission_provisioning_operations')"));
            $this->assertSame(1, (int) DB::scalar(<<<'SQL'
                SELECT count(*) FROM pg_trigger
                WHERE tgrelid = 'public.organization_permission_grants'::regclass
                    AND tgname = 'organization_permission_grants_validate_system'
                SQL));
        } finally {
            DB::setDefaultConnection('pgsql');
        }
    }

    private function rejectsConstraint(string $constraint, callable $attempt): void
    {
        try {
            $this->withSetupTransaction($attempt);
            $this->fail("Expected {$constraint} to reject the grant.");
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0], ['23503', '23514']);
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }

    private function rejectsDatabase(string $sqlState, string $message, callable $attempt): void
    {
        try {
            $this->withSetupTransaction($attempt);
            $this->fail("Expected database rejection: {$message}.");
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function systemFixture(
        Connection $db,
        bool $createOperation,
        bool $registerPermission = true,
        ?string $operationUser = null,
        string $module = 'RAW_MATERIALS',
    ): string {
        $operation = (string) Str::uuid();
        $db->table('system_permission_manifests')->insert([
            'manifest_id' => 'fixture', 'version' => 1, 'module_key' => $module,
        ]);
        if ($registerPermission) {
            $db->table('system_permission_manifest_permissions')->insert([
                'manifest_id' => 'fixture', 'manifest_version' => 1, 'permission' => 'RAW_MATERIALS_VIEW',
            ]);
        }
        if ($createOperation) {
            $db->table('system_permission_provisioning_operations')->insert([
                'id' => $operation,
                'organization_id' => $this->recipient->organization_id,
                'recipient_user_id' => $operationUser ?? $this->recipient->id,
                'location_id' => $this->location->id,
                'manifest_id' => 'fixture',
                'manifest_version' => 1,
            ]);
        }
        $this->audit($db, $operation, 'SYSTEM_PERMISSION_PROVISIONING', null);

        return $operation;
    }

    private function audit(Connection $db, string $operation, string $event, ?string $actor): void
    {
        $db->table('raw_material_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->recipient->organization_id,
            'operation_id' => $operation,
            'actor_user_id' => $actor,
            'subject_type' => 'ORGANIZATION',
            'event_code' => $event,
            'outcome' => 'ACCEPTED',
            'metadata' => json_encode([
                'manifest_id' => 'fixture',
                'manifest_version' => 1,
                'recipient_user_id' => $this->recipient->id,
                'permissions' => ['RAW_MATERIALS_VIEW'],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function systemOrigin(string $operation): array
    {
        return [
            'grant_origin' => 'SYSTEM_PROVISIONING',
            'granted_by' => null,
            'system_provisioning_operation_id' => $operation,
            'system_manifest_id' => 'fixture',
            'system_manifest_version' => 1,
        ];
    }

    private function insertGrant(Connection $db, array $attributes): void
    {
        $db->table('organization_permission_grants')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->recipient->organization_id,
            'user_id' => $this->recipient->id,
            'permission' => 'RAW_MATERIALS_VIEW',
            'granted_by' => null,
            'valid_from' => now(),
            'reason_code' => 'TEST_FIXTURE',
            ...$attributes,
        ]);
    }
}
