<?php

namespace Tests\Feature;

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Models\Location;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\AuthorizationTestCase;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class BossRawMaterialCatalogConcurrencyTest extends AuthorizationTestCase
{
    private Location $context;

    private User $boss;

    private User $administrator;

    private string $bossMembershipId;

    private RoleAssignment $bossRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->context = $this->primaryCentralKitchen();
        $this->boss = $this->user('boss-raw-catalog-concurrency@example.test');
        $this->administrator = $this->user('admin-raw-catalog-concurrency@example.test');
        $this->bossRole = $this->role($this->boss, BusinessRole::Boss);
        $this->role($this->administrator, BusinessRole::Administrator);
        $this->bossMembershipId = (string) app(MembershipService::class)
            ->assign($this->boss, $this->context, $this->administrator->id)->id;
        app(MembershipService::class)->assign($this->administrator, $this->context, $this->administrator->id);

        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->context,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(),
            [
                'enabled' => true,
                'expected_state_version' => 0,
                'current_password' => self::PASSWORD,
            ],
        );
        $this->selectContext($this->boss, $this->context);
    }

    public function test_boss_role_revocation_serializes_in_both_lock_orders(): void
    {
        $this->assertBothLockOrdersSerialize('role');
    }

    public function test_exact_operating_membership_revocation_serializes_without_reviving_old_id(): void
    {
        $this->assertBothLockOrdersSerialize('membership');
    }

    public function test_global_raw_suppliers_restriction_serializes_in_both_lock_orders(): void
    {
        $this->assertBothLockOrdersSerialize('restriction');
    }

    public function test_organization_capability_disable_serializes_in_both_lock_orders(): void
    {
        $this->assertBothLockOrdersSerialize('capability');
    }

    private function assertBothLockOrdersSerialize(string $action): void
    {
        $this->runReaderFirst($action);
        $this->assertDeniedHttpResponse($action);

        $writerExpectedVersion = $this->restoreAfterReaderFirst($action);
        $this->runWriterFirst($action, $writerExpectedVersion);
        $this->assertDeniedHttpResponse($action);
    }

    private function runReaderFirst(string $action): void
    {
        $barrier = random_int(10_000_000, 2_000_000_000);
        $this->lockBarrier($barrier);
        $processes = [];
        try {
            [$reader, $readerName] = $this->startProcess(
                'read',
                $action,
                $this->boss,
                $this->bossMembershipId,
                null,
                $this->expectedVersion($action),
                $barrier,
                false,
            );
            $processes[] = $reader;
            $this->assertWaitingOnLock($reader, $readerName);

            [$writer, $writerName] = $this->startProcess(
                'write',
                $action,
                $this->administrator,
                $this->bossMembershipId,
                $this->bossRole->id,
                $this->expectedVersion($action),
                $barrier,
                false,
            );
            $processes[] = $writer;
            $this->assertWaitingOnLock($writer, $writerName);
            $this->unlockBarrier($barrier);

            $readResult = $this->finishProcess($reader);
            $writeResult = $this->finishProcess($writer);
            $this->assertSame(200, $readResult['status']);
            $this->assertSame(0, $readResult['data']['meta']['total']);
            $this->assertSame(200, $writeResult['status']);
        } finally {
            $this->unlockBarrier($barrier);
            $this->stopProcesses($processes);
        }
    }

    private function runWriterFirst(string $action, int $expectedVersion): void
    {
        $barrier = random_int(10_000_000, 2_000_000_000);
        $this->lockBarrier($barrier);
        $processes = [];
        try {
            [$writer, $writerName] = $this->startProcess(
                'write',
                $action,
                $this->administrator,
                $this->bossMembershipId,
                $this->bossRole->id,
                $expectedVersion,
                $barrier,
                true,
            );
            $processes[] = $writer;
            $this->assertWaitingOnLock($writer, $writerName);

            [$reader, $readerName] = $this->startProcess(
                'read',
                $action,
                $this->boss,
                $this->bossMembershipId,
                null,
                $expectedVersion,
                $barrier,
                false,
            );
            $processes[] = $reader;
            $this->assertWaitingOnLock($reader, $readerName);
            $this->unlockBarrier($barrier);

            $writeResult = $this->finishProcess($writer);
            $readResult = $this->finishProcess($reader);
            $this->assertSame(200, $writeResult['status']);
            $this->assertSame($this->deniedStatus($action), $readResult['status']);
        } finally {
            $this->unlockBarrier($barrier);
            $this->stopProcesses($processes);
        }
    }

    private function restoreAfterReaderFirst(string $action): int
    {
        return match ($action) {
            'role' => $this->restoreBossRole(),
            'membership' => $this->restoreMembership(),
            'restriction' => $this->restoreRestriction(),
            'capability' => $this->restoreCapability(),
        };
    }

    private function restoreBossRole(): int
    {
        $this->bossRole = app(RoleAdministration::class)->grant(
            $this->administrator,
            $this->context,
            $this->boss,
            BusinessRole::Boss,
            null,
        );

        return 0;
    }

    private function restoreMembership(): int
    {
        $oldMembershipId = $this->bossMembershipId;
        $newMembership = app(MembershipService::class)->assign(
            $this->boss,
            $this->context,
            $this->administrator->id,
        );
        $this->assertNotSame($oldMembershipId, (string) $newMembership->id);
        $this->bossMembershipId = (string) $newMembership->id;

        $this->withSession([
            'identity_version' => $this->boss->auth_version,
            'operating_membership_id' => $oldMembershipId,
        ]);
        $this->assertDeniedHttpResponse('membership');
        $this->selectContext($this->boss, $this->context);

        return 0;
    }

    private function restoreRestriction(): int
    {
        app(BossLocationRestrictions::class)->replace(
            $this->administrator,
            $this->context,
            $this->boss->id,
            1,
            [],
            [],
            [],
        );

        return 2;
    }

    private function restoreCapability(): int
    {
        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->context,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(),
            [
                'enabled' => true,
                'expected_state_version' => 2,
                'current_password' => self::PASSWORD,
            ],
        );

        return 3;
    }

    private function expectedVersion(string $action): int
    {
        return match ($action) {
            'restriction' => (int) DB::table('boss_location_restriction_revisions')
                ->where('user_id', $this->boss->id)
                ->max('version'),
            'capability' => (int) DB::table('organization_capability_states')
                ->where('organization_id', $this->boss->organization_id)
                ->where('capability_key', CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ)
                ->value('state_version'),
            default => 0,
        };
    }

    private function assertDeniedHttpResponse(string $action): void
    {
        $this->getJson('/api/v1/boss/raw-materials')
            ->assertStatus($this->deniedStatus($action))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    private function deniedStatus(string $action): int
    {
        return $action === 'capability' ? 503 : 403;
    }

    private function selectContext(User $actor, Location $context): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        Auth::guard('web')->login($actor);
        $this->withSession([
            'identity_version' => $actor->auth_version,
            'operating_membership_id' => null,
        ]);
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $context->id])->assertOk();
    }

    /**
     * @return array{Process, string}
     */
    private function startProcess(
        string $kind,
        string $action,
        User $actor,
        string $membershipId,
        ?string $assignmentId,
        int $expectedVersion,
        int $barrier,
        bool $hold,
    ): array {
        $applicationName = 'boss-raw-'.Str::lower(Str::random(10));
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/concurrent_boss_raw_catalog.php'),
            $kind,
            $action,
            $actor->id,
            $this->context->id,
            $this->boss->id,
            $membershipId,
            (string) $this->boss->auth_version,
            $assignmentId ?? '',
            (string) $expectedVersion,
            $applicationName,
            (string) $barrier,
            $hold ? '1' : '0',
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing', 'MAIL_MAILER' => 'array']);
        $process->setTimeout(40);
        $process->start();

        return [$process, $applicationName];
    }

    private function lockBarrier(int $barrier): void
    {
        DB::select('SELECT pg_advisory_lock(?::bigint)', [$barrier]);
    }

    private function unlockBarrier(int $barrier): void
    {
        DB::select('SELECT pg_advisory_unlock(?::bigint)', [$barrier]);
    }

    private function assertWaitingOnLock(Process $process, string $applicationName): void
    {
        $deadline = microtime(true) + 10;
        $waiting = false;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (bool) DB::scalar(
                "SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock')",
                [$applicationName],
            );
            if ($waiting || ! $process->isRunning()) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->assertTrue($waiting, $process->getErrorOutput().$process->getOutput());
    }

    /**
     * @return array<string, mixed>
     */
    private function finishProcess(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<Process>  $processes
     */
    private function stopProcesses(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}
