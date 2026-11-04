<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Models\MembershipRequest;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;

class SecurityApiTest extends AuthorizationTestCase
{
    private function selectContext($user, $location): void
    {
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertOk();
    }

    public function test_management_api_requires_identity_context_and_explicit_authorization_not_dev_token(): void
    {
        $location = $this->activeRestaurant();
        $target = $this->user();
        $this->postJson("/api/v1/security/users/{$target->id}/roles", ['role' => 'ADMINISTRATOR'])->assertUnauthorized();
        $manager = $this->user('manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $this->actingAs($manager, 'web')->postJson("/api/v1/security/users/{$target->id}/roles", ['role' => 'ADMINISTRATOR'])->assertForbidden();
        $this->selectContext($manager, $location);
        $this->postJson("/api/v1/security/users/{$target->id}/roles", ['role' => 'ADMINISTRATOR', 'current_password' => self::PASSWORD])->assertForbidden();
        $this->postJson('/api/v1/security/locations', ['name' => 'Forbidden', 'code' => 'NO', 'type' => 'CENTRAL_KITCHEN'])->assertForbidden();
        $this->assertGreaterThan(0, DB::table('security_events')->where('outcome', 'rejected')->count());
        $this->getJson('/api/v1/locations')->assertNotFound();
        $this->withHeader('X-Veltra-Development-Token', str_repeat('x', 40))
            ->postJson("/api/v1/security/users/{$target->id}/roles", ['role' => 'BOSS'])->assertForbidden();
    }

    public function test_manager_scoped_creation_role_changes_and_membership_approval_cannot_escalate(): void
    {
        Mail::fake();
        $location = $this->activeRestaurant();
        $manager = $this->user('manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $outside = $this->user('outside@example.test');
        $this->selectContext($manager, $location);
        $this->getJson("/api/v1/security/locations/{$location->id}/users")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissing(['email' => $outside->email]);
        $profile = ['first_name' => 'New', 'last_name' => 'Staff', 'email' => 'new@example.test'];
        $newResponse = $this->postJson("/api/v1/security/locations/{$location->id}/personnel", $profile)->assertAccepted();
        $existingResponse = $this->postJson("/api/v1/security/locations/{$location->id}/personnel", [...$profile, 'email' => $outside->email])->assertAccepted();
        $this->assertSame($newResponse->json(), $existingResponse->json());
        $this->assertSame(0, $outside->memberships()->count());
        $this->getJson("/api/v1/security/locations/{$location->id}/membership-requests")->assertForbidden();
        $pending = MembershipRequest::firstOrFail();
        $this->postJson("/api/v1/security/membership-requests/{$pending->id}/decision", ['approve' => true])->assertForbidden();
        $created = User::where('email', 'new@example.test')->firstOrFail();
        $grant = $this->postJson("/api/v1/security/users/{$created->id}/roles", ['role' => 'RESTAURANT_CHEF', 'location_id' => $location->id])
            ->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/security/roles/{$grant}")->assertOk();
        $this->postJson("/api/v1/security/users/{$created->id}/locations/{$location->id}/permissions", ['permission' => 'FOODCOST_VIEW'])->assertForbidden();
        $this->getJson("/api/v1/security/users/{$created->id}/assignments")->assertOk()->assertJsonMissingPath('data.password');
    }

    public function test_email_initiation_has_uniform_response_and_no_credentials(): void
    {
        Mail::fake();
        $location = $this->activeRestaurant();
        $manager = $this->user('manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $target = $this->user();
        $this->role($target, BusinessRole::Employee, $location);
        $this->selectContext($manager, $location);
        $sent = $this->postJson("/api/v1/security/users/{$target->id}/links", ['kind' => 'reset'])->assertAccepted();
        $limited = $this->postJson("/api/v1/security/users/{$target->id}/links", ['kind' => 'reset'])->assertAccepted();
        $unknown = $this->postJson('/api/v1/security/users/'.Str::uuid().'/links', ['kind' => 'reset'])->assertAccepted();
        $this->assertSame($sent->json(), $unknown->json());
        $this->assertSame($sent->json(), $limited->json());
        Mail::assertSentCount(1);
        $this->assertStringNotContainsString('token', $sent->getContent());
    }

    public function test_admin_kitchen_provisioning_membership_and_email_reverification_are_explicit(): void
    {
        Mail::fake();
        $location = $this->kitchen();
        $admin = $this->user('admin@example.test');
        $this->role($admin, BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $this->updateFixture($admin->organization, ['authorization_initialized' => true]);
        $this->selectContext($admin, $location);
        $this->postJson("/api/v1/security/locations/{$location->id}/personnel", ['first_name' => 'New', 'last_name' => 'Chef', 'email' => 'newchef@example.test'])->assertAccepted();
        $new = User::where('email', 'newchef@example.test')->firstOrFail();
        $this->assertSame(0, RoleAssignment::where('user_id', $new->id)->count());
        $this->postJson("/api/v1/security/users/{$new->id}/roles", ['role' => 'CENTRAL_KITCHEN_CHEF', 'location_id' => $location->id])->assertCreated();
        $other = $this->user('old@example.test');
        $this->postJson("/api/v1/security/users/{$other->id}/locations/{$location->id}/membership")->assertCreated();
        $version = $other->auth_version;
        $this->patchJson("/api/v1/security/users/{$other->id}/email", ['email' => ' NEW@example.test '])->assertAccepted();
        $this->assertSame('new@example.test', $other->fresh()->email);
        $this->assertNull($other->fresh()->email_verified_at);
        $this->assertGreaterThan($version, $other->fresh()->auth_version);
        $this->patchJson("/api/v1/security/users/{$admin->id}/email", ['email' => 'self@example.test'])->assertForbidden();
        $this->deleteJson('/api/v1/security/memberships/'.$admin->memberships()->firstOrFail()->id)->assertConflict();
        $this->patchJson("/api/v1/security/locations/{$location->id}", ['is_active' => false])->assertConflict();
    }

    public function test_role_mutations_use_real_csrf_and_validation_and_admin_confirmation_is_rate_limited(): void
    {
        $actor = $this->user('admin@example.test');
        $location = $this->activeRestaurant();
        $this->role($actor, BusinessRole::Administrator);
        app(MembershipService::class)->assign($actor, $location);
        $target = $this->user();
        $this->selectContext($actor, $location);
        $this->app->detectEnvironment(fn () => 'local');
        $this->postJson("/api/v1/security/users/{$target->id}/roles", ['role' => 'BOSS'])->assertStatus(419);
        $this->withSession(['_token' => 'csrf-test'])->withHeader('X-CSRF-TOKEN', 'csrf-test');
        $this->postJson("/api/v1/security/users/{$target->id}/roles", ['role' => 'BOSS', 'is_admin' => true])->assertUnprocessable();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/security/users/{$target->id}/roles", ['role' => 'ADMINISTRATOR', 'current_password' => 'wrong'])->assertForbidden();
        }
        $this->postJson("/api/v1/security/users/{$target->id}/roles", ['role' => 'ADMINISTRATOR', 'current_password' => self::PASSWORD])->assertForbidden();
        $this->assertSame(0, RoleAssignment::where('user_id', $target->id)->count());
    }
}
