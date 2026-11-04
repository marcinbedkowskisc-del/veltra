<?php

namespace App\Domain\Authorization;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class AuthorizationMutationContext
{
    public function run(AuthorizationMutationScope $scope, ?string $actorId, Closure $operation): mixed
    {
        return $this->runWithSubject($scope, $actorId, null, $operation);
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

        return DB::transaction(function () use ($scope, $actorId, $subjectId, $operation, $signingKey): mixed {
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

            return $result;
        });
    }
}
