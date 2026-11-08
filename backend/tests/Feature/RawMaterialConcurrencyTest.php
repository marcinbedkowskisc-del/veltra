<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ModuleCatalogFixtures;
use Tests\TestCase;

class RawMaterialConcurrencyTest extends TestCase
{
    public function test_parallel_creates_serialize_code_allocation_and_audit(): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->resetTestDatabase();
        $processes = [];

        try {
            $installation = app(InstallationService::class);
            $organization = $installation->initializeOrganization(['name' => 'Concurrency Test', 'code' => 'CONC']);
            $kitchen = $installation->createLocation([
                'name' => 'Kuchnia centralna',
                'code' => 'CK1',
                'type' => 'CENTRAL_KITCHEN',
            ]);
            $location = $installation->createLocation([
                'name' => 'Restauracja',
                'code' => 'R1',
                'type' => 'RESTAURANT',
            ]);
            $installation->assignManagingKitchen($location, $kitchen);
            $location = $installation->updateLocation($location, ['is_active' => true]);
            $administrator = $this->user($organization, 'concurrency-admin@example.test');
            RoleAssignment::on('pgsql_setup')->create([
                'organization_id' => $organization->id,
                'user_id' => $administrator->id,
                'role' => BusinessRole::Administrator,
            ]);
            app(MembershipService::class)->assign($administrator, $kitchen, $administrator->id);
            $steward = $this->user($organization, 'concurrency-steward@example.test');
            app(MembershipService::class)->assign($steward, $kitchen);
            $this->updateFixture($organization, ['authorization_initialized' => true]);
            $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
            LocationModuleState::on('pgsql_setup')->create([
                'organization_id' => $organization->id,
                'location_id' => $kitchen->id,
                'module_key' => 'RAW_MATERIALS',
                'enabled' => true,
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);
            $this->bootstrapPermissions($administrator, $steward);
            $categoryId = Str::uuid()->toString();
            DB::connection('pgsql_setup')->table('raw_material_categories')->insert([
                'id' => $categoryId,
                'organization_id' => $organization->id,
                'code' => 'BASIC',
                'name' => 'Podstawowe',
                'depth' => 1,
                'created_by' => $steward->id,
            ]);

            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            [$first, $firstName] = $this->startCreateProcess($steward, $kitchen, $categoryId, 'Ryż', 'rawmat-first-');
            $processes[] = $first;
            $this->assertWaitingOnOrganizationLock($first, $firstName);
            [$second, $secondName] = $this->startCreateProcess($steward, $kitchen, $categoryId, 'Soczewica', 'rawmat-second-');
            $processes[] = $second;
            $this->assertWaitingOnOrganizationLock($second, $secondName);

            DB::commit();
            $firstResult = $this->finishProcess($first);
            $secondResult = $this->finishProcess($second);
            $codes = [$firstResult['code'], $secondResult['code']];
            sort($codes);

            $this->assertSame(['RAW-000001', 'RAW-000002'], $codes);
            $this->assertSame(2, DB::table('raw_materials')->where('organization_id', $organization->id)->count());
            $this->assertSame(2, DB::table('raw_material_audit_events')
                ->where('organization_id', $organization->id)->where('event_code', 'CREATED')->count());
            $this->assertSame(2, (int) DB::table('organization_sku_counters')
                ->where('organization_id', $organization->id)->where('prefix', 'RAW')->value('last_value'));
            $this->assertSame(0, (int) DB::table('organization_sku_counters')
                ->where('organization_id', $organization->id)->where('prefix', 'SUR')->value('last_value'));
        } finally {
            $this->cleanup($processes);
        }
    }

    public function test_parallel_imported_reservation_and_generated_allocation_use_one_counter_without_duplicates(): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->resetTestDatabase();
        $processes = [];

