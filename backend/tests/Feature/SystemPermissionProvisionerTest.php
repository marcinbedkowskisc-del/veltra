<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Authorization\SystemPermissionManifest;
use App\Domain\Authorization\SystemPermissionManifestRegistry;
use App\Domain\Authorization\SystemPermissionProvisioningException;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialPermissionBootstrap;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;
use Tests\Support\SystemPermissionProvisioningFixture;

class SystemPermissionProvisionerTest extends AuthorizationTestCase
{
    private User $administrator;

    private Location $primary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primary = $this->kitchen();
        $initial = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Fixture', 'last_name' => 'Administrator', 'email' => 'provisioning@example.test',
        ], self::PASSWORD);
        $this->administrator = $this->updateFixture($initial, ['email_verified_at' => now()]);
        app(MembershipService::class)->assign($this->administrator, $this->primary);
        app(RoleAdministration::class)->bootstrapInitial($this->administrator);
    }

    public function test_approved_execution_is_audited_atomic_and_idempotent(): void
    {
        $manifest = $this->manifest();
        $provisioner = SystemPermissionProvisioningFixture::provisioner($manifest);
        [$first, $second] = $this->asMigrator(function () use ($manifest, $provisioner): array {
            $approval = $provisioner->releaseDigest($manifest->id, $manifest->version);

            return [
                $provisioner->execute($manifest->id, $manifest->version, $approval),
                $provisioner->execute($manifest->id, $manifest->version, $approval),
            ];
        });

        $this->assertSame($first, $second);
        $grants = DB::table('organization_permission_grants')->where('system_provisioning_operation_id', $first)
            ->orderBy('permission')->get();
        $this->assertSame(['RAW_MATERIALS_CREATE', 'RAW_MATERIALS_VIEW'], $grants->pluck('permission')->all());
        foreach ($grants as $grant) {
            $this->assertSame('SYSTEM_PROVISIONING', $grant->grant_origin);
            $this->assertNull($grant->granted_by);
            $this->assertSame($manifest->id, $grant->system_manifest_id);
            $this->assertSame($manifest->version, $grant->system_manifest_version);
        }
        $audit = DB::table('raw_material_audit_events')->where('operation_id', $first)
            ->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')->where('outcome', 'ACCEPTED')->get();
        $this->assertCount(1, $audit);
        $this->assertNull($audit[0]->actor_user_id);
        $this->assertSame($manifest->contentHash(), json_decode($audit[0]->metadata, true, flags: JSON_THROW_ON_ERROR)['manifest_hash']);
        $this->assertTrue(app(RawMaterialAuthorization::class)->hasEffectiveGrant($this->administrator, RawMaterialPermission::View));
        $this->assertTrue(app(RawMaterialAuthorization::class)->hasEffectiveGrant($this->administrator, RawMaterialPermission::Create));
    }

    public function test_missing_approval_is_rejected_and_audited(): void
    {
        $manifest = $this->manifest();
        $provisioner = SystemPermissionProvisioningFixture::provisioner($manifest);
        try {
            $this->asMigrator(fn () => $provisioner->execute($manifest->id, $manifest->version, 'unapproved'));
            $this->fail('Unapproved execution must be rejected.');
        } catch (SystemPermissionProvisioningException) {
            $this->assertSame(0, DB::table('organization_permission_grants')->count());
            $this->assertSame(1, DB::table('raw_material_audit_events')
                ->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')->where('outcome', 'REJECTED')->count());
        }
    }

    public function test_wrong_installation_and_unregistered_manifest_are_rejected(): void
    {
        $manifest = $this->manifest(database: 'another_database');
        $provisioner = SystemPermissionProvisioningFixture::provisioner($manifest);
        try {
            $this->asMigrator(fn () => $provisioner->execute($manifest->id, $manifest->version, 'invalid'));
            $this->fail('Wrong installation must be rejected.');
        } catch (BusinessRuleViolation) {
            $this->assertSame(0, DB::table('organization_permission_grants')->count());
        }
        $this->expectException(SystemPermissionProvisioningException::class);
        $this->asMigrator(fn () => $provisioner->execute('unregistered', 1, 'invalid'));
    }

    public function test_runtime_database_role_cannot_execute_an_approved_manifest(): void
    {
        $manifest = $this->manifest();
        $provisioner = SystemPermissionProvisioningFixture::provisioner($manifest);
        $approval = $this->asMigrator(fn () => $provisioner->releaseDigest($manifest->id, $manifest->version));

        try {
            $provisioner->execute($manifest->id, $manifest->version, $approval);
            $this->fail('The runtime database role must not execute provisioning.');
        } catch (SystemPermissionProvisioningException) {
            $this->assertSame(0, DB::table('organization_permission_grants')->count());
            $this->assertSame(0, $this->asMigrator(fn () => DB::table('system_permission_provisioning_operations')->count()));
        }
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $base = $this->manifest();
        $manifest = new SystemPermissionManifest(
            id: $base->id, version: $base->version, module: $base->module, permissions: $base->permissions,
            eligibleRole: $base->eligibleRole, grantScope: 'LOCATION', applicability: $base->applicability,
            databaseName: $base->databaseName, organizationId: $base->organizationId,
            administratorId: $base->administratorId, locationId: $base->locationId,
        );

        $this->expectException(SystemPermissionProvisioningException::class);
        $this->asMigrator(fn () => SystemPermissionProvisioningFixture::provisioner($manifest)
            ->releaseDigest($manifest->id, $manifest->version));
    }

    public function test_modified_content_under_the_same_version_is_rejected(): void
    {
        $manifest = $this->manifest();
        $this->executeApproved($manifest);
        $changed = $this->manifest(permissions: [RawMaterialPermission::View]);
        $provisioner = SystemPermissionProvisioningFixture::provisioner($changed);

        try {
            $this->asMigrator(function () use ($changed, $provisioner): void {
                $approval = $provisioner->releaseDigest($changed->id, $changed->version);
                $provisioner->execute($changed->id, $changed->version, $approval);
            });
            $this->fail('Modified contents must be rejected.');
        } catch (SystemPermissionProvisioningException) {
            $this->assertSame(2, DB::table('organization_permission_grants')->count());
            $this->assertSame(1, DB::table('raw_material_audit_events')
                ->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')->where('outcome', 'REJECTED')->count());
        }
    }

    public function test_approval_is_bound_to_the_exact_manifest_content(): void
    {
        $original = $this->manifest();
        $changed = $this->manifest(permissions: [RawMaterialPermission::View]);
        $provisioner = SystemPermissionProvisioningFixture::provisioner($changed);
        $approval = $this->asMigrator(fn () => SystemPermissionProvisioningFixture::provisioner($original)
            ->releaseDigest($original->id, $original->version));

        try {
            $this->asMigrator(fn () => $provisioner->execute($changed->id, $changed->version, $approval));
            $this->fail('Approval of different manifest contents must be rejected.');
        } catch (SystemPermissionProvisioningException) {
            $this->assertSame(0, DB::table('organization_permission_grants')->count());
            $this->assertSame(1, DB::table('raw_material_audit_events')
                ->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')->where('outcome', 'REJECTED')->count());
        }
    }

    public function test_retry_does_not_restore_a_revoked_system_grant(): void
    {
        $manifest = $this->manifest();
        $operation = $this->executeApproved($manifest);
        $grant = DB::table('organization_permission_grants')->where('system_provisioning_operation_id', $operation)
            ->where('permission', RawMaterialPermission::View->value)->first();
        $this->asMigrator(fn () => DB::table('organization_permission_grant_events')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $manifest->organizationId,
            'grant_id' => $grant->id, 'event_type' => 'REVOKED', 'actor_user_id' => $this->administrator->id,
            'reason_code' => 'FIXTURE',
        ]));

        $this->assertSame($operation, $this->executeApproved($manifest));
        $this->assertFalse(app(RawMaterialAuthorization::class)->hasEffectiveGrant($this->administrator, RawMaterialPermission::View));
        $this->assertTrue(app(RawMaterialAuthorization::class)->hasEffectiveGrant($this->administrator, RawMaterialPermission::Create));
    }

    public function test_recovery_grants_only_the_pinned_acceptance_permissions_and_is_not_replayed(): void
    {
        $steward = $this->user('recovery-steward@example.test');
        app(MembershipService::class)->assign($steward, $this->primary, $this->administrator->id);
        app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $this->administrator->id, $steward->id,
            [RawMaterialPermission::PermissionDelegate->value], 'Opiekun testowy', self::PASSWORD,
        );
        $this->executeApproved($this->manifest());
        $manifest = $this->recoveryManifest();
        $operation = $this->executeApproved($manifest);

        $expected = array_map(fn (RawMaterialPermission $permission) => $permission->value, $manifest->permissions);
        sort($expected);
        $grants = DB::table('organization_permission_grants')->where('system_provisioning_operation_id', $operation)
            ->orderBy('permission')->pluck('permission')->all();
        $this->assertSame($expected, $grants);
        $this->assertFalse(app(RawMaterialAuthorization::class)
            ->hasEffectiveGrant($this->administrator, RawMaterialPermission::PermissionDelegate));
        $audit = DB::table('raw_material_audit_events')->where('operation_id', $operation)
            ->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')->where('outcome', 'ACCEPTED')->sole();
        $metadata = json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($audit->actor_user_id);
        $this->assertSame('SYSTEM_PROVISIONER', $metadata['actor_type']);
        $this->assertSame('veltra_migrator', $metadata['actor_database_role']);
        $this->assertNotEmpty($metadata['reason']);
        $this->assertSame('ORGANIZATION', $metadata['grant_scope']);
        $this->assertSame($this->administrator->id, $metadata['recipient_user_id']);
        $this->assertSame($this->primary->id, $metadata['location_id']);
        $this->assertEqualsCanonicalizing($expected, $metadata['permissions']);
        $this->assertNotNull($audit->occurred_at);

        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->administrator->organization_id, 'location_id' => $this->primary->id,
            'module_key' => 'RAW_MATERIALS', 'enabled' => true,
            'created_by' => $this->administrator->id, 'updated_by' => $this->administrator->id,
        ]);
        $this->actingAs($this->administrator, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->primary->id,
        ])->assertOk();
        $this->getJson('/api/v1/security/raw-materials/capabilities')->assertOk()
            ->assertJsonPath('data.actions.edit', true)
            ->assertJsonPath('data.actions.manage_categories', true)
            ->assertJsonPath('data.actions.view_reference_price', true)
            ->assertJsonPath('data.actions.manage_reference_price', true)
            ->assertJsonPath('data.actions.view_usable_cost', true)
            ->assertJsonPath('data.actions.create_and_approve', true);
        $input = [
            'base_unit_code' => 'g', 'nominal_quantity' => '2800', 'usable_quantity' => '1500',
            'pricing' => ['basis' => 'PACKAGE', 'amount' => '28'],
        ];
        $this->postJson('/api/v1/raw-materials/pricing-preview', $input)->assertOk()
            ->assertJsonPath('data.net_pln_per_package', '28.00000000000000')
            ->assertJsonPath('data.usable_unit_cost', '1.86666666666667');
        $input['pricing'] = ['basis' => 'NOMINAL_UNIT', 'amount' => '10', 'unit_code' => 'kg'];
        $this->postJson('/api/v1/raw-materials/pricing-preview', $input)->assertOk()
            ->assertJsonPath('data.net_pln_per_package', '28.00000000000000')
            ->assertJsonPath('data.usable_unit_cost', '1.86666666666667');

        $grantId = DB::table('organization_permission_grants')->where('system_provisioning_operation_id', $operation)
            ->where('permission', RawMaterialPermission::Edit->value)->value('id');
        $this->asMigrator(fn () => DB::table('organization_permission_grant_events')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $manifest->organizationId,
            'grant_id' => $grantId, 'event_type' => 'REVOKED', 'actor_user_id' => $this->administrator->id,
            'reason_code' => 'FIXTURE',
        ]));
        $this->role($steward, BusinessRole::Administrator);
        $this->assertSame($operation, $this->executeApproved($manifest));
        $this->assertFalse(app(RawMaterialAuthorization::class)
            ->hasEffectiveGrant($this->administrator, RawMaterialPermission::Edit));
        $this->assertSame(1, DB::table('raw_material_audit_events')->where('operation_id', $operation)->count());
    }

    public function test_recovery_does_not_treat_the_recipient_as_a_delegating_administrator(): void
    {
        $this->executeApproved($this->manifest(permissions: [RawMaterialPermission::PermissionDelegate]));
        $operation = $this->executeApproved($this->recoveryManifest());

        $this->assertSame(7, DB::table('organization_permission_grants')
            ->where('system_provisioning_operation_id', $operation)->count());
    }

    public function test_recovery_refuses_an_eligible_delegating_administrator(): void
    {
        $other = $this->user('other-delegating-admin@example.test');
        app(MembershipService::class)->assign($other, $this->primary, $this->administrator->id);
        $this->role($other, BusinessRole::Administrator);
        app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $this->administrator->id, $other->id,
            [RawMaterialPermission::PermissionDelegate->value], 'Delegacja testowa', self::PASSWORD,
        );

        try {
            $this->executeApproved($this->recoveryManifest());
            $this->fail('Recovery must not bypass an eligible delegating administrator.');
        } catch (SystemPermissionProvisioningException) {
            $this->assertSame(0, DB::table('organization_permission_grants')
                ->where('grant_origin', 'SYSTEM_PROVISIONING')->count());
            $this->assertSame(1, DB::table('raw_material_audit_events')
                ->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')->where('outcome', 'REJECTED')->count());
        }
    }

    public function test_registered_recovery_manifest_is_bound_to_the_original_stage_target(): void
    {
        $registry = new SystemPermissionManifestRegistry;
        $original = $registry->find('stage6a1-raw-administrator', 1);
        $recovery = $registry->find('stage6a1-raw-administrator', 2);
        $this->assertNotNull($original);
        $this->assertNotNull($recovery);
        $this->assertSame('RECOVERY', $recovery->applicability);
        $this->assertSame($original->databaseName, $recovery->databaseName);
        $this->assertSame($original->organizationId, $recovery->organizationId);
        $this->assertSame($original->administratorId, $recovery->administratorId);
        $this->assertSame($original->locationId, $recovery->locationId);
        $this->assertSame([
            RawMaterialPermission::Edit, RawMaterialPermission::CategoryManage,
            RawMaterialPermission::ReferencePriceView, RawMaterialPermission::ReferencePriceManage,
            RawMaterialPermission::AllergenManage, RawMaterialPermission::Submit, RawMaterialPermission::Approve,
        ], $recovery->permissions);
        $this->assertNull($registry->find('stage6a1-raw-administrator', 3));
        $this->assertNull($registry->find('other', 2));
    }

    public function test_command_selects_the_recovery_manifest_without_shadowing_the_framework_version_flag(): void
    {
        $this->artisan('veltra:provision-stage6a1-raw-permissions', [
            '--manifest-version' => '2', '--show-release' => true,
        ])->expectsOutputToContain('wersja: 2')->assertExitCode(1);
        $this->artisan('veltra:provision-stage6a1-raw-permissions', [
            '--manifest-version' => '3', '--show-release' => true,
        ])->expectsOutput('Nieznana wersja manifestu.')->assertExitCode(1);
    }

    public function test_wrong_administrator_and_invalid_permissions_are_rejected(): void
    {
        $other = $this->user('not-administrator@example.test');
        $manifest = $this->manifest(administrator: $other->id);
        try {
            $this->executeApproved($manifest);
            $this->fail('A user without the administrator role must not receive the grants.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('organization_permission_grants')->count());
        }

        $invalid = $this->manifest(permissions: ['UNREGISTERED_PERMISSION']);
        $this->expectException(SystemPermissionProvisioningException::class);
        $this->asMigrator(fn () => SystemPermissionProvisioningFixture::provisioner($invalid)->releaseDigest($invalid->id, $invalid->version));
    }

    public function test_cross_organization_location_is_rejected(): void
    {
        $this->withForeignOrganizationFixture(function (Location $foreign): void {
            $base = $this->manifest();
            $manifest = new SystemPermissionManifest(
                id: $base->id, version: $base->version, module: $base->module, permissions: $base->permissions,
                eligibleRole: $base->eligibleRole, grantScope: $base->grantScope, applicability: $base->applicability,
                databaseName: $base->databaseName, organizationId: $base->organizationId,
                administratorId: $base->administratorId, locationId: $foreign->id,
            );
            try {
                $this->executeApproved($manifest);
                $this->fail('A foreign organization location must be rejected.');
            } catch (AuthorizationException) {
                $this->assertSame(0, DB::table('organization_permission_grants')->count());
            }
        });
    }

    public function test_concurrent_retries_serialize_to_one_operation(): void
    {
        $manifest = $this->manifest();
        $setup = $this->setupConnection();
        $setup->beginTransaction();
        $setup->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            'system-permission:'.$manifest->id.':'.$manifest->version,
        ]);
        $workers = [];
        try {
            foreach ([1, 2] as $number) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY, base_path('tests/Support/system_permission_provisioner_worker.php'),
                    $manifest->id, $manifest->organizationId, $manifest->administratorId,
                    $manifest->locationId, 'veltra-provision-fixture-'.$number,
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $waiting = 0;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $setup->select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) $setup->scalar(<<<'SQL'
                    SELECT count(*) FROM pg_stat_activity
                    WHERE application_name IN ('veltra-provision-fixture-1', 'veltra-provision-fixture-2')
                        AND wait_event_type = 'Lock'
                    SQL);
                if ($waiting === 2) {
                    break;
                }
                usleep(100000);
            }
        } finally {
            $setup->rollBack();
        }

        $operations = [];
        foreach ($workers as [$process, $pipes]) {
            $output = trim(stream_get_contents($pipes[1]));
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $operations[] = $output;
        }
        $this->assertSame(2, $waiting, 'Both workers must contend for the same transaction lock.');
        $this->assertSame($operations[0], $operations[1]);
        $this->assertSame(2, DB::table('organization_permission_grants')->where('system_provisioning_operation_id', $operations[0])->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('operation_id', $operations[0])->where('outcome', 'ACCEPTED')->count());
    }

    public function test_failed_second_grant_rolls_back_first_grant_operation_and_accepted_audit(): void
    {
        $other = $this->user('grantor@example.test');
        $this->asMigrator(function () use ($other): void {
            DB::transaction(function () use ($other): void {
                $operation = (string) Str::uuid();
                DB::table('raw_material_audit_events')->insert([
                    'id' => (string) Str::uuid(), 'organization_id' => $this->administrator->organization_id,
                    'operation_id' => $operation, 'actor_user_id' => $other->id, 'subject_type' => 'ORGANIZATION',
                    'event_code' => 'PERMISSION_BOOTSTRAP', 'outcome' => 'ACCEPTED',
                    'metadata' => json_encode([
                        'recipient_user_id' => $this->administrator->id, 'permissions' => ['RAW_MATERIALS_CREATE'],
                    ], JSON_THROW_ON_ERROR),
                ]);
                DB::table('organization_permission_grants')->insert([
                    'id' => (string) Str::uuid(), 'organization_id' => $this->administrator->organization_id,
                    'user_id' => $this->administrator->id, 'permission' => 'RAW_MATERIALS_CREATE',
                    'granted_by' => $other->id, 'valid_from' => now(), 'bootstrap_operation_id' => $operation,
                    'reason_code' => 'FIXTURE',
                ]);
            });
        });

        try {
            $this->executeApproved($this->manifest());
            $this->fail('The conflicting second grant must abort the whole operation.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('organization_permission_grants')->where('grant_origin', 'SYSTEM_PROVISIONING')->count());
            $this->assertSame(0, $this->asMigrator(fn () => DB::table('system_permission_provisioning_operations')->count()));
            $this->assertSame(0, DB::table('raw_material_audit_events')->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')->where('outcome', 'ACCEPTED')->count());
            $this->assertSame(1, DB::table('raw_material_audit_events')->where('event_code', 'SYSTEM_PERMISSION_PROVISIONING')->where('outcome', 'REJECTED')->count());
        }
    }

    private function executeApproved(SystemPermissionManifest $manifest): string
    {
        $provisioner = SystemPermissionProvisioningFixture::provisioner($manifest);

        return $this->asMigrator(function () use ($manifest, $provisioner): string {
            return $provisioner->execute(
                $manifest->id, $manifest->version, $provisioner->releaseDigest($manifest->id, $manifest->version),
            );
        });
    }

    private function manifest(
        ?string $administrator = null,
        string $database = 'veltra_testing',
        array $permissions = [RawMaterialPermission::View, RawMaterialPermission::Create],
    ): SystemPermissionManifest {
        return new SystemPermissionManifest(
            id: 'synthetic-raw-'.str_replace('-', '', $this->administrator->id),
            version: 1, module: 'RAW_MATERIALS', permissions: $permissions,
            eligibleRole: BusinessRole::Administrator, grantScope: 'ORGANIZATION', applicability: 'UPGRADE',
            databaseName: $database, organizationId: $this->administrator->organization_id,
            administratorId: $administrator ?? $this->administrator->id, locationId: $this->primary->id,
        );
    }

    private function recoveryManifest(): SystemPermissionManifest
    {
        $base = $this->manifest();

        return new SystemPermissionManifest(
            id: $base->id, version: 2, module: $base->module,
            permissions: SystemPermissionManifest::stage6a1RawAcceptanceRecovery()->permissions,
            eligibleRole: $base->eligibleRole, grantScope: $base->grantScope, applicability: 'RECOVERY',
            databaseName: $base->databaseName, organizationId: $base->organizationId,
            administratorId: $base->administratorId, locationId: $base->locationId,
        );
    }

    private function asMigrator(callable $operation): mixed
    {
        $this->setupConnection();
        DB::setDefaultConnection('pgsql_setup');
        try {
            return $operation();
        } finally {
            DB::setDefaultConnection('pgsql');
        }
    }
}
