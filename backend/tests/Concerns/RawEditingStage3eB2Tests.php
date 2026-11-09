<?php

namespace Tests\Concerns;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\Modules\ModuleCatalog;
use App\Domain\RawMaterials\Documents\RawMaterialDocumentService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialCsvImportPersistenceService;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialHistoryService;
use App\Domain\RawMaterials\RawMaterialLocationShareAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\LocationModuleState;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\ModuleCatalogFixtures;

trait RawEditingStage3eB2Tests
{
    public function test_stage3e_b2_stale_editor_does_not_overwrite_or_append_audit(): void
    {
        [$material, $input] = $this->b2Draft();
        $version = $material['version'];
        $second = $this->user('b2-second-editor@example.test');
        app(MembershipService::class)->assign($second, $this->location);
        $permissions = app(OrganizationRawMaterialPermissionAdministration::class);
        foreach ([RawMaterialPermission::View, RawMaterialPermission::Edit] as $permission) {
            $permissions->grant($this->steward, $this->location, $second, $permission, 'Drugi edytor');
        }
        $saved = $this->rawMaterialService->revise($second, $this->location, $material['id'], array_replace($input, ['name' => 'Zapis drugiego aktora']), $version);
        $this->assertNotSame($version, $saved['version']);
        $before = DB::table('raw_material_audit_events')->count();
        try {
            $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input, $version);
            $this->fail('Stara wersja nadpisała zmianę.');
        } catch (RawMaterialException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame('RAW_MATERIAL_VERSION_CONFLICT', $exception->errorCode);
            $this->assertSame([], $exception->details);
        }
        $editor = $this->rawMaterialService->editor($this->steward, $this->location, $material['id']);
        $this->assertSame('Zapis drugiego aktora', $editor['revision']['name']);
        $this->assertSame(2, DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count());
        $this->assertSame($before, DB::table('raw_material_audit_events')->count());
    }

    public function test_stage3e_b2_http_preconditions_are_required_and_return_no_current_revision_on_conflict(): void
    {
        [$material, $input] = $this->b2Draft();
        $this->selectRawMaterialApiContext($this->steward);
        $url = '/api/v1/raw-materials/'.$material['id'];
        $this->getJson($url.'/editor')->assertOk()->assertJsonPath('data.version', $material['version']);
        $this->patchJson($url.'/draft', $input)->assertStatus(428)->assertJsonPath('error.code', 'RAW_MATERIAL_VERSION_REQUIRED');
        $this->patchJson($url.'/draft', $input, ['If-Match' => '*'])->assertUnprocessable();
        $saved = $this->patchJson($url.'/draft', $input, ['If-Match' => '"'.$material['version'].'"'])->assertOk()->json('data');
        $response = $this->postJson($url.'/submit', [], ['If-Match' => $material['version']])->assertConflict();
        $this->assertSame(['error'], array_keys($response->json()));
        $this->assertArrayNotHasKey('details', $response->json('error'));
        $this->postJson($url.'/submit', [], ['If-Match' => $saved['version']])->assertOk();
        $this->assertSame(2, DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count());
    }

    public function test_stage3e_b2_every_revision_and_lifecycle_path_requires_observed_version(): void
    {
        [$material, $input] = $this->b2Draft();
        $calls = [
            fn () => $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input),
            fn () => $this->rawMaterialService->reviseAllergenDeclaration($this->steward, $this->location, $material['id'], ['status' => 'UNKNOWN', 'items' => []]),
            fn () => $this->rawMaterialService->reviseStorageVariants($this->steward, $this->location, $material['id'], []),
            fn () => $this->rawMaterialService->submit($this->steward, $this->location, $material['id']),
        ];
        foreach (['approve', 'returnForChanges', 'block', 'unblock', 'archive', 'restore'] as $method) {
            $calls[] = fn () => $this->rawMaterialService->{$method}($this->steward, $this->location, $material['id'], 'Test precondition');
        }
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Ścieżka mutacji pominęła wersję.');
            } catch (RawMaterialException $exception) {
                $this->assertSame('RAW_MATERIAL_VERSION_REQUIRED', $exception->errorCode);
            }
        }
        $this->assertSame($material['version'], $this->rawVersion($material['id']));
    }

    public function test_stage3e_b2_completion_distinguishes_valid_incomplete_draft_from_invalid_edits(): void
    {
        [$material, $input] = $this->b2Draft();
        $report = $this->rawMaterialService->completion($this->steward, $this->location, $material['id']);
        $this->assertSame(0, $report['error_count']);
        $this->assertSame(1, $report['missing_requirement_count']);
        $this->assertSame('allergen_declaration', $report['missing_requirements'][0]['field']);
        $this->assertSame('allergens', $report['missing_requirements'][0]['section']);
        $this->assertNotContains('approve', $report['available_actions']);
        $this->assertContains('submit', $report['available_actions']);
        $this->assertArrayNotHasKey('percentage_complete', $report);
        $bad = $this->rawMaterialService->validateEdits($this->steward, $this->location, $material['id'], $input + ['nominal_quantity' => '0']);
        $this->assertSame(1, $bad['error_count']);
        $this->assertSame(1, $bad['missing_requirement_count']);
        $this->assertSame('nominal_quantity', $bad['errors'][0]['field']);
        $this->assertSame([], $bad['available_actions']);
        $this->assertSame($material['version'], $this->rawVersion($material['id']));
        $this->assertSame(1, DB::table('raw_material_revisions')->count());
    }

    public function test_stage3e_b2_completion_and_approval_share_allergen_and_integrity_requirements(): void
    {
        [$material] = $this->b2Draft();
        $submitted = $this->rawMaterialService->submit($this->steward, $this->location, $material['id'], $material['version']);
        $report = $this->rawMaterialService->completion($this->steward, $this->location, $material['id']);
        $this->assertNotContains('approve', $report['available_actions']);
        try {
            $this->rawMaterialService->approve($this->steward, $this->location, $material['id'], 'Test', $submitted['version']);
            $this->fail('Zatwierdzono niekompletną deklarację.');
        } catch (RawMaterialException $exception) {
            $this->assertSame($report['missing_requirements'][0]['code'], $exception->errorCode);
        }
        $this->rawMaterialService->returnForChanges($this->steward, $this->location, $material['id'], 'Uzupełnij alergeny', $submitted['version']);
        $this->attestWorkingRevision($material['id']);
        $this->rawMaterialService->submit($this->steward, $this->location, $material['id'], $this->rawVersion($material['id']));
        $report = $this->rawMaterialService->completion($this->steward, $this->location, $material['id']);
        $this->assertSame(0, $report['missing_requirement_count']);
        $this->assertContains('approve', $report['available_actions']);
        $this->assertSame('VERIFICATION', $report['lifecycle_state']);
        $this->app->instance(ModuleCatalog::class, ModuleCatalogFixtures::ready());
        $this->selectRawMaterialApiContext($this->steward);
        foreach (['editor', 'completion', 'history'] as $path) {
            $this->getJson('/api/v1/raw-materials/'.$material['id'].'/'.$path)->assertStatus(503);
        }
        $this->postJson('/api/v1/raw-materials/'.$material['id'].'/validation', [])->assertStatus(503);
    }

    public function test_stage3e_b2_unsaved_specialized_sections_reuse_actual_validation_without_writing(): void
    {
        [$material] = $this->b2Draft();
        $catalog = $this->publishedAllergenCatalogFixture();
        $report = $this->rawMaterialService->validateEdits($this->steward, $this->location, $material['id'], [
            'status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $catalog, 'items' => [],
        ], 'allergens');
        $this->assertSame(0, $report['error_count']);
        $this->assertSame(0, $report['missing_requirement_count']);
        $bad = $this->rawMaterialService->validateEdits($this->steward, $this->location, $material['id'], [
            'status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $catalog,
            'items' => [['allergen_code' => 'MILK', 'exposure_kind' => 'DIRECT']],
        ], 'allergens');
        $this->assertGreaterThan(0, $bad['error_count']);
        $this->assertSame('items', $bad['errors'][0]['field']);
        $variant = ['variant_code' => 'CHILLED', 'name_pl' => 'Chłodnia', 'storage_type' => 'CHILLED',
            'temperature_min_c' => '8', 'temperature_max_c' => '2'];
        $bad = $this->rawMaterialService->validateEdits($this->steward, $this->location, $material['id'], ['variants' => [$variant]], 'storage');
        $this->assertSame(1, $bad['error_count']);
        $this->assertSame('variants.0.temperature_max_c', $bad['errors'][0]['field']);
        $this->selectRawMaterialApiContext($this->steward);
        $this->postJson('/api/v1/raw-materials/'.$material['id'].'/validation/storage', ['variants' => [$variant]])
            ->assertOk()->assertJsonPath('data.error_count', 1);
        $this->assertSame($material['version'], $this->rawVersion($material['id']));
        $this->assertSame(1, DB::table('raw_material_revisions')->count());
    }

    public function test_stage3e_b2_lifecycle_versions_prevent_aba_and_restore_preserves_b1_quantities(): void
    {
        $material = $this->stage3eMaterial('Przywrócenie ilości B2', 'g', '100', '75');
        $old = $material['version'];
        $blocked = $this->rawMaterialService->block($this->steward, $this->location, $material['id'], 'Test blokady', $old);
        $active = $this->rawMaterialService->unblock($this->steward, $this->location, $material['id'], 'Test odblokowania', $blocked['version']);
        $this->assertNotSame($old, $active['version']);
        try {
            $this->rawMaterialService->archive($this->steward, $this->location, $material['id'], 'Stara wersja', $old);
            $this->fail('Powrót do stanu ACTIVE ponownie uaktywnił starą wersję.');
        } catch (RawMaterialException $exception) {
            $this->assertSame('RAW_MATERIAL_VERSION_CONFLICT', $exception->errorCode);
        }
        $archived = $this->rawMaterialService->archive($this->steward, $this->location, $material['id'], 'Test archiwum', $active['version']);
        $this->rawMaterialService->restore($this->steward, $this->location, $material['id'], 'Test przywrócenia', $archived['version']);
        $pending = $this->rawMaterialService->editor($this->steward, $this->location, $material['id']);
        $this->assertSame('100.000000', $pending['revision']['nominal_quantity']);
        $this->assertSame('75.000000', $pending['revision']['usable_quantity']);
        $this->b2Race($material['id'], $pending['version'], 'approve', 'return', []);
    }

    public function test_stage3e_b2_audit_metadata_history_pagination_and_canonical_actor(): void
    {
        [$material, $input] = $this->b2Draft();
        foreach (range(1, 26) as $index) {
            $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], array_replace($input, ['description' => 'Zmiana '.$index]), $this->rawVersion($material['id']));
        }

        $service = app(RawMaterialHistoryService::class);
        $first = $service->history($this->steward, $this->location, $material['id'], ['per_page' => 25]);
        $again = $service->history($this->steward, $this->location, $material['id'], ['per_page' => 25]);
        $last = $service->history($this->steward, $this->location, $material['id'], ['per_page' => 25, 'page' => 2]);
        $this->assertSame($first, $again);
        $this->assertSame(27, $first['meta']['total']);
        $this->assertCount(2, $last['data']);
        $this->assertSame([], array_intersect(array_column($first['data'], 'id'), array_column($last['data'], 'id')));
        $shown = $this->rawMaterialService->show($this->steward, $this->location, $material['id']);
        $this->assertSame($first['data'][0]['occurred_at'], $shown['last_update']['occurred_at']);
        $this->assertSame($this->steward->id, $shown['last_update']['actor']['id']);
        $this->assertSame($this->steward->first_name, $shown['last_update']['actor']['first_name']);
        $this->assertSame('/api/v1/raw-materials/'.$material['id'].'/history', $shown['history_url']);
        $this->assertArrayNotHasKey('metadata', $first['data'][0]);
        $this->assertArrayNotHasKey('email', $first['data'][0]['actor']);
        $this->selectRawMaterialApiContext($this->steward);
        $this->getJson($shown['history_url'].'?per_page=25&page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($shown['history_url'].'?per_page=1')->assertUnprocessable();
    }

    public function test_stage3e_b2_audit_financial_events_require_their_own_grant(): void
    {
        $this->role($this->steward, BusinessRole::Administrator);
        $material = $this->stage3eMaterial('Audyt finansowy B2', 'kg', '100');
        $this->stage3ePrice($material['id'], '0.123456', 'b2-financial-audit');
        $reader = $this->user('b2-audit-reader@example.test');
        app(MembershipService::class)->assign($reader, $this->location);
        $grants = app(OrganizationRawMaterialPermissionAdministration::class);
        foreach ([RawMaterialPermission::View, RawMaterialPermission::AuditView] as $permission) {
            $grants->grant($this->steward, $this->location, $reader, $permission, 'Test audytu');
        }
        $history = app(RawMaterialHistoryService::class);
        $this->assertSame([], $this->rawMaterialService->completion($reader, $this->location, $material['id'])['available_actions']);
        $before = $history->history($reader, $this->location, $material['id'], []);
        $this->assertNotContains('REFERENCE_PRICE_SET', array_column($before['data'], 'event_code'));
        $this->assertStringNotContainsString('0.123456', json_encode($before));
        $grants->grant($this->steward, $this->location, $reader, RawMaterialPermission::ReferencePriceView, 'Test ceny w audycie');
        $after = $history->history($reader, $this->location, $material['id'], []);
        $this->assertContains('REFERENCE_PRICE_SET', array_column($after['data'], 'event_code'));
        $this->assertSame($before['meta']['total'] + 1, $after['meta']['total']);
        app(RawMaterialCsvImportPersistenceService::class)->recordPreparedBatch(
            $this->steward, $this->location, str_repeat('b', 64), 'b2-audit-import-price',
            [$this->csvImportRow(['code' => $material['code'], 'name' => 'Audyt finansowy B2'], [
                'unit' => 'kg', 'price_net' => '0.5', 'normalized_net_pln_per_base_unit' => '0.5',
                'effective_date' => now()->addDay()->toDateString(),
            ])],
        );
        $imported = $history->history($reader, $this->location, $material['id'], []);
        $this->assertContains('REFERENCE_PRICE_IMPORTED', array_column($imported['data'], 'event_code'));
    }

    public function test_stage3e_b2_recipient_history_and_metadata_never_reveal_working_revision(): void
    {
        $material = $this->stage3eMaterial('Widoczna rewizja B2', 'g', '100');
        $restaurant = $this->activeRestaurant('B2RECIPIENT');
        app(MembershipService::class)->assign($this->steward, $restaurant);
        $this->role($this->steward, BusinessRole::Administrator);
        LocationModuleState::on('pgsql_setup')->create([
            'organization_id' => $this->steward->organization_id, 'location_id' => $restaurant->id,
            'module_key' => 'RAW_MATERIALS', 'enabled' => true,
            'created_by' => $this->bootstrapAdministrator->id, 'updated_by' => $this->bootstrapAdministrator->id,
        ]);
        app(RawMaterialLocationShareAdministration::class)->share($this->steward, $this->location, $material['id'], $restaurant->id);
        $history = app(RawMaterialHistoryService::class);
        $before = $history->history($this->steward, $restaurant, $material['id'], []);
        $metadata = $this->rawMaterialService->show($this->steward, $restaurant, $material['id'])['last_update'];
        $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], [
            'category_id' => $material['revision']['category_id'], 'name' => 'Ukryty szkic B2', 'base_unit_code' => 'g',
        ], $material['version']);
        $after = $history->history($this->steward, $restaurant, $material['id'], []);
        $this->assertSame($before, $after);
        $this->assertSame(['APPROVED'], array_column($after['data'], 'event_code'));
        $shown = $this->rawMaterialService->show($this->steward, $restaurant, $material['id']);
        $this->assertSame($metadata, $shown['last_update']);
        $this->assertArrayNotHasKey('version', $shown);
        $report = $this->rawMaterialService->completion($this->steward, $restaurant, $material['id']);
        $this->assertSame($material['revision']['id'], $report['revision_id']);
        $this->assertSame([], $report['available_actions']);
        $this->assertNull($report['version']);
    }

    public function test_stage3e_b2_unauthorized_identity_never_receives_conflict_or_history_details(): void
    {
        [$material, $input] = $this->b2Draft();
        $reader = $this->user('b2-denied@example.test');
        app(MembershipService::class)->assign($reader, $this->location);
        $this->selectRawMaterialApiContext($reader);
        $url = '/api/v1/raw-materials/'.$material['id'];
        $this->patchJson($url.'/draft', $input, ['If-Match' => $material['version']])->assertForbidden()->assertJsonMissingPath('data');
        foreach (['editor', 'completion', 'history'] as $path) {
            $response = $this->getJson($url.'/'.$path)->assertForbidden();
            $this->assertStringNotContainsString($material['version'], $response->getContent());
            $this->assertStringNotContainsString($material['revision']['id'], $response->getContent());
        }
    }

    public function test_stage3e_b2_parallel_editors_and_lifecycle_races_have_one_winner(): void
    {
        [$material, $input] = $this->b2Draft();
        $this->b2Race($material['id'], $material['version'], 'revise', 'revise', $input);
        $this->assertSame(2, DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count());
        $this->b2Race($material['id'], $this->rawVersion($material['id']), 'revise', 'submit', $input);
        $active = $this->stage3eMaterial('Wyścig archiwizacji B2', 'g', '100', categoryId: $input['category_id']);
        $this->b2Race($active['id'], $active['version'], 'revise', 'archive', array_replace($input, ['name' => 'Zmiana przed archiwizacją']));
    }

    public function test_stage3e_b2_delete_structurally_blocked_without_weakening_history_or_reusing_sku(): void
    {
        [$material, $input] = $this->b2Draft();
        $auditCount = DB::table('raw_material_audit_events')->count();
        foreach (['raw_materials' => $material['id'], 'raw_material_revisions' => $material['revision']['id']] as $table => $id) {
            try {
                app(AuthorizationMutationContext::class)->run(AuthorizationMutationScope::rawMaterialMutate(), $this->steward->id,
                    fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()));
                $this->fail('Osłabiono ochronę historii.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        }
        $this->assertSame($auditCount, DB::table('raw_material_audit_events')->count());
        $this->assertDatabaseHas('organization_sku_assignments', ['sku' => $material['code']]);
        $next = $this->rawMaterialService->create($this->steward, $this->location, array_replace($input, ['name' => 'Kolejny szkic B2']));
        $this->assertNotSame($material['code'], $next['code']);
        $this->selectRawMaterialApiContext($this->steward);
        $this->deleteJson('/api/v1/raw-materials/'.$material['id'])->assertStatus(405);
    }

    public function test_stage3e_b2_history_includes_material_document_links_without_payload_or_storage_keys(): void
    {
        [$material] = $this->b2Draft();
        Queue::fake();
        app(RawMaterialDocumentService::class)->create(
            $this->steward, $this->location, $material['revision']['id'], 'SPECIFICATION',
            'specyfikacja-b2.txt', 'Poufna testowa specyfikacja B2',
        );
        $history = app(RawMaterialHistoryService::class)->history($this->steward, $this->location, $material['id'], []);
        $this->assertSame('VERSION_LINKED', $history['data'][0]['event_code']);
        $serialized = json_encode($history, JSON_THROW_ON_ERROR);
        foreach (['Poufna testowa', 'specyfikacja-b2.txt', 'storage_key', 'sha256'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $serialized);
        }
        $shown = $this->rawMaterialService->show($this->steward, $this->location, $material['id']);
        $this->assertSame($history['data'][0]['occurred_at'], $shown['last_update']['occurred_at']);
        $this->assertSame($this->steward->id, $shown['last_update']['actor']['id']);
    }

    public function test_stage3e_b2_previously_approved_and_archived_materials_keep_hard_delete_protection(): void
    {
        $material = $this->stage3eMaterial('Chroniona historia B2', 'pc', '4');
        foreach (['ACTIVE', 'ARCHIVED'] as $state) {
            if ($state === 'ARCHIVED') {
                $this->rawMaterialService->archive($this->steward, $this->location, $material['id'], 'Test ochrony historii', $this->rawVersion($material['id']));
            }
            try {
                app(AuthorizationMutationContext::class)->run(AuthorizationMutationScope::rawMaterialMutate(), $this->steward->id,
                    fn () => DB::transaction(fn () => DB::table('raw_materials')->where('id', $material['id'])->delete()));
                $this->fail('Usunięto chroniony materiał.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
            $this->assertDatabaseHas('raw_materials', ['id' => $material['id'], 'lifecycle_state' => $state]);
            $this->assertDatabaseHas('raw_material_revision_approval_events', ['revision_id' => $material['revision']['id'], 'outcome' => 'APPROVED']);
        }
    }

    public function test_stage3e_b2_editor_without_audit_sees_update_time_but_not_actor_or_history(): void
    {
        [$material, $input] = $this->b2Draft();
        $reader = $this->user('b2-editor-no-audit@example.test');
        app(MembershipService::class)->assign($reader, $this->location);
        $grants = app(OrganizationRawMaterialPermissionAdministration::class);
        foreach ([RawMaterialPermission::View, RawMaterialPermission::Edit] as $permission) {
            $grants->grant($this->steward, $this->location, $reader, $permission, 'Test wglądu bez audytu');
        }
        $this->attestWorkingRevision($material['id']);
        $this->rawMaterialService->revise($this->steward, $this->location, $material['id'], $input, $this->rawVersion($material['id']));
        $shown = $this->rawMaterialService->show($reader, $this->location, $material['id']);
        $editor = $this->rawMaterialService->editor($reader, $this->location, $material['id']);
        $this->assertArrayNotHasKey('attested_by', $editor['allergen_declaration']);
        $this->assertArrayNotHasKey('attested_at', $editor['allergen_declaration']);
        $event = DB::table('raw_material_audit_events')->where('subject_id', $material['id'])
            ->where('event_code', 'REVISION_CREATED')->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $this->assertSame($event->occurred_at, $shown['last_update']['occurred_at']);
        $this->assertNull($shown['last_update']['actor']);
        $this->assertNull($shown['history_url']);
        $this->selectRawMaterialApiContext($reader);
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/history')->assertForbidden();
    }

    private function b2Draft(): array
    {
        $input = ['category_id' => $this->category()['id'], 'name' => 'Szkic B2', 'base_unit_code' => 'g'];

        return [$this->rawMaterialService->create($this->steward, $this->location, $input), $input];
    }

    private function b2Race(string $materialId, string $version, string $first, string $second, array $input): void
    {
        $processes = [];
        try {
            DB::beginTransaction();
            Organization::whereKey($this->steward->organization_id)->lockForUpdate()->firstOrFail();
            foreach ([$first, $second] as $index => $operation) {
                $application = 'stage3eb2-'.$operation.'-'.$index;
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/concurrent_raw_edit.php'), $this->steward->id,
                    $this->location->id, $materialId, $version, $operation, json_encode($input, JSON_THROW_ON_ERROR), $application,
                ], base_path(), timeout: 30);
                $process->start();
                $processes[] = $process;
                $this->assertRevisionProcessWaitingOnOrganizationLock($process, $application);
            }
            DB::commit();
            $results = array_map(fn (Process $process): array => $this->finishRevisionProcess($process), $processes);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $conflicts = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 409));
            $this->assertSame('RAW_MATERIAL_VERSION_CONFLICT', $conflicts[0]['code']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
