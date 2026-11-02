<?php

namespace App\Domain\Authorization;

use App\Models\Location;

interface ReportCatalog
{
    public function sourceKitchen(string $reportId): ?Location;
}
