import { useEffect, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import Pagination from '../design-system/Pagination'
import { fetchBossRawMaterial, fetchBossRawMaterials, type BossRawMaterial, type BossRawMaterialsPage } from './api'

type Props = {
  identityKey: string
  operatingLocationId: string
  onSessionExpired: () => void
}

type CatalogStatus = 'ready' | 'forbidden' | 'unavailable' | 'error' | 'expired'
type CatalogState = { key: string; status: CatalogStatus; message?: string }
type CatalogResult = { key: string; page: BossRawMaterialsPage }
type SelectedMaterial = { contextKey: string; id: string }
type DetailState = { key: string } & (
  | { status: 'ready'; material: BossRawMaterial }
  | { status: 'error'; message: string }
)

function supplierDescription(names: string[] | null): string {
  if (names === null) return 'Brak zweryfikowanego snapshotu dostawców'
  if (names.length === 0) return 'Brak dostawców w zatwierdzonej rewizji'
  return names.join(', ')
}

function accessFailure(cause: unknown): { status: CatalogStatus; message: string } | null {
  if (!(cause instanceof ApiError)) return null
  if (cause.status === 401 || cause.status === 419) {
    return { status: 'expired', message: 'Sesja wygasła. Zaloguj się ponownie.' }
  }
  if (cause.status === 403) {
    return { status: 'forbidden', message: 'Nie masz dostępu do katalogu BOSS.' }
  }
  if (cause.status === 503 && cause.code === 'BOSS_RAW_CATALOG_UNAVAILABLE') {
    return {
      status: 'unavailable',
      message: 'Katalog BOSS nie jest obecnie gotowy lub włączony dla organizacji.',
    }
  }
  return null
}

export default function BossRawMaterialsCatalogPage({
  identityKey,
  operatingLocationId,
  onSessionExpired,
}: Props) {
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [pageNumber, setPageNumber] = useState(1)
  const [retry, setRetry] = useState(0)
  const [catalogState, setCatalogState] = useState<CatalogState | null>(null)
  const [catalogResult, setCatalogResult] = useState<CatalogResult | null>(null)
  const [selected, setSelected] = useState<SelectedMaterial | null>(null)
  const [detailState, setDetailState] = useState<DetailState | null>(null)

  const contextKey = `${identityKey}:${operatingLocationId}`
  const requestKey = `${contextKey}:${search}:${pageNumber}:${retry}`
  const currentCatalogState = catalogState?.key === requestKey
    ? catalogState
    : { key: requestKey, status: 'loading' as const }
  const currentPage = catalogResult?.key === requestKey ? catalogResult.page : null
  const selectedId = selected?.contextKey === contextKey ? selected.id : null
  const detailKey = selectedId ? `${contextKey}:${selectedId}` : null
  const currentDetail = detailKey
    ? detailState?.key === detailKey
      ? detailState
      : { key: detailKey, status: 'loading' as const }
    : null
  const loading = currentCatalogState.status === 'loading'

  useEffect(() => {
    const controller = new AbortController()
    let active = true

    void fetchBossRawMaterials(search, pageNumber, controller.signal)
      .then((result) => {
        if (!active) return
        setCatalogResult({ key: requestKey, page: result })
        setCatalogState({ key: requestKey, status: 'ready' })
      })
      .catch((cause: unknown) => {
        if (!active || controller.signal.aborted) return
        setCatalogResult(null)
        const failure = accessFailure(cause)
        if (failure) {
          setCatalogState({ key: requestKey, ...failure })
          if (failure.status === 'expired') onSessionExpired()
        } else {
          setCatalogState({
            key: requestKey,
            status: 'error',
            message: cause instanceof Error ? cause.message : 'Nie udało się wczytać katalogu BOSS.',
          })
        }
      })

    return () => {
      active = false
      controller.abort()
    }
  }, [onSessionExpired, operatingLocationId, identityKey, pageNumber, requestKey, retry, search])

  useEffect(() => {
    if (!selectedId || !detailKey) return
    const controller = new AbortController()
    let active = true

    void fetchBossRawMaterial(selectedId, controller.signal)
      .then((material) => {
        if (active) setDetailState({ key: detailKey, status: 'ready', material })
      })
      .catch((cause: unknown) => {
        if (!active || controller.signal.aborted) return
        const failure = accessFailure(cause)
        if (failure) {
          setCatalogResult(null)
          setCatalogState({ key: requestKey, ...failure })
          setSelected(null)
          setDetailState(null)
          if (failure.status === 'expired') onSessionExpired()
          return
        }
        setDetailState({
          key: detailKey,
          status: 'error',
          message: cause instanceof Error ? cause.message : 'Nie udało się wczytać szczegółów surowca.',
        })
      })

    return () => {
      active = false
      controller.abort()
    }
  }, [detailKey, onSessionExpired, requestKey, selectedId])

  function applySearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSelected(null)
    setPageNumber(1)
    setSearch(searchInput.trim())
  }

  function changePage(nextPage: number) {
    setSelected(null)
    setPageNumber(nextPage)
  }

  function retryCatalog() {
    setRetry((current) => current + 1)
  }

  return (
    <section className="catalog-page boss-catalog-page" aria-labelledby="boss-catalog-title" aria-busy={loading}>
      <header className="catalog-heading">
        <div>
          <p className="eyebrow">WIDOK BOSS · TYLKO DO ODCZYTU</p>
          <h1 id="boss-catalog-title">Katalog zatwierdzonych surowców</h1>
          <p className="catalog-subtitle">Dane i dostawcy pochodzą wyłącznie z zatwierdzonych rewizji.</p>
        </div>
      </header>

      <form className="catalog-toolbar boss-catalog-toolbar" onSubmit={applySearch}>
        <label className="filter-field boss-catalog-search" htmlFor="boss-catalog-search">
          Szukaj po nazwie lub kodzie
          <input
            id="boss-catalog-search"
            type="search"
            value={searchInput}
            onChange={(event) => setSearchInput(event.currentTarget.value)}
            maxLength={120}
            placeholder="Wpisz nazwę lub kod"
          />
        </label>
        <button type="submit" className="button button-primary" disabled={loading}>Szukaj</button>
      </form>

      {currentCatalogState.status === 'loading' && (
        <p className="state-panel" role="status">Wczytywanie katalogu BOSS…</p>
      )}
      {currentCatalogState.status === 'forbidden' && (
        <section className="state-panel" role="alert">
          <h2>Brak dostępu</h2>
          <p>{currentCatalogState.message}</p>
        </section>
      )}
      {currentCatalogState.status === 'unavailable' && (
        <section className="state-panel" role="alert">
          <h2>Katalog BOSS niedostępny</h2>
          <p>{currentCatalogState.message}</p>
          <button type="button" className="button button-secondary" onClick={retryCatalog}>Spróbuj ponownie</button>
        </section>
      )}
      {(currentCatalogState.status === 'error' || currentCatalogState.status === 'expired') && (
        <section className="state-panel" role="alert">
          <h2>{currentCatalogState.status === 'expired' ? 'Sesja wygasła' : 'Nie można wczytać katalogu'}</h2>
          <p>{currentCatalogState.message}</p>
          {currentCatalogState.status === 'error' && (
            <button type="button" className="button button-secondary" onClick={retryCatalog}>Spróbuj ponownie</button>
          )}
        </section>
      )}

      {currentCatalogState.status === 'ready' && currentPage && (
        <>
          <p className="boss-catalog-count" aria-live="polite">
            Liczba zatwierdzonych surowców: {currentPage.meta.total}
          </p>
          {currentPage.data.length === 0 ? (
            <p className="state-panel" role="status">Brak zatwierdzonych surowców spełniających kryteria.</p>
          ) : (
            <div className="table-scroll boss-catalog-table-wrap">
              <table className="catalog-table boss-catalog-table">
                <thead>
                  <tr>
                    <th scope="col">Kod</th>
                    <th scope="col">Nazwa</th>
                    <th scope="col">Kategoria</th>
                    <th scope="col">Jednostka</th>
                    <th scope="col">Zatwierdzeni dostawcy</th>
                    <th scope="col"><span className="visually-hidden">Szczegóły</span></th>
                  </tr>
                </thead>
                <tbody>
                  {currentPage.data.map((material) => (
                    <tr key={material.id}>
                      <td>{material.code}</td>
                      <td>{material.name}</td>
                      <td>{material.category.name}</td>
                      <td>{material.base_unit_code}</td>
                      <td>{supplierDescription(material.supplier_names)}</td>
                      <td>
                        <button
                          type="button"
                          className="button button-secondary"
                          onClick={() => setSelected({ contextKey, id: material.id })}
                        >
                          Szczegóły
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {currentPage.meta.last_page > 1 && (
            <Pagination
              currentPage={currentPage.meta.current_page}
              totalPages={currentPage.meta.last_page}
              onPageChange={changePage}
              disabled={loading}
              label="Stronicowanie katalogu BOSS"
              className="boss-catalog-pagination"
            />
          )}
          {selectedId && (
            <section className="boss-catalog-detail state-panel" aria-labelledby="boss-catalog-detail-title">
              <header>
                <h2 id="boss-catalog-detail-title">Szczegóły zatwierdzonego surowca</h2>
                <button type="button" className="button button-secondary" onClick={() => setSelected(null)}>
                  Zamknij szczegóły
                </button>
              </header>
              {currentDetail?.status === 'loading' && <p role="status">Wczytywanie szczegółów…</p>}
              {currentDetail?.status === 'error' && <p role="alert">{currentDetail.message}</p>}
              {currentDetail?.status === 'ready' && currentDetail.material && (
                <dl>
                  <div><dt>Kod</dt><dd>{currentDetail.material.code}</dd></div>
                  <div><dt>Nazwa</dt><dd>{currentDetail.material.name}</dd></div>
                  <div><dt>Kategoria</dt><dd>{currentDetail.material.category.name}</dd></div>
                  <div><dt>Jednostka bazowa</dt><dd>{currentDetail.material.base_unit_code}</dd></div>
                  <div><dt>Zatwierdzeni dostawcy</dt><dd>{supplierDescription(currentDetail.material.supplier_names)}</dd></div>
                </dl>
              )}
            </section>
          )}
        </>
      )}
    </section>
  )
}
