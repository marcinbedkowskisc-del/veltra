import { useEffect, useLayoutEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import AnimatedPanel from '../design-system/AnimatedPanel'
import VeltraDisclosure from '../design-system/VeltraDisclosure'
import { allergenDisplayName } from './allergenDisplayNames'
import GlobalPrecautionaryAllergenSection from './GlobalPrecautionaryAllergenSection'
import {
  archiveAllergenEntry,
  createAllergenEntry,
  fetchAllergenEntries,
  fetchAllergenSources,
  fetchCanonicalAllergens,
  updateAllergenEntry,
  type AllergenDictionaryVersion,
  type AllergenListingStatus,
  type AllergenSource,
  type AllergenSourceEntry,
  type AllergenSourceEntryInput,
  type CanonicalAllergen,
} from './allergensApi'

type StatusFilter = 'active' | 'archived'
type DictionaryColumn = { source: AllergenSource; version: AllergenDictionaryVersion }
type EntryForm = {
  versionId: string
  entry: AllergenSourceEntry | null
  canonicalMode: 'existing' | 'new'
  canonicalId: string
  canonicalName: string
  sourceName: string
  sourceNote: string
  status: AllergenListingStatus | ''
  listingRule: string
  sourceScope: string
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

const LISTING_RULES = [
  ['DIRECT', 'Wpis wskazany bezpośrednio'],
  ['INHERITED_FROM_WHEAT', 'Odniesienie do wpisu dotyczącego pszenicy'],
  ['EXAMPLE_SPECIES', 'Przykładowy gatunek w grupie'],
  ['SEPARATE_CLAUSE_4_2_1_7', 'Osobny punkt wykazu Codex'],
  ['CLAUSE_4_2_1_5', 'Punkt wykazu Codex'],
  ['SOURCE_CATEGORY', 'Kategoria wskazana przez źródło'],
  ['INHERITED_FROM_RECOMMENDED_GROUP', 'Odniesienie do zalecanej grupy'],
  ['SOURCE_EXPLICIT_SUBTYPE', 'Podtyp wskazany przez źródło'],
  ['NOT_ALLERGEN_LIST', 'Pozycja poza wykazem alergenów'],
] as const

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

function errorText(cause: unknown): string {
  if (cause instanceof ApiError) return Object.values(cause.errors).flat()[0] ?? cause.message
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.'
}

function preferredVersion(source: AllergenSource): AllergenDictionaryVersion | undefined {
  return source.versions.find((version) => version.status === 'ACTIVE')
    ?? source.versions.find((version) => version.status === 'ACTIVE_STANDARD')
    ?? source.versions[0]
}

function newEntryForm(versionId: string): EntryForm {
  return {
    versionId,
    entry: null,
    canonicalMode: 'existing',
    canonicalId: '',
    canonicalName: '',
    sourceName: '',
    sourceNote: '',
    status: '',
    listingRule: '',
    sourceScope: '',
  }
}

function sourceVersionLabel(version: AllergenDictionaryVersion): string {
  if (version.status === 'FUTURE') return `Przyszły wykaz · od ${version.effectiveFrom ?? 'nie określono'}`
  if (version.status === 'HISTORICAL') return `Poprzedni wykaz · ${version.effectiveUntil ?? 'historyczny'}`
  return version.status === 'ACTIVE_STANDARD' ? 'Obowiązujący standard' : 'Bieżący wykaz'
}

function sourceVersionStatusLabel(version: AllergenDictionaryVersion): string {
  if (version.status === 'FUTURE') return 'Przyszła'
  if (version.status === 'HISTORICAL') return 'Historyczna'
  return version.status === 'ACTIVE_STANDARD' ? 'Standard obowiązujący' : 'Bieżąca'
}

function dateLabel(value: string | null, emptyLabel: string): string {
  return value ?? emptyLabel
}

export default function AllergenDictionaryManagementPage() {
  const [activeSection, setActiveSection] = useState<'dictionaries' | 'precautionary'>('dictionaries')
  const [sources, setSources] = useState<AllergenSource[]>([])
  const [canonical, setCanonical] = useState<CanonicalAllergen[]>([])
  const [selectedVersionIds, setSelectedVersionIds] = useState<Record<string, string>>({})
  const [entriesByVersion, setEntriesByVersion] = useState<Record<string, AllergenSourceEntry[]>>({})
  const [searchBySource, setSearchBySource] = useState<Record<string, string>>({})
  const [statusBySource, setStatusBySource] = useState<Record<string, StatusFilter>>({})
  const [form, setForm] = useState<EntryForm | null>(null)
  const [canonicalSearch, setCanonicalSearch] = useState('')
  const [openMenuEntryId, setOpenMenuEntryId] = useState<string | null>(null)
  const [menuPlacement, setMenuPlacement] = useState<'top' | 'bottom'>('bottom')
  const [sourcesLoading, setSourcesLoading] = useState(true)
  const [columnsLoading, setColumnsLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [reload, setReload] = useState(0)
  const menuButtonRefs = useRef<Record<string, HTMLButtonElement | null>>({})
  const menuRefs = useRef<Record<string, HTMLDivElement | null>>({})

  useLayoutEffect(() => {
    if (!openMenuEntryId) return
    const trigger = menuButtonRefs.current[openMenuEntryId]?.getBoundingClientRect()
    const menuHeight = menuRefs.current[openMenuEntryId]?.getBoundingClientRect().height
    if (!trigger || menuHeight === undefined) return
    const spaceAbove = trigger.top
    const spaceBelow = window.innerHeight - trigger.bottom
    setMenuPlacement(spaceBelow >= menuHeight || spaceBelow >= spaceAbove ? 'bottom' : 'top')
  }, [openMenuEntryId])

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
  const loading = sourcesLoading || columnsLoading

  useEffect(() => {
    if (columns.length === 0) return
    const controller = new AbortController()
    void Promise.all(columns.map(async ({ version }) => {
      const entries = await fetchAllergenEntries(version.id, controller.signal)
      return { versionId: version.id, entries }
    })).then((results) => {
      if (controller.signal.aborted) return
      setEntriesByVersion((current) => ({
        ...current,
        ...Object.fromEntries(results.map(({ versionId, entries }) => [versionId, entries])),
      }))
      setError(null)
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted) setError(errorText(cause))
    }).finally(() => {
      if (!controller.signal.aborted) setColumnsLoading(false)
    })
    return () => controller.abort()
  }, [columns, reload])

  useEffect(() => {
    if (!openMenuEntryId) return
    const entryId = openMenuEntryId
    menuRefs.current[entryId]?.querySelector<HTMLButtonElement>('[role="menuitem"]')?.focus()

    function closeMenu(event: KeyboardEvent) {
      if (event.key !== 'Escape') return
      menuButtonRefs.current[entryId]?.focus()
      setOpenMenuEntryId(null)
    }

    function closeOnOutsideClick(event: PointerEvent) {
      const target = event.target
      if (!(target instanceof Node) || menuRefs.current[entryId]?.contains(target)
        || menuButtonRefs.current[entryId]?.contains(target)) return
      setOpenMenuEntryId(null)
    }

    document.addEventListener('keydown', closeMenu)
    document.addEventListener('pointerdown', closeOnOutsideClick)
    return () => {
      document.removeEventListener('keydown', closeMenu)
      document.removeEventListener('pointerdown', closeOnOutsideClick)
    }
  }, [openMenuEntryId])

  const formVersion = form
    ? columns.find(({ version }) => version.id === form.versionId)?.version
    : undefined
  const formSource = form
    ? sources.find((source) => source.versions.some((version) => version.id === form.versionId))
    : undefined
  const selectedCanonical = form?.entry
    ? canonical.find((item) => item.id === form.entry?.allergenId)
    : canonical.find((item) => item.id === form?.canonicalId)
  const existingRelationships = form
    ? new Set((entriesByVersion[form.versionId] ?? []).map((entry) => entry.allergenId))
    : new Set<string>()
  const canonicalOptions = canonical.filter((item) => item.recordStatus === 'ACTIVE'
    && !existingRelationships.has(item.id)
    && `${allergenDisplayName(item.id, item.canonicalPl)} ${item.canonicalPl} ${item.synonymsPl.join(' ')}`
      .toLocaleLowerCase('pl-PL')
      .includes(canonicalSearch.trim().toLocaleLowerCase('pl-PL')))

  function reloadDictionaries() {
    setSourcesLoading(true)
    setColumnsLoading(true)
    setOpenMenuEntryId(null)
    setReload((value) => value + 1)
  }

  function openAddForm(versionId: string) {
    setCanonicalSearch('')
    setFormError(null)
    setForm(newEntryForm(versionId))
  }

  function openEditForm(entry: AllergenSourceEntry, versionId: string) {
    setOpenMenuEntryId(null)
    setFormError(null)
    setForm({
      versionId,
      entry,
      canonicalMode: 'existing',
      canonicalId: entry.allergenId,
      canonicalName: entry.canonicalPl,
      sourceName: entry.sourceName,
      sourceNote: entry.note ?? '',
      status: entry.status,
      listingRule: entry.listingRule,
      sourceScope: entry.sourceScope,
    })
  }

  async function submitEntry(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!form) return
    setBusy(true)
    setFormError(null)
    setSuccess(null)

    try {
      const existing = form.entry
      const input: AllergenSourceEntryInput = {
        source_name: form.sourceName.trim() || existing?.sourceName || selectedCanonical?.canonicalPl || form.canonicalName,
        status: existing?.status ?? form.status as AllergenListingStatus,
        listing_rule: existing?.listingRule ?? form.listingRule,
        legal_effective_from: existing?.legalEffectiveFrom ?? null,
        transition_end: existing?.transitionEnd ?? null,
        source_scope: existing?.sourceScope ?? form.sourceScope,
        threshold: existing?.threshold ?? null,
        exceptions: existing?.exceptions ?? [],
        note: form.sourceNote.trim() || null,
        metadata: existing?.metadata ?? {},
      }

      if (existing) {
        await updateAllergenEntry(existing.id, input)
      } else if (form.canonicalMode === 'new') {
        const canonicalName = form.canonicalName.trim()
        if (!canonicalName) throw new Error('Wpisz nazwę nowego alergenu.')
        if (!form.status || !form.listingRule || !form.sourceScope) {
          throw new Error('Uzupełnij brakujące informacje na podstawie oficjalnego źródła.')
        }
        const language = formSource ? SOURCE_LANGUAGES[formSource.jurisdiction] : undefined
        const originalName = form.sourceName.trim()
        await createAllergenEntry(form.versionId, {
          ...input,
          new_canonical: {
            canonical_pl: canonicalName,
            parent_id: null,
            synonyms_pl: [],
            original_names: language && originalName ? { [language]: originalName } : {},
          },
        })
      } else {
        if (!form.canonicalId) throw new Error('Wybierz alergen z listy.')
        if (!form.status || !form.listingRule || !form.sourceScope) {
          throw new Error('Uzupełnij brakujące informacje na podstawie oficjalnego źródła.')
        }
        await createAllergenEntry(form.versionId, {
          ...input,
          canonical_allergen_id: form.canonicalId,
        })
      }
      setForm(null)
      setSuccess(existing ? 'Zapisano zmiany.' : 'Dodano alergen do słownika.')
      reloadDictionaries()
    } catch (cause) {
      setFormError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  async function archiveEntry(entry: AllergenSourceEntry, source: AllergenSource) {
    setOpenMenuEntryId(null)
    const displayName = allergenDisplayName(entry.allergenId, entry.canonicalPl)
    if (!window.confirm(`Usunąć „${displayName}” ze słownika ${source.namePl}? Usunięta zostanie tylko ta pozycja w wybranym słowniku. Historia i wcześniejsze deklaracje alergenów surowca pozostaną bez zmian.`)) return
    setBusy(true)
    setError(null)
    setSuccess(null)
    try {
      await archiveAllergenEntry(entry.id)
      setSuccess(`Usunięto „${displayName}” ze słownika ${source.namePl}.`)
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
        <h2 id="admin-allergen-title">Słowniki alergenów</h2>
        <p>Wybierz kraj lub organizację, aby zobaczyć listę alergenów.</p>
      </div>
    </header>

    {success && <p className="notice notice-success" role="status">{success}</p>}
    {error && <p className="inline-error" role="alert">{error}</p>}

    <div className="admin-allergen-tabs" role="tablist" aria-label="Sekcje alergenów">
      <button type="button" className={`button ${activeSection === 'dictionaries' ? 'button-primary' : 'button-secondary'}`}
        id="admin-allergen-dictionaries-tab" role="tab" aria-selected={activeSection === 'dictionaries'}
        aria-controls="admin-allergen-dictionaries-panel" tabIndex={activeSection === 'dictionaries' ? 0 : -1}
        onClick={() => setActiveSection('dictionaries')}>
        Słowniki międzynarodowe
      </button>
      <button type="button" className={`button ${activeSection === 'precautionary' ? 'button-primary' : 'button-secondary'}`}
        id="admin-allergen-precautionary-tab" role="tab" aria-selected={activeSection === 'precautionary'}
        aria-controls="admin-allergen-precautionary-panel" tabIndex={activeSection === 'precautionary' ? 0 : -1}
        onClick={() => setActiveSection('precautionary')}>
        MOŻE ZAWIERAĆ
      </button>
    </div>

    {activeSection === 'precautionary'
      ? <div id="admin-allergen-precautionary-panel" role="tabpanel" aria-labelledby="admin-allergen-precautionary-tab">
        <GlobalPrecautionaryAllergenSection canonical={canonical} />
      </div>
      : <div id="admin-allergen-dictionaries-panel" role="tabpanel" aria-labelledby="admin-allergen-dictionaries-tab">
        {sourcesLoading ? <p role="status">Ładowanie słowników alergenów…</p> : columns.length === 0
          ? <p className="admin-allergen-empty">Brak dostępnych list alergenów.</p>
          : <div className="admin-allergen-source-grid" aria-label="Słowniki alergenów">
        {columns.map(({ source, version }) => {
          const sourceEntries = entriesByVersion[version.id] ?? []
          const filter = statusBySource[source.sourceCode] ?? 'active'
          const normalizedSearch = (searchBySource[source.sourceCode] ?? '').trim().toLocaleLowerCase('pl-PL')
          const visibleEntries = sourceEntries.filter((entry) => {
            if (filter === 'active' && entry.archivedAt !== null) return false
            if (filter === 'archived' && entry.archivedAt === null) return false
            return !normalizedSearch
              || `${allergenDisplayName(entry.allergenId, entry.canonicalPl)} ${entry.canonicalPl} ${entry.sourceName}`
              .toLocaleLowerCase('pl-PL').includes(normalizedSearch)
          })

          return <section className="admin-allergen-column" key={source.sourceCode} aria-labelledby={`allergen-source-${source.sourceCode}`}>
            <header className="admin-allergen-column-header">
              <h3 id={`allergen-source-${source.sourceCode}`}>{source.namePl}</h3>
              {source.versions.length > 1 && <label className="admin-allergen-version-picker">
                Wersja słownika
                <select aria-label={`Wersja słownika — ${source.namePl}`} value={version.id} disabled={loading}
                  onChange={(event) => {
                    setSelectedVersionIds((current) => ({ ...current, [source.sourceCode]: event.target.value }))
                    setColumnsLoading(true)
                  }}>
                  {source.versions.map((item) => <option key={item.id} value={item.id}>{sourceVersionLabel(item)}</option>)}
                </select>
              </label>}
              <p className="admin-allergen-version-summary">
                <span>Wersja: {sourceVersionLabel(version)}</span>
                <span>Status: {sourceVersionStatusLabel(version)}</span>
                <span>Obowiązuje od: {dateLabel(version.effectiveFrom, 'nie określono')}</span>
                <span>Zweryfikowano: {dateLabel(version.lastVerifiedOn, 'nie określono')}</span>
              </p>
              <VeltraDisclosure className="admin-allergen-source-info" title="Informacje o źródle">
                <dl>
                  <div><dt>Wersja</dt><dd>{sourceVersionLabel(version)}</dd></div>
                  <div><dt>Organ</dt><dd>{version.authority}</dd></div>
                  <div><dt>Status</dt><dd>{sourceVersionStatusLabel(version)}</dd></div>
                  <div><dt>Obowiązuje od</dt><dd>{dateLabel(version.effectiveFrom, 'nie określono')}</dd></div>
                  {version.effectiveUntil && <div><dt>Obowiązuje do</dt><dd>{version.effectiveUntil}</dd></div>}
                  <div><dt>Zweryfikowano</dt><dd>{dateLabel(version.lastVerifiedOn, 'nie określono')}</dd></div>
                  {version.officialUrl && <div><dt>Źródło</dt><dd><a href={version.officialUrl} target="_blank" rel="noreferrer">Otwórz oficjalne źródło</a></dd></div>}
                </dl>
              </VeltraDisclosure>
              <label className="admin-allergen-column-search">
                <span className="visually-hidden">Szukaj w słowniku {source.namePl}</span>
                <input type="search" aria-label={`Szukaj w słowniku ${source.namePl}`}
                  placeholder="Szukaj alergenu"
                  value={searchBySource[source.sourceCode] ?? ''}
                  onChange={(event) => setSearchBySource((current) => ({ ...current, [source.sourceCode]: event.target.value }))} />
              </label>
              <label className="admin-allergen-column-filter">
                <span className="visually-hidden">Pokaż wpisy dla {source.namePl}</span>
                <select aria-label={`Pokaż wpisy dla ${source.namePl}`} value={filter}
                  onChange={(event) => setStatusBySource((current) => ({
                    ...current,
                    [source.sourceCode]: event.target.value === 'archived' ? 'archived' : 'active',
                  }))}>
                  <option value="active">Aktywne alergeny</option>
                  <option value="archived">Archiwalne alergeny</option>
                </select>
              </label>
            </header>

            {loading && !entriesByVersion[version.id]
              ? <p role="status">Ładowanie wpisów…</p>
              : visibleEntries.length === 0
                ? <p className="admin-allergen-empty">{sourceEntries.length === 0 ? 'Ta lista nie zawiera jeszcze alergenów.' : 'Brak alergenów dla tego filtra.'}</p>
                : <ul className="admin-allergen-entries" aria-label={`Alergeny — ${source.namePl}`}>
                  {visibleEntries.map((entry) => <li className="admin-allergen-row" key={entry.id} data-allergen-menu>
                    <span className="admin-allergen-row-name">{allergenDisplayName(entry.allergenId, entry.canonicalPl)}</span>
                    <button type="button" className="admin-allergen-menu-button" aria-haspopup="menu"
                      aria-expanded={openMenuEntryId === entry.id}
                      aria-label={`Opcje dla: ${allergenDisplayName(entry.allergenId, entry.canonicalPl)}`}
                      disabled={busy || entry.archivedAt !== null}
                      ref={(node) => { menuButtonRefs.current[entry.id] = node }}
                      onClick={() => setOpenMenuEntryId((current) => current === entry.id ? null : entry.id)}>
                      <span aria-hidden="true">⋯</span>
                    </button>
                    <div className="admin-allergen-context-menu" role="menu"
                      aria-label={`Opcje dla: ${allergenDisplayName(entry.allergenId, entry.canonicalPl)}`}
                      aria-hidden={openMenuEntryId !== entry.id}
                      inert={openMenuEntryId !== entry.id}
                      data-open={openMenuEntryId === entry.id}
                      data-placement={menuPlacement}
                      ref={(node) => { menuRefs.current[entry.id] = node }}>
                      <button type="button" role="menuitem"
                        aria-expanded={form?.entry?.id === entry.id}
                        aria-controls="admin-allergen-form-panel"
                        onClick={() => openEditForm(entry, version.id)}>Edytuj</button>
                      <button type="button" role="menuitem" onClick={() => void archiveEntry(entry, source)}>Usuń ze słownika</button>
                    </div>
                  </li>)}
                </ul>}

            {columnsLoading && <p className="admin-allergen-count" role="status">Ładowanie wpisów…</p>}
            {!columnsLoading && <p className="admin-allergen-count" aria-live="polite">
              {visibleEntries.length} {visibleEntries.length === 1 ? 'alergen' : 'alergenów'}
            </p>}
            <button type="button" className="button button-secondary admin-allergen-add"
              id={`admin-allergen-add-${version.id}`}
              aria-expanded={form?.versionId === version.id && !form.entry}
              aria-controls="admin-allergen-form-panel"
              disabled={busy || loading}
              onClick={() => openAddForm(version.id)}>+ Dodaj alergen</button>
          </section>
        })}
          </div>}

        <AnimatedPanel id="admin-allergen-form-panel" open={form !== null} role="region"
          ariaLabelledBy="admin-allergen-form-title"
          className="admin-allergen-form-panel">
        {form && <form className="admin-allergen-form admin-category-form" onSubmit={(event) => void submitEntry(event)}>
      <h3 id="admin-allergen-form-title">{form.entry ? 'Edytuj alergen' : 'Dodaj alergen do słownika'}</h3>
      <p className="admin-allergen-form-context admin-allergen-wide">
        Słownik: <strong>{formSource?.namePl}</strong>{formVersion?.status === 'FUTURE' ? ' — przyszła wersja' : ''}
      </p>
      {form.entry
        ? <div className="create-form-field admin-allergen-wide">
          <label>Nazwa alergenu
            <input value={allergenDisplayName(form.entry.allergenId, form.canonicalName)} readOnly aria-describedby="admin-allergen-canonical-help" />
          </label>
          <span id="admin-allergen-canonical-help" className="admin-allergen-field-help">
            Ta nazwa jest wspólna dla wszystkich słowników. Zmiany wprowadzone poniżej dotyczą tylko wybranego słownika.
          </span>
        </div>
        : <label className="create-form-field admin-allergen-wide">Sposób dodania
          <select value={form.canonicalMode} onChange={(event) => setForm({
            ...form,
            canonicalMode: event.target.value === 'new' ? 'new' : 'existing',
            canonicalId: '',
          })}>
            <option value="existing">Wybierz istniejący alergen</option>
            <option value="new">Dodaj nowy alergen</option>
          </select>
        </label>}

      {!form.entry && form.canonicalMode === 'existing' && <>
        <label className="create-form-field">Szukaj alergenu
          <input type="search" value={canonicalSearch} onChange={(event) => setCanonicalSearch(event.target.value)} />
        </label>
        <label className="create-form-field">Nazwa alergenu
          <select required value={form.canonicalId} onChange={(event) => setForm({ ...form, canonicalId: event.target.value })}>
            <option value="">Wybierz alergen</option>
            {canonicalOptions.map((item) => <option key={item.id} value={item.id}>{allergenDisplayName(item.id, item.canonicalPl)}</option>)}
          </select>
        </label>
      </>}

      {!form.entry && form.canonicalMode === 'new' && <div className="create-form-field admin-allergen-wide">
        <label>Nazwa alergenu
          <input required maxLength={160} value={form.canonicalName}
            onChange={(event) => setForm({ ...form, canonicalName: event.target.value })} />
        </label>
      </div>}

      <label className="create-form-field">Nazwa używana w tym słowniku
        <input maxLength={180} value={form.sourceName}
          onChange={(event) => setForm({ ...form, sourceName: event.target.value })} />
      </label>
      <label className="create-form-field admin-allergen-wide">Uwagi
        <textarea maxLength={2000} value={form.sourceNote}
          onChange={(event) => setForm({ ...form, sourceNote: event.target.value })} />
      </label>

      {!form.entry && <fieldset className="admin-allergen-legal-metadata admin-allergen-wide">
        <legend>Informacje wymagane dla tego słownika</legend>
        <p>Uzupełnij je na podstawie oficjalnego źródła. Nie wpisujemy ich automatycznie.</p>
        <label className="create-form-field">Status na liście
          <select required value={form.status} onChange={(event) => setForm({
            ...form,
            status: event.target.value as AllergenListingStatus | '',
          })}>
            <option value="">Wybierz status po weryfikacji</option>
            {STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
        </label>
        <label className="create-form-field">Jak alergen widnieje w źródle
          <select required value={form.listingRule} onChange={(event) => setForm({ ...form, listingRule: event.target.value })}>
            <option value="">Wybierz sposób ujęcia</option>
            {LISTING_RULES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
        <label className="create-form-field admin-allergen-wide">Gdzie obowiązuje
          <select required value={form.sourceScope} onChange={(event) => setForm({ ...form, sourceScope: event.target.value })}>
            <option value="">Wybierz na podstawie oficjalnego źródła</option>
            <option value="LEGAL_LIST">Na wykazie alergenów</option>
          </select>
        </label>
      </fieldset>}

      {formError && <p className="inline-error admin-allergen-wide" role="alert">{formError}</p>}
      <div className="admin-category-actions admin-allergen-wide">
        <button type="submit" className="button button-primary" disabled={busy}>
          {busy ? 'Zapisywanie…' : 'Zapisz'}
        </button>
        <button type="button" className="button button-secondary" disabled={busy}
          onClick={() => { setForm(null); setFormError(null) }}>Anuluj</button>
      </div>
        </form>}
        </AnimatedPanel>
      </div>}
  </section>
}
