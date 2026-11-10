<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RawMaterialCapabilitiesController
{
    public function __invoke(
        Request $request,
        RawMaterialAuthorization $authorization,
        ModuleCatalog $modules,
        ModuleAvailability $availability,
    ): JsonResponse {
        $actor = $request->user();
        $location = $request->attributes->get('operating_location');
        if (! $location instanceof Location) {
            throw new HttpException(403, __('messages.operating_location_required'));
        }

        $createCapability = $authorization->evaluateCreateCapability($actor, $location);
        $editCapability = $authorization->evaluateEditCapability($actor, $location);
        $definition = $modules->find('RAW_MATERIALS');
        $moduleAvailable = $definition !== null && $availability->enabled($location, 'RAW_MATERIALS');

        return response()->json([
            'data' => [
                'module' => 'RAW_MATERIALS',
                'location_id' => $location->id,
                'readiness' => $definition?->ready ? 'READY' : 'NOT_READY',
                'available' => $moduleAvailable,
                'permissions' => [
                    'create' => $createCapability['permission'],
                    'edit' => $editCapability['permission'],
                ],
                'actions' => [
                    'create' => $createCapability['permission'] && $createCapability['context'] && $moduleAvailable,
                    'edit' => $editCapability['permission'] && $editCapability['context'] && $moduleAvailable,
                ],
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
