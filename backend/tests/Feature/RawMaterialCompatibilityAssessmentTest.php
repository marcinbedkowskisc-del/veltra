<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\Documents\DocumentScanner;
use App\Domain\RawMaterials\Documents\DocumentScannerException;
use App\Domain\RawMaterials\Documents\DocumentScanStatus;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentScanService;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentService;
use App\Domain\RawMaterials\RawMaterialCommercialService;
use App\Domain\RawMaterials\RawMaterialCompatibilityAssessmentService;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialLocationShareAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialCompatibilityAssessmentTest extends AuthorizationTestCase
{
    private Location $location;

    private User $administrator;

    private User $steward;

    private string $materialId;

    private string $rawMaterialRevisionId;

    private string $supplierId;

    private string $productId;

    private string $supplierProductRevisionId;

    private string $storageRoot;

    private RawMaterialCompatibilityAssessmentService $assessments;

    private RawMaterialDocumentService $documents;

    private RawMaterialCommercialService $commercial;

    private RawMaterialService $rawMaterials;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->storageRoot = storage_path('framework/testing/raw-material-compatibility/'.Str::uuid());
        config(['filesystems.disks.raw_material_documents.root' => $this->storageRoot]);
        app('filesystem')->forgetDisk('raw_material_documents');

        $this->activeRestaurant();
        $this->location = $this->primaryCentralKitchen();
        $this->administrator = $this->user('compatibility-admin@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->location, $this->administrator->id);
        $this->steward = $this->user('compatibility-steward@example.test');
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
        $this->commercial = app(RawMaterialCommercialService::class);
        $this->documents = app(RawMaterialDocumentService::class);
        $this->assessments = app(RawMaterialCompatibilityAssessmentService::class);

        $category = $this->rawMaterials->createCategory($this->steward, $this->location, [
            'code' => 'COMPATIBILITY',
            'name' => 'Testy zgodności',
        ]);
        $material = $this->rawMaterials->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Surowiec testowy do oceny zgodności',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
        ]);
        $this->materialId = $material['id'];
        $catalogVersionId = $this->publishedCatalog();
        $this->rawMaterials->reviseAllergenDeclaration($this->steward, $this->location, $this->materialId, [
            'status' => 'DECLARED',
            'catalog_version_id' => $catalogVersionId,
            'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
        ], expectedVersion: $this->rawVersion($this->materialId));
        $this->rawMaterials->submit($this->steward, $this->location, $this->materialId, expectedVersion: $this->rawVersion($this->materialId));
        $this->rawMaterials->approve($this->steward, $this->location, $this->materialId, 'Zatwierdzenie syntetycznego surowca', expectedVersion: $this->rawVersion($this->materialId));
        $this->rawMaterialRevisionId = DB::table('raw_materials')
            ->where('id', $this->materialId)
            ->value('approved_revision_id');

        $suffix = Str::upper(Str::random(8));
        $this->supplierId = $this->commercial->createSupplier($this->steward, $this->location, [
            'supplier_code' => 'COMPAT_'.$suffix,
            'display_name' => 'Dostawca testowy zgodności',
        ], 'compat-supplier-'.$suffix)['data']['id'];
        $product = $this->commercial->createProduct($this->steward, $this->location, $this->materialId, [
            'supplier_id' => $this->supplierId,
            'raw_material_revision_id' => $this->rawMaterialRevisionId,
            'product_name' => 'Produkt syntetyczny do oceny',
            'supplier_sku' => 'COMPAT-'.$suffix,
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '1',
            'content_unit_code' => 'g',
        ], 'compat-product-'.$suffix);
        $this->productId = $product['data']['id'];
        $this->supplierProductRevisionId = $product['data']['current_revision']['id'];
    }

    protected function tearDown(): void
    {
        try {
            (new Filesystem)->deleteDirectory($this->storageRoot);
        } finally {
            parent::tearDown();
        }
    }

    public function test_business_decisions_bind_exact_clean_evidence_and_are_immutable_audited_and_idempotent(): void
    {
        $evidence = $this->createEvidence("synthetic supplier certificate\n");
        $scan = app(RawMaterialDocumentScanService::class)->scanVersion($evidence['version']['id']);
        $this->assertSame(DocumentScanStatus::Clean->value, $scan['status']);

        $context = $this->assessments->history($this->steward, $this->location, $this->productId);
        $this->assertSame('PENDING', $context['status']);
        $this->assertSame($this->supplierProductRevisionId, $context['assessment_context']['supplier_product_revision_id']);
        $this->assertSame($this->rawMaterialRevisionId, $context['assessment_context']['raw_material_revision_id']);
        $this->assertSame('DECLARED', $context['assessment_context']['allergen_attestation']['status']);
        $this->assertSame('DIRECT', $context['assessment_context']['allergen_attestation']['allergens'][0]['exposure_kind']);
        $this->assertSame($evidence['version']['id'], $context['assessment_context']['clean_evidence'][0]['document_version_id']);

        $input = $this->decision($evidence['version']['id'], 'COMPATIBLE');
        $result = $this->assessments->assess($this->steward, $this->location, $this->productId, $input, 'compat-assess-0001');
        $retried = $this->assessments->assess($this->steward, $this->location, $this->productId, $input, 'compat-assess-0001');
        $assessmentId = $result['data']['assessment_id'];

        $this->assertSame($assessmentId, $retried['data']['assessment_id']);
        $this->assertSame($this->supplierProductRevisionId, $result['data']['supplier_product_revision_id']);
        $this->assertSame($this->rawMaterialRevisionId, $result['data']['raw_material_revision_id']);
        $this->assertSame($evidence['version']['id'], $result['data']['document_version_id']);
        $this->assertSame($evidence['version']['sha256'], $result['data']['document_sha256']);
        $this->assertSame($this->steward->id, $result['data']['assessed_by']);
        $this->assertNotEmpty($result['data']['assessed_at']);
        $this->assertSame('COMPATIBLE', $this->assessments->history(
            $this->steward,
            $this->location,
            $this->productId,
        )['status']);

        $incompatible = $this->assessments->assess(
            $this->steward,
            $this->location,
            $this->productId,
            $this->decision($evidence['version']['id'], 'INCOMPATIBLE', 'Niezgodność wykazana w przeglądzie testowym.'),
            'compat-assess-0002',
        );
        $history = $this->assessments->history($this->steward, $this->location, $this->productId);
        $this->assertSame('INCOMPATIBLE', $history['status']);
        $this->assertSame('CURRENT', $history['history'][0]['applicability']);
        $this->assertSame('HISTORICAL', $history['history'][1]['applicability']);
        $this->assertNotSame($assessmentId, $incompatible['data']['assessment_id']);
        $this->assertSame(2, DB::table('raw_material_supplier_compatibility_assessments')
            ->where('supplier_product_id', $this->productId)->count());
        $this->assertSame(2, DB::table('raw_materials_commercial_audit_events')
            ->where('subject_type', 'SUPPLIER_COMPATIBILITY_ASSESSMENT')
            ->whereIn('subject_id', [$assessmentId, $incompatible['data']['assessment_id']])
            ->count());
        $this->assertSame(2, DB::table('raw_materials_commercial_idempotency')
            ->where('operation_code', 'supplier-compatibility.assess')->count());

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('raw_material_supplier_compatibility_assessments')
            ->where('id', $assessmentId)->update(['outcome' => 'INCOMPATIBLE']));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('raw_material_supplier_compatibility_assessments')
            ->where('id', $assessmentId)->delete());

        try {
            $this->assessments->assess(
                $this->steward,
                $this->location,
                $this->productId,
                $this->decision($evidence['version']['id'], 'INCOMPATIBLE'),
                'compat-assess-0001',
            );
            $this->fail('A conflicting idempotency-key reuse was accepted.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }
    }

    public function test_pending_infected_and_error_scans_are_rejected_as_assessment_evidence(): void
    {
        $pending = $this->createEvidence("pending synthetic evidence\n");
        $this->assertEvidenceRejected($pending['version']['id'], 'PENDING_EVIDENCE');

        $infected = $this->createEvidence(
            'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
        );
        $infectedScan = app(RawMaterialDocumentScanService::class)->scanVersion($infected['version']['id']);
        $this->assertSame(DocumentScanStatus::Infected->value, $infectedScan['status']);
        $this->assertEvidenceRejected($infected['version']['id'], 'INFECTED_EVIDENCE');

        $error = $this->createEvidence("synthetic scanner failure evidence\n");
        $scanner = Mockery::mock(DocumentScanner::class);
        $scanner->shouldReceive('scan')->once()->andThrow(new DocumentScannerException(
            'TEST_SCANNER_UNAVAILABLE',
            'ClamAV niedostępny w scenariuszu testowym.',
        ));
        $this->app->instance(DocumentScanner::class, $scanner);
        $errorScan = app(RawMaterialDocumentScanService::class)->scanVersion($error['version']['id']);
        $this->assertSame(DocumentScanStatus::Error->value, $errorScan['status']);
        $this->assertEvidenceRejected($error['version']['id'], 'ERROR_EVIDENCE');

        $this->assertSame(0, DB::table('raw_material_supplier_compatibility_assessments')
            ->where('supplier_product_id', $this->productId)->count());

        $this->app->forgetInstance(DocumentScanner::class);
        $previouslyClean = $this->createEvidence("synthetic evidence rescanned after assessment\n");
        app(RawMaterialDocumentScanService::class)->scanVersion($previouslyClean['version']['id']);
        $this->assessments->assess(
            $this->steward,
            $this->location,
            $this->productId,
            $this->decision($previouslyClean['version']['id'], 'COMPATIBLE'),
            'compat-rescan-001',
        );
        $scanner = Mockery::mock(DocumentScanner::class);
        $scanner->shouldReceive('scan')->once()->andThrow(new DocumentScannerException(
            'TEST_RESCAN_UNAVAILABLE',
            'ClamAV niedostępny w scenariuszu testowym.',
        ));
        $this->app->instance(DocumentScanner::class, $scanner);
        $rescanned = app(RawMaterialDocumentScanService::class)->scanVersion($previouslyClean['version']['id']);
        $this->assertSame(DocumentScanStatus::Error->value, $rescanned['status']);
        $this->assertSame('PENDING', $this->assessments->history(
            $this->steward,
            $this->location,
            $this->productId,
        )['status']);
    }

    public function test_a_new_supplier_or_raw_material_revision_makes_old_assessment_pending(): void
    {
        $evidence = $this->createEvidence("synthetic revision evidence\n");
        app(RawMaterialDocumentScanService::class)->scanVersion($evidence['version']['id']);
        $this->assessments->assess(
            $this->steward,
            $this->location,
            $this->productId,
            $this->decision($evidence['version']['id'], 'COMPATIBLE'),
            'compat-revision-001',
        );
        $this->assertSame('COMPATIBLE', $this->assessments->history(
            $this->steward,
            $this->location,
            $this->productId,
        )['status']);

        $this->commercial->reviseProduct($this->steward, $this->location, $this->productId, [
            'supplier_id' => $this->supplierId,
            'raw_material_revision_id' => $this->rawMaterialRevisionId,
            'product_name' => 'Produkt syntetyczny — nowa rewizja',
            'supplier_sku' => 'COMPAT-REV2',
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '1',
            'content_unit_code' => 'g',
            'expected_revision_id' => $this->supplierProductRevisionId,
        ], 'compat-product-rev-002');
        $afterProductRevision = $this->assessments->history($this->steward, $this->location, $this->productId);
        $this->assertSame('PENDING', $afterProductRevision['status']);
        $this->assertSame('HISTORICAL', $afterProductRevision['history'][0]['applicability']);

        $this->rawMaterials->reviseAllergenDeclaration($this->steward, $this->location, $this->materialId, [
            'status' => 'DECLARED',
            'catalog_version_id' => DB::table('raw_material_revision_allergen_attestations')
                ->where('revision_id', $this->rawMaterialRevisionId)->value('catalog_version_id'),
            'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'CROSS_CONTACT_POTENTIAL']],
        ], expectedVersion: $this->rawVersion($this->materialId));
        $afterRawMaterialRevision = $this->assessments->history($this->steward, $this->location, $this->productId);
        $this->assertSame('PENDING', $afterRawMaterialRevision['status']);
        $this->assertFalse($afterRawMaterialRevision['assessment_context']['raw_material_revision_current']);

        $this->rawMaterials->submit($this->steward, $this->location, $this->materialId, expectedVersion: $this->rawVersion($this->materialId));
        $approved = $this->rawMaterials->approve(
            $this->steward,
            $this->location,
            $this->materialId,
            'Zatwierdzenie zmienionej deklaracji alergenów.',
            expectedVersion: $this->rawVersion($this->materialId));
        $newRawMaterialRevisionId = $approved['approved_revision_id'];
        $newProductRevision = $this->commercial->reviseProduct($this->steward, $this->location, $this->productId, [
            'supplier_id' => $this->supplierId,
            'raw_material_revision_id' => $newRawMaterialRevisionId,
            'product_name' => 'Produkt po zmianie deklaracji alergenów',
            'supplier_sku' => 'COMPAT-ALLERGEN-REV',
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '1',
            'content_unit_code' => 'g',
            'expected_revision_id' => DB::table('supplier_product_revisions')
                ->where('supplier_product_id', $this->productId)
                ->orderByDesc('revision_no')
                ->value('id'),
        ], 'compat-allergen-product-003');
        $afterApprovedRevision = $this->assessments->history($this->steward, $this->location, $this->productId);
        $this->assertSame('PENDING', $afterApprovedRevision['status']);
        $this->assertSame('HISTORICAL', $afterApprovedRevision['history'][0]['applicability']);
        $this->assertSame($newRawMaterialRevisionId, $afterApprovedRevision['assessment_context']['raw_material_revision_id']);
        $this->assertSame(
            'CROSS_CONTACT_POTENTIAL',
            $afterApprovedRevision['assessment_context']['allergen_attestation']['allergens'][0]['exposure_kind'],
        );
        $this->assertNotSame(
            $this->supplierProductRevisionId,
            $newProductRevision['data']['current_revision']['id'],
        );
        $this->assertSame(
            $newRawMaterialRevisionId,
            DB::table('supplier_product_revisions')
                ->where('id', $newProductRevision['data']['current_revision']['id'])
                ->value('raw_material_revision_id'),
        );
    }

    public function test_assessment_authorization_organization_isolation_and_readiness_gate_are_enforced(): void
    {
        $unprivileged = $this->user('compatibility-unprivileged@example.test');
        app(MembershipService::class)->assign($unprivileged, $this->location);
        try {
            $this->assessments->assess(
                $unprivileged,
                $this->location,
                $this->productId,
                $this->decision(Str::uuid()->toString(), 'COMPATIBLE'),
                'compat-denied-0001',
            );
            $this->fail('An actor without the explicit assessment permission was authorized.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('raw_material_supplier_compatibility_assessments')->count());
        }

        $this->withForeignOrganizationFixture(function (Location $foreignLocation): void {
            try {
                $this->assessments->history($this->steward, $foreignLocation, $this->productId);
                $this->fail('A cross-organization operating context was accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(0, DB::table('raw_material_supplier_compatibility_assessments')->count());
            }
        });

        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        try {
            $this->assessments->history($this->steward, $this->location, $this->productId);
            $this->fail('The compatibility service bypassed RAW_MATERIALS readiness.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIALS_MODULE_UNAVAILABLE', $exception->errorCode);
            $this->assertSame(503, $exception->status);
        }
    }

    public function test_http_workflow_returns_current_assessment_and_remains_behind_readiness_gate(): void
    {
        $evidence = $this->createEvidence("synthetic API compatibility evidence\n");
        app(RawMaterialDocumentScanService::class)->scanVersion($evidence['version']['id']);
        $this->selectContext($this->steward);

        $this->postJson('/api/v1/raw-materials/supplier-products/'.$this->productId.'/compatibility-assessments', [
            ...$this->decision($evidence['version']['id'], 'COMPATIBLE'),
        ], ['Idempotency-Key' => 'compat-http-0001'])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'COMPATIBLE')
            ->assertJsonPath('data.supplier_product_revision_id', $this->supplierProductRevisionId)
            ->assertJsonPath('data.raw_material_revision_id', $this->rawMaterialRevisionId)
            ->assertJsonPath('data.document_sha256', $evidence['version']['sha256']);

        $this->getJson('/api/v1/raw-materials/supplier-products/'.$this->productId.'/compatibility-assessments')
            ->assertOk()
            ->assertJsonPath('status', 'COMPATIBLE')
            ->assertJsonPath('history.0.applicability', 'CURRENT');

        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $this->getJson('/api/v1/raw-materials/supplier-products/'.$this->productId.'/compatibility-assessments')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
    }

    public function test_recipient_compatibility_history_excludes_assessments_for_superseded_raw_revisions(): void
    {
        $categoryId = DB::table('raw_material_revisions')->where('id', $this->rawMaterialRevisionId)->value('category_id');
        $catalogVersionId = $this->publishedCatalog();
        $material = $this->rawMaterials->create($this->steward, $this->location, [
            'category_id' => $categoryId,
            'name' => 'Surowiec historii zgodności',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            'allergen_state' => 'DECLARED',
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
            ],
        ]);
        $this->materialId = $material['id'];
        $this->rawMaterials->submit($this->steward, $this->location, $this->materialId, expectedVersion: $this->rawVersion($this->materialId));
        $this->rawMaterials->approve($this->steward, $this->location, $this->materialId, 'Pierwsze zatwierdzenie', expectedVersion: $this->rawVersion($this->materialId));
        $this->rawMaterialRevisionId = DB::table('raw_materials')
            ->where('id', $this->materialId)
            ->value('approved_revision_id');
        $product = $this->commercial->createProduct($this->steward, $this->location, $this->materialId, [
            'supplier_id' => $this->supplierId,
            'raw_material_revision_id' => $this->rawMaterialRevisionId,
            'product_name' => 'Produkt historii zgodności',
            'supplier_sku' => 'COMPAT-HISTORY',
            'purchase_unit_code' => 'PACK',
            'content_quantity' => '1',
            'content_unit_code' => 'g',
        ], 'compat-history-product-001');
        $this->productId = $product['data']['id'];
        $this->supplierProductRevisionId = $product['data']['current_revision']['id'];

        $evidence = $this->createEvidence("synthetic historical compatibility evidence\n");
        app(RawMaterialDocumentScanService::class)->scanVersion($evidence['version']['id']);
        $this->assessments->assess(
            $this->steward,
            $this->location,
            $this->productId,
            $this->decision($evidence['version']['id'], 'COMPATIBLE'),
            'compat-history-v1-0001',
        );

        $recipient = $this->activeRestaurant('RCURRENT');
        app(MembershipService::class)->assign($this->steward, $recipient);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $recipient->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->administrator->id,
            'updated_by' => $this->administrator->id,
        ]);
        app(RawMaterialLocationShareAdministration::class)->share(
            $this->administrator,
            $this->location,
            $this->materialId,
            $recipient->id,
        );

        $approved = DB::table('raw_material_revisions')->where('id', $this->rawMaterialRevisionId)->first();
        $this->rawMaterials->revise($this->steward, $this->location, $this->materialId, [
            'category_id' => $approved->category_id,
            'name' => 'Nowa zatwierdzona rewizja surowca',
            'description' => $approved->description,
            'base_unit_code' => $approved->base_unit_code,
        ], expectedVersion: $this->rawVersion($this->materialId));
        $catalogVersionId = $this->publishedCatalog();
        $this->rawMaterials->reviseAllergenDeclaration($this->steward, $this->location, $this->materialId, [
            'status' => 'DECLARED',
            'catalog_version_id' => $catalogVersionId,
            'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
        ], expectedVersion: $this->rawVersion($this->materialId));
        $this->rawMaterials->submit($this->steward, $this->location, $this->materialId, expectedVersion: $this->rawVersion($this->materialId));
        $this->rawMaterials->approve($this->steward, $this->location, $this->materialId, 'Aktualizacja syntetyczna', expectedVersion: $this->rawVersion($this->materialId));

        $this->assertSame($this->rawMaterialRevisionId, $this->assessments
            ->history($this->steward, $this->location, $this->productId)['history'][0]['raw_material_revision_id']);

        $recipientHistory = $this->assessments->history($this->steward, $recipient, $this->productId);
        $this->assertSame('PENDING', $recipientHistory['status']);
        $this->assertNull($recipientHistory['assessment_context']);
        $this->assertSame([], $recipientHistory['history']);
    }

    public function test_assessment_and_audit_roll_back_together_when_audit_insert_fails(): void
    {
        $evidence = $this->createEvidence("synthetic audit failure evidence\n");
        app(RawMaterialDocumentScanService::class)->scanVersion($evidence['version']['id']);
        $setup = DB::connection('pgsql_setup');
        $setup->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.stage2d4b_test_fail_compatibility_audit()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'test audit failure' USING ERRCODE = 'P0001';
            END
            $$;
            CREATE TRIGGER stage2d4b_test_fail_compatibility_audit
                BEFORE INSERT ON public.raw_materials_commercial_audit_events
                FOR EACH ROW WHEN (NEW.subject_type = 'SUPPLIER_COMPATIBILITY_ASSESSMENT')
                EXECUTE FUNCTION public.stage2d4b_test_fail_compatibility_audit();
            SQL);

        try {
            try {
                $this->assessments->assess(
                    $this->steward,
                    $this->location,
                    $this->productId,
                    $this->decision($evidence['version']['id'], 'COMPATIBLE'),
                    'compat-audit-fail-001',
                );
                $this->fail('The forced audit failure did not abort assessment creation.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->errorInfo[0] ?? null);
            }

            $this->assertSame(0, DB::table('raw_material_supplier_compatibility_assessments')
                ->where('supplier_product_id', $this->productId)->count());
            $this->assertSame(0, DB::table('raw_materials_commercial_idempotency')
                ->where('operation_code', 'supplier-compatibility.assess')->count());
            $this->assertSame(0, DB::table('raw_materials_commercial_audit_events')
                ->where('subject_type', 'SUPPLIER_COMPATIBILITY_ASSESSMENT')->count());
        } finally {
            $setup->unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS stage2d4b_test_fail_compatibility_audit
                    ON public.raw_materials_commercial_audit_events;
                DROP FUNCTION IF EXISTS public.stage2d4b_test_fail_compatibility_audit();
                SQL);
        }
    }

    private function createEvidence(string $contents): array
    {
        return $this->documents->create(
            $this->steward,
            $this->location,
            $this->rawMaterialRevisionId,
            'SUPPLIER_CERTIFICATE',
            'synthetic-evidence.txt',
            $contents,
            'SUPPLIER_COMPATIBILITY_EVIDENCE',
            $this->supplierProductRevisionId,
        );
    }

    private function decision(string $documentVersionId, string $outcome, ?string $reason = null): array
    {
        return [
            'document_version_id' => $documentVersionId,
            'outcome' => $outcome,
            'decision_reason' => $reason ?? 'Ocena biznesowa wykonana na podstawie dowodu i deklaracji.',
            'allergen_declaration_reviewed' => true,
        ];
    }

    private function assertEvidenceRejected(string $documentVersionId, string $scenario): void
    {
        try {
            $this->assessments->assess(
                $this->steward,
                $this->location,
                $this->productId,
                $this->decision($documentVersionId, 'COMPATIBLE'),
                'compat-'.$scenario.'-001',
            );
            $this->fail("{$scenario} was accepted as compatibility evidence.");
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_COMPATIBILITY_EVIDENCE_INVALID', $exception->errorCode);
        }
    }

    private function publishedCatalog(): string
    {
        $versionId = Str::uuid()->toString();
        $versionNumber = (int) DB::connection('pgsql_setup')
            ->table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
        $this->withSetupTransaction(function (Connection $connection) use ($versionId, $versionNumber): void {
            $connection->table('raw_material_allergen_catalog_versions')->insert([
                'id' => $versionId,
                'version_number' => $versionNumber,
                'status' => 'DRAFT',
            ]);
            $connection->table('raw_material_allergen_definitions')->insert([
                'catalog_version_id' => $versionId,
                'allergen_code' => 'TEST_WHEAT',
                'name_pl' => 'Pszenica testowa',
                'display_order' => 1,
            ]);
            $connection->table('raw_material_allergen_catalog_versions')->where('id', $versionId)
                ->update(['status' => 'PUBLISHED', 'published_at' => now()]);
        });

        return $versionId;
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

    private function selectContext(User $actor): void
    {
        Auth::forgetGuards();
        $response = $this->actingAs($actor, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }
}
