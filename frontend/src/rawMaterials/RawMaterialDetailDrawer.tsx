import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { formatDecimal, formatMoney, lifecycleLabel } from './catalog'
import { unitLabel, unitShortLabel, type RawMaterialUnitOption } from './create'
import Drawer from '../design-system/Drawer'
import Tabs from '../design-system/Tabs'
import { formatNumber } from '../design-system/formatting'
import {
  fetchRawMaterialCapabilities,
  fetchRawMaterialCompletion,
  fetchRawMaterialDetail,
  fetchRawMaterialReferencePrices,
  fetchRawMaterialRevisions,
  type RevisedRawMaterial,
} from './api'
import type { CategoryOption } from './catalog'
import type { RawMaterialDetail, RawMaterialReferencePrice, RawMaterialRevision } from './detail'
import RawMaterialEditForm from './RawMaterialEditForm'
import RawMaterialWorkflow from './RawMaterialWorkflow'

type Props = {
  materialId: string
  operatingLocationId: string
  globalEditAllowed: boolean
  refreshKey: number
  categories: CategoryOption[]
  unitOptions?: RawMaterialUnitOption[]
  returnFocus: HTMLElement | null
  canReadReferencePrice: boolean | null
  syntheticPreview?: boolean
  working?: boolean
  onClose: () => void
  onSessionExpired: () => void
  onModuleUnavailable: () => void
  onEditAuthorizationDenied: () => void
  onEditSaved: (material: RevisedRawMaterial) => void
  onWorkflowSaved: () => void
}

type DetailState = 'loading' | 'ready' | 'not-found' | 'forbidden' | 'session-expired' | 'error'
type HistoryState = 'loading' | 'ready' | 'forbidden' | 'error'
type PriceHistoryState = 'loading' | 'ready' | 'forbidden' | 'error'
type EditAvailability = 'checking' | 'allowed' | 'denied' | 'error'
function isSessionExpired(cause: unknown): boolean {
  return cause instanceof ApiError && (cause.status === 401 || cause.status === 419)
}

function allergenLabel(state: string): string {
  return ({
    DECLARED: 'Zadeklarowano',
    UNKNOWN: 'Nieznany',
  } as Record<string, string>)[state] ?? state
}

function dimensionLabel(value: string): string {
  return ({
    MASS: 'Masa',
    VOLUME: 'Objętość',
    COUNT: 'Sztuki',
  } as Record<string, string>)[value] ?? value
}

function dateLabel(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('pl-PL')
}

function revisionOutcomeLabel(value: string): string {
  return ({
    APPROVED: 'Zatwierdzono',
    RETURNED: 'Zwrócono do poprawy',
  } as Record<string, string>)[value] ?? value
}

