<?php

namespace Tests;

use App\Domain\Installation\InstallationService;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

abstract class InstallationTestCase extends TestCase
{
    use DatabaseTransactions;

    protected InstallationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('veltra_testing', DB::connection()->getDatabaseName());
        $this->service = app(InstallationService::class);
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

    protected function assertDatabaseRejects(string $sqlState, callable $operation): void
    {
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);

            return;
        }

        $this->fail("PostgreSQL did not reject the operation with SQLSTATE {$sqlState}.");
    }
}
