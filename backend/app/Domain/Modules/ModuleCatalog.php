<?php

namespace App\Domain\Modules;

interface ModuleCatalog
{
    /**
     * @return array<string, ModuleDefinition>
     */
    public function all(): array;

    public function find(string $key): ?ModuleDefinition;
}
