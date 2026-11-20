<?php

namespace Tests\Feature;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class OrganizationCapabilityDatabaseIntegrityTest extends AuthorizationTestCase
{
    private Location $kitchen;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->kitchen = Location::where('code', 'CK1')->firstOrFail();
        $this->administrator = $this->user('capability-integrity@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->kitchen, $this->administrator->id);
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
    }

    public function test_postgresql_17_installs_pinned_trigger_functions_deferred_checks_and_least_privilege_grants(): void
    {
        $serverVersion = (int) DB::scalar('SHOW server_version_num');
        $this->assertGreaterThanOrEqual(170000, $serverVersion);
        $this->assertLessThan(180000, $serverVersion);

        $functions = DB::table('pg_proc as procedure')
            ->join('pg_namespace as namespace', 'namespace.oid', '=', 'procedure.pronamespace')
            ->where('namespace.nspname', 'veltra_private')
            ->whereIn('procedure.proname', [
                'guard_organization_capability_state',
                'reject_organization_capability_event_mutation',
                'require_organization_capability_event_pair',
                'require_organization_capability_state_audit',
            ])
            ->orderBy('procedure.proname')
            ->selectRaw("array_to_string(procedure.proconfig, '|') AS function_config")
            ->pluck('function_config');
        $this->assertCount(4, $functions);
        foreach ($functions as $config) {
            $this->assertStringContainsString('search_path=pg_catalog, public, pg_temp', $config);
        }

        $deferredTriggers = DB::table('pg_trigger as trigger')
            ->join('pg_class as relation', 'relation.oid', '=', 'trigger.tgrelid')
            ->whereIn('relation.relname', ['organization_capability_states', 'organization_capability_events'])
            ->where('trigger.tgdeferrable', true)
            ->where('trigger.tginitdeferred', true)
            ->where('trigger.tgisinternal', false)
            ->count();
        $this->assertSame(2, $deferredTriggers);

        $this->assertSame([
            'organization_capability_events:INSERT:organization-capability.audit',
            'organization_capability_states:INSERT:organization-capability.configure',
            'organization_capability_states:UPDATE:organization-capability.configure',
        ], DB::connection('pgsql_setup')->table('veltra_private.authorization_mutation_scopes')
            ->whereIn('table_name', ['organization_capability_states', 'organization_capability_events'])
            ->orderBy('table_name')->orderBy('operation')
            ->get()
            ->map(fn (object $scope): string => "{$scope->table_name}:{$scope->operation}:{$scope->scope_prefix}")
            ->all());
        $this->assertSame(
            2,
            DB::connection('pgsql_setup')->table('veltra_private.runtime_table_allowlist')
                ->whereIn('table_name', ['organization_capability_states', 'organization_capability_events'])
                ->count(),
        );

        $privileges = DB::selectOne(<<<'SQL'
            SELECT
                has_table_privilege('veltra_app', 'public.organization_capability_states', 'SELECT')::text AS state_select,
                has_table_privilege('veltra_app', 'public.organization_capability_states', 'INSERT')::text AS state_insert,
                has_table_privilege('veltra_app', 'public.organization_capability_states', 'UPDATE')::text AS state_update,
                has_table_privilege('veltra_app', 'public.organization_capability_states', 'DELETE')::text AS state_delete,
                has_table_privilege('veltra_app', 'public.organization_capability_states', 'TRUNCATE')::text AS state_truncate,
                has_table_privilege('veltra_app', 'public.organization_capability_events', 'SELECT')::text AS events_select,
                has_table_privilege('veltra_app', 'public.organization_capability_events', 'INSERT')::text AS events_insert,
                has_table_privilege('veltra_app', 'public.organization_capability_events', 'UPDATE')::text AS events_update,
                has_table_privilege('veltra_app', 'public.organization_capability_events', 'DELETE')::text AS events_delete,
                has_table_privilege('veltra_app', 'public.organization_capability_events', 'TRUNCATE')::text AS events_truncate
            SQL);
        $this->assertSame('true', $privileges->state_select);
        $this->assertSame('true', $privileges->state_insert);
        $this->assertSame('true', $privileges->state_update);
        $this->assertSame('false', $privileges->state_delete);
        $this->assertSame('false', $privileges->state_truncate);
        $this->assertSame('true', $privileges->events_select);
        $this->assertSame('true', $privileges->events_insert);
        $this->assertSame('false', $privileges->events_update);
        $this->assertSame('false', $privileges->events_delete);
        $this->assertSame('false', $privileges->events_truncate);
    }

