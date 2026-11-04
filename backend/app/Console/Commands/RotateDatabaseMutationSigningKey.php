<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RotateDatabaseMutationSigningKey extends Command
{
    protected $signature = 'veltra:rotate-database-mutation-signing-key';

    protected $description = 'Rotate the dedicated key used for protected PostgreSQL mutations';

    public function handle(): int
    {
        $migrationRole = (string) env('APP_DB_MIGRATION_USERNAME', 'veltra_migrator');
        if ((string) DB::scalar('SELECT session_user') !== $migrationRole) {
            $this->error('Uruchom rotację wyłącznie połączeniem roli migracyjnej.');

            return self::FAILURE;
        }

        $configuredKey = config('authorization.database_mutation_signing_key');
        if (! is_string($configuredKey) || ! preg_match('/\A[a-f0-9]{64}\z/', $configuredKey)) {
            $this->error('Klucz musi zawierać 32 losowe bajty zapisane jako 64 znaki hex.');

            return self::FAILURE;
        }

        $signingKey = hash_hmac(
            'sha256',
            'veltra-db-authorization-ticket-v2',
            hex2bin($configuredKey),
            true,
        );

        try {
            DB::transaction(function () use ($signingKey): void {
                $updated = DB::update(
                    "UPDATE veltra_private.mutation_signing_keys SET signing_key = decode(?, 'hex') WHERE singleton",
                    [bin2hex($signingKey)],
                );
                if ($updated !== 1) {
                    throw new RuntimeException('Nie znaleziono dokładnie jednego rekordu klucza mutacji.');
                }
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Klucz mutacji PostgreSQL został zmieniony. Wszystkie procesy runtime muszą używać zgodnej wartości.');

        return self::SUCCESS;
    }
}
