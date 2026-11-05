<?php

namespace App\Domain\RawMaterials\Documents;

use RuntimeException;
use Throwable;

final class DocumentScannerException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
