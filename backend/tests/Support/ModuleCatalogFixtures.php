<?php

namespace Tests\Support;

use App\Domain\Installation\LocationType;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\Modules\ModuleDefinition;

final class ModuleCatalogFixtures
{
    public static function ready(): DefinedModuleCatalog
    {
        $types = [LocationType::CentralKitchen, LocationType::Restaurant];

        return new DefinedModuleCatalog([
            new ModuleDefinition('TEMPERATURES', 'Temperatures', 'TEMPERATURE_REGISTER', $types, ready: true),
            new ModuleDefinition('CLEANING', 'Cleaning', 'CLEANING_ENTRY_CREATE', $types, ready: true),
            new ModuleDefinition('LOSSES', 'Losses', 'LOSS_REPORT_CREATE', $types, ready: true),
            new ModuleDefinition('INVENTORY', 'Inventory', 'INVENTORY_COUNT_ENTRY', $types, ready: true),
            new ModuleDefinition('READY_PARENT', 'Ready Parent', 'TEST_PARENT', $types, ready: true),
            new ModuleDefinition('READY_CHILD', 'Ready Child', 'TEST_CHILD', $types, ['READY_PARENT'], true),
            new ModuleDefinition('READY_GRANDCHILD', 'Ready Grandchild', 'TEST_GRANDCHILD', $types, ['READY_CHILD'], true),
            new ModuleDefinition('RESTAURANT_ONLY', 'Restaurant Only', 'TEST_RESTAURANT', [LocationType::Restaurant], ready: true),
            new ModuleDefinition('NOT_READY_TEST', 'Not Ready Test', 'TEST_NOT_READY', $types),
        ]);
    }
}
