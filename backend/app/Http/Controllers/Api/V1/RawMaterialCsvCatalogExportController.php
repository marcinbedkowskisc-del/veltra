<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RawMaterials\RawMaterialCsvCatalogExportWorkflow;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RawMaterialCsvCatalogExportController extends Controller
{
    public function create(
        Request $request,
        RawMaterialCsvCatalogExportWorkflow $workflow,
    ): JsonResponse {
        return response()->json(['data' => $workflow->create(
            $request->user(),
            $request->attributes->get('operating_location'),
        )], 201);
    }

    public function download(
        Request $request,
        string $export,
        RawMaterialCsvCatalogExportWorkflow $workflow,
    ): StreamedResponse {
        $download = $workflow->authorizeDownload(
            $request->user(),
            $request->attributes->get('operating_location'),
            $export,
        );

        return Storage::disk('raw_material_csv_exports')->download(
            $download['storage_key'],
            $download['filename'],
            [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/csv; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
