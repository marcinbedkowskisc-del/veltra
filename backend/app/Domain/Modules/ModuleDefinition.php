<?php

namespace App\Domain\Modules;

use App\Domain\Installation\LocationType;

final readonly class ModuleDefinition
{
    /**
     * @param  list<LocationType>  $locationTypes
     * @param  list<string>  $dependencies
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $permission,
        public array $locationTypes,
        public array $dependencies = [],
        public bool $ready = false,
    ) {}

    public function supports(LocationType $type): bool
    {
        return in_array($type, $this->locationTypes, true);
    }
}
