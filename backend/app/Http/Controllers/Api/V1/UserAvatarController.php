<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\UserAvatarService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserAvatarPhotoUploadRequest;
use App\Http\Requests\UserAvatarSelectionRequest;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UserAvatarController extends Controller
{
    public function selectSelf(UserAvatarSelectionRequest $request, UserAvatarService $service): JsonResponse
    {
        $validated = $request->validated();
        $service->selectSelf($request->user(), $validated['type'] === 'LIBRARY' ? $validated['library_id'] : null);

        return $this->saved();
    }

    public function uploadSelf(UserAvatarPhotoUploadRequest $request, UserAvatarService $service): JsonResponse
    {
        $service->uploadSelf($request->user(), $this->uploadedPhoto($request));

        return $this->saved();
    }

    public function resetSelf(Request $request, UserAvatarService $service): JsonResponse
    {
        $service->selectSelf($request->user(), null);

        return $this->saved();
    }

    public function selfPhoto(Request $request, UserAvatarService $service): StreamedResponse
    {
        return $service->selfPhoto($request->user());
    }

    public function selectForAdministrator(
        UserAvatarSelectionRequest $request,
        string $user,
        UserAvatarService $service,
    ): JsonResponse {
        $validated = $request->validated();
        $service->selectForAdministrator(
            $request->user(),
            $this->operatingLocation($request),
            $user,
            $validated['type'] === 'LIBRARY' ? $validated['library_id'] : null,
        );

        return $this->saved();
    }

    public function uploadForAdministrator(
        UserAvatarPhotoUploadRequest $request,
        string $user,
        UserAvatarService $service,
    ): JsonResponse {
        $service->uploadForAdministrator(
            $request->user(),
            $this->operatingLocation($request),
            $user,
            $this->uploadedPhoto($request),
        );

        return $this->saved();
    }

    public function resetForAdministrator(Request $request, string $user, UserAvatarService $service): JsonResponse
    {
        $service->selectForAdministrator($request->user(), $this->operatingLocation($request), $user, null);

        return $this->saved();
    }

    public function administratorPhoto(Request $request, string $user, UserAvatarService $service): StreamedResponse
    {
        return $service->administratorPhoto($request->user(), $this->operatingLocation($request), $user);
    }

    private function operatingLocation(Request $request): Location
    {
        $location = $request->attributes->get('operating_location');
        abort_unless($location instanceof Location, 403);

        return $location;
    }

    private function uploadedPhoto(Request $request): UploadedFile
    {
        $photo = $request->file('photo');
        abort_unless($photo instanceof UploadedFile, 422);

        return $photo;
    }

    private function saved(): JsonResponse
    {
        return response()->json(['data' => null], 200, ['Cache-Control' => 'private, no-store']);
    }
}
