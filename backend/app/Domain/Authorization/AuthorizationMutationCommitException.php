<?php

namespace App\Domain\Authorization;

use PDOException;
use RuntimeException;

final class AuthorizationMutationCommitException extends RuntimeException
{
    public function __construct(
        public readonly bool $transactionRolledBack,
        PDOException $commitFailure,
    ) {
        parent::__construct('Authorization mutation commit outcome could not be confirmed.', 0, $commitFailure);
    }
}
