<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\DefinedModuleCatalog;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Domain\RawMaterials\RawMaterialService;
use App\Models\Location;
use App\Models\LocationModuleState;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\AuthorizationTestCase;
use Tests\Support\ModuleCatalogFixtures;

class RawMaterialsTest extends AuthorizationTestCase
{
    private Location $location;

    private User $bootstrapAdministrator;

    private User $steward;

    private RawMaterialService $rawMaterialService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->location = $this->activeRestaurant();
        $this->bootstrapAdministrator = $this->user('raw-material-bootstrap@example.test');
        $this->role($this->bootstrapAdministrator, BusinessRole::Administrator);
        app(MembershipService::class)->assign($this->bootstrapAdministrator, $this->location);
        $this->steward = $this->user('raw-material-steward@example.test');
        app(MembershipService::class)->assign($this->steward, $this->location);
        Organization::query()->whereKey($this->steward->organization_id)->update(['authorization_initialized' => true]);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::readyWithRawMaterials());
        LocationModuleState::create([
            'organization_id' => $this->steward->organization_id,
            'location_id' => $this->location->id,
            'module_key' => 'RAW_MATERIALS',
            'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id,
            'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        $this->bootstrapFixtureGrants($this->bootstrapAdministrator, $this->steward);
        $this->rawMaterialService = app(RawMaterialService::class);
    }

    public function test_create_allocates_immutable_code_and_records_controlled_unit_conversions(): void
    {
        $category = $this->category();
        $material = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Mąka pszenna',
            'base_unit_code' => 'kg',
            'conversions' => [['unit_code' => 'g']],
            'aliases' => ['Mąka tortowa'],
        ]);

        $this->assertSame('SUR-000001', $material['code']);
        $this->assertSame('DRAFT', $material['lifecycle_state']);
        $this->assertSame('UNKNOWN', $material['revision']['allergen_state']);
        $this->assertSame('MASS', $material['revision']['base_unit_dimension']);
        $this->assertSame('0.001000000000', DB::table('raw_material_revision_units')
            ->where('revision_id', $material['revision']['id'])->where('unit_code', 'g')->value('to_base_factor'));
        $this->assertSame(2, DB::table('raw_material_aliases')->where('revision_id', $material['revision']['id'])->count());
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->where('event_code', 'CREATED')->count());
    }

    public function test_duplicate_terms_and_client_supplied_metric_factors_are_rejected_atomically(): void
    {
        $category = $this->category();
        $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Kasza jaglana',
            'base_unit_code' => 'g',
        ]);
        $before = DB::table('raw_materials')->count();

        try {
            $this->rawMaterialService->create($this->steward, $this->location, [
                'category_id' => $category['id'],
                'name' => 'Kasza',
                'base_unit_code' => 'g',
                'aliases' => ['  KASZA   JAGLANA '],
            ]);
            $this->fail('A duplicate alias was accepted.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_DUPLICATE_TERM', $exception->errorCode);
            $this->assertSame(422, $exception->status);
        }
        $this->assertSame($before, DB::table('raw_materials')->count());

        $this->expectException(ValidationException::class);
        $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Kasza gryczana',
            'base_unit_code' => 'kg',
            'conversions' => [['unit_code' => 'g', 'to_base_factor' => '0.5']],
        ]);
    }

    public function test_api_requires_both_module_availability_and_an_organization_grant(): void
    {
        $this->selectContext($this->steward);
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('meta.total', 0);

        $this->app->instance(ModuleCatalog::class, new DefinedModuleCatalog);
        $this->getJson('/api/v1/raw-materials')->assertStatus(503)
            ->assertJsonPath('error.code', 'RAW_MATERIALS_MODULE_UNAVAILABLE')
            ->assertJsonPath('error.message', __('messages.raw_materials_module_unavailable'));
        $catalog = $this->getJson('/api/v1/security/modules/catalog')->assertOk()->json('data');
        $rawMaterials = collect($catalog)->firstWhere('module', 'RAW_MATERIALS');
        $this->assertSame('NOT_READY', $rawMaterials['readiness']);
    }

    public function test_location_roles_do_not_bypass_organization_grants(): void
    {
        $manager = $this->user('raw-material-manager@example.test');
        $this->role($manager, BusinessRole::Administrator);
        app(MembershipService::class)->assign($manager, $this->location);
        $this->selectContext($manager);
        $this->getJson('/api/v1/raw-materials')->assertForbidden()
            ->assertJsonPath('message', __('messages.unauthorized'));
    }

    public function test_self_grant_is_rejected_over_the_api(): void
    {
        $this->selectContext($this->steward);
        $this->postJson('/api/v1/raw-materials/permissions/'.$this->steward->id, [
            'permission' => RawMaterialPermission::View->value,
            'reason' => 'Nie wolno nadać sobie prawa',
        ])->assertForbidden();
    }

    public function test_unknown_allergen_state_blocks_approval_and_polish_api_contract_is_stable(): void
    {
        $category = $this->category();
        $material = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $category['id'],
            'name' => 'Olej rzepakowy',
            'base_unit_code' => 'ml',
        ]);
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id']);
        $this->assertSame('VERIFICATION', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));

        try {
            $this->rawMaterialService->approve($this->steward, $this->location, $material['id'], 'Test akceptacji');
            $this->fail('An unknown allergen declaration was approved.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED', $exception->errorCode);
            $this->assertSame(422, $exception->status);
        }

        $this->selectContext($this->steward);
        $this->postJson('/api/v1/raw-materials', [
            'organization_id' => $this->steward->organization_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('request')
            ->assertJsonPath('message', __('messages.validation_failed'));
    }

    public function test_block_archive_restore_and_reapproval_preserve_identity_and_history(): void
    {
        $category = $this->category();
        $materialId = $this->declaredMaterial($category['id'], 'Sól morska');
        $this->rawMaterialService->submit($this->steward, $this->location, $materialId);
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Pierwsza akceptacja');
        $code = DB::table('raw_materials')->where('id', $materialId)->value('code');

        $this->rawMaterialService->block($this->steward, $this->location, $materialId, 'Kontrola bezpieczeństwa');
        $this->assertSame('BLOCKED', DB::table('raw_materials')->where('id', $materialId)->value('lifecycle_state'));
        $this->rawMaterialService->unblock($this->steward, $this->location, $materialId, 'Zakończono kontrolę');
        $this->rawMaterialService->archive($this->steward, $this->location, $materialId, 'Wycofanie z katalogu');
        $this->rawMaterialService->restore($this->steward, $this->location, $materialId, 'Ponowna walidacja');
        $this->assertSame('VERIFICATION', DB::table('raw_materials')->where('id', $materialId)->value('lifecycle_state'));
        $this->rawMaterialService->approve($this->steward, $this->location, $materialId, 'Ponowna akceptacja');

        $this->assertSame($code, DB::table('raw_materials')->where('id', $materialId)->value('code'));
        $this->assertSame(2, DB::table('raw_material_revisions')->where('raw_material_id', $materialId)->count());
        $this->assertSame(2, DB::table('raw_material_revision_approval_events as approvals')
            ->join('raw_material_revisions as revisions', 'revisions.id', '=', 'approvals.revision_id')
            ->where('revisions.raw_material_id', $materialId)
            ->where('approvals.outcome', 'APPROVED')->count());
        $this->assertSame('ACTIVE', DB::table('raw_materials')->where('id', $materialId)->value('lifecycle_state'));
        $this->assertSame(7, DB::table('raw_material_audit_events')->where('subject_id', $materialId)->count());
    }

    private function selectContext(User $actor): void
    {
        $response = $this->actingAs($actor, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $this->location->id,
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }

    public function test_postgresql_enforces_category_depth_immutable_history_and_master_identity(): void
    {
        $root = $this->category();
        $child = $this->rawMaterialService->createCategory($this->steward, $this->location, [
            'code' => 'GRAIN',
            'name' => 'Zboża',
            'parent_id' => $root['id'],
        ]);
        $grandchild = $this->rawMaterialService->createCategory($this->steward, $this->location, [
            'code' => 'WHEAT',
            'name' => 'Pszenica',
            'parent_id' => $child['id'],
        ]);
        try {
            $this->rawMaterialService->createCategory($this->steward, $this->location, [
                'code' => 'FLOUR',
                'name' => 'Mąka',
                'parent_id' => $grandchild['id'],
            ]);
            $this->fail('A fourth category level was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }

        $material = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => $root['id'],
            'name' => 'Cukier',
            'base_unit_code' => 'kg',
            'conversions' => [['unit_code' => 'g']],
        ]);
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_materials')
            ->where('id', $material['id'])->update(['code' => 'SUR-999999']));
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_revisions')
            ->where('id', $material['revision']['id'])->update(['name' => 'Zmieniona nazwa']));
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->update(['outcome' => 'REJECTED']));
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->delete());
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_materials')
            ->where('id', $material['id'])->delete());
        $this->assertConstraintViolation('23514', fn () => DB::table('raw_material_revision_units')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $material['revision']['id'],
            'unit_code' => 'mg',
            'dimension' => 'MASS',
            'conversion_kind' => 'METRIC',
            'to_base_factor' => '0.5',
        ]));
    }

    private function assertConstraintViolation(string $sqlState, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected PostgreSQL to reject the invalid operation.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);
        }
    }

    private function category(): array
    {
        return $this->rawMaterialService->createCategory($this->steward, $this->location, [
            'code' => 'DRY_GOODS',
            'name' => 'Produkty sypkie',
        ]);
    }

    private function bootstrapFixtureGrants(User $actor, User $recipient): void
    {
        $permissions = array_map(
            fn (RawMaterialPermission $permission): string => $permission->value,
            RawMaterialPermission::cases(),
        );
        $operationId = Str::uuid()->toString();
        DB::table('raw_material_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => $operationId,
            'actor_user_id' => $actor->id,
            'subject_type' => 'ORGANIZATION',
            'subject_id' => $actor->organization_id,
            'event_code' => 'PERMISSION_BOOTSTRAP',
            'outcome' => 'ACCEPTED',
            'reason_code' => 'INITIAL_STEWARD',
            'metadata' => json_encode([
                'recipient_user_id' => $recipient->id,
                'permissions' => $permissions,
            ], JSON_THROW_ON_ERROR),
        ]);
        foreach ($permissions as $permission) {
            DB::table('organization_permission_grants')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $actor->organization_id,
                'user_id' => $recipient->id,
                'permission' => $permission,
                'granted_by' => $actor->id,
                'valid_from' => now(),
                'delegation_source_id' => null,
                'bootstrap_operation_id' => $operationId,
                'reason_code' => 'INITIAL_STEWARD',
            ]);
        }
    }

    private function declaredMaterial(string $categoryId, string $name): string
    {
        $materialId = Str::uuid()->toString();
        $revisionId = Str::uuid()->toString();
        $alias = mb_strtolower(trim($name), 'UTF-8');
        $payload = [
            'category_id' => $categoryId,
            'name' => $name,
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
        $aliases = [['alias' => $name, 'normalized_alias' => $alias]];
        $hash = hash('sha256', json_encode([
            'payload' => $payload,
            'units' => $units,
            'aliases' => $aliases,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        DB::table('raw_materials')->insert([
            'id' => $materialId,
            'organization_id' => $this->steward->organization_id,
            'code' => 'SUR-000099',
            'lifecycle_state' => 'DRAFT',
            'working_revision_id' => $revisionId,
            'created_by' => $this->steward->id,
        ]);
        DB::table('raw_material_revisions')->insert([
            'id' => $revisionId,
            'organization_id' => $this->steward->organization_id,
            'raw_material_id' => $materialId,
            'revision_no' => 1,
            'category_id' => $categoryId,
            'name' => $name,
            'base_unit_code' => 'g',
            'base_unit_dimension' => 'MASS',
            'allergen_state' => 'DECLARED',
            'critical_change' => false,
            'created_by' => $this->steward->id,
            'content_hash' => $hash,
        ]);
        DB::table('raw_material_revision_units')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'revision_id' => $revisionId,
            ...$units[0],
        ]);
        DB::table('raw_material_aliases')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'raw_material_id' => $materialId,
            'revision_id' => $revisionId,
            ...$aliases[0],
        ]);

        return $materialId;
    }
}
