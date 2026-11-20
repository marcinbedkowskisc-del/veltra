<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\AuthorizationTestCase;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class OrganizationCapabilityTest extends AuthorizationTestCase
{
    private Location $kitchen;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->kitchen = Location::where('code', 'CK1')->firstOrFail();
        $this->administrator = $this->user('capability-admin@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->kitchen, $this->administrator->id);
        $this->selectContext($this->administrator, $this->kitchen);
    }

    public function test_status_is_organization_scoped_disabled_and_not_ready_without_granting_data_access(): void
    {
        $this->getJson($this->capabilityPath())->assertOk()
            ->assertJsonPath('data.capability', CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ)
            ->assertJsonPath('data.scope', 'ORGANIZATION')
            ->assertJsonPath('data.readiness', 'NOT_READY')
            ->assertJsonPath('data.configured_state', 'DISABLED')
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.state_version', 0)
            ->assertJsonPath('data.operations', ['list', 'detail'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(0, DB::table('organization_capability_states')->count());
        $this->assertSame(0, DB::table('organization_capability_events')->count());
        $this->assertSame(0, DB::table('location_module_states')->count());

        $rawModules = $this->getJson('/api/v1/security/modules/catalog')->assertOk()->json('data');
        $rawModule = collect($rawModules)->firstWhere('module', 'RAW_MATERIALS');
        $this->assertSame('NOT_READY', $rawModule['readiness']);
    }

    public function test_not_ready_activation_is_rejected_and_audited_without_creating_state(): void
    {
        $this->putJson($this->capabilityPath(), $this->body(true))->assertConflict()
            ->assertJsonPath('error.code', 'CAPABILITY_NOT_READY');

        $attempt = DB::table('organization_capability_events')->firstOrFail();
        $this->assertSame('activation_attempt', $attempt->event_kind);
        $this->assertSame('rejected', $attempt->outcome);
        $this->assertSame('capability_not_ready', $attempt->detail_code);
        $this->assertFalse((bool) $attempt->previous_enabled);
        $this->assertFalse((bool) $attempt->effective_enabled);
        $this->assertFalse((bool) $attempt->state_changed);
        $this->assertSame(0, DB::table('organization_capability_states')->count());
    }

    public function test_disabling_an_absent_capability_is_an_audited_idempotent_noop(): void
    {
        $response = $this->putJson($this->capabilityPath(), $this->body(false))->assertOk();
        $response->assertJsonPath('data.capability', CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ)
            ->assertJsonPath('data.previous_state', 'DISABLED')
            ->assertJsonPath('data.requested_state', 'DISABLED')
            ->assertJsonPath('data.effective_state', 'DISABLED')
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.changed', false)
            ->assertJsonPath('data.state_version', 0);

        $attempt = DB::table('organization_capability_events')->firstOrFail();
        $this->assertSame('accepted', $attempt->outcome);
        $this->assertSame('activation_idempotent', $attempt->detail_code);
        $this->assertSame(0, DB::table('organization_capability_events')->where('event_kind', 'activation_transition')->count());
        $this->assertSame(0, DB::table('organization_capability_states')->count());
    }

    public function test_activation_deactivation_and_idempotent_retry_increment_only_real_transitions(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());

        $this->putJson($this->capabilityPath(), $this->body(true, 0))->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.state_version', 1)
            ->assertJsonPath('data.available', true);
        $state = DB::table('organization_capability_states')->firstOrFail();

        $this->putJson($this->capabilityPath(), $this->body(true, 1))->assertOk()
            ->assertJsonPath('data.changed', false)
            ->assertJsonPath('data.state_version', 1);
        $this->assertSame($state->updated_at, DB::table('organization_capability_states')->where('id', $state->id)->value('updated_at'));

        $this->putJson($this->capabilityPath(), $this->body(false, 1))->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.state_version', 2)
            ->assertJsonPath('data.available', false);

        $this->assertSame(3, DB::table('organization_capability_events')->where('event_kind', 'activation_attempt')->count());
        $this->assertSame(2, DB::table('organization_capability_events')->where('event_kind', 'activation_transition')->count());
        $this->assertSame(2, (int) DB::table('organization_capability_states')->value('state_version'));
        $this->assertFalse((bool) DB::table('organization_capability_states')->value('enabled'));
        $this->assertSame(0, DB::table('location_module_states')->count());
    }

    public function test_stale_version_is_audited_and_cannot_overwrite_a_newer_transition(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        $this->putJson($this->capabilityPath(), $this->body(true, 0))->assertOk();

        $this->putJson($this->capabilityPath(), $this->body(false, 0))->assertConflict()
            ->assertJsonPath('error.code', 'CAPABILITY_STATE_VERSION_CONFLICT');

        $this->assertTrue((bool) DB::table('organization_capability_states')->value('enabled'));
        $this->assertSame(1, (int) DB::table('organization_capability_states')->value('state_version'));
        $rejection = DB::table('organization_capability_events')->where('outcome', 'rejected')->firstOrFail();
        $this->assertSame('state_version_conflict', $rejection->detail_code);
        $this->assertFalse((bool) $rejection->state_changed);
        $this->assertSame(1, (int) $rejection->previous_state_version);
        $this->assertSame(1, (int) $rejection->result_state_version);
    }

    public function test_boss_without_administrator_authority_cannot_read_or_configure_capability(): void
    {
        $boss = $this->user('capability-boss@example.test');
        $this->role($boss, BusinessRole::Boss);
        app(MembershipService::class)->assign($boss, $this->kitchen, $boss->id);
        $this->selectContext($boss, $this->kitchen);

        $this->getJson($this->capabilityPath())->assertForbidden();
        $this->getJson($this->capabilityPath().'/history')->assertForbidden();
        $this->putJson($this->capabilityPath(), $this->body(false))->assertForbidden();

        $rejection = DB::table('organization_capability_events')->firstOrFail();
        $this->assertSame('rejected', $rejection->outcome);
        $this->assertSame('administrator_authorization_failed', $rejection->detail_code);
        $this->assertSame($boss->id, $rejection->actor_id);
        $this->assertSame(0, DB::table('organization_capability_states')->count());

        $this->role($boss, BusinessRole::Administrator);
        $this->selectContext($boss, $this->kitchen);
        $this->getJson($this->capabilityPath())->assertOk()
            ->assertJsonPath('data.configured_state', 'DISABLED');
        $this->putJson($this->capabilityPath(), $this->body(false))->assertOk()
            ->assertJsonPath('data.changed', false);
    }

    public function test_revoked_administrator_role_is_rechecked_for_status_history_and_mutation(): void
    {
        $roleId = DB::table('role_assignments')->where('user_id', $this->administrator->id)
            ->where('role', BusinessRole::Administrator->value)->value('id');
        $this->assertNotNull($roleId);
        $otherAdministrator = $this->user('capability-role-revoker@example.test');
        $this->role($otherAdministrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($otherAdministrator, $this->kitchen, $otherAdministrator->id);
        app(RoleAdministration::class)->revoke(
            $otherAdministrator,
            $this->kitchen,
            RoleAssignment::findOrFail($roleId),
            self::PASSWORD,
        );

        $this->getJson($this->capabilityPath())->assertForbidden();
        $this->getJson($this->capabilityPath().'/history')->assertForbidden();
        $this->putJson($this->capabilityPath(), $this->body(true))->assertForbidden();

        $this->assertSame(0, DB::table('organization_capability_states')->count());
        $this->assertSame(1, DB::table('organization_capability_events')->count());
        $this->assertSame('rejected', DB::table('organization_capability_events')->value('outcome'));
        $this->assertSame('administrator_authorization_failed', DB::table('organization_capability_events')->value('detail_code'));
    }

    public function test_revoked_membership_invalidates_the_selected_context_before_capability_access(): void
    {
        $membership = app(MembershipService::class)->available($this->administrator)
            ->where('location_id', $this->kitchen->id)->firstOrFail();
        app(MembershipService::class)->deactivate($membership, 'Capability authorization test', $this->administrator->id);

        $this->getJson($this->capabilityPath())->assertForbidden();
        $this->getJson($this->capabilityPath().'/history')->assertForbidden();
        $this->putJson($this->capabilityPath(), $this->body(false))->assertForbidden();
        $this->assertSame(0, DB::table('organization_capability_events')->count());
        $this->assertSame(0, DB::table('organization_capability_states')->count());
    }

    public function test_inactive_and_unverified_actors_cannot_read_or_change_capability_state(): void
    {
        $this->updateFixture($this->administrator, ['is_active' => false]);
        $this->getJson($this->capabilityPath())->assertUnauthorized();

        $unverified = $this->user('capability-unverified@example.test');
        $this->role($unverified, BusinessRole::Administrator);
        app(MembershipService::class)->assign($unverified, $this->kitchen, $unverified->id);
        $this->selectContext($unverified, $this->kitchen);
        $this->updateFixture($unverified, ['email_verified_at' => null]);

        $this->getJson($this->capabilityPath())->assertUnauthorized();
        $this->putJson($this->capabilityPath(), $this->body(false))->assertUnauthorized();
        $this->assertSame(0, DB::table('organization_capability_events')->count());
        $this->assertSame(0, DB::table('organization_capability_states')->count());
    }

    public function test_inactive_operating_location_is_rejected_as_an_invalid_capability_context(): void
    {
        $context = $this->kitchen('CAPABILITY_INACTIVE_CONTEXT');
        app(MembershipService::class)->assign($this->administrator, $context, $this->administrator->id);
        $this->selectContext($this->administrator, $context);
        $this->updateFixture($context, ['is_active' => false]);

        $this->getJson($this->capabilityPath())->assertForbidden();
        $this->getJson($this->capabilityPath().'/history')->assertForbidden();
        $this->putJson($this->capabilityPath(), $this->body(false))->assertForbidden();
        $this->assertSame(0, DB::table('organization_capability_events')->count());
        $this->assertSame(0, DB::table('organization_capability_states')->count());
    }

    public function test_capability_status_and_history_are_isolated_between_organizations(): void
    {
        $this->putJson($this->capabilityPath(), $this->body(false))->assertOk();
        $this->assertSame(1, DB::table('organization_capability_events')->count());

        $this->withForeignOrganizationFixture(function (Location $foreignLocation): void {
            $foreignAdmin = User::on('pgsql_setup')->create([
                'organization_id' => $foreignLocation->organization_id,
                'first_name' => 'Foreign',
                'last_name' => 'Administrator',
                'email' => 'foreign-capability-admin@example.test',
                'password' => self::PASSWORD,
                'is_active' => true,
            ]);
            $foreignAdmin->forceFill(['email_verified_at' => now()])->save();
            $foreignAdmin = User::on('pgsql')->findOrFail($foreignAdmin->id);
            $this->role($foreignAdmin, BusinessRole::Administrator);
            LocationMembership::on('pgsql_setup')->create([
                'organization_id' => $foreignLocation->organization_id,
                'user_id' => $foreignAdmin->id,
                'location_id' => $foreignLocation->id,
                'is_active' => true,
                'assigned_at' => now(),
            ]);

            $this->selectContext($foreignAdmin, $foreignLocation);
            $this->getJson($this->capabilityPath())->assertOk()
                ->assertJsonPath('data.configured_state', 'DISABLED')
                ->assertJsonPath('data.state_version', 0);
            $this->getJson($this->capabilityPath().'/history')->assertOk()
                ->assertJsonPath('meta.total', 0);
            $this->assertSame(1, DB::table('organization_capability_events')->count());
        }, truncateAfterOperation: true);
    }

    public function test_status_history_are_admin_only_and_history_is_paged_with_only_capability_audit_fields(): void
    {
        $this->putJson($this->capabilityPath(), $this->body(false))->assertOk();
        $this->putJson($this->capabilityPath(), $this->body(false))->assertOk();

        $history = $this->getJson($this->capabilityPath().'/history?per_page=1&page=1')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(1, $history->json('data'));
        $this->assertSame(2, $history->json('meta.total'));
        $this->assertSame(1, $history->json('meta.per_page'));
        $this->assertEqualsCanonicalizing([
            'id', 'organization_id', 'capability_key', 'operation_id', 'event_kind',
            'outcome', 'actor_id', 'operating_location_id', 'previous_enabled',
            'requested_enabled', 'effective_enabled', 'expected_state_version',
            'previous_state_version', 'result_state_version', 'state_changed',
            'detail_code', 'occurred_at',
        ], array_keys($history->json('data.0')));
        $this->assertArrayNotHasKey('current_password', $history->json('data.0'));
    }

    public function test_route_does_not_accept_client_operation_ids_or_direct_identifier_lookups(): void
    {
        $this->putJson($this->capabilityPath(), [...$this->body(false), 'operation_id' => 'client-controlled'])
            ->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->getJson($this->capabilityPath().'/'.fake()->uuid())->assertNotFound();

        $this->assertSame(0, DB::table('organization_capability_events')->count());
        $this->assertSame(0, DB::table('organization_capability_states')->count());
    }

    public function test_password_confirmation_is_rate_limited_and_each_rejection_is_audited(): void
    {
        RateLimiter::clear('organization-capability-administrator-confirm:'.$this->administrator->id.'|127.0.0.1');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->putJson($this->capabilityPath(), $this->body(true, 0, 'incorrect'))->assertForbidden();
        }
        $this->putJson($this->capabilityPath(), $this->body(true, 0, 'incorrect'))->assertTooManyRequests();

        $this->assertSame(6, DB::table('organization_capability_events')
            ->where('event_kind', 'activation_attempt')->where('outcome', 'rejected')->count());
        $this->assertSame(0, DB::table('organization_capability_states')->count());
        $this->assertSame(0, DB::table('organization_capability_events')->where('event_kind', 'activation_transition')->count());
    }

    public function test_request_without_operating_context_is_rejected_before_service_audit(): void
    {
        Auth::forgetGuards();
        Auth::guard('web')->login($this->administrator);
        $this->withSession([
            'identity_version' => $this->administrator->auth_version,
            'operating_membership_id' => null,
        ]);

        $this->getJson($this->capabilityPath())->assertForbidden();
        $this->assertSame(0, DB::table('organization_capability_events')->count());
    }

    /**
     * @return array{enabled: bool, expected_state_version: int, current_password: string}
     */
    private function body(bool $enabled, int $version = 0, ?string $password = null): array
    {
        return [
            'enabled' => $enabled,
            'expected_state_version' => $version,
            'current_password' => $password ?? self::PASSWORD,
        ];
    }

    private function capabilityPath(): string
    {
        return '/api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ';
    }

    private function selectContext(User $user, Location $location): void
    {
        Auth::forgetGuards();
        Auth::guard('web')->login($user);
        $this->withSession([
            'identity_version' => $user->auth_version,
            'operating_membership_id' => null,
        ]);
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertOk();
    }
}
