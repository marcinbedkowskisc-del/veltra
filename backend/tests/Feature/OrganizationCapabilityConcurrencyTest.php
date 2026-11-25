<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\AuthorizationTestCase;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class OrganizationCapabilityConcurrencyTest extends AuthorizationTestCase
{
    public function test_concurrent_expected_version_zero_requests_serialize_to_one_transition_and_one_conflict(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        $this->activeRestaurant();
        $organization = Organization::firstOrFail();
        $kitchen = Location::where('code', 'CK1')->firstOrFail();
        $actor = $this->provisionedUser('capability-concurrency@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($actor, $kitchen, $actor->id);

        $processes = [];
        try {
            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            [$first, $firstName] = $this->startProcess($actor, $kitchen);
            $processes[] = $first;
            $this->assertWaitingOnOrganizationLock($first, $firstName);
            [$second, $secondName] = $this->startProcess($actor, $kitchen);
            $processes[] = $second;
            $this->assertWaitingOnOrganizationLock($second, $secondName);

            DB::commit();
            $results = [$this->finishProcess($first), $this->finishProcess($second)];
            $accepted = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 200));
            $conflicted = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409));

            $this->assertCount(1, $accepted);
            $this->assertCount(1, $conflicted);
            $this->assertSame('CAPABILITY_STATE_VERSION_CONFLICT', $conflicted[0]['code']);
            $this->assertSame(1, (int) DB::table('organization_capability_states')->value('state_version'));
            $this->assertTrue((bool) DB::table('organization_capability_states')->value('enabled'));
            $this->assertSame(1, DB::table('organization_capability_events')
                ->where('event_kind', 'activation_transition')->count());
            $this->assertSame(2, DB::table('organization_capability_events')
                ->where('event_kind', 'activation_attempt')->count());
            $this->assertSame(1, DB::table('organization_capability_events')
                ->where('outcome', 'accepted')->where('event_kind', 'activation_attempt')->count());
            $this->assertSame(1, DB::table('organization_capability_events')
                ->where('outcome', 'rejected')->where('event_kind', 'activation_attempt')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    /**
     * @return array{Process, string}
     */
    private function startProcess(User $actor, Location $context): array
    {
        $applicationName = 'capability-'.Str::lower(Str::random(10));
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/concurrent_organization_capability_configure.php'),
            $actor->id,
            $context->id,
            '0',
            $applicationName,
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
        $process->setTimeout(30);
        $process->start();

        return [$process, $applicationName];
    }

    private function assertWaitingOnOrganizationLock(Process $process, string $applicationName): void
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
}
