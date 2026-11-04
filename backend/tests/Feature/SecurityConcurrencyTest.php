<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Identity\AccountLinks;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Models\Location;
use App\Models\PermissionGrant;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SecurityConcurrencyTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function test_permissions_and_email_limits_serialize_real_postgresql_connections(string $operation, bool $commit): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->resetTestDatabase();
        $process = null;
        try {
            Mail::fake();
            $installation = app(InstallationService::class);
            $organization = $installation->initializeOrganization(['name' => 'Concurrency', 'code' => 'CONCURRENCY']);
            $kitchen = $installation->createLocation(['name' => 'Kitchen', 'code' => 'CK', 'type' => 'CENTRAL_KITCHEN']);
            $location = $installation->createLocation(['name' => 'Restaurant', 'code' => 'R', 'type' => 'RESTAURANT']);
            $installation->assignManagingKitchen($location, $kitchen);
            $installation->updateLocation($location, ['is_active' => true]);
            $users = [];
            foreach ([BusinessRole::RestaurantManager, BusinessRole::Employee] as $i => $role) {
                $user = User::on('pgsql_setup')->create(['organization_id' => $organization->id, 'first_name' => 'Fixture', 'last_name' => 'Person', 'email' => "fixture{$i}@example.test", 'password' => 'Test-Only-Password!42']);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user = User::on('pgsql')->findOrFail($user->getKey());
                $membership = app(MembershipService::class)->assign($user, $location);
                RoleAssignment::on('pgsql_setup')->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'location_id' => $location->id, 'membership_id' => $membership->id]);
                $users[] = $user;
            }
            [$actor, $target] = $users;
            $this->app->bind(ModuleAvailability::class, fn () => new class implements ModuleAvailability
            {
                public function enabled(Location $location, string $module): bool
                {
                    return true;
                }
            });
            $permissions = app(PermissionAdministration::class);
            if ($operation === 'grant') {
                $permissions->grant($actor, $location, $target, $location, Permission::TemperatureRegister);
            }
            DB::beginTransaction();
            if ($operation === 'email') {
                app(AccountLinks::class)->issue($target, 'reset', '127.0.0.1', $actor, $location);
            } elseif ($operation === 'grant') {
                $permissions->revokeEffective($actor, $location, $target, $location, Permission::TemperatureRegister);
            } else {
                $permissions->grant($actor, $location, $target, $location, Permission::TemperatureRegister, now()->toImmutable()->addDay());
                $permissions->grant($actor, $location, $target, $location, Permission::TemperatureRegister);
            }
            $name = 'veltra-security-'.bin2hex(random_bytes(6));
            $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent_security.php'), $actor->id, $target->id, $location->id, $name, $operation], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing', 'MAIL_MAILER' => 'array']);
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
            $outcome = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            $this->assertSame($operation === 'email' && $commit ? 'rejected' : 'accepted', $outcome);
            if ($operation === 'email') {
                $this->assertSame(1, DB::table('account_email_deliveries')->count());
            } else {
                $this->assertSame($operation === 'grant' ? 1 : 0, PermissionGrant::whereNull('revoked_at')->count());
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            $this->withSetupConnection(function (Connection $connection): void {
                $connection->statement('TRUNCATE TABLE public.organizations CASCADE');
                $connection->table('activation_tokens')->delete();
                $connection->table('password_reset_tokens')->delete();
            });
        }
    }

    public static function outcomes(): array
    {
        return [
            'revoke after committed grants' => ['revoke', true],
            'revoke after rolled-back grants' => ['revoke', false],
            'explicit new grant after revoke' => ['grant', true],
            'email after commit' => ['email', true],
            'email after rollback' => ['email', false],
        ];
    }
}
