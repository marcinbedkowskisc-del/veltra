<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialCapabilitiesApiTest extends AuthorizationTestCase
{
    private Location $location;

    private User $administrator;

    private User $steward;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->location = $this->primaryCentralKitchen();
        $this->administrator = $this->user('capabilities-administrator@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->location, $this->administrator->id);
        $this->steward = $this->user('capabilities-steward@example.test');
        app(MembershipService::class)->assign($this->steward, $this->location);
        $this->updateFixture($this->steward->organization, ['authorization_initialized' => true]);
        $this->bootstrapFixtureGrants();
    }

    public function test_capabilities_reports_effective_create_permission_when_module_is_not_ready(): void
    {
        $this->selectContext($this->steward, $this->location);
        $before = [
            'grants' => DB::table('organization_permission_grants')->count(),
            'audit' => DB::table('raw_material_audit_events')->count(),
            'materials' => DB::table('raw_materials')->count(),
        ];

        $response = $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities');

        $response->assertOk()
            ->assertJsonPath('data.module', 'RAW_MATERIALS')
            ->assertJsonPath('data.location_id', $this->location->id)
            ->assertJsonPath('data.readiness', 'NOT_READY')
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.permissions.create', true)
            ->assertJsonPath('data.actions.create', false);
        $this->assertSame(
            app(RawMaterialAuthorization::class)->hasEffectiveGrant($this->steward, RawMaterialPermission::Create),
            $response->json('data.permissions.create'),
        );
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($before, [
            'grants' => DB::table('organization_permission_grants')->count(),
            'audit' => DB::table('raw_material_audit_events')->count(),
            'materials' => DB::table('raw_materials')->count(),
        ]);
    }

    public function test_create_action_requires_ready_enabled_module_and_primary_catalog_context(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        $this->enableRawMaterials($this->location);
        $this->selectContext($this->steward, $this->location);

        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.readiness', 'READY')
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.permissions.create', true)
            ->assertJsonPath('data.actions.create', true);

        $secondaryKitchen = $this->kitchen('CK2');
        app(MembershipService::class)->assign($this->steward, $secondaryKitchen);
        $this->enableRawMaterials($secondaryKitchen);
        $this->selectContext($this->steward, $secondaryKitchen);

        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.location_id', $secondaryKitchen->id)
            ->assertJsonPath('data.permissions.create', true)
            ->assertJsonPath('data.actions.create', false);
    }

    public function test_ready_module_readiness_is_separate_from_location_availability(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        $this->selectContext($this->steward, $this->location);

        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.readiness', 'READY')
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.permissions.create', true)
            ->assertJsonPath('data.actions.create', false);
    }

    public function test_view_without_create_does_not_expose_create_capability(): void
    {
        $this->prepareReadyModule();
        $viewOnly = $this->newRecipient('capabilities-view-only@example.test');
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $viewOnly,
            RawMaterialPermission::View,
            'Grant VIEW bez CREATE do testu capabilities',
        );
        $this->selectContext($viewOnly, $this->location);

        $response = $this->actingAs($viewOnly, 'web')->getJson('/api/v1/security/raw-materials/capabilities');

        $response->assertOk()
            ->assertJsonPath('data.permissions.create', false)
            ->assertJsonPath('data.actions.create', false);
        $this->assertFalse(app(RawMaterialAuthorization::class)
            ->hasEffectiveGrant($viewOnly, RawMaterialPermission::Create));
    }

    public function test_missing_effective_create_grant_is_reported_without_revealing_an_action(): void
    {
        $operator = $this->user('capabilities-no-grant@example.test');
        app(MembershipService::class)->assign($operator, $this->location);
        $this->selectContext($operator, $this->location);

        $this->actingAs($operator, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', false)
            ->assertJsonPath('data.actions.create', false);
    }

    public function test_future_create_grant_is_not_effective(): void
    {
        $this->prepareReadyModule();
        $recipient = $this->newRecipient('capabilities-future@example.test');
        $this->delegateCreate($recipient, now()->addDay()->toISOString());
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', false)
            ->assertJsonPath('data.actions.create', false);
    }

    public function test_expired_create_grant_is_not_effective(): void
    {
        $this->prepareReadyModule();
        $recipient = $this->newRecipient('capabilities-expired@example.test');
        $grantStarts = DB::table('organization_permission_grants')
            ->where('organization_id', $this->steward->organization_id)
            ->where('user_id', $this->steward->id)
            ->whereIn('permission', [
                RawMaterialPermission::Create->value,
                RawMaterialPermission::PermissionDelegate->value,
            ])
            ->pluck('valid_from')
            ->map(static fn ($date) => Carbon::parse($date));
        $validFrom = $grantStarts->max();
        $validUntil = $validFrom->copy()->addMicrosecond();
        $this->assertTrue($validUntil->isPast());
        $this->delegateCreate($recipient, $validFrom->toISOString(), $validUntil->toISOString());
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', false)
            ->assertJsonPath('data.actions.create', false);
    }

    public function test_delegated_create_capability_disappears_after_revocation(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        $this->enableRawMaterials($this->location);
        $recipient = $this->user('capabilities-delegatee@example.test');
        app(MembershipService::class)->assign($recipient, $this->location);
        $permissions = app(OrganizationRawMaterialPermissionAdministration::class);
        $delegated = $permissions->grant(
            $this->steward,
            $this->location,
            $recipient,
            RawMaterialPermission::Create,
            'Delegowanie CREATE do testu capabilities',
        );
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', true)
            ->assertJsonPath('data.actions.create', true);

        $permissions->revoke(
            $this->steward,
            $this->location,
            $delegated['id'],
            'Cofnięcie CREATE w teście capabilities',
        );
        $this->selectContext($recipient, $this->location);
        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', false)
            ->assertJsonPath('data.actions.create', false);
    }

    public function test_delegated_create_disappears_when_its_source_grant_is_revoked(): void
    {
        $this->prepareReadyModule();
        $recipient = $this->newRecipient('capabilities-revoked-source@example.test');
        $this->delegateCreate($recipient);
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', true)
            ->assertJsonPath('data.actions.create', true);

        $sourceGrantId = DB::table('organization_permission_grants')
            ->where('organization_id', $this->steward->organization_id)
            ->where('user_id', $this->steward->id)
            ->where('permission', RawMaterialPermission::Create->value)
            ->value('id');
        app(OrganizationRawMaterialPermissionAdministration::class)->revoke(
            $this->steward,
            $this->location,
            $sourceGrantId,
            'Cofnięcie źródła delegowanego CREATE',
        );
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', false)
            ->assertJsonPath('data.actions.create', false);
    }

    public function test_requested_foreign_location_cannot_override_the_session_context(): void
    {
        $this->selectContext($this->steward, $this->location);

        $this->withForeignOrganizationFixture(function (Location $foreignLocation): void {
            $this->actingAs($this->steward, 'web')
                ->getJson('/api/v1/security/raw-materials/capabilities?location_id='.$foreignLocation->id)
                ->assertOk()
                ->assertJsonPath('data.location_id', $this->location->id)
                ->assertJsonPath('data.permissions.create', true);
        });
    }

    public function test_successful_get_does_not_allocate_sku_or_write_permissions_or_audit(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        $this->enableRawMaterials($this->location);
        $this->selectContext($this->steward, $this->location);
        $before = [
            'grants' => DB::table('organization_permission_grants')->count(),
            'grant_events' => DB::table('organization_permission_grant_events')->count(),
            'audit_events' => DB::table('raw_material_audit_events')->count(),
            'materials' => DB::table('raw_materials')->count(),
            'sku_reservations' => DB::table('raw_material_sku_reservations')->count(),
            'sku_counters' => DB::table('organization_sku_counters')->count(),
            'sku_assignments' => DB::table('organization_sku_assignments')->count(),
        ];

        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.actions.create', true);

        $this->assertSame($before, [
            'grants' => DB::table('organization_permission_grants')->count(),
            'grant_events' => DB::table('organization_permission_grant_events')->count(),
            'audit_events' => DB::table('raw_material_audit_events')->count(),
            'materials' => DB::table('raw_materials')->count(),
            'sku_reservations' => DB::table('raw_material_sku_reservations')->count(),
            'sku_counters' => DB::table('organization_sku_counters')->count(),
            'sku_assignments' => DB::table('organization_sku_assignments')->count(),
        ]);
    }

    public function test_capabilities_requires_valid_session_and_operating_context_and_is_read_only(): void
    {
        $this->getJson('/api/v1/security/raw-materials/capabilities')->assertUnauthorized();

        $this->actingAs($this->steward, 'web')
            ->withSession(['identity_version' => 'stale'])
            ->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertUnauthorized();

        $this->actingAs($this->steward, 'web')
            ->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertForbidden();

        $this->selectContext($this->steward, $this->location);
        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/security/raw-materials/capabilities')
            ->assertStatus(405);
    }

    private function selectContext(User $actor, Location $location): void
    {
        $response = $this->actingAs($actor, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }

    private function enableRawMaterials(Location $location): void
    {
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $location->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->administrator->id,
            'updated_by' => $this->administrator->id,
        ]);
    }

    private function prepareReadyModule(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        if (! LocationModuleState::query()
            ->where('location_id', $this->location->id)
            ->where('module_key', 'RAW_MATERIALS')
            ->where('enabled', true)
            ->exists()) {
            $this->enableRawMaterials($this->location);
        }
    }

    private function newRecipient(string $email): User
    {
        $recipient = $this->user($email);
        app(MembershipService::class)->assign($recipient, $this->location);

        return $recipient;
    }

    private function delegateCreate(
        User $recipient,
        ?string $validFrom = null,
        ?string $validUntil = null,
    ): array {
        return app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $recipient,
            RawMaterialPermission::Create,
            'Delegowanie CREATE do testu capabilities',
            $validFrom,
            $validUntil,
        );
    }

    private function bootstrapFixtureGrants(): void
    {
        $permissions = array_map(
            static fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        $this->withSetupTransaction(function (Connection $connection) use ($permissions, $operationId): void {
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->administrator->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $this->administrator->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $this->administrator->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $this->steward->id,
                    'permissions' => $permissions,
                ], JSON_THROW_ON_ERROR),
            ]);
            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $this->administrator->organization_id,
                    'user_id' => $this->steward->id,
                    'permission' => $permission,
                    'granted_by' => $this->administrator->id,
                    'valid_from' => now(),
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
            }
        });
    }
}
