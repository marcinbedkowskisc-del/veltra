<?php

namespace App\Domain\Authorization;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class AuthorizationMutationContext
{
    public function run(string $scope, ?string $actorId, Closure $operation): mixed
    {
        if (! preg_match('/\A[a-z][A-Za-z0-9._-]{0,80}\z/', $scope)) {
            throw new \InvalidArgumentException('Invalid authorization mutation scope.');
        }

        $applicationKey = config('app.key');
        if (! is_string($applicationKey) || $applicationKey === '') {
            throw new LogicException('Application key is required for protected database mutations.');
        }

        return DB::transaction(function () use ($scope, $actorId, $operation, $applicationKey): mixed {
            $connection = DB::connection();
            $previous = $connection->selectOne(<<<'SQL'
                SELECT current_setting('veltra.authorization_payload', true) AS payload,
                    current_setting('veltra.authorization_signature', true) AS signature
                SQL);
            $payload = json_encode([
                'actor_id' => $actorId,
                'expires_at' => now()->addSeconds(30)->timestamp,
                'nonce' => (string) Str::uuid(),
                'scope' => $scope,
                'transaction_id' => (string) $connection->scalar('SELECT txid_current()'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $signingKey = hash_hmac('sha256', 'veltra-db-authorization-ticket-v1', $applicationKey, true);
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
