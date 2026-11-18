import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import {
  archiveAllergenEntry,
  archiveCanonicalAllergen,
  createAllergenEntry,
  fetchAllergenEntries,
  fetchAllergenHistory,
  fetchAllergenSources,
  fetchCanonicalAllergens,
  updateAllergenEntry,
  updateCanonicalAllergen,
  type AllergenDictionaryHistoryEvent,
  type AllergenDictionaryVersion,
  type AllergenListingStatus,
  type AllergenSource,
  type AllergenSourceEntry,
  type AllergenSourceEntryInput,
  type CanonicalAllergen,
} from './allergensApi'

type StatusFilter = 'all' | 'active' | 'archived'
type DictionaryColumn = { source: AllergenSource; version: AllergenDictionaryVersion }
type EntryForm = {
  versionId: string
  entry: AllergenSourceEntry | null
  canonicalMode: 'existing' | 'new'
  canonicalId: string
  newCanonicalId: string
  canonicalPl: string
  parentId: string
  synonyms: string
  originalNames: string
  sourceName: string
  status: AllergenListingStatus
  listingRule: string
  effectiveFrom: string
  transitionEnd: string
  sourceScope: string
  threshold: string
  exceptions: string
  note: string
  metadata: string
}
type CanonicalForm = {
  versionId: string
  allergen: CanonicalAllergen
  canonicalPl: string
  synonyms: string
}

const STATUSES: Array<{ value: AllergenListingStatus; label: string }> = [
  { value: 'MANDATORY', label: 'Obowiązkowy' },
  { value: 'RECOMMENDED', label: 'Zalecany' },
  { value: 'VOLUNTARY', label: 'Dobrowolny' },
  { value: 'NATIONAL_OPTION', label: 'Opcja krajowa' },
  { value: 'WARNING_ONLY', label: 'Tylko ostrzeżenie' },
  { value: 'FUTURE_MANDATORY', label: 'Obowiązkowy w przyszłości' },
  { value: 'HISTORICAL', label: 'Historyczny' },
]

const VERSION_STATUSES: Record<AllergenDictionaryVersion['status'], string> = {
  ACTIVE: 'Aktywna',
  ACTIVE_STANDARD: 'Aktywny standard',
  FUTURE: 'Przyszła',
  HISTORICAL: 'Historyczna',
}

function errorText(cause: unknown): string {
  if (cause instanceof ApiError) return Object.values(cause.errors).flat()[0] ?? cause.message
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.'
}

function preferredVersion(source: AllergenSource): AllergenDictionaryVersion | undefined {
  return source.versions.find((version) => version.status === 'ACTIVE')
    ?? source.versions.find((version) => version.status === 'ACTIVE_STANDARD')
    ?? source.versions[0]
}

function entryForm(entry: AllergenSourceEntry | null, versionId: string): EntryForm {
  return {
    versionId,
    entry,
    canonicalMode: 'existing',
    canonicalId: entry?.allergenId ?? '',
    newCanonicalId: '',
    canonicalPl: '',
    parentId: '',
    synonyms: '',
    originalNames: '{}',
    sourceName: entry?.sourceName ?? '',
    status: entry?.status ?? 'MANDATORY',
    listingRule: entry?.listingRule ?? '',
    effectiveFrom: entry?.legalEffectiveFrom ?? '',
    transitionEnd: entry?.transitionEnd ?? '',
    sourceScope: entry?.sourceScope ?? '',
    threshold: entry?.threshold ? JSON.stringify(entry.threshold, null, 2) : '',
    exceptions: entry?.exceptions.join('\n') ?? '',
    note: entry?.note ?? '',
    metadata: entry ? JSON.stringify(entry.metadata, null, 2) : '{}',
  }
}

function parseObject(value: string, label: string): Record<string, unknown> | null {
  if (!value.trim()) return null
  let parsed: unknown
  try {
    parsed = JSON.parse(value)
  } catch {
    throw new Error(`${label}: wpisz poprawny obiekt JSON.`)
  }
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new Error(`${label}: wymagany jest obiekt JSON.`)
  }
  return parsed as Record<string, unknown>
}

