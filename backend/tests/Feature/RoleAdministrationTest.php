<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\BusinessRuleViolation;
use App\Models\Location;
use App\Models\RoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\AuthorizationTestCase;

class RoleAdministrationTest extends AuthorizationTestCase
{
    public function test_admin_grants_require_fresh_password_and_rejections_are_durable(): void
    {
        $actor = app(IdentityService::class)->provisionInitial(['first_name' => 'Initial', 'last_name' => 'Admin', 'email' => 'admin@example.test'], self::PASSWORD);
        $roles = app(RoleAdministration::class);
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($actor)->forceFill(['email_verified_at' => now()])->save());
        $this->assertFalse($actor->organization->fresh()->authorization_initialized);
        $this->assertFalse(RoleAssignment::where('user_id', $actor->id)->where('role', BusinessRole::Administrator)->exists());

        $context = $this->kitchen();
        app(MembershipService::class)->assign($actor, $context);
        $roles->bootstrapInitial($actor);
        $actor = $this->updateFixture($actor, ['email_verified_at' => now()]);
        $this->updateFixture($actor->organization, ['authorization_initialized' => true]);
        $this->assertTrue($actor->organization->fresh()->authorization_initialized);

        $other = $this->pendingUser();
        try {
            $roles->grant($actor, $context, $other, BusinessRole::Administrator, null, 'wrong');
            $this->fail('Fresh password confirmation was bypassed.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('security_events')->where('outcome', 'rejected')->count());
        }
        $second = $roles->grant($actor, $context, $other, BusinessRole::Administrator, null, self::PASSWORD);
        app(MembershipService::class)->assign($other, $context, $actor->id);
        $roles->revoke($actor, $context, $second, self::PASSWORD);
        $this->assertNull(RoleAssignment::where('user_id', $actor->id)->firstOrFail()->revoked_at);
        $this->assertNotNull($second->fresh()->revoked_at);
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('security_events')->update(['outcome' => 'accepted']));
    }

    public function test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql(): void
    {
        $context = $this->kitchen();
        $actor = $this->provisionedUser('person@example.test', BusinessRole::Administrator);
        $role = RoleAssignment::where('user_id', $actor->id)->firstOrFail();
        app(MembershipService::class)->assign($actor, $context, $actor->id);
        $organization = $this->updateFixture($actor->organization, ['authorization_initialized' => true]);
        $this->assertTrue($organization->authorization_initialized);
        $roles = app(RoleAdministration::class);
        try {
            $roles->revoke($actor, $context, $role, self::PASSWORD);
            $this->fail('An ordinary administrator revoked their own administrator role.');
        } catch (BusinessRuleViolation) {
            $this->assertNull($role->fresh()->revoked_at);
        }
        try {
            $roles->setAccountActive($actor, $context, $actor, false);
            $this->fail('The last administrator was disabled.');
        } catch (BusinessRuleViolation) {
            $this->assertTrue($actor->fresh()->is_active);
        }
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($actor)->update(['is_active' => false]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($role)->update(['revoked_at' => now()]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($organization)->forceFill(['authorization_initialized' => false])->save());
        $membership = $actor->memberships()->firstOrFail();
        $this->assertRuntimeDatabaseRejects('23514', fn () => app(MembershipService::class)->deactivate($membership, 'Attempted context removal'));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $this->setupModel($context)->update(['is_active' => false]));
    }

    public function test_manager_and_kitchen_hierarchy_and_protected_roles_across_locations(): void
    {
        $location = $this->activeRestaurant();
        $other = $this->activeRestaurant('R2');
        $manager = $this->provisionedUser('manager@example.test', BusinessRole::RestaurantManager, $location);
        $worker = $this->pendingUser();
        app(MembershipService::class)->assign($worker, $location);
        $service = app(RoleAdministration::class);
        $employee = $service->grant($manager, $location, $worker, BusinessRole::Employee, $location);
        $this->assertSame(BusinessRole::Employee, $employee->role);
        $service->revoke($manager, $location, $employee);
        foreach ([BusinessRole::Administrator, BusinessRole::Boss, BusinessRole::CentralKitchenChef, BusinessRole::RestaurantManager] as $role) {
            $this->assertFalse($service->canAdminister($manager, $location, $worker, $role, $role->organizationWide() ? null : $location));
        }
        $this->role($worker, BusinessRole::RestaurantManager, $other);
        $this->assertFalse($service->canAdminister($manager, $location, $worker, BusinessRole::Employee, $location));
    }

    public function test_non_administrator_cannot_grant_global_administrator_through_domain_service(): void
    {
        $location = $this->activeRestaurant();
        $manager = $this->provisionedUser('role-escalation-manager@example.test', BusinessRole::RestaurantManager, $location);
        $target = $this->pendingUser('role-escalation-target@example.test');
        $before = RoleAssignment::where('organization_id', $manager->organization_id)
            ->where('role', BusinessRole::Administrator)
            ->whereNull('location_id')
            ->whereNull('revoked_at')
            ->count();

        try {
            app(RoleAdministration::class)->grant(
                $manager,
                $location,
                $target,
                BusinessRole::Administrator,
                null,
                self::PASSWORD,
            );
            $this->fail('A location manager received a global administrator assignment.');
        } catch (AuthorizationException) {
            $this->assertSame($before, RoleAssignment::where('organization_id', $manager->organization_id)
                ->where('role', BusinessRole::Administrator)
                ->whereNull('location_id')
                ->whereNull('revoked_at')
                ->count());
        }

    }

    public function test_one_role_can_span_locations_and_another_role_requires_atomic_replacement(): void
    {
        $first = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $admin = $this->provisionedUser('role-admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $first);
        $target = $this->pendingUser('role-target@example.test');
        $firstMembership = app(MembershipService::class)->assign($target, $first);
        app(MembershipService::class)->assign($target, $second);
        $roles = app(RoleAdministration::class);
        $firstRole = $roles->grant($admin, $first, $target, BusinessRole::Employee, $first);
        $secondRole = $roles->grant($admin, $first, $target, BusinessRole::Employee, $second);

        try {
            $roles->grant($admin, $first, $target, BusinessRole::RestaurantChef, $first);
            $this->fail('A different role was granted without replacement.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertSame(__('messages.business_role_replacement_required'), $exception->getMessage());
        }
        $this->assertDatabaseRejects('23P01', fn (Connection $connection) => RoleAssignment::on('pgsql_setup')->create([
            'organization_id' => $target->organization_id, 'user_id' => $target->id,
            'role' => BusinessRole::RestaurantChef, 'location_id' => $first->id,
            'membership_id' => $firstMembership->id,
        ]));

        try {
            $roles->replace($target, $first, $target, BusinessRole::RestaurantChef, [$first->id]);
            $this->fail('A non-administrator replaced a role.');
        } catch (AuthorizationException) {
            $this->assertNull($firstRole->fresh()->revoked_at);
            $this->assertNull($secondRole->fresh()->revoked_at);
        }
        try {
            $roles->replace($admin, $first, $target, BusinessRole::CentralKitchenChef, [$first->id]);
            $this->fail('An ineligible location was accepted.');
        } catch (BusinessRuleViolation) {
            $this->assertNull($firstRole->fresh()->revoked_at);
            $this->assertNull($secondRole->fresh()->revoked_at);
        }

        $replacements = $roles->replace($admin, $first, $target, BusinessRole::RestaurantChef, [$first->id, $second->id]);
        $this->assertCount(2, $replacements);
        $this->assertNotNull($firstRole->fresh()->revoked_at);
        $this->assertNotNull($secondRole->fresh()->revoked_at);
        $this->assertSame(2, RoleAssignment::where('user_id', $target->id)->where('role', BusinessRole::RestaurantChef)->whereNull('revoked_at')->count());
        $this->assertSame(4, RoleAssignment::where('user_id', $target->id)->count());
    }

    #[DataProvider('transactionOutcomes')]
    public function test_concurrent_different_role_grants_are_serialized(bool $commit): void
    {
        $location = $this->activeRestaurant();
        $admin = $this->provisionedUser('concurrent-admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $target = $this->pendingUser('concurrent-target@example.test');
        app(MembershipService::class)->assign($target, $location);
        $process = null;
        try {
            DB::beginTransaction();
            app(RoleAdministration::class)->grant($admin, $location, $target, BusinessRole::Employee, $location);
            $name = 'veltra-role-'.bin2hex(random_bytes(6));
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/concurrent_role.php'), $admin->id, $target->id, $location->id, $name],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing'],
            );
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
            $this->assertSame($commit ? 'conflict' : 'granted', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome']);
            $this->assertSame(1, RoleAssignment::where('user_id', $target->id)->whereNull('revoked_at')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
        }
    }

    public static function transactionOutcomes(): array
    {
        return ['first commit' => [true], 'first rollback' => [false]];
    }

    public function test_role_replacement_revokes_incompatible_operational_grants(): void
    {
        $location = $this->activeRestaurant();
        $admin = $this->provisionedUser('grant-admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $target = $this->provisionedUser('grant-target@example.test', BusinessRole::Employee, $location);
        $this->app->bind(ModuleAvailability::class, fn () => new class implements ModuleAvailability
        {
            public function enabled(Location $location, string $module): bool
            {
                return true;
            }
        });
        $permissions = app(PermissionAdministration::class);
        $operational = $permissions->grant($admin, $location, $target, $location, Permission::TemperatureRegister);
        $financial = $permissions->grant($admin, $location, $target, $location, Permission::FoodcostView);

        app(RoleAdministration::class)->replace($admin, $location, $target, BusinessRole::RestaurantChef, [$location->id]);

        $this->assertNotNull($operational->fresh()->revoked_at);
        $this->assertSame($admin->id, $operational->fresh()->revoked_by);
        $this->assertNull($financial->fresh()->revoked_at);
    }
}
