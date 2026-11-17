<?php

use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\RawMaterials\Documents\DocumentStorageException;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentException;
use App\Domain\RawMaterials\RawMaterialException;
use App\Http\Middleware\LocalManagementAccess;
use App\Http\Middleware\PolishApiResponses;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->appendToGroup('api', PolishApiResponses::class);
        $middleware->redirectGuestsTo(null);
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            LocalManagementAccess::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->is('api/*')) {
                $response->headers->set('Content-Language', 'pl');
            }

            return $response;
        });
        $exceptions->dontFlash(['token', 'current_password', 'password', 'password_confirmation']);
        $exceptions->dontReport([BusinessRuleViolation::class]);
        $exceptions->report(function (QueryException $exception) {
            if (preg_match('/\b(users|location_memberships|activation_tokens|password_reset_tokens|role_assignments|permission_grants|security_events|account_email_deliveries|membership_requests|report_shares)\b/i', $exception->getSql())) {
                // QueryException messages include SQL bindings, which can contain password hashes.
                Log::error('Identity database operation failed.', ['sqlstate' => $exception->errorInfo[0] ?? null]);

                return false;
            }
            if (preg_match('/\b(raw_materials|raw_material_revisions|raw_material_revision_units|raw_material_aliases|raw_material_audit_events|raw_material_categories|raw_material_category_closure|raw_materials_currency_codes|organization_suppliers|supplier_products|supplier_product_revisions|supplier_offers|organization_exchange_rate_events|supplier_offer_events|supplier_offer_fx_normalization_events|raw_material_reference_price_events|raw_material_financial_restatements|raw_materials_commercial_idempotency|raw_materials_commercial_audit_events|raw_material_catalog_import_batches|raw_material_catalog_import_rows)\b/i', $exception->getSql())) {
                Log::error('Raw-material database operation failed.', ['sqlstate' => $exception->errorInfo[0] ?? null]);

                return false;
            }
        });
        $exceptions->render(function (BusinessRuleViolation $exception, Request $request) {
            if ($request->is('api/v1/*')) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }
        });
        $exceptions->render(function (RawMaterialException $exception, Request $request) {
            if ($request->is('api/v1/raw-materials/*') || $request->is('api/v1/raw-materials')
                || $request->is('api/v1/administration/categories/*') || $request->is('api/v1/administration/categories')) {
                return response()->json([
                    'error' => [
                        'code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                    ] + ($exception->details === [] ? [] : ['details' => $exception->details]),
                ], $exception->status);
            }

            return null;
        });
        $exceptions->render(function (RawMaterialDocumentException|DocumentStorageException $exception, Request $request) {
            if ($request->is('api/v1/raw-materials/*')) {
                return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]],
                    $exception instanceof RawMaterialDocumentException ? $exception->status : 503);
            }
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('messages.unauthenticated')], 401);
            }
        });
        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if ($request->is('api/*') && in_array($exception->getMessage(), ['', 'This action is unauthorized.'], true)) {
                return response()->json(['message' => __('messages.unauthorized')], 403);
            }
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => __('messages.validation_failed'),
                    'errors' => $exception->errors(),
                ], $exception->status);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = $exception->getMessage();
            $localizedMessage = match ($exception->getStatusCode()) {
                404 => $message === 'Not Found'
                    || str_starts_with($message, 'The route ')
                    || str_starts_with($message, 'No query results for model')
                    ? __('messages.resource_not_found')
                    : null,
                419 => $message === '' || in_array($message, ['Page Expired', 'CSRF token mismatch.'], true)
                    ? __('messages.session_expired')
                    : null,
                429 => $message === '' || $message === 'Too Many Attempts.'
                    ? __('messages.too_many_requests')
                    : null,
                default => null,
            };

            if ($localizedMessage !== null) {
                return response()->json(
                    ['message' => $localizedMessage],
                    $exception->getStatusCode(),
                    $exception->getHeaders(),
                );
            }

            return null;
        });
        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->is('api/v1/raw-materials/*') || $request->is('api/v1/raw-materials')) {
                return response()->json([
                    'error' => [
                        'code' => 'RAW_MATERIAL_INTEGRITY_CONFLICT',
                        'message' => __('messages.raw_material_integrity_conflict'),
                    ],
                ], in_array($exception->errorInfo[0] ?? null, ['23503', '23505', '23514', '23P01'], true) ? 409 : 500);
            }
            if ($request->is('api/v1/auth/*', 'api/v1/security/*', 'api/v1/account/*')) {
                return response()->json(['message' => __('messages.identity_operation_failed')], 500);
            }
            if ($request->is('api/v1/*') && in_array($exception->errorInfo[0] ?? null, ['23503', '23505', '23514', '23P01'], true)) {
                return response()->json(['message' => __('messages.location_integrity_conflict')], 409);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
