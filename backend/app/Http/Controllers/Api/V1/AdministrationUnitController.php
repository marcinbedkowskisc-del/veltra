<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RawMaterials\RawMaterialService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdministrationUnitCreateRequest;
use App\Http\Requests\AdministrationUnitListRequest;
use App\Http\Requests\AdministrationUnitUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdministrationUnitController extends Controller
{
    public function index(AdministrationUnitListRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json($service->administrationUnits(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(AdministrationUnitCreateRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->createAdministrationUnit(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        )], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function update(
        AdministrationUnitUpdateRequest $request,
        string $unit,
        RawMaterialService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->updateAdministrationUnit(
            $request->user(),
            $request->attributes->get('operating_location'),
            $unit,
            $request->validated(),
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function archive(Request $request, string $unit, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->archiveAdministrationUnit(
            $request->user(),
            $request->attributes->get('operating_location'),
            $unit,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function restore(Request $request, string $unit, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->restoreAdministrationUnit(
            $request->user(),
            $request->attributes->get('operating_location'),
            $unit,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }
}
