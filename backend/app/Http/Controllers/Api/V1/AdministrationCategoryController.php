<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RawMaterials\RawMaterialService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdministrationCategoryCreateRequest;
use App\Http\Requests\AdministrationCategoryListRequest;
use App\Http\Requests\AdministrationCategoryUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdministrationCategoryController extends Controller
{
    public function index(AdministrationCategoryListRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json($service->administrationCategories(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(AdministrationCategoryCreateRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->createAdministrationCategory(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        )], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function update(
        AdministrationCategoryUpdateRequest $request,
        string $category,
        RawMaterialService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->updateAdministrationCategory(
            $request->user(),
            $request->attributes->get('operating_location'),
            $category,
            $request->validated(),
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function archive(Request $request, string $category, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->archiveAdministrationCategory(
            $request->user(),
            $request->attributes->get('operating_location'),
            $category,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function restore(Request $request, string $category, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->restoreAdministrationCategory(
            $request->user(),
            $request->attributes->get('operating_location'),
            $category,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }
}
