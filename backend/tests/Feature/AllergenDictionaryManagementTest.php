<?php

namespace Tests\Feature;

use App\Domain\Allergens\InternationalAllergenDictionaryService;
use App\Domain\Authorization\BusinessRole;
use App\Domain\Identity\MembershipService;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\AllergenDictionariesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\AuthorizationTestCase;

class AllergenDictionaryManagementTest extends AuthorizationTestCase
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
        $location = $this->kitchen();
        $admin = $this->user('allergen-admin@example.test');
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

    public function test_master_seed_is_complete_idempotent_and_preserves_future_source_status(): void
    {
        [$admin] = $this->administratorContext();
        $this->seedDictionaries();
        $seedCounts = [
            'sources' => DB::table('international_allergen_sources')->count(),
            'versions' => DB::table('international_allergen_dictionary_versions')->count(),
            'canonical' => DB::table('international_allergen_canonical_definitions')->count(),
            'links' => DB::table('international_allergen_source_entries')->count(),
            'pool_versions' => DB::table('raw_material_allergen_pool_versions')->count(),
        ];

        $this->seedDictionaries();

        $this->assertSame($seedCounts, [
            'sources' => DB::table('international_allergen_sources')->count(),
            'versions' => DB::table('international_allergen_dictionary_versions')->count(),
            'canonical' => DB::table('international_allergen_canonical_definitions')->count(),
            'links' => DB::table('international_allergen_source_entries')->count(),
            'pool_versions' => DB::table('raw_material_allergen_pool_versions')->count(),
        ]);
        $this->assertSame(['sources' => 8, 'versions' => 9, 'canonical' => 54, 'links' => 178, 'pool_versions' => 1], $seedCounts);

        $sources = $this->getJson('/api/v1/administration/allergens/dictionaries')
            ->assertOk()->json('data');
        $this->assertCount(8, $sources);
        $versions = collect($sources)->flatMap(fn (array $source) => $source['versions']);
        $this->assertCount(9, $versions);
        $expectedEntryCounts = [
            'EU_1169_2011_ANNEX_II' => 28,
            'US_FDA_MAJOR_ALLERGENS' => 24,
            'CODEX_CXS_1_1985_2024' => 25,
            'JP_CAA_ALLERGENS_2026_04' => 29,
            'CN_GB7718_2011_CURRENT' => 8,
            'CN_GB7718_2025_FUTURE' => 8,
            'KR_MFDS_ALLERGENS' => 19,
            'TW_TFDA_ALLERGENS_2020' => 17,
            'TH_FDA_NOTIFICATION_474_2026' => 20,
        ];
        foreach ($versions as $version) {
            $databaseCount = DB::table('international_allergen_source_entries')
                ->where('dictionary_version_id', $version['id'])
                ->count();
            $this->assertSame($expectedEntryCounts[$version['code']], $databaseCount, $version['code'].' database count');
            $this->assertSame($databaseCount, $version['entry_count'], $version['code'].' dictionary count');
            $entriesResponse = $this->getJson('/api/v1/administration/allergens/dictionaries/'.$version['id'].'/entries')
                ->assertOk()->json('data');
            $this->assertCount($expectedEntryCounts[$version['code']], $entriesResponse, $version['code'].' API count');
        }
        $canonicalResponse = $this->getJson('/api/v1/administration/allergens/canonical')->assertOk();
        $this->assertCount(54, $canonicalResponse->json('data'));
        $canonicalPayload = json_decode($canonicalResponse->getContent(), flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $canonicalPayload->data[0]->original_names);
        $this->getJson('/api/v1/administration/allergens/canonical?search=gluten')
            ->assertOk()->assertJsonPath('data.0.id', 'ALG_CEREALS_GLUTEN');

        $future = $versions->firstWhere('code', 'CN_GB7718_2025_FUTURE');
        $this->assertNotNull($future);
        $this->assertSame('FUTURE', $future['status']);
        $this->assertSame('2027-03-16', $future['effective_from']);
        $futureResponse = $this->getJson('/api/v1/administration/allergens/dictionaries/'.$future['id'].'/entries')
            ->assertOk();
        $futureEntries = $futureResponse->json('data');
        $futurePayload = json_decode($futureResponse->getContent(), flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $futurePayload->data[0]->metadata);
        $this->assertCount(8, $futureEntries);
        $this->assertSame(['FUTURE_MANDATORY'], array_values(array_unique(array_column($futureEntries, 'status'))));
        $this->assertSame(['2027-03-16'], array_values(array_unique(array_column($futureEntries, 'legal_effective_from'))));
    }

    public function test_manual_create_is_deduplicated_audited_and_publishes_a_new_raw_snapshot(): void
    {
        [$admin, $location] = $this->administratorContext();
        $this->seedDictionaries();
        $versionId = DB::table('international_allergen_dictionary_versions')
            ->where('code', 'EU_1169_2011_ANNEX_II')->value('id');
        $existingAllergenId = DB::table('international_allergen_source_entries')
            ->where('dictionary_version_id', $versionId)->value('allergen_id');
        $service = app(InternationalAllergenDictionaryService::class);
        $input = [
            'source_name' => 'Alergen testowy',
            'status' => 'RECOMMENDED',
            'listing_rule' => 'DIRECT',
            'legal_effective_from' => '2030-01-01',
            'transition_end' => null,
            'source_scope' => 'TEST_SCOPE',
            'threshold' => null,
            'exceptions' => ['Testowy wyjątek'],
            'note' => null,
            'metadata' => [],
        ];

        try {
            $service->createEntry($admin, $location, $versionId, [
                ...$input,
                'canonical_allergen_id' => $existingAllergenId,
            ]);
            $this->fail('A duplicate source-to-canonical link was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $canonicalId = 'TEST_'.Str::upper(Str::random(10));
        $poolCount = DB::table('raw_material_allergen_pool_versions')->count();
        $seedCatalogId = DB::table('raw_material_allergen_pool_versions')
            ->orderByDesc('version_number')->value('catalog_version_id');
        $rollbackMarker = 'ROLLBACK_ALLERGEN_SEED_TEST';
        try {
            DB::transaction(function () use (
                $service,
                $admin,
                $location,
                $versionId,
                $canonicalId,
                $input,
                $seedCatalogId,
                $rollbackMarker,
            ): void {
                $entry = $service->createEntry($admin, $location, $versionId, [
                    ...$input,
                    'new_canonical' => [
                        'canonical_pl' => 'Alergen próbny '.$canonicalId,
                        'parent_id' => null,
                        'synonyms_pl' => [],
                        'original_names' => [],
                    ],
                ]);
                $generatedCanonicalId = $entry['allergen_id'];
                $this->assertMatchesRegularExpression('/^ALG_[A-Z0-9_]{1,35}$/', $generatedCanonicalId);
                $this->assertSame('RECOMMENDED', $entry['status']);
                $this->assertSame(['Testowy wyjątek'], $entry['exceptions']);
                $this->assertSame('2030-01-01', $entry['legal_effective_from']);
                $this->assertSame($admin->id, DB::table('international_allergen_canonical_definitions')
                    ->where('id', $generatedCanonicalId)->value('last_manual_edit_by'));
                $sourceCodes = DB::table('raw_material_allergen_definitions')
                    ->where('catalog_version_id', DB::table('raw_material_allergen_pool_versions')
                        ->orderByDesc('version_number')->value('catalog_version_id'))
                    ->where('canonical_allergen_id', $generatedCanonicalId)
                    ->value('source_codes');
                $this->assertSame(['EU'], json_decode($sourceCodes, true, flags: JSON_THROW_ON_ERROR));
                $this->assertDatabaseHas('raw_material_audit_events', [
                    'subject_type' => 'ALLERGEN_DICTIONARY',
                    'subject_id' => $entry['id'],
                    'event_code' => 'CANONICAL_AND_SOURCE_ENTRY_CREATED',
                ]);

                $createdCatalogId = DB::table('raw_material_allergen_pool_versions')
                    ->orderByDesc('version_number')->value('catalog_version_id');
                $archivedEntry = $service->archiveEntry($admin, $location, $entry['id']);
                $this->assertNotNull($archivedEntry['archived_at']);
                $entryArchiveCatalogId = DB::table('raw_material_allergen_pool_versions')
                    ->orderByDesc('version_number')->value('catalog_version_id');
                $archivedSourceCodes = DB::table('raw_material_allergen_definitions')
                    ->where('catalog_version_id', $entryArchiveCatalogId)
                    ->where('canonical_allergen_id', $generatedCanonicalId)
                    ->value('source_codes');
                $this->assertSame([], json_decode($archivedSourceCodes, true, flags: JSON_THROW_ON_ERROR));

                $archivedCanonical = $service->archiveCanonical($admin, $location, $versionId, $generatedCanonicalId);
                $this->assertSame('ARCHIVED', $archivedCanonical['record_status']);
                $finalCatalogId = DB::table('raw_material_allergen_pool_versions')
                    ->orderByDesc('version_number')->value('catalog_version_id');
                $this->assertDatabaseMissing('raw_material_allergen_definitions', [
                    'catalog_version_id' => $finalCatalogId,
                    'canonical_allergen_id' => $generatedCanonicalId,
                ]);
                $this->assertDatabaseHas('raw_material_allergen_definitions', [
                    'catalog_version_id' => $createdCatalogId,
                    'canonical_allergen_id' => $generatedCanonicalId,
                ]);
                $this->assertDatabaseHas('raw_material_allergen_catalog_versions', [
                    'id' => $seedCatalogId,
                    'status' => 'PUBLISHED',
                ]);
                throw new RuntimeException($rollbackMarker);
            });
        } catch (RuntimeException $exception) {
            $this->assertSame($rollbackMarker, $exception->getMessage());
        }

        $this->assertSame($poolCount, DB::table('raw_material_allergen_pool_versions')->count());
        $this->assertDatabaseMissing('international_allergen_canonical_definitions', ['canonical_pl' => 'Alergen próbny '.$canonicalId]);
    }
}
