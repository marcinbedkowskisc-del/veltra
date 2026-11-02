<?php

namespace App\Domain\Authorization;

use App\Models\Location;

interface ModuleAvailability
{
    public function enabled(Location $location, string $module): bool;
}
