<?php

namespace App\Domain\Installation;

use App\Domain\Authorization\SystemPermissionManifest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use JsonException;

class LocalStage6a1Instance
{
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
        $role = (string) env('APP_DB_MIGRATION_USERNAME', 'veltra_migrator');
        if ($role === '' || ! $this->hasLocalStageConfiguration()
            || $manifest->databaseName !== 'veltra_stage6a1') {
            throw new BusinessRuleViolation('Baza nie odpowiada instalacji wskazanej w manifeście.');
        }
        $actual = $this->databaseIdentity();
        if (! $this->isLocalStage($actual, $role)
            || $actual->name !== $manifest->databaseName
            || ! Organization::whereKey($manifest->organizationId)->where('is_active', true)
                ->where('authorization_initialized', true)->exists()
            || ! User::whereKey($manifest->administratorId)->where('organization_id', $manifest->organizationId)
                ->where('is_initial_identity', true)->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
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
        if (! $this->hasLocalStageConfiguration()) {
            return null;
        }
        if (! $this->isLocalStage($this->databaseIdentity(), 'veltra_app') || Organization::count() !== 1) {
            return null;
        }
        $organization = Organization::where('is_active', true)->where('authorization_initialized', true)->first();
        if (! $organization) {
            return null;
        }
        $users = User::where('organization_id', $organization->id)->where('is_initial_identity', true)
            ->where('is_active', true)->whereNotNull('email_verified_at')->limit(2)->get();
        if ($users->count() !== 1) {
            return null;
        }

        return ['organization_id' => $organization->id, 'user_id' => $users->first()->id];
    }

    private function hasLocalStageConfiguration(): bool
    {
        return app()->environment('local') && $this->insideContainer()
            && config('app.local_stage_project') === 'veltra-stage6a1'
            && config('database.default') === 'pgsql'
            && DB::connection()->getConfig('host') === 'postgres'
            && (string) DB::connection()->getConfig('port') === '5432'
            && config('app.url') === 'http://localhost:8001'
            && config('app.frontend_url') === 'http://localhost:5174';
    }

    private function isLocalStage(object $database, string $role): bool
    {
        return $this->hasLocalStageConfiguration()
            && $database->configured_name === 'veltra_stage6a1'
            && $database->name === 'veltra_stage6a1'
            && $database->role === $role
            && $database->server_address === gethostbyname('postgres')
            && (int) $database->server_port === 5432;
    }

    protected function insideContainer(): bool
    {
        return is_file('/.dockerenv');
    }
}
