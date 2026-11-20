<?php

namespace App\Domain\Modules;

interface OrganizationCapabilityCatalog
{
    public function find(string $key): ?OrganizationCapabilityDefinition;

    /** @return list<OrganizationCapabilityDefinition> */
    public function all(): array;
}
