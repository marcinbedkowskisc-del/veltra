import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { formatDecimal, formatMoney, lifecycleLabel } from './catalog'
import {
  fetchRawMaterialCapabilities,
  fetchRawMaterialCompletion,
  fetchRawMaterialDetail,
  fetchRawMaterialRevisions,
  type RevisedRawMaterial,
} from './api'
import type { CategoryOption } from './catalog'
import type { RawMaterialDetail, RawMaterialRevision } from './detail'
import RawMaterialEditForm from './RawMaterialEditForm'

type Props = {
  materialId: string
  operatingLocationId: string
  globalEditAllowed: boolean
  refreshKey: number
  categories: CategoryOption[]
  returnFocus: HTMLElement | null
  onClose: () => void
  onSessionExpired: () => void
  onModuleUnavailable: () => void
  onEditAuthorizationDenied: () => void
  onEditSaved: (material: RevisedRawMaterial) => void
}

type DetailState = 'loading' | 'ready' | 'not-found' | 'forbidden' | 'session-expired' | 'error'
type HistoryState = 'loading' | 'ready' | 'forbidden' | 'error'
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

export default function RawMaterialDetailDrawer({
  materialId,
  operatingLocationId,
  globalEditAllowed,
  refreshKey,
  categories,
  returnFocus,
  onClose,
  onSessionExpired,
  onModuleUnavailable,
  onEditAuthorizationDenied,
  onEditSaved,
}: Props) {
  const [detail, setDetail] = useState<RawMaterialDetail | null>(null)
  const [detailState, setDetailState] = useState<DetailState>('loading')
  const [detailLoadedRefreshKey, setDetailLoadedRefreshKey] = useState(-1)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [revisions, setRevisions] = useState<RawMaterialRevision[]>([])
  const [historyState, setHistoryState] = useState<HistoryState | null>(null)
  const [historyLoadedRefreshKey, setHistoryLoadedRefreshKey] = useState(-1)
  const [historyError, setHistoryError] = useState<string | null>(null)
  const [editAvailability, setEditAvailability] = useState<EditAvailability>('checking')
  const [editOpen, setEditOpen] = useState(false)
  const [editOpening, setEditOpening] = useState(false)
  const [editError, setEditError] = useState<string | null>(null)
  const [editReturnFocus, setEditReturnFocus] = useState<HTMLElement | null>(null)
  const dialogRef = useRef<HTMLElement>(null)
  const closeButtonRef = useRef<HTMLButtonElement>(null)
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
    closeButtonRef.current?.focus()
    return () => {
      active.current = false
      if (returnFocus?.isConnected) returnFocus.focus()
    }
  }, [returnFocus])

  useEffect(() => {
    const controller = new AbortController()

    void fetchRawMaterialDetail(materialId, controller.signal).then((result) => {
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
  }, [materialId, refreshKey])

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

  function handleKeyDown(event: React.KeyboardEvent<HTMLElement>) {
    if (event.key === 'Escape') {
      event.preventDefault()
      onClose()
      return
    }
    if (event.key !== 'Tab') return

    const focusable = dialogRef.current?.querySelectorAll<HTMLElement>(
      'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])',
    )
    if (!focusable?.length) {
      event.preventDefault()
      dialogRef.current?.focus()
      return
    }
    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

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
    if (!globalEditAllowed || !expectedLocation || editOpening) return
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

  return (
    <div className="detail-backdrop" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose()
    }}>
      <section
        ref={dialogRef}
        className="detail-drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="raw-detail-title"
        tabIndex={-1}
        onKeyDown={handleKeyDown}
      >
        <header className="detail-drawer-header">
          <div>
            <p className="eyebrow">KATALOG SUROWCÓW</p>
            <h2 id="raw-detail-title">Szczegóły surowca</h2>
          </div>
          <button
            ref={closeButtonRef}
            type="button"
            className="button button-secondary"
            onClick={onClose}
            aria-label="Zamknij szczegóły surowca"
          >
            Zamknij
          </button>
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
        {detailState === 'ready' && detail && detailLoadedRefreshKey === refreshKey && (
          <div className="detail-content">
            <section className="detail-section" aria-labelledby="detail-identity-title">
              <h3 id="detail-identity-title">{detail.revision?.name ?? detail.sku}</h3>
              {globalEditAllowed && editAvailability === 'allowed' && (
                <button type="button" className="button button-primary edit-material-action"
                  disabled={editOpening} onClick={(event) => void openEditor(event.currentTarget)}>
                  {editOpening ? 'Sprawdzanie uprawnień…' : 'Edytuj surowiec'}
                </button>
              )}
              <dl className="detail-grid">
                <div><dt>SKU</dt><dd>{detail.sku}</dd></div>
                <div><dt>Stan</dt><dd>{lifecycleLabel(detail.lifecycleState)}</dd></div>
                <div><dt>Rewizja widoczna w szczegółach</dt><dd>{detail.revision
                  ? `v${detail.revision.revisionNo}${detail.revision.id === detail.approvedRevisionId ? ' · zatwierdzona, obowiązująca' : ' · robocza'}`
                  : 'Brak rewizji'}</dd></div>
                <div><dt>Kategoria</dt><dd>{categoryName(detail.revision?.categoryId ?? null)}</dd></div>
                <div><dt>Jednostka bazowa</dt><dd>{detail.revision?.baseUnitCode ?? '—'}</dd></div>
                <div><dt>Wymiar</dt><dd>{detail.revision?.baseUnitDimension ?? '—'}</dd></div>
                <div><dt>Stan alergenów</dt><dd>{detail.revision ? allergenLabel(detail.revision.allergenState) : '—'}</dd></div>
                {detail.lastUpdate && (
                  <div>
                    <dt>Ostatnia aktualizacja</dt>
                    <dd>{dateLabel(detail.lastUpdate.occurredAt)}{detail.lastUpdate.actor ? ` · ${detail.lastUpdate.actor}` : ''}</dd>
                  </div>
                )}
              </dl>
              {detail.revision?.description && (
                <div className="detail-description">
                  <h4>Opis</h4>
                  <p>{detail.revision.description}</p>
                </div>
              )}
            </section>

            <section className="detail-section" aria-labelledby="detail-quantities-title">
              <h3 id="detail-quantities-title">Ilości i wycena</h3>
              <dl className="detail-grid">
                <div><dt>Ilość nominalna</dt><dd>{formatDecimal(detail.nominalQuantity)}</dd></div>
                <div><dt>Ilość użytkowa</dt><dd>{formatDecimal(detail.usableQuantity)}</dd></div>
                <div><dt>Wydajność</dt><dd>{detail.yieldPercent === null ? '—' : `${formatDecimal(detail.yieldPercent)}%`}</dd></div>
                {detail.referenceNetPrice !== undefined && (
                  <div><dt>Cena netto</dt><dd>{detail.referenceNetPrice === null ? 'Brak danych' : `${formatMoney(detail.referenceNetPrice)} ${detail.currencyCode}`}</dd></div>
                )}
                {detail.usableUnitCost !== undefined && (
                  <div><dt>Koszt jednostkowy</dt><dd>{detail.usableUnitCost === null ? 'Brak danych' : `${formatMoney(detail.usableUnitCost)} ${detail.usableCostBasis}`}</dd></div>
                )}
              </dl>
            </section>

            {detail.revision && (
              <section className="detail-section" aria-labelledby="detail-units-title">
                <h3 id="detail-units-title">Jednostki i aliasy</h3>
                {detail.revision.units.length > 0 ? (
                  <ul className="detail-list">
                    {detail.revision.units.map((unit) => (
                      <li key={unit.unitCode}>
                        <strong>{unit.unitCode}</strong> · {unit.dimension} · współczynnik {unit.toBaseFactor}
                        {unit.basis ? ` (${unit.basis})` : ''}
                      </li>
                    ))}
                  </ul>
                ) : <p className="detail-muted">Brak dodatkowych jednostek.</p>}
                {detail.revision.aliases.length > 0 && (
                  <p className="detail-aliases"><strong>Aliasy:</strong> {detail.revision.aliases.join(', ')}</p>
                )}
              </section>
            )}

            <section className="detail-section" aria-labelledby="detail-revisions-title">
              <h3 id="detail-revisions-title">Historia rewizji</h3>
              {detail.historyUrl === null && <p className="detail-muted">Historia rewizji nie jest dostępna w bieżącym zakresie uprawnień.</p>}
              {(historyState === null || historyState === 'loading' || historyLoadedRefreshKey !== refreshKey)
                && <p className="detail-muted" role="status">Ładowanie historii rewizji…</p>}
              {historyState === 'forbidden' && historyLoadedRefreshKey === refreshKey
                && <p className="detail-muted" role="status">Historia rewizji nie jest dostępna w bieżącym kontekście.</p>}
              {historyState === 'error' && historyLoadedRefreshKey === refreshKey
                && <p className="inline-error" role="alert">{historyError}</p>}
              {historyState === 'ready' && historyLoadedRefreshKey === refreshKey && (revisions.length > 0 ? (
                <ol className="detail-revisions">
                  {revisions.map((revision) => (
                    <li key={revision.id}>
                      <strong>v{revision.revisionNo} · {revision.name}</strong>
                      <span>{dateLabel(revision.createdAt)} · {categoryName(revision.categoryId)} · {revision.baseUnitCode} · {allergenLabel(revision.allergenState)}</span>
                      <span>{revision.criticalChange ? 'Zmiana krytyczna' : 'Zmiana standardowa'}</span>
                      {revision.approvals.map((approval, index) => (
                        <span key={`${approval.occurredAt}-${index}`}>
                          {revisionOutcomeLabel(approval.outcome)} · {dateLabel(approval.occurredAt)}
                          {approval.reason ? ` · ${approval.reason}` : ''}
                        </span>
                      ))}
                    </li>
                  ))}
                </ol>
              ) : <p className="detail-muted">Brak dostępnych rewizji.</p>)}
            </section>
          </div>
        )}
      </section>
      {editOpen && (
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
      )}
    </div>
  )
}