function parseStringObject(value: string, label: string): Record<string, string> {
  const parsed = parseObject(value, label) ?? {}
  const result: Record<string, string> = {}
  for (const [key, item] of Object.entries(parsed)) {
    if (typeof item !== 'string') throw new Error(`${label}: każda nazwa musi być tekstem.`)
    result[key] = item
  }
  return result
}

function sourceVersionLabel(version: AllergenDictionaryVersion): string {
  return `${version.code} · ${VERSION_STATUSES[version.status]}`
}

function dateLabel(value: string | null, emptyLabel: string): string {
  return value ?? emptyLabel
}

export default function AllergenDictionaryManagementPage() {
  const [sources, setSources] = useState<AllergenSource[]>([])
  const [canonical, setCanonical] = useState<CanonicalAllergen[]>([])
  const [selectedVersionIds, setSelectedVersionIds] = useState<Record<string, string>>({})
  const [entriesByVersion, setEntriesByVersion] = useState<Record<string, AllergenSourceEntry[]>>({})
  const [historyByVersion, setHistoryByVersion] = useState<Record<string, AllergenDictionaryHistoryEvent[]>>({})
  const [searchBySource, setSearchBySource] = useState<Record<string, string>>({})
  const [statusBySource, setStatusBySource] = useState<Record<string, StatusFilter>>({})
  const [form, setForm] = useState<EntryForm | null>(null)
  const [canonicalForm, setCanonicalForm] = useState<CanonicalForm | null>(null)
  const [sourcesLoading, setSourcesLoading] = useState(true)
  const [columnsLoading, setColumnsLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    void Promise.all([fetchAllergenSources(controller.signal), fetchCanonicalAllergens(controller.signal)])
      .then(([sourceResult, canonicalResult]) => {
        if (controller.signal.aborted) return
        setColumnsLoading(sourceResult.length > 0)
        setSources(sourceResult)
        setCanonical(canonicalResult)
        setSelectedVersionIds((current) => Object.fromEntries(sourceResult.flatMap((source) => {
          const currentVersion = source.versions.find((version) => version.id === current[source.sourceCode])
          const version = currentVersion ?? preferredVersion(source)
          return version ? [[source.sourceCode, version.id]] : []
        })))
        setError(null)
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) {
          setColumnsLoading(false)
          setError(errorText(cause))
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setSourcesLoading(false)
      })
    return () => controller.abort()
  }, [reload])

  const columns = useMemo<DictionaryColumn[]>(() => sources.flatMap((source) => {
    const version = source.versions.find((item) => item.id === selectedVersionIds[source.sourceCode])
      ?? preferredVersion(source)
    return version ? [{ source, version }] : []
  }), [sources, selectedVersionIds])

  useEffect(() => {
    if (columns.length === 0) {
      return
    }
    const controller = new AbortController()
    void Promise.all(columns.map(async ({ version }) => {
      const [entries, history] = await Promise.all([
        fetchAllergenEntries(version.id, controller.signal),
        fetchAllergenHistory(version.id, controller.signal),
      ])
      return { versionId: version.id, entries, history }
    })).then((results) => {
      if (controller.signal.aborted) return
      setEntriesByVersion((current) => ({
        ...current,
        ...Object.fromEntries(results.map(({ versionId, entries }) => [versionId, entries])),
      }))
      setHistoryByVersion((current) => ({
        ...current,
        ...Object.fromEntries(results.map(({ versionId, history }) => [versionId, history])),
      }))
      setError(null)
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted) setError(errorText(cause))
    }).finally(() => {
      if (!controller.signal.aborted) setColumnsLoading(false)
    })
    return () => controller.abort()
  }, [columns, reload])

  const loading = sourcesLoading || columnsLoading

  function reloadDictionaries() {
    setSourcesLoading(true)
    setColumnsLoading(true)
    setReload((value) => value + 1)
  }

  async function submitEntry(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!form) return
    setBusy(true)
    setFormError(null)
    setSuccess(null)
    try {
      const input: AllergenSourceEntryInput = {
        source_name: form.sourceName.trim(),
        status: form.status,
        listing_rule: form.listingRule.trim(),
        legal_effective_from: form.effectiveFrom || null,
        transition_end: form.transitionEnd || null,
        source_scope: form.sourceScope.trim(),
        threshold: parseObject(form.threshold, 'Próg'),
        exceptions: form.exceptions.split('\n').map((item) => item.trim()).filter(Boolean),
        note: form.note.trim() || null,
        metadata: parseObject(form.metadata, 'Metadane') ?? {},
      }
      if (form.entry) {
        await updateAllergenEntry(form.entry.id, input)
      } else {
        const newCanonical = form.canonicalMode === 'new' ? {
          id: form.newCanonicalId.trim(),
          canonical_pl: form.canonicalPl.trim(),
          parent_id: form.parentId || null,
          synonyms_pl: form.synonyms.split(',').map((item) => item.trim()).filter(Boolean),
          original_names: parseStringObject(form.originalNames, 'Nazwy oryginalne'),
        } : undefined
        if (newCanonical) {
          await createAllergenEntry(form.versionId, { ...input, new_canonical: newCanonical })
        } else {
          await createAllergenEntry(form.versionId, { ...input, canonical_allergen_id: form.canonicalId })
        }
      }
      setForm(null)
      setSuccess(form.entry ? 'Zapisano wpis słownika.' : 'Dodano wpis słownika.')
      reloadDictionaries()
    } catch (cause) {
      setFormError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  async function saveCanonical(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canonicalForm) return
    setBusy(true)
    setFormError(null)
    try {
      await updateCanonicalAllergen(canonicalForm.versionId, canonicalForm.allergen.id, {
        canonical_pl: canonicalForm.canonicalPl.trim(),
        synonyms_pl: canonicalForm.synonyms.split(',').map((item) => item.trim()).filter(Boolean),
      })
      setCanonicalForm(null)
      setSuccess('Zapisano definicję kanoniczną.')
      reloadDictionaries()
    } catch (cause) {
      setFormError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  async function archiveEntry(entry: AllergenSourceEntry) {
    setBusy(true)
    setError(null)
    setSuccess(null)
    try {
      await archiveAllergenEntry(entry.id)
      setSuccess('Zarchiwizowano powiązanie ze słownikiem.')
      reloadDictionaries()
    } catch (cause) {
      setError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  async function archiveCanonicalEntry(entry: AllergenSourceEntry) {
    if (!window.confirm(`Zarchiwizować alergen kanoniczny „${entry.canonicalPl}”? Zostanie usunięty z bieżącej puli RAW.`)) return
    setBusy(true)
    setError(null)
    setSuccess(null)
    try {
      await archiveCanonicalAllergen(entry.dictionaryVersionId, entry.allergenId)
      setSuccess('Zarchiwizowano alergen kanoniczny.')
      reloadDictionaries()
    } catch (cause) {
      setError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  return <section className="admin-allergen-page" aria-labelledby="admin-allergen-title">
    <header className="admin-category-header">
      <div>
        <h2 id="admin-allergen-title">Międzynarodowe słowniki alergenów</h2>
        <p>Porównuj wpisy źródłowe i ich wspólne definicje kanoniczne. Archiwizacja nie zmienia historycznych deklaracji RAW.</p>
      </div>
    </header>

    {success && <p className="notice notice-success" role="status">{success}</p>}
    {error && <p className="inline-error" role="alert">{error}</p>}

    {sourcesLoading ? <p role="status">Ładowanie słowników alergenów…</p> : columns.length === 0
      ? <p className="admin-allergen-empty">Brak skonfigurowanych słowników źródłowych.</p>
      : <div className="admin-allergen-source-grid" aria-label="Słowniki źródłowe alergenów">
        {columns.map(({ source, version }) => {
          const sourceEntries = entriesByVersion[version.id] ?? []
          const filter = statusBySource[source.sourceCode] ?? 'active'
          const normalizedSearch = (searchBySource[source.sourceCode] ?? '').trim().toLocaleLowerCase('pl-PL')
          const visibleEntries = sourceEntries.filter((entry) => {
            if (filter === 'active' && entry.archivedAt !== null) return false
            if (filter === 'archived' && entry.archivedAt === null) return false
            return !normalizedSearch || `${entry.canonicalPl} ${entry.sourceName} ${entry.allergenId}`
              .toLocaleLowerCase('pl-PL').includes(normalizedSearch)
          })

          return <section className="admin-allergen-column" key={source.sourceCode} aria-labelledby={`allergen-source-${source.sourceCode}`}>
            <header className="admin-allergen-column-header">
              <div className="admin-allergen-source-title">
                <h3 id={`allergen-source-${source.sourceCode}`}>{source.namePl}</h3>
                <span className="admin-allergen-jurisdiction">{source.jurisdiction}</span>
              </div>
              {source.versions.length > 1
                ? <label className="create-form-field">Wersja słownika
                  <select value={version.id} disabled={loading} onChange={(event) => {
                    setSelectedVersionIds((current) => ({ ...current, [source.sourceCode]: event.target.value }))
                    setColumnsLoading(true)
                  }}>
                    {source.versions.map((item) => <option key={item.id} value={item.id}>{sourceVersionLabel(item)}</option>)}
                  </select>
                </label>
                : <p className="admin-allergen-version-code">{version.code}</p>}
              <div className="admin-allergen-version-state">
                <span className={`admin-allergen-status status-${version.status.toLowerCase()}`}>
                  {VERSION_STATUSES[version.status]}
                </span>
                <span>Obowiązuje od: {dateLabel(version.effectiveFrom, 'nie określono')}</span>
                {version.effectiveUntil && <span>Do: {version.effectiveUntil}</span>}
                <span>Zweryfikowano: {dateLabel(version.lastVerifiedOn, 'nie określono')}</span>
                <span>Ręczna aktualizacja: {version.lastManualEditAt ?? 'brak'}</span>
                {version.officialUrl && <a href={version.officialUrl} target="_blank" rel="noreferrer">Oficjalne źródło</a>}
              </div>
              <label className="admin-allergen-column-search">Szukaj w tym słowniku
                <input type="search" value={searchBySource[source.sourceCode] ?? ''} onChange={(event) =>
                  setSearchBySource((current) => ({ ...current, [source.sourceCode]: event.target.value }))} />
              </label>
              <label className="admin-allergen-column-filter">Status wpisu
                <select value={filter} onChange={(event) => {
                  const value = event.target.value
                  if (value === 'all' || value === 'active' || value === 'archived') {
                    setStatusBySource((current) => ({ ...current, [source.sourceCode]: value }))
                  }
                }}>
                  <option value="active">Aktywne</option><option value="archived">Archiwalne</option><option value="all">Wszystkie</option>
                </select>
              </label>
              <button type="button" className="button button-primary admin-allergen-add"
                disabled={busy || loading}
                onClick={() => { setForm(entryForm(null, version.id)); setFormError(null) }}>+ Dodaj alergen</button>
            </header>

            {loading && !entriesByVersion[version.id]
              ? <p role="status">Ładowanie wpisów…</p>
              : visibleEntries.length === 0
                ? <p className="admin-allergen-empty">{sourceEntries.length === 0 ? 'Brak wpisów w tej wersji.' : 'Brak wyników dla filtra.'}</p>
                : <div className="admin-allergen-entries">
                  {visibleEntries.map((entry) => {
                    const canonicalDefinition = canonical.find((item) => item.id === entry.allergenId)
                    return <article className="admin-allergen-entry" key={entry.id}>
                      <div className="admin-allergen-canonical">
                        <span>Alergen kanoniczny</span>
                        <strong>{entry.canonicalPl}</strong>
                        <code>{entry.allergenId}</code>
                      </div>
                      <div className="admin-allergen-source-entry">
                        <span>Wpis źródłowy</span>
                        <strong>{entry.sourceName}</strong>
                        <span className={`admin-allergen-status status-${entry.status.toLowerCase()}`}>
                          {entry.archivedAt ? 'Archiwalny' : STATUSES.find((item) => item.value === entry.status)?.label}
                        </span>
                      </div>
                      <dl>
                        <div><dt>Reguła</dt><dd>{entry.listingRule}</dd></div>
                        <div><dt>Zakres</dt><dd>{entry.sourceScope}</dd></div>
                        {entry.legalEffectiveFrom && <div><dt>Data wejścia w życie</dt><dd>{entry.legalEffectiveFrom}</dd></div>}
                        {entry.transitionEnd && <div><dt>Koniec okresu przejściowego</dt><dd>{entry.transitionEnd}</dd></div>}
                      </dl>
                      {entry.threshold && <p><strong>Próg:</strong> <code>{JSON.stringify(entry.threshold)}</code></p>}
                      {entry.exceptions.length > 0 && <div><strong>Wyjątki:</strong><ul>{entry.exceptions.map((item, index) =>
                        <li key={`${entry.id}-exception-${index}`}>{item}</li>)}</ul></div>}
                      {entry.note && <p>{entry.note}</p>}
                      {entry.metadata && Object.keys(entry.metadata).length > 0 && <details>
                        <summary>Metadane źródłowe</summary><pre>{JSON.stringify(entry.metadata, null, 2)}</pre>
                      </details>}
                      <div className="admin-category-actions admin-allergen-entry-actions">
                        {!entry.archivedAt && <button type="button" className="button button-secondary" disabled={busy}
                          onClick={() => { setForm(entryForm(entry, version.id)); setFormError(null) }}>Edytuj wpis</button>}
                        {!entry.archivedAt && entry.canonicalStatus === 'ACTIVE' && canonicalDefinition && <>
                          <button type="button" className="button button-secondary" disabled={busy}
                            onClick={() => setCanonicalForm({
                              versionId: version.id,
                              allergen: canonicalDefinition,
                              canonicalPl: entry.canonicalPl,
                              synonyms: canonicalDefinition.synonymsPl.join(', '),
                            })}>Edytuj nazwę kanoniczną</button>
                          <button type="button" className="button button-secondary" disabled={busy}
                            onClick={() => void archiveCanonicalEntry(entry)}>Archiwizuj alergen globalnie</button>
                        </>}
                        {!entry.archivedAt && <button type="button" className="button button-secondary" disabled={busy}
                          onClick={() => void archiveEntry(entry)}>Archiwizuj powiązanie</button>}
                      </div>
                    </article>
                  })}
                </div>}

            <details className="admin-allergen-column-history">
              <summary>Historia zmian ({(historyByVersion[version.id] ?? []).length})</summary>
              {(historyByVersion[version.id] ?? []).length === 0
                ? <p>Brak ręcznych zmian w tej wersji.</p>
                : <ol>{(historyByVersion[version.id] ?? []).map((event) => <li key={event.id}>
                  <strong>{event.event_code}</strong> · {new Date(event.occurred_at).toLocaleString('pl-PL')}
                  <details><summary>Szczegóły zmiany</summary>
                    <pre>{JSON.stringify({ before: event.before, after: event.after }, null, 2)}</pre>
                  </details>
                </li>)}</ol>}
            </details>
          </section>
        })}
      </div>}

    {form && <form className="admin-allergen-form admin-category-form" onSubmit={(event) => void submitEntry(event)}>
      <h3>{form.entry ? `Edytuj wpis: ${form.entry.canonicalPl}` : 'Nowy wpis słownika'}</h3>
      {!form.entry && <>
        <label className="create-form-field">Sposób dodania alergenu
          <select value={form.canonicalMode} onChange={(event) => setForm({ ...form, canonicalMode: event.target.value as EntryForm['canonicalMode'] })}>
            <option value="existing">Wybierz z puli kanonicznej</option><option value="new">Utwórz nowy alergen kanoniczny</option>
          </select>
        </label>
        {form.canonicalMode === 'existing' ? <label className="create-form-field">Alergen kanoniczny
          <select required value={form.canonicalId} onChange={(event) => setForm({ ...form, canonicalId: event.target.value })}>
            <option value="">Wybierz alergen</option>
            {canonical.filter((item) => item.recordStatus === 'ACTIVE').map((item) =>
              <option key={item.id} value={item.id}>{item.canonicalPl} · {item.id}</option>)}
          </select>
        </label> : <>
          <label className="create-form-field">Identyfikator kanoniczny<input required maxLength={40}
            pattern="[A-Z][A-Z0-9_]{0,39}" value={form.newCanonicalId}
            onChange={(event) => setForm({ ...form, newCanonicalId: event.target.value })} /></label>
          <label className="create-form-field">Nazwa kanoniczna<input required maxLength={160} value={form.canonicalPl}
            onChange={(event) => setForm({ ...form, canonicalPl: event.target.value })} /></label>
          <label className="create-form-field">Alergen nadrzędny
            <select value={form.parentId} onChange={(event) => setForm({ ...form, parentId: event.target.value })}>
              <option value="">Brak</option>{canonical.filter((item) => item.recordStatus === 'ACTIVE').map((item) =>
                <option key={item.id} value={item.id}>{item.canonicalPl}</option>)}
            </select>
          </label>
          <label className="create-form-field">Polskie synonimy, oddzielone przecinkami<input value={form.synonyms}
            onChange={(event) => setForm({ ...form, synonyms: event.target.value })} /></label>
          <label className="create-form-field admin-allergen-wide">Nazwy oryginalne — obiekt JSON<textarea required
            value={form.originalNames} onChange={(event) => setForm({ ...form, originalNames: event.target.value })} /></label>
        </>}
      </>}
      <p className="admin-allergen-form-context admin-allergen-wide">
        Słownik docelowy: {columns.find(({ version }) => version.id === form.versionId)?.version.code ?? form.versionId}
      </p>
      <label className="create-form-field">Nazwa w źródle<input required maxLength={180} value={form.sourceName}
        onChange={(event) => setForm({ ...form, sourceName: event.target.value })} /></label>
      <label className="create-form-field">Status źródłowy<select value={form.status}
        onChange={(event) => setForm({ ...form, status: event.target.value as AllergenListingStatus })}>
        {STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
      </select></label>
      <label className="create-form-field">Reguła źródłowa<input required maxLength={64} value={form.listingRule}
        onChange={(event) => setForm({ ...form, listingRule: event.target.value })} /></label>
      <label className="create-form-field">Zakres źródła<input required maxLength={40} value={form.sourceScope}
        onChange={(event) => setForm({ ...form, sourceScope: event.target.value })} /></label>
      <label className="create-form-field">Data wejścia w życie<input type="date" value={form.effectiveFrom}
        onChange={(event) => setForm({ ...form, effectiveFrom: event.target.value })} /></label>
      <label className="create-form-field">Koniec okresu przejściowego<input type="date" value={form.transitionEnd}
        onChange={(event) => setForm({ ...form, transitionEnd: event.target.value })} /></label>
      <label className="create-form-field">Próg — obiekt JSON<textarea value={form.threshold}
        onChange={(event) => setForm({ ...form, threshold: event.target.value })} /></label>
      <label className="create-form-field">Wyjątki — jeden wpis w wierszu<textarea value={form.exceptions}
        onChange={(event) => setForm({ ...form, exceptions: event.target.value })} /></label>
      <label className="create-form-field">Notatka<textarea value={form.note}
        onChange={(event) => setForm({ ...form, note: event.target.value })} /></label>
      <label className="create-form-field admin-allergen-wide">Metadane — obiekt JSON<textarea required value={form.metadata}
        onChange={(event) => setForm({ ...form, metadata: event.target.value })} /></label>
      {formError && <p className="inline-error admin-allergen-wide" role="alert">{formError}</p>}
      <div className="admin-category-actions admin-allergen-wide">
        <button type="submit" className="button button-primary" disabled={busy}>Zapisz wpis</button>
        <button type="button" className="button button-secondary" disabled={busy} onClick={() => setForm(null)}>Anuluj</button>
      </div>
    </form>}

    {canonicalForm && <form className="admin-allergen-form admin-category-form" onSubmit={(event) => void saveCanonical(event)}>
      <h3>Edytuj nazwę kanoniczną</h3>
      <label className="create-form-field">Nazwa polska<input required maxLength={160} value={canonicalForm.canonicalPl}
        onChange={(event) => setCanonicalForm({ ...canonicalForm, canonicalPl: event.target.value })} /></label>
      <label className="create-form-field">Synonimy, oddzielone przecinkami<input value={canonicalForm.synonyms}
        onChange={(event) => setCanonicalForm({ ...canonicalForm, synonyms: event.target.value })} /></label>
      {formError && <p className="inline-error admin-allergen-wide" role="alert">{formError}</p>}
      <div className="admin-category-actions admin-allergen-wide">
        <button type="submit" className="button button-primary" disabled={busy}>Zapisz nazwę</button>
        <button type="button" className="button button-secondary" disabled={busy} onClick={() => setCanonicalForm(null)}>Anuluj</button>
      </div>
    </form>}
  </section>
}
