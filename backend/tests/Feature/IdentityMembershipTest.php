<?php

namespace Tests\Feature;

use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class IdentityMembershipTest extends IdentityTestCase
{
    public function test_email_is_normalized_unique_case_insensitively_and_user_cannot_be_deleted(): void
    {
        $user = $this->user(' PERSON@EXAMPLE.TEST ');
        $this->assertSame('person@example.test', $user->email);
        $this->assertDatabaseRejects('23505', fn (Connection $connection) => $this->user('PERSON@EXAMPLE.TEST'));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('users')->where('id', $user->id)->update(['email' => 'Mixed@Example.Test']));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($user)->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($user)->update(['organization_id' => Str::uuid()->toString()]));
    }

    public function test_one_user_has_independent_memberships_at_multiple_restaurants_and_a_kitchen(): void
    {
        $user = $this->user();
        $first = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $kitchen = Location::where('type', 'CENTRAL_KITCHEN')->firstOrFail();
        $service = app(MembershipService::class);
        foreach ([$first, $second, $kitchen] as $location) {
            $membership = $service->assign($user, $location);
            $this->assertTrue($membership->user->is($user));
            $this->assertTrue($membership->location->is($location));
            $this->assertTrue($membership->organization->is($user->organization));
        }
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/locations')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(1, User::count());
    }

    public function test_duplicate_active_pair_is_rejected_by_postgresql_and_service(): void
    {
        $user = $this->user();
        $kitchen = $this->kitchen();
        $service = app(MembershipService::class);
        $service->assign($user, $kitchen);
        $this->assertDatabaseRejects('23505', fn (Connection $connection) => LocationMembership::on('pgsql_setup')->create([
            'organization_id' => $user->organization_id, 'user_id' => $user->id, 'location_id' => $kitchen->id, 'is_active' => true,
        ]));
        $this->expectException(BusinessRuleViolation::class);
        $service->assign($user, $kitchen);
    }

    public function test_revocation_is_immediate_preserves_history_and_regrant_does_not_restore_context(): void
    {
        $user = $this->user();
        $location = $this->kitchen();
        $service = app(MembershipService::class);
        $membership = $service->assign($user, $location);
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertOk();
        $this->getJson('/api/v1/auth/operating-location')->assertJsonPath('data.id', $location->id);
        $service->deactivate($membership, 'Assignment ended');
        $service->deactivate($membership, 'Already ended');
        $renewed = $service->assign($user, $location);
        $this->assertNotSame($membership->id, $renewed->id);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->assertNull(session('operating_membership_id'));
        $this->getJson('/api/v1/auth/operating-location')->assertOk()->assertExactJson(['data' => null]);
        $this->assertSame(2, $user->memberships()->count());
        $this->assertNotNull($membership->fresh()->deactivated_at);
        $this->assertSame('Assignment ended', $membership->fresh()->deactivation_reason);
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($membership)->fresh()->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($membership)->fresh()->update(['is_active' => true]));
        $this->assertSame(1, User::count());
    }

    public function test_revoked_membership_cannot_be_selected_and_is_removed_from_available_locations(): void
    {
        $user = $this->user();
        $location = $this->kitchen();
        $service = app(MembershipService::class);
        $membership = $service->assign($user, $location);
        $service->deactivate($membership, 'Revoked');
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/locations')->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertForbidden();
    }

    public function test_inactive_location_invalidates_selected_context_and_is_not_listed(): void
    {
        $user = $this->user();
        $location = $this->kitchen();
        app(MembershipService::class)->assign($user, $location);
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertOk();
        $this->service->updateLocation($location, ['is_active' => false]);
        $this->getJson('/api/v1/auth/operating-location')->assertForbidden();
        $this->getJson('/api/v1/auth/locations')->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertForbidden();
    }

    public function test_context_is_not_inferred_from_managing_or_supplying_kitchens_or_another_user(): void
    {
        $user = $this->user();
        $otherUser = $this->user('other@example.test');
        $first = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $kitchen = Location::where('type', 'CENTRAL_KITCHEN')->firstOrFail();
        $memberships = app(MembershipService::class);
        $memberships->assign($user, $first);
        $memberships->assign($user, $kitchen);
        $memberships->assign($otherUser, $second);
        $this->service->establishSupply($second, $kitchen);
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/locations')->assertJsonCount(2, 'data');
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $first->id])->assertOk();
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $second->id])->assertForbidden();
        $this->getJson('/api/v1/auth/operating-location')->assertJsonPath('data.id', $first->id);
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $kitchen->id])->assertOk();
        $this->getJson('/api/v1/auth/operating-location')->assertJsonPath('data.id', $kitchen->id);
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => Str::uuid()->toString()])->assertForbidden();
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => 'invalid'])->assertUnprocessable();
    }

    public function test_assignment_rejects_disabled_users_and_locations(): void
    {
        $user = $this->user();
        $location = $this->kitchen();
        $location = $this->updateFixture($location, ['is_active' => false]);
        $this->expectException(BusinessRuleViolation::class);
        app(MembershipService::class)->assign($user, $location);
    }

    public function test_disabled_user_cannot_receive_an_active_membership(): void
    {
        $user = $this->user();
        $user = $this->updateFixture($user, ['is_active' => false]);
        $location = $this->kitchen();
        $this->expectException(BusinessRuleViolation::class);
        app(MembershipService::class)->assign($user, $location);
    }

    public function test_operating_membership_belongs_to_the_current_user_not_a_forged_session_assignment(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $membership = app(MembershipService::class)->assign($other, $this->kitchen());
        $this->actingAs($user, 'web')->withSession(['operating_membership_id' => $membership->id]);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->assertNull(session('operating_membership_id'));
    }

    public function test_cross_organization_memberships_fail_at_database_and_service_boundaries(): void
    {
        $user = $this->user();
        $this->withForeignOrganizationFixture(function (Location $foreign) use ($user): void {
            $this->assertDatabaseRejects('23503', fn (Connection $connection) => LocationMembership::on('pgsql_setup')->create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id, 'location_id' => $foreign->id, 'is_active' => true,
            ]));
            try {
                app(MembershipService::class)->assign($user, $foreign);
                $this->fail('Cross-organization membership accepted.');
            } catch (BusinessRuleViolation $exception) {
                $this->assertSame(__('messages.membership_must_belong_to_organization'), $exception->getMessage());
            }
            $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', ['location_id' => $foreign->id])->assertForbidden();
        });
    }
}
