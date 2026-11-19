<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\AdministrationUserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdministrationUserListRequest;
use App\Http\Requests\AdministrationUserProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdministrationUserController extends Controller
{
    public function index(AdministrationUserListRequest $request, AdministrationUserService $service): JsonResponse
    {
        return response()->json($service->list(
            $request->user(),
            $request->attributes->get('operating_location'),
            $request->validated(),
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function locations(Request $request, AdministrationUserService $service): JsonResponse
    {
        return response()->json(['data' => $service->locations(
            $request->user(),
            $request->attributes->get('operating_location'),
        )], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, string $user, AdministrationUserService $service): JsonResponse
    {
        return response()->json($service->show(
            $request->user(),
            $request->attributes->get('operating_location'),
            $user,
        ), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function updateProfile(
        AdministrationUserProfileRequest $request,
        string $user,
        AdministrationUserService $service,
    ): JsonResponse {
        $service->updateProfile(
            $request->user(),
            $request->attributes->get('operating_location'),
            $user,
            $request->validated(),
        );

        return response()->json(['data' => null, 'message' => __('messages.account_profile_updated')], 200, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
