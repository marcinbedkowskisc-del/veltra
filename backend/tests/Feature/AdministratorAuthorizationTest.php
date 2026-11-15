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

class AdministratorAuthorizationTest extends AuthorizationTestCase
{
    public function test_initial_and_subsequent_administrators_have_the_same_role(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $secondAdmin = $this->createAdministrator($initialAdministrator, $primary, 'second@example.test');
        $authorization = app(BusinessAuthorization::class);

        $this->assertCount(6, BusinessRole::cases());
        $this->assertTrue($initialAdministrator->fresh()->is_initial_identity);
        $this->assertTrue($authorization->hasRole($initialAdministrator, BusinessRole::Administrator));
        $this->assertTrue($authorization->hasRole($secondAdmin, BusinessRole::Administrator));
        $this->assertSame(
            [BusinessRole::Administrator],
            RoleAssignment::where('user_id', $initialAdministrator->id)->whereNull('revoked_at')->pluck('role')->all(),
        );
        $this->assertSame(0, PermissionGrant::where('user_id', $initialAdministrator->id)->count());
        $this->assertSame(0, DB::table('organization_permission_grants')->where('user_id', $initialAdministrator->id)->count());
    }

    public function test_legacy_unresolved_primary_kitchen_does_not_prevent_initial_administrator_bootstrap(): void
    {
        $initial = app(IdentityService::class)->provisionInitial([
            'first_name' => 'Initial',
            'last_name' => 'Admin',
            'email' => 'legacy-initial@example.test',
        ], self::PASSWORD);
        $this->createLegacyUndesignatedKitchen($initial->organization);
        app(RoleAdministration::class)->bootstrapInitial($initial);

        $this->assertTrue($initial->fresh()->is_initial_identity);
        $this->assertTrue(RoleAssignment::where('user_id', $initial->id)->where('role', BusinessRole::Administrator)->exists());
        $this->assertTrue($initial->organization->fresh()->primary_central_kitchen_unresolved);
    }

    public function test_administrators_can_manage_other_administrators_and_ordinary_accounts(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $ordinaryAdmin = $this->createAdministrator($initialAdministrator, $primary, 'ordinary-admin@example.test');
        $targetAdmin = $this->createAdministrator($initialAdministrator, $primary, 'target-admin@example.test');
        $restaurant = $this->activeRestaurant();
        app(PersonnelAdministration::class)->assignMembership($initialAdministrator, $primary, $targetAdmin, $restaurant);
        $employee = $this->user('employee@example.test');
        app(MembershipService::class)->assign($employee, $restaurant);

        app(RoleAdministration::class)->grant($ordinaryAdmin, $primary, $targetAdmin, BusinessRole::Boss, null);
        app(PermissionAdministration::class)->grant(
            $ordinaryAdmin, $primary, $targetAdmin, $restaurant, Permission::FoodcostView,
        );
        app(RoleAdministration::class)->setAccountActive($ordinaryAdmin, $primary, $targetAdmin, false);

        $this->assertFalse($targetAdmin->fresh()->is_active);
        $this->assertSame(1, RoleAssignment::where('user_id', $targetAdmin->id)->where('role', BusinessRole::Boss)->whereNull('revoked_at')->count());
        $this->assertSame(1, PermissionGrant::where('user_id', $targetAdmin->id)->where('permission', Permission::FoodcostView)->whereNull('revoked_at')->count());
        $this->assertGreaterThan(0, DB::table('security_events')->where('actor_id', $ordinaryAdmin->id)
            ->where('target_id', $targetAdmin->id)->where('outcome', 'accepted')->count());

        app(RoleAdministration::class)->grant($ordinaryAdmin, $primary, $employee, BusinessRole::RestaurantChef, $restaurant);
        $this->assertTrue(RoleAssignment::where('user_id', $employee->id)->where('role', BusinessRole::RestaurantChef)->whereNull('revoked_at')->exists());
    }

