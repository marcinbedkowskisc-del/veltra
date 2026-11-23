<?php

namespace App\Domain\RawMaterials\Documents;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RawMaterialDocumentScanService
{
    public function __construct(
        private readonly PrivateDocumentStorage $storage,
        private readonly DocumentScanner $scanner,
        private readonly AuthorizationMutationContext $contexts,
    ) {}

    public function scanVersion(string $versionId): array
    {
        $version = DB::table('raw_material_document_versions')
            ->where('id', $versionId)
            ->first([
                'id',
                'organization_id',
                'size_bytes',
                'sha256',
                'storage_key',
            ]);

        if (! $version) {
            throw new RawMaterialDocumentException(
                'RAW_MATERIAL_DOCUMENT_VERSION_NOT_FOUND',
                'Nie znaleziono wersji dokumentu do skanowania.',
                404,
            );
        }

        try {
            $contents = $this->storage->read($version->storage_key);
        } catch (DocumentStorageException $exception) {
            return $this->recordError($version, $exception->errorCode);
        }

        if (strlen($contents) !== (int) $version->size_bytes
            || ! hash_equals($version->sha256, hash('sha256', $contents))) {
            return $this->recordError($version, 'RAW_MATERIAL_DOCUMENT_CHECKSUM_MISMATCH');
        }

        try {
            $outcome = $this->scanner->scan($contents);
        } catch (DocumentScannerException $exception) {
            return $this->recordError($version, $exception->errorCode);
        }

        return $this->recordResult($version, $outcome);
    }

    private function recordError(object $version, string $errorCode): array
    {
        Log::warning('Skanowanie dokumentu nie zakończyło się wiarygodnym wynikiem; dokument pozostaje zablokowany.', [
            'document_version_id' => $version->id,
            'error_code' => $errorCode,
        ]);

        return $this->recordResult($version, null, $errorCode);
    }

    private function recordResult(
        object $version,
        ?DocumentScanOutcome $outcome,
        ?string $errorCode = null,
    ): array {
        $status = $outcome?->status ?? DocumentScanStatus::Error;

        return $this->contexts->run(
            AuthorizationMutationScope::rawMaterialDocumentScanResult(),
            null,
            function () use ($version, $outcome, $errorCode, $status): array {
                return DB::transaction(function () use ($version, $outcome, $errorCode, $status): array {
                    DB::select(
                        'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
                        ['raw_material_document_scan_results', $version->id],
                    );
                    $scanNumber = (int) DB::table('raw_material_document_scan_results')
                        ->where('document_version_id', $version->id)
                        ->where('organization_id', $version->organization_id)
                        ->max('scan_number') + 1;
                    $id = Str::uuid()->toString();

                    DB::table('raw_material_document_scan_results')->insert([
                        'id' => $id,
                        'organization_id' => $version->organization_id,
                        'document_version_id' => $version->id,
                        'sha256' => $version->sha256,
                        'scan_number' => $scanNumber,
                        'result_state' => $status->value,
                        'scanner_service' => 'CLAMAV',
                        'scanner_version' => $outcome?->scannerVersion,
                        'signature_version' => $outcome?->signatureVersion,
                        'detection_name' => $outcome?->detectionName,
                        'error_code' => $errorCode,
                        'recorded_by' => null,
                    ]);

                    return [
                        'id' => $id,
                        'document_version_id' => $version->id,
                        'sha256' => $version->sha256,
                        'scan_number' => $scanNumber,
                        'status' => $status->value,
                        'error_code' => $errorCode,
                        'detection_name' => $outcome?->detectionName,
                    ];
                });
            },
        );
    }
}
