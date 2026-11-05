<?php

namespace App\Domain\RawMaterials\Documents;

use RuntimeException;
use Throwable;

final class DocumentStorageException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
