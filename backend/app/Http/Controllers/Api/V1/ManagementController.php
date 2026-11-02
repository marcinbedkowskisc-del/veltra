<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Installation\InstallationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManagingKitchenRequest;
use App\Http\Requests\PaginationRequest;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ManagementController extends Controller
{
    public function history(PaginationRequest $request, Location $location, InstallationService $service): AnonymousResourceCollection
    {
        return JsonResource::collection($service->managementHistory($location, $request->integer('per_page', 20)));
    }

    public function current(Location $location, InstallationService $service): JsonResponse
    {
        return response()->json(['data' => $service->currentManagingKitchen($location)]);
    }

    public function store(ManagingKitchenRequest $request, Location $location, InstallationService $service): JsonResponse
    {
        $kitchen = Location::findOrFail($request->validated('managing_kitchen_id'));

        return response()->json(['data' => $service->assignManagingKitchen($location, $kitchen)], 201);
    }

    public function update(ManagingKitchenRequest $request, Location $location, InstallationService $service): JsonResponse
    {
        $kitchen = Location::findOrFail($request->validated('managing_kitchen_id'));

        return response()->json(['data' => $service->assignManagingKitchen($location, $kitchen, replace: true)]);
    }

    public function managedRestaurants(PaginationRequest $request, Location $location, InstallationService $service): AnonymousResourceCollection
    {
        return JsonResource::collection($service->managedRestaurants($location, $request->integer('per_page', 20)));
    }
}
