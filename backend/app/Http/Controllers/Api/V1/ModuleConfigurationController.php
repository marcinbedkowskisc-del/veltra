<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Modules\ModuleConfigurationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModuleConfigurationRequest;
use App\Http\Requests\ModuleHistoryRequest;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ModuleConfigurationController extends Controller
{
    public function catalog(ModuleConfigurationService $service): JsonResponse
    {
        return response()->json(['data' => $service->catalog()]);
    }

    public function states(Request $request, Location $location, ModuleConfigurationService $service): JsonResponse
    {
        return response()->json(['data' => $service->states(
            $request->user(),
            $request->attributes->get('operating_location'),
            $location,
        )]);
    }

    public function configure(ModuleConfigurationRequest $request, ModuleConfigurationService $service): JsonResponse
    {
        return response()->json(['data' => $service->configure(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        )]);
    }

    public function history(
        ModuleHistoryRequest $request,
        Location $location,
        ModuleConfigurationService $service,
    ): JsonResponse {
        return response()->json($service->history(
            $request->user(),
            $request->attributes->get('operating_location'),
            $location,
            $request->integer('per_page', 25),
        ));
    }
}
