import { useCallback, useState } from 'react'
import { createRoot } from 'react-dom/client'
import './App.css'
import './index.css'
import RawMaterialsCatalogPage from './rawMaterials/RawMaterialsCatalogPage'
import { installVisualPreviewApi } from './visualPreviewApi'

installVisualPreviewApi()

function VisualPreview() {
  const mode = new URLSearchParams(window.location.search).get('mode') ?? 'demo'
  const createPreview = mode.startsWith('create-')
  const [sessionExpired, setSessionExpired] = useState(false)
  const handleSessionExpired = useCallback(() => setSessionExpired(true), [])
  const previewDetailId = new URLSearchParams(window.location.search).get('detail') === 'first'
    ? '00000000-0000-4000-8000-000000000001'
    : undefined
  const [canvasWidth, canvasHeight] = (new URLSearchParams(window.location.search).get('canvas') ?? '')
    .split('x')
    .map(Number)
  const width = Number.isFinite(canvasWidth) && canvasWidth > 0 ? `${canvasWidth}px` : '100vw'
  const height = Number.isFinite(canvasHeight) && canvasHeight > 0 ? `${canvasHeight}px` : '100vh'

  return (
    <div className="preview-canvas" style={{ width, height, overflow: 'hidden' }}>
      <div className="app-shell" style={{ width: '100%', height: '100%', overflow: 'hidden' }}>
        <header className="app-header">
          <a className="brand-link" href="/" aria-label="VELTRA 2.0 — strona główna">
            VELTRA <span>2.0</span>
          </a>
          <nav className="primary-nav" aria-label="Nawigacja główna">
            <a href="/">Start</a>
            <a href="/raw-materials" aria-current="page">Surowce</a>
          </nav>
          <span className="stage-label">IZOLOWANY PODGLĄD • DANE DEMONSTRACYJNE</span>
        </header>

        <div className="app-layout">
          <aside className="app-sidebar" aria-label="Konto i status środowiska">
            <section className="health-card" aria-label="Status środowiska">
              <div className="section-heading">
                <span className="status-indicator" data-status="down" aria-hidden="true" />
                <h2>Status backendu</h2>
              </div>
              <p className="error">Backend wyłączony — odpowiedzi API są symulowane.</p>
              <p>Moduł RAW_MATERIALS: NOT_READY</p>
              {createPreview && (
                <p className="notice">
                  Podgląd tworzenia używa syntetycznych capabilities i danych. Żądania zapisu są symulowane; produkcyjna gotowość pozostaje NOT_READY.
                </p>
              )}
            </section>
            <section className="auth-panel" aria-label="Konto i lokalizacja">
              <h2>Kontekst testowy</h2>
              <p>Użytkownik demonstracyjny</p>
              <p>Bistro Demonstracyjne • Kuchnia centralna</p>
              <p className="notice">To nie jest sesja produkcyjna. Dane są syntetyczne.</p>
            </section>
          </aside>

          <main className="app-main">
            {sessionExpired && (
              <section className="state-panel state-error" role="alert">
                <h2>Symulacja: sesja wygasła</h2>
                <p>Żądanie demonstracyjne otrzymało HTTP 401.</p>
              </section>
            )}
            <RawMaterialsCatalogPage
              onSessionExpired={handleSessionExpired}
              previewDetailId={previewDetailId}
              operatingLocationId="00000000-0000-4000-8000-000000000101"
            />
          </main>
        </div>
      </div>
    </div>
  )
}

createRoot(document.getElementById('root')!).render(<VisualPreview />)
