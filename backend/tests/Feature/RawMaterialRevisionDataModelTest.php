<?php

namespace Tests\Feature;

use App\Domain\Allergens\InstallationPrecautionaryAllergenService;
use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Database\Seeders\AllergenDictionariesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialRevisionDataModelTest extends AuthorizationTestCase
{
    private Location $location;

    private User $administrator;

    private User $steward;

    private RawMaterialService $rawMaterials;

    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->location = $this->primaryCentralKitchen();
        $this->administrator = $this->user('revision-data-admin@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->location, $this->administrator->id);
        $this->steward = $this->user('revision-data-steward@example.test');
        app(MembershipService::class)->assign($this->steward, $this->location);
        $this->updateFixture($this->steward->organization, ['authorization_initialized' => true]);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $this->location->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->administrator->id,
            'updated_by' => $this->administrator->id,
        ]);
        $this->bootstrapFixtureGrants();
        $this->rawMaterials = app(RawMaterialService::class);
        $category = $this->rawMaterials->createCategory($this->steward, $this->location, [
            'code' => 'TEST_DATA',
            'name' => 'Dane testowe',
        ]);
        $this->categoryId = $category['id'];
    }

    public function test_installation_precautionary_updates_leave_raw_declarations_and_history_unchanged(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
            ['allergen_code' => 'TEST_MILK', 'name_pl' => 'Mleko'],
        ]);
        $material = $this->createMaterial('Surowiec z deklaracjami', [
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [
                    ['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT'],
                    ['allergen_code' => 'TEST_MILK', 'exposure_kind' => 'CROSS_CONTACT_POTENTIAL'],
                ],
            ],
        ]);
        $revisionId = $material['revision']['id'];
        $declarationsBefore = DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $revisionId)
            ->orderBy('allergen_code')
            ->get(['catalog_version_id', 'allergen_code', 'exposure_kind'])
            ->toArray();
        $revisionBefore = DB::table('raw_material_revisions')
            ->where('id', $revisionId)
            ->first(['allergen_state', 'content_hash', 'revision_no']);
        $historyBefore = DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL')
            ->where('subject_id', $material['id'])
            ->orderBy('occurred_at')
            ->get(['id', 'event_code', 'before_hash', 'after_hash', 'metadata'])
            ->toArray();

        $this->withSetupConnection(function (): void {
            DB::setDefaultConnection('pgsql_setup');
            try {
                app(AllergenDictionariesSeeder::class)->run();
            } finally {
                DB::setDefaultConnection('pgsql');
            }
        });
        $globalList = app(InstallationPrecautionaryAllergenService::class);
        $globalList->add($this->administrator, $this->location, 'ALG_SOY');
        $globalList->remove($this->administrator, $this->location, 'ALG_SOY');

        $this->assertEquals($declarationsBefore, DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $revisionId)
            ->orderBy('allergen_code')
            ->get(['catalog_version_id', 'allergen_code', 'exposure_kind'])
            ->toArray());
        $this->assertEquals($revisionBefore, DB::table('raw_material_revisions')
            ->where('id', $revisionId)
            ->first(['allergen_state', 'content_hash', 'revision_no']));
        $this->assertEquals($historyBefore, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL')
            ->where('subject_id', $material['id'])
            ->orderBy('occurred_at')
            ->get(['id', 'event_code', 'before_hash', 'after_hash', 'metadata'])
            ->toArray());
    }

    public function test_unknown_and_no_known_allergen_states_are_distinct_in_the_revision_snapshot(): void
    {
        $unknown = $this->createMaterial('Nieznany stan');
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
        ]);
        $noKnown = $this->createMaterial('Brak znanych alergenów', [
            'allergen_declaration' => [
                'status' => 'NO_KNOWN_ALLERGENS',
                'catalog_version_id' => $catalogVersionId,
                'items' => [],
            ],
        ]);

        $unknownRevision = DB::table('raw_material_revisions')->where('id', $unknown['revision']['id'])->first();
        $noKnownRevision = DB::table('raw_material_revisions')->where('id', $noKnown['revision']['id'])->first();
        $this->assertSame('UNKNOWN', $unknownRevision->allergen_state);
        $this->assertNull(DB::table('raw_material_revision_allergen_attestations')
            ->where('revision_id', $unknownRevision->id)->first());
        $this->assertSame('DECLARED', $noKnownRevision->allergen_state);
        $this->assertSame('NO_KNOWN_ALLERGENS', DB::table('raw_material_revision_allergen_attestations')
            ->where('revision_id', $noKnownRevision->id)->value('attestation_status'));
        $this->assertSame(0, DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $noKnownRevision->id)->count());
        $this->assertNotSame($unknownRevision->content_hash, $noKnownRevision->content_hash);
        $this->assertRevisionHashMatches($unknownRevision);
        $this->assertRevisionHashMatches($noKnownRevision);
    }

    public function test_version_three_hash_covers_declarations_storage_and_is_preserved_when_revising(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
            ['allergen_code' => 'TEST_MILK', 'name_pl' => 'Mleko'],
        ]);
        $material = $this->createMaterial('Mieszanka testowa', [
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [
                    ['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT'],
                    ['allergen_code' => 'TEST_MILK', 'exposure_kind' => 'CROSS_CONTACT_POTENTIAL'],
                ],
            ],
            'storage_variants' => [$this->storageVariant()],
        ]);
        $revisionId = $material['revision']['id'];
        $revision = DB::table('raw_material_revisions')->where('id', $revisionId)->first();

        $this->assertSame(3, (int) $revision->content_hash_version);
        $this->assertSame(2, DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $revisionId)->count());
        $this->assertSame(1, DB::table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisionId)->count());
        $this->assertRevisionHashMatches($revision);

        $revised = $this->rawMaterials->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $this->categoryId,
            'name' => 'Mieszanka testowa poprawiona',
            'base_unit_code' => 'g',
        ], expectedVersion: $this->rawVersion($material['id']));
        $revisedId = $revised['revision']['id'];
        $revisedRevision = DB::table('raw_material_revisions')->where('id', $revisedId)->first();
        $this->assertSame(2, DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $revisedId)->count());
        $this->assertSame(1, DB::table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisedId)->count());
        $this->assertRevisionHashMatches($revisedRevision);
    }

    public function test_package_and_nominal_unit_prices_normalize_to_equivalent_usable_costs(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $catalogVersionId = $this->publishedCatalog([]);
        $materials = [];
        foreach ([
            ['Cena opakowania', ['basis' => 'PACKAGE', 'amount' => '28']],
            ['Cena za kilogram', ['basis' => 'NOMINAL_UNIT', 'amount' => '10', 'unit_code' => 'kg']],
        ] as [$name, $pricing]) {
            $material = $this->createMaterial($name, [
                'nominal_quantity' => '2800',
                'usable_quantity' => '1500',
                'conversions' => [['unit_code' => 'kg']],
                'pricing' => $pricing,
                'allergen_declaration' => [
                    'status' => 'NO_KNOWN_ALLERGENS',
                    'catalog_version_id' => $catalogVersionId,
                    'items' => [],
                ],
            ]);
            $revision = DB::table('raw_material_revisions')->where('id', $material['revision']['id'])->first();
            $this->assertSame(3, (int) $revision->content_hash_version);
            $this->assertRevisionHashMatches($revision);

            $this->rawMaterials->submit(
                $this->steward,
                $this->location,
                $material['id'],
                expectedVersion: $this->rawVersion($material['id']),
            );
            $this->rawMaterials->approve(
                $this->steward,
                $this->location,
                $material['id'],
                'Zatwierdzenie ceny testowej',
                expectedVersion: $this->rawVersion($material['id']),
            );
            $materials[] = DB::table('raw_material_reference_price_events')
                ->where('raw_material_id', $material['id'])->first();
        }

        $this->assertSame('28.00000000000000', (string) $materials[0]->net_pln_per_package);
        $this->assertSame($materials[0]->net_pln_per_package, $materials[1]->net_pln_per_package);
        $this->assertSame('PACKAGE', $materials[0]->pricing_input_basis);
        $this->assertSame('NOMINAL_UNIT', $materials[1]->pricing_input_basis);
        foreach ($materials as $event) {
            $this->assertSame('2800.000000', (string) $event->pricing_nominal_quantity);
            $this->assertSame('1500.000000', (string) $event->pricing_usable_quantity);
            $this->assertSame('1.86666666666667', (string) DB::scalar(
                'SELECT raw_materials_round_half_even_v1(CAST(? AS numeric) / CAST(? AS numeric) * 100, 14)::text',
                [$event->net_pln_per_package, $event->pricing_usable_quantity],
            ));
        }
    }

    public function test_archived_custom_unit_keeps_raw_revision_reference_and_locks_its_definition(): void
    {
        $unit = $this->rawMaterials->createAdministrationUnit($this->administrator, $this->location, [
            'code' => 'mg_history',
            'name' => 'Miligram historyczny',
            'symbol' => 'mgh',
            'dimension' => 'MASS',
            'base_unit_code' => 'g',
            'to_base_factor' => '0.000001',
        ]);
        $material = $this->createMaterial('Materiał z niestandardową jednostką', [
            'conversions' => [['unit_code' => 'mg_history']],
        ]);
        $revisionUnit = DB::table('raw_material_revision_units')
            ->where('revision_id', $material['revision']['id'])
            ->where('unit_code', 'mg_history')
            ->first();

        $this->assertNotNull($revisionUnit);
        $this->assertSame('METRIC', $revisionUnit->conversion_kind);
        $this->assertSame('0.000001000000', (string) $revisionUnit->to_base_factor);

        try {
            $this->rawMaterials->updateAdministrationUnit($this->administrator, $this->location, $unit['id'], [
                'name' => 'Miligram historyczny',
                'symbol' => 'mgh',
                'dimension' => 'MASS',
                'base_unit_code' => 'g',
                'to_base_factor' => '0.000002',
            ]);
            $this->fail('A referenced unit definition must remain immutable.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_UNIT_DEFINITION_IN_USE', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $this->rawMaterials->archiveAdministrationUnit($this->administrator, $this->location, $unit['id']);
        $this->assertSame(1, DB::table('raw_material_revision_units')
            ->where('revision_id', $material['revision']['id'])
            ->where('unit_code', 'mg_history')->count());

        try {
            $this->createMaterial('Materiał z archiwalną jednostką', [
                'conversions' => [['unit_code' => 'mg_history']],
            ]);
            $this->fail('An archived unit must not be assigned to a new RAW revision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conversions.0.unit_code', $exception->errors());
        }

        $this->rawMaterials->restoreAdministrationUnit($this->administrator, $this->location, $unit['id']);
        $this->assertSame('0.000001000000', (string) DB::table('raw_material_units')
            ->where('id', $unit['id'])->value('to_base_factor'));
    }

    public function test_omitted_usable_quantity_defaults_to_nominal_and_does_not_keep_stale_yield(): void
    {
        $material = $this->createMaterial('Wydajność domyślna', ['nominal_quantity' => '2800']);
        $revision = DB::table('raw_material_revisions')->where('id', $material['revision']['id'])->first();
        $this->assertFalse((bool) $revision->usable_quantity_explicit);
        $this->assertSame('2800.000000', (string) $revision->usable_quantity);

        $revised = $this->rawMaterials->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $this->categoryId,
            'name' => 'Wydajność nominalna zmieniona',
            'base_unit_code' => 'g',
            'nominal_quantity' => '3000',
        ], expectedVersion: $this->rawVersion($material['id']));
        $defaultedRevision = DB::table('raw_material_revisions')->where('id', $revised['revision']['id'])->first();
        $this->assertFalse((bool) $defaultedRevision->usable_quantity_explicit);
        $this->assertSame('3000.000000', (string) $defaultedRevision->usable_quantity);
        $this->assertRevisionHashMatches($defaultedRevision);

        $explicit = $this->rawMaterials->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $this->categoryId,
            'name' => 'Wydajność jawna',
            'base_unit_code' => 'g',
            'nominal_quantity' => '3000',
            'usable_quantity' => '1500',
        ], expectedVersion: $this->rawVersion($material['id']));
        $explicitRevision = DB::table('raw_material_revisions')->where('id', $explicit['revision']['id'])->first();
        $this->assertTrue((bool) $explicitRevision->usable_quantity_explicit);
        $this->assertSame('1500.000000', (string) $explicitRevision->usable_quantity);
        $this->assertRevisionHashMatches($explicitRevision);
    }

    public function test_approved_quantity_changes_recalculate_both_price_modes_and_supplier_links_do_not_change_price(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $catalogVersionId = $this->publishedCatalog([]);
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $this->steward,
            $this->location,
            ['supplier_code' => 'TEST_SUPPLIER', 'display_name' => 'Dostawca testowy'],
            'test-supplier-0001',
        )['data'];

        foreach ([
            [['basis' => 'PACKAGE', 'amount' => '28'], '28.00000000000000', '28.00000000000000'],
            [['basis' => 'NOMINAL_UNIT', 'amount' => '10', 'unit_code' => 'kg'], '20.00000000000000', '30.00000000000000'],
        ] as [$pricing, $initialPrice, $revisedPrice]) {
            $material = $this->createMaterial('Cena '.$pricing['basis'], [
                'nominal_quantity' => '2000',
                'conversions' => [['unit_code' => 'kg']],
                'pricing' => $pricing,
                'allergen_declaration' => [
                    'status' => 'NO_KNOWN_ALLERGENS',
                    'catalog_version_id' => $catalogVersionId,
                    'items' => [],
                ],
            ]);
            $id = $material['id'];
            $this->rawMaterials->submit($this->steward, $this->location, $id, expectedVersion: $this->rawVersion($id));
            $this->rawMaterials->approve($this->steward, $this->location, $id, 'Cena początkowa', expectedVersion: $this->rawVersion($id));

            $this->rawMaterials->revise($this->steward, $this->location, $id, [
                'category_id' => $this->categoryId,
                'name' => 'Nowa ilość '.$pricing['basis'],
                'base_unit_code' => 'g',
                'nominal_quantity' => '3000',
                'conversions' => [['unit_code' => 'kg']],
            ], expectedVersion: $this->rawVersion($id));
            $this->assertSame(1, DB::table('raw_material_reference_price_events')->where('raw_material_id', $id)->count());
            $this->rawMaterials->submit($this->steward, $this->location, $id, expectedVersion: $this->rawVersion($id));
            $this->rawMaterials->approve($this->steward, $this->location, $id, 'Zmiana ilości', expectedVersion: $this->rawVersion($id));

            $events = DB::table('raw_material_reference_price_events')->where('raw_material_id', $id)->orderBy('event_no')->get();
            $this->assertCount(2, $events);
            $this->assertSame($initialPrice, (string) $events[0]->net_pln_per_package);
            $this->assertSame('2000.000000', (string) $events[0]->pricing_nominal_quantity);
            $this->assertSame($revisedPrice, (string) $events[1]->net_pln_per_package);
            $this->assertSame('3000.000000', (string) $events[1]->pricing_nominal_quantity);
            $this->assertSame('3000.000000', (string) $events[1]->pricing_usable_quantity);

            $this->rawMaterials->revise($this->steward, $this->location, $id, [
                'category_id' => $this->categoryId,
                'name' => 'Nowa ilość '.$pricing['basis'],
                'base_unit_code' => 'g',
                'nominal_quantity' => '3000',
                'conversions' => [['unit_code' => 'kg']],
                'supplier_ids' => [$supplier['id']],
            ], expectedVersion: $this->rawVersion($id));
            $this->assertSame(1, DB::table('raw_material_supplier_associations')->where('raw_material_id', $id)->count());
            $this->rawMaterials->submit($this->steward, $this->location, $id, expectedVersion: $this->rawVersion($id));
            $this->rawMaterials->approve($this->steward, $this->location, $id, 'Przypisanie dostawcy', expectedVersion: $this->rawVersion($id));
            $this->assertSame(2, DB::table('raw_material_reference_price_events')->where('raw_material_id', $id)->count());
        }
    }

    public function test_database_rejects_duplicate_and_invalid_declarations_and_storage_bounds(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
        ]);
        $material = $this->createMaterial('Materiał z alergenem', [
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
            ],
        ]);
        $revisionId = $material['revision']['id'];

        $this->assertDatabaseRejects('23505', fn (Connection $connection) => $connection->table('raw_material_revision_allergen_declarations')->insert([
            'revision_id' => $revisionId,
            'organization_id' => $this->steward->organization_id,
            'catalog_version_id' => $catalogVersionId,
            'allergen_code' => 'TEST_WHEAT',
            'exposure_kind' => 'CROSS_CONTACT_POTENTIAL',
        ]));

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_allergen_declarations')->insert([
            'revision_id' => $revisionId,
            'organization_id' => $this->steward->organization_id,
            'catalog_version_id' => $catalogVersionId,
            'allergen_code' => 'TEST_WHEAT',
            'exposure_kind' => 'UNKNOWN',
        ]));

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$this->storageVariant('INVALID_TEMP', temperatureMin: '8.00', temperatureMax: '-1.00'),
        ]));

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$this->storageVariant('INVALID_HUMIDITY', humidityMin: '75.00', humidityMax: '40.00'),
        ]));
    }

    public function test_database_rejects_multiple_defaults_and_cross_organization_references(): void
    {
        $material = $this->createMaterial('Przechowywanie testowe', [
            'storage_variants' => [$this->storageVariant()],
        ]);
        $revisionId = $material['revision']['id'];

        $this->assertDatabaseRejects('23505', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$this->storageVariant('SECOND_DEFAULT'),
        ]));

        $this->withForeignOrganizationFixture(function (Location $foreignLocation) use ($revisionId): void {
            $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $foreignLocation->organization_id,
                'revision_id' => $revisionId,
                ...$this->storageVariant('CROSS_ORG', isDefault: false),
            ]));
        });
    }

    public function test_approved_revision_data_cannot_be_modified_or_extended(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
        ]);
        $material = $this->createMaterial('Zatwierdzany materiał', [
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
            ],
            'storage_variants' => [$this->storageVariant()],
        ]);
        $revisionId = $material['revision']['id'];
        $this->rawMaterials->submit($this->steward, $this->location, $material['id'], expectedVersion: $this->rawVersion($material['id']));
        $this->rawMaterials->approve($this->steward, $this->location, $material['id'], 'Zatwierdzenie testowe', expectedVersion: $this->rawVersion($material['id']));

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisionId)->update(['name_pl' => 'Zmiana po zatwierdzeniu']));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisionId)->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$this->storageVariant('AFTER_APPROVAL', isDefault: false),
        ]));
    }

    public function test_v1_declared_revision_cannot_be_approved_as_v2_without_a_new_exact_revision_attestation(): void
    {
        $legacy = $this->createLegacyDeclaredV1Revision();
        $legacyRevision = DB::table('raw_material_revisions')->where('id', $legacy['revision_id'])->first();
        $this->assertSame(1, (int) $legacyRevision->content_hash_version);
        $this->assertSame($legacy['hash'], $this->calculateRevisionHash($legacyRevision));

        $v2 = $this->rawMaterials->revise($this->steward, $this->location, $legacy['material_id'], [
            'category_id' => $this->categoryId,
            'name' => 'Historyczny materiał po rewizji',
            'base_unit_code' => 'g',
        ], expectedVersion: $this->rawVersion($legacy['material_id']));
        $v2RevisionId = $v2['revision']['id'];
        $v2Revision = DB::table('raw_material_revisions')->where('id', $v2RevisionId)->first();
        $this->assertSame(3, (int) $v2Revision->content_hash_version);
        $this->assertSame('DECLARED', $v2Revision->allergen_state);
        $this->assertSame(0, DB::table('raw_material_revision_allergen_attestations')
            ->where('revision_id', $v2RevisionId)->count());
        $this->assertRevisionHashMatches($v2Revision);

        $this->rawMaterials->submit($this->steward, $this->location, $legacy['material_id'], expectedVersion: $this->rawVersion($legacy['material_id']));
        try {
            $this->rawMaterials->approve(
                $this->steward,
                $this->location,
                $legacy['material_id'],
                'Próba zatwierdzenia bez nowego atestu',
                expectedVersion: $this->rawVersion($legacy['material_id']));
            $this->fail('A v2 revision inherited DECLARED state without an exact-revision attestation.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED', $exception->errorCode);
        }

        $this->rawMaterials->returnForChanges(
            $this->steward,
            $this->location,
            $legacy['material_id'],
            'Wymagany nowy atest',
            expectedVersion: $this->rawVersion($legacy['material_id']));
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
        ]);
        $attested = $this->rawMaterials->reviseAllergenDeclaration(
            $this->steward,
            $this->location,
            $legacy['material_id'],
            [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
            ],
            expectedVersion: $this->rawVersion($legacy['material_id']));
        $attestedRevisionId = $attested['revision']['id'];
        $attestedRevision = DB::table('raw_material_revisions')->where('id', $attestedRevisionId)->first();
        $this->assertRevisionHashMatches($attestedRevision);
        $this->rawMaterials->submit($this->steward, $this->location, $legacy['material_id'], expectedVersion: $this->rawVersion($legacy['material_id']));
        $approved = $this->rawMaterials->approve(
            $this->steward,
            $this->location,
            $legacy['material_id'],
            'Zatwierdzenie po jawnym atestowaniu',
            expectedVersion: $this->rawVersion($legacy['material_id']));

        $this->assertSame($attestedRevisionId, $approved['approved_revision_id']);
        $this->assertSame($legacy['hash'], DB::table('raw_material_revisions')
            ->where('id', $legacy['revision_id'])->value('content_hash'));
    }

    public function test_runtime_inserts_require_scoped_signed_mutation_context_for_stage2d_tables(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
            ['allergen_code' => 'TEST_MILK', 'name_pl' => 'Mleko'],
        ]);
        $material = $this->createMaterial('Materiał z podpisanymi mutacjami', [
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
            ],
            'storage_variants' => [$this->storageVariant()],
        ]);
        $revisionId = $material['revision']['id'];
        $attestation = DB::table('raw_material_revision_allergen_attestations')
            ->where('revision_id', $revisionId)->first();

        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection
            ->table('raw_material_revision_allergen_attestations')->insert([
                'revision_id' => $revisionId,
                'organization_id' => $this->steward->organization_id,
                'catalog_version_id' => $attestation->catalog_version_id,
                'attestation_status' => $attestation->attestation_status,
                'attested_by' => $attestation->attested_by,
                'attested_at' => $attestation->attested_at,
            ]));

        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection
            ->table('raw_material_revision_allergen_declarations')->insert([
                'revision_id' => $revisionId,
                'organization_id' => $this->steward->organization_id,
                'catalog_version_id' => $catalogVersionId,
                'allergen_code' => 'TEST_MILK',
                'exposure_kind' => 'DIRECT',
            ]));

        $this->assertRuntimeDatabaseRejects('42501', fn () => app(AuthorizationMutationContext::class)
            ->runForSubject(
                AuthorizationMutationScope::rawMaterialAllergenManage(),
                $this->steward->id,
                Str::uuid()->toString(),
                fn () => DB::connection('pgsql')->table('raw_material_revision_allergen_declarations')->insert([
                    'revision_id' => $revisionId,
                    'organization_id' => $this->steward->organization_id,
                    'catalog_version_id' => $catalogVersionId,
                    'allergen_code' => 'TEST_MILK',
                    'exposure_kind' => 'DIRECT',
                ]),
            ));

        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection
            ->table('raw_material_revision_storage_variants')->insert([
                'id' => Str::uuid()->toString(),
                'revision_id' => $revisionId,
                'organization_id' => $this->steward->organization_id,
                ...$this->storageVariant('COLD', isDefault: false),
            ]));

        $this->assertSame(1, DB::table('raw_material_revision_allergen_attestations')
            ->where('revision_id', $revisionId)->count());
        $this->assertSame(1, DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $revisionId)->count());
        $this->assertSame(1, DB::table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisionId)->count());
        $this->assertRevisionHashMatches(DB::table('raw_material_revisions')->where('id', $revisionId)->first());
    }

    public function test_historical_revision_hash_format_remains_version_one(): void
    {
        $legacy = $this->createLegacyDeclaredV1Revision();
        $revision = DB::table('raw_material_revisions')->where('id', $legacy['revision_id'])->first();
        $this->assertSame(1, (int) $revision->content_hash_version);
        $this->assertSame($legacy['hash'], $this->calculateRevisionHash($revision));
    }

    private function createLegacyDeclaredV1Revision(): array
    {
        $materialId = Str::uuid()->toString();
        $revisionId = Str::uuid()->toString();
        $legacyName = 'Historyczny materiał';
        $alias = mb_strtolower($legacyName, 'UTF-8');
        $payload = [
            'category_id' => $this->categoryId,
            'name' => $legacyName,
            'description' => null,
            'base_unit_code' => 'g',
            'base_unit_dimension' => 'MASS',
            'allergen_state' => 'DECLARED',
        ];
        ksort($payload);
        $units = [[
            'unit_code' => 'g',
            'dimension' => 'MASS',
            'conversion_kind' => 'BASE',
            'to_base_factor' => '1.000000000000',
            'basis' => null,
        ]];
        $aliases = [['alias' => $legacyName, 'normalized_alias' => $alias]];
        $legacyHash = hash('sha256', json_encode([
            'payload' => $payload,
            'units' => $units,
            'aliases' => $aliases,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->withSetupTransaction(function (Connection $connection) use (
            $materialId,
            $revisionId,
            $legacyName,
            $payload,
            $units,
            $aliases,
            $legacyHash,
        ): void {
            $connection->table('organization_sku_counters')->insertOrIgnore([
                'organization_id' => $this->steward->organization_id,
                'prefix' => 'SUR',
                'last_value' => 0,
                'updated_at' => now(),
            ]);
            $connection->table('organization_sku_counters')
                ->where('organization_id', $this->steward->organization_id)
                ->where('prefix', 'SUR')->update(['last_value' => 1]);
            $connection->table('raw_materials')->insert([
                'id' => $materialId,
                'organization_id' => $this->steward->organization_id,
                'code' => 'SUR-000001',
                'lifecycle_state' => 'DRAFT',
                'working_revision_id' => $revisionId,
                'created_by' => $this->steward->id,
            ]);
            $connection->table('organization_sku_assignments')->insert([
                'organization_id' => $this->steward->organization_id,
                'sku' => 'SUR-000001',
                'prefix' => 'SUR',
                'sequence_number' => 1,
                'entity_type' => 'RAW',
                'entity_id' => $materialId,
                'allocation_source' => 'LEGACY',
                'assigned_by' => $this->steward->id,
            ]);
            $connection->table('raw_material_revisions')->insert([
                'id' => $revisionId,
                'organization_id' => $this->steward->organization_id,
                'raw_material_id' => $materialId,
                'revision_no' => 1,
                'category_id' => $this->categoryId,
                'name' => $legacyName,
                'base_unit_code' => 'g',
                'base_unit_dimension' => 'MASS',
                'allergen_state' => $payload['allergen_state'],
                'critical_change' => false,
                'created_by' => $this->steward->id,
                'content_hash' => $legacyHash,
            ]);
            foreach ($units as $unit) {
                $connection->table('raw_material_revision_units')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $this->steward->organization_id,
                    'revision_id' => $revisionId,
                    ...$unit,
                ]);
            }
            $connection->table('raw_material_aliases')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->steward->organization_id,
                'raw_material_id' => $materialId,
                'revision_id' => $revisionId,
                ...$aliases[0],
            ]);
        });

        return [
            'material_id' => $materialId,
            'revision_id' => $revisionId,
            'hash' => $legacyHash,
        ];
    }

    private function createMaterial(string $name, array $extra = []): array
    {
        return $this->rawMaterials->create($this->steward, $this->location, [
            'category_id' => $this->categoryId,
            'name' => $name,
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            ...$extra,
        ]);
    }

    private function publishedCatalog(array $definitions): string
    {
        $versionId = Str::uuid()->toString();
        $versionNumber = (int) DB::connection('pgsql_setup')
            ->table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
        $this->withSetupTransaction(function (Connection $connection) use ($versionId, $versionNumber, $definitions): void {
            $connection->table('raw_material_allergen_catalog_versions')->insert([
                'id' => $versionId,
                'version_number' => $versionNumber,
                'status' => 'DRAFT',
            ]);
            foreach ($definitions as $index => $definition) {
                $connection->table('raw_material_allergen_definitions')->insert([
                    'catalog_version_id' => $versionId,
                    'allergen_code' => $definition['allergen_code'],
                    'name_pl' => $definition['name_pl'],
                    'display_order' => $index + 1,
                ]);
            }
            $connection->table('raw_material_allergen_catalog_versions')->where('id', $versionId)
                ->update(['status' => 'PUBLISHED', 'published_at' => now()]);
        });

        return $versionId;
    }

    private function storageVariant(
        string $code = 'AMBIENT',
        string $temperatureMin = '10.00',
        string $temperatureMax = '25.00',
        ?string $humidityMin = null,
        ?string $humidityMax = null,
        bool $isDefault = true,
    ): array {
        return [
            'variant_code' => $code,
            'name_pl' => 'Warunki pokojowe',
            'storage_type' => 'AMBIENT',
            'temperature_min_c' => $temperatureMin,
            'temperature_max_c' => $temperatureMax,
            'humidity_min_percent' => $humidityMin,
            'humidity_max_percent' => $humidityMax,
            'conditions' => null,
            'is_default' => $isDefault,
        ];
    }

    private function assertRevisionHashMatches(object $revision): void
    {
        $this->assertSame($revision->content_hash, $this->calculateRevisionHash($revision));
    }

    private function calculateRevisionHash(object $revision): string
    {
        $method = new ReflectionMethod(RawMaterialService::class, 'hashFromDatabase');

        return $method->invoke($this->rawMaterials, $revision);
    }

    protected function assertDatabaseRejects(string $sqlState, callable $operation): void
    {
        try {
            $this->withSetupTransaction(function (Connection $connection) use ($operation): void {
                $operation($connection);
                $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);

            return;
        }

        $this->fail("PostgreSQL did not reject the operation with SQLSTATE {$sqlState}.");
    }

    protected function assertRuntimeDatabaseRejects(string $sqlState, callable $operation): void
    {
        try {
            $operation(DB::connection('pgsql'));
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);

            return;
        }

        $this->fail("PostgreSQL runtime role did not reject the operation with SQLSTATE {$sqlState}.");
    }

    private function bootstrapFixtureGrants(): void
    {
        $permissions = array_map(
            fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        $this->withSetupTransaction(function (Connection $connection) use ($permissions, $operationId): void {
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->steward->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $this->administrator->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $this->steward->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $this->steward->id,
                    'permissions' => $permissions,
                ], JSON_THROW_ON_ERROR),
            ]);
            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $this->steward->organization_id,
                    'user_id' => $this->steward->id,
                    'permission' => $permission,
                    'granted_by' => $this->administrator->id,
                    'valid_from' => now(),
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
            }
        });
    }
}
