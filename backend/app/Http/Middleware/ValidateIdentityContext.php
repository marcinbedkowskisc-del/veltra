<?php

namespace App\Http\Middleware;

use App\Domain\Identity\MembershipService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ValidateIdentityContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::with('organization')->find($request->user()->id);
        if (! $user || ! $user->is_active || ! $user->organization->is_active || $user->email_verified_at === null
            || $request->session()->get('identity_version') !== $user->auth_version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(401, __('messages.unauthenticated'));
        }
        Auth::guard('web')->setUser($user);
        $request->setUserResolver(fn () => $user);
        $membershipId = $request->session()->get('operating_membership_id');
        if ($membershipId !== null) {
            $membership = app(MembershipService::class)->available($user)->with('location')->find($membershipId);
            if (! $membership) {
                $request->session()->forget('operating_membership_id');
                abort(403, __('messages.operating_location_unavailable'));
            }
            $request->attributes->set('operating_location', $membership->location);
        }

        return $next($request);
    }
}
