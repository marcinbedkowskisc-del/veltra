<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $setupUsername = env('TEST_DB_SETUP_USERNAME');
        $setupPassword = env('TEST_DB_SETUP_PASSWORD');

        if ($setupUsername === null && $setupPassword === null) {
            throw new LogicException('Separate disposable-database setup credentials are required for backend tests.');
        }

        if ($setupUsername !== 'veltra_migrator'
            || ! is_string($setupPassword)
            || $setupPassword === '') {
            throw new LogicException('Test setup credentials must explicitly identify the dedicated veltra_migrator role.');
        }

        $runtime = config('database.connections.pgsql');
        config(['database.connections.pgsql_setup' => array_replace($runtime, [
            'url' => null,
            'username' => $setupUsername,
            'password' => $setupPassword,
        ])]);
        DB::purge('pgsql_setup');
        $this->setupConnection();
    }

    protected function assertRuntimeConnection(): void
    {
        if (DB::getDefaultConnection() !== 'pgsql') {
            throw new LogicException('Laravel tests must use the restricted pgsql connection by default.');
        }

        $identity = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT current_database() AS database_name,
                   current_user AS role_name,
                   session_user AS session_role,
                   (SELECT rolsuper::text FROM pg_roles WHERE rolname = current_user) AS is_superuser,
                   pg_get_userbyid((SELECT relowner FROM pg_class WHERE oid = 'public.organizations'::regclass)) AS table_owner,
                   has_table_privilege(current_user, 'public.organizations', 'TRUNCATE')::text AS can_truncate,
                   has_schema_privilege(current_user, 'public', 'CREATE')::text AS can_create
            SQL);

        if ($identity->database_name !== 'veltra_testing'
            || $identity->role_name !== 'veltra_app'
            || $identity->session_role !== 'veltra_app'
            || $identity->is_superuser !== 'false'
            || $identity->table_owner !== 'veltra_migrator'
            || $identity->can_truncate !== 'false'
            || $identity->can_create !== 'false') {
            throw new LogicException('The application connection is not the restricted veltra_app role on the disposable test database.');
        }
    }

    protected function setupConnection(): Connection
    {
        if (env('TEST_DB_SETUP_USERNAME') !== 'veltra_migrator'
            || ! is_string(env('TEST_DB_SETUP_PASSWORD'))
            || env('TEST_DB_SETUP_PASSWORD') === '') {
            throw new LogicException('A dedicated veltra_migrator test-setup connection is required.');
        }

        $connection = DB::connection('pgsql_setup');
        $identity = $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database_name,
                   current_user AS role_name,
                   session_user AS session_role,
                   (SELECT rolsuper::text FROM pg_roles WHERE rolname = current_user) AS is_superuser,
                   pg_get_userbyid((SELECT relowner FROM pg_class WHERE oid = 'public.organizations'::regclass)) AS table_owner
            SQL);

        if ($identity->database_name !== 'veltra_testing'
            || $identity->role_name !== 'veltra_migrator'
            || $identity->session_role !== 'veltra_migrator'
            || $identity->is_superuser !== 'false'
            || $identity->table_owner !== 'veltra_migrator') {
            throw new LogicException('The setup connection must be the non-superuser owner of the disposable test schema.');
        }

        $this->assertRuntimeConnection();

        return $connection;
    }

    protected function withSetupConnection(callable $operation): mixed
    {
        $connection = $this->setupConnection();

        if (DB::getDefaultConnection() !== 'pgsql') {
            throw new LogicException('Setup operations cannot start while the application connection is elevated.');
        }

        try {
            return $operation($connection);
        } finally {
            $this->assertRuntimeConnection();
        }
    }

    protected function withSetupTransaction(callable $operation): mixed
    {
        return $this->withSetupConnection(
            fn (Connection $connection): mixed => $connection->transaction(
                fn (): mixed => $operation($connection),
            ),
        );
    }

    protected function setupModel(Model $model): Model
    {
        return (clone $model)->setConnection('pgsql_setup');
    }

    protected function updateFixture(Model $model, array $attributes): Model
    {
        $modelClass = $model::class;
        $key = $model->getKey();

        $this->withSetupConnection(function (Connection $connection) use ($modelClass, $key, $attributes): void {
            $fixture = $modelClass::on($connection->getName())->findOrFail($key);
            $fixture->forceFill($attributes)->save();
        });

        return $modelClass::on('pgsql')->findOrFail($key);
    }

    protected function resetTestDatabase(): void
    {
        $connection = $this->setupConnection();
        $connection->statement('TRUNCATE TABLE public.organizations CASCADE');
        $connection->table('raw_material_units')->insertOrIgnore([
            [
                'id' => '00000000-0000-4000-8000-000000000102',
                'code' => 'g',
                'name' => 'gram',
                'symbol' => 'g',
                'dimension' => 'MASS',
                'base_unit_code' => 'g',
                'to_base_factor' => '1',
            ],
            [
                'id' => '00000000-0000-4000-8000-000000000104',
                'code' => 'ml',
                'name' => 'mililitr',
                'symbol' => 'ml',
                'dimension' => 'VOLUME',
                'base_unit_code' => 'ml',
                'to_base_factor' => '1',
            ],
            [
                'id' => '00000000-0000-4000-8000-000000000106',
                'code' => 'pc',
                'name' => 'sztuka',
                'symbol' => 'szt.',
                'dimension' => 'COUNT',
                'base_unit_code' => 'pc',
                'to_base_factor' => '1',
            ],
        ]);
        $connection->table('raw_material_units')->insertOrIgnore([
            [
                'id' => '00000000-0000-4000-8000-000000000101',
                'code' => 'mg',
                'name' => 'miligram',
                'symbol' => 'mg',
                'dimension' => 'MASS',
                'base_unit_code' => 'g',
                'to_base_factor' => '0.001',
            ],
            [
                'id' => '00000000-0000-4000-8000-000000000103',
                'code' => 'kg',
                'name' => 'kilogram',
                'symbol' => 'kg',
                'dimension' => 'MASS',
                'base_unit_code' => 'g',
                'to_base_factor' => '1000',
            ],
            [
                'id' => '00000000-0000-4000-8000-000000000105',
                'code' => 'l',
                'name' => 'litr',
                'symbol' => 'l',
                'dimension' => 'VOLUME',
                'base_unit_code' => 'ml',
                'to_base_factor' => '1000',
            ],
        ]);
    }
}
