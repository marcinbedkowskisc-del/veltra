<?php

namespace Tests;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

abstract class AuthorizationTestCase extends IdentityTestCase
{
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
