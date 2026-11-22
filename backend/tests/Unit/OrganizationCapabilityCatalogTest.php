<?php

namespace Tests\Unit;

use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use PHPUnit\Framework\TestCase;

class OrganizationCapabilityCatalogTest extends TestCase
{
    public function test_boss_raw_catalog_capability_is_closed_and_not_ready(): void
    {
        $catalog = new CodeOrganizationCapabilityCatalog;
        $definition = $catalog->find(CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ);

        $this->assertNotNull($definition);
        $this->assertSame('ORGANIZATION', $definition->scope);
        $this->assertFalse($definition->ready);
        $this->assertSame(['list', 'detail'], $definition->operations);
        $this->assertNull($catalog->find('UNRECOGNIZED_CAPABILITY'));
        $this->assertCount(1, $catalog->all());
    }
}
