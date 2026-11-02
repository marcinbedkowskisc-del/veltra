<?php

namespace Tests\Unit;

use App\Domain\Installation\LocationType;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\Modules\ModuleDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ModuleCatalogTest extends TestCase
{
    public function test_production_catalog_contains_only_approved_not_ready_modules(): void
    {
        $catalog = new DefinedModuleCatalog;
        $modules = $catalog->all();

        $this->assertSame(['TEMPERATURES', 'CLEANING', 'LOSSES', 'INVENTORY'], array_keys($modules));
        $this->assertSame([
            'TEMPERATURES' => 'TEMPERATURE_REGISTER',
            'CLEANING' => 'CLEANING_ENTRY_CREATE',
            'LOSSES' => 'LOSS_REPORT_CREATE',
            'INVENTORY' => 'INVENTORY_COUNT_ENTRY',
        ], array_map(fn (ModuleDefinition $module): string => $module->permission, $modules));
        foreach ($modules as $module) {
            $this->assertFalse($module->ready);
            $this->assertSame([], $module->dependencies);
            $this->assertSame([LocationType::CentralKitchen, LocationType::Restaurant], $module->locationTypes);
        }
    }

    public function test_unknown_dependencies_and_cycles_are_rejected(): void
    {
        $types = [LocationType::Restaurant];
        try {
            new DefinedModuleCatalog([new ModuleDefinition('ORPHAN', 'Orphan', 'ORPHAN_USE', $types, ['MISSING'])]);
            $this->fail('A missing dependency was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('unknown module', $exception->getMessage());
        }

        try {
            new DefinedModuleCatalog([
                new ModuleDefinition('CYCLE_A', 'Cycle A', 'CYCLE_A_USE', $types, ['CYCLE_B']),
                new ModuleDefinition('CYCLE_B', 'Cycle B', 'CYCLE_B_USE', $types, ['CYCLE_A']),
            ]);
            $this->fail('A dependency cycle was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('cycle', $exception->getMessage());
        }
    }
}
