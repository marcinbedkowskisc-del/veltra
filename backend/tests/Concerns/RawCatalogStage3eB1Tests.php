<?php

namespace Tests\Concerns;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Identity\MembershipService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialCatalogCsvImportWorkflow;
use App\Domain\RawMaterials\RawMaterialCatalogValues;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialCsvCatalogExportWorkflow;
use App\Domain\RawMaterials\RawMaterialCsvImportPersistenceService;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialLocationShareAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialSkuReservationService;
use App\Domain\Sku\SkuAllocationService;
use App\Domain\Sku\SkuPrefix;
use App\Models\LocationModuleState;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

trait RawCatalogStage3eB1Tests
{
    public function test_stage3e_b1_catalog_global_sort_filters_pagination_and_all_fields(): void
    {
        $category = $this->category();
        foreach (range(30, 1) as $index) {
            $this->stage3eMaterial(sprintf('Katalog %02d', $index), 'g', (string) $index, null, $category['id']);
        }
        $page = $this->rawMaterialService->list($this->steward, $this->location, ['per_page' => 25, 'page' => 2]);
        $this->assertSame(['current_page' => 2, 'per_page' => 25, 'total' => 30, 'last_page' => 2, 'from' => 26, 'to' => 30], $page['meta']);
        $this->assertSame('Katalog 26', $page['data'][0]['revision']['name']);
        $this->assertSame(26, $page['data'][0]['ordinal']);
        $this->assertSame('26.000000', $page['data'][0]['nominal_quantity']);
        $this->assertSame('26.000000', $page['data'][0]['usable_quantity']);
        $this->assertSame('100.000000', $page['data'][0]['yield_percent']);
        $this->assertArrayHasKey('reference_net_price', $page['data'][0]);
        $this->assertSame($page['data'][0]['sku'], $page['data'][0]['code']);
        $this->assertSame(50, $this->rawMaterialService->list($this->steward, $this->location, [])['meta']['per_page']);
        foreach (['sku', 'unit', 'name', 'nominal_quantity', 'usable_quantity', 'reference_net_price'] as $sort) {
            $result = $this->rawMaterialService->list($this->steward, $this->location, [
                'sort' => $sort, 'direction' => 'desc', 'per_page' => 25, 'category_id' => $category['id'], 'unit' => 'g', 'lifecycle_state' => 'ACTIVE',
            ]);
            $this->assertCount(25, $result['data']);
            if ($sort === 'nominal_quantity' || $sort === 'usable_quantity') {
                $this->assertSame('30.000000', $result['data'][0][$sort]);
            }
        }
        $this->assertSame(1, $this->rawMaterialService->list($this->steward, $this->location, ['search' => 'kAtAlOg 15'])['meta']['total']);
        $this->assertSame(1, $this->rawMaterialService->list($this->steward, $this->location, ['search' => 'raw-000015'])['meta']['total']);
        $this->assertSame(0, $this->rawMaterialService->list($this->steward, $this->location, ['search' => '%'])['meta']['total']);
        $empty = $this->rawMaterialService->list($this->steward, $this->location, ['page' => 9]);
        $this->assertSame([], $empty['data']);
        $this->assertNull($empty['meta']['from']);
        $this->selectRawMaterialApiContext($this->steward);
        foreach ([25, 50, 100, 200] as $size) {
            $this->getJson('/api/v1/raw-materials?per_page='.$size)->assertOk()->assertJsonPath('meta.per_page', $size);
        }
        $this->getJson('/api/v1/raw-materials?per_page=20')->assertUnprocessable();
        $this->rawMaterialService->archive($this->steward, $this->location, $page['data'][0]['id'], 'Archiwizacja testowa', expectedVersion: $this->rawVersion($page['data'][0]['id']));
        $this->assertSame(29, $this->rawMaterialService->list($this->steward, $this->location, [])['meta']['total']);
        $this->assertSame([], $this->rawMaterialService->list($this->steward, $this->location, ['lifecycle_state' => 'ARCHIVED'])['data']);
    }

