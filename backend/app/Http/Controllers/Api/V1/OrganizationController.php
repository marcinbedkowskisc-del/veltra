<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Installation\InstallationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitializeOrganizationRequest;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => Organization::firstOrFail()]);
    }

    public function store(InitializeOrganizationRequest $request, InstallationService $service): JsonResponse
    {
        return response()->json(['data' => $service->initializeOrganization($request->validated())], 201);
    }
}
