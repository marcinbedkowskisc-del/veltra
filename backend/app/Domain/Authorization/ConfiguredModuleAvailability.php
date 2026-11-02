<?php

namespace App\Domain\Authorization;

use App\Domain\Modules\ModuleCatalog;
use App\Models\Location;
use App\Models\LocationModuleState;

final class ConfiguredModuleAvailability implements ModuleAvailability
{
    public function __construct(private ModuleCatalog $catalog) {}

    public function enabled(Location $location, string $module): bool
    {
        $definition = $this->catalog->find($module);
        if (! $definition || ! $definition->ready || ! $definition->supports($location->type)) {
            return false;
        }

        $state = LocationModuleState::query()
            ->where('location_id', $location->id)
            ->where('module_key', $definition->key)
            ->where('enabled', true)
            ->exists();
        if (! $state) {
            return false;
        }

        foreach ($definition->dependencies as $dependency) {
            if (! $this->enabled($location, $dependency)) {
                return false;
            }
        }

        return true;
    }
}
