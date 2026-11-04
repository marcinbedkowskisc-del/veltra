<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdministratorConcurrencyTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function test_last_admin_is_preserved_between_independent_postgresql_transactions(bool $commit, string $operation): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->resetTestDatabase();
        $process = null;
        try {
            $installation = app(InstallationService::class);
            $installation->initializeOrganization(['name' => 'Concurrency', 'code' => 'CONCURRENCY']);
            $kitchen = $installation->createLocation(['name' => 'Kitchen', 'code' => 'CK', 'type' => 'CENTRAL_KITCHEN']);
            $first = app(IdentityService::class)->provisionInitial(['first_name' => 'First', 'last_name' => 'Admin', 'email' => 'first@example.test'], 'Test-Only-Password!42');
            $first = $this->updateFixture($first, ['email_verified_at' => now()]);
            $memberships = app(MembershipService::class);
            $memberships->assign($first, $kitchen);
            $roles = app(RoleAdministration::class);
            $firstRole = $roles->bootstrapInitial($first);
            $second = User::on('pgsql_setup')->create(['organization_id' => $first->organization_id, 'first_name' => 'Second', 'last_name' => 'Admin', 'email' => 'second@example.test', 'password' => 'Test-Only-Password!42']);
            $second->forceFill(['email_verified_at' => now()])->save();
            $second = User::on('pgsql')->findOrFail($second->getKey());
            $memberships->assign($second, $kitchen);
            $roles->grant($first, $kitchen, $second, BusinessRole::Administrator, null, 'Test-Only-Password!42');
            DB::beginTransaction();
            $roles->revoke($first, $kitchen, $firstRole, 'Test-Only-Password!42');
            $name = 'veltra-admin-'.bin2hex(random_bytes(6));
            $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent_administrator.php'), $second->id, $kitchen->id, $name, $operation], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
            $process->setTimeout(20);
            $process->start();
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock')", [$name]);
                if ($waiting || ! $process->isRunning()) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, $process->getErrorOutput());
            $commit ? DB::commit() : DB::rollBack();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame($commit ? 'rejected' : 'accepted', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome']);
            $this->assertSame(1, RoleAssignment::where('role', BusinessRole::Administrator)->whereNull('revoked_at')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('users')->whereColumn('users.id', 'role_assignments.user_id')->where('is_active', true))->count());
            $this->assertSame($commit ? 1 : 0, DB::table('security_events')->where('outcome', 'rejected')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            $this->resetTestDatabase();
        }
    }

    public static function outcomes(): array
    {
        return [
            'revoke commits' => [true, 'revoke'],
            'revoke rolls back' => [false, 'revoke'],
            'deactivate commits' => [true, 'deactivate'],
            'deactivate rolls back' => [false, 'deactivate'],
        ];
    }
}
