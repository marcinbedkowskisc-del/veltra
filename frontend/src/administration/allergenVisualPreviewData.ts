import masterJson from '../../../backend/database/data/allergen_dictionaries_master_v1_1.json?raw'

type MasterDictionary = {
  code: string
  jurisdiction: string
  authority: string
  status: 'ACTIVE' | 'ACTIVE_STANDARD' | 'FUTURE' | 'HISTORICAL'
  effective_from?: string | null
  replaced_on?: string | null
  official_url?: string | null
  last_verified_on?: string | null
  last_manual_edit_at?: string | null
  [key: string]: unknown
}

type MasterAllergen = {
  id: string
  parent_id: string | null
  canonical_pl: string
  synonyms_pl: string[]
  original_names: Record<string, string>
  record_status: 'ACTIVE' | 'ARCHIVED'
}

type MasterLink = {
  source_code: string
  allergen_id: string
  status: string
  listing_rule: string
  legal_effective_from?: string | null
  transition_end?: string | null
  source_scope: string
  threshold?: Record<string, unknown> | null
  exceptions?: string[]
  note?: string | null
}

type MasterData = {
  source_dictionaries: MasterDictionary[]
  allergens: MasterAllergen[]
  allergen_source_links: MasterLink[]
}

type PreviewVersion = {
  id: string
  code: string
  authority: string
  status: MasterDictionary['status']
  official_url: string | null
  effective_from: string | null
  effective_until: string | null
  last_verified_on: string | null
  last_manual_edit_at: string | null
  entry_count: number
  metadata: Record<string, unknown>
}

type PreviewSource = {
  source_code: string
  jurisdiction: string
  name_pl: string
  versions: PreviewVersion[]
}

type PreviewEntry = {
  id: string
  dictionary_version_id: string
  allergen_id: string
  source_name: string
  canonical_pl: string
  canonical_status: 'ACTIVE' | 'ARCHIVED'
  status: string
  listing_rule: string
  legal_effective_from: string | null
  transition_end: string | null
  source_scope: string
  threshold: Record<string, unknown> | null
  exceptions: string[]
  note: string | null
  metadata: Record<string, unknown>
  archived_at: string | null
}

const master = JSON.parse(masterJson) as MasterData

const SOURCE_NAMES: Record<string, string> = {
  EU: 'Unia Europejska',
  US: 'USA — FDA',
  CODEX: 'Codex Alimentarius',
  JP: 'Japonia',
  CN: 'Chiny',
  KR: 'Korea Południowa',
  TW: 'Tajwan',
  TH: 'Tajlandia',
}

const SOURCE_LANGUAGES: Record<string, string> = {
  EU: 'en',
  US: 'en',
  CODEX: 'en',
  JP: 'ja',
  CN: 'zh',
  KR: 'ko',
  TW: 'zh-TW',
  TH: 'th',
}

const SOURCE_IDS = new Map(master.source_dictionaries.map((dictionary, index) => [
  dictionary.code,
  `00000000-0000-4000-8000-${String(index + 101).padStart(12, '0')}`,
]))
const allergensById = new Map(master.allergens.map((allergen) => [allergen.id, allergen]))
const jurisdictionByDictionaryCode = new Map(master.source_dictionaries.map((dictionary) => [
  dictionary.code,
  dictionary.jurisdiction,
]))
const linksByCode = new Map<string, MasterLink[]>()

for (const link of master.allergen_source_links) {
  const links = linksByCode.get(link.source_code) ?? []
  links.push(link)
  linksByCode.set(link.source_code, links)
}

const metadataExcludedFromVersion = new Set([
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
])

function versionId(code: string): string {
  const id = SOURCE_IDS.get(code)
  if (!id) throw new Error(`MASTER v1.1 contains an unknown dictionary: ${code}`)
  return id
}

const sourceMap = new Map<string, PreviewSource>()

for (const dictionary of master.source_dictionaries) {
  const sourceCode = dictionary.jurisdiction
  const metadata = Object.fromEntries(Object.entries(dictionary)
    .filter(([key]) => !metadataExcludedFromVersion.has(key)))
  const source = sourceMap.get(sourceCode) ?? {
    source_code: sourceCode,
    jurisdiction: sourceCode,
    name_pl: SOURCE_NAMES[sourceCode] ?? sourceCode,
    versions: [],
  }
  source.versions.push({
      id: versionId(dictionary.code),
      code: dictionary.code,
      authority: dictionary.authority,
      status: dictionary.status,
      official_url: dictionary.official_url ?? null,
      effective_from: dictionary.effective_from ?? null,
      effective_until: dictionary.replaced_on ?? null,
      last_verified_on: dictionary.last_verified_on ?? null,
      last_manual_edit_at: dictionary.last_manual_edit_at ?? null,
      entry_count: linksByCode.get(dictionary.code)?.length ?? 0,
      metadata,
  })
  sourceMap.set(sourceCode, source)
}

export const previewAllergenSources: PreviewSource[] = [...sourceMap.values()]

export const previewCanonicalAllergens = master.allergens.map((allergen) => {
  const links = master.allergen_source_links.filter((link) => link.allergen_id === allergen.id)
  return {
    ...allergen,
    source_codes: [...new Set(links.map((link) => jurisdictionByDictionaryCode.get(link.source_code) ?? link.source_code))],
    relationship_count: links.length,
    archived_at: null,
  }
})

export const previewAllergenEntries: Record<string, PreviewEntry[]> = Object.fromEntries(master.source_dictionaries.map((dictionary) => {
  const dictionaryVersionId = versionId(dictionary.code)
  const sourceLanguage = SOURCE_LANGUAGES[dictionary.jurisdiction]
  const entries = (linksByCode.get(dictionary.code) ?? []).slice().sort((left, right) => {
    const leftName = allergensById.get(left.allergen_id)?.canonical_pl ?? ''
    const rightName = allergensById.get(right.allergen_id)?.canonical_pl ?? ''
    return leftName.localeCompare(rightName, 'pl-PL') || left.allergen_id.localeCompare(right.allergen_id)
  }).map((link, index) => {
    const allergen = allergensById.get(link.allergen_id)
    if (!allergen) throw new Error(`MASTER v1.1 contains an unknown canonical allergen: ${link.allergen_id}`)
    const entrySequence = Number(dictionaryVersionId.slice(-12)) * 100 + index + 1

    return {
      id: `00000000-0000-4000-8000-${String(entrySequence).padStart(12, '0')}`,
      dictionary_version_id: dictionaryVersionId,
      allergen_id: allergen.id,
      source_name: allergen.original_names[sourceLanguage] ?? allergen.canonical_pl,
      canonical_pl: allergen.canonical_pl,
      canonical_status: allergen.record_status,
      status: link.status,
      listing_rule: link.listing_rule,
      legal_effective_from: link.legal_effective_from ?? null,
      transition_end: link.transition_end ?? null,
      source_scope: link.source_scope,
      threshold: link.threshold ?? null,
      exceptions: link.exceptions ?? [],
      note: link.note ?? null,
      metadata: {},
      archived_at: null,
    }
  })

  return [dictionaryVersionId, entries]
}))

export const previewAllergenRelationshipCount = master.allergen_source_links.length