function PriceHistoryChart({
  prices,
  baseUnitCode,
  unitOptions,
}: {
  prices: RawMaterialReferencePrice[]
  baseUnitCode: string
  unitOptions: RawMaterialUnitOption[]
}) {
  const points = prices
    .filter((price) => price.netPlnPerBaseUnit !== null)
    .map((price) => ({ ...price, value: Number(price.netPlnPerBaseUnit) }))
    .sort((left, right) => left.effectiveFrom.localeCompare(right.effectiveFrom)
      || left.eventNo - right.eventNo)

  if (points.length === 0 || points.some((point) => !Number.isFinite(point.value))) {
    return <p className="detail-muted">Nie ma jeszcze danych cenowych dla jednostki podstawowej.</p>
  }

  const width = 760
  const height = 220
  const margin = { top: 16, right: 18, bottom: 32, left: 78 }
  const plotWidth = width - margin.left - margin.right
  const plotHeight = height - margin.top - margin.bottom
  const values = points.map((point) => point.value)
  const minValue = Math.min(...values)
  const maxValue = Math.max(...values)
  const padding = maxValue === minValue ? Math.max(maxValue * 0.1, 1) : (maxValue - minValue) * 0.12
  const domainMin = Math.max(0, minValue - padding)
  const domainMax = maxValue + padding
  const domainRange = domainMax - domainMin || 1
  const coordinates = points.map((point, index) => ({
    ...point,
    x: margin.left + (points.length === 1 ? plotWidth / 2 : (index / (points.length - 1)) * plotWidth),
    y: margin.top + ((domainMax - point.value) / domainRange) * plotHeight,
  }))
  const dateStep = Math.max(1, Math.ceil(points.length / 5))

  return (
    <div className="price-chart-scroll">
      <svg
        className="price-chart"
        viewBox={`0 0 ${width} ${height}`}
        role="img"
        aria-label={`Historia cen netto w PLN za ${unitLabel(baseUnitCode, unitOptions)}`}
      >
        <text className="price-chart-axis-title" x="14" y={margin.top + plotHeight / 2}
          transform={`rotate(-90 14 ${margin.top + plotHeight / 2})`}>
          PLN / {unitOptions.find((unit) => unit.code === baseUnitCode)?.symbol ?? unitShortLabel(baseUnitCode)}
        </text>
        {[0, 1, 2, 3].map((step) => {
          const value = domainMax - (step / 3) * domainRange
          const y = margin.top + (step / 3) * plotHeight
          return (
            <g key={step}>
              <line className="price-chart-grid" x1={margin.left} x2={width - margin.right} y1={y} y2={y} />
              <text className="price-chart-value" x={margin.left - 10} y={y + 4} textAnchor="end">
                {formatMoney(value.toFixed(2))}
              </text>
            </g>
          )
        })}
        <polyline
          className="price-chart-line"
          points={coordinates.map((point) => `${point.x},${point.y}`).join(' ')}
          fill="none"
        />
        {coordinates.map((point) => (
          <circle key={point.id} className="price-chart-point" cx={point.x} cy={point.y} r="3.5">
            <title>
              {dateLabel(point.effectiveFrom)} · {formatMoney(point.netPlnPerBaseUnit)} PLN / {unitLabel(baseUnitCode, unitOptions)}
            </title>
          </circle>
        ))}
        {coordinates.map((point, index) => (
          (index % dateStep === 0 || index === coordinates.length - 1) && (
            <text key={`date-${point.id}`} className="price-chart-date" x={point.x} y={height - 12} textAnchor="middle">
              {new Date(`${point.effectiveFrom}T00:00:00`).toLocaleDateString('pl-PL', {
                day: '2-digit',
                month: '2-digit',
                year: '2-digit',
              })}
            </text>
          )
        ))}
      </svg>
    </div>
  )
}

