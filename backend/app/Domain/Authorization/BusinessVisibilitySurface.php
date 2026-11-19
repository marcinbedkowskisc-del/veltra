<?php

namespace App\Domain\Authorization;

use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Location;
use InvalidArgumentException;

final readonly class BusinessVisibilitySurface
{
    /**
     * @param  list<BossVisibilityCategory>  $categories
     */
    public function __construct(
        public string $key,
        public BusinessVisibilitySource $source,
        public string $operation,
        public Permission|RawMaterialPermission $permission,
        public array $categories,
        public BusinessDataProvenance $provenance,
        public ?Location $target = null,
        public ?string $module = null,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $key)
            || ! in_array($operation, ['list', 'detail', 'search', 'export'], true)
            || count($categories) !== count(array_unique($categories, SORT_REGULAR))) {
            throw new InvalidArgumentException('Business visibility surfaces require stable keys and valid read contracts.');
        }
        foreach ($categories as $category) {
            if (! $category instanceof BossVisibilityCategory) {
                throw new InvalidArgumentException('Business visibility surface category must be registered.');
            }
        }
        if ($module !== null && ! preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D', $module)) {
            throw new InvalidArgumentException('Business visibility surface module key is invalid.');
        }
    }
}
