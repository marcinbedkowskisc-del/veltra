<?php

namespace Tests\Support;

use App\Domain\Installation\LocationType;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityDefinition;

final class OrganizationCapabilityCatalogFixtures
{
    public static function ready(): OrganizationCapabilityCatalog
    {
        return new class implements OrganizationCapabilityCatalog
        {
            public function find(string $key): ?OrganizationCapabilityDefinition
            {
                return $key === CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ
                    ? $this->definition()
                    : null;
            }

            public function all(): array
            {
                return [$this->definition()];
            }

            private function definition(): OrganizationCapabilityDefinition
            {
                return new OrganizationCapabilityDefinition(
                    CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
                    'ORGANIZATION',
                    ready: true,
                    operatingContextTypes: [LocationType::CentralKitchen, LocationType::Restaurant],
                    operations: ['list', 'detail'],
                );
            }
        };
    }
}
