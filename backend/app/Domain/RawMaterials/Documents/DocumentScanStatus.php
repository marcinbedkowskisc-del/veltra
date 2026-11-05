<?php

namespace App\Domain\RawMaterials\Documents;

enum DocumentScanStatus: string
{
    case Pending = 'PENDING';
    case Clean = 'CLEAN';
    case Infected = 'INFECTED';
    case Error = 'ERROR';
}
