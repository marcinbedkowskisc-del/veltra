<?php

namespace App\Domain\Allergens;

use App\Domain\Authorization\AuthorizationMutationContext;
use App\Domain\Authorization\AuthorizationMutationScope;
use App\Domain\Authorization\BusinessAuthorization;
use App\Domain\Authorization\BusinessRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InternationalAllergenDictionaryService
{
    public function __construct(
        private readonly BusinessAuthorization $authorization,
        private readonly AuthorizationMutationContext $contexts,
    ) {}

    public function dictionaries(User $actor, Location $context): array
    {
        $this->assertAdministrator($actor, $context);

        return DB::table('international_allergen_sources')
            ->orderBy('source_code')
            ->get(['source_code', 'jurisdiction', 'name_pl'])
            ->map(function (object $source): array {
                return [
                    'source_code' => $source->source_code,
                    'jurisdiction' => $source->jurisdiction,
                    'name_pl' => $source->name_pl,
                    'versions' => DB::table('international_allergen_dictionary_versions as versions')
                        ->where('versions.source_code', $source->source_code)
                        ->orderBy('versions.effective_from')
                        ->orderBy('versions.code')
                        ->get([
                            'versions.id',
                            'versions.code',
                            'versions.authority',
                            'versions.status',
                            'versions.official_url',
                            'versions.effective_from',
                            'versions.effective_until',
                            'versions.last_verified_on',
                            'versions.last_manual_edit_at',
                            'versions.metadata',
                        ])
                        ->map(fn (object $version): array => [
                            ...$this->dictionaryVersion($version),
                            'entry_count' => DB::table('international_allergen_source_entries')
                                ->where('dictionary_version_id', $version->id)
                                ->whereNull('archived_at')
                                ->count(),
                        ])->all(),
                ];
            })->all();
    }

    public function dictionaryEntries(
        User $actor,
        Location $context,
        string $versionId,
        string $status = 'all',
        ?string $search = null,
    ): array {
        $this->assertAdministrator($actor, $context);
        $this->requireDictionaryVersion($versionId);

        $entries = DB::table('international_allergen_source_entries as entries')
            ->join('international_allergen_canonical_definitions as allergens', 'allergens.id', '=', 'entries.allergen_id')
            ->where('entries.dictionary_version_id', $versionId)
            ->when($status === 'active', fn ($query) => $query->whereNull('entries.archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('entries.archived_at'))
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('entries.source_name', 'ilike', '%'.$search.'%')
                        ->orWhere('allergens.canonical_pl', 'ilike', '%'.$search.'%')
                        ->orWhere('allergens.id', 'ilike', '%'.$search.'%');
                });
            })
            ->orderBy('allergens.canonical_pl')
            ->orderBy('allergens.id')
            ->get([
                'entries.id',
                'entries.dictionary_version_id',
                'entries.allergen_id',
                'entries.source_name',
                'entries.status',
                'entries.listing_rule',
                'entries.legal_effective_from',
                'entries.transition_end',
                'entries.source_scope',
                'entries.threshold',
                'entries.exceptions',
                'entries.note',
                'entries.metadata',
                'entries.archived_at',
                'allergens.canonical_pl',
                'allergens.record_status',
            ]);

        return $entries->map(fn (object $entry): array => $this->presentEntry($entry))->all();
    }

    public function canonicalAllergens(User $actor, Location $context, ?string $search = null): array
    {
        $this->assertAdministrator($actor, $context);
        $query = DB::table('international_allergen_canonical_definitions as allergens')
            ->leftJoin('international_allergen_source_entries as entries', function ($join): void {
                $join->on('entries.allergen_id', '=', 'allergens.id')->whereNull('entries.archived_at');
            })
            ->leftJoin('international_allergen_dictionary_versions as versions', 'versions.id', '=', 'entries.dictionary_version_id')
            ->select([
                'allergens.id',
                'allergens.parent_id',
                'allergens.canonical_pl',
                'allergens.synonyms_pl',
                'allergens.original_names',
                'allergens.record_status',
                'allergens.archived_at',
                DB::raw("coalesce(jsonb_agg(distinct versions.source_code) FILTER (WHERE versions.source_code IS NOT NULL), '[]'::jsonb) AS source_codes"),
                DB::raw('count(distinct entries.id) AS relationship_count'),
            ])
            ->groupBy(
                'allergens.id',
                'allergens.parent_id',
                'allergens.canonical_pl',
                'allergens.synonyms_pl',
                'allergens.original_names',
                'allergens.record_status',
                'allergens.archived_at',
            )
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $query->havingRaw(
                    'allergens.canonical_pl ILIKE ? OR allergens.id ILIKE ? OR allergens.synonyms_pl::text ILIKE ?',
                    ['%'.$search.'%', '%'.$search.'%', '%'.$search.'%'],
                );
            })
            ->orderBy('allergens.canonical_pl')
            ->orderBy('allergens.id');

        return $query->get()->map(fn (object $allergen): array => [
            'id' => $allergen->id,
            'parent_id' => $allergen->parent_id,
            'canonical_pl' => $allergen->canonical_pl,
            'synonyms_pl' => $this->jsonValue($allergen->synonyms_pl),
            'original_names' => $this->jsonObjectValue($allergen->original_names),
            'record_status' => $allergen->record_status,
            'archived_at' => $allergen->archived_at,
            'source_codes' => $this->jsonValue($allergen->source_codes),
            'relationship_count' => (int) $allergen->relationship_count,
        ])->all();
    }

    public function history(User $actor, Location $context, string $versionId): array
    {
        $this->assertAdministrator($actor, $context);
        $this->requireDictionaryVersion($versionId);

        return DB::table('raw_material_audit_events')
            ->where('organization_id', $actor->organization_id)
            ->where('subject_type', 'ALLERGEN_DICTIONARY')
            ->where('metadata->source_dictionary_version_id', $versionId)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get([
                'id',
                'actor_user_id',
                'subject_id',
                'event_code',
                'occurred_at',
                'metadata',
            ])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'actor_user_id' => $event->actor_user_id,
                'subject_id' => $event->subject_id,
                'event_code' => $event->event_code,
                'occurred_at' => $event->occurred_at,
                'before' => $this->jsonValue($event->metadata)['before'] ?? null,
                'after' => $this->jsonValue($event->metadata)['after'] ?? null,
            ])->all();
    }

    public function createEntry(User $actor, Location $context, string $versionId, array $input): array
    {
        $this->assertAdministrator($actor, $context);

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::allergenDictionaryManage(),
            $actor->id,
            $versionId,
            fn (): array => DB::transaction(function () use ($actor, $context, $versionId, $input): array {
                $this->assertAdministrator($actor, $context);
                $this->requireDictionaryVersion($versionId, true);
                $canonicalId = $input['canonical_allergen_id'] ?? null;
                $createdCanonical = false;
                if ($canonicalId === null) {
                    $canonical = $this->createCanonical($actor, $input['new_canonical']);
                    $canonicalId = $canonical['id'];
                    $createdCanonical = true;
                } else {
                    $canonical = DB::table('international_allergen_canonical_definitions')
                        ->where('id', $canonicalId)->where('record_status', 'ACTIVE')->first();
                    if (! $canonical) {
                        throw ValidationException::withMessages([
                            'canonical_allergen_id' => ['Wybierz aktywny alergen z puli kanonicznej.'],
                        ]);
                    }
                    $canonical = $this->canonicalState($canonical);
                }

                if (DB::table('international_allergen_source_entries')
                    ->where('dictionary_version_id', $versionId)
                    ->where('allergen_id', $canonicalId)->exists()) {
                    throw new HttpException(409, 'Ten alergen jest już powiązany z wybranym słownikiem.');
                }

                $entryId = Str::uuid()->toString();
                $entryData = $this->entryInput($input, $canonical['canonical_pl']);
                DB::table('international_allergen_source_entries')->insert([
                    'id' => $entryId,
                    'dictionary_version_id' => $versionId,
                    'allergen_id' => $canonicalId,
                    ...$entryData,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->markManualEdit($actor, $versionId);
                $this->publishCanonicalPoolSnapshot($actor);

                $after = $this->entryState($entryId);
                $this->audit($actor, $context, $versionId, $entryId,
                    $createdCanonical ? 'CANONICAL_AND_SOURCE_ENTRY_CREATED' : 'SOURCE_ENTRY_CREATED',
                    null,
                    ['canonical' => $canonical, 'source_entry' => $after]);

                return $after;
            }),
        );
    }

    public function updateEntry(User $actor, Location $context, string $entryId, array $input): array
    {
        $this->assertAdministrator($actor, $context);
        $entry = $this->requireEntry($entryId);
        $versionId = $entry->dictionary_version_id;

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::allergenDictionaryManage(),
            $actor->id,
            $versionId,
            fn (): array => DB::transaction(function () use ($actor, $context, $entryId, $versionId, $input): array {
                $this->assertAdministrator($actor, $context);
                $entry = $this->requireEntry($entryId, true);
                if ($entry->archived_at !== null) {
                    throw new HttpException(409, 'Przywrócenie zarchiwizowanego powiązania nie jest obsługiwane.');
                }
                $before = $this->entryState($entryId);
                $data = $this->entryInput($input, $entry->canonical_pl);
                DB::table('international_allergen_source_entries')->where('id', $entryId)->update([
                    ...$data,
                    'updated_at' => now(),
                ]);
                $after = $this->entryState($entryId);
                if ($before === $after) {
                    return $after;
                }
                $this->markManualEdit($actor, $versionId);
                $this->publishCanonicalPoolSnapshot($actor);
                $this->audit($actor, $context, $versionId, $entryId, 'SOURCE_ENTRY_UPDATED', $before, $after);

                return $after;
            }),
        );
    }

    public function archiveEntry(User $actor, Location $context, string $entryId): array
    {
        $this->assertAdministrator($actor, $context);
        $entry = $this->requireEntry($entryId);
        $versionId = $entry->dictionary_version_id;

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::allergenDictionaryManage(),
            $actor->id,
            $versionId,
            fn (): array => DB::transaction(function () use ($actor, $context, $entryId, $versionId): array {
                $this->assertAdministrator($actor, $context);
                $entry = $this->requireEntry($entryId, true);
                if ($entry->archived_at !== null) {
                    throw new HttpException(409, 'Powiązanie ze słownikiem jest już zarchiwizowane.');
                }
                $before = $this->entryState($entryId);
                DB::table('international_allergen_source_entries')->where('id', $entryId)->update([
                    'archived_at' => now(),
                    'archived_by' => $actor->id,
                    'updated_at' => now(),
                ]);
                $after = $this->entryState($entryId);
                $this->markManualEdit($actor, $versionId);
                $this->publishCanonicalPoolSnapshot($actor);
                $this->audit($actor, $context, $versionId, $entryId, 'SOURCE_ENTRY_ARCHIVED', $before, $after);

                return $after;
            }),
        );
    }

    public function updateCanonical(
        User $actor,
        Location $context,
        string $versionId,
        string $allergenId,
        array $input,
    ): array {
        $this->assertAdministrator($actor, $context);

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::allergenDictionaryManage(),
            $actor->id,
            $versionId,
            fn (): array => DB::transaction(function () use ($actor, $context, $versionId, $allergenId, $input): array {
                $this->assertAdministrator($actor, $context);
                $this->requireDictionaryVersion($versionId, true);
                $entry = DB::table('international_allergen_source_entries')
                    ->where('dictionary_version_id', $versionId)->where('allergen_id', $allergenId)->first();
                if (! $entry) {
                    throw new HttpException(404, 'Nie znaleziono powiązania alergenu z wybranym słownikiem.');
                }
                $allergen = DB::table('international_allergen_canonical_definitions')
                    ->where('id', $allergenId)->lockForUpdate()->first();
                if (! $allergen || $allergen->record_status !== 'ACTIVE') {
                    throw new HttpException(404, 'Nie znaleziono aktywnej definicji kanonicznej.');
                }
                $before = $this->canonicalState($allergen);
                $after = [
                    ...$before,
                    'canonical_pl' => trim($input['canonical_pl']),
                    'synonyms_pl' => array_values(array_unique(array_map('trim', $input['synonyms_pl'] ?? []))),
                ];
                $this->assertUniqueCanonicalName($after['canonical_pl'], $allergenId);
                if ($before === $after) {
                    return $before;
                }
                DB::table('international_allergen_canonical_definitions')->where('id', $allergenId)->update([
                    'canonical_pl' => $after['canonical_pl'],
                    'synonyms_pl' => json_encode($after['synonyms_pl'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'last_manual_edit_at' => now(),
                    'last_manual_edit_by' => $actor->id,
                    'updated_at' => now(),
                ]);
                $this->markManualEdit($actor, $versionId);
                $this->publishCanonicalPoolSnapshot($actor);
                $this->audit($actor, $context, $versionId, $entry->id, 'CANONICAL_DEFINITION_UPDATED', $before, $after);

                return $after;
            }),
        );
    }

    public function archiveCanonical(
        User $actor,
        Location $context,
        string $versionId,
        string $allergenId,
    ): array {
        $this->assertAdministrator($actor, $context);

        return $this->contexts->runForSubject(
            AuthorizationMutationScope::allergenDictionaryManage(),
            $actor->id,
            $versionId,
            fn (): array => DB::transaction(function () use ($actor, $context, $versionId, $allergenId): array {
                $this->assertAdministrator($actor, $context);
                $this->requireDictionaryVersion($versionId, true);
                $entry = DB::table('international_allergen_source_entries')
                    ->where('dictionary_version_id', $versionId)->where('allergen_id', $allergenId)->first();
                if (! $entry) {
                    throw new HttpException(404, 'Nie znaleziono powiązania alergenu z wybranym słownikiem.');
                }
                $allergen = DB::table('international_allergen_canonical_definitions')
                    ->where('id', $allergenId)->lockForUpdate()->first();
                if (! $allergen) {
                    throw new HttpException(404, 'Nie znaleziono definicji kanonicznej.');
                }
                if ($allergen->record_status === 'ARCHIVED') {
                    throw new HttpException(409, 'Definicja kanoniczna jest już zarchiwizowana.');
                }
                $before = $this->canonicalState($allergen);
                $after = [
                    ...$before,
                    'record_status' => 'ARCHIVED',
                    'archived_at' => now()->toISOString(),
                ];
                DB::table('international_allergen_canonical_definitions')->where('id', $allergenId)->update([
                    'record_status' => 'ARCHIVED',
                    'archived_at' => now(),
                    'archived_by' => $actor->id,
                    'last_manual_edit_at' => now(),
                    'last_manual_edit_by' => $actor->id,
                    'updated_at' => now(),
                ]);
                $this->markManualEdit($actor, $versionId);
                $this->publishCanonicalPoolSnapshot($actor);
                $this->audit($actor, $context, $versionId, $entry->id, 'CANONICAL_DEFINITION_ARCHIVED', $before, $after);

                return $after;
            }),
        );
    }

    private function createCanonical(User $actor, array $input): array
    {
        $id = trim($input['id']);
        $name = trim($input['canonical_pl']);
        if (DB::table('international_allergen_canonical_definitions')->where('id', $id)->exists()) {
            throw ValidationException::withMessages([
                'new_canonical.id' => ['Identyfikator istnieje już w puli. Wybierz istniejący alergen.'],
            ]);
        }
        $this->assertUniqueCanonicalName($name);

        $state = [
            'id' => $id,
            'parent_id' => $input['parent_id'] ?? null,
            'canonical_pl' => $name,
            'synonyms_pl' => array_values(array_unique(array_map('trim', $input['synonyms_pl'] ?? []))),
            'original_names' => $input['original_names'] ?? [],
            'record_status' => 'ACTIVE',
            'archived_at' => null,
        ];
        if ($state['parent_id'] !== null && ! DB::table('international_allergen_canonical_definitions')
            ->where('id', $state['parent_id'])->where('record_status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages([
                'new_canonical.parent_id' => ['Wybierz aktywny alergen nadrzędny.'],
            ]);
        }

        $now = now();
        DB::table('international_allergen_canonical_definitions')->insert([
            ...$state,
            'synonyms_pl' => json_encode($state['synonyms_pl'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'original_names' => json_encode((object) $state['original_names'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'last_manual_edit_at' => $now,
            'last_manual_edit_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $state;
    }

    private function assertUniqueCanonicalName(string $name, ?string $excludeId = null): void
    {
        $query = DB::table('international_allergen_canonical_definitions')
            ->whereRaw('lower(canonical_pl) = lower(?)', [trim($name)]);
        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'canonical_pl' => ['Alergen o tej nazwie już istnieje w puli kanonicznej.'],
            ]);
        }
    }

    private function entryInput(array $input, string $fallbackName): array
    {
        return [
            'source_name' => trim($input['source_name'] ?? $fallbackName),
            'status' => $input['status'],
            'listing_rule' => $input['listing_rule'],
            'legal_effective_from' => $input['legal_effective_from'] ?? null,
            'transition_end' => $input['transition_end'] ?? null,
            'source_scope' => $input['source_scope'],
            'threshold' => isset($input['threshold'])
                ? json_encode((object) $input['threshold'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : null,
            'exceptions' => json_encode($input['exceptions'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'note' => $input['note'] ?? null,
            'metadata' => json_encode((object) ($input['metadata'] ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function markManualEdit(User $actor, string $versionId): void
    {
        DB::table('international_allergen_dictionary_versions')->where('id', $versionId)->update([
            'last_manual_edit_at' => now(),
            'last_manual_edit_by' => $actor->id,
        ]);
    }

    private function publishCanonicalPoolSnapshot(User $actor): void
    {
        $catalogVersionId = Str::uuid()->toString();
        $versionNumber = (int) DB::table('raw_material_allergen_catalog_versions')->max('version_number') + 1;
        $now = now();
        DB::table('raw_material_allergen_catalog_versions')->insert([
            'id' => $catalogVersionId,
            'version_number' => $versionNumber,
            'status' => 'DRAFT',
            'created_at' => $now,
            'published_at' => null,
        ]);

        $allergens = DB::table('international_allergen_canonical_definitions')
            ->where('record_status', 'ACTIVE')
            ->orderBy('canonical_pl')
            ->orderBy('id')
            ->get(['id', 'canonical_pl']);
        foreach ($allergens as $index => $allergen) {
            $sourceCodes = DB::table('international_allergen_source_entries as entries')
                ->join('international_allergen_dictionary_versions as versions', 'versions.id', '=', 'entries.dictionary_version_id')
                ->where('entries.allergen_id', $allergen->id)
                ->whereNull('entries.archived_at')
                ->distinct()
                ->orderBy('versions.source_code')
                ->pluck('versions.source_code')
                ->all();
            DB::table('raw_material_allergen_definitions')->insert([
                'catalog_version_id' => $catalogVersionId,
                'allergen_code' => $allergen->id,
                'name_pl' => $allergen->canonical_pl,
                'display_order' => $index + 1,
                'is_active' => true,
                'canonical_allergen_id' => $allergen->id,
                'source_codes' => json_encode($sourceCodes, JSON_THROW_ON_ERROR),
            ]);
        }

        DB::table('raw_material_allergen_catalog_versions')->where('id', $catalogVersionId)->update([
            'status' => 'PUBLISHED',
            'published_at' => $now,
        ]);
        DB::table('raw_material_allergen_pool_versions')->insert([
            'id' => Str::uuid()->toString(),
            'catalog_version_id' => $catalogVersionId,
            'version_number' => $versionNumber,
            'created_by' => $actor->id,
            'created_at' => $now,
        ]);
    }

    private function audit(
        User $actor,
        Location $context,
        string $versionId,
        ?string $entryId,
        string $eventCode,
        ?array $before,
        array $after,
    ): void {
        $beforeJson = $before === null
            ? null
            : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $afterJson = json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        DB::table('raw_material_audit_events')->insert([
            'id' => Str::uuid()->toString(),
            'organization_id' => $actor->organization_id,
            'operation_id' => Str::uuid()->toString(),
            'actor_user_id' => $actor->id,
            'actor_location_id' => $context->id,
            'subject_type' => 'ALLERGEN_DICTIONARY',
            'subject_id' => $entryId,
            'event_code' => $eventCode,
            'outcome' => 'ACCEPTED',
            'before_hash' => $beforeJson === null ? null : hash('sha256', $beforeJson),
            'after_hash' => hash('sha256', $afterJson),
            'metadata' => json_encode([
                'source_dictionary_version_id' => $versionId,
                'before' => $before,
                'after' => $after,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function requireDictionaryVersion(string $versionId, bool $lock = false): object
    {
        $query = DB::table('international_allergen_dictionary_versions')->where('id', $versionId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $version = $query->first();
        if (! $version) {
            throw new HttpException(404, 'Nie znaleziono wersji słownika alergenów.');
        }

        return $version;
    }

    private function requireEntry(string $entryId, bool $lock = false): object
    {
        $query = DB::table('international_allergen_source_entries as entries')
            ->join('international_allergen_canonical_definitions as allergens', 'allergens.id', '=', 'entries.allergen_id')
            ->where('entries.id', $entryId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $entry = $query->first([
            'entries.id',
            'entries.dictionary_version_id',
            'entries.allergen_id',
            'entries.archived_at',
            'allergens.canonical_pl',
        ]);
        if (! $entry) {
            throw new HttpException(404, 'Nie znaleziono wpisu słownika alergenów.');
        }

        return $entry;
    }

    private function entryState(string $entryId): array
    {
        $entry = DB::table('international_allergen_source_entries as entries')
            ->join('international_allergen_canonical_definitions as allergens', 'allergens.id', '=', 'entries.allergen_id')
            ->where('entries.id', $entryId)
            ->first([
                'entries.id',
                'entries.dictionary_version_id',
                'entries.allergen_id',
                'entries.source_name',
                'entries.status',
                'entries.listing_rule',
                'entries.legal_effective_from',
                'entries.transition_end',
                'entries.source_scope',
                'entries.threshold',
                'entries.exceptions',
                'entries.note',
                'entries.metadata',
                'entries.archived_at',
                'allergens.canonical_pl',
                'allergens.record_status',
            ]);

        return $this->presentEntry($entry);
    }

    private function presentEntry(object $entry): array
    {
        return [
            'id' => $entry->id,
            'dictionary_version_id' => $entry->dictionary_version_id,
            'allergen_id' => $entry->allergen_id,
            'source_name' => $entry->source_name,
            'canonical_pl' => $entry->canonical_pl,
            'canonical_status' => $entry->record_status,
            'status' => $entry->status,
            'listing_rule' => $entry->listing_rule,
            'legal_effective_from' => $entry->legal_effective_from,
            'transition_end' => $entry->transition_end,
            'source_scope' => $entry->source_scope,
            'threshold' => $this->jsonObjectValue($entry->threshold),
            'exceptions' => $this->jsonValue($entry->exceptions),
            'note' => $entry->note,
            'metadata' => $this->jsonObjectValue($entry->metadata),
            'archived_at' => $entry->archived_at,
        ];
    }

    private function canonicalState(object $allergen): array
    {
        return [
            'id' => $allergen->id,
            'parent_id' => $allergen->parent_id,
            'canonical_pl' => $allergen->canonical_pl,
            'synonyms_pl' => $this->jsonValue($allergen->synonyms_pl),
            'original_names' => $this->jsonObjectValue($allergen->original_names),
            'record_status' => $allergen->record_status,
            'archived_at' => $allergen->archived_at,
        ];
    }

    private function dictionaryVersion(object $version): array
    {
        return [
            'id' => $version->id,
            'code' => $version->code,
            'authority' => $version->authority,
            'status' => $version->status,
            'official_url' => $version->official_url,
            'effective_from' => $version->effective_from,
            'effective_until' => $version->effective_until,
            'last_verified_on' => $version->last_verified_on,
            'last_manual_edit_at' => $version->last_manual_edit_at,
            'metadata' => $this->jsonObjectValue($version->metadata),
        ];
    }

    private function jsonObjectValue(mixed $value): mixed
    {
        $decoded = $this->jsonValue($value);

        return $decoded === [] ? (object) [] : $decoded;
    }

    private function jsonValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertAdministrator(User $actor, Location $context): void
    {
        if (! $this->authorization->eligible($actor, $context)
            || ! $this->authorization->hasRole($actor, BusinessRole::Administrator)) {
            throw new HttpException(403, __('messages.unauthorized'));
        }
    }
}
