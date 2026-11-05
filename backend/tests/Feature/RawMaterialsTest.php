<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Installation\LocationType;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\ModuleDefinition;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialCsvImportPersistenceService;
use App\Domain\RawMaterials\RawMaterialCsvParser;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $this->location = $this->activeRestaurant();
        $this->bootstrapAdministrator = $this->user('raw-material-bootstrap@example.test');
        $this->role($this->bootstrapAdministrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->bootstrapAdministrator, $this->location);
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

        $this->assertSame('SUR-000001', $material['code']);
        $this->assertSame('DRAFT', $material['lifecycle_state']);
        $this->assertSame('UNKNOWN', $material['revision']['allergen_state']);
        $this->assertSame('MASS', $material['revision']['base_unit_dimension']);
        $this->assertSame('0.001000000000', DB::table('raw_material_revision_units')
            ->where('revision_id', $material['revision']['id'])->where('unit_code', 'g')->value('to_base_factor'));
        $this->assertSame(2, DB::table('raw_material_aliases')->where('revision_id', $material['revision']['id'])->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->where('event_code', 'CREATED')->count());
        DB::statement('SET CONSTRAINTS raw_material_code_counter_consistency, raw_materials_code_counter_consistency IMMEDIATE');
        $this->assertSame(1, (int) DB::table('raw_material_code_counters')
            ->where('organization_id', $this->steward->organization_id)
            ->where('prefix', 'SUR')->value('last_value'));
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
            ->get($export['download_url'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
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
        app(MembershipService::class)->assign($manager, $this->location);
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

        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplierId)->where('event_code', 'SUPPLIER_CREATED')->count());

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
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_id', $supplierId)->where('event_code', 'SUPPLIER_ARCHIVED')->count());
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
        $materialCount = DB::table('raw_materials')
            ->where('organization_id', $this->steward->organization_id)
            ->count();
        $code = 'SUR-'.sprintf('%06d', $materialCount + 99);
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
        $code = 'SUR-'.sprintf('%06d', $materialCount + 99);
        $additionalUnits = $csvUnit['unit_code'] === $baseUnitCode ? [] : [$csvUnit];
        $materialId = $this->declaredMaterial(
            $categoryId,
            $name,
            $additionalUnits,
            $code,
            $baseUnitCode,
            $baseUnitDimension,
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
    ): string {
        $materialId = Str::uuid()->toString();
        $revisionId = Str::uuid()->toString();
        $alias = mb_strtolower(trim($name), 'UTF-8');
        $payload = [
            'category_id' => $categoryId,
            'name' => $name,
            'description' => null,
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
        if (! preg_match('/^SUR-([0-9]{6,9})$/D', $materialCode, $codeParts)) {
            throw new \LogicException('The raw material fixture code is invalid.');
        }

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
        ): string {
            $counterQuery = $connection->table('raw_material_code_counters')
                ->where('organization_id', $this->steward->organization_id)
                ->where('prefix', 'SUR');
            $counter = $counterQuery->lockForUpdate()->first();
            if (! $counter) {
                $connection->table('raw_material_code_counters')->insert([
                    'organization_id' => $this->steward->organization_id,
                    'prefix' => 'SUR',
                    'last_value' => 0,
                ]);
                $counter = (object) ['last_value' => 0];
            }
            $targetCounterValue = (int) $codeParts[1];
            if ($targetCounterValue < (int) $counter->last_value) {
                throw new \LogicException('The raw material fixture code must advance the sequence.');
            }
            while ((int) $counter->last_value < $targetCounterValue) {
                $counterQuery->update(['last_value' => DB::raw('last_value + 1')]);
                $counter->last_value++;
            }
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

    private function attestWorkingRevision(string $materialId): void
    {
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

        $this->rawMaterialService->reviseAllergenDeclaration($this->steward, $this->location, $materialId, [
            'status' => 'NO_KNOWN_ALLERGENS',
            'catalog_version_id' => $catalogVersionId,
            'items' => [],
        ]);
    }
}
