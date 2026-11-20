<?php

namespace App\Domain\Modules;

use App\Domain\Installation\LocationType;

final readonly class OrganizationCapabilityDefinition
{
    /**
     * @param  list<LocationType>  $operatingContextTypes
     * @param  list<string>  $operations
     */
    public function __construct(
        public string $key,
        public string $scope,
        public bool $ready,
        public array $operatingContextTypes,
        public array $operations,
    ) {}
}
