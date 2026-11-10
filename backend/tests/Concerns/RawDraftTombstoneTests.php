<?php

namespace Tests\Concerns;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialException;
use App\Domain\RawMaterials\RawMaterialPermission;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

trait RawDraftTombstoneTests
{
    public function test_stage3e_b41_tombstones_only_eligible_draft_and_preserves_history_and_sku(): void
    {
        [$material, $input] = $this->b2Draft();
        $revision = $this->rawMaterialService->revise(
            $this->steward,
            $this->location,
            $material['id'],
            array_replace($input, ['name' => 'Szkic po pierwszej zmianie']),
            $material['version'],
        );
        $revision = $this->rawMaterialService->revise(
            $this->steward,
            $this->location,
            $material['id'],
            array_replace($input, ['name' => 'Szkic po drugiej zmianie']),
            $revision['version'],
        );
        $revisionCount = DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count();
        $priorAuditCount = DB::table('raw_material_audit_events')->where('subject_id', $material['id'])->count();
        $assignment = DB::table('organization_sku_assignments')
            ->where('organization_id', $this->steward->organization_id)
            ->where('entity_type', 'RAW')
            ->where('entity_id', $material['id'])
            ->first();

        $this->selectRawMaterialApiContext($this->steward);
        $this->deleteJson('/api/v1/raw-materials/'.$material['id'].'/draft', [], [
            'If-Match' => $revision['version'],
        ])->assertNoContent();

        $tombstone = DB::table('raw_materials')->where('id', $material['id'])->first();
        $event = DB::table('raw_material_audit_events')
            ->where('organization_id', $this->steward->organization_id)
            ->where('subject_type', 'RAW_MATERIAL')
            ->where('subject_id', $material['id'])
            ->where('event_code', 'DRAFT_DELETED')
            ->first();
        $this->assertNotNull($tombstone);
        $this->assertNotNull($tombstone->deleted_at);
        $this->assertSame($this->steward->id, $tombstone->deleted_by);
        $this->assertNotSame($revision['version'], $tombstone->edit_version);
        $this->assertSame($tombstone->deleted_operation_id, $event->operation_id);
        $this->assertSame($this->steward->id, $event->actor_user_id);
        $this->assertSame($this->location->id, $event->actor_location_id);
        $this->assertSame('ACCEPTED', $event->outcome);
        $this->assertSame($revision['version'], json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR)['version']);
        $this->assertSame($priorAuditCount + 1, DB::table('raw_material_audit_events')->where('subject_id', $material['id'])->count());
        $this->assertSame($revisionCount, DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count());
        $this->assertSame(
            (array) $assignment,
            (array) DB::table('organization_sku_assignments')
                ->where('organization_id', $this->steward->organization_id)
                ->where('entity_type', 'RAW')
                ->where('entity_id', $material['id'])
                ->first(),
        );
        $this->assertSame(1, DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->where('event_code', 'DRAFT_DELETED')->count());

