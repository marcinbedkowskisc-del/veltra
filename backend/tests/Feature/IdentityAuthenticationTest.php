<?php

namespace Tests\Feature;

use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class IdentityAuthenticationTest extends IdentityTestCase
{
    public function test_email_normalization_is_shared_for_unicode_provisioning_login_and_uniqueness(): void
    {
        $user = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Unicode', 'last_name' => 'Fixture', 'email' => ' ŻÓŁĆ@EXAMPLE.TEST ',
        ], self::PASSWORD);
        $this->assertSame('żółć@example.test', $user->email);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/v1/auth/login', ['email' => ' ŻÓŁĆ@EXAMPLE.TEST ', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.email', 'żółć@example.test');
        $this->assertDatabaseRejects('23505', fn () => $this->user('ŻÓŁĆ@EXAMPLE.TEST'));
    }

    public function test_unauthenticated_api_returns_json_without_an_accept_header(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_email_password_login_regenerates_session_and_logout_invalidates_it(): void
    {
        $user = $this->user();
        $this->withSession(['_token' => 'test-csrf-token', 'operating_membership_id' => 'old-selection']);
        $before = session()->getId();
        $this->postJson('/api/v1/auth/login', ['email' => ' PERSON@EXAMPLE.TEST ', 'password' => self::PASSWORD])
            ->assertOk()->assertExactJson(['data' => [
                'id' => $user->id, 'first_name' => 'Test', 'last_name' => 'Person', 'email' => 'person@example.test',
            ]])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotSame($before, session()->getId());
        $this->assertNull(session('operating_membership_id'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.is_initial_identity')->assertJsonMissingPath('data.organization');
        $authenticatedId = session()->getId();
        $token = session()->token();
        $this->postJson('/api/v1/auth/logout')->assertOk()->assertExactJson(['data' => null, 'message' => 'Logged out.']);
        $this->assertNotSame($authenticatedId, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_unknown_wrong_password_and_disabled_accounts_have_the_same_failure(): void
    {
        $user = $this->user();
        foreach ([
            ['person@example.test', 'wrong-password'],
            ['unknown@example.test', self::PASSWORD],
        ] as [$email, $password]) {
            $this->postJson('/api/v1/auth/login', compact('email', 'password'))
                ->assertUnauthorized()->assertExactJson(['message' => 'Invalid email or password.']);
        }
        app(IdentityService::class)->disable($user);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid email or password.']);
    }

    public function test_disabled_account_loses_access_on_the_next_protected_request(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')->assertOk();
        app(IdentityService::class)->disable($user);
        $this->getJson('/api/v1/auth/locations')->assertUnauthorized();
        $this->assertGuest('web');
        $this->assertNull(session('operating_membership_id'));
    }

    public function test_login_rate_limit_is_case_insensitive_and_ip_limit_prevents_email_rotation(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $i % 2 ? 'MISSING@EXAMPLE.TEST' : 'missing@example.test', 'password' => 'wrong',
            ])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "other{$i}@example.test", 'password' => 'wrong'])
                ->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'another@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_csrf_is_enforced_with_the_real_middleware_for_login_selection_and_logout(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $user = $this->user();
        $location = $this->kitchen();
        app(MembershipService::class)->assign($user, $location);
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(419);
        $this->withSession(['_token' => 'valid-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'wrong')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'valid-csrf-token')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $csrf = session()->token();
        $this->withHeader('X-CSRF-TOKEN', '')
            ->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', $csrf)
            ->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertOk();
        $this->withHeader('X-CSRF-TOKEN', '')->postJson('/api/v1/auth/logout')->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_sessions_and_csrf_cookies_have_expected_flags(): void
    {
        $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());
        $this->assertTrue($cookies[config('session.cookie')]->isHttpOnly());
        $this->assertFalse($cookies['XSRF-TOKEN']->isHttpOnly());
        $this->assertSame('lax', $cookies[config('session.cookie')]->getSameSite());
        $this->assertNull($cookies[config('session.cookie')]->getDomain());
        $this->assertTrue(config('session.encrypt'));
    }

    public function test_disabling_the_installation_revokes_session_access(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')->assertOk();
        $user->organization->update(['is_active' => false]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_unauthenticated_unknown_origin_and_bearer_requests_cannot_access_auth_api(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer 1|not-a-real-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withHeader('Origin', 'https://untrusted.example')
            ->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertUnauthorized();
    }

    public function test_session_identity_cannot_bypass_the_stage_1b_development_boundary(): void
    {
        $this->actingAs($this->user(), 'web');
        config(['development.management_enabled' => false]);
        $this->getJson('/api/v1/locations')->assertNotFound();
        $this->postJson('/api/v1/organization', ['name' => 'No', 'code' => 'NO'])->assertNotFound();
        $this->app->detectEnvironment(fn () => 'local');
        config(['development.management_enabled' => true, 'development.management_token' => str_repeat('t', 64)]);
        $this->getJson('/api/v1/locations')->assertForbidden();
        $this->withHeader('X-Veltra-Development-Token', str_repeat('t', 64));
        $this->getJson('/api/v1/locations')->assertOk();
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/api/v1/locations')->assertNotFound();
    }

    public function test_validation_and_responses_do_not_echo_passwords_or_internal_fields(): void
    {
        $user = $this->user();
        Log::spy();
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'bad', 'password' => self::PASSWORD])->assertUnprocessable();
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
        $this->assertStringNotContainsString($user->password, $user->toJson());
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        Log::shouldNotHaveReceived('error');
    }

    public function test_identity_query_failure_logging_never_includes_sql_bindings(): void
    {
        Log::spy();
        $exception = new QueryException('pgsql', 'insert into "users" ("password") values (?)', [self::PASSWORD], new \Exception('private failure'));
        report($exception);
        Log::shouldHaveReceived('error')->once()->with('Identity database operation failed.', ['sqlstate' => null]);
    }

    public function test_public_registration_and_password_reset_are_not_available(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertNotFound();
        $this->postJson('/api/v1/auth/forgot-password', [])->assertNotFound();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'x', 'remember' => true])->assertUnprocessable();
        $this->actingAs($this->user(), 'web')->putJson('/api/v1/auth/operating-location', ['location_id' => Str::uuid()->toString()])
            ->assertForbidden();
    }
}
