<?php

namespace App\Domain\RawMaterials\Documents;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialLocationSharePolicy;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Jobs\ScanRawMaterialDocumentVersion;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class RawMaterialDocumentService
{
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024;

    private const DOCUMENT_TYPES = [
        'ALLERGEN_DECLARATION',
        'SUPPLIER_CERTIFICATE',
        'SPECIFICATION',
        'SAFETY_DATA_SHEET',
        'OTHER',
    ];

    private const LINK_PURPOSES = [
        'RAW_MATERIAL_SUPPORTING_DOCUMENT',
        'ALLERGEN_EVIDENCE',
        'SUPPLIER_COMPATIBILITY_EVIDENCE',
    ];

    public function __construct(
        private readonly RawMaterialAuthorization $authorization,
        private readonly RawMaterialLocationSharePolicy $sharePolicy,
        private readonly AuthorizationMutationContext $contexts,
        private readonly PrivateDocumentStorage $storage,
    ) {}

    public function create(
        User $actor,
        Location $context,
        string $rawMaterialRevisionId,
        string $documentType,
        string $originalFilename,
        string $contents,
        string $linkPurpose = 'RAW_MATERIAL_SUPPORTING_DOCUMENT',
        ?string $supplierProductRevisionId = null,
    ): array {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Create);
        $this->validateMetadata($documentType, $originalFilename, $contents, $linkPurpose, $supplierProductRevisionId);
        $revision = $this->requireRevision($actor, $rawMaterialRevisionId, $supplierProductRevisionId);
        $payload = $this->preparePayload($originalFilename, $contents);
        $objectKey = $this->newObjectKey();
        $this->storeAndVerify($objectKey, $contents, $payload['sha256']);

