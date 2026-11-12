<?php

namespace App\Domain\Modules;

use App\Domain\Installation\LocationType;
use InvalidArgumentException;

final class DefinedModuleCatalog implements ModuleCatalog
{
    /**
     * @var array<string, ModuleDefinition>
     */
    private array $modules;

    /**
     * @param  list<ModuleDefinition>|null  $definitions
     */
    public function __construct(?array $definitions = null)
    {
        $definitions ??= self::productionDefinitions();
        $this->modules = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof ModuleDefinition || ! preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D', $definition->key)) {
                throw new InvalidArgumentException('Module definitions require a stable uppercase key.');
            }
            if (isset($this->modules[$definition->key])) {
                throw new InvalidArgumentException("Duplicate module definition: {$definition->key}.");
            }
            if ($definition->name === '' || $definition->permission === '' || $definition->locationTypes === []) {
                throw new InvalidArgumentException("Module {$definition->key} requires a name, permission, and location type.");
            }
            foreach ($definition->locationTypes as $type) {
                if (! $type instanceof LocationType) {
                    throw new InvalidArgumentException("Module {$definition->key} has an invalid location type.");
                }
            }
            if (count($definition->dependencies) !== count(array_unique($definition->dependencies))) {
                throw new InvalidArgumentException("Module {$definition->key} contains duplicate dependencies.");
            }
            $this->modules[$definition->key] = $definition;
        }

        $this->validateDependencies();
    }

    public function all(): array
    {
        return $this->modules;
    }

    public function find(string $key): ?ModuleDefinition
    {
        return $this->modules[$key] ?? null;
    }

    public function withReadyRawMaterials(): self
    {
        $definitions = $this->all();
        $raw = $definitions['RAW_MATERIALS'];
        $definitions['RAW_MATERIALS'] = new ModuleDefinition(
            $raw->key, $raw->name, $raw->permission, $raw->locationTypes, $raw->dependencies, ready: true,
        );

        return new self(array_values($definitions));
    }

    /**
     * @return list<ModuleDefinition>
     */
    private static function productionDefinitions(): array
    {
        $types = [LocationType::CentralKitchen, LocationType::Restaurant];

        return [
            new ModuleDefinition('TEMPERATURES', 'Rejestr temperatur', 'TEMPERATURE_REGISTER', $types),
            new ModuleDefinition('CLEANING', 'Higiena i czyszczenie', 'CLEANING_ENTRY_CREATE', $types),
            new ModuleDefinition('LOSSES', 'Straty', 'LOSS_REPORT_CREATE', $types),
            new ModuleDefinition('INVENTORY', 'Inwentaryzacja', 'INVENTORY_COUNT_ENTRY', $types),
            new ModuleDefinition('RAW_MATERIALS', 'Surowce', 'RAW_MATERIALS_VIEW', $types),
        ];
    }

    private function validateDependencies(): void
    {
        foreach ($this->modules as $definition) {
            foreach ($definition->dependencies as $dependency) {
                if (! isset($this->modules[$dependency])) {
                    throw new InvalidArgumentException("Module {$definition->key} depends on unknown module {$dependency}.");
                }
            }
        }

        $visited = [];
        $visiting = [];
        $visit = function (string $key) use (&$visit, &$visited, &$visiting): void {
            if (isset($visiting[$key])) {
                throw new InvalidArgumentException("Module dependency cycle includes {$key}.");
            }
            if (isset($visited[$key])) {
                return;
            }
            $visiting[$key] = true;
            foreach ($this->modules[$key]->dependencies as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };

        foreach (array_keys($this->modules) as $key) {
            $visit($key);
        }
    }
}
