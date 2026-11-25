<?php

namespace Tests\Concerns;

use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\LocationModuleState;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModuleCatalogFixtures;

trait RawWorkbenchTests
{
    public function test_workbench_separates_draft_pending_and_approved_snapshots_through_the_existing_lifecycle(): void
    {
        [$material, $input] = $this->b2Draft();
        $id = $material['id'];
        $this->selectRawMaterialApiContext($this->steward);
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials/workbench')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku', $material['sku'])
            ->assertJsonPath('data.0.workflow_state', 'DRAFT');
        $this->patchJson("/api/v1/raw-materials/{$id}/draft", [...$input, 'name' => 'Zmieniony szkic'], [
            'If-Match' => $material['version'],
        ])->assertOk();
        $this->getJson('/api/v1/raw-materials/workbench?search=Zmieniony')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.revision.name', 'Zmieniony szkic');
        $this->postJson("/api/v1/raw-materials/{$id}/submit", [], ['If-Match' => $material['version']])
            ->assertConflict()->assertJsonPath('error.code', 'RAW_MATERIAL_VERSION_CONFLICT');
        $this->attestWorkingRevision($id);
        $revisionCount = DB::table('raw_material_revisions')->where('raw_material_id', $id)->count();
        $this->postJson("/api/v1/raw-materials/{$id}/submit", [], ['If-Match' => $this->rawVersion($id)])->assertOk();
        $this->getJson('/api/v1/raw-materials/workbench?lifecycle_state=VERIFICATION')
            ->assertOk()->assertJsonPath('data.0.workflow_state', 'VERIFICATION');
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson("/api/v1/raw-materials/{$id}/approve", ['reason' => 'Jawna akceptacja autora'], [
            'If-Match' => $this->rawVersion($id),
        ])->assertOk()->assertJsonPath('data.lifecycle_state', 'ACTIVE')->assertJsonPath('data.sku', $material['sku']);
        $this->assertSame($revisionCount, DB::table('raw_material_revisions')->where('raw_material_id', $id)->count());
        $this->getJson('/api/v1/raw-materials/workbench')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('data.0.revision.name', 'Zmieniony szkic');
        $this->rawMaterialService->revise($this->steward, $this->location, $id, [
            ...$input, 'name' => 'Nowsza wersja robocza',
        ], $this->rawVersion($id));
        $this->getJson('/api/v1/raw-materials/workbench')->assertOk()
            ->assertJsonPath('data.0.revision.name', 'Nowsza wersja robocza')->assertJsonPath('data.0.workflow_state', 'DRAFT');
        $this->getJson("/api/v1/raw-materials/{$id}/editor")->assertOk()
            ->assertJsonPath('data.revision.name', 'Nowsza wersja robocza')->assertJsonStructure(['data' => ['last_update', 'history_url', 'nominal_quantity']]);
        $this->getJson('/api/v1/raw-materials')->assertOk()->assertJsonPath('data.0.revision.name', 'Zmieniony szkic');
        $this->getJson("/api/v1/raw-materials/{$id}/history")->assertOk();
    }

    public function test_workbench_search_filters_and_pagination_use_only_working_revision_and_hide_finance(): void
    {
        [$first, $input] = $this->b2Draft();
        for ($index = 0; $index < 25; $index++) {
            $this->rawMaterialService->create($this->steward, $this->location, [...$input, 'name' => "Pozycja {$index}"]);
        }
        $this->selectRawMaterialApiContext($this->steward);
        $this->getJson('/api/v1/raw-materials/workbench?sort=sku&per_page=25&page=2')
            ->assertOk()->assertJsonPath('meta.total', 26)->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
        $response = $this->getJson('/api/v1/raw-materials/workbench?search='.urlencode($first['sku']).'&unit=g&category_id='.$input['category_id'])
            ->assertOk()->assertJsonPath('meta.total', 1);
        foreach (['reference_net_price', 'currency_code', 'usable_unit_cost', 'usable_cost_basis'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data.0'));
        }
        $this->getJson('/api/v1/raw-materials/workbench?unit=kg')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials/workbench?search=nieistniejacy')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/raw-materials/workbench?sort=reference_net_price')->assertUnprocessable();
        $this->getJson('/api/v1/raw-materials/workbench?supplier_id='.$this->steward->id)->assertUnprocessable();
    }

    public function test_workbench_requires_view_and_draft_inspection_primary_membership_and_readiness(): void
    {
        [$material] = $this->b2Draft();
        $reader = $this->restaurantEmployeeInCentralKitchen('workbench-viewer@example.test');
        $grants = app(OrganizationRawMaterialPermissionAdministration::class);
        $editorOnly = $this->restaurantEmployeeInCentralKitchen('workbench-no-view@example.test');
        $grants->grant($this->steward, $this->location, $editorOnly, RawMaterialPermission::Edit, 'Edycja bez odczytu');
        $editorOnly->refresh();
        $this->flushSession();
        $this->selectRawMaterialApiContext($editorOnly);
        $this->getJson('/api/v1/raw-materials/workbench')->assertForbidden();
        $grants->grant($this->steward, $this->location, $reader, RawMaterialPermission::View, 'Odczyt');
        $reader->refresh();
        $this->flushSession();
        $this->selectRawMaterialApiContext($reader);
        $this->getJson('/api/v1/raw-materials/workbench')->assertForbidden();
        $grants->grant($this->steward, $this->location, $reader, RawMaterialPermission::AuditView, 'Wgląd w szkice');
        $reader->refresh();
        $this->flushSession();
        $this->selectRawMaterialApiContext($reader);
        $this->getJson('/api/v1/raw-materials/workbench')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/raw-materials/'.$material['id'].'/submit', [], [
            'If-Match' => $material['version'],
        ])->assertForbidden();
        $secondary = $this->activeRestaurant('WORKBENCH_SECONDARY');
        app(MembershipService::class)->assign($reader, $secondary);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $reader->organization_id, 'location_id' => $secondary->id,
            'module_key' => 'RAW_MATERIALS', 'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id, 'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        $reader->refresh();
        $this->selectRawMaterialApiContext($reader);
        $this->putJson('/api/v1/auth/operating-location', ['location_id' => $secondary->id])->assertOk();
        $this->getJson('/api/v1/raw-materials/workbench')->assertForbidden();
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $this->getJson('/api/v1/raw-materials/workbench')->assertStatus(503);
    }

    public function test_workbench_excludes_tombstoned_drafts_and_requires_a_session(): void
    {
        [$material] = $this->b2Draft();
        $this->selectRawMaterialApiContext($this->steward);
        $this->getJson('/api/v1/raw-materials/workbench')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $material['id']);
        $this->rawMaterialService->deleteDraft($this->steward, $this->location, $material['id'], $this->rawVersion($material['id']));
        $this->getJson('/api/v1/raw-materials/workbench')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/raw-materials/workbench')->assertUnauthorized();
    }
}