    public function test_another_administrator_can_manage_the_initial_administrator(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $ordinaryAdmin = $this->createAdministrator($initialAdministrator, $primary, 'ordinary@example.test');
        $initialRole = RoleAssignment::where('user_id', $initialAdministrator->id)->where('role', BusinessRole::Administrator)->firstOrFail();

        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection->table('users')
            ->where('id', $initialAdministrator->id)->update(['is_active' => false]));
        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection->table('role_assignments')
            ->where('id', $initialRole->id)->update(['revoked_at' => now(), 'revoked_by' => $initialAdministrator->id]));
        app(RoleAdministration::class)->revoke($ordinaryAdmin, $primary, $initialRole, self::PASSWORD);
        $this->assertNotNull($initialRole->fresh()->revoked_at);
        app(RoleAdministration::class)->setAccountActive($ordinaryAdmin, $primary, $initialAdministrator, false);
        $this->assertFalse($initialAdministrator->fresh()->is_active);
        $this->assertTrue(app(BusinessAuthorization::class)->hasRole($ordinaryAdmin, BusinessRole::Administrator));
    }

    public function test_administrator_cannot_grant_themselves_roles_or_permissions(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $ordinaryAdmin = $this->createAdministrator($initialAdministrator, $primary, 'self-role@example.test');
        $restaurant = $this->activeRestaurant();
        $membership = app(MembershipService::class)->assign($ordinaryAdmin, $restaurant, $initialAdministrator->id);

        $this->assertUnauthorized(fn () => app(RoleAdministration::class)->grant(
            $ordinaryAdmin, $primary, $ordinaryAdmin, BusinessRole::Boss, null,
        ));
        $this->assertUnauthorized(fn () => app(PermissionAdministration::class)->grant(
            $ordinaryAdmin, $primary, $ordinaryAdmin, $restaurant, Permission::FoodcostView,
        ));
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::roleGrant(BusinessRole::Boss),
            $ordinaryAdmin->id,
            fn () => DB::table('role_assignments')->insert([
                'id' => Str::uuid()->toString(), 'organization_id' => $ordinaryAdmin->organization_id,
                'user_id' => $ordinaryAdmin->id, 'role' => BusinessRole::Boss->value,
                'granted_by' => $ordinaryAdmin->id,
            ]),
        ));
        $this->assertSignedRuntimeMutationRejects('23514', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::permissionGrant(Permission::FoodcostView),
            $ordinaryAdmin->id,
            fn () => DB::table('permission_grants')->insert([
                'id' => Str::uuid()->toString(), 'organization_id' => $ordinaryAdmin->organization_id,
                'user_id' => $ordinaryAdmin->id, 'location_id' => $restaurant->id,
                'membership_id' => $membership->id, 'permission' => Permission::FoodcostView->value,
                'granted_by' => $ordinaryAdmin->id, 'valid_from' => now(),
            ]),
        ));
    }

    public function test_administrator_can_edit_own_profile_with_password_and_email_reverification(): void
    {
        Mail::fake();
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $this->createAdministrator($initialAdministrator, $primary, 'second-profile@example.test');
        $this->selectContext($initialAdministrator, $primary);
        $version = $initialAdministrator->auth_version;

        $this->patchJson('/api/v1/security/me/profile', [
            'first_name' => 'Updated',
            'email' => 'updated-super@example.test',
            'current_password' => 'wrong-password',
        ])->assertForbidden();
        $this->assertSame('Initial', $initialAdministrator->fresh()->first_name);

        $this->patchJson('/api/v1/security/me/profile', [
            'first_name' => 'Updated',
            'last_name' => 'Identity',
            'email' => 'Updated-Super@example.test',
            'current_password' => self::PASSWORD,
        ])->assertAccepted();

        $updated = $initialAdministrator->fresh();
        $this->assertSame($initialAdministrator->id, $updated->id);
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
        $this->assertTrue(app(BusinessAuthorization::class)->hasRole($updated, BusinessRole::Administrator));
    }

    public function test_profile_endpoint_changes_only_the_authenticated_administrator(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $ordinaryAdmin = $this->createAdministrator($initialAdministrator, $primary, 'ordinary-profile@example.test');
        $this->selectContext($ordinaryAdmin, $primary);

        $this->patchJson('/api/v1/security/me/profile', [
            'first_name' => 'Changed',
            'current_password' => self::PASSWORD,
        ])->assertAccepted();
        $this->assertSame('Initial', $initialAdministrator->fresh()->first_name);
        $this->assertSame('Changed', $ordinaryAdmin->fresh()->first_name);
    }

    public function test_last_administrator_cannot_invalidate_their_verified_email(): void
    {
        [$administrator, $primary] = $this->createInitialAdministrator();
        $this->selectContext($administrator, $primary);

        $this->patchJson('/api/v1/security/me/profile', [
            'email' => 'new@example.test',
            'current_password' => self::PASSWORD,
        ])->assertConflict();

        $this->assertNotNull($administrator->fresh()->email_verified_at);
        $this->assertSame('initial-admin@example.test', $administrator->fresh()->email);
    }

    public function test_ordinary_administrator_cannot_deactivate_primary_but_can_deactivate_secondary_kitchen(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $ordinaryAdmin = $this->createAdministrator($initialAdministrator, $primary, 'primary-disable@example.test');
        $this->createAdministrator($initialAdministrator, $primary, 'primary-disable-second@example.test');
        $secondaryKitchen = $this->kitchen('SECONDARY');
        app(MembershipService::class)->assign($ordinaryAdmin, $secondaryKitchen, $initialAdministrator->id);

        $this->selectContext($ordinaryAdmin, $secondaryKitchen);
        $this->patchJson("/api/v1/security/locations/{$primary->id}", ['is_active' => false])->assertConflict();

        $this->assertTrue($primary->fresh()->is_active);
        $this->assertTrue(app(BusinessAuthorization::class)->hasRole($initialAdministrator, BusinessRole::Administrator));

        $this->patchJson("/api/v1/security/locations/{$secondaryKitchen->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($secondaryKitchen->fresh()->is_active);
    }

    public function test_administrator_cannot_deactivate_primary_central_kitchen(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $targetAdmin = $this->createAdministrator($initialAdministrator, $primary, 'primary-disable-target@example.test');
        $secondaryKitchen = $this->kitchen('SECONDARY');
        app(MembershipService::class)->assign($initialAdministrator, $secondaryKitchen, $initialAdministrator->id);

        $this->selectContext($initialAdministrator, $secondaryKitchen);
        $this->patchJson("/api/v1/security/locations/{$primary->id}", ['is_active' => false])->assertConflict();

        $this->assertTrue($primary->fresh()->is_active);
        $this->assertTrue(app(BusinessAuthorization::class)->hasRole($initialAdministrator, BusinessRole::Administrator));
        app(RoleAdministration::class)->grant($initialAdministrator, $primary, $targetAdmin, BusinessRole::Boss, null);
        $this->assertTrue(RoleAssignment::where('user_id', $targetAdmin->id)
            ->where('role', BusinessRole::Boss)->whereNull('revoked_at')->exists());
    }

    public function test_non_administrator_cannot_deactivate_primary_central_kitchen(): void
    {
        [, $primary] = $this->createInitialAdministrator();
        $secondaryKitchen = $this->kitchen('SECONDARY');
        $nonAdministrator = $this->user('primary-disable-nonadmin@example.test');
        $this->role($nonAdministrator, BusinessRole::CentralKitchenChef, $secondaryKitchen);

        $this->selectContext($nonAdministrator, $secondaryKitchen);
        $this->patchJson("/api/v1/security/locations/{$primary->id}", ['is_active' => false])->assertForbidden();
        $this->assertTrue($primary->fresh()->is_active);
    }

    public function test_database_rejects_primary_kitchen_deactivation_and_identity_changes(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();

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
        $this->assertTrue(app(BusinessAuthorization::class)->hasRole($initialAdministrator, BusinessRole::Administrator));
    }

    public function test_concurrent_administrator_role_grants_are_serialized_and_audited(): void
    {
        [$initialAdministrator, $primary] = $this->createInitialAdministrator();
        $target = $this->createAdministrator($initialAdministrator, $primary, 'concurrent-target@example.test');
        $process = null;
        DB::beginTransaction();
        try {
            app(RoleAdministration::class)->grant($initialAdministrator, $primary, $target, BusinessRole::Boss, null);
            $name = 'veltra-administrator-'.bin2hex(random_bytes(6));
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/concurrent_super_admin_mutation.php'),
                $initialAdministrator->id,
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

    private function createInitialAdministrator(): array
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

    private function createAdministrator(User $initialAdministrator, Location $primary, string $email): User
    {
        $user = $this->user($email);
        app(MembershipService::class)->assign($user, $primary, $initialAdministrator->id);
        app(RoleAdministration::class)->grant($initialAdministrator, $primary, $user, BusinessRole::Administrator, null, self::PASSWORD);

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
