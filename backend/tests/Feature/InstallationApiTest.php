<?php

namespace Tests\Feature;

use App\Models\LocationManagementAssignment;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\InstallationTestCase;

class InstallationApiTest extends InstallationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        config(['development.management_enabled' => true, 'development.management_token' => str_repeat('t', 64)]);
        $this->withHeader('X-Veltra-Development-Token', str_repeat('t', 64));
    }

    public function test_organization_initialization_retrieval_and_duplicate_error(): void
    {
        $this->getJson('/api/v1/organization')->assertNotFound();
        $this->postJson('/api/v1/organization', ['name' => 'Example', 'code' => 'EXAMPLE'])
            ->assertCreated()->assertJsonPath('data.name', 'Example')->assertJsonMissingPath('data.installation');
        $this->getJson('/api/v1/organization')->assertOk()->assertJsonPath('data.code', 'EXAMPLE');
        $this->postJson('/api/v1/organization', ['name' => 'Second', 'code' => 'SECOND'])
            ->assertStatus(409)->assertJsonPath('message', 'The installation organization has already been initialized.');
    }

    public function test_location_validation_pagination_filters_retrieval_and_update(): void
    {
        $this->initialize();
        $this->postJson('/api/v1/locations', ['name' => 'Invalid', 'code' => 'BAD', 'type' => 'WAREHOUSE'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/v1/locations', ['name' => ' ', 'code' => 'bad code', 'type' => 'RESTAURANT'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'code']);
        $this->postJson('/api/v1/locations', ['name' => 'Kitchen', 'code' => 'CK1', 'type' => 'CENTRAL_KITCHEN'])
            ->assertCreated()->assertJsonPath('data.is_active', true);
        $response = $this->postJson('/api/v1/locations', ['name' => 'Restaurant', 'code' => 'R1', 'type' => 'RESTAURANT'])
            ->assertCreated()->assertJsonPath('data.is_active', false);
        $id = $response->json('data.id');
        $this->getJson("/api/v1/locations/{$id}")->assertOk()->assertJsonPath('data.code', 'R1');
        $this->patchJson("/api/v1/locations/{$id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->patchJson("/api/v1/locations/{$id}", ['code' => 'CK1'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson("/api/v1/locations/{$id}", ['type' => 'CENTRAL_KITCHEN'])->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->patchJson("/api/v1/locations/{$id}", ['organization_id' => Str::uuid()->toString()])->assertUnprocessable();
        $this->getJson('/api/v1/locations?per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)->assertJsonStructure(['data', 'links', 'meta']);
        $this->getJson('/api/v1/locations?type=RESTAURANT&is_active=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/locations?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/locations?type=INVALID')->assertUnprocessable();
        $this->getJson('/api/v1/locations/'.Str::uuid())->assertNotFound();
        $this->getJson('/api/v1/locations/not-a-uuid')->assertNotFound();
    }

    public function test_locations_cannot_be_created_before_initialization_or_activated_without_a_manager(): void
    {
        $this->postJson('/api/v1/locations', ['name' => 'Kitchen', 'code' => 'CK1', 'type' => 'CENTRAL_KITCHEN'])->assertStatus(409);
        $this->initialize();
        $this->postJson('/api/v1/locations', ['name' => 'Restaurant', 'code' => 'R1', 'type' => 'RESTAURANT', 'is_active' => true])->assertStatus(409);
        $restaurant = $this->restaurant();
        $this->patchJson("/api/v1/locations/{$restaurant->id}", ['is_active' => true])->assertStatus(409);
    }

    public function test_management_api_preserves_history_and_returns_consistent_errors(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $secondKitchen = $this->kitchen('CK2');
        $base = "/api/v1/locations/{$restaurant->id}";
        $this->getJson("{$base}/managing-kitchen")->assertOk()->assertExactJson(['data' => null]);
        $this->postJson("{$base}/managing-kitchen", [])->assertUnprocessable()->assertJsonValidationErrors('managing_kitchen_id');
        $this->postJson("{$base}/managing-kitchen", ['managing_kitchen_id' => 'invalid'])->assertUnprocessable();
        $this->postJson("{$base}/managing-kitchen", ['managing_kitchen_id' => Str::uuid()->toString()])->assertUnprocessable();
        $this->putJson("{$base}/managing-kitchen", ['managing_kitchen_id' => $kitchen->id])->assertStatus(409);
        $this->postJson("{$base}/managing-kitchen", ['managing_kitchen_id' => $restaurant->id])->assertStatus(409);
        $this->postJson("{$base}/managing-kitchen", ['managing_kitchen_id' => $kitchen->id])->assertCreated();
        $this->patchJson($base, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson("{$base}/managing-kitchen", ['managing_kitchen_id' => $secondKitchen->id])->assertStatus(409);
        $this->putJson("{$base}/managing-kitchen", ['managing_kitchen_id' => $secondKitchen->id])->assertOk();
        $this->getJson("{$base}/managing-kitchen")->assertOk()->assertJsonPath('data.id', $secondKitchen->id);
        $this->getJson("{$base}/management-assignments")->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/locations/{$secondKitchen->id}/managed-restaurants")->assertOk()->assertJsonPath('data.0.id', $restaurant->id);
        $this->getJson("{$base}/managed-restaurants")->assertStatus(409);
        $this->getJson("{$base}/management-assignments?per_page=0")->assertUnprocessable();
    }

    public function test_supply_api_is_independent_and_supports_multiple_suppliers_and_disabling(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $kitchen = $this->kitchen();
        $secondKitchen = $this->kitchen('CK2');
        $base = "/api/v1/locations/{$restaurant->id}";
        $first = $this->postJson("{$base}/supply-relationships", ['supplier_kitchen_id' => $kitchen->id])
            ->assertCreated()->json('data.id');
        $this->postJson("{$base}/supply-relationships", ['supplier_kitchen_id' => $secondKitchen->id])->assertCreated();
        $this->postJson("{$base}/supply-relationships", ['supplier_kitchen_id' => $kitchen->id])->assertStatus(409);
        $this->postJson("{$base}/supply-relationships", ['supplier_kitchen_id' => $restaurant->id])->assertStatus(409);
        $this->postJson("{$base}/supply-relationships", ['supplier_kitchen_id' => 'invalid'])->assertUnprocessable();
        $this->getJson("{$base}/suppliers")->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("{$base}/managing-kitchen")->assertExactJson(['data' => null]);
        $this->assertSame(0, LocationManagementAssignment::count());
        $this->deleteJson("/api/v1/supply-relationships/{$first}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson("{$base}/suppliers")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("{$base}/supply-relationships")->assertOk()->assertJsonPath('meta.total', 2);
    }

    #[DataProvider('unavailableEnvironments')]
    public function test_management_boundary_fails_closed_outside_explicit_local_setup(
        string $environment, bool $enabled, string $token,
    ): void {
        $this->app->detectEnvironment(fn () => $environment);
        config(['development.management_enabled' => $enabled, 'development.management_token' => $token]);
        $this->getJson('/api/v1/organization')->assertNotFound();
        $this->postJson('/api/v1/organization', ['name' => 'Denied', 'code' => 'DENIED'])->assertNotFound();
        $this->getJson('/api/v1/locations/'.Str::uuid())->assertNotFound()->assertJsonPath('message', 'Development management API is unavailable.');
    }

    public static function unavailableEnvironments(): array
    {
        return [
            'production despite valid configuration' => ['production', true, str_repeat('t', 64)],
            'staging' => ['staging', true, str_repeat('t', 64)],
            'testing' => ['testing', true, str_repeat('t', 64)],
            'local disabled' => ['local', false, str_repeat('t', 64)],
            'local missing token' => ['local', true, ''],
            'local short token' => ['local', true, 'short'],
        ];
    }

    public function test_missing_or_incorrect_development_token_is_forbidden_before_model_binding(): void
    {
        $this->withHeader('X-Veltra-Development-Token', '');
        $this->getJson('/api/v1/organization')->assertForbidden();
        $this->getJson('/api/v1/locations/'.Str::uuid())->assertForbidden();
        $this->withHeader('X-Veltra-Development-Token', 'wrong');
        $this->getJson('/api/v1/locations')->assertForbidden();
        $this->postJson('/api/v1/organization', ['name' => 'Denied', 'code' => 'DENIED'])->assertForbidden();
    }

    public function test_supply_eligibility_does_not_grant_api_access(): void
    {
        $this->initialize();
        $restaurant = $this->restaurant();
        $this->service->establishSupply($restaurant, $this->kitchen());
        $this->withHeader('X-Veltra-Development-Token', '');
        $this->getJson("/api/v1/locations/{$restaurant->id}")->assertForbidden();
        $this->getJson("/api/v1/locations/{$restaurant->id}/suppliers")->assertForbidden();
    }
}
