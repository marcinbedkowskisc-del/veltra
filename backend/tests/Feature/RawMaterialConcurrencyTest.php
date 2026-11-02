<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\InstallationService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
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
        DB::statement('TRUNCATE organizations CASCADE');
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
            RoleAssignment::create([
                'organization_id' => $organization->id,
                'user_id' => $administrator->id,
                'role' => BusinessRole::Administrator,
            ]);
            app(MembershipService::class)->assign($administrator, $location);
            $steward = $this->user($organization, 'concurrency-steward@example.test');
            app(MembershipService::class)->assign($steward, $location);
            Organization::whereKey($organization->id)->update(['authorization_initialized' => true]);
            $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
            LocationModuleState::create([
                'organization_id' => $organization->id,
                'location_id' => $location->id,
                'module_key' => 'RAW_MATERIALS',
                'enabled' => true,
                'created_by' => $administrator->id,
                'updated_by' => $administrator->id,
            ]);
            $this->bootstrapPermissions($administrator, $steward);
            $categoryId = Str::uuid()->toString();
            DB::table('raw_material_categories')->insert([
                'id' => $categoryId,
                'organization_id' => $organization->id,
                'code' => 'BASIC',
                'name' => 'Podstawowe',
                'depth' => 1,
                'created_by' => $steward->id,
            ]);

            DB::beginTransaction();
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            [$first, $firstName] = $this->startCreateProcess($steward, $location, $categoryId, 'Ryż', 'rawmat-first-');
            $processes[] = $first;
            $this->assertWaitingOnOrganizationLock($first, $firstName);
            [$second, $secondName] = $this->startCreateProcess($steward, $location, $categoryId, 'Soczewica', 'rawmat-second-');
            $processes[] = $second;
            $this->assertWaitingOnOrganizationLock($second, $secondName);

            DB::commit();
            $firstResult = $this->finishProcess($first);
            $secondResult = $this->finishProcess($second);
            $codes = [$firstResult['code'], $secondResult['code']];
            sort($codes);

            $this->assertSame(['SUR-000001', 'SUR-000002'], $codes);
            $this->assertSame(2, DB::table('raw_materials')->where('organization_id', $organization->id)->count());
            $this->assertSame(2, DB::table('raw_material_audit_events')
                ->where('organization_id', $organization->id)->where('event_code', 'CREATED')->count());
            $this->assertSame(2, (int) DB::table('raw_material_code_counters')
                ->where('organization_id', $organization->id)->value('last_value'));
        } finally {
            $this->cleanup($processes);
        }
    }

    private function user(Organization $organization, string $email): User
    {
        $user = User::create([
            'organization_id' => $organization->id,
            'first_name' => 'Test',
            'last_name' => 'Concurrency',
            'email' => $email,
            'password' => 'Test-Only-Password!42',
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->refresh();
    }

    private function bootstrapPermissions(User $actor, User $recipient): void
    {
        $permissions = array_map(
            fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        DB::table('raw_material_audit_events')->insert([
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
            DB::table('organization_permission_grants')->insert([
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

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
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
        DB::statement('TRUNCATE organizations CASCADE');
    }
}
