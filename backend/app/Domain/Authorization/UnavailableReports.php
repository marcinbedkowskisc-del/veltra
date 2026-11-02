<?php

namespace App\Domain\Authorization;

use App\Models\Location;

final class UnavailableReports implements ReportCatalog
{
    public function sourceKitchen(string $reportId): ?Location
    {
        return null;
    }
}
