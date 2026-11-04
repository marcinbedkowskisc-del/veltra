<?php

namespace Tests\Feature;

use App\Console\Commands\BootstrapAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\AccountLinks;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Mail\IdentityLink;
use App\Models\RoleAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\AuthorizationTestCase;

class AccountSecurityTest extends AuthorizationTestCase
{
    public function test_interactive_bootstrap_is_recoverable_before_verification_and_closed_after_activation(): void
    {
        Mail::fake();
        $user = app(IdentityService::class)->provisionInitial(['first_name' => 'Initial', 'last_name' => 'Admin', 'email' => 'initial@example.test'], self::PASSWORD);
        $command = app(BootstrapAuthorization::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->setInputs(['wrong']);
        $this->assertSame(1, $tester->execute([], ['interactive' => true]));
        $this->assertSame(1, DB::table('security_events')->where('action', 'administrator.bootstrap')->where('outcome', 'rejected')->count());
        $tester->setInputs([self::PASSWORD, 'Initial Kitchen', 'CK']);
        $this->assertSame(0, $tester->execute([], ['interactive' => true]));
        $this->assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
        $this->assertSame(1, $user->memberships()->where('is_active', true)->count());
        $this->travel(61)->seconds();
        $tester->setInputs([self::PASSWORD]);
        $this->assertSame(0, $tester->execute([], ['interactive' => true]));
        $this->assertSame(1, RoleAssignment::count());
        app(AccountLinks::class)->consume('activation', $user->email, $this->sentToken(), 'New-Password!42');
        $this->assertSame(1, $tester->execute([], ['interactive' => true]));
        $this->assertStringContainsString('Authorization is already initialized.', $tester->getDisplay());
    }

    private function sentToken(): string
    {
        $mail = Mail::sent(IdentityLink::class)->last();
        preg_match('/token=([^\s]+)/', $mail->render(), $matches);

        return rawurldecode($matches[1]);
    }

    public function test_initial_identity_cannot_login_until_single_use_activation_and_new_password(): void
    {
        Mail::fake();
        $user = app(IdentityService::class)->provisionInitial(['first_name' => 'Initial', 'last_name' => 'Admin', 'email' => 'initial@example.test'], self::PASSWORD);
        app(RoleAdministration::class)->bootstrapInitial($user);
        app(MembershipService::class)->assign($user, $this->kitchen());
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertUnauthorized()->assertExactJson(['message' => __('messages.invalid_credentials')]);
        $links = app(AccountLinks::class);
        $links->issue($user, 'activation', 'cli-bootstrap', bootstrap: true);
        $token = $this->sentToken();
        $stored = DB::table('activation_tokens')->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));
        $links->consume('activation', $user->email, $token, 'New-Password!42');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue($user->organization->fresh()->authorization_initialized);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'New-Password!42'])->assertOk();
        $this->expectException(ValidationException::class);
        $links->consume('activation', $user->email, $token, 'Other-Password!42');
    }

    public function test_activation_expiration_new_issuance_and_shared_rolling_email_limits(): void
    {
        Mail::fake();
        $user = app(IdentityService::class)->provisionInitial(['first_name' => 'Initial', 'last_name' => 'Admin', 'email' => 'initial@example.test'], self::PASSWORD);
        $links = app(AccountLinks::class);
        $links->issue($user, 'activation', 'cli-bootstrap', bootstrap: true);
        $first = $this->sentToken();
        try {
            $links->issue($user, 'activation', 'cli-bootstrap', bootstrap: true);
            $this->fail('60 second cooldown bypassed.');
        } catch (BusinessRuleViolation) {
            Mail::assertSentCount(1);
        }
        $this->travel(61)->seconds();
        $links->issue($user, 'activation', 'cli-bootstrap', bootstrap: true);
        $second = $this->sentToken();
        try {
            $links->consume('activation', $user->email, $first, 'New-Password!42');
            $this->fail('Replaced link remained valid.');
        } catch (ValidationException) {
            $this->assertNull($user->fresh()->email_verified_at);
        }
        $this->travel(25)->hours();
        try {
            $links->consume('activation', $user->email, $second, 'New-Password!42');
            $this->fail('Expired activation remained valid.');
        } catch (ValidationException) {
            $this->assertNull($user->fresh()->email_verified_at);
        }
        $this->travelBack();
        $this->travel(122)->seconds();
        $links->issue($user, 'activation', 'cli-bootstrap', bootstrap: true);
        $this->travel(61)->seconds();
        try {
            $links->issue($user, 'activation', 'cli-bootstrap', bootstrap: true);
            $this->fail('Rolling limit bypassed.');
        } catch (BusinessRuleViolation) {
            Mail::assertSentCount(3);
            $this->assertSame(3, DB::table('account_email_deliveries')->count());
        }
    }

    public function test_reset_invalidates_prior_sessions_is_single_use_and_expires_after_thirty_minutes(): void
    {
        Mail::fake();
        $location = $this->activeRestaurant();
        $manager = $this->user('manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $target = $this->user();
        $this->role($target, BusinessRole::Employee, $location);
        $links = app(AccountLinks::class);
        $this->postJson('/api/v1/auth/login', ['email' => $target->email, 'password' => self::PASSWORD])->assertOk();
        $links->issue($target, 'reset', '127.0.0.1', $manager, $location);
        $token = $this->sentToken();
        $version = $target->auth_version;
        $links->consume('reset', $target->email, $token, 'New-Password!42');
        $this->assertGreaterThan($version, $target->fresh()->auth_version);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        try {
            $links->consume('reset', $target->email, $token, 'Other-Password!42');
            $this->fail('Single-use reset was reused.');
        } catch (ValidationException) {
            $this->assertTrue(Hash::check('New-Password!42', $target->fresh()->password));
        }
        $this->travel(61)->seconds();
        $links->issue($target, 'reset', '127.0.0.1', $manager, $location);
        $token = $this->sentToken();
        $this->travel(31)->minutes();
        $this->expectException(ValidationException::class);
        $links->consume('reset', $target->email, $token, 'Other-Password!42');
    }

    public function test_reset_supervisor_checks_all_target_roles_and_email_changes_revoke_verification_and_sessions(): void
    {
        $location = $this->activeRestaurant();
        $other = $this->activeRestaurant('R2');
        $manager = $this->user('manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $target = $this->user();
        $this->role($target, BusinessRole::Employee, $location);
        $this->assertTrue(app(AccountLinks::class)->canSend($manager, $location, $target));
        $this->role($target, BusinessRole::RestaurantManager, $other);
        $this->assertFalse(app(AccountLinks::class)->canSend($manager, $location, $target));
        $old = $target->auth_version;
        $target = $this->updateFixture($target, ['email' => 'changed@example.test']);
        $this->assertNull($target->fresh()->email_verified_at);
        $this->assertGreaterThan($old, $target->fresh()->auth_version);
    }

    public function test_activation_and_reset_share_the_same_three_message_account_budget(): void
    {
        Mail::fake();
        $restaurant = $this->activeRestaurant();
        $manager = $this->user('manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $restaurant);
        $target = $this->user();
        $target = $this->updateFixture($target, ['email_verified_at' => null]);
        $this->role($target, BusinessRole::Employee, $restaurant);
        $links = app(AccountLinks::class);
        $links->issue($target, 'activation', '127.0.0.1', $manager, $restaurant);
        $links->consume('activation', $target->email, $this->sentToken(), 'New-Password!42');
        $this->travel(61)->seconds();
        $links->issue($target, 'reset', '127.0.0.1', $manager, $restaurant);
        $this->travel(61)->seconds();
        $links->issue($target, 'reset', '127.0.0.1', $manager, $restaurant);
        $this->travel(61)->seconds();
        try {
            $links->issue($target, 'reset', '127.0.0.1', $manager, $restaurant);
            $this->fail('Combined activation/reset budget was exceeded.');
        } catch (BusinessRuleViolation) {
            Mail::assertSentCount(3);
        }
    }

    public function test_email_delivery_uses_refreshed_recipient_not_stale_user_model(): void
    {
        Mail::fake();
        $location = $this->activeRestaurant();
        $manager = $this->user('manager@example.test');
        $this->role($manager, BusinessRole::RestaurantManager, $location);
        $target = $this->user();
        $this->role($target, BusinessRole::Employee, $location);
        $this->updateFixture($target, ['email' => 'changed@example.test']);
        app(AccountLinks::class)->issue($target, 'activation', '127.0.0.1', $manager, $location);
        Mail::assertSent(IdentityLink::class, fn (IdentityLink $mail) => $mail->hasTo('changed@example.test') && ! $mail->hasTo($target->email));
    }
}
