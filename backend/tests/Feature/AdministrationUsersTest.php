<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ConfiguredModuleAvailability;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\SecurityMutation;
use App\Domain\Identity\MembershipService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialPermissionBootstrap;
use App\Models\Location;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;

class AdministrationUsersTest extends AuthorizationTestCase
{
    private function selectContext(User $user, Location $location): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ])->assertOk();
    }

    private function administrator(Location $location): User
    {
        $admin = $this->provisionedUser('admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $this->selectContext($admin, $location);

        return $admin;
    }

    public function test_administrator_directory_search_filters_multiple_roles_and_memberships(): void
    {
        $first = $this->activeRestaurant('R1');
        $second = $this->activeRestaurant('R2');
        $this->administrator($first);

        $target = $this->provisionedUser('anna@example.test', BusinessRole::RestaurantManager, $first);
        $this->role($target, BusinessRole::RestaurantManager, $second);
        $inactive = $this->provisionedUser('inactive@example.test', BusinessRole::Boss);
        $this->updateFixture($inactive, ['is_active' => false]);

        $this->getJson('/api/v1/administration/users?search=anna&status=all')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'anna@example.test')
            ->assertJsonCount(2, 'data.0.roles')
            ->assertJsonCount(2, 'data.0.locations');

        $this->getJson('/api/v1/administration/users?role=RESTAURANT_MANAGER&status=all')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/administration/users?location_id='.$second->id.'&status=all')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/administration/users?status=inactive')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_user_details_include_assignments_requests_and_safe_audit_history(): void
    {
        $location = $this->activeRestaurant();
        $admin = $this->administrator($location);
        $target = $this->provisionedUser('target@example.test', BusinessRole::Employee, $location);
        $assignment = RoleAssignment::where('user_id', $target->id)->firstOrFail();
        $this->withSetupConnection(fn (Connection $connection): bool => $connection->table('membership_requests')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $target->organization_id,
            'user_id' => $target->id,
            'location_id' => $location->id,
            'requested_by' => $admin->id,
            'requested_at' => now(),
        ]));
        app(SecurityMutation::class)->record($admin, $target, $location, 'role.grant.EMPLOYEE', 'accepted', $assignment->id);

        $this->getJson("/api/v1/administration/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.roles.0.id', $assignment->id)
            ->assertJsonPath('data.roles.0.role', 'EMPLOYEE')
            ->assertJsonPath('data.memberships.0.location_name', $location->name)
            ->assertJsonPath('data.requests.0.decision', null)
            ->assertJsonPath('data.history.0.action', 'role.grant.EMPLOYEE')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.token');
    }

    public function test_user_details_include_organization_raw_grants_without_module_readiness_or_unknown_user_access(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->updateFixture($this->administrator($location), [
            'first_name' => 'Adam',
            'last_name' => 'Administrator',
        ]);
        $bootstrapper = $this->provisionedUser('raw-bootstrapper@example.test', BusinessRole::Administrator);
        $bootstrapper = $this->updateFixture($bootstrapper, ['first_name' => 'Ala', 'last_name' => 'Bootstrap']);
        app(MembershipService::class)->assign($bootstrapper, $location);
        $target = $this->updateFixture($this->provisionedUser('raw-target@example.test', BusinessRole::Employee, $location), [
            'first_name' => 'Anna',
            'last_name' => 'Raw',
        ]);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);
        app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $bootstrapper->id,
            $administrator->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Nadanie testowe do PA',
            self::PASSWORD,
        );
        $this->app->bind(ModuleAvailability::class, fn () => new class implements ModuleAvailability
        {
            public function enabled(Location $location, string $module): bool
            {
                return true;
            }
        });
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $administrator,
            $location,
            $target,
            RawMaterialPermission::View,
            'Delegacja testowa do PA',
        );
        $this->app->bind(ModuleAvailability::class, ConfiguredModuleAvailability::class);

        $this->assertDatabaseMissing('location_module_states', [
            'location_id' => $location->id,
            'module_key' => 'RAW_MATERIALS',
        ]);
        $this->getJson("/api/v1/administration/users/{$target->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.raw_permissions.grants')
            ->assertJsonPath('data.raw_permissions.grants.0.source', 'DELEGATED')
            ->assertJsonPath('data.raw_permissions.grants.0.status', 'EFFECTIVE')
            ->assertJsonPath('data.raw_permissions.grants.0.lineage.0.source', 'BOOTSTRAP')
            ->assertJsonPath('data.raw_permissions.grants.0.lineage.0.granted_by_name', 'Ala Bootstrap')
            ->assertJsonPath('data.raw_permissions.grants.0.lineage.0.recipient_name', 'Adam Administrator')
            ->assertJsonPath('data.raw_permissions.grants.0.granted_by_name', 'Adam Administrator')
            ->assertJsonPath('data.raw_permissions.grants.0.lineage.0.revoked_at', null)
            ->assertJsonCount(2, 'data.raw_permissions.history')
            ->assertJsonMissingPath('data.raw_permissions.grants.0.operation_id');

        $this->getJson('/api/v1/administration/users/'.Str::uuid())->assertNotFound();
    }

    public function test_user_details_project_effective_permissions_through_stage_1d_authorization(): void
    {
        $location = $this->activeRestaurant();
        $this->administrator($location);
        $target = $this->provisionedUser('employee@example.test', BusinessRole::Employee, $location);

        $this->getJson("/api/v1/administration/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.effective_permissions.0.location_id', $location->id)
            ->assertJsonPath('data.effective_permissions.0.permissions.0.permission', 'LOCATION_VIEW')
            ->assertJsonPath('data.effective_permissions.0.permissions.0.operating_contexts.0.id', $location->id);
    }

    public function test_administrator_can_update_profile_and_audit_the_change(): void
    {
        $location = $this->activeRestaurant();
        $admin = $this->administrator($location);
        $target = $this->pendingUser('target@example.test');

        $this->patchJson("/api/v1/administration/users/{$target->id}/profile", [
            'first_name' => 'Anna',
            'last_name' => 'Kowalska',
        ])->assertOk()->assertJsonPath('message', __('messages.account_profile_updated'));

        $this->assertSame('Anna', $target->fresh()->first_name);
        $this->assertDatabaseHas('security_events', [
            'actor_id' => $admin->id,
            'target_id' => $target->id,
            'action' => 'account.profile_update',
            'outcome' => 'accepted',
        ]);
    }

    public function test_non_administrators_cannot_access_administration_user_endpoints(): void
    {
        $location = $this->activeRestaurant();
        $manager = $this->provisionedUser('manager@example.test', BusinessRole::RestaurantManager, $location);
        $this->selectContext($manager, $location);

        $this->getJson('/api/v1/administration/users')->assertForbidden();
        $this->getJson('/api/v1/administration/locations')->assertForbidden();
        $this->patchJson('/api/v1/administration/users/'.Str::uuid().'/profile', [
            'first_name' => 'Not',
            'last_name' => 'Allowed',
        ])->assertForbidden();
    }
}
