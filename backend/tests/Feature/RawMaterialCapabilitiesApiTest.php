<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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
            ->assertJsonPath('data.actions.create', false)
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.actions.edit', false);
        $this->assertSame(
            app(RawMaterialAuthorization::class)->hasEffectiveGrant($this->steward, RawMaterialPermission::Create),
            $response->json('data.permissions.create'),
        );
        $this->assertSame(
            app(RawMaterialAuthorization::class)->hasEffectiveGrant($this->steward, RawMaterialPermission::Edit),
            $response->json('data.permissions.edit'),
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
            ->assertJsonPath('data.actions.create', true)
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.actions.edit', true);

        $secondaryKitchen = $this->kitchen('CK2');
        app(MembershipService::class)->assign($this->steward, $secondaryKitchen);
        $this->enableRawMaterials($secondaryKitchen);
        $this->selectContext($this->steward, $secondaryKitchen);

        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.location_id', $secondaryKitchen->id)
            ->assertJsonPath('data.permissions.create', true)
            ->assertJsonPath('data.actions.create', false)
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.actions.edit', false);
    }

    public function test_boss_restrictions_do_not_suppress_an_independent_raw_grant(): void
    {
        $this->prepareReadyModule();
        $this->role($this->steward, BusinessRole::Boss);

        $this->selectContext($this->administrator, $this->location);
        $this->putJson("/api/v1/administration/users/{$this->steward->id}/boss-location-restrictions", [
            'version' => 0,
            'excluded_location_ids' => [$this->location->id],
            'policy_schema_version' => 2,
            'excluded_categories' => [],
            'excluded_category_locations' => [],
        ])->assertOk();

        $this->selectContext($this->steward, $this->location);
        $this->getJson('/api/v1/raw-materials')->assertOk();
    }

    public function test_boss_restrictions_do_not_grant_access_to_raw_routes_without_an_independent_grant(): void
    {
        $this->prepareReadyModule();
        $boss = $this->newRecipient('capabilities-boss-without-raw-grant@example.test');
        $this->role($boss, BusinessRole::Boss);

        $this->selectContext($this->administrator, $this->location);
        $this->putJson("/api/v1/administration/users/{$boss->id}/boss-location-restrictions", [
            'version' => 0,
            'excluded_location_ids' => [$this->location->id],
            'policy_schema_version' => 2,
            'excluded_categories' => [],
            'excluded_category_locations' => [],
        ])->assertOk();

        $this->selectContext($boss, $this->location);
        $this->getJson('/api/v1/raw-materials')->assertForbidden();
    }

    public function test_category_management_capability_requires_grant_availability_and_primary_context(): void
    {
        $this->selectContext($this->steward, $this->location);
        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.manage_categories', true)
            ->assertJsonPath('data.actions.manage_categories', false);

        $this->prepareReadyModule();
        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()->assertJsonPath('data.actions.manage_categories', true);
        $this->selectContext($this->administrator, $this->location);
        $this->postJson('/api/v1/administration/units', [
            'code' => 'mg_pa04',
            'name' => 'Miligram PA-04',
            'symbol' => 'mg4',
            'dimension' => 'MASS',
            'base_unit_code' => 'g',
            'to_base_factor' => '0.000001',
        ])->assertCreated();
        $this->selectContext($this->steward, $this->location);
        $unitOptions = $this->getJson('/api/v1/raw-materials/units')->assertOk()->json('data');
        $this->assertContains('mg_pa04', collect($unitOptions)->pluck('code')->all());

        $category = $this->postJson('/api/v1/raw-materials/categories', [
            'code' => 'TEST_CATEGORY', 'name' => 'Kategoria testowa',
        ])->assertCreated()->assertJsonPath('data.depth', 1)->json('data.id');
        $this->patchJson('/api/v1/raw-materials/categories/'.$category, ['name' => 'Nowa nazwa'])
            ->assertOk()->assertJsonPath('data.name', 'Nowa nazwa');

        $secondary = $this->kitchen('CAT2');
        app(MembershipService::class)->assign($this->steward, $secondary);
        $this->enableRawMaterials($secondary);
        $this->selectContext($this->steward, $secondary);
        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.manage_categories', true)
            ->assertJsonPath('data.actions.manage_categories', false);
    }

    public function test_category_management_requires_an_effective_grant(): void
    {
        $this->prepareReadyModule();
        $recipient = $this->newRecipient('category-view-only@example.test');
        $this->selectContext($recipient, $this->location);
        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.manage_categories', false)
            ->assertJsonPath('data.actions.manage_categories', false);
        $this->postJson('/api/v1/raw-materials/categories', ['code' => 'DENIED', 'name' => 'Odmowa'])
            ->assertForbidden();
    }

    public function test_administration_archiving_preserves_existing_raw_material_references(): void
    {
        $this->prepareReadyModule();
        $category = app(RawMaterialService::class)->createCategory($this->steward, $this->location, [
            'code' => 'PA_REFERENCE',
            'name' => 'Kategoria z surowcem',
        ]);
        $material = app(RawMaterialService::class)->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Surowiec w archiwizowanej kategorii',
            'base_unit_code' => 'kg',
            'nominal_quantity' => '1000',
            'conversions' => [['unit_code' => 'g']],
        ]);

        $this->selectContext($this->administrator, $this->location);
        $this->postJson('/api/v1/administration/categories/'.$category['id'].'/archive')
            ->assertOk()->assertJsonPath('data.raw_material_count', 1);
        $this->assertDatabaseHas('raw_material_revisions', [
            'raw_material_id' => $material['id'],
            'category_id' => $category['id'],
        ]);

        $this->selectContext($this->steward, $this->location);
        $this->getJson('/api/v1/raw-materials/categories')->assertOk()
            ->assertJsonPath('data.0.id', $category['id'])
            ->assertJsonStructure(['data' => [['archived_at', 'archived_by']]]);
    }

    public function test_pricing_preview_uses_authoritative_normalization_and_cost_rules(): void
    {
        $this->prepareReadyModule();
        $this->role($this->steward, BusinessRole::Administrator);
        $this->selectContext($this->steward, $this->location);
        $this->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()->assertJsonPath('data.actions.manage_reference_price', true)
            ->assertJsonPath('data.actions.view_usable_cost', true);

        $input = [
            'base_unit_code' => 'g', 'nominal_quantity' => '2800', 'usable_quantity' => '1500',
            'pricing' => ['basis' => 'PACKAGE', 'amount' => '28'],
        ];
        $this->postJson('/api/v1/raw-materials/pricing-preview', $input)
            ->assertOk()
            ->assertJsonPath('data.net_pln_per_package', '28.00000000000000')
            ->assertJsonPath('data.usable_unit_cost', '1.86666666666667')
            ->assertJsonPath('data.usable_cost_basis', 'PLN/100g')
            ->assertHeader('Cache-Control', 'no-store, private');
        $input['pricing'] = ['basis' => 'NOMINAL_UNIT', 'amount' => '10', 'unit_code' => 'kg'];
        $this->postJson('/api/v1/raw-materials/pricing-preview', $input)
            ->assertOk()->assertJsonPath('data.net_pln_per_package', '28.00000000000000')
            ->assertJsonPath('data.usable_unit_cost', '1.86666666666667');

        $input = [
            'base_unit_code' => 'ml', 'nominal_quantity' => '2000',
            'pricing' => ['basis' => 'NOMINAL_UNIT', 'amount' => '10', 'unit_code' => 'l'],
        ];
        $this->postJson('/api/v1/raw-materials/pricing-preview', $input)
            ->assertOk()->assertJsonPath('data.net_pln_per_package', '20.00000000000000')
            ->assertJsonPath('data.usable_unit_cost', '1.00000000000000')
            ->assertJsonPath('data.usable_cost_basis', 'PLN/100ml');
        $input = [
            'base_unit_code' => 'pc', 'nominal_quantity' => '10', 'usable_quantity' => '8',
            'pricing' => ['basis' => 'NOMINAL_UNIT', 'amount' => '2', 'unit_code' => 'pc'],
        ];
        $this->postJson('/api/v1/raw-materials/pricing-preview', $input)
            ->assertOk()->assertJsonPath('data.net_pln_per_package', '20.00000000000000')
            ->assertJsonPath('data.usable_unit_cost', '2.50000000000000')
            ->assertJsonPath('data.usable_cost_basis', 'PLN/pc');
        $input['pricing']['amount'] = '0';
        $this->postJson('/api/v1/raw-materials/pricing-preview', $input)
            ->assertOk()->assertJsonPath('data.usable_unit_cost', '0.00000000000000');
        foreach ([
            ['nominal_quantity' => '0'],
            ['usable_quantity' => '-1'],
            ['usable_quantity' => '11'],
            ['pricing' => ['basis' => 'PACKAGE', 'amount' => '-1']],
            ['pricing' => ['basis' => 'NOMINAL_UNIT', 'amount' => 'abc', 'unit_code' => 'pc']],
            ['pricing' => ['basis' => 'NOMINAL_UNIT', 'amount' => '1', 'unit_code' => 'kg']],
        ] as $invalid) {
            $this->postJson('/api/v1/raw-materials/pricing-preview', array_replace($input, $invalid))
                ->assertUnprocessable();
        }
    }

    public function test_pricing_preview_rejects_non_administrator_with_manage_grant(): void
    {
        $this->prepareReadyModule();
        $this->selectContext($this->steward, $this->location);
        $this->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()->assertJsonPath('data.permissions.manage_reference_price', true)
            ->assertJsonPath('data.actions.manage_reference_price', false);
        $this->postJson('/api/v1/raw-materials/pricing-preview', [
            'base_unit_code' => 'g', 'nominal_quantity' => '100',
            'pricing' => ['basis' => 'PACKAGE', 'amount' => '10'],
        ])->assertForbidden();
    }

    public function test_pricing_preview_does_not_expose_financial_data_without_reference_price_view(): void
    {
        $this->prepareReadyModule();
        $recipient = $this->newRecipient('pricing-preview-view-only@example.test');
        $this->role($this->steward, BusinessRole::Administrator);
        $this->role($recipient, BusinessRole::Administrator);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward, $this->location, $recipient, RawMaterialPermission::View, 'Odczyt katalogu w teście',
        );
        $this->selectContext($recipient, $this->location);
        $this->postJson('/api/v1/raw-materials/pricing-preview', [
            'base_unit_code' => 'g', 'nominal_quantity' => '100',
            'pricing' => ['basis' => 'PACKAGE', 'amount' => '10'],
        ])->assertForbidden()->assertJsonMissingPath('data.net_pln_per_package')
            ->assertJsonMissingPath('data.usable_unit_cost');
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
            ->assertJsonPath('data.actions.create', false)
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.actions.edit', false);
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
            ->assertJsonPath('data.actions.create', false)
            ->assertJsonPath('data.permissions.edit', false)
            ->assertJsonPath('data.actions.edit', false);
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
            ->assertJsonPath('data.actions.create', false)
            ->assertJsonPath('data.permissions.edit', false)
            ->assertJsonPath('data.actions.edit', false);
    }

    public function test_create_without_edit_does_not_expose_edit_capability(): void
    {
        $this->prepareReadyModule();
        $createOnly = $this->newRecipient('capabilities-create-without-edit@example.test');
        $this->delegateCreate($createOnly);
        $createOnly->refresh();

        $this->selectContext($createOnly, $this->location);
        $this->actingAs($createOnly, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', true)
            ->assertJsonPath('data.actions.create', true)
            ->assertJsonPath('data.permissions.edit', false)
            ->assertJsonPath('data.actions.edit', false);
    }

    public function test_edit_without_create_does_not_expose_create_capability(): void
    {
        $this->prepareReadyModule();
        $editOnly = $this->newRecipient('capabilities-edit-without-create@example.test');
        $this->delegateEdit($editOnly);
        $editOnly->refresh();
        $this->selectContext($editOnly, $this->location);
        $this->actingAs($editOnly, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.create', false)
            ->assertJsonPath('data.actions.create', false)
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.actions.edit', true);
    }

    public function test_expired_edit_grant_is_not_effective(): void
    {
        $this->prepareReadyModule();
        $recipient = $this->newRecipient('capabilities-expired-edit@example.test');
        $grantStarts = DB::table('organization_permission_grants')
            ->where('organization_id', $this->steward->organization_id)
            ->where('user_id', $this->steward->id)
            ->whereIn('permission', [
                RawMaterialPermission::Edit->value,
                RawMaterialPermission::PermissionDelegate->value,
            ])
            ->pluck('valid_from')
            ->map(static fn ($date) => Carbon::parse($date));
        $validFrom = $grantStarts->max();
        $validUntil = $validFrom->copy()->addMicrosecond();
        $this->assertTrue($validUntil->isPast());
        $this->delegateEdit($recipient, $validFrom->toISOString(), $validUntil->toISOString());
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.edit', false)
            ->assertJsonPath('data.actions.edit', false);
    }

    public function test_delegated_edit_uses_effective_grant_and_respects_revocation(): void
    {
        $this->prepareReadyModule();
        $recipient = $this->newRecipient('capabilities-delegated-edit@example.test');
        $grant = $this->delegateEdit($recipient);
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.actions.edit', true);

        app(OrganizationRawMaterialPermissionAdministration::class)->revoke(
            $this->steward,
            $this->location,
            $grant['id'],
            'Cofnięcie EDIT w teście capabilities',
        );
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.edit', false)
            ->assertJsonPath('data.actions.edit', false);
    }

    public function test_delegated_edit_disappears_when_its_source_grant_is_revoked(): void
    {
        $this->prepareReadyModule();
        $recipient = $this->newRecipient('capabilities-revoked-edit-source@example.test');
        $this->delegateEdit($recipient);
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.actions.edit', true);

        $sourceGrantId = DB::table('organization_permission_grants')
            ->where('organization_id', $this->steward->organization_id)
            ->where('user_id', $this->steward->id)
            ->where('permission', RawMaterialPermission::Edit->value)
            ->value('id');
        app(OrganizationRawMaterialPermissionAdministration::class)->revoke(
            $this->steward,
            $this->location,
            $sourceGrantId,
            'Cofnięcie źródła delegowanego EDIT',
        );
        $this->selectContext($recipient, $this->location);

        $this->actingAs($recipient, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.permissions.edit', false)
            ->assertJsonPath('data.actions.edit', false);
    }

    public function test_edit_action_requires_primary_context_while_permission_remains_effective(): void
    {
        $this->prepareReadyModule();
        $secondaryKitchen = $this->kitchen('CK2');
        app(MembershipService::class)->assign($this->steward, $secondaryKitchen);
        $this->enableRawMaterials($secondaryKitchen);
        $this->selectContext($this->steward, $secondaryKitchen);

        $this->actingAs($this->steward, 'web')->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()
            ->assertJsonPath('data.location_id', $secondaryKitchen->id)
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.actions.edit', false);
    }

    public function test_inactive_membership_cannot_read_capabilities(): void
    {
        $this->selectContext($this->steward, $this->location);
        DB::connection('pgsql_setup')->table('location_memberships')
            ->where('user_id', $this->steward->id)
            ->where('location_id', $this->location->id)
            ->update([
                'is_active' => false,
                'deactivated_at' => now()->addSecond(),
                'deactivation_reason' => 'Test nieaktywnego członkostwa',
                'updated_at' => now(),
            ]);

        $this->actingAs($this->steward, 'web')
            ->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertForbidden();
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
        $this->prepareReadyModule();
        $this->selectContext($this->steward, $this->location);

        $this->withForeignOrganizationFixture(function (Location $foreignLocation): void {
            $this->actingAs($this->steward, 'web')
                ->getJson('/api/v1/security/raw-materials/capabilities?location_id='.$foreignLocation->id)
                ->assertOk()
                ->assertJsonPath('data.location_id', $this->location->id)
                ->assertJsonPath('data.permissions.create', true)
                ->assertJsonPath('data.permissions.edit', true)
                ->assertJsonPath('data.actions.edit', true);
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
            ->assertJsonPath('data.actions.create', true)
            ->assertJsonPath('data.actions.edit', true);

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
        Auth::forgetGuards();
        $this->flushSession();
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

    private function delegateEdit(
        User $recipient,
        ?string $validFrom = null,
        ?string $validUntil = null,
    ): array {
        return app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $recipient,
            RawMaterialPermission::Edit,
            'Delegowanie EDIT do testu capabilities',
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
