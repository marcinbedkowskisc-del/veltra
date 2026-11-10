import { useEffect, useMemo, useRef, useState } from 'react'
import { ApiError } from '../api'
import CatalogFilters, { type SupplierFilterState } from './CatalogFilters'
import CatalogTable from './CatalogTable'
import SelectionActionBar from './SelectionActionBar'
import { downloadCatalogExport, exportCatalog, fetchCatalog, fetchCategories, fetchSuppliers } from './api'
import {
  PAGE_SIZES,
  catalogExportPayload,
  supplierLabel,
  type CatalogFilters as FilterValues,
  type CatalogMeta,
  type CatalogQuery,
  type CatalogRow,
  type CategoryOption,
  type SortDirection,
  type SortKey,
  type SupplierOption,
} from './catalog'

type Props = {
  onSessionExpired: () => void
}

type CatalogState = 'loading' | 'ready' | 'forbidden' | 'unavailable' | 'error'

type PreviousPage = {
  page: number
  ids: Set<string>
}

function isSessionExpired(cause: unknown): boolean {
  return cause instanceof ApiError && (cause.status === 401 || cause.status === 419)
}

const EMPTY_FILTERS: FilterValues = {
  search: '',
  categoryId: '',
  supplierId: '',
  lifecycleState: '',
  unit: '',
}

const EMPTY_META: CatalogMeta = {
  currentPage: 1,
  perPage: 25,
  total: 0,
  lastPage: 1,
  from: null,
  to: null,
}

