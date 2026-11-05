<?php

namespace App\Domain\RawMaterials\Documents;

use RuntimeException;
use Throwable;

final class RawMaterialDocumentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
