<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Modules\OrganizationCapabilityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationCapabilityActivationRequest;
use App\Http\Requests\OrganizationCapabilityHistoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class OrganizationCapabilityController extends Controller
{
    public function show(OrganizationCapabilityService $service): JsonResponse
    {
        return response()->json(['data' => $service->status(
            request()->user(),
            request()->attributes->get('operating_location'),
            'BOSS_RAW_CATALOG_READ',
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function configure(
        OrganizationCapabilityActivationRequest $request,
        OrganizationCapabilityService $service,
    ): JsonResponse {
        $input = $request->validated();

        return response()->json(['data' => $service->configure(
            $request->user(),
            $request->attributes->get('operating_location'),
            'BOSS_RAW_CATALOG_READ',
            Str::uuid()->toString(),
            $input,
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function history(
        OrganizationCapabilityHistoryRequest $request,
        OrganizationCapabilityService $service,
    ): JsonResponse {
        return response()->json($service->history(
            $request->user(),
            $request->attributes->get('operating_location'),
            'BOSS_RAW_CATALOG_READ',
            $request->integer('per_page', 25),
            $request->integer('page', 1),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }
}
