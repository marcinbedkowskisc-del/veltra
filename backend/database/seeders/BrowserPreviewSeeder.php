<?php

namespace Database\Seeders;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class BrowserPreviewSeeder extends Seeder
{
    public function run(): void
    {
        $this->assertIsolatedTarget();

        if (Organization::exists() || User::exists()) {
            throw new LogicException('Browser preview seeding requires an empty disposable database.');
        }

        $passwords = [
            'administrator' => getenv('PA07B2_PREVIEW_ADMIN_PASSWORD'),
            'boss' => getenv('PA07B2_PREVIEW_BOSS_PASSWORD'),
            'boss_raw' => getenv('PA07B2_PREVIEW_BOSS_RAW_PASSWORD'),
        ];
        foreach ($passwords as $password) {
            if (! is_string($password) || $password === '') {
                throw new LogicException('Synthetic browser-preview credentials are required.');
            }
        }

        $installation = app(InstallationService::class);
        $organization = $installation->initializeOrganization([
            'name' => 'VELTRA — izolowany podgląd PA-07B.2',
            'code' => 'PA07B2_PREVIEW',
        ]);
        $primaryKitchen = $installation->createLocation([
            'name' => 'Kuchnia testowa CK-1',
            'code' => 'CK_PREVIEW_1',
            'type' => 'CENTRAL_KITCHEN',
        ]);
        $secondaryKitchen = $installation->createLocation([
            'name' => 'Kuchnia testowa CK-2',
            'code' => 'CK_PREVIEW_2',
            'type' => 'CENTRAL_KITCHEN',
        ]);
        $restaurant = $installation->createLocation([
            'name' => 'Restauracja testowa R-1',
            'code' => 'R_PREVIEW_1',
            'type' => 'RESTAURANT',
        ]);
        $secondRestaurant = $installation->createLocation([
            'name' => 'Restauracja testowa R-2',
            'code' => 'R_PREVIEW_2',
            'type' => 'RESTAURANT',
        ]);
        $installation->assignManagingKitchen($restaurant, $primaryKitchen);
        $installation->assignManagingKitchen($secondRestaurant, $secondaryKitchen);
        $restaurant = $installation->updateLocation($restaurant, ['is_active' => true]);
        $secondRestaurant = $installation->updateLocation($secondRestaurant, ['is_active' => true]);

        $administrator = $this->createUser(
            $organization,
            'Preview',
            'Administrator',
            'pa07b2-admin@example.test',
            $passwords['administrator'],
        );
        $boss = $this->createUser(
            $organization,
            'Preview',
            'BOSS',
            'pa07b2-boss@example.test',
            $passwords['boss'],
        );
        $bossWithRawGrant = $this->createUser(
            $organization,
            'Preview',
            'BOSS RAW',
            'pa07b2-boss-raw@example.test',
            $passwords['boss_raw'],
        );

        $memberships = app(MembershipService::class);
        foreach ([$primaryKitchen, $restaurant, $secondRestaurant] as $location) {
            $memberships->assign($administrator, $location, $administrator->id);
        }
        foreach ([$boss, $bossWithRawGrant] as $user) {
            $memberships->assign($user, $primaryKitchen, $administrator->id);
        }

        $this->assignRole($organization, $administrator, BusinessRole::Administrator);
        $this->assignRole($organization, $boss, BusinessRole::Boss, $administrator);
        $this->assignRole($organization, $bossWithRawGrant, BusinessRole::Boss, $administrator);

        $setup = $this->setupConnection();
        $setupOrganization = Organization::on($setup->getName())->findOrFail($organization->id);
        $setupOrganization->forceFill(['authorization_initialized' => true])->save();
        $this->bootstrapRawGrants($organization, $administrator, $bossWithRawGrant);

        $this->command?->info('Created synthetic administrator, BOSS accounts, locations, and independent RAW grants.');
    }

    private function assertIsolatedTarget(): void
    {
        $connection = DB::connection();
        $identity = $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database_name, current_user AS role_name,
                (SELECT rolsuper::text FROM pg_roles WHERE rolname = current_user) AS is_superuser,
                pg_get_userbyid((SELECT relowner FROM pg_class WHERE oid = 'public.organizations'::regclass)) AS table_owner,
                has_table_privilege(current_user, 'public.organizations', 'TRUNCATE')::text AS can_truncate
            SQL);

        if (app()->environment() !== 'browser-preview'
            || getenv('VELTRA_BROWSER_PREVIEW') !== '1'
            || config('database.default') !== 'pgsql'
            || $connection->getConfig('host') !== 'postgres'
            || $identity->database_name !== 'veltra_pa07b2_browser_preview'
            || $identity->role_name !== 'veltra_app'
            || $identity->is_superuser !== 'false'
            || $identity->table_owner !== 'veltra_migrator'
            || $identity->can_truncate !== 'false') {
            throw new LogicException('Refusing to seed outside the dedicated PA-07B.2 browser-preview database.');
        }

        $this->setupConnection();
    }

    private function setupConnection(): Connection
    {
        $password = getenv('PA07B2_PREVIEW_MIGRATOR_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new LogicException('The dedicated preview migration-role password is required for fixture setup.');
        }

        $runtime = config('database.connections.pgsql');
        config(['database.connections.pgsql_setup' => array_replace($runtime, [
            'url' => null,
            'username' => 'veltra_migrator',
            'password' => $password,
        ])]);
        DB::purge('pgsql_setup');
        $connection = DB::connection('pgsql_setup');
        $identity = $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database_name, current_user AS role_name,
                (SELECT rolsuper::text FROM pg_roles WHERE rolname = current_user) AS is_superuser,
                pg_get_userbyid((SELECT relowner FROM pg_class WHERE oid = 'public.organizations'::regclass)) AS table_owner
            SQL);

        if ($connection->getConfig('host') !== 'postgres'
            || $identity->database_name !== 'veltra_pa07b2_browser_preview'
            || $identity->role_name !== 'veltra_migrator'
            || $identity->is_superuser !== 'false'
            || $identity->table_owner !== 'veltra_migrator') {
            throw new LogicException('Fixture setup requires the non-superuser migration role on the disposable preview database.');
        }

        return $connection;
    }

    private function createUser(
        Organization $organization,
        string $firstName,
        string $lastName,
        string $email,
        string $password,
    ): User {
        $user = User::on('pgsql_setup')->create([
            'organization_id' => $organization->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return User::on('pgsql')->findOrFail($user->id);
    }

    private function assignRole(
        Organization $organization,
        User $user,
        BusinessRole $role,
        ?User $grantedBy = null,
    ): void {
        RoleAssignment::on('pgsql_setup')->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'location_id' => null,
            'membership_id' => null,
            'granted_by' => $grantedBy?->id,
        ]);
    }

    private function bootstrapRawGrants(Organization $organization, User $administrator, User $recipient): void
    {
        $permissions = array_map(
            static fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        $connection = $this->setupConnection();
        $connection->transaction(function () use ($connection, $permissions, $operationId, $organization, $administrator, $recipient): void {
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $organization->id,
                'operation_id' => $operationId,
                'actor_user_id' => $administrator->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $organization->id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $recipient->id,
                    'permissions' => $permissions,
                ], JSON_THROW_ON_ERROR),
            ]);

            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $organization->id,
                    'user_id' => $recipient->id,
                    'permission' => $permission,
                    'granted_by' => $administrator->id,
                    'valid_from' => now(),
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
            }
        });
    }
}
