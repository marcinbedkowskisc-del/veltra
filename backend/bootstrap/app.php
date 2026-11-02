<?php

use App\Domain\Installation\BusinessRuleViolation;
use App\Http\Middleware\LocalManagementAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(null);
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            LocalManagementAccess::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'current_password', 'password', 'password_confirmation']);
        $exceptions->dontReport([BusinessRuleViolation::class]);
        $exceptions->report(function (QueryException $exception) {
            if (preg_match('/\b(users|location_memberships|activation_tokens|password_reset_tokens|role_assignments|permission_grants|security_events|account_email_deliveries|membership_requests|report_shares)\b/i', $exception->getSql())) {
                // QueryException messages include SQL bindings, which can contain password hashes.
                Log::error('Identity database operation failed.', ['sqlstate' => $exception->errorInfo[0] ?? null]);

                return false;
            }
        });
        $exceptions->render(function (BusinessRuleViolation $exception, Request $request) {
            if ($request->is('api/v1/*')) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }
        });
        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->is('api/v1/auth/*', 'api/v1/security/*', 'api/v1/account/*')) {
                return response()->json(['message' => 'The identity operation could not be completed.'], 500);
            }
            if ($request->is('api/v1/*') && in_array($exception->errorInfo[0] ?? null, ['23503', '23505', '23514', '23P01'], true)) {
                return response()->json(['message' => 'The operation conflicts with location integrity rules. Refresh the data and verify the relationship.'], 409);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
