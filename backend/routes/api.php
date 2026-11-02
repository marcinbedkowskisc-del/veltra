<?php

use App\Http\Controllers\Api\V1\AuthenticationController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\ManagementController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\SupplyController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\LocalManagementAccess;
use App\Http\Middleware\RequireSpaSession;
use App\Http\Middleware\ValidateIdentityContext;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1/auth')->middleware(RequireSpaSession::class)->group(function (): void {
    Route::post('/login', [AuthenticationController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [AuthenticationController::class, 'logout'])->middleware('auth:sanctum');
    Route::middleware(['auth:sanctum', ValidateIdentityContext::class])->group(function (): void {
        Route::get('/me', [AuthenticationController::class, 'me']);
        Route::get('/locations', [AuthenticationController::class, 'locations']);
        Route::get('/operating-location', [AuthenticationController::class, 'currentLocation']);
        Route::put('/operating-location', [AuthenticationController::class, 'selectLocation']);
    });
});

Route::prefix('v1')->middleware(LocalManagementAccess::class)->group(function (): void {
    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::post('/organization', [OrganizationController::class, 'store']);
    Route::get('/locations', [LocationController::class, 'index']);
    Route::post('/locations', [LocationController::class, 'store']);
    Route::get('/locations/{location}', [LocationController::class, 'show'])->whereUuid('location');
    Route::patch('/locations/{location}', [LocationController::class, 'update'])->whereUuid('location');
    Route::get('/locations/{location}/management-assignments', [ManagementController::class, 'history'])->whereUuid('location');
    Route::get('/locations/{location}/managing-kitchen', [ManagementController::class, 'current'])->whereUuid('location');
    Route::post('/locations/{location}/managing-kitchen', [ManagementController::class, 'store'])->whereUuid('location');
    Route::put('/locations/{location}/managing-kitchen', [ManagementController::class, 'update'])->whereUuid('location');
    Route::get('/locations/{location}/managed-restaurants', [ManagementController::class, 'managedRestaurants'])->whereUuid('location');
    Route::get('/locations/{location}/supply-relationships', [SupplyController::class, 'index'])->whereUuid('location');
    Route::post('/locations/{location}/supply-relationships', [SupplyController::class, 'store'])->whereUuid('location');
    Route::get('/locations/{location}/suppliers', [SupplyController::class, 'suppliers'])->whereUuid('location');
    Route::delete('/supply-relationships/{supplyRelationship}', [SupplyController::class, 'destroy'])->whereUuid('supplyRelationship');
});
