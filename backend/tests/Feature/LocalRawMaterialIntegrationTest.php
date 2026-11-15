<?php

namespace Tests\Feature;

use App\Console\Commands\ConfigureStage6a1Raw;
use App\Domain\Authorization\RoleAdministration;
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
            'development.management_enabled' => false, 'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit',
            'mail.mailers.smtp.port' => 1025, 'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => null, 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null,
        ]);
        $this->instance = new LocalRawInstanceFixture;
        $this->instance->identity = [
            'compose_project' => 'veltra-stage6a1', 'postgres_volume' => 'veltra-stage6a1_postgres-data',
            'container_hostname' => gethostname(), 'expires_at' => now()->addHours(2)->timestamp,
            'organization_id' => $this->administrator->organization_id, 'user_id' => $this->administrator->id,
            'database_oid' => '16384', 'server_address' => gethostbyname('postgres'),
            'mailpit_address' => gethostbyname('mailpit'), 'raw_integration_enabled' => true,
        ];
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

    private function assertRejected(): void
    {
        try {
            $this->instance->rawIntegrationEnabled();
            $this->fail('Niepoprawna instancja nie może włączyć RAW.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function test_default_off_and_removal_are_observed_by_the_same_catalog_instance(): void
    {
        $catalog = app(ModuleCatalog::class);
        $identity = $this->instance->identity;
        $this->instance->identity = null;
        $this->assertFalse($catalog->find('RAW_MATERIALS')->ready);
        $this->instance->identity = $identity;
        $this->assertTrue($catalog->find('RAW_MATERIALS')->ready);
        foreach ((new DefinedModuleCatalog)->all() as $key => $definition) {
            if ($key !== 'RAW_MATERIALS') {
                $this->assertEquals($definition, $catalog->find($key));
            }
        }
        $this->assertFalse((new DefinedModuleCatalog)->find('RAW_MATERIALS')->ready);
        $this->instance->identity = null;
        $this->assertFalse($catalog->find('RAW_MATERIALS')->ready);
    }

    public function test_production_staging_testing_and_noncontainer_instances_cannot_opt_in(): void
    {
        foreach (['production', 'staging', 'testing'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            $this->assertRejected();
        }
        $this->app->detectEnvironment(fn () => 'local');
        $this->instance->container = false;
        $this->assertRejected();
    }

    public function test_wrong_attestation_database_network_and_expiration_are_rejected(): void
    {
        $original = $this->instance->identity;
        foreach ([
            'compose_project' => 'veltra', 'postgres_volume' => 'veltra_postgres-data',
            'container_hostname' => 'other', 'database_oid' => '9999', 'server_address' => '192.0.2.1',
            'mailpit_address' => '192.0.2.1', 'organization_id' => '00000000-0000-4000-8000-000000000001',
            'user_id' => '00000000-0000-4000-8000-000000000002', 'raw_integration_enabled' => false,
            'expires_at' => now()->subSecond()->timestamp,
        ] as $key => $value) {
            $this->instance->identity = array_replace($original, [$key => $value]);
            $this->assertRejected();
        }
        $this->instance->identity = $original;
        $this->travel(3)->hours();
        $this->assertRejected();
        $this->travelBack();
        foreach (['configured_name', 'name', 'role', 'oid', 'server_address', 'server_port'] as $key) {
            $before = $this->instance->database->$key;
            $this->instance->database->$key = 'unapproved';
            $this->assertRejected();
            $this->instance->database->$key = $before;
        }
        config(['mail.mailers.smtp.url' => 'smtp://remote.example:1025']);
        $this->assertRejected();
    }

    public function test_missing_writable_symlinked_and_invalid_attestations_are_rejected(): void
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
            file_put_contents($path, json_encode($this->instance->identity));
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
        $this->instance->identity = null;
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
    public ?array $identity = null;

    public object $database;

    public bool $container = true;

    protected function rawIdentity(): ?array
    {
        return $this->identity;
    }

    protected function insideContainer(): bool
    {
        return $this->container;
    }

    public function databaseIdentity(): object
    {
        return $this->database;
    }
}
