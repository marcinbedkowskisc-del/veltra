<?php

namespace Tests\Concerns;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\PermissionAdministration;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\Documents\DocumentScanner;
use App\Domain\RawMaterials\Documents\DocumentScannerException;
use App\Domain\RawMaterials\Documents\DocumentScanOutcome;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentException;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentScanService;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentService;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentSetService;
use App\Domain\RawMaterials\Documents\RawMaterialUploadService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialCsvCatalogExportWorkflow;
use App\Domain\RawMaterials\RawMaterialLocationShareAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialSkuReservationService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\ModuleCatalogFixtures;

trait RawDocumentsStage3eB3Tests
{
    public function test_stage3e_b3_multifile_upload_retains_success_and_reports_each_failure(): void
    {
        $this->b3Storage();
        $this->selectRawMaterialApiContext($this->steward);
        $result = $this->post('/api/v1/raw-materials/document-uploads', [
            'files' => [
                UploadedFile::fake()->createWithContent('poprawny.txt', 'Treść dokumentu'),
                UploadedFile::fake()->createWithContent('podszycie.pdf', '<html>nie PDF</html>'),
                UploadedFile::fake()->createWithContent('drugi.csv', "nazwa;ilość\nMąka;1"),
            ],
            'keys' => ['b3-file-1', 'b3-file-2', 'b3-file-3'],
        ], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(['STAGED', 'FAILED', 'STAGED'], array_column($result, 'status'));
        $this->assertSame(2, DB::table('raw_material_document_uploads')->count());
        $this->assertSame(0, DB::table('raw_material_document_versions')->count());
        $this->assertCount(2, Storage::disk('raw_material_documents')->allFiles());
        $retry = app(RawMaterialUploadService::class)->stage($this->steward, $this->location, 'b3-file-1', 'poprawny.txt', 'Treść dokumentu');
        $this->assertSame($result[0]['upload_id'], $retry['upload_id']);
        $this->assertArrayNotHasKey('storage_key', $retry);
        $this->assertArrayNotHasKey('sha256', $retry);
    }

    public function test_stage3e_b3_content_filename_and_size_validation_fail_closed(): void
    {
        $this->b3Storage();
        $service = app(RawMaterialUploadService::class);
        foreach ([
            ['../plik.txt', 'tekst'], ['a\\b.txt', 'tekst'], ["plik\n.txt", 'tekst'],
            ['plik.svg', '<svg></svg>'], ['plik.exe', 'tekst'], ['plik.png', 'tekst'],
            ['plik.txt', ''], ['plik.txt', str_repeat('a', RawMaterialUploadService::MAX_BYTES + 1)],
        ] as $index => [$filename, $contents]) {
            try {
                $service->stage($this->steward, $this->location, 'invalid-'.$index, $filename, $contents);
                $this->fail('Przyjęto niedozwolony plik.');
            } catch (RawMaterialDocumentException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
        $this->assertSame(0, DB::table('raw_material_document_uploads')->count());
        $this->selectRawMaterialApiContext($this->steward);
        $this->post('/api/v1/raw-materials/document-uploads', [
            'files' => [
                UploadedFile::fake()->createWithContent('a.txt', str_repeat('a', 11 * 1024 * 1024)),
                UploadedFile::fake()->createWithContent('b.txt', str_repeat('b', 11 * 1024 * 1024)),
            ],
            'keys' => ['size-a', 'size-b'],
        ], ['Accept' => 'application/json'])->assertStatus(413);
        $this->assertSame([], Storage::disk('raw_material_documents')->allFiles());
    }

    public function test_stage3e_b3_scan_failure_is_retryable_and_idempotency_is_payload_bound(): void
    {
        $this->b3Storage();
        $scanner = Mockery::mock(DocumentScanner::class);
        $scanner->shouldReceive('scan')->once()->andThrow(new DocumentScannerException('CLAMAV_UNAVAILABLE', 'Skaner niedostępny.'));
        $scanner->shouldReceive('scan')->once()->andReturn(DocumentScanOutcome::clean('test', 'test'));
        $this->app->instance(DocumentScanner::class, $scanner);
        $uploads = app(RawMaterialUploadService::class);
        $first = $uploads->stage($this->steward, $this->location, 'retry-scan', 'plik.txt', 'Dokument');
        $this->assertSame('FAILED', $first['status']);
        $retry = $uploads->retry($this->steward, $this->location, $first['upload_id']);
        $this->assertSame('STAGED', $retry['status']);
        $this->assertSame($first['upload_id'], $retry['upload_id']);
        $this->assertSame($retry, $uploads->stage($this->steward, $this->location, 'retry-scan', 'plik.txt', 'Dokument'));
        try {
            $uploads->stage($this->steward, $this->location, 'retry-scan', 'plik.txt', 'Inna treść');
            $this->fail('Klucz zaakceptował inną zawartość.');
        } catch (RawMaterialDocumentException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertCount(1, Storage::disk('raw_material_documents')->allFiles());
    }

    public function test_stage3e_b3_uploads_are_actor_location_and_organization_scoped(): void
    {
        $this->b3Storage();
        [$material, $input] = $this->b2Draft();
        $upload = $this->b3Upload();
        $other = $this->user('b3-other@example.test');
        app(MembershipService::class)->assign($other, $this->location);
        foreach ([RawMaterialPermission::View, RawMaterialPermission::Edit] as $permission) {
            app(OrganizationRawMaterialPermissionAdministration::class)->grant($this->steward, $this->location, $other, $permission, 'Test B3');
        }
        $this->selectRawMaterialApiContext($other);
        $this->postJson('/api/v1/raw-materials/document-uploads/'.$upload['upload_id'].'/retry')->assertNotFound();
        $this->patchJson('/api/v1/raw-materials/'.$material['id'].'/draft', $input + ['document_upload_ids' => [$upload['upload_id']]], ['If-Match' => $material['version']])->assertNotFound();
        $this->assertSame($material['version'], $this->rawVersion($material['id']));
        $this->assertSame('STAGED', DB::table('raw_material_document_uploads')->value('state'));
        $foreign = clone $this->steward;
        $foreign->organization_id = Str::uuid()->toString();
        try {
            app(RawMaterialUploadService::class)->retry($foreign, $this->location, $upload['upload_id']);
            $this->fail('Ujawniono upload innej organizacji.');
        } catch (RawMaterialDocumentException $exception) {
            $this->assertSame(404, $exception->status);
        }
        $restaurant = $this->b3Recipient();
        $this->b3Context($this->steward, $restaurant);
        $this->postJson('/api/v1/raw-materials/document-uploads/'.$upload['upload_id'].'/retry')->assertForbidden();
    }

    public function test_stage3e_b3_expiry_cleanup_removes_only_unclaimed_files(): void
    {
        $this->b3Storage();
        $expired = $this->b3ShortUpload('expiry');
        $claimed = $this->b3DraftWithDocument();
        usleep(2100000);
        $this->assertSame(1, app(RawMaterialUploadService::class)->cleanupExpired());
        $this->assertSame('EXPIRED', DB::table('raw_material_document_uploads')->where('id', $expired['upload_id'])->value('state'));
        $this->assertCount(1, Storage::disk('raw_material_documents')->allFiles());
        $this->assertSame('CLAIMED', DB::table('raw_material_document_uploads')->where('claimed_version_id', $claimed[2])->value('state'));
        $this->selectRawMaterialApiContext($this->steward);
        $this->postJson('/api/v1/raw-materials/document-uploads/'.$expired['upload_id'].'/retry')->assertNotFound();
    }

    public function test_stage3e_b3_create_reservation_replay_never_duplicates_document_links(): void
    {
        $this->b3Storage();
        $upload = $this->b3Upload();
        $reservation = app(RawMaterialSkuReservationService::class)->reserve($this->steward, $this->location, 'b3-create-reservation');
        $input = ['category_id' => $this->category()['id'], 'name' => 'Utworzenie z dokumentem', 'base_unit_code' => 'g', 'nominal_quantity' => '1000', 'sku_reservation_id' => $reservation['reservation_id'], 'document_upload_ids' => [$upload['upload_id']]];
        $created = $this->rawMaterialService->create($this->steward, $this->location, $input);
        $this->assertEquals($created, $this->rawMaterialService->create($this->steward, $this->location, $input));
        $this->assertSame(1, DB::table('raw_material_document_versions')->count());
        $this->assertSame(1, DB::table('raw_material_document_version_links')->count());
        $this->assertSame('CLAIMED', DB::table('raw_material_document_uploads')->value('state'));
    }

    public function test_stage3e_b3_failed_save_rolls_back_all_claims_links_and_revision(): void
    {
        $this->b3Storage();
        [$material, $input] = $this->b2Draft();
        $upload = $this->b3Upload();
        $this->selectRawMaterialApiContext($this->steward);
        $this->patchJson('/api/v1/raw-materials/'.$material['id'].'/draft', $input + [
            'document_upload_ids' => [$upload['upload_id'], 'ffffffff-ffff-4fff-bfff-ffffffffffff'],
        ], ['If-Match' => $material['version']])->assertNotFound();
        $this->assertSame(0, DB::table('raw_material_document_versions')->count());
        $this->assertSame(0, DB::table('raw_material_document_version_links')->count());
        $this->assertSame(1, DB::table('raw_material_revisions')->count());
        $this->assertSame('STAGED', DB::table('raw_material_document_uploads')->value('state'));
        $this->assertSame($material['version'], $this->rawVersion($material['id']));
        $saved = $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input + ['document_upload_ids' => [$upload['upload_id']]], $material['version']);
        $this->patchJson('/api/v1/raw-materials/'.$material['id'].'/draft', $input, ['If-Match' => $material['version']])->assertConflict();
        $this->assertSame($saved['version'], $this->rawVersion($material['id']));
        $this->assertSame(1, DB::table('raw_material_document_versions')->count());
    }

    public function test_stage3e_b3_detachment_is_save_scoped_cancelable_and_historically_immutable(): void
    {
        $this->b3Storage();
        [$material, $input, $versionId] = $this->b3DraftWithDocument(true);
        $this->b3Approve($material['id']);
        $approved = $this->rawMaterialService->show($this->steward, $this->location, $material['id']);
        $revision = DB::table('raw_material_revisions')->where('id', $approved['revision']['id'])->first();
        $documents = app(RawMaterialDocumentSetService::class);
        $this->assertSame([$versionId], array_column($documents->listing($this->steward, $this->location, $material['id'])['documents'], 'id'));
        $saved = $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input, $this->rawVersion($material['id']));
        $this->assertSame([$versionId], $documents->ids($this->steward->organization_id, $saved['revision']['id']));
        $detached = $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input + ['detach_document_version_ids' => [$versionId]], $saved['version']);
        $this->assertSame([], $documents->ids($this->steward->organization_id, $detached['revision']['id']));
        $this->assertCount(1, $documents->listing($this->steward, $this->location, $material['id'])['documents']);
        $this->assertSame([], $documents->listing($this->steward, $this->location, $material['id'], true)['documents']);
        $this->assertEquals($revision, DB::table('raw_material_revisions')->where('id', $revision->id)->first());
        $this->assertSame(1, DB::table('raw_material_document_version_links')->count());
        $this->assertCount(1, Storage::disk('raw_material_documents')->allFiles());
        $this->assertDatabaseRejects('23514', fn ($connection) => $connection->table('raw_material_revision_document_sets')->where('revision_id', $revision->id)->update(['document_version_ids' => '[]']));
    }

    public function test_stage3e_b3_document_reads_enforce_scan_integrity_headers_and_preview_types(): void
    {
        $this->b3Storage();
        [$material, , $versionId] = $this->b3DraftWithDocument();
        $this->selectRawMaterialApiContext($this->steward);
        $base = '/api/v1/raw-materials/'.$material['id'].'/documents';
        $list = $this->getJson($base)->assertOk()->json('data.documents.0');
        $this->assertArrayNotHasKey('storage_key', $list);
        $this->assertArrayNotHasKey('upload_id', $list);
        $this->assertNull($list['download_url']);
        $this->get($base.'/'.$versionId.'/download')->assertConflict();
        app(RawMaterialDocumentScanService::class)->scanVersion($versionId);
        $this->get($base.'/'.$versionId.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertContent('Dokument testowy B3');
        $this->get($base.'/'.$versionId.'/preview')->assertUnprocessable();
        $stored = DB::table('raw_material_document_versions')->where('id', $versionId)->first();
        Storage::disk('raw_material_documents')->put($stored->storage_key, 'zmieniona treść');
        $this->get($base.'/'.$versionId.'/download')->assertConflict();
    }

    public function test_stage3e_b3_pdf_preview_uses_inline_safe_headers(): void
    {
        $this->b3Storage();
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $upload = $this->b3Upload('pdf', 'instrukcja.pdf', $pdf);
        $input = ['category_id' => $this->category()['id'], 'name' => 'PDF', 'base_unit_code' => 'g', 'nominal_quantity' => '1000', 'document_upload_ids' => [$upload['upload_id']]];
        $material = $this->rawMaterialService->create($this->steward, $this->location, $input);
        $versionId = DB::table('raw_material_document_versions')->value('id');
        app(RawMaterialDocumentScanService::class)->scanVersion($versionId);
        $this->selectRawMaterialApiContext($this->steward);
        $this->get('/api/v1/raw-materials/'.$material['id'].'/documents/'.$versionId.'/preview')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; frame-ancestors 'self'")
            ->assertHeader('Content-Disposition', 'inline; filename=instrukcja.pdf')->assertContent($pdf);
    }

    public function test_stage3e_b3_recipient_cannot_read_drafts_pending_or_revoked_document_urls(): void
    {
        $this->b3Storage();
        [$material, $input, $versionId] = $this->b3DraftWithDocument(true);
        $this->b3Approve($material['id']);
        app(RawMaterialDocumentScanService::class)->scanVersion($versionId);
        $recipient = $this->b3Recipient();
        app(RawMaterialLocationShareAdministration::class)->share($this->bootstrapAdministrator, $this->location, $material['id'], $recipient->id);
        $upload = $this->b3Upload('hidden', 'ukryty.txt', 'Ukryty szkic');
        $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input + ['document_upload_ids' => [$upload['upload_id']]], $this->rawVersion($material['id']));
        $hiddenId = DB::table('raw_material_document_uploads')->where('id', $upload['upload_id'])->value('claimed_version_id');
        app(RawMaterialDocumentScanService::class)->scanVersion($hiddenId);
        $this->b3Context($this->steward, $recipient);
        $base = '/api/v1/raw-materials/'.$material['id'].'/documents';
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.documents');
        $this->get($base.'/'.$versionId.'/download')->assertOk();
        $this->get($base.'/'.$hiddenId.'/download')->assertNotFound();
        $this->getJson($base.'?view=editor')->assertForbidden();
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id'], $this->rawVersion($material['id']));
        $this->get($base.'/'.$hiddenId.'/download')->assertNotFound();
        app(RawMaterialLocationShareAdministration::class)->revoke($this->bootstrapAdministrator, $this->location, $material['id'], $recipient->id);
        $this->get($base.'/'.$versionId.'/download')->assertNotFound();
        $this->get($base.'/'.Str::uuid().'/download')->assertNotFound();
    }

    public function test_stage3e_b3_parallel_saves_claim_one_upload_once(): void
    {
        $this->b3Storage();
        [$material, $input] = $this->b2Draft();
        $upload = $this->b3Upload();
        $this->b2Race($material['id'], $material['version'], 'revise', 'revise', $input + ['document_upload_ids' => [$upload['upload_id']]], Storage::disk('raw_material_documents')->path(''));
        $this->assertSame(1, DB::table('raw_material_document_versions')->count());
        $this->assertSame(1, DB::table('raw_material_document_version_links')->count());
        $this->assertSame('CLAIMED', DB::table('raw_material_document_uploads')->value('state'));
    }

    public function test_stage3e_b3_cleanup_skips_a_file_claimed_by_an_uncommitted_save(): void
    {
        $this->b3Storage();
        $upload = $this->b3ShortUpload('race-expiry');
        [$material, $input] = $this->b2Draft();
        DB::beginTransaction();
        try {
            $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input + ['document_upload_ids' => [$upload['upload_id']]], $material['version']);
            usleep(2100000);
            $process = new Process([PHP_BINARY, base_path('tests/Support/cleanup_raw_uploads.php')], base_path(), timeout: 15);
            $process->mustRun();
            $this->assertSame('0', trim($process->getOutput()));
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(0, app(RawMaterialUploadService::class)->cleanupExpired());
        $this->assertCount(1, Storage::disk('raw_material_documents')->allFiles());
        $this->assertSame('CLAIMED', DB::table('raw_material_document_uploads')->value('state'));
    }

    public function test_stage3e_b3_selected_export_uses_filters_columns_and_idempotent_manifest(): void
    {
        Storage::fake('raw_material_csv_exports');
        $category = $this->category();
        $a = $this->stage3eMaterial('Żurawina A', 'kg', '2', '1.5', $category['id']);
        $b = $this->stage3eMaterial('Żurawina B', 'kg', '3', null, $category['id']);
        $this->selectRawMaterialApiContext($this->steward);
        $body = ['material_ids' => [$b['id'], $a['id']], 'columns' => ['ordinal', 'sku', 'name', 'nominal_quantity', 'yield_percent'], 'search' => 'żUrAwInA', 'unit' => 'kg', 'category_id' => $category['id'], 'sort' => 'name', 'direction' => 'desc'];
        $headers = ['Idempotency-Key' => 'b3-selected-export'];
        $created = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', $body, $headers)->assertCreated()->assertJsonPath('data.row_count', 2)->json('data');
        $this->assertSame($created, $this->postJson('/api/v1/raw-materials/catalog-exports/selection', $body, $headers)->assertCreated()->json('data'));
        $csv = $this->get($created['download_url'])->assertOk()->streamedContent();
        $this->assertStringStartsWith("ordinal;sku;name;nominal_quantity;yield_percent\n", $csv);
        $this->assertStringContainsString('3.000000;100.000000', $csv);
        $this->assertLessThan(strpos($csv, 'Żurawina A'), strpos($csv, 'Żurawina B'));
        $this->assertCount(1, Storage::disk('raw_material_csv_exports')->allFiles());
        $this->postJson('/api/v1/raw-materials/catalog-exports/selection', array_replace($body, ['columns' => ['name']]), $headers)->assertConflict();
        $this->postJson('/api/v1/raw-materials/catalog-exports/selection', $body + ['page' => 2], ['Idempotency-Key' => 'bad-page'])->assertUnprocessable();
    }

    public function test_stage3e_b3_export_default_and_explicit_columns_separate_financial_permissions(): void
    {
        Storage::fake('raw_material_csv_exports');
        $material = $this->stage3eMaterial('Ceny B3', 'g', '100');
        $this->role($this->steward, BusinessRole::Administrator);
        $this->stage3ePrice($material['id'], '0.12345678901234', 'b3-price');
        $reader = $this->user('b3-reader@example.test');
        app(MembershipService::class)->assign($reader, $this->location);
        $grants = app(OrganizationRawMaterialPermissionAdministration::class);
        $grants->grant($this->steward, $this->location, $reader, RawMaterialPermission::View, 'Odczyt B3');
        $this->selectRawMaterialApiContext($reader);
        $created = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', [], ['Idempotency-Key' => 'reader-default'])->assertCreated()->json('data');
        $csv = $this->get($created['download_url'])->assertOk()->streamedContent();
        $this->assertStringStartsWith("ordinal;sku;name;unit;nominal_quantity;usable_quantity\n", $csv);
        $this->assertStringNotContainsString('12.345678901234', $csv);
        foreach (['reference_net_price', 'usable_unit_cost', 'supplier_names'] as $column) {
            $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => [$column]], ['Idempotency-Key' => 'denied-'.$column])->assertForbidden();
        }
        $grant = $grants->grant($this->steward, $this->location, $reader, RawMaterialPermission::ReferencePriceView, 'Cena B3');
        $price = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => ['reference_net_price']], ['Idempotency-Key' => 'price-only'])->assertCreated()->json('data');
        $this->assertStringContainsString('12.34567890123400', $this->get($price['download_url'])->assertOk()->streamedContent());
        $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => ['usable_unit_cost']], ['Idempotency-Key' => 'no-foodcost'])->assertForbidden();
        $grants->revoke($this->steward, $this->location, $grant['id'], 'Odebranie B3');
        $this->get($price['download_url'])->assertForbidden();
    }

    public function test_stage3e_b3_selected_export_denies_hidden_ids_and_revoked_downloads(): void
    {
        Storage::fake('raw_material_csv_exports');
        $a = $this->stage3eMaterial('Udostępniony B3', 'g', '1');
        $b = $this->stage3eMaterial('Ukryty B3', 'g', '1', null, $a['revision']['category_id']);
        $recipient = $this->b3Recipient();
        $shares = app(RawMaterialLocationShareAdministration::class);
        $shares->share($this->bootstrapAdministrator, $this->location, $a['id'], $recipient->id);
        $this->b3Context($this->steward, $recipient);
        $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['material_ids' => [$a['id'], $b['id']]], ['Idempotency-Key' => 'hidden-selection'])->assertNotFound();
        $this->assertSame([], Storage::disk('raw_material_csv_exports')->allFiles());
        $created = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['material_ids' => [$a['id']]], ['Idempotency-Key' => 'visible-selection'])->assertCreated()->json('data');
        $shares->revoke($this->bootstrapAdministrator, $this->location, $a['id'], $recipient->id);
        $this->get($created['download_url'])->assertNotFound();
    }

    public function test_stage3e_b3_exported_usable_cost_requires_reference_price_and_foodcost_together(): void
    {
        Storage::fake('raw_material_csv_exports');
        $restaurant = $this->b3Recipient();
        $this->role($this->steward, BusinessRole::Administrator);
        $material = $this->stage3eMaterial('Koszt B3', 'g', '100', '80');
        $this->stage3ePrice($material['id'], '0.1', 'b3-cost-price');
        $reader = $this->user('b3-cost-reader@example.test');
        app(MembershipService::class)->assign($reader, $restaurant);
        $this->role($reader, BusinessRole::Employee, $restaurant);
        app(RawMaterialLocationShareAdministration::class)->share($this->steward, $this->location, $material['id'], $restaurant->id);
        $grants = app(OrganizationRawMaterialPermissionAdministration::class);
        $grants->grant($this->steward, $this->location, $reader, RawMaterialPermission::View, 'Odczyt B3');
        app(PermissionAdministration::class)->grant($this->steward, $this->location, $reader, $restaurant, Permission::FoodcostView);
        $this->b3Context($reader, $restaurant);
        $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => ['usable_unit_cost']], ['Idempotency-Key' => 'foodcost-only'])->assertForbidden();
        $grant = $grants->grant($this->steward, $this->location, $reader, RawMaterialPermission::ReferencePriceView, 'Referencja B3');
        $cost = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => ['usable_unit_cost']], ['Idempotency-Key' => 'both-financial-grants'])->assertCreated()->json('data');
        $this->assertStringContainsString('12.50000000000000', $this->get($cost['download_url'])->assertOk()->streamedContent());
        $grants->revoke($this->steward, $this->location, $grant['id'], 'Odebranie ceny B3');
        $this->get($cost['download_url'])->assertForbidden();
    }

    public function test_stage3e_b3_export_formula_risk_and_payload_tampering_are_rejected(): void
    {
        Storage::fake('raw_material_csv_exports');
        $material = $this->stage3eMaterial('=HYPERLINK("test")', 'g', '1');
        $this->selectRawMaterialApiContext($this->steward);
        $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => ['name']], ['Idempotency-Key' => 'formula'])->assertUnprocessable();
        $created = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => ['sku']], ['Idempotency-Key' => 'safe-sku'])->assertCreated()->json('data');
        $download = app(RawMaterialCsvCatalogExportWorkflow::class)->authorizeSelectionDownload($this->steward, $this->location, $created['export_id']);
        Storage::disk('raw_material_csv_exports')->put($download['storage_key'], 'podmieniony eksport');
        $this->get($created['download_url'])->assertStatus(500)->assertJsonPath('error.code', 'RAW_MATERIAL_CSV_EXPORT_INTEGRITY_FAILED');
        $this->assertSame(1, DB::table('raw_materials')->where('id', $material['id'])->count());
    }

    public function test_stage3e_b3_supplier_column_and_filter_use_current_approved_scope(): void
    {
        Storage::fake('raw_material_csv_exports');
        $fixture = $this->createCommercialOfferFixture('Dostawca B3', 'B3SUP', 'B3PROD', 'b3-supplier');
        $this->selectRawMaterialApiContext($this->steward);
        $created = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', [
            'columns' => ['sku', 'supplier_names'], 'supplier_id' => $fixture['supplier_id'], 'unit' => 'g',
        ], ['Idempotency-Key' => 'supplier-selection'])->assertCreated()->assertJsonPath('data.row_count', 1)->json('data');
        $this->assertStringContainsString('B3SUP', $this->get($created['download_url'])->assertOk()->streamedContent());
    }

    public function test_stage3e_b31_bulk_archive_validation_is_non_mutating_and_uses_atomic_policy(): void
    {
        $material = $this->stage3eMaterial('Walidacja archiwizacji B3', 'g', '1');
        $this->selectRawMaterialApiContext($this->steward);
        $body = ['items' => [['id' => $material['id'], 'version' => $this->rawVersion($material['id'])]]];
        $this->postJson('/api/v1/raw-materials/bulk-archive/validation', $body)->assertOk()
            ->assertJsonPath('data.mutation_available', true)->assertJsonPath('data.mutation_performed', false)
            ->assertJsonPath('data.policy_status', 'ALL_OR_NOTHING')->assertJsonPath('data.validated_count', 1);
        $this->assertSame($body['items'][0]['version'], $this->rawVersion($material['id']));
        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
        $this->postJson('/api/v1/raw-materials/bulk-archive/validation', ['items' => [['id' => $material['id']]]])->assertStatus(428);
        $body['items'][0]['version'] = Str::uuid()->toString();
        $this->postJson('/api/v1/raw-materials/bulk-archive/validation', $body)->assertConflict();
        $body['items'][0]['id'] = Str::uuid()->toString();
        $this->postJson('/api/v1/raw-materials/bulk-archive/validation', $body)->assertNotFound();
        $this->assertSame(1, DB::table('raw_material_audit_events')->where('event_code', 'BULK_ARCHIVE_VALIDATED')->count());
    }

    public function test_stage3e_b31_bulk_archive_commits_two_materials_and_audits_them_together(): void
    {
        $categoryId = $this->category()['id'];
        $first = $this->stage3eMaterial('Archiwizacja atomowa 1', 'g', '1', categoryId: $categoryId);
        $second = $this->stage3eMaterial('Archiwizacja atomowa 2', 'g', '2', categoryId: $categoryId);
        $items = [
            ['id' => $first['id'], 'version' => $this->rawVersion($first['id'])],
            ['id' => $second['id'], 'version' => $this->rawVersion($second['id'])],
        ];
        $this->selectRawMaterialApiContext($this->steward);

        $result = $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => $items,
            'reason' => 'Porządkowanie katalogu',
        ])->assertOk()->assertJsonPath('data.archived_count', 2)->json('data');

        $expectedIds = [$first['id'], $second['id']];
        sort($expectedIds);
        $this->assertSame($expectedIds, collect($result['materials'])->pluck('id')->sort()->values()->all());
        foreach ($items as $item) {
            $this->assertDatabaseHas('raw_materials', ['id' => $item['id'], 'lifecycle_state' => 'ARCHIVED']);
            $this->assertNotSame($item['version'], $this->rawVersion($item['id']));
        }
        $events = DB::table('raw_material_audit_events')->whereIn('subject_id', [$first['id'], $second['id']])
            ->where('event_code', 'ARCHIVED')->orderBy('subject_id')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$result['operation_id']], $events->pluck('operation_id')->unique()->values()->all());
        $this->assertSame(['USER_PROVIDED', 'USER_PROVIDED'], $events->pluck('reason_code')->all());

        $auditCount = $events->count();
        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => $items,
            'reason' => 'Ponowienie żądania',
        ])->assertConflict();
        $this->assertSame($auditCount, DB::table('raw_material_audit_events')->whereIn('subject_id', [$first['id'], $second['id']])
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_commits_several_materials(): void
    {
        $categoryId = $this->category()['id'];
        $materials = [];
        foreach (range(1, 4) as $index) {
            $materials[] = $this->stage3eMaterial('Archiwizacja grupowa '.$index, 'g', (string) $index, categoryId: $categoryId);
        }
        $items = array_map(fn (array $material): array => [
            'id' => $material['id'],
            'version' => $this->rawVersion($material['id']),
        ], $materials);
        $this->selectRawMaterialApiContext($this->steward);

        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => $items,
            'reason' => 'Porządkowanie katalogu',
        ])->assertOk()->assertJsonPath('data.archived_count', 4);

        foreach ($materials as $material) {
            $this->assertDatabaseHas('raw_materials', ['id' => $material['id'], 'lifecycle_state' => 'ARCHIVED']);
        }
        $this->assertSame(4, DB::table('raw_material_audit_events')->whereIn('subject_id', array_column($materials, 'id'))
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_rejects_one_ineligible_material_without_partial_commit(): void
    {
        $categoryId = $this->category()['id'];
        $eligible = $this->stage3eMaterial('Zdolny do archiwizacji', 'g', '1', categoryId: $categoryId);
        $draft = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $categoryId,
            'name' => 'Szkic niekwalifikujący się do archiwizacji',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
        ]);
        $this->selectRawMaterialApiContext($this->steward);

        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [
                ['id' => $eligible['id'], 'version' => $this->rawVersion($eligible['id'])],
                ['id' => $draft['id'], 'version' => $this->rawVersion($draft['id'])],
            ],
            'reason' => 'Porządkowanie katalogu',
        ])->assertConflict()->assertJsonPath('error.code', 'RAW_MATERIAL_ARCHIVE_INVALID');

        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $eligible['id'])->value('lifecycle_state'));
        $this->assertSame('DRAFT', DB::table('raw_materials')->where('id', $draft['id'])->value('lifecycle_state'));
        $this->assertSame(0, DB::table('raw_material_audit_events')->whereIn('subject_id', [$eligible['id'], $draft['id']])
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_rejects_a_stale_version_without_partial_commit(): void
    {
        $categoryId = $this->category()['id'];
        $first = $this->stage3eMaterial('Aktualna wersja', 'g', '1', categoryId: $categoryId);
        $second = $this->stage3eMaterial('Nieaktualna wersja', 'g', '2', categoryId: $categoryId);
        $this->selectRawMaterialApiContext($this->steward);

        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [
                ['id' => $first['id'], 'version' => $this->rawVersion($first['id'])],
                ['id' => $second['id'], 'version' => Str::uuid()->toString()],
            ],
            'reason' => 'Porządkowanie katalogu',
        ])->assertConflict()->assertJsonPath('error.code', 'RAW_MATERIAL_VERSION_CONFLICT');

        foreach ([$first, $second] as $material) {
            $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
        }
        $this->assertSame(0, DB::table('raw_material_audit_events')->whereIn('subject_id', [$first['id'], $second['id']])
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_rejects_an_unavailable_material_without_leaking_or_mutating(): void
    {
        $eligible = $this->stage3eMaterial('Dostępny materiał', 'g', '1');
        $missingId = Str::uuid()->toString();
        $this->selectRawMaterialApiContext($this->steward);

        $response = $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [
                ['id' => $eligible['id'], 'version' => $this->rawVersion($eligible['id'])],
                ['id' => $missingId, 'version' => Str::uuid()->toString()],
            ],
            'reason' => 'Porządkowanie katalogu',
        ])->assertNotFound();

        $this->assertStringNotContainsString($missingId, $response->getContent());
        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $eligible['id'])->value('lifecycle_state'));
        $this->assertSame(0, DB::table('raw_material_audit_events')->where('subject_id', $eligible['id'])
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_rejects_malformed_selections_and_versions(): void
    {
        $material = $this->stage3eMaterial('Walidacja listy archiwizacji', 'g', '1');
        $item = ['id' => $material['id'], 'version' => $this->rawVersion($material['id'])];
        $this->selectRawMaterialApiContext($this->steward);

        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [],
            'reason' => 'Porządkowanie katalogu',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [$item, $item],
            'reason' => 'Porządkowanie katalogu',
        ])->assertUnprocessable();
        $tooManyItems = [];
        foreach (range(1, 101) as $index) {
            $tooManyItems[] = ['id' => Str::uuid()->toString(), 'version' => Str::uuid()->toString()];
        }
        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => $tooManyItems,
            'reason' => 'Porządkowanie katalogu',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [['id' => $item['id']]],
            'reason' => 'Porządkowanie katalogu',
        ])->assertStatus(428);
        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [['id' => $item['id'], 'version' => 'not-a-version']],
            'reason' => 'Porządkowanie katalogu',
        ])->assertUnprocessable();

        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
        $this->assertSame(0, DB::table('raw_material_audit_events')->where('subject_id', $material['id'])
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_rolls_back_every_change_after_an_in_transaction_failure(): void
    {
        $categoryId = $this->category()['id'];
        $first = $this->stage3eMaterial('Rollback pierwszy', 'g', '1', categoryId: $categoryId);
        $second = $this->stage3eMaterial('Rollback drugi', 'g', '2', categoryId: $categoryId);
        $this->selectRawMaterialApiContext($this->steward);
        $updates = 0;
        $injectFailure = true;
        DB::listen(function (QueryExecuted $query) use (&$updates, &$injectFailure): void {
            if ($injectFailure && $query->connectionName === 'pgsql' && preg_match('/^update "raw_materials"/i', ltrim($query->sql))) {
                $updates++;
                if ($updates === 2) {
                    $injectFailure = false;
                    throw new \RuntimeException('Błąd testowy po mutacji pierwszego materiału.');
                }
            }
        });

        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [
                ['id' => $first['id'], 'version' => $this->rawVersion($first['id'])],
                ['id' => $second['id'], 'version' => $this->rawVersion($second['id'])],
            ],
            'reason' => 'Test atomowego rollbacku',
        ])->assertServerError();

        $this->assertSame(2, $updates);
        foreach ([$first, $second] as $material) {
            $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
        }
        $this->assertSame(0, DB::table('raw_material_audit_events')->whereIn('subject_id', [$first['id'], $second['id']])
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_recipient_cannot_archive_a_read_only_shared_material(): void
    {
        $material = $this->stage3eMaterial('Udostępniony tylko do odczytu', 'g', '1');
        $recipient = $this->b3Recipient();
        app(RawMaterialLocationShareAdministration::class)->share(
            $this->bootstrapAdministrator, $this->location, $material['id'], $recipient->id,
        );
        $this->b3Context($this->steward, $recipient);

        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [['id' => $material['id'], 'version' => $this->rawVersion($material['id'])]],
            'reason' => 'Nieuprawniona próba',
        ])->assertForbidden();

        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
        $this->assertSame(0, DB::table('raw_material_audit_events')->where('subject_id', $material['id'])
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_preserves_revision_document_and_commercial_history(): void
    {
        $this->b3Storage();
        $this->role($this->steward, BusinessRole::Administrator);
        $material = $this->stage3eMaterial('Historia do zachowania', 'g', '100');
        $document = app(RawMaterialDocumentService::class)->create(
            $this->steward,
            $this->location,
            $material['revision']['id'],
            'SPECIFICATION',
            'historia.txt',
            'Treść dokumentu historycznego',
        );
        $this->stage3ePrice($material['id'], '0.1', 'b31-preserved-price');
        $revisionCount = DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count();
        $documentVersionId = $document['version']['id'];
        $priceCount = DB::table('raw_material_reference_price_events')->where('raw_material_id', $material['id'])->count();
        $this->selectRawMaterialApiContext($this->steward);

        $this->postJson('/api/v1/raw-materials/bulk-archive', [
            'items' => [['id' => $material['id'], 'version' => $this->rawVersion($material['id'])]],
            'reason' => 'Archiwizacja bez utraty historii',
        ])->assertOk();

        $this->assertSame($revisionCount, DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count());
        $this->assertDatabaseHas('raw_material_document_version_links', [
            'document_version_id' => $documentVersionId,
            'raw_material_revision_id' => $material['revision']['id'],
        ]);
        $this->assertSame($priceCount, DB::table('raw_material_reference_price_events')->where('raw_material_id', $material['id'])->count());
    }

    public function test_stage3e_b31_overlapping_bulk_requests_with_reversed_order_are_serialized(): void
    {
        $categoryId = $this->category()['id'];
        $first = $this->stage3eMaterial('Blokada zbiorcza A', 'g', '1', categoryId: $categoryId);
        $second = $this->stage3eMaterial('Blokada zbiorcza B', 'g', '2', categoryId: $categoryId);
        $items = [
            ['id' => $first['id'], 'version' => $this->rawVersion($first['id'])],
            ['id' => $second['id'], 'version' => $this->rawVersion($second['id'])],
        ];
        $payload = ['items' => $items, 'reason' => 'Wyścig zbiorczy'];
        $reversedPayload = ['items' => array_reverse($items), 'reason' => 'Wyścig zbiorczy'];

        $results = $this->b3RaceProcesses([
            ['operation' => 'bulk-archive', 'material_id' => $first['id'], 'version' => $items[0]['version'], 'payload' => $payload],
            ['operation' => 'bulk-archive', 'material_id' => $second['id'], 'version' => $items[1]['version'], 'payload' => $reversedPayload],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $conflict = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409))[0];
        $this->assertSame('RAW_MATERIAL_VERSION_CONFLICT', $conflict['code']);
        foreach ([$first, $second] as $material) {
            $this->assertSame('ARCHIVED', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
        }
        $this->assertSame(2, DB::table('raw_material_audit_events')->whereIn('subject_id', [$first['id'], $second['id']])
            ->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_racing_with_single_material_edit_has_one_winner(): void
    {
        $material = $this->stage3eMaterial('Wyścig edycji i archiwizacji zbiorczej', 'g', '1');
        $archivePayload = [
            'items' => [['id' => $material['id'], 'version' => $this->rawVersion($material['id'])]],
            'reason' => 'Wyścig edycji',
        ];
        $editPayload = [
            'category_id' => $material['revision']['category_id'],
            'name' => 'Zmieniona nazwa',
            'base_unit_code' => 'g',
        ];
        $results = $this->b3RaceProcesses([
            ['operation' => 'bulk-archive', 'material_id' => $material['id'], 'version' => $archivePayload['items'][0]['version'], 'payload' => $archivePayload],
            ['operation' => 'revise', 'material_id' => $material['id'], 'version' => $archivePayload['items'][0]['version'], 'payload' => $editPayload],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame('RAW_MATERIAL_VERSION_CONFLICT', array_values(array_filter(
            $results,
            fn (array $result): bool => $result['status'] === 409,
        ))[0]['code']);
        $state = DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state');
        $this->assertContains($state, ['ACTIVE', 'ARCHIVED']);
        $this->assertSame($state === 'ARCHIVED' ? 1 : 0, DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->where('event_code', 'ARCHIVED')->count());
    }

    public function test_stage3e_b31_bulk_archive_racing_with_approval_never_partially_archives(): void
    {
        $categoryId = $this->category()['id'];
        $eligible = $this->stage3eMaterial('Kandydat do archiwizacji', 'g', '1', categoryId: $categoryId);
        $pending = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $categoryId,
            'name' => 'Kandydat do zatwierdzenia',
            'base_unit_code' => 'g',
            'nominal_quantity' => '2',
            'allergen_declaration' => [
                'status' => 'NO_KNOWN_ALLERGENS',
                'catalog_version_id' => $this->publishedAllergenCatalogFixture(),
                'items' => [],
            ],
        ]);
        $this->rawMaterialService->submit($this->steward, $this->location, $pending['id'], $this->rawVersion($pending['id']));
        $pendingVersion = $this->rawVersion($pending['id']);
        $archivePayload = [
            'items' => [
                ['id' => $eligible['id'], 'version' => $this->rawVersion($eligible['id'])],
                ['id' => $pending['id'], 'version' => $pendingVersion],
            ],
            'reason' => 'Wyścig zatwierdzania',
        ];
        $results = $this->b3RaceProcesses([
            ['operation' => 'bulk-archive', 'material_id' => $eligible['id'], 'version' => $archivePayload['items'][0]['version'], 'payload' => $archivePayload],
            ['operation' => 'approve', 'material_id' => $pending['id'], 'version' => $pendingVersion, 'payload' => []],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $failure = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409))[0];
        $this->assertContains($failure['code'], ['RAW_MATERIAL_ARCHIVE_INVALID', 'RAW_MATERIAL_VERSION_CONFLICT']);
        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $eligible['id'])->value('lifecycle_state'));
        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $pending['id'])->value('lifecycle_state'));
        $this->assertSame(0, DB::table('raw_material_audit_events')->whereIn('subject_id', [$eligible['id'], $pending['id']])
            ->where('event_code', 'ARCHIVED')->count());
    }

    /**
     * @param  list<array{operation: string, material_id: string, version: string, payload: array<string, mixed>}>  $jobs
     * @return list<array<string, mixed>>
     */
    private function b3RaceProcesses(array $jobs): array
    {
        $processes = [];
        try {
            DB::beginTransaction();
            Organization::whereKey($this->steward->organization_id)->lockForUpdate()->firstOrFail();
            foreach ($jobs as $index => $job) {
                $application = 'stage3eb31-race-'.$index;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/concurrent_raw_edit.php'),
                    $this->steward->id,
                    $this->location->id,
                    $job['material_id'],
                    $job['version'],
                    $job['operation'],
                    json_encode($job['payload'], JSON_THROW_ON_ERROR),
                    $application,
                ], base_path(), timeout: 30);
                $process->start();
                $processes[] = $process;
                $this->assertRevisionProcessWaitingOnOrganizationLock($process, $application);
            }
            DB::commit();

            return array_map(fn (Process $process): array => $this->finishRevisionProcess($process), $processes);
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

    public function test_stage3e_b3_export_all_is_not_paginated_and_selection_spans_pages(): void
    {
        Storage::fake('raw_material_csv_exports');
        $category = $this->category();
        $catalog = $this->publishedAllergenCatalogFixture();
        $ids = [];
        foreach (range(1, 51) as $index) {
            $material = $this->rawMaterialService->create($this->steward, $this->location, [
                'category_id' => $category['id'], 'name' => sprintf('Eksport %02d', $index), 'base_unit_code' => 'g', 'nominal_quantity' => '1000',
                'allergen_declaration' => ['status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $catalog, 'items' => []],
            ]);
            $this->b3Approve($material['id']);
            $ids[] = $material['id'];
        }
        $page = $this->rawMaterialService->list($this->steward, $this->location, ['per_page' => 25, 'page' => 3]);
        $this->assertSame([$ids[50]], array_column($page['data'], 'id'));
        $this->selectRawMaterialApiContext($this->steward);
        $all = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => ['sku', 'name']], ['Idempotency-Key' => 'all-pages'])->assertCreated()->assertJsonPath('data.row_count', 51)->json('data');
        $this->assertSame(52, substr_count($this->get($all['download_url'])->assertOk()->streamedContent(), "\n"));
        $selected = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['material_ids' => [$ids[0], $ids[50]], 'columns' => ['name']], ['Idempotency-Key' => 'cross-page-selection'])->assertCreated()->assertJsonPath('data.row_count', 2)->json('data');
        $csv = $this->get($selected['download_url'])->assertOk()->streamedContent();
        $this->assertStringContainsString('Eksport 01', $csv);
        $this->assertStringContainsString('Eksport 51', $csv);
    }

    public function test_stage3e_b3_supplier_evidence_and_unrelated_versions_are_not_material_attachments(): void
    {
        $this->b3Storage();
        $fixture = $this->createCommercialOfferFixture('Dowody B3', 'B3EVID', 'B3EVIDP', 'b3-evidence');
        $document = app(RawMaterialDocumentService::class)->create(
            $this->steward, $this->location, DB::table('raw_materials')->where('id', $fixture['material_id'])->value('approved_revision_id'), 'OTHER',
            'dowod.txt', 'Dowód produktu dostawcy', 'SUPPLIER_COMPATIBILITY_EVIDENCE', $fixture['product_revision_id'],
        );
        app(RawMaterialDocumentScanService::class)->scanVersion($document['version']['id']);
        $this->selectRawMaterialApiContext($this->steward);
        $base = '/api/v1/raw-materials/'.$fixture['material_id'].'/documents';
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data.documents');
        $this->get($base.'/'.$document['version']['id'].'/download')->assertNotFound();
        $material = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => DB::table('raw_material_categories')->value('id'), 'name' => 'Niepowiązany szkic', 'base_unit_code' => 'g', 'nominal_quantity' => '1000',
        ]);
        $this->get('/api/v1/raw-materials/'.$material['id'].'/documents/'.$document['version']['id'].'/download')->assertNotFound();
    }

    public function test_stage3e_b3_infected_upload_cannot_be_committed(): void
    {
        $this->b3Storage();
        $scanner = Mockery::mock(DocumentScanner::class);
        $scanner->shouldReceive('scan')->andReturn(DocumentScanOutcome::infected('test', 'test', 'Test.Malware'));
        $this->app->instance(DocumentScanner::class, $scanner);
        $upload = $this->b3Upload();
        $this->assertSame('FAILED', $upload['status']);
        [$material, $input] = $this->b2Draft();
        $this->selectRawMaterialApiContext($this->steward);
        $this->patchJson('/api/v1/raw-materials/'.$material['id'].'/draft', $input + ['document_upload_ids' => [$upload['upload_id']]], ['If-Match' => $material['version']])
            ->assertConflict()->assertJsonPath('error.code', 'RAW_MATERIAL_UPLOAD_NOT_CLEAN');
        $this->assertSame(0, DB::table('raw_material_document_versions')->count());
        $this->assertSame($material['version'], $this->rawVersion($material['id']));
    }

    public function test_stage3e_b3_restore_preserves_document_set_and_old_export_is_denied_after_new_approval(): void
    {
        $this->b3Storage();
        Storage::fake('raw_material_csv_exports');
        [$material, , $versionId] = $this->b3DraftWithDocument(true);
        $this->b3Approve($material['id']);
        $export = app(RawMaterialCsvCatalogExportWorkflow::class)->createSelection($this->steward, $this->location, ['columns' => ['name']], 'old-approval');
        $this->rawMaterialService->archive($this->steward, $this->location, $material['id'], 'Test B3', $this->rawVersion($material['id']));
        $this->rawMaterialService->restore($this->steward, $this->location, $material['id'], 'Test B3', $this->rawVersion($material['id']));
        $pending = DB::table('raw_materials')->where('id', $material['id'])->value('pending_revision_id');
        $this->assertSame([$versionId], app(RawMaterialDocumentSetService::class)->ids($this->steward->organization_id, $pending));
        $this->rawMaterialService->approve($this->steward, $this->location, $material['id'], 'Nowe zatwierdzenie B3', $this->rawVersion($material['id']));
        $this->selectRawMaterialApiContext($this->steward);
        $this->get($export['download_url'])->assertNotFound();
        $this->assertSame(1, DB::table('raw_material_document_versions')->count());
    }

    public function test_stage3e_b3_new_apis_preserve_not_ready_gate(): void
    {
        $this->b3Storage();
        $this->selectRawMaterialApiContext($this->steward);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        foreach (['/document-uploads', '/catalog-exports/selection', '/bulk-archive/validation', '/bulk-archive'] as $route) {
            $this->postJson('/api/v1/raw-materials'.$route)->assertStatus(503);
        }
        $this->getJson('/api/v1/raw-materials/'.Str::uuid().'/documents')->assertStatus(503);
        $this->assertSame(0, DB::table('raw_material_document_uploads')->count());
    }

    private function b3Context(User $actor, Location $location): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $actor = User::findOrFail($actor->id);
        $this->withSession(['identity_version' => $actor->auth_version]);
        $this->actingAs($actor, 'web')->putJson('/api/v1/auth/operating-location', ['location_id' => $location->id])->assertOk();
    }

    private function b3ShortUpload(string $key): array
    {
        $id = Str::uuid()->toString();
        $contents = 'Dokument testowy B3';
        $storageKey = 'raw-material-uploads/'.substr($id, 0, 2).'/'.$id;
        Storage::disk('raw_material_documents')->put($storageKey, $contents);
        app(AuthorizationMutationContext::class)->run(AuthorizationMutationScope::rawMaterialMutate(), $this->steward->id, fn () => DB::table('raw_material_document_uploads')->insert([
            'id' => $id, 'organization_id' => $this->steward->organization_id, 'location_id' => $this->location->id,
            'actor_user_id' => $this->steward->id, 'idempotency_key' => $key, 'original_filename' => 'dokument.txt',
            'media_type' => 'text/plain', 'size_bytes' => strlen($contents), 'sha256' => hash('sha256', $contents),
            'storage_key' => $storageKey, 'scan_state' => 'CLEAN', 'expires_at' => now()->addSeconds(2),
        ]));

        return ['upload_id' => $id];
    }

    private function b3Storage(): void
    {
        Queue::fake();
        Storage::fake('raw_material_documents');
        $scanner = Mockery::mock(DocumentScanner::class);
        $scanner->shouldReceive('scan')->andReturn(DocumentScanOutcome::clean('test-scanner', 'test-signatures'));
        $this->app->instance(DocumentScanner::class, $scanner);
    }

    private function b3Upload(string $key = 'b3-upload', string $filename = 'dokument.txt', string $contents = 'Dokument testowy B3'): array
    {
        return app(RawMaterialUploadService::class)->stage($this->steward, $this->location, $key, $filename, $contents);
    }

    private function b3DraftWithDocument(bool $declared = false): array
    {
        $upload = $this->b3Upload('b3-claimed');
        $input = ['category_id' => $this->category()['id'], 'name' => 'Dokumenty B3', 'base_unit_code' => 'g', 'nominal_quantity' => '1000'];
        if ($declared) {
            $input['allergen_declaration'] = ['status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $this->publishedAllergenCatalogFixture(), 'items' => []];
        }
        $material = $this->rawMaterialService->create($this->steward, $this->location, $input + ['document_upload_ids' => [$upload['upload_id']]]);

        return [$material, $input, DB::table('raw_material_document_uploads')->where('id', $upload['upload_id'])->value('claimed_version_id')];
    }

    private function b3Approve(string $id): void
    {
        $this->rawMaterialService->submit($this->steward, $this->location, $id, $this->rawVersion($id));
        $this->rawMaterialService->approve($this->steward, $this->location, $id, 'Test B3', $this->rawVersion($id));
    }

    private function b3Recipient(): Location
    {
        $location = $this->activeRestaurant('B3RECIPIENT');
        app(MembershipService::class)->assign($this->steward, $location);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id, 'location_id' => $location->id,
            'module_key' => 'RAW_MATERIALS', 'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id, 'updated_by' => $this->bootstrapAdministrator->id,
        ]);

        return $location;
    }
}
