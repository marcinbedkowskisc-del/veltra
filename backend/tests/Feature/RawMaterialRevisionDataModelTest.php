<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialRevisionDataModelTest extends AuthorizationTestCase
{
    private Location $location;

    private User $administrator;

    private User $steward;

    private RawMaterialService $rawMaterials;

    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->location = $this->activeRestaurant();
        $this->administrator = $this->user('revision-data-admin@example.test');
        $this->role($this->administrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->administrator, $this->location);
        $this->steward = $this->user('revision-data-steward@example.test');
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
            'code' => 'TEST_DATA',
            'name' => 'Dane testowe',
        ]);
        $this->categoryId = $category['id'];
    }

    public function test_unknown_and_no_known_allergen_states_are_distinct_in_the_revision_snapshot(): void
    {
        $unknown = $this->createMaterial('Nieznany stan');
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
        ]);
        $noKnown = $this->createMaterial('Brak znanych alergenów', [
            'allergen_declaration' => [
                'status' => 'NO_KNOWN_ALLERGENS',
                'catalog_version_id' => $catalogVersionId,
                'items' => [],
            ],
        ]);

        $unknownRevision = DB::table('raw_material_revisions')->where('id', $unknown['revision']['id'])->first();
        $noKnownRevision = DB::table('raw_material_revisions')->where('id', $noKnown['revision']['id'])->first();
        $this->assertSame('UNKNOWN', $unknownRevision->allergen_state);
        $this->assertNull(DB::table('raw_material_revision_allergen_attestations')
            ->where('revision_id', $unknownRevision->id)->first());
        $this->assertSame('DECLARED', $noKnownRevision->allergen_state);
        $this->assertSame('NO_KNOWN_ALLERGENS', DB::table('raw_material_revision_allergen_attestations')
            ->where('revision_id', $noKnownRevision->id)->value('attestation_status'));
        $this->assertSame(0, DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $noKnownRevision->id)->count());
        $this->assertNotSame($unknownRevision->content_hash, $noKnownRevision->content_hash);
        $this->assertRevisionHashMatches($unknownRevision);
        $this->assertRevisionHashMatches($noKnownRevision);
    }

    public function test_version_two_hash_covers_declarations_storage_and_is_preserved_when_revising(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
            ['allergen_code' => 'TEST_MILK', 'name_pl' => 'Mleko'],
        ]);
        $material = $this->createMaterial('Mieszanka testowa', [
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [
                    ['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT'],
                    ['allergen_code' => 'TEST_MILK', 'exposure_kind' => 'CROSS_CONTACT_POTENTIAL'],
                ],
            ],
            'storage_variants' => [$this->storageVariant()],
        ]);
        $revisionId = $material['revision']['id'];
        $revision = DB::table('raw_material_revisions')->where('id', $revisionId)->first();

        $this->assertSame(2, (int) $revision->content_hash_version);
        $this->assertSame(2, DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $revisionId)->count());
        $this->assertSame(1, DB::table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisionId)->count());
        $this->assertRevisionHashMatches($revision);

        $revised = $this->rawMaterials->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $this->categoryId,
            'name' => 'Mieszanka testowa poprawiona',
            'base_unit_code' => 'g',
        ]);
        $revisedId = $revised['revision']['id'];
        $revisedRevision = DB::table('raw_material_revisions')->where('id', $revisedId)->first();
        $this->assertSame(2, DB::table('raw_material_revision_allergen_declarations')
            ->where('revision_id', $revisedId)->count());
        $this->assertSame(1, DB::table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisedId)->count());
        $this->assertRevisionHashMatches($revisedRevision);
    }

    public function test_database_rejects_duplicate_and_invalid_declarations_and_storage_bounds(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
        ]);
        $material = $this->createMaterial('Materiał z alergenem', [
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
            ],
        ]);
        $revisionId = $material['revision']['id'];

        $this->assertDatabaseRejects('23505', fn (Connection $connection) => $connection->table('raw_material_revision_allergen_declarations')->insert([
            'revision_id' => $revisionId,
            'organization_id' => $this->steward->organization_id,
            'catalog_version_id' => $catalogVersionId,
            'allergen_code' => 'TEST_WHEAT',
            'exposure_kind' => 'CROSS_CONTACT_POTENTIAL',
        ]));

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_allergen_declarations')->insert([
            'revision_id' => $revisionId,
            'organization_id' => $this->steward->organization_id,
            'catalog_version_id' => $catalogVersionId,
            'allergen_code' => 'TEST_WHEAT',
            'exposure_kind' => 'UNKNOWN',
        ]));

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$this->storageVariant('INVALID_TEMP', temperatureMin: '8.00', temperatureMax: '-1.00'),
        ]));

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$this->storageVariant('INVALID_HUMIDITY', humidityMin: '75.00', humidityMax: '40.00'),
        ]));
    }

    public function test_database_rejects_multiple_defaults_and_cross_organization_references(): void
    {
        $material = $this->createMaterial('Przechowywanie testowe', [
            'storage_variants' => [$this->storageVariant()],
        ]);
        $revisionId = $material['revision']['id'];

        $this->assertDatabaseRejects('23505', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$this->storageVariant('SECOND_DEFAULT'),
        ]));

        $this->withForeignOrganizationFixture(function (Location $foreignLocation) use ($revisionId): void {
            $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $foreignLocation->organization_id,
                'revision_id' => $revisionId,
                ...$this->storageVariant('CROSS_ORG', isDefault: false),
            ]));
        });
    }

    public function test_approved_revision_data_cannot_be_modified_or_extended(): void
    {
        $catalogVersionId = $this->publishedCatalog([
            ['allergen_code' => 'TEST_WHEAT', 'name_pl' => 'Pszenica'],
        ]);
        $material = $this->createMaterial('Zatwierdzany materiał', [
            'allergen_declaration' => [
                'status' => 'DECLARED',
                'catalog_version_id' => $catalogVersionId,
                'items' => [['allergen_code' => 'TEST_WHEAT', 'exposure_kind' => 'DIRECT']],
            ],
            'storage_variants' => [$this->storageVariant()],
        ]);
        $revisionId = $material['revision']['id'];
        $this->rawMaterials->submit($this->steward, $this->location, $material['id']);
        $this->rawMaterials->approve($this->steward, $this->location, $material['id'], 'Zatwierdzenie testowe');

        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisionId)->update(['name_pl' => 'Zmiana po zatwierdzeniu']));
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')
            ->where('revision_id', $revisionId)->delete());
        $this->assertDatabaseRejects('23514', fn (Connection $connection) => $connection->table('raw_material_revision_storage_variants')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$this->storageVariant('AFTER_APPROVAL', isDefault: false),
        ]));
    }

    public function test_historical_revision_hash_format_remains_version_one(): void
    {
        $materialId = Str::uuid()->toString();
        $revisionId = Str::uuid()->toString();
        $legacyName = 'Historyczny materiał';
        $alias = mb_strtolower($legacyName, 'UTF-8');
        $payload = [
            'category_id' => $this->categoryId,
            'name' => $legacyName,
            'description' => null,
            'base_unit_code' => 'g',
            'base_unit_dimension' => 'MASS',
            'allergen_state' => 'DECLARED',
        ];
        ksort($payload);
        $units = [[
            'unit_code' => 'g',
            'dimension' => 'MASS',
            'conversion_kind' => 'BASE',
            'to_base_factor' => '1.000000000000',
            'basis' => null,
        ]];
        $aliases = [['alias' => $legacyName, 'normalized_alias' => $alias]];
        $legacyHash = hash('sha256', json_encode([
            'payload' => $payload,
            'units' => $units,
            'aliases' => $aliases,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->withSetupTransaction(function (Connection $connection) use (
            $materialId,
            $revisionId,
            $legacyName,
            $payload,
            $units,
            $aliases,
            $legacyHash,
        ): void {
            $connection->table('raw_material_code_counters')->insert([
                'organization_id' => $this->steward->organization_id,
                'prefix' => 'SUR',
                'last_value' => 0,
            ]);
            $connection->table('raw_material_code_counters')->where('organization_id', $this->steward->organization_id)
                ->where('prefix', 'SUR')->update(['last_value' => 1]);
            $connection->table('raw_materials')->insert([
                'id' => $materialId,
                'organization_id' => $this->steward->organization_id,
                'code' => 'SUR-000001',
                'lifecycle_state' => 'DRAFT',
                'working_revision_id' => $revisionId,
                'created_by' => $this->steward->id,
            ]);
            $connection->table('raw_material_revisions')->insert([
                'id' => $revisionId,
                'organization_id' => $this->steward->organization_id,
                'raw_material_id' => $materialId,
                'revision_no' => 1,
                'category_id' => $this->categoryId,
                'name' => $legacyName,
                'base_unit_code' => 'g',
                'base_unit_dimension' => 'MASS',
                'allergen_state' => $payload['allergen_state'],
                'critical_change' => false,
                'created_by' => $this->steward->id,
                'content_hash' => $legacyHash,
            ]);
            foreach ($units as $unit) {
                $connection->table('raw_material_revision_units')->insert([
                    'id' => Str::uuid()->toString(),
                    'organization_id' => $this->steward->organization_id,
                    'revision_id' => $revisionId,
                    ...$unit,
                ]);
            }
            $connection->table('raw_material_aliases')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->steward->organization_id,
                'raw_material_id' => $materialId,
                'revision_id' => $revisionId,
                ...$aliases[0],
            ]);
        });

        $revision = DB::table('raw_material_revisions')->where('id', $revisionId)->first();
        $this->assertSame(1, (int) $revision->content_hash_version);
        $this->assertSame($legacyHash, $this->calculateRevisionHash($revision));
    }

    private function createMaterial(string $name, array $extra = []): array
    {
        return $this->rawMaterials->create($this->steward, $this->location, [
            'category_id' => $this->categoryId,
            'name' => $name,
            'base_unit_code' => 'g',
            ...$extra,
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

    private function storageVariant(
        string $code = 'AMBIENT',
        string $temperatureMin = '10.00',
        string $temperatureMax = '25.00',
        ?string $humidityMin = null,
        ?string $humidityMax = null,
        bool $isDefault = true,
    ): array {
        return [
            'variant_code' => $code,
            'name_pl' => 'Warunki pokojowe',
            'storage_type' => 'AMBIENT',
            'temperature_min_c' => $temperatureMin,
            'temperature_max_c' => $temperatureMax,
            'humidity_min_percent' => $humidityMin,
            'humidity_max_percent' => $humidityMax,
            'conditions' => null,
            'is_default' => $isDefault,
        ];
    }

    private function assertRevisionHashMatches(object $revision): void
    {
        $this->assertSame($revision->content_hash, $this->calculateRevisionHash($revision));
    }

    private function calculateRevisionHash(object $revision): string
    {
        $method = new ReflectionMethod(RawMaterialService::class, 'hashFromDatabase');

        return $method->invoke($this->rawMaterials, $revision);
    }

    protected function assertDatabaseRejects(string $sqlState, callable $operation): void
    {
        try {
            $this->withSetupTransaction(function (Connection $connection) use ($operation): void {
                $operation($connection);
                $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);

            return;
        }

        $this->fail("PostgreSQL did not reject the operation with SQLSTATE {$sqlState}.");
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
}
