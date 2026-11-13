<?php

namespace Tests\Concerns;

use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Domain\RawMaterials\OrganizationRawMaterialPermissionAdministration;
use App\Domain\RawMaterials\RawMaterialPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait RawFormWorkflowTests
{
    public function test_create_approved_requires_administrator_and_is_atomic_and_idempotent(): void
    {
        $category = $this->category();
        $catalog = $this->publishedAllergenCatalogFixture();
        $this->selectRawMaterialApiContext($this->steward);
        $reservation = $this->postJson('/api/v1/raw-materials/sku-reservations', [], [
            'Idempotency-Key' => 'stage6a5-complete-1',
        ])->assertCreated()->json('data');
        $payload = [
            'sku_reservation_id' => $reservation['reservation_id'],
            'category_id' => $category['id'],
            'name' => 'Kompletny surowiec',
            'base_unit_code' => 'g',
            'allergen_declaration' => ['status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $catalog, 'items' => []],
        ];
        $url = '/api/v1/raw-materials/create-approved';
        $this->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()->assertJsonPath('data.actions.create_and_approve', false);
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertDatabaseMissing('raw_materials', ['id' => $reservation['reservation_id']]);
        $this->role($this->steward, BusinessRole::Administrator);
        $this->flushSession();
        $this->selectRawMaterialApiContext($this->steward->fresh());
        $this->getJson('/api/v1/security/raw-materials/capabilities')
            ->assertOk()->assertJsonPath('data.actions.create_and_approve', true);
        $bad = $payload;
        $bad['allergen_declaration']['status'] = 'UNKNOWN';
        $bad['allergen_declaration']['catalog_version_id'] = null;
        $this->postJson($url, $bad)->assertUnprocessable();
        $this->assertDatabaseMissing('raw_materials', ['id' => $reservation['reservation_id']]);
        $first = $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.lifecycle_state', 'ACTIVE')->assertJsonPath('data.sku', $reservation['sku'])
            ->json('data');
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.lifecycle_state', 'ACTIVE');
        $changed = $payload;
        $changed['name'] = 'Inna nazwa';
        $this->postJson($url, $changed)->assertConflict();
        $this->assertSame(1, DB::table('raw_material_revisions')->where('raw_material_id', $first['id'])->count());
        foreach (['CREATED', 'SUBMITTED', 'APPROVED'] as $event) {
            $this->assertSame(1, DB::table('raw_material_audit_events')
                ->where('subject_id', $first['id'])->where('event_code', $event)->count());
        }
    }

    public function test_edit_saves_basic_data_and_allergens_in_one_revision_with_version_check(): void
    {
        [$material, $input] = $this->b2Draft();
        $catalog = $this->publishedAllergenCatalogFixture();
        $this->selectRawMaterialApiContext($this->steward);
        $payload = [
            ...$input,
            'name' => 'Szkic z deklaracją',
            'allergen_declaration' => ['status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $catalog, 'items' => []],
        ];
        $url = '/api/v1/raw-materials/'.$material['id'].'/draft';
        $this->patchJson($url, $payload, ['If-Match' => $material['version']])->assertOk()
            ->assertJsonPath('data.revision.name', 'Szkic z deklaracją')
            ->assertJsonPath('data.revision.allergen_state', 'DECLARED');
        $this->patchJson($url, $payload, ['If-Match' => $material['version']])->assertConflict();
        $this->assertSame(2, DB::table('raw_material_revisions')->where('raw_material_id', $material['id'])->count());
    }

    public function test_create_approved_requires_each_effective_grant_even_for_administrators(): void
    {
        $category = $this->category();
        $catalog = $this->publishedAllergenCatalogFixture();
        $this->role($this->steward, BusinessRole::Administrator);
        $required = [RawMaterialPermission::Create, RawMaterialPermission::AllergenManage, RawMaterialPermission::Submit, RawMaterialPermission::Approve];
        $payload = [
            'sku_reservation_id' => Str::uuid()->toString(),
            'category_id' => $category['id'],
            'name' => 'Surowiec bez kompletu uprawnień',
            'base_unit_code' => 'g',
            'allergen_declaration' => ['status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $catalog, 'items' => []],
        ];
        $before = DB::table('raw_materials')->count();
        foreach ($required as $missing) {
            $recipient = $this->user('missing-'.strtolower($missing->name).'@example.test');
            app(MembershipService::class)->assign($recipient, $this->location);
            foreach ($required as $permission) {
                if ($permission !== $missing) {
                    app(OrganizationRawMaterialPermissionAdministration::class)->grant(
                        $this->steward, $this->location, $recipient, $permission, 'Test uprawnień formularza',
                    );
                }
            }
            $this->role($recipient, BusinessRole::Administrator);
            $this->flushSession();
            $this->selectRawMaterialApiContext($recipient);
            $this->getJson('/api/v1/security/raw-materials/capabilities')
                ->assertOk()->assertJsonPath('data.actions.create_and_approve', false);
            $this->postJson('/api/v1/raw-materials/create-approved', $payload)->assertForbidden();
        }
        $this->assertSame($before, DB::table('raw_materials')->count());
    }

    public function test_form_rejects_spoofed_attestation_and_leaves_reservation_unconsumed(): void
    {
        $category = $this->category();
        $catalog = $this->publishedAllergenCatalogFixture();
        $this->role($this->steward, BusinessRole::Administrator);
        $this->selectRawMaterialApiContext($this->steward);
        $reservation = $this->postJson('/api/v1/raw-materials/sku-reservations', [], [
            'Idempotency-Key' => 'stage6a5-forged-attestation',
        ])->assertCreated()->json('data');
        $before = DB::table('raw_materials')->count();
        $payload = [
            'sku_reservation_id' => $reservation['reservation_id'],
            'category_id' => $category['id'], 'name' => 'Próba nieprawidłowej atestacji', 'base_unit_code' => 'g',
            'allergen_declaration' => [
                'status' => 'NO_KNOWN_ALLERGENS', 'catalog_version_id' => $catalog, 'items' => [],
                'attested_by' => $this->bootstrapAdministrator->id,
            ],
        ];
        $this->postJson('/api/v1/raw-materials/create-approved', $payload)->assertUnprocessable();
        $this->assertSame($before, DB::table('raw_materials')->count());
        $this->assertNull(DB::table('raw_material_sku_reservations')->where('id', $reservation['reservation_id'])->value('consumed_at'));
    }
}
