<?php

namespace Tests\Support;

use App\Domain\Authorization\SystemPermissionManifest;
use App\Domain\Authorization\SystemPermissionManifestRegistry;
use App\Domain\Authorization\SystemPermissionProvisioner;
use App\Domain\Authorization\SystemProvisioningRequestValidator;
use App\Domain\Installation\BusinessRuleViolation;
use App\Domain\Installation\LocalStage6a1Instance;
use Illuminate\Support\Facades\DB;

final class SystemPermissionProvisioningFixture
{
    public static function provisioner(SystemPermissionManifest $manifest): SystemPermissionProvisioner
    {
        $registry = new class($manifest) extends SystemPermissionManifestRegistry
        {
            public function __construct(private SystemPermissionManifest $manifest) {}

            public function find(string $id, int $version): ?SystemPermissionManifest
            {
                return $id === $this->manifest->id && $version === $this->manifest->version ? $this->manifest : null;
            }
        };
        $instance = new class extends LocalStage6a1Instance
        {
            public function verifyProvisioningTarget(SystemPermissionManifest $manifest): array
            {
                $identity = DB::selectOne(<<<'SQL'
                    SELECT current_database() AS database_name,
                        (SELECT oid::text FROM pg_database WHERE datname = current_database()) AS database_oid,
                        host(inet_server_addr()) AS server_address
                    SQL);
                if (! app()->environment('testing') || $identity->database_name !== 'veltra_testing'
                    || $manifest->databaseName !== 'veltra_testing') {
                    throw new BusinessRuleViolation('Niezgodna syntetyczna instalacja testowa.');
                }

                return ['database_oid' => $identity->database_oid, 'server_address' => $identity->server_address];
            }
        };

        return new SystemPermissionProvisioner($registry, $instance, app(SystemProvisioningRequestValidator::class));
    }
}
