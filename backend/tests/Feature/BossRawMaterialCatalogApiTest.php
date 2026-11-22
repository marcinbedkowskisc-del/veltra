<?php

namespace Tests\Feature;

use App\Domain\Authorization\BossLocationRestrictions;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\CodeOrganizationCapabilityCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\Modules\OrganizationCapabilityCatalog;
use App\Domain\Modules\OrganizationCapabilityService;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;
use Tests\Support\OrganizationCapabilityCatalogFixtures;

class BossRawMaterialCatalogApiTest extends AuthorizationTestCase
{
    private Location $context;

    private User $boss;

    private User $administrator;

    private User $rawSteward;

    private RawMaterialService $rawMaterials;

    private string $categoryId;

    private string $allergenCatalogId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeRestaurant();
        $this->context = $this->primaryCentralKitchen();
        $this->boss = $this->user('boss-raw-catalog-api@example.test');
        $this->administrator = $this->user('admin-raw-catalog-api@example.test');
        $this->rawSteward = $this->user('raw-raw-catalog-api@example.test');
        $this->role($this->boss, BusinessRole::Boss);
        $this->role($this->administrator, BusinessRole::Administrator);
        $this->role($this->rawSteward, BusinessRole::Administrator);
        foreach ([$this->boss, $this->administrator, $this->rawSteward] as $user) {
            app(MembershipService::class)->assign($user, $this->context, $user->id);
        }

        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->context,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(),
            [
                'enabled' => true,
                'expected_state_version' => 0,
                'current_password' => self::PASSWORD,
            ],
        );

        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->rawSteward->organization_id,
            'location_id' => $this->context->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->administrator->id,
            'updated_by' => $this->administrator->id,
        ]);
        $this->bootstrapRawPermissions($this->administrator, $this->rawSteward);
        $this->rawMaterials = app(RawMaterialService::class);
        $category = $this->rawMaterials->createCategory($this->rawSteward, $this->context, [
            'code' => 'BOSS_RAW_API_TEST',
            'name' => 'BOSS API category',
        ]);
        $this->categoryId = $category['id'];
        $this->allergenCatalogId = $this->publishedAllergenCatalog();
        $this->selectContext($this->boss, $this->context);
    }

    public function test_list_and_detail_return_exact_approved_dto_with_no_store(): void
    {
        $this->createApprovedMaterial('Zulu material', 'private description');
        $alphaId = $this->createApprovedMaterial('Alpha material');
        $this->createApprovedMaterial('Literal 50%_ value');

        $list = $this->getJson('/api/v1/boss/raw-materials?sort=name&direction=asc')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(
            ['Alpha material', 'Literal 50%_ value', 'Zulu material'],
            array_column($list->json('data'), 'name'),
        );
        $this->assertSame([
            'data' => [
                ['id', 'code', 'name', 'category', 'base_unit_code', 'supplier_names'],
                ['id', 'code', 'name', 'category', 'base_unit_code', 'supplier_names'],
                ['id', 'code', 'name', 'category', 'base_unit_code', 'supplier_names'],
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ], [
            'data' => array_map(
                static fn (array $item): array => array_keys($item),
                $list->json('data'),
            ),
            'meta' => array_keys($list->json('meta')),
        ]);
        $this->assertSame(['id', 'name'], array_keys($list->json('data.0.category')));
        $this->assertSame(3, $list->json('meta.total'));

        $detail = $this->getJson("/api/v1/boss/raw-materials/{$alphaId}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($list->json('data.0'), $detail->json('data'));

        $this->getJson('/api/v1/boss/raw-materials?search=50%25_')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Literal 50%_ value');
        $this->getJson('/api/v1/boss/raw-materials?search=private')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_filters_pagination_and_unknown_parameters_have_safe_contracts(): void
    {
        $this->createApprovedMaterial('One material');
        $this->createApprovedMaterial('Two material');

        $this->getJson('/api/v1/boss/raw-materials?per_page=25&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonCount(0, 'data')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->getJson("/api/v1/boss/raw-materials?category_id={$this->categoryId}&unit=g")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->getJson('/api/v1/boss/raw-materials?supplier_id='.Str::uuid())
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => __('messages.validation_failed'),
                ],
            ])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->getJson('/api/v1/boss/raw-materials?sort=supplier')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_framework_and_middleware_errors_are_normalized_and_not_cached(): void
    {
        $this->getJson('/api/v1/boss/raw-materials/not-a-uuid')
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'BOSS_RAW_MATERIAL_NOT_FOUND',
                    'message' => __('messages.resource_not_found'),
                ],
            ])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->postJson('/api/v1/boss/raw-materials')
            ->assertStatus(405)
            ->assertExactJson([
                'error' => [
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => __('messages.boss_raw_catalog_method_not_allowed'),
                ],
            ])
            ->assertHeader('Cache-Control', 'no-store, private');

        Auth::forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/boss/raw-materials')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->selectContext($this->boss, $this->context);
        $this->withSession([
            'identity_version' => $this->boss->auth_version,
            'operating_membership_id' => null,
        ])->getJson('/api/v1/boss/raw-materials')
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'BOSS_RAW_CATALOG_FORBIDDEN',
                    'message' => __('messages.unauthorized'),
                ],
            ])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_raw_material_module_is_not_a_boss_catalog_readiness_dependency(): void
    {
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());

        $this->getJson('/api/v1/boss/raw-materials')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_disabled_capability_and_global_raw_suppliers_restriction_are_denied_safely(): void
    {
        $this->app->instance(OrganizationCapabilityCatalog::class, OrganizationCapabilityCatalogFixtures::ready());
        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->context,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(),
            [
                'enabled' => false,
                'expected_state_version' => 1,
                'current_password' => self::PASSWORD,
            ],
        );
        $this->getJson('/api/v1/boss/raw-materials')
            ->assertStatus(503)
            ->assertExactJson([
                'error' => [
                    'code' => 'BOSS_RAW_CATALOG_UNAVAILABLE',
                    'message' => __('messages.boss_raw_catalog_unavailable'),
                ],
            ])
            ->assertHeader('Cache-Control', 'no-store, private');

        app(OrganizationCapabilityService::class)->configure(
            $this->administrator,
            $this->context,
            CodeOrganizationCapabilityCatalog::BOSS_RAW_CATALOG_READ,
            Str::uuid()->toString(),
            [
                'enabled' => true,
                'expected_state_version' => 2,
                'current_password' => self::PASSWORD,
            ],
        );
        app(BossLocationRestrictions::class)->replace(
            $this->administrator,
            $this->context,
            $this->boss->id,
            0,
            [],
            ['RAW_SUPPLIERS'],
            [],
        );
        $this->getJson('/api/v1/boss/raw-materials')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'BOSS_RAW_CATALOG_FORBIDDEN')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    private function selectContext(User $actor, Location $context): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        Auth::guard('web')->login($actor);
        $this->withSession([
            'identity_version' => $actor->auth_version,
            'operating_membership_id' => null,
        ]);
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $context->id])->assertOk();
    }

    private function createApprovedMaterial(string $name, ?string $description = null): string
    {
        $material = $this->rawMaterials->create($this->rawSteward, $this->context, [
            'category_id' => $this->categoryId,
            'name' => $name,
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
            'description' => $description,
        ]);
        $this->rawMaterials->reviseAllergenDeclaration($this->rawSteward, $this->context, $material['id'], [
            'status' => 'NO_KNOWN_ALLERGENS',
            'catalog_version_id' => $this->allergenCatalogId,
            'items' => [],
        ], $this->rawVersion($material['id']));
        $this->rawMaterials->submit($this->rawSteward, $this->context, $material['id'], $this->rawVersion($material['id']));
        $this->rawMaterials->approve(
            $this->rawSteward,
            $this->context,
            $material['id'],
            'Approved API fixture',
            $this->rawVersion($material['id']),
        );

        return $material['id'];
    }

    private function bootstrapRawPermissions(User $administrator, User $recipient): void
    {
        $permissions = array_map(
            static fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        $this->withSetupTransaction(function (Connection $connection) use (
            $administrator,
            $recipient,
            $permissions,
            $operationId,
        ): void {
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $administrator->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $administrator->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $administrator->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $recipient->id,
                    'permissions' => $permissions,
                ], JSON_THROW_ON_ERROR),
            ]);
            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $administrator->organization_id,
                    'user_id' => $recipient->id,
                    'permission' => $permission,
                    'granted_by' => $administrator->id,
                    'valid_from' => now(),
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
            }
        });
    }

    private function publishedAllergenCatalog(): string
    {
        $catalogId = Str::uuid()->toString();
        $versionNumber = (int) DB::connection('pgsql_setup')
            ->table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
        $this->withSetupTransaction(function (Connection $connection) use ($catalogId, $versionNumber): void {
            $connection->table('raw_material_allergen_catalog_versions')->insert([
                'id' => $catalogId,
                'version_number' => $versionNumber,
                'status' => 'DRAFT',
            ]);
            $connection->table('raw_material_allergen_definitions')->insert([
                'catalog_version_id' => $catalogId,
                'allergen_code' => 'TEST_WHEAT',
                'name_pl' => 'Pszenica',
                'display_order' => 1,
            ]);
            $connection->table('raw_material_allergen_catalog_versions')->where('id', $catalogId)
                ->update(['status' => 'PUBLISHED', 'published_at' => now()]);
        });

        return $catalogId;
    }
}
