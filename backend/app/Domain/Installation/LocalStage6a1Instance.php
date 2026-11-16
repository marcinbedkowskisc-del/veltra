<?php

namespace App\Domain\Installation;

use App\Domain\Authorization\SystemPermissionManifest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use JsonException;

class LocalStage6a1Instance
{
    private const RAW_DIRECTORY = '/run/veltra-stage6a1';

    private const RAW_IDENTITY = self::RAW_DIRECTORY.'/raw.json';

    public function assertConfiguration(array $identity, bool $container): void
    {
        if (! app()->environment('local') || ! $container
            || ($identity['compose_project'] ?? null) !== 'veltra-stage6a1'
            || ($identity['postgres_volume'] ?? null) !== 'veltra-stage6a1_postgres-data'
            || ($identity['container_hostname'] ?? null) !== gethostname()
            || ! is_int($identity['expires_at'] ?? null)
            || $identity['expires_at'] <= now()->timestamp
            || $identity['expires_at'] > now()->addDay()->timestamp
            || ! is_string($identity['organization_id'] ?? null)
            || ! is_string($identity['user_id'] ?? null)
            || ! is_string($identity['database_oid'] ?? null)
            || ! is_string($identity['server_address'] ?? null)
            || ! is_string($identity['mailpit_address'] ?? null)
            || config('database.default') !== 'pgsql'
            || DB::connection()->getConfig('host') !== 'postgres'
            || (string) DB::connection()->getConfig('port') !== '5432'
            || gethostbyname('postgres') !== $identity['server_address']
            || config('app.url') !== 'http://localhost:8001'
            || config('app.frontend_url') !== 'http://localhost:5174'
            || config('development.management_enabled') !== false
            || config('mail.default') !== 'smtp'
            || config('mail.mailers.smtp.transport') !== 'smtp'
            || config('mail.mailers.smtp.url') !== null
            || ! in_array(config('mail.mailers.smtp.scheme'), [null, 'smtp'], true)
            || config('mail.mailers.smtp.host') !== 'mailpit'
            || (string) config('mail.mailers.smtp.port') !== '1025'
            || config('mail.mailers.smtp.username') !== null
            || config('mail.mailers.smtp.password') !== null
            || gethostbyname('mailpit') !== $identity['mailpit_address']) {
            throw new BusinessRuleViolation('Odmowa: nie potwierdzono konkretnej izolowanej instancji Stage 6A.1 i Mailpit.');
        }
    }

    public function assertDatabase(array $identity, object $actual, string $expectedRole = 'veltra_app'): void
    {
        if ($actual->configured_name !== 'veltra_stage6a1'
            || $actual->name !== 'veltra_stage6a1'
            || $actual->role !== $expectedRole
            || $actual->oid !== $identity['database_oid']
            || $actual->server_address !== $identity['server_address']
            || (int) $actual->server_port !== 5432) {
            throw new BusinessRuleViolation('Odmowa: rzeczywiste połączenie nie wskazuje na poświadczoną bazę Stage 6A.1.');
        }
    }

    public function readIdentity(string $path): array
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || ! is_readable($path)
            || fileowner($path) !== 0 || (fileperms($path) & 0222) !== 0 || filesize($path) > 4096) {
            throw new BusinessRuleViolation('Brak ważnego, tylko do odczytu poświadczenia instancji przygotowanego przez administratora hosta.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new BusinessRuleViolation('Nie można odczytać poświadczenia instancji.');
        }
        try {
            $identity = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BusinessRuleViolation('Niepoprawny format poświadczenia instancji.');
        }
        if (! is_array($identity) || array_is_list($identity)) {
            throw new BusinessRuleViolation('Niepoprawny format poświadczenia instancji.');
        }

        return $identity;
    }

    public function databaseIdentity(): object
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_database() AS name, current_user AS role,
                (SELECT oid::text FROM pg_database WHERE datname = current_database()) AS oid,
                host(inet_server_addr()) AS server_address, inet_server_port() AS server_port
            SQL);
        $identity->configured_name = DB::connection()->getDatabaseName();

        return $identity;
    }

    /**
     * @return array{database_oid: string, server_address: string}
     */
    public function verifyProvisioningTarget(SystemPermissionManifest $manifest): array
    {
        $this->assertRawDirectory();
        $identity = $this->readIdentity(self::RAW_IDENTITY);
        $this->assertConfiguration($identity, $this->insideContainer());
        $role = (string) env('APP_DB_MIGRATION_USERNAME', 'veltra_migrator');
        if ($role === '' || ($identity['organization_id'] ?? null) !== $manifest->organizationId
            || ($identity['user_id'] ?? null) !== $manifest->administratorId) {
            throw new BusinessRuleViolation('Poświadczenie nie odpowiada celowi manifestu.');
        }
        $actual = $this->databaseIdentity();
        $this->assertDatabase($identity, $actual, $role);
        if ($actual->name !== $manifest->databaseName) {
            throw new BusinessRuleViolation('Baza nie odpowiada instalacji wskazanej w manifeście.');
        }

        return ['database_oid' => $actual->oid, 'server_address' => $actual->server_address];
    }

    public function rawIntegrationEnabled(): bool
    {
        return $this->rawConfigurationIdentity() !== null;
    }

    public function rawConfigurationIdentity(): ?array
    {
        $identity = $this->rawIdentity();
        if ($identity === null) {
            return null;
        }
        $this->assertConfiguration($identity, $this->insideContainer());
        if (($identity['raw_integration_enabled'] ?? null) !== true) {
            throw new BusinessRuleViolation('Brak jawnego opt-in lokalnych testów RAW w poświadczeniu instancji.');
        }
        $this->assertDatabase($identity, $this->databaseIdentity());
        if (Organization::count() !== 1
            || ! Organization::whereKey($identity['organization_id'])->where('is_active', true)
                ->where('authorization_initialized', true)->exists()
            || ! User::whereKey($identity['user_id'])->where('organization_id', $identity['organization_id'])
                ->where('is_initial_identity', true)->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
            throw new BusinessRuleViolation('Odmowa: poświadczenie RAW nie odpowiada aktywnej lokalnej instalacji.');
        }

        return $identity;
    }

    protected function rawIdentity(): ?array
    {
        clearstatcache(true, self::RAW_DIRECTORY);
        if (! file_exists(self::RAW_DIRECTORY) && ! is_link(self::RAW_DIRECTORY)) {
            return null;
        }

        $this->assertRawDirectory();
        clearstatcache(true, self::RAW_IDENTITY);

        return file_exists(self::RAW_IDENTITY) || is_link(self::RAW_IDENTITY)
            ? $this->readIdentity(self::RAW_IDENTITY) : null;
    }

    private function assertRawDirectory(): void
    {
        clearstatcache(true, self::RAW_DIRECTORY);
        if (is_link(self::RAW_DIRECTORY) || ! is_dir(self::RAW_DIRECTORY)
            || fileowner(self::RAW_DIRECTORY) !== 0 || (fileperms(self::RAW_DIRECTORY) & 0022) !== 0) {
            throw new BusinessRuleViolation('Niepoprawny katalog poświadczenia RAW.');
        }
    }

    protected function insideContainer(): bool
    {
        return is_file('/.dockerenv');
    }
}
