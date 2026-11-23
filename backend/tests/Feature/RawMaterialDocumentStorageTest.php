<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\Documents\DocumentScanner;
use App\Domain\RawMaterials\Documents\DocumentScannerException;
use App\Domain\RawMaterials\Documents\DocumentScanOutcome;
use App\Domain\RawMaterials\Documents\DocumentScanStatus;
use App\Domain\RawMaterials\Documents\DocumentStorageException;
use App\Domain\RawMaterials\Documents\PrivateDocumentStorage;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentException;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentScanService;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentService;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Jobs\ScanRawMaterialDocumentVersion;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialDocumentStorageTest extends AuthorizationTestCase
{
    private Location $location;

    private User $administrator;

    private User $steward;

    private RawMaterialDocumentService $documents;

    private RawMaterialService $rawMaterials;

    private string $categoryId;

    private string $materialId;

    private string $revisionId;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->storageRoot = storage_path('framework/testing/raw-material-documents/'.Str::uuid());
        config(['filesystems.disks.raw_material_documents.root' => $this->storageRoot]);
        app('filesystem')->forgetDisk('raw_material_documents');

        $this->activeRestaurant();
        $this->location = $this->primaryCentralKitchen();
        $this->administrator = $this->user('document-admin@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->location, $this->administrator->id);
        $this->steward = $this->user('document-steward@example.test');
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
            'code' => 'DOCUMENTS',
            'name' => 'Dokumenty testowe',
        ]);
        $this->categoryId = $category['id'];
        $material = $this->rawMaterials->create($this->steward, $this->location, [
            'category_id' => $this->categoryId,
            'name' => 'Surowiec testowy do dokumentów',
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
        ]);
        $this->materialId = $material['id'];
        $this->revisionId = $material['revision']['id'];
        $this->documents = app(RawMaterialDocumentService::class);
    }

    protected function tearDown(): void
    {
        try {
            (new Filesystem)->deleteDirectory($this->storageRoot);
        } finally {
            parent::tearDown();
        }
    }

    public function test_create_stores_private_payload_and_links_the_exact_revision(): void
    {
        $contents = "synthetic document only\n";
        $result = $this->documents->create(
            $this->steward,
            $this->location,
            $this->revisionId,
            'SPECIFICATION',
            'specification.txt',
            $contents,
        );
        $version = DB::table('raw_material_document_versions')
            ->where('id', $result['version']['id'])->first();
        $link = DB::table('raw_material_document_version_links')
            ->where('document_version_id', $version->id)->first();

        $this->assertSame(1, (int) $version->version_number);
        $this->assertSame(hash('sha256', $contents), $version->sha256);
        $this->assertSame(strlen($contents), (int) $version->size_bytes);
        $this->assertSame('PENDING_SCAN', $version->scan_state);
        $this->assertSame(1, DB::table('raw_material_document_scan_results')
            ->where('document_version_id', $version->id)
            ->where('result_state', 'PENDING')
            ->count());
        Queue::assertPushed(ScanRawMaterialDocumentVersion::class);
        $this->assertSame($this->revisionId, $link->raw_material_revision_id);
        $this->assertNull($link->supplier_product_revision_id);
        $this->assertSame($contents, Storage::disk('raw_material_documents')->get($version->storage_key));
        $this->assertStringStartsWith('raw-material-documents/', $version->storage_key);
        $this->assertArrayNotHasKey('storage_key', $result['version']);
        $this->assertSame(4, DB::table('raw_material_document_lifecycle_events')
            ->where('document_id', $result['id'])->count());
        $this->assertTrue($this->documents->verifyVersion(
            $this->steward,
            $this->location,
            $version->id,
        )['verified']);

        $this->assertFalse((bool) config('filesystems.disks.raw_material_documents.serve'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.raw_material_documents'));
        $this->assertStringStartsNotWith(public_path(), config('filesystems.disks.raw_material_documents.root'));
        $columns = DB::connection('pgsql_setup')->select(<<<'SQL'
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'raw_material_documents'
                ORDER BY column_name
                SQL);
        $this->assertSame(
            ['created_at', 'created_by', 'document_type', 'id', 'organization_id'],
            array_map(fn (object $column): string => $column->column_name, $columns),
        );
    }

    public function test_compatibility_history_is_retained_read_only_and_new_links_are_informational(): void
    {
        $this->assertNotNull(DB::scalar("SELECT to_regclass('public.raw_material_supplier_compatibility_assessments')"));
        $this->assertNull(DB::scalar("SELECT to_regclass('public.raw_material_supplier_compatibility_status')"));
        $this->assertNull(DB::scalar("SELECT to_regclass('public.raw_material_approved_compatibility_document_evidence')"));
        $this->assertFalse((bool) DB::scalar(
            "SELECT has_table_privilege(current_user, 'public.raw_material_supplier_compatibility_assessments', 'INSERT')",
        ));
        DB::table('raw_material_supplier_compatibility_assessments')->limit(1)->get();

        $document = $this->documents->create(
            $this->steward,
            $this->location,
            $this->revisionId,
            'SUPPLIER_CERTIFICATE',
            'informacyjny-certyfikat.txt',
            "certificate data\n",
        );
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('raw_material_document_version_links')
            ->where('document_version_id', $document['version']['id'])
            ->update(['link_purpose' => 'ALLERGEN_EVIDENCE']));
    }

    public function test_clamav_scans_synthetic_clean_and_eicar_samples(): void
    {
        $scanner = app(DocumentScanner::class);
        $clean = $scanner->scan("synthetic clean document\n");
        $infected = $scanner->scan(
            'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
        );

        $this->assertSame(DocumentScanStatus::Clean, $clean->status);
        $this->assertNotSame('', $clean->scannerVersion);
        $this->assertNotSame('', $clean->signatureVersion);
        $this->assertSame(DocumentScanStatus::Infected, $infected->status);
        $this->assertSame('Eicar-Test-Signature', $infected->detectionName);
    }

    public function test_scan_results_remain_file_security_controls_only(): void
    {
        $version = $this->createScannableDocumentVersion();
        $scanService = app(RawMaterialDocumentScanService::class);

        $this->app->instance(DocumentScanner::class, new class implements DocumentScanner
        {
            public function scan(string $contents): DocumentScanOutcome
            {
                return DocumentScanOutcome::clean('1.4.3', '27910');
            }
        });
        $scanService = app(RawMaterialDocumentScanService::class);
        $clean = $scanService->scanVersion($version['version']['id']);
        $this->assertSame('CLEAN', $clean['status']);
        $this->assertSame($version['version']['sha256'], $clean['sha256']);
        $this->assertSame('DRAFT', DB::table('raw_materials')->where('id', $this->materialId)->value('lifecycle_state'));

        $this->app->instance(DocumentScanner::class, new class implements DocumentScanner
        {
            public function scan(string $contents): DocumentScanOutcome
            {
                return DocumentScanOutcome::infected('1.4.3', '27910', 'Eicar-Test-Signature');
            }
        });
        $scanService = app(RawMaterialDocumentScanService::class);
        $infected = $scanService->scanVersion($version['version']['id']);
        $this->assertSame('INFECTED', $infected['status']);

        $this->assertSame(
            ['PENDING', 'CLEAN', 'INFECTED'],
            DB::table('raw_material_document_scan_results')
                ->where('document_version_id', $version['version']['id'])
                ->orderBy('scan_number')
                ->pluck('result_state')
                ->all(),
        );
    }

    public function test_scanner_errors_missing_payload_and_checksum_mismatch_remain_blocked(): void
    {
        $version = $this->createScannableDocumentVersion();
        $scanService = app(RawMaterialDocumentScanService::class);
        $this->app->instance(DocumentScanner::class, new class implements DocumentScanner
        {
            public function scan(string $contents): DocumentScanOutcome
            {
                throw new DocumentScannerException('CLAMAV_UNAVAILABLE', 'Skaner testowy jest niedostępny.');
            }
        });

        $scanService = app(RawMaterialDocumentScanService::class);
        $error = $scanService->scanVersion($version['version']['id']);
        $this->assertSame('ERROR', $error['status']);
        $this->assertSame('CLAMAV_UNAVAILABLE', $error['error_code']);
        $this->assertSame('DRAFT', DB::table('raw_materials')->where('id', $this->materialId)->value('lifecycle_state'));

        $second = $this->createScannableDocumentVersion();
        $storedVersion = DB::table('raw_material_document_versions')
            ->where('id', $second['version']['id'])->first();
        Storage::disk('raw_material_documents')->delete($storedVersion->storage_key);
        $missing = $scanService->scanVersion($second['version']['id']);
        $this->assertSame('ERROR', $missing['status']);
        $this->assertSame('RAW_MATERIAL_DOCUMENT_PAYLOAD_MISSING', $missing['error_code']);
        $this->assertSame('DRAFT', DB::table('raw_materials')->where('id', $this->materialId)->value('lifecycle_state'));
    }

    public function test_runtime_cannot_record_authoritative_scan_results_or_mutate_scan_history(): void
    {
        $version = $this->documents->create(
            $this->steward,
            $this->location,
            $this->revisionId,
            'OTHER',
            'trusted-scan.txt',
            'synthetic scan trust test',
        );
        $row = DB::table('raw_material_document_scan_results')
            ->where('document_version_id', $version['version']['id'])
            ->where('result_state', 'PENDING')
            ->first();

        try {
            DB::table('raw_material_document_scan_results')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->steward->organization_id,
                'document_version_id' => $row->document_version_id,
                'sha256' => $row->sha256,
                'scan_number' => 2,
                'result_state' => 'CLEAN',
                'scanner_service' => 'CLAMAV',
                'scanner_version' => '1.4.3',
                'signature_version' => '27910',
            ]);
            $this->fail('Direct runtime SQL recorded an authoritative scan result.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0] ?? null);
        }

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('raw_material_document_scan_results')
            ->where('id', $row->id)
            ->update(['result_state' => 'CLEAN']));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('raw_material_document_scan_results')
            ->where('id', $row->id)
            ->delete());
    }

    public function test_new_version_preserves_old_payload_and_its_revision_link(): void
    {
        $first = $this->documents->create(
            $this->steward,
            $this->location,
            $this->revisionId,
            'SPECIFICATION',
            'version-one.txt',
            'synthetic version one',
        );
        $nextRevision = $this->rawMaterials->revise($this->steward, $this->location, $this->materialId, [
            'category_id' => $this->categoryId,
            'name' => 'Nowa rewizja surowca',
            'base_unit_code' => 'g',
        ], expectedVersion: $this->rawVersion($this->materialId));
        $second = $this->documents->appendVersion(
            $this->steward,
            $this->location,
            $first['id'],
            $nextRevision['revision']['id'],
            'version-two.txt',
            'synthetic version two',
        );

        $versions = DB::table('raw_material_document_versions')
            ->where('document_id', $first['id'])->orderBy('version_number')->get();
        $links = DB::table('raw_material_document_version_links')
            ->whereIn('document_version_id', $versions->pluck('id'))->orderBy('document_version_id')->get();

        $this->assertCount(2, $versions);
        $this->assertSame([1, 2], $versions->pluck('version_number')->map(fn ($value): int => (int) $value)->all());
        $this->assertSame('synthetic version one', Storage::disk('raw_material_documents')->get($versions[0]->storage_key));
        $this->assertSame('synthetic version two', Storage::disk('raw_material_documents')->get($versions[1]->storage_key));
        $this->assertSame(2, $links->count());
        $this->assertSame($this->revisionId, $links->firstWhere('document_version_id', $versions[0]->id)->raw_material_revision_id);
        $this->assertSame($nextRevision['revision']['id'], $links->firstWhere('document_version_id', $versions[1]->id)->raw_material_revision_id);
        $this->assertSame(7, DB::table('raw_material_document_lifecycle_events')
            ->where('document_id', $first['id'])->count());
        $this->assertSame($first['id'], $second['id']);
    }

    public function test_document_versions_links_and_events_are_immutable(): void
    {
        $result = $this->documents->create(
            $this->steward,
            $this->location,
            $this->revisionId,
            'OTHER',
            'immutable.txt',
            'synthetic immutable content',
        );
        $versionId = $result['version']['id'];

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_document_versions')
            ->where('id', $versionId)->update(['original_filename' => 'modified.txt']));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_document_versions')->where('id', $versionId)->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_document_version_links')
            ->where('document_version_id', $versionId)->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_document_lifecycle_events')
            ->where('document_version_id', $versionId)->update(['event_code' => 'SCAN_REQUIRED']));
    }

    public function test_database_rejects_cross_organization_document_references(): void
    {
        $result = $this->documents->create(
            $this->steward,
            $this->location,
            $this->revisionId,
            'OTHER',
            'tenant.txt',
            'synthetic tenant data',
        );
        $versionId = $result['version']['id'];

        $this->withForeignOrganizationFixture(function (Location $foreignLocation): void {
            $this->assertDatabaseRejects('23503', fn (Connection $connection) => $connection->table('raw_material_document_version_links')->insert([
                'document_version_id' => Str::uuid()->toString(),
                'organization_id' => $foreignLocation->organization_id,
                'raw_material_revision_id' => $this->revisionId,
                'link_purpose' => 'RAW_MATERIAL_SUPPORTING_DOCUMENT',
            ]));
        });
    }

    public function test_checksum_mismatch_and_missing_payload_are_reported_without_serving_content(): void
    {
        $result = $this->documents->create(
            $this->steward,
            $this->location,
            $this->revisionId,
            'OTHER',
            'integrity.txt',
            'original synthetic bytes',
        );
        $versionId = $result['version']['id'];
        $version = DB::table('raw_material_document_versions')->where('id', $versionId)->first();
        $disk = Storage::disk('raw_material_documents');

        $disk->put($version->storage_key, 'modified synthetic bytes', ['visibility' => 'private']);
        try {
            $this->documents->verifyVersion($this->steward, $this->location, $versionId);
            $this->fail('A checksum mismatch was accepted.');
        } catch (RawMaterialDocumentException $exception) {
            $this->assertSame('RAW_MATERIAL_DOCUMENT_CHECKSUM_MISMATCH', $exception->errorCode);
        }

        $disk->delete($version->storage_key);
        try {
            $this->documents->verifyVersion($this->steward, $this->location, $versionId);
            $this->fail('A missing private payload was accepted.');
        } catch (RawMaterialDocumentException $exception) {
            $this->assertSame('RAW_MATERIAL_DOCUMENT_PAYLOAD_MISSING', $exception->errorCode);
        }
    }

    public function test_storage_failure_leaves_no_document_metadata(): void
    {
        $storage = Mockery::mock(PrivateDocumentStorage::class);
        $storage->shouldReceive('put')->once()->andThrow(new DocumentStorageException(
            'RAW_MATERIAL_DOCUMENT_STORAGE_WRITE_FAILED',
            'Nie udało się bezpiecznie zapisać pliku dokumentu.',
        ));
        $storage->shouldReceive('delete')->once()->andReturnNull();
        $this->app->instance(PrivateDocumentStorage::class, $storage);
        $service = app(RawMaterialDocumentService::class);

        try {
            $service->create(
                $this->steward,
                $this->location,
                $this->revisionId,
                'OTHER',
                'failed.txt',
                'synthetic failed upload',
            );
            $this->fail('A failed storage write produced a successful result.');
        } catch (RawMaterialDocumentException $exception) {
            $this->assertSame('RAW_MATERIAL_DOCUMENT_STORAGE_WRITE_FAILED', $exception->errorCode);
            $this->assertSame(0, DB::table('raw_material_documents')->count());
            $this->assertSame(0, DB::table('raw_material_document_versions')->count());
        }
    }

    public function test_client_filename_cannot_select_a_filesystem_path(): void
    {
        try {
            $this->documents->create(
                $this->steward,
                $this->location,
                $this->revisionId,
                'OTHER',
                '../private.txt',
                'synthetic path test',
            );
            $this->fail('A path-like client filename was accepted.');
        } catch (RawMaterialDocumentException $exception) {
            $this->assertSame('RAW_MATERIAL_DOCUMENT_FILENAME_INVALID', $exception->errorCode);
            $this->assertSame(0, DB::table('raw_material_documents')->count());
        }
    }

    private function createScannableDocumentVersion(): array
    {
        return $this->documents->create(
            $this->steward,
            $this->location,
            $this->revisionId,
            'SUPPLIER_CERTIFICATE',
            'synthetic-supplier-certificate.txt',
            "synthetic supplier certificate\n",
        );
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
