<?php

use App\Http\Controllers\Api\V1\AccountLinkController;
use App\Http\Controllers\Api\V1\AuthenticationController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\ManagementController;
use App\Http\Controllers\Api\V1\ModuleConfigurationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SecurityStructureController;
use App\Http\Controllers\Api\V1\SupplyController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuditSecurityRejection;
use App\Http\Middleware\LocalManagementAccess;
use App\Http\Middleware\RequireOperatingContext;
use App\Http\Middleware\RequireSpaSession;
use App\Http\Middleware\ValidateIdentityContext;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1/account')->middleware([RequireSpaSession::class, 'throttle:account-links'])->group(function (): void {
    Route::post('/activate', [AccountLinkController::class, 'activate']);
    Route::post('/reset-password', [AccountLinkController::class, 'resetPassword']);
});

Route::prefix('v1/security')->middleware([RequireSpaSession::class, 'auth:sanctum', ValidateIdentityContext::class, AuditSecurityRejection::class, RequireOperatingContext::class, 'throttle:security'])->group(function (): void {
    Route::post('/locations', [SecurityStructureController::class, 'store']);
    Route::patch('/locations/{location}', [SecurityStructureController::class, 'update'])->whereUuid('location');
    Route::post('/locations/{location}/managing-kitchen', [SecurityStructureController::class, 'managingKitchen'])->whereUuid('location');
    Route::put('/locations/{location}/managing-kitchen', [SecurityStructureController::class, 'managingKitchen'])->whereUuid('location');
    Route::get('/access', [SecurityController::class, 'access']);
    Route::get('/modules/catalog', [ModuleConfigurationController::class, 'catalog']);
    Route::get('/locations/{location}/modules', [ModuleConfigurationController::class, 'states'])->whereUuid('location');
    Route::post('/modules/configuration', [ModuleConfigurationController::class, 'configure']);
    Route::get('/locations/{location}/modules/history', [ModuleConfigurationController::class, 'history'])->whereUuid('location');
    Route::post('/users/{user}/roles', [SecurityController::class, 'grantRole'])->whereUuid('user');
    Route::delete('/roles/{assignment}', [SecurityController::class, 'revokeRole'])->whereUuid('assignment');
    Route::patch('/users/{user}/status', [SecurityController::class, 'accountStatus'])->whereUuid('user');
    Route::patch('/users/{user}/email', [SecurityController::class, 'changeEmail'])->whereUuid('user');
    Route::get('/locations/{location}/users', [SecurityController::class, 'users'])->whereUuid('location');
    Route::get('/users/{user}/assignments', [SecurityController::class, 'assignments'])->whereUuid('user');
    Route::post('/locations/{location}/personnel', [SecurityController::class, 'personnel'])->whereUuid('location');
    Route::get('/locations/{location}/membership-requests', [SecurityController::class, 'requests'])->whereUuid('location');
    Route::post('/membership-requests/{membershipRequest}/decision', [SecurityController::class, 'decide'])->whereUuid('membershipRequest');
    Route::delete('/memberships/{membership}', [SecurityController::class, 'removeMembership'])->whereUuid('membership');
    Route::post('/users/{user}/locations/{location}/membership', [SecurityController::class, 'assignMembership'])->whereUuid('user')->whereUuid('location');
    Route::post('/users/{user}/locations/{location}/permissions', [SecurityController::class, 'grantPermission'])->whereUuid('user')->whereUuid('location');
    Route::delete('/users/{user}/locations/{location}/permissions', [SecurityController::class, 'revokePermission'])->whereUuid('user')->whereUuid('location');
    Route::post('/users/{identifier}/links', [SecurityController::class, 'link'])->whereUuid('identifier');
});

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
