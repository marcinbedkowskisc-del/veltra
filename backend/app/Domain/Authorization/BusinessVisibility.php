<?php

namespace App\Domain\Authorization;

use App\Domain\Installation\LocationType;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityAccess;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use App\Models\User;

final class BusinessVisibility
{
    public function __construct(
        private BusinessAuthorization $authorization,
        private BossLocationRestrictions $restrictions,
        private RawMaterialAuthorization $rawAuthorization,
        private ModuleAvailability $modules,
        private OrganizationCapabilityAccess $organizationCapabilities,
    ) {}

    public function decide(User $actor, Location $context, BusinessVisibilitySurface $surface): BusinessVisibilityDecision
    {
        return match ($surface->source) {
            BusinessVisibilitySource::Boss => $this->decideBoss($actor, $context, $surface),
            BusinessVisibilitySource::Stage1d => $this->decideStage1d($actor, $context, $surface),
            BusinessVisibilitySource::RawOrganization => $this->decideRaw($actor, $context, $surface),
        };
    }

    private function decideBoss(User $actor, Location $context, BusinessVisibilitySurface $surface): BusinessVisibilityDecision
    {
        if ($surface->intent !== null) {
            return $this->decideBossRawCatalog($actor, $context, $surface);
        }

        if (! $surface->permission instanceof Permission
            || ! in_array($surface->permission, [Permission::LocationView, Permission::ReportsView, Permission::ReportsExport], true)
            || ! $this->authorization->eligible($actor, $context, $surface->target)
            || ! $this->authorization->hasRole($actor, BusinessRole::Boss)) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'BOSS_ACCESS_NOT_AUTHORIZED');
        }

        if (in_array($surface->permission, [Permission::ReportsView, Permission::ReportsExport], true)
            && ! in_array(BossVisibilityCategory::ReportsAnalytics, $surface->categories, true)) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'SURFACE_CATEGORY_MISSING');
        }
        if (in_array($surface->permission, [Permission::ReportsView, Permission::ReportsExport], true)
            && $surface->target?->type !== LocationType::Restaurant
            && ! ($surface->key === 'boss.report.shared' && $surface->target?->type === LocationType::CentralKitchen)) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'REPORT_TARGET_NOT_AVAILABLE');
        }
        if ($surface->permission === Permission::LocationView) {
            if ($surface->target === null && $surface->key !== 'boss.location_directory') {
                return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'LOCATION_TARGET_REQUIRED');
            }
            if ($surface->target !== null && $surface->target->type !== LocationType::Restaurant) {
                return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'LOCATION_TARGET_REQUIRED');
            }
        }
        if ($surface->module !== null && ! $this->modules->enabled($context, $surface->module)) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'OPERATING_CONTEXT_MODULE_UNAVAILABLE');
        }
        if ($surface->provenance->scope === BusinessDataScope::Unknown) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'DATA_PROVENANCE_UNKNOWN');
        }

        foreach ($surface->provenance->locationIds as $locationId) {
            $origin = Location::where('organization_id', $actor->organization_id)->whereKey($locationId)->first();
            if (! $origin || ! $origin->is_active) {
                return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'DATA_ORIGIN_UNAVAILABLE');
            }
            if ($surface->module !== null && ! $this->modules->enabled($origin, $surface->module)) {
                return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'DATA_ORIGIN_MODULE_UNAVAILABLE');
            }
        }
        if ($this->restrictions->denies($actor, $surface->provenance, $surface->categories)) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'BOSS_RESTRICTION_APPLIES');
        }

        return BusinessVisibilityDecision::allow(BusinessVisibilitySource::Boss);
    }

    private function decideBossRawCatalog(
        User $actor,
        Location $context,
        BusinessVisibilitySurface $surface,
    ): BusinessVisibilityDecision {
        $operation = $surface->intent?->value;
        if ($operation === null
            || ! $this->authorization->eligible($actor, $context)
            || ! $this->authorization->hasOrganizationRole($actor, BusinessRole::Boss)) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'BOSS_ACCESS_NOT_AUTHORIZED');
        }

        if (! $this->organizationCapabilities->isEnabled(
            $actor->organization_id,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            $operation,
            $context->type,
        )) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'BOSS_CAPABILITY_UNAVAILABLE');
        }

        if ($this->restrictions->denies(
            $actor,
            $surface->provenance,
            $surface->categories,
        )) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Boss, 'BOSS_RESTRICTION_APPLIES');
        }

        return BusinessVisibilityDecision::allow(BusinessVisibilitySource::Boss);
    }

    private function decideStage1d(User $actor, Location $context, BusinessVisibilitySurface $surface): BusinessVisibilityDecision
    {
        if (! $surface->permission instanceof Permission || ! $surface->target
            || ! $this->authorization->allowsIndependent($actor, $context, $surface->target, $surface->permission)) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::Stage1d, 'STAGE_1D_ACCESS_NOT_AUTHORIZED');
        }

        return BusinessVisibilityDecision::allow(BusinessVisibilitySource::Stage1d);
    }

    private function decideRaw(User $actor, Location $context, BusinessVisibilitySurface $surface): BusinessVisibilityDecision
    {
        if (! $surface->permission instanceof RawMaterialPermission
            || ! in_array($surface->permission, [
                RawMaterialPermission::View,
                RawMaterialPermission::SupplierView,
                RawMaterialPermission::PurchasingCostsView,
                RawMaterialPermission::ReferencePriceView,
                RawMaterialPermission::ExchangeRateView,
                RawMaterialPermission::AuditView,
            ], true)) {
            return BusinessVisibilityDecision::deny(BusinessVisibilitySource::RawOrganization, 'RAW_READ_CONTRACT_REQUIRED');
        }

        $this->rawAuthorization->assertAllowed($actor, $context, $surface->permission);

        return BusinessVisibilityDecision::allow(BusinessVisibilitySource::RawOrganization);
    }
}
