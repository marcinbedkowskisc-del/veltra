<?php

namespace Tests\Feature;

use App\Domain\Allergens\EffectivePrecautionaryAllergenService;
use App\Domain\Allergens\InstallationPrecautionaryAllergenService;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\AllergenDictionariesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationTestCase;

class InstallationPrecautionaryAllergenTest extends AuthorizationTestCase
{
    private function seedDictionaries(): void
    {
        $this->withSetupConnection(function (): void {
            DB::setDefaultConnection('pgsql_setup');
            try {
                app(AllergenDictionariesSeeder::class)->run();
            } finally {
                DB::setDefaultConnection('pgsql');
            }
        });
    }

    private function administratorContext(): array
    {
        $location = $this->activeRestaurant();
        $admin = $this->user('precautionary-admin@example.test');
        $this->role($admin, BusinessRole::Administrator);
        app(MembershipService::class)->assign($admin, $location);
        $this->selectContext($admin, $location);

        return [$admin, $location];
    }

    private function selectContext(User $user, Location $location): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($user, 'web')->putJson('/api/v1/auth/operating-location', [
            'location_id' => $location->id,
        ])->assertOk();
    }

    public function test_list_is_installation_wide_persistent_deduplicated_and_audited(): void
    {
        [$admin, $location] = $this->administratorContext();
        $this->seedDictionaries();
        $canonicalBefore = DB::table('international_allergen_canonical_definitions')
            ->where('id', 'ALG_SOY')->first();
        $sourceRelationshipsBefore = DB::table('international_allergen_source_entries')
            ->where('allergen_id', 'ALG_SOY')->count();
        $columns = DB::getSchemaBuilder()->getColumnListing('installation_precautionary_allergens');
        $this->assertNotContains('organization_id', $columns);
        $this->assertNotContains('location_id', $columns);

        $this->postJson('/api/v1/administration/allergens/maybe-contains', [
            'allergen_id' => 'ALG_SOY',
        ])->assertCreated()
            ->assertJsonPath('data.allergen_id', 'ALG_SOY')
            ->assertJsonPath('data.canonical_pl', 'SOJA');
        $this->assertSame(1, DB::table('installation_precautionary_allergens')
            ->where('allergen_id', 'ALG_SOY')->count());

        $secondLocation = $this->kitchen('CK2');
        app(MembershipService::class)->assign($admin, $secondLocation);
        $this->selectContext($admin, $secondLocation);
        $this->getJson('/api/v1/administration/allergens/maybe-contains')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.allergen_id', 'ALG_SOY');
        $this->postJson('/api/v1/administration/allergens/maybe-contains', [
            'allergen_id' => 'ALG_SOY',
        ])->assertStatus(409);

        $this->deleteJson('/api/v1/administration/allergens/maybe-contains/ALG_SOY')
            ->assertOk()->assertJsonPath('data.allergen_id', 'ALG_SOY');
        $this->assertSame(0, DB::table('installation_precautionary_allergens')->count());
        $this->assertEquals($canonicalBefore, DB::table('international_allergen_canonical_definitions')
            ->where('id', 'ALG_SOY')->first());
        $this->assertSame($sourceRelationshipsBefore, DB::table('international_allergen_source_entries')
            ->where('allergen_id', 'ALG_SOY')->count());
        $this->assertEqualsCanonicalizing(
            ['INSTALLATION_PRECAUTIONARY_ALLERGEN_ADDED', 'INSTALLATION_PRECAUTIONARY_ALLERGEN_REMOVED'],
            DB::table('raw_material_audit_events')
                ->where('subject_type', 'INSTALLATION_PRECAUTIONARY_ALLERGEN')
                ->orderBy('event_code')
                ->pluck('event_code')->all(),
        );
    }

    public function test_administration_routes_deny_non_administrators(): void
    {
        $this->getJson('/api/v1/administration/allergens/maybe-contains')->assertUnauthorized();
        [, $location] = $this->administratorContext();
        $boss = $this->user('precautionary-boss@example.test');
        $this->role($boss, BusinessRole::Boss);
        app(MembershipService::class)->assign($boss, $location);
        $this->selectContext($boss, $location);

        $this->getJson('/api/v1/administration/allergens/maybe-contains')->assertForbidden();
        $this->postJson('/api/v1/administration/allergens/maybe-contains', [
            'allergen_id' => 'ALG_SOY',
        ])->assertForbidden();
        $this->deleteJson('/api/v1/administration/allergens/maybe-contains/ALG_SOY')->assertForbidden();
    }

    public function test_effective_lists_use_exact_ids_and_preserve_global_and_material_specific_entries(): void
    {
        [$admin, $location] = $this->administratorContext();
        $this->seedDictionaries();
        $globalList = app(InstallationPrecautionaryAllergenService::class);
        foreach (['ALG_SOY', 'ALG_MUSTARD', 'ALG_CELERY', 'ALG_CEREALS_GLUTEN'] as $allergenId) {
            $globalList->add($admin, $location, $allergenId);
        }

        $composer = app(EffectivePrecautionaryAllergenService::class);
        $firstMaterial = $composer->forDeclarations(
            ['ALG_SOY', 'ALG_WHEAT'],
            ['ALG_SOY', 'ALG_MILK'],
        );
        $secondMaterial = $composer->forDeclarations(['ALG_MUSTARD']);

        $this->assertEqualsCanonicalizing(
            ['ALG_MILK', 'ALG_CEREALS_GLUTEN', 'ALG_CELERY', 'ALG_MUSTARD'],
            $firstMaterial,
        );
        $this->assertEqualsCanonicalizing(
            ['ALG_CEREALS_GLUTEN', 'ALG_CELERY', 'ALG_SOY'],
            $secondMaterial,
        );
        $this->assertNotContains('ALG_SOY', $firstMaterial);
        $this->assertNotContains('ALG_WHEAT', $firstMaterial);
        $this->assertContains('ALG_CEREALS_GLUTEN', $firstMaterial);
        $this->assertSame(
            ['ALG_CELERY', 'ALG_CEREALS_GLUTEN', 'ALG_MUSTARD', 'ALG_SOY'],
            $globalList->allergenIds(),
        );
    }
}
