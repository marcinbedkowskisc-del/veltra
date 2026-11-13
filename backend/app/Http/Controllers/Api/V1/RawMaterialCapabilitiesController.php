<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
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
        $allergenManage = $authorization->hasEffectiveGrant($actor, RawMaterialPermission::AllergenManage);
        $createApproved = app(BusinessAuthorization::class)->hasRole($actor, BusinessRole::Administrator)
            && $createCapability['permission'] && $createCapability['context'] && $moduleAvailable
            && $allergenManage
            && $authorization->hasEffectiveGrant($actor, RawMaterialPermission::Submit)
            && $authorization->hasEffectiveGrant($actor, RawMaterialPermission::Approve);

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
                    'edit_allergens' => $allergenManage && $editCapability['context'] && $moduleAvailable,
                    'create_and_approve' => $createApproved,
                ],
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
