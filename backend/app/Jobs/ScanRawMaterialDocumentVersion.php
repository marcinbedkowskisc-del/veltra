<?php

namespace App\Jobs;

use App\Domain\RawMaterials\Documents\RawMaterialDocumentScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ScanRawMaterialDocumentVersion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly string $documentVersionId) {}

    public function handle(RawMaterialDocumentScanService $scanner): void
    {
        $scanner->scanVersion($this->documentVersionId);
    }
}
