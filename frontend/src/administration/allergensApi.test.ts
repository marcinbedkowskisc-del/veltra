import { afterEach, expect, it, vi } from 'vitest'
import { fetchAllergenEntries, fetchAllergenSources, fetchCanonicalAllergens } from './allergensApi'

afterEach(() => vi.unstubAllGlobals())

it('parses empty JSON objects in dictionary, canonical and source-entry responses', async () => {
  const versionId = '00000000-0000-4000-8000-000000000501'
  const entryId = '00000000-0000-4000-8000-000000000502'
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{
      source_code: 'CN',
      jurisdiction: 'CN',
      name_pl: 'Chiny',
      versions: [{
        id: versionId,
        code: 'CN_TEST',
        authority: 'Test',
        status: 'FUTURE',
        official_url: null,
        effective_from: null,
        effective_until: null,
        last_verified_on: null,
        last_manual_edit_at: null,
        entry_count: 1,
        metadata: {},
      }],
    }] })))
    .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{
      id: 'ALG_TEST',
      parent_id: null,
      canonical_pl: 'Testowy alergen',
      synonyms_pl: [],
      original_names: {},
      record_status: 'ACTIVE',
      archived_at: null,
      source_codes: [],
      relationship_count: 1,
    }] })))
    .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{
      id: entryId,
      dictionary_version_id: versionId,
      allergen_id: 'ALG_TEST',
      source_name: 'Allergen',
      canonical_pl: 'Testowy alergen',
      canonical_status: 'ACTIVE',
      status: 'FUTURE_MANDATORY',
      listing_rule: 'DIRECT',
      legal_effective_from: null,
      transition_end: null,
      source_scope: 'LEGAL_LIST',
      threshold: null,
      exceptions: [],
      note: null,
      metadata: {},
      archived_at: null,
    }] })))
  vi.stubGlobal('fetch', fetchMock)

  await expect(fetchAllergenSources()).resolves.toMatchObject([{ versions: [{ metadata: {} }] }])
  await expect(fetchCanonicalAllergens()).resolves.toMatchObject([{ originalNames: {} }])
  await expect(fetchAllergenEntries(versionId)).resolves.toMatchObject([{ metadata: {}, exceptions: [] }])
})
