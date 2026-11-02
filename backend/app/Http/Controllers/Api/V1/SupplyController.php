<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Installation\InstallationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\SupplyRequest;
use App\Models\Location;
use App\Models\LocationSupplyRelationship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplyController extends Controller
{
    public function index(PaginationRequest $request, Location $location, InstallationService $service): AnonymousResourceCollection
    {
        return JsonResource::collection($service->supplyRelationships($location, $request->integer('per_page', 20)));
    }

    public function suppliers(PaginationRequest $request, Location $location, InstallationService $service): AnonymousResourceCollection
    {
        return JsonResource::collection($service->authorizedSuppliers($location, $request->integer('per_page', 20)));
    }

    public function store(SupplyRequest $request, Location $location, InstallationService $service): JsonResponse
    {
        $kitchen = Location::findOrFail($request->validated('supplier_kitchen_id'));

        return response()->json(['data' => $service->establishSupply($location, $kitchen)], 201);
    }

    public function destroy(LocationSupplyRelationship $supplyRelationship, InstallationService $service): JsonResponse
    {
        return response()->json(['data' => $service->disableSupply($supplyRelationship)]);
    }
}