        try {
            $installation = app(InstallationService::class);
            $organization = $installation->initializeOrganization(['name' => 'Concurrent Catalog Import', 'code' => 'CATCONC']);
            $location = $installation->createLocation([
                'name' => 'Kuchnia',
                'code' => 'CK1',
                'type' => 'CENTRAL_KITCHEN',
            ]);
            $location = $installation->updateLocation($location, ['is_active' => true]);
            $administrator = $this->user($organization, 'catalog-concurrency-admin@example.test');
            RoleAssignment::on('pgsql_setup')->create([
                'organization_id' => $organization->id,
                'user_id' => $administrator->id,
                'role' => BusinessRole::Administrator,
            ]);
            app(MembershipService::class)->assign($administrator, $location, $administrator->id);
            $steward = $this->user($organization, 'catalog-concurrency-steward@example.test');
            app(MembershipService::class)->assign($steward, $location);
            $this->updateFixture($organization, ['authorization_initialized' => true]);
            $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
            LocationModuleState::on('pgsql_setup')->create([
                'organization_id' => $organization->id,
                'location_id' => $location->id,
                'module_key' => 'RAW_MATERIALS',
                'enabled' => true,
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);
            $this->bootstrapPermissions($administrator, $steward);
            $categoryId = Str::uuid()->toString();
            DB::connection('pgsql_setup')->table('raw_material_categories')->insert([
                'id' => $categoryId,
                'organization_id' => $organization->id,
                'code' => 'BASIC',
                'name' => 'Podstawowe',
                'depth' => 1,
                'created_by' => $steward->id,
            ]);
            DB::connection('pgsql_setup')->table('organization_sku_counters')->insertOrIgnore([
                'organization_id' => $organization->id,
                'prefix' => 'RAW',
                'last_value' => 0,
                'updated_at' => now(),
            ]);

            DB::beginTransaction();
            DB::table('organization_sku_counters')
                ->where('organization_id', $organization->id)
                ->where('prefix', 'RAW')
                ->lockForUpdate()
                ->firstOrFail();
            $reserveName = 'catalog-reserve-'.Str::lower(Str::random(8));
            $reserveProcess = new Process([
                PHP_BINARY,
                base_path('tests/Support/concurrent_raw_material_sku_reservation.php'),
                $steward->id,
                'RAW-000050',
                Str::uuid()->toString(),
                $reserveName,
            ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
            $reserveProcess->setTimeout(30);
            $reserveProcess->start();
            $processes[] = $reserveProcess;
            $this->assertWaitingOnOrganizationLock($reserveProcess, $reserveName);

            [$createProcess, $createName] = $this->startCreateProcess(
                $steward,
                $location,
                $categoryId,
                'Równoległy produkt',
                'catalog-create-',
            );
            $processes[] = $createProcess;
            $this->assertWaitingOnOrganizationLock($createProcess, $createName);

            DB::commit();
            $reservation = $this->finishReservationProcess($reserveProcess);
            $created = $this->finishProcess($createProcess);

            $this->assertSame(['status' => 'reserved', 'sku' => 'RAW-000050'], $reservation);
            $this->assertNotSame('RAW-000050', $created['code']);
            $this->assertSame(2, DB::table('organization_sku_assignments')
                ->where('organization_id', $organization->id)
                ->where('prefix', 'RAW')
                ->count());
            $this->assertSame(2, (int) DB::table('organization_sku_assignments')
                ->where('organization_id', $organization->id)
                ->selectRaw('count(DISTINCT sku) AS count')
                ->value('count'));
            $this->assertSame(
                (int) DB::table('organization_sku_assignments')->where('organization_id', $organization->id)->where('prefix', 'RAW')->max('sequence_number'),
                (int) DB::table('organization_sku_counters')->where('organization_id', $organization->id)->where('prefix', 'RAW')->value('last_value'),
            );
        } finally {
            $this->cleanup($processes);
        }
    }

    public function test_concurrent_membership_and_user_suspension_permanently_revoke_delegated_grants(): void
    {
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->resetTestDatabase();
        $processes = [];

        try {
            $installation = app(InstallationService::class);
            $organization = $installation->initializeOrganization(['name' => 'Suspension Concurrency', 'code' => 'SUSP']);
            $kitchen = $installation->createLocation([
                'name' => 'Kuchnia centralna',
                'code' => 'CK1',
                'type' => 'CENTRAL_KITCHEN',
            ]);
            $location = $installation->createLocation([
                'name' => 'Restauracja',
                'code' => 'R1',
                'type' => 'RESTAURANT',
            ]);
            $installation->assignManagingKitchen($location, $kitchen);
            $location = $installation->updateLocation($location, ['is_active' => true]);
            $administrator = $this->user($organization, 'suspension-admin@example.test');
            RoleAssignment::on('pgsql_setup')->create([
                'organization_id' => $organization->id,
                'user_id' => $administrator->id,
                'role' => BusinessRole::Administrator,
            ]);
            app(MembershipService::class)->assign($administrator, $location, $administrator->id);
            $steward = $this->user($organization, 'suspension-steward@example.test');
            app(MembershipService::class)->assign($steward, $location);
            $recipient = $this->user($organization, 'suspension-recipient@example.test');
            $recipientMembership = app(MembershipService::class)->assign($recipient, $location);
            $this->updateFixture($organization, ['authorization_initialized' => true]);
            $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
            LocationModuleState::on('pgsql_setup')->create([
                'organization_id' => $organization->id,
                'location_id' => $location->id,
                'module_key' => 'RAW_MATERIALS',
                'enabled' => true,
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);
            $this->bootstrapPermissions($administrator, $steward);
            $sourceGrant = DB::table('organization_permission_grants')
                ->where('user_id', $steward->id)
                ->where('permission', RawMaterialPermission::View->value)
                ->first();
            $permissions = app(OrganizationRawMaterialPermissionAdministration::class);
            $authorization = app(RawMaterialAuthorization::class);

            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            [$grantProcess, $grantProcessName] = $this->startGrantProcess(
                $steward->id,
                $location->id,
                $recipient->id,
                'rawmat-grant-race-',
            );
            $processes[] = $grantProcess;
            $this->assertWaitingOnOrganizationLock($grantProcess, $grantProcessName);
            app(MembershipService::class)->deactivate($recipientMembership, 'Członkostwo zawieszone współbieżnie');
            DB::commit();
            $this->assertSame(['denied' => true], $this->finishGrantProcess($grantProcess));
            $this->assertSame(0, DB::table('organization_permission_grants')
                ->where('user_id', $recipient->id)->count());
            $this->assertSame(0, DB::table('raw_material_audit_events')
                ->where('event_code', 'PERMISSION_GRANTED')
                ->whereJsonContains('metadata', ['recipient_user_id' => $recipient->id])->count());
            $this->assertEmpty($authorization->effectiveGrantIds($recipient->fresh(), RawMaterialPermission::View));

            app(MembershipService::class)->assign($recipient->fresh(), $location);
            $secondChildGrant = $permissions->grant(
                $steward,
                $location,
                $recipient->fresh(),
                RawMaterialPermission::View,
                'Jawne ponowne nadanie po zawieszeniu członkostwa',
            );
            $this->assertNotEmpty($secondChildGrant['id']);
            $this->assertNotEmpty($authorization->effectiveGrantIds($recipient->fresh(), RawMaterialPermission::View));

            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            [$userProcess, $userProcessName] = $this->startSuspensionProcess(
                'user',
                $recipient->id,
                'rawmat-user-suspension-',
            );
            $processes[] = $userProcess;
            $this->assertWaitingOnOrganizationLock($userProcess, $userProcessName);
            DB::commit();
            $this->finishSuspensionProcess($userProcess);

            $this->assertSame(1, DB::table('organization_permission_grant_events')
                ->where('grant_id', $secondChildGrant['id'])
                ->where('reason_code', 'USER_DEACTIVATED')->count());
            $this->assertSame(0, DB::table('organization_permission_grant_events')
                ->where('grant_id', $sourceGrant->id)->count());

            $this->updateFixture($recipient, ['is_active' => true]);
            $this->assertEmpty($authorization->effectiveGrantIds($recipient->fresh(), RawMaterialPermission::View));
        } finally {
            $this->cleanup($processes);
        }
    }

    private function startSuspensionProcess(string $subjectType, string $subjectId, string $prefix): array
    {
        $applicationName = $prefix.Str::lower(Str::random(8));
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/concurrent_raw_material_suspend.php'),
            $subjectType,
            $subjectId,
            $applicationName,
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
        $process->setTimeout(30);
        $process->start();

        return [$process, $applicationName];
    }

    private function startGrantProcess(
        string $actorId,
        string $locationId,
        string $recipientId,
        string $prefix,
    ): array {
        $applicationName = $prefix.Str::lower(Str::random(8));
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/concurrent_raw_material_grant.php'),
            $actorId,
            $locationId,
            $recipientId,
            $applicationName,
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
        $process->setTimeout(30);
        $process->start();

        return [$process, $applicationName];
    }

    private function finishGrantProcess(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function finishSuspensionProcess(Process $process): void
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame(['completed' => true], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    private function user(Organization $organization, string $email): User
    {
        $user = User::on('pgsql_setup')->create([
            'organization_id' => $organization->id,
            'first_name' => 'Test',
            'last_name' => 'Concurrency',
            'email' => $email,
            'password' => 'Test-Only-Password!42',
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return User::on('pgsql')->findOrFail($user->getKey());
    }

    private function bootstrapPermissions(User $actor, User $recipient): void
    {
        $permissions = array_map(
            fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        $this->withSetupTransaction(function (Connection $connection) use ($actor, $recipient, $permissions, $operationId): void {
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $actor->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $actor->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'TEST_FIXTURE',
                'metadata' => json_encode([
                    'recipient_user_id' => $recipient->id,
                    'permissions' => $permissions,
                ], JSON_THROW_ON_ERROR),
            ]);
            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $actor->organization_id,
                    'user_id' => $recipient->id,
                    'permission' => $permission,
                    'granted_by' => $actor->id,
                    'valid_from' => now(),
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'TEST_FIXTURE',
                ]);
            }
        });
    }

    private function startCreateProcess(
        User $actor,
        Location $location,
        string $categoryId,
        string $name,
        string $prefix,
    ): array {
        $applicationName = $prefix.Str::lower(Str::random(8));
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/concurrent_raw_material_create.php'),
            $actor->id,
            $location->id,
            $categoryId,
            $name,
            $applicationName,
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
        $process->setTimeout(30);
        $process->start();

        return [$process, $applicationName];
    }

    private function assertWaitingOnOrganizationLock(Process $process, string $applicationName): void
    {
        $deadline = microtime(true) + 10;
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

    private function finishProcess(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        try {
            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->fail('Proces alokacji zwrócił nieprawidłowy JSON: '.$process->getOutput());
        }
    }

    private function finishReservationProcess(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        try {
            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->fail('Proces rezerwacji SKU zwrócił nieprawidłowy JSON: '.$process->getOutput());
        }
    }

    private function cleanup(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $this->resetTestDatabase();
    }
}