export default function RawMaterialDetailDrawer({
  materialId,
  operatingLocationId,
  globalEditAllowed,
  refreshKey,
  categories,
  unitOptions = [],
  returnFocus,
  canReadReferencePrice,
  syntheticPreview = false,
  working = false,
  onClose,
  onSessionExpired,
  onModuleUnavailable,
  onEditAuthorizationDenied,
  onEditSaved,
  onWorkflowSaved,
}: Props) {
  const [detail, setDetail] = useState<RawMaterialDetail | null>(null)
  const [detailState, setDetailState] = useState<DetailState>('loading')
  const [detailLoadedRefreshKey, setDetailLoadedRefreshKey] = useState(-1)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [revisions, setRevisions] = useState<RawMaterialRevision[]>([])
  const [historyState, setHistoryState] = useState<HistoryState | null>(null)
  const [historyLoadedRefreshKey, setHistoryLoadedRefreshKey] = useState(-1)
  const [historyError, setHistoryError] = useState<string | null>(null)
  const [referencePrices, setReferencePrices] = useState<RawMaterialReferencePrice[]>([])
  const [priceHistoryState, setPriceHistoryState] = useState<PriceHistoryState>('loading')
  const [priceHistoryLoadedKey, setPriceHistoryLoadedKey] = useState<string | null>(null)
  const [priceHistoryError, setPriceHistoryError] = useState<string | null>(null)
  const [priceAccessDenied, setPriceAccessDenied] = useState(false)
  const [editAvailability, setEditAvailability] = useState<EditAvailability>('checking')
  const [editOpen, setEditOpen] = useState(false)
  const [editOpening, setEditOpening] = useState(false)
  const [editError, setEditError] = useState<string | null>(null)
  const [editReturnFocus, setEditReturnFocus] = useState<HTMLElement | null>(null)
  const [activeTab, setActiveTab] = useState('overview')
  const [drawerClosing, setDrawerClosing] = useState(false)
  const closeButtonRef = useRef<HTMLButtonElement>(null)
  const closeRequested = useRef(false)
  const onSessionExpiredRef = useRef(onSessionExpired)
  const onModuleUnavailableRef = useRef(onModuleUnavailable)
  const locationRef = useRef(operatingLocationId)
  const active = useRef(true)
  const categoryNames = new Map(categories.map((category) => [category.id, category.name]))

  useLayoutEffect(() => {
    locationRef.current = operatingLocationId
  }, [operatingLocationId])

  useEffect(() => {
    onSessionExpiredRef.current = onSessionExpired
    onModuleUnavailableRef.current = onModuleUnavailable
  }, [onModuleUnavailable, onSessionExpired])

  useEffect(() => {
    active.current = true
    return () => {
      active.current = false
    }
  }, [])

  useEffect(() => {
    const controller = new AbortController()

    const request = working
      ? fetchRawMaterialDetail(materialId, controller.signal, true)
      : fetchRawMaterialDetail(materialId, controller.signal)
    void request.then((result) => {
      if (controller.signal.aborted) return
      setDetail(result)
      setDetailState('ready')
      setDetailLoadedRefreshKey(refreshKey)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      setDetail(null)
      setDetailLoadedRefreshKey(refreshKey)
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        onModuleUnavailableRef.current()
        return
      }
      if (isSessionExpired(cause)) {
        setDetailState('session-expired')
        onSessionExpiredRef.current()
        return
      }
      if (cause instanceof ApiError && cause.status === 403) {
        setDetailState('forbidden')
        return
      }
      if (cause instanceof ApiError && cause.status === 404) {
        setDetailState('not-found')
        return
      }
      setDetailError(cause instanceof Error ? cause.message : 'Nie udało się pobrać szczegółów surowca.')
      setDetailState('error')
    })
    return () => controller.abort()
  }, [canReadReferencePrice, materialId, refreshKey, working])

  useEffect(() => {
    if (!globalEditAllowed) return
    const controller = new AbortController()
    void fetchRawMaterialCompletion(materialId, controller.signal).then((completion) => {
      if (controller.signal.aborted) return
      setEditAvailability(completion.availableActions.includes('edit') && completion.version !== null
        ? 'allowed'
        : 'denied')
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      if (isSessionExpired(cause)) {
        onSessionExpiredRef.current()
        return
      }
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        onModuleUnavailableRef.current()
        return
      }
      setEditAvailability('error')
    })
    return () => controller.abort()
  }, [globalEditAllowed, materialId])

  useEffect(() => {
    if (!detail || detail.historyUrl === null) return
    const controller = new AbortController()
    void fetchRawMaterialRevisions(materialId, controller.signal).then((result) => {
      if (controller.signal.aborted) return
      setRevisions(result)
      setHistoryState('ready')
      setHistoryLoadedRefreshKey(refreshKey)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      setRevisions([])
      setHistoryLoadedRefreshKey(refreshKey)
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        onModuleUnavailableRef.current()
        return
      }
      if (isSessionExpired(cause)) {
        setDetail(null)
        setDetailState('session-expired')
        onSessionExpiredRef.current()
        return
      }
      if (cause instanceof ApiError && cause.status === 403) {
        setHistoryState('forbidden')
        return
      }
      setHistoryError(cause instanceof Error ? cause.message : 'Nie udało się pobrać historii rewizji.')
      setHistoryState('error')
    })
    return () => controller.abort()
  }, [detail, materialId, refreshKey])

  const priceHistoryRequestKey = `${materialId}:${refreshKey}`

  useEffect(() => {
    if (canReadReferencePrice !== true) return

    const controller = new AbortController()
    void fetchRawMaterialReferencePrices(materialId, controller.signal).then((result) => {
      if (controller.signal.aborted) return
      setReferencePrices(result)
      setPriceAccessDenied(false)
      setPriceHistoryState('ready')
      setPriceHistoryLoadedKey(priceHistoryRequestKey)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      setReferencePrices([])
      if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        onModuleUnavailableRef.current()
        return
      }
      if (isSessionExpired(cause)) {
        setDetail(null)
        setDetailState('session-expired')
        onSessionExpiredRef.current()
        return
      }
      if (cause instanceof ApiError && cause.status === 403) {
        setPriceAccessDenied(true)
        setPriceHistoryState('forbidden')
        setPriceHistoryLoadedKey(priceHistoryRequestKey)
        return
      }
      if (cause instanceof ApiError && cause.status === 404) {
        setPriceAccessDenied(true)
        setDetail(null)
        setDetailState('not-found')
        setPriceHistoryState('forbidden')
        setPriceHistoryLoadedKey(priceHistoryRequestKey)
        return
      }
      setPriceHistoryError(cause instanceof Error
        ? cause.message
        : 'Nie udało się pobrać historii cen.')
      setPriceHistoryState('error')
      setPriceHistoryLoadedKey(priceHistoryRequestKey)
    })
    return () => controller.abort()
  }, [canReadReferencePrice, materialId, priceHistoryRequestKey, refreshKey])

  const handleEditAuthorizationDenied = useCallback(() => {
    setEditOpen(false)
    setEditAvailability('denied')
    setEditError('Brak aktualnego uprawnienia do edycji tego surowca.')
    onEditAuthorizationDenied()
  }, [onEditAuthorizationDenied])

  const handleSessionExpired = useCallback(() => onSessionExpiredRef.current(), [])
  const handleModuleUnavailable = useCallback(() => onModuleUnavailableRef.current(), [])

  async function openEditor(trigger: HTMLButtonElement) {
    const expectedLocation = operatingLocationId
    if (!globalEditAllowed || !expectedLocation || editOpening || closeRequested.current) return
    setEditOpening(true)
    setEditError(null)
    const controller = new AbortController()
    try {
      const [capabilities, completion] = await Promise.all([
        fetchRawMaterialCapabilities(expectedLocation, controller.signal),
        fetchRawMaterialCompletion(materialId, controller.signal),
      ])
      if (controller.signal.aborted || !active.current || locationRef.current !== expectedLocation) return
      if (capabilities.locationId === expectedLocation
        && (!capabilities.available || capabilities.readiness !== 'READY')) {
        onModuleUnavailableRef.current()
        return
      }
      if (capabilities.locationId !== expectedLocation || !capabilities.actions.edit
        || !completion.availableActions.includes('edit') || !completion.version) {
        setEditAvailability('denied')
        setEditError('Nie można potwierdzić obu poziomów uprawnienia. Edytor pozostaje zamknięty.')
        onEditAuthorizationDenied()
        return
      }
      setEditAvailability('allowed')
      setEditReturnFocus(trigger)
      setEditOpen(true)
    } catch (cause) {
      if (controller.signal.aborted || !active.current || locationRef.current !== expectedLocation) return
      if (isSessionExpired(cause)) {
        onSessionExpiredRef.current()
      } else if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
        onModuleUnavailableRef.current()
      } else if (cause instanceof ApiError && cause.status === 403) {
        setEditAvailability('denied')
        setEditError('Brak aktualnego uprawnienia do edycji tego surowca.')
        onEditAuthorizationDenied()
      } else if (cause instanceof ApiError && cause.status === 404) {
        setEditAvailability('denied')
        setEditError('Surowiec jest niedostępny w bieżącym kontekście.')
      } else {
        setEditError(cause instanceof Error
          ? `Nie można potwierdzić uprawnień do edycji: ${cause.message}`
          : 'Nie można potwierdzić uprawnień do edycji.')
      }
    } finally {
      controller.abort()
      setEditOpening(false)
    }
  }

  const categoryName = (id: string | null) => id === null ? '—' : categoryNames.get(id) ?? id

  const editorOverlay = editOpen ? (
    <RawMaterialEditForm
      key={`${materialId}:${operatingLocationId}`}
      materialId={materialId}
      operatingLocationId={operatingLocationId}
      categories={categories}
      returnFocus={editReturnFocus}
      onClose={() => {
        setEditOpen(false)
        if (editReturnFocus?.isConnected) editReturnFocus.focus()
      }}
      onSaved={onEditSaved}
      onSessionExpired={handleSessionExpired}
      onAuthorizationDenied={handleEditAuthorizationDenied}
      onModuleUnavailable={handleModuleUnavailable}
    />
  ) : null

  return (
    <Drawer
      labelledBy="raw-detail-title"
      returnFocus={returnFocus}
      initialFocusRef={closeButtonRef}
      width="min(65vw, 1150px)"
      className="detail-drawer"
      suspendFocusTrap={editOpen}
      overlay={editorOverlay}
      onClose={onClose}
      onCloseStart={() => {
        closeRequested.current = true
        setDrawerClosing(true)
      }}
    >
      {(requestClose) => (
        <>
          <header className="detail-drawer-header">
            <div>
              <p className="eyebrow">KATALOG SUROWCÓW{detail?.sku ? ` · ${detail.sku}` : ''}</p>
              <h2 id="raw-detail-title">{detail?.revision?.name ?? 'Szczegóły surowca'}</h2>
              {detail && <span className="status-badge">{lifecycleLabel(detail.lifecycleState)}</span>}
            </div>
            <div className="detail-drawer-header-actions">
              {globalEditAllowed && editAvailability === 'allowed' && (
                <button type="button" className="button button-primary"
                  disabled={editOpening || drawerClosing}
                  onClick={(event) => void openEditor(event.currentTarget)}>
                  {editOpening ? 'Sprawdzanie uprawnień…' : 'Edytuj surowiec'}
                </button>
              )}
              <button
                ref={closeButtonRef}
                type="button"
                className="button button-secondary"
                onClick={requestClose}
                aria-label="Zamknij szczegóły surowca"
              >
                Zamknij
              </button>
            </div>
          </header>

          {editError && <p className="inline-error" role="alert">{editError}</p>}
          {globalEditAllowed && editAvailability === 'checking' && (
            <p className="detail-state" role="status">Sprawdzanie uprawnień do edycji tego surowca…</p>
          )}
          {(detailState === 'loading' || detailLoadedRefreshKey !== refreshKey)
            && <p className="detail-state" role="status">Ładowanie szczegółów surowca…</p>}
          {detailState === 'forbidden' && (
            <div className="state-panel state-error" role="alert">
              <h3>Brak dostępu</h3>
              <p>Szczegóły surowca nie są dostępne w bieżącym kontekście.</p>
            </div>
          )}
          {detailState === 'not-found' && (
            <div className="state-panel state-error" role="status">
              <h3>Surowiec niedostępny</h3>
              <p>Nie znaleziono surowca lub nie jest on dostępny w bieżącym kontekście.</p>
            </div>
          )}
          {detailState === 'session-expired' && (
            <div className="state-panel state-error" role="alert">
              <h3>Sesja wygasła</h3>
              <p>Zaloguj się ponownie, aby wyświetlić szczegóły surowca.</p>
            </div>
          )}
          {detailState === 'error' && (
            <div className="state-panel state-error" role="alert">
              <h3>Nie udało się wczytać szczegółów</h3>
              <p>{detailError}</p>
            </div>
          )}
          {detailState === 'ready' && detail && detailLoadedRefreshKey === refreshKey && !syntheticPreview && !editOpen && (
            <RawMaterialWorkflow
              key={`${materialId}:${operatingLocationId}:${refreshKey}`}
              materialId={materialId}
              refreshKey={refreshKey}
              onSaved={onWorkflowSaved}
              onSessionExpired={onSessionExpired}
              onModuleUnavailable={onModuleUnavailable}
            />
          )}
          {detailState === 'ready' && detail && detailLoadedRefreshKey === refreshKey && (
            <Tabs
              label="Szczegóły surowca"
              value={activeTab}
              onChange={setActiveTab}
              tabs={[
                {
                  id: 'overview',
                  label: 'Przegląd',
                  content: (
                    <div className="detail-content">
                      <section className="detail-section" aria-labelledby="detail-identity-title">
                        <h3 id="detail-identity-title">Informacje podstawowe</h3>
                        <dl className="detail-grid">
                          <div><dt>SKU</dt><dd>{detail.sku}</dd></div>
                          <div><dt>Aktualna wersja surowca</dt><dd>{detail.revision
                            ? `Wersja ${detail.revision.revisionNo} — ${detail.revision.id === detail.approvedRevisionId ? 'zatwierdzona i obowiązująca' : 'robocza'}`
                            : 'Brak wersji'}</dd></div>
                          <div><dt>Kategoria</dt><dd>{categoryName(detail.revision?.categoryId ?? null)}</dd></div>
                          <div><dt>Jednostka podstawowa</dt><dd>{detail.revision ? unitLabel(detail.revision.baseUnitCode, unitOptions) : '—'}</dd></div>
                          <div><dt>Rodzaj jednostki</dt><dd>{detail.revision ? dimensionLabel(detail.revision.baseUnitDimension) : '—'}</dd></div>
                        </dl>
                        {detail.revision?.description && (
                          <div className="detail-description">
                            <h4>Opis</h4>
                            <p>{detail.revision.description}</p>
                          </div>
                        )}
                      </section>

                      <section className="detail-section" aria-labelledby="detail-quantities-title">
                        <h3 id="detail-quantities-title">Ilości, wydajność i wycena</h3>
                        <dl className="detail-grid">
                          <div><dt>Ilość nominalna</dt><dd>{formatDecimal(detail.nominalQuantity)}</dd></div>
                          <div><dt>Ilość użytkowa</dt><dd>{formatDecimal(detail.usableQuantity)}</dd></div>
                          <div><dt>Wydajność</dt><dd>{detail.yieldPercent === null ? '—' : `${formatDecimal(detail.yieldPercent)}%`}</dd></div>
                          {canReadReferencePrice === true && !priceAccessDenied && detail.referenceNetPrice !== undefined && (
                            <div><dt>Cena netto</dt><dd>{detail.referenceNetPrice === null ? 'Brak danych' : `${formatMoney(detail.referenceNetPrice)} ${detail.currencyCode}`}</dd></div>
                          )}
                          {detail.usableUnitCost !== undefined && (
                            <div><dt>Koszt jednostkowy</dt><dd>{detail.usableUnitCost === null ? 'Brak danych' : `${formatMoney(detail.usableUnitCost)} ${detail.usableCostBasis}`}</dd></div>
                          )}
                        </dl>
                      </section>

                      <section className="detail-section detail-section-wide" aria-labelledby="detail-prices-title">
                        <div className="detail-section-heading">
                          <div>
                            <h3 id="detail-prices-title">Historia cen</h3>
                            <p className="detail-muted">Cena netto w PLN za jednostkę podstawową</p>
                          </div>
                          {syntheticPreview && <span className="demo-data-badge">Dane demonstracyjne</span>}
                        </div>
                        {canReadReferencePrice !== true && (
                          <p className="detail-muted">Historia cen nie jest dostępna w bieżącym zakresie uprawnień.</p>
                        )}
                        {canReadReferencePrice === true
                          && (priceHistoryState === 'loading' || priceHistoryLoadedKey !== priceHistoryRequestKey)
                          && <p className="detail-muted" role="status">Ładowanie historii cen…</p>}
                        {canReadReferencePrice === true && priceHistoryState === 'forbidden'
                          && priceHistoryLoadedKey === priceHistoryRequestKey
                          && <p className="detail-muted">Historia cen nie jest dostępna w bieżącym kontekście.</p>}
                        {canReadReferencePrice === true && priceHistoryState === 'error'
                          && priceHistoryLoadedKey === priceHistoryRequestKey
                          && <p className="inline-error" role="alert">{priceHistoryError}</p>}
                        {canReadReferencePrice === true && priceHistoryState === 'ready'
                          && priceHistoryLoadedKey === priceHistoryRequestKey && (
                            referencePrices.length === 0
                              ? <p className="detail-muted">Nie ma jeszcze zarejestrowanych cen dla tego surowca.</p>
                              : detail.revision
                                ? (
                                  <>
                                    <PriceHistoryChart prices={referencePrices} baseUnitCode={detail.revision.baseUnitCode} unitOptions={unitOptions} />
                                    <ul className="detail-list" aria-label="Szczegóły historii cen">
                                      {referencePrices.map((price) => (
                                        <li key={price.id}>
                                          <strong>{dateLabel(price.effectiveFrom)} · zdarzenie {price.eventNo}</strong>
                                          {price.pricingInputBasis && price.pricingInputAmount !== null && (
                                            <p className="detail-muted">
                                              Podstawa: {price.pricingInputBasis === 'PACKAGE'
                                                ? 'całe opakowanie'
                                                : `${price.pricingInputBasis === 'NOMINAL_UNIT' ? 'jednostka nominalna' : 'jednostka podstawowa'} (${unitShortLabel(price.pricingInputUnitCode ?? '')})`}
                                              {' · '}średnia cena wejściowa {formatMoney(price.pricingInputAmount)} PLN
                                              {price.pricingNominalQuantity !== null
                                                && price.pricingUsableQuantity !== null
                                                && price.pricingBaseUnitCode !== null
                                                ? ` · nominalna ${formatNumber(price.pricingNominalQuantity, { maximumFractionDigits: 6 })} ${unitShortLabel(price.pricingBaseUnitCode)} · użytkowa ${formatNumber(price.pricingUsableQuantity, { maximumFractionDigits: 6 })} ${unitShortLabel(price.pricingBaseUnitCode)}`
                                                : ''}
                                            </p>
                                          )}
                                        </li>
                                      ))}
                                    </ul>
                                  </>
                                )
                                : <p className="detail-muted">Nie można wyświetlić cen bez informacji o jednostce podstawowej.</p>
                          )}
                      </section>
                    </div>
                  ),
                },
                {
                  id: 'units',
                  label: 'Jednostki i nazwy',
                  content: (
                    <div className="detail-content detail-tab-content">
                      <section className="detail-section" aria-labelledby="detail-base-unit-title">
                        <h3 id="detail-base-unit-title">Jednostka podstawowa</h3>
                        <dl className="detail-grid">
                          <div><dt>Jednostka</dt><dd>{detail.revision ? unitLabel(detail.revision.baseUnitCode, unitOptions) : '—'}</dd></div>
                          <div><dt>Rodzaj jednostki</dt><dd>{detail.revision ? dimensionLabel(detail.revision.baseUnitDimension) : '—'}</dd></div>
                        </dl>
                      </section>
                      <section className="detail-section" aria-labelledby="detail-units-title">
                        <h3 id="detail-units-title">Dodatkowe jednostki miary</h3>
                        {detail.revision && detail.revision.units.length > 0 ? (
                          <ul className="detail-list">
                            {detail.revision.units.map((unit) => (
                              <li key={unit.unitCode}>
                                <strong>{unitLabel(unit.unitCode, unitOptions)}</strong> · {dimensionLabel(unit.dimension)} · przelicznik {formatNumber(unit.toBaseFactor, { maximumFractionDigits: 12 })}
                                {unit.basis ? ` (${unit.basis})` : ''}
                              </li>
                            ))}
                          </ul>
                        ) : <p className="detail-muted">Brak dodatkowych jednostek miary.</p>}
                      </section>
                      <section className="detail-section detail-section-wide" aria-labelledby="detail-aliases-title">
                        <h3 id="detail-aliases-title">Inne nazwy surowca</h3>
                        {detail.revision?.aliases.length ? (
                          <p className="detail-aliases">{detail.revision.aliases.join(', ')}</p>
                        ) : <p className="detail-muted">Brak innych nazw surowca.</p>}
                      </section>
                    </div>
                  ),
                },
                {
                  id: 'history',
                  label: 'Historia zmian',
                  content: (
                    <div className="detail-content detail-tab-content">
                      <section className="detail-section" aria-labelledby="detail-other-title">
                        <h3 id="detail-other-title">Pozostałe informacje</h3>
                        <dl className="detail-grid">
                          <div><dt>Stan alergenów</dt><dd>{detail.revision ? allergenLabel(detail.revision.allergenState) : '—'}</dd></div>
                          {detail.lastUpdate && (
                            <div>
                              <dt>Ostatnia aktualizacja</dt>
                              <dd>{dateLabel(detail.lastUpdate.occurredAt)}{detail.lastUpdate.actor ? ` · ${detail.lastUpdate.actor}` : ''}</dd>
                            </div>
                          )}
                        </dl>
                      </section>
                      <section className="detail-section detail-section-wide" aria-labelledby="detail-revisions-title">
                        <h3 id="detail-revisions-title">Historia zmian</h3>
                        {detail.historyUrl === null && <p className="detail-muted">Historia zmian nie jest dostępna w bieżącym zakresie uprawnień.</p>}
                        {(historyState === null || historyState === 'loading' || historyLoadedRefreshKey !== refreshKey)
                          && <p className="detail-muted" role="status">Ładowanie historii zmian…</p>}
                        {historyState === 'forbidden' && historyLoadedRefreshKey === refreshKey
                          && <p className="detail-muted" role="status">Historia zmian nie jest dostępna w bieżącym kontekście.</p>}
                        {historyState === 'error' && historyLoadedRefreshKey === refreshKey
                          && <p className="inline-error" role="alert">{historyError}</p>}
                        {historyState === 'ready' && historyLoadedRefreshKey === refreshKey && (revisions.length > 0 ? (
                          <ol className="detail-revisions">
                            {revisions.map((revision) => (
                              <li key={revision.id}>
                                <strong>Wersja {revision.revisionNo} · {revision.name}</strong>
                                <span>{dateLabel(revision.createdAt)} · {categoryName(revision.categoryId)} · {unitLabel(revision.baseUnitCode, unitOptions)} · {allergenLabel(revision.allergenState)}</span>
                                <span>{revision.criticalChange ? 'Zmiana oznaczona jako krytyczna' : 'Zmiana standardowa'}</span>
                                {revision.approvals.map((approval, index) => (
                                  <span key={`${approval.occurredAt}-${index}`}>
                                    {revisionOutcomeLabel(approval.outcome)} · {dateLabel(approval.occurredAt)}
                                    {approval.reason ? ` · ${approval.reason}` : ''}
                                  </span>
                                ))}
                              </li>
                            ))}
                          </ol>
                        ) : <p className="detail-muted">Brak dostępnych zmian.</p>)}
                      </section>
                    </div>
                  ),
                },
              ]}
            />
          )}
        </>
      )}
    </Drawer>
  )
}
