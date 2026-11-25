<?php

namespace Tests;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

abstract class AuthorizationTestCase extends IdentityTestCase
{
    protected function pendingUser(string $email = 'person@example.test'): User
    {
        return User::on('pgsql_setup')->create([
            'organization_id' => Organization::firstOrFail()->id,
            'first_name' => 'Test', 'last_name' => 'Person',
            'email' => $email, 'password' => self::PASSWORD, 'is_active' => true,
        ])->setConnection('pgsql');
    }

    protected function provisionedUser(string $email, BusinessRole $role, ?Location $location = null): User
    {
        $id = $this->withSetupTransaction(function () use ($email, $role, $location): string {
            $user = User::on('pgsql_setup')->create([
                'organization_id' => Organization::firstOrFail()->id,
                'first_name' => 'Test', 'last_name' => 'Person',
                'email' => $email, 'password' => self::PASSWORD, 'is_active' => true,
            ]);
            $membership = $location ? LocationMembership::on('pgsql_setup')->create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id,
                'location_id' => $location->id, 'is_active' => true,
            ]) : null;
            RoleAssignment::on('pgsql_setup')->create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id,
                'role' => $role, 'location_id' => $location?->id, 'membership_id' => $membership?->id,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user->id;
        });

        return User::on('pgsql')->findOrFail($id);
    }

    protected function rawVersion(string $materialId): ?string
    {
        return DB::table('raw_materials')->where('id', $materialId)->value('edit_version');
    }

    protected function role(User $user, BusinessRole $role, ?Location $location = null): RoleAssignment
    {
        $membership = $location ? app(MembershipService::class)->available($user)->where('location_id', $location->id)->first()
            ?? app(MembershipService::class)->assign($user, $location, $user->id) : null;

        $assignment = RoleAssignment::on('pgsql_setup')->create([
            'organization_id' => $user->organization_id, 'user_id' => $user->id,
            'role' => $role, 'location_id' => $location?->id, 'membership_id' => $membership?->id,
        ]);

        return RoleAssignment::on('pgsql')->findOrFail($assignment->getKey());
    }
}
