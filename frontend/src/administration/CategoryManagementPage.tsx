import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { ApiError } from '../api'
import VeltraAccordion from '../design-system/VeltraAccordion'
import {
  archiveAdministrationCategory,
  createAdministrationCategory,
  fetchAdministrationCategories,
  restoreAdministrationCategory,
  updateAdministrationCategory,
  type AdministrationCategory,
  type AdministrationCategoryPage,
} from './categoriesApi'

type CategoryForm = {
  mode: 'create' | 'edit'
  parent: AdministrationCategory | null
  category: AdministrationCategory | null
  code: string
  name: string
  description: string
}

type CategoryStatus = 'active' | 'archived' | 'all'

function errorText(cause: unknown): string {
  if (cause instanceof ApiError) {
    const validationMessage = Object.values(cause.errors).flat()[0]
    return validationMessage ?? cause.message
  }
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.'
}

function pageLabel(meta: AdministrationCategoryPage['meta']): string {
  return `Strona ${meta.currentPage} z ${meta.lastPage} · ${meta.total} kategorii`
}

type CategoryTreeListProps = {
  categories: AdministrationCategory[]
  groupId: string
  currentPage: AdministrationCategoryPage['meta']
  expandedByGroup: Record<string, string[]>
  childrenPages: Record<string, AdministrationCategoryPage>
  childrenLoading: Record<string, boolean>
  childrenErrors: Record<string, string>
  renderActions: (category: AdministrationCategory) => ReactNode
  onExpandedChange: (groupId: string, ids: string[], categories: AdministrationCategory[], previous: string[]) => void
  onPage: (groupId: string, page: number, isRoot: boolean) => void
  isRoot?: boolean
}

function CategoryTreeList({
  categories, groupId, currentPage, expandedByGroup, childrenPages, childrenLoading, childrenErrors,
  renderActions, onExpandedChange, onPage, isRoot = false,
}: CategoryTreeListProps) {
  const expanded = expandedByGroup[groupId] ?? []
  return (
    <>
      <VeltraAccordion
        expandedIds={expanded}
        onExpandedChange={(ids) => onExpandedChange(groupId, ids, categories, expanded)}
        items={categories.map((category) => ({
          id: category.id,
          title: `${category.name} · ${category.code}${category.archivedAt ? ' · Archiwalna' : ''}`,
          content: (
            <div className="admin-category-node">
              <dl className="admin-category-details">
                <div><dt>Poziom</dt><dd>{category.depth}</dd></div>
                <div><dt>Kategoria nadrzędna</dt><dd>{category.parentName ?? 'Brak — kategoria główna'}</dd></div>
                <div><dt>Status</dt><dd>{category.archivedAt === null ? 'Aktywna' : 'Archiwalna'}</dd></div>
                <div><dt>Powiązane kartoteki RAW</dt><dd>{category.rawMaterialCount}</dd></div>
              </dl>
              {category.description && <p className="admin-category-description">{category.description}</p>}
              {renderActions(category)}
              {expanded.includes(category.id) && (
                <div className="admin-category-children">
                  {childrenLoading[`${category.id}:1`] && <p role="status">Ładowanie podkategorii…</p>}
                  {childrenErrors[`${category.id}:1`] && <p className="inline-error" role="alert">{childrenErrors[`${category.id}:1`]}</p>}
                  {!category.hasChildren && <p className="admin-category-empty">Brak podkategorii.</p>}
                  {category.hasChildren && childrenPages[`${category.id}:1`] && (
                    childrenPages[`${category.id}:1`].data.length > 0 ? (
                      <CategoryTreeList
                        categories={childrenPages[`${category.id}:1`].data}
                        groupId={category.id}
                        currentPage={childrenPages[`${category.id}:1`].meta}
                        expandedByGroup={expandedByGroup}
                        childrenPages={childrenPages}
                        childrenLoading={childrenLoading}
                        childrenErrors={childrenErrors}
                        renderActions={renderActions}
                        onExpandedChange={onExpandedChange}
                        onPage={onPage}
                      />
                    ) : <p className="admin-category-empty">Brak podkategorii.</p>
                  )}
                </div>
              )}
            </div>
          ),
        }))}
      />
      {currentPage.lastPage > 1 && (
        <div className="admin-category-pagination" aria-label="Stronicowanie kategorii">
          <button type="button" className="button button-secondary" disabled={currentPage.currentPage <= 1}
            onClick={() => onPage(groupId, currentPage.currentPage - 1, isRoot)}>Poprzednia</button>
          <span aria-live="polite">{pageLabel(currentPage)}</span>
          <button type="button" className="button button-secondary" disabled={currentPage.currentPage >= currentPage.lastPage}
            onClick={() => onPage(groupId, currentPage.currentPage + 1, isRoot)}>Następna</button>
        </div>
      )}
    </>
  )
}

