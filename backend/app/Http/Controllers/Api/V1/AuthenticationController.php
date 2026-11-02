<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\MembershipService;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\SelectOperatingLocationRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticationController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = [...$request->validated(), 'is_active' => true];
        if (! Auth::guard('web')->attempt($credentials)
            || ! Auth::guard('web')->user()->organization->is_active
            || Auth::guard('web')->user()->email_verified_at === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Invalid email or password.'], 401);
        }
        $request->session()->regenerate();
        $request->session()->forget('operating_membership_id');
        $request->session()->put('identity_version', Auth::guard('web')->user()->auth_version);

        return response()->json(['data' => new UserResource(Auth::guard('web')->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => null, 'message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    public function locations(Request $request, MembershipService $service): JsonResponse
    {
        $locations = $service->available($request->user())->with('location')->get()
            ->map(fn ($membership) => $membership->location)->sortBy('code')->values();

        return response()->json(['data' => $locations]);
    }

    public function selectLocation(SelectOperatingLocationRequest $request, MembershipService $service): JsonResponse
    {
        $membership = $service->available($request->user())
            ->where('location_id', $request->validated('location_id'))->with('location')->first();
        abort_unless($membership, 403, 'This operating location is not available.');
        // Store the assignment identity, not just the location: regranting never revives a revoked context.
        $request->session()->put('operating_membership_id', $membership->id);

        return response()->json(['data' => $membership->location]);
    }

    public function currentLocation(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->attributes->get('operating_location')]);
    }
}
