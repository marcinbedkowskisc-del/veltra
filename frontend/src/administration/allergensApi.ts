import { isRecord, request, requestEnvelope } from '../api'

const API = '/api/v1/administration/allergens'
const STATUSES = [
  'MANDATORY',
  'RECOMMENDED',
  'VOLUNTARY',
  'NATIONAL_OPTION',
  'WARNING_ONLY',
  'FUTURE_MANDATORY',
  'HISTORICAL',
] as const

export type AllergenListingStatus = (typeof STATUSES)[number]
export type AllergenDictionaryVersion = {
  id: string
  code: string
  authority: string
  status: 'ACTIVE' | 'ACTIVE_STANDARD' | 'FUTURE' | 'HISTORICAL'
  officialUrl: string | null
  effectiveFrom: string | null
  effectiveUntil: string | null
  lastVerifiedOn: string | null
  lastManualEditAt: string | null
  entryCount: number
  metadata: Record<string, unknown>
}
export type AllergenSource = { sourceCode: string; jurisdiction: string; namePl: string; versions: AllergenDictionaryVersion[] }
export type CanonicalAllergen = {
  id: string
  parentId: string | null
  canonicalPl: string
  synonymsPl: string[]
  originalNames: Record<string, string>
  recordStatus: 'ACTIVE' | 'ARCHIVED'
  archivedAt: string | null
  sourceCodes: string[]
  relationshipCount: number
}
export type AllergenSourceEntry = {
  id: string
  dictionaryVersionId: string
  allergenId: string
  sourceName: string
  canonicalPl: string
  canonicalStatus: 'ACTIVE' | 'ARCHIVED'
  status: AllergenListingStatus
  listingRule: string
  legalEffectiveFrom: string | null
  transitionEnd: string | null
  sourceScope: string
  threshold: Record<string, unknown> | null
  exceptions: string[]
  note: string | null
  metadata: Record<string, unknown>
  archivedAt: string | null
}
export type AllergenSourceEntryInput = {
  source_name: string
  status: AllergenListingStatus
  listing_rule: string
  legal_effective_from: string | null
  transition_end: string | null
  source_scope: string
  threshold: Record<string, unknown> | null
  exceptions: string[]
  note: string | null
  metadata: Record<string, unknown>
}
export type NewCanonicalAllergen = {
  id?: string
  canonical_pl: string
  parent_id: string | null
  synonyms_pl: string[]
  original_names: Record<string, string>
}
export type AllergenDictionaryHistoryEvent = {
  id: string
  actor_user_id: string | null
  subject_id: string | null
  event_code: string
  occurred_at: string
  before: unknown
  after: unknown
}

function isStringOrNull(value: unknown): value is string | null {
  return value === null || typeof value === 'string'
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return isRecord(value) && !Array.isArray(value)
}

function parseVersion(value: unknown): AllergenDictionaryVersion {
  if (!isRecord(value) || typeof value.id !== 'string' || typeof value.code !== 'string'
    || typeof value.authority !== 'string'
    || value.status !== 'ACTIVE' && value.status !== 'ACTIVE_STANDARD' && value.status !== 'FUTURE' && value.status !== 'HISTORICAL'
    || !isStringOrNull(value.official_url) || !isStringOrNull(value.effective_from)
    || !isStringOrNull(value.effective_until) || !isStringOrNull(value.last_verified_on)
    || !isStringOrNull(value.last_manual_edit_at)
    || !isObjectRecord(value.metadata)
    || !Number.isInteger(value.entry_count) || Number(value.entry_count) < 0) {
    throw new Error('Backend zwrócił nieprawidłową wersję słownika alergenów.')
  }
  return {
    id: value.id,
    code: value.code,
    authority: value.authority,
    status: value.status,
    officialUrl: value.official_url,
    effectiveFrom: value.effective_from,
    effectiveUntil: value.effective_until,
    lastVerifiedOn: value.last_verified_on,
    lastManualEditAt: value.last_manual_edit_at,
    entryCount: Number(value.entry_count),
    metadata: value.metadata,
  }
}

