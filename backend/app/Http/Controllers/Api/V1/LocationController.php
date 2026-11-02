<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Installation\InstallationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLocationRequest;
use App\Http\Requests\ListLocationsRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationController extends Controller
{
    public function index(ListLocationsRequest $request): AnonymousResourceCollection
    {
        $input = $request->validated();
        $locations = Location::query()
            ->when(isset($input['type']), fn (Builder $query) => $query->where('type', $input['type']))
            ->when(isset($input['is_active']), fn (Builder $query) => $query->where('is_active', $input['is_active']))
            ->orderBy('code')->paginate($input['per_page'] ?? 20);

        return JsonResource::collection($locations);
    }

    public function store(CreateLocationRequest $request, InstallationService $service): JsonResponse
    {
        return response()->json(['data' => $service->createLocation($request->validated())], 201);
    }

    public function show(Location $location): JsonResponse
    {
        return response()->json(['data' => $location]);
    }

    public function update(UpdateLocationRequest $request, Location $location, InstallationService $service): JsonResponse
    {
        return response()->json(['data' => $service->updateLocation($location, $request->validated())]);
    }
}