    public function test_stage3e_b1_supplier_and_unit_filters_preserve_recipient_scope(): void
    {
        $fixture = $this->createCommercialOfferFixture('Widoczny dostawca', 'FILTER', 'FILTER-1', 'stage3e-filter');
        $categoryId = DB::table('raw_material_categories')->value('id');
        $hidden = $this->stage3eMaterial('Nieudostępniony', 'ml', '100', null, $categoryId);
        $restaurant = $this->activeRestaurant('FILTER_R');
        app(MembershipService::class)->assign($this->steward, $restaurant);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id, 'location_id' => $restaurant->id,
            'module_key' => 'RAW_MATERIALS', 'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id, 'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        app(RawMaterialLocationShareAdministration::class)->share($this->bootstrapAdministrator, $this->location, $fixture['material_id'], $restaurant->id);
        $result = $this->rawMaterialService->list($this->steward, $restaurant, ['supplier_id' => $fixture['supplier_id'], 'unit' => 'g']);
        $this->assertSame([$fixture['material_id']], array_column($result['data'], 'id'));
        $this->assertSame([], $this->rawMaterialService->list($this->steward, $restaurant, ['unit' => 'ml'])['data']);
        $this->assertSame([], $this->rawMaterialService->list($this->steward, $restaurant, ['supplier_id' => Str::uuid()->toString()])['data']);
        $this->assertNotContains($hidden['id'], array_column($result['data'], 'id'));
        app(RawMaterialLocationShareAdministration::class)->revoke($this->bootstrapAdministrator, $this->location, $fixture['material_id'], $restaurant->id);
        $this->assertSame([], $this->rawMaterialService->list($this->steward, $restaurant, ['supplier_id' => $fixture['supplier_id']])['data']);
    }

