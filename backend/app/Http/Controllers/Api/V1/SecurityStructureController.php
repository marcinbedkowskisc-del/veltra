<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Authorization\SecurityMutation;
use App\Domain\Installation\InstallationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLocationRequest;
use App\Http\Requests\ManagingKitchenRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityStructureController extends Controller
{
    private function mutate(Request $request, string $action, \Closure $operation): mixed
    {
        return app(SecurityMutation::class)->run($request->user(), $request->user(), $request->attributes->get('operating_location'), $action, function () use ($request, $operation): mixed {
            $authorization = app(BusinessAuthorization::class);
            if (! $authorization->eligible($request->user(), $request->attributes->get('operating_location'))
                || ! $authorization->hasRole($request->user(), BusinessRole::Administrator)) {
                throw new AuthorizationException;
            }

            return $operation();
        });
    }

    public function store(CreateLocationRequest $request, InstallationService $service): JsonResponse
    {
        $location = $this->mutate($request, 'structure.create', fn () => $service->createLocation($request->validated()));

        return response()->json(['data' => $location], 201);
    }

    public function update(UpdateLocationRequest $request, Location $location, InstallationService $service): JsonResponse
    {
        $location = $this->mutate($request, 'structure.update', function () use ($request, $service, $location): Location {
            if ($request->validated('is_active') === false) {
                app(RoleAdministration::class)->requireUsableAdministrator(disabledLocation: $location->id);
            }

            return $service->updateLocation($location, $request->validated());
        });

        return response()->json(['data' => $location]);
    }

    public function managingKitchen(ManagingKitchenRequest $request, Location $location, InstallationService $service): JsonResponse
    {
        $assignment = $this->mutate($request, 'structure.management', fn () => $service->assignManagingKitchen($location,
            Location::findOrFail($request->validated('managing_kitchen_id')), replace: $request->isMethod('PUT')));

        return response()->json(['data' => $assignment]);
    }
}
