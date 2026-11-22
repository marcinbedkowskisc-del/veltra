<?php

use App\Http\Controllers\Api\V1\AccountLinkController;
use App\Http\Controllers\Api\V1\AdministrationAllergenController;
use App\Http\Controllers\Api\V1\AdministrationCategoryController;
use App\Http\Controllers\Api\V1\AdministrationSupplierController;
use App\Http\Controllers\Api\V1\AdministrationUnitController;
use App\Http\Controllers\Api\V1\AdministrationUserController;
use App\Http\Controllers\Api\V1\AuthenticationController;
use App\Http\Controllers\Api\V1\BossLocationController;
use App\Http\Controllers\Api\V1\BossRawMaterialCatalogController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\ManagementController;
use App\Http\Controllers\Api\V1\ModuleConfigurationController;
use App\Http\Controllers\Api\V1\OrganizationCapabilityController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\RawMaterialCapabilitiesController;
use App\Http\Controllers\Api\V1\RawMaterialCatalogCsvImportController;
use App\Http\Controllers\Api\V1\RawMaterialController;
use App\Http\Controllers\Api\V1\RawMaterialCsvCatalogExportController;
use App\Http\Controllers\Api\V1\RawMaterialCsvImportController;
use App\Http\Controllers\Api\V1\RawMaterialDocumentController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SecurityStructureController;
use App\Http\Controllers\Api\V1\SupplyController;
use App\Http\Controllers\Api\V1\UserAvatarController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuditSecurityRejection;
use App\Http\Middleware\BossRawCatalogNoStore;
use App\Http\Middleware\LocalManagementAccess;
use App\Http\Middleware\RequireAdministrator;
use App\Http\Middleware\RequireOperatingContext;
use App\Http\Middleware\RequireRawMaterialsAvailability;
use App\Http\Middleware\RequireSpaSession;
use App\Http\Middleware\ValidateIdentityContext;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1/account')->middleware([RequireSpaSession::class, 'throttle:account-links'])->group(function (): void {
    Route::post('/activate', [AccountLinkController::class, 'activate']);
    Route::post('/reset-password', [AccountLinkController::class, 'resetPassword']);
});