    public function test_stage3e_b1_quantities_defaults_validation_and_revision_immutability(): void
    {
        $material = $this->stage3eMaterial('Ilości', 'kg', '2', '1.5');
        $approved = $material['revision']['id'];
        $hash = DB::table('raw_material_revisions')->where('id', $approved)->value('content_hash');
        $input = ['category_id' => $material['revision']['category_id'], 'name' => 'Ilości', 'base_unit_code' => 'kg', 'nominal_quantity' => '2', 'usable_quantity' => '1'];
        $revised = $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input, expectedVersion: $this->rawVersion($material['id']));
        $this->assertSame('1.000000', $revised['revision']['usable_quantity']);
        $this->assertSame('1.500000', $this->rawMaterialService->show($this->steward, $this->location, $material['id'])['usable_quantity']);
        $this->assertSame('75.000000', $this->rawMaterialService->show($this->steward, $this->location, $material['id'])['yield_percent']);
        $this->assertSame($hash, DB::table('raw_material_revisions')->where('id', $approved)->value('content_hash'));
        $this->assertDatabaseRejects('23514', fn ($connection) => $connection->table('raw_material_revisions')->where('id', $approved)->update(['usable_quantity' => '0.5']));
        foreach ([
            ['g', '0', '1'], ['g', '-1', '1'], ['g', '1.1234567', '1'], ['g', '1', '0'],
            ['pc', '1.5', '1'], ['pc', '2', '1.5'], ['ml', null, '1'], ['g', '1e3', '1'],
        ] as [$unit, $nominal, $usable]) {
            try {
                RawMaterialCatalogValues::quantities(['base_unit_code' => $unit, 'nominal_quantity' => $nominal, 'usable_quantity' => $usable]);
                $this->fail('Nieprawidłowe ilości zostały zaakceptowane.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['nominal_quantity' => null, 'usable_quantity' => null], RawMaterialCatalogValues::quantities(['base_unit_code' => 'g']));
    }

    public function test_stage3e_b1_decimal_usable_cost_for_every_unit_and_rounding_boundary(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $category = $this->category();
        foreach ([
            ['g', '1000', '800', '0.01', '1.25000000000000', 'PLN/100g'],
            ['kg', '1', '0.8', '10', '1.25000000000000', 'PLN/100g'],
            ['mg', '1000000', '800000', '0.00001', '1.25000000000000', 'PLN/100g'],
            ['ml', '1000', '800', '0.01', '1.25000000000000', 'PLN/100ml'],
            ['l', '1', '0.8', '10', '1.25000000000000', 'PLN/100ml'],
            ['pc', '10', '8', '1', '1.25000000000000', 'PLN/pc'],
            ['g', '1', '1', '0.01005', '1.00500000000000', 'PLN/100g'],
            ['pc', '3', '3', '0.33333333333333', '0.33333333333333', 'PLN/pc'],
        ] as $index => [$unit, $nominal, $usable, $price, $cost, $basis]) {
            $material = $this->stage3eMaterial('Koszt '.$index, $unit, $nominal, $usable, $category['id']);
            $this->stage3ePrice($material['id'], $price, 'stage3e-cost-'.$index);
            $shown = $this->rawMaterialService->show($this->steward, $this->location, $material['id']);
            $this->assertSame($cost, $shown['usable_unit_cost']);
            $this->assertSame($basis, $shown['usable_cost_basis']);
            $this->assertSame($cost, $this->rawMaterialService->list($this->steward, $this->location, ['search' => $material['code']])['data'][0]['usable_unit_cost']);
        }
        $this->assertSame('1.00', (string) DB::scalar("SELECT raw_materials_round_half_even_v1('1.005'::numeric, 2)"));
        $this->assertSame('1.02', (string) DB::scalar("SELECT raw_materials_round_half_even_v1('1.015'::numeric, 2)"));
    }

    public function test_stage3e_b1_financial_fields_and_sorts_are_independently_authorized(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $material = $this->stage3eMaterial('Finanse', 'g', '100', '80');
        $this->stage3ePrice($material['id'], '0.1', 'stage3e-finance');
        $reader = $this->user('stage3e-reader@example.test');
        app(MembershipService::class)->assign($reader, $this->location);
        $permissions = app(OrganizationRawMaterialPermissionAdministration::class);
        $permissions->grant($this->steward, $this->location, $reader, RawMaterialPermission::View, 'Odczyt testowy');
        $shown = $this->rawMaterialService->show($reader, $this->location, $material['id']);
        $this->assertArrayNotHasKey('reference_net_price', $shown);
        $this->assertArrayNotHasKey('usable_unit_cost', $shown);
        $row = $this->rawMaterialService->list($reader, $this->location, [])['data'][0];
        $this->assertArrayNotHasKey('reference_net_price', $row);
        $this->assertArrayNotHasKey('usable_unit_cost', $row);
        foreach (['reference_net_price', 'usable_unit_cost'] as $sort) {
            try {
                $this->rawMaterialService->list($reader, $this->location, ['sort' => $sort]);
                $this->fail('Sortowanie ujawniło zakazane dane finansowe.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->rawMaterialService->list($reader, $this->location, ['supplier_id' => Str::uuid()->toString()]);
            $this->fail('Filtr ujawnił relację dostawcy bez uprawnień.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        Storage::fake('raw_material_csv_exports');
        $export = app(RawMaterialCsvCatalogExportWorkflow::class)->createExtended($reader, $this->location);
        $download = app(RawMaterialCsvCatalogExportWorkflow::class)->authorizeDownload($reader, $this->location, $export['export_id'], true);
        $csv = Storage::disk('raw_material_csv_exports')->get($download['storage_key']);
        $this->assertStringNotContainsString('reference_net_price', $csv);
        $this->assertStringNotContainsString('usable_unit_cost', $csv);
        $this->selectRawMaterialApiContext($reader);
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/reference-prices')->assertForbidden();
        $this->postJson('/api/v1/raw-materials/reference-price-exports', ['as_of_date' => now()->toDateString()])->assertForbidden();
        $permissions->grant($this->steward, $this->location, $reader, RawMaterialPermission::ReferencePriceView, 'Cena bez foodcost');
        $shown = $this->rawMaterialService->show($reader, $this->location, $material['id']);
        $this->assertSame('10.00000000000000', $shown['reference_net_price']);
        $this->assertArrayNotHasKey('usable_unit_cost', $shown);
    }

    public function test_stage3e_b1_reference_mutation_requires_administrator_even_with_grant(): void
    {
        $material = $this->stage3eMaterial('Zakaz cen', 'g', '100');
        $this->selectRawMaterialApiContext($this->steward);
        $this->postJson('/api/v1/raw-materials/'.$material['id'].'/reference-prices', [
            'event_type' => 'PRICE_SET', 'effective_from' => now()->toDateString(),
            'net_pln_per_package' => '10', 'expected_event_no' => 0, 'reason_code' => 'TEST',
        ], ['Idempotency-Key' => 'stage3e-denied-price'])->assertForbidden();
        try {
            app(RawMaterialCsvImportPersistenceService::class)->recordPreparedBatch(
                $this->steward, $this->location, str_repeat('a', 64), 'stage3e-denied-import',
                [$this->csvImportRow(['code' => $material['code'], 'name' => 'Zakaz cen'])],
            );
            $this->fail('Nieadministrator zaimportował cenę.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('raw_material_reference_price_events')->count());
        }
    }

    public function test_stage3e_b1_manual_unchanged_price_keeps_attempt_audit_without_history_duplicate(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $material = $this->stage3eMaterial('Cena opakowania', 'g', '1000', '800');
        $this->selectRawMaterialApiContext($this->steward);
        $url = '/api/v1/raw-materials/'.$material['id'].'/reference-prices';
        $input = ['event_type' => 'PRICE_SET', 'effective_from' => now()->toDateString(), 'net_pln_per_package' => '10.00', 'expected_event_no' => 0, 'reason_code' => 'TEST'];
        $first = $this->postJson($url, $input, ['Idempotency-Key' => 'stage3e-price-first'])->assertCreated()->json('data.id');
        $input['expected_event_no'] = 1;
        $input['net_pln_per_package'] = '10.000';
        $this->postJson($url, $input, ['Idempotency-Key' => 'stage3e-price-same'])->assertCreated()->assertJsonPath('data.id', $first)->assertJsonPath('changed', false);
        $this->assertSame(1, DB::table('raw_material_reference_price_events')->count());
        $this->assertSame(1, DB::table('raw_materials_commercial_audit_events')->where('event_code', 'REFERENCE_PRICE_UNCHANGED')->count());
        $this->assertSame('10.00000000000000', $this->rawMaterialService->show($this->steward, $this->location, $material['id'])['reference_net_price']);
    }

    public function test_stage3e_b1_package_price_remains_exact_with_recurring_base_conversion(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $material = $this->stage3eMaterial('Cena dokładna', 'pc', '3');
        $service = app(RawMaterialCommercialService::class);
        $input = ['event_type' => 'PRICE_SET', 'effective_from' => now()->toDateString(), 'net_pln_per_package' => '1', 'expected_event_no' => 0, 'reason_code' => 'TEST'];
        $service->setReferencePrice($this->steward, $this->location, $material['id'], $input, 'stage3e-exact-package');
        $shown = $this->rawMaterialService->show($this->steward, $this->location, $material['id']);
        $this->assertSame('1.00000000000000', $shown['reference_net_price']);
        $this->assertSame('0.33333333333333', $shown['usable_unit_cost']);
        $this->assertSame('1.00000000000000', DB::table('raw_material_reference_price_events')->value('net_pln_per_package'));
    }

    public function test_stage3e_b1_package_price_withdrawal_preserves_legacy_history_and_clears_derived_values(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $material = $this->stage3eMaterial('Cena wycofana', 'g', '100', '80');
        $this->stage3ePrice($material['id'], '0.1', 'stage3e-withdraw-first');
        $this->selectRawMaterialApiContext($this->steward);
        $this->postJson('/api/v1/raw-materials/'.$material['id'].'/reference-prices', [
            'event_type' => 'PRICE_WITHDRAWN', 'effective_from' => now()->toDateString(),
            'expected_event_no' => 1, 'reason_code' => 'TEST_WITHDRAWAL',
        ], ['Idempotency-Key' => 'stage3e-withdraw-second'])->assertCreated()->assertJsonPath('data.net_pln_per_package', null);
        $shown = $this->rawMaterialService->show($this->steward, $this->location, $material['id']);
        $this->assertNull($shown['reference_net_price']);
        $this->assertNull($shown['usable_unit_cost']);
        $this->assertSame(2, DB::table('raw_material_reference_price_events')->count());
    }

    public function test_stage3e_b1_foodcost_grant_does_not_grant_reference_prices_or_purchase_prices(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $material = $this->stage3eMaterial('Finanse odbiorcy', 'g', '100', '80');
        $this->stage3ePrice($material['id'], '0.1', 'stage3e-foodcost-price');
        $restaurant = $this->activeRestaurant('FOODCOST_R');
        $reader = $this->user('stage3e-foodcost@example.test');
        app(MembershipService::class)->assign($reader, $restaurant);
        $this->role($reader, BusinessRole::Employee, $restaurant);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $reader->organization_id, 'location_id' => $restaurant->id,
            'module_key' => 'RAW_MATERIALS', 'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id, 'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        app(RawMaterialLocationShareAdministration::class)->share($this->bootstrapAdministrator, $this->location, $material['id'], $restaurant->id);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant($this->steward, $this->location, $reader, RawMaterialPermission::View, 'Odczyt');
        app(PermissionAdministration::class)->grant(
            $this->steward, $this->location, $reader, $restaurant, Permission::FoodcostView,
        );
        $shown = $this->rawMaterialService->show($reader, $restaurant, $material['id']);
        $this->assertArrayNotHasKey('reference_net_price', $shown);
        $this->assertArrayNotHasKey('usable_unit_cost', $shown);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant($this->steward, $this->location, $reader, RawMaterialPermission::ReferencePriceView, 'Referencja');
        $shown = $this->rawMaterialService->show($reader, $restaurant, $material['id']);
        $this->assertSame('12.50000000000000', $shown['usable_unit_cost']);
        $this->assertFalse(app(BusinessAuthorization::class)->allows(
            $reader, $restaurant, $restaurant, Permission::PurchasePricesView,
        ));
    }

    public function test_stage3e_b1_new_catalog_import_rejects_seven_digits_and_reserved_form_numbers(): void
    {
        Storage::fake('raw_material_csv_imports');
        $category = $this->category();
        $reservation = app(RawMaterialSkuReservationService::class)->reserve($this->steward, $this->location, 'stage3e-import-form');
        $csv = "sku;entity_type;name;description;base_unit_code;category_code;nominal_quantity;usable_quantity\n"
            ."RAW-1000000;RAW;Za duży;;g;{$category['code']};1;\n"
            ."{$reservation['sku']};RAW;Rezerwacja formularza;;g;{$category['code']};1;\n";
        $result = app(RawMaterialCatalogCsvImportWorkflow::class)->upload(
            $this->steward, $this->location, UploadedFile::fake()->createWithContent('range.csv', $csv),
        );
        $this->assertFalse($result['can_commit']);
        $this->assertSame(0, DB::table('raw_materials')->count());
    }

    public function test_stage3e_b1_durable_sku_reservation_abandonment_consumption_and_conflict(): void
    {
        $service = app(RawMaterialSkuReservationService::class);
        $first = $service->reserve($this->steward, $this->location, 'stage3e-reserve-first');
        $this->assertSame('RAW-000001', $first['sku']);
        $this->assertSame(0, DB::table('raw_materials')->count());
        $this->assertSame($first, $service->reserve($this->steward, $this->location, 'stage3e-reserve-first'));
        $second = $service->reserve($this->steward, $this->location, 'stage3e-reserve-second');
        $this->assertSame('RAW-000002', $second['sku']);
        $input = [
            'category_id' => $this->category()['id'], 'name' => 'Rezerwacja', 'base_unit_code' => 'g',
            'nominal_quantity' => '1000', 'sku_reservation_id' => $second['reservation_id'],
            'conversions' => [['unit_code' => 'kg', 'to_base_factor' => '1000']],
        ];
        $created = $this->rawMaterialService->create($this->steward, $this->location, $input);
        $this->assertSame($second['sku'], $created['code']);
        $input['conversions'][0] = ['to_base_factor' => '1000', 'unit_code' => 'kg'];
        $this->assertEquals($created, $this->rawMaterialService->create($this->steward, $this->location, $input));
        $this->assertSame(2, (int) DB::table('organization_sku_counters')->where('prefix', 'RAW')->value('last_value'));
        $this->assertSame(1, DB::table('raw_materials')->count());
        $this->assertSame('CONSUMED', $service->reserve($this->steward, $this->location, 'stage3e-reserve-second')['status']);
        $this->expectException(RawMaterialException::class);
        $this->rawMaterialService->create($this->steward, $this->location, array_replace($input, ['name' => 'Inny zapis']));
    }

    public function test_stage3e_b1_sku_expiry_never_recycles_and_claims_are_actor_bound(): void
    {
        $service = app(RawMaterialSkuReservationService::class);
        $first = $service->reserve($this->steward, $this->location, 'stage3e-expiry-first');
        $input = [
            'category_id' => $this->category()['id'],
            'name' => 'Wygasły',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            'sku_reservation_id' => $first['reservation_id'],
        ];
        $this->travel(25)->hours();
        try {
            $this->assertSame('EXPIRED', $service->reserve($this->steward, $this->location, 'stage3e-expiry-first')['status']);
            $this->rawMaterialService->create($this->steward, $this->location, $input);
            $this->fail('Wygasła rezerwacja została wykorzystana.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_SKU_RESERVATION_EXPIRED', $exception->errorCode);
        } finally {
            $this->travelBack();
        }
        $other = $this->user('stage3e-other@example.test');
        app(MembershipService::class)->assign($other, $this->location);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant($this->steward, $this->location, $other, RawMaterialPermission::Create, 'Test izolacji rezerwacji');
        try {
            $this->rawMaterialService->create($other, $this->location, $input);
            $this->fail('Inny aktor wykorzystał rezerwację.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_SKU_RESERVATION_NOT_FOUND', $exception->errorCode);
        }
        $this->assertSame('RAW-000002', $service->reserve($this->steward, $this->location, 'stage3e-expiry-next')['sku']);
    }

    public function test_stage3e_b1_sku_six_digit_exhaustion_and_import_collision(): void
    {
        $allocation = app(SkuAllocationService::class);
        $reservation = app(RawMaterialSkuReservationService::class)->reserve($this->steward, $this->location, 'stage3e-collision');
        try {
            DB::transaction(fn () => $allocation->reserveImported($this->steward, SkuPrefix::Raw, $reservation['sku'], Str::uuid()->toString()));
            $this->fail('Import przejął rezerwację formularza.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }
        DB::transaction(fn () => $allocation->reserveImported($this->steward, SkuPrefix::Raw, 'RAW-999998', Str::uuid()->toString()));
        $this->assertSame('RAW-999999', app(RawMaterialSkuReservationService::class)->reserve($this->steward, $this->location, 'stage3e-last-sku')['sku']);
        foreach (range(1, 2) as $attempt) {
            try {
                app(RawMaterialSkuReservationService::class)->reserve($this->steward, $this->location, 'stage3e-exhausted-'.$attempt);
                $this->fail('Wydano siedmiocyfrowy RAW.');
            } catch (RawMaterialException $exception) {
                $this->assertSame('RAW_MATERIAL_CODE_CAPACITY_REACHED', $exception->errorCode);
            }
        }
        $this->assertNull(SkuPrefix::Raw->parse('RAW-1000000'));
        $this->assertSame('INT-1000000', SkuPrefix::Intermediate->format(1000000));
        $this->assertSame('PRD-1000000', SkuPrefix::Product->format(1000000));
        $this->assertSame('MEN-1000000', SkuPrefix::Menu->format(1000000));
        $this->assertSame('MEN-000001', SkuPrefix::Menu->format(1));
        try {
            $this->rawMaterialService->create($this->steward, $this->location, [
                'category_id' => $this->category()['id'],
                'name' => 'Brak numeru',
                'base_unit_code' => 'g',
                'nominal_quantity' => '1000',
            ]);
            $this->fail('Zapis ominął wyczerpanie zakresu RAW.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_CODE_CAPACITY_REACHED', $exception->errorCode);
        }
    }

    public function test_stage3e_b1_sku_http_contract_and_abuse_limit(): void
    {
        $this->selectRawMaterialApiContext($this->steward);
        $url = '/api/v1/raw-materials/sku-reservations';
        $this->postJson($url)->assertUnprocessable();
        $reservation = $this->postJson($url, [], ['Idempotency-Key' => 'stage3e-http-reservation'])->assertCreated()->assertJsonPath('data.sku', 'RAW-000001')->json('data');
        $input = ['category_id' => $this->category()['id'], 'name' => 'HTTP rezerwacja', 'base_unit_code' => 'pc', 'nominal_quantity' => '6', 'sku_reservation_id' => $reservation['reservation_id']];
        $this->postJson('/api/v1/raw-materials', $input)->assertCreated()->assertJsonPath('data.code', 'RAW-000001');
        $this->postJson('/api/v1/raw-materials', $input)->assertCreated()->assertJsonPath('data.code', 'RAW-000001');
        $this->postJson('/api/v1/raw-materials', $input + ['sku' => 'RAW-999999'])->assertUnprocessable();
        foreach (range(1, 18) as $index) {
            $this->postJson($url, [], ['Idempotency-Key' => 'stage3e-http-rate-'.$index])->assertCreated();
        }
        $this->postJson($url, [], ['Idempotency-Key' => 'stage3e-http-rate-over'])->assertTooManyRequests();
        $this->assertSame(1, DB::table('raw_materials')->count());
    }

    public function test_stage3e_b1_parallel_reservations_and_idempotent_parallel_creation(): void
    {
        $category = $this->category();
        $processes = [];
        try {
            foreach (['reserve', 'create', 'create-distinct'] as $operation) {
                DB::beginTransaction();
                Organization::whereKey($this->steward->organization_id)->lockForUpdate()->firstOrFail();
                foreach ([1, 2] as $index) {
                    $application = 'stage3eb1-'.$operation.'-'.$index;
                    $process = new Process([
                        PHP_BINARY, base_path('tests/Support/concurrent_raw_catalog.php'),
                        $this->steward->id, $this->location->id, $category['id'], $operation,
                        $operation === 'reserve' ? 'stage3e-parallel-'.$index
                            : ($operation === 'create-distinct' && $index === 2 ? $otherReservation['reservation_id'] : $reservation['reservation_id']),
                        $application,
                    ], base_path(), timeout: 30);
                    $process->start();
                    $processes[] = $process;
                    $this->assertRevisionProcessWaitingOnOrganizationLock($process, $application);
                }
                DB::commit();
                $second = $this->finishRevisionProcess(array_pop($processes));
                $first = $this->finishRevisionProcess(array_pop($processes));
                if ($operation === 'reserve') {
                    $this->assertNotSame($first['sku'], $second['sku']);
                    $reservation = $first;
                    $otherReservation = $second;
                } elseif ($operation === 'create') {
                    $this->assertEquals($first, $second);
                } else {
                    $this->assertNotSame($first['id'], $second['id']);
                }
            }
            $this->assertSame(2, DB::table('raw_materials')->count());
            $this->assertSame(2, DB::table('organization_sku_assignments')->where('prefix', 'RAW')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    private function stage3eMaterial(string $name, string $unit, string $nominal, ?string $usable = null, ?string $categoryId = null): array
    {
        $input = [
            'category_id' => $categoryId ?? $this->category()['id'], 'name' => $name, 'base_unit_code' => $unit, 'nominal_quantity' => $nominal,
            'allergen_declaration' => ['status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $this->publishedAllergenCatalogFixture(), 'items' => []],
        ];
        if ($usable !== null) {
            $input['usable_quantity'] = $usable;
        }
        $material = $this->rawMaterialService->create($this->steward, $this->location, $input);
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id'], expectedVersion: $this->rawVersion($material['id']));

        return $this->rawMaterialService->approve($this->steward, $this->location, $material['id'], 'Zatwierdzenie testowe 3E-B1', expectedVersion: $this->rawVersion($material['id']));
    }

    private function stage3ePrice(string $materialId, string $amount, string $key): array
    {
        return app(RawMaterialCommercialService::class)->setReferencePrice($this->steward, $this->location, $materialId, [
            'event_type' => 'PRICE_SET', 'effective_from' => now()->toDateString(), 'net_pln_per_base_unit' => $amount,
            'expected_event_no' => 0, 'reason_code' => 'STAGE3E_TEST',
        ], $key);
    }
}
