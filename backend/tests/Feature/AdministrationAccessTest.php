<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Authorization\ModuleAvailability;
use App\Domain\Authorization\RoleAdministration;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationTestCase;

class AdministrationAccessTest extends AuthorizationTestCase
{
    private function selectContext(User $user, Location $location): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ])->assertOk();
    }

    public function test_active_administrator_can_access_pa_independently_of_raw(): void
    {
        $location = $this->kitchen();
        $admin = $this->user('admin@example.test');
        $this->role($admin, BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $this->selectContext($admin, $location);

        $this->assertFalse(app(ModuleAvailability::class)->enabled($location, 'RAW_MATERIALS'));
        $this->getJson('/api/v1/administration/access')->assertOk()
            ->assertExactJson(['data' => ['allowed' => true]])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/administration/categories')->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/administration/units')->assertOk()
            ->assertJsonCount(6, 'data');
        foreach ([
            ['kg', 'g', '1000.000000000000'],
            ['g', 'kg', '0.001000000000'],
            ['l', 'ml', '1000.000000000000'],
            ['ml', 'l', '0.001000000000'],
        ] as [$source, $target, $factor]) {
            $sourceUnit = collect($this->getJson('/api/v1/administration/units?search='.$source)
                ->assertOk()->json('data'))->firstWhere('code', $source);
            $conversion = collect($sourceUnit['conversions'])->firstWhere('unit_code', $target);
            $this->assertSame($factor, $conversion['factor']);
        }
        $this->postJson('/api/v1/administration/categories', [
            'code' => 'ADMIN_ONLY',
            'name' => 'Kategoria administracyjna',
        ])->assertCreated()->assertJsonPath('data.depth', 1);
    }

    public function test_administrator_can_manage_shared_units_and_reject_invalid_factors(): void
    {
        $location = $this->kitchen();
        $admin = $this->user('unit-admin@example.test');
        $this->role($admin, BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $this->selectContext($admin, $location);

        $this->postJson('/api/v1/administration/units', [
            'code' => 'mg_custom',
            'name' => 'Miligram testowy',
            'symbol' => 'mg*',
            'dimension' => 'MASS',
            'base_unit_code' => 'g',
            'to_base_factor' => '0.001',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'mg_custom')
            ->assertJsonPath('data.to_base_factor', '0.001000000000');

        $unitId = DB::table('raw_material_units')->where('code', 'mg_custom')->value('id');
        $unitsResponse = $this->getJson('/api/v1/administration/units?dimension=MASS&search=custom')
            ->assertOk()->assertJsonPath('data.0.code', 'mg_custom');
        $conversionToGrams = collect($unitsResponse->json('data.0.conversions'))
            ->firstWhere('unit_code', 'g');
        $this->assertSame('0.001000000000', $conversionToGrams['factor']);

        $this->patchJson('/api/v1/administration/units/'.$unitId, [
            'name' => 'Miligram niestandardowy',
            'symbol' => 'mgx',
            'dimension' => 'MASS',
            'base_unit_code' => 'g',
            'to_base_factor' => '0.002',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Miligram niestandardowy')
            ->assertJsonPath('data.to_base_factor', '0.002000000000');
        $gramUnit = collect($this->getJson('/api/v1/administration/units?search=gram')
            ->assertOk()->json('data'))->firstWhere('code', 'g');
        $conversionToCustomUnit = collect($gramUnit['conversions'])->firstWhere('unit_code', 'mg_custom');
        $this->assertSame('500.000000000000', $conversionToCustomUnit['factor']);

        $this->postJson('/api/v1/administration/units', [
            'code' => 'zero_unit',
            'name' => 'Nieprawidłowa jednostka',
            'symbol' => 'x',
            'dimension' => 'MASS',
            'base_unit_code' => 'g',
            'to_base_factor' => '0',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/administration/units/'.$unitId.'/archive')
            ->assertOk()->assertJsonPath('data.archived_at', fn ($value) => is_string($value));
        $this->getJson('/api/v1/administration/units?status=archived&search=custom')
            ->assertOk()->assertJsonPath('data.0.code', 'mg_custom');
        $this->postJson('/api/v1/administration/units/'.$unitId.'/restore')
            ->assertOk()->assertJsonPath('data.archived_at', null);

        $this->assertSame('0.002000000000', (string) DB::table('raw_material_units')
            ->where('id', $unitId)->value('to_base_factor'));
    }

    public function test_employee_manager_and_boss_are_denied_and_manager_personnel_access_remains(): void
    {
        $location = $this->activeRestaurant();
        foreach ([BusinessRole::Employee, BusinessRole::RestaurantManager, BusinessRole::Boss] as $role) {
            $user = $this->user(strtolower($role->value).'@example.test');
            $this->role($user, $role, $role->organizationWide() ? null : $location);
            if ($role->organizationWide()) {
                app(MembershipService::class)->assign($user, $location);
            }
            $this->selectContext($user, $location);
            $this->getJson('/api/v1/administration/access')->assertForbidden();
            $this->getJson('/api/v1/administration/categories')->assertForbidden();
            $this->getJson('/api/v1/administration/units')->assertForbidden();
            $this->getJson('/api/v1/administration/allergens/dictionaries')->assertForbidden();
            if ($role === BusinessRole::RestaurantManager) {
                $this->getJson("/api/v1/security/locations/{$location->id}/users")->assertOk();
            }
        }
    }

    public function test_unauthenticated_and_missing_context_are_rejected(): void
    {
        $this->getJson('/api/v1/administration/access')->assertUnauthorized();
        $this->getJson('/api/v1/administration/categories')->assertUnauthorized();
        $this->getJson('/api/v1/administration/units')->assertUnauthorized();
        $this->getJson('/api/v1/administration/allergens/dictionaries')->assertUnauthorized();
        $admin = $this->user('admin@example.test');
        $this->role($admin, BusinessRole::Administrator);
        $this->actingAs($admin, 'web')->getJson('/api/v1/administration/access')->assertForbidden();
        $this->getJson('/api/v1/administration/categories')->assertForbidden();
        $this->getJson('/api/v1/administration/units')->assertForbidden();
        $this->getJson('/api/v1/administration/allergens/dictionaries')->assertForbidden();
    }

    public function test_category_hierarchy_archive_restore_and_raw_references_are_preserved(): void
    {
        $location = $this->kitchen();
        $admin = $this->user('category-admin@example.test');
        $this->role($admin, BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $this->selectContext($admin, $location);

        $root = $this->postJson('/api/v1/administration/categories', [
            'code' => 'F10_ROOT',
            'name' => 'Surowce',
            'description' => 'Opis główny',
        ])->assertCreated()
            ->assertJsonPath('data.description', 'Opis główny')
            ->json('data');
        $child = $this->postJson('/api/v1/administration/categories', [
            'parent_id' => $root['id'],
            'code' => 'F10_CHILD',
            'name' => 'Zboża',
        ])->assertCreated()
            ->assertJsonPath('data.parent_id', $root['id'])
            ->assertJsonPath('data.depth', 2)
            ->json('data');

        $this->getJson('/api/v1/administration/categories?parent_id='.$root['id'])
            ->assertOk()->assertJsonPath('data.0.id', $child['id']);
        $this->patchJson('/api/v1/administration/categories/'.$child['id'], [
            'name' => 'Zboża i produkty zbożowe',
            'description' => 'Opis podkategorii',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Zboża i produkty zbożowe')
            ->assertJsonPath('data.description', 'Opis podkategorii');
        $otherRoot = $this->postJson('/api/v1/administration/categories', [
            'code' => 'F10_OTHER',
            'name' => 'Inna kategoria',
        ])->assertCreated()->json('data');
        $this->patchJson('/api/v1/administration/categories/'.$child['id'], [
            'name' => 'Zboża i produkty zbożowe',
            'parent_id' => $otherRoot['id'],
        ])->assertUnprocessable();
        $this->getJson('/api/v1/administration/categories?parent_id='.$root['id'])
            ->assertOk()->assertJsonPath('data.0.id', $child['id']);
        $this->postJson('/api/v1/administration/categories', [
            'code' => 'F10_ROOT',
            'name' => 'Duplikat',
        ])->assertUnprocessable();
        $this->getJson('/api/v1/administration/categories?search=produkty')
            ->assertOk()->assertJsonPath('data.0.path.0', 'Surowce')
            ->assertJsonPath('data.0.path.1', 'Zboża i produkty zbożowe');

        $this->postJson('/api/v1/administration/categories/'.$root['id'].'/archive')
            ->assertStatus(409)->assertJsonPath('error.code', 'RAW_MATERIAL_CATEGORY_HAS_ACTIVE_DESCENDANTS');
        $this->postJson('/api/v1/administration/categories/'.$child['id'].'/archive')
            ->assertOk()->assertJsonPath('data.archived_at', fn ($value) => is_string($value));
        $this->postJson('/api/v1/administration/categories/'.$root['id'].'/archive')->assertOk();
        $this->postJson('/api/v1/administration/categories/'.$child['id'].'/restore')
            ->assertStatus(409)->assertJsonPath('error.code', 'RAW_MATERIAL_CATEGORY_PARENT_ARCHIVED');
        $this->postJson('/api/v1/administration/categories/'.$root['id'].'/restore')->assertOk();
        $this->postJson('/api/v1/administration/categories/'.$child['id'].'/restore')->assertOk();
    }

    public function test_revoked_role_and_disabled_account_are_rejected_on_the_next_request(): void
    {
        $location = $this->kitchen();
        $actor = $this->user('actor@example.test');
        $target = $this->user('target@example.test');
        $this->role($actor, BusinessRole::Administrator);
        $assignment = $this->role($target, BusinessRole::Administrator);
        app(MembershipService::class)->assign($actor, $location);
        app(MembershipService::class)->assign($target, $location);

        $this->selectContext($target, $location);
        $this->getJson('/api/v1/administration/access')->assertOk();
        $this->getJson('/api/v1/administration/categories')->assertOk();
        app(RoleAdministration::class)->revoke($actor, $location, $assignment, self::PASSWORD);
        $this->getJson('/api/v1/administration/access')->assertForbidden();
        $this->getJson('/api/v1/administration/categories')->assertForbidden();

        $disabled = $this->user('disabled@example.test');
        $this->role($disabled, BusinessRole::Administrator);
        app(MembershipService::class)->assign($disabled, $location);
        $this->selectContext($disabled, $location);
        $this->getJson('/api/v1/administration/access')->assertOk();
        $this->getJson('/api/v1/administration/categories')->assertOk();
        app(RoleAdministration::class)->setAccountActive($actor, $location, $disabled, false);
        $this->getJson('/api/v1/administration/access')->assertUnauthorized();
        $this->getJson('/api/v1/administration/categories')->assertUnauthorized();
    }
}
