<?php

namespace App\Domain\Authorization;

use App\Models\Location;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BossLocationRestrictions
{
    private function revisions(User $boss): Builder
    {
        return DB::table('boss_location_restriction_revisions')
            ->where('organization_id', $boss->organization_id)
            ->where('user_id', $boss->id)
            ->orderByDesc('version');
    }

    public function excludes(User $boss, Location $location): bool
    {
        return in_array(strtolower($location->id), $this->snapshot($boss)['excluded_location_ids'], true);
    }

    public function hasExclusions(User $boss): bool
    {
        $policy = $this->snapshot($boss);

        return $policy['excluded_location_ids'] !== [] || $policy['excluded_categories'] !== []
            || $policy['excluded_category_locations'] !== [];
    }

    /**
     * @return array{
     *   version: int,
     *   excluded_location_ids: list<string>,
     *   excluded_categories: list<string>,
     *   excluded_category_locations: list<array{category: string, location_id: string}>
     * }
     */
    private function snapshot(User $boss): array
    {
        $revision = $this->revisions($boss)->first([
            'version', 'excluded_locations', 'excluded_categories', 'excluded_category_locations',
        ]);
        if (! $revision) {
            return ['version' => 0, 'excluded_location_ids' => [], 'excluded_categories' => [], 'excluded_category_locations' => []];
        }
        $locations = $this->decodeList($revision->excluded_locations);
        $categories = $this->decodeList($revision->excluded_categories);
        $categoryLocations = json_decode($revision->excluded_category_locations, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($categoryLocations) || ! array_is_list($categoryLocations)) {
            throw new \UnexpectedValueException('Stored BOSS location-category restrictions are malformed.');
        }
        $pairs = [];
        foreach ($categoryLocations as $pair) {
            if (! is_array($pair) || ! isset($pair['category'], $pair['location_id'])
                || ! is_string($pair['category']) || ! BossVisibilityCategory::tryFrom($pair['category'])
                || ! is_string($pair['location_id']) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $pair['location_id'])) {
                throw new \UnexpectedValueException('Stored BOSS location-category restriction is invalid.');
            }
            $pairs[] = ['category' => $pair['category'], 'location_id' => strtolower($pair['location_id'])];
            if (! Location::where('organization_id', $boss->organization_id)->whereKey($pair['location_id'])->exists()) {
                throw new \UnexpectedValueException('Stored BOSS location-category restriction is invalid.');
            }
        }
        $this->assertUnique($locations, 'location');
        $this->assertUnique($categories, 'category');
        foreach ($locations as $locationId) {
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $locationId)
                || ! Location::where('organization_id', $boss->organization_id)->whereKey($locationId)->exists()) {
                throw new \UnexpectedValueException('Stored BOSS location restriction is invalid.');
            }
        }
        foreach ($categories as $category) {
            if (! BossVisibilityCategory::tryFrom($category)) {
                throw new \UnexpectedValueException('Stored BOSS category restriction is invalid.');
            }
        }
        if (count($pairs) !== count(array_unique(array_map(
            fn (array $pair): string => $pair['category'].':'.$pair['location_id'],
            $pairs,
        )))) {
            throw new \UnexpectedValueException('Stored BOSS location-category restrictions contain duplicates.');
        }

        return [
            'version' => (int) $revision->version,
            'excluded_location_ids' => $locations,
            'excluded_categories' => $categories,
            'excluded_category_locations' => $pairs,
        ];
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \UnexpectedValueException('Stored BOSS restriction list is malformed.');
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new \UnexpectedValueException('Stored BOSS restriction list is malformed.');
            }
        }

        return $value;
    }

    /** @param list<string> $values */
    private function assertUnique(array $values, string $kind): void
    {
        if (count($values) !== count(array_unique(array_map('strtolower', $values)))) {
            throw new \UnexpectedValueException("Stored BOSS {$kind} restrictions contain duplicates.");
        }
    }

    private function assertCanManage(User $actor, Location $context, User $boss, bool $updating): void
    {
        $authorization = app(BusinessAuthorization::class);
        $hasBossHistory = RoleAssignment::where('organization_id', $boss->organization_id)
            ->where('user_id', $boss->id)->where('role', BusinessRole::Boss)->exists();
        if ($actor->is($boss) || ! $authorization->eligible($actor, $context)
            || ! $authorization->hasRole($actor, BusinessRole::Administrator) || ! $hasBossHistory
            || ($updating && ! $authorization->hasRole($boss, BusinessRole::Boss))) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }
    }

    public function show(User $actor, Location $context, string $targetId): array
    {
        $boss = User::where('organization_id', $actor->organization_id)->findOrFail($targetId);
        $this->assertCanManage($actor, $context, $boss, false);

        return $this->snapshot($boss);
    }

    /**
     * @param  list<string>  $excludedLocations
     * @param  list<string>|null  $excludedCategories
     * @param  list<array{category: string, location_id: string}>|null  $excludedCategoryLocations
     */
    public function replace(
        User $actor,
        Location $context,
        string $targetId,
        int $version,
        array $excludedLocations,
        ?array $excludedCategories = null,
        ?array $excludedCategoryLocations = null,
    ): array {
        $boss = User::where('organization_id', $actor->organization_id)->findOrFail($targetId);
        $mutations = app(SecurityMutation::class);
        try {
            return $mutations->run($actor, $boss, null, 'boss.location_restrictions', function () use (
                $actor, $context, $boss, $version, $excludedLocations, $excludedCategories, $excludedCategoryLocations,
            ): array {
                $this->assertCanManage($actor, $context, $boss, true);
                $current = $this->snapshot($boss);
                if ($version !== $current['version']) {
                    throw new ConflictHttpException(__('messages.boss_restrictions_conflict'));
                }
                if (($excludedCategories === null) !== ($excludedCategoryLocations === null)) {
                    throw new \InvalidArgumentException('Category restrictions must be replaced together.');
                }
                $locations = $this->normalizeLocations($boss, $excludedLocations);
                $categories = $excludedCategories === null
                    ? $current['excluded_categories'] : $this->normalizeCategories($excludedCategories);
                $pairs = $excludedCategoryLocations === null
                    ? $current['excluded_category_locations'] : $this->normalizePairs($boss, $excludedCategoryLocations);
                if ($locations === $current['excluded_location_ids'] && $categories === $current['excluded_categories']
                    && $pairs === $current['excluded_category_locations']) {
                    return $current;
                }
                DB::table('boss_location_restriction_revisions')->insert([
                    'organization_id' => $boss->organization_id,
                    'user_id' => $boss->id,
                    'actor_id' => $actor->id,
                    'version' => $version + 1,
                    'previous_locations' => json_encode($current['excluded_location_ids'], JSON_THROW_ON_ERROR),
                    'excluded_locations' => json_encode($locations, JSON_THROW_ON_ERROR),
                    'previous_categories' => json_encode($current['excluded_categories'], JSON_THROW_ON_ERROR),
                    'excluded_categories' => json_encode($categories, JSON_THROW_ON_ERROR),
                    'previous_category_locations' => json_encode($current['excluded_category_locations'], JSON_THROW_ON_ERROR),
                    'excluded_category_locations' => json_encode($pairs, JSON_THROW_ON_ERROR),
                ]);

                return [
                    'version' => $version + 1,
                    'excluded_location_ids' => $locations,
                    'excluded_categories' => $categories,
                    'excluded_category_locations' => $pairs,
                ];
            });
        } catch (ConflictHttpException $exception) {
            $mutations->record($actor, $boss, null, 'boss.location_restrictions', 'rejected');
            throw $exception;
        }
    }

    /** @param list<string> $ids
     * @return list<string>
     */
    private function normalizeLocations(User $boss, array $ids): array
    {
        $locations = array_map(strtolower(...), $ids);
        sort($locations);
        $this->assertUniqueInput($locations);
        if ($locations !== [] && Location::where('organization_id', $boss->organization_id)->whereIn('id', $locations)->count() !== count($locations)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        return $locations;
    }

    /** @param list<string> $categories
     * @return list<string>
     */
    private function normalizeCategories(array $categories): array
    {
        $normalized = array_map(function (string $category): string {
            if (! BossVisibilityCategory::tryFrom($category)) {
                throw new \InvalidArgumentException('Unknown BOSS visibility category.');
            }

            return $category;
        }, $categories);
        sort($normalized);
        $this->assertUniqueInput($normalized);

        return $normalized;
    }

    /**
     * @param  list<array{category: string, location_id: string}>  $pairs
     * @return list<array{category: string, location_id: string}>
     */
    private function normalizePairs(User $boss, array $pairs): array
    {
        $normalized = [];
        foreach ($pairs as $pair) {
            if (! isset($pair['category'], $pair['location_id'])
                || ! BossVisibilityCategory::tryFrom($pair['category'])
                || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $pair['location_id'])) {
                throw new \InvalidArgumentException('Invalid BOSS category-location restriction.');
            }
            $normalized[] = ['category' => $pair['category'], 'location_id' => strtolower($pair['location_id'])];
        }
        usort($normalized, fn (array $left, array $right): int => [$left['category'], $left['location_id']] <=> [$right['category'], $right['location_id']]);
        $keys = array_map(fn (array $pair): string => $pair['category'].':'.$pair['location_id'], $normalized);
        $this->assertUniqueInput($keys);
        $locationIds = array_values(array_unique(array_column($normalized, 'location_id')));
        if ($locationIds !== []
            && Location::where('organization_id', $boss->organization_id)->whereIn('id', $locationIds)->count() !== count($locationIds)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        return $normalized;
    }

    /** @param list<string> $values */
    private function assertUniqueInput(array $values): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw new \InvalidArgumentException('Duplicate BOSS restriction entries are not allowed.');
        }
    }

    /**
     * @param  list<BossVisibilityCategory>  $categories
     */
    public function denies(User $boss, BusinessDataProvenance $provenance, array $categories): bool
    {
        if ($provenance->scope === BusinessDataScope::Unknown) {
            return true;
        }
        $policy = $this->snapshot($boss);
        foreach ($categories as $category) {
            if (! $category instanceof BossVisibilityCategory) {
                return true;
            }
            if (in_array($category->value, $policy['excluded_categories'], true)) {
                return true;
            }
        }
        if ($provenance->scope === BusinessDataScope::Organization) {
            return false;
        }
        foreach ($provenance->locationIds as $locationId) {
            if (in_array(strtolower($locationId), $policy['excluded_location_ids'], true)) {
                return true;
            }
            foreach ($categories as $category) {
                if (in_array(['category' => $category->value, 'location_id' => strtolower($locationId)], $policy['excluded_category_locations'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function visibleLocations(User $boss, Location $context): Builder
    {
        $authorization = app(BusinessAuthorization::class);
        if (! $authorization->eligible($boss, $context) || ! $authorization->hasRole($boss, BusinessRole::Boss)) {
            throw new AuthorizationException(__('messages.unauthorized'));
        }

        $excluded = $this->revisions($boss)->select('excluded_locations')->limit(1);

        return DB::table('locations')->where('organization_id', $boss->organization_id)->where('is_active', true)
            ->whereRaw('NOT (coalesce(('.$excluded->toSql()."), '[]'::jsonb) @> jsonb_build_array(locations.id))", $excluded->getBindings());
    }
}
