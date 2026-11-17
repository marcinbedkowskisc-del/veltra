import { useEffect, useState, type FormEvent } from 'react'
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

type EntryForm = {
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

type CanonicalForm = { allergen: CanonicalAllergen; canonicalPl: string; synonyms: string }

const STATUSES: Array<{ value: AllergenListingStatus; label: string }> = [
  { value: 'MANDATORY', label: 'Obowiązkowy' },
  { value: 'RECOMMENDED', label: 'Zalecany' },
  { value: 'VOLUNTARY', label: 'Dobrowolny' },
  { value: 'NATIONAL_OPTION', label: 'Opcja krajowa' },
  { value: 'WARNING_ONLY', label: 'Tylko ostrzeżenie' },
  { value: 'FUTURE_MANDATORY', label: 'Obowiązkowy w przyszłości' },
  { value: 'HISTORICAL', label: 'Historyczny' },
]

function errorText(cause: unknown): string {
  if (cause instanceof ApiError) return Object.values(cause.errors).flat()[0] ?? cause.message
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.'
}

function entryForm(entry: AllergenSourceEntry | null): EntryForm {
  return {
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

function sourceVersionLabel(source: AllergenSource, version: AllergenDictionaryVersion): string {
  return `${source.namePl} · ${version.code} · ${version.status}`
}

export default function AllergenDictionaryManagementPage() {
  const [sources, setSources] = useState<AllergenSource[]>([])
  const [canonical, setCanonical] = useState<CanonicalAllergen[]>([])
  const [entries, setEntries] = useState<AllergenSourceEntry[]>([])
  const [history, setHistory] = useState<AllergenDictionaryHistoryEvent[]>([])
  const [versionId, setVersionId] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'archived'>('active')
  const [searchText, setSearchText] = useState('')
  const [search, setSearch] = useState('')
  const [form, setForm] = useState<EntryForm | null>(null)
  const [canonicalForm, setCanonicalForm] = useState<CanonicalForm | null>(null)
  const [loading, setLoading] = useState(true)
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
        setSources(sourceResult)
        setCanonical(canonicalResult)
        setVersionId((current) => current || sourceResult.flatMap((source) => source.versions)
          .find((version) => version.status === 'ACTIVE' || version.status === 'ACTIVE_STANDARD')?.id
          || sourceResult[0]?.versions[0]?.id || '')
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) setError(errorText(cause))
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [reload])

  useEffect(() => {
    if (!versionId) return
    const controller = new AbortController()
    void Promise.all([
      fetchAllergenEntries(versionId, controller.signal),
      fetchAllergenHistory(versionId, controller.signal),
    ]).then(([entryResult, historyResult]) => {
      if (controller.signal.aborted) return
      setEntries(entryResult)
      setHistory(historyResult)
      setError(null)
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted) setError(errorText(cause))
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [versionId, reload])

  const selectedVersion = sources.flatMap((source) => source.versions.map((version) => ({ source, version })))
    .find(({ version }) => version.id === versionId)
  const visibleEntries = entries.filter((entry) => {
    if (statusFilter === 'active' && entry.archivedAt !== null) return false
    if (statusFilter === 'archived' && entry.archivedAt === null) return false
    const normalized = search.trim().toLocaleLowerCase('pl-PL')
    return !normalized || `${entry.canonicalPl} ${entry.sourceName} ${entry.allergenId}`
      .toLocaleLowerCase('pl-PL').includes(normalized)
  })

  async function submitEntry(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!form || !versionId) return
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
          await createAllergenEntry(versionId, { ...input, new_canonical: newCanonical })
        } else {
          await createAllergenEntry(versionId, { ...input, canonical_allergen_id: form.canonicalId })
        }
      }
      setForm(null)
      setSuccess(form.entry ? 'Zapisano wpis słownika.' : 'Dodano wpis słownika.')
      setLoading(true)
      setReload((value) => value + 1)
    } catch (cause) {
      setFormError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  async function saveCanonical(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canonicalForm || !versionId) return
    setBusy(true)
    setFormError(null)
    try {
      await updateCanonicalAllergen(versionId, canonicalForm.allergen.id, {
        canonical_pl: canonicalForm.canonicalPl.trim(),
        synonyms_pl: canonicalForm.synonyms.split(',').map((item) => item.trim()).filter(Boolean),
      })
      setCanonicalForm(null)
      setSuccess('Zapisano definicję kanoniczną.')
      setLoading(true)
      setReload((value) => value + 1)
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
      setLoading(true)
      setReload((value) => value + 1)
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
      await archiveCanonicalAllergen(versionId, entry.allergenId)
      setSuccess('Zarchiwizowano alergen kanoniczny.')
      setLoading(true)
      setReload((value) => value + 1)
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
        <p>Wersjonowane dane źródłowe, wspólna pula kanoniczna i historia zmian. Archiwizacja nie zmienia historycznych deklaracji RAW.</p>
      </div>
      <button type="button" className="button button-primary" disabled={busy || loading || !versionId}
        onClick={() => { setForm(entryForm(null)); setFormError(null) }}>Dodaj alergen do słownika</button>
    </header>

    {success && <p className="notice notice-success" role="status">{success}</p>}
    {error && <p className="inline-error" role="alert">{error}</p>}

    <div className="admin-allergen-filters">
      <label className="create-form-field">Słownik i wersja
        <select value={versionId} onChange={(event) => {
          setLoading(true)
          setVersionId(event.target.value)
          setForm(null)
        }}>
          {sources.flatMap((source) => source.versions.map((version) =>
            <option key={version.id} value={version.id}>{sourceVersionLabel(source, version)}</option>))}
        </select>
      </label>
      <form className="admin-allergen-search" onSubmit={(event) => {
        event.preventDefault()
        setSearch(searchText)
      }}>
        <label className="create-form-field">Szukaj alergenu
          <input value={searchText} onChange={(event) => setSearchText(event.target.value)} />
        </label>
        <button type="submit" className="button button-secondary">Szukaj</button>
      </form>
      <label className="create-form-field">Status wpisu
        <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value as typeof statusFilter)}>
          <option value="active">Aktywne</option><option value="archived">Archiwalne</option><option value="all">Wszystkie</option>
        </select>
      </label>
    </div>

    {selectedVersion && <dl className="admin-allergen-version-meta">
      <div><dt>Wersja</dt><dd>{selectedVersion.version.code}</dd></div>
      <div><dt>Organ</dt><dd>{selectedVersion.version.authority}</dd></div>
      <div><dt>Jurysdykcja</dt><dd>{selectedVersion.source.jurisdiction}</dd></div>
      <div><dt>Obowiązuje od</dt><dd>{selectedVersion.version.effectiveFrom ?? 'Nie określono'}</dd></div>
      <div><dt>Obowiązuje do</dt><dd>{selectedVersion.version.effectiveUntil ?? 'Bez daty końcowej'}</dd></div>
      <div><dt>Weryfikacja źródła</dt><dd>{selectedVersion.version.lastVerifiedOn ?? 'Nie określono'}</dd></div>
      <div><dt>Ostatnia ręczna zmiana</dt><dd>{selectedVersion.version.lastManualEditAt ?? 'Brak'}</dd></div>
      <div><dt>Aktywne wpisy</dt><dd>{selectedVersion.version.entryCount}</dd></div>
      {selectedVersion.version.officialUrl && <div><dt>Źródło</dt><dd><a href={selectedVersion.version.officialUrl} target="_blank" rel="noreferrer">Oficjalny dokument</a></dd></div>}
      {Object.keys(selectedVersion.version.metadata).length > 0 && <div><dt>Metadane wersji</dt><dd><details>
        <summary>Pokaż metadane</summary><pre>{JSON.stringify(selectedVersion.version.metadata, null, 2)}</pre>
      </details></dd></div>}
    </dl>}

    {loading ? <p role="status">Ładowanie słowników alergenów…</p> : visibleEntries.length === 0
      ? <p className="admin-allergen-empty">Brak wpisów spełniających wybrane kryteria.</p>
      : <div className="admin-allergen-grid">
        {visibleEntries.map((entry) => {
          const canonicalDefinition = canonical.find((item) => item.id === entry.allergenId)
          return <article className="admin-allergen-card" key={entry.id}>
          <header><div><h3>{entry.canonicalPl}</h3><p>{entry.sourceName} · {entry.allergenId}</p></div>
            <span>{entry.archivedAt ? 'Archiwalny' : STATUSES.find((item) => item.value === entry.status)?.label}</span></header>
          <dl>
            <div><dt>Reguła</dt><dd>{entry.listingRule}</dd></div>
            <div><dt>Zakres</dt><dd>{entry.sourceScope}</dd></div>
            <div><dt>Data wejścia w życie</dt><dd>{entry.legalEffectiveFrom ?? 'Nie określono'}</dd></div>
            <div><dt>Koniec okresu przejściowego</dt><dd>{entry.transitionEnd ?? 'Nie określono'}</dd></div>
          </dl>
          {entry.threshold && <p><strong>Próg:</strong> <code>{JSON.stringify(entry.threshold)}</code></p>}
          {entry.exceptions.length > 0 && <div><strong>Wyjątki:</strong><ul>{entry.exceptions.map((item, index) => <li key={`${entry.id}-exception-${index}`}>{item}</li>)}</ul></div>}
          {entry.note && <p>{entry.note}</p>}
          {entry.metadata && Object.keys(entry.metadata).length > 0 && <details><summary>Metadane źródłowe</summary><pre>{JSON.stringify(entry.metadata, null, 2)}</pre></details>}
          <div className="admin-category-actions">
            {!entry.archivedAt && <button type="button" className="button button-secondary" disabled={busy}
              onClick={() => { setForm(entryForm(entry)); setFormError(null) }}>Edytuj wpis</button>}
            {!entry.archivedAt && entry.canonicalStatus === 'ACTIVE' && canonicalDefinition && <>
              <button type="button" className="button button-secondary" disabled={busy}
                onClick={() => setCanonicalForm({ allergen: canonicalDefinition, canonicalPl: entry.canonicalPl, synonyms: canonicalDefinition.synonymsPl.join(', ') })}>Edytuj nazwę kanoniczną</button>
              <button type="button" className="button button-secondary" disabled={busy} onClick={() => void archiveCanonicalEntry(entry)}>Archiwizuj alergen globalnie</button>
            </>}
            {!entry.archivedAt && <button type="button" className="button button-secondary" disabled={busy} onClick={() => void archiveEntry(entry)}>Archiwizuj powiązanie</button>}
          </div>
        </article>
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
            {canonical.filter((item) => item.recordStatus === 'ACTIVE').map((item) => <option key={item.id} value={item.id}>{item.canonicalPl} · {item.id}</option>)}
          </select>
        </label> : <>
          <label className="create-form-field">Identyfikator kanoniczny<input required maxLength={40} pattern="[A-Z][A-Z0-9_]{0,39}" value={form.newCanonicalId} onChange={(event) => setForm({ ...form, newCanonicalId: event.target.value })} /></label>
          <label className="create-form-field">Nazwa kanoniczna<input required maxLength={160} value={form.canonicalPl} onChange={(event) => setForm({ ...form, canonicalPl: event.target.value })} /></label>
          <label className="create-form-field">Alergen nadrzędny
            <select value={form.parentId} onChange={(event) => setForm({ ...form, parentId: event.target.value })}>
              <option value="">Brak</option>{canonical.filter((item) => item.recordStatus === 'ACTIVE').map((item) => <option key={item.id} value={item.id}>{item.canonicalPl}</option>)}
            </select>
          </label>
          <label className="create-form-field">Polskie synonimy, oddzielone przecinkami<input value={form.synonyms} onChange={(event) => setForm({ ...form, synonyms: event.target.value })} /></label>
          <label className="create-form-field admin-allergen-wide">Nazwy oryginalne — obiekt JSON<textarea required value={form.originalNames} onChange={(event) => setForm({ ...form, originalNames: event.target.value })} /></label>
        </>}
      </>}
      <label className="create-form-field">Nazwa w źródle<input required maxLength={180} value={form.sourceName} onChange={(event) => setForm({ ...form, sourceName: event.target.value })} /></label>
      <label className="create-form-field">Status źródłowy<select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value as AllergenListingStatus })}>
        {STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
      </select></label>
      <label className="create-form-field">Reguła źródłowa<input required maxLength={64} value={form.listingRule} onChange={(event) => setForm({ ...form, listingRule: event.target.value })} /></label>
      <label className="create-form-field">Zakres źródła<input required maxLength={40} value={form.sourceScope} onChange={(event) => setForm({ ...form, sourceScope: event.target.value })} /></label>
      <label className="create-form-field">Data wejścia w życie<input type="date" value={form.effectiveFrom} onChange={(event) => setForm({ ...form, effectiveFrom: event.target.value })} /></label>
      <label className="create-form-field">Koniec okresu przejściowego<input type="date" value={form.transitionEnd} onChange={(event) => setForm({ ...form, transitionEnd: event.target.value })} /></label>
      <label className="create-form-field">Próg — obiekt JSON<textarea value={form.threshold} onChange={(event) => setForm({ ...form, threshold: event.target.value })} /></label>
      <label className="create-form-field">Wyjątki — jeden wpis w wierszu<textarea value={form.exceptions} onChange={(event) => setForm({ ...form, exceptions: event.target.value })} /></label>
      <label className="create-form-field">Notatka<textarea value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} /></label>
      <label className="create-form-field admin-allergen-wide">Metadane — obiekt JSON<textarea required value={form.metadata} onChange={(event) => setForm({ ...form, metadata: event.target.value })} /></label>
      {formError && <p className="inline-error admin-allergen-wide" role="alert">{formError}</p>}
      <div className="admin-category-actions admin-allergen-wide">
        <button type="submit" className="button button-primary" disabled={busy}>Zapisz wpis</button>
        <button type="button" className="button button-secondary" disabled={busy} onClick={() => setForm(null)}>Anuluj</button>
      </div>
    </form>}

    {canonicalForm && <form className="admin-allergen-form admin-category-form" onSubmit={(event) => void saveCanonical(event)}>
      <h3>Edytuj nazwę kanoniczną</h3>
      <label className="create-form-field">Nazwa polska<input required maxLength={160} value={canonicalForm.canonicalPl} onChange={(event) => setCanonicalForm({ ...canonicalForm, canonicalPl: event.target.value })} /></label>
      <label className="create-form-field">Synonimy, oddzielone przecinkami<input value={canonicalForm.synonyms} onChange={(event) => setCanonicalForm({ ...canonicalForm, synonyms: event.target.value })} /></label>
      {formError && <p className="inline-error" role="alert">{formError}</p>}
      <div className="admin-category-actions"><button type="submit" className="button button-primary" disabled={busy}>Zapisz nazwę</button>
        <button type="button" className="button button-secondary" disabled={busy} onClick={() => setCanonicalForm(null)}>Anuluj</button></div>
    </form>}

    <section className="admin-allergen-history" aria-labelledby="allergen-history-title">
      <h3 id="allergen-history-title">Historia zmian — ostatnie 200 wpisów</h3>
      {history.length === 0 ? <p>Brak ręcznych zmian w tej wersji słownika.</p> : <ol>
        {history.map((event) => <li key={event.id}>
          <strong>{event.event_code}</strong> · {new Date(event.occurred_at).toLocaleString('pl-PL')} · użytkownik {event.actor_user_id ?? 'nieznany'}
          <details><summary>Szczegóły zmiany</summary><pre>{JSON.stringify({ before: event.before, after: event.after }, null, 2)}</pre></details>
        </li>)}
      </ol>}
    </section>
  </section>
}
