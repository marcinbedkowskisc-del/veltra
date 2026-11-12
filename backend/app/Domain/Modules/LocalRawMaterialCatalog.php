<?php

namespace App\Domain\Modules;

use App\Domain\Installation\LocalStage6a1Instance;

final class LocalRawMaterialCatalog implements ModuleCatalog
{
    public function __construct(private LocalStage6a1Instance $instance) {}

    public function all(): array
    {
        $catalog = new DefinedModuleCatalog;

        return ($this->instance->rawIntegrationEnabled() ? $catalog->withReadyRawMaterials() : $catalog)->all();
    }

    public function find(string $key): ?ModuleDefinition
    {
        return $this->all()[$key] ?? null;
    }
}
