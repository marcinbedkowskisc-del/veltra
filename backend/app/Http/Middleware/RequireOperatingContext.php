<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOperatingContext
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->attributes->get('operating_location'), 403, 'Select an active operating location first.');

        return $next($request);
    }
}
