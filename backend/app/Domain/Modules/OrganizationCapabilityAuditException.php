<?php

namespace App\Domain\Modules;

use Throwable;

final class OrganizationCapabilityAuditException extends \RuntimeException
{
    public function __construct(
        Throwable $auditFailure,
        public readonly Throwable $operationFailure,
    ) {
        parent::__construct('Organization capability rejection audit could not be persisted.', 0, $auditFailure);
    }
}
