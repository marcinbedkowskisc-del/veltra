<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialAuthorization;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Http\Requests\RawMaterialAllergenDeclarationRequest;
use App\Http\Requests\RawMaterialStorageVariantsRequest;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialStage2dApiTest extends AuthorizationTestCase
{
    private Location $location;

    private User $administrator;

    private User $steward;

    private RawMaterialService $rawMaterials;

    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeRestaurant();
        $this->location = $this->primaryCentralKitchen();
        $this->administrator = $this->user('stage2d3-admin@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->location, $this->administrator->id);
        $this->steward = $this->user('stage2d3-steward@example.test');
        app(MembershipService::class)->assign($this->steward, $this->location);
        $this->updateFixture($this->steward->organization, ['authorization_initialized' => true]);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $this->location->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->administrator->id,
            'updated_by' => $this->administrator->id,
        ]);
        $this->bootstrapFixtureGrants();
        $this->rawMaterials = app(RawMaterialService::class);
        $category = $this->rawMaterials->createCategory($this->steward, $this->location, [
            'code' => 'STAGE2D3',
            'name' => 'Dane Stage 2D.3',
        ]);
        $this->categoryId = $category['id'];
    }

    public function test_catalog_and_allergen_workflows_preserve_unknown_direct_cross_contact_and_attestation_states(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
            ['allergen_code' => 'TEST_MILK', 'name_pl' => 'Mleko'],
        ]);
        $draftCatalogId = $this->draftCatalog();
        $catalog = $this->rawMaterials->allergenCatalog($this->steward, $this->location);
        $catalogIds = array_column($catalog, 'id');
        $this->assertContains($catalogVersionId, $catalogIds);
        $this->assertNotContains($draftCatalogId, $catalogIds);
        $published = collect($catalog)->firstWhere('id', $catalogVersionId);
        $this->assertSame(
            ['TEST_WHEAT', 'TEST_MILK'],
            array_column($published['definitions'], 'allergen_code'),
        );

        $material = $this->createMaterial('Materiał z alergenami');
        $initialRevisionId = $material['revision']['id'];
        $unknown = $this->rawMaterials->revisionStage2d(
            $this->steward,
            $this->location,
            $material['id'],
            $initialRevisionId,
        );
        $this->assertSame('UNKNOWN', $unknown['allergen_declaration']['status']);
        $this->assertSame([], $unknown['allergen_declaration']['items']);

        try {
            $this->rawMaterials->reviseAllergenDeclaration($this->steward, $this->location, $material['id'], [
                'status' => 'DECLARED',
                'catalog_version_id' => $draftCatalogId,
                'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
            ], expectedVersion: $this->rawVersion($material['id']));
            $this->fail('A draft allergen catalog version was accepted.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_ALLERGEN_CATALOG_UNAVAILABLE', $exception->errorCode);
        }

        $declared = $this->rawMaterials->reviseAllergenDeclaration($this->steward, $this->location, $material['id'], [
            'status' => 'DECLARED',
            'catalog_version_id' => $catalogVersionId,
            'items' => [
                ['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT'],
                ['allergen_code' => 'TEST_MILK', 'exposure_kind' => 'CROSS_CONTACT_POTENTIAL'],
            ],
        ], expectedVersion: $this->rawVersion($material['id']));
        $declaredRevisionId = $declared['revision']['id'];
        $declaredRevision = DB::table('raw_material_revisions')->where('id', $declaredRevisionId)->first();
        $this->assertSame(3, (int) $declaredRevision->content_hash_version);
        $this->assertSame('DECLARED', $declaredRevision->allergen_state);
        $this->assertRevisionHashMatches($declaredRevision);
        $declaredData = $this->rawMaterials->revisionStage2d(
            $this->steward,
            $this->location,
            $material['id'],
            $declaredRevisionId,
        );
        $this->assertSame('DECLARED', $declaredData['allergen_declaration']['status']);
        $this->assertSame(
            [
                'TEST_MILK' => 'CROSS_CONTACT_POTENTIAL',
                'TEST_WHEAT' => 'DIRECT',
            ],
            array_column($declaredData['allergen_declaration']['items'], 'exposure_kind', 'allergen_code'),
        );
        $this->assertSame('UNKNOWN', DB::table('raw_material_revisions')->where('id', $initialRevisionId)->value('allergen_state'));

        $noKnown = $this->rawMaterials->reviseAllergenDeclaration($this->steward, $this->location, $material['id'], [
            'status' => 'NO_KNOWN_ALLERGENS',
            'catalog_version_id' => $catalogVersionId,
            'items' => [],
        ], expectedVersion: $this->rawVersion($material['id']));
        $noKnownRevisionId = $noKnown['revision']['id'];
        $noKnownData = $this->rawMaterials->revisionStage2d(
            $this->steward,
            $this->location,
            $material['id'],
            $noKnownRevisionId,
        );
        $this->assertSame('NO_KNOWN_ALLERGENS', $noKnownData['allergen_declaration']['status']);
        $this->assertSame([], $noKnownData['allergen_declaration']['items']);
        $this->assertNotSame(
            DB::table('raw_material_revisions')->where('id', $initialRevisionId)->value('content_hash'),
            DB::table('raw_material_revisions')->where('id', $noKnownRevisionId)->value('content_hash'),
        );
    }

    public function test_storage_updates_create_hashed_revisions_and_keep_approved_history_immutable(): void
    {
        $material = $this->createMaterial('Przechowywanie materiału');
        $initialRevisionId = $material['revision']['id'];
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
        ]);
        $material = $this->rawMaterials->reviseAllergenDeclaration($this->steward, $this->location, $material['id'], [
            'status' => 'NO_KNOWN_ALLERGENS',
            'catalog_version_id' => $catalogVersionId,
            'items' => [],
        ], expectedVersion: $this->rawVersion($material['id']));
        $this->rawMaterials->submit($this->steward, $this->location, $material['id'], expectedVersion: $this->rawVersion($material['id']));
        $approved = $this->rawMaterials->approve($this->steward, $this->location, $material['id'], 'Zatwierdzenie testowe', expectedVersion: $this->rawVersion($material['id']));
        $approvedRevisionId = $approved['approved_revision_id'];
        $approvedHash = DB::table('raw_material_revisions')->where('id', $approvedRevisionId)->value('content_hash');

        $updated = $this->rawMaterials->reviseStorageVariants(
            $this->steward,
            $this->location,
            $material['id'],
            [$this->storageVariant()],
            expectedVersion: $this->rawVersion($material['id']));
        $draftRevisionId = $updated['revision']['id'];
        $draft = DB::table('raw_material_revisions')->where('id', $draftRevisionId)->first();
        $this->assertSame($approvedRevisionId, $updated['approved_revision_id']);
        $this->assertNotSame($approvedRevisionId, $draftRevisionId);
        $this->assertSame($approvedHash, DB::table('raw_material_revisions')->where('id', $approvedRevisionId)->value('content_hash'));
        $this->assertSame(1, DB::table('raw_material_revision_storage_variants')->where('revision_id', $draftRevisionId)->count());
        $this->assertRevisionHashMatches($draft);
        $this->assertSame(
            'STORAGE_VARIANTS_CHANGED',
            json_decode(DB::table('raw_material_audit_events')
                ->where('subject_id', $material['id'])
                ->where('event_code', 'REVISION_CREATED')
                ->latest('occurred_at')
                ->value('metadata'), true, flags: JSON_THROW_ON_ERROR)['change_type'],
        );
        $this->assertSame([], $this->rawMaterials->revisionStage2d(
            $this->steward,
            $this->location,
            $material['id'],
            $initialRevisionId,
        )['storage_variants']);

        $this->rawMaterials->submit($this->steward, $this->location, $material['id'], expectedVersion: $this->rawVersion($material['id']));
        try {
            $this->rawMaterials->reviseStorageVariants(
                $this->steward,
                $this->location,
                $material['id'],
                [],
                expectedVersion: $this->rawVersion($material['id']));
            $this->fail('A submitted revision was editable.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_REVISION_NOT_EDITABLE', $exception->errorCode);
        }
    }

    public function test_specialized_permissions_are_explicit_and_revision_reads_are_organization_scoped(): void
    {
        $material = $this->createMaterial('Materiał tylko do odczytu');
        $viewer = $this->user('stage2d3-viewer@example.test');
        app(MembershipService::class)->assign($viewer, $this->location);
        $permissions = app(OrganizationRawMaterialPermissionAdministration::class);
        $permissions->grant(
            $this->steward,
            $this->location,
            $viewer,
            RawMaterialPermission::View,
            'Jawne prawo odczytu',
        );
        $permissions->grant(
            $this->steward,
            $this->location,
            $viewer,
            RawMaterialPermission::AuditView,
            'Jawne prawo odczytu historii',
        );

        $this->assertFalse(app(RawMaterialAuthorization::class)
            ->hasEffectiveGrant($viewer, RawMaterialPermission::AllergenManage));
        $this->assertFalse(app(RawMaterialAuthorization::class)
            ->hasEffectiveGrant($viewer, RawMaterialPermission::StorageManage));
        try {
            $this->rawMaterials->reviseAllergenDeclaration($viewer, $this->location, $material['id'], [
                'status' => 'UNKNOWN',
                'items' => [],
            ], expectedVersion: $this->rawVersion($material['id']));
            $this->fail('A user without the explicit allergen permission changed revision data.');
        } catch (AuthorizationException) {
            $this->assertSame(1, DB::table('raw_material_revisions')
                ->where('raw_material_id', $material['id'])->count());
        }

        try {
            $this->rawMaterials->revisionStage2d(
                $viewer,
                $this->location,
                $material['id'],
                Str::uuid()->toString(),
            );
            $this->fail('A foreign or missing revision was returned.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_REVISION_NOT_FOUND', $exception->errorCode);
        }
    }

    public function test_revision_and_audit_mutation_roll_back_together_when_audit_insert_fails(): void
    {
        $material = $this->createMaterial('Wycofany wariant');
        $beforeWorkingRevision = DB::table('raw_materials')->where('id', $material['id'])->value('working_revision_id');
        $this->withSetupConnection(fn (Connection $connection) => $connection->unprepared(<<<'SQL'
            CREATE FUNCTION raw_materials_test_fail_stage2d_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Injected audit failure' USING ERRCODE = 'P0001';
            END
            $$;
            CREATE TRIGGER raw_materials_test_fail_stage2d_audit
                BEFORE INSERT ON raw_material_audit_events
                FOR EACH ROW EXECUTE FUNCTION raw_materials_test_fail_stage2d_audit();
            SQL));

        try {
            $this->rawMaterials->reviseStorageVariants(
                $this->steward,
                $this->location,
                $material['id'],
                [$this->storageVariant()],
                expectedVersion: $this->rawVersion($material['id']));
            $this->fail('A revision succeeded despite a failing audit insert.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->errorInfo[0] ?? null);
        } finally {
            $this->withSetupConnection(function (Connection $connection): void {
                $connection->unprepared('DROP TRIGGER raw_materials_test_fail_stage2d_audit ON raw_material_audit_events');
                $connection->unprepared('DROP FUNCTION raw_materials_test_fail_stage2d_audit()');
            });
        }

        $this->assertSame(
            $beforeWorkingRevision,
            DB::table('raw_materials')->where('id', $material['id'])->value('working_revision_id'),
        );
        $this->assertSame(1, DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count());
        $this->assertSame(0, DB::table('raw_material_revision_storage_variants')->where('revision_id', $beforeWorkingRevision)->count());
    }

    public function test_request_validation_rejects_ambiguous_allergen_states_and_invalid_storage_bounds(): void
    {
        $allergenPayload = [
            'status' => 'NO_KNOWN_ALLERGENS',
            'catalog_version_id' => Str::uuid()->toString(),
            'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'CROSS_CONTACT_POTENTIAL']],
        ];
        $allergenRequest = RawMaterialAllergenDeclarationRequest::create('/test', 'PUT', $allergenPayload);
        $allergenValidator = Validator::make($allergenPayload, $allergenRequest->rules());
        foreach ($allergenRequest->after() as $callback) {
            $allergenValidator->after($callback);
        }
        $this->assertTrue($allergenValidator->fails());
        $this->assertArrayHasKey('items', $allergenValidator->errors()->messages());

        $storagePayload = ['variants' => [
            $this->storageVariant('FIRST', true),
            $this->storageVariant('SECOND', true),
            [
                ...$this->storageVariant('INVALID', false),
                'temperature_min_c' => '8',
                'temperature_max_c' => '-1',
                'humidity_min_percent' => '20',
                'humidity_max_percent' => null,
            ],
        ]];
        $storageRequest = RawMaterialStorageVariantsRequest::create('/test', 'PUT', $storagePayload);
        $storageValidator = Validator::make($storagePayload, $storageRequest->rules());
        foreach ($storageRequest->after() as $callback) {
            $storageValidator->after($callback);
        }
        $this->assertTrue($storageValidator->fails());
        $this->assertArrayHasKey('variants', $storageValidator->errors()->messages());
        $this->assertArrayHasKey('variants.2.temperature_max_c', $storageValidator->errors()->messages());
        $this->assertArrayHasKey('variants.2.humidity_max_percent', $storageValidator->errors()->messages());
    }

    public function test_http_endpoints_return_not_ready_without_bypassing_feature_gate(): void
    {
        $this->selectContext($this->steward);
        $this->app->instance(ModuleCatalog::class, new DefinedModuleCatalog);

        $this->getJson('/api/v1/raw-materials/allergen-catalog')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
        $this->getJson('/api/v1/raw-materials/'.Str::uuid().'/revisions/'.Str::uuid().'/stage2d')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
        $this->putJson('/api/v1/raw-materials/'.Str::uuid().'/draft/allergen-declaration', [
            'status' => 'UNKNOWN',
        ])->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
        $this->putJson('/api/v1/raw-materials/'.Str::uuid().'/draft/storage-variants', [
            'variants' => [],
        ])->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE');
    }

    private function createMaterial(string $name): array
    {
        return $this->rawMaterials->create($this->steward, $this->location, [
            'category_id' => $this->categoryId,
            'name' => $name,
            'base_unit_code' => 'g',
            'nominal_quantity' => '1000',
        ]);
    }

    private function publishedCatalog(array $definitions): string
    {
        $versionId = Str::uuid()->toString();
        $versionNumber = (int) DB::connection('pgsql_setup')
            ->table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
        $this->withSetupTransaction(function (Connection $connection) use ($versionId, $versionNumber, $definitions): void {
            $connection->table('raw_material_allergen_catalog_versions')->insert([
                'id' => $versionId,
                'version_number' => $versionNumber,
                'status' => 'DRAFT',
            ]);
            foreach ($definitions as $index => $definition) {
                $connection->table('raw_material_allergen_definitions')->insert([
                    'catalog_version_id' => $versionId,
                    'allergen_code' => $definition['allergen_code'],
                    'name_pl' => $definition['name_pl'],
                    'display_order' => $index + 1,
                ]);
            }
            $connection->table('raw_material_allergen_catalog_versions')->where('id', $versionId)
                ->update(['status' => 'PUBLISHED', 'published_at' => now()]);
        });

        return $versionId;
    }

    private function draftCatalog(): string
    {
        $versionId = Str::uuid()->toString();
        $versionNumber = (int) DB::connection('pgsql_setup')
            ->table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
        $this->withSetupTransaction(fn (Connection $connection) => $connection->table('raw_material_allergen_catalog_versions')->insert([
            'id' => $versionId,
            'version_number' => $versionNumber,
            'status' => 'DRAFT',
        ]));

        return $versionId;
    }

    private function storageVariant(string $code = 'CHILLED', bool $isDefault = true): array
    {
        return [
            'variant_code' => $code,
            'name_pl' => 'Przechowywanie chłodnicze',
            'storage_type' => 'CHILLED',
            'temperature_min_c' => '2',
            'temperature_max_c' => '6',
            'humidity_min_percent' => '40',
            'humidity_max_percent' => '70',
            'conditions' => null,
            'is_default' => $isDefault,
        ];
    }

    private function assertRevisionHashMatches(object $revision): void
    {
        $method = new ReflectionMethod(RawMaterialService::class, 'hashFromDatabase');
        $this->assertSame($revision->content_hash, $method->invoke($this->rawMaterials, $revision));
    }

    private function bootstrapFixtureGrants(): void
    {
        $permissions = array_map(
            fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        $this->withSetupTransaction(function (Connection $connection) use ($permissions, $operationId): void {
            $connection->table('raw_material_audit_events')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->steward->organization_id,
                'operation_id' => $operationId,
                'actor_user_id' => $this->administrator->id,
                'subject_type' => 'ORGANIZATION',
                'subject_id' => $this->steward->organization_id,
                'event_code' => 'PERMISSION_BOOTSTRAP',
                'outcome' => 'ACCEPTED',
                'reason_code' => 'INITIAL_STEWARD',
                'metadata' => json_encode([
                    'recipient_user_id' => $this->steward->id,
                    'permissions' => $permissions,
                ], JSON_THROW_ON_ERROR),
            ]);
            foreach ($permissions as $permission) {
                $connection->table('organization_permission_grants')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $this->steward->organization_id,
                    'user_id' => $this->steward->id,
                    'permission' => $permission,
                    'granted_by' => $this->administrator->id,
                    'valid_from' => now(),
                    'delegation_source_id' => null,
                    'bootstrap_operation_id' => $operationId,
                    'reason_code' => 'INITIAL_STEWARD',
                ]);
            }
        });
    }

    private function selectContext(User $actor): void
    {
        Auth::forgetGuards();
        $response = $this->actingAs($actor, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }
}
