<?php

namespace Tests\Feature;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\PersonnelAdministration;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\ModuleConfigurationService;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialPermissionBootstrap;
use App\Domain\RawMaterials\RawMaterialPermissionUpgrade;
use App\Domain\RawMaterials\RawMaterialService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ModuleCatalogFixtures;
use Tests\TestCase;

class AuthorizationMutationBoundaryTest extends TestCase
{
    private array $originalDatabaseConfig;

    private string $connectionName;

    protected function setUp(): void
    {
        parent::setUp();

        $runtimeUsername = (string) env('APP_DB_USERNAME', '');
        $runtimePassword = (string) env('APP_DB_PASSWORD', '');
        if ($runtimeUsername === '' || $runtimePassword === '') {
            $this->markTestSkipped('Rzeczywiste poświadczenia runtime nie są skonfigurowane.');
        }

        $this->connectionName = (string) config('database.default');
        $this->originalDatabaseConfig = config("database.connections.{$this->connectionName}");
        config([
            "database.connections.{$this->connectionName}.username" => $runtimeUsername,
            "database.connections.{$this->connectionName}.password" => $runtimePassword,
        ]);
        DB::purge($this->connectionName);
        DB::connection($this->connectionName)->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connectionName) && DB::connection($this->connectionName)->transactionLevel() > 0) {
            DB::connection($this->connectionName)->rollBack();
        }
        if (isset($this->connectionName, $this->originalDatabaseConfig)) {
            DB::purge($this->connectionName);
            config(["database.connections.{$this->connectionName}" => $this->originalDatabaseConfig]);
        }

        parent::tearDown();
    }

    public function test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available(): void
    {
        $connection = DB::connection($this->connectionName);
        $this->assertSame((string) env('APP_DB_USERNAME'), $connection->scalar('SELECT session_user'));
        $this->assertFalse($connection->scalar("SELECT has_schema_privilege(session_user, 'public', 'CREATE')"));
        $this->assertFalse($connection->scalar("SELECT has_schema_privilege(session_user, 'veltra_private', 'USAGE')"));
        $this->assertFalse($connection->scalar("SELECT has_table_privilege(session_user, 'public.role_assignments', 'TRUNCATE')"));
        $this->assertTrue($connection->scalar("SELECT has_table_privilege(session_user, 'public.raw_material_permission_grant_operation_uses', 'SELECT')"));
        $this->assertFalse($connection->scalar("SELECT has_table_privilege(session_user, 'public.raw_material_permission_grant_operation_uses', 'INSERT')"));
        $this->assertFalse($connection->scalar("SELECT has_table_privilege(session_user, 'public.raw_material_permission_grant_operation_uses', 'UPDATE')"));
        $this->assertFalse($connection->scalar("SELECT has_table_privilege(session_user, 'public.raw_material_permission_grant_operation_uses', 'DELETE')"));
        $this->assertFalse($connection->scalar("SELECT has_table_privilege(session_user, 'public.raw_material_permission_grant_operation_uses', 'TRUNCATE')"));

        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.role_assignments DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.raw_material_permission_upgrade_operations DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.raw_material_permission_grant_operation_uses DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.raw_material_audit_events DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.security_events DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.organization_permission_grant_events DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.raw_materials_commercial_audit_events DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.supplier_offer_events DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.raw_material_financial_restatements DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('INSERT INTO public.raw_materials_currency_codes DEFAULT VALUES'));
        $this->assertRuntimeDenied(fn () => $connection->statement('TRUNCATE public.role_assignments'));
        $this->assertRuntimeDenied(fn () => $connection->statement('ALTER TABLE public.role_assignments DISABLE TRIGGER ALL'));
        $this->assertRuntimeDenied(fn () => $connection->statement('SET ROLE veltra_migrator'));
        $this->assertRuntimeDenied(fn () => $connection->select('SELECT veltra_private.enforce_authorization_mutation()'));

        $connection->statement('CREATE TEMP TABLE role_assignments (id uuid)');
        $connection->statement('SET LOCAL search_path = pg_temp, public');
        $this->assertRuntimeDenied(fn () => $connection->statement(
            "INSERT INTO public.role_assignments (id, organization_id, user_id, role) VALUES (gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), 'ADMINISTRATOR')",
        ));
        $this->assertRuntimeDenied(fn () => $connection->statement(
            'CREATE TRIGGER forged_trigger BEFORE INSERT ON role_assignments FOR EACH ROW EXECUTE FUNCTION veltra_private.enforce_authorization_mutation()',
        ));
        $connection->statement('SET LOCAL search_path = public');
        $connection->statement('DROP TABLE pg_temp.role_assignments');

        $connection->select(
            "SELECT set_config('veltra.authorization_payload', ?, true), set_config('veltra.authorization_signature', ?, true)",
            ['{"actor_id":null,"expires_at":4102444800,"nonce":"00000000-0000-4000-8000-000000000022","scope":"role.grant.ADMINISTRATOR","transaction_id":"1"}', str_repeat('0', 64)],
        );
        $this->assertRuntimeDenied(fn () => $connection->statement(
            "INSERT INTO public.role_assignments (id, organization_id, user_id, role) VALUES (gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), 'ADMINISTRATOR')",
        ));

        $organization = app(InstallationService::class)->initializeOrganization([
            'name' => 'Granica autoryzacji',
            'code' => 'AUTH-BOUNDARY',
        ]);
        $location = app(InstallationService::class)->createLocation([
            'name' => 'Kuchnia główna',
            'code' => 'AUTH-CK',
            'type' => 'CENTRAL_KITCHEN',
        ]);
        $administrator = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Admin',
            'last_name' => 'Pierwszy',
            'email' => 'admin-one@example.test',
        ], self::password());
        $roles = app(RoleAdministration::class);
        $roles->bootstrapInitial($administrator);
        app(MembershipService::class)->assign($administrator, $location);

        app(AuthorizationMutationContext::class)->run(AuthorizationMutationScope::accountConsume(), null, function () use ($administrator, $organization): void {
            $administrator->forceFill(['email_verified_at' => now()])->save();
            $organization->forceFill(['authorization_initialized' => true])->save();
        });

        $secondAdministrator = app(PersonnelAdministration::class)->requestPersonnel(
            $administrator,
            $location,
            $location,
            [
                'first_name' => 'Admin',
                'last_name' => 'Drugi',
                'email' => 'admin-two@example.test',
            ],
        );
        $this->assertNotNull($secondAdministrator);
        app(AuthorizationMutationContext::class)->run(AuthorizationMutationScope::accountConsume(), null, function () use ($secondAdministrator): void {
            $secondAdministrator->forceFill([
                'email_verified_at' => now(),
                'password' => Hash::make(self::password()),
            ])->save();
        });
        $roles->grant(
            $administrator,
            $location,
            $secondAdministrator,
            BusinessRole::Administrator,
            null,
            self::password(),
        );

        $bootstrap = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $secondAdministrator->id,
            [RawMaterialPermission::PermissionDelegate->value],
            'Nadanie początkowej delegacji',
            self::password(),
        );
        $upgrade = app(RawMaterialPermissionUpgrade::class)->grant(
            $secondAdministrator->id,
            $administrator->id,
            [
                RawMaterialPermission::CategoryManage->value,
                RawMaterialPermission::Create->value,
                RawMaterialPermission::SupplierManage->value,
            ],
            'Autoryzowane rozszerzenie uprawnień',
            'runtime-upgrade-000022',
            self::password(),
        );

        $this->assertFalse($upgrade['replayed']);
        $this->assertSame(1, $connection->table('raw_material_permission_upgrade_operations')
            ->where('id', $upgrade['operation_id'])->count());
        $this->assertSame(1, $connection->table('raw_material_audit_events')
            ->where('operation_id', $upgrade['operation_id'])->where('event_code', 'PERMISSION_UPGRADE')->count());
        $this->assertSame(1, $connection->table('organization_permission_grants')
            ->where('bootstrap_operation_id', $upgrade['operation_id'])
            ->where('permission', RawMaterialPermission::SupplierManage->value)->count());
        $roleAssignmentId = $connection->table('role_assignments')
            ->where('user_id', $secondAdministrator->id)
            ->where('role', BusinessRole::Administrator->value)
            ->value('id');
        $this->assertRoleGrantTicketCannotBeReusedForRevocation(
            $connection,
            $administrator->id,
            $secondAdministrator->id,
            $roleAssignmentId,
        );
        $securityEventId = $connection->table('security_events')->value('id');
        $this->assertRuntimeDenied(fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::roleGrant(BusinessRole::Administrator),
            $administrator->id,
            fn (): int => $connection->table('security_events')
                ->where('id', $securityEventId)->update(['outcome' => 'rejected']),
        ));
        $permissionGrantId = $bootstrap[0]['id'];
        $this->assertRuntimeDenied(fn () => $connection->table('role_assignments')
            ->where('id', $roleAssignmentId)->update(['revoked_at' => now()]));
        $this->assertRuntimeDenied(fn () => $connection->table('role_assignments')
            ->where('id', $roleAssignmentId)->delete());
        $this->assertRuntimeDenied(fn () => $connection->table('security_events')
            ->where('id', $securityEventId)->update(['outcome' => 'rejected']));
        $this->assertRuntimeDenied(fn () => $connection->table('security_events')
            ->where('id', $securityEventId)->delete());
        $this->assertRuntimeDenied(fn () => $connection->table('organization_permission_grants')
            ->where('id', $permissionGrantId)->update(['valid_until' => now()]));
        $this->assertRuntimeDenied(fn () => $connection->table('organization_permission_grants')
            ->where('id', $permissionGrantId)->delete());

        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        app(ModuleConfigurationService::class)->configure($administrator, $location, [
            'module_ids' => ['RAW_MATERIALS'],
            'location_ids' => [$location->id],
            'enabled' => true,
            'current_password' => self::password(),
        ]);
        $category = app(RawMaterialService::class)->createCategory($administrator, $location, [
            'code' => 'BASIC',
            'name' => 'Podstawowe',
        ]);
        $material = app(RawMaterialService::class)->create($administrator, $location, [
            'category_id' => $category['id'],
            'name' => 'Mąka testowa',
            'base_unit_code' => 'kg',
            'conversions' => [['unit_code' => 'g']],
            'aliases' => ['Mąka do testów'],
        ]);
        $this->assertTempApprovalCannotBeForged($connection, $material['id']);
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $administrator,
            $location,
            ['supplier_code' => 'SUP-TEST', 'display_name' => 'Dostawca testowy'],
            'runtime-supplier-000022',
        );
        $this->assertSame('DRAFT', $material['lifecycle_state']);
        $this->assertSame('SUP-TEST', $supplier['data']['supplier_code']);
        $this->assertSame(1, $connection->table('raw_materials_commercial_idempotency')
            ->where('idempotency_key', 'runtime-supplier-000022')->count());
        $this->assertNineTableRuntimeDmlMatrix(
            $connection,
            $organization->id,
            $administrator->id,
            $category['id'],
            $material['id'],
            $supplier['data']['id'],
        );

        $bootstrapOperation = $connection->table('organization_permission_grants')
            ->where('id', $bootstrap[0]['id'])->value('bootstrap_operation_id');
        $this->assertRuntimeDenied(fn () => $connection->table('organization_permission_grants')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $administrator->id,
            'permission' => RawMaterialPermission::SupplierView->value,
            'granted_by' => $administrator->id,
            'valid_from' => now(),
            'delegation_source_id' => null,
            'bootstrap_operation_id' => $bootstrapOperation,
            'reason_code' => 'INITIAL_STEWARD',
        ]));
        $this->assertRuntimeDenied(fn () => $connection->table('role_assignments')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $administrator->id,
            'role' => BusinessRole::Administrator->value,
        ]));

        $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function assertTempApprovalCannotBeForged(Connection $connection, string $materialId): void
    {
        $material = $connection->table('raw_materials')->where('id', $materialId)->first();
        $this->assertNotNull($material?->working_revision_id);

        $connection->table('raw_materials')->where('id', $materialId)->update([
            'lifecycle_state' => 'VERIFICATION',
            'pending_revision_id' => $material->working_revision_id,
            'working_revision_id' => null,
        ]);
        $revision = $connection->table('raw_material_revisions')->where('id', $material->working_revision_id)->first();
        $this->assertNotNull($revision);

        $connection->statement('CREATE TEMP TABLE raw_material_revision_approval_events (
            organization_id uuid, revision_id uuid, outcome varchar(16), content_hash char(64)
        )');
        $connection->statement('CREATE TEMP TABLE raw_material_revisions (id uuid, content_hash char(64))');
        $connection->table('pg_temp.raw_material_revision_approval_events')->insert([
            'organization_id' => $material->organization_id,
            'revision_id' => $revision->id,
            'outcome' => 'APPROVED',
            'content_hash' => $revision->content_hash,
        ]);
        $connection->table('pg_temp.raw_material_revisions')->insert([
            'id' => $revision->id,
            'content_hash' => $revision->content_hash,
        ]);
        $connection->statement('SET LOCAL search_path = pg_temp, public');

        $this->assertRuntimeDenied(fn () => $connection->table('public.raw_materials')
            ->where('id', $materialId)
            ->update([
                'lifecycle_state' => 'ACTIVE',
                'approved_revision_id' => $revision->id,
                'pending_revision_id' => null,
            ]));

        $after = $connection->table('public.raw_materials')->where('id', $materialId)->first();
        $this->assertSame('VERIFICATION', $after->lifecycle_state);
        $this->assertNull($after->approved_revision_id);
        $this->assertSame(0, $connection->table('public.raw_material_revision_approval_events')
            ->where('revision_id', $revision->id)->where('outcome', 'APPROVED')->count());
        $this->assertSame(1, $connection->scalar(
            <<<'SQL'
                SELECT count(*)
                FROM pg_proc functions
                JOIN pg_namespace namespaces ON namespaces.oid = functions.pronamespace
                CROSS JOIN LATERAL unnest(functions.proconfig) AS settings
                WHERE namespaces.nspname = 'public'
                    AND functions.proname = 'raw_materials_preserve_master'
                    AND settings = 'search_path=pg_catalog, public, pg_temp'
                SQL,
        ));

        $connection->statement('SET LOCAL search_path = public, pg_temp');
        $connection->statement('DROP TABLE pg_temp.raw_material_revision_approval_events');
        $connection->statement('DROP TABLE pg_temp.raw_material_revisions');
    }

    private function assertRoleGrantTicketCannotBeReusedForRevocation(
        Connection $connection,
        string $actorId,
        string $otherActorId,
        string $roleAssignmentId,
    ): void {
        $this->assertRuntimeDenied(fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::roleGrant(BusinessRole::Administrator),
            $actorId,
            fn (): int => $connection->table('role_assignments')
                ->where('id', $roleAssignmentId)
                ->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revoked_by' => $actorId]),
        ));
        $this->assertRuntimeDenied(fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::roleRevoke(BusinessRole::Administrator),
            $actorId,
            fn (): int => $connection->table('role_assignments')
                ->where('id', $roleAssignmentId)
                ->update(['revoked_at' => DB::raw('clock_timestamp()'), 'revoked_by' => $otherActorId]),
        ));
        $this->assertNull($connection->table('role_assignments')->where('id', $roleAssignmentId)->value('revoked_at'));
    }

    private function assertNineTableRuntimeDmlMatrix(
        Connection $connection,
        string $organizationId,
        string $actorId,
        string $categoryId,
        string $materialId,
        string $supplierId,
    ): void {
        $material = $connection->table('raw_materials')->where('id', $materialId)->first();
        $revision = $connection->table('raw_material_revisions')
            ->where('raw_material_id', $materialId)->first();
        $unit = $connection->table('raw_material_revision_units')
            ->where('revision_id', $revision->id)->first();
        $alias = $connection->table('raw_material_aliases')
            ->where('revision_id', $revision->id)->first();

        $this->assertSame(1, $connection->table('public.raw_material_categories')
            ->where('id', $categoryId)->update(['name' => 'Nazwa zmieniona bez audytu', 'updated_at' => now()]));
        $counterBefore = (int) $connection->table('organization_sku_counters')
            ->where('organization_id', $organizationId)->where('prefix', 'RAW')->value('last_value');
        $this->assertRuntimeDenied(fn () => $connection->table('public.organization_sku_counters')
            ->where('organization_id', $organizationId)->where('prefix', 'RAW')
            ->update(['last_value' => DB::raw('last_value + 1'), 'updated_at' => now()]));
        $this->assertSame($counterBefore, (int) $connection->table('organization_sku_counters')
            ->where('organization_id', $organizationId)->where('prefix', 'RAW')->value('last_value'));

        $auditCountBefore = (int) $connection->table('raw_materials_commercial_audit_events')->count();
        $this->assertSame(1, $connection->table('public.organization_suppliers')
            ->where('id', $supplierId)->update(['display_name' => 'Nazwa zmieniona bez audytu', 'updated_at' => now()]));
        $this->assertSame($auditCountBefore, (int) $connection->table('raw_materials_commercial_audit_events')->count());

        $this->assertRuntimeDenied(fn () => $connection->table('public.raw_material_revisions')
            ->where('id', $revision->id)->update(['name' => 'Niedozwolona zmiana']));
        $this->assertRuntimeDenied(fn () => $connection->table('public.raw_material_revision_units')
            ->where('id', $unit->id)->update(['to_base_factor' => '2']));
        $this->assertRuntimeDenied(fn () => $connection->table('public.raw_material_aliases')
            ->where('id', $alias->id)->update(['alias' => 'Niedozwolony alias']));

        $workingRevisionId = $material->pending_revision_id;
        $this->assertSame(1, $connection->table('public.raw_materials')->where('id', $materialId)->update([
            'lifecycle_state' => 'DRAFT',
            'pending_revision_id' => null,
            'working_revision_id' => $workingRevisionId,
        ]));
        $newRevisionId = (string) Str::uuid();
        $connection->table('public.raw_materials')->where('id', $materialId)
            ->update(['working_revision_id' => $newRevisionId]);
        $this->assertTrue($connection->table('public.raw_material_revisions')->insert([
            'id' => $newRevisionId,
            'organization_id' => $organizationId,
            'raw_material_id' => $materialId,
            'revision_no' => 2,
            'category_id' => $categoryId,
            'name' => 'Rewizja dodana bez kontroli Laravel',
            'base_unit_code' => $revision->base_unit_code,
            'base_unit_dimension' => $revision->base_unit_dimension,
            'allergen_state' => $revision->allergen_state,
            'critical_change' => false,
            'created_by' => $actorId,
            'content_hash' => str_repeat('0', 64),
        ]));
        $this->assertTrue($connection->table('public.raw_material_revision_units')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'revision_id' => $newRevisionId,
            'unit_code' => 'pc',
            'dimension' => 'COUNT',
            'conversion_kind' => 'MATERIAL_SPECIFIC',
            'to_base_factor' => '1',
            'basis' => 'Przeliczenie testowe',
        ]));
        $this->assertTrue($connection->table('public.raw_material_aliases')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'raw_material_id' => $materialId,
            'revision_id' => $newRevisionId,
            'alias' => 'Alias dodany bez kontroli Laravel',
            'normalized_alias' => 'alias dodany bez kontroli laravel',
        ]));

        $productId = (string) Str::uuid();
        $this->assertSame(1, $connection->table('public.supplier_products')->insertOrIgnore([
            'id' => $productId,
            'organization_id' => $organizationId,
            'supplier_id' => $supplierId,
            'raw_material_id' => $materialId,
            'created_by' => $actorId,
        ]));
        $this->assertSame('ACTIVE', $connection->table('supplier_products')->where('id', $productId)
            ->value('lifecycle_state'));
        $this->assertRuntimeDenied(fn () => $connection->table('public.supplier_offers')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'supplier_product_id' => $productId,
            'created_by' => $actorId,
        ]));
    }

    private function assertRuntimeDenied(callable $operation): void
    {
        $connection = DB::connection($this->connectionName);
        $before = $this->protectedAuthorizationTableCounts($connection);

        try {
            $connection->transaction($operation);
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0] ?? null, ['42501', '22023', '23514']);
            $this->assertSame($before, $this->protectedAuthorizationTableCounts($connection));

            return;
        }

        $this->fail('PostgreSQL accepted an unsigned or out-of-scope runtime mutation.');
    }

    private function protectedAuthorizationTableCounts(Connection $connection): array
    {
        $tables = [
            'role_assignments',
            'location_memberships',
            'permission_grants',
            'organization_permission_grants',
            'organization_permission_grant_events',
            'raw_material_permission_grant_operation_uses',
            'raw_material_permission_upgrade_operations',
            'raw_material_audit_events',
            'raw_materials_commercial_audit_events',
            'supplier_offer_events',
            'raw_material_financial_restatements',
            'security_events',
        ];

        return array_combine($tables, array_map(
            fn (string $table): int => (int) $connection->scalar("SELECT count(*) FROM public.{$table}"),
            $tables,
        ));
    }

    private static function password(): string
    {
        return 'Strong-Test-Password-2026!';
    }
}