Route::prefix('v1/administration')->middleware([
    RequireSpaSession::class,
    'auth:sanctum',
    ValidateIdentityContext::class,
    AuditSecurityRejection::class,
    RequireOperatingContext::class,
    'throttle:security',
    RequireAdministrator::class,
])->group(function (): void {
    Route::get('/access', fn () => response()->json(['data' => ['allowed' => true]]));
    Route::get('/locations', [AdministrationUserController::class, 'locations']);
    Route::get('/users', [AdministrationUserController::class, 'index']);
    Route::get('/users/{user}', [AdministrationUserController::class, 'show'])->whereUuid('user');
    Route::patch('/users/{user}/profile', [AdministrationUserController::class, 'updateProfile'])->whereUuid('user');
    Route::put('/users/{user}/avatar', [UserAvatarController::class, 'selectForAdministrator'])->whereUuid('user');
    Route::post('/users/{user}/avatar/photo', [UserAvatarController::class, 'uploadForAdministrator'])->whereUuid('user');
    Route::delete('/users/{user}/avatar', [UserAvatarController::class, 'resetForAdministrator'])->whereUuid('user');
    Route::get('/users/{user}/avatar/photo', [UserAvatarController::class, 'administratorPhoto'])->whereUuid('user');
    Route::get('/users/{user}/boss-location-restrictions', [BossLocationController::class, 'showRestrictions'])->whereUuid('user');
    Route::put('/users/{user}/boss-location-restrictions', [BossLocationController::class, 'updateRestrictions'])->whereUuid('user');
    Route::get('/categories', [AdministrationCategoryController::class, 'index']);
    Route::post('/categories', [AdministrationCategoryController::class, 'store']);
    Route::patch('/categories/{category}', [AdministrationCategoryController::class, 'update'])->whereUuid('category');
    Route::post('/categories/{category}/archive', [AdministrationCategoryController::class, 'archive'])->whereUuid('category');
    Route::post('/categories/{category}/restore', [AdministrationCategoryController::class, 'restore'])->whereUuid('category');
    Route::get('/suppliers', [AdministrationSupplierController::class, 'index']);
    Route::post('/suppliers', [AdministrationSupplierController::class, 'store']);
    Route::get('/suppliers/similar-names', [AdministrationSupplierController::class, 'similarNames']);
    Route::get('/suppliers/{supplier}', [AdministrationSupplierController::class, 'show'])->whereUuid('supplier');
    Route::patch('/suppliers/{supplier}', [AdministrationSupplierController::class, 'update'])->whereUuid('supplier');
    Route::post('/suppliers/{supplier}/archive', [AdministrationSupplierController::class, 'archive'])->whereUuid('supplier');
    Route::post('/suppliers/{supplier}/restore', [AdministrationSupplierController::class, 'restore'])->whereUuid('supplier');
    Route::post('/suppliers/{supplier}/contacts', [AdministrationSupplierController::class, 'storeContact'])->whereUuid('supplier');
    Route::patch('/suppliers/{supplier}/contacts/{contact}', [AdministrationSupplierController::class, 'updateContact'])
        ->whereUuid('supplier')->whereUuid('contact');
    Route::delete('/suppliers/{supplier}/contacts/{contact}', [AdministrationSupplierController::class, 'removeContact'])
        ->whereUuid('supplier')->whereUuid('contact');
    Route::get('/units', [AdministrationUnitController::class, 'index']);
    Route::post('/units', [AdministrationUnitController::class, 'store']);
    Route::patch('/units/{unit}', [AdministrationUnitController::class, 'update'])->whereUuid('unit');
    Route::post('/units/{unit}/archive', [AdministrationUnitController::class, 'archive'])->whereUuid('unit');
    Route::post('/units/{unit}/restore', [AdministrationUnitController::class, 'restore'])->whereUuid('unit');
    Route::get('/allergens/dictionaries', [AdministrationAllergenController::class, 'dictionaries']);
    Route::get('/allergens/canonical', [AdministrationAllergenController::class, 'canonicalAllergens']);
    Route::get('/allergens/maybe-contains', [AdministrationAllergenController::class, 'precautionaryAllergens']);
    Route::post('/allergens/maybe-contains', [AdministrationAllergenController::class, 'addPrecautionaryAllergen']);
    Route::delete('/allergens/maybe-contains/{allergen}', [AdministrationAllergenController::class, 'removePrecautionaryAllergen'])
        ->where('allergen', '[A-Z][A-Z0-9_]{0,39}');
    Route::get('/allergens/dictionaries/{version}/entries', [AdministrationAllergenController::class, 'entries'])->whereUuid('version');
    Route::get('/allergens/dictionaries/{version}/history', [AdministrationAllergenController::class, 'history'])->whereUuid('version');
    Route::post('/allergens/dictionaries/{version}/entries', [AdministrationAllergenController::class, 'storeEntry'])->whereUuid('version');
    Route::patch('/allergens/entries/{entry}', [AdministrationAllergenController::class, 'updateEntry'])->whereUuid('entry');
    Route::post('/allergens/entries/{entry}/archive', [AdministrationAllergenController::class, 'archiveEntry'])->whereUuid('entry');
    Route::patch('/allergens/dictionaries/{version}/canonical/{allergen}', [AdministrationAllergenController::class, 'updateCanonical'])
        ->whereUuid('version')->where('allergen', '[A-Z][A-Z0-9_]{0,39}');
    Route::post('/allergens/dictionaries/{version}/canonical/{allergen}/archive', [AdministrationAllergenController::class, 'archiveCanonical'])
        ->whereUuid('version')->where('allergen', '[A-Z][A-Z0-9_]{0,39}');
});

