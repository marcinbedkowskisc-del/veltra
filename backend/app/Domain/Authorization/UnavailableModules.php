<?php

namespace App\Domain\Authorization;

use App\Models\Location;

final class UnavailableModules implements ModuleAvailability
{
    public function enabled(Location $location, string $module): bool
    {
        return false;
    }
}