        $this->getJson('/api/v1/raw-materials/'.$material['id'])->assertNotFound();
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/editor')->assertNotFound();
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/completion')->assertNotFound();
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/history')->assertNotFound();
        $this->getJson('/api/v1/raw-materials/'.$material['id'].'/revisions')->assertNotFound();
        $this->deleteJson('/api/v1/raw-materials/'.$material['id'].'/draft', [], [
            'If-Match' => $tombstone->edit_version,
        ])->assertNotFound();
        $this->assertSame($tombstone->code, DB::table('raw_materials')->where('id', $material['id'])->value('code'));
    }

    public function test_stage3e_b41_requires_a_valid_current_edit_version_without_a_false_audit_event(): void
    {
        [$material, $input] = $this->b2Draft();
        $this->selectRawMaterialApiContext($this->steward);
        $url = '/api/v1/raw-materials/'.$material['id'].'/draft';
        $this->deleteJson($url)->assertStatus(428)->assertJsonPath('error.code', 'RAW_MATERIAL_VERSION_REQUIRED');
        $this->deleteJson($url, [], ['If-Match' => '*'])->assertStatus(422)
            ->assertJsonPath('error.code', 'RAW_MATERIAL_VERSION_INVALID');

        $updated = $this->rawMaterialService->revise(
            $this->steward,
            $this->location,
            $material['id'],
            array_replace($input, ['name' => 'Wersja aktualna']),
            $material['version'],
        );
        $this->deleteJson($url, [], ['If-Match' => $material['version']])->assertConflict()
            ->assertJsonPath('error.code', 'RAW_MATERIAL_VERSION_CONFLICT');
        $this->assertSame($updated['version'], $this->rawVersion($material['id']));
        $this->assertSame(0, DB::table('raw_material_audit_events')
            ->where('subject_id', $material['id'])->where('event_code', 'DRAFT_DELETED')->count());
    }

    public function test_stage3e_b41_rejects_a_draft_with_return_history(): void
    {
        [$material] = $this->b2Draft();
        $submitted = $this->rawMaterialService->submit(
            $this->steward,
            $this->location,
            $material['id'],
            $material['version'],
        );
        $returned = $this->rawMaterialService->returnForChanges(
            $this->steward,
            $this->location,
            $material['id'],
            'Wymagana korekta szkicu',
            $submitted['version'],
        );

        $this->assertSame('DRAFT', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
        $this->assertSame(1, DB::table('raw_material_revision_approval_events')
            ->where('revision_id', $material['revision']['id'])->where('outcome', 'RETURNED')->count());
        try {
            $this->rawMaterialService->deleteDraft(
                $this->steward,
                $this->location,
                $material['id'],
                $returned['version'],
            );
            $this->fail('Szkic z historią zwrotu został oznaczony jako usunięty.');
        } catch (RawMaterialException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame('RAW_MATERIAL_DRAFT_DELETE_INVALID', $exception->errorCode);
        }
        $this->assertNull(DB::table('raw_materials')->where('id', $material['id'])->value('deleted_at'));
    }

    public function test_stage3e_b41_rejects_verification_state(): void
    {
        [$material] = $this->b2Draft();
        $submitted = $this->rawMaterialService->submit(
            $this->steward,
            $this->location,
            $material['id'],
            $material['version'],
        );

        try {
            $this->rawMaterialService->deleteDraft(
                $this->steward,
                $this->location,
                $material['id'],
                $submitted['version'],
            );
            $this->fail('Surowiec w stanie weryfikacji został oznaczony jako usunięty.');
        } catch (RawMaterialException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame('RAW_MATERIAL_DRAFT_DELETE_INVALID', $exception->errorCode);
        }
        $this->assertSame('VERIFICATION', DB::table('raw_materials')->where('id', $material['id'])->value('lifecycle_state'));
    }

    public function test_stage3e_b41_preserves_approved_revision_history_as_a_permanent_dependency(): void
    {
        [$material] = $this->b2Draft();
        $this->attestWorkingRevision($material['id']);
        $submitted = $this->rawMaterialService->submit(
            $this->steward,
            $this->location,
            $material['id'],
            $this->rawVersion($material['id']),
        );
        $approved = $this->rawMaterialService->approve(
            $this->steward,
            $this->location,
            $material['id'],
            'Zatwierdzenie testowe',
            $submitted['version'],
        );
        $approvedRevisionId = DB::table('raw_materials')
            ->where('id', $material['id'])
            ->value('approved_revision_id');

        $this->assertSame(1, DB::table('raw_material_revision_approval_events')
            ->where('revision_id', $approvedRevisionId)->where('outcome', 'APPROVED')->count());
        $this->assertTrue((bool) DB::scalar(
            'SELECT public.raw_materials_draft_has_dependencies(?, ?)',
            [$this->steward->organization_id, $material['id']],
        ));
        try {
            $this->rawMaterialService->deleteDraft(
                $this->steward,
                $this->location,
                $material['id'],
                $approved['version'],
            );
            $this->fail('Zatwierdzony materiał został oznaczony jako usunięty.');
        } catch (RawMaterialException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame('RAW_MATERIAL_DRAFT_DELETE_INVALID', $exception->errorCode);
        }
        $this->assertNull(DB::table('raw_materials')->where('id', $material['id'])->value('deleted_at'));
    }

    public function test_stage3e_b41_rejects_durable_commercial_dependencies_and_prevents_new_links(): void
    {
        [$material] = $this->b2Draft();
        $this->insertCommercialAuditDependency($material['id']);
        $version = $this->rawVersion($material['id']);

        try {
            $this->rawMaterialService->deleteDraft(
                $this->steward,
                $this->location,
                $material['id'],
                $version,
            );
            $this->fail('Trwała zależność handlowa nie zablokowała tombstone.');
        } catch (RawMaterialException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame('RAW_MATERIAL_DRAFT_DELETE_INVALID', $exception->errorCode);
        }
        $this->assertNull(DB::table('raw_materials')->where('id', $material['id'])->value('deleted_at'));
    }

    public function test_stage3e_b41_rejects_durable_document_links_but_keeps_unclaimed_uploads(): void
    {
        $this->b3Storage();
        [$material] = $this->b3DraftWithDocument();
        try {
            $this->rawMaterialService->deleteDraft(
                $this->steward,
                $this->location,
                $material['id'],
                $material['version'],
            );
            $this->fail('Trwały zestaw dokumentów nie zablokował tombstone.');
        } catch (RawMaterialException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame('RAW_MATERIAL_DRAFT_DELETE_INVALID', $exception->errorCode);
        }
        $this->assertSame(1, DB::table('raw_material_revision_document_sets')->count());
        $this->assertSame(1, DB::table('raw_material_document_version_links')->count());

        $this->b3Storage();
        $upload = $this->b3Upload('b41-staged-upload');
        $unclaimed = $this->rawMaterialService->create($this->steward, $this->location, [
            'category_id' => DB::table('raw_material_categories')->value('id'),
            'name' => 'Szkic z uploadem tymczasowym',
            'base_unit_code' => 'g',
        ]);
        $this->rawMaterialService->deleteDraft(
            $this->steward,
            $this->location,
            $unclaimed['id'],
            $unclaimed['version'],
        );
        $this->assertSame('STAGED', DB::table('raw_material_document_uploads')
            ->where('id', $upload['upload_id'])->value('state'));
    }

    public function test_stage3e_b41_prevents_dependencies_after_tombstone_and_keeps_hard_delete_denied(): void
    {
        [$material] = $this->b2Draft();
        $this->rawMaterialService->deleteDraft(
            $this->steward,
            $this->location,
            $material['id'],
            $material['version'],
        );

        try {
            $this->insertCommercialAuditDependency($material['id']);
            $this->fail('Po tombstone utworzono nową zależność RAW.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('raw_material_audit_events')
                ->where('subject_id', $material['id'])->where('event_code', 'DRAFT_DELETED')->count());
        }

        try {
            $this->withSetupConnection(fn ($connection) => $connection->table('raw_materials')
                ->where('id', $material['id'])->delete());
            $this->fail('Bezpośredni DELETE RAW nie został zablokowany.');
        } catch (QueryException) {
            $this->assertNotNull(DB::table('raw_materials')->where('id', $material['id'])->first());
        }
    }

    public function test_stage3e_b41_rolls_back_the_success_event_if_the_tombstone_update_fails(): void
    {
        [$material] = $this->b2Draft();
        $this->withSetupConnection(fn ($connection) => $connection->unprepared(<<<'SQL'
            CREATE FUNCTION public.stage3e_b41_force_tombstone_rollback()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public AS $function$
            BEGIN
                RAISE EXCEPTION 'Injected tombstone rollback';
            END
            $function$;
            CREATE TRIGGER zzzz_stage3e_b41_force_tombstone_rollback
                BEFORE UPDATE ON public.raw_materials
                FOR EACH ROW EXECUTE FUNCTION public.stage3e_b41_force_tombstone_rollback();
            SQL));

        try {
            try {
                $this->rawMaterialService->deleteDraft(
                    $this->steward,
                    $this->location,
                    $material['id'],
                    $material['version'],
                );
                $this->fail('Wstrzyknięta awaria nie przerwała tombstone.');
            } catch (QueryException) {
                $this->assertNull(DB::table('raw_materials')->where('id', $material['id'])->value('deleted_at'));
                $this->assertSame(0, DB::table('raw_material_audit_events')
                    ->where('subject_id', $material['id'])->where('event_code', 'DRAFT_DELETED')->count());
            }
        } finally {
            $this->withSetupConnection(fn ($connection) => $connection->unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS zzzz_stage3e_b41_force_tombstone_rollback ON public.raw_materials;
                DROP FUNCTION IF EXISTS public.stage3e_b41_force_tombstone_rollback();
                SQL));
        }
    }

    public function test_stage3e_b41_permission_is_explicit_and_cannot_be_delegated(): void
    {
        [$material] = $this->b2Draft();
        $this->role($this->steward, BusinessRole::Administrator);
        $administrator = $this->bootstrapAdministrator;
        $this->selectRawMaterialApiContext($administrator);
        $this->deleteJson('/api/v1/raw-materials/'.$material['id'].'/draft', [], [
            'If-Match' => $material['version'],
        ])->assertForbidden();

        $recipient = $this->user('b41-no-delete-grant@example.test');
        app(MembershipService::class)->assign($recipient, $this->location);
        try {
            $this->rawMaterialService->deleteDraft(
                $recipient,
                $this->location,
                $material['id'],
                $material['version'],
            );
            $this->fail('Użytkownik bez capability usunął szkic.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $sourceGrant = DB::table('organization_permission_grants')
            ->where('organization_id', $this->steward->organization_id)
            ->where('user_id', $this->steward->id)
            ->where('permission', RawMaterialPermission::DeleteDraft->value)
            ->whereNull('delegation_source_id')
            ->firstOrFail();
        try {
            $this->withSetupConnection(fn ($connection) => $connection->table('organization_permission_grants')->insert([
                'id' => Str::uuid()->toString(),
                'organization_id' => $this->steward->organization_id,
                'user_id' => $recipient->id,
                'permission' => RawMaterialPermission::DeleteDraft->value,
                'granted_by' => $this->steward->id,
                'valid_from' => now(),
                'delegation_source_id' => $sourceGrant->id,
                'bootstrap_operation_id' => null,
                'reason_code' => 'TEST_NON_DELEGABLE',
            ]));
            $this->fail('Baza dopuściła delegowanie capability tombstone.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('organization_permission_grants')
                ->where('user_id', $recipient->id)
                ->where('permission', RawMaterialPermission::DeleteDraft->value)
                ->count());
        }

        $this->expectException(AuthorizationException::class);
        app(OrganizationRawMaterialPermissionAdministration::class)->grant(
            $this->steward,
            $this->location,
            $recipient,
            RawMaterialPermission::DeleteDraft,
            'Nie deleguj prawa',
        );
    }

    public function test_stage3e_b41_serializes_competing_deletion_requests(): void
    {
        [$material] = $this->b2Draft();
        $version = $material['version'];
        $processes = [];

        try {
            DB::beginTransaction();
            Organization::whereKey($this->steward->organization_id)->lockForUpdate()->firstOrFail();
            foreach ([0, 1] as $index) {
                $application = 'stage3eb41-delete-'.$index;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/concurrent_raw_edit.php'),
                    $this->steward->id,
                    $this->location->id,
                    $material['id'],
                    $version,
                    'delete-draft',
                    '{}',
                    $application,
                ], base_path(), timeout: 30);
                $process->start();
                $processes[] = [$process, $application];
                $this->assertRevisionProcessWaitingOnOrganizationLock($process, $application);
            }
            DB::commit();

            $results = array_map(fn (array $entry): array => $this->finishRevisionProcess($entry[0]), $processes);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([204, 404], $statuses);
            $this->assertSame(1, DB::table('raw_material_audit_events')
                ->where('subject_id', $material['id'])->where('event_code', 'DRAFT_DELETED')->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as [$process]) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_stage3e_b41_serializes_tombstone_against_revision_and_dependency_creation(): void
    {
        [$material, $input] = $this->b2Draft();
        $version = $material['version'];
        $this->runB41Race($material['id'], $version, 'revise', array_replace($input, ['name' => 'Wyścig rewizji']));

        $material = $this->rawMaterialService->create(
            $this->steward,
            $this->location,
            array_replace($input, ['name' => 'Szkic do wyścigu zależności']),
        );
        $version = $material['version'];
        $this->runB41Race($material['id'], $version, 'commercial-dependency', []);
    }

    private function runB41Race(string $materialId, string $version, string $operation, array $payload): void
    {
        $processes = [];
        try {
            DB::beginTransaction();
            Organization::whereKey($this->steward->organization_id)->lockForUpdate()->firstOrFail();
            $operations = $operation === 'revise' ? ['revise', 'delete-draft'] : ['delete-draft', 'commercial-dependency'];
            foreach ($operations as $index => $currentOperation) {
                $application = 'stage3eb41-race-'.$index;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/concurrent_raw_edit.php'),
                    $this->steward->id,
                    $this->location->id,
                    $materialId,
                    $version,
                    $currentOperation,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    $application,
                ], base_path(), timeout: 30);
                $process->start();
                $processes[] = [$process, $application];
                $this->assertRevisionProcessWaitingOnOrganizationLock($process, $application);
            }
            DB::commit();

            $results = array_map(fn (array $entry): array => $this->finishRevisionProcess($entry[0]), $processes);
            $statuses = array_column($results, 'status');
            sort($statuses);
            if ($operation === 'revise') {
                $this->assertTrue($statuses === [200, 409] || $statuses === [204, 404]);
                $this->assertSame(
                    $statuses === [204, 404],
                    DB::table('raw_materials')->where('id', $materialId)->value('deleted_at') !== null,
                );
            } else {
                $this->assertTrue($statuses === [200, 409] || $statuses === [204, 409]);
                $this->assertSame(
                    $statuses === [200, 409] ? 1 : 0,
                    DB::table('raw_materials_commercial_audit_events')
                        ->where('subject_type', 'RAW_MATERIAL')->where('subject_id', $materialId)->count(),
                );
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as [$process]) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    private function insertCommercialAuditDependency(string $materialId): void
    {
        $this->withSetupConnection(fn ($connection) => $connection->table('raw_materials_commercial_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $this->steward->organization_id,
            'operation_id' => Str::uuid()->toString(),
            'actor_user_id' => $this->steward->id,
            'actor_location_id' => $this->location->id,
            'subject_type' => 'RAW_MATERIAL',
            'subject_id' => $materialId,
            'event_code' => 'TEST_DEPENDENCY_CREATED',
            'outcome' => 'ACCEPTED',
            'metadata' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
        ]));
    }
}
