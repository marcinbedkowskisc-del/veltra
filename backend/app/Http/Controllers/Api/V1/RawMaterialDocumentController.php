<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RawMaterials\Documents\DocumentStorageException;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentException;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentSetService;
use App\Domain\RawMaterials\Documents\RawMaterialUploadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class RawMaterialDocumentController extends Controller
{
    public function upload(Request $request, RawMaterialUploadService $uploads): JsonResponse
    {
        $actor = $request->user();
        $context = $request->attributes->get('operating_location');
        $uploads->authorize($actor, $context);
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['required', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,120}\z/D', 'distinct'],
        ]);
        $files = $request->file('files', []);
        $keys = $request->input('keys');
        if (array_diff_key($files, $keys) !== []) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_UPLOAD_BATCH_INVALID', 'Każdy plik wymaga osobnego klucza ponowienia.', 422);
        }
        $size = array_sum(array_map(fn ($file): int => $file instanceof UploadedFile && $file->isValid() ? $file->getSize() : 0, $files));
        if ($size > RawMaterialUploadService::MAX_BYTES) {
            throw new RawMaterialDocumentException('RAW_MATERIAL_UPLOAD_REQUEST_TOO_LARGE', 'Łączny rozmiar plików w żądaniu przekracza 20 MiB. Wyślij je w osobnych żądaniach.', 413);
        }
        $results = [];
        foreach ($keys as $index => $key) {
            $file = $files[$index] ?? null;
            try {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    throw new RawMaterialDocumentException('RAW_MATERIAL_UPLOAD_FILE_INVALID', 'Nie udało się odebrać pliku. Ponów ten upload.', 422);
                }
                $results[] = ['key' => $key] + $uploads->stage($actor, $context, $key, $file->getClientOriginalName(), $file->getContent());
            } catch (RawMaterialDocumentException|DocumentStorageException $exception) {
                $results[] = ['key' => $key, 'status' => 'FAILED', 'error_code' => $exception->errorCode, 'message' => $exception->getMessage()];
            }
        }

        return response()->json(['data' => $results], 200, ['Cache-Control' => 'no-store']);
    }

    public function retry(Request $request, string $upload, RawMaterialUploadService $uploads): JsonResponse
    {
        return response()->json(['data' => $uploads->retry($request->user(), $request->attributes->get('operating_location'), $upload)], headers: ['Cache-Control' => 'no-store']);
    }

    public function index(Request $request, string $material, RawMaterialDocumentSetService $documents): JsonResponse
    {
        return response()->json(['data' => $documents->listing($request->user(), $request->attributes->get('operating_location'), $material, $this->editing($request))], headers: ['Cache-Control' => 'no-store']);
    }

    public function download(Request $request, string $material, string $version, RawMaterialDocumentSetService $documents): Response
    {
        return $this->file($request, $material, $version, $documents, false);
    }

    public function preview(Request $request, string $material, string $version, RawMaterialDocumentSetService $documents): Response
    {
        return $this->file($request, $material, $version, $documents, true);
    }

    private function editing(Request $request): bool
    {
        $validated = $request->validate(['view' => ['sometimes', 'in:current,editor']]);

        return ($validated['view'] ?? 'current') === 'editor';
    }

    private function file(Request $request, string $material, string $version, RawMaterialDocumentSetService $documents, bool $preview): Response
    {
        $file = $documents->read($request->user(), $request->attributes->get('operating_location'), $material, $version, $preview, $this->editing($request));

        return response($file['contents'], 200, [
            'Content-Type' => $file['media_type'],
            'Content-Disposition' => HeaderUtils::makeDisposition($preview ? 'inline' : 'attachment', $file['filename'], preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($file['filename']))),
            'Content-Length' => (string) strlen($file['contents']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'self'",
        ]);
    }
}
