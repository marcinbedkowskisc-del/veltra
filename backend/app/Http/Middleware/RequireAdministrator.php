<?php

namespace App\Http\Middleware;

use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdministrator
{
    public function __construct(private BusinessAuthorization $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $context = $request->attributes->get('operating_location');

        abort_unless(
            $actor && $context
                && $this->authorization->eligible($actor, $context)
                && $this->authorization->hasRole($actor, BusinessRole::Administrator),
            403,
            __('messages.unauthorized'),
        );

        return $next($request);
    }
}
