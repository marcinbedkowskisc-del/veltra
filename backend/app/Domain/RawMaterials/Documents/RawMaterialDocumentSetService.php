<?php

namespace App\Domain\RawMaterials\Documents;

use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RawMaterialDocumentSetService
{
    public function __construct(
        private RawMaterialUploadService $uploads,
        private RawMaterialAuthorization $authorization,
        private PrivateDocumentStorage $storage,
    ) {}

    public function save(User $actor, Location $context, string $revisionId, ?string $baseRevisionId, array $input): void
    {
        Validator::make($input, [
            'document_upload_ids' => ['sometimes', 'array'],
            'document_upload_ids.*' => ['required', 'uuid', 'distinct'],
            'detach_document_version_ids' => ['sometimes', 'array'],
            'detach_document_version_ids.*' => ['required', 'uuid', 'distinct'],
        ])->validate();
        $ids = $baseRevisionId === null ? [] : $this->ids($actor->organization_id, $baseRevisionId);
        $detach = $input['detach_document_version_ids'] ?? [];
        if (array_diff($detach, $ids) !== []) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_DOCUMENT_NOT_FOUND', 'Nie znaleziono dokumentu w edytowanej rewizji.', 404);
        }
        $ids = array_values(array_diff($ids, $detach));
        $uploadIds = $input['document_upload_ids'] ?? [];
        sort($uploadIds);
        foreach ($uploadIds as $uploadId) {
            $ids[] = $this->uploads->claim($actor, $context, $uploadId, $revisionId);
        }
        sort($ids);
        DB::table('raw_material_revision_document_sets')->insert([
            'revision_id' => $revisionId,
            'organization_id' => $actor->organization_id,
            'document_version_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
        ]);
    }

    public function ids(string $organizationId, string $revisionId): array
    {
        $set = DB::table('raw_material_revision_document_sets')->where('organization_id', $organizationId)
            ->where('revision_id', $revisionId)->first();
        $original = DB::table('raw_material_document_version_links')->where('organization_id', $organizationId)
            ->where('raw_material_revision_id', $revisionId)->whereNull('supplier_product_revision_id')
            ->orderBy('document_version_id')->pluck('document_version_id')->all();

        return array_values(array_unique(array_merge(
            $original,
            $set === null ? [] : json_decode($set->document_version_ids, true, flags: JSON_THROW_ON_ERROR),
        )));
    }

    public function listing(User $actor, Location $context, string $materialId, bool $editing = false): array
    {
        $service = app(RawMaterialService::class);
        $snapshot = $editing ? $service->editor($actor, $context, $materialId) : $service->show($actor, $context, $materialId);
        $revisionId = $snapshot['revision']['id'] ?? null;
        if ($revisionId === null) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_DOCUMENT_NOT_FOUND', 'Nie znaleziono dostępnej rewizji dokumentów.', 404);
        }
        $versions = DB::table('raw_material_document_versions as versions')
            ->join('raw_material_documents as documents', function ($join): void {
                $join->on('documents.id', '=', 'versions.document_id')
                    ->on('documents.organization_id', '=', 'versions.organization_id');
            })
            ->where('versions.organization_id', $actor->organization_id)
            ->whereIn('versions.id', $this->ids($actor->organization_id, $revisionId))
            ->orderBy('versions.original_filename')->orderBy('versions.id')
            ->get(['versions.*', 'documents.document_type']);

        return [
            'revision_id' => $revisionId,
            'documents' => $versions->map(function (object $version) use ($materialId, $editing): array {
                $scan = $this->scanState($version);
                $suffix = $editing ? '?view=editor' : '';
                $base = '/api/v1/raw-materials/'.$materialId.'/documents/'.$version->id;

                return [
                    'id' => $version->id,
                    'filename' => $version->original_filename,
                    'document_type' => $version->document_type,
                    'media_type' => $version->media_type,
                    'size_bytes' => (int) $version->size_bytes,
                    'status' => $scan,
                    'download_url' => $scan === 'CLEAN' ? $base.'/download'.$suffix : null,
                    'preview_url' => $scan === 'CLEAN' && $this->previewable($version->media_type) ? $base.'/preview'.$suffix : null,
                ];
            })->all(),
        ];
    }

    public function read(User $actor, Location $context, string $materialId, string $versionId, bool $preview, bool $editing = false): array
    {
        $listing = $this->listing($actor, $context, $materialId, $editing);
        if (! in_array($versionId, array_column($listing['documents'], 'id'), true)) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_DOCUMENT_NOT_FOUND', 'Nie znaleziono dostępnego dokumentu.', 404);
        }
        $this->authorization->assertAllowed($actor, $context, RawMaterialPermission::View);
        $version = DB::table('raw_material_document_versions')->where('organization_id', $actor->organization_id)->where('id', $versionId)->first();
        if ($this->scanState($version) !== 'CLEAN') {
            throw new RawMaterialDocumentException('RAW_MATERIAL_DOCUMENT_NOT_CLEAN', 'Dokument jest zablokowany do czasu poprawnego skanowania.', 409);
        }
        if ($preview && ! $this->previewable($version->media_type)) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_DOCUMENT_PREVIEW_UNSUPPORTED', 'Ten format jest dostępny wyłącznie do pobrania.', 422);
        }
        $contents = $this->storage->read($version->storage_key);
        if (strlen($contents) !== (int) $version->size_bytes || ! hash_equals($version->sha256, hash('sha256', $contents))) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_DOCUMENT_CHECKSUM_MISMATCH', 'Kontrola integralności dokumentu nie powiodła się.', 409);
        }

        return ['contents' => $contents, 'filename' => $version->original_filename, 'media_type' => $version->media_type];
    }

    private function scanState(object $version): string
    {
        $scan = DB::table('raw_material_document_scan_results')->where('organization_id', $version->organization_id)
            ->where('document_version_id', $version->id)->orderByDesc('scan_number')->first();

        return $scan && hash_equals($version->sha256, $scan->sha256) ? $scan->result_state : 'PENDING';
    }

    private function previewable(string $mediaType): bool
    {
        return in_array($mediaType, ['application/pdf', 'image/png', 'image/jpeg'], true);
    }
}
