<?php

namespace App\Domain\Modules;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class OrganizationCapabilityException extends HttpException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $status,
        ?Throwable $previous = null,
    ) {
        parent::__construct($status, $message, $previous);
    }
}
