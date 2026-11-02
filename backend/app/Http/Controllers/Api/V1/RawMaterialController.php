<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRawMaterialsRequest;
use App\Http\Requests\RawMaterialApprovalRequest;
use App\Http\Requests\RawMaterialCategoryRenameRequest;
use App\Http\Requests\RawMaterialCategoryRequest;
use App\Http\Requests\RawMaterialDataRequest;
use App\Http\Requests\RawMaterialGrantRequest;
use App\Http\Requests\RawMaterialReasonRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RawMaterialController extends Controller
{
    public function index(ListRawMaterialsRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json($service->list(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        ));
    }

    public function show(Request $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->show(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
        )]);
    }

    public function revisions(Request $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->revisions(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
        )]);
    }

    public function store(RawMaterialDataRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->create(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        )], 201);
    }

    public function updateDraft(RawMaterialDataRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->revise(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated(),
        )]);
    }

    public function submit(Request $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->submit(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
        )]);
    }

    public function approve(RawMaterialApprovalRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->approve(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason') ?? '',
        )]);
    }

    public function returnForChanges(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->returnForChanges(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
        )]);
    }

    public function block(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->block(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
        )]);
    }

    public function unblock(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->unblock(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
        )]);
    }

    public function archive(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->archive(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
        )]);
    }

    public function restore(RawMaterialReasonRequest $request, string $material, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->restore(
            $request->user(),
            $request->attributes->get('operating_location'),
            $material,
            $request->validated('reason'),
        )]);
    }

    public function categories(Request $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->categories(
            $request->user(),
            $request->attributes->get('operating_location'),
        )]);
    }

    public function storeCategory(RawMaterialCategoryRequest $request, RawMaterialService $service): JsonResponse
    {
        return response()->json(['data' => $service->createCategory(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        )], 201);
    }

    public function renameCategory(
        RawMaterialCategoryRenameRequest $request,
        string $category,
        RawMaterialService $service,
    ): JsonResponse {
        return response()->json(['data' => $service->renameCategory(
            $request->user(),
            $request->attributes->get('operating_location'),
            $category,
            $request->validated('name'),
        )]);
    }

    public function grantPermission(
        RawMaterialGrantRequest $request,
        User $user,
        OrganizationRawMaterialPermissionAdministration $service,
    ): JsonResponse {
        return response()->json(['data' => $service->grant(
            $request->user(),
            $request->attributes->get('operating_location'),
            $user,
            RawMaterialPermission::from($request->validated('permission')),
            $request->validated('reason'),
            $request->validated('valid_from'),
            $request->validated('valid_until'),
        )], 201);
    }

    public function revokePermission(
        RawMaterialReasonRequest $request,
        string $grant,
        OrganizationRawMaterialPermissionAdministration $service,
    ): JsonResponse {
        $service->revoke(
            $request->user(),
            $request->attributes->get('operating_location'),
            $grant,
            $request->validated('reason'),
        );

        return response()->json(['data' => null]);
    }
}