    public function test_state_identity_versions_and_audit_rows_are_immutable(): void
    {
        $this->enable();
        $state = (array) DB::table('organization_capability_states')->firstOrFail();
        $event = (array) DB::table('organization_capability_events')
            ->where('event_kind', 'activation_transition')->firstOrFail();

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_states')->where('id', $state['id'])->update(['id' => Str::uuid()->toString()]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_states')->where('id', $state['id'])->update(['state_version' => 3]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_states')->where('id', $state['id'])->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_events')->where('id', $event['id'])->update(['detail_code' => 'tampered']));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_events')->where('id', $event['id'])->delete());

        $this->assertDatabaseRejects('23503', fn (Connection $connection) => $connection
            ->table('organization_capability_states')->where('id', $state['id'])->update([
                'enabled' => false,
                'state_version' => 2,
                'last_transition_event_id' => Str::uuid()->toString(),
            ]));
    }

    public function test_deferred_integrity_rejects_missing_or_orphaned_attempts_and_transitions(): void
    {
        $inconsistentRejection = $this->eventValues(
            Str::uuid()->toString(), 'activation_attempt', 'rejected', false, true, true, 0, 0, 0, false, 'operation_rejected',
        );
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_events')->insert($inconsistentRejection));

        $inconsistentIdempotentAttempt = $this->eventValues(
            Str::uuid()->toString(), 'activation_attempt', 'accepted', false, false, true, 0, 0, 0, false, 'activation_idempotent',
        );
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_events')->insert($inconsistentIdempotentAttempt));

        $operationId = Str::uuid()->toString();
        $changed = $this->eventValues(
            $operationId, 'activation_attempt', 'accepted', false, true, true, 0, 0, 1, true, 'activation_accepted',
        );
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_events')->insert($changed));

        $orphanTransition = $this->eventValues(
            Str::uuid()->toString(), 'activation_transition', 'accepted', false, true, true, 0, 0, 1, true, 'activation_accepted',
        );
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('organization_capability_events')->insert($orphanTransition));

        $transitionId = Str::uuid()->toString();
        $operationId = Str::uuid()->toString();
        $attempt = $this->eventValues(
            $operationId, 'activation_attempt', 'accepted', false, true, true, 0, 0, 1, true, 'activation_accepted',
        );
        $transition = $this->eventValues(
            $operationId, 'activation_transition', 'accepted', false, true, true, 0, 0, 1, true, 'activation_accepted',
        );
        $transition['id'] = $transitionId;
        $transition['detail_code'] = 'mismatched_detail_code';
        $this->assertDatabaseRejects('23514', function (Connection $connection) use ($attempt, $transition): void {
            $connection->table('organization_capability_events')->insert([$attempt, $transition]);
        });
    }

    public function test_duplicate_events_and_cross_organization_attribution_are_rejected(): void
    {
        $attempt = $this->eventValues(
            Str::uuid()->toString(), 'activation_attempt', 'rejected', false, false, false, 0, 0, 0, false, 'operation_rejected',
        );
        $this->assertDatabaseRejects('23505', function (Connection $connection) use ($attempt): void {
            $connection->table('organization_capability_events')->insert([$attempt, [
                ...$attempt,
                'id' => Str::uuid()->toString(),
            ]]);
        });

        $this->withForeignOrganizationFixture(function (Location $foreignLocation): void {
            $event = $this->eventValues(
                Str::uuid()->toString(), 'activation_attempt', 'rejected', false, false, false, 0, 0, 0, false, 'operation_rejected',
            );
            $event['operating_location_id'] = $foreignLocation->id;
            $this->assertDatabaseRejects('23503', fn (Connection $connection) => $connection
                ->table('organization_capability_events')->insert($event));
        });
    }

    public function test_foreign_organization_actor_cannot_be_attributed_to_capability_events_or_state(): void
    {
        $this->withForeignOrganizationFixture(function (Location $foreignLocation): void {
            $foreignActor = User::on('pgsql_setup')->create([
                'organization_id' => $foreignLocation->organization_id,
                'first_name' => 'Foreign',
                'last_name' => 'Actor',
                'email' => 'foreign-capability-actor@example.test',
                'password' => self::PASSWORD,
                'is_active' => true,
            ]);

            $event = $this->eventValues(
                Str::uuid()->toString(), 'activation_attempt', 'rejected', false, false, false, 0, 0, 0, false, 'operation_rejected',
            );
            $event['actor_id'] = $foreignActor->id;
            $this->assertDatabaseRejects('23503', fn (Connection $connection) => $connection
                ->table('organization_capability_events')->insert($event));

            $state = [
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->administrator->organization_id,
                'capability_key' => 'BOSS_RAW_CATALOG_READ',
                'enabled' => true,
                'state_version' => 1,
                'last_transition_event_id' => Str::uuid()->toString(),
                'created_by' => $foreignActor->id,
                'updated_by' => $foreignActor->id,
            ];
            $this->assertDatabaseRejects('23503', fn (Connection $connection) => $connection
                ->table('organization_capability_states')->insert($state));
        }, truncateAfterOperation: true);
    }

    public function test_signed_mutation_scope_matrix_keeps_capability_stage1e_rbac_and_raw_tables_separate(): void
    {
        $expectedScopes = [
            'location_module_states' => [
                'INSERT' => ['module.'],
                'UPDATE' => ['module.'],
                'DELETE' => ['module.'],
            ],
            'security_events' => [
                'INSERT' => ['module.audit', 'security.event'],
                'UPDATE' => ['module.audit', 'security.event'],
                'DELETE' => ['module.audit', 'security.event'],
            ],
            'role_assignments' => [
                'INSERT' => [
                    'administrator.bootstrap',
                    ...array_map(fn (BusinessRole $role): string => 'role.grant.'.$role->value, BusinessRole::cases()),
                ],
                'UPDATE' => [
                    'membership.deactivate',
                    'membership.remove',
                    ...array_map(fn (BusinessRole $role): string => 'role.revoke.'.$role->value, BusinessRole::cases()),
                ],
            ],
            'permission_grants' => [
                'INSERT' => ['membership.', 'permission.'],
                'UPDATE' => ['membership.', 'permission.'],
                'DELETE' => ['membership.', 'permission.'],
            ],
            'raw_materials' => [
                'INSERT' => ['raw-material.mutate'],
                'UPDATE' => ['raw-material.draft.delete', 'raw-material.mutate', 'raw-material.unblock'],
                'DELETE' => ['raw-material.mutate'],
            ],
            'organization_capability_states' => [
                'INSERT' => ['organization-capability.configure'],
                'UPDATE' => ['organization-capability.configure'],
            ],
            'organization_capability_events' => [
                'INSERT' => ['organization-capability.audit'],
            ],
        ];
        $expected = [];
        foreach ($expectedScopes as $table => $operations) {
            foreach ($operations as $operation => $prefixes) {
                foreach ($prefixes as $prefix) {
                    $expected[] = "{$table}:{$operation}:{$prefix}";
                }
            }
        }
        sort($expected);

        $actual = DB::connection('pgsql_setup')->table('veltra_private.authorization_mutation_scopes')
            ->whereIn('table_name', array_keys($expectedScopes))
            ->get()
            ->map(fn (object $scope): string => "{$scope->table_name}:{$scope->operation}:{$scope->scope_prefix}")
            ->all();
        sort($actual);
        $this->assertSame($expected, $actual);

        $rawMaterialId = $this->createRawMaterialScopeProbe();
        $this->enable();
        $stateId = DB::table('organization_capability_states')->value('id');
        $roleAssignmentId = DB::table('role_assignments')->where('user_id', $this->administrator->id)->value('id');
        $moduleStateId = DB::table('location_module_states')->where('location_id', $this->kitchen->id)->value('id');
        $restaurant = Location::where('code', 'R1')->firstOrFail();
        $permissionRecipient = $this->user('capability-scope-permission-recipient@example.test');
        $membership = app(MembershipService::class)->assign($permissionRecipient, $restaurant, $this->administrator->id);
        $state = DB::table('organization_capability_states')->where('id', $stateId)->firstOrFail();
        $eventValues = $this->eventValues(
            Str::uuid()->toString(),
            'activation_attempt',
            'rejected',
            (bool) $state->enabled,
            ! (bool) $state->enabled,
            (bool) $state->enabled,
            (int) $state->state_version,
            (int) $state->state_version,
            (int) $state->state_version,
            false,
            'operation_rejected',
        );
        $securityEvent = [
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->administrator->organization_id,
            'actor_id' => $this->administrator->id,
            'target_id' => $this->administrator->id,
            'location_id' => $this->kitchen->id,
            'action' => 'scope.matrix.probe',
            'outcome' => 'accepted',
        ];
        $permissionGrant = [
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->administrator->organization_id,
            'user_id' => $permissionRecipient->id,
            'location_id' => $restaurant->id,
            'membership_id' => $membership->id,
            'permission' => 'REPORTS_VIEW',
            'granted_by' => $this->administrator->id,
            'valid_from' => now(),
        ];
        $mismatchedPairs = [
            [AuthorizationMutationScope::organizationCapabilityConfigure(), 'organization_capability_events'],
            [AuthorizationMutationScope::organizationCapabilityAudit(), 'organization_capability_states'],
            [AuthorizationMutationScope::moduleConfigure(), 'organization_capability_states'],
            [AuthorizationMutationScope::moduleAudit(), 'organization_capability_events'],
            [AuthorizationMutationScope::organizationCapabilityConfigure(), 'location_module_states'],
            [AuthorizationMutationScope::organizationCapabilityAudit(), 'location_module_states'],
            [AuthorizationMutationScope::organizationCapabilityConfigure(), 'security_events'],
            [AuthorizationMutationScope::organizationCapabilityAudit(), 'security_events'],
            [AuthorizationMutationScope::organizationCapabilityConfigure(), 'role_assignments'],
            [AuthorizationMutationScope::organizationCapabilityAudit(), 'role_assignments'],
            [AuthorizationMutationScope::organizationCapabilityConfigure(), 'permission_grants'],
            [AuthorizationMutationScope::organizationCapabilityAudit(), 'permission_grants'],
            [AuthorizationMutationScope::organizationCapabilityConfigure(), 'raw_materials'],
            [AuthorizationMutationScope::organizationCapabilityAudit(), 'raw_materials'],
        ];

        foreach ($mismatchedPairs as [$scope, $table]) {
            try {
                app(AuthorizationMutationContext::class)->run($scope, $this->administrator->id, function () use (
                    $table,
                    $stateId,
                    $roleAssignmentId,
                    $moduleStateId,
                    $rawMaterialId,
                    $eventValues,
                    $securityEvent,
                    $permissionGrant,
                ): void {
                    if ($table === 'organization_capability_states') {
                        DB::table($table)->where('id', $stateId)->update(['updated_at' => DB::raw('updated_at')]);

                        return;
                    }
                    if ($table === 'organization_capability_events') {
                        DB::table($table)->insert($eventValues);

                        return;
                    }
                    if ($table === 'location_module_states') {
                        DB::table($table)->where('id', $moduleStateId)->update(['enabled' => DB::raw('enabled')]);

                        return;
                    }
                    if ($table === 'security_events') {
                        DB::table($table)->insert($securityEvent);

                        return;
                    }
                    if ($table === 'role_assignments') {
                        DB::table($table)->where('id', $roleAssignmentId)->update(['revoked_at' => DB::raw('revoked_at')]);

                        return;
                    }
                    if ($table === 'permission_grants') {
                        DB::table($table)->insert($permissionGrant);

                        return;
                    }
                    if ($table === 'raw_materials') {
                        DB::table($table)->where('id', $rawMaterialId)->update(['updated_at' => DB::raw('updated_at')]);

                        return;
                    }

                    DB::statement("INSERT INTO public.{$table} DEFAULT VALUES");
                });
                $this->fail('The '.$scope->value().' scope unexpectedly authorized '.$table.'.');
            } catch (QueryException $exception) {
                $this->assertSame(
                    '42501',
                    $exception->errorInfo[0],
                    $scope->value().' against '.$table.': '.($exception->errorInfo[2] ?? $exception->getMessage()),
                );
                $this->assertStringContainsString('out of scope', $exception->getMessage(), $scope->value().' against '.$table);
            }
        }
    }

    public function test_signed_mutation_scopes_are_narrow_and_cannot_be_substituted(): void
    {
        $event = $this->eventValues(
            Str::uuid()->toString(), 'activation_attempt', 'rejected', false, false, false, 0, 0, 0, false, 'operation_rejected',
        );
        $this->assertRuntimeDatabaseRejects('42501', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::moduleConfigure(),
            $this->administrator->id,
            fn () => DB::table('organization_capability_events')->insert($event),
        ));

        $state = [
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->administrator->organization_id,
            'capability_key' => 'BOSS_RAW_CATALOG_READ',
            'enabled' => true,
            'state_version' => 1,
            'last_transition_event_id' => Str::uuid()->toString(),
            'created_by' => $this->administrator->id,
            'updated_by' => $this->administrator->id,
        ];
        $this->assertRuntimeDatabaseRejects('42501', fn () => app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::organizationCapabilityAudit(),
            $this->administrator->id,
            fn () => DB::table('organization_capability_states')->insert($state),
        ));

        $this->assertSame(0, DB::table('organization_capability_states')->count());
        $this->assertSame(0, DB::table('organization_capability_events')->count());
    }

    public function test_runtime_cannot_mutate_append_only_history_or_delete_state(): void
    {
        $this->enable();
        $this->assertRuntimeDatabaseRejects('42501', fn () => DB::table('organization_capability_events')->whereNotNull('id')->update(['detail_code' => 'tampered']));
        $this->assertRuntimeDatabaseRejects('42501', fn () => DB::table('organization_capability_events')->whereNotNull('id')->delete());
        $this->assertRuntimeDatabaseRejects('42501', fn () => DB::table('organization_capability_states')->whereNotNull('id')->delete());
    }

    private function enable(): void
    {
        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->kitchen,
            'BOSS_RAW_CATALOG_READ',
            Str::uuid()->toString(),
            [
                'enabled' => true,
                'expected_state_version' => 0,
                'current_password' => self::PASSWORD,
            ],
        );
    }

    private function createRawMaterialScopeProbe(): string
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        $steward = $this->user('capability-scope-steward@example.test');
        app(MembershipService::class)->assign($steward, $this->kitchen, $steward->id);
        $operationId = Str::uuid()->toString();
        $categoryId = Str::uuid()->toString();

        $this->withSetupTransaction(function (Connection $connection) use ($steward, $operationId, $categoryId): void {
            $connection->table('organizations')
                ->where('id', $this->administrator->organization_id)
                ->update(['authorization_initialized' => true]);
            $connection->table('location_module_states')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->administrator->organization_id,
                'location_id' => $this->kitchen->id,
                'module_key' => 'RAW_MATERIALS',
                'enabled' => true,
                'created_by' => $this->administrator->id,
                'updated_by' => $this->administrator->id,
            ]);
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->administrator->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $this->administrator->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $this->administrator->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $steward->id,
                    'permissions' => [RawMaterialPermission::Create->value],
                ], JSON_THROW_ON_ERROR),
            ]);
            $connection->table('organization_permission_grants')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->administrator->organization_id,
                'user_id' => $steward->id,
                'permission' => RawMaterialPermission::Create->value,
                'granted_by' => $this->administrator->id,
                'valid_from' => now(),
                'delegation_source_id' => null,
                'bootstrap_operation_id' => $operationId,
                'reason_code' => 'INITIAL_STEWARD',
            ]);
            $connection->table('raw_material_categories')->insert([
                'id' => $categoryId,
                'organization_id' => $this->administrator->organization_id,
                'parent_id' => null,
                'code' => 'SCOPE_PROBE',
                'name' => 'Scope probe',
                'depth' => 1,
                'created_by' => $steward->id,
            ]);
        });

        return app(RawMaterialService::class)->create($steward, $this->kitchen, [
            'category_id' => $categoryId,
            'name' => 'Scope probe material',
            'base_unit_code' => 'kg',
            'nominal_quantity' => '1000',
        ])['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventValues(
        string $operationId,
        string $eventKind,
        string $outcome,
        bool $previous,
        bool $requested,
        bool $effective,
        int $expectedVersion,
        int $previousVersion,
        int $resultVersion,
        bool $changed,
        string $detailCode,
    ): array {
        return [
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->administrator->organization_id,
            'capability_key' => 'BOSS_RAW_CATALOG_READ',
            'operation_id' => $operationId,
            'event_kind' => $eventKind,
            'outcome' => $outcome,
            'actor_id' => $this->administrator->id,
            'operating_location_id' => $this->kitchen->id,
            'previous_enabled' => $previous,
            'requested_enabled' => $requested,
            'effective_enabled' => $effective,
            'expected_state_version' => $expectedVersion,
            'previous_state_version' => $previousVersion,
            'result_state_version' => $resultVersion,
            'state_changed' => $changed,
            'detail_code' => $detailCode,
        ];
    }
}
