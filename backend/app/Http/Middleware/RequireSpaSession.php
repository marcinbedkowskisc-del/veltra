<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSpaSession
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->hasSession() && $request->attributes->get('sanctum'), 401, 'A first-party session is required.');

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
