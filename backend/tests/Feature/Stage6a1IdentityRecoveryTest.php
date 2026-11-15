<?php

namespace Tests\Feature;

use App\Console\Commands\RecoverStage6a1Identity;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\AccountLinks;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Mail\IdentityLink;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\AuthorizationTestCase;

class Stage6a1IdentityRecoveryTest extends AuthorizationTestCase
{
    private User $initial;

    private Stage6a1RecoveryTestCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->initial = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Initial', 'last_name' => 'Fixture', 'email' => 'initial@example.test',
        ], self::PASSWORD);
        $primary = $this->kitchen();
        app(RoleAdministration::class)->bootstrapInitial($this->initial);
        app(MembershipService::class)->assign($this->initial, $primary);
        $this->app->detectEnvironment(fn () => 'local');
        config([
            'app.url' => 'http://localhost:8001',
            'app.frontend_url' => 'http://localhost:5174',
            'development.management_enabled' => false,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => 'mailpit',
            'mail.mailers.smtp.port' => 1025,
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);
        $this->command = new Stage6a1RecoveryTestCommand;
        $this->command->setLaravel($this->app);
        $this->command->identity = [
            'compose_project' => 'veltra-stage6a1',
            'postgres_volume' => 'veltra-stage6a1_postgres-data',
            'container_hostname' => gethostname(),
            'expires_at' => now()->addHours(2)->timestamp,
            'organization_id' => $this->initial->organization_id,
            'user_id' => $this->initial->id,
            'database_oid' => '16384',
            'server_address' => gethostbyname('postgres'),
            'mailpit_address' => gethostbyname('mailpit'),
        ];
        // Only attested container/database metadata is simulated; all writes use isolated PostgreSQL.
        $this->command->database = (object) [
            'configured_name' => 'veltra_stage6a1', 'name' => 'veltra_stage6a1',
            'role' => 'veltra_app', 'oid' => '16384',
            'server_address' => gethostbyname('postgres'), 'server_port' => 5432,
        ];
    }

    private function execute(array $input = [], array $answers = ['ODZYSKAJ STAGE6A1'], bool $interactive = true): CommandTester
    {
        $tester = new CommandTester($this->command);
        $tester->setInputs($answers);
        $tester->execute($input, ['interactive' => $interactive]);

        return $tester;
    }

    private function token(): string
    {
        $mail = Mail::sent(IdentityLink::class)->last();
        preg_match('/token=([^\s]+)/', $mail->render(), $matches);

        return rawurldecode($matches[1]);
    }

    public function test_recovery_replaces_the_link_audits_and_preserves_identity_permissions_and_readiness(): void
    {
        $links = app(AccountLinks::class);
        $links->issue($this->initial, 'activation', 'cli-bootstrap', bootstrap: true);
        $old = $this->token();
        $before = $this->initial->fresh()->getRawOriginal();
        $this->travel(61)->seconds();

        $tester = $this->execute();

        $this->assertSame(0, $tester->getStatusCode());
        Mail::assertSentCount(2);
        $new = $this->token();
        $this->assertNotSame($old, $new);
        foreach ([$old, $new, self::PASSWORD, $before['password']] as $secret) {
            $this->assertStringNotContainsString($secret, $tester->getDisplay());
        }
        $this->assertTrue($before === $this->initial->fresh()->getRawOriginal(), 'Recovery must not modify the identity.');
        $this->assertDatabaseCount('role_assignments', 1);
        $this->assertDatabaseCount('location_memberships', 1);
        $this->assertDatabaseCount('location_module_states', 0);
        $this->assertFalse(app(ModuleCatalog::class)->find('RAW_MATERIALS')->ready);
        $this->assertDatabaseHas('security_events', [
            'action' => 'initial_identity.local_recovery', 'outcome' => 'accepted',
            'target_id' => $this->initial->id, 'actor_id' => null,
        ]);
        try {
            $links->consume('activation', $this->initial->email, $old, 'Fixture-New-Password!42');
            $this->fail('The replaced link remained valid.');
        } catch (ValidationException) {
            $this->assertNull($this->initial->fresh()->email_verified_at);
        }
        $links->consume('activation', $this->initial->email, $new, 'Fixture-New-Password!42');
        $this->withSession(['_token' => 'fixture-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'fixture-csrf-token')
            ->postJson('/api/v1/auth/login', [
                'email' => $this->initial->email, 'password' => 'Fixture-New-Password!42',
            ])->assertOk();
        $this->assertSame(1, $this->execute()->getStatusCode());
    }

    public function test_check_confirmation_and_noninteractive_execution_never_write_or_send(): void
    {
        $before = DB::table('security_events')->count();
        $this->assertSame(0, $this->execute(['--check' => true], [], false)->getStatusCode());
        $this->assertSame(1, $this->execute([], ['nie'])->getStatusCode());
        $this->assertSame(1, $this->execute([], [], false)->getStatusCode());
        $this->assertSame($before, DB::table('security_events')->count());
        $this->assertSame(0, DB::table('activation_tokens')->where('email', $this->initial->email)->count());
        Mail::assertNothingSent();
    }

    public function test_production_staging_testing_and_execution_outside_container_are_rejected(): void
    {
        foreach (['production', 'staging', 'testing'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            $this->assertSame(1, $this->execute()->getStatusCode());
        }
        $this->app->detectEnvironment(fn () => 'local');
        $this->command->container = false;
        $this->assertSame(1, $this->execute()->getStatusCode());
        Mail::assertNothingSent();
    }

    public function test_wrong_database_instance_attestation_and_remote_mail_configuration_are_rejected(): void
    {
        $original = $this->command->identity;
        foreach ([
            'compose_project' => 'veltra',
            'postgres_volume' => 'veltra_postgres-data',
            'container_hostname' => 'another-container',
            'organization_id' => '00000000-0000-4000-8000-000000000001',
            'user_id' => '00000000-0000-4000-8000-000000000002',
            'database_oid' => '99999',
            'server_address' => '172.22.0.99',
            'mailpit_address' => '192.0.2.1',
            'expires_at' => now()->subSecond()->timestamp,
        ] as $key => $value) {
            $this->command->identity = array_replace($original, [$key => $value]);
            $this->assertSame(1, $this->execute()->getStatusCode(), $key);
        }
        $this->command->identity = $original;
        foreach (['configured_name', 'name', 'role'] as $key) {
            $before = $this->command->database->$key;
            $this->command->database->$key = 'unapproved';
            $this->assertSame(1, $this->execute()->getStatusCode(), $key);
            $this->command->database->$key = $before;
        }
        $this->command->nativeIdentity = true;
        $this->assertSame(1, $this->execute()->getStatusCode());
        $this->command->nativeIdentity = false;
        config(['mail.mailers.smtp.url' => 'smtp://remote.example:1025']);
        $this->assertSame(1, $this->execute()->getStatusCode());
        Mail::assertNothingSent();
    }

    public function test_activated_identity_and_completed_bootstrap_are_rejected(): void
    {
        $this->initial = $this->updateFixture($this->initial, ['email_verified_at' => now()]);
        $this->assertSame(1, $this->execute()->getStatusCode());
        $this->updateFixture(Organization::firstOrFail(), ['authorization_initialized' => true]);
        $this->assertSame(1, $this->execute()->getStatusCode());
        Mail::assertNothingSent();
    }

    public function test_additional_accounts_and_multiple_initial_identities_are_rejected(): void
    {
        $extra = $this->user('extra@example.test');
        $this->assertSame(1, $this->execute()->getStatusCode());
        $this->withSetupTransaction(function (Connection $connection) use ($extra): void {
            $connection->statement('DROP INDEX users_single_initial_identity');
            $connection->statement('ALTER TABLE users DISABLE TRIGGER users_preserve_identity');
            $connection->table('users')->where('id', $extra->id)->update(['is_initial_identity' => true]);
            $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $connection->statement('ALTER TABLE users ENABLE TRIGGER users_preserve_identity');
        });
        try {
            $this->assertSame(1, $this->execute()->getStatusCode());
        } finally {
            $this->withSetupTransaction(function (Connection $connection) use ($extra): void {
                $connection->statement('ALTER TABLE users DISABLE TRIGGER users_preserve_identity');
                $connection->table('users')->where('id', $extra->id)->update(['is_initial_identity' => false]);
                $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
                $connection->statement('ALTER TABLE users ENABLE TRIGGER users_preserve_identity');
                $connection->statement('CREATE UNIQUE INDEX users_single_initial_identity ON users (is_initial_identity) WHERE is_initial_identity');
            });
        }
        Mail::assertNothingSent();
    }

    public function test_retries_observe_existing_limits_and_replace_only_the_last_link(): void
    {
        $this->assertSame(0, $this->execute()->getStatusCode());
        $first = $this->token();
        $this->assertSame(1, $this->execute()->getStatusCode());
        Mail::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->assertSame(0, $this->execute()->getStatusCode());
        $this->assertNotSame($first, $this->token());
        $this->assertSame(1, DB::table('activation_tokens')->where('email', $this->initial->email)->count());
        $this->assertSame(2, DB::table('security_events')->where('action', 'initial_identity.local_recovery')
            ->where('outcome', 'accepted')->count());
        $this->assertSame(1, DB::table('security_events')->where('action', 'initial_identity.local_recovery')
            ->where('outcome', 'rejected')->count());
    }

    public function test_state_is_checked_again_after_the_owner_confirmation(): void
    {
        $this->command->onRecheck = function (): void {
            $this->initial = $this->updateFixture($this->initial, ['email_verified_at' => now()]);
        };
        $this->assertSame(1, $this->execute()->getStatusCode());
        $this->assertSame(0, DB::table('security_events')->where('action', 'initial_identity.local_recovery')->count());
        Mail::assertNothingSent();
    }

    public function test_additional_location_and_inactive_identity_are_rejected(): void
    {
        $this->initial = $this->updateFixture($this->initial, ['is_active' => false]);
        $this->assertSame(1, $this->execute()->getStatusCode());
        $this->initial = $this->updateFixture($this->initial, ['is_active' => true]);
        $this->kitchen('EXTRA');
        $this->assertSame(1, $this->execute()->getStatusCode());
        Mail::assertNothingSent();
    }

    public function test_smtp_failure_rolls_back_replacement_and_allows_safe_retry(): void
    {
        app(AccountLinks::class)->issue($this->initial, 'activation', 'cli-bootstrap', bootstrap: true);
        $before = DB::table('activation_tokens')->where('email', $this->initial->email)->first();
        $this->travel(61)->seconds();
        Log::spy();
        Mail::shouldReceive('to')->once()->with($this->initial->email)->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new TransportException('Synthetic SMTP failure'));
        $this->assertSame(1, $this->execute()->getStatusCode());
        $this->assertTrue($before == DB::table('activation_tokens')->where('email', $this->initial->email)->first());
        $this->assertSame(0, DB::table('security_events')->where('action', 'initial_identity.local_recovery')
            ->where('outcome', 'accepted')->count());
        $this->assertDatabaseHas('security_events', ['action' => 'initial_identity.local_recovery', 'outcome' => 'rejected']);
        Log::shouldHaveReceived('error')->once()->with('Identity email SMTP delivery failed; retry requires a new link.');
        Mail::swap(new MailManager($this->app));
        Mail::fake();
        $this->assertSame(0, $this->execute()->getStatusCode());
        Mail::assertSentCount(1);
    }
}

class Stage6a1RecoveryTestCommand extends RecoverStage6a1Identity
{
    public array $identity;

    public object $database;

    public bool $container = true;

    public bool $nativeIdentity = false;

    public ?Closure $onRecheck = null;

    private int $instanceChecks = 0;

    protected function assertInstance(): array
    {
        if (++$this->instanceChecks === 2 && $this->onRecheck !== null) {
            ($this->onRecheck)();
        }

        return parent::assertInstance();
    }

    protected function insideContainer(): bool
    {
        return $this->container;
    }

    protected function readIdentity(): array
    {
        return $this->nativeIdentity ? parent::readIdentity() : $this->identity;
    }

    protected function databaseIdentity(): object
    {
        return $this->database;
    }
}
