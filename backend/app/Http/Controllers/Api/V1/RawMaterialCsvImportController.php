<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RawMaterials\RawMaterialCsvImportWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Requests\RawMaterialCsvCommitRequest;
use App\Http\Requests\RawMaterialCsvUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RawMaterialCsvImportController extends Controller
{
    public function upload(
        RawMaterialCsvUploadRequest $request,
        RawMaterialCsvImportWorkflow $workflow,
    ): JsonResponse {
        return response()->json(['data' => $workflow->upload(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->file('file'),
        )], 201);
    }

    public function commit(
        RawMaterialCsvCommitRequest $request,
        string $preview,
        RawMaterialCsvImportWorkflow $workflow,
    ): JsonResponse {
        return response()->json(['data' => $workflow->commit(
            $request->user(),
            $request->attributes->get('operating_location'),
            $preview,
            $request->idempotencyKey(),
            $request->confirmed(),
        )]);
    }

    public function result(
        Request $request,
        string $batch,
        RawMaterialCsvImportWorkflow $workflow,
    ): JsonResponse {
        return response()->json(['data' => $workflow->result(
            $request->user(),
            $request->attributes->get('operating_location'),
            $batch,
        )]);
    }
}
