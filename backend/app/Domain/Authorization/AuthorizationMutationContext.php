<?php

namespace App\Domain\Authorization;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;

final class AuthorizationMutationContext
{
    public function run(AuthorizationMutationScope $scope, ?string $actorId, Closure $operation): mixed
    {
        return $this->runWithSubject($scope, $actorId, null, $operation);
    }

    public function runWithCommitOutcome(
        AuthorizationMutationScope $scope,
        ?string $actorId,
        Closure $operation,
    ): mixed {
        return $this->runWithSubject($scope, $actorId, null, $operation, true);
    }

    public function runForSubject(
        AuthorizationMutationScope $scope,
        ?string $actorId,
        string $subjectId,
        Closure $operation,
    ): mixed {
        if (! Str::isUuid($subjectId)) {
            throw new InvalidArgumentException('A UUID subject is required for a scoped database mutation.');
        }

        return $this->runWithSubject($scope, $actorId, $subjectId, $operation);
    }

    private function runWithSubject(
        AuthorizationMutationScope $scope,
        ?string $actorId,
        ?string $subjectId,
        Closure $operation,
        bool $classifyCommitFailure = false,
    ): mixed {
        $configuredKey = config('authorization.database_mutation_signing_key');
        if (! is_string($configuredKey) || ! preg_match('/\A[a-f0-9]{64}\z/', $configuredKey)) {
            throw new LogicException('A dedicated database mutation signing key is required.');
        }

        $signingKey = hash_hmac(
            'sha256',
            'veltra-db-authorization-ticket-v2',
            hex2bin($configuredKey),
            true,
        );

        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();
        $pdo = $classifyCommitFailure ? $connection->getPdo() : null;
        $operationCompleted = false;

        try {
            return $connection->transaction(function () use (
                $scope,
                $actorId,
                $subjectId,
                $operation,
                $signingKey,
                &$operationCompleted,
            ): mixed {
                $connection = DB::connection();
                $previous = $connection->selectOne(<<<'SQL'
                    SELECT current_setting('veltra.authorization_payload', true) AS payload,
                        current_setting('veltra.authorization_signature', true) AS signature
                    SQL);
                $claims = [
                    'actor_id' => $actorId,
                    'expires_at' => now()->addSeconds(30)->timestamp,
                    'nonce' => (string) Str::uuid(),
                    'scope' => $scope->value(),
                    'transaction_id' => (string) $connection->scalar('SELECT txid_current()'),
                ];
                if ($subjectId !== null) {
                    $claims['subject_id'] = $subjectId;
                }
                $payload = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $signature = hash_hmac('sha256', $payload, $signingKey);

                $connection->select(
                    "SELECT set_config('veltra.authorization_payload', ?, true), set_config('veltra.authorization_signature', ?, true)",
                    [$payload, $signature],
                );

                $result = $operation();

                $connection->select(
                    "SELECT set_config('veltra.authorization_payload', ?, true), set_config('veltra.authorization_signature', ?, true)",
                    [$previous->payload ?? '', $previous->signature ?? ''],
                );
                $operationCompleted = true;

                return $result;
            });
        } catch (PDOException $exception) {
            if (! $classifyCommitFailure || ! $operationCompleted || $transactionLevel !== 0 || ! $pdo instanceof PDO) {
                throw $exception;
            }

            throw new AuthorizationMutationCommitException(
                $this->isConfirmedPostgresConstraintRollback($exception, $connection, $pdo),
                $exception,
            );
        }
    }

    private function isConfirmedPostgresConstraintRollback(
        PDOException $exception,
        Connection $connection,
        PDO $pdo,
    ): bool {
        if ($connection->getDriverName() !== 'pgsql'
            || ! preg_match('/\A23[A-Z0-9]{3}\z/', (string) $exception->getCode())
            || $connection->transactionLevel() !== 0) {
            return false;
        }

        try {
            return ! $pdo->inTransaction();
        } catch (PDOException) {
            return false;
        }
    }
}
