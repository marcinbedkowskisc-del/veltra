<?php

namespace App\Domain\Modules;

use App\Domain\Installation\LocationType;

final class CodeOrganizationCapabilityCatalog implements OrganizationCapabilityCatalog
{
    public const BOSS_RAW_CATALOG_READ = 'BOSS_RAW_CATALOG_READ';

    public function find(string $key): ?OrganizationCapabilityDefinition
    {
        return $key === self::BOSS_RAW_CATALOG_READ
            ? $this->bossRawCatalogRead()
            : null;
    }

    public function all(): array
    {
        return [$this->bossRawCatalogRead()];
    }

    private function bossRawCatalogRead(): OrganizationCapabilityDefinition
    {
        return new OrganizationCapabilityDefinition(
            self::BOSS_RAW_CATALOG_READ,
            'ORGANIZATION',
            ready: false,
            operatingContextTypes: [LocationType::CentralKitchen, LocationType::Restaurant],
            operations: ['list', 'detail'],
        );
    }
}
