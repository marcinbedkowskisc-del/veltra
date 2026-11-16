<?php

namespace Tests\Feature;

use App\Console\Commands\ConfigureStage6a1Raw;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Authorization\SystemPermissionManifest;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocalStage6a1Instance;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\Modules\LocalRawMaterialCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialPermissionBootstrap;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\AuthorizationTestCase;

class LocalRawMaterialIntegrationTest extends AuthorizationTestCase
{
    private LocalRawInstanceFixture $instance;

    private User $administrator;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Local', 'last_name' => 'Fixture', 'email' => 'owner-fixture@example.test',
        ], self::PASSWORD);
        $this->location = $this->kitchen('PRIMARY_TEST_KITCHEN');
        app(RoleAdministration::class)->bootstrapInitial($this->administrator);
        app(MembershipService::class)->assign($this->administrator, $this->location);
        $this->administrator = $this->updateFixture($this->administrator, ['email_verified_at' => now()]);
        $this->updateFixture($this->administrator->organization, ['authorization_initialized' => true]);
        $this->app->detectEnvironment(fn () => 'local');
        config([
            'app.url' => 'http://localhost:8001', 'app.frontend_url' => 'http://localhost:5174',
            'app.local_stage_project' => 'veltra-stage6a1',
            'development.management_enabled' => false, 'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit',
            'mail.mailers.smtp.port' => 1025, 'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => null, 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null,
        ]);
        $this->instance = new LocalRawInstanceFixture;
        $this->instance->database = (object) [
            'configured_name' => 'veltra_stage6a1', 'name' => 'veltra_stage6a1', 'role' => 'veltra_app',
            'oid' => '16384', 'server_address' => gethostbyname('postgres'), 'server_port' => 5432,
        ];
        $this->app->instance(LocalStage6a1Instance::class, $this->instance);
        $this->app->instance(ModuleCatalog::class, new LocalRawMaterialCatalog($this->instance));
        $this->withSession(['_token' => 'local-raw-fixture-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'local-raw-fixture-csrf');
    }

    private function selectContext(User $actor, Location $location): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $response = $this->withSession(['_token' => 'local-raw-fixture-csrf'])
            ->actingAs($actor->fresh(), 'web')
            ->putJson('/api/v1/auth/operating-location', [
                'location_id' => $location->id,
            ]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }

    private function configure(array $answers = ['yes', self::PASSWORD], bool $interactive = true): CommandTester
    {
        $command = new ConfigureStage6a1Raw;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->setInputs($answers);
        $tester->execute([], ['interactive' => $interactive]);

        return $tester;
    }

    public function test_only_local_stage_enables_raw_without_an_acceptance_credential(): void
    {
        $catalog = app(ModuleCatalog::class);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse($catalog->find('RAW_MATERIALS')->ready);
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertTrue($catalog->find('RAW_MATERIALS')->ready);
        foreach ((new DefinedModuleCatalog)->all() as $key => $definition) {
            if ($key !== 'RAW_MATERIALS') {
                $this->assertEquals($definition, $catalog->find($key));
            }
        }
        $this->assertFalse((new DefinedModuleCatalog)->find('RAW_MATERIALS')->ready);
        $this->app->detectEnvironment(fn () => 'testing');
        $this->assertFalse($catalog->find('RAW_MATERIALS')->ready);
    }

    public function test_production_staging_testing_and_noncontainer_instances_cannot_opt_in(): void
    {
        foreach (['production', 'staging', 'testing'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            $this->assertFalse($this->instance->rawIntegrationEnabled());
        }
        $this->app->detectEnvironment(fn () => 'local');
        $this->instance->container = false;
        $this->assertFalse($this->instance->rawIntegrationEnabled());
    }

    public function test_wrong_database_or_stage_urls_do_not_enable_raw(): void
    {
        foreach (['configured_name', 'name', 'role', 'server_address', 'server_port'] as $key) {
            $before = $this->instance->database->$key;
            $this->instance->database->$key = 'unapproved';
            $this->assertFalse($this->instance->rawIntegrationEnabled());
            $this->instance->database->$key = $before;
        }
        config(['app.url' => 'http://localhost:8000']);
        $this->assertFalse($this->instance->rawIntegrationEnabled());
        config(['app.url' => 'http://localhost:8001', 'app.local_stage_project' => null]);
        $this->assertFalse($this->instance->rawIntegrationEnabled());
    }

    public function test_provisioning_target_uses_local_database_and_manifest_without_raw_credential(): void
    {
        $manifest = new SystemPermissionManifest(
            id: 'synthetic-stage', version: 1, module: 'RAW_MATERIALS',
            permissions: [RawMaterialPermission::View], eligibleRole: BusinessRole::Administrator,
            grantScope: 'ORGANIZATION', applicability: 'UPGRADE', databaseName: 'veltra_stage6a1',
            organizationId: $this->administrator->organization_id, administratorId: $this->administrator->id,
            locationId: $this->location->id,
        );
        $this->instance->database->role = 'veltra_migrator';
        $this->assertSame([
            'database_oid' => '16384', 'server_address' => gethostbyname('postgres'),
        ], $this->instance->verifyProvisioningTarget($manifest));
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(BusinessRuleViolation::class);
        $this->instance->verifyProvisioningTarget($manifest);
    }

    public function test_recovery_identity_reader_still_rejects_invalid_files(): void
    {
        $instance = new LocalStage6a1Instance;
        $directory = sys_get_temp_dir().'/veltra-raw-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $path = $directory.'/identity.json';
        try {
            foreach ([null, '[]', 'invalid-json'] as $contents) {
                if ($contents !== null) {
                    file_put_contents($path, $contents);
                    chmod($path, 0444);
                }
                try {
                    $instance->readIdentity($path);
                    $this->fail('Niepoprawny plik poświadczenia zaakceptowano.');
                } catch (BusinessRuleViolation $exception) {
                    $this->assertNotSame('', $exception->getMessage());
                }
                if (is_file($path)) {
                    chmod($path, 0644);
                }
            }
            file_put_contents($path, '{"synthetic":true}');
            try {
                $instance->readIdentity($path);
                $this->fail('Zapisywalne poświadczenie zaakceptowano.');
            } catch (BusinessRuleViolation $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            symlink($path, $directory.'/link.json');
            try {
                $instance->readIdentity($directory.'/link.json');
                $this->fail('Dowiązanie zaakceptowano.');
            } catch (BusinessRuleViolation $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        } finally {
            if (is_link($directory.'/link.json')) {
                unlink($directory.'/link.json');
            }
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_ready_still_requires_standard_configuration_authentication_and_effective_grants(): void
    {
        $this->getJson('/api/v1/raw-materials')->assertUnauthorized();
        $this->selectContext($this->administrator, $this->location);
        $this->actingAs($this->administrator, 'web')->getJson('/api/v1/raw-materials')->assertStatus(503);
        $tester = $this->configure();
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
        $this->assertDatabaseHas('location_module_states', [
            'location_id' => $this->location->id, 'module_key' => 'RAW_MATERIALS', 'enabled' => true,
        ]);
        $this->actingAs($this->administrator, 'web')->getJson('/api/v1/raw-materials')->assertForbidden();
        $steward = $this->user('raw.steward@veltra.test');
        app(MembershipService::class)->assign($steward, $this->location, $this->administrator->id);
        app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $this->administrator->id, $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Lokalny test integracyjny', self::PASSWORD,
        );
        $this->selectContext($steward, $this->location);
        $this->actingAs($steward->fresh(), 'web')->getJson('/api/v1/raw-materials')->assertOk();
        $this->postJson('/api/v1/raw-materials', [
            'category_id' => $this->location->id, 'name' => 'Niedozwolony zapis', 'base_unit_code' => 'g',
        ])->assertForbidden();
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/api/v1/raw-materials')->assertStatus(503);
    }

    public function test_configuration_requires_confirmation_password_and_preserves_audited_idempotency(): void
    {
        $this->assertSame(1, $this->configure([], false)->getStatusCode());
        $this->assertSame(1, $this->configure(['no'])->getStatusCode());
        $this->assertSame(1, $this->configure(['yes', 'wrong-password'])->getStatusCode());
        $this->assertDatabaseCount('location_module_states', 0);
        $this->assertSame(0, $this->configure()->getStatusCode());
        $this->assertSame(0, $this->configure()->getStatusCode());
        $this->assertDatabaseCount('location_module_states', 1);
        $this->assertTrue(DB::table('security_events')->where('action', 'module.configure')->exists());
    }
}

class LocalRawInstanceFixture extends LocalStage6a1Instance
{
    public object $database;

    public bool $container = true;

    protected function insideContainer(): bool
    {
        return $this->container;
    }

    public function databaseIdentity(): object
    {
        return $this->database;
    }
}
