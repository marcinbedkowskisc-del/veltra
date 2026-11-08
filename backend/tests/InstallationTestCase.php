<?php

namespace Tests;

use App\Domain\Installation\InstallationService;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

abstract class InstallationTestCase extends TestCase
{
    protected InstallationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTestDatabase();
        $this->assertRuntimeConnection();
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->service = app(InstallationService::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->resetTestDatabase();
        } finally {
            parent::tearDown();
        }
    }

    protected function initialize(): Organization
    {
        return $this->service->initializeOrganization(['name' => 'Test Organization', 'code' => 'TEST']);
    }

    protected function kitchen(string $code = 'CK1'): Location
    {
        return $this->service->createLocation(['name' => $code, 'code' => $code, 'type' => 'CENTRAL_KITCHEN']);
    }

    protected function restaurant(string $code = 'R1'): Location
    {
        return $this->service->createLocation(['name' => $code, 'code' => $code, 'type' => 'RESTAURANT']);
    }

    protected function createLegacyUndesignatedKitchen(Organization $organization, string $code = 'LEGACY'): Location
    {
        $locationId = $this->withSetupTransaction(function (Connection $connection) use ($organization, $code): string {
            $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $connection->statement('ALTER TABLE locations DISABLE TRIGGER locations_assign_primary_central_kitchen');
            $connection->statement('ALTER TABLE organizations DISABLE TRIGGER organizations_primary_central_kitchen_resolution_immutable');
            $location = Location::on('pgsql_setup')->create([
                'organization_id' => $organization->id,
                'name' => $code,
                'code' => $code,
                'type' => 'CENTRAL_KITCHEN',
                'is_active' => true,
            ]);
            $connection->table('organizations')
                ->where('id', $organization->id)
                ->update(['primary_central_kitchen_unresolved' => true]);
            $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $connection->statement('ALTER TABLE locations ENABLE TRIGGER locations_assign_primary_central_kitchen');
            $connection->statement('ALTER TABLE organizations ENABLE TRIGGER organizations_primary_central_kitchen_resolution_immutable');

            return $location->id;
        });

        return Location::on('pgsql')->findOrFail($locationId);
    }

    protected function withForeignOrganizationFixture(callable $operation): mixed
    {
        [$organizationId, $locationId] = $this->withSetupTransaction(function (Connection $connection): array {
            $connection->statement('ALTER TABLE organizations DROP CONSTRAINT organizations_single_installation_unique');
            $organization = Organization::on('pgsql_setup')->create(['name' => 'Foreign fixture', 'code' => 'FOREIGN_ORG']);
            $connection->statement('ALTER TABLE locations DISABLE TRIGGER locations_assign_primary_central_kitchen');
            $location = Location::on('pgsql_setup')->create([
                'organization_id' => $organization->id,
                'name' => 'Foreign kitchen',
                'code' => 'FOREIGN',
                'type' => 'CENTRAL_KITCHEN',
                'is_active' => true,
            ]);
            $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $connection->statement('ALTER TABLE locations ENABLE TRIGGER locations_assign_primary_central_kitchen');

            return [$organization->id, $location->id];
        });

        try {
            return $operation(Location::on('pgsql')->findOrFail($locationId));
        } finally {
            $this->withSetupTransaction(function (Connection $connection) use ($organizationId, $locationId): void {
                $connection->table('locations')->where('id', $locationId)->delete();
                $connection->table('organizations')->where('id', $organizationId)->delete();
                $connection->statement('ALTER TABLE organizations ADD CONSTRAINT organizations_single_installation_unique UNIQUE (installation)');
            });
        }
    }

    protected function assertDatabaseRejects(string $sqlState, callable $operation): void
    {
        try {
            $this->withSetupTransaction(function (Connection $connection) use ($operation): void {
                $operation($connection);
                $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);

            return;
        }

        $this->fail("PostgreSQL did not reject the operation with SQLSTATE {$sqlState}.");
    }

    protected function assertRuntimeDatabaseRejects(string $sqlState, callable $operation): void
    {
        try {
            DB::transaction(function () use ($operation): void {
                $operation(DB::connection());
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);

            return;
        }

        $this->fail("PostgreSQL did not reject the runtime operation with SQLSTATE {$sqlState}.");
    }
}