export default function CategoryManagementPage() {
  const [status, setStatus] = useState<CategoryStatus>('active')
  const [searchText, setSearchText] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [rootPage, setRootPage] = useState<AdministrationCategoryPage | null>(null)
  const [children, setChildren] = useState<Record<string, AdministrationCategoryPage>>({})
  const [childrenLoading, setChildrenLoading] = useState<Record<string, boolean>>({})
  const [childrenErrors, setChildrenErrors] = useState<Record<string, string>>({})
  const [expanded, setExpanded] = useState<Record<string, string[]>>({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [form, setForm] = useState<CategoryForm | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [busyCategoryId, setBusyCategoryId] = useState<string | null>(null)
  const [confirmArchive, setConfirmArchive] = useState<AdministrationCategory | null>(null)
  const [needsRefresh, setNeedsRefresh] = useState(false)
  const controllers = useRef(new Map<string, AbortController>())

  useEffect(() => () => {
    controllers.current.forEach((controller) => controller.abort())
    controllers.current.clear()
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    void fetchAdministrationCategories({ status, search, page }, controller.signal)
      .then((result) => {
        if (controller.signal.aborted) return
        setRootPage(result)
        setNeedsRefresh(false)
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) setError(errorText(cause))
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [status, search, page, reloadKey])

  useEffect(() => {
    controllers.current.forEach((controller) => controller.abort())
    controllers.current.clear()
  }, [status, search, page])

  function resetCategoryHierarchy() {
    controllers.current.forEach((controller) => controller.abort())
    controllers.current.clear()
    setChildren({})
    setChildrenLoading({})
    setChildrenErrors({})
    setExpanded({})
  }

  function reloadRootPage() {
    setLoading(true)
    setError(null)
    setReloadKey((current) => current + 1)
  }

  async function loadChildren(parentId: string, childPage = 1) {
    const key = `${parentId}:${childPage}`
    controllers.current.get(key)?.abort()
    const controller = new AbortController()
    controllers.current.set(key, controller)
    setChildrenLoading((current) => ({ ...current, [key]: true }))
    setChildrenErrors((current) => ({ ...current, [key]: '' }))
    try {
      const result = await fetchAdministrationCategories({
        status,
        parentId,
        page: childPage,
      }, controller.signal)
      if (!controller.signal.aborted) {
        setChildren((current) => ({ ...current, [key]: result }))
      }
    } catch (cause) {
      if (!controller.signal.aborted) {
        setChildrenErrors((current) => ({ ...current, [key]: errorText(cause) }))
      }
    } finally {
      if (!controller.signal.aborted) {
        controllers.current.delete(key)
        setChildrenLoading((current) => ({ ...current, [key]: false }))
      }
    }
  }

  function openCreate(parent: AdministrationCategory | null) {
    setSuccess(null)
    setFormError(null)
    setForm({
      mode: 'create',
      parent,
      category: null,
      code: '',
      name: '',
      description: '',
    })
  }

  function openEdit(category: AdministrationCategory) {
    setSuccess(null)
    setFormError(null)
    setForm({
      mode: 'edit',
      parent: null,
      category,
      code: category.code,
      name: category.name,
      description: category.description ?? '',
    })
  }

  async function submitForm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!form || busy || needsRefresh) return
    setBusy(true)
    setFormError(null)
    setSuccess(null)
    try {
      if (form.mode === 'create') {
        await createAdministrationCategory({
          parent_id: form.parent?.id ?? null,
          code: form.code.trim(),
          name: form.name.trim(),
          description: form.description.trim() || null,
        })
        setSuccess('Kategoria została utworzona.')
      } else if (form.category) {
        await updateAdministrationCategory(form.category.id, {
          name: form.name.trim(),
          description: form.description.trim() || null,
        })
        setSuccess('Zmiany kategorii zostały zapisane.')
      }
      setForm(null)
      resetCategoryHierarchy()
      reloadRootPage()
    } catch (cause) {
      setFormError(errorText(cause))
      if (!(cause instanceof ApiError) || cause.status >= 500) setNeedsRefresh(true)
    } finally {
      setBusy(false)
    }
  }

  async function archiveCategory(category: AdministrationCategory) {
    setBusyCategoryId(category.id)
    setError(null)
    setSuccess(null)
    try {
      await archiveAdministrationCategory(category.id)
      setConfirmArchive(null)
      setSuccess(`Kategoria „${category.name}” została zarchiwizowana. Powiązania surowców zachowano.`)
      resetCategoryHierarchy()
      reloadRootPage()
    } catch (cause) {
      setError(errorText(cause))
      if (!(cause instanceof ApiError) || cause.status >= 500) setNeedsRefresh(true)
    } finally {
      setBusyCategoryId(null)
    }
  }

  async function restoreCategory(category: AdministrationCategory) {
    setBusyCategoryId(category.id)
    setError(null)
    setSuccess(null)
    try {
      await restoreAdministrationCategory(category.id)
      setSuccess(`Kategoria „${category.name}” została przywrócona.`)
      resetCategoryHierarchy()
      reloadRootPage()
    } catch (cause) {
      setError(errorText(cause))
      if (!(cause instanceof ApiError) || cause.status >= 500) setNeedsRefresh(true)
    } finally {
      setBusyCategoryId(null)
    }
  }

  function categoryActions(category: AdministrationCategory) {
    const isBusy = busy || busyCategoryId === category.id || needsRefresh
    return (
      <>
        <div className="admin-category-actions">
          {category.archivedAt === null ? (
            <>
              <button type="button" className="button button-secondary" disabled={isBusy}
                onClick={() => openEdit(category)}>Edytuj</button>
              {category.depth < 3 && (
                <button type="button" className="button button-secondary" disabled={isBusy}
                  onClick={() => openCreate(category)}>Dodaj podkategorię</button>
              )}
              <button type="button" className="button button-secondary" disabled={isBusy}
                onClick={() => setConfirmArchive(category)}>Archiwizuj</button>
            </>
          ) : (
            <button type="button" className="button button-secondary" disabled={isBusy}
              onClick={() => void restoreCategory(category)}>Przywróć</button>
          )}
        </div>
        {confirmArchive?.id === category.id && (
          <div className="admin-category-confirm" role="group" aria-label={`Potwierdzenie archiwizacji: ${category.name}`}>
            <p>
              Archiwizacja nie usuwa kategorii ani powiązań surowców.
              {category.rawMaterialCount > 0 && ` Zachowa ${category.rawMaterialCount} powiązanych kartotek surowców.`}
              {' '}Nie można archiwizować kategorii z aktywnym potomkiem.
            </p>
            <button type="button" className="button button-primary" disabled={isBusy}
              onClick={() => void archiveCategory(category)}>Potwierdź archiwizację</button>
            <button type="button" className="button button-secondary" disabled={isBusy}
              onClick={() => setConfirmArchive(null)}>Anuluj</button>
          </div>
        )}
      </>
    )
  }

  function handleExpandedChange(groupId: string, ids: string[], categories: AdministrationCategory[], previous: string[]) {
    setExpanded((current) => ({ ...current, [groupId]: ids }))
    for (const id of ids.filter((candidate) => !previous.includes(candidate))) {
      const category = categories.find((candidate) => candidate.id === id)
      if (!category?.hasChildren) continue
      const childKey = `${id}:1`
      if (!children[childKey] && !childrenLoading[childKey]) void loadChildren(id)
    }
  }

  function handleCategoryPage(groupId: string, nextPage: number, isRoot: boolean) {
    if (isRoot) {
      setChildren({})
      setChildrenLoading({})
      setChildrenErrors({})
      setExpanded({})
      setLoading(true)
      setError(null)
      setPage(nextPage)
      return
    }
    void loadChildren(groupId, nextPage)
  }

  function handleSearchPage(nextPage: number) {
    setLoading(true)
    setError(null)
    setPage(nextPage)
  }

  const searchResults = search.trim() !== ''

  return (
    <section className="admin-category-page" aria-labelledby="admin-category-title">
      <header className="admin-category-header">
        <div>
          <h2 id="admin-category-title">Kategorie surowców</h2>
          <p>Wspólny słownik organizacji używany przez katalog RAW. Kody i hierarchia kategorii są niezmienne.</p>
        </div>
        <button type="button" className="button button-primary" disabled={busy || needsRefresh}
          onClick={() => openCreate(null)}>Dodaj kategorię główną</button>
      </header>

      {success && <p className="inline-success" role="status">{success}</p>}
      {error && <p className="inline-error" role="alert">{error}</p>}
      {needsRefresh && (
        <div className="admin-category-refresh" role="alert">
          <p>Nie można potwierdzić wyniku zapisu. Odśwież listę i sprawdź stan przed ponowną próbą.</p>
          <button type="button" className="button button-secondary" disabled={loading}
            onClick={() => {
              resetCategoryHierarchy()
              reloadRootPage()
            }}>Odśwież kategorię</button>
        </div>
      )}

      <form className="admin-category-filters" onSubmit={(event) => {
        event.preventDefault()
        resetCategoryHierarchy()
        setPage(1)
        setSearch(searchText.trim())
        reloadRootPage()
      }}>
        <label className="create-form-field">
          Szukaj po nazwie lub opisie
          <input type="search" value={searchText} maxLength={120}
            onChange={(event) => setSearchText(event.target.value)} />
        </label>
        <label className="create-form-field">
          Status kategorii
          <select value={status} onChange={(event) => {
            resetCategoryHierarchy()
            setStatus(event.target.value as CategoryStatus)
            setPage(1)
            reloadRootPage()
          }}>
            <option value="active">Aktywne</option>
            <option value="archived">Archiwalne</option>
            <option value="all">Wszystkie</option>
          </select>
        </label>
        <div className="admin-category-filter-actions">
          <button type="submit" className="button button-secondary">Szukaj</button>
          {search && <button type="button" className="button button-secondary" onClick={() => {
            resetCategoryHierarchy()
            setSearchText('')
            setSearch('')
            setPage(1)
            reloadRootPage()
          }}>Wyczyść wyszukiwanie</button>}
        </div>
      </form>

      {form && (
        <form className="state-panel admin-category-form" onSubmit={(event) => void submitForm(event)}>
          <h3>{form.mode === 'create' ? (form.parent ? 'Nowa podkategoria' : 'Nowa kategoria główna') : 'Edycja kategorii'}</h3>
          {formError && <p className="inline-error" role="alert">{formError}</p>}
          <div className="create-form-grid">
            {form.mode === 'create' ? (
              <label className="create-form-field">Kod kategorii
                <input value={form.code} required maxLength={40} pattern="[A-Z0-9_-]+"
                  onChange={(event) => setForm({ ...form, code: event.target.value.toUpperCase() })} />
                <span className="field-hint">Unikalny w organizacji; wielkie litery, cyfry, _ lub -.</span>
              </label>
            ) : (
              <label className="create-form-field">Kod kategorii
                <input value={form.code} readOnly aria-readonly="true" />
                <span className="field-hint">Kod jest niezmienny.</span>
              </label>
            )}
            <label className="create-form-field">Nazwa
              <input value={form.name} required maxLength={160}
                onChange={(event) => setForm({ ...form, name: event.target.value })} />
            </label>
            <label className="create-form-field admin-category-form-description">Opis (opcjonalnie)
              <textarea value={form.description} maxLength={4000} rows={3}
                onChange={(event) => setForm({ ...form, description: event.target.value })} />
            </label>
            <div className="create-form-field">
              <span>Kategoria nadrzędna</span>
              <p>{form.mode === 'create' ? (form.parent?.name ?? 'Brak — kategoria główna') : (form.category?.parentName ?? 'Brak — kategoria główna')}</p>
              <span className="field-hint">Hierarchii nie można zmienić; zabezpiecza ją ograniczenie bazy danych.</span>
            </div>
          </div>
          <div className="admin-category-actions">
            <button type="submit" className="button button-primary" disabled={busy || needsRefresh}>
              {busy ? 'Zapisywanie…' : 'Zapisz kategorię'}
            </button>
            <button type="button" className="button button-secondary" disabled={busy}
              onClick={() => { setForm(null); setFormError(null) }}>Anuluj</button>
          </div>
        </form>
      )}

      <div className="admin-category-results" aria-busy={loading}>
        {loading && <p role="status">Ładowanie kategorii…</p>}
        {!loading && error && <p>Nie można wyświetlić kategorii.</p>}
        {!loading && !error && !rootPage?.data.length && (
          <p className="admin-category-empty">{searchResults ? 'Brak kategorii pasujących do wyszukiwania.' : 'Brak kategorii w tym widoku.'}</p>
        )}
        {!loading && !error && rootPage?.data.length && (
          searchResults ? (
            <div className="admin-category-search-results" aria-label="Wyniki wyszukiwania kategorii">
              {rootPage.data.map((category) => (
                <article key={category.id} className="admin-category-search-result">
                  <div>
                    <h3>{category.name} <span>{category.code}</span></h3>
                    <p className="admin-category-path">{category.path.join(' › ')}</p>
                    {category.description && <p>{category.description}</p>}
                    <p>{category.archivedAt ? 'Archiwalna' : 'Aktywna'} · Powiązane kartoteki RAW: {category.rawMaterialCount}</p>
                  </div>
                  {categoryActions(category)}
                </article>
              ))}
              {rootPage.meta.lastPage > 1 && (
                <div className="admin-category-pagination" aria-label="Stronicowanie kategorii">
                  <button type="button" className="button button-secondary" disabled={rootPage.meta.currentPage <= 1 || loading || busy}
                    onClick={() => handleSearchPage(rootPage.meta.currentPage - 1)}>Poprzednia</button>
                  <span aria-live="polite">{pageLabel(rootPage.meta)}</span>
                  <button type="button" className="button button-secondary" disabled={rootPage.meta.currentPage >= rootPage.meta.lastPage || loading || busy}
                    onClick={() => handleSearchPage(rootPage.meta.currentPage + 1)}>Następna</button>
                </div>
              )}
            </div>
          ) : (
            <CategoryTreeList
              categories={rootPage.data}
              groupId="root"
              currentPage={rootPage.meta}
              expandedByGroup={expanded}
              childrenPages={children}
              childrenLoading={childrenLoading}
              childrenErrors={childrenErrors}
              renderActions={categoryActions}
              onExpandedChange={handleExpandedChange}
              onPage={handleCategoryPage}
              isRoot
            />
          )
        )}
      </div>
    </section>
  )
}
