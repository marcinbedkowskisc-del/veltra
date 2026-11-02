<?php

namespace App\Domain\RawMaterials;

use RuntimeException;

final class RawMaterialException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
