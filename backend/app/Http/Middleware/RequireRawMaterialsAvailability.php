<?php

namespace App\Http\Middleware;

use App\Domain\Authorization\ModuleAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRawMaterialsAvailability
{
    public function handle(Request $request, Closure $next): Response
    {
        $location = $request->attributes->get('operating_location');
        if (! $location || ! app(ModuleAvailability::class)->enabled($location, 'RAW_MATERIALS')) {
            return response()->json([
                'error' => [
                    'code' => 'RAW_MATERIALS_MODULE_UNAVAILABLE',
                    'message' => __('messages.raw_materials_module_unavailable'),
                ],
            ], 503);
        }

        return $next($request);
    }
}
