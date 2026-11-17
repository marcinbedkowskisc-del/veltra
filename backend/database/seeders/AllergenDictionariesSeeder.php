<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AllergenDictionariesSeeder extends Seeder
{
    private const MASTER_DOCUMENT = 'VELTRA_ALLERGEN_DICTIONARIES_MASTER_v1.1';

    private const SOURCE_NAMES = [
        'EU' => 'Unia Europejska',
        'US' => 'USA — FDA',
        'CODEX' => 'Codex Alimentarius',
        'JP' => 'Japonia',
        'CN' => 'Chiny',
        'KR' => 'Korea Południowa',
        'TW' => 'Tajwan',
        'TH' => 'Tajlandia',
    ];

    private const SOURCE_LANGUAGES = [
        'EU' => 'en',
        'US' => 'en',
        'CODEX' => 'en',
        'JP' => 'ja',
        'CN' => 'zh',
        'KR' => 'ko',
        'TW' => 'zh-TW',
        'TH' => 'th',
    ];

    public function run(): void
    {
        $path = database_path('data/allergen_dictionaries_master_v1_1.json');
        $master = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSeedCounts($master);

        DB::transaction(function () use ($master): void {
            $importedAt = now();
            $versions = [];

            foreach ($master['source_dictionaries'] as $dictionary) {
                $sourceCode = $dictionary['jurisdiction'];
                DB::table('international_allergen_sources')->insertOrIgnore([
                    'source_code' => $sourceCode,
                    'jurisdiction' => $sourceCode,
                    'name_pl' => self::SOURCE_NAMES[$sourceCode],
                ]);

                $metadata = $dictionary;
                foreach ([
                    'code',
                    'jurisdiction',
                    'authority',
                    'status',
                    'official_url',
                    'effective_from',
                    'replaced_on',
                    'last_verified_on',
                    'last_manual_edit_at',
                    'seed_imported_at',
                    'manual_edit_date_policy',
                ] as $key) {
                    unset($metadata[$key]);
                }

                DB::table('international_allergen_dictionary_versions')->insertOrIgnore([
                    'id' => Str::uuid()->toString(),
                    'source_code' => $sourceCode,
                    'code' => $dictionary['code'],
                    'authority' => $dictionary['authority'],
                    'status' => $dictionary['status'],
                    'official_url' => $dictionary['official_url'] ?? null,
                    'effective_from' => $dictionary['effective_from'] ?? null,
                    'effective_until' => $dictionary['replaced_on'] ?? null,
                    'last_verified_on' => $dictionary['last_verified_on'] ?? null,
                    'last_manual_edit_at' => null,
                    'last_manual_edit_by' => null,
                    'seed_imported_at' => $importedAt,
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);

                $versions[$dictionary['code']] = DB::table('international_allergen_dictionary_versions')
                    ->where('code', $dictionary['code'])->value('id');
            }

            $remaining = collect($master['allergens'])->keyBy('id');
            while ($remaining->isNotEmpty()) {
                $insertedInPass = false;
                foreach ($remaining as $id => $allergen) {
                    if ($allergen['parent_id'] !== null && $remaining->has($allergen['parent_id'])) {
                        continue;
                    }

                    DB::table('international_allergen_canonical_definitions')->insertOrIgnore([
                        'id' => $allergen['id'],
                        'parent_id' => $allergen['parent_id'],
                        'canonical_pl' => $allergen['canonical_pl'],
                        'synonyms_pl' => json_encode($allergen['synonyms_pl'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'original_names' => json_encode((object) $allergen['original_names'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'record_status' => $allergen['record_status'],
                        'created_at' => $importedAt,
                        'updated_at' => $importedAt,
                    ]);
                    $remaining->forget($id);
                    $insertedInPass = true;
                }
                if (! $insertedInPass) {
                    throw new RuntimeException('Canonical allergen seed contains a missing or cyclic parent.');
                }
            }

            $canonical = collect($master['allergens'])->keyBy('id');
            foreach ($master['allergen_source_links'] as $link) {
                $dictionaryVersionId = $versions[$link['source_code']] ?? null;
                if ($dictionaryVersionId === null) {
                    throw new RuntimeException('Allergen seed references an unknown source dictionary.');
                }

                $names = $canonical[$link['allergen_id']]['original_names'] ?? [];
                $source = collect($master['source_dictionaries'])->firstWhere('code', $link['source_code']);
                $language = self::SOURCE_LANGUAGES[$source['jurisdiction']];
                $sourceName = $names[$language] ?? $canonical[$link['allergen_id']]['canonical_pl'];

                DB::table('international_allergen_source_entries')->insertOrIgnore([
                    'id' => Str::uuid()->toString(),
                    'dictionary_version_id' => $dictionaryVersionId,
                    'allergen_id' => $link['allergen_id'],
                    'source_name' => $sourceName,
                    'status' => $link['status'],
                    'listing_rule' => $link['listing_rule'],
                    'legal_effective_from' => $link['legal_effective_from'] ?? null,
                    'transition_end' => $link['transition_end'] ?? null,
                    'source_scope' => $link['source_scope'],
                    'threshold' => isset($link['threshold'])
                        ? json_encode($link['threshold'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                        : null,
                    'exceptions' => json_encode($link['exceptions'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'note' => $link['note'] ?? null,
                    'metadata' => json_encode((object) [], JSON_THROW_ON_ERROR),
                    'created_at' => $importedAt,
                    'updated_at' => $importedAt,
                ]);
            }

            $existingSeedSnapshot = DB::table('raw_material_allergen_pool_versions')
                ->where('seed_document_id', self::MASTER_DOCUMENT)->exists();
            if (! $existingSeedSnapshot) {
                $catalogVersionId = Str::uuid()->toString();
                $versionNumber = (int) DB::table('raw_material_allergen_catalog_versions')
                    ->max('version_number') + 1;
                DB::table('raw_material_allergen_catalog_versions')->insert([
                    'id' => $catalogVersionId,
                    'version_number' => $versionNumber,
                    'status' => 'DRAFT',
                    'created_at' => $importedAt,
                    'published_at' => null,
                ]);

                $sourceCodesByAllergen = DB::table('international_allergen_source_entries as entries')
                    ->join('international_allergen_dictionary_versions as versions', 'versions.id', '=', 'entries.dictionary_version_id')
                    ->whereNull('entries.archived_at')
                    ->select('entries.allergen_id', 'versions.source_code')
                    ->get()
                    ->groupBy('allergen_id')
                    ->map(fn ($rows) => $rows->pluck('source_code')->unique()->sort()->values()->all());

                foreach ($master['allergens'] as $position => $allergen) {
                    DB::table('raw_material_allergen_definitions')->insert([
                        'catalog_version_id' => $catalogVersionId,
                        'allergen_code' => $allergen['id'],
                        'name_pl' => $allergen['canonical_pl'],
                        'display_order' => $position + 1,
                        'is_active' => $allergen['record_status'] === 'ACTIVE',
                        'canonical_allergen_id' => $allergen['id'],
                        'source_codes' => json_encode($sourceCodesByAllergen[$allergen['id']] ?? [], JSON_THROW_ON_ERROR),
                    ]);
                }

                DB::table('raw_material_allergen_catalog_versions')->where('id', $catalogVersionId)->update([
                    'status' => 'PUBLISHED',
                    'published_at' => $importedAt,
                ]);
                DB::table('raw_material_allergen_pool_versions')->insert([
                    'id' => Str::uuid()->toString(),
                    'catalog_version_id' => $catalogVersionId,
                    'version_number' => $versionNumber,
                    'seed_document_id' => self::MASTER_DOCUMENT,
                    'created_at' => $importedAt,
                ]);
            }
        });
    }

    private function assertSeedCounts(array $master): void
    {
        if (count($master['allergens'] ?? []) !== 54
            || count($master['source_dictionaries'] ?? []) !== 9
            || count($master['allergen_source_links'] ?? []) !== 178) {
            throw new RuntimeException('The approved allergen seed must contain exactly 54 canonical definitions, 9 dictionary versions and 178 source links.');
        }
    }
}
