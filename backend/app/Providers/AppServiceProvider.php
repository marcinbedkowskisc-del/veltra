<?php

namespace App\Providers;

use App\Domain\Authorization\ConfiguredModuleAvailability;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\ReportCatalog;
use App\Domain\Authorization\UnavailableReports;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\LocalRawMaterialCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\RawMaterials\Documents\ClamAvDocumentScanner;
use App\Domain\RawMaterials\Documents\DocumentScanner;
use App\Domain\RawMaterials\Documents\PrivateDocumentStorage;
use App\Domain\RawMaterials\Documents\PrivateLocalDocumentStorage;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleCatalog::class, LocalRawMaterialCatalog::class);
        $this->app->singleton(OrganizationCapabilityCatalog::class, CodeOrganizationCapabilityCatalog::class);
        $this->app->bind(ModuleAvailability::class, ConfiguredModuleAvailability::class);
        $this->app->bind(ReportCatalog::class, UnavailableReports::class);
        $this->app->bind(PrivateDocumentStorage::class, PrivateLocalDocumentStorage::class);
        $this->app->bind(DocumentScanner::class, ClamAvDocumentScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This installation supports cookie sessions only, never bearer-token authentication.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);
        RateLimiter::for('security', fn (Request $request) => Limit::perMinute(60)->by('security:'.$request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('raw-sku', fn (Request $request) => Limit::perMinute(20)->by('raw-sku:'.$request->user()?->id));
        RateLimiter::for('account-links', fn (Request $request) => Limit::perMinute(10)->by('account-link:'.$request->ip()));
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $identity = is_string($email) ? User::normalizeEmail($email) : '';

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-identity:'.hash('sha256', $identity.'|'.$request->ip())),
            ];
        });
    }
}
