import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, useTransition } from 'react'
import { ApiError } from '../api'
import CatalogFilters, { type SupplierFilterState } from './CatalogFilters'
import CatalogTable from './CatalogTable'
import Pagination from '../design-system/Pagination'
import RawMaterialCreateForm from './RawMaterialCreateForm'
import RawMaterialDetailDrawer from './RawMaterialDetailDrawer'
import SelectionActionBar from './SelectionActionBar'
import {
  downloadCatalogExport,
  exportCatalog,
  fetchCatalog,
  fetchCategories,
  fetchRawMaterialCapabilities,
  fetchSuppliers,
  type CreatedRawMaterial,
  type RawMaterialCapabilities,
} from './api'
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
  previewDetailId?: string
  operatingLocationId?: string
  syntheticPreview?: boolean
}

type CatalogState = 'loading' | 'ready' | 'forbidden' | 'unavailable' | 'error'

type PreviousPage = {
  page: number
  ids: Set<string>
}

type SelectedDetail = {
  id: string
  trigger: HTMLButtonElement | null
  locationId: string | null
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

export default function RawMaterialsCatalogPage({
  onSessionExpired,
  previewDetailId,
  operatingLocationId,
  syntheticPreview = false,
}: Props) {
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
  const [settledCatalogRequestKey, setSettledCatalogRequestKey] = useState<string | null>(null)
  const [catalogError, setCatalogError] = useState<string | null>(null)
  const [catalogRequestRevision, setCatalogRequestRevision] = useState(0)
  const [categories, setCategories] = useState<CategoryOption[]>([])
  const [categoriesAvailable, setCategoriesAvailable] = useState(false)
  const [supplierState, setSupplierState] = useState<SupplierFilterState>('checking')
  const [supplierInput, setSupplierInput] = useState('')
  const [supplierSuggestions, setSupplierSuggestions] = useState<SupplierOption[]>([])
  const [supplierSearching, setSupplierSearching] = useState(false)
  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => new Set())
  const [selectedDetail, setSelectedDetail] = useState<SelectedDetail | null>(() => previewDetailId
    ? { id: previewDetailId, trigger: null, locationId: operatingLocationId ?? null }
    : null)
  const [detailRefreshKey, setDetailRefreshKey] = useState(0)
  const [exporting, setExporting] = useState(false)
  const [exportMessage, setExportMessage] = useState<string | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)
  const [capabilities, setCapabilities] = useState<RawMaterialCapabilities | null>(null)
  const [capabilitiesLoading, setCapabilitiesLoading] = useState(false)
  const [capabilitiesError, setCapabilitiesError] = useState<string | null>(null)
  const [createFormLocationId, setCreateFormLocationId] = useState<string | null>(null)
  const [createOpeningLocationId, setCreateOpeningLocationId] = useState<string | null>(null)
  const [createReturnFocus, setCreateReturnFocus] = useState<HTMLButtonElement | null>(null)
  const [createdMaterial, setCreatedMaterial] = useState<CreatedRawMaterial | null>(null)
  const [createdMaterialLocationId, setCreatedMaterialLocationId] = useState<string | null>(null)
  const [resultsPending, startResultsTransition] = useTransition()
  const exportKey = useRef<{ fingerprint: string; key: string } | null>(null)
  const previousPage = useRef<PreviousPage | null>(null)
  const moduleUnavailable = useRef(false)
  const capabilitiesGeneration = useRef(0)
  const capabilitiesController = useRef<AbortController | null>(null)
  const operatingLocationRef = useRef(operatingLocationId)
  const previousOperatingLocation = useRef(operatingLocationId)
  const locationGeneration = useRef(0)
  const catalogRequestGeneration = useRef(0)
  useLayoutEffect(() => {
    operatingLocationRef.current = operatingLocationId
    if (previousOperatingLocation.current !== operatingLocationId) {
      previousOperatingLocation.current = operatingLocationId
      locationGeneration.current += 1
      setSelectedDetail(null)
      setDetailRefreshKey(0)
    }
  }, [operatingLocationId])
  const currentSelectedDetail = selectedDetail?.locationId === (operatingLocationId ?? null) ? selectedDetail : null
  const globalEditAllowed = Boolean(capabilities
    && capabilities.locationId === operatingLocationId
    && capabilities.readiness === 'READY'
    && capabilities.available
    && capabilities.actions.edit)
  const createOpening = createOpeningLocationId === operatingLocationId && createOpeningLocationId !== null
  const currentCreatedMaterial = createdMaterialLocationId === operatingLocationId ? createdMaterial : null
  const searchPending = searchText !== filters.search

  const refreshCapabilities = useCallback(async (): Promise<RawMaterialCapabilities | null> => {
    if (!operatingLocationId) return null

    const generation = ++capabilitiesGeneration.current
    capabilitiesController.current?.abort()
    const controller = new AbortController()
    capabilitiesController.current = controller
    setCapabilities(null)
    setCapabilitiesLoading(true)
    setCapabilitiesError(null)

    try {
      const result = await fetchRawMaterialCapabilities(operatingLocationId, controller.signal)
      if (controller.signal.aborted || generation !== capabilitiesGeneration.current) return null
      setCapabilities(result)
      return result
    } catch (cause) {
      if (controller.signal.aborted || generation !== capabilitiesGeneration.current) return null
      setCapabilities(null)
      setCapabilitiesError('Nie można potwierdzić uprawnień do tworzenia. Akcja pozostaje ukryta.')
      if (isSessionExpired(cause)) onSessionExpired()
      return null
    } finally {
      if (!controller.signal.aborted && generation === capabilitiesGeneration.current) {
        setCapabilitiesLoading(false)
      }
    }
  }, [onSessionExpired, operatingLocationId])

  useEffect(() => {
    return () => {
      setCreateFormLocationId(null)
      setCreateOpeningLocationId(null)
      setCreatedMaterial(null)
      setCreatedMaterialLocationId(null)
    }
  }, [operatingLocationId])

  useEffect(() => {
    let active = true
    void Promise.resolve().then(() => {
      if (active) void refreshCapabilities()
    })
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') void refreshCapabilities()
    }
    document.addEventListener('visibilitychange', handleVisibilityChange)

    return () => {
      active = false
      capabilitiesGeneration.current += 1
      capabilitiesController.current?.abort()
      capabilitiesController.current = null
      document.removeEventListener('visibilitychange', handleVisibilityChange)
    }
  }, [refreshCapabilities])

  const handleDetailSessionExpired = useCallback(() => {
    setSelectedDetail(null)
    setRows([])
    setMeta(EMPTY_META)
    setCanReadReferencePrice(null)
    setCanReadUsableCost(null)
    setSelectedIds(new Set())
    setCatalogState('forbidden')
    setCatalogError('Sesja wygasła. Zaloguj się ponownie.')
    onSessionExpired()
  }, [onSessionExpired])

  const markModuleUnavailable = useCallback(() => {
    setSelectedDetail(null)
    setCreateFormLocationId(null)
    setCapabilities(null)
    setCapabilitiesLoading(false)
    moduleUnavailable.current = true
    setRows([])
    setMeta(EMPTY_META)
    setCategories([])
    setCategoriesAvailable(false)
    setSupplierState('module-unavailable')
    setSupplierInput('')
    setSupplierSuggestions([])
    setSupplierSearching(false)
    setFilters(EMPTY_FILTERS)
    setSearchText('')
    setSelectedIds(new Set())
    setCanReadReferencePrice(null)
    setCanReadUsableCost(null)
    setCatalogState('unavailable')
    setCatalogError('Moduł RAW_MATERIALS jest niedostępny w tej lokalizacji.')
    setExportMessage(null)
    setExportError(null)
    exportKey.current = null
    previousPage.current = null
  }, [])

  async function openCreateForm(trigger: HTMLButtonElement) {
    const expectedLocationId = operatingLocationId
    const expectedGeneration = locationGeneration.current
    if (!expectedLocationId || createOpening) return
    setCreateOpeningLocationId(expectedLocationId)
    setCreateReturnFocus(trigger)
    setCapabilitiesError(null)
    try {
      const current = await refreshCapabilities()
      if (operatingLocationRef.current !== expectedLocationId
        || locationGeneration.current !== expectedGeneration) return
      if (!current) return
      if (current.locationId !== expectedLocationId || !current.actions.create) {
        setCapabilitiesError('Brak aktualnego uprawnienia do tworzenia w tej lokalizacji. Akcja pozostaje niedostępna.')
        return
      }

      const currentCategories = await fetchCategories()
      if (operatingLocationRef.current !== expectedLocationId
        || locationGeneration.current !== expectedGeneration) return
      setCategories(currentCategories)
      setCategoriesAvailable(true)
      setCreateFormLocationId(expectedLocationId)
    } catch (cause) {
      if (operatingLocationRef.current !== expectedLocationId
        || locationGeneration.current !== expectedGeneration) return
      if (isSessionExpired(cause)) {
        onSessionExpired()
      } else if (cause instanceof ApiError && (cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE' || cause.status === 503)) {
        markModuleUnavailable()
      } else if (cause instanceof ApiError && cause.status === 403) {
        setCapabilities(null)
        setCapabilitiesError('Nie można odczytać kategorii w bieżącym kontekście. Formularz pozostaje zamknięty.')
      } else {
        setCapabilitiesError(cause instanceof Error
          ? `Nie można przygotować listy kategorii: ${cause.message}`
          : 'Nie można przygotować listy kategorii.')
      }
    } finally {
      if (operatingLocationRef.current === expectedLocationId
        && locationGeneration.current === expectedGeneration) {
        setCreateOpeningLocationId(null)
      }
    }
  }

  function handleCreated(material: CreatedRawMaterial) {
    setCreatedMaterial(material)
    setCreatedMaterialLocationId(operatingLocationId ?? null)
    setSelectedIds(new Set())
    exportKey.current = null
    setCatalogRequestRevision((revision) => revision + 1)
  }

  function handleEdited() {
    setCatalogRequestRevision((revision) => revision + 1)
    setDetailRefreshKey((count) => count + 1)
  }

  function handleCreateSessionExpired() {
    setRows([])
    setMeta(EMPTY_META)
    setCategories([])
    setCategoriesAvailable(false)
    setSelectedIds(new Set())
    setCanReadReferencePrice(null)
    setCanReadUsableCost(null)
    setCatalogState('forbidden')
    setCatalogError('Sesja wygasła. Zaloguj się ponownie.')
    onSessionExpired()
  }

  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (filters.search === searchText) return
      setFilters((current) => current.search === searchText ? current : { ...current, search: searchText })
      setPage(1)
      setCatalogRequestRevision((revision) => revision + 1)
    }, 300)
    return () => window.clearTimeout(timer)
  }, [filters.search, searchText])

  useEffect(() => {
    if (catalogState !== 'ready') return
    const controller = new AbortController()
    void fetchCategories(controller.signal).then((result) => {
      if (controller.signal.aborted || moduleUnavailable.current) return
      setCategories(result)
      setCategoriesAvailable(true)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted || moduleUnavailable.current) return
      if (isSessionExpired(cause)) onSessionExpired()
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        markModuleUnavailable()
        return
      }
      setCategories([])
      setCategoriesAvailable(false)
    })
    return () => controller.abort()
  }, [catalogState, markModuleUnavailable, onSessionExpired])

  useEffect(() => {
    if (catalogState !== 'ready') return
    const controller = new AbortController()
    void fetchSuppliers('', controller.signal).then((result) => {
      if (controller.signal.aborted || moduleUnavailable.current) return
      setSupplierSuggestions(result)
      setSupplierState('available')
    }).catch((cause: unknown) => {
      if (controller.signal.aborted || moduleUnavailable.current) return
      if (isSessionExpired(cause)) {
        onSessionExpired()
        return
      }
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        markModuleUnavailable()
        return
      }
      setSupplierState(cause instanceof ApiError && cause.status === 403 ? 'denied' : 'unavailable')
      setSupplierSuggestions([])
    })
    return () => controller.abort()
  }, [catalogState, markModuleUnavailable, onSessionExpired])

  useEffect(() => {
    if (supplierState !== 'available' || !supplierInput.trim() || filters.supplierId) return
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      void fetchSuppliers(supplierInput, controller.signal).then((result) => {
        if (controller.signal.aborted || moduleUnavailable.current) return
        setSupplierSuggestions(result)
        setSupplierSearching(false)
      }).catch((cause: unknown) => {
        if (controller.signal.aborted || moduleUnavailable.current) return
        if (isSessionExpired(cause)) {
          onSessionExpired()
          return
        }
        if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
          markModuleUnavailable()
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
  }, [filters.supplierId, markModuleUnavailable, onSessionExpired, supplierInput, supplierState])

  const query = useMemo<CatalogQuery>(() => ({
    ...filters,
    sort,
    direction,
    page,
    perPage,
  }), [direction, filters, page, perPage, sort])
  const queryKey = JSON.stringify(query)
  const catalogRequestKey = JSON.stringify([queryKey, catalogRequestRevision])

  useEffect(() => {
    if (searchPending) return
    const controller = new AbortController()
    const requestGeneration = ++catalogRequestGeneration.current

    void fetchCatalog(query, controller.signal).then((result) => {
      if (controller.signal.aborted || requestGeneration !== catalogRequestGeneration.current || moduleUnavailable.current) return
      startResultsTransition(() => {
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
        setCatalogState('ready')
        setCatalogError(null)
      })
    }).catch((cause: unknown) => {
      if (controller.signal.aborted || requestGeneration !== catalogRequestGeneration.current || moduleUnavailable.current) return
      setSelectedIds(new Set())
      exportKey.current = null

      if (isSessionExpired(cause)) {
        setSelectedDetail(null)
        setRows([])
        setMeta(EMPTY_META)
        setCatalogState('forbidden')
        onSessionExpired()
        return
      }
      if (cause instanceof ApiError && cause.status === 403 && query.supplierId) {
        setSelectedDetail(null)
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
        setSelectedDetail(null)
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
        setSelectedDetail(null)
        setRows([])
        setMeta(EMPTY_META)
        setCanReadReferencePrice(false)
        setCanReadUsableCost(false)
        setCatalogState('forbidden')
        setCatalogError('Brak uprawnień do katalogu RAW lub bieżącego kontekstu lokalizacji.')
        return
      }
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        markModuleUnavailable()
        return
      }

      setCatalogError(cause instanceof Error ? cause.message : 'Nie udało się pobrać katalogu RAW.')
      setCatalogState((current) => current === 'ready' ? 'ready' : 'error')
    }).finally(() => {
      if (!controller.signal.aborted && requestGeneration === catalogRequestGeneration.current) {
        setSettledCatalogRequestKey(catalogRequestKey)
      }
    })
    return () => controller.abort()
  }, [catalogRequestKey, markModuleUnavailable, onSessionExpired, query, searchPending])

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
        setSearchText(value)
      }
      return
    }
    if (filters[field] === value) return
    clearSelection()
    setPage(1)
    setFilters((current) => ({ ...current, search: searchText, [field]: value }))
    setCatalogRequestRevision((revision) => revision + 1)
  }

  function resetFilters() {
    const hasCatalogCriteria = Object.values(filters).some(Boolean) || page !== 1
    const hasActiveCriteria = Object.values(filters).some(Boolean)
      || searchText !== ''
      || supplierInput !== ''
      || page !== 1
    if (!hasActiveCriteria) return

    clearSelection()
    setFilters(EMPTY_FILTERS)
    setSearchText('')
    setSupplierInput('')
    setSupplierSearching(false)
    setSupplierSuggestions([])
    setPage(1)
    if (hasCatalogCriteria) setCatalogRequestRevision((revision) => revision + 1)
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
    setCatalogRequestRevision((revision) => revision + 1)
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
      if (moduleUnavailable.current) return
      const file = await downloadCatalogExport(exportResult.downloadUrl)
      if (moduleUnavailable.current) return
      beginDownload(file.blob, file.filename)
      setExportMessage('Plik CSV został przygotowany i pobrany.')
    } catch (cause) {
      if (isSessionExpired(cause)) {
        onSessionExpired()
        return
      }
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        markModuleUnavailable()
        return
      }
      if (moduleUnavailable.current) return
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
  const refreshing = catalogState === 'ready'
    && (settledCatalogRequestKey !== catalogRequestKey || resultsPending)
  const tableLoading = catalogState === 'loading' || refreshing || searchPending
  const totalPages = Math.max(1, meta.lastPage)
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
        {createOpening || (capabilities && capabilities.locationId === operatingLocationId && capabilities.actions.create) ? (
          <div className="deferred-action">
            <button
              type="button"
              className="button button-primary"
              disabled={capabilitiesLoading || createOpening}
              onClick={(event) => void openCreateForm(event.currentTarget)}
            >
              <span aria-hidden="true">+</span> Dodaj surowiec
            </button>
          </div>
        ) : null}
      </header>

      {operatingLocationId && capabilitiesLoading && (
        <p className="refresh-status" role="status">Sprawdzanie uprawnień katalogu…</p>
      )}
      {operatingLocationId && capabilitiesError && catalogState === 'ready' && (
        <p className="inline-error" role="alert">{capabilitiesError}</p>
      )}
      <CatalogFilters
        values={filters}
        searchInput={searchText}
        searchLoading={tableLoading}
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
        onReset={resetFilters}
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
            setCatalogRequestRevision((revision) => revision + 1)
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
      {exportError && <p className="inline-error" role="alert">{exportError}</p>}
      {exportMessage && <p className="inline-success" role="status">{exportMessage}</p>}
      {currentCreatedMaterial && (
        <p className="inline-success" role="status">
          Utworzono szkic {currentCreatedMaterial.sku}. Identyfikator: {currentCreatedMaterial.id}. Stan zwrócony przez serwer: {currentCreatedMaterial.lifecycleState}.
        </p>
      )}
      {catalogState !== 'forbidden' && catalogState !== 'unavailable' && catalogState !== 'error' && (
        <>
          <div className="catalog-summary" aria-live="polite">
            <span>
              {meta.total === 0
                ? '0 surowców'
                : `Pozycje ${meta.from ?? 0}–${meta.to ?? 0} z ${meta.total}`}
            </span>
            <span className="catalog-refresh-status" role="status">
              {tableLoading && rows.length > 0 ? 'Aktualizowanie wyników…' : ''}
            </span>
            <label>
              <span>Wierszy na stronę</span>
              <select
                value={perPage}
                onChange={(event) => {
                  setPerPage(Number(event.target.value) as (typeof PAGE_SIZES)[number])
                  setPage(1)
                  setCatalogRequestRevision((revision) => revision + 1)
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
            onOpenDetail={(id, trigger) => setSelectedDetail({ id, trigger, locationId: operatingLocationId ?? null })}
          />

          <Pagination
            currentPage={meta.currentPage}
            totalPages={totalPages}
            disabled={tableLoading}
            className="pagination"
            label="Stronicowanie katalogu"
            onPageChange={(nextPage) => {
              setPage(nextPage)
              setCatalogRequestRevision((revision) => revision + 1)
            }}
          />
        </>
      )}

      <SelectionActionBar
        count={selectedIds.size}
        exporting={exporting}
        disabled={refreshing || searchPending || catalogState !== 'ready'}
        onExport={() => void runExport([...selectedIds])}
        onClear={clearSelection}
      />
      {currentSelectedDetail && (
        <RawMaterialDetailDrawer
          key={`${currentSelectedDetail.id}:${currentSelectedDetail.locationId ?? ''}`}
          materialId={currentSelectedDetail.id}
          operatingLocationId={currentSelectedDetail.locationId ?? ''}
          globalEditAllowed={globalEditAllowed}
          refreshKey={detailRefreshKey}
          categories={categories}
          returnFocus={currentSelectedDetail.trigger}
          canReadReferencePrice={canReadReferencePrice}
          syntheticPreview={syntheticPreview}
          onClose={() => setSelectedDetail(null)}
          onSessionExpired={handleDetailSessionExpired}
          onModuleUnavailable={markModuleUnavailable}
          onEditAuthorizationDenied={() => {
            setCapabilities(null)
            setCapabilitiesError('Nie można potwierdzić uprawnień do edycji. Akcja pozostaje ukryta.')
          }}
          onEditSaved={handleEdited}
        />
      )}
      {createFormLocationId === operatingLocationId
        && capabilities?.locationId === operatingLocationId
        && capabilities.actions.create
        && catalogState === 'ready'
        && operatingLocationId && (
        <RawMaterialCreateForm
          categories={categories}
          operatingLocationId={createFormLocationId}
          returnFocus={createReturnFocus}
          onClose={() => {
            setCreateFormLocationId(null)
          }}
          onCreated={handleCreated}
          onOpenCreated={(material) => {
            setCreateFormLocationId(null)
            setSelectedDetail({ id: material.id, trigger: null, locationId: operatingLocationId ?? null })
          }}
          onSessionExpired={handleCreateSessionExpired}
          onAuthorizationDenied={() => {
            setCapabilities(null)
            setCapabilitiesError('Uprawnienie do tworzenia wygasło lub zostało cofnięte.')
          }}
          onModuleUnavailable={markModuleUnavailable}
        />
      )}
    </section>
  )
}
