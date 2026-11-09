<?php

namespace Tests\Feature;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\LocationType;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\ModuleDefinition;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentException;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialCatalogCsvImportWorkflow;
use App\Domain\RawMaterials\RawMaterialCatalogCsvParser;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialCsvCatalogExportWorkflow;
use App\Domain\RawMaterials\RawMaterialCsvImportPersistenceService;
use App\Domain\RawMaterials\RawMaterialCsvParser;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialLocationShareAdministration;
use App\Domain\RawMaterials\RawMaterialLocationSharePolicy;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Domain\Sku\SkuAllocationService;
use App\Domain\Sku\SkuPrefix;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialsTest extends AuthorizationTestCase
{
    private Location $location;

    private User $bootstrapAdministrator;

    private User $steward;

    private RawMaterialService $rawMaterialService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->location = $this->primaryCentralKitchen();
        $this->bootstrapAdministrator = $this->user('raw-material-bootstrap@example.test');
        $this->role($this->bootstrapAdministrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->bootstrapAdministrator, $this->location, $this->bootstrapAdministrator->id);
        $this->steward = $this->user('raw-material-steward@example.test');
        app(MembershipService::class)->assign($this->steward, $this->location);
        $this->updateFixture($this->steward->organization, ['authorization_initialized' => true]);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $this->location->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id,
            'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        $this->bootstrapFixtureGrants($this->bootstrapAdministrator, $this->steward);
        $this->rawMaterialService = app(RawMaterialService::class);
    }

    public function test_stage2e7_integrated_catalog_pricing_and_as_of_exports(): void
    {
        Storage::fake('raw_material_csv_imports');
        Storage::fake('raw_material_csv_exports');
        $category = $this->category();
        $this->selectRawMaterialApiContext($this->steward);

        $catalogCsv = "sku;entity_type;name;description;base_unit_code;category_code\n"
            ."RAW-000801;RAW;Mąka integracyjna;Opis mąki;kg;{$category['code']}\n"
            ."RAW-000802;RAW;Ryż integracyjny;Opis ryżu;kg;{$category['code']}\n"
            ."RAW-000803;RAW;Sól integracyjna;Opis soli;kg;{$category['code']}\n";
        $catalogPreviewResponse = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/catalog-imports', [
            'file' => UploadedFile::fake()->createWithContent('katalog-integracyjny.csv', $catalogCsv),
        ])->assertCreated()->assertJsonPath('data.can_commit', true);
        $catalogPreview = $catalogPreviewResponse->json('data');
        $catalogCommitUrl = '/api/v1/raw-materials/catalog-imports/'.$catalogPreview['preview_id'].'/commit';
        $catalogCommit = $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'stage2e7-catalog-integrated-0001')
            ->postJson($catalogCommitUrl, ['confirmed' => true])
            ->assertOk()->assertJsonPath('data.batch_status', 'COMMITTED');
        $catalogBatchId = $catalogCommit->json('data.batch_id');
        $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'stage2e7-catalog-integrated-0001')
            ->postJson($catalogCommitUrl, ['confirmed' => true])
            ->assertOk()->assertJsonPath('data.batch_id', $catalogBatchId);

        $materials = DB::table('raw_materials')->where('organization_id', $this->steward->organization_id)
            ->whereIn('code', ['RAW-000801', 'RAW-000802', 'RAW-000803'])->orderBy('code')->get();
        $this->assertSame(['RAW-000801', 'RAW-000802', 'RAW-000803'], $materials->pluck('code')->all());
        $this->assertSame(['DRAFT', 'DRAFT', 'DRAFT'], $materials->pluck('lifecycle_state')->all());
        $this->assertSame([null, null, null], $materials->pluck('approved_revision_id')->all());

        foreach ([
            '/api/v1/raw-materials/catalog-exports',
            '/api/v1/raw-materials/catalog-exports/extended',
        ] as $exportRoute) {
            $draftExport = $this->actingAs($this->steward, 'web')->postJson($exportRoute)
                ->assertCreated()->assertJsonPath('data.row_count', 0)->json('data');
            $draftCsv = $this->actingAs($this->steward, 'web')->get($draftExport['download_url'])
                ->assertOk()->streamedContent();
            $this->assertStringNotContainsString('RAW-00080', $draftCsv);
        }

        foreach ($materials as $material) {
            $this->attestWorkingRevision($material->id);
            $this->selectRawMaterialApiContext($this->steward);
            $this->actingAs($this->steward, 'web')
                ->postJson('/api/v1/raw-materials/'.$material->id.'/submit')
                ->assertOk();
            $this->actingAs($this->steward, 'web')
                ->postJson('/api/v1/raw-materials/'.$material->id.'/approve', ['reason' => 'Zatwierdzenie testu integracyjnego'])
                ->assertOk();
        }

        $this->selectRawMaterialApiContext($this->steward);
        $legacyExport = $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertCreated()->assertJsonPath('data.row_count', 3)->json('data');
        $legacyCsv = $this->actingAs($this->steward, 'web')->get($legacyExport['download_url'])
            ->assertOk()->streamedContent();
        $this->assertSame(
            "ingredient_id;ingredient_name\n"
                ."RAW-000801;\"Mąka integracyjna\"\n"
                ."RAW-000802;\"Ryż integracyjny\"\n"
                ."RAW-000803;\"Sól integracyjna\"\n",
            $legacyCsv,
        );

        $extendedExport = $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports/extended')
            ->assertCreated()->assertJsonPath('data.row_count', 3)->json('data');
        $extendedCsv = $this->actingAs($this->steward, 'web')->get($extendedExport['download_url'])
            ->assertOk()->streamedContent();
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $extendedCsv);
        rewind($stream);
        $extendedRows = [];
        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $extendedRows[] = $row;
        }
        fclose($stream);
        $this->assertSame([
            'sku', 'entity_type', 'name', 'description', 'category_code',
            'category_path_codes', 'base_unit_code', 'approved_revision_no',
        ], $extendedRows[0]);
        $this->assertSame(['RAW-000801', 'RAW', 'Mąka integracyjna', 'Opis mąki', $category['code'], $category['code'], 'kg', '2'], $extendedRows[1]);

        $priceCsv = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n";
        foreach ([
            ['RAW-000801', 'Mąka integracyjna'],
            ['RAW-000802', 'Ryż integracyjny'],
            ['RAW-000803', 'Sól integracyjna'],
        ] as [$sku, $name]) {
            $priceCsv .= "{$sku};{$name};4.125;5;kg;2026-01-01\n";
        }
        $this->selectRawMaterialApiContext($this->steward);
        $baselinePreview = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('ceny-bazowe.csv', $priceCsv),
        ])->assertCreated()->assertJsonPath('data.can_commit', true)->json('data');
        $baselineCommitUrl = '/api/v1/raw-materials/imports/'.$baselinePreview['preview_id'].'/commit';
        $baselineCommit = $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'stage2e7-price-baseline-0001')
            ->postJson($baselineCommitUrl, ['confirmed' => true])
            ->assertOk()->assertJsonPath('data.batch_status', 'COMMITTED')
            ->assertJsonPath('data.rows.0.status', 'BAZA POCZĄTKOWA METADANYCH');
        $baselineBatchId = $baselineCommit->json('data.batch_id');
        $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'stage2e7-price-baseline-0001')
            ->postJson($baselineCommitUrl, ['confirmed' => true])
            ->assertOk()->assertJsonPath('data.batch_id', $baselineBatchId);

        $changedCsv = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n"
            ."RAW-000801;Mąka integracyjna;4.125;23;kg;2026-01-03\n"
            ."RAW-000802;Ryż integracyjny;5.25;5;kg;2026-01-03\n"
            ."RAW-000803;Sól integracyjna;4.125;5;kg;2026-01-03\n";
        $changePreview = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('ceny-zmiany.csv', $changedCsv),
        ])->assertCreated()->assertJsonPath('data.can_commit', true)
            ->assertJsonPath('data.rows.0.status', 'ZMIANA DANYCH')
            ->assertJsonPath('data.rows.1.status', 'ZMIANA CENY')
            ->assertJsonPath('data.rows.2.status', 'BEZ ZMIAN')
            ->json('data');
        $changeCommit = $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'stage2e7-price-changes-0001')
            ->postJson('/api/v1/raw-materials/imports/'.$changePreview['preview_id'].'/commit', ['confirmed' => true])
            ->assertOk()->assertJsonPath('data.batch_status', 'COMMITTED');
        $this->assertSame(4, DB::table('raw_material_reference_price_events')->count());
        $this->assertSame(4, DB::table('raw_material_import_metadata_events')->count());

        $priceExports = [];
        foreach (['2025-12-31', '2026-01-01', '2026-01-03'] as $asOfDate) {
            $export = $this->actingAs($this->steward, 'web')
                ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => $asOfDate])
                ->assertCreated()->json('data');
            $csv = $this->actingAs($this->steward, 'web')->get($export['download_url'])
                ->assertOk()->streamedContent();
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $csv);
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                $rows[] = $row;
            }
            fclose($stream);
            $priceExports[$asOfDate] = collect(array_slice($rows, 1))->keyBy(1)->all();
        }
        $this->assertSame('NO_REFERENCE_PRICE', $priceExports['2025-12-31']['RAW-000801'][8]);
        $this->assertSame('4.12500000000000', $priceExports['2026-01-01']['RAW-000801'][6]);
        $this->assertSame('23.00', $priceExports['2026-01-03']['RAW-000801'][9]);
        $this->assertSame('4.12500000000000', $priceExports['2026-01-03']['RAW-000801'][6]);
        $this->assertSame('5.25000000000000', $priceExports['2026-01-03']['RAW-000802'][6]);
        $this->assertSame('4.12500000000000', $priceExports['2026-01-03']['RAW-000803'][6]);
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $changeCommit->json('data.batch_id'))
            ->where('event_code', 'CSV_IMPORT_COMMITTED')->count());

        $generated = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'SKU po imporcie integracyjnym',
            'base_unit_code' => 'kg',
        ]);
        $this->assertSame('RAW-000804', $generated['code']);
    }

    public function test_create_allocates_immutable_code_and_records_controlled_unit_conversions(): void
    {
        $category = $this->category();
        $material = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Mąka pszenna',
            'base_unit_code' => 'kg',
            'conversions' => [['unit_code' => 'g']],
            'aliases' => ['Mąka tortowa'],
        ]);

        $this->assertSame('RAW-000001', $material['code']);
        $this->assertSame('DRAFT', $material['lifecycle_state']);
        $this->assertSame('UNKNOWN', $material['revision']['allergen_state']);
        $this->assertSame('MASS', $material['revision']['base_unit_dimension']);
        $this->assertSame('0.001000000000', DB::table('raw_material_revision_units')
            ->where('revision_id', $material['revision']['id'])->where('unit_code', 'g')->value('to_base_factor'));
        $this->assertSame(2, DB::table('raw_material_aliases')->where('revision_id', $material['revision']['id'])->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->where('event_code', 'CREATED')->count());
        DB::statement('SET CONSTRAINTS organization_sku_counter_consistency, organization_sku_assignment_counter_consistency IMMEDIATE');
        $this->assertSame(1, (int) DB::table('organization_sku_counters')
            ->where('organization_id', $this->steward->organization_id)
            ->where('prefix', 'RAW')->value('last_value'));
        $this->assertSame('GENERATED', DB::table('organization_sku_assignments')
            ->where('organization_id', $this->steward->organization_id)
            ->where('sku', 'RAW-000001')->value('allocation_source'));
    }

    public function test_sku_prefixes_allocate_independently_and_reserve_unassigned_imported_numbers(): void
    {
        $allocation = app(SkuAllocationService::class);
        $organizationId = $this->steward->organization_id;

        $this->assertSame('INT-000010', DB::transaction(
            fn (): string => $allocation->reserveImported(
                $this->steward,
                SkuPrefix::Intermediate,
                'INT-000010',
                Str::uuid()->toString(),
            ),
        ));
        $this->assertSame('INT-000011', DB::transaction(
            fn (): string => $allocation->allocateNext(
                $this->steward,
                SkuPrefix::Intermediate,
                Str::uuid()->toString(),
            ),
        ));
        $this->assertSame('INT-000005', DB::transaction(
            fn (): string => $allocation->reserveImported(
                $this->steward,
                SkuPrefix::Intermediate,
                'INT-000005',
                Str::uuid()->toString(),
            ),
        ));
        $this->assertSame('PRD-000001', DB::transaction(
            fn (): string => $allocation->allocateNext(
                $this->steward,
                SkuPrefix::Product,
                Str::uuid()->toString(),
            ),
        ));
        $this->assertSame('RAW-000001', DB::transaction(
            fn (): string => $allocation->allocateNext(
                $this->steward,
                SkuPrefix::Raw,
                Str::uuid()->toString(),
            ),
        ));

        $this->assertSame(11, (int) DB::table('organization_sku_counters')
            ->where('organization_id', $organizationId)->where('prefix', 'INT')->value('last_value'));
        $this->assertSame(1, (int) DB::table('organization_sku_counters')
            ->where('organization_id', $organizationId)->where('prefix', 'PRD')->value('last_value'));
        $this->assertSame(1, (int) DB::table('organization_sku_counters')
            ->where('organization_id', $organizationId)->where('prefix', 'RAW')->value('last_value'));
        $this->assertSame('IMPORTED', DB::table('organization_sku_assignments')
            ->where('organization_id', $organizationId)->where('sku', 'INT-000005')->value('allocation_source'));

        try {
            DB::transaction(fn (): string => $allocation->reserveImported(
                $this->steward,
                SkuPrefix::Intermediate,
                'INT-000005',
                Str::uuid()->toString(),
            ));
            $this->fail('Zarezerwowany numer SKU nie może zostać przypisany ponownie.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('już użyty', $exception->getMessage());
        }

        $this->assertRuntimeDatabaseRejects('42501', fn () => DB::table('organization_sku_assignments')->insert([
            'organization_id' => $organizationId,
            'sku' => 'MEN-000001',
            'prefix' => 'MEN',
            'sequence_number' => 1,
            'entity_type' => 'MEN',
            'entity_id' => Str::uuid()->toString(),
            'allocation_source' => 'IMPORTED',
            'assigned_by' => $this->steward->id,
            'created_at' => now(),
        ]));
        $this->assertRuntimeDatabaseRejects('42501', fn () => DB::table('organization_sku_assignments')
            ->where('organization_id', $organizationId)->where('sku', 'INT-000005')
            ->update(['sku' => 'INT-000006']));
    }

    public function test_sku_counters_are_scoped_to_each_organization(): void
    {
        $setup = DB::connection('pgsql_setup');
        $setup->statement('ALTER TABLE public.organizations DROP CONSTRAINT organizations_single_installation_unique');

        try {
            $foreignOrganization = Organization::on('pgsql_setup')->create([
                'name' => 'Druga organizacja SKU',
                'code' => 'SKU-FOREIGN',
            ]);
            $foreignActor = User::on('pgsql_setup')->create([
                'organization_id' => $foreignOrganization->id,
                'first_name' => 'Test',
                'last_name' => 'Tenant',
                'email' => 'sku-foreign@example.test',
                'password' => self::PASSWORD,
                'is_active' => true,
            ]);
            $foreignActor->forceFill(['email_verified_at' => now()])->save();
            $allocation = app(SkuAllocationService::class);

            $this->assertSame('RAW-000001', DB::transaction(
                fn (): string => $allocation->allocateNext(
                    $this->steward,
                    SkuPrefix::Raw,
                    Str::uuid()->toString(),
                ),
            ));
            $this->assertSame('RAW-000001', DB::transaction(
                fn (): string => $allocation->allocateNext(
                    $foreignActor,
                    SkuPrefix::Raw,
                    Str::uuid()->toString(),
                ),
            ));
            $this->assertSame(1, DB::table('organization_sku_assignments')
                ->where('organization_id', $this->steward->organization_id)
                ->where('sku', 'RAW-000001')->count());
            $this->assertSame(1, DB::table('organization_sku_assignments')
                ->where('organization_id', $foreignOrganization->id)
                ->where('sku', 'RAW-000001')->count());
        } finally {
            $this->resetTestDatabase();
            $setup->statement(
                'ALTER TABLE public.organizations ADD CONSTRAINT organizations_single_installation_unique UNIQUE (installation)',
            );
        }
    }

    public function test_csv_import_records_metadata_baseline_and_only_genuine_history_changes(): void
    {
        $material = $this->activeCsvImportMaterial('Składnik do importu CSV');
        $imports = app(RawMaterialCsvImportPersistenceService::class);
        $initial = $this->csvImportRow($material, [
            'price_net' => '4.125',
            'normalized_net_pln_per_base_unit' => '0.004125',
            'vat_rate' => '5',
            'unit' => 'kg',
            'effective_date' => '2026-01-01',
        ]);
        $sourceHash = hash('sha256', 'fixture-csv-baseline');
        $baseline = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            $sourceHash,
            'csv-import-baseline-0001',
            [$initial],
        );

        $this->assertSame('COMMITTED', $baseline['batch_status']);
        $this->assertSame('BAZA POCZĄTKOWA METADANYCH', $baseline['rows'][0]['status']);
        $this->assertNotNull($baseline['rows'][0]['created_reference_price_event_id']);
        $this->assertSame(1, DB::table('raw_material_import_metadata_events')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertSame(1, DB::table('raw_material_reference_price_events')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('raw_material_import_batches')->where('id', $baseline['batch_id'])
            ->update(['source_sha256' => hash('sha256', 'tampered')]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('raw_material_import_rows')->where('batch_id', $baseline['batch_id'])
            ->delete());
        $metadataId = $baseline['rows'][0]['effective_metadata_state_id'];
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('raw_material_import_metadata_events')->where('id', $metadataId)
            ->update(['vat_rate' => '0']));
        $this->assertNull(DB::table('raw_material_import_metadata_events')
            ->where('raw_material_id', $material['id'])->whereDate('effective_from', '<', '2026-01-01')->first());

        $replay = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            $sourceHash,
            'csv-import-baseline-0001',
            [$initial],
        );
        $this->assertSame($baseline, $replay);
        $this->assertSame(1, DB::table('raw_material_import_rows')
            ->where('raw_material_id', $material['id'])->count());

        $sameState = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'fixture-csv-same-state'),
            'csv-import-same-state-0001',
            [$this->csvImportRow($material, [
                'effective_date' => '2026-01-02',
                'price_net' => '4.125',
                'normalized_net_pln_per_base_unit' => '0.004125',
                'vat_rate' => '5',
                'unit' => 'kg',
            ])],
        );
        $this->assertSame('BEZ ZMIAN', $sameState['rows'][0]['status']);
        $this->assertSame(1, DB::table('raw_material_reference_price_events')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertSame(1, DB::table('raw_material_import_metadata_events')
            ->where('raw_material_id', $material['id'])->count());

        $metadataChange = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'fixture-csv-metadata-change'),
            'csv-import-metadata-change-0001',
            [$this->csvImportRow($material, [
                'effective_date' => '2026-01-03',
                'price_net' => '4.125',
                'normalized_net_pln_per_base_unit' => '0.004125',
                'vat_rate' => '23',
                'unit' => 'l',
            ])],
        );
        $this->assertSame('ZMIANA DANYCH', $metadataChange['rows'][0]['status']);
        $this->assertNull($metadataChange['rows'][0]['created_reference_price_event_id']);
        $this->assertSame(1, DB::table('raw_material_reference_price_events')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertSame(2, DB::table('raw_material_import_metadata_events')
            ->where('raw_material_id', $material['id'])->count());

        $priceChange = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'fixture-csv-price-change'),
            'csv-import-price-change-0001',
            [$this->csvImportRow($material, [
                'effective_date' => '2026-01-04',
                'price_net' => '5',
                'normalized_net_pln_per_base_unit' => '0.005',
                'vat_rate' => '23',
                'unit' => 'l',
            ])],
        );
        $this->assertSame('ZMIANA CENY', $priceChange['rows'][0]['status']);
        $this->assertSame(2, DB::table('raw_material_reference_price_events')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertSame(2, DB::table('raw_material_import_metadata_events')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertSame($metadataChange['rows'][0]['effective_metadata_state_id'],
            $priceChange['rows'][0]['effective_metadata_state_id']);
        $this->assertSame($metadataChange['rows'][0]['effective_metadata_state_id'],
            DB::table('raw_material_import_metadata_events')
                ->where('raw_material_id', $material['id'])
                ->whereDate('effective_from', '<=', '2026-01-03')
                ->orderByDesc('effective_from')
                ->value('id'));

        $priceAndMetadataChange = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'fixture-csv-price-and-metadata-change'),
            'csv-import-price-metadata-change-0001',
            [$this->csvImportRow($material, [
                'effective_date' => '2026-01-05',
                'price_net' => '6',
                'normalized_net_pln_per_base_unit' => '0.006',
                'vat_rate' => '5',
                'unit' => 'kg',
            ])],
        );
        $this->assertSame('ZMIANA CENY I DANYCH', $priceAndMetadataChange['rows'][0]['status']);
        $this->assertSame(3, DB::table('raw_material_reference_price_events')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertSame(3, DB::table('raw_material_import_metadata_events')
            ->where('raw_material_id', $material['id'])->count());

        try {
            $imports->recordPreparedBatch(
                $this->steward,
                $this->location,
                $sourceHash,
                'csv-import-baseline-0001',
                [$this->csvImportRow($material, ['price_net' => '9'])],
            );
            $this->fail('The CSV import idempotency key was reused with a different payload.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }

        $unknown = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'unknown-ingredient'),
            'csv-import-unknown-ingredient-0001',
            [$this->csvImportRow([
                'id' => null,
                'code' => 'SUR-999999',
                'name' => 'Nieznany składnik',
            ])],
        );
        $this->assertSame('REJECTED', $unknown['batch_status']);
        $this->assertSame('BŁĘDY', $unknown['rows'][0]['status']);
        $this->assertSame('UNKNOWN_INGREDIENT_ID', $unknown['rows'][0]['error_code']);
    }

    public function test_csv_same_date_conflict_rejects_the_whole_batch_without_writing_history(): void
    {
        $categoryId = $this->category()['id'];
        $firstMaterial = $this->activeCsvImportMaterial('Pierwszy składnik CSV', $categoryId);
        $secondMaterial = $this->activeCsvImportMaterial('Drugi składnik CSV', $categoryId);
        $imports = app(RawMaterialCsvImportPersistenceService::class);
        $secondBaseline = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'second-material-baseline'),
            'csv-import-second-baseline-0001',
            [$this->csvImportRow($secondMaterial, [
                'effective_date' => '2026-02-01',
                'price_net' => '2',
                'normalized_net_pln_per_base_unit' => '0.002',
                'vat_rate' => '5',
                'unit' => 'kg',
            ])],
        );
        $this->assertSame('COMMITTED', $secondBaseline['batch_status']);

        $eventCountBefore = DB::table('raw_material_reference_price_events')->count();
        $metadataCountBefore = DB::table('raw_material_import_metadata_events')->count();
        $result = $imports->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'atomic-date-conflict'),
            'csv-import-date-conflict-0001',
            [
                $this->csvImportRow($firstMaterial, [
                    'effective_date' => '2026-02-01',
                    'price_net' => '3',
                    'normalized_net_pln_per_base_unit' => '0.003',
                    'vat_rate' => '5',
                    'unit' => 'kg',
                ]),
                $this->csvImportRow($secondMaterial, [
                    'effective_date' => '2026-02-01',
                    'price_net' => '2.5',
                    'normalized_net_pln_per_base_unit' => '0.0025',
                    'vat_rate' => '5',
                    'unit' => 'kg',
                ]),
            ],
        );

        $this->assertSame('REJECTED', $result['batch_status']);
        $this->assertSame('BŁĘDY', $result['rows'][0]['status']);
        $this->assertSame('KONFLIKT DATY', $result['rows'][1]['status']);
        $this->assertSame($eventCountBefore, DB::table('raw_material_reference_price_events')->count());
        $this->assertSame($metadataCountBefore, DB::table('raw_material_import_metadata_events')->count());
        $this->assertSame(0, DB::table('raw_material_import_metadata_events')
            ->where('raw_material_id', $firstMaterial['id'])->count());
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $result['batch_id'])
            ->where('event_code', 'CSV_IMPORT_REJECTED')
            ->where('outcome', 'REJECTED')->count());
    }

    public function test_csv_import_history_requires_signed_runtime_mutations_and_composite_tenant_references(): void
    {
        $organizationId = $this->steward->organization_id;
        $actorId = $this->steward->id;
        $this->assertRuntimeDatabaseRejects('42501', fn () => DB::table('raw_material_import_batches')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $organizationId,
            'actor_user_id' => $actorId,
        ]));
        $this->assertRuntimeDatabaseRejects('42501', fn () => DB::table('raw_material_import_rows')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $organizationId,
        ]));
        $this->assertRuntimeDatabaseRejects('42501', fn () => DB::table('raw_material_import_metadata_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $organizationId,
        ]));

        $material = $this->activeCsvImportMaterial('Składnik z ograniczeniem organizacji');
        $batch = app(RawMaterialCsvImportPersistenceService::class)->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'tenant-integrity'),
            'csv-import-tenant-integrity-0001',
            [$this->csvImportRow($material)],
        );
        $this->withForeignOrganizationFixture(function (Location $foreignLocation) use ($batch): void {
            $this->assertDatabaseRejects('23503', fn (Connection $connection) => $connection
                ->table('raw_material_import_rows')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $foreignLocation->organization_id,
                    'batch_id' => $batch['batch_id'],
                    'row_number' => 999,
                    'ingredient_code' => 'SUR-999999',
                    'input_ingredient_name' => 'Niedozwolony wiersz',
                    'source_price_net' => '1',
                    'normalized_net_pln_per_base_unit' => '1',
                    'vat_rate' => '23',
                    'input_unit' => 'kg',
                    'effective_date' => '2026-01-01',
                    'result_code' => 'INVALID_ROW',
                    'error_code' => 'TEST_TENANT_MISMATCH',
                ]));
        });
    }

    public function test_csv_parser_rejects_malformed_records_and_duplicate_identifiers(): void
    {
        $parser = app(RawMaterialCsvParser::class);
        $header = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n";
        $valid = $parser->parse($header."SUR-000123;\"Mąka; drobna\";4.125;23;kg;2026-11-01\n");
        $this->assertSame('Mąka; drobna', $valid[0]['ingredient_name']);
        $this->assertSame('RAW-000123', $parser->parse(
            $header."RAW-000123;Mąka;4.125;23;kg;2026-11-01\n",
        )[0]['ingredient_id']);

        foreach ([
            $header."SUR-000123;Mąka;4;23;kg;2026-11-01;extra\n",
            $header."SUR-000123;\"Mąka;4;23;kg;2026-11-01\n",
            $header."SUR-000123;Mą\"ka;4;23;kg;2026-11-01\n",
            $header."SUR-000123;Mąka;1e3;23;kg;2026-11-01\n",
            $header."SUR-000123;Mąka;4.123456789012345;23;kg;2026-11-01\n",
            $header."SUR-000123;Mąka;4;101;kg;2026-11-01\n",
            $header."SUR-000123;Mąka;4;23;kg;2026-02-30\n",
        ] as $invalid) {
            try {
                $parser->parse($invalid);
                $this->fail('Parser zaakceptował nieprawidłowy plik CSV.');
            } catch (RawMaterialException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }

        try {
            $parser->parse($header
                ."SUR-000123;Mąka;4;23;kg;2026-11-01\n"
                ."SUR-000123;Mąka;4;23;kg;2026-11-02\n");
            $this->fail('Parser zaakceptował powtórzony identyfikator SUR.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_CSV_DUPLICATE_IDENTIFIER', $exception->errorCode);
            $this->assertSame([2, 3], $exception->details['row_numbers']);
        }
    }

    public function test_catalog_csv_parser_requires_the_exact_header_and_preserves_quoted_utf8_fields(): void
    {
        $parser = app(RawMaterialCatalogCsvParser::class);
        $rows = $parser->parse(
            'sku;entity_type;name;description;base_unit_code;category_code'."\r\n"
                .'RAW-000123;RAW;"Mąka; ""00""";"Opis'."\r\n".'z polskimi znakami";kg;DRY_GOODS'."\r\n",
        );

        $this->assertSame('Mąka; "00"', $rows[0]['name']);
        $this->assertSame("Opis\r\nz polskimi znakami", $rows[0]['description']);
        $this->assertSame(2, $rows[0]['row_number']);

        foreach ([
            "SKU;entity_type;name;description;base_unit_code;category_code\nRAW-000123;RAW;Mąka;;kg;DRY_GOODS\n",
            "sku;entity_type;name;description;base_unit_code;category_code;extra\nRAW-000123;RAW;Mąka;;kg;DRY_GOODS;wartość\n",
            "sku;entity_type;name;description;base_unit_code;category_code\n",
        ] as $invalid) {
            try {
                $parser->parse($invalid);
                $this->fail('Parser zaakceptował nieprawidłowy nagłówek albo puste dane.');
            } catch (RawMaterialException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }

        try {
            $parser->parse("\xFF\xFE".'sku;entity_type;name;description;base_unit_code;category_code'."\nRAW-000123;RAW;Mąka;;kg;DRY_GOODS\n");
            $this->fail('Parser zaakceptował dane spoza UTF-8.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_CATALOG_CSV_ENCODING_INVALID', $exception->errorCode);
        }
    }

    public function test_catalog_csv_import_preserves_skus_exact_names_and_draft_state_and_is_idempotent(): void
    {
        Storage::fake('raw_material_csv_imports');
        $category = $this->category();
        $workflow = app(RawMaterialCatalogCsvImportWorkflow::class);
        $csv = "sku;entity_type;name;description;base_unit_code;category_code\n"
            .'RAW-000020;RAW;" Mąka; ""00"" ";"Opis; ""dokładny""";kg;'.$category['code']."\n"
            .'RAW-000010;RAW;Ryż;Ziarno;g;'.$category['code']."\n";
        $preview = $workflow->upload(
            $this->steward,
            $this->location,
            UploadedFile::fake()->createWithContent('katalog.csv', $csv),
        );

        $this->assertTrue($preview['can_commit']);
        $this->assertSame(['CREATE', 'CREATE'], array_column($preview['rows'], 'status_code'));
        $idempotencyKey = 'catalog-import-exact-identities-0001';
        $result = $workflow->commit($this->steward, $this->location, $preview['preview_id'], $idempotencyKey, true);
        $retry = $workflow->commit($this->steward, $this->location, $preview['preview_id'], $idempotencyKey, true);

        $this->assertSame($result['batch_id'], $retry['batch_id']);
        $this->assertSame(2, $result['row_count']);
        $this->assertSame(2, DB::table('raw_materials')->where('organization_id', $this->steward->organization_id)->count());
        $materialId = DB::table('raw_materials')->where('code', 'RAW-000020')->value('id');
        $this->assertSame(' Mąka; "00" ', DB::table('raw_material_revisions')
            ->where('raw_material_id', $materialId)->value('name'));
        $this->assertSame('Opis; "dokładny"', DB::table('raw_material_revisions')
            ->where('raw_material_id', $materialId)->value('description'));
        $this->assertSame('DRAFT', DB::table('raw_materials')->where('code', 'RAW-000020')->value('lifecycle_state'));
        $this->assertNull(DB::table('raw_materials')->where('code', 'RAW-000020')->value('approved_revision_id'));
        $this->assertSame('UNKNOWN', DB::table('raw_material_revisions')
            ->where('raw_material_id', $materialId)->value('allergen_state'));
        $this->assertSame(0, DB::table('raw_material_aliases')->count());
        $this->assertSame(0, DB::table('supplier_products')->count());
        $this->assertSame(0, DB::table('raw_material_reference_price_events')->count());
        $this->assertSame(1, DB::table('raw_material_catalog_import_batches')->count());
        $this->assertSame(2, DB::table('raw_material_catalog_import_rows')->count());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_catalog_import_rows')
            ->where('batch_id', $result['batch_id'])
            ->update(['name' => 'Zmieniona historia']));

        $generated = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Później wygenerowany',
            'base_unit_code' => 'kg',
        ]);
        $this->assertSame('RAW-000021', $generated['code']);

        $singleRowCsv = "sku;entity_type;name;description;base_unit_code;category_code\n"
            .'RAW-000020;RAW;" Mąka; ""00"" ";"Opis; ""dokładny""";kg;'.$category['code']."\n";
        $singleRowPreview = $workflow->upload(
            $this->steward,
            $this->location,
            UploadedFile::fake()->createWithContent('katalog-czesciowy.csv', $singleRowCsv),
        );
        $workflow->commit(
            $this->steward,
            $this->location,
            $singleRowPreview['preview_id'],
            'catalog-import-partial-file-key-01',
            true,
        );
        $this->assertSame(3, DB::table('raw_materials')->where('organization_id', $this->steward->organization_id)->count());
        $this->assertSame(1, DB::table('raw_materials')->where('code', 'RAW-000010')->count());
    }

    public function test_catalog_csv_import_reports_duplicates_missing_skus_and_invalid_categories(): void
    {
        Storage::fake('raw_material_csv_imports');
        $workflow = app(RawMaterialCatalogCsvImportWorkflow::class);
        $csv = "sku;entity_type;name;description;base_unit_code;category_code\n"
            ."RAW-000201;RAW;Pierwszy;;kg;BASIC\n"
            ."RAW-000201;RAW;Duplikat;;kg;BASIC\n"
            .";RAW;Brak SKU;;kg;BASIC\n"
            ."RAW-000204;RAW;Brak kategorii;;kg;UNKNOWN\n"
            ."RAW-000205;RAW;Nieobsługiwana jednostka;;box;BASIC\n";
        $preview = $workflow->upload(
            $this->steward,
            $this->location,
            UploadedFile::fake()->createWithContent('bledny-katalog.csv', $csv),
        );

        $this->assertFalse($preview['can_commit']);
        $this->assertSame(
            ['DUPLICATE_SKU', 'DUPLICATE_SKU', 'SKU_REQUIRED', 'CATEGORY_NOT_FOUND', 'BASE_UNIT_INVALID'],
            array_column($preview['rows'], 'error_code'),
        );
        $this->assertSame(0, DB::table('raw_material_catalog_import_batches')->count());
        $this->assertSame(0, DB::table('raw_materials')->count());
    }

    public function test_catalog_csv_import_rejects_existing_conflicts_and_preview_snapshot_changes(): void
    {
        Storage::fake('raw_material_csv_imports');
        $category = $this->category();
        $workflow = app(RawMaterialCatalogCsvImportWorkflow::class);
        $csv = "sku;entity_type;name;description;base_unit_code;category_code\n"
            ."RAW-000300;RAW;Nowa mąka;;kg;{$category['code']}\n";
        $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('katalog.csv', $csv);
        $initial = $workflow->upload($this->steward, $this->location, $file());
        $this->rawMaterialService->createImported($this->steward, $this->location, 'RAW-000300', [
            'category_id' => $category['id'],
            'name' => 'Inna mąka',
            'description' => null,
            'base_unit_code' => 'kg',
        ]);

        try {
            $workflow->commit($this->steward, $this->location, $initial['preview_id'], 'catalog-stale-preview-key-01', true);
            $this->fail('Zatwierdzono nieaktualny podgląd importu.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_CATALOG_CSV_PREVIEW_STALE', $exception->errorCode);
        }
        $this->assertSame(0, DB::table('raw_material_catalog_import_batches')->count());

        $conflict = $workflow->upload($this->steward, $this->location, $file());
        $this->assertFalse($conflict['can_commit']);
        $this->assertSame('SKU_CONFLICT', $conflict['rows'][0]['error_code']);

        $identicalCsv = "sku;entity_type;name;description;base_unit_code;category_code\n"
            ."RAW-000300;RAW;Inna mąka;;kg;{$category['code']}\n";
        $identical = $workflow->upload(
            $this->steward,
            $this->location,
            UploadedFile::fake()->createWithContent('identyczny.csv', $identicalCsv),
        );
        $this->assertTrue($identical['can_commit']);
        $this->assertSame('NO_CHANGE', $identical['rows'][0]['status_code']);
        $workflow->commit($this->steward, $this->location, $identical['preview_id'], 'catalog-identical-row-key-01', true);
        $this->assertSame(1, DB::table('raw_materials')->count());
        $this->assertSame(1, DB::table('raw_material_catalog_import_rows')->where('result_code', 'NO_CHANGE')->count());
    }

    public function test_catalog_csv_import_rolls_back_the_entire_batch_after_a_mid_batch_failure(): void
    {
        Storage::fake('raw_material_csv_imports');
        $category = $this->category();
        $csv = "sku;entity_type;name;description;base_unit_code;category_code\n"
            ."RAW-000401;RAW;Pierwszy;;kg;{$category['code']}\n"
            ."RAW-000402;RAW;Drugi;;kg;{$category['code']}\n";
        $workflow = app(RawMaterialCatalogCsvImportWorkflow::class);
        $preview = $workflow->upload(
            $this->steward,
            $this->location,
            UploadedFile::fake()->createWithContent('wycofany.csv', $csv),
        );
        $setup = DB::connection('pgsql_setup');
        $setup->unprepared(<<<'SQL'
            CREATE FUNCTION public.test_reject_second_catalog_import_row()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                IF NEW.name = 'Drugi' THEN
                    RAISE EXCEPTION 'Kontrolowana awaria importu';
                END IF;
                RETURN NEW;
            END
            $function$;
            CREATE TRIGGER test_reject_second_catalog_import_row
                BEFORE INSERT ON public.raw_material_revisions
                FOR EACH ROW EXECUTE FUNCTION public.test_reject_second_catalog_import_row();
            SQL);

        try {
            try {
                $workflow->commit(
                    $this->steward,
                    $this->location,
                    $preview['preview_id'],
                    'catalog-rollback-batch-key-01',
                    true,
                );
                $this->fail('Nie zgłoszono awarii kontrolowanej podczas zatwierdzania.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Kontrolowana awaria importu', $exception->getMessage());
            }
        } finally {
            $setup->unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS test_reject_second_catalog_import_row ON public.raw_material_revisions;
                DROP FUNCTION IF EXISTS public.test_reject_second_catalog_import_row();
                SQL);
        }

        $this->assertSame(0, DB::table('raw_materials')->count());
        $this->assertSame(0, DB::table('organization_sku_assignments')->where('prefix', 'RAW')->count());
        $this->assertSame(0, DB::table('raw_material_catalog_import_batches')->count());
        $this->assertSame(0, DB::table('raw_material_catalog_import_rows')->count());
    }

    public function test_catalog_csv_import_is_tenant_scoped_and_can_reserve_the_same_sku_in_another_organization(): void
    {
        Storage::fake('raw_material_csv_imports');
        $category = $this->category();
        $sku = 'RAW-000050';
        $this->withForeignOrganizationFixture(function (Location $foreignLocation) use ($category, $sku): void {
            $foreignOrganizationId = $foreignLocation->organization_id;
            $foreignActorId = Str::uuid()->toString();
            try {
                $this->withSetupTransaction(function (Connection $setup) use ($foreignOrganizationId, $foreignActorId, $sku): void {
                    $setup->table('users')->insert([
                        'id' => $foreignActorId,
                        'organization_id' => $foreignOrganizationId,
                        'first_name' => 'Obcy',
                        'last_name' => 'Użytkownik',
                        'email' => 'catalog-foreign@example.test',
                        'password' => self::PASSWORD,
                        'is_active' => true,
                        'email_verified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $setup->table('organization_sku_counters')->insert([
                        'organization_id' => $foreignOrganizationId,
                        'prefix' => 'RAW',
                        'last_value' => 0,
                    ]);
                    $setup->table('organization_sku_assignments')->insert([
                        'organization_id' => $foreignOrganizationId,
                        'sku' => $sku,
                        'prefix' => 'RAW',
                        'sequence_number' => 50,
                        'entity_type' => 'RAW',
                        'entity_id' => Str::uuid()->toString(),
                        'allocation_source' => 'IMPORTED',
                        'assigned_by' => $foreignActorId,
                        'created_at' => now(),
                    ]);
                    $setup->table('organization_sku_counters')->where('organization_id', $foreignOrganizationId)
                        ->where('prefix', 'RAW')->update(['last_value' => 50]);
                });

                $workflow = app(RawMaterialCatalogCsvImportWorkflow::class);
                $csv = "sku;entity_type;name;description;base_unit_code;category_code\n"
                    ."{$sku};RAW;Lokalny materiał;;kg;{$category['code']}\n";
                $preview = $workflow->upload(
                    $this->steward,
                    $this->location,
                    UploadedFile::fake()->createWithContent('tenant.csv', $csv),
                );

                $this->assertTrue($preview['can_commit']);
                $workflow->commit($this->steward, $this->location, $preview['preview_id'], 'catalog-tenant-sku-key-01', true);
                $this->assertSame(1, DB::table('raw_materials')
                    ->where('organization_id', $this->steward->organization_id)->where('code', $sku)->count());
                $this->assertSame(1, DB::table('organization_sku_assignments')
                    ->where('organization_id', $foreignOrganizationId)->where('sku', $sku)->count());
            } finally {
                $this->resetTestDatabase();
            }
        });
    }

    public function test_catalog_csv_import_commits_at_least_375_rows_using_the_disposable_redis_cache(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.prefix' => 'veltra-stage2e4-catalog-test-'.Str::uuid()->toString(),
            'database.redis.default.url' => null,
            'database.redis.default.host' => env('TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.default.port' => (int) env('TEST_REDIS_PORT', 6379),
            'database.redis.default.database' => 14,
            'database.redis.cache.url' => null,
            'database.redis.cache.host' => env('TEST_REDIS_HOST', '127.0.0.1'),
            'database.redis.cache.port' => (int) env('TEST_REDIS_PORT', 6379),
            'database.redis.cache.database' => 15,
        ]);
        Cache::purge('redis');
        Cache::store('redis')->put('catalog-import-redis-probe', 'connected', 30);
        $this->assertSame('connected', Cache::store('redis')->get('catalog-import-redis-probe'));

        Storage::fake('raw_material_csv_imports');
        $category = $this->category();
        $csv = "sku;entity_type;name;description;base_unit_code;category_code\n";
        for ($number = 1; $number <= 375; $number++) {
            $csv .= sprintf("RAW-%06d;RAW;Surowiec %03d;;kg;%s\n", $number, $number, $category['code']);
        }

        $workflow = app(RawMaterialCatalogCsvImportWorkflow::class);
        $preview = $workflow->upload(
            $this->steward,
            $this->location,
            UploadedFile::fake()->createWithContent('katalog-375.csv', $csv),
        );
        $this->assertTrue($preview['can_commit']);
        $this->assertSame(375, $preview['row_count']);

        $result = $workflow->commit(
            $this->steward,
            $this->location,
            $preview['preview_id'],
            'catalog-375-records-key-01',
            true,
        );
        $this->assertSame(375, $result['row_count']);
        $this->assertSame(375, DB::table('raw_materials')->where('organization_id', $this->steward->organization_id)->count());
        $this->assertSame(375, DB::table('raw_material_catalog_import_rows')->where('batch_id', $result['batch_id'])->count());
        $this->assertSame(375, (int) DB::table('organization_sku_counters')
            ->where('organization_id', $this->steward->organization_id)->where('prefix', 'RAW')->value('last_value'));
    }

    public function test_catalog_csv_import_http_preview_commit_and_result_are_authorized_and_audited(): void
    {
        Storage::fake('raw_material_csv_imports');
        $category = $this->category();
        $this->selectRawMaterialApiContext($this->steward);
        $csv = "sku;entity_type;name;description;base_unit_code;category_code\n"
            ."RAW-000601;RAW;Import HTTP;;kg;{$category['code']}\n";
        $previewResponse = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/catalog-imports', [
            'file' => UploadedFile::fake()->createWithContent('katalog-http.csv', $csv),
        ]);
        $previewResponse->assertCreated()->assertJsonPath('data.can_commit', true)
            ->assertJsonPath('data.rows.0.status', 'UTWORZENIE');
        $preview = $previewResponse->json('data');
        $this->assertArrayNotHasKey('storage_key', $preview);
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_CATALOG_IMPORT_PREVIEW')
            ->where('event_code', 'CATALOG_CSV_IMPORT_PREVIEWED')->count());

        $commitUrl = '/api/v1/raw-materials/catalog-imports/'.$preview['preview_id'].'/commit';
        $commitResponse = $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'catalog-http-idempotency-key-01')
            ->postJson($commitUrl, ['confirmed' => true]);
        $commitResponse->assertOk()->assertJsonPath('data.batch_status', 'COMMITTED')
            ->assertJsonPath('data.rows.0.status', 'UTWORZENIE');
        $batchId = $commitResponse->json('data.batch_id');

        $retryResponse = $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'catalog-http-idempotency-key-01')
            ->postJson($commitUrl, ['confirmed' => true]);
        $retryResponse->assertOk()->assertJsonPath('data.batch_id', $batchId);
        $this->actingAs($this->steward, 'web')
            ->getJson('/api/v1/raw-materials/catalog-imports/results/'.$batchId)
            ->assertOk()->assertJsonPath('data.rows.0.sku', 'RAW-000601');
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $batchId)
            ->where('event_code', 'CATALOG_CSV_IMPORT_COMMITTED')->count());

        $consumerLocation = $this->activeRestaurant('RCSVRESULT');
        app(MembershipService::class)->assign($this->steward, $consumerLocation);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $consumerLocation->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id,
            'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        Auth::forgetGuards();
        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $consumerLocation->id,
        ])->assertOk();
        $this->actingAs($this->steward, 'web')
            ->getJson('/api/v1/raw-materials/catalog-imports/results/'.$batchId)
            ->assertNotFound();
    }

    public function test_catalog_csv_import_requires_create_permission_and_readiness_returns_503(): void
    {
        Storage::fake('raw_material_csv_imports');
        $unauthorized = $this->user('catalog-import-unauthorized@example.test');
        app(MembershipService::class)->assign($unauthorized, $this->location);
        try {
            app(RawMaterialCatalogCsvImportWorkflow::class)->upload(
                $unauthorized,
                $this->location,
                UploadedFile::fake()->createWithContent(
                    'katalog.csv',
                    "sku;entity_type;name;description;base_unit_code;category_code\nRAW-000501;RAW;Mąka;;kg;BASIC\n",
                ),
            );
            $this->fail('Importer zaakceptował operatora bez uprawnienia CREATE.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('raw_material_catalog_import_batches')->count());
        }

        $this->selectRawMaterialApiContext($this->steward);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/catalog-imports', [
            'file' => UploadedFile::fake()->createWithContent(
                'katalog.csv',
                "sku;entity_type;name;description;base_unit_code;category_code\nRAW-000501;RAW;Mąka;;kg;BASIC\n",
            ),
        ])->assertStatus(503)->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
    }

    public function test_csv_http_preview_commit_is_private_normalized_idempotent_and_audited(): void
    {
        Storage::fake('raw_material_csv_imports');
        $material = $this->activeCsvWorkflowMaterial('Mąka; drobna');
        $this->selectRawMaterialApiContext($this->steward);
        $csv = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n"
            .$material['code'].';"'.$material['name'].'";4.125;5;kg;2026-01-01'."\n";

        $previewResponse = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('ceny.csv', $csv),
        ]);
        $previewResponse->assertCreated()->assertJsonPath('data.can_commit', true)
            ->assertJsonPath('data.rows.0.status', 'BAZA POCZĄTKOWA METADANYCH')
            ->assertJsonPath('data.rows.0.normalized_net_pln_per_base_unit', '0.00412500000000');
        $preview = $previewResponse->json('data');
        $this->assertSame(0, DB::table('raw_material_reference_price_events')->count());
        $this->assertSame(0, DB::table('raw_material_import_metadata_events')->count());
        $this->assertCount(1, Storage::disk('raw_material_csv_imports')->allFiles());
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_IMPORT_PREVIEW')
            ->where('event_code', 'CSV_IMPORT_PREVIEWED')
            ->count());

        $idempotencyKey = 'csv-http-workflow-key-0001';
        $commitUrl = '/api/v1/raw-materials/imports/'.$preview['preview_id'].'/commit';
        $storedFile = Storage::disk('raw_material_csv_imports')->allFiles()[0];
        Storage::disk('raw_material_csv_imports')->put($storedFile, $csv.'#zmieniono');
        $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson($commitUrl, ['confirmed' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'RAW_MATERIAL_CSV_SOURCE_CHANGED');
        Storage::disk('raw_material_csv_imports')->put($storedFile, $csv);
        $commitResponse = $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson($commitUrl, ['confirmed' => false]);
        $commitResponse->assertStatus(422)->assertJsonValidationErrors('confirmed');
        $this->assertSame(0, DB::table('raw_material_reference_price_events')->count());
        $commitResponse = $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson($commitUrl, ['confirmed' => true]);
        $commitResponse->assertOk()->assertJsonPath('data.batch_status', 'COMMITTED')
            ->assertJsonPath('data.rows.0.status', 'BAZA POCZĄTKOWA METADANYCH');
        $batchId = $commitResponse->json('data.batch_id');

        $retry = $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson($commitUrl, ['confirmed' => true]);
        $retry->assertOk()->assertJsonPath('data.batch_id', $batchId);
        $this->assertSame(1, DB::table('raw_material_reference_price_events')->count());
        $this->assertSame(1, DB::table('raw_material_import_metadata_events')->count());
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $batchId)
            ->where('event_code', 'CSV_IMPORT_COMMITTED')->count());

        $this->actingAs($this->steward, 'web')->getJson('/api/v1/raw-materials/imports/results/'.$batchId)
            ->assertOk()->assertJsonPath('data.rows.0.normalized_net_pln_per_base_unit', '0.00412500000000');
    }

    public function test_csv_preview_reports_invalid_ingredients_and_blocks_atomic_commit(): void
    {
        Storage::fake('raw_material_csv_imports');
        $categoryId = $this->category()['id'];
        $draftId = $this->declaredMaterial($categoryId, 'Szkic CSV', materialCode: 'SUR-000099');
        $draft = DB::table('raw_materials')->where('id', $draftId)->value('code');
        $this->selectRawMaterialApiContext($this->steward);
        $csv = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n"
            .$draft.';Szkic CSV;1;23;kg;2026-11-01'."\n"
            ."SUR-999999;Nieznany;1;23;kg;2026-11-01\n";
        $response = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('bledne.csv', $csv),
        ]);

        $response->assertCreated()->assertJsonPath('data.can_commit', false)
            ->assertJsonPath('data.rows.0.error_code', 'INGREDIENT_NOT_ACTIVE')
            ->assertJsonPath('data.rows.1.error_code', 'UNKNOWN_INGREDIENT_ID');
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('event_code', 'CSV_IMPORT_PREVIEWED')
            ->where('outcome', 'REJECTED')
            ->count());
        $previewId = $response->json('data.preview_id');
        $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'csv-invalid-preview-key-01')
            ->postJson('/api/v1/raw-materials/imports/'.$previewId.'/commit', ['confirmed' => true])
            ->assertStatus(422)->assertJsonPath('error.code', 'RAW_MATERIAL_CSV_PREVIEW_HAS_ERRORS');
        $this->assertSame(0, DB::table('raw_material_reference_price_events')->count());
    }

    public function test_csv_preview_checks_exact_name_units_and_half_even_conversion_with_periods(): void
    {
        Storage::fake('raw_material_csv_imports');
        $categoryId = $this->category()['id'];
        $mass = $this->activeCsvWorkflowMaterial('Masa CSV', categoryId: $categoryId);
        $volume = $this->activeCsvWorkflowMaterial('Objętość CSV', 'ml', 'VOLUME', [
            'unit_code' => 'l',
            'dimension' => 'VOLUME',
            'conversion_kind' => 'METRIC',
            'to_base_factor' => '1000.000000000000',
            'basis' => null,
        ], $categoryId);
        $count = $this->activeCsvWorkflowMaterial('Sztuki CSV', 'pc', 'COUNT', [
            'unit_code' => 'pc',
            'dimension' => 'COUNT',
            'conversion_kind' => 'BASE',
            'to_base_factor' => '1.000000000000',
            'basis' => null,
        ], $categoryId);
        $halfEven = $this->activeCsvWorkflowMaterial('Zaokrąglenie CSV', 'ml', 'VOLUME', [
            'unit_code' => 'l',
            'dimension' => 'VOLUME',
            'conversion_kind' => 'METRIC',
            'to_base_factor' => '1000.000000000000',
            'basis' => null,
        ], $categoryId);
        app(RawMaterialCsvImportPersistenceService::class)->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'future-reference-price'),
            'csv-import-future-reference-price-01',
            [$this->csvImportRow($mass, [
                'price_net' => '1000',
                'normalized_net_pln_per_base_unit' => '1',
                'effective_date' => '2026-06-01',
            ])],
        );
        $this->selectRawMaterialApiContext($this->steward);
        $csv = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n"
            .$mass['code'].';'.$mass['name'].';4.125;5;kg;2026-01-01'."\n"
            .$volume['code'].';'.$volume['name'].';3.45;23;l;2026-01-02'."\n"
            .$count['code'].';'.$count['name'].';12;23;szt.;2027-01-01'."\n"
            .$halfEven['code'].';'.$halfEven['name'].';0.000000000015;5;l;2026-01-03'."\n";

        $response = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('jednostki.csv', $csv),
        ]);
        $response->assertCreated()->assertJsonPath('data.can_commit', true)
            ->assertJsonPath('data.rows.0.normalized_net_pln_per_base_unit', '0.00412500000000')
            ->assertJsonPath('data.rows.0.is_backdated', true)
            ->assertJsonPath('data.rows.0.affected_until', '2026-06-01')
            ->assertJsonPath('data.rows.1.normalized_net_pln_per_base_unit', '0.00345000000000')
            ->assertJsonPath('data.rows.1.is_backdated', true)
            ->assertJsonPath('data.rows.2.normalized_net_pln_per_base_unit', '12.00000000000000')
            ->assertJsonPath('data.rows.2.is_future', true)
            ->assertJsonPath('data.rows.3.normalized_net_pln_per_base_unit', '0.00000000000002');
        $this->assertSame(1, DB::table('raw_material_reference_price_events')->count());

        $badCsv = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n"
            .$mass['code'].';inna nazwa;4;5;kg;2026-01-01'."\n"
            .$volume['code'].';'.$volume['name'].';4;5;szt.;2026-01-01'."\n";
        $invalid = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('niepoprawne-jednostki.csv', $badCsv),
        ]);
        $invalid->assertCreated()->assertJsonPath('data.can_commit', false)
            ->assertJsonPath('data.rows.0.error_code', 'INGREDIENT_NAME_MISMATCH')
            ->assertJsonPath('data.rows.1.error_code', 'UNIT_NOT_COMPATIBLE');
    }

    public function test_csv_preview_detects_state_race_and_readiness_gate_blocks_http(): void
    {
        Storage::fake('raw_material_csv_imports');
        $material = $this->activeCsvWorkflowMaterial('Składnik wyścigu CSV');
        $this->selectRawMaterialApiContext($this->steward);
        $csv = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n"
            .$material['code'].';'.$material['name'].';4.125;5;kg;2026-02-01'."\n";
        $preview = $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('race.csv', $csv),
        ])->assertCreated()->json('data');

        app(RawMaterialCsvImportPersistenceService::class)->recordPreparedBatch(
            $this->steward,
            $this->location,
            hash('sha256', 'intervening-import'),
            'csv-race-intervening-key-001',
            [$this->csvImportRow($material, [
                'effective_date' => '2026-01-15',
                'normalized_net_pln_per_base_unit' => '0.004',
            ])],
        );
        $this->actingAs($this->steward, 'web')
            ->withHeader('Idempotency-Key', 'csv-race-commit-key-0001')
            ->postJson('/api/v1/raw-materials/imports/'.$preview['preview_id'].'/commit', ['confirmed' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'RAW_MATERIAL_CSV_PREVIEW_STALE');

        $this->app->instance(ModuleCatalog::class, new DefinedModuleCatalog([
            new ModuleDefinition(
                'RAW_MATERIALS',
                'Surowce',
                'RAW_MATERIALS_VIEW',
                [LocationType::CentralKitchen, LocationType::Restaurant],
            ),
        ]));
        $this->actingAs($this->steward, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('not-ready.csv', $csv),
        ])->assertStatus(503)->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
    }

    public function test_csv_import_http_rejects_an_actor_without_reference_price_permission(): void
    {
        Storage::fake('raw_material_csv_imports');
        $reader = $this->user('raw-material-csv-reader@example.test');
        app(MembershipService::class)->assign($reader, $this->location);
        $this->selectRawMaterialApiContext($reader);
        $csv = "ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date\n"
            ."SUR-999999;Nieznany;1;23;kg;2026-11-01\n";

        $this->actingAs($reader, 'web')->post('/api/v1/raw-materials/imports', [
            'file' => UploadedFile::fake()->createWithContent('brak-uprawnien.csv', $csv),
        ])->assertForbidden();
    }

    public function test_catalog_csv_export_contains_only_exact_active_approved_names_and_is_sorted(): void
    {
        Storage::fake('raw_material_csv_exports');
        $categoryId = $this->category()['id'];
        $this->declaredMaterial($categoryId, 'Nieopublikowany szkic');
        $second = $this->activeCsvWorkflowMaterial('Mąka; "biała"', categoryId: $categoryId);
        $first = $this->activeCsvWorkflowMaterial('Żurawina', categoryId: $categoryId);
        $this->selectRawMaterialApiContext($this->steward);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $created = $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertCreated()
            ->assertJsonPath('data.row_count', 2);
        $catalogQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from "raw_materials" as "materials"'),
        );
        DB::disableQueryLog();
        $this->assertCount(1, $catalogQueries);
        $this->assertContains(
            $this->steward->organization_id,
            $catalogQueries->first()['bindings'],
            'Katalog musi być ograniczony do organizacji aktora.',
        );
        $export = $created->json('data');
        $this->assertSame(
            '/api/v1/raw-materials/catalog-exports/'.$export['export_id'].'/download',
            $export['download_url'],
        );
        $files = Storage::disk('raw_material_csv_exports')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('SUR-', $files[0]);
        $this->assertStringNotContainsString('://', $files[0]);

        $download = $this->actingAs($this->steward, 'web')->get($export['download_url'])->assertOk();
        $download->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $csv = $download->streamedContent();
        $this->assertSame(hash('sha256', $csv), $export['sha256']);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        $this->assertSame(['ingredient_id', 'ingredient_name'], $rows[0]);
        $this->assertSame(2, count($rows[1]));
        $this->assertSame(2, count($rows[2]));
        $this->assertSame(
            [$second['code'], $first['code']],
            array_column(array_slice($rows, 1), 0),
        );
        $this->assertSame(
            [$second['name'], $first['name']],
            array_column(array_slice($rows, 1), 1),
        );
        $this->assertSame(
            "ingredient_id;ingredient_name\n"
                .$second['code'].";\"Mąka; \"\"biała\"\"\"\n"
                .$first['code'].";Żurawina\n",
            $csv,
        );
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $export['export_id'])
            ->where('event_code', 'CSV_CATALOG_EXPORT_CREATED')
            ->where('outcome', 'ACCEPTED')
            ->count());
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $export['export_id'])
            ->where('event_code', 'CSV_CATALOG_EXPORT_DOWNLOADED')
            ->where('outcome', 'ACCEPTED')
            ->count());
    }

    public function test_catalog_csv_export_rejects_formula_risk_names_without_creating_a_file(): void
    {
        Storage::fake('raw_material_csv_exports');
        $categoryId = $this->category()['id'];
        $materials = [];
        foreach ([' =1+1', '+1', '-1', '@SUM(A1:A2)', "\tvalue", "\x01value"] as $name) {
            $materials[] = $this->activeCsvWorkflowMaterial($name, categoryId: $categoryId);
        }
        $this->selectRawMaterialApiContext($this->steward);

        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'RAW_MATERIAL_CSV_EXPORT_FORMULA_RISK')
            ->assertJsonPath('error.details.ingredient_ids', array_column($materials, 'code'));

        $this->assertSame([], Storage::disk('raw_material_csv_exports')->allFiles());
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('event_code', 'CSV_CATALOG_EXPORT_REJECTED')
            ->where('reason_code', 'FORMULA_RISK_NAME')
            ->where('outcome', 'REJECTED')
            ->count());
    }

    public function test_catalog_csv_export_requires_view_permission_and_reauthorizes_download(): void
    {
        Storage::fake('raw_material_csv_exports');
        $this->activeCsvWorkflowMaterial('Widoczny składnik');
        $viewer = $this->user('raw-material-catalog-reader@example.test');
        app(MembershipService::class)->assign($viewer, $this->location);
        $grant = app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $viewer,
            RawMaterialPermission::View,
            'Eksport zatwierdzonego katalogu',
        );
        $this->selectRawMaterialApiContext($viewer);
        $export = $this->actingAs($viewer, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertCreated()
            ->json('data');

        app(OrganizationRawMaterialPermissionAdministration::class)->revoke(
            $this->steward,
            $this->location,
            $grant['id'],
            'Wycofanie dostępu do katalogu',
        );
        $this->selectRawMaterialApiContext($viewer);
        $this->actingAs($viewer, 'web')
            ->get($export['download_url'])
            ->assertForbidden();
        $this->actingAs($viewer, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertForbidden();

        $this->assertSame(0, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $export['export_id'])
            ->where('event_code', 'CSV_CATALOG_EXPORT_DOWNLOADED')
            ->count());
    }

    public function test_catalog_csv_export_limits_leave_no_partial_file_and_readiness_gate_blocks_export(): void
    {
        Storage::fake('raw_material_csv_exports');
        $this->activeCsvWorkflowMaterial('Składnik testu limitu');
        $this->selectRawMaterialApiContext($this->steward);
        config(['raw_material_csv.export_max_rows' => 0]);

        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'RAW_MATERIAL_CSV_EXPORT_ROW_LIMIT');
        $this->assertSame([], Storage::disk('raw_material_csv_exports')->allFiles());

        config(['raw_material_csv.export_max_rows' => 5000]);
        config(['raw_material_csv.export_max_bytes' => 1]);

        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'RAW_MATERIAL_CSV_EXPORT_SIZE_LIMIT');
        $this->assertSame([], Storage::disk('raw_material_csv_exports')->allFiles());

        config(['raw_material_csv.export_max_bytes' => 5 * 1024 * 1024]);
        $export = $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertCreated()
            ->json('data');
        $referenceExport = $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => '2026-01-01'])
            ->assertCreated()
            ->json('data');
        $this->app->instance(ModuleCatalog::class, new DefinedModuleCatalog([
            new ModuleDefinition(
                'RAW_MATERIALS',
                'Surowce',
                'RAW_MATERIALS_VIEW',
                [LocationType::CentralKitchen, LocationType::Restaurant],
            ),
        ]));

        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports/extended')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => '2026-01-01'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
        $this->actingAs($this->steward, 'web')
            ->get($export['download_url'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
        $this->actingAs($this->steward, 'web')
            ->get($referenceExport['download_url'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
    }

    public function test_extended_catalog_export_contains_exact_approved_snapshot_and_category_path(): void
    {
        Storage::fake('raw_material_csv_exports');
        $root = $this->category();
        $parent = app(RawMaterialService::class)->createCategory($this->steward, $this->location, [
            'code' => 'GRAINS',
            'name' => 'Zboża',
            'parent_id' => $root['id'],
        ]);
        $leaf = app(RawMaterialService::class)->createCategory($this->steward, $this->location, [
            'code' => 'FLOUR',
            'name' => 'Mąki',
            'parent_id' => $parent['id'],
        ]);
        $raw = $this->activeCsvWorkflowMaterial(
            'Mąka; "biała"',
            categoryId: $leaf['id'],
            code: 'RAW-000123',
            description: 'Opis; dokładny "cytat"',
        );
        $sur = $this->activeCsvWorkflowMaterial('Żurawina', categoryId: $leaf['id']);
        $this->rawMaterialService->revise($this->steward, $this->location, $raw['id'], [
            'category_id' => $root['id'],
            'name' => 'Nazwa oczekującej rewizji',
            'description' => 'Tajny opis roboczy',
            'base_unit_code' => 'g',
        ]);
        $this->selectRawMaterialApiContext($this->steward);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $export = $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports/extended')
            ->assertCreated()
            ->assertJsonPath('data.row_count', 2)
            ->json('data');
        $catalogQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from "raw_materials" as "materials"'),
        );
        DB::disableQueryLog();
        $this->assertCount(1, $catalogQueries, 'Zatwierdzony snapshot i ścieżka kategorii muszą pochodzić z jednego zapytania.');
        $this->assertContains($this->steward->organization_id, $catalogQueries->first()['bindings']);
        $this->assertSame(
            '/api/v1/raw-materials/catalog-exports/extended/'.$export['export_id'].'/download',
            $export['download_url'],
        );
        $this->assertCount(1, Storage::disk('raw_material_csv_exports')->allFiles());

        $download = $this->actingAs($this->steward, 'web')
            ->get($export['download_url'])
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $csv = $download->streamedContent();
        $this->assertSame(hash('sha256', $csv), $export['sha256']);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        $this->assertSame([
            'sku',
            'entity_type',
            'name',
            'description',
            'category_code',
            'category_path_codes',
            'base_unit_code',
            'approved_revision_no',
        ], $rows[0]);
        $this->assertSame([
            $raw['code'],
            'RAW',
            'Mąka; "biała"',
            'Opis; dokładny "cytat"',
            'FLOUR',
            'DRY_GOODS/GRAINS/FLOUR',
            'g',
            '1',
        ], $rows[1]);
        $this->assertSame([
            $sur['code'],
            'RAW',
            'Żurawina',
            '',
            'FLOUR',
            'DRY_GOODS/GRAINS/FLOUR',
            'g',
            '1',
        ], $rows[2]);
        $this->assertStringContainsString('Mąka; ""biała""', $csv);
        $this->assertStringContainsString('Opis; dokładny ""cytat""', $csv);
        $this->assertStringNotContainsString('Nazwa oczekującej rewizji', $csv);
        $this->assertStringNotContainsString('Tajny opis roboczy', $csv);
        $this->assertStringNotContainsString($raw['id'], $csv);
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $export['export_id'])
            ->where('event_code', 'CSV_CATALOG_EXTENDED_EXPORT_CREATED')
            ->where('outcome', 'ACCEPTED')
            ->count());
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $export['export_id'])
            ->where('event_code', 'CSV_CATALOG_EXTENDED_EXPORT_DOWNLOADED')
            ->where('outcome', 'ACCEPTED')
            ->count());
    }

    public function test_extended_catalog_export_rejects_formula_risk_fields_without_creating_a_file(): void
    {
        Storage::fake('raw_material_csv_exports');
        $categoryId = $this->category()['id'];
        $unsafe = $this->activeCsvWorkflowMaterial(
            'Bezpieczna nazwa',
            categoryId: $categoryId,
            description: " \t=HYPERLINK(\"https://example.invalid\")",
        );
        $this->selectRawMaterialApiContext($this->steward);

        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports/extended')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'RAW_MATERIAL_CSV_EXPORT_FORMULA_RISK')
            ->assertJsonPath('error.details.skus.0', $unsafe['code'])
            ->assertJsonPath('error.details.rejected_records.0.identifier', $unsafe['code'])
            ->assertJsonPath('error.details.rejected_records.0.fields.0', 'description');
        $this->assertSame([], Storage::disk('raw_material_csv_exports')->allFiles());
    }

    public function test_extended_catalog_export_reauthorizes_download(): void
    {
        Storage::fake('raw_material_csv_exports');
        $categoryId = $this->category()['id'];
        $this->activeCsvWorkflowMaterial('Bezpieczna pozycja', categoryId: $categoryId);
        $viewer = $this->user('raw-material-extended-reader@example.test');
        app(MembershipService::class)->assign($viewer, $this->location);
        $grant = app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $viewer,
            RawMaterialPermission::View,
            'Odczyt rozszerzonego katalogu',
        );
        $this->selectRawMaterialApiContext($viewer);
        $export = $this->actingAs($viewer, 'web')
            ->postJson('/api/v1/raw-materials/catalog-exports/extended')
            ->assertCreated()
            ->json('data');
        app(OrganizationRawMaterialPermissionAdministration::class)->revoke(
            $this->steward,
            $this->location,
            $grant['id'],
            'Wycofanie dostępu do katalogu',
        );
        $this->selectRawMaterialApiContext($viewer);
        $this->actingAs($viewer, 'web')
            ->get($export['download_url'])
            ->assertForbidden();
        $this->assertSame(0, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $export['export_id'])
            ->where('event_code', 'CSV_CATALOG_EXTENDED_EXPORT_DOWNLOADED')
            ->count());
    }

    public function test_reference_price_export_uses_independent_as_of_histories_and_exact_decimals(): void
    {
        Storage::fake('raw_material_csv_exports');
        $categoryId = $this->category()['id'];
        $material = $this->activeCsvWorkflowMaterial(
            'Mąka referencyjna',
            categoryId: $categoryId,
            code: 'RAW-000123',
        );
        $withoutHistory = $this->activeCsvWorkflowMaterial(
            'Cena bez metadanych VAT',
            categoryId: $categoryId,
            code: 'SUR-000124',
        );
        $unpriced = $this->activeCsvWorkflowMaterial(
            'Surowiec bez historii',
            categoryId: $categoryId,
            code: 'SUR-000125',
        );
        app(RawMaterialCommercialService::class)->setReferencePrice($this->steward, $this->location, $withoutHistory['id'], [
            'event_type' => 'PRICE_SET',
            'effective_from' => '2026-01-02',
            'net_pln_per_base_unit' => '1.23456789012345',
            'reason_code' => 'REFERENCE_EXPORT_TEST',
            'expected_event_no' => 0,
        ], 'reference-export-no-metadata-001');
        $imports = app(RawMaterialCsvImportPersistenceService::class);
        $batches = [
            [
                'key' => 'reference-export-baseline-001',
                'hash' => 'reference-export-baseline',
                'row' => $this->csvImportRow($material, [
                    'effective_date' => '2026-01-01',
                    'price_net' => '4.125',
                    'normalized_net_pln_per_base_unit' => '0.004125',
                    'vat_rate' => '5',
                    'unit' => 'kg',
                ]),
            ],
            [
                'key' => 'reference-export-metadata-001',
                'hash' => 'reference-export-metadata',
                'row' => $this->csvImportRow($material, [
                    'effective_date' => '2026-01-03',
                    'price_net' => '4.125',
                    'normalized_net_pln_per_base_unit' => '0.004125',
                    'vat_rate' => '23',
                    'unit' => 'l',
                ]),
            ],
            [
                'key' => 'reference-export-price-001',
                'hash' => 'reference-export-price',
                'row' => $this->csvImportRow($material, [
                    'effective_date' => '2026-01-04',
                    'price_net' => '5',
                    'normalized_net_pln_per_base_unit' => '0.005',
                    'vat_rate' => '23',
                    'unit' => 'l',
                ]),
            ],
            [
                'key' => 'reference-export-future-001',
                'hash' => 'reference-export-future',
                'row' => $this->csvImportRow($material, [
                    'effective_date' => '2026-01-10',
                    'price_net' => '6',
                    'normalized_net_pln_per_base_unit' => '0.006',
                    'vat_rate' => '5',
                    'unit' => 'kg',
                ]),
            ],
        ];
        foreach ($batches as $batch) {
            $imports->recordPreparedBatch(
                $this->steward,
                $this->location,
                hash('sha256', $batch['hash']),
                $batch['key'],
                [$batch['row']],
            );
        }
        app(RawMaterialCommercialService::class)->setReferencePrice($this->steward, $this->location, $material['id'], [
            'event_type' => 'PRICE_WITHDRAWN',
            'effective_from' => '2026-01-11',
            'reason_code' => 'REFERENCE_EXPORT_TEST_WITHDRAWAL',
            'expected_event_no' => 3,
        ], 'reference-export-withdrawal-001');

        $this->selectRawMaterialApiContext($this->steward);
        $create = function (string $date) {
            $response = $this->actingAs($this->steward, 'web')->postJson(
                '/api/v1/raw-materials/reference-price-exports',
                ['as_of_date' => $date],
            )->assertCreated();
            $export = $response->json('data');
            $this->assertSame($date, $export['as_of_date']);

            return $this->actingAs($this->steward, 'web')
                ->get($export['download_url'])
                ->assertOk()
                ->streamedContent();
        };
        $parse = static function (string $csv): array {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $csv);
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                $rows[] = $row;
            }
            fclose($stream);

            return $rows;
        };
        $beforeBaseline = $parse($create('2025-12-31'));
        $metadataChange = $parse($create('2026-01-03'));
        $priceChange = $parse($create('2026-01-04'));
        $beforeFuture = $parse($create('2026-01-09'));
        $future = $parse($create('2026-01-10'));
        $withdrawn = $parse($create('2026-01-11'));

        $this->assertSame([
            'as_of_date',
            'sku',
            'entity_type',
            'approved_name',
            'category_code',
            'base_unit_code',
            'reference_price_net_pln_per_base_unit',
            'reference_price_effective_from',
            'reference_price_status',
            'vat_rate_percent',
            'vat_input_unit',
            'metadata_effective_from',
            'metadata_status',
        ], $priceChange[0]);
        $this->assertSame([
            '2025-12-31',
            $material['code'],
            'RAW',
            'Mąka referencyjna',
            'DRY_GOODS',
            'g',
            '',
            '',
            'NO_REFERENCE_PRICE',
            '',
            '',
            '',
            'UNKNOWN',
        ], $beforeBaseline[1]);
        $this->assertSame([
            '2026-01-03',
            $material['code'],
            'RAW',
            'Mąka referencyjna',
            'DRY_GOODS',
            'g',
            '0.004125',
            '2026-01-01',
            'KNOWN',
            '23.00',
            'l',
            '2026-01-03',
            'KNOWN',
        ], $metadataChange[1]);
        $this->assertSame('0.005', $priceChange[1][6]);
        $this->assertSame('23.00', $priceChange[1][9], 'Zmiana ceny nie może tworzyć fikcyjnej zmiany metadanych.');
        $this->assertSame('2026-01-03', $priceChange[1][11]);
        $this->assertSame('0.005', $beforeFuture[1][6], 'Zdarzenie przyszłe nie może wpływać na wcześniejszy snapshot.');
        $this->assertSame('0.006', $future[1][6]);
        $this->assertSame('5.00', $future[1][9]);
        $this->assertSame('kg', $future[1][10]);
        $this->assertSame('g', $future[1][5], 'Historyczna jednostka wejściowa nie może zastępować zatwierdzonej jednostki bazowej.');
        $this->assertSame('', $withdrawn[1][6]);
        $this->assertSame('', $withdrawn[1][7]);
        $this->assertSame('NO_REFERENCE_PRICE', $withdrawn[1][8]);
        $this->assertSame('2026-01-10', $withdrawn[1][11]);
        $this->assertSame([
            '2026-01-04',
            $withoutHistory['code'],
            'RAW',
            'Cena bez metadanych VAT',
            'DRY_GOODS',
            'g',
            '1.23456789012345',
            '2026-01-02',
            'KNOWN',
            '',
            '',
            '',
            'UNKNOWN',
        ], $priceChange[2]);
        $this->assertSame([
            '2026-01-04',
            $unpriced['code'],
            'RAW',
            'Surowiec bez historii',
            'DRY_GOODS',
            'g',
            '',
            '',
            'NO_REFERENCE_PRICE',
            '',
            '',
            '',
            'UNKNOWN',
        ], $priceChange[3]);
    }

    public function test_reference_price_export_requires_reference_permission_and_reauthorizes_download(): void
    {
        Storage::fake('raw_material_csv_exports');
        $this->activeCsvWorkflowMaterial('Eksport z cenami');
        $viewer = $this->user('raw-material-price-export-reader@example.test');
        app(MembershipService::class)->assign($viewer, $this->location);
        $catalogGrant = app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $viewer,
            RawMaterialPermission::View,
            'Odczyt katalogu',
        );
        $this->selectRawMaterialApiContext($viewer);
        $this->actingAs($viewer, 'web')
            ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => '2026-01-01'])
            ->assertForbidden();

        app(OrganizationRawMaterialPermissionAdministration::class)->revoke(
            $this->steward,
            $this->location,
            $catalogGrant['id'],
            'Wycofanie odczytu katalogu',
        );
        $priceGrant = app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $viewer,
            RawMaterialPermission::ReferencePriceView,
            'Odczyt cen referencyjnych',
        );
        $this->selectRawMaterialApiContext($viewer);
        $export = $this->actingAs($viewer, 'web')
            ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => '2026-01-01'])
            ->assertCreated()
            ->json('data');
        app(OrganizationRawMaterialPermissionAdministration::class)->revoke(
            $this->steward,
            $this->location,
            $priceGrant['id'],
            'Wycofanie odczytu cen',
        );
        $this->selectRawMaterialApiContext($viewer);
        $this->actingAs($viewer, 'web')
            ->get($export['download_url'])
            ->assertForbidden();
        $this->actingAs($viewer, 'web')
            ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => '2026-02-30'])
            ->assertUnprocessable();
        $this->assertSame(0, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $export['export_id'])
            ->where('event_code', 'CSV_REFERENCE_PRICE_EXPORT_DOWNLOADED')
            ->count());
    }

    public function test_reference_price_export_rejects_formula_values_without_creating_a_file(): void
    {
        Storage::fake('raw_material_csv_exports');
        $categoryId = $this->category()['id'];
        $this->activeCsvWorkflowMaterial('=HYPERLINK("https://example.invalid")', categoryId: $categoryId);
        $this->selectRawMaterialApiContext($this->steward);

        $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => '2026-01-01'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_FORMULA_RISK')
            ->assertJsonPath('error.details.rejected_records.0.fields.0', 'approved_name');
        $this->assertSame([], Storage::disk('raw_material_csv_exports')->allFiles());
    }

    public function test_reference_price_export_expired_private_manifest_is_not_downloadable(): void
    {
        Storage::fake('raw_material_csv_exports');
        $categoryId = $this->category()['id'];
        $this->activeCsvWorkflowMaterial('Bezpieczna nazwa', categoryId: $categoryId);
        $this->selectRawMaterialApiContext($this->steward);
        config(['raw_material_csv.export_ttl_minutes' => 1]);
        $export = $this->actingAs($this->steward, 'web')
            ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => '2026-01-01'])
            ->assertCreated()
            ->json('data');
        Cache::forget('raw-material-csv-catalog-export:'.$this->steward->organization_id.':'.$this->steward->id.':'.$export['export_id']);
        $this->actingAs($this->steward, 'web')
            ->get($export['download_url'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RAW_MATERIAL_REFERENCE_PRICE_EXPORT_NOT_FOUND');
        $this->assertCount(1, Storage::disk('raw_material_csv_exports')->allFiles(), 'Prywatny plik nie może mieć publicznego URL ani pobrania bez manifestu.');
    }

    public function test_reference_price_export_isolates_two_organizations(): void
    {
        Storage::fake('raw_material_csv_exports');
        $categoryId = $this->category()['id'];
        $local = $this->activeCsvWorkflowMaterial('Materiał lokalny', categoryId: $categoryId);
        $this->withForeignOrganizationFixture(function (Location $foreignLocation) use ($local): void {
            $foreignActorId = Str::uuid()->toString();
            $foreignMaterialId = Str::uuid()->toString();
            $foreignRevisionId = Str::uuid()->toString();
            $foreignCategoryId = Str::uuid()->toString();
            $foreignSku = 'SUR-000001';
            $operationId = Str::uuid()->toString();
            $contentHash = str_repeat('a', 64);
            try {
                $this->withSetupTransaction(function (Connection $setup) use (
                    $foreignLocation,
                    $foreignActorId,
                    $foreignMaterialId,
                    $foreignRevisionId,
                    $foreignCategoryId,
                    $foreignSku,
                    $operationId,
                    $contentHash,
                ): void {
                    $setup->table('users')->insert([
                        'id' => $foreignActorId,
                        'organization_id' => $foreignLocation->organization_id,
                        'first_name' => 'Obcy',
                        'last_name' => 'Użytkownik',
                        'email' => 'reference-export-foreign@example.test',
                        'password' => self::PASSWORD,
                        'is_active' => true,
                        'email_verified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $setup->table('organization_sku_counters')->insert([
                        'organization_id' => $foreignLocation->organization_id,
                        'prefix' => 'SUR',
                        'last_value' => 0,
                    ]);
                    $setup->table('organization_sku_assignments')->insert([
                        'organization_id' => $foreignLocation->organization_id,
                        'sku' => $foreignSku,
                        'prefix' => 'SUR',
                        'sequence_number' => 1,
                        'entity_type' => 'RAW',
                        'entity_id' => $foreignMaterialId,
                        'allocation_source' => 'LEGACY',
                        'assigned_by' => $foreignActorId,
                    ]);
                    $setup->table('organization_sku_counters')
                        ->where('organization_id', $foreignLocation->organization_id)
                        ->where('prefix', 'SUR')
                        ->update(['last_value' => 1]);
                    $setup->table('raw_material_categories')->insert([
                        'id' => $foreignCategoryId,
                        'organization_id' => $foreignLocation->organization_id,
                        'code' => 'FOREIGN',
                        'name' => 'Obca kategoria',
                        'depth' => 1,
                        'created_by' => $foreignActorId,
                    ]);
                    $setup->table('raw_materials')->insert([
                        'id' => $foreignMaterialId,
                        'organization_id' => $foreignLocation->organization_id,
                        'code' => $foreignSku,
                        'lifecycle_state' => 'DRAFT',
                        'working_revision_id' => $foreignRevisionId,
                        'created_by' => $foreignActorId,
                    ]);
                    $setup->table('raw_material_revisions')->insert([
                        'id' => $foreignRevisionId,
                        'organization_id' => $foreignLocation->organization_id,
                        'raw_material_id' => $foreignMaterialId,
                        'revision_no' => 1,
                        'category_id' => $foreignCategoryId,
                        'name' => 'Nieudostępniony materiał',
                        'base_unit_code' => 'kg',
                        'base_unit_dimension' => 'MASS',
                        'allergen_state' => 'DECLARED',
                        'critical_change' => false,
                        'created_by' => $foreignActorId,
                        'content_hash' => $contentHash,
                    ]);
                    $setup->table('raw_material_revision_units')->insert([
                        'id' => Str::uuid()->toString(),
                        'organization_id' => $foreignLocation->organization_id,
                        'revision_id' => $foreignRevisionId,
                        'unit_code' => 'kg',
                        'dimension' => 'MASS',
                        'conversion_kind' => 'BASE',
                        'to_base_factor' => '1.000000000000',
                    ]);
                    $setup->table('raw_materials')->where('id', $foreignMaterialId)->update([
                        'lifecycle_state' => 'VERIFICATION',
                        'pending_revision_id' => $foreignRevisionId,
                        'working_revision_id' => null,
                    ]);
                    $setup->table('raw_material_revision_approval_events')->insert([
                        'id' => Str::uuid()->toString(),
                        'organization_id' => $foreignLocation->organization_id,
                        'revision_id' => $foreignRevisionId,
                        'operation_id' => Str::uuid()->toString(),
                        'outcome' => 'APPROVED',
                        'actor_user_id' => $foreignActorId,
                        'reason' => 'Fixture izolacji tenantów',
                        'content_hash' => $contentHash,
                    ]);
                    $setup->table('raw_materials')->where('id', $foreignMaterialId)->update([
                        'lifecycle_state' => 'ACTIVE',
                        'approved_revision_id' => $foreignRevisionId,
                        'pending_revision_id' => null,
                    ]);
                    $setup->table('raw_material_reference_price_events')->insert([
                        'id' => Str::uuid()->toString(),
                        'organization_id' => $foreignLocation->organization_id,
                        'raw_material_id' => $foreignMaterialId,
                        'event_no' => 1,
                        'event_type' => 'PRICE_SET',
                        'effective_from' => '2026-01-01',
                        'net_pln_per_base_unit' => '88.88888888888888',
                        'reason_code' => 'TENANT_ISOLATION_FIXTURE',
                        'recorded_by' => $foreignActorId,
                        'operation_id' => $operationId,
                    ]);
                });

                $this->assertSame(2, DB::table('organizations')->count());
                $this->selectRawMaterialApiContext($this->steward);
                $export = $this->actingAs($this->steward, 'web')
                    ->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => '2026-01-02'])
                    ->assertCreated()
                    ->json('data');
                $csv = $this->actingAs($this->steward, 'web')->get($export['download_url'])->assertOk()->streamedContent();
                $this->assertStringContainsString($local['code'], $csv);
                $this->assertStringNotContainsString($foreignSku, $csv);
                $this->assertStringNotContainsString('Nieudostępniony materiał', $csv);
                $this->assertStringNotContainsString('88.88888888888888', $csv);
            } finally {
                $this->resetTestDatabase();
            }
        });
    }

    public function test_view_only_reader_cannot_discover_drafts_or_pending_revisions(): void
    {
        $category = $this->category();
        $draft = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Tajny szkic',
            'description' => 'Nieopublikowana treść',
            'base_unit_code' => 'g',
        ]);
        $activeId = $this->declaredMaterial($category['id'], 'Materiał zatwierdzony');
        $this->rawMaterialService->submit($this->steward, $this->location, $activeId);
        $this->rawMaterialService->approve($this->steward, $this->location, $activeId, 'Pierwsze zatwierdzenie');
        $this->rawMaterialService->revise($this->steward, $this->location, $activeId, [
            'category_id' => $category['id'],
            'name' => 'Oczekująca poufna zmiana',
            'description' => 'Nieopublikowany opis',
            'base_unit_code' => 'g',
        ]);
        $this->rawMaterialService->submit($this->steward, $this->location, $activeId);

        $viewer = $this->user('raw-material-view-only@example.test');
        app(MembershipService::class)->assign($viewer, $this->location);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $viewer,
            RawMaterialPermission::View,
            'Odczyt zatwierdzonego katalogu',
        );
        $this->selectContext($viewer);

        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['name' => 'Tajny szkic'])
            ->assertJsonMissing(['name' => 'Oczekująca poufna zmiana']);
        $this->getJson('/api/v1/raw-materials?search=Tajny%20szkic')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials?search=Oczekująca%20poufna%20zmiana')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials?category_id='.$category['id'].'&lifecycle_state=DRAFT')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials/'.$draft['id'])->assertNotFound()
            ->assertJsonPath('error.code', 'RAW_MATERIAL_NOT_FOUND')
            ->assertJsonMissing(['description' => 'Nieopublikowana treść']);
        $this->getJson('/api/v1/raw-materials/'.$activeId)->assertOk()
            ->assertJsonPath('data.revision.name', 'Materiał zatwierdzony')
            ->assertJsonMissing(['description' => 'Nieopublikowany opis']);
        $this->getJson('/api/v1/raw-materials/'.$activeId.'/revisions')->assertForbidden();
        $this->getJson('/api/v1/raw-materials/'.Str::uuid())->assertNotFound()
            ->assertJsonPath('error.code', 'RAW_MATERIAL_NOT_FOUND');
    }

    public function test_draft_can_be_edited_and_returned_for_changes_without_mutating_prior_revisions(): void
    {
        $category = $this->category();
        $material = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Szkic do edycji',
            'base_unit_code' => 'g',
        ]);
        $originalRevisionId = $material['revision']['id'];
        $this->selectContext($this->steward);
        $this->patchJson('/api/v1/raw-materials/'.$material['id'].'/draft', [
            'category_id' => $category['id'],
            'name' => 'Poprawiony szkic',
            'description' => 'Pierwsza edycja',
            'base_unit_code' => 'g',
        ])->assertOk()->assertJsonPath('data.revision.revision_no', 2)
            ->assertJsonPath('data.revision.name', 'Poprawiony szkic');
        $this->assertSame('Szkic do edycji', DB::table('raw_material_revisions')
            ->where('id', $originalRevisionId)->value('name'));
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id']);
        $this->rawMaterialService->returnForChanges(
            $this->steward,
            $this->location,
            $material['id'],
            'Doprecyzuj opis',
        );
        $returnedRevisionId = DB::table('raw_materials')->where('id', $material['id'])->value('working_revision_id');
        $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $category['id'],
            'name' => 'Poprawiony szkic ponownie',
            'description' => 'Druga edycja',
            'base_unit_code' => 'g',
        ]);

        $revisions = $this->rawMaterialService->revisions($this->steward, $this->location, $material['id']);
        $this->assertCount(3, $revisions);
        $this->assertSame('DRAFT', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
        $this->assertNotSame(
            $returnedRevisionId,
            DB::table('raw_materials')->where('id', $material['id'])->value('working_revision_id'),
        );
        $this->assertSame(2, DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->where('event_code', 'REVISION_CREATED')->count());
    }

    public function test_editing_an_active_material_keeps_approved_snapshot_until_reapproval(): void
    {
        $category = $this->category();
        $materialId = $this->declaredMaterial($category['id'], 'Materiał przed zmianą');
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Pierwsze zatwierdzenie');
        $approvedRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id');

        $this->rawMaterialService->revise($this->steward, $this->location, $materialId, [
            'category_id' => $category['id'],
            'name' => 'Materiał po zmianie',
            'description' => 'Zmiana oczekuje na zatwierdzenie',
            'base_unit_code' => 'g',
        ]);
        $unattestedRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('working_revision_id');
        $this->assertNotSame($approvedRevisionId, $unattestedRevisionId);
        $this->assertSame('DECLARED', DB::table('raw_material_revisions')
            ->where('id', $unattestedRevisionId)->value('allergen_state'));
        $this->assertSame('Materiał przed zmianą', $this->rawMaterialService
            ->show($this->steward, $this->location, $materialId)['revision']['name']);

        $this->attestWorkingRevision($materialId);
        $pendingRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('working_revision_id');
        $this->assertNotSame($unattestedRevisionId, $pendingRevisionId);
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->assertSame($approvedRevisionId, DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id'));
        $this->assertSame($pendingRevisionId, DB::table('raw_materials')->where('id', $materialId)->value('pending_revision_id'));
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Ponowne zatwierdzenie');

        $this->assertSame($pendingRevisionId, DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id'));
        $this->assertSame('Materiał przed zmianą', DB::table('raw_material_revisions')
            ->where('id', $approvedRevisionId)->value('name'));
        $this->assertSame(2, DB::table('raw_material_revision_approval_events')
            ->whereIn('revision_id', [$approvedRevisionId, $pendingRevisionId])
            ->where('outcome', 'APPROVED')->count());
    }

    public function test_recipient_reads_only_the_current_approved_revision_and_its_documents(): void
    {
        Storage::fake('raw_material_documents');
        $category = $this->category();
        $material = $this->activeCsvImportMaterial('Zatwierdzona wersja V1', $category['id']);
        $v1Id = DB::table('raw_materials')->where('id', $material['id'])->value('approved_revision_id');
        $restaurant = $this->activeRestaurant('RREVISION');
        $secondRestaurant = $this->activeRestaurant('RREVISION2');
        app(MembershipService::class)->assign($this->steward, $restaurant);
        app(MembershipService::class)->assign($this->steward, $secondRestaurant);
        foreach ([$restaurant, $secondRestaurant] as $recipientLocation) {
            LocationModuleState::on('pgsql_setup')->create([
                'organization_id' => $this->steward->organization_id,
                'location_id' => $recipientLocation->id,
                'module_key' => 'RAW_MATERIALS',
                'enabled' => true,
                'created_by' => $this->bootstrapAdministrator->id,
                'updated_by' => $this->bootstrapAdministrator->id,
            ]);
            app(RawMaterialLocationShareAdministration::class)->share(
                $this->bootstrapAdministrator,
                $this->location,
                $material['id'],
                $recipientLocation->id,
            );
        }
        $documents = app(RawMaterialDocumentService::class);
        $v1Document = $documents->create(
            $this->steward,
            $this->location,
            $v1Id,
            'OTHER',
            'zatwierdzona-v1.txt',
            'synthetic approved v1',
        );
        $commercial = app(RawMaterialCommercialService::class);
        $supplierId = $commercial->createSupplier($this->steward, $this->location, [
            'supplier_code' => 'REVISION_TEST',
            'display_name' => 'Dostawca testu rewizji',
        ], 'revision-test-supplier-001')['data']['id'];
        $productId = $commercial->createProduct($this->steward, $this->location, $material['id'], [
            'supplier_id' => $supplierId,
            'raw_material_revision_id' => $v1Id,
            'product_name' => 'Produkt powiązany z V1',
            'supplier_sku' => 'REV-V1',
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '1',
            'content_unit_code' => 'g',
        ], 'revision-test-product-001')['data']['id'];

        $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $category['id'],
            'name' => 'Poufna wersja V2',
            'description' => 'Treść oczekująca na zatwierdzenie',
            'base_unit_code' => 'g',
        ]);
        $unattestedV2Id = DB::table('raw_materials')->where('id', $material['id'])->value('working_revision_id');
        $this->assertSame($v1Id, $this->rawMaterialService
            ->show($this->steward, $restaurant, $material['id'])['revision']['id']);
        $this->assertSame([$v1Id], array_column(
            $this->rawMaterialService->revisions($this->steward, $restaurant, $material['id']),
            'id',
        ));
        try {
            $this->rawMaterialService->revisionStage2d(
                $this->steward,
                $restaurant,
                $material['id'],
                $unattestedV2Id,
            );
            $this->fail('A recipient read Stage 2D data for a draft revision.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_REVISION_NOT_FOUND', $exception->errorCode);
        }
        $this->attestWorkingRevision($material['id']);
        $v2Id = DB::table('raw_materials')->where('id', $material['id'])->value('working_revision_id');
        $v2Document = $documents->create(
            $this->steward,
            $this->location,
            $v2Id,
            'OTHER',
            'niezatwierdzona-v2.txt',
            'synthetic pending v2',
        );
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id']);

        $this->assertSame([$v2Id, $unattestedV2Id, $v1Id], array_column(
            $this->rawMaterialService->revisions($this->steward, $this->location, $material['id']),
            'id',
        ));
        $this->assertSame('Zatwierdzona wersja V1', $this->rawMaterialService
            ->show($this->steward, $this->location, $material['id'])['revision']['name']);
        $this->assertSame([$v2Id, $unattestedV2Id, $v1Id], array_column(
            $this->rawMaterialService->revisions($this->steward, $this->location, $material['id']),
            'id',
        ));

        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $restaurant->id,
        ])->assertOk();
        $this->getJson('/api/v1/raw-materials/'.$material['id'])
            ->assertOk()->assertJsonPath('data.revision.id', $v1Id)
            ->assertJsonPath('data.revision.name', 'Zatwierdzona wersja V1')
            ->assertJsonMissing(['name' => 'Poufna wersja V2']);
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/revisions')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $v1Id)
            ->assertJsonMissing(['name' => 'Poufna wersja V2']);
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/revisions/'.$v1Id.'/stage2d')->assertOk();
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/revisions/'.$v2Id.'/stage2d')
            ->assertNotFound()->assertJsonPath('error.code', 'RAW_MATERIAL_REVISION_NOT_FOUND');
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/supplier-products')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.current_revision.product_name', 'Produkt powiązany z V1');
        $this->assertSame($v1Id, $documents->verifyVersion(
            $this->steward,
            $restaurant,
            $v1Document['version']['id'],
        )['raw_material_revision_id']);
        try {
            $documents->verifyVersion($this->steward, $restaurant, $v2Document['version']['id']);
            $this->fail('A recipient read a document linked to an unapproved revision.');
        } catch (RawMaterialDocumentException $exception) {
            $this->assertSame('RAW_MATERIAL_DOCUMENT_VERSION_NOT_FOUND', $exception->errorCode);
        }
        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $secondRestaurant->id,
        ])->assertOk();
        $this->getJson('/api/v1/raw-materials/'.$material['id'])
            ->assertOk()->assertJsonPath('data.revision.id', $v1Id);

        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ])->assertOk();
        $this->assertSame([$v2Id, $unattestedV2Id, $v1Id], array_column(
            $this->rawMaterialService->revisions($this->steward, $this->location, $material['id']),
            'id',
        ));
        $this->rawMaterialService->returnForChanges(
            $this->steward,
            $this->location,
            $material['id'],
            'Regresja rewizji oczekującej',
        );
        $this->assertSame($v1Id, DB::table('raw_materials')->where('id', $material['id'])->value('approved_revision_id'));
        try {
            $this->rawMaterialService->approve($this->steward, $this->location, $material['id'], 'Nieprawidłowa próba');
            $this->fail('A returned revision was approved without resubmission.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_APPROVAL_INVALID', $exception->errorCode);
        }
        $this->assertSame($v1Id, $this->rawMaterialService
            ->show($this->steward, $restaurant, $material['id'])['revision']['id']);
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id']);
        $this->rawMaterialService->approve($this->steward, $this->location, $material['id'], 'Zatwierdzenie wersji V2');
        $this->assertSame($v2Id, DB::table('raw_materials')->where('id', $material['id'])->value('approved_revision_id'));
        $this->assertSame([$v1Id], array_column(
            $commercial->productRevisions($this->steward, $this->location, $productId)['data'],
            'raw_material_revision_id',
        ));

        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $restaurant->id,
        ])->assertOk();
        $this->getJson('/api/v1/raw-materials/'.$material['id'])
            ->assertOk()->assertJsonPath('data.revision.id', $v2Id)
            ->assertJsonPath('data.revision.name', 'Poufna wersja V2');
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/revisions')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $v2Id);
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/revisions/'.$v2Id.'/stage2d')->assertOk();
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/revisions/'.$v1Id.'/stage2d')
            ->assertNotFound()->assertJsonPath('error.code', 'RAW_MATERIAL_REVISION_NOT_FOUND');
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/supplier-products')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials/supplier-products/'.$productId.'/revisions')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($v2Id, $documents->verifyVersion(
            $this->steward,
            $restaurant,
            $v2Document['version']['id'],
        )['raw_material_revision_id']);
        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $secondRestaurant->id,
        ])->assertOk();
        $this->getJson('/api/v1/raw-materials/'.$material['id'])
            ->assertOk()->assertJsonPath('data.revision.id', $v2Id);
    }

    public function test_revision_approval_and_recipient_reads_are_serialized_consistently(): void
    {
        $category = $this->category();
        $material = $this->activeCsvImportMaterial('Materiał do wyścigu akceptacji', $category['id']);
        $v1Id = DB::table('raw_materials')->where('id', $material['id'])->value('approved_revision_id');
        $restaurant = $this->activeRestaurant('RREVISION_CONC');
        app(MembershipService::class)->assign($this->steward, $restaurant);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $restaurant->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id,
            'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        app(RawMaterialLocationShareAdministration::class)->share(
            $this->bootstrapAdministrator,
            $this->location,
            $material['id'],
            $restaurant->id,
        );
        $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $category['id'],
            'name' => 'Materiał V2',
            'base_unit_code' => 'g',
        ]);
        $this->attestWorkingRevision($material['id']);
        $v2Id = DB::table('raw_materials')->where('id', $material['id'])->value('working_revision_id');
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id']);

        $processes = [];
        try {
            DB::beginTransaction();
            DB::table('organizations')->where('id', $this->steward->organization_id)
                ->lockForUpdate()->firstOrFail();
            foreach ([1, 2] as $index) {
                [$process, $applicationName] = $this->startRevisionProcess(
                    'approve',
                    $this->steward,
                    $this->location,
                    $material['id'],
                    null,
                    'raw-approve-'.$index.'-',
                );
                $processes[] = [$process, $applicationName];
                $this->assertRevisionProcessWaitingOnOrganizationLock($process, $applicationName);
            }
            DB::commit();
            $approvalResults = array_map(fn (array $pair): array => $this->finishRevisionProcess($pair[0]), $processes);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as [$process]) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
        $statuses = array_column($approvalResults, 'status');
        sort($statuses);
        $this->assertSame(['approved', 'denied'], $statuses);
        $this->assertSame(1, count(array_filter(
            $approvalResults,
            fn (array $result): bool => ($result['code'] ?? null) === 'RAW_MATERIAL_APPROVAL_INVALID',
        )));
        $this->assertSame($v2Id, DB::table('raw_materials')->where('id', $material['id'])->value('approved_revision_id'));
        $this->assertSame(2, DB::table('raw_material_revision_approval_events')
            ->whereIn('revision_id', [$v1Id, $v2Id])->where('outcome', 'APPROVED')->count());

        $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $category['id'],
            'name' => 'Materiał V3',
            'base_unit_code' => 'g',
        ]);
        $this->attestWorkingRevision($material['id']);
        $v3Id = DB::table('raw_materials')->where('id', $material['id'])->value('working_revision_id');
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id']);

        $processes = [];
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $this->steward->organization_id)
                ->lockForUpdate()->firstOrFail();
            [$approval, $approvalName] = $this->startRevisionProcess(
                'approve',
                $this->steward,
                $this->location,
                $material['id'],
                null,
                'raw-read-approve-',
            );
            $processes[] = [$approval, $approvalName];
            $this->assertRevisionProcessWaitingOnOrganizationLock($approval, $approvalName);
            [$read, $readName] = $this->startRevisionProcess(
                'read',
                $this->steward,
                $restaurant,
                $material['id'],
                null,
                'raw-read-before-approve-',
            );
            $processes[] = [$read, $readName];
            $readResult = $this->finishRevisionProcess($read);
            $this->assertSame('read', $readResult['status']);
            $this->assertSame($v2Id, $readResult['data']['revision']['id']);
            $this->assertSame('Materiał V2', $readResult['data']['revision']['name']);
            DB::commit();
            $this->assertSame('approved', $this->finishRevisionProcess($approval)['status']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as [$process]) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
        $this->assertSame($v3Id, DB::table('raw_materials')->where('id', $material['id'])->value('approved_revision_id'));

        $processes = [];
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $this->steward->organization_id)
                ->lockForUpdate()->firstOrFail();
            [$revoke, $revokeName] = $this->startRevisionProcess(
                'revoke',
                $this->bootstrapAdministrator,
                $this->location,
                $material['id'],
                $restaurant->id,
                'raw-revoke-race-',
            );
            $processes[] = [$revoke, $revokeName];
            $this->assertRevisionProcessWaitingOnOrganizationLock($revoke, $revokeName);
            [$read, $readName] = $this->startRevisionProcess(
                'read',
                $this->steward,
                $restaurant,
                $material['id'],
                null,
                'raw-read-before-revoke-',
            );
            $processes[] = [$read, $readName];
            $readResult = $this->finishRevisionProcess($read);
            $this->assertSame('read', $readResult['status']);
            $this->assertSame($v3Id, $readResult['data']['revision']['id']);
            DB::commit();
            $this->assertSame('revoked', $this->finishRevisionProcess($revoke)['status']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as [$process]) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
        [$readAfterRevoke] = $this->startRevisionProcess(
            'read',
            $this->steward,
            $restaurant,
            $material['id'],
            null,
            'raw-read-after-revoke-',
        );
        $this->assertSame('denied', $this->finishRevisionProcess($readAfterRevoke)['status']);
    }

    public function test_duplicate_terms_and_client_supplied_metric_factors_are_rejected_atomically(): void
    {
        $category = $this->category();
        $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Kasza jaglana',
            'base_unit_code' => 'g',
        ]);
        $before = DB::table('raw_materials')->count();

        try {
            $this->rawMaterialService->create($this->steward, $this->location, [
                'category_id' => $category['id'],
                'name' => 'Kasza',
                'base_unit_code' => 'g',
                'aliases' => ['  KASZA   JAGLANA '],
            ]);
            $this->fail('A duplicate alias was accepted.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_DUPLICATE_TERM', $exception->errorCode);
            $this->assertSame(422, $exception->status);
        }
        $this->assertSame($before, DB::table('raw_materials')->count());

        $this->expectException(ValidationException::class);
        $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Kasza gryczana',
            'base_unit_code' => 'kg',
            'conversions' => [['unit_code' => 'g', 'to_base_factor' => '0.5']],
        ]);
    }

    public function test_api_requires_both_module_availability_and_an_organization_grant(): void
    {
        $this->selectContext($this->steward);
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('meta.total', 0);

        $this->app->instance(ModuleCatalog::class, new DefinedModuleCatalog);
        $this->getJson('/api/v1/raw-materials')->assertStatus(503)
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE')
            ->assertJsonPath('error.message', __('messages.raw_materials_module_unavailable'));
        $catalog = $this->getJson('/api/v1/security/modules/catalog')->assertOk()->json('data');
        $rawMaterials = collect($catalog)->firstWhere('module', 'RAW_MATERIALS');
        $this->assertSame('NOT_READY', $rawMaterials['readiness']);
    }

    public function test_location_roles_do_not_bypass_organization_grants(): void
    {
        $manager = $this->user('raw-material-manager@example.test');
        $this->role($manager, BusinessRole::Administrator);
        app(MembershipService::class)->assign($manager, $this->location, $manager->id);
        $this->selectContext($manager);
        $this->getJson('/api/v1/raw-materials')->assertForbidden()
            ->assertJsonPath('message', __('messages.unauthorized'));
    }

    public function test_self_grant_is_rejected_over_the_api(): void
    {
        $this->selectContext($this->steward);
        $this->postJson('/api/v1/raw-materials/permissions/'.$this->steward->id, [
            'permission' => RawMaterialPermission::View->value,
            'reason' => 'Nie wolno nadać sobie prawa',
        ])->assertForbidden();
    }

    public function test_unknown_allergen_state_blocks_approval_and_polish_api_contract_is_stable(): void
    {
        $category = $this->category();
        $material = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Olej rzepakowy',
            'base_unit_code' => 'ml',
        ]);
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id']);
        $this->assertSame('VERIFICATION', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));

        try {
            $this->rawMaterialService->approve($this->steward, $this->location, $material['id'], 'Test akceptacji');
            $this->fail('An unknown allergen declaration was approved.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED', $exception->errorCode);
            $this->assertSame(422, $exception->status);
        }

        $this->selectContext($this->steward);
        $this->postJson('/api/v1/raw-materials', [
            'organization_id' => $this->steward->organization_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('request')
            ->assertJsonPath('message', __('messages.validation_failed'));
    }

    public function test_block_archive_restore_and_reapproval_preserve_identity_and_history(): void
    {
        $category = $this->category();
        $materialId = $this->declaredMaterial($category['id'], 'Sól morska');
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Pierwsza akceptacja');
        $approvedRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id');
        $code = DB::table('raw_materials')->where('id', $materialId)->value('code');

        $this->rawMaterialService->block($this->steward, $this->location, $materialId, 'Kontrola bezpieczeństwa');
        $this->assertSame('BLOCKED', DB::table('raw_materials')->where('id', $materialId)->value('lifecycle_state'));
        $this->rawMaterialService->unblock($this->steward, $this->location, $materialId, 'Zakończono kontrolę');
        $unblockEvent = DB::table('raw_material_audit_events')
            ->where('subject_id', $materialId)->where('event_code', 'UNBLOCKED')->first();
        $this->assertNotNull($unblockEvent);
        $this->assertSame($this->steward->id, $unblockEvent->actor_user_id);
        $this->assertSame('ACCEPTED', $unblockEvent->outcome);
        $this->assertNotNull($unblockEvent->transaction_id);
        $this->assertNotSame($unblockEvent->before_hash, $unblockEvent->after_hash);
        $this->rawMaterialService->archive($this->steward, $this->location, $materialId, 'Wycofanie z katalogu');
        $this->rawMaterialService->restore($this->steward, $this->location, $materialId, 'Ponowna walidacja');
        $this->assertSame('VERIFICATION', DB::table('raw_materials')->where('id', $materialId)->value('lifecycle_state'));
        $this->rawMaterialService->returnForChanges(
            $this->steward,
            $this->location,
            $materialId,
            'Wymagany nowy atest po przywróceniu',
        );
        $returnedMaterial = DB::table('raw_materials')->where('id', $materialId)->first();
        $this->assertSame('ACTIVE', $returnedMaterial->lifecycle_state);
        $this->assertSame($approvedRevisionId, $returnedMaterial->approved_revision_id);
        $this->attestWorkingRevision($materialId);
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Ponowna akceptacja');

        $this->assertSame($code, DB::table('raw_materials')->where('id', $materialId)->value('code'));
        $this->assertSame(3, DB::table('raw_material_revisions')->where('raw_material_id', $materialId)->count());
        $this->assertSame(2, DB::table('raw_material_revision_approval_events as approvals')
            ->join('raw_material_revisions as revisions', 'revisions.id', '=', 'approvals.revision_id')
            ->where('revisions.raw_material_id', $materialId)
            ->where('approvals.outcome', 'APPROVED')->count());
        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $materialId)->value('lifecycle_state'));
        $this->assertSame(10, DB::table('raw_material_audit_events')->where('subject_id', $materialId)->count());
    }

    public function test_unblock_state_and_immutable_audit_record_roll_back_together(): void
    {
        $category = $this->category();
        $materialId = $this->declaredMaterial($category['id'], 'Materiał do cofnięcia transakcji');
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Zatwierdzenie');
        $this->rawMaterialService->block($this->steward, $this->location, $materialId, 'Kontrola');
        $auditCount = DB::table('raw_material_audit_events')->where('subject_id', $materialId)->count();

        try {
            DB::transaction(function () use ($materialId): void {
                $this->rawMaterialService->unblock(
                    $this->steward,
                    $this->location,
                    $materialId,
                    'Operacja zostanie wycofana',
                );
                throw new \RuntimeException('rollback marker');
            });
            $this->fail('Oczekiwano wycofania transakcji testowej.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback marker', $exception->getMessage());
        }

        $this->assertSame('BLOCKED', DB::table('raw_materials')->where('id', $materialId)->value('lifecycle_state'));
        $this->assertSame($auditCount, DB::table('raw_material_audit_events')->where('subject_id', $materialId)->count());
    }

    public function test_supplier_product_and_initial_revision_roll_back_together(): void
    {
        $category = $this->category();
        $materialId = $this->declaredMaterial($category['id'], 'Produkt do cofnięcia transakcji');
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Zatwierdzenie');
        $materialRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id');
        $commercial = app(RawMaterialCommercialService::class);
        $supplierId = $commercial->createSupplier($this->steward, $this->location, [
            'supplier_code' => 'ROLLBACK_SUPPLIER',
            'display_name' => 'Dostawca testowy',
        ], 'rollback-supplier-create-0001')['data']['id'];

        try {
            DB::transaction(function () use ($commercial, $materialId, $supplierId, $materialRevisionId): void {
                $commercial->createProduct($this->steward, $this->location, $materialId, [
                    'supplier_id' => $supplierId,
                    'raw_material_revision_id' => $materialRevisionId,
                    'product_name' => 'Produkt testowy',
                    'supplier_sku' => 'ROLLBACK-1',
                    'purchase_unit_code' => 'PACK',
                    'content_quantity' => '1',
                    'content_unit_code' => 'g',
                ], 'rollback-product-create-0001');
                throw new \RuntimeException('rollback marker');
            });
            $this->fail('Oczekiwano wycofania transakcji testowej.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback marker', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('supplier_products')->where('organization_id', $this->steward->organization_id)
            ->where('raw_material_id', $materialId)->count());
        $this->assertSame(0, DB::table('supplier_product_revisions')->where('organization_id', $this->steward->organization_id)
            ->where('raw_material_id', $materialId)->count());
        $this->assertSame(0, DB::table('raw_materials_commercial_idempotency')
            ->where('idempotency_key', 'rollback-product-create-0001')->count());
    }

    public function test_supplier_business_details_are_preserved_by_archive_workflow(): void
    {
        $commercial = app(RawMaterialCommercialService::class);
        $created = $commercial->createSupplier($this->steward, $this->location, [
            'supplier_code' => 'IMMUTABLE_DETAILS',
            'display_name' => 'Dostawca z danymi stałymi',
            'legal_name' => 'Dostawca z danymi stałymi sp. z o.o.',
            'tax_identifier' => '1234567890',
        ], 'supplier-details-create-0001');
        $supplierId = $created['data']['id'];

        $createdAudit = DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplierId)->where('event_code', 'SUPPLIER_CREATED')->first();
        $this->assertNotNull($createdAudit);
        $this->assertSame($this->steward->id, $createdAudit->actor_user_id);
        $this->assertSame($this->location->id, $createdAudit->actor_location_id);

        $commercial->archiveSupplier(
            $this->steward,
            $this->location,
            $supplierId,
            'Zamknięcie współpracy',
            'supplier-details-archive-0001',
        );

        $supplier = DB::table('organization_suppliers')->where('id', $supplierId)->first();
        $this->assertSame('ARCHIVED', $supplier->lifecycle_state);
        $this->assertSame('Dostawca z danymi stałymi', $supplier->display_name);
        $this->assertSame('Dostawca z danymi stałymi sp. z o.o.', $supplier->legal_name);
        $this->assertNotEmpty($supplier->tax_identifier_ciphertext);
        $archiveAudit = DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplierId)->where('event_code', 'SUPPLIER_ARCHIVED')->first();
        $this->assertNotNull($archiveAudit);
        $this->assertSame($this->steward->id, $archiveAudit->actor_user_id);
        $this->assertSame($this->location->id, $archiveAudit->actor_location_id);
    }

    public function test_supplier_corrections_are_authorized_audited_and_preserve_commercial_references(): void
    {
        $references = $this->createCommercialOfferFixture(
            'Mąka do korekty dostawcy',
            'CORRECTION_01',
            'CORRECTION_SKU_01',
            'supplier-correction',
        );
        $supplierId = $references['supplier_id'];
        $supplierBefore = DB::table('organization_suppliers')->where('id', $supplierId)->first();
        $productBefore = DB::table('supplier_products')->where('id', $references['product_id'])->first();
        $productRevisionsBefore = DB::table('supplier_product_revisions')
            ->where('supplier_product_id', $references['product_id'])->orderBy('revision_no')->get()->toArray();
        $this->selectRawMaterialApiContext($this->steward);

        $this->actingAs($this->steward, 'web')->patchJson(
            '/api/v1/raw-materials/suppliers/'.$supplierId,
            [
                'display_name' => 'Młyn po korekcie',
                'legal_name' => 'Młyn po korekcie sp. z o.o.',
                'tax_identifier' => '9876543210',
                'reason' => 'Korekta danych dostawcy',
            ],
            ['Idempotency-Key' => 'supplier-correction-0001'],
        )->assertOk()
            ->assertJsonPath('data.id', $supplierId)
            ->assertJsonPath('data.supplier_code', 'CORRECTION_01')
            ->assertJsonPath('data.display_name', 'Młyn po korekcie')
            ->assertJsonPath('data.legal_name', 'Młyn po korekcie sp. z o.o.')
            ->assertJsonMissing(['tax_identifier' => '9876543210']);

        $supplierAfter = DB::table('organization_suppliers')->where('id', $supplierId)->first();
        $this->assertSame($supplierBefore->id, $supplierAfter->id);
        $this->assertSame($supplierBefore->organization_id, $supplierAfter->organization_id);
        $this->assertSame($supplierBefore->supplier_code, $supplierAfter->supplier_code);
        $this->assertSame($supplierBefore->created_by, $supplierAfter->created_by);
        $this->assertSame('Młyn po korekcie', $supplierAfter->display_name);
        $this->assertSame('Młyn po korekcie sp. z o.o.', $supplierAfter->legal_name);
        $this->assertSame('9876543210', Crypt::decryptString($supplierAfter->tax_identifier_ciphertext));
        $this->assertEquals($productBefore, DB::table('supplier_products')->where('id', $references['product_id'])->first());
        $this->assertEquals($productRevisionsBefore, DB::table('supplier_product_revisions')
            ->where('supplier_product_id', $references['product_id'])->orderBy('revision_no')->get()->toArray());

        $audit = DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplierId)
            ->where('event_code', 'SUPPLIER_DETAILS_CORRECTED')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->steward->id, $audit->actor_user_id);
        $this->assertSame($this->location->id, $audit->actor_location_id);
        $this->assertSame('ACCEPTED', $audit->outcome);
        $this->assertNotSame($audit->before_hash, $audit->after_hash);
        $metadata = json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Korekta danych dostawcy', $metadata['reason']);
        $this->assertSame(['display_name', 'legal_name', 'tax_identifier'], $metadata['changed_fields']);
        $this->assertNotNull($metadata['tax_identifier_after_fingerprint']);
        $this->assertStringNotContainsString('9876543210', $audit->metadata);
        $this->assertSame(1, DB::table('raw_materials_commercial_idempotency')
            ->where('actor_user_id', $this->steward->id)
            ->where('operation_code', 'supplier.correct')
            ->where('idempotency_key', 'supplier-correction-0001')->count());

        $this->actingAs($this->steward, 'web')->patchJson(
            '/api/v1/raw-materials/suppliers/'.$supplierId,
            ['tax_identifier' => '1234567890', 'reason' => 'Korekta identyfikatora'],
            ['Idempotency-Key' => 'supplier-correction-tax-only-0001'],
        )->assertOk()->assertJsonMissing(['tax_identifier' => '1234567890']);
        $taxOnlyAudit = DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplierId)
            ->where('event_code', 'SUPPLIER_DETAILS_CORRECTED')
            ->orderByDesc('occurred_at')
            ->first();
        $this->assertNotSame($taxOnlyAudit->before_hash, $taxOnlyAudit->after_hash);
        $taxOnlyMetadata = json_decode($taxOnlyAudit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['tax_identifier'], $taxOnlyMetadata['changed_fields']);
        $this->assertNotSame(
            $taxOnlyMetadata['tax_identifier_before_fingerprint'],
            $taxOnlyMetadata['tax_identifier_after_fingerprint'],
        );
        $this->assertStringNotContainsString('1234567890', $taxOnlyAudit->metadata);
        $this->assertSame('1234567890', Crypt::decryptString(
            DB::table('organization_suppliers')->where('id', $supplierId)->value('tax_identifier_ciphertext'),
        ));

        $this->actingAs($this->steward, 'web')->patchJson(
            '/api/v1/raw-materials/suppliers/'.$supplierId,
            ['supplier_code' => 'FORGED_CODE', 'reason' => 'Próba zmiany tożsamości'],
            ['Idempotency-Key' => 'supplier-correction-invalid-0001'],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('request');
        $this->assertSame('CORRECTION_01', DB::table('organization_suppliers')
            ->where('id', $supplierId)->value('supplier_code'));
    }

    public function test_supplier_correction_requires_supplier_manage_permission(): void
    {
        $supplier = app(RawMaterialCommercialService::class)->createSupplier(
            $this->steward,
            $this->location,
            ['supplier_code' => 'CORRECTION_DENIED', 'display_name' => 'Dostawca chroniony'],
            'supplier-correction-denied-create-0001',
        );
        $supplierId = $supplier['data']['id'];
        $reader = $this->user('supplier-correction-reader@example.test');
        app(MembershipService::class)->assign($reader, $this->location);
        $this->selectRawMaterialApiContext($reader);
        $auditCount = DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplierId)->count();

        $this->actingAs($reader, 'web')->patchJson(
            '/api/v1/raw-materials/suppliers/'.$supplierId,
            ['display_name' => 'Zmiana bez uprawnienia', 'reason' => 'Nieuprawniona korekta'],
            ['Idempotency-Key' => 'supplier-correction-denied-0001'],
        )->assertForbidden();
        $this->assertSame('Dostawca chroniony', DB::table('organization_suppliers')
            ->where('id', $supplierId)->value('display_name'));
        $this->assertSame($auditCount, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplierId)->count());
    }

    public function test_supplier_products_offer_prices_and_cost_permissions_are_isolated(): void
    {
        $category = $this->category();
        $materialId = $this->declaredMaterial($category['id'], 'Mąka do oferty', [[
            'unit_code' => 'mg',
            'dimension' => 'MASS',
            'conversion_kind' => 'METRIC',
            'to_base_factor' => '0.001000000000',
            'basis' => null,
        ]]);
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Zatwierdzona baza');
        $materialRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id');
        $this->selectContext($this->steward);

        $this->postJson('/api/v1/raw-materials/suppliers', [
            'supplier_code' => 'MISSING_KEY',
            'display_name' => 'Dostawca bez klucza',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.Idempotency-Key.0', __('messages.raw_materials_idempotency_key_required'));
        $this->assertSame(0, DB::table('organization_suppliers')->where('organization_id', $this->steward->organization_id)->count());

        $supplierResponse = $this->postJson('/api/v1/raw-materials/suppliers', [
            'supplier_code' => 'MILL_01',
            'display_name' => 'Młyn testowy',
            'tax_identifier' => '1234567890',
        ], ['Idempotency-Key' => 'supplier-create-0001'])->assertCreated();
        $supplierId = $supplierResponse->json('data.id');
        $supplierResponse->assertJsonMissing(['tax_identifier' => '1234567890']);

        $productResponse = $this->postJson('/api/v1/raw-materials/'.$materialId.'/supplier-products', [
            'supplier_id' => $supplierId,
            'raw_material_revision_id' => $materialRevisionId,
            'product_name' => 'Mąka pszenna 500 g',
            'supplier_sku' => 'FLOUR-500',
            'purchase_unit_code' => 'BAG',
            'content_quantity' => '500000',
            'content_unit_code' => 'mg',
        ], ['Idempotency-Key' => 'product-create-0001'])->assertCreated();
        $productId = $productResponse->json('data.id');
        $productRevisionId = $productResponse->json('data.current_revision.id');
        $this->assertSame('500.00000000000000', $productResponse->json('data.current_revision.base_quantity'));
        $this->assertSame('0.001000000000', $productResponse->json('data.current_revision.to_base_factor'));
        $this->assertSame(1, DB::table('supplier_product_revisions')->where('supplier_product_id', $productId)->count());
        DB::statement('SET CONSTRAINTS supplier_products_initial_revision IMMEDIATE');
        DB::statement('SET CONSTRAINTS supplier_products_initial_revision DEFERRED');

        $offerResponse = $this->postJson('/api/v1/raw-materials/supplier-products/'.$productId.'/offers', [
            'offer_reference' => 'Q-2026-01',
        ], ['Idempotency-Key' => 'offer-create-0001'])->assertCreated();
        $offerId = $offerResponse->json('data.id');
        $eventResponse = $this->postJson('/api/v1/raw-materials/supplier-offers/'.$offerId.'/price-events', [
            'event_type' => 'PRICE_SET',
            'valid_from' => now()->toDateString(),
            'net_amount_per_purchase_unit' => '5.00000000',
            'currency_code' => 'PLN',
            'quantity_from' => '1',
            'reason_code' => 'SUPPLIER_QUOTE',
        ], ['Idempotency-Key' => 'price-event-00001'])->assertCreated();
        $eventId = $eventResponse->json('data.id');
        $this->assertSame('NORMALIZED', $eventResponse->json('data.normalization_status'));
        $this->assertTrue((bool) DB::table('supplier_product_revisions')->where('id', $productRevisionId)->exists());
        $this->assertTrue((bool) DB::selectOne(
            'SELECT reporting_amount_pln_per_base_unit = 0.01 AS matches
                FROM supplier_offer_fx_normalization_events WHERE supplier_offer_event_id = ?',
            [$eventId],
        )->matches);
        $this->assertSame('1', (string) DB::table('supplier_offer_fx_normalization_events')
            ->where('supplier_offer_event_id', $eventId)->value('rate_snapshot'));
        $this->postJson('/api/v1/raw-materials/supplier-offers/'.$offerId.'/price-events', [
            'event_type' => 'WITHDRAWN',
            'valid_from' => now()->toDateString(),
            'supersedes_event_id' => $eventId,
            'reason_code' => 'OFFER_WITHDRAWN',
        ], ['Idempotency-Key' => 'price-withdrawal-0001'])->assertCreated()
            ->assertJsonPath('data.event_type', 'WITHDRAWN');
        $this->assertSame('ACTIVE', DB::table('supplier_offers')->where('id', $offerId)->value('lifecycle_state'));
        $replacement = $this->postJson('/api/v1/raw-materials/supplier-offers/'.$offerId.'/price-events', [
            'event_type' => 'PRICE_SET',
            'valid_from' => now()->toDateString(),
            'net_amount_per_purchase_unit' => '6.00000000',
            'currency_code' => 'PLN',
            'quantity_from' => '1',
            'reason_code' => 'UPDATED_QUOTE',
        ], ['Idempotency-Key' => 'price-event-00002'])->assertCreated();
        $this->assertSame(3, DB::table('supplier_offer_events')->where('offer_id', $offerId)->count());

        Log::spy();
        $this->postJson('/api/v1/raw-materials/supplier-offers/'.$offerId.'/price-events', [
            'event_type' => 'PRICE_SET',
            'valid_from' => now()->toDateString(),
            'net_amount_per_purchase_unit' => '9.00000000',
            'currency_code' => 'PLN',
            'quantity_from' => '1',
            'reason_code' => 'OVERLAPPING_QUOTE',
        ], ['Idempotency-Key' => 'price-event-overlap-0001'])->assertStatus(409)
            ->assertJsonPath('error.code', 'RAW_MATERIAL_INTEGRITY_CONFLICT')
            ->assertJsonMissing(['net_amount_per_purchase_unit' => '9.00000000']);
        Log::shouldHaveReceived('error')->with(
            'Raw-material database operation failed.',
            \Mockery::on(fn (array $context): bool => $context === ['sqlstate' => '23P01']),
        );

        $catalogManager = $this->user('raw-material-supplier-manager@example.test');
        app(MembershipService::class)->assign($catalogManager, $this->location);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $catalogManager,
            RawMaterialPermission::SupplierManage,
            'Zarządzanie danymi dostawców',
        );
        $this->assertFalse(app(RawMaterialAuthorization::class)->hasEffectiveGrant(
            $catalogManager,
            RawMaterialPermission::PurchasingCostsManage,
        ));
        $this->flushSession();
        $this->selectContext($catalogManager->fresh());
        $this->postJson('/api/v1/raw-materials/supplier-products/'.$productId.'/offers', [
            'offer_reference' => 'Oferta bez uprawnienia kosztowego',
        ], ['Idempotency-Key' => 'offer-without-cost-permission'])->assertForbidden();
        $this->assertSame(1, DB::table('supplier_offers')->where('supplier_product_id', $productId)->count());

        $viewer = $this->user('raw-material-supplier-viewer@example.test');
        app(MembershipService::class)->assign($viewer, $this->location);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $viewer,
            RawMaterialPermission::SupplierView,
            'Odczyt danych dostawców',
        );
        $this->assertFalse(app(RawMaterialAuthorization::class)->hasEffectiveGrant(
            $viewer,
            RawMaterialPermission::PurchasingCostsView,
        ));
        $this->flushSession();
        $this->selectContext($viewer->fresh());
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.email', 'raw-material-supplier-viewer@example.test');
        $this->getJson('/api/v1/raw-materials/suppliers')->assertOk()
            ->assertJsonMissing(['tax_identifier' => '1234567890'])
            ->assertJsonMissing(['net_amount_per_purchase_unit' => '5.00000000']);
        $this->getJson('/api/v1/raw-materials/'.$materialId.'/supplier-products')->assertOk()
            ->assertJsonMissing(['net_amount_per_purchase_unit' => '5.00000000']);
        $this->getJson('/api/v1/raw-materials/supplier-products/'.$productId.'/offers')->assertOk()
            ->assertJsonMissing(['net_amount_per_purchase_unit' => '6.00000000'])
            ->assertJsonMissing(['currency_code' => 'PLN']);
        $this->getJson('/api/v1/raw-materials/supplier-products/'.Str::uuid().'/revisions')->assertNotFound();
        $this->getJson('/api/v1/raw-materials/supplier-offers/'.$offerId.'/price-events')->assertForbidden();
        $this->getJson('/api/v1/raw-materials/supplier-offers/'.Str::uuid().'/price-events')->assertForbidden();

        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('supplier_product_revisions')
            ->where('id', $productRevisionId)->update(['product_name' => 'Niedozwolona zmiana']));
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('supplier_offer_events')
            ->where('id', $eventId)->update(['net_amount_per_purchase_unit' => '100']));
    }

    public function test_nbp_as_of_overrides_half_even_restatements_and_idempotency(): void
    {
        $category = $this->category();
        $materialId = $this->declaredMaterial($category['id'], 'Cena referencyjna');
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Zatwierdzona cena');
        $this->selectContext($this->steward);
        Http::fake([
            'https://api.nbp.pl/*' => Http::response(
                '[{"table":"A","no":"214/A/NBP/2026","effectiveDate":"2026-10-30","rates":[{"currency":"dolar amerykański","code":"USD","mid":4.123456789012}]}]',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);
        $commercial = app(RawMaterialCommercialService::class);
        $sync = $commercial->syncNbpRate(
            $this->steward,
            $this->location,
            'USD',
            '2026-11-01',
            'nbp-sync-sunday-0001',
        );
        $this->assertSame('2026-10-30', $sync['data']['selected_rate']['rate_date']);
        $this->assertSame('4.123456789012', $sync['data']['selected_rate']['pln_per_quote_unit']);
        $nbpRateId = $sync['data']['selected_rate']['id'];

        Http::fake(['https://api.nbp.pl/*' => Http::response([], 503)]);
        $syncReplay = $commercial->syncNbpRate(
            $this->steward,
            $this->location,
            'USD',
            '2026-11-01',
            'nbp-sync-sunday-0001',
        );
        $this->assertEquals($sync, $syncReplay);
        Http::assertNothingSent();
        $legacyKey = 'nbp-sync-legacy-0001';
        $legacyPayload = [
            'currency_code' => 'USD',
            'operation_date' => '2026-11-01',
            'source_hashes' => [DB::table('organization_exchange_rate_events')
                ->where('id', $nbpRateId)->value('source_payload_hash')],
        ];
        ksort($legacyPayload);
        DB::connection('pgsql_setup')->table('raw_materials_commercial_idempotency')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'actor_user_id' => $this->steward->id,
            'operation_code' => 'exchange-rate.nbp-sync',
            'idempotency_key' => $legacyKey,
            'request_hash' => hash_hmac(
                'sha256',
                json_encode($legacyPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                (string) config('app.key'),
            ),
            'response_body' => json_encode($sync, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $legacyReplay = $commercial->syncNbpRate(
            $this->steward,
            $this->location,
            'USD',
            '2026-11-01',
            $legacyKey,
        );
        $this->assertEquals($sync, $legacyReplay);
        Http::assertNothingSent();
        try {
            $commercial->syncNbpRate(
                $this->steward,
                $this->location,
                'EUR',
                '2026-11-01',
                'nbp-sync-sunday-0001',
            );
            $this->fail('An NBP idempotency key was reused with a different currency.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_IDEMPOTENCY_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
        Http::assertNothingSent();

        $override = $commercial->overrideExchangeRate(
            $this->steward,
            $this->location,
            $nbpRateId,
            '4.000000000000',
            'KONTROLOWANY_OVERRIDE',
            'fx-override-000001',
        );
        $this->assertSame('MANUAL_OVERRIDE', $override['data']['source_code']);
        $this->assertSame('4.000000000000', $commercial->exchangeRate(
            $this->steward,
            $this->location,
            'USD',
            '2026-11-01',
        )['data']['pln_per_quote_unit']);
        $this->assertSame('PLN_IDENTITY', $commercial->exchangeRate(
            $this->steward,
            $this->location,
            'PLN',
            '2026-11-01',
        )['data']['source_code']);

        $rounding = DB::selectOne(<<<'SQL'
            SELECT
                raw_materials_round_half_even_v1(2.5, 0) AS positive_even,
                raw_materials_round_half_even_v1(3.5, 0) AS positive_odd,
                raw_materials_round_half_even_v1(-2.5, 0) AS negative_even,
                raw_materials_round_half_even_v1(-3.5, 0) AS negative_odd
            SQL);
        $this->assertSame('2', (string) $rounding->positive_even);
        $this->assertSame('4', (string) $rounding->positive_odd);
        $this->assertSame('-2', (string) $rounding->negative_even);
        $this->assertSame('-4', (string) $rounding->negative_odd);

        $price = $commercial->setReferencePrice(
            $this->steward,
            $this->location,
            $materialId,
            [
                'event_type' => 'PRICE_SET',
                'effective_from' => '2026-11-01',
                'net_pln_per_base_unit' => '0.00500000000000',
                'expected_event_no' => 0,
                'reason_code' => 'INITIAL_REFERENCE',
            ],
            'reference-price-0001',
        );
        $eventId = $price['data']['id'];
        $restatement = $commercial->createRestatement(
            $this->steward,
            $this->location,
            $materialId,
            $eventId,
            [
                'restated_net_pln_per_base_unit' => '0.00600000000000',
                'operation_date' => '2026-11-01',
                'reason_code' => 'UDOKUMENTOWANA_KOREKTA',
            ],
            'restatement-000001',
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $restatement['data']['original_snapshot_hash']);
        $this->assertSame('0.00500000000000', (string) DB::table('raw_material_reference_price_events')
            ->where('id', $eventId)->value('net_pln_per_base_unit'));
        $this->assertSame(1, DB::table('raw_material_financial_restatements')
            ->where('original_reference_price_event_id', $eventId)->count());

        $supplierInput = ['supplier_code' => 'IDEMPOTENT', 'display_name' => 'Dostawca idempotentny'];
        $first = $commercial->createSupplier($this->steward, $this->location, $supplierInput, 'supplier-idempotent-001');
        $replay = $commercial->createSupplier($this->steward, $this->location, $supplierInput, 'supplier-idempotent-001');
        $this->assertSame($first['data']['id'], $replay['data']['id']);
        try {
            $commercial->createSupplier(
                $this->steward,
                $this->location,
                ['supplier_code' => 'IDEMPOTENT', 'display_name' => 'Inny dostawca'],
                'supplier-idempotent-001',
            );
            $this->fail('An idempotency key was reused with a different payload.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_IDEMPOTENCY_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
    }

    public function test_postgresql_half_even_preserves_large_values_and_fractional_precision(): void
    {
        $rounding = DB::selectOne(<<<'SQL'
            SELECT
                raw_materials_round_half_even_v1('50000000.12345678901234'::numeric, 14)::text AS large_positive,
                raw_materials_round_half_even_v1('-50000000.12345678901234'::numeric, 14)::text AS large_negative,
                raw_materials_round_half_even_v1('0.123456789012345'::numeric, 14)::text AS fractional_even_tie,
                raw_materials_round_half_even_v1('-0.123456789012345'::numeric, 14)::text AS fractional_negative_even_tie,
                raw_materials_round_half_even_v1('0.123456789012355'::numeric, 14)::text AS fractional_odd_tie,
                raw_materials_round_half_even_v1('-0.123456789012355'::numeric, 14)::text AS fractional_negative_odd_tie,
                raw_materials_round_half_even_v1(2.5, 0)::text AS positive_even_tie,
                raw_materials_round_half_even_v1(3.5, 0)::text AS positive_odd_tie,
                raw_materials_round_half_even_v1(-2.5, 0)::text AS negative_even_tie,
                raw_materials_round_half_even_v1(-3.5, 0)::text AS negative_odd_tie
            SQL);

        $this->assertSame('50000000.12345678901234', $rounding->large_positive);
        $this->assertSame('-50000000.12345678901234', $rounding->large_negative);
        $this->assertSame('0.12345678901234', $rounding->fractional_even_tie);
        $this->assertSame('-0.12345678901234', $rounding->fractional_negative_even_tie);
        $this->assertSame('0.12345678901236', $rounding->fractional_odd_tie);
        $this->assertSame('-0.12345678901236', $rounding->fractional_negative_odd_tie);
        $this->assertSame('2', $rounding->positive_even_tie);
        $this->assertSame('4', $rounding->positive_odd_tie);
        $this->assertSame('-2', $rounding->negative_even_tie);
        $this->assertSame('-4', $rounding->negative_odd_tie);
    }

    public function test_reference_price_view_does_not_disclose_purchasing_source_without_cost_permission(): void
    {
        $fixture = $this->createCommercialOfferFixture('Cena z oferty', 'SOURCE_01', 'SOURCE-1', 'source-disclosure');
        $commercial = app(RawMaterialCommercialService::class);
        $commercial->setReferencePrice($this->steward, $this->location, $fixture['material_id'], [
            'event_type' => 'PRICE_SET',
            'effective_from' => now()->toDateString(),
            'net_pln_per_base_unit' => '5.00000000000000',
            'expected_event_no' => 0,
            'source_offer_event_id' => $fixture['price_event_id'],
            'reason_code' => 'SOURCE_OFFER',
        ], 'source-disclosure-reference-price');

        $referenceViewer = $this->user('reference-price-only@example.test');
        app(MembershipService::class)->assign($referenceViewer, $this->location);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $referenceViewer,
            RawMaterialPermission::ReferencePriceView,
            'Odczyt cen referencyjnych bez kosztów zakupu',
        );
        $this->assertFalse(app(RawMaterialAuthorization::class)->hasEffectiveGrant(
            $referenceViewer,
            RawMaterialPermission::PurchasingCostsView,
        ));
        $this->flushSession();
        $this->selectContext($referenceViewer->fresh());
        $this->getJson('/api/v1/raw-materials/'.$fixture['material_id'].'/reference-prices')
            ->assertOk()->assertJsonPath('data.0.source', null);

        $this->flushSession();
        $this->selectContext($this->steward->fresh());
        $this->getJson('/api/v1/raw-materials/'.$fixture['material_id'].'/reference-prices')
            ->assertOk()->assertJsonPath('data.0.source.amount', '5.00000000')
            ->assertJsonPath('data.0.source.currency', 'PLN');
    }

    public function test_blocked_material_rejects_new_offers_and_offer_prices_in_api_and_postgresql(): void
    {
        $fixture = $this->createCommercialOfferFixture('Blokowany surowiec', 'BLOCKED_01', 'BLOCKED-1', 'blocked');
        $commercial = app(RawMaterialCommercialService::class);
        $this->rawMaterialService->block($this->steward, $this->location, $fixture['material_id'], 'Blokada testowa');

        $this->postJson('/api/v1/raw-materials/supplier-products/'.$fixture['product_id'].'/offers', [], [
            'Idempotency-Key' => 'blocked-material-offer-0001',
        ])->assertNotFound();
        $this->postJson('/api/v1/raw-materials/supplier-offers/'.$fixture['offer_id'].'/price-events', [
            'event_type' => 'PRICE_SET',
            'valid_from' => now()->toDateString(),
            'net_amount_per_purchase_unit' => '7.00000000',
            'currency_code' => 'USD',
            'quantity_from' => '1',
            'reason_code' => 'BLOCKED_MATERIAL',
        ], ['Idempotency-Key' => 'blocked-material-price-0001'])->assertNotFound();
        $this->assertSame(1, DB::table('supplier_offers')->where('supplier_product_id', $fixture['product_id'])->count());
        $this->assertSame(1, DB::table('supplier_offer_events')->where('offer_id', $fixture['offer_id'])->count());

        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('supplier_offers')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'supplier_product_id' => $fixture['product_id'],
            'created_by' => $this->steward->id,
        ]));
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('supplier_offer_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'offer_id' => $fixture['offer_id'],
            'supplier_product_id' => $fixture['product_id'],
            'supplier_product_revision_id' => $fixture['product_revision_id'],
            'event_no' => 2,
            'event_type' => 'PRICE_SET',
            'valid_from' => now()->addDay()->toDateString(),
            'net_amount_per_purchase_unit' => '7.00000000',
            'currency_code' => 'USD',
            'quantity_from' => '1',
            'recorded_by' => $this->steward->id,
            'reason_code' => 'DIRECT_SQL_BLOCKED_MATERIAL',
            'operation_id' => Str::uuid()->toString(),
        ]));
    }

    public function test_archived_supplier_cannot_be_used_as_new_reference_price_source_in_api_or_postgresql(): void
    {
        $fixture = $this->createCommercialOfferFixture('Archiwizowany dostawca', 'ARCHIVED_01', 'ARCHIVED-1', 'archived');
        $commercial = app(RawMaterialCommercialService::class);
        $commercial->archiveSupplier(
            $this->steward,
            $this->location,
            $fixture['supplier_id'],
            'Archiwizacja testowa',
            'archived-supplier-archive-001',
        );
        $this->postJson('/api/v1/raw-materials/'.$fixture['material_id'].'/reference-prices', [
            'event_type' => 'PRICE_SET',
            'effective_from' => now()->toDateString(),
            'net_pln_per_base_unit' => '5.00000000000000',
            'expected_event_no' => 0,
            'source_offer_event_id' => $fixture['price_event_id'],
            'reason_code' => 'ARCHIVED_SUPPLIER_SOURCE',
        ], ['Idempotency-Key' => 'archived-supplier-reference-001'])->assertNotFound();
        $this->assertSame(0, DB::table('raw_material_reference_price_events')
            ->where('raw_material_id', $fixture['material_id'])->count());

        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('raw_material_reference_price_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'raw_material_id' => $fixture['material_id'],
            'event_no' => 1,
            'event_type' => 'PRICE_SET',
            'effective_from' => now()->toDateString(),
            'net_pln_per_base_unit' => '5.00000000000000',
            'source_net_amount' => '5.00000000',
            'source_currency_code' => 'PLN',
            'source_offer_event_id' => $fixture['price_event_id'],
            'source_product_revision_id' => $fixture['product_revision_id'],
            'rate_snapshot' => '1',
            'operation_date' => now()->toDateString(),
            'source_code' => 'PLN_IDENTITY',
            'reason_code' => 'DIRECT_SQL_ARCHIVED_SUPPLIER',
            'recorded_by' => $this->steward->id,
            'operation_id' => Str::uuid()->toString(),
        ]));
    }

    public function test_supplier_product_revisions_require_active_supplier_and_product_in_api_and_postgresql(): void
    {
        $commercial = app(RawMaterialCommercialService::class);
        $categoryId = $this->category()['id'];
        $active = $this->createCommercialOfferFixture(
            'Aktywny produkt', 'REVISION_ACTIVE', 'ACTIVE-1', 'revision-active', $categoryId,
        );
        $currentRevision = DB::table('supplier_product_revisions')
            ->where('id', $active['product_revision_id'])->first();

        $this->postJson('/api/v1/raw-materials/supplier-products/'.$active['product_id'].'/revisions', [
            'supplier_id' => $active['supplier_id'],
            'raw_material_revision_id' => $currentRevision->raw_material_revision_id,
            'expected_revision_id' => $active['product_revision_id'],
            'product_name' => 'Aktywny produkt - rewizja 2',
            'supplier_sku' => 'ACTIVE-2',
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '2',
            'content_unit_code' => 'g',
        ], ['Idempotency-Key' => 'active-product-revision-002'])->assertOk()
            ->assertJsonPath('data.current_revision.revision_no', 2);
        $this->assertSame(2, DB::table('supplier_product_revisions')
            ->where('supplier_product_id', $active['product_id'])->count());

        $archivedSupplier = $this->createCommercialOfferFixture(
            'Produkt z archiwizowanym dostawcą', 'REVISION_ARCHIVED_SUPPLIER', 'ARCH-S-1', 'revision-archived-supplier',
            $categoryId,
        );
        $commercial->archiveSupplier(
            $this->steward,
            $this->location,
            $archivedSupplier['supplier_id'],
            'Archiwizacja testowa',
            'archived-supplier-revision-001',
        );
        $supplierRevision = DB::table('supplier_product_revisions')
            ->where('id', $archivedSupplier['product_revision_id'])->first();
        $this->postJson('/api/v1/raw-materials/supplier-products/'.$archivedSupplier['product_id'].'/revisions', [
            'supplier_id' => $archivedSupplier['supplier_id'],
            'raw_material_revision_id' => $supplierRevision->raw_material_revision_id,
            'expected_revision_id' => $archivedSupplier['product_revision_id'],
            'product_name' => 'Niedozwolona rewizja',
            'supplier_sku' => 'ARCH-S-2',
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '2',
            'content_unit_code' => 'g',
        ], ['Idempotency-Key' => 'archived-supplier-revision-002'])->assertNotFound();
        $this->assertSame(1, DB::table('supplier_product_revisions')
            ->where('supplier_product_id', $archivedSupplier['product_id'])->count());
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $this->insertProductRevisionDirectly(
            $connection,
            $archivedSupplier['product_id'],
            $supplierRevision,
            'ARCH-S-2',
        ));

        $archivedProduct = $this->createCommercialOfferFixture(
            'Archiwizowany produkt', 'REVISION_ARCHIVED_PRODUCT', 'ARCH-P-1', 'revision-archived-product',
            $categoryId,
        );
        $commercial->archiveProduct(
            $this->steward,
            $this->location,
            $archivedProduct['product_id'],
            'Archiwizacja testowa',
            'archived-product-revision-001',
        );
        $productRevision = DB::table('supplier_product_revisions')
            ->where('id', $archivedProduct['product_revision_id'])->first();
        $this->postJson('/api/v1/raw-materials/supplier-products/'.$archivedProduct['product_id'].'/revisions', [
            'supplier_id' => $archivedProduct['supplier_id'],
            'raw_material_revision_id' => $productRevision->raw_material_revision_id,
            'expected_revision_id' => $archivedProduct['product_revision_id'],
            'product_name' => 'Niedozwolona rewizja',
            'supplier_sku' => 'ARCH-P-2',
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '2',
            'content_unit_code' => 'g',
        ], ['Idempotency-Key' => 'archived-product-revision-002'])->assertNotFound();
        $this->assertSame(1, DB::table('supplier_product_revisions')
            ->where('supplier_product_id', $archivedProduct['product_id'])->count());
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $this->insertProductRevisionDirectly(
            $connection,
            $archivedProduct['product_id'],
            $productRevision,
            'ARCH-P-2',
        ));
    }

    public function test_commercial_mutation_rolls_back_when_append_only_audit_fails(): void
    {
        $this->withSetupConnection(fn (Connection $connection) => $connection->unprepared(<<<'SQL'
            CREATE FUNCTION raw_materials_test_fail_commercial_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Injected audit failure' USING ERRCODE = 'P0001';
            END
            $$;
            CREATE TRIGGER raw_materials_test_fail_commercial_audit
                BEFORE INSERT ON raw_materials_commercial_audit_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_test_fail_commercial_audit();
            SQL));

        try {
            app(RawMaterialCommercialService::class)->createSupplier(
                $this->steward,
                $this->location,
                ['supplier_code' => 'ROLLBACK', 'display_name' => 'Wycofany zapis'],
                'supplier-rollback-001',
            );
            $this->fail('The mutation succeeded despite a failing audit insert.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->errorInfo[0] ?? null);
        } finally {
            $this->withSetupConnection(function (Connection $connection): void {
                $connection->unprepared('DROP TRIGGER raw_materials_test_fail_commercial_audit ON raw_materials_commercial_audit_events');
                $connection->unprepared('DROP FUNCTION raw_materials_test_fail_commercial_audit()');
            });
        }

        $this->assertDatabaseMissing('organization_suppliers', [
            'organization_id' => $this->steward->organization_id,
            'supplier_code' => 'ROLLBACK',
        ]);
        $this->assertDatabaseMissing('raw_materials_commercial_idempotency', [
            'organization_id' => $this->steward->organization_id,
            'idempotency_key' => 'supplier-rollback-001',
        ]);
        $this->assertDatabaseMissing('raw_materials_commercial_audit_events', [
            'organization_id' => $this->steward->organization_id,
            'event_code' => 'SUPPLIER_CREATED',
        ]);
    }

    public function test_category_supplier_correction_and_archive_roll_back_when_their_audits_fail(): void
    {
        $category = $this->category();
        $renamed = $this->rawMaterialService->renameCategory(
            $this->steward,
            $this->location,
            $category['id'],
            'Kategoria z audytem',
        );
        $this->assertSame('Kategoria z audytem', $renamed['name']);
        $categoryAudit = DB::table('raw_material_audit_events')
            ->where('subject_id', $category['id'])
            ->where('event_code', 'CATEGORY_RENAMED')
            ->first();
        $this->assertNotNull($categoryAudit);
        $this->assertSame($this->steward->id, $categoryAudit->actor_user_id);

        $commercial = app(RawMaterialCommercialService::class);
        $supplier = $commercial->createSupplier($this->steward, $this->location, [
            'supplier_code' => 'AUDIT_ROLLBACK',
            'display_name' => 'Dostawca przed korektą',
            'legal_name' => 'Dostawca przed korektą sp. z o.o.',
        ], 'supplier-audit-rollback-create-0001')['data'];
        $supplierBefore = DB::table('organization_suppliers')->where('id', $supplier['id'])->first();

        $this->withSetupConnection(fn (Connection $connection) => $connection->unprepared(<<<'SQL'
            CREATE FUNCTION public.test_fail_stage2e_business_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.event_code IN ('CATEGORY_RENAMED', 'SUPPLIER_DETAILS_CORRECTED', 'SUPPLIER_ARCHIVED') THEN
                    RAISE EXCEPTION 'Injected business audit failure' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER test_fail_stage2e_category_audit
                BEFORE INSERT ON public.raw_material_audit_events
                FOR EACH ROW EXECUTE FUNCTION public.test_fail_stage2e_business_audit();
            CREATE TRIGGER test_fail_stage2e_supplier_audit
                BEFORE INSERT ON public.raw_materials_commercial_audit_events
                FOR EACH ROW EXECUTE FUNCTION public.test_fail_stage2e_business_audit();
            SQL));

        try {
            foreach ([
                fn () => $this->rawMaterialService->renameCategory(
                    $this->steward,
                    $this->location,
                    $category['id'],
                    'Zmiana wycofana',
                ),
                fn () => $commercial->correctSupplier(
                    $this->steward,
                    $this->location,
                    $supplier['id'],
                    [
                        'display_name' => 'Korekta wycofana',
                        'reason' => 'Korekta do testu rollback',
                    ],
                    'supplier-audit-rollback-correct-0001',
                ),
                fn () => $commercial->archiveSupplier(
                    $this->steward,
                    $this->location,
                    $supplier['id'],
                    'Archiwizacja do testu rollback',
                    'supplier-audit-rollback-archive-0001',
                ),
            ] as $mutation) {
                try {
                    $mutation();
                    $this->fail('Mutacja biznesowa powiodła się mimo błędu audytu.');
                } catch (QueryException $exception) {
                    $this->assertSame('P0001', $exception->errorInfo[0] ?? null);
                }
            }
        } finally {
            $this->withSetupConnection(fn (Connection $connection) => $connection->unprepared(<<<'SQL'
                DROP TRIGGER test_fail_stage2e_supplier_audit ON public.raw_materials_commercial_audit_events;
                DROP TRIGGER test_fail_stage2e_category_audit ON public.raw_material_audit_events;
                DROP FUNCTION public.test_fail_stage2e_business_audit();
                SQL));
        }

        $this->assertSame('Kategoria z audytem', DB::table('raw_material_categories')
            ->where('id', $category['id'])->value('name'));
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $category['id'])->where('event_code', 'CATEGORY_RENAMED')->count());
        $this->assertEquals($supplierBefore, DB::table('organization_suppliers')->where('id', $supplier['id'])->first());
        $this->assertSame(0, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplier['id'])
            ->whereIn('event_code', ['SUPPLIER_DETAILS_CORRECTED', 'SUPPLIER_ARCHIVED'])->count());
        $this->assertDatabaseMissing('raw_materials_commercial_idempotency', [
            'organization_id' => $this->steward->organization_id,
            'idempotency_key' => 'supplier-audit-rollback-correct-0001',
        ]);
        $this->assertDatabaseMissing('raw_materials_commercial_idempotency', [
            'organization_id' => $this->steward->organization_id,
            'idempotency_key' => 'supplier-audit-rollback-archive-0001',
        ]);
    }

    public function test_explicit_location_share_gates_raw_material_access_and_preserves_revocation_history(): void
    {
        $material = $this->activeCsvImportMaterial('Udostępniany surowiec');
        $restaurant = $this->activeRestaurant('RSHARE');
        $otherLocation = $this->activeRestaurant('RSHARE2');
        app(MembershipService::class)->assign($this->steward, $restaurant);
        app(MembershipService::class)->assign($this->steward, $otherLocation);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $restaurant->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id,
            'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        $policy = app(RawMaterialLocationSharePolicy::class);
        $administration = app(RawMaterialLocationShareAdministration::class);
        RoleAssignment::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'user_id' => $this->steward->id,
            'role' => BusinessRole::Administrator,
        ]);

        $this->assertFalse(app(BusinessAuthorization::class)->isSuperAdmin($this->bootstrapAdministrator));
        $this->assertFalse($policy->canConsume($this->steward, $restaurant, $material['id']));
        $financialGrantCount = DB::table('organization_permission_grants')
            ->where('user_id', $this->steward->id)
            ->whereIn('permission', [
                RawMaterialPermission::PurchasingCostsView->value,
                RawMaterialPermission::ReferencePriceView->value,
            ])->count();

        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $restaurant->id,
        ])->assertOk();
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('meta.total', 0);

        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ])->assertOk();
        $shareResponse = $this->actingAs($this->steward, 'web')->postJson('/api/v1/raw-materials/'.$material['id'].'/shares', [
            'location_id' => $restaurant->id,
        ])->assertCreated();
        $shareId = $shareResponse->json('data.id');
        $this->assertTrue($policy->canConsume($this->steward, $restaurant, $material['id']));

        Auth::forgetGuards();
        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $restaurant->id,
        ])->assertOk();
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/raw-materials/'.$material['id'])->assertOk()
            ->assertJsonPath('data.id', $material['id']);
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/revisions')->assertOk();
        Storage::fake('raw_material_csv_exports');
        $export = app(RawMaterialCsvCatalogExportWorkflow::class)
            ->create($this->steward, $restaurant);
        $this->assertSame(1, $export['row_count']);
        app(RawMaterialCsvCatalogExportWorkflow::class)
            ->authorizeDownload($this->steward, $restaurant, $export['export_id']);

        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ])->assertOk();
        $this->actingAs($this->steward, 'web')->postJson('/api/v1/raw-materials/'.$material['id'].'/shares', [
            'location_id' => $restaurant->id,
        ])->assertConflict();

        try {
            DB::table('raw_material_location_shares')->where('id', $shareId)->update([
                'revoked_by' => $this->bootstrapAdministrator->id,
                'revoked_context_location_id' => $this->location->id,
                'revoked_operation_id' => Str::uuid()->toString(),
                'revoked_at' => now(),
            ]);
            $this->fail('A direct runtime SQL share revocation bypassed the signed mutation boundary.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0] ?? null);
        }
        $this->assertDatabaseHas('raw_material_location_shares', ['id' => $shareId, 'revoked_at' => null]);

        $this->actingAs($this->steward, 'web')->deleteJson('/api/v1/raw-materials/'.$material['id'].'/shares/'.$restaurant->id, [
            'reason' => 'Dostęp nie jest już potrzebny',
        ])->assertOk()->assertJsonPath('data.active', false);
        $this->assertFalse($policy->canConsume($this->steward, $restaurant, $material['id']));
        try {
            app(RawMaterialCsvCatalogExportWorkflow::class)
                ->authorizeDownload($this->steward, $restaurant, $export['export_id']);
            $this->fail('A revoked share still allowed downloading an earlier catalog export.');
        } catch (RawMaterialException $exception) {
            $this->assertSame(404, $exception->status);
        }

        $this->actingAs($this->steward, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $restaurant->id,
        ])->assertOk();
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials/'.$material['id'])->assertNotFound();

        $this->assertDatabaseHas('raw_material_location_shares', ['id' => $shareId]);
        $this->assertSame(1, DB::table('raw_material_location_shares')->where('id', $shareId)
            ->whereNotNull('revoked_at')->count());
        $this->assertSame(1, DB::table('raw_materials')->where('id', $material['id'])->count());
        $this->assertSame(1, DB::table('raw_material_revisions')
            ->where('raw_material_id', $material['id'])->where('revision_no', 1)->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE')
            ->where('subject_id', $shareId)->where('event_code', 'LOCATION_SHARE_CREATED')
            ->where('outcome', 'ACCEPTED')->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE')
            ->where('subject_id', $shareId)->where('event_code', 'LOCATION_SHARE_REVOKED')
            ->where('outcome', 'ACCEPTED')->count());
        $this->assertSame($financialGrantCount, DB::table('organization_permission_grants')
            ->where('user_id', $this->steward->id)
            ->whereIn('permission', [
                RawMaterialPermission::PurchasingCostsView->value,
                RawMaterialPermission::ReferencePriceView->value,
            ])->count());

        $this->assertFalse($policy->canConsume($this->steward, $otherLocation, $material['id']));
        $this->withForeignOrganizationFixture(fn (Location $foreignLocation) => $this->assertFalse(
            $policy->canConsume($this->steward, $foreignLocation, $material['id']),
        ));

        $draft = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $this->rawMaterialService->createCategory($this->steward, $this->location, [
                'code' => 'OTHER_GOODS',
                'name' => 'Pozostałe produkty',
            ])['id'],
            'name' => 'Surowiec bez zatwierdzonej rewizji',
            'base_unit_code' => 'kg',
            'conversions' => [['unit_code' => 'g']],
        ]);
        $this->assertFalse($policy->canConsume($this->steward, $restaurant, $draft['id']));
        $signedOperationId = Str::uuid()->toString();
        try {
            app(AuthorizationMutationContext::class)->run(
                AuthorizationMutationScope::rawMaterialShare(),
                $this->bootstrapAdministrator->id,
                function () use ($draft, $restaurant, $signedOperationId): void {
                    DB::table('raw_material_location_shares')->insert([
                        'id' => Str::uuid()->toString(),
                        'organization_id' => $this->steward->organization_id,
                        'raw_material_id' => $draft['id'],
                        'location_id' => $restaurant->id,
                        'created_by' => $this->bootstrapAdministrator->id,
                        'created_context_location_id' => $this->location->id,
                        'created_operation_id' => $signedOperationId,
                    ]);
                },
            );
            $this->fail('A signed mutation made an unapproved draft shareable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0] ?? null);
        }
        $this->assertSame(0, DB::table('raw_material_location_shares')->where('raw_material_id', $draft['id'])->count());
        $this->expectException(RawMaterialException::class);
        $administration->share($this->bootstrapAdministrator, $this->location, $draft['id'], $restaurant->id);
    }

    public function test_raw_material_sharing_requires_an_authorized_administrator(): void
    {
        $material = $this->activeCsvImportMaterial('Surowiec do próby nieuprawnionego');
        $restaurant = $this->activeRestaurant('RSHARE3');
        $notAdministrator = $this->user('raw-share-unauthorized@example.test');
        app(MembershipService::class)->assign($notAdministrator, $this->location);

        try {
            app(RawMaterialLocationShareAdministration::class)->share(
                $notAdministrator,
                $this->location,
                $material['id'],
                $restaurant->id,
            );
            $this->fail('A non-administrator created a material share.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('raw_material_location_shares')
                ->where('raw_material_id', $material['id'])->count());
        }
    }

    public function test_pending_material_cannot_be_shared_and_archived_material_can_still_be_unshared(): void
    {
        $category = $this->category();
        $pendingId = $this->declaredMaterial($category['id'], 'Surowiec oczekujący zatwierdzenia');
        $this->rawMaterialService->submit($this->steward, $this->location, $pendingId);
        $restaurant = $this->activeRestaurant('RSHARE_PENDING');

        try {
            app(RawMaterialLocationShareAdministration::class)->share(
                $this->bootstrapAdministrator,
                $this->location,
                $pendingId,
                $restaurant->id,
            );
            $this->fail('A pending material was shared before its first revision was approved.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_SHARE_REQUIRES_APPROVED_ACTIVE_MATERIAL', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $material = $this->activeCsvImportMaterial('Zarchiwizowany udostępniony surowiec', $category['id']);
        $administration = app(RawMaterialLocationShareAdministration::class);
        $administration->share($this->bootstrapAdministrator, $this->location, $material['id'], $restaurant->id);
        $this->rawMaterialService->archive(
            $this->steward,
            $this->location,
            $material['id'],
            'Archiwizacja testowa przed cofnięciem share',
        );
        $this->assertFalse(app(RawMaterialLocationSharePolicy::class)
            ->canConsume($this->steward, $restaurant, $material['id']));
        $administration->revoke($this->bootstrapAdministrator, $this->location, $material['id'], $restaurant->id);
        $this->assertSame(1, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $material['id'])->whereNotNull('revoked_at')->count());
    }

    public function test_share_creation_rolls_back_when_its_audit_write_fails(): void
    {
        $material = $this->activeCsvImportMaterial('Surowiec dla testu wycofania transakcji');
        $restaurant = $this->activeRestaurant('RSHARE_ROLLBACK');
        $this->withSetupConnection(fn (Connection $connection) => $connection->unprepared(<<<'SQL'
            CREATE FUNCTION public.test_fail_raw_material_share_audit()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.subject_type = 'RAW_MATERIAL_SHARE' THEN
                    RAISE EXCEPTION 'synthetic share audit failure' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER test_fail_raw_material_share_audit
                BEFORE INSERT ON public.raw_material_audit_events
                FOR EACH ROW EXECUTE FUNCTION public.test_fail_raw_material_share_audit();
            SQL));

        try {
            app(RawMaterialLocationShareAdministration::class)->share(
                $this->bootstrapAdministrator,
                $this->location,
                $material['id'],
                $restaurant->id,
            );
            $this->fail('A share committed despite its audit insert failing.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->errorInfo[0] ?? null);
        } finally {
            $this->withSetupConnection(fn (Connection $connection) => $connection->unprepared(<<<'SQL'
                DROP TRIGGER test_fail_raw_material_share_audit ON public.raw_material_audit_events;
                DROP FUNCTION public.test_fail_raw_material_share_audit();
                SQL));
        }

        $this->assertSame(0, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertSame(0, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE')
            ->whereRaw("metadata->>'raw_material_id' = ?", [$material['id']])->count());
    }

    public function test_concurrent_share_creation_and_revocation_are_serialized(): void
    {
        $material = $this->activeCsvImportMaterial('Surowiec dla operacji równoległych');
        $restaurant = $this->activeRestaurant('RSHARE_CONC');
        $operation = function (string $mode) use ($material, $restaurant): array {
            $processes = [];
            $applicationNames = [];

            DB::beginTransaction();
            try {
                DB::table('organizations')->where('id', $this->steward->organization_id)
                    ->lockForUpdate()->firstOrFail();
                for ($index = 0; $index < 2; $index++) {
                    $applicationName = 'raw-share-'.$mode.'-'.strtolower(Str::random(8));
                    $process = new Process([
                        PHP_BINARY,
                        base_path('tests/Support/concurrent_raw_material_share.php'),
                        $this->bootstrapAdministrator->id,
                        $this->location->id,
                        $material['id'],
                        $restaurant->id,
                        $mode,
                        $applicationName,
                    ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
                    $process->setTimeout(30);
                    $process->start();
                    $processes[] = $process;
                    $applicationNames[] = $applicationName;
                }

                foreach ($processes as $index => $process) {
                    $deadline = microtime(true) + 10;
                    $waiting = false;
                    do {
                        DB::select('SELECT pg_stat_clear_snapshot()');
                        $waiting = (bool) DB::scalar(
                            "SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock')",
                            [$applicationNames[$index]],
                        );
                        if ($waiting || ! $process->isRunning()) {
                            break;
                        }
                        usleep(20_000);
                    } while (microtime(true) < $deadline);
                    $this->assertTrue($waiting, $process->getErrorOutput().$process->getOutput());
                }
                DB::commit();

                $results = [];
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                    $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['result'];
                }
                sort($results);

                return $results;
            } finally {
                foreach ($processes as $process) {
                    if ($process->isRunning()) {
                        $process->stop();
                    }
                }
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        };

        $this->assertSame(['conflict', 'created'], $operation('share'));
        $this->assertSame(1, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $material['id'])->where('location_id', $restaurant->id)
            ->whereNull('revoked_at')->count());
        $this->assertSame(['conflict', 'revoked'], $operation('revoke'));
        $this->assertSame(1, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $material['id'])->where('location_id', $restaurant->id)
            ->whereNotNull('revoked_at')->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE')
            ->where('event_code', 'LOCATION_SHARE_CREATED')->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE')
            ->where('event_code', 'LOCATION_SHARE_REVOKED')->count());
    }

    public function test_bulk_share_supports_both_views_and_is_idempotent(): void
    {
        $categoryId = $this->category()['id'];
        $materialOne = $this->activeCsvImportMaterial('Bulk share — pierwszy surowiec', $categoryId);
        $materialTwo = $this->activeCsvImportMaterial('Bulk share — drugi surowiec', $categoryId);
        $restaurantOne = $this->activeRestaurant('RBULK1');
        $restaurantTwo = $this->activeRestaurant('RBULK2');
        $restaurantThree = $this->activeRestaurant('RBULK3');
        $this->role($this->steward, BusinessRole::Administrator);
        $this->selectRawMaterialApiContext($this->steward);

        $materialPerspective = $this->actingAs($this->steward, 'web')->postJson(
            '/api/v1/raw-materials/'.$materialOne['id'].'/shares/bulk',
            ['location_ids' => [$restaurantOne->id, $restaurantTwo->id, $restaurantOne->id]],
        )->assertOk()->assertJsonPath('outcome', 'ACCEPTED')
            ->assertJsonCount(2, 'data.created')
            ->assertJsonCount(1, 'data.duplicates');

        $this->actingAs($this->steward, 'web')->postJson(
            '/api/v1/raw-materials/'.$materialOne['id'].'/shares/bulk',
            ['location_ids' => [$restaurantTwo->id, $restaurantOne->id]],
        )->assertOk()->assertJsonPath('outcome', 'ACCEPTED')
            ->assertJsonCount(0, 'data.created')
            ->assertJsonCount(2, 'data.already_active');

        $this->actingAs($this->steward, 'web')->postJson('/api/v1/raw-materials/bulk-shares/by-location', [
            'location_id' => $restaurantThree->id,
            'material_ids' => [$materialOne['id'], $materialTwo['id']],
        ])->assertOk()->assertJsonPath('outcome', 'ACCEPTED')
            ->assertJsonCount(2, 'data.created');

        $this->assertSame(4, DB::table('raw_material_location_shares')->whereNull('revoked_at')->count());
        $this->assertSame(3, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE_BULK')
            ->where('event_code', 'LOCATION_SHARE_BULK_COMPLETED')
            ->where('outcome', 'ACCEPTED')->count());
        $this->assertSame(0, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE_BULK')
            ->where('event_code', 'LOCATION_SHARE_BULK_REJECTED')
            ->where('reason_code', 'TARGET_VALIDATION_FAILED')->count());
        $this->assertNotNull($materialPerspective->json('data.operation_id'));
    }

    public function test_bulk_share_rejects_all_invalid_authorized_targets_atomically(): void
    {
        $approvedMaterial = $this->activeCsvImportMaterial('Bulk share — zatwierdzony');
        $approvedRevisionId = DB::table('raw_materials')->where('id', $approvedMaterial['id'])->value('approved_revision_id');
        $categoryId = DB::table('raw_material_revisions')->where('id', $approvedRevisionId)->value('category_id');
        $draftSequence = (int) DB::table('organization_sku_assignments')
            ->where('organization_id', $this->steward->organization_id)
            ->where('prefix', 'SUR')
            ->max('sequence_number') + 1;
        $draftMaterialId = $this->declaredMaterial(
            $categoryId,
            'Bulk share — szkic',
            materialCode: 'SUR-'.sprintf('%06d', $draftSequence),
        );
        $inactiveLocation = $this->activeRestaurant('RBULK_INACTIVE');
        $this->updateFixture($inactiveLocation, ['is_active' => false]);
        $this->role($this->steward, BusinessRole::Administrator);
        $this->selectRawMaterialApiContext($this->steward);

        $response = $this->actingAs($this->steward, 'web')->postJson('/api/v1/raw-materials/bulk-shares/by-location', [
            'location_id' => $inactiveLocation->id,
            'material_ids' => [$approvedMaterial['id'], $draftMaterialId],
        ])->assertUnprocessable()->assertJsonPath('outcome', 'REJECTED')
            ->assertJsonCount(2, 'data.invalid_targets');

        $invalidTargets = $response->json('data.invalid_targets');
        $this->assertSame(
            ['LOCATION_INACTIVE'],
            $invalidTargets[0]['reasons'],
        );
        $this->assertContains('LOCATION_INACTIVE', $invalidTargets[1]['reasons']);
        $this->assertContains('MATERIAL_NOT_ACTIVE', $invalidTargets[1]['reasons']);
        $this->assertContains('APPROVED_REVISION_MISSING', $invalidTargets[1]['reasons']);
        $this->assertSame(0, DB::table('raw_material_location_shares')
            ->whereIn('raw_material_id', [$approvedMaterial['id'], $draftMaterialId])->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE_BULK')
            ->where('event_code', 'LOCATION_SHARE_BULK_REJECTED')
            ->where('outcome', 'REJECTED')
            ->where('reason_code', 'TARGET_VALIDATION_FAILED')->count());
    }

    public function test_bulk_share_denies_unauthorized_and_cross_organization_targets_without_disclosure(): void
    {
        $material = $this->activeCsvImportMaterial('Bulk share — granica autoryzacji');
        $target = $this->activeRestaurant('RBULK_AUTH');
        $this->selectRawMaterialApiContext($this->steward);
        $this->actingAs($this->steward, 'web')->postJson(
            '/api/v1/raw-materials/'.$material['id'].'/shares/bulk',
            ['location_ids' => [$target->id]],
        )->assertForbidden()->assertDontSee($target->id);

        $this->role($this->steward, BusinessRole::Administrator);
        $this->withForeignOrganizationFixture(function (Location $foreignLocation) use ($material): void {
            $response = $this->actingAs($this->steward, 'web')->postJson(
                '/api/v1/raw-materials/'.$material['id'].'/shares/bulk',
                ['location_ids' => [$foreignLocation->id]],
            )->assertForbidden()->assertDontSee($foreignLocation->id);
            $this->assertSame(0, DB::table('raw_material_location_shares')
                ->where('raw_material_id', $material['id'])->count());
            $this->assertSame(1, DB::table('raw_material_audit_events')
                ->where('subject_type', 'RAW_MATERIAL_SHARE_BULK')
                ->where('event_code', 'LOCATION_SHARE_BULK_REJECTED')
                ->where('reason_code', 'UNAUTHORIZED_TARGET')->count());
            $this->assertNotSame('', $response->getContent());
        });
    }

    public function test_bulk_share_audit_failure_rolls_back_every_target_and_audits_rejection(): void
    {
        $material = $this->activeCsvImportMaterial('Bulk share — rollback audytu');
        $targetOne = $this->activeRestaurant('RBULK_ROLLBACK1');
        $targetTwo = $this->activeRestaurant('RBULK_ROLLBACK2');
        $this->role($this->steward, BusinessRole::Administrator);
        $this->withSetupConnection(fn (Connection $connection) => $connection->unprepared(<<<'SQL'
            CREATE FUNCTION public.test_fail_bulk_raw_material_share_audit()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.subject_type = 'RAW_MATERIAL_SHARE' THEN
                    RAISE EXCEPTION 'synthetic bulk share audit failure' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER test_fail_bulk_raw_material_share_audit
                BEFORE INSERT ON public.raw_material_audit_events
                FOR EACH ROW EXECUTE FUNCTION public.test_fail_bulk_raw_material_share_audit();
            SQL));

        try {
            try {
                app(RawMaterialLocationShareAdministration::class)->bulkShareToLocations(
                    $this->bootstrapAdministrator,
                    $this->location,
                    $material['id'],
                    [$targetOne->id, $targetTwo->id],
                );
                $this->fail('A bulk share committed despite a failed target audit write.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->errorInfo[0] ?? null);
            }
        } finally {
            $this->withSetupConnection(fn (Connection $connection) => $connection->unprepared(<<<'SQL'
                DROP TRIGGER test_fail_bulk_raw_material_share_audit ON public.raw_material_audit_events;
                DROP FUNCTION public.test_fail_bulk_raw_material_share_audit();
                SQL));
        }

        $this->assertSame(0, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $material['id'])->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE_BULK')
            ->where('event_code', 'LOCATION_SHARE_BULK_REJECTED')
            ->where('outcome', 'REJECTED')
            ->where('reason_code', 'DATABASE_WRITE_FAILED')->count());
    }

    public function test_concurrent_bulk_share_requests_serialize_on_postgresql(): void
    {
        $material = $this->activeCsvImportMaterial('Bulk share — równoległy');
        $target = $this->activeRestaurant('RBULK_CONCURRENT');
        $operationNames = [];
        $processes = [];
        $committed = false;

        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $this->steward->organization_id)
                ->lockForUpdate()->firstOrFail();
            for ($index = 0; $index < 2; $index++) {
                $applicationName = 'raw-bulk-'.strtolower(Str::random(8));
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/concurrent_raw_material_share.php'),
                    $this->bootstrapAdministrator->id,
                    $this->location->id,
                    $material['id'],
                    $target->id,
                    'bulk-share',
                    $applicationName,
                ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
                $operationNames[] = $applicationName;
            }

            foreach ($processes as $index => $process) {
                $deadline = microtime(true) + 10;
                $waiting = false;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $waiting = (bool) DB::scalar(
                        "SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock')",
                        [$operationNames[$index]],
                    );
                    if ($waiting || ! $process->isRunning()) {
                        break;
                    }
                    usleep(20_000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($waiting, $process->getErrorOutput().$process->getOutput());
            }
            DB::commit();
            $committed = true;
        } finally {
            if (! $committed) {
                foreach ($processes as $process) {
                    if ($process->isRunning()) {
                        $process->stop();
                    }
                }
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        }

        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $outcomes[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['result'];
        }
        $this->assertSame(['accepted', 'accepted'], $outcomes);
        $this->assertSame(1, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $material['id'])->where('location_id', $target->id)
            ->whereNull('revoked_at')->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE')
            ->where('event_code', 'LOCATION_SHARE_CREATED')
            ->whereRaw("metadata->>'raw_material_id' = ?", [$material['id']])->count());
        $this->assertSame(2, DB::table('raw_material_audit_events')
            ->where('subject_type', 'RAW_MATERIAL_SHARE_BULK')
            ->where('event_code', 'LOCATION_SHARE_BULK_COMPLETED')->count());
    }

    public function test_bulk_share_waits_for_revocation_archive_and_location_deactivation(): void
    {
        $administration = app(RawMaterialLocationShareAdministration::class);
        $this->role($this->steward, BusinessRole::Administrator);

        $categoryId = $this->category()['id'];
        $revokedMaterial = $this->activeCsvImportMaterial('Bulk share — po cofnięciu', $categoryId);
        $revokedTarget = $this->activeRestaurant('RBULK_REVOKE_RACE');
        $administration->share($this->bootstrapAdministrator, $this->location, $revokedMaterial['id'], $revokedTarget->id);
        $revocationResult = $this->runBulkShareAfterOrganizationMutation(
            $revokedMaterial['id'],
            $revokedTarget->id,
            fn () => $administration->revoke(
                $this->bootstrapAdministrator,
                $this->location,
                $revokedMaterial['id'],
                $revokedTarget->id,
            ),
        );
        $this->assertSame('ACCEPTED', $revocationResult['data']['outcome']);
        $this->assertSame(1, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $revokedMaterial['id'])->whereNotNull('revoked_at')->count());
        $this->assertSame(1, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $revokedMaterial['id'])->whereNull('revoked_at')->count());

        $archivedMaterial = $this->activeCsvImportMaterial('Bulk share — archiwizacja wyścig', $categoryId);
        $archiveTarget = $this->activeRestaurant('RBULK_ARCHIVE_RACE');
        $archiveResult = $this->runBulkShareAfterOrganizationMutation(
            $archivedMaterial['id'],
            $archiveTarget->id,
            fn () => $this->rawMaterialService->archive(
                $this->steward,
                $this->location,
                $archivedMaterial['id'],
                'Test współbieżności bulk share',
            ),
        );
        $this->assertSame('REJECTED', $archiveResult['data']['outcome']);
        $this->assertContains('MATERIAL_NOT_ACTIVE', $archiveResult['data']['data']['invalid_targets'][0]['reasons']);
        $this->assertSame(0, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $archivedMaterial['id'])->count());

        $deactivatedMaterial = $this->activeCsvImportMaterial('Bulk share — dezaktywacja wyścig', $categoryId);
        $deactivatedTarget = $this->activeRestaurant('RBULK_DEACTIVATE_RACE');
        $deactivateResult = $this->runBulkShareAfterOrganizationMutation(
            $deactivatedMaterial['id'],
            $deactivatedTarget->id,
            fn () => $this->service->updateLocation($deactivatedTarget, ['is_active' => false]),
        );
        $this->assertSame('REJECTED', $deactivateResult['data']['outcome']);
        $this->assertContains('LOCATION_INACTIVE', $deactivateResult['data']['data']['invalid_targets'][0]['reasons']);
        $this->assertSame(0, DB::table('raw_material_location_shares')
            ->where('raw_material_id', $deactivatedMaterial['id'])->count());
    }

    private function runBulkShareAfterOrganizationMutation(
        string $materialId,
        string $targetLocationId,
        callable $mutation,
    ): array {
        $applicationName = 'raw-bulk-race-'.strtolower(Str::random(8));
        $process = null;
        $committed = false;
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $this->steward->organization_id)
                ->lockForUpdate()->firstOrFail();
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/concurrent_raw_material_share.php'),
                $this->bootstrapAdministrator->id,
                $this->location->id,
                $materialId,
                $targetLocationId,
                'bulk-share',
                $applicationName,
            ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
            $process->setTimeout(30);
            $process->start();

            $deadline = microtime(true) + 10;
            $waiting = false;
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
            $mutation();
            DB::commit();
            $committed = true;
        } finally {
            if (! $committed) {
                if ($process?->isRunning()) {
                    $process->stop();
                }
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        }

        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function selectContext(User $actor): void
    {
        Auth::forgetGuards();
        $response = $this->actingAs($actor, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }

    public function test_postgresql_enforces_category_depth_immutable_history_and_master_identity(): void
    {
        $root = $this->category();
        $child = $this->rawMaterialService->createCategory($this->steward, $this->location, [
            'code' => 'GRAIN',
            'name' => 'Zboża',
            'parent_id' => $root['id'],
        ]);
        $grandchild = $this->rawMaterialService->createCategory($this->steward, $this->location, [
            'code' => 'WHEAT',
            'name' => 'Pszenica',
            'parent_id' => $child['id'],
        ]);
        try {
            $this->rawMaterialService->createCategory($this->steward, $this->location, [
                'code' => 'FLOUR',
                'name' => 'Mąka',
                'parent_id' => $grandchild['id'],
            ]);
            $this->fail('A fourth category level was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }

        $material = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $root['id'],
            'name' => 'Cukier',
            'base_unit_code' => 'kg',
            'conversions' => [['unit_code' => 'g']],
        ]);
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('raw_materials')
            ->where('id', $material['id'])->update(['code' => 'SUR-999999']));
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('raw_material_revisions')
            ->where('id', $material['revision']['id'])->update(['name' => 'Zmieniona nazwa']));
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->update(['outcome' => 'REJECTED']));
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->delete());
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('raw_materials')
            ->where('id', $material['id'])->delete());
        $this->assertConstraintViolation('23514', fn (Connection $connection) => $connection->table('raw_material_revision_units')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $material['revision']['id'],
            'unit_code' => 'mg',
            'dimension' => 'MASS',
            'conversion_kind' => 'METRIC',
            'to_base_factor' => '0.5',
        ]));
    }

    private function assertConstraintViolation(string $sqlState, callable $operation): void
    {
        try {
            $this->withSetupTransaction(fn (Connection $connection) => $operation($connection));
            $this->fail('Expected PostgreSQL to reject the invalid operation.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);
        }
    }

    private function insertProductRevisionDirectly(Connection $connection, string $productId, object $revision, string $sku): void
    {
        $connection->table('supplier_product_revisions')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'supplier_product_id' => $productId,
            'revision_no' => 2,
            'raw_material_id' => $revision->raw_material_id,
            'raw_material_revision_id' => $revision->raw_material_revision_id,
            'raw_material_revision_unit_id' => $revision->raw_material_revision_unit_id,
            'supplier_sku' => $sku,
            'product_name' => 'Bezpośredni zapis SQL',
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '2',
            'content_unit_code' => 'g',
            'to_base_factor_snapshot' => $revision->to_base_factor_snapshot,
            'base_quantity_snapshot' => $revision->base_quantity_snapshot,
            'content_hash' => str_repeat('0', 64),
            'created_by' => $this->steward->id,
        ]);
    }

    private function createCommercialOfferFixture(
        string $materialName,
        string $supplierCode,
        string $supplierSku,
        string $keyPrefix,
        ?string $categoryId = null,
    ): array {
        $categoryId ??= $this->category()['id'];
        $materialCode = 'SUR-'.sprintf(
            '%06d',
            DB::table('raw_materials')->where('organization_id', $this->steward->organization_id)->count() + 99,
        );
        $materialId = $this->declaredMaterial($categoryId, $materialName, materialCode: $materialCode);
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Zatwierdzona baza');
        $materialRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('approved_revision_id');
        $this->selectContext($this->steward);

        $commercial = app(RawMaterialCommercialService::class);
        $supplierId = $commercial->createSupplier($this->steward, $this->location, [
            'supplier_code' => $supplierCode,
            'display_name' => $supplierCode,
        ], $keyPrefix.'-supplier-001')['data']['id'];
        $product = $commercial->createProduct($this->steward, $this->location, $materialId, [
            'supplier_id' => $supplierId,
            'raw_material_revision_id' => $materialRevisionId,
            'product_name' => $materialName,
            'supplier_sku' => $supplierSku,
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '1',
            'content_unit_code' => 'g',
        ], $keyPrefix.'-product-001')['data'];
        $productId = $product['id'];
        $productRevisionId = $product['current_revision']['id'];
        $offerId = $commercial->createOffer(
            $this->steward,
            $this->location,
            $productId,
            [],
            $keyPrefix.'-offer-001',
        )['data']['id'];
        $priceEventId = $commercial->recordOfferPriceEvent($this->steward, $this->location, $offerId, [
            'event_type' => 'PRICE_SET',
            'valid_from' => now()->toDateString(),
            'net_amount_per_purchase_unit' => '5.00000000',
            'currency_code' => 'PLN',
            'quantity_from' => '1',
            'reason_code' => 'SUPPLIER_QUOTE',
        ], $keyPrefix.'-price-event-001')['data']['id'];

        return [
            'material_id' => $materialId,
            'supplier_id' => $supplierId,
            'product_id' => $productId,
            'product_revision_id' => $productRevisionId,
            'offer_id' => $offerId,
            'price_event_id' => $priceEventId,
        ];
    }

    private function activeCsvImportMaterial(string $name, ?string $categoryId = null): array
    {
        $categoryId ??= $this->category()['id'];
        $lastSequence = (int) DB::table('organization_sku_assignments')
            ->where('organization_id', $this->steward->organization_id)
            ->where('prefix', 'SUR')
            ->max('sequence_number');
        $code = 'SUR-'.sprintf('%06d', max(99, $lastSequence + 1));
        $materialId = $this->declaredMaterial($categoryId, $name, materialCode: $code);
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Aktywacja testowa CSV');

        return [
            'id' => $materialId,
            'code' => $code,
            'name' => $name,
        ];
    }

    private function activeCsvWorkflowMaterial(
        string $name,
        string $baseUnitCode = 'g',
        string $baseUnitDimension = 'MASS',
        ?array $csvUnit = null,
        ?string $categoryId = null,
        ?string $code = null,
        ?string $description = null,
    ): array {
        $categoryId ??= $this->category()['id'];
        $csvUnit ??= [
            'unit_code' => 'kg',
            'dimension' => 'MASS',
            'conversion_kind' => 'METRIC',
            'to_base_factor' => '1000.000000000000',
            'basis' => null,
        ];
        $materialCount = DB::table('raw_materials')
            ->where('organization_id', $this->steward->organization_id)
            ->count();
        $code ??= 'SUR-'.sprintf('%06d', $materialCount + 99);
        $additionalUnits = $csvUnit['unit_code'] === $baseUnitCode ? [] : [$csvUnit];
        $materialId = $this->declaredMaterial(
            $categoryId,
            $name,
            $additionalUnits,
            $code,
            $baseUnitCode,
            $baseUnitDimension,
            $description,
        );
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Aktywacja testowa importu CSV');

        return [
            'id' => $materialId,
            'code' => $code,
            'name' => $name,
        ];
    }

    private function selectRawMaterialApiContext(User $actor): void
    {
        Auth::forgetGuards();
        $response = $this->actingAs($actor, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }

    private function csvImportRow(array $material, array $overrides = []): array
    {
        return array_replace([
            'ingredient_id' => $material['code'],
            'ingredient_name' => $material['name'],
            'price_net' => '4.125',
            'normalized_net_pln_per_base_unit' => '0.004125',
            'vat_rate' => '5',
            'unit' => 'kg',
            'effective_date' => '2026-01-01',
        ], $overrides);
    }

    private function category(): array
    {
        return $this->rawMaterialService->createCategory($this->steward, $this->location, [
            'code' => 'DRY_GOODS',
            'name' => 'Produkty sypkie',
        ]);
    }

    private function bootstrapFixtureGrants(User $actor, User $recipient): void
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
                'reason_code' => 'INITIAL_STEWARD',
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
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
            }
        });
    }

    private function declaredMaterial(
        string $categoryId,
        string $name,
        array $additionalUnits = [],
        ?string $materialCode = null,
        string $baseUnitCode = 'g',
        string $baseUnitDimension = 'MASS',
        ?string $description = null,
    ): string {
        $materialId = Str::uuid()->toString();
        $revisionId = Str::uuid()->toString();
        $alias = mb_strtolower(trim($name), 'UTF-8');
        $payload = [
            'category_id' => $categoryId,
            'name' => $name,
            'description' => $description,
            'base_unit_code' => $baseUnitCode,
            'base_unit_dimension' => $baseUnitDimension,
            'allergen_state' => 'DECLARED',
        ];
        ksort($payload);
        $units = [[
            'unit_code' => $baseUnitCode,
            'dimension' => $baseUnitDimension,
            'conversion_kind' => 'BASE',
            'to_base_factor' => '1.000000000000',
            'basis' => null,
        ], ...$additionalUnits];
        usort($units, fn (array $left, array $right): int => $left['unit_code'] <=> $right['unit_code']);
        $aliases = [['alias' => $name, 'normalized_alias' => $alias]];
        $hash = hash('sha256', json_encode([
            'payload' => $payload,
            'units' => $units,
            'aliases' => $aliases,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $materialCode ??= 'SUR-000099';
        if (! preg_match('/^(SUR|RAW)-([0-9]{6,9})$/D', $materialCode, $codeParts)) {
            throw new \LogicException('The raw material fixture code is invalid.');
        }
        $prefix = $codeParts[1];

        return $this->withSetupTransaction(function (Connection $connection) use (
            $materialId,
            $revisionId,
            $categoryId,
            $name,
            $materialCode,
            $codeParts,
            $units,
            $aliases,
            $hash,
            $baseUnitCode,
            $baseUnitDimension,
            $description,
            $prefix,
        ): string {
            $counterQuery = $connection->table('organization_sku_counters')
                ->where('organization_id', $this->steward->organization_id)
                ->where('prefix', $prefix);
            $counter = $counterQuery->lockForUpdate()->first();
            if (! $counter) {
                $connection->table('organization_sku_counters')->insert([
                    'organization_id' => $this->steward->organization_id,
                    'prefix' => $prefix,
                    'last_value' => 0,
                ]);
                $counter = (object) ['last_value' => 0];
            }
            $targetCounterValue = (int) $codeParts[2];
            if ($targetCounterValue < (int) $counter->last_value) {
                throw new \LogicException('The raw material fixture code must advance the sequence.');
            }
            while ((int) $counter->last_value < $targetCounterValue) {
                $counterQuery->update(['last_value' => DB::raw('last_value + 1')]);
                $counter->last_value++;
            }
            $connection->table('organization_sku_assignments')->insert([
                'organization_id' => $this->steward->organization_id,
                'sku' => $materialCode,
                'prefix' => $prefix,
                'sequence_number' => (int) $codeParts[2],
                'entity_type' => 'RAW',
                'entity_id' => $materialId,
                'allocation_source' => $prefix === 'SUR' ? 'LEGACY' : 'IMPORTED',
                'assigned_by' => $this->steward->id,
            ]);
            $connection->table('raw_materials')->insert([
                'id' => $materialId,
                'organization_id' => $this->steward->organization_id,
                'code' => $materialCode,
                'lifecycle_state' => 'DRAFT',
                'working_revision_id' => $revisionId,
                'created_by' => $this->steward->id,
            ]);
            $connection->table('raw_material_revisions')->insert([
                'id' => $revisionId,
                'organization_id' => $this->steward->organization_id,
                'raw_material_id' => $materialId,
                'revision_no' => 1,
                'category_id' => $categoryId,
                'name' => $name,
                'description' => $description,
                'base_unit_code' => $baseUnitCode,
                'base_unit_dimension' => $baseUnitDimension,
                'allergen_state' => 'DECLARED',
                'critical_change' => false,
                'created_by' => $this->steward->id,
                'content_hash' => $hash,
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

            return $materialId;
        });
    }

    private function attestWorkingRevision(
        string $materialId,
        ?User $actor = null,
        ?Location $context = null,
    ): void {
        $actor ??= $this->steward;
        $context ??= $this->location;
        $catalogVersionId = Str::uuid()->toString();
        $versionNumber = (int) DB::connection('pgsql_setup')
            ->table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
        $this->withSetupTransaction(function (Connection $connection) use ($catalogVersionId, $versionNumber): void {
            $connection->table('raw_material_allergen_catalog_versions')->insert([
                'id' => $catalogVersionId,
                'version_number' => $versionNumber,
                'status' => 'DRAFT',
            ]);
            $connection->table('raw_material_allergen_definitions')->insert([
                'catalog_version_id' => $catalogVersionId,
                'allergen_code' => 'TEST_WHEAT',
                'name_pl' => 'Pszenica',
                'display_order' => 1,
            ]);
            $connection->table('raw_material_allergen_catalog_versions')
                ->where('id', $catalogVersionId)
                ->update(['status' => 'PUBLISHED', 'published_at' => now()]);
        });

        $this->rawMaterialService->reviseAllergenDeclaration($actor, $context, $materialId, [
            'status' => 'NO_KNOWN_ALLERGENS',
            'catalog_version_id' => $catalogVersionId,
            'items' => [],
        ]);
    }

    private function startRevisionProcess(
        string $operation,
        User $actor,
        Location $context,
        string $materialId,
        ?string $targetLocationId,
        string $namePrefix,
    ): array {
        $applicationName = $namePrefix.Str::lower(Str::random(8));
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/concurrent_raw_material_revision.php'),
            $operation,
            $actor->id,
            $context->id,
            $materialId,
            $targetLocationId ?? '',
            $applicationName,
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'veltra_testing']);
        $process->setTimeout(30);
        $process->start();

        return [$process, $applicationName];
    }

    private function assertRevisionProcessWaitingOnOrganizationLock(
        Process $process,
        string $applicationName,
    ): void {
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

    private function finishRevisionProcess(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
