<?php

namespace Tests\Feature;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Authorization\PersonnelAdministration;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\AccountLinks;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Mail\IdentityLink;
use App\Models\Location;
use App\Models\PermissionGrant;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\AuthorizationTestCase;

class SuperAdminAuthorizationTest extends AuthorizationTestCase
{
    public function test_initial_identity_is_the_only_super_admin_and_is_not_a_seventh_role(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $secondAdmin = $this->createAdministrator($superAdmin, $primary, 'second@example.test');
        $authorization = app(BusinessAuthorization::class);

        $this->assertCount(6, BusinessRole::cases());
        $this->assertTrue($superAdmin->fresh()->is_initial_identity);
        $this->assertTrue($authorization->isSuperAdmin($superAdmin));
        $this->assertFalse($authorization->isSuperAdmin($secondAdmin));
        $this->assertSame(
            [BusinessRole::Administrator],
            RoleAssignment::where('user_id', $superAdmin->id)->whereNull('revoked_at')->pluck('role')->all(),
        );
        $this->assertSame(0, PermissionGrant::where('user_id', $superAdmin->id)->count());
        $this->assertSame(0, DB::table('organization_permission_grants')->where('user_id', $superAdmin->id)->count());
    }

    public function test_super_admin_status_fails_closed_when_legacy_primary_identity_is_unresolved(): void
    {
        $initial = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Initial',
            'last_name' => 'Admin',
            'email' => 'legacy-initial@example.test',
        ], self::PASSWORD);
        $this->createLegacyUndesignatedKitchen($initial->organization);
        app(RoleAdministration::class)->bootstrapInitial($initial);

        $this->assertTrue($initial->fresh()->is_initial_identity);
        $this->assertFalse(app(BusinessAuthorization::class)->isSuperAdmin($initial));
        $this->assertTrue($initial->organization->fresh()->primary_central_kitchen_unresolved);
    }

    public function test_only_super_admin_manages_other_administrators_while_ordinary_accounts_remain_manageable(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $ordinaryAdmin = $this->createAdministrator($superAdmin, $primary, 'ordinary-admin@example.test');
        $targetAdmin = $this->createAdministrator($superAdmin, $primary, 'target-admin@example.test');
        $restaurant = $this->activeRestaurant();
        app(PersonnelAdministration::class)->assignMembership($superAdmin, $primary, $targetAdmin, $restaurant);
        $employee = $this->user('employee@example.test');
        app(MembershipService::class)->assign($employee, $restaurant);

        try {
            app(RoleAdministration::class)->grant($ordinaryAdmin, $primary, $targetAdmin, BusinessRole::Boss, null);
            $this->fail('Ordinary administrator changed another administrator role.');
        } catch (AuthorizationException) {
            $this->assertSame(0, RoleAssignment::where('user_id', $targetAdmin->id)->where('role', BusinessRole::Boss)->count());
        }

        try {
            app(RoleAdministration::class)->setAccountActive($ordinaryAdmin, $primary, $targetAdmin, false);
            $this->fail('Ordinary administrator changed another administrator status.');
        } catch (AuthorizationException) {
            $this->assertTrue($targetAdmin->fresh()->is_active);
        }

        try {
            app(PermissionAdministration::class)->grant(
                $ordinaryAdmin, $primary, $targetAdmin, $restaurant, Permission::FoodcostView,
            );
            $this->fail('Ordinary administrator granted a permission to another administrator.');
        } catch (AuthorizationException) {
            $this->assertSame(0, PermissionGrant::where('user_id', $targetAdmin->id)->count());
        }

        try {
            app(PersonnelAdministration::class)->removeMembership(
                $ordinaryAdmin,
                $primary,
                $targetAdmin->memberships()->where('location_id', $restaurant->id)->firstOrFail(),
            );
            $this->fail('Ordinary administrator removed another administrator membership.');
        } catch (AuthorizationException) {
            $this->assertTrue($targetAdmin->memberships()->where('location_id', $restaurant->id)->where('is_active', true)->exists());
        }

        app(RoleAdministration::class)->grant($superAdmin, $primary, $targetAdmin, BusinessRole::Boss, null);
        app(PermissionAdministration::class)->grant(
            $superAdmin, $primary, $targetAdmin, $restaurant, Permission::FoodcostView,
        );
        app(RoleAdministration::class)->setAccountActive($superAdmin, $primary, $targetAdmin, false);

        $this->assertFalse($targetAdmin->fresh()->is_active);
        $this->assertSame(1, RoleAssignment::where('user_id', $targetAdmin->id)->where('role', BusinessRole::Boss)->whereNull('revoked_at')->count());
        $this->assertSame(1, PermissionGrant::where('user_id', $targetAdmin->id)->where('permission', Permission::FoodcostView)->whereNull('revoked_at')->count());
        $this->assertGreaterThan(0, DB::table('security_events')->where('actor_id', $ordinaryAdmin->id)
            ->where('target_id', $targetAdmin->id)->where('outcome', 'rejected')->count());
        $this->assertGreaterThan(0, DB::table('security_events')->where('actor_id', $superAdmin->id)
            ->where('target_id', $targetAdmin->id)->where('outcome', 'accepted')->count());

        app(RoleAdministration::class)->grant($ordinaryAdmin, $primary, $employee, BusinessRole::RestaurantChef, $restaurant);
        $this->assertTrue(RoleAssignment::where('user_id', $employee->id)->where('role', BusinessRole::RestaurantChef)->whereNull('revoked_at')->exists());
    }

    public function test_administrators_cannot_modify_super_admin_and_super_admin_cannot_remove_protected_identity(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $ordinaryAdmin = $this->createAdministrator($superAdmin, $primary, 'ordinary@example.test');
        $superRole = RoleAssignment::where('user_id', $superAdmin->id)->where('role', BusinessRole::Administrator)->firstOrFail();

        $this->assertUnauthorized(fn () => app(RoleAdministration::class)->setAccountActive($ordinaryAdmin, $primary, $superAdmin, false));
        $this->assertUnauthorized(fn () => app(RoleAdministration::class)->changeEmail($ordinaryAdmin, $primary, $superAdmin, 'blocked@example.test'));
        $this->assertUnauthorized(fn () => app(RoleAdministration::class)->revoke($ordinaryAdmin, $primary, $superRole));
        $this->assertUnauthorized(fn () => app(RoleAdministration::class)->setAccountActive($superAdmin, $primary, $superAdmin, false));
        $this->assertUnauthorized(fn () => app(RoleAdministration::class)->revoke($superAdmin, $primary, $superRole));
        $this->assertUnauthorized(fn () => app(PersonnelAdministration::class)->removeMembership(
            $superAdmin,
            $primary,
            $superAdmin->memberships()->where('location_id', $primary->id)->firstOrFail(),
        ));

        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection->table('users')
            ->where('id', $superAdmin->id)->update(['is_active' => false]));
        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection->table('role_assignments')
            ->where('id', $superRole->id)->update(['revoked_at' => now(), 'revoked_by' => $superAdmin->id]));
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::accountDeactivate(),
            $superAdmin->id,
            fn () => DB::table('users')->where('id', $superAdmin->id)->update(['is_active' => false]),
        ));
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::roleRevoke(BusinessRole::Administrator),
            $superAdmin->id,
            fn () => DB::table('role_assignments')->where('id', $superRole->id)
                ->update(['revoked_at' => now(), 'revoked_by' => $superAdmin->id]),
        ));
        $primaryMembership = $superAdmin->memberships()->where('location_id', $primary->id)->firstOrFail();
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::membershipDeactivate(),
            $superAdmin->id,
            fn () => DB::table('location_memberships')->where('id', $primaryMembership->id)->update([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivation_reason' => 'Test-only attempt',
            ]),
        ));
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::identityDisable(),
            null,
            fn () => app(IdentityService::class)->disable($superAdmin),
        ));
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::identityDisable(),
            null,
            fn () => DB::table('users')->where('id', $superAdmin->id)->delete(),
        ));

        $this->assertTrue($superAdmin->fresh()->is_active);
        $this->assertNull($superRole->fresh()->revoked_at);
        $this->assertTrue(app(BusinessAuthorization::class)->isSuperAdmin($superAdmin));
    }

    public function test_ordinary_administrator_cannot_revoke_its_own_administrator_role_even_with_a_signed_mutation(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $ordinaryAdmin = $this->createAdministrator($superAdmin, $primary, 'self-role@example.test');
        $role = RoleAssignment::where('user_id', $ordinaryAdmin->id)
            ->where('role', BusinessRole::Administrator)->firstOrFail();

        $this->assertUnauthorized(fn () => app(RoleAdministration::class)->revoke(
            $ordinaryAdmin, $primary, $role,
        ));
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::roleRevoke(BusinessRole::Administrator),
            $ordinaryAdmin->id,
            fn () => DB::table('role_assignments')->where('id', $role->id)
                ->update(['revoked_at' => now(), 'revoked_by' => $ordinaryAdmin->id]),
        ));

        $this->assertNull($role->fresh()->revoked_at);
        $this->assertTrue($ordinaryAdmin->fresh()->is_active);
    }

    public function test_super_admin_can_edit_own_profile_only_after_password_confirmation_and_email_reverification(): void
    {
        Mail::fake();
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $this->selectContext($superAdmin, $primary);
        $version = $superAdmin->auth_version;

        $this->patchJson('/api/v1/security/me/profile', [
            'first_name' => 'Updated',
            'email' => 'updated-super@example.test',
            'current_password' => 'wrong-password',
        ])->assertForbidden();
        $this->assertSame('Initial', $superAdmin->fresh()->first_name);

        $this->patchJson('/api/v1/security/me/profile', [
            'first_name' => 'Updated',
            'last_name' => 'Identity',
            'email' => 'Updated-Super@example.test',
            'current_password' => self::PASSWORD,
        ])->assertAccepted();

        $updated = $superAdmin->fresh();
        $this->assertSame($superAdmin->id, $updated->id);
        $this->assertSame('updated-super@example.test', $updated->email);
        $this->assertSame('Updated', $updated->first_name);
        $this->assertNull($updated->email_verified_at);
        $this->assertGreaterThan($version, $updated->auth_version);
        Mail::assertSentCount(1);
        Mail::assertSent(IdentityLink::class, fn ($mail): bool => $mail->hasTo('updated-super@example.test'));
        $mail = Mail::sent(IdentityLink::class)->last();
        $token = $this->sentToken($mail);

        $this->getJson('/api/v1/security/access?location_id='.$primary->id.'&permission=LOCATION_VIEW')->assertUnauthorized();
        app(AccountLinks::class)->consume('activation', $updated->email, $token, 'Updated-Password!42');
        $this->assertNotNull($updated->fresh()->email_verified_at);
        $this->postJson('/api/v1/auth/login', [
            'email' => $updated->email,
            'password' => 'Updated-Password!42',
        ])->assertOk();
        $this->assertTrue(app(BusinessAuthorization::class)->isSuperAdmin($updated));
    }

    public function test_super_admin_profile_endpoint_does_not_allow_another_administrator_to_edit_it(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $ordinaryAdmin = $this->createAdministrator($superAdmin, $primary, 'ordinary-profile@example.test');
        $this->selectContext($ordinaryAdmin, $primary);

        $this->patchJson('/api/v1/security/me/profile', [
            'first_name' => 'Changed',
            'current_password' => self::PASSWORD,
        ])->assertForbidden();
        $this->assertSame('Initial', $superAdmin->fresh()->first_name);
    }

    public function test_ordinary_administrator_cannot_deactivate_primary_but_can_deactivate_secondary_kitchen(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $ordinaryAdmin = $this->createAdministrator($superAdmin, $primary, 'primary-disable@example.test');
        $this->createAdministrator($superAdmin, $primary, 'primary-disable-second@example.test');
        $secondaryKitchen = $this->kitchen('SECONDARY');
        app(MembershipService::class)->assign($ordinaryAdmin, $secondaryKitchen, $superAdmin->id);

        $this->selectContext($ordinaryAdmin, $secondaryKitchen);
        $this->patchJson("/api/v1/security/locations/{$primary->id}", ['is_active' => false])->assertConflict();

        $this->assertTrue($primary->fresh()->is_active);
        $this->assertTrue(app(BusinessAuthorization::class)->isSuperAdmin($superAdmin));

        $this->patchJson("/api/v1/security/locations/{$secondaryKitchen->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($secondaryKitchen->fresh()->is_active);
    }

    public function test_super_admin_cannot_deactivate_primary_central_kitchen(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $targetAdmin = $this->createAdministrator($superAdmin, $primary, 'primary-disable-target@example.test');
        $secondaryKitchen = $this->kitchen('SECONDARY');
        app(MembershipService::class)->assign($superAdmin, $secondaryKitchen, $superAdmin->id);

        $this->selectContext($superAdmin, $secondaryKitchen);
        $this->patchJson("/api/v1/security/locations/{$primary->id}", ['is_active' => false])->assertConflict();

        $this->assertTrue($primary->fresh()->is_active);
        $this->assertTrue(app(BusinessAuthorization::class)->isSuperAdmin($superAdmin));
        app(RoleAdministration::class)->grant($superAdmin, $primary, $targetAdmin, BusinessRole::Boss, null);
        $this->assertTrue(RoleAssignment::where('user_id', $targetAdmin->id)
            ->where('role', BusinessRole::Boss)->whereNull('revoked_at')->exists());
    }

    public function test_non_administrator_cannot_deactivate_primary_central_kitchen(): void
    {
        [, $primary] = $this->createSuperAdmin();
        $secondaryKitchen = $this->kitchen('SECONDARY');
        $nonAdministrator = $this->user('primary-disable-nonadmin@example.test');
        $this->role($nonAdministrator, BusinessRole::CentralKitchenChef, $secondaryKitchen);

        $this->selectContext($nonAdministrator, $secondaryKitchen);
        $this->patchJson("/api/v1/security/locations/{$primary->id}", ['is_active' => false])->assertForbidden();
        $this->assertTrue($primary->fresh()->is_active);
    }

    public function test_database_rejects_primary_kitchen_deactivation_and_identity_changes(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();

        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection->table('locations')
            ->where('id', $primary->id)->update(['is_active' => false]));
        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection->table('locations')
            ->where('id', $primary->id)->delete());

        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::installationLocationUpdate(),
            null,
            fn () => DB::table('locations')->where('id', $primary->id)->update(['is_active' => false]),
        ));
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::installationLocationUpdate(),
            null,
            fn () => DB::table('locations')->where('id', $primary->id)->update(['is_primary_central_kitchen' => false]),
        ));

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('locations')
            ->where('id', $primary->id)->update(['is_active' => false]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('locations')
            ->where('id', $primary->id)->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('locations')
            ->where('id', $primary->id)->update(['is_primary_central_kitchen' => false]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('locations')
            ->where('id', $primary->id)->update(['organization_id' => Str::uuid()->toString()]));

        $this->assertTrue($primary->fresh()->is_active);
        $this->assertTrue($primary->fresh()->is_primary_central_kitchen);
        $this->assertTrue(app(BusinessAuthorization::class)->isSuperAdmin($superAdmin));
    }

    public function test_concurrent_super_admin_role_grants_are_serialized_and_audited(): void
    {
        [$superAdmin, $primary] = $this->createSuperAdmin();
        $target = $this->createAdministrator($superAdmin, $primary, 'concurrent-target@example.test');
        $process = null;
        DB::beginTransaction();
        try {
            app(RoleAdministration::class)->grant($superAdmin, $primary, $target, BusinessRole::Boss, null);
            $name = 'veltra-super-admin-'.bin2hex(random_bytes(6));
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/concurrent_super_admin_mutation.php'),
                $superAdmin->id,
                $target->id,
                $primary->id,
                $name,
            ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
            $process->setTimeout(20);
            $process->start();
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (bool) DB::scalar(
                    "SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock')",
                    [$name],
                );
                if ($waiting || ! $process->isRunning()) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, $process->getErrorOutput());
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('rejected', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome']);
            $this->assertSame(1, RoleAssignment::where('user_id', $target->id)->where('role', BusinessRole::Boss)->whereNull('revoked_at')->count());
            $this->assertSame(1, DB::table('security_events')->where('target_id', $target->id)->where('action', 'role.grant.BOSS')
                ->where('outcome', 'rejected')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
        }
    }

    private function createSuperAdmin(): array
    {
        $primary = $this->kitchen('PRIMARY');
        $user = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Initial',
            'last_name' => 'Admin',
            'email' => 'initial-admin@example.test',
        ], self::PASSWORD);
        app(MembershipService::class)->assign($user, $primary);
        $this->updateFixture($user, ['email_verified_at' => now()]);
        $user = $user->fresh();
        app(RoleAdministration::class)->bootstrapInitial($user);

        return [$user->fresh(), $primary];
    }

    private function createAdministrator(User $superAdmin, Location $primary, string $email): User
    {
        $user = $this->user($email);
        app(MembershipService::class)->assign($user, $primary, $superAdmin->id);
        app(RoleAdministration::class)->grant($superAdmin, $primary, $user, BusinessRole::Administrator, null, self::PASSWORD);

        return $user->fresh();
    }

    private function selectContext(User $user, Location $location): void
    {
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertOk();
    }

    private function assertUnauthorized(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The unauthorized account mutation was accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function assertSignedRuntimeMutationRejects(string $sqlState, callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);

            return;
        }

        $this->fail("The signed runtime mutation was not rejected with SQLSTATE {$sqlState}.");
    }

    private function sentToken(IdentityLink $mail): string
    {
        preg_match('/token=([^\s]+)/', $mail->render(), $matches);

        return rawurldecode($matches[1]);
    }
}
