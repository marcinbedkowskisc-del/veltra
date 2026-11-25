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
        return self::catalog(true);
    }

    public static function notReady(): OrganizationCapabilityCatalog
    {
        return self::catalog(false);
    }

    private static function catalog(bool $ready): OrganizationCapabilityCatalog
    {
        return new class($ready) implements OrganizationCapabilityCatalog
        {
            public function __construct(private bool $ready) {}

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
                    ready: $this->ready,
                    operatingContextTypes: [LocationType::CentralKitchen, LocationType::Restaurant],
                    operations: ['list', 'detail'],
                );
            }
        };
    }
}
