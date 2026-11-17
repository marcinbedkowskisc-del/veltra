<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\User;
use Tests\AuthorizationTestCase;

class AdministrationAccessTest extends AuthorizationTestCase
{
    private function selectContext(User $user, Location $location): void
    {
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ])->assertOk();
    }

    public function test_active_administrator_can_access_pa_independently_of_raw(): void
    {
        $location = $this->kitchen();
        $admin = $this->user('admin@example.test');
        $this->role($admin, BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $this->selectContext($admin, $location);

        $this->assertFalse(app(ModuleAvailability::class)->enabled($location, 'RAW_MATERIALS'));
        $this->getJson('/api/v1/administration/access')->assertOk()
            ->assertExactJson(['data' => ['allowed' => true]])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_employee_manager_and_boss_are_denied_and_manager_personnel_access_remains(): void
    {
        $location = $this->activeRestaurant();
        foreach ([BusinessRole::Employee, BusinessRole::RestaurantManager, BusinessRole::Boss] as $role) {
            $user = $this->user(strtolower($role->value).'@example.test');
            $this->role($user, $role, $role->organizationWide() ? null : $location);
            if ($role->organizationWide()) {
                app(MembershipService::class)->assign($user, $location);
            }
            $this->selectContext($user, $location);
            $this->getJson('/api/v1/administration/access')->assertForbidden();
            if ($role === BusinessRole::RestaurantManager) {
                $this->getJson("/api/v1/security/locations/{$location->id}/users")->assertOk();
            }
        }
    }

    public function test_unauthenticated_and_missing_context_are_rejected(): void
    {
        $this->getJson('/api/v1/administration/access')->assertUnauthorized();
        $admin = $this->user('admin@example.test');
        $this->role($admin, BusinessRole::Administrator);
        $this->actingAs($admin, 'web')->getJson('/api/v1/administration/access')->assertForbidden();
    }

    public function test_revoked_role_and_disabled_account_are_rejected_on_the_next_request(): void
    {
        $location = $this->kitchen();
        $actor = $this->user('actor@example.test');
        $target = $this->user('target@example.test');
        $this->role($actor, BusinessRole::Administrator);
        $assignment = $this->role($target, BusinessRole::Administrator);
        app(MembershipService::class)->assign($actor, $location);
        app(MembershipService::class)->assign($target, $location);

        $this->selectContext($target, $location);
        $this->getJson('/api/v1/administration/access')->assertOk();
        app(RoleAdministration::class)->revoke($actor, $location, $assignment, self::PASSWORD);
        $this->getJson('/api/v1/administration/access')->assertForbidden();

        $disabled = $this->user('disabled@example.test');
        $this->role($disabled, BusinessRole::Administrator);
        app(MembershipService::class)->assign($disabled, $location);
        $this->selectContext($disabled, $location);
        $this->getJson('/api/v1/administration/access')->assertOk();
        app(RoleAdministration::class)->setAccountActive($actor, $location, $disabled, false);
        $this->getJson('/api/v1/administration/access')->assertUnauthorized();
    }
}
