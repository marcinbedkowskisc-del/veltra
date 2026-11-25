<?php

namespace Tests\Feature;

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BossVisibilityCategory;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\LocationModuleState;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\InstallationTestCase;
use Tests\Support\ModuleCatalogFixtures;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class BossRawFinancialMatrixTest extends InstallationTestCase
{
    private const PASSWORD = 'Test-Only-Password!42';

    private Location $kitchen;

    private User $admin;

    private User $steward;

    private User $boss;

    private string $materialId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array']);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->initialize();
        $this->kitchen = $this->kitchen();
        $this->admin = $this->provision('admin@example.test', BusinessRole::Administrator);
        $this->steward = $this->provision('steward@example.test', BusinessRole::Administrator);
        $this->boss = $this->provision('boss@example.test', BusinessRole::Boss);
        $this->updateFixture($this->admin->organization, ['authorization_initialized' => true]);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->admin->organization_id, 'location_id' => $this->kitchen->id,
            'module_key' => 'RAW_MATERIALS', 'enabled' => true,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        app(OrganizationCapabilityService::class)->configure(
            $this->admin, $this->kitchen, CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(), ['enabled' => true, 'expected_state_version' => 0, 'current_password' => self::PASSWORD],
        );
        $catalogId = $this->prepareRawFixtures();
        $service = app(RawMaterialService::class);
        $category = $service->createCategory($this->steward, $this->kitchen, ['code' => 'TEST', 'name' => 'Test']);
        $material = $service->create($this->steward, $this->kitchen, [
            'category_id' => $category['id'], 'name' => 'Surowiec testowy', 'base_unit_code' => 'g',
            'nominal_quantity' => '2800', 'usable_quantity' => '1500',
            'pricing' => ['basis' => 'PACKAGE', 'amount' => '28'],
            'allergen_declaration' => ['status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $catalogId, 'items' => []],
        ]);
        $this->materialId = $material['id'];
        $version = fn () => DB::table('raw_materials')->where('id', $this->materialId)->value('edit_version');
        $service->submit($this->steward, $this->kitchen, $this->materialId, expectedVersion: $version());
        $service->approve($this->steward, $this->kitchen, $this->materialId, 'Test', expectedVersion: $version());
        Storage::fake('raw_material_csv_exports');
    }

    private function provision(string $email, BusinessRole $role): User
    {
        $id = $this->withSetupTransaction(function () use ($email, $role): string {
            $user = User::on('pgsql_setup')->create([
                'organization_id' => $this->kitchen->organization_id, 'first_name' => 'Test', 'last_name' => 'User',
                'email' => $email, 'password' => self::PASSWORD, 'is_active' => true,
            ]);
            LocationMembership::on('pgsql_setup')->create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id,
                'location_id' => $this->kitchen->id, 'is_active' => true,
            ]);
            RoleAssignment::on('pgsql_setup')->create([
                'organization_id' => $user->organization_id, 'user_id' => $user->id, 'role' => $role,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user->id;
        });

        return User::on('pgsql')->findOrFail($id);
    }

    private function prepareRawFixtures(): string
    {
        return $this->withSetupTransaction(function (Connection $connection): string {
            $permissions = array_map(fn (RawMaterialPermission $permission): string => $permission->value, RawMaterialPermission::cases());
            $operationId = Str::uuid()->toString();
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(), 'organization_id' => $this->admin->organization_id,
                'operation_id' => $operationId, 'actor_user_id' => $this->admin->id,
                'subject_type' => 'ORGANIZATION', 'subject_id' => $this->admin->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP', 'outcome' => 'ACCEPTED', 'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode(['recipient_user_id' => $this->steward->id, 'permissions' => $permissions], JSON_THROW_ON_ERROR),
            ]);
            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(), 'organization_id' => $this->admin->organization_id,
                    'user_id' => $this->steward->id, 'permission' => $permission, 'granted_by' => $this->admin->id,
                    'valid_from' => now(), 'bootstrap_operation_id' => $operationId, 'reason_code' => 'INITIAL_STEWARD',
                ]);
            }
            $id = Str::uuid()->toString();
            $version = (int) $connection->table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
            $connection->table('raw_material_allergen_catalog_versions')->insert(['id' => $id, 'version_number' => $version, 'status' => 'DRAFT']);
            $connection->table('raw_material_allergen_catalog_versions')->where('id', $id)->update(['status' => 'PUBLISHED', 'published_at' => now()]);

            return $id;
        });
    }

    #[DataProvider('configurations')]
    public function test_boss_api_enforces_independent_financial_categories(bool $foodcost, bool $prices): void
    {
        $excluded = [];
        if (! $foodcost) {
            $excluded[] = BossVisibilityCategory::Foodcost->value;
        }
        if (! $prices) {
            $excluded[] = BossVisibilityCategory::PurchaseFinancials->value;
        }
        app(BossLocationRestrictions::class)->replace($this->admin, $this->kitchen, $this->boss->id, 0, [], $excluded, []);
        $this->actingAs($this->boss, 'web')->withSession(['identity_version' => $this->boss->auth_version]);
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $this->kitchen->id])->assertOk();
        foreach (['/api/v1/raw-materials', '/api/v1/raw-materials/'.$this->materialId] as $path) {
            $response = $this->getJson($path)->assertOk();
            $item = $path === '/api/v1/raw-materials' ? $response->json('data.0') : $response->json('data');
            $this->assertSame($prices, array_key_exists('reference_net_price', $item));
            $this->assertSame($foodcost, array_key_exists('usable_unit_cost', $item));
            if ($foodcost) {
                $this->assertSame('1.86666666666667', $item['usable_unit_cost']);
            }
            $this->assertArrayHasKey('nominal_quantity', $item);
            $this->assertArrayHasKey('usable_quantity', $item);
        }
        $this->getJson('/api/v1/raw-materials/'.$this->materialId.'/history')->assertOk()->assertJsonMissingPath('data.0.metadata');
        $this->assertSame($prices ? 200 : 403, $this->getJson('/api/v1/raw-materials/'.$this->materialId.'/reference-prices')->status());
        $this->assertSame($prices ? 200 : 403, $this->getJson('/api/v1/raw-materials?sort=reference_net_price')->status());
        $this->assertSame($foodcost ? 200 : 403, $this->getJson('/api/v1/raw-materials?sort=usable_unit_cost')->status());
        $priceDownload = null;
        foreach (['reference_net_price' => $prices, 'usable_unit_cost' => $foodcost] as $column => $allowed) {
            $response = $this->postJson('/api/v1/raw-materials/catalog-exports/selection', ['columns' => [$column]], ['Idempotency-Key' => 'test-'.$column]);
            $this->assertSame($allowed ? 201 : 403, $response->status(), $response->getContent());
            if ($allowed) {
                $csv = $this->get($response->json('data.download_url'))->assertOk()->streamedContent();
                $this->assertStringContainsString($column, $csv);
                $this->assertStringNotContainsString($column === 'reference_net_price' ? 'usable_unit_cost' : 'reference_net_price', $csv);
                if ($column === 'reference_net_price') {
                    $priceDownload = $response->json('data.download_url');
                }
            }
        }
        $this->assertSame($foodcost && $prices ? 200 : 403, $this->getJson('/api/v1/raw-materials/'.$this->materialId.'/documents')->status());
        if (! $foodcost || ! $prices) {
            $this->get('/api/v1/raw-materials/'.$this->materialId.'/documents/'.Str::uuid().'/download')->assertForbidden();
        }
        $this->postJson('/api/v1/raw-materials/'.$this->materialId.'/archive', ['reason' => 'Test'])->assertForbidden();
        if ($priceDownload !== null) {
            $restrictions = app(BossLocationRestrictions::class);
            $version = $restrictions->show($this->admin, $this->kitchen, $this->boss->id)['version'];
            $restrictions->replace($this->admin, $this->kitchen, $this->boss->id, $version, [], [
                ...$excluded, BossVisibilityCategory::PurchaseFinancials->value,
            ], []);
            $this->get($priceDownload)->assertForbidden();
        }
    }

    public static function configurations(): array
    {
        return ['A' => [true, true], 'B' => [false, false], 'C' => [true, false], 'D' => [false, true]];
    }
}
