<?php

namespace Tests\Feature;

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

    public function test_boss_directory_applies_individual_restrictions_before_filtering_and_keeps_other_roles_local(): void
    {
        $restaurant = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $kitchen = $this->primaryCentralKitchen();
        $boss = $this->user('boss@example.test');
        $admin = $this->user('admin@example.test');
        $this->role($boss, BusinessRole::Boss);
        $this->role($admin, BusinessRole::Administrator);
        $this->selectContext($boss, $restaurant);
        $this->getJson('/api/v1/security/boss/locations')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonFragment(['id' => $kitchen->id])->assertJsonMissingPath('data.0.organization_id');
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
        $this->getJson('/api/v1/security/boss/locations?type=RESTAURANT')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $local = "/api/v1/security/access?location_id={$restaurant->id}&permission=LOCATION_VIEW";
        $this->getJson($local)->assertOk()->assertJsonPath('data.allowed', false);
        $this->role($boss, BusinessRole::RestaurantManager, $restaurant);
        $this->getJson($local)->assertOk()->assertJsonPath('data.allowed', true);
        $this->getJson("/api/v1/security/access?location_id={$second->id}&permission=PURCHASE_PRICES_VIEW")
            ->assertOk()->assertJsonPath('data.allowed', false);
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
        $boss = $this->user('boss-policy@example.test');
        $admin = $this->user('admin-policy@example.test');
        $this->role($boss, BusinessRole::Boss);
        $this->role($admin, BusinessRole::Administrator);
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
        $this->assertFalse(app(BusinessAuthorization::class)
            ->allows($boss, $restaurant, $kitchen, Permission::ReportsView));
    }

    public function test_legacy_location_only_update_preserves_category_policy_and_foreign_locations_are_rejected(): void
    {
        $restaurant = $this->activeRestaurant();
        $boss = $this->user('boss-legacy-policy@example.test');
        $admin = $this->user('admin-legacy-policy@example.test');
        $this->role($boss, BusinessRole::Boss);
        $this->role($admin, BusinessRole::Administrator);
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

        $this->withForeignOrganizationFixture(function (Location $foreignLocation) use ($path, $boss, $admin): void {
            $this->putJson($path, [
                'version' => 2,
                'excluded_location_ids' => [$foreignLocation->id],
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
                    'location_id' => $foreignLocation->id,
                ]],
            ])->assertForbidden();

            $foreignPolicy = $this->revisionRow($boss, $admin);
            $foreignPolicy['organization_id'] = $foreignLocation->organization_id;
            $foreignPolicy['version'] = 1;
            $this->assertRuntimeDatabaseRejects('23503', fn () => app(SecurityMutation::class)->run(
                $admin,
                $boss,
                null,
                'boss.location_restrictions',
                fn () => DB::table('boss_location_restriction_revisions')->insert($foreignPolicy),
            ));
        });
    }

    public function test_restrictions_survive_boss_role_removal_and_regrant(): void
    {
        $restaurant = $this->activeRestaurant();
        $second = $this->activeRestaurant('R2');
        $boss = $this->user('boss-regrant@example.test');
        $admin = $this->user('admin-regrant@example.test');
        $bossRole = $this->role($boss, BusinessRole::Boss);
        $this->role($admin, BusinessRole::Administrator);
        $this->selectContext($admin, $restaurant);
        $path = "/api/v1/administration/users/{$boss->id}/boss-location-restrictions";
        $this->putJson($path, [
            'version' => 0,
            'excluded_location_ids' => [$restaurant->id],
        ])->assertOk();

        app(RoleAdministration::class)->revoke($admin, $restaurant, $bossRole);
        $this->getJson($path)->assertOk()->assertJsonPath('data.excluded_location_ids.0', $restaurant->id);
        $this->selectContext($boss, $restaurant);
        $this->getJson('/api/v1/security/boss/locations')->assertForbidden();
        app(RoleAdministration::class)->grant($admin, $restaurant, $boss, BusinessRole::Boss, null);

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
        $boss = $this->user('boss-trigger@example.test');
        $admin = $this->user('admin-trigger@example.test');
        $this->role($boss, BusinessRole::Boss);
        $this->role($admin, BusinessRole::Administrator);
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