function parseSource(value: unknown): AllergenSource {
  if (!isRecord(value) || typeof value.source_code !== 'string'
    || typeof value.jurisdiction !== 'string' || typeof value.name_pl !== 'string'
    || !Array.isArray(value.versions)) {
    throw new Error('Backend zwrócił nieprawidłowy słownik źródłowy alergenów.')
  }
  return {
    sourceCode: value.source_code,
    jurisdiction: value.jurisdiction,
    namePl: value.name_pl,
    versions: value.versions.map(parseVersion),
  }
}

function parseCanonical(value: unknown): CanonicalAllergen {
  if (!isRecord(value) || typeof value.id !== 'string' || typeof value.canonical_pl !== 'string'
    || !(value.parent_id === null || typeof value.parent_id === 'string')
    || !Array.isArray(value.synonyms_pl) || !value.synonyms_pl.every((item) => typeof item === 'string')
    || !isObjectRecord(value.original_names)
    || value.record_status !== 'ACTIVE' && value.record_status !== 'ARCHIVED'
    || !isStringOrNull(value.archived_at)
    || !Array.isArray(value.source_codes) || !value.source_codes.every((item) => typeof item === 'string')
    || !Number.isInteger(value.relationship_count) || Number(value.relationship_count) < 0) {
    throw new Error('Backend zwrócił nieprawidłowy alergen kanoniczny.')
  }
  const originalNames: Record<string, string> = {}
  for (const [language, name] of Object.entries(value.original_names)) {
    if (typeof name !== 'string') throw new Error('Backend zwrócił nieprawidłowe nazwy źródłowe alergenu.')
    originalNames[language] = name
  }
  return {
    id: value.id,
    parentId: value.parent_id,
    canonicalPl: value.canonical_pl,
    synonymsPl: value.synonyms_pl,
    originalNames,
    recordStatus: value.record_status,
    archivedAt: value.archived_at,
    sourceCodes: value.source_codes,
    relationshipCount: Number(value.relationship_count),
  }
}

function parseEntry(value: unknown): AllergenSourceEntry {
  if (!isRecord(value) || typeof value.id !== 'string' || typeof value.dictionary_version_id !== 'string'
    || typeof value.allergen_id !== 'string' || typeof value.source_name !== 'string'
    || typeof value.canonical_pl !== 'string'
    || value.canonical_status !== 'ACTIVE' && value.canonical_status !== 'ARCHIVED'
    || typeof value.status !== 'string' || !STATUSES.includes(value.status as AllergenListingStatus)
    || typeof value.listing_rule !== 'string' || !isStringOrNull(value.legal_effective_from)
    || !isStringOrNull(value.transition_end) || typeof value.source_scope !== 'string'
    || !(value.threshold === null || isObjectRecord(value.threshold))
    || !Array.isArray(value.exceptions) || !value.exceptions.every((item) => typeof item === 'string')
    || !isStringOrNull(value.note) || !isObjectRecord(value.metadata) || !isStringOrNull(value.archived_at)) {
    throw new Error('Backend zwrócił nieprawidłowy wpis słownika alergenów.')
  }
  return {
    id: value.id,
    dictionaryVersionId: value.dictionary_version_id,
    allergenId: value.allergen_id,
    sourceName: value.source_name,
    canonicalPl: value.canonical_pl,
    canonicalStatus: value.canonical_status,
    status: value.status as AllergenListingStatus,
    listingRule: value.listing_rule,
    legalEffectiveFrom: value.legal_effective_from,
    transitionEnd: value.transition_end,
    sourceScope: value.source_scope,
    threshold: value.threshold,
    exceptions: value.exceptions,
    note: value.note,
    metadata: value.metadata,
    archivedAt: value.archived_at,
  }
}

function parseArray<T>(value: unknown, parser: (item: unknown) => T, message: string): T[] {
  if (!Array.isArray(value)) throw new Error(message)
  return value.map(parser)
}

export async function fetchAllergenSources(signal?: AbortSignal): Promise<AllergenSource[]> {
  const response = await requestEnvelope('/api/v1/administration/allergens/dictionaries', 'GET', undefined, { signal })
  return parseArray(response.data, parseSource, 'Backend zwrócił nieprawidłowe słowniki źródłowe.')
}

