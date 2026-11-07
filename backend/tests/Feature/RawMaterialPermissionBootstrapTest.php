<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\IdentityService;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\ModuleConfigurationService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialPermissionBootstrap;
use App\Domain\RawMaterials\RawMaterialPermissionUpgrade;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialPermissionBootstrapTest extends AuthorizationTestCase
{
    public function test_migration_role_is_not_superuser_or_database_owner(): void
    {
        $role = $this->setupConnection()->selectOne(<<<'SQL'
            SELECT
                current_user AS role_name,
                session_user AS session_role,
                current_database() AS database_name,
                CASE WHEN rol.rolsuper THEN 1 ELSE 0 END AS superuser,
                CASE WHEN rol.rolcreatedb THEN 1 ELSE 0 END AS createdb,
                CASE WHEN rol.rolcreaterole THEN 1 ELSE 0 END AS createrole,
                CASE WHEN db.datdba = rol.oid THEN 1 ELSE 0 END AS database_owner,
                CASE WHEN has_database_privilege(current_user, current_database(), 'CREATE') THEN 1 ELSE 0 END AS can_create_schema_objects
            FROM pg_roles rol
            JOIN pg_database db ON db.datname = current_database()
            WHERE rol.rolname = current_user
            SQL);

        $this->assertSame('veltra_migrator', $role->role_name);
        $this->assertSame('veltra_migrator', $role->session_role);
        $this->assertSame('veltra_testing', $role->database_name);
        $this->assertRuntimeConnection();
        $this->assertSame(0, (int) $role->superuser);
        $this->assertSame(0, (int) $role->createdb);
        $this->assertSame(0, (int) $role->createrole);
        $this->assertSame(0, (int) $role->database_owner);
        $this->assertSame(1, (int) $role->can_create_schema_objects);
    }

    public function test_runtime_cannot_write_to_permission_operation_ledger_directly(): void
    {
        $runtimeUsername = (string) env('APP_DB_USERNAME', '');
        $runtimePassword = (string) env('APP_DB_PASSWORD', '');
        $this->assertNotSame('', $runtimeUsername);
        $this->assertNotSame('', $runtimePassword);
        $this->assertSame(
            config('database.connections.pgsql.username'),
            $runtimeUsername,
            'Domyślne połączenie aplikacji musi używać ograniczonej roli runtime.',
        );
        $this->assertNotSame($this->setupConnection()->getConfig('username'), $runtimeUsername);

        $connection = config('database.connections.pgsql');
        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $connection['host'],
                $connection['port'],
                $connection['database'],
            ),
            $runtimeUsername,
            $runtimePassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $identity = $pdo->query('SELECT current_user, session_user, current_database() AS database_name')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('veltra_app', $identity['current_user']);
        $this->assertSame('veltra_app', $identity['session_user']);
        $this->assertSame('veltra_testing', $identity['database_name']);

        $privileges = $pdo->query(<<<'SQL'
            SELECT
                has_table_privilege(current_user, 'public.raw_material_permission_grant_operation_uses', 'SELECT') AS can_select,
                has_table_privilege(current_user, 'public.raw_material_permission_grant_operation_uses', 'INSERT') AS can_insert,
                has_table_privilege(current_user, 'public.raw_material_permission_grant_operation_uses', 'UPDATE') AS can_update,
                has_table_privilege(current_user, 'public.raw_material_permission_grant_operation_uses', 'DELETE') AS can_delete,
                has_table_privilege(current_user, 'public.raw_material_permission_grant_operation_uses', 'TRUNCATE') AS can_truncate
            SQL)->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue($privileges['can_select']);
        $this->assertFalse($privileges['can_insert']);
        $this->assertFalse($privileges['can_update']);
        $this->assertFalse($privileges['can_delete']);
        $this->assertFalse($privileges['can_truncate']);
        $this->assertSame(
            0,
            (int) $pdo->query(
                'SELECT count(*) FROM pg_auth_members WHERE member = (SELECT oid FROM pg_roles WHERE rolname = current_user)',
            )->fetchColumn(),
        );

        $function = $pdo->query(<<<'SQL'
            SELECT prosecdef, array_to_string(proconfig, ',') AS function_config
            FROM pg_proc
            WHERE oid = 'public.raw_materials_reject_bootstrap_grant_replay()'::regprocedure
            SQL)->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue($function['prosecdef']);
        $this->assertStringContainsString('search_path=pg_catalog, public', $function['function_config']);
        $this->assertFalse($pdo->query(
            "SELECT has_function_privilege(current_user, 'public.raw_materials_reject_bootstrap_grant_replay()', 'EXECUTE')",
        )->fetchColumn());

        try {
            $pdo->exec(<<<'SQL'
                INSERT INTO public.raw_material_permission_grant_operation_uses (
                    id, organization_id, bootstrap_operation_id, permission, created_at
                ) VALUES (
                    gen_random_uuid(), gen_random_uuid(), gen_random_uuid(),
                    'FORGED_LEDGER_ENTRY', clock_timestamp()
                )
                SQL);
            $this->fail('Runtime credentials must not append entries to the permission operation ledger.');
        } catch (PDOException $exception) {
            $this->assertSame('42501', (string) $exception->getCode());
        }

        foreach ([
            "UPDATE public.raw_material_permission_grant_operation_uses SET permission = 'FORGED_LEDGER_ENTRY'",
            'DELETE FROM public.raw_material_permission_grant_operation_uses',
            'TRUNCATE public.raw_material_permission_grant_operation_uses',
        ] as $statement) {
            try {
                $pdo->exec($statement);
                $this->fail('Runtime credentials must not mutate or truncate the permission operation ledger.');
            } catch (PDOException $exception) {
                $this->assertSame('42501', (string) $exception->getCode());
            }
        }
    }

    public function test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table(): void
    {
        $runtimeUsername = (string) env('APP_DB_USERNAME', '');
        $runtimePassword = (string) env('APP_DB_PASSWORD', '');
        $connection = config('database.connections.pgsql');
        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $connection['host'],
                $connection['port'],
                $connection['database'],
            ),
            $runtimeUsername,
            $runtimePassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $operationId = (string) Str::uuid();
        $before = (int) $pdo->query(
            'SELECT count(*) FROM public.raw_material_permission_grant_operation_uses',
        )->fetchColumn();

        $pdo->exec(<<<'SQL'
            CREATE TEMP TABLE m019_forgery (
                organization_id uuid,
                bootstrap_operation_id uuid,
                permission varchar(80)
            )
            SQL);

        try {
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER m019_forgery_trigger
                    BEFORE INSERT ON m019_forgery
                    FOR EACH ROW
                    EXECUTE FUNCTION public.raw_materials_reject_bootstrap_grant_replay()
                SQL);
            $this->fail('Runtime credentials must not attach the privileged trigger function to a temporary table.');
        } catch (PDOException $exception) {
            $this->assertSame('42501', (string) $exception->getCode());
        }

        try {
            $statement = $pdo->prepare(
                'SELECT public.raw_materials_reject_bootstrap_grant_replay()',
            );
            $statement->execute();
            $this->fail('Runtime credentials must not invoke the trigger function directly.');
        } catch (PDOException $exception) {
            $this->assertSame('42501', (string) $exception->getCode());
        }

        $this->assertSame(
            $before,
            (int) $pdo->query(
                'SELECT count(*) FROM public.raw_material_permission_grant_operation_uses',
            )->fetchColumn(),
        );
        $this->assertSame(
            0,
            (int) $pdo->query(
                'SELECT count(*) FROM public.raw_material_permission_grant_operation_uses WHERE bootstrap_operation_id = '.$pdo->quote($operationId),
            )->fetchColumn(),
        );
    }

    public function test_permission_grant_validators_pin_public_before_temporary_schemas(): void
    {
        foreach ([
            'public.raw_materials_serialize_grant()' => 'raw_materials_serialize_grant',
            'public.raw_materials_permission_upgrade_authority_is_effective(uuid,uuid)' => 'raw_materials_permission_upgrade_authority_is_effective',
            'public.raw_materials_revoke_grants_on_suspension()' => 'raw_materials_revoke_grants_on_suspension',
            'public.raw_materials_reject_bootstrap_grant_replay()' => 'raw_materials_reject_bootstrap_grant_replay',
        ] as $function => $name) {
            $config = DB::selectOne(
                'SELECT array_to_string(proconfig, \',\') AS settings FROM pg_proc WHERE oid = ?::regprocedure',
                [$function],
            )->settings;

            $this->assertSame(
                'search_path=pg_catalog, public, pg_temp',
                $config,
                "The {$name} function must resolve public relations before pg_temp.",
            );
        }
    }

    public function test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables(): void
    {
        $runtimeUsername = (string) env('APP_DB_USERNAME', '');
        $runtimePassword = (string) env('APP_DB_PASSWORD', '');
        $this->assertNotSame('', $runtimeUsername, 'Runtime PostgreSQL credentials are required for this security test.');
        $this->assertNotSame('', $runtimePassword, 'Runtime PostgreSQL credentials are required for this security test.');
        $connection = config('database.connections.pgsql');
        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $connection['host'],
                $connection['port'],
                $connection['database'],
            ),
            $runtimeUsername,
            $runtimePassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $organizationId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $recipientId = (string) Str::uuid();
        $operationId = (string) Str::uuid();
        $grantId = (string) Str::uuid();
        $beforeGrants = (int) $pdo->query('SELECT count(*) FROM public.organization_permission_grants')->fetchColumn();
        $beforeLedger = (int) $pdo->query('SELECT count(*) FROM public.raw_material_permission_grant_operation_uses')->fetchColumn();
        $beforeAudit = (int) $pdo->query('SELECT count(*) FROM public.raw_material_audit_events')->fetchColumn();

        $pdo->beginTransaction();
        try {
            $pdo->exec(<<<'SQL'
                CREATE TEMP TABLE organization_permission_grants (
                    id uuid, organization_id uuid, user_id uuid, permission varchar(80),
                    granted_by uuid, valid_from timestamptz, valid_until timestamptz,
                    delegation_source_id uuid, bootstrap_operation_id uuid
                );
                CREATE TEMP TABLE raw_material_audit_events (
                    organization_id uuid, operation_id uuid, actor_user_id uuid,
                    event_code varchar(80), outcome varchar(16), metadata jsonb
                );
                SQL);
            $audit = $pdo->prepare(<<<'SQL'
                INSERT INTO pg_temp.raw_material_audit_events (
                    organization_id, operation_id, actor_user_id, event_code, outcome, metadata
                ) VALUES (?, ?, ?, 'PERMISSION_BOOTSTRAP', 'ACCEPTED', ?::jsonb)
                SQL);
            $audit->execute([
                $organizationId,
                $operationId,
                $actorId,
                json_encode([
                    'recipient_user_id' => $recipientId,
                    'permissions' => [RawMaterialPermission::PermissionDelegate->value],
                ], JSON_THROW_ON_ERROR),
            ]);

            $pdo->exec('SAVEPOINT m023_temp_shadow_validation');
            $pdo->exec('SET LOCAL search_path = pg_temp, public');
            $accepted = false;
            $sqlState = null;
            try {
                $grant = $pdo->prepare(<<<'SQL'
                    INSERT INTO public.organization_permission_grants (
                        id, organization_id, user_id, permission, granted_by,
                        valid_from, bootstrap_operation_id, reason_code
                    ) VALUES (?, ?, ?, ?, ?, clock_timestamp(), ?, 'TEMP_SHADOW_REGRESSION')
                    SQL);
                $grant->execute([
                    $grantId,
                    $organizationId,
                    $recipientId,
                    RawMaterialPermission::PermissionDelegate->value,
                    $actorId,
                    $operationId,
                ]);
                $accepted = true;
            } catch (PDOException $exception) {
                $sqlState = (string) $exception->getCode();
            } finally {
                $pdo->exec('ROLLBACK TO SAVEPOINT m023_temp_shadow_validation');
            }

            $this->assertFalse($accepted, 'A temporary audit relation must not authorize a persistent grant.');
            $this->assertContains($sqlState, ['42501', '22023', '23514']);
        } finally {
            $pdo->rollBack();
        }

        $this->assertSame($beforeGrants, (int) $pdo->query('SELECT count(*) FROM public.organization_permission_grants')->fetchColumn());
        $this->assertSame($beforeLedger, (int) $pdo->query('SELECT count(*) FROM public.raw_material_permission_grant_operation_uses')->fetchColumn());
        $this->assertSame($beforeAudit, (int) $pdo->query('SELECT count(*) FROM public.raw_material_audit_events')->fetchColumn());
    }

    public function test_initial_grant_is_explicit_audited_one_time_and_never_self_granted(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('bootstrap-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $recipient = $this->user('bootstrap-steward@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);
        $permissions = [
            RawMaterialPermission::View->value,
            RawMaterialPermission::PermissionDelegate->value,
        ];

        $grants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $recipient->id,
            $permissions,
            'Pierwsze nadanie opiekunowi',
            self::PASSWORD,
        );

        $this->assertCount(2, $grants);
        $this->assertSame(2, DB::table('organization_permission_grants')
            ->where('organization_id', $administrator->organization_id)->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('organization_id', $administrator->organization_id)
            ->where('event_code', 'PERMISSION_BOOTSTRAP')->count());

        foreach ([
            fn () => app(RawMaterialPermissionBootstrap::class)->grantInitial(
                $administrator->id,
                $recipient->id,
                $permissions,
                'Drugie nadanie',
                self::PASSWORD,
            ),
            fn () => app(RawMaterialPermissionBootstrap::class)->grantInitial(
                $administrator->id,
                $administrator->id,
                $permissions,
                'Próba samonadania',
                self::PASSWORD,
            ),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An unauthorized bootstrap grant was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(2, DB::table('organization_permission_grants')
                    ->where('organization_id', $administrator->organization_id)->count());
            }
        }
    }

    public function test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('bootstrap-replay-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('bootstrap-replay-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);
        $this->enableRawMaterials($administrator, $location);

        $grants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Jawne ustanowienie opiekuna',
            self::PASSWORD,
        );
        $viewGrant = collect($grants)->firstWhere('permission', RawMaterialPermission::View->value);
        $operationId = DB::table('organization_permission_grants')
            ->where('id', $viewGrant['id'])->value('bootstrap_operation_id');
        $administration = app(OrganizationRawMaterialPermissionAdministration::class);
        $administration->revoke($steward, $location, $viewGrant['id'], 'Cofnięcie prawa do testu replay');

        $auditCountAfterRevocation = DB::table('raw_material_audit_events')
            ->where('organization_id', $administrator->organization_id)->count();
        $this->assertSame([], app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($steward, RawMaterialPermission::View));

        try {
            DB::transaction(fn () => DB::table('organization_permission_grants')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $administrator->organization_id,
                'user_id' => $steward->id,
                'permission' => RawMaterialPermission::View->value,
                'granted_by' => $administrator->id,
                'valid_from' => now(),
                'delegation_source_id' => null,
                'bootstrap_operation_id' => $operationId,
                'reason_code' => 'INITIAL_STEWARD',
            ]));
            $this->fail('A historical bootstrap operation ID must not restore a revoked permission.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }

        $this->assertSame(1, DB::table('organization_permission_grants')
            ->where('id', $viewGrant['id'])->count());
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $viewGrant['id'])->where('event_type', 'REVOKED')->count());
        $this->assertSame($auditCountAfterRevocation, DB::table('raw_material_audit_events')
            ->where('organization_id', $administrator->organization_id)->count());
        $this->assertSame([], app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($steward, RawMaterialPermission::View));
    }

    public function test_migration_guard_fails_closed_for_an_active_historical_duplicate(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('bootstrap-legacy-duplicate-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $recipient = $this->user('bootstrap-legacy-duplicate-recipient@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);

        $grants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $recipient->id,
            [
                RawMaterialPermission::View->value,
                RawMaterialPermission::PermissionDelegate->value,
            ],
            'Jawne ustanowienie opiekuna',
            self::PASSWORD,
        );
        $grant = DB::table('organization_permission_grants')->where('id', $grants[0]['id'])->first();

        $this->withSetupTransaction(function (Connection $connection) use ($grant): void {
            try {
                $connection->statement(
                    'ALTER TABLE organization_permission_grants DISABLE TRIGGER organization_permission_grants_validate'
                );
                $connection->statement(
                    'ALTER TABLE organization_permission_grants DISABLE TRIGGER organization_permission_grants_bootstrap_replay_guard'
                );
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $grant->organization_id,
                    'user_id' => $grant->user_id,
                    'permission' => $grant->permission,
                    'granted_by' => $grant->granted_by,
                    'valid_from' => $grant->valid_from,
                    'valid_until' => $grant->valid_until,
                    'delegation_source_id' => $grant->delegation_source_id,
                    'bootstrap_operation_id' => $grant->bootstrap_operation_id,
                    'reason_code' => $grant->reason_code,
                ]);
            } finally {
                $connection->statement(
                    'ALTER TABLE organization_permission_grants ENABLE TRIGGER organization_permission_grants_bootstrap_replay_guard'
                );
                $connection->statement(
                    'ALTER TABLE organization_permission_grants ENABLE TRIGGER organization_permission_grants_validate'
                );
            }
        });

        try {
            DB::select('SELECT raw_materials_assert_no_active_bootstrap_replays()');
            $this->fail('The migration guard must reject active historical duplicate grants.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString('manual review', $exception->getMessage());
        }
    }

    public function test_bootstrap_rejects_unknown_permissions_and_invalid_passwords(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('bootstrap-invalid-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $recipient = $this->user('bootstrap-invalid-steward@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);

        foreach ([
            fn () => app(RawMaterialPermissionBootstrap::class)->grantInitial(
                $administrator->id,
                $recipient->id,
                ['RAW_MATERIALS_ROOT'],
                'Nieznane uprawnienie',
                self::PASSWORD,
            ),
            fn () => app(RawMaterialPermissionBootstrap::class)->grantInitial(
                $administrator->id,
                $recipient->id,
                [RawMaterialPermission::PermissionDelegate->value],
                'Nieprawidłowe hasło',
                'Nieprawidłowe-Hasło!99',
            ),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An invalid bootstrap request was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(0, DB::table('organization_permission_grants')
                    ->where('organization_id', $administrator->organization_id)->count());
            }
        }
    }

    public function test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('permission-upgrade-bootstrap-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('permission-upgrade-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $this->role($steward, BusinessRole::Administrator);
        $recipient = $this->user('permission-upgrade-recipient@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);
        $initialGrants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Jawne ustanowienie opiekuna',
            self::PASSWORD,
        );
        $authorityGrant = collect($initialGrants)
            ->firstWhere('permission', RawMaterialPermission::PermissionDelegate->value);
        $permissions = [
            RawMaterialPermission::PurchasingCostsView->value,
            RawMaterialPermission::SupplierView->value,
        ];
        $upgrade = app(RawMaterialPermissionUpgrade::class);
        try {
            $upgrade->grant(
                $steward->id,
                $recipient->id,
                $permissions,
                'Uwierzytelnienie musi być ponowione',
                'stage2c-upgrade-badpass',
                'Nieprawidłowe-Hasło!99',
            );
            $this->fail('A permission upgrade with an incorrect password must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('raw_material_permission_upgrade_operations')->count());
        }

        $result = $upgrade->grant(
            $steward->id,
            $recipient->id,
            $permissions,
            'Zatwierdzony zakres dla organizacji już działającej',
            'stage2c-upgrade-0001',
            self::PASSWORD,
        );

        $this->assertFalse($result['replayed']);
        $this->assertCount(2, $result['grants']);
        $this->assertSame($authorityGrant['id'], DB::table('raw_material_permission_upgrade_operations')
            ->where('id', $result['operation_id'])->value('authority_grant_id'));
        $this->assertSame($permissions, DB::table('organization_permission_grants')
            ->where('bootstrap_operation_id', $result['operation_id'])
            ->orderBy('permission')->pluck('permission')->all());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('operation_id', $result['operation_id'])
            ->where('event_code', 'PERMISSION_UPGRADE')
            ->where('actor_user_id', $steward->id)
            ->where('outcome', 'ACCEPTED')->count());
        $this->assertSame(2, count(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::SupplierView))
            + count(app(RawMaterialAuthorization::class)
                ->effectiveGrantIds($recipient, RawMaterialPermission::PurchasingCostsView)));
        $this->assertSame(0, LocationModuleState::where('organization_id', $administrator->organization_id)
            ->where('module_key', 'RAW_MATERIALS')->count());

        $retry = $upgrade->grant(
            $steward->id,
            $recipient->id,
            array_reverse($permissions),
            'Zatwierdzony zakres dla organizacji już działającej',
            'stage2c-upgrade-0001',
            self::PASSWORD,
        );
        $this->assertTrue($retry['replayed']);
        $this->assertSame($result['operation_id'], $retry['operation_id']);
        $this->assertSame($result['grants'], $retry['grants']);
        $this->assertSame(2, DB::table('organization_permission_grants')
            ->where('bootstrap_operation_id', $result['operation_id'])->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('operation_id', $result['operation_id'])->where('event_code', 'PERMISSION_UPGRADE')->count());

        try {
            $upgrade->grant(
                $steward->id,
                $recipient->id,
                [RawMaterialPermission::SupplierManage->value],
                'Inna lista dla istniejącego klucza',
                'stage2c-upgrade-0001',
                self::PASSWORD,
            );
            $this->fail('A reused idempotency key with changed permissions must conflict.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_IDEMPOTENCY_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        try {
            DB::transaction(fn () => DB::table('raw_material_permission_upgrade_operations')
                ->where('id', $result['operation_id'])->update(['reason' => 'Niedozwolona zmiana']));
            $this->fail('Permission-upgrade operations must be append-only.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('raw_material_audit_events')
                ->where('operation_id', $result['operation_id'])->update(['reason_code' => 'Niedozwolona zmiana']));
            $this->fail('Permission-upgrade audit events must be append-only.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }

        app(IdentityService::class)->disable($steward);
        $grantIds = collect($result['grants'])->pluck('id');
        $this->assertSame($grantIds->count(), DB::table('organization_permission_grant_events')
            ->whereIn('grant_id', $grantIds)->where('event_type', 'REVOKED')->count());
        $this->updateFixture($steward, ['is_active' => true]);
        $this->assertSame([], app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::SupplierView));
        $this->assertSame([], app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::PurchasingCostsView));
    }

    public function test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql(): void
    {
        $location = $this->activeRestaurant();
        $bootstrapAdministrator = $this->user('nested-upgrade-bootstrap-admin@example.test');
        $this->role($bootstrapAdministrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($bootstrapAdministrator, $location);
        $steward = $this->user('nested-upgrade-steward@example.test');
        $this->role($steward, BusinessRole::Administrator);
        app(MembershipService::class)->assign($steward, $location);
        $delegatedAdministrator = $this->user('nested-upgrade-delegated-admin@example.test');
        $this->role($delegatedAdministrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($delegatedAdministrator, $location);
        $recipient = $this->user('nested-upgrade-recipient@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        $directSqlRecipient = $this->user('nested-upgrade-sql-recipient@example.test');
        app(MembershipService::class)->assign($directSqlRecipient, $location);
        $this->updateFixture($bootstrapAdministrator->organization, ['authorization_initialized' => true]);
        $this->enableRawMaterials($bootstrapAdministrator, $location);

        $initialGrants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $bootstrapAdministrator->id,
            $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Jawne ustanowienie opiekuna',
            self::PASSWORD,
        );
        $rootAuthority = collect($initialGrants)
            ->firstWhere('permission', RawMaterialPermission::PermissionDelegate->value);
        $upgrade = app(RawMaterialPermissionUpgrade::class);
        $firstUpgrade = $upgrade->grant(
            $steward->id,
            $delegatedAdministrator->id,
            [RawMaterialPermission::PermissionDelegate->value],
            'Jawne przekazanie delegowania',
            'nested-upgrade-first-001',
            self::PASSWORD,
        );
        $delegatedAuthority = DB::table('organization_permission_grants')
            ->where('bootstrap_operation_id', $firstUpgrade['operation_id'])
            ->where('permission', RawMaterialPermission::PermissionDelegate->value)
            ->first();
        $secondUpgrade = $upgrade->grant(
            $delegatedAdministrator->id,
            $recipient->id,
            [RawMaterialPermission::SupplierView->value],
            'Jawne przekazanie odczytu danych dostawcy',
            'nested-upgrade-second-001',
            self::PASSWORD,
        );
        $authorization = app(RawMaterialAuthorization::class);

        $this->assertNotEmpty($authorization->effectiveGrantIds(
            $delegatedAdministrator,
            RawMaterialPermission::PermissionDelegate,
        ));
        $this->assertNotEmpty($authorization->effectiveGrantIds(
            $recipient,
            RawMaterialPermission::SupplierView,
        ));
        $this->assertTrue((bool) DB::scalar(
            'SELECT raw_materials_permission_upgrade_authority_is_effective(?, ?) ',
            [$bootstrapAdministrator->organization_id, $secondUpgrade['operation_id']],
        ));

        app(OrganizationRawMaterialPermissionAdministration::class)->revoke(
            $steward,
            $location,
            $rootAuthority['id'],
            'Trwałe cofnięcie źródłowego prawa delegowania',
        );

        $this->assertEmpty($authorization->effectiveGrantIds(
            $delegatedAdministrator->fresh(),
            RawMaterialPermission::PermissionDelegate,
        ));
        $this->assertEmpty($authorization->effectiveGrantIds(
            $recipient->fresh(),
            RawMaterialPermission::SupplierView,
        ));
        $this->assertFalse((bool) DB::scalar(
            'SELECT raw_materials_permission_upgrade_authority_is_effective(?, ?)',
            [$bootstrapAdministrator->organization_id, $secondUpgrade['operation_id']],
        ));

        try {
            $upgrade->grant(
                $delegatedAdministrator->id,
                $directSqlRecipient->id,
                [RawMaterialPermission::SupplierManage->value],
                'Próba po cofnięciu źródłowego prawa',
                'nested-upgrade-denied-001',
                self::PASSWORD,
            );
            $this->fail('An actor with revoked upstream authority completed another upgrade.');
        } catch (AuthorizationException) {
            $this->assertSame(2, DB::table('raw_material_permission_upgrade_operations')->count());
        }

        $forgedOperationId = (string) Str::uuid();
        try {
            DB::transaction(function () use (
                $forgedOperationId,
                $bootstrapAdministrator,
                $delegatedAdministrator,
                $directSqlRecipient,
                $delegatedAuthority,
            ): void {
                DB::table('raw_material_permission_upgrade_operations')->insert([
                    'id' => $forgedOperationId,
                    'organization_id' => $bootstrapAdministrator->organization_id,
                    'idempotency_key' => 'nested-upgrade-sql-001',
                    'request_hash' => hash('sha256', 'direct SQL attempt'),
                    'actor_user_id' => $delegatedAdministrator->id,
                    'recipient_user_id' => $directSqlRecipient->id,
                    'authority_grant_id' => $delegatedAuthority->id,
                    'permissions' => json_encode(
                        [RawMaterialPermission::SupplierManage->value],
                        JSON_THROW_ON_ERROR,
                    ),
                    'reason' => 'Próba bezpośredniego SQL po cofnięciu authority',
                    'created_at' => now(),
                ]);
                DB::table('raw_material_audit_events')->insert([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $bootstrapAdministrator->organization_id,
                    'operation_id' => $forgedOperationId,
                    'actor_user_id' => $delegatedAdministrator->id,
                    'subject_type' => 'ORGANIZATION',
                    'subject_id' => $bootstrapAdministrator->organization_id,
                    'event_code' => 'PERMISSION_UPGRADE',
                    'outcome' => 'ACCEPTED',
                    'reason_code' => 'EXPLICIT_ADMIN_UPGRADE',
                    'metadata' => json_encode([
                        'recipient_user_id' => $directSqlRecipient->id,
                        'permissions' => [RawMaterialPermission::SupplierManage->value],
                        'reason' => 'Próba bezpośredniego SQL po cofnięciu authority',
                        'authority_grant_id' => $delegatedAuthority->id,
                        'idempotency_key' => 'nested-upgrade-sql-001',
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                DB::table('organization_permission_grants')->insert([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $bootstrapAdministrator->organization_id,
                    'user_id' => $directSqlRecipient->id,
                    'permission' => RawMaterialPermission::SupplierManage->value,
                    'granted_by' => $delegatedAdministrator->id,
                    'valid_from' => now(),
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $forgedOperationId,
                    'reason_code' => 'EXPLICIT_ADMIN_UPGRADE',
                ]);
            });
            $this->fail('PostgreSQL accepted a grant backed by revoked upstream authority.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0] ?? null);
            $this->assertSame(2, DB::table('raw_material_permission_upgrade_operations')->count());
            $this->assertSame(2, DB::table('raw_material_audit_events')
                ->where('event_code', 'PERMISSION_UPGRADE')->count());
        }
    }

    public function test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('permission-upgrade-denied-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $recipient = $this->user('permission-upgrade-denied-recipient@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);
        $service = app(RawMaterialPermissionUpgrade::class);

        try {
            $service->grant(
                $administrator->id,
                $recipient->id,
                [RawMaterialPermission::SupplierManage->value],
                'Brak prawa delegowania',
                'stage2c-upgrade-denied',
                self::PASSWORD,
            );
            $this->fail('An administrator without permission-delegation authority must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('raw_material_permission_upgrade_operations')->count());
            $this->assertSame(0, DB::table('organization_permission_grants')->count());
        }

        try {
            DB::table('organization_permission_grants')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $administrator->organization_id,
                'user_id' => $recipient->id,
                'permission' => RawMaterialPermission::SupplierManage->value,
                'granted_by' => $administrator->id,
                'valid_from' => now(),
                'delegation_source_id' => null,
                'bootstrap_operation_id' => (string) Str::uuid(),
                'reason_code' => 'DIRECT_SQL_PROBE',
            ]);
            $this->fail('A root grant without an audited bootstrap or upgrade operation must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_delegation_is_limited_to_effective_authority_and_revocation_is_audited(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('delegation-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('delegation-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $recipient = $this->user('delegation-recipient@example.test');
        app(MembershipService::class)->assign($recipient, $location);
        $suspensionRecipient = $this->user('suspension-delegation-recipient@example.test');
        app(MembershipService::class)->assign($suspensionRecipient, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);
        $this->enableRawMaterials($administrator, $location);
        $bootstrapGrants = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Pierwsze nadanie opiekunowi',
            self::PASSWORD,
        );
        $viewGrant = collect($bootstrapGrants)->firstWhere('permission', RawMaterialPermission::View->value);
        $service = app(OrganizationRawMaterialPermissionAdministration::class);
        $delegated = $service->grant(
            $steward,
            $location,
            $recipient,
            RawMaterialPermission::View,
            'Delegowanie odczytu katalogu',
        );

        $this->assertSame($viewGrant['id'], DB::table('organization_permission_grants')
            ->where('id', $delegated['id'])->value('delegation_source_id'));
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($recipient, RawMaterialPermission::View));
        $service->revoke($steward, $location, $delegated['id'], 'Jawne odwołanie delegacji');
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $delegated['id'])->where('event_type', 'REVOKED')->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $delegated['id'])->where('event_code', 'PERMISSION_REVOKED')
            ->where('actor_user_id', $steward->id)->count());
        $delegatedForSuspension = $service->grant(
            $steward,
            $location,
            $suspensionRecipient,
            RawMaterialPermission::View,
            'Delegowanie odbiorcy objętego zawieszeniem grantora',
        );
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));
        try {
            $service->grant(
                $steward,
                $location,
                $recipient,
                RawMaterialPermission::PermissionDelegate,
                'Próba delegacji uprawnienia delegowania',
            );
            $this->fail('The delegation permission was delegated.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('organization_permission_grants')
                ->where('user_id', $recipient->id)->count());
        }

        $contextResponse = $this->actingAs($recipient, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ]);
        $this->assertSame(200, $contextResponse->status(), $contextResponse->getContent());
        app(IdentityService::class)->disable($steward);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $viewGrant['id'])->where('reason_code', 'USER_DEACTIVATED')->count());
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $delegatedForSuspension['id'])->where('reason_code', 'USER_DEACTIVATED')->count());
        $this->assertSame(2, DB::table('raw_material_audit_events')
            ->whereIn('subject_id', [$viewGrant['id'], $delegatedForSuspension['id']])
            ->where('event_code', 'PERMISSION_REVOKED')
            ->where('reason_code', 'USER_DEACTIVATED')
            ->whereNull('actor_user_id')->count());
        $this->getJson('/api/v1/raw-materials')->assertForbidden();
        try {
            DB::transaction(fn () => DB::table('organization_permission_grants')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $steward->organization_id,
                'user_id' => $administrator->id,
                'permission' => RawMaterialPermission::View->value,
                'granted_by' => $steward->id,
                'valid_from' => now(),
                'delegation_source_id' => $viewGrant['id'],
                'bootstrap_operation_id' => null,
                'reason_code' => 'DIRECT_SQL_PROBE',
            ]));
            $this->fail('A suspended grantor created a delegated grant through direct SQL.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0] ?? null);
        }
        $this->updateFixture($steward, ['is_active' => true]);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));

        $stewardMembership = app(MembershipService::class)->available($steward)
            ->where('location_id', $location->id)->firstOrFail();
        app(MembershipService::class)->deactivate($stewardMembership, 'Członkostwo testowe zawieszone');
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));
        app(MembershipService::class)->assign($steward->fresh(), $location);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($suspensionRecipient, RawMaterialPermission::View));

        try {
            $service->grant(
                $steward,
                $location,
                $recipient,
                RawMaterialPermission::Edit,
                'Próba eskalacji uprawnień',
            );
            $this->fail('A permission outside the steward authority was delegated.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('organization_permission_grants')
                ->where('user_id', $recipient->id)->count());
        }

        $this->assertSame(0, DB::table('organization_permission_grant_events')
            ->where('grant_id', $delegatedForSuspension['id'])->where('reason_code', 'USER_PROVIDED')->count());
    }

    public function test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('permanent-revoke-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('permanent-revoke-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $recipient = $this->user('permanent-revoke-recipient@example.test');
        $recipientMembership = app(MembershipService::class)->assign($recipient, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);
        $this->enableRawMaterials($administrator, $location);

        $bootstrap = app(RawMaterialPermissionBootstrap::class)->grantInitial(
            $administrator->id,
            $steward->id,
            [RawMaterialPermission::View->value, RawMaterialPermission::PermissionDelegate->value],
            'Nadanie do testu trwałego odwołania',
            self::PASSWORD,
        );
        $sourceGrant = collect($bootstrap)->firstWhere('permission', RawMaterialPermission::View->value);
        $permissions = app(OrganizationRawMaterialPermissionAdministration::class);
        $firstDelegation = $permissions->grant(
            $steward,
            $location,
            $recipient,
            RawMaterialPermission::View,
            'Pierwsze delegowanie',
        );
        $authorization = app(RawMaterialAuthorization::class);
        $this->assertNotEmpty($authorization->effectiveGrantIds($recipient, RawMaterialPermission::View));

        try {
            DB::transaction(function () use ($recipientMembership): void {
                app(MembershipService::class)->deactivate($recipientMembership, 'Zawieszenie wycofane przez rollback');
                throw new \RuntimeException('rollback fixture');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback fixture', $exception->getMessage());
        }
        $this->assertTrue($recipientMembership->fresh()->is_active);
        $this->assertSame(0, DB::table('organization_permission_grant_events')
            ->where('grant_id', $firstDelegation['id'])->count());
        $this->assertNotEmpty($authorization->effectiveGrantIds($recipient, RawMaterialPermission::View));

        app(MembershipService::class)->deactivate($recipientMembership, 'Zawieszenie członkostwa');
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $firstDelegation['id'])
            ->where('reason_code', 'MEMBERSHIP_DEACTIVATED')->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $firstDelegation['id'])
            ->where('event_code', 'PERMISSION_REVOKED')
            ->where('reason_code', 'MEMBERSHIP_DEACTIVATED')
            ->whereJsonContains('metadata', [
                'source_type' => 'MEMBERSHIP',
                'source_id' => $recipientMembership->id,
                'affected_user_id' => $recipient->id,
            ])->count());

        app(MembershipService::class)->assign($recipient->fresh(), $location);
        $this->assertEmpty($authorization->effectiveGrantIds($recipient, RawMaterialPermission::View));

        $secondDelegation = $permissions->grant(
            $steward,
            $location,
            $recipient->fresh(),
            RawMaterialPermission::View,
            'Jawne ponowne nadanie po wznowieniu członkostwa',
        );
        $this->assertNotSame($firstDelegation['id'], $secondDelegation['id']);
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $secondDelegation['id'])
            ->where('event_code', 'PERMISSION_GRANTED')
            ->where('actor_user_id', $steward->id)->count());
        $this->assertSame([$secondDelegation['id']], $authorization->effectiveGrantIds(
            $recipient->fresh(),
            RawMaterialPermission::View,
        ));

        $permissions->revoke($steward, $location, $secondDelegation['id'], 'Test jawnego cofnięcia po wznowieniu');
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $secondDelegation['id'])->count());
        $this->assertEmpty($authorization->effectiveGrantIds($recipient->fresh(), RawMaterialPermission::View));
        $thirdDelegation = $permissions->grant(
            $steward,
            $location,
            $recipient->fresh(),
            RawMaterialPermission::View,
            'Jawne nadanie po cofnięciu poprzedniego grantu',
        );

        app(IdentityService::class)->disable($recipient->fresh());
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $thirdDelegation['id'])
            ->where('reason_code', 'USER_DEACTIVATED')->count());
        $this->updateFixture($recipient, ['is_active' => true]);
        $this->assertEmpty($authorization->effectiveGrantIds($recipient->fresh(), RawMaterialPermission::View));
        $this->assertSame(0, DB::table('organization_permission_grant_events')
            ->where('grant_id', $sourceGrant['id'])->count());
        $this->assertSame(1, DB::table('organization_permission_grant_events')
            ->where('grant_id', $thirdDelegation['id'])->whereNull('actor_user_id')->count());
    }

    public function test_delegated_grants_expire_with_source_permission_and_delegation_authority(): void
    {
        $location = $this->activeRestaurant();
        $administrator = $this->user('expiry-admin@example.test');
        $this->role($administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($administrator, $location);
        $steward = $this->user('expiry-steward@example.test');
        app(MembershipService::class)->assign($steward, $location);
        $viewRecipient = $this->user('expiry-view-recipient@example.test');
        app(MembershipService::class)->assign($viewRecipient, $location);
        $createRecipient = $this->user('expiry-create-recipient@example.test');
        app(MembershipService::class)->assign($createRecipient, $location);
        $this->updateFixture($administrator->organization, ['authorization_initialized' => true]);
        $this->enableRawMaterials($administrator, $location);

        $viewExpiresAt = now()->addSeconds(30);
        $delegateExpiresAt = now()->addSeconds(40);
        $bootstrap = $this->insertBootstrapGrants($administrator, $steward, [
            RawMaterialPermission::View->value => $viewExpiresAt,
            RawMaterialPermission::Create->value => null,
            RawMaterialPermission::PermissionDelegate->value => $delegateExpiresAt,
        ]);
        $service = app(OrganizationRawMaterialPermissionAdministration::class);
        $viewGrant = $service->grant(
            $steward,
            $location,
            $viewRecipient,
            RawMaterialPermission::View,
            'Delegacja z wygasającym źródłem',
            null,
            $viewExpiresAt->copy()->subSecond()->toISOString(),
        );
        $createGrant = $service->grant(
            $steward,
            $location,
            $createRecipient,
            RawMaterialPermission::Create,
            'Delegacja z wygasającym prawem delegowania',
            null,
            $delegateExpiresAt->copy()->subSecond()->toISOString(),
        );
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($viewRecipient, RawMaterialPermission::View));
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($createRecipient, RawMaterialPermission::Create));

        sleep(31);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($viewRecipient, RawMaterialPermission::View));
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($createRecipient, RawMaterialPermission::Create));
        $this->assertNotEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($steward, RawMaterialPermission::PermissionDelegate));

        sleep(10);
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($createRecipient, RawMaterialPermission::Create));
        $this->assertEmpty(app(RawMaterialAuthorization::class)
            ->effectiveGrantIds($steward, RawMaterialPermission::PermissionDelegate));
        $this->assertSame(0, DB::table('organization_permission_grant_events')
            ->whereIn('grant_id', [$viewGrant['id'], $createGrant['id']])->count());
        $this->assertCount(3, $bootstrap);
    }

    private function enableRawMaterials(User $administrator, Location $location): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        app(ModuleConfigurationService::class)->configure($administrator, $location, [
            'module_ids' => ['RAW_MATERIALS'],
            'location_ids' => [$location->id],
            'enabled' => true,
            'current_password' => self::PASSWORD,
        ]);
    }

    /**
     * @param  array<string, Carbon|null>  $expiries
     * @return list<array{id: string, permission: string}>
     */
    private function insertBootstrapGrants(User $administrator, User $recipient, array $expiries): array
    {
        return $this->withSetupTransaction(function (Connection $connection) use ($administrator, $recipient, $expiries): array {
            $operationId = Str::uuid()->toString();
            $permissions = array_keys($expiries);
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $administrator->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $administrator->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $administrator->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $recipient->id,
                    'permissions' => $permissions,
                ], JSON_THROW_ON_ERROR),
            ]);

            $grants = [];
            foreach ($expiries as $permission => $validUntil) {
                $id = Str::uuid()->toString();
                $connection->table('organization_permission_grants')->insert([
                    'id' => $id,
                    'organization_id' => $administrator->organization_id,
                    'user_id' => $recipient->id,
                    'permission' => $permission,
                    'granted_by' => $administrator->id,
                    'valid_from' => now()->subSecond(),
                    'valid_until' => $validUntil,
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
                $grants[] = ['id' => $id, 'permission' => $permission];
            }

            return $grants;
        });
    }
}
