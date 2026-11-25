<?php

namespace Tests\Feature;

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BossVisibilityCategory;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessDataProvenance;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\BusinessVisibility;
use App\Domain\Authorization\BusinessVisibilitySource;
use App\Domain\Authorization\BusinessVisibilitySurface;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Authorization\SecurityMutation;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;

class BossLocationRestrictionsTest extends AuthorizationTestCase
{
    private function selectContext(User $user, Location $location): void
    {
        $memberships = app(MembershipService::class);
        if (! $memberships->available($user)->where('location_id', $location->id)->exists()) {
            $memberships->assign($user, $location);
        }
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ])->assertOk();
    }

    public function test_boss_recipe_read_includes_central_and_future_restaurants_but_honors_category_restrictions(): void
    {
        $restaurant = $this->activeRestaurant();
        $future = $this->activeRestaurant('R2');
        $kitchen = $this->primaryCentralKitchen();
        $boss = $this->provisionedUser('boss-recipes@example.test', BusinessRole::Boss);
        $admin = $this->provisionedUser('boss-recipes-admin@example.test', BusinessRole::Administrator);
        app(MembershipService::class)->assign($boss, $restaurant);
        app(MembershipService::class)->assign($admin, $restaurant);
        $authorization = app(BusinessAuthorization::class);
        foreach ([$restaurant, $future, $kitchen] as $location) {
            $this->assertTrue($authorization->allows($boss, $restaurant, $location, Permission::RecipesView));
            $this->assertTrue($authorization->allows($boss, $restaurant, $location, Permission::QuantityCalculate));
            $this->assertFalse($authorization->allows($boss, $restaurant, $location, Permission::PersonnelManage));
        }
        app(BossLocationRestrictions::class)->replace(
            $admin, $restaurant, $boss->id, 0, [], [BossVisibilityCategory::ProductsRecipes->value], [],
        );
        foreach ([$restaurant, $future, $kitchen] as $location) {
            $this->assertFalse($authorization->allows($boss, $restaurant, $location, Permission::RecipesView));
            $this->assertFalse($authorization->allows($boss, $restaurant, $location, Permission::QuantityCalculate));
            $this->assertTrue($authorization->allows($boss, $restaurant, $location, Permission::LocationView));
        }
    }

    public function test_boss_directory_applies_individual_restrictions_before_filtering_and_keeps_other_roles_local(): void
    {
        $restaurant = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $kitchen = $this->primaryCentralKitchen();
        $boss = $this->provisionedUser('boss@example.test', BusinessRole::Boss);
        $admin = $this->provisionedUser('admin@example.test', BusinessRole::Administrator);
        $this->selectContext($boss, $restaurant);
        $this->getJson('/api/v1/security/boss/locations')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonFragment(['id' => $kitchen->id])->assertJsonMissingPath('data.0.organization_id');
        $this->getJson("/api/v1/security/access?location_id={$kitchen->id}&permission=LOCATION_VIEW")
            ->assertOk()->assertJsonPath('data.allowed', true);
        $path = "/api/v1/administration/users/{$boss->id}/boss-location-restrictions";
        $this->getJson($path)->assertForbidden();
        $this->putJson($path, ['version' => 0, 'excluded_location_ids' => []])->assertForbidden();
        $this->patchJson("/api/v1/security/locations/{$second->id}", ['name' => 'Changed'])->assertForbidden();

        $this->selectContext($admin, $restaurant);
        $this->getJson($path)->assertOk()->assertJsonPath('data.version', 0);
        $this->putJson($path, ['version' => 0, 'excluded_location_ids' => [Str::uuid()->toString()]])->assertForbidden();
        $this->putJson($path, ['version' => 0, 'excluded_location_ids' => [$restaurant->id, $kitchen->id]])
            ->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson($path, ['version' => 0, 'excluded_location_ids' => []])->assertConflict();
        $this->assertDatabaseHas('security_events', [
            'actor_id' => $admin->id, 'target_id' => $boss->id,
            'action' => 'boss.location_restrictions', 'outcome' => 'accepted',
        ]);
        $this->assertDatabaseHas('security_events', [
            'actor_id' => $admin->id, 'target_id' => $boss->id,
            'action' => 'boss.location_restrictions', 'outcome' => 'rejected',
        ]);
        $this->assertDatabaseHas('boss_location_restriction_revisions', [
            'user_id' => $boss->id, 'actor_id' => $admin->id, 'version' => 1,
        ]);

        $this->selectContext($boss, $restaurant);
        $this->getJson('/api/v1/security/boss/locations?type=CENTRAL_KITCHEN')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/security/access?location_id={$kitchen->id}&permission=LOCATION_VIEW")
            ->assertOk()->assertJsonPath('data.allowed', false);
        $this->getJson('/api/v1/security/boss/locations?type=RESTAURANT')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $local = "/api/v1/security/access?location_id={$restaurant->id}&permission=LOCATION_VIEW";
        $this->getJson($local)->assertOk()->assertJsonPath('data.allowed', false);
        $membership = $boss->memberships()->where('location_id', $restaurant->id)->where('is_active', true)->firstOrFail();
        $this->assertDatabaseRejects('23P01', fn (Connection $connection) => $connection->table('role_assignments')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $boss->organization_id,
            'user_id' => $boss->id,
            'role' => BusinessRole::RestaurantManager->value,
            'location_id' => $restaurant->id,
            'membership_id' => $membership->id,
        ]));
        $this->getJson($local)->assertOk()->assertJsonPath('data.allowed', false);
        $this->getJson("/api/v1/security/access?location_id={$second->id}&permission=PURCHASE_PRICES_VIEW")
            ->assertOk()->assertJsonPath('data.allowed', true);
        $this->getJson("/api/v1/security/access?location_id={$second->id}&permission=INVENTORY_COUNT_ENTRY")
            ->assertOk()->assertJsonPath('data.allowed', false);

        $this->selectContext($admin, $restaurant);
        $this->putJson($path, ['version' => 1, 'excluded_location_ids' => []])->assertOk()->assertJsonPath('data.version', 2);
        $this->selectContext($boss, $restaurant);
        $this->getJson('/api/v1/security/boss/locations')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_category_location_policy_is_cumulative_and_rejects_invalid_api_values(): void
    {
        $restaurant = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $kitchen = $this->primaryCentralKitchen();
        $boss = $this->provisionedUser('boss-policy@example.test', BusinessRole::Boss);
        $admin = $this->provisionedUser('admin-policy@example.test', BusinessRole::Administrator);
        $this->selectContext($admin, $restaurant);
        $path = "/api/v1/administration/users/{$boss->id}/boss-location-restrictions";

        $this->putJson($path, [
            'version' => 0,
            'excluded_location_ids' => [],
            'policy_schema_version' => 2,
            'excluded_categories' => ['NOT_A_CATEGORY'],
            'excluded_category_locations' => [],
        ])->assertUnprocessable();
        $this->putJson($path, [
            'version' => 0,
            'excluded_location_ids' => [],
            'policy_schema_version' => 2,
            'excluded_categories' => [],
            'excluded_category_locations' => [
                ['category' => 'REPORTS_ANALYTICS', 'location_id' => $second->id],
                ['category' => 'REPORTS_ANALYTICS', 'location_id' => $second->id],
            ],
        ])->assertUnprocessable();

        $this->putJson($path, [
            'version' => 0,
            'excluded_location_ids' => [$restaurant->id],
            'policy_schema_version' => 2,
            'excluded_categories' => [BossVisibilityCategory::Foodcost->value],
            'excluded_category_locations' => [[
                'category' => BossVisibilityCategory::ReportsAnalytics->value,
                'location_id' => $second->id,
            ]],
        ])->assertOk()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.excluded_categories.0', BossVisibilityCategory::Foodcost->value)
            ->assertJsonPath('data.excluded_category_locations.0.location_id', $second->id);

        $this->selectContext($boss, $restaurant);
        $authorization = app(BusinessVisibility::class);
        $this->assertFalse($authorization->decide($boss, $restaurant, new BusinessVisibilitySurface(
            'boss.test.foodcost',
            BusinessVisibilitySource::Boss,
            'detail',
            Permission::LocationView,
            [BossVisibilityCategory::Foodcost],
            BusinessDataProvenance::location($second->id),
            $second,
        ))->allowed);
        $this->assertFalse(app(BusinessAuthorization::class)
            ->allows($boss, $restaurant, $restaurant, Permission::LocationView));
        $this->assertTrue(app(BusinessAuthorization::class)
            ->allows($boss, $restaurant, $second, Permission::LocationView));
        $this->assertTrue(app(BusinessAuthorization::class)
            ->allows($boss, $restaurant, $kitchen, Permission::ReportsView));
    }

    public function test_legacy_location_only_update_preserves_category_policy_and_unknown_locations_are_rejected(): void
    {
        $restaurant = $this->activeRestaurant();
        $boss = $this->provisionedUser('boss-legacy-policy@example.test', BusinessRole::Boss);
        $admin = $this->provisionedUser('admin-legacy-policy@example.test', BusinessRole::Administrator);
        $this->selectContext($admin, $restaurant);
        $path = "/api/v1/administration/users/{$boss->id}/boss-location-restrictions";
        $pair = [
            'category' => BossVisibilityCategory::ReportsAnalytics->value,
            'location_id' => $restaurant->id,
        ];

        $this->putJson($path, [
            'version' => 0,
            'excluded_location_ids' => [],
            'policy_schema_version' => 2,
            'excluded_categories' => [BossVisibilityCategory::Foodcost->value],
            'excluded_category_locations' => [$pair],
        ])->assertOk();
        $this->putJson($path, [
            'version' => 1,
            'excluded_location_ids' => [$restaurant->id],
        ])->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.excluded_categories.0', BossVisibilityCategory::Foodcost->value)
            ->assertJsonPath('data.excluded_category_locations.0.category', BossVisibilityCategory::ReportsAnalytics->value);

        $unknownLocationId = Str::uuid()->toString();
        $this->putJson($path, [
            'version' => 2,
            'excluded_location_ids' => [$unknownLocationId],
            'policy_schema_version' => 2,
            'excluded_categories' => [],
            'excluded_category_locations' => [],
        ])->assertForbidden();
        $this->putJson($path, [
            'version' => 2,
            'excluded_location_ids' => [],
            'policy_schema_version' => 2,
            'excluded_categories' => [],
            'excluded_category_locations' => [[
                'category' => BossVisibilityCategory::ReportsAnalytics->value,
                'location_id' => $unknownLocationId,
            ]],
        ])->assertForbidden();

        $unknownPolicy = $this->revisionRow($boss, $admin);
        $unknownPolicy['organization_id'] = Str::uuid()->toString();
        $unknownPolicy['version'] = 1;
        $this->assertRuntimeDatabaseRejects('23503', fn () => app(SecurityMutation::class)->run(
            $admin,
            $boss,
            null,
            'boss.location_restrictions',
            fn () => DB::table('boss_location_restriction_revisions')->insert($unknownPolicy),
        ));
    }

    public function test_restrictions_survive_boss_role_removal_and_regrant(): void
    {
        $restaurant = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $boss = $this->provisionedUser('boss-regrant@example.test', BusinessRole::Boss);
        $admin = $this->provisionedUser('admin-regrant@example.test', BusinessRole::Administrator);
        $this->selectContext($admin, $restaurant);
        app(MembershipService::class)->assign($boss, $restaurant);
        $path = "/api/v1/administration/users/{$boss->id}/boss-location-restrictions";
        $this->putJson($path, [
            'version' => 0,
            'excluded_location_ids' => [$restaurant->id],
        ])->assertOk();

        app(RoleAdministration::class)->replace($admin, $restaurant, $boss, BusinessRole::Employee, [$restaurant->id]);
        $this->getJson($path)->assertOk()->assertJsonPath('data.excluded_location_ids.0', $restaurant->id);
        $this->selectContext($boss, $restaurant);
        $this->getJson('/api/v1/security/boss/locations')->assertForbidden();
        app(RoleAdministration::class)->replace($admin, $restaurant, $boss, BusinessRole::Boss, []);

        $this->selectContext($boss, $restaurant);
        $locations = $this->getJson('/api/v1/security/boss/locations')->assertOk()
            ->assertJsonCount(2, 'data')->json('data');
        $this->assertNotContains($restaurant->id, array_column($locations, 'id'));
        $this->assertContains($second->id, array_column($locations, 'id'));
        $this->assertDatabaseHas('boss_location_restriction_revisions', [
            'user_id' => $boss->id,
            'version' => 1,
        ]);
        $this->assertSame(
            json_encode([$restaurant->id], JSON_THROW_ON_ERROR),
            DB::table('boss_location_restriction_revisions')->where('user_id', $boss->id)->value('excluded_locations'),
        );
    }

    public function test_revision_trigger_rejects_unsigned_mismatched_and_malformed_writes_and_history_is_append_only(): void
    {
        $restaurant = $this->activeRestaurant();
        $boss = $this->provisionedUser('boss-trigger@example.test', BusinessRole::Boss);
        $admin = $this->provisionedUser('admin-trigger@example.test', BusinessRole::Administrator);
        $this->selectContext($admin, $restaurant);
        $this->putJson("/api/v1/administration/users/{$boss->id}/boss-location-restrictions", [
            'version' => 0,
            'excluded_location_ids' => [$restaurant->id],
        ])->assertOk();

        $row = $this->revisionRow($boss, $admin);
        $row['previous_locations'] = json_encode([$restaurant->id], JSON_THROW_ON_ERROR);
        $this->assertRuntimeDatabaseRejects('42501', fn (Connection $connection) => $connection
            ->table('boss_location_restriction_revisions')->insert($row));

        $mismatchedActor = $row;
        $mismatchedActor['actor_id'] = $boss->id;
        $this->assertRuntimeDatabaseRejects('42501', fn () => app(SecurityMutation::class)->run(
            $admin,
            $boss,
            null,
            'boss.location_restrictions',
            fn () => DB::table('boss_location_restriction_revisions')->insert($mismatchedActor),
        ));

        foreach ([
            ['excluded_categories' => '[null]'],
            ['excluded_category_locations' => '[{"category":null,"location_id":"'.$restaurant->id.'"}]'],
            ['excluded_locations' => '[null]'],
        ] as $invalid) {
            $malformed = array_replace($row, $invalid);
            $this->assertRuntimeDatabaseRejects('23514', fn () => app(SecurityMutation::class)->run(
                $admin,
                $boss,
                null,
                'boss.location_restrictions',
                fn () => DB::table('boss_location_restriction_revisions')->insert($malformed),
            ));
        }

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('boss_location_restriction_revisions')->where('user_id', $boss->id)->update([
                'excluded_locations' => '[]',
            ]));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection
            ->table('boss_location_restriction_revisions')->where('user_id', $boss->id)->delete());
    }

    private function revisionRow(User $boss, User $actor): array
    {
        return [
            'organization_id' => $boss->organization_id,
            'user_id' => $boss->id,
            'actor_id' => $actor->id,
            'version' => 2,
            'previous_locations' => json_encode([], JSON_THROW_ON_ERROR),
            'excluded_locations' => json_encode([], JSON_THROW_ON_ERROR),
            'previous_categories' => json_encode([], JSON_THROW_ON_ERROR),
            'excluded_categories' => json_encode([], JSON_THROW_ON_ERROR),
            'previous_category_locations' => json_encode([], JSON_THROW_ON_ERROR),
            'excluded_category_locations' => json_encode([], JSON_THROW_ON_ERROR),
        ];
    }
}
