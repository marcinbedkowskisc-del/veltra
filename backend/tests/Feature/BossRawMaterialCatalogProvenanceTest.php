<?php

namespace Tests\Feature;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Domain\RawMaterials\BossRawMaterialCatalogService;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Domain\Sku\SkuPrefix;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class BossRawMaterialCatalogProvenanceTest extends AuthorizationTestCase
{
    private Location $context;

    private User $boss;

    private User $administrator;

    private User $rawSteward;

    private RawMaterialService $rawMaterials;

    private string $categoryId;

    private string $allergenCatalogId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->context = $this->primaryCentralKitchen();
        $this->boss = $this->user('boss-raw-catalog-data@example.test');
        $this->administrator = $this->user('admin-raw-catalog-data@example.test');
        $this->rawSteward = $this->user('raw-raw-catalog-data@example.test');
        $this->role($this->boss, BusinessRole::Boss);
        $this->role($this->administrator, BusinessRole::Administrator);
        $this->role($this->rawSteward, BusinessRole::Administrator);
        foreach ([$this->boss, $this->administrator, $this->rawSteward] as $user) {
            app(MembershipService::class)->assign($user, $this->context, $user->id);
        }

        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->context,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(),
            [
                'enabled' => true,
                'expected_state_version' => 0,
                'current_password' => self::PASSWORD,
            ],
        );

        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->rawSteward->organization_id,
            'location_id' => $this->context->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->administrator->id,
            'updated_by' => $this->administrator->id,
        ]);
        $this->bootstrapRawPermissions($this->administrator, $this->rawSteward);
        $this->rawMaterials = app(RawMaterialService::class);
        $category = $this->rawMaterials->createCategory($this->rawSteward, $this->context, [
            'code' => 'BOSS_RAW_TEST',
            'name' => 'BOSS test category',
        ]);
        $this->categoryId = $category['id'];
        $this->allergenCatalogId = $this->publishedAllergenCatalog();
    }

    public function test_only_approved_active_projection_is_returned_and_pending_values_do_not_fallback(): void
    {
        $materialId = $this->createApprovedMaterial(
            'Approved visible name',
            'Do not expose this description secret-description-91',
        );
        $draft = $this->rawMaterials->create($this->rawSteward, $this->context, [
            'category_id' => $this->categoryId,
            'name' => 'Unapproved draft name',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            'description' => 'draft-secret-92',
        ]);

        $this->rawMaterials->revise($this->rawSteward, $this->context, $materialId, [
            'category_id' => $this->categoryId,
            'name' => 'Pending replacement name',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
        ], $this->rawVersion($materialId));
        $this->assertSame('Approved visible name', $this->listedItem($materialId)['name']);

        $this->rawMaterials->submit($this->rawSteward, $this->context, $materialId, $this->rawVersion($materialId));
        $response = $this->listing();
        $this->assertSame(1, $response['meta']['total']);
        $this->assertSame('Approved visible name', $response['data'][0]['name']);
        $this->assertSame($materialId, $response['data'][0]['id']);
        $this->assertNotSame($draft['id'], $response['data'][0]['id']);
        $this->assertSame(
            ['id', 'code', 'name', 'category', 'base_unit_code', 'supplier_names'],
            array_keys($response['data'][0]),
        );
        $this->assertSame(['id', 'name'], array_keys($response['data'][0]['category']));
        $this->assertSame([], $this->listing(['search' => 'secret-description-91'])['data']);
        $this->assertSame([], $this->listing(['search' => 'draft-secret-92'])['data']);

        $detail = $this->detail($materialId);
        $this->assertSame($response['data'][0], $detail);
    }

    public function test_category_projection_uses_current_name_and_retains_archived_categories(): void
    {
        $materialId = $this->createApprovedMaterial('Category current name');
        $this->rawMaterials->renameCategory($this->rawSteward, $this->context, $this->categoryId, 'Renamed current category');
        $this->rawMaterials->archiveAdministrationCategory($this->rawSteward, $this->context, $this->categoryId);

        $item = $this->listedItem($materialId);
        $this->assertSame('Renamed current category', $item['category']['name']);
        $this->assertSame($this->categoryId, $item['category']['id']);
        $this->assertArrayNotHasKey('archived_at', $item['category']);
        $this->assertArrayNotHasKey('archived_by', $item['category']);
    }

    public function test_mismatched_current_approval_hash_excludes_list_and_detail_uniformly(): void
    {
        $materialId = $this->createApprovedMaterial('Hash mismatch material');
        $revisionId = DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id');

        $this->withSetupConnection(function (Connection $connection) use ($revisionId): void {
            $connection->statement('ALTER TABLE raw_material_revisions DISABLE TRIGGER USER');
            try {
                $connection->table('raw_material_revisions')->where('id', $revisionId)
                    ->update(['content_hash' => str_repeat('f', 64)]);
            } finally {
                $connection->statement('ALTER TABLE raw_material_revisions ENABLE TRIGGER USER');
            }
        });

        $this->assertSame(0, $this->listing()['meta']['total']);
        try {
            $this->detail($materialId);
            $this->fail('A material with mismatched approval content hash was exposed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_foreign_organization_material_is_absent_from_results_count_and_detail(): void
    {
        $this->createApprovedMaterial('Own organization material');
        $this->withForeignOrganizationFixture(function (Location $foreignContext): void {
            $this->withSetupTransaction(function (Connection $connection) use ($foreignContext): void {
                $connection->statement('ALTER TABLE locations DISABLE TRIGGER locations_primary_central_kitchen_immutable');
                try {
                    $connection->table('locations')->where('id', $foreignContext->id)
                        ->update(['is_primary_central_kitchen' => true]);
                    $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
                } finally {
                    $connection->statement('ALTER TABLE locations ENABLE TRIGGER locations_primary_central_kitchen_immutable');
                }
            });
            $foreignOrganization = Organization::on('pgsql_setup')
                ->whereKey($foreignContext->organization_id)
                ->firstOrFail();
            $foreignAdministrator = $this->foreignUser($foreignOrganization, 'foreign-admin@example.test');
            $foreignSteward = $this->foreignUser($foreignOrganization, 'foreign-steward@example.test');
            $this->role($foreignAdministrator, BusinessRole::Administrator);
            foreach ([$foreignAdministrator, $foreignSteward] as $user) {
                LocationMembership::on('pgsql_setup')->create([
                    'organization_id' => $foreignOrganization->id,
                    'user_id' => $user->id,
                    'location_id' => $foreignContext->id,
                    'is_active' => true,
                    'assigned_at' => now(),
                ]);
            }
            LocationModuleState::on('pgsql_setup')->create([
                'organization_id' => $foreignOrganization->id,
                'location_id' => $foreignContext->id,
                'module_key' => 'RAW_MATERIALS',
                'enabled' => true,
                'created_by' => $foreignAdministrator->id,
                'updated_by' => $foreignAdministrator->id,
            ]);
            $foreignAdministrator = User::on('pgsql')->findOrFail($foreignAdministrator->id);
            $foreignSteward = User::on('pgsql')->findOrFail($foreignSteward->id);
            $seedSequence = (int) DB::table('organization_sku_assignments')
                ->where('prefix', 'RAW')
                ->max('sequence_number') + 1;
            $this->withSetupTransaction(function (Connection $connection) use ($foreignOrganization, $foreignAdministrator, $seedSequence): void {
                $connection->table('organization_sku_counters')->insert([
                    'organization_id' => $foreignOrganization->id,
                    'prefix' => 'RAW',
                    'last_value' => 0,
                ]);
                $connection->table('organization_sku_assignments')->insert([
                    'organization_id' => $foreignOrganization->id,
                    'sku' => SkuPrefix::Raw->format($seedSequence),
                    'prefix' => 'RAW',
                    'sequence_number' => $seedSequence,
                    'entity_type' => 'RAW',
                    'entity_id' => Str::uuid()->toString(),
                    'allocation_source' => 'IMPORTED',
                    'assigned_by' => $foreignAdministrator->id,
                    'created_at' => now(),
                ]);
                $connection->table('organization_sku_counters')
                    ->where('organization_id', $foreignOrganization->id)
                    ->where('prefix', 'RAW')
                    ->update(['last_value' => $seedSequence]);
            });
            $this->bootstrapRawPermissions($foreignAdministrator, $foreignSteward);
            $foreignCategory = $this->rawMaterials->createCategory($foreignSteward, $foreignContext, [
                'code' => 'FOREIGN_RAW_TEST',
                'name' => 'Foreign secret category',
            ]);
            $foreignMaterialId = $this->createApprovedMaterial(
                'Foreign secret material',
                'foreign-secret-description',
                $foreignSteward,
                $foreignContext,
                $foreignCategory['id'],
            );

            $result = $this->listing();
            $this->assertSame(1, $result['meta']['total']);
            $this->assertSame(['Own organization material'], array_column($result['data'], 'name'));
            $this->assertSame([], $this->listing(['search' => 'Foreign secret'])['data']);
            try {
                $this->detail($foreignMaterialId);
                $this->fail('A foreign organization material was exposed.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }, truncateAfterOperation: true);
    }

    private function assertConstraintViolation(callable $operation): void
    {
        try {
            $this->withSetupTransaction(function (Connection $connection) use ($operation): void {
                $operation($connection);
                $this->fail('Snapshot supplier data accepted an update or delete.');
            });
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $this->assertContains($sqlState, ['23514', '23503', '23505']);
        }
    }

    public function test_returned_proposal_survives_new_revision_without_supplier_input(): void
    {
        $materialId = $this->createApprovedMaterial('Approved A supplier baseline');
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $this->rawSteward,
            $this->context,
            [
                'supplier_code' => 'RETURN_PROPOSAL_B',
                'display_name' => 'Dostawca B',
                'tax_identifier' => '1234567893',
            ],
            'returned-proposal-b',
        );

        $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Proposal B before return',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
                'supplier_ids' => [$supplier['data']['id']],
            ],
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->submit(
            $this->rawSteward,
            $this->context,
            $materialId,
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->returnForChanges(
            $this->rawSteward,
            $this->context,
            $materialId,
            'Zwrot proposal B',
            $this->rawVersion($materialId),
        );

        $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Proposal B after return',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
            ],
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->submit(
            $this->rawSteward,
            $this->context,
            $materialId,
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->approve(
            $this->rawSteward,
            $this->context,
            $materialId,
            'Approval proposal B',
            $this->rawVersion($materialId),
        );

        $this->assertSame(['Dostawca B'], $this->detail($materialId)['supplier_names']);
    }

    public function test_explicit_empty_and_explicit_supplier_replace_inherited_proposal(): void
    {
        $materialId = $this->createApprovedMaterial('Inherited proposal material');
        $supplierA = app(RawMaterialCommercialService::class)->createSupplier(
            $this->rawSteward,
            $this->context,
            [
                'supplier_code' => 'INHERIT_A',
                'display_name' => 'Supplier A',
                'tax_identifier' => '1234567895',
            ],
            'inherit-supplier-a',
        );
        $supplierC = app(RawMaterialCommercialService::class)->createSupplier(
            $this->rawSteward,
            $this->context,
            [
                'supplier_code' => 'INHERIT_C',
                'display_name' => 'Supplier C',
                'tax_identifier' => '1234567896',
            ],
            'inherit-supplier-c',
        );

        $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Explicit replace revision',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
                'supplier_ids' => [$supplierA['data']['id']],
            ],
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->submit($this->rawSteward, $this->context, $materialId, $this->rawVersion($materialId));
        $this->rawMaterials->returnForChanges($this->rawSteward, $this->context, $materialId, 'Return inherited proposal', $this->rawVersion($materialId));
        $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Explicit replace revision C',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
                'supplier_ids' => [$supplierC['data']['id']],
            ],
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->submit($this->rawSteward, $this->context, $materialId, $this->rawVersion($materialId));
        $this->rawMaterials->approve($this->rawSteward, $this->context, $materialId, 'Approval replacement C', $this->rawVersion($materialId));

        $this->assertSame(['Supplier C'], $this->detail($materialId)['supplier_names']);
    }

    public function test_signed_proposal_mutation_after_submit_is_rejected(): void
    {
        $materialId = $this->createApprovedMaterial('Pending proposal mutation guard');
        $revision = $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Pending proposal mutation revision',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
            ],
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->submit(
            $this->rawSteward,
            $this->context,
            $materialId,
            $this->rawVersion($materialId),
        );

        $pendingRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('pending_revision_id');
        $proposal = DB::table('raw_material_revision_supplier_proposals')
            ->where('organization_id', $this->rawSteward->organization_id)
            ->where('revision_id', $pendingRevisionId)
            ->first();
        $this->assertNotNull($proposal);

        try {
            app(AuthorizationMutationContext::class)->run(
                AuthorizationMutationScope::rawMaterialMutate(),
                $this->rawSteward->id,
                fn () => DB::table('raw_material_revision_supplier_proposals')
                    ->where('id', $proposal->id)
                    ->update(['state' => 'PROPOSED_EMPTY', 'updated_at' => now()]),
            );
            $this->fail('Signed proposal mutation was accepted after submit.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) ($exception->errorInfo[0] ?? ''));
        }
    }

    public function test_proposal_mutation_after_submit_is_rejected_by_database_guard(): void
    {
        $materialId = $this->createApprovedMaterial('Pending proposal guard material');
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $this->rawSteward,
            $this->context,
            [
                'supplier_code' => 'PENDING_GUARD_3C',
                'display_name' => 'Proposal guard',
                'tax_identifier' => '1234567894',
            ],
            'pending-proposal-guard-supplier',
        );

        $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Pending proposal guard revision',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
                'supplier_ids' => [$supplier['data']['id']],
            ],
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->submit(
            $this->rawSteward,
            $this->context,
            $materialId,
            $this->rawVersion($materialId),
        );

        $revisionId = DB::table('raw_materials')->where('id', $materialId)->value('pending_revision_id');
        $this->assertConstraintViolation(
            fn (Connection $connection): int => $connection->table('raw_material_revision_supplier_proposals')
                ->where('organization_id', $this->rawSteward->organization_id)
                ->where('revision_id', $revisionId)
                ->update(['state' => 'PROPOSED_EMPTY', 'updated_at' => now()]),
        );
    }

    public function test_signed_proposal_mutation_after_approval_is_rejected(): void
    {
        $materialId = $this->createApprovedMaterial('Approved proposal mutation guard');
        $approvedRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id');

        try {
            app(AuthorizationMutationContext::class)->run(
                AuthorizationMutationScope::rawMaterialMutate(),
                $this->rawSteward->id,
                fn () => DB::table('raw_material_revision_supplier_proposals')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $this->rawSteward->organization_id,
                    'raw_material_id' => $materialId,
                    'revision_id' => $approvedRevisionId,
                    'state' => 'PROPOSED_EMPTY',
                    'created_by' => $this->rawSteward->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );
            $this->fail('Signed proposal mutation was accepted after approval.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) ($exception->errorInfo[0] ?? ''));
        }
    }

    public function test_signed_cross_revision_and_identity_updates_are_rejected(): void
    {
        $materialId = $this->createApprovedMaterial('Immutable proposal identity guard');
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $this->rawSteward,
            $this->context,
            [
                'supplier_code' => 'CROSS_REVISION_GUARD',
                'display_name' => 'Cross revision supplier',
                'tax_identifier' => '1234567895',
            ],
            'cross-revision-proposal-supplier',
        );
        $supplierId = (string) $supplier['data']['id'];

        $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Approved proposal source',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
                'supplier_ids' => [$supplierId],
            ],
            $this->rawVersion($materialId),
        );
        $this->rawMaterials->submit(
            $this->rawSteward,
            $this->context,
            $materialId,
            $this->rawVersion($materialId),
        );
        $sourceRevisionId = (string) DB::table('raw_materials')
            ->where('id', $materialId)
            ->value('pending_revision_id');
        $this->rawMaterials->approve(
            $this->rawSteward,
            $this->context,
            $materialId,
            'Approve immutable proposal source',
            $this->rawVersion($materialId),
        );

        $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Working proposal destination',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
            ],
            $this->rawVersion($materialId),
        );
        $destinationRevisionId = (string) DB::table('raw_materials')
            ->where('id', $materialId)
            ->value('working_revision_id');

        $sourceProposal = DB::table('raw_material_revision_supplier_proposals')
            ->where('organization_id', $this->rawSteward->organization_id)
            ->where('revision_id', $sourceRevisionId)
            ->first();
        $sourceItem = DB::table('raw_material_revision_supplier_proposal_items')
            ->where('organization_id', $this->rawSteward->organization_id)
            ->where('revision_id', $sourceRevisionId)
            ->first();
        $this->assertNotNull($sourceProposal);
        $this->assertNotNull($sourceItem);

        $proposalCount = DB::table('raw_material_revision_supplier_proposals')->count();
        $itemCount = DB::table('raw_material_revision_supplier_proposal_items')->count();

        foreach ([
            ['revision_id' => $destinationRevisionId],
            ['organization_id' => (string) Str::uuid()],
            ['raw_material_id' => (string) Str::uuid()],
            ['created_by' => (string) Str::uuid()],
        ] as $identityChange) {
            try {
                app(AuthorizationMutationContext::class)->run(
                    AuthorizationMutationScope::rawMaterialMutate(),
                    $this->rawSteward->id,
                    fn () => DB::table('raw_material_revision_supplier_proposals')
                        ->where('id', $sourceProposal->id)
                        ->update($identityChange),
                );
                $this->fail('Cross-revision or ownership proposal UPDATE was accepted.');
            } catch (QueryException $exception) {
                $sqlState = (string) ($exception->errorInfo[0] ?? '');
                $this->assertContains($sqlState, ['23514', '23503', '23505']);
            }
        }

        foreach ([
            ['revision_id' => $destinationRevisionId],
            ['organization_id' => (string) Str::uuid()],
            ['supplier_id' => (string) Str::uuid()],
        ] as $identityChange) {
            try {
                app(AuthorizationMutationContext::class)->run(
                    AuthorizationMutationScope::rawMaterialMutate(),
                    $this->rawSteward->id,
                    fn () => DB::table('raw_material_revision_supplier_proposal_items')
                        ->where('id', $sourceItem->id)
                        ->update($identityChange),
                );
                $this->fail('Cross-revision, ownership or supplier item UPDATE was accepted.');
            } catch (QueryException $exception) {
                $sqlState = (string) ($exception->errorInfo[0] ?? '');
                $this->assertContains($sqlState, ['23514', '23503', '23505']);
            }
        }

        $this->assertSame(
            $proposalCount,
            DB::table('raw_material_revision_supplier_proposals')->count(),
        );
        $this->assertSame(
            $itemCount,
            DB::table('raw_material_revision_supplier_proposal_items')->count(),
        );
    }

    public function test_editable_state_update_and_delete_insert_item_replacement_succeed(): void
    {
        $materialId = $this->createApprovedMaterial('Editable proposal guard');
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $this->rawSteward,
            $this->context,
            [
                'supplier_code' => 'EDITABLE_PROPOSAL_GUARD',
                'display_name' => 'Editable proposal supplier',
                'tax_identifier' => '1234567896',
            ],
            'editable-proposal-supplier',
        );
        $supplierId = (string) $supplier['data']['id'];

        $this->rawMaterials->revise(
            $this->rawSteward,
            $this->context,
            $materialId,
            [
                'category_id' => $this->categoryId,
                'name' => 'Editable proposal revision',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
                'supplier_ids' => [$supplierId],
            ],
            $this->rawVersion($materialId),
        );
        $revisionId = (string) DB::table('raw_materials')
            ->where('id', $materialId)
            ->value('working_revision_id');
        $proposal = DB::table('raw_material_revision_supplier_proposals')
            ->where('organization_id', $this->rawSteward->organization_id)
            ->where('revision_id', $revisionId)
            ->first();
        $this->assertNotNull($proposal);

        app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::rawMaterialMutate(),
            $this->rawSteward->id,
            function () use ($proposal): void {
                DB::table('raw_material_revision_supplier_proposals')
                    ->where('id', $proposal->id)
                    ->update(['state' => 'PROPOSED_NONEMPTY', 'updated_at' => now()]);
            },
        );

        $item = DB::table('raw_material_revision_supplier_proposal_items')
            ->where('organization_id', $this->rawSteward->organization_id)
            ->where('revision_id', $revisionId)
            ->first();
        $this->assertNotNull($item);

        app(AuthorizationMutationContext::class)->run(
            AuthorizationMutationScope::rawMaterialMutate(),
            $this->rawSteward->id,
            function () use ($item, $proposal, $revisionId): void {
                DB::table('raw_material_revision_supplier_proposal_items')
                    ->where('id', $item->id)
                    ->delete();
                DB::table('raw_material_revision_supplier_proposal_items')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $proposal->organization_id,
                    'revision_id' => $revisionId,
                    'supplier_id' => $item->supplier_id,
                ]);
            },
        );

        $this->assertSame(
            1,
            DB::table('raw_material_revision_supplier_proposal_items')
                ->where('organization_id', $this->rawSteward->organization_id)
                ->where('revision_id', $revisionId)
                ->count(),
        );
    }

    public function test_legacy_supplier_association_is_not_projected_to_boss(): void
    {
        $materialId = $this->createApprovedMaterial('Legacy supplier material');
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $this->rawSteward,
            $this->context,
            [
                'supplier_code' => 'LEGACY_BOSS_3C',
                'display_name' => 'Legacy dostawca',
                'tax_identifier' => '1234567891',
            ],
            'legacy-boss-3c-supplier',
        );
        $this->withSetupTransaction(function (Connection $connection) use ($supplier, $materialId): void {
            $connection->table('raw_material_supplier_associations')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->rawSteward->organization_id,
                'raw_material_id' => $materialId,
                'supplier_id' => $supplier['data']['id'],
                'created_by' => $this->rawSteward->id,
                'created_at' => now(),
            ]);
        });

        $this->assertNull($this->listedItem($materialId)['supplier_names']);
        $this->assertNull($this->detail($materialId)['supplier_names']);
    }

    public function test_approved_supplier_snapshot_is_revision_bound_and_uses_live_name(): void
    {
        $materialId = $this->createApprovedMaterial('Snapshot supplier material');
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $this->rawSteward,
            $this->context,
            [
                'supplier_code' => 'APPROVED_BOSS_3C',
                'display_name' => 'Dostawca przed rename',
                'tax_identifier' => '1234567892',
            ],
            'approved-boss-3c-supplier',
        );
        $supplierId = $supplier['data']['id'];

        $this->rawMaterials->revise($this->rawSteward, $this->context, $materialId, [
            'category_id' => $this->categoryId,
            'name' => 'Snapshot supplier material revised',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            'supplier_ids' => [$supplierId],
        ], $this->rawVersion($materialId));
        $this->rawMaterials->submit($this->rawSteward, $this->context, $materialId, $this->rawVersion($materialId));
        $this->rawMaterials->approve(
            $this->rawSteward,
            $this->context,
            $materialId,
            'Zatwierdzenie snapshotu dostawcy',
            $this->rawVersion($materialId),
        );

        $revisionId = DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id');
        $snapshot = DB::table('raw_material_approved_supplier_snapshots')
            ->where('organization_id', $this->rawSteward->organization_id)
            ->where('revision_id', $revisionId)
            ->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('APPROVED_NONEMPTY', $snapshot->state);
        $this->assertSame(
            $supplierId,
            (string) DB::table('raw_material_approved_supplier_snapshot_items')
                ->where('snapshot_id', $snapshot->id)
                ->value('supplier_id'),
        );
        $this->assertSame(['Dostawca przed rename'], $this->listedItem($materialId)['supplier_names']);
        $this->assertSame(['Dostawca przed rename'], $this->detail($materialId)['supplier_names']);
        $this->assertConstraintViolation(
            fn (Connection $connection): int => $connection->table('raw_material_approved_supplier_snapshots')
                ->where('id', $snapshot->id)
                ->update(['state' => 'APPROVED_EMPTY']),
        );
        $this->assertConstraintViolation(
            fn (Connection $connection): int => $connection->table('raw_material_approved_supplier_snapshot_items')
                ->where('snapshot_id', $snapshot->id)
                ->delete(),
        );

        app(AuthorizationMutationContext::class)->runForSubject(
            AuthorizationMutationScope::rawMaterialSupplierCorrection(),
            $this->rawSteward->id,
            $supplierId,
            fn () => DB::table('organization_suppliers')
                ->where('organization_id', $this->rawSteward->organization_id)
                ->where('id', $supplierId)
                ->update(['display_name' => 'Dostawca po rename', 'updated_at' => now()]),
        );
        $this->assertSame(['Dostawca po rename'], $this->detail($materialId)['supplier_names']);
    }

    public function test_explicit_empty_supplier_snapshot_returns_empty_array(): void
    {
        $material = $this->rawMaterials->create($this->rawSteward, $this->context, [
            'category_id' => $this->categoryId,
            'name' => 'Explicit empty supplier material',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            'supplier_ids' => [],
            'supplier_association_empty_confirmed' => true,
        ]);
        $this->rawMaterials->reviseAllergenDeclaration(
            $this->rawSteward,
            $this->context,
            $material['id'],
            [
                'status' => 'NO_KNOWN_ALLERGENS',
                'catalog_version_id' => $this->allergenCatalogId,
                'items' => [],
            ],
            $this->rawVersion($material['id']),
        );
        $this->rawMaterials->submit($this->rawSteward, $this->context, $material['id'], $this->rawVersion($material['id']));
        $this->rawMaterials->approve(
            $this->rawSteward,
            $this->context,
            $material['id'],
            'Zatwierdzenie pustego snapshotu',
            $this->rawVersion($material['id']),
        );

        $this->assertSame([], $this->listedItem($material['id'])['supplier_names']);
        $this->assertSame([], $this->detail($material['id'])['supplier_names']);
    }

    public function test_empty_supplier_array_requires_explicit_confirmation(): void
    {
        $this->expectException(ValidationException::class);
        $this->rawMaterials->create($this->rawSteward, $this->context, [
            'category_id' => $this->categoryId,
            'name' => 'Unconfirmed empty supplier material',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            'supplier_ids' => [],
        ]);
    }

    private function createApprovedMaterial(
        string $name,
        ?string $description = null,
        ?User $actor = null,
        ?Location $context = null,
        ?string $categoryId = null,
    ): string {
        $actor ??= $this->rawSteward;
        $context ??= $this->context;
        $categoryId ??= $this->categoryId;
        $material = $this->rawMaterials->create($actor, $context, [
            'category_id' => $categoryId,
            'name' => $name,
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            'description' => $description,
        ]);
        $this->rawMaterials->reviseAllergenDeclaration($actor, $context, $material['id'], [
            'status' => 'NO_KNOWN_ALLERGENS',
            'catalog_version_id' => $this->allergenCatalogId,
            'items' => [],
        ], $this->rawVersion($material['id']));
        $this->rawMaterials->submit($actor, $context, $material['id'], $this->rawVersion($material['id']));
        $this->rawMaterials->approve(
            $actor,
            $context,
            $material['id'],
            'Approved test fixture',
            $this->rawVersion($material['id']),
        );

        return $material['id'];
    }

    private function foreignUser(Organization $organization, string $email): User
    {
        $user = User::on('pgsql_setup')->create([
            'organization_id' => $organization->id,
            'first_name' => 'Foreign',
            'last_name' => 'Fixture',
            'email' => $email,
            'password' => self::PASSWORD,
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return User::on('pgsql')->findOrFail($user->id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    private function listing(array $filters = []): array
    {
        return app(BossRawMaterialCatalogService::class)->listing(
            $this->boss,
            $this->context,
            $this->membershipId($this->boss),
            $this->boss->auth_version,
            $filters,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(string $materialId): array
    {
        return app(BossRawMaterialCatalogService::class)->detail(
            $this->boss,
            $this->context,
            $this->membershipId($this->boss),
            $this->boss->auth_version,
            $materialId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function listedItem(string $materialId): array
    {
        $item = collect($this->listing()['data'])->firstWhere('id', $materialId);
        $this->assertIsArray($item);

        return $item;
    }

    private function membershipId(User $user): string
    {
        return (string) LocationMembership::query()
            ->where('user_id', $user->id)
            ->where('location_id', $this->context->id)
            ->where('is_active', true)
            ->value('id');
    }

    private function bootstrapRawPermissions(User $administrator, User $recipient): void
    {
        $permissions = array_map(
            static fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        $this->withSetupTransaction(function (Connection $connection) use (
            $administrator,
            $recipient,
            $permissions,
            $operationId,
        ): void {
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
            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $administrator->organization_id,
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

    private function publishedAllergenCatalog(): string
    {
        $catalogId = Str::uuid()->toString();
        $versionNumber = (int) DB::connection('pgsql_setup')
            ->table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
        $this->withSetupTransaction(function (Connection $connection) use ($catalogId, $versionNumber): void {
            $connection->table('raw_material_allergen_catalog_versions')->insert([
                'id' => $catalogId,
                'version_number' => $versionNumber,
                'status' => 'DRAFT',
            ]);
            $connection->table('raw_material_allergen_definitions')->insert([
                'catalog_version_id' => $catalogId,
                'allergen_code' => 'TEST_WHEAT',
                'name_pl' => 'Pszenica',
                'display_order' => 1,
            ]);
            $connection->table('raw_material_allergen_catalog_versions')->where('id', $catalogId)
                ->update(['status' => 'PUBLISHED', 'published_at' => now()]);
        });

        return $catalogId;
    }
}
