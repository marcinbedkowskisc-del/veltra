<?php

namespace App\Domain\Authorization;

use App\Domain\RawMaterials\RawMaterialPermission;

final readonly class SystemPermissionManifest
{
    /**
     * @param  list<RawMaterialPermission>  $permissions
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $module,
        public array $permissions,
        public BusinessRole $eligibleRole,
        public string $grantScope,
        public string $applicability,
        public string $databaseName,
        public string $organizationId,
        public string $administratorId,
        public string $locationId,
    ) {}

    public static function stage6a1RawUpgrade(): self
    {
        return new self(
            id: 'stage6a1-raw-administrator',
            version: 1,
            module: 'RAW_MATERIALS',
            permissions: [RawMaterialPermission::View, RawMaterialPermission::Create],
            eligibleRole: BusinessRole::Administrator,
            grantScope: 'ORGANIZATION',
            applicability: 'UPGRADE',
            databaseName: 'veltra_stage6a1',
            organizationId: '01a1dc0c-cfbd-7361-b269-f3c11d028694',
            administratorId: '01a1dc16-df76-72e1-9c4a-539339717911',
            locationId: '01a1dc18-9739-7374-8e8d-9408105f5893',
        );
    }

    public function matchesTarget(
        string $databaseName,
        string $organizationId,
        string $administratorId,
        string $locationId,
        BusinessRole $role,
    ): bool {
        return $this->databaseName === $databaseName
            && $this->organizationId === $organizationId
            && $this->administratorId === $administratorId
            && $this->locationId === $locationId
            && $this->eligibleRole === $role;
    }

    public function contentHash(): string
    {
        return hash('sha256', json_encode([
            'id' => $this->id,
            'version' => $this->version,
            'module' => $this->module,
            'permissions' => array_map(fn (RawMaterialPermission $permission): string => $permission->value, $this->permissions),
            'eligible_role' => $this->eligibleRole->value,
            'grant_scope' => $this->grantScope,
            'applicability' => $this->applicability,
            'database_name' => $this->databaseName,
            'organization_id' => $this->organizationId,
            'administrator_id' => $this->administratorId,
            'location_id' => $this->locationId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
