<?php

namespace Tests\Feature;

use App\Domain\Authorization\AuthorizationMutationCommitException;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\OrganizationCapabilityAuditException;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityException;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class OrganizationCapabilityAuditFailureTest extends AuthorizationTestCase
{
    private Location $kitchen;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->kitchen = Location::where('code', 'CK1')->firstOrFail();
        $this->administrator = $this->provisionedUser('capability-audit-failure@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->kitchen, $this->administrator->id);
        $this->selectContext($this->administrator);
    }

    public function test_failure_during_accepted_transition_rolls_back_state_and_records_only_a_rejection(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        $this->installFailureTrigger("NEW.event_kind = 'activation_transition'");

        try {
            $this->putJson($this->capabilityPath(), $this->body(true))->assertInternalServerError();

            $this->assertSame(0, DB::table('organization_capability_states')->count());
            $this->assertSame(1, DB::table('organization_capability_events')->count());
            $this->assertSame('activation_attempt', DB::table('organization_capability_events')->value('event_kind'));
            $this->assertSame('rejected', DB::table('organization_capability_events')->value('outcome'));
            $this->assertSame(1, DB::table('security_events')->where('action', 'security.request')
                ->where('outcome', 'rejected')->count());
        } finally {
            $this->removeFailureTrigger();
        }
    }

    public function test_deferred_commit_time_constraint_failure_rolls_back_transition_and_records_rejection(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        $this->installDeferredTransitionFailureTrigger();

        try {
            $this->putJson($this->capabilityPath(), $this->body(true))->assertInternalServerError()
                ->assertJsonPath('error.code', 'CAPABILITY_AUDIT_PERSISTENCE_FAILED');

            $this->assertSame(0, DB::table('organization_capability_states')->count());
            $this->assertSame(1, DB::table('organization_capability_events')->count());
            $rejection = DB::table('organization_capability_events')->firstOrFail();
            $this->assertSame('activation_attempt', $rejection->event_kind);
            $this->assertSame('rejected', $rejection->outcome);
            $this->assertSame('operation_rejected', $rejection->detail_code);
            $this->assertFalse((bool) $rejection->effective_enabled);
            $this->assertFalse((bool) $rejection->state_changed);
            $this->assertSame(0, DB::table('organization_capability_events')
                ->where('event_kind', 'activation_transition')->count());
        } finally {
            $this->removeFailureTrigger();
        }
    }

    public function test_confirmed_commit_rollback_preserves_the_original_pdo_exception(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        $this->installDeferredTransitionFailureTrigger();

        try {
            try {
                app(OrganizationCapabilityService::class)->configure(
                    $this->administrator,
                    $this->kitchen,
                    'BOSS_RAW_CATALOG_READ',
                    Str::uuid()->toString(),
                    $this->body(true),
                );
                $this->fail('A failed deferred commit must not return success.');
            } catch (OrganizationCapabilityException $exception) {
                $this->assertSame('CAPABILITY_AUDIT_PERSISTENCE_FAILED', $exception->errorCode);
                $this->assertInstanceOf(AuthorizationMutationCommitException::class, $exception->getPrevious());
                $this->assertTrue($exception->getPrevious()->transactionRolledBack);
                $this->assertInstanceOf(\PDOException::class, $exception->getPrevious()->getPrevious());
                $this->assertSame('23514', $exception->getPrevious()->getPrevious()->getCode());
            }

            $this->assertSame(0, DB::table('organization_capability_states')->count());
            $this->assertSame(1, DB::table('organization_capability_events')->count());
            $rejection = DB::table('organization_capability_events')->firstOrFail();
            $this->assertSame('rejected', $rejection->outcome);
            $this->assertFalse((bool) $rejection->effective_enabled);
            $this->assertFalse((bool) $rejection->state_changed);
        } finally {
            $this->removeFailureTrigger();
        }
    }

    public function test_uncertain_commit_outcome_fails_closed_without_a_rejection_audit_claim(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        $this->installDeferredTransitionFailureTrigger('08006');

        try {
            $response = $this->putJson($this->capabilityPath(), $this->body(true))
                ->assertStatus(503)
                ->assertJsonPath('error.code', 'CAPABILITY_COMMIT_OUTCOME_UNKNOWN');

            $this->assertStringNotContainsString('Injected', json_encode($response->json(), JSON_THROW_ON_ERROR));
            $this->assertSame(0, DB::table('organization_capability_states')->count());
            $this->assertSame(0, DB::table('organization_capability_events')->count());
            $this->assertSame(1, DB::table('security_events')->where('action', 'security.request')
                ->where('outcome', 'rejected')->count());
        } finally {
            $this->removeFailureTrigger();
        }
    }

    public function test_rejected_attempt_audit_failure_is_explicit_and_preserves_the_original_rejection(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::notReady());
        $this->installFailureTrigger('true');

        try {
            try {
                app(OrganizationCapabilityService::class)->configure(
                    $this->administrator,
                    $this->kitchen,
                    'BOSS_RAW_CATALOG_READ',
                    Str::uuid()->toString(),
                    $this->body(true),
                );
                $this->fail('A failed audit insert must not be reported as a successful operation.');
            } catch (OrganizationCapabilityAuditException $exception) {
                $this->assertInstanceOf(OrganizationCapabilityException::class, $exception->operationFailure);
                $this->assertSame('CAPABILITY_NOT_READY', $exception->operationFailure->errorCode);
                $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
            }

            $this->putJson($this->capabilityPath(), $this->body(true))->assertInternalServerError()
                ->assertJsonPath('error.code', 'CAPABILITY_AUDIT_PERSISTENCE_FAILED');
            $this->assertSame(0, DB::table('organization_capability_states')->count());
            $this->assertSame(0, DB::table('organization_capability_events')->count());
            $this->assertSame(1, DB::table('security_events')->where('action', 'security.request')
                ->where('outcome', 'rejected')->count());
        } finally {
            $this->removeFailureTrigger();
        }
    }

    private function installFailureTrigger(string $condition): void
    {
        DB::connection('pgsql_setup')->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.test_fail_organization_capability_audit()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$function\$
            BEGIN
                IF {$condition} THEN
                    RAISE EXCEPTION 'Injected isolated audit failure' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            \$function\$;
            CREATE TRIGGER test_fail_organization_capability_audit
                BEFORE INSERT ON public.organization_capability_events
                FOR EACH ROW EXECUTE FUNCTION public.test_fail_organization_capability_audit();
            SQL);
    }

    private function installDeferredTransitionFailureTrigger(string $sqlState = '23514'): void
    {
        DB::connection('pgsql_setup')->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.test_fail_deferred_organization_capability_transition()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$function\$
            BEGIN
                IF NEW.event_kind = 'activation_transition' AND NEW.outcome = 'accepted' THEN
                    RAISE EXCEPTION 'Injected deferred commit failure' USING ERRCODE = '{$sqlState}';
                END IF;
                RETURN NULL;
            END;
            \$function\$;
            CREATE CONSTRAINT TRIGGER test_fail_deferred_organization_capability_transition
                AFTER INSERT ON public.organization_capability_events
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION public.test_fail_deferred_organization_capability_transition();
            SQL);
    }

    private function removeFailureTrigger(): void
    {
        DB::connection('pgsql_setup')->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS test_fail_organization_capability_audit ON public.organization_capability_events;
            DROP TRIGGER IF EXISTS test_fail_deferred_organization_capability_transition ON public.organization_capability_events;
            DROP FUNCTION IF EXISTS public.test_fail_organization_capability_audit();
            DROP FUNCTION IF EXISTS public.test_fail_deferred_organization_capability_transition();
            SQL);
    }

    /**
     * @return array{enabled: bool, expected_state_version: int, current_password: string}
     */
    private function body(bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'expected_state_version' => 0,
            'current_password' => self::PASSWORD,
        ];
    }

    private function capabilityPath(): string
    {
        return '/api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ';
    }

    private function selectContext(User $user): void
    {
        Auth::forgetGuards();
        Auth::guard('web')->login($user);
        $this->withSession([
            'identity_version' => $user->auth_version,
            'operating_membership_id' => null,
        ]);
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $this->kitchen->id])->assertOk();
    }
}