export default function RawMaterialsCatalogPage({ onSessionExpired }: Props) {
  const [filters, setFilters] = useState<FilterValues>(EMPTY_FILTERS)
  const [searchText, setSearchText] = useState('')
  const [sort, setSort] = useState<SortKey>('name')
  const [direction, setDirection] = useState<SortDirection>('asc')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState<(typeof PAGE_SIZES)[number]>(25)
  const [rows, setRows] = useState<CatalogRow[]>([])
  const [meta, setMeta] = useState(EMPTY_META)
  const [canReadReferencePrice, setCanReadReferencePrice] = useState<boolean | null>(null)
  const [canReadUsableCost, setCanReadUsableCost] = useState<boolean | null>(null)
  const [catalogState, setCatalogState] = useState<CatalogState>('loading')
  const [loadedQueryKey, setLoadedQueryKey] = useState<string | null>(null)
  const [catalogError, setCatalogError] = useState<string | null>(null)
  const [retryCount, setRetryCount] = useState(0)
  const [categories, setCategories] = useState<CategoryOption[]>([])
  const [categoriesAvailable, setCategoriesAvailable] = useState(false)
  const [supplierState, setSupplierState] = useState<SupplierFilterState>('checking')
  const [supplierInput, setSupplierInput] = useState('')
  const [supplierSuggestions, setSupplierSuggestions] = useState<SupplierOption[]>([])
  const [supplierSearching, setSupplierSearching] = useState(false)
  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => new Set())
  const [exporting, setExporting] = useState(false)
  const [exportMessage, setExportMessage] = useState<string | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)
  const exportKey = useRef<{ fingerprint: string; key: string } | null>(null)
  const previousPage = useRef<PreviousPage | null>(null)
  const searchPending = searchText !== filters.search

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setFilters((current) => current.search === searchText ? current : { ...current, search: searchText })
    }, 300)
    return () => window.clearTimeout(timer)
  }, [searchText])

  useEffect(() => {
    if (catalogState !== 'ready') return
    const controller = new AbortController()
    void fetchCategories(controller.signal).then((result) => {
      setCategories(result)
      setCategoriesAvailable(true)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      if (isSessionExpired(cause)) onSessionExpired()
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        setRows([])
        setMeta(EMPTY_META)
        setSelectedIds(new Set())
        setCanReadReferencePrice(null)
        setCanReadUsableCost(null)
        setCatalogState('unavailable')
        setCatalogError('Moduł RAW_MATERIALS jest niedostępny w tej lokalizacji.')
      }
      setCategories([])
      setCategoriesAvailable(false)
    })
    return () => controller.abort()
  }, [catalogState, onSessionExpired])

  useEffect(() => {
    if (catalogState !== 'ready') return
    const controller = new AbortController()
    void fetchSuppliers('', controller.signal).then((result) => {
      setSupplierSuggestions(result)
      setSupplierState('available')
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      if (isSessionExpired(cause)) {
        onSessionExpired()
        return
      }
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        setRows([])
        setMeta(EMPTY_META)
        setSelectedIds(new Set())
        setCanReadReferencePrice(null)
        setCanReadUsableCost(null)
        exportKey.current = null
        setCatalogState('unavailable')
        setCatalogError('Moduł RAW_MATERIALS jest niedostępny w tej lokalizacji.')
      }
      setSupplierState(cause instanceof ApiError && cause.status === 403 ? 'denied' : 'unavailable')
      setSupplierSuggestions([])
    })
    return () => controller.abort()
  }, [catalogState, onSessionExpired])

  useEffect(() => {
    if (supplierState !== 'available' || !supplierInput.trim() || filters.supplierId) return
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      void fetchSuppliers(supplierInput, controller.signal).then((result) => {
        setSupplierSuggestions(result)
        setSupplierSearching(false)
      }).catch((cause: unknown) => {
        if (controller.signal.aborted) return
        if (isSessionExpired(cause)) {
          onSessionExpired()
          return
        }
        if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
          setRows([])
          setMeta(EMPTY_META)
          setSelectedIds(new Set())
          setCanReadReferencePrice(null)
          setCanReadUsableCost(null)
          exportKey.current = null
          setCatalogState('unavailable')
          setCatalogError('Moduł RAW_MATERIALS jest niedostępny w tej lokalizacji.')
          return
        }
        setSupplierSuggestions([])
        setSupplierSearching(false)
        if (cause instanceof ApiError && cause.status === 403) {
          setSupplierState('denied')
          setSupplierInput('')
          setFilters((current) => ({ ...current, supplierId: '' }))
        } else {
          setSupplierState('unavailable')
        }
      })
    }, 300)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [filters.supplierId, onSessionExpired, supplierInput, supplierState])

  const query = useMemo<CatalogQuery>(() => ({
    ...filters,
    sort,
    direction,
    page,
    perPage,
  }), [direction, filters, page, perPage, sort])
  const queryKey = JSON.stringify(query)

  useEffect(() => {
    const controller = new AbortController()

    void fetchCatalog(query, controller.signal).then((result) => {
      if (previousPage.current?.page === query.page) {
        const currentIds = new Set(result.rows.map((row) => row.id))
        const removedIds = [...previousPage.current.ids].filter((id) => !currentIds.has(id))
        if (removedIds.length > 0) {
          setSelectedIds((selected) => {
            const next = new Set(selected)
            removedIds.forEach((id) => next.delete(id))
            return next
          })
        }
      }
      previousPage.current = { page: query.page, ids: new Set(result.rows.map((row) => row.id)) }
      setRows(result.rows)
      setMeta(result.meta)
      setCanReadReferencePrice(result.canReadReferencePrice)
      setCanReadUsableCost(result.canReadUsableCost)
      setLoadedQueryKey(queryKey)
      setCatalogState('ready')
      setCatalogError(null)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      setSelectedIds(new Set())
      exportKey.current = null

      if (isSessionExpired(cause)) {
        setRows([])
        setMeta(EMPTY_META)
        setCatalogState('forbidden')
        onSessionExpired()
        return
      }
      if (cause instanceof ApiError && cause.status === 403 && query.supplierId) {
        setRows([])
        setMeta(EMPTY_META)
        setSupplierState('denied')
        setSupplierInput('')
        setFilters((current) => ({ ...current, supplierId: '' }))
        setPage(1)
        return
      }
      if (cause instanceof ApiError && cause.status === 403
        && (query.sort === 'reference_net_price' || query.sort === 'usable_unit_cost')) {
        setRows([])
        setMeta(EMPTY_META)
        if (query.sort === 'reference_net_price') setCanReadReferencePrice(false)
        if (query.sort === 'usable_unit_cost') setCanReadUsableCost(false)
        setSort('name')
        setDirection('asc')
        setPage(1)
        return
      }
      if (cause instanceof ApiError && cause.status === 403) {
        setRows([])
        setMeta(EMPTY_META)
        setCanReadReferencePrice(false)
        setCanReadUsableCost(false)
        setCatalogState('forbidden')
        setCatalogError('Brak uprawnień do katalogu RAW lub bieżącego kontekstu lokalizacji.')
        return
      }
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        setRows([])
        setMeta(EMPTY_META)
        setCanReadReferencePrice(null)
        setCanReadUsableCost(null)
        setCatalogState('unavailable')
        setCatalogError('Moduł RAW_MATERIALS jest niedostępny w tej lokalizacji.')
        return
      }

      setCatalogError(cause instanceof Error ? cause.message : 'Nie udało się pobrać katalogu RAW.')
      setCatalogState('error')
    })
    return () => controller.abort()
  }, [onSessionExpired, query, queryKey, retryCount])

  function clearSelection() {
    exportKey.current = null
    setSelectedIds(new Set())
    setExportMessage(null)
    setExportError(null)
  }

  function updateFilter(field: keyof FilterValues, value: string) {
    if (field === 'search') {
      if (value !== searchText) {
        clearSelection()
        setPage(1)
        setSearchText(value)
      }
      return
    }
    if (filters[field] === value) return
    clearSelection()
    setPage(1)
    setFilters((current) => ({ ...current, [field]: value }))
  }

  function setSupplierSearch(value: string) {
    setSupplierInput(value)
    setSupplierSearching(value.trim().length > 0)
    if (filters.supplierId) updateFilter('supplierId', '')
  }

  function selectSupplier(supplier: SupplierOption) {
    setSupplierInput(supplierLabel(supplier))
    setSupplierSearching(false)
    updateFilter('supplierId', supplier.id)
  }

  function sortBy(key: SortKey) {
    if (key === 'reference_net_price' && canReadReferencePrice !== true) return
    if (key === 'usable_unit_cost' && canReadUsableCost !== true) return
    const nextDirection = sort === key ? (direction === 'asc' ? 'desc' : 'asc') : 'asc'
    setSort(key)
    setDirection(nextDirection)
    setPage(1)
  }

  function toggleRow(id: string) {
    exportKey.current = null
    setSelectedIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
    setExportMessage(null)
    setExportError(null)
  }

  function toggleCurrentPage() {
    exportKey.current = null
    setSelectedIds((current) => {
      const next = new Set(current)
      const allSelected = rows.length > 0 && rows.every((row) => next.has(row.id))
      rows.forEach((row) => {
        if (allSelected) next.delete(row.id)
        else next.add(row.id)
      })
      return next
    })
    setExportMessage(null)
    setExportError(null)
  }

  function beginDownload(blob: Blob, filename: string) {
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.hidden = true
    document.body.append(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  }

  async function runExport(materialIds?: string[]) {
    if (searchPending || refreshing || catalogState !== 'ready') return
    const payload = catalogExportPayload(query, materialIds)
    const fingerprint = JSON.stringify(payload)
    const key = exportKey.current?.fingerprint === fingerprint
      ? exportKey.current.key
      : crypto.randomUUID()
    exportKey.current = { fingerprint, key }
    setExporting(true)
    setExportMessage(null)
    setExportError(null)

    try {
      const exportResult = await exportCatalog(payload, key)
      const file = await downloadCatalogExport(exportResult.downloadUrl)
      beginDownload(file.blob, file.filename)
      setExportMessage('Plik CSV został przygotowany i pobrany.')
    } catch (cause) {
      if (isSessionExpired(cause)) {
        onSessionExpired()
        return
      }
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        setRows([])
        setMeta(EMPTY_META)
        setCanReadReferencePrice(null)
        setCanReadUsableCost(null)
        setCatalogState('unavailable')
        setCatalogError('Moduł RAW_MATERIALS jest niedostępny w tej lokalizacji.')
        return
      }
      if (cause instanceof ApiError && cause.status === 403) {
        setSelectedIds(new Set())
        exportKey.current = null
        if (filters.supplierId) {
          setSupplierState('denied')
          setSupplierInput('')
          setFilters((current) => ({ ...current, supplierId: '' }))
          setPage(1)
        } else if (sort === 'reference_net_price' || sort === 'usable_unit_cost') {
          setRows([])
          setMeta(EMPTY_META)
          if (sort === 'reference_net_price') setCanReadReferencePrice(false)
          if (sort === 'usable_unit_cost') setCanReadUsableCost(false)
          setSort('name')
          setDirection('asc')
          setPage(1)
        } else {
          setRows([])
          setMeta(EMPTY_META)
          setCanReadReferencePrice(false)
          setCanReadUsableCost(false)
          setCatalogState('forbidden')
        }
      }
      if (cause instanceof ApiError && cause.status === 404) {
        setSelectedIds(new Set())
        exportKey.current = null
      }
      setExportError(cause instanceof Error ? cause.message : 'Nie udało się przygotować eksportu CSV.')
    } finally {
      setExporting(false)
    }
  }

  const hasFilters = Object.values(filters).some(Boolean)
  const refreshing = catalogState === 'ready' && loadedQueryKey !== queryKey
  const tableLoading = catalogState === 'loading' || refreshing || searchPending
  const emptyMessage = searchPending
    ? 'Aktualizowanie wyników…'
    : hasFilters
      ? 'Brak surowców spełniających wybrane kryteria.'
      : 'Katalog nie zawiera jeszcze surowców.'
  const filterControlsDisabled = catalogState === 'forbidden' || catalogState === 'unavailable'
  const exportDisabled = filterControlsDisabled || catalogState !== 'ready'
    || refreshing || searchPending || meta.total === 0 || exporting

  return (
    <section className="catalog-page" aria-labelledby="catalog-title" aria-busy={tableLoading}>
      <header className="catalog-heading">
        <div>
          <p className="eyebrow">BAZA OPERACYJNA</p>
          <h1 id="catalog-title">Katalog surowców</h1>
          <p className="catalog-subtitle">Zatwierdzone surowce dostępne w bieżącym kontekście.</p>
        </div>
        <div className="deferred-action">
          <button type="button" className="button button-primary" disabled>
            <span aria-hidden="true">+</span> Dodaj surowiec
          </button>
          <small>Edytor surowca jest planowany w etapie 4B.</small>
        </div>
      </header>

      <CatalogFilters
        values={filters}
        searchInput={searchText}
        categories={categories}
        categoriesAvailable={categoriesAvailable}
        supplierState={supplierState}
        supplierInput={supplierInput}
        supplierSuggestions={supplierSuggestions}
        supplierSearching={supplierSearching}
        disabled={filterControlsDisabled}
        onChange={updateFilter}
        onSupplierInput={setSupplierSearch}
        onSupplierSelect={selectSupplier}
        onSupplierClear={() => {
          setSupplierInput('')
          setSupplierSearching(false)
          updateFilter('supplierId', '')
        }}
        onExport={() => void runExport()}
        exportDisabled={exportDisabled}
      />

      {catalogState === 'forbidden' && (
        <div className="state-panel state-error" role="alert">
          <h2>Brak dostępu</h2>
          <p>{catalogError}</p>
        </div>
      )}
      {catalogState === 'unavailable' && (
        <div className="state-panel state-unavailable" role="status">
          <h2>Moduł niedostępny</h2>
          <p>{catalogError}</p>
        </div>
      )}
      {catalogState === 'error' && (
        <div className="state-panel state-error" role="alert">
          <h2>Nie udało się wczytać katalogu</h2>
          <p>{catalogError}</p>
          <button type="button" className="button button-secondary" onClick={() => {
            setCatalogState('loading')
            setRetryCount((count) => count + 1)
          }}>
            Spróbuj ponownie
          </button>
        </div>
      )}
      {catalogError && catalogState === 'ready' && (
        <p className="inline-error" role="alert">
          Nie udało się odświeżyć katalogu. {catalogError}
        </p>
      )}
      {searchPending && catalogState === 'ready' && (
        <p className="refresh-status" role="status">Aktualizowanie wyników wyszukiwania…</p>
      )}

      {exportError && <p className="inline-error" role="alert">{exportError}</p>}
      {exportMessage && <p className="inline-success" role="status">{exportMessage}</p>}
      {refreshing && catalogState === 'ready' && <p className="refresh-status" role="status">Odświeżanie katalogu…</p>}

      {catalogState !== 'forbidden' && catalogState !== 'unavailable' && catalogState !== 'error' && (
        <>
          <div className="catalog-summary" aria-live="polite">
            <span>
              {meta.total === 0
                ? '0 surowców'
                : `Pozycje ${meta.from ?? 0}–${meta.to ?? 0} z ${meta.total}`}
            </span>
            <label>
              <span>Wierszy na stronę</span>
              <select
                value={perPage}
                onChange={(event) => {
                  setPerPage(Number(event.target.value) as (typeof PAGE_SIZES)[number])
                  setPage(1)
                }}
                aria-label="Liczba wierszy na stronę"
              >
                {PAGE_SIZES.map((size) => <option key={size} value={size}>{size}</option>)}
              </select>
            </label>
          </div>

          <CatalogTable
            rows={rows}
            selectedIds={selectedIds}
            selectionDisabled={tableLoading || catalogState !== 'ready'}
            sort={sort}
            direction={direction}
            canReadReferencePrice={canReadReferencePrice}
            canReadUsableCost={canReadUsableCost}
            emptyMessage={emptyMessage}
            loading={tableLoading}
            onSort={sortBy}
            onToggle={toggleRow}
            onTogglePage={toggleCurrentPage}
          />

          <nav className="pagination" aria-label="Stronicowanie katalogu">
            <span>Strona {meta.currentPage} z {meta.lastPage}</span>
            <div>
              <button
                type="button"
                className="button button-secondary"
                onClick={() => setPage((current) => Math.max(1, current - 1))}
                disabled={tableLoading || page <= 1}
              >
                Poprzednia
              </button>
              <button
                type="button"
                className="button button-secondary"
                onClick={() => setPage((current) => Math.min(meta.lastPage, current + 1))}
                disabled={tableLoading || page >= meta.lastPage}
              >
                Następna
              </button>
            </div>
          </nav>
        </>
      )}

      <SelectionActionBar
        count={selectedIds.size}
        exporting={exporting}
        disabled={refreshing || searchPending || catalogState !== 'ready'}
        onExport={() => void runExport([...selectedIds])}
        onClear={clearSelection}
      />
    </section>
  )
}
