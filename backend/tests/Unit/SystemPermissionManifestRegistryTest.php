<?php

namespace Tests\Unit;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\SystemPermissionManifestRegistry;
use App\Domain\RawMaterials\RawMaterialPermission;
use PHPUnit\Framework\TestCase;

class SystemPermissionManifestRegistryTest extends TestCase
{
    public function test_registry_limits_the_approved_upgrade_to_its_explicit_scope(): void
    {
        $registry = new SystemPermissionManifestRegistry;
        $manifest = $registry->find('stage6a1-raw-administrator', 1);

        $this->assertNotNull($manifest);
        $this->assertSame('RAW_MATERIALS', $manifest->module);
        $this->assertSame([RawMaterialPermission::View, RawMaterialPermission::Create], $manifest->permissions);
        $this->assertSame('ORGANIZATION', $manifest->grantScope);
        $this->assertSame('UPGRADE', $manifest->applicability);
        $this->assertSame(BusinessRole::Administrator, $manifest->eligibleRole);
        $this->assertSame(
            'a19431a27e57e841214e5b26b7a3ab9a61f904e6007366c7bd6caa9407b79583',
            $manifest->contentHash(),
            'Changing an approved manifest requires a new version and approval.',
        );
        $this->assertSame($manifest->contentHash(), $registry->find($manifest->id, $manifest->version)->contentHash());
        $this->assertNull($registry->find($manifest->id, 2));
        $this->assertNull($registry->find('unregistered', 1));
    }

    public function test_manifest_rejects_unexpected_installation_targets(): void
    {
        $manifest = (new SystemPermissionManifestRegistry)->find('stage6a1-raw-administrator', 1);
        $this->assertNotNull($manifest);

        $this->assertTrue($manifest->matchesTarget(
            $manifest->databaseName, $manifest->organizationId, $manifest->administratorId,
            $manifest->locationId, BusinessRole::Administrator,
        ));
        $this->assertFalse($manifest->matchesTarget(
            'another_database', $manifest->organizationId, $manifest->administratorId,
            $manifest->locationId, BusinessRole::Administrator,
        ));
        $this->assertFalse($manifest->matchesTarget(
            $manifest->databaseName, 'another_organization', $manifest->administratorId,
            $manifest->locationId, BusinessRole::Administrator,
        ));
        $this->assertFalse($manifest->matchesTarget(
            $manifest->databaseName, $manifest->organizationId, 'another_user',
            $manifest->locationId, BusinessRole::Administrator,
        ));
        $this->assertFalse($manifest->matchesTarget(
            $manifest->databaseName, $manifest->organizationId, $manifest->administratorId,
            'another_location', BusinessRole::Administrator,
        ));
        $this->assertFalse($manifest->matchesTarget(
            $manifest->databaseName, $manifest->organizationId, $manifest->administratorId,
            $manifest->locationId, BusinessRole::Boss,
        ));
    }
}
