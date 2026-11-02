<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalManagementAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('development.management_token');
        if (! app()->environment('local')
            || config('development.management_enabled') !== true
            || ! is_string($token) || strlen($token) < 32) {
            abort(404, __('messages.development_api_unavailable'));
        }

        $provided = $request->header('X-Veltra-Development-Token');
        if (! is_string($provided) || ! hash_equals($token, $provided)) {
            abort(403, __('messages.development_token_required'));
        }

        return $next($request);
    }
}
