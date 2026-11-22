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
        public Permission|RawMaterialPermission|null $permission,
        public array $categories,
        public BusinessDataProvenance $provenance,
        public ?Location $target = null,
        public ?string $module = null,
        public ?BossRawCatalogIntent $intent = null,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $key)
            || ! in_array($operation, ['list', 'detail', 'search', 'export'], true)
            || count($categories) !== count(array_unique($categories, SORT_REGULAR))) {
            throw new InvalidArgumentException('Business visibility surfaces require stable keys and valid read contracts.');
        }
        if ($intent === null && $permission === null) {
            throw new InvalidArgumentException('Business visibility surfaces require a permission or a typed intent.');
        }
        if ($intent !== null && ($source !== BusinessVisibilitySource::Boss
            || $permission !== null
            || $operation !== $intent->value
            || $categories !== [BossVisibilityCategory::RawSuppliers]
            || $provenance->scope !== BusinessDataScope::Organization
            || $target !== null
            || $module !== null)) {
            throw new InvalidArgumentException('BOSS raw catalog intents require organization provenance and the registered category.');
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