export async function fetchCanonicalAllergens(signal?: AbortSignal): Promise<CanonicalAllergen[]> {
  const response = await requestEnvelope(`${API}/canonical`, 'GET', undefined, { signal })
  return parseArray(response.data, parseCanonical, 'Backend zwrócił nieprawidłową pulę alergenów.')
}

export async function fetchAllergenEntries(versionId: string, signal?: AbortSignal): Promise<AllergenSourceEntry[]> {
  const response = await requestEnvelope(`${API}/dictionaries/${encodeURIComponent(versionId)}/entries`, 'GET', undefined, { signal })
  return parseArray(response.data, parseEntry, 'Backend zwrócił nieprawidłowe wpisy słownika.')
}

export async function fetchAllergenHistory(versionId: string, signal?: AbortSignal): Promise<AllergenDictionaryHistoryEvent[]> {
  const response = await requestEnvelope(`${API}/dictionaries/${encodeURIComponent(versionId)}/history`, 'GET', undefined, { signal })
  return parseArray(response.data, (value) => {
    if (!isRecord(value) || typeof value.id !== 'string'
      || !isStringOrNull(value.actor_user_id) || !isStringOrNull(value.subject_id)
      || typeof value.event_code !== 'string' || typeof value.occurred_at !== 'string'
      || !(value.before === null || isRecord(value.before) || Array.isArray(value.before))
      || !(value.after === null || isRecord(value.after) || Array.isArray(value.after))) {
      throw new Error('Backend zwrócił nieprawidłową historię zmian słownika.')
    }
    return {
      id: value.id,
      actor_user_id: value.actor_user_id,
      subject_id: value.subject_id,
      event_code: value.event_code,
      occurred_at: value.occurred_at,
      before: value.before,
      after: value.after,
    }
  }, 'Backend zwrócił nieprawidłową historię zmian słownika.')
}

export async function createAllergenEntry(versionId: string, input: AllergenSourceEntryInput & ({
  canonical_allergen_id: string
  new_canonical?: never
} | {
  canonical_allergen_id?: never
  new_canonical: NewCanonicalAllergen
})): Promise<AllergenSourceEntry> {
  return parseEntry(await request(`${API}/dictionaries/${encodeURIComponent(versionId)}/entries`, 'POST', input))
}

export async function updateAllergenEntry(id: string, input: AllergenSourceEntryInput): Promise<AllergenSourceEntry> {
  return parseEntry(await request(`${API}/entries/${encodeURIComponent(id)}`, 'PATCH', input))
}

export async function archiveAllergenEntry(id: string): Promise<AllergenSourceEntry> {
  return parseEntry(await request(`${API}/entries/${encodeURIComponent(id)}/archive`, 'POST', {}))
}

export async function updateCanonicalAllergen(versionId: string, id: string, input: {
  canonical_pl: string
  synonyms_pl: string[]
}): Promise<CanonicalAllergen> {
  const value = await request(`${API}/dictionaries/${encodeURIComponent(versionId)}/canonical/${encodeURIComponent(id)}`, 'PATCH', input)
  if (!isRecord(value) || typeof value.id !== 'string' || typeof value.canonical_pl !== 'string') {
    throw new Error('Backend zwrócił nieprawidłowy alergen kanoniczny.')
  }
  return {
    id: value.id,
    parentId: typeof value.parent_id === 'string' ? value.parent_id : null,
    canonicalPl: value.canonical_pl,
    synonymsPl: Array.isArray(value.synonyms_pl) ? value.synonyms_pl.filter((item): item is string => typeof item === 'string') : [],
    originalNames: isRecord(value.original_names) ? Object.fromEntries(
      Object.entries(value.original_names).filter((entry): entry is [string, string] => typeof entry[1] === 'string'),
    ) : {},
    recordStatus: value.record_status === 'ARCHIVED' ? 'ARCHIVED' : 'ACTIVE',
    archivedAt: typeof value.archived_at === 'string' ? value.archived_at : null,
    sourceCodes: [],
    relationshipCount: 0,
  }
}

export async function archiveCanonicalAllergen(versionId: string, id: string): Promise<void> {
  await request(`${API}/dictionaries/${encodeURIComponent(versionId)}/canonical/${encodeURIComponent(id)}/archive`, 'POST', {})
}
