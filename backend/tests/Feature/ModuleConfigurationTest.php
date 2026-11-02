<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Models\Location;
use App\Models\PermissionGrant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class ModuleConfigurationTest extends AuthorizationTestCase
{
    private Location $restaurant;

    private Location $kitchen;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = $this->activeRestaurant();
        $this->kitchen = Location::where('code', 'CK1')->firstOrFail();
        $this->administrator = $this->user('administrator@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->kitchen);
        $this->selectContext($this->administrator, $this->kitchen);
    }

    public function test_catalog_and_new_location_states_are_fail_closed_for_both_types(): void
    {
        $catalog = $this->getJson('/api/v1/security/modules/catalog')->assertOk()->json('data');
        $this->assertSame(['TEMPERATURES', 'CLEANING', 'LOSSES', 'INVENTORY', 'RAW_MATERIALS'], array_column($catalog, 'module'));
        foreach ($catalog as $module) {
            $this->assertSame('NOT_READY', $module['readiness']);
            $this->assertSame([], $module['dependencies']);
            $this->assertSame(['CENTRAL_KITCHEN', 'RESTAURANT'], $module['location_types']);
        }

        foreach ([$this->kitchen, $this->restaurant] as $location) {
            $states = $this->getJson("/api/v1/security/locations/{$location->id}/modules")->assertOk()->json('data');
            foreach ($states as $state) {
                $this->assertSame('DISABLED', $state['configured_state']);
                $this->assertFalse($state['available']);
            }
        }

        $newKitchen = $this->kitchen('CK2');
        $newRestaurant = $this->activeRestaurant('R2');
        foreach ([$newKitchen, $newRestaurant] as $location) {
            $states = $this->getJson("/api/v1/security/locations/{$location->id}/modules")->assertOk()->json('data');
            foreach ($states as $state) {
                $this->assertSame('DISABLED', $state['configured_state']);
            }
        }
        $this->assertSame(0, DB::table('location_module_states')->count());
    }

    public function test_production_api_cannot_enable_not_ready_modules_and_records_rejection(): void
    {
        $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertConflict()
            ->assertJsonPath('message', __('messages.module_not_ready', ['module' => 'Rejestr temperatur']));

        $this->assertSame(0, DB::table('location_module_states')->count());
        $this->assertSame(1, DB::table('security_events')->where('event_kind', 'module_attempt')
            ->where('outcome', 'rejected')->where('module_key', 'TEMPERATURES')->count());
        $this->assertSame(0, DB::table('security_events')->where('event_kind', 'module_transition')->count());
    }

    public function test_each_configuration_attempt_requires_a_fresh_password_and_limits_failures(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $body = $this->configurationBody('TEMPERATURES', [$this->restaurant->id], true, 'incorrect');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/security/modules/configuration', $body)->assertForbidden();
        }
        $this->postJson('/api/v1/security/modules/configuration', $body)->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.fresh_password_confirmation_rate_limited'));

        $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.fresh_password_confirmation_rate_limited'));
        $this->assertSame(0, DB::table('location_module_states')->count());
        $this->assertSame(7, DB::table('security_events')->where('event_kind', 'module_attempt')
            ->where('outcome', 'rejected')->where('module_key', 'TEMPERATURES')->count());
    }

    public function test_non_administrator_cannot_change_configuration(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $roleContexts = [
            [BusinessRole::CentralKitchenChef, $this->kitchen, $this->kitchen],
            [BusinessRole::Boss, $this->kitchen, null],
            [BusinessRole::RestaurantManager, $this->restaurant, $this->restaurant],
            [BusinessRole::RestaurantChef, $this->restaurant, $this->restaurant],
            [BusinessRole::Employee, $this->restaurant, $this->restaurant],
        ];

        foreach ($roleContexts as [$role, $context, $assignmentLocation]) {
            $user = $this->user('non-admin-'.strtolower($role->value).'@example.test');
            if ($assignmentLocation) {
                $this->role($user, $role, $assignmentLocation);
            } else {
                $this->role($user, $role);
                app(MembershipService::class)->assign($user, $context);
            }
            $this->selectContext($user, $context);
            $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertForbidden();
        }

        $this->assertSame(0, DB::table('location_module_states')->count());
    }

    public function test_explicit_central_kitchen_change_does_not_inherit_to_its_restaurant(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $response = $this->configure('TEMPERATURES', [$this->kitchen->id], true)->assertOk();
        $this->assertSame(1, $response->json('data.changed_count'));
        $this->assertTrue(app(ModuleAvailability::class)->enabled($this->kitchen, 'TEMPERATURES'));
        $this->assertFalse(app(ModuleAvailability::class)->enabled($this->restaurant, 'TEMPERATURES'));
        $this->getJson("/api/v1/security/locations/{$this->restaurant->id}/modules")
            ->assertOk()->assertJsonPath('data.0.configured_state', 'DISABLED');
    }

    public function test_all_compatible_bulk_target_is_resolved_and_applied_atomically(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $response = $this->postJson('/api/v1/security/modules/configuration', [
            'module_ids' => ['TEMPERATURES'],
            'all_compatible_locations' => true,
            'enabled' => true,
            'current_password' => self::PASSWORD,
        ])->assertOk();

        $this->assertSame(2, $response->json('data.changed_count'));
        $this->assertCount(2, $response->json('data.targets'));
        $this->assertSame(2, DB::table('location_module_states')->where('module_key', 'TEMPERATURES')->count());
        $this->assertSame(2, DB::table('security_events')->where('operation_id', $response->json('data.operation_id'))
            ->where('event_kind', 'module_transition')->count());
    }

    public function test_one_incompatible_target_rolls_back_the_whole_bulk_operation(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $response = $this->configure('RESTAURANT_ONLY', [$this->restaurant->id, $this->kitchen->id], true)
            ->assertConflict();
        $this->assertStringContainsString('nie jest zgodny', $response->json('message'));
        $this->assertSame(0, DB::table('location_module_states')->count());
        $this->assertSame(2, DB::table('security_events')->where('event_kind', 'module_attempt')
            ->where('outcome', 'rejected')->where('module_key', 'RESTAURANT_ONLY')->count());
        $this->assertSame(0, DB::table('security_events')->where('event_kind', 'module_transition')->count());
    }

    public function test_idempotent_retry_records_attempt_without_fake_transition(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $first = $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertOk();
        $state = DB::table('location_module_states')->where('module_key', 'TEMPERATURES')->first();
        $second = $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertOk();

        $this->assertSame(1, $first->json('data.changed_count'));
        $this->assertSame(0, $second->json('data.changed_count'));
        $this->assertSame(1, $second->json('data.unchanged_count'));
        $this->assertSame($state->updated_at, DB::table('location_module_states')->where('id', $state->id)->value('updated_at'));
        $this->assertSame(2, DB::table('security_events')->where('module_key', 'TEMPERATURES')
            ->where('event_kind', 'module_attempt')->where('outcome', 'accepted')->count());
        $this->assertSame(1, DB::table('security_events')->where('module_key', 'TEMPERATURES')
            ->where('event_kind', 'module_transition')->count());
        $this->postJson('/api/v1/security/modules/configuration', [
            'module_ids' => ['TEMPERATURES'],
            'location_ids' => [$this->restaurant->id],
            'enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_dependencies_must_be_explicitly_ready_and_enabled_before_dependents(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());

        $this->configure('READY_CHILD', [$this->restaurant->id], true)->assertConflict()
            ->assertJsonPath('message', __('messages.module_dependency_missing', [
                'module' => 'Ready Child',
                'dependency' => 'Ready Parent',
            ]));
        $this->configure(['READY_PARENT', 'READY_CHILD'], [$this->restaurant->id], true)->assertConflict();
        $this->assertSame(0, DB::table('location_module_states')->count());

        $this->configure('READY_PARENT', [$this->restaurant->id], true)->assertOk();
        $this->configure('READY_CHILD', [$this->restaurant->id], true)->assertOk();
        $this->configure('READY_GRANDCHILD', [$this->restaurant->id], true)->assertOk();
        $this->configure('READY_PARENT', [$this->restaurant->id], false)->assertConflict()
            ->assertJsonPath('message', __('messages.cannot_disable_module', [
                'module' => 'READY_PARENT',
                'dependent' => 'Ready Child',
            ]));
        $this->configure('READY_CHILD', [$this->restaurant->id], false)->assertConflict();

        $this->configure('READY_GRANDCHILD', [$this->restaurant->id], false)->assertOk();
        $this->configure('READY_CHILD', [$this->restaurant->id], false)->assertOk();
        $this->configure('READY_PARENT', [$this->restaurant->id], false)->assertOk();
        $this->assertFalse(app(ModuleAvailability::class)->enabled($this->restaurant, 'READY_PARENT'));
    }

    public function test_disabling_preserves_permission_grants_but_revoked_grants_do_not_return(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertOk();
        $employee = $this->user('employee@example.test');
        $this->role($employee, BusinessRole::Employee, $this->restaurant);
        $grant = app(PermissionAdministration::class)->grant(
            $this->administrator,
            $this->kitchen,
            $employee,
            $this->restaurant,
            Permission::TemperatureRegister,
        );
        $authorization = app(BusinessAuthorization::class);
        $this->assertTrue($authorization->allows($employee, $this->restaurant, $this->restaurant, Permission::TemperatureRegister));

        $this->configure('TEMPERATURES', [$this->restaurant->id], false)->assertOk();
        $this->assertSame(1, PermissionGrant::whereKey($grant->id)->count());
        $this->assertFalse($authorization->allows($employee, $this->restaurant, $this->restaurant, Permission::TemperatureRegister));
        $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertOk();
        $this->assertTrue($authorization->allows($employee, $this->restaurant, $this->restaurant, Permission::TemperatureRegister));

        app(PermissionAdministration::class)->revokeEffective(
            $this->administrator,
            $this->kitchen,
            $employee,
            $this->restaurant,
            Permission::TemperatureRegister,
        );
        $this->configure('TEMPERATURES', [$this->restaurant->id], false)->assertOk();
        $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertOk();
        $this->assertFalse($authorization->allows($employee, $this->restaurant, $this->restaurant, Permission::TemperatureRegister));
        $this->assertNotNull(PermissionGrant::findOrFail($grant->id)->revoked_at);
    }

    public function test_module_history_is_administrator_only_and_read_only(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $this->configure('TEMPERATURES', [$this->restaurant->id], true)->assertOk();
        $url = "/api/v1/security/locations/{$this->restaurant->id}/modules/history";
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.module_key', 'TEMPERATURES');

        $manager = $this->user('manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $this->restaurant);
        $this->selectContext($manager, $this->restaurant);
        $this->getJson($url)->assertForbidden();
        $this->postJson($url, [])->assertStatus(405);
    }

    public function test_unknown_modules_and_unselected_targets_are_rejected_without_state_changes(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $this->configure('UNKNOWN_MODULE', [$this->restaurant->id], true)->assertUnprocessable()
            ->assertJsonValidationErrors('module_ids');
        $this->configure('TEMPERATURES', [], true)->assertUnprocessable();
        $this->assertSame(0, DB::table('location_module_states')->count());
    }

    public function test_security_routes_reject_requests_without_a_first_party_session(): void
    {
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->flushSession();

        $this->withoutHeader('Origin')
            ->getJson('/api/v1/security/modules/catalog')
            ->assertUnauthorized();
    }

    public function test_security_configuration_rejects_a_bad_csrf_token(): void
    {
        config(['sanctum.middleware.validate_csrf_token' => EnforcedValidateCsrfTokenForTesting::class]);

        $this->withHeader('X-CSRF-TOKEN', 'invalid-token')
            ->postJson('/api/v1/security/modules/configuration', $this->configurationBody('TEMPERATURES', [$this->restaurant->id], true))
            ->assertStatus(419);

        $this->assertSame(0, DB::table('location_module_states')->count());
        $this->assertSame(0, DB::table('security_events')->where('event_kind', 'module_transition')->count());
    }

    public function test_security_routes_reject_inactive_users(): void
    {
        $replacement = $this->user('replacement-admin@example.test');
        $this->role($replacement, BusinessRole::Administrator);
        app(MembershipService::class)->assign($replacement, $this->kitchen);
        $this->administrator->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/security/modules/catalog')->assertUnauthorized();
        $this->assertSame(0, DB::table('location_module_states')->count());
    }

    public function test_configuration_rejects_invalid_inactive_and_unresolved_targets(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $inactiveRestaurant = $this->restaurant('R2');

        $this->configure('TEMPERATURES', ['not-a-uuid'], true)->assertUnprocessable()
            ->assertJsonValidationErrors('location_ids.0');
        $this->configure('TEMPERATURES', [Str::uuid()->toString()], true)->assertConflict();
        $this->configure('TEMPERATURES', [$inactiveRestaurant->id], true)->assertConflict();

        $this->assertSame(0, DB::table('location_module_states')->count());
        $this->assertSame(0, DB::table('security_events')->where('event_kind', 'module_transition')->count());
    }

    public function test_history_access_is_denied_outside_the_actors_location_scope(): void
    {
        $manager = $this->user('scoped-manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $this->restaurant);
        $this->selectContext($manager, $this->restaurant);

        $this->getJson("/api/v1/security/locations/{$this->kitchen->id}/modules/history")->assertForbidden();
    }

    public function test_spoofed_administrator_fields_do_not_escalate_a_manager(): void
    {
        $manager = $this->user('spoofed-manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $this->restaurant);
        $this->selectContext($manager, $this->restaurant);

        $this->postJson('/api/v1/security/modules/configuration', [
            ...$this->configurationBody('TEMPERATURES', [$this->restaurant->id], true),
            'actor_id' => $this->administrator->id,
            'organization_id' => $this->administrator->organization_id,
            'role' => 'ADMINISTRATOR',
            'is_admin' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->assertSame(0, DB::table('location_module_states')->count());
        $this->assertSame(0, DB::table('security_events')->where('event_kind', 'module_transition')->count());
    }

    /**
     * @param  string|list<string>  $modules
     * @param  list<string>  $locations
     */
    private function configure(string|array $modules, array $locations, bool $enabled, ?string $password = null)
    {
        return $this->postJson('/api/v1/security/modules/configuration', $this->configurationBody($modules, $locations, $enabled, $password));
    }

    /**
     * @param  string|list<string>  $modules
     * @param  list<string>  $locations
     * @return array<string, mixed>
     */
    private function configurationBody(string|array $modules, array $locations, bool $enabled, ?string $password = null): array
    {
        return [
            'module_ids' => is_array($modules) ? $modules : [$modules],
            'location_ids' => $locations,
            'enabled' => $enabled,
            'current_password' => $password ?? self::PASSWORD,
        ];
    }

    private function selectContext(User $user, Location $location): void
    {
        Auth::forgetGuards();
        Auth::guard('web')->login($user);
        $this->withSession([
            'identity_version' => $user->auth_version,
            'operating_membership_id' => null,
        ]);
        $response = $this->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }
}

final class EnforcedValidateCsrfTokenForTesting extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