Route::prefix('v1/security')->middleware([RequireSpaSession::class, 'auth:sanctum', ValidateIdentityContext::class, AuditSecurityRejection::class, RequireOperatingContext::class, 'throttle:security'])->group(function (): void {
    Route::get('/raw-materials/capabilities', RawMaterialCapabilitiesController::class);
    Route::get('/organization-capabilities/BOSS_RAW_CATALOG_READ', [OrganizationCapabilityController::class, 'show']);
    Route::put('/organization-capabilities/BOSS_RAW_CATALOG_READ', [OrganizationCapabilityController::class, 'configure']);
    Route::get('/organization-capabilities/BOSS_RAW_CATALOG_READ/history', [OrganizationCapabilityController::class, 'history']);
    Route::post('/locations', [SecurityStructureController::class, 'store']);
    Route::patch('/locations/{location}', [SecurityStructureController::class, 'update'])->whereUuid('location');
    Route::post('/locations/{location}/managing-kitchen', [SecurityStructureController::class, 'managingKitchen'])->whereUuid('location');
    Route::put('/locations/{location}/managing-kitchen', [SecurityStructureController::class, 'managingKitchen'])->whereUuid('location');
    Route::get('/access', [SecurityController::class, 'access']);
    Route::get('/boss/locations', [BossLocationController::class, 'index']);
    Route::get('/modules/catalog', [ModuleConfigurationController::class, 'catalog']);
    Route::get('/locations/{location}/modules', [ModuleConfigurationController::class, 'states'])->whereUuid('location');
    Route::post('/modules/configuration', [ModuleConfigurationController::class, 'configure']);
    Route::get('/locations/{location}/modules/history', [ModuleConfigurationController::class, 'history'])->whereUuid('location');
    Route::post('/users/{user}/roles', [SecurityController::class, 'grantRole'])->whereUuid('user');
    Route::patch('/me/profile', [SecurityController::class, 'updateMyProfile']);
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

Route::prefix('v1/boss/raw-materials')->middleware([
    RequireSpaSession::class,
    'auth:sanctum',
    ValidateIdentityContext::class,
    AuditSecurityRejection::class,
    RequireOperatingContext::class,
    'throttle:security',
    BossRawCatalogNoStore::class,
])->group(function (): void {
    Route::get('/', [BossRawMaterialCatalogController::class, 'index']);
    Route::get('/{material}', [BossRawMaterialCatalogController::class, 'show'])->whereUuid('material');
});

Route::prefix('v1/raw-materials')->middleware([
    RequireSpaSession::class,
    'auth:sanctum',
    ValidateIdentityContext::class,
    AuditSecurityRejection::class,
    RequireOperatingContext::class,
    'throttle:security',
    RequireRawMaterialsAvailability::class,
])->group(function (): void {
    Route::post('/pricing-preview', [RawMaterialController::class, 'previewPricing']);
    Route::post('/document-uploads', [RawMaterialDocumentController::class, 'upload']);
    Route::post('/bulk-archive/validation', [RawMaterialController::class, 'validateBulkArchive']);
    Route::post('/bulk-archive', [RawMaterialController::class, 'bulkArchive']);
    Route::post('/document-uploads/{upload}/retry', [RawMaterialDocumentController::class, 'retry'])->whereUuid('upload');
    Route::get('/{material}/documents', [RawMaterialDocumentController::class, 'index'])->whereUuid('material');
    Route::get('/{material}/documents/{version}/download', [RawMaterialDocumentController::class, 'download'])->whereUuid('material')->whereUuid('version');
    Route::get('/{material}/documents/{version}/preview', [RawMaterialDocumentController::class, 'preview'])->whereUuid('material')->whereUuid('version');
    Route::post('/catalog-imports', [RawMaterialCatalogCsvImportController::class, 'upload']);
    Route::post('/catalog-imports/{preview}/commit', [RawMaterialCatalogCsvImportController::class, 'commit'])
        ->whereUuid('preview');
    Route::get('/catalog-imports/results/{batch}', [RawMaterialCatalogCsvImportController::class, 'result'])
        ->whereUuid('batch');
    Route::post('/imports', [RawMaterialCsvImportController::class, 'upload']);
    Route::post('/imports/{preview}/commit', [RawMaterialCsvImportController::class, 'commit'])->whereUuid('preview');
    Route::get('/imports/results/{batch}', [RawMaterialCsvImportController::class, 'result'])->whereUuid('batch');
    Route::post('/catalog-exports/extended', [RawMaterialCsvCatalogExportController::class, 'createExtended']);
    Route::post('/catalog-exports/selection', [RawMaterialCsvCatalogExportController::class, 'createSelection']);
    Route::get('/catalog-exports/selection/{export}/download', [RawMaterialCsvCatalogExportController::class, 'downloadSelection'])->whereUuid('export');
    Route::get('/catalog-exports/extended/{export}/download', [RawMaterialCsvCatalogExportController::class, 'downloadExtended'])
        ->whereUuid('export');
    Route::post('/reference-price-exports', [RawMaterialCsvCatalogExportController::class, 'createReferencePrices']);
    Route::get('/reference-price-exports/{export}/download', [RawMaterialCsvCatalogExportController::class, 'downloadReferencePrices'])
        ->whereUuid('export');
    Route::post('/catalog-exports', [RawMaterialCsvCatalogExportController::class, 'create']);
    Route::get('/catalog-exports/{export}/download', [RawMaterialCsvCatalogExportController::class, 'download'])
        ->whereUuid('export');
    Route::get('/suppliers', [RawMaterialController::class, 'suppliers']);
    Route::post('/suppliers', [RawMaterialController::class, 'storeSupplier']);
    Route::patch('/suppliers/{supplier}', [RawMaterialController::class, 'correctSupplier'])
        ->whereUuid('supplier');
    Route::post('/suppliers/{supplier}/archive', [RawMaterialController::class, 'archiveSupplier'])
        ->whereUuid('supplier');
    Route::get('/{material}/supplier-products', [RawMaterialController::class, 'supplierProducts'])
        ->whereUuid('material');
    Route::post('/{material}/supplier-products', [RawMaterialController::class, 'storeSupplierProduct'])
        ->whereUuid('material');
    Route::get('/supplier-products/{product}/revisions', [RawMaterialController::class, 'supplierProductRevisions'])
        ->whereUuid('product');
    Route::post('/supplier-products/{product}/revisions', [RawMaterialController::class, 'reviseSupplierProduct'])
        ->whereUuid('product');
    Route::get('/supplier-products/{product}/compatibility-assessments', [RawMaterialController::class, 'supplierCompatibilityAssessments'])
        ->whereUuid('product');
    Route::post('/supplier-products/{product}/compatibility-assessments', [RawMaterialController::class, 'assessSupplierCompatibility'])
        ->whereUuid('product');
    Route::post('/supplier-products/{product}/archive', [RawMaterialController::class, 'archiveSupplierProduct'])
        ->whereUuid('product');
    Route::get('/supplier-products/{product}/offers', [RawMaterialController::class, 'supplierOffers'])
        ->whereUuid('product');
    Route::post('/supplier-products/{product}/offers', [RawMaterialController::class, 'storeSupplierOffer'])
        ->whereUuid('product');
    Route::get('/supplier-offers/{offer}/price-events', [RawMaterialController::class, 'supplierOfferPriceEvents'])
        ->whereUuid('offer');
    Route::post('/supplier-offers/{offer}/price-events', [RawMaterialController::class, 'storeSupplierOfferPriceEvent'])
        ->whereUuid('offer');
    Route::get('/exchange-rates', [RawMaterialController::class, 'exchangeRate']);
    Route::post('/exchange-rates/nbp-sync', [RawMaterialController::class, 'syncExchangeRate']);
    Route::post('/exchange-rates/{rate}/overrides', [RawMaterialController::class, 'overrideExchangeRate'])
        ->whereUuid('rate');
    Route::get('/allergen-catalog', [RawMaterialController::class, 'allergenCatalog']);
    Route::put('/{material}/draft/allergen-declaration', [RawMaterialController::class, 'updateDraftAllergenDeclaration'])
        ->whereUuid('material');
    Route::put('/{material}/draft/storage-variants', [RawMaterialController::class, 'updateDraftStorageVariants'])
        ->whereUuid('material');
    Route::get('/{material}/revisions/{revision}/stage2d', [RawMaterialController::class, 'revisionStage2d'])
        ->whereUuid('material')->whereUuid('revision');
    Route::get('/{material}/reference-prices', [RawMaterialController::class, 'referencePrices'])
        ->whereUuid('material');
    Route::post('/{material}/reference-prices', [RawMaterialController::class, 'storeReferencePrice'])
        ->whereUuid('material');
    Route::get('/{material}/reference-prices/{event}/restatements', [RawMaterialController::class, 'referencePriceRestatements'])
        ->whereUuid('material')->whereUuid('event');
    Route::post('/{material}/reference-prices/{event}/restatements', [RawMaterialController::class, 'storeReferencePriceRestatement'])
        ->whereUuid('material')->whereUuid('event');
    Route::get('/{material}/shares', [RawMaterialController::class, 'shares'])->whereUuid('material');
    Route::post('/{material}/shares', [RawMaterialController::class, 'createShare'])->whereUuid('material');
    Route::post('/{material}/shares/bulk', [RawMaterialController::class, 'bulkShareLocations'])
        ->whereUuid('material');
    Route::delete('/{material}/draft', [RawMaterialController::class, 'deleteDraft'])->whereUuid('material');
    Route::post('/bulk-shares/by-location', [RawMaterialController::class, 'bulkShareMaterials']);
    Route::delete('/{material}/shares/{location}', [RawMaterialController::class, 'revokeShare'])
        ->whereUuid('material')->whereUuid('location');
    Route::get('/', [RawMaterialController::class, 'index']);
    Route::get('/workbench', [RawMaterialController::class, 'workbench']);
    Route::post('/sku-reservations', [RawMaterialController::class, 'reserveSku'])->middleware('throttle:raw-sku');
    Route::post('/create-approved', [RawMaterialController::class, 'storeApproved']);
    Route::post('/', [RawMaterialController::class, 'store']);
    Route::get('/categories', [RawMaterialController::class, 'categories']);
    Route::post('/categories', [RawMaterialController::class, 'storeCategory']);
    Route::patch('/categories/{category}', [RawMaterialController::class, 'renameCategory'])->whereUuid('category');
    Route::get('/units', [RawMaterialController::class, 'units']);
    Route::get('/{material}', [RawMaterialController::class, 'show'])->whereUuid('material');
    Route::get('/{material}/editor', [RawMaterialController::class, 'editor'])->whereUuid('material');
    Route::get('/{material}/completion', [RawMaterialController::class, 'completion'])->whereUuid('material');
    Route::post('/{material}/validation/{section?}', [RawMaterialController::class, 'validateEdits'])
        ->whereUuid('material')->whereIn('section', ['basic', 'allergens', 'storage']);
    Route::get('/{material}/history', [RawMaterialController::class, 'history'])->whereUuid('material');
    Route::patch('/{material}/draft', [RawMaterialController::class, 'updateDraft'])->whereUuid('material');
    Route::get('/{material}/revisions', [RawMaterialController::class, 'revisions'])->whereUuid('material');
    Route::post('/{material}/submit', [RawMaterialController::class, 'submit'])->whereUuid('material');
    Route::post('/{material}/approve', [RawMaterialController::class, 'approve'])->whereUuid('material');
    Route::post('/{material}/return', [RawMaterialController::class, 'returnForChanges'])->whereUuid('material');
    Route::post('/{material}/block', [RawMaterialController::class, 'block'])->whereUuid('material');
    Route::post('/{material}/unblock', [RawMaterialController::class, 'unblock'])->whereUuid('material');
    Route::post('/{material}/archive', [RawMaterialController::class, 'archive'])->whereUuid('material');
    Route::post('/{material}/restore', [RawMaterialController::class, 'restore'])->whereUuid('material');
    Route::post('/permissions/{user}', [RawMaterialController::class, 'grantPermission'])->whereUuid('user');
    Route::delete('/permissions/{grant}', [RawMaterialController::class, 'revokePermission'])->whereUuid('grant');
});

Route::prefix('v1/auth')->middleware(RequireSpaSession::class)->group(function (): void {
    Route::post('/login', [AuthenticationController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [AuthenticationController::class, 'logout'])->middleware('auth:sanctum');
    Route::middleware(['auth:sanctum', ValidateIdentityContext::class])->group(function (): void {
        Route::get('/me', [AuthenticationController::class, 'me']);
        Route::put('/avatar', [UserAvatarController::class, 'selectSelf']);
        Route::post('/avatar/photo', [UserAvatarController::class, 'uploadSelf']);
        Route::delete('/avatar', [UserAvatarController::class, 'resetSelf']);
        Route::get('/avatar/photo', [UserAvatarController::class, 'selfPhoto']);
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
