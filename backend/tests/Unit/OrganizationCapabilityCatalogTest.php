<?php

namespace Tests\Unit;

use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use PHPUnit\Framework\TestCase;

class OrganizationCapabilityCatalogTest extends TestCase
{
    public function test_boss_raw_catalog_capability_is_ready_and_limited_to_read_operations(): void
    {
        $catalog = new CodeOrganizationCapabilityCatalog;
        $definition = $catalog->find(CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ);

        $this->assertNotNull($definition);
        $this->assertSame('ORGANIZATION', $definition->scope);
        $this->assertTrue($definition->ready);
        $this->assertSame(['list', 'detail'], $definition->operations);
        $this->assertNull($catalog->find('UNRECOGNIZED_CAPABILITY'));
        $this->assertCount(1, $catalog->all());
    }
}