        try {
            $result = $this->contexts->run(
                AuthorizationMutationScope::rawMaterialMutate(),
                $actor->id,
                fn (): array => DB::transaction(function () use (
                    $actor,
                    $revision,
                    $documentType,
                    $payload,
                    $objectKey,
                    $linkPurpose,
                    $supplierProductRevisionId,
                ): array {
                    $documentId = Str::uuid()->toString();
                    DB::table('raw_material_documents')->insert([
                        'id' => $documentId,
                        'organization_id' => $actor->organization_id,
                        'document_type' => $documentType,
                        'created_by' => $actor->id,
                    ]);

                    $version = $this->insertVersion(
                        $actor,
                        $documentId,
                        1,
                        $revision->id,
                        $payload,
                        $objectKey,
                        $linkPurpose,
                        $supplierProductRevisionId,
                    );
                    $this->recordEvent($actor, $documentId, null, 'DOCUMENT_CREATED', [
                        'document_type' => $documentType,
                    ]);
                    $this->recordVersionEvents($actor, $documentId, $version, $revision->id);

                    return [
                        'id' => $documentId,
                        'document_type' => $documentType,
                        'version' => $this->presentVersion($version),
                    ];
                }),
            );
            ScanRawMaterialDocumentVersion::dispatch($result['version']['id']);

            return $result;
        } catch (Throwable $exception) {
            $this->cleanupIfUnreferenced($objectKey, $exception);
            throw $exception;
        }
    }

    public function appendVersion(
        User $actor,
        Location $context,
        string $documentId,
        string $rawMaterialRevisionId,
        string $originalFilename,
        string $contents,
        string $linkPurpose = 'RAW_MATERIAL_SUPPORTING_DOCUMENT',
        ?string $supplierProductRevisionId = null,
    ): array {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::Edit);
        $this->validateMetadata('OTHER', $originalFilename, $contents, $linkPurpose, $supplierProductRevisionId);

        $document = DB::table('raw_material_documents')
            ->where('id', $documentId)
            ->where('organization_id', $actor->organization_id)
            ->first();
        if (! $document) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_DOCUMENT_NOT_FOUND',
                'Nie znaleziono dokumentu.',
                404,
            );
        }
        $revision = $this->requireRevision($actor, $rawMaterialRevisionId, $supplierProductRevisionId);
        $payload = $this->preparePayload($originalFilename, $contents);
        $objectKey = $this->newObjectKey();
        $this->storeAndVerify($objectKey, $contents, $payload['sha256']);

        try {
            $result = $this->contexts->run(
                AuthorizationMutationScope::rawMaterialMutate(),
                $actor->id,
                fn (): array => DB::transaction(function () use (
                    $actor,
                    $documentId,
                    $revision,
                    $payload,
                    $objectKey,
                    $linkPurpose,
                    $supplierProductRevisionId,
                ): array {
                    DB::select(
                        'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
                        ['raw_material_document_versions', $documentId],
                    );
                    $lockedDocument = DB::table('raw_material_documents')
                        ->where('id', $documentId)
                        ->where('organization_id', $actor->organization_id)
                        ->first();
                    if (! $lockedDocument) {
                        throw new RawMaterialDocumentException(
                            'RAW_MATERIAL_DOCUMENT_NOT_FOUND',
                            'Nie znaleziono dokumentu.',
                            404,
                        );
                    }
                    $versionNumber = (int) DB::table('raw_material_document_versions')
                        ->where('document_id', $documentId)
                        ->where('organization_id', $actor->organization_id)
                        ->max('version_number') + 1;
                    $version = $this->insertVersion(
                        $actor,
                        $documentId,
                        $versionNumber,
                        $revision->id,
                        $payload,
                        $objectKey,
                        $linkPurpose,
                        $supplierProductRevisionId,
                    );
                    $this->recordVersionEvents($actor, $documentId, $version, $revision->id);

                    return [
                        'id' => $documentId,
                        'version' => $this->presentVersion($version),
                    ];
                }),
            );
            ScanRawMaterialDocumentVersion::dispatch($result['version']['id']);

            return $result;
        } catch (Throwable $exception) {
            $this->cleanupIfUnreferenced($objectKey, $exception);
            throw $exception;
        }
    }

    public function verifyVersion(User $actor, Location $context, string $versionId): array
    {
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);

        $isPrimary = $this->sharePolicy->isPrimaryContext($context);
        $versionQuery = DB::table('raw_material_document_versions as versions')
            ->join('raw_material_document_version_links as links', function ($join): void {
                $join->on('links.document_version_id', '=', 'versions.id')
                    ->on('links.organization_id', '=', 'versions.organization_id');
            })
            ->join('raw_material_revisions as revisions', function ($join): void {
                $join->on('revisions.id', '=', 'links.raw_material_revision_id')
                    ->on('revisions.organization_id', '=', 'links.organization_id');
            })
            ->join('raw_materials as materials', function ($join): void {
                $join->on('materials.id', '=', 'revisions.raw_material_id')
                    ->on('materials.organization_id', '=', 'revisions.organization_id');
            })
            ->where('versions.id', $versionId)
            ->where('versions.organization_id', $actor->organization_id)
            ->whereNull('materials.deleted_at')
            ->select([
                'versions.id',
                'versions.document_id',
                'versions.version_number',
                'versions.original_filename',
                'versions.media_type',
                'versions.size_bytes',
                'versions.sha256',
                'versions.scan_state',
                'versions.storage_key',
                'versions.stored_at',
                'links.raw_material_revision_id',
                'revisions.raw_material_id',
            ]);
        if (! $isPrimary) {
            $versionQuery
                ->whereColumn('materials.approved_revision_id', 'links.raw_material_revision_id')
                ->where('materials.lifecycle_state', 'ACTIVE');
        }
        $version = $versionQuery->first();
        if (! $version) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_DOCUMENT_VERSION_NOT_FOUND',
                'Nie znaleziono wersji dokumentu.',
                404,
            );
        }
        if (! $isPrimary) {
            $this->sharePolicy->assertCanConsume($actor, $context, $version->raw_material_id);
        }

        try {
            $contents = $this->storage->read($version->storage_key);
        } catch (DocumentStorageException $exception) {
            throw new RawMaterialDocumentException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->errorCode === 'RAW_MATERIAL_DOCUMENT_PAYLOAD_MISSING' ? 409 : 503,
                $exception,
            );
        }
        if (strlen($contents) !== (int) $version->size_bytes
            || ! hash_equals($version->sha256, hash('sha256', $contents))) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_DOCUMENT_CHECKSUM_MISMATCH',
                'Kontrola integralności pliku dokumentu nie powiodła się.',
                409,
            );
        }

        $latestScan = DB::table('raw_material_document_scan_results')
            ->where('document_version_id', $version->id)
            ->where('organization_id', $actor->organization_id)
            ->orderByDesc('scan_number')
            ->first(['result_state']);

        return [
            'verified' => true,
            'version' => $this->presentVersion($version, $latestScan?->result_state),
            'raw_material_revision_id' => $version->raw_material_revision_id,
        ];
    }

    public function inspectUpload(string $filename, string $contents): array
    {
        $this->validateMetadata('OTHER', $filename, $contents, 'RAW_MATERIAL_SUPPORTING_DOCUMENT', null);
        $payload = $this->preparePayload($filename, $contents);
        $allowed = [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv'],
        ];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($payload['media_type'], $allowed[$extension] ?? [], true)) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_DOCUMENT_FORMAT_UNSUPPORTED', 'Zawartość pliku nie odpowiada dozwolonemu formatowi PDF, PNG, JPEG, TXT lub CSV.', 422);
        }

        return $payload;
    }

    public function promoteUpload(User $actor, string $revisionId, object $upload): string
    {
        $documentId = Str::uuid()->toString();
        DB::table('raw_material_documents')->insert([
            'id' => $documentId, 'organization_id' => $actor->organization_id,
            'document_type' => 'OTHER', 'created_by' => $actor->id,
        ]);
        $version = $this->insertVersion(
            $actor, $documentId, 1, $revisionId, (array) $upload, $upload->storage_key,
            'RAW_MATERIAL_SUPPORTING_DOCUMENT', null,
        );
        $this->recordEvent($actor, $documentId, null, 'DOCUMENT_CREATED', ['document_type' => 'OTHER']);
        $this->recordVersionEvents($actor, $documentId, $version, $revisionId);
        ScanRawMaterialDocumentVersion::dispatch($version->id)->afterCommit();

        return $version->id;
    }

    private function insertVersion(
        User $actor,
        string $documentId,
        int $versionNumber,
        string $rawMaterialRevisionId,
        array $payload,
        string $objectKey,
        string $linkPurpose,
        ?string $supplierProductRevisionId,
    ): object {
        $versionId = Str::uuid()->toString();
        $storedAt = now()->utc();
        DB::table('raw_material_document_versions')->insert([
            'id' => $versionId,
            'organization_id' => $actor->organization_id,
            'document_id' => $documentId,
            'version_number' => $versionNumber,
            'original_filename' => $payload['original_filename'],
            'media_type' => $payload['media_type'],
            'size_bytes' => $payload['size_bytes'],
            'sha256' => $payload['sha256'],
            'storage_key' => $objectKey,
            'scan_state' => 'PENDING_SCAN',
            'created_by' => $actor->id,
            'stored_at' => $storedAt,
        ]);
        DB::table('raw_material_document_version_links')->insert([
            'document_version_id' => $versionId,
            'organization_id' => $actor->organization_id,
            'raw_material_revision_id' => $rawMaterialRevisionId,
            'supplier_product_revision_id' => $supplierProductRevisionId,
            'link_purpose' => $linkPurpose,
        ]);

        return (object) [
            'id' => $versionId,
            'version_number' => $versionNumber,
            'original_filename' => $payload['original_filename'],
            'media_type' => $payload['media_type'],
            'size_bytes' => $payload['size_bytes'],
            'sha256' => $payload['sha256'],
            'scan_state' => 'PENDING_SCAN',
            'stored_at' => $storedAt,
        ];
    }

    private function recordVersionEvents(User $actor, string $documentId, object $version, string $revisionId): void
    {
        DB::table('raw_material_document_scan_results')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'document_version_id' => $version->id,
            'sha256' => $version->sha256,
            'scan_number' => 1,
            'result_state' => DocumentScanStatus::Pending->value,
            'recorded_by' => $actor->id,
        ]);
        $metadata = [
            'version_number' => $version->version_number,
            'sha256' => $version->sha256,
            'raw_material_revision_id' => $revisionId,
        ];
        $this->recordEvent($actor, $documentId, $version->id, 'VERSION_STORED', $metadata);
        $this->recordEvent($actor, $documentId, $version->id, 'VERSION_LINKED', [
            'raw_material_revision_id' => $revisionId,
        ]);
        $this->recordEvent($actor, $documentId, $version->id, 'SCAN_REQUIRED', [
            'scan_state' => $version->scan_state,
        ]);
    }

    private function recordEvent(
        User $actor,
        string $documentId,
        ?string $versionId,
        string $eventCode,
        array $metadata,
    ): void {
        DB::table('raw_material_document_lifecycle_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'document_id' => $documentId,
            'document_version_id' => $versionId,
            'event_code' => $eventCode,
            'actor_user_id' => $actor->id,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function requireRevision(
        User $actor,
        string $revisionId,
        ?string $supplierProductRevisionId,
    ): object {
        $revision = DB::table('raw_material_revisions')
            ->where('id', $revisionId)
            ->where('organization_id', $actor->organization_id)
            ->whereExists(fn ($materials) => $materials->selectRaw('1')
                ->from('raw_materials')
                ->whereColumn('raw_materials.id', 'raw_material_revisions.raw_material_id')
                ->whereColumn('raw_materials.organization_id', 'raw_material_revisions.organization_id')
                ->whereNull('raw_materials.deleted_at'))
            ->first(['id']);
        if (! $revision) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_REVISION_NOT_FOUND',
                'Nie znaleziono wskazanej rewizji surowca.',
                404,
            );
        }

        if ($supplierProductRevisionId !== null && ! DB::table('supplier_product_revisions')
            ->where('id', $supplierProductRevisionId)
            ->where('organization_id', $actor->organization_id)
            ->where('raw_material_revision_id', $revisionId)
            ->exists()) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_SUPPLIER_REVISION_MISMATCH',
                'Rewizja produktu dostawcy nie jest powiązana ze wskazaną rewizją surowca.',
                422,
            );
        }

        return $revision;
    }

    private function validateMetadata(
        string $documentType,
        string $originalFilename,
        string $contents,
        string $linkPurpose,
        ?string $supplierProductRevisionId,
    ): void {
        if (! in_array($documentType, self::DOCUMENT_TYPES, true)
            || ! in_array($linkPurpose, self::LINK_PURPOSES, true)
            || (($linkPurpose === 'SUPPLIER_COMPATIBILITY_EVIDENCE') !== ($supplierProductRevisionId !== null))) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_DOCUMENT_METADATA_INVALID',
                'Dane dokumentu lub jego powiązania są nieprawidłowe.',
                422,
            );
        }
        if ($originalFilename === '' || strlen($originalFilename) > 255
            || basename($originalFilename) !== $originalFilename
            || str_contains($originalFilename, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $originalFilename) === 1) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_DOCUMENT_FILENAME_INVALID',
                'Nazwa pliku jest nieprawidłowa.',
                422,
            );
        }
        if ($contents === '' || strlen($contents) > self::MAX_SIZE_BYTES) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_DOCUMENT_SIZE_INVALID',
                'Plik dokumentu jest pusty albo przekracza dozwolony rozmiar.',
                422,
            );
        }
    }

    private function preparePayload(string $originalFilename, string $contents): array
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mediaType = $fileInfo->buffer($contents);
        if (! is_string($mediaType) || preg_match('/\A[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+\z/', $mediaType) !== 1) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_DOCUMENT_MEDIA_TYPE_INVALID',
                'Nie udało się ustalić typu pliku dokumentu.',
                422,
            );
        }

        return [
            'original_filename' => $originalFilename,
            'media_type' => $mediaType,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function newObjectKey(): string
    {
        $uuid = Str::uuid()->toString();

        return 'raw-material-documents/'.substr($uuid, 0, 2).'/'.$uuid;
    }

    private function storeAndVerify(string $objectKey, string $contents, string $sha256): void
    {
        try {
            $this->storage->put($objectKey, $contents);
            $storedContents = $this->storage->read($objectKey);
        } catch (DocumentStorageException $exception) {
            $this->cleanupObject($objectKey, $exception);
            throw new RawMaterialDocumentException(
                $exception->errorCode,
                $exception->getMessage(),
                503,
                $exception,
            );
        }

        if (strlen($storedContents) !== strlen($contents) || ! hash_equals($sha256, hash('sha256', $storedContents))) {
            $integrityException = new DocumentStorageException(
                'RAW_MATERIAL_DOCUMENT_STORAGE_VERIFY_FAILED',
                'Weryfikacja zapisanego pliku dokumentu nie powiodła się.',
            );
            $this->cleanupObject($objectKey, $integrityException);
            throw new RawMaterialDocumentException(
                $integrityException->errorCode,
                $integrityException->getMessage(),
                503,
                $integrityException,
            );
        }
    }

    private function cleanupIfUnreferenced(string $objectKey, Throwable $cause): void
    {
        try {
            $isReferenced = DB::table('raw_material_document_versions')
                ->where('storage_key', $objectKey)
                ->exists();
        } catch (Throwable $checkFailure) {
            Log::warning('Nie można potwierdzić, czy plik dokumentu ma zatwierdzone metadane; pozostawiono obiekt bez zmian.', [
                'cause' => $cause::class,
                'check_failure' => $checkFailure::class,
            ]);

            return;
        }

        if (! $isReferenced) {
            $this->cleanupObject($objectKey, $cause);
        }
    }

    private function cleanupObject(string $objectKey, Throwable $cause): void
    {
        try {
            $this->storage->delete($objectKey);
        } catch (DocumentStorageException $cleanupFailure) {
            Log::error('Nie udało się posprzątać niepowiązanego obiektu dokumentu po błędzie zapisu.', [
                'cause' => $cause::class,
                'cleanup_failure' => $cleanupFailure::class,
            ]);
        }
    }

    private function presentVersion(object $version, ?string $scanResultState = null): array
    {
        return [
            'id' => $version->id,
            'version_number' => (int) $version->version_number,
            'original_filename' => $version->original_filename,
            'media_type' => $version->media_type,
            'size_bytes' => (int) $version->size_bytes,
            'sha256' => $version->sha256,
            'scan_state' => $version->scan_state,
            'scan_result_state' => $scanResultState
                ?? $version->scan_result_state
                ?? DocumentScanStatus::Pending->value,
            'stored_at' => $version->stored_at,
        ];
    }
}
