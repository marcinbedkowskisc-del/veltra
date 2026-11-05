<?php

namespace App\Domain\RawMaterials\Documents;

interface DocumentScanner
{
    public function scan(string $contents): DocumentScanOutcome;
}
