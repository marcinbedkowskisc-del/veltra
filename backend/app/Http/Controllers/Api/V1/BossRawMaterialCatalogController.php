<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RawMaterials\BossRawMaterialCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListBossRawMaterialsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BossRawMaterialCatalogController extends Controller
{
    public function index(
        ListBossRawMaterialsRequest $request,
        BossRawMaterialCatalogService $catalog,
    ): JsonResponse {
        return response()->json($catalog->listing(
            $request->user(),
            $request->attributes->get('operating_location'),
            $this->sessionString($request, 'operating_membership_id'),
            $this->sessionInteger($request, 'identity_version'),
            $request->validated(),
        ));
    }

    public function show(
        Request $request,
        string $material,
        BossRawMaterialCatalogService $catalog,
    ): JsonResponse {
        return response()->json([
            'data' => $catalog->detail(
                $request->user(),
                $request->attributes->get('operating_location'),
                $this->sessionString($request, 'operating_membership_id'),
                $this->sessionInteger($request, 'identity_version'),
                $material,
            ),
        ]);
    }

    private function sessionString(Request $request, string $key): ?string
    {
        $value = $request->session()->get($key);

        return is_string($value) ? $value : null;
    }

    private function sessionInteger(Request $request, string $key): ?int
    {
        $value = $request->session()->get($key);

        return is_int($value) ? $value : null;
    }
}
