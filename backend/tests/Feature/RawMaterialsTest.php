<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        Organization::query()->whereKey($this->steward->organization_id)->update(['authorization_initialized' => true]);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::create([
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
        $pendingRevisionId = DB::table('raw_materials')->where('id', $materialId)->value('working_revision_id');
        $this->assertNotSame($approvedRevisionId, $pendingRevisionId);
        $this->assertSame('DECLARED', DB::table('raw_material_revisions')
            ->where('id', $pendingRevisionId)->value('allergen_state'));
        $this->assertSame('Materiał przed zmianą', $this->rawMaterialService
            ->show($this->steward, $this->location, $materialId)['revision']['name']);

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
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Ponowna akceptacja');

        $this->assertSame($code, DB::table('raw_materials')->where('id', $materialId)->value('code'));
        $this->assertSame(2, DB::table('raw_material_revisions')->where('raw_material_id', $materialId)->count());
        $this->assertSame(2, DB::table('raw_material_revision_approval_events as approvals')
            ->join('raw_material_revisions as revisions', 'revisions.id', '=', 'approvals.revision_id')
            ->where('revisions.raw_material_id', $materialId)
            ->where('approvals.outcome', 'APPROVED')->count());
        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $materialId)->value('lifecycle_state'));
        $this->assertSame(7, DB::table('raw_material_audit_events')->where('subject_id', $materialId)->count());
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

        try {
            DB::transaction(fn () => DB::table('supplier_product_revisions')->where('id', $productRevisionId)
                ->update(['product_name' => 'Niedozwolona zmiana']));
            $this->fail('A supplier-product revision was mutable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0] ?? null);
        }
        try {
            DB::transaction(fn () => DB::table('supplier_offer_events')->where('id', $eventId)
                ->update(['net_amount_per_purchase_unit' => '100']));
            $this->fail('A supplier-price event was mutable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0] ?? null);
        }
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
        DB::table('raw_materials_commercial_idempotency')->insert([
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

        $this->assertConstraintViolation('23514', fn () => DB::table('supplier_offers')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'supplier_product_id' => $fixture['product_id'],
            'created_by' => $this->steward->id,
        ]));
        $this->assertConstraintViolation('23514', fn () => DB::table('supplier_offer_events')->insert([
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

        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_reference_price_events')->insert([
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
        $this->assertConstraintViolation('23514', fn () => $this->insertProductRevisionDirectly(
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
        $this->assertConstraintViolation('23514', fn () => $this->insertProductRevisionDirectly(
            $archivedProduct['product_id'],
            $productRevision,
            'ARCH-P-2',
        ));
    }

    public function test_commercial_mutation_rolls_back_when_append_only_audit_fails(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION raw_materials_test_fail_commercial_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Injected audit failure' USING ERRCODE = 'P0001';
            END
            $$;
            CREATE TRIGGER raw_materials_test_fail_commercial_audit
                BEFORE INSERT ON raw_materials_commercial_audit_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_test_fail_commercial_audit();
            SQL);

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
            DB::unprepared('DROP TRIGGER raw_materials_test_fail_commercial_audit ON raw_materials_commercial_audit_events');
            DB::unprepared('DROP FUNCTION raw_materials_test_fail_commercial_audit()');
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
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_materials')
            ->where('id', $material['id'])->update(['code' => 'SUR-999999']));
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_revisions')
            ->where('id', $material['revision']['id'])->update(['name' => 'Zmieniona nazwa']));
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->update(['outcome' => 'REJECTED']));
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->delete());
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_materials')
            ->where('id', $material['id'])->delete());
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_revision_units')->insert([
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
            DB::transaction($operation);
            $this->fail('Expected PostgreSQL to reject the invalid operation.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);
        }
    }

    private function insertProductRevisionDirectly(string $productId, object $revision, string $sku): void
    {
        DB::table('supplier_product_revisions')->insert([
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
        DB::table('raw_material_audit_events')->insert([
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
            DB::table('organization_permission_grants')->insert([
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
    }

    private function declaredMaterial(
        string $categoryId,
        string $name,
        array $additionalUnits = [],
        ?string $materialCode = null,
    ): string {
        $materialId = Str::uuid()->toString();
        $revisionId = Str::uuid()->toString();
        $alias = mb_strtolower(trim($name), 'UTF-8');
        $payload = [
            'category_id' => $categoryId,
            'name' => $name,
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
        ], ...$additionalUnits];
        $aliases = [['alias' => $name, 'normalized_alias' => $alias]];
        $hash = hash('sha256', json_encode([
            'payload' => $payload,
            'units' => $units,
            'aliases' => $aliases,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        DB::table('raw_materials')->insert([
            'id' => $materialId,
            'organization_id' => $this->steward->organization_id,
            'code' => $materialCode ?? 'SUR-000099',
            'lifecycle_state' => 'DRAFT',
            'working_revision_id' => $revisionId,
            'created_by' => $this->steward->id,
        ]);
        DB::table('raw_material_revisions')->insert([
            'id' => $revisionId,
            'organization_id' => $this->steward->organization_id,
            'raw_material_id' => $materialId,
            'revision_no' => 1,
            'category_id' => $categoryId,
            'name' => $name,
            'base_unit_code' => 'g',
            'base_unit_dimension' => 'MASS',
            'allergen_state' => 'DECLARED',
            'critical_change' => false,
            'created_by' => $this->steward->id,
            'content_hash' => $hash,
        ]);
        foreach ($units as $unit) {
            DB::table('raw_material_revision_units')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->steward->organization_id,
                'revision_id' => $revisionId,
                ...$unit,
            ]);
        }
        DB::table('raw_material_aliases')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'raw_material_id' => $materialId,
            'revision_id' => $revisionId,
            ...$aliases[0],
        ]);

        return $materialId;
    }
}
