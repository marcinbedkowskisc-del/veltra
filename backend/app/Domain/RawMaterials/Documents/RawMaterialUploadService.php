<?php

namespace App\Domain\RawMaterials\Documents;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RawMaterialUploadService
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private RawMaterialAuthorization $authorization,
        private AuthorizationMutationContext $contexts,
        private RawMaterialDocumentService $documents,
        private PrivateDocumentStorage $storage,
        private DocumentScanner $scanner,
    ) {}

    public function authorize(User $actor, Location $context): void
    {
        $permission = $this->authorization->hasEffectiveGrant($actor, RawMaterialPermission::Edit)
            ? RawMaterialPermission::Edit : RawMaterialPermission::Create;
        $this->authorization->assertAllowed($actor, $context, $permission);
    }

    public function stage(User $actor, Location $context, string $key, string $filename, string $contents): array
    {
        $this->authorize($actor, $context);
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,120}\z/D', $key)) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_UPLOAD_KEY_INVALID', 'Klucz ponowienia uploadu jest nieprawidłowy.', 422);
        }
        $payload = $this->documents->inspectUpload($filename, $contents);

        $upload = DB::transaction(function () use ($actor, $context, $key, $payload): object {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [
                'raw-material-upload', $actor->id.':'.$context->id.':'.$key,
            ]);
            $this->authorize($actor, $context);
            $existing = $this->scoped($actor, $context)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->sha256 !== $payload['sha256'] || $existing->original_filename !== $payload['original_filename']) {
                    throw new RawMaterialDocumentException('RAW_MATERIAL_UPLOAD_KEY_CONFLICT', 'Klucz uploadu został użyty dla innego pliku.', 409);
                }
                $this->requireStaged($existing);

                return $existing;
            }
            $id = Str::uuid()->toString();
            $storageKey = 'raw-material-uploads/'.substr($id, 0, 2).'/'.$id;
            $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn () => DB::table('raw_material_document_uploads')->insert([
                'id' => $id,
                'organization_id' => $actor->organization_id,
                'location_id' => $context->id,
                'actor_user_id' => $actor->id,
                'idempotency_key' => $key,
                ...$payload,
                'storage_key' => $storageKey,
                'scan_state' => 'ERROR',
                'expires_at' => now()->addHour(),
            ]));

            return $this->scoped($actor, $context)->where('id', $id)->first();
        });
        if ($upload->scan_state === 'CLEAN') {
            return $this->present($upload);
        }

        return DB::transaction(function () use ($actor, $context, $upload, $contents): array {
            $locked = $this->scoped($actor, $context)->where('id', $upload->id)->lockForUpdate()->first();
            $this->requireStaged($locked);
            $this->authorize($actor, $context);
            if ($locked->scan_state !== 'CLEAN') {
                $this->storage->put($locked->storage_key, $contents);
                $this->verify($this->storage->read($locked->storage_key), $locked);
                $scanState = $this->scan($contents);
                $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn () => DB::table('raw_material_document_uploads')
                    ->where('id', $locked->id)->update(['scan_state' => $scanState]));
                $locked->scan_state = $scanState;
            }

            return $this->present($locked);
        });
    }

    public function retry(User $actor, Location $context, string $id): array
    {
        $this->authorize($actor, $context);

        return DB::transaction(function () use ($actor, $context, $id): array {
            $upload = $this->scoped($actor, $context)->where('id', $id)->lockForUpdate()->first();
            $this->requireStaged($upload);
            $this->authorize($actor, $context);
            $contents = $this->storage->read($upload->storage_key);
            $this->verify($contents, $upload);
            $scanState = $this->scan($contents);
            $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), $actor->id, fn () => DB::table('raw_material_document_uploads')
                ->where('id', $id)->update(['scan_state' => $scanState]));
            $upload->scan_state = $scanState;

            return $this->present($upload);
        });
    }

    public function claim(User $actor, Location $context, string $id, string $revisionId): string
    {
        $upload = $this->scoped($actor, $context)->where('id', $id)->lockForUpdate()->first();
        $this->requireStaged($upload);
        if ($upload->scan_state !== 'CLEAN') {
            throw new RawMaterialDocumentException('RAW_MATERIAL_UPLOAD_NOT_CLEAN', 'Plik nie ma prawidłowego wyniku skanowania.', 409);
        }
        $this->verify($this->storage->read($upload->storage_key), $upload);
        $versionId = $this->documents->promoteUpload($actor, $revisionId, $upload);
        DB::table('raw_material_document_uploads')->where('id', $id)->update([
            'state' => 'CLAIMED', 'claimed_version_id' => $versionId,
        ]);

        return $versionId;
    }

    public function cleanupExpired(): int
    {
        $count = 0;
        do {
            $cleaned = $this->contexts->run(AuthorizationMutationScope::rawMaterialMutate(), null, function (): bool {
                $upload = DB::table('raw_material_document_uploads')->where('state', 'STAGED')
                    ->where('expires_at', '<=', now())->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $upload) {
                    return false;
                }
                if (DB::table('raw_material_document_versions')->where('storage_key', $upload->storage_key)->exists()) {
                    throw new RawMaterialDocumentException('RAW_MATERIAL_UPLOAD_CLEANUP_PROTECTED', 'Sprzątanie wykryło chroniony dokument; pliku nie usunięto.', 409);
                }
                $this->storage->delete($upload->storage_key);
                DB::table('raw_material_document_uploads')->where('id', $upload->id)->update(['state' => 'EXPIRED']);

                return true;
            });
            $count += (int) $cleaned;
        } while ($cleaned);

        return $count;
    }

    private function scoped(User $actor, Location $context): Builder
    {
        return DB::table('raw_material_document_uploads')->where('organization_id', $actor->organization_id)
            ->where('location_id', $context->id)->where('actor_user_id', $actor->id);
    }

    private function requireStaged(?object $upload): void
    {
        if (! $upload || $upload->state !== 'STAGED' || Carbon::parse($upload->expires_at)->lte(now())) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_UPLOAD_UNAVAILABLE', 'Nie znaleziono dostępnego uploadu w bieżącym kontekście.', 404);
        }
    }

    private function scan(string $contents): string
    {
        try {
            return $this->scanner->scan($contents)->status->value;
        } catch (DocumentScannerException $exception) {
            Log::warning('Skanowanie uploadu RAW nie powiodło się; plik pozostaje niedostępny.', ['error_code' => $exception->errorCode]);

            return DocumentScanStatus::Error->value;
        }
    }

    private function verify(string $contents, object $upload): void
    {
        if (strlen($contents) !== (int) $upload->size_bytes || ! hash_equals($upload->sha256, hash('sha256', $contents))) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_DOCUMENT_CHECKSUM_MISMATCH', 'Kontrola integralności pliku nie powiodła się.', 409);
        }
    }

    private function present(object $upload): array
    {
        return [
            'upload_id' => $upload->id,
            'filename' => $upload->original_filename,
            'media_type' => $upload->media_type,
            'size_bytes' => (int) $upload->size_bytes,
            'status' => $upload->scan_state === 'CLEAN' ? 'STAGED' : 'FAILED',
            'error_code' => $upload->scan_state === 'CLEAN' ? null : 'RAW_MATERIAL_UPLOAD_NOT_CLEAN',
            'message' => $upload->scan_state === 'CLEAN' ? 'Plik oczekuje na zapis surowca.' : 'Plik jest zablokowany po skanowaniu. Można ponowić próbę.',
            'expires_at' => $upload->expires_at,
        ];
    }
}
