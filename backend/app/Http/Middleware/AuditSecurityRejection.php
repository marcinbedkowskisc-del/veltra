<?php

namespace App\Http\Middleware;

use App\Domain\Authorization\SecurityMutation;
use App\Domain\Installation\BusinessRuleViolation;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuditSecurityRejection
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (AuthorizationException|ValidationException|BusinessRuleViolation|QueryException|HttpExceptionInterface $exception) {
            $this->record($request);
            throw $exception;
        }
        if ($response->getStatusCode() >= 400) {
            $this->record($request);
        }

        return $response;
    }

    private function record(Request $request): void
    {
        if (! $request->isMethod('GET')) {
            app(SecurityMutation::class)->record($request->user(), $request->user(), $request->attributes->get('operating_location'), 'security.request', 'rejected');
        }
    }
}
