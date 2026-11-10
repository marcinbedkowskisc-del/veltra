import { useCallback, useEffect, useState } from 'react'
import './App.css'
import AccountLinkForm from './AccountLinkForm'
import AuthPanel, { type AuthContext } from './AuthPanel'
import { developmentStageLabel } from './config/developmentStage'
import RawMaterialsCatalogPage from './rawMaterials/RawMaterialsCatalogPage'

type Health = {
  app: string
  status: 'ok' | 'degraded'
  checks: {
    postgresql: 'up' | 'down'
    redis: 'up' | 'down'
  }
}

function isHealth(value: unknown): value is Health {
  if (typeof value !== 'object' || value === null) return false
  if (!('app' in value) || value.app !== 'VELTRA 2.0') return false
  if (!('status' in value) || (value.status !== 'ok' && value.status !== 'degraded')) return false
  if (!('checks' in value) || typeof value.checks !== 'object' || value.checks === null) return false

  const checks = value.checks
  return (
    'postgresql' in checks &&
    (checks.postgresql === 'up' || checks.postgresql === 'down') &&
    'redis' in checks &&
    (checks.redis === 'up' || checks.redis === 'down') &&
    (value.status === 'ok') === (checks.postgresql === 'up' && checks.redis === 'up')
  )
}

function App() {
  const [health, setHealth] = useState<Health | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [attempt, setAttempt] = useState(0)
  const [authContext, setAuthContext] = useState<AuthContext | null>(null)
  const [authKey, setAuthKey] = useState(0)
  const [sessionExpired, setSessionExpired] = useState(false)
  const isRawMaterialsRoute = window.location.pathname === '/raw-materials'
  const isActivationRoute = window.location.pathname === '/activate'
    || window.location.pathname === '/reset-password'

  useEffect(() => {
    const controller = new AbortController()
    const timeout = window.setTimeout(() => controller.abort(), 10_000)
    let active = true

    async function checkHealth() {
      setLoading(true)
      setError(null)
      setHealth(null)

      try {
        const baseUrl = (import.meta.env.VITE_API_BASE_URL ?? '/api').replace(/\/$/, '')
        const response = await fetch(`${baseUrl}/health`, {
          signal: controller.signal,
          headers: { Accept: 'application/json' },
          cache: 'no-store',
        })
        if (response.status !== 200 && response.status !== 503) {
          throw new Error(`Backend zwrócił HTTP ${response.status}.`)
        }
        let result: unknown
        try {
          result = await response.json()
        } catch {
          throw new Error('Backend zwrócił nieprawidłową odpowiedź.')
        }
        if (!isHealth(result) || (response.ok !== (result.status === 'ok'))) {
          throw new Error('Backend zwrócił nieprawidłowy status.')
        }
        if (active) setHealth(result)
      } catch (cause) {
        if (active) {
          setError(controller.signal.aborted
            ? 'Przekroczono czas oczekiwania na backend.'
            : cause instanceof Error && cause.message === 'Backend zwrócił nieprawidłową odpowiedź.'
              ? cause.message
              : 'Nie można połączyć się z backendem.')
        }
      } finally {
        window.clearTimeout(timeout)
        if (active) setLoading(false)
      }
    }

    void checkHealth()
    return () => {
      active = false
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [attempt])

  const handleContextChange = useCallback((context: AuthContext | null) => {
    setAuthContext(context)
    if (context) setSessionExpired(false)
  }, [])

  const handleSessionExpired = useCallback(() => {
    setAuthContext(null)
    setSessionExpired(true)
    setAuthKey((key) => key + 1)
  }, [])

  if (isActivationRoute) {
    return (
      <main className="account-route">
        <a className="brand-link" href="/">VELTRA <span>2.0</span></a>
        <AccountLinkForm activation={window.location.pathname === '/activate'} />
      </main>
    )
  }

  return (
    <div className="app-shell">
      <header className="app-header">
        <a className="brand-link" href="/" aria-label="VELTRA 2.0 — strona główna">
          VELTRA <span>2.0</span>
        </a>
        <nav className="primary-nav" aria-label="Nawigacja główna">
          <a href="/" aria-current={isRawMaterialsRoute ? undefined : 'page'}>Start</a>
          <a href="/raw-materials" aria-current={isRawMaterialsRoute ? 'page' : undefined}>Surowce</a>
        </nav>
        <span className="stage-label">{developmentStageLabel}</span>
      </header>

      <div className="app-layout">
        <aside className="app-sidebar" aria-label="Konto i status środowiska">
          <section className="health-card" aria-label="Status środowiska" aria-live="polite" aria-busy={loading}>
            <div className="section-heading">
              <span className="status-indicator" data-status={health?.status ?? (error ? 'down' : 'loading')} aria-hidden="true" />
              <h2>Status backendu</h2>
            </div>
            {loading && <p>Sprawdzanie połączenia…</p>}
            {error && <p role="alert" className="error">Backend niedostępny: {error}</p>}
            {health && (
              <>
                <p className={health.status === 'ok' ? 'success' : 'error'}>
                  {health.status === 'ok' ? 'Backend działa poprawnie' : 'Backend: awaria zależności'}
                </p>
                <dl>
                  <div><dt>PostgreSQL</dt><dd>{health.checks.postgresql === 'up' ? 'Dostępny' : 'Niedostępny'}</dd></div>
                  <div><dt>Redis</dt><dd>{health.checks.redis === 'up' ? 'Dostępny' : 'Niedostępny'}</dd></div>
                </dl>
              </>
            )}
            <button type="button" className="button button-quiet" disabled={loading} onClick={() => setAttempt((value) => value + 1)}>
              Sprawdź ponownie
            </button>
          </section>
          <AuthPanel key={authKey} onContextChange={handleContextChange} />
        </aside>

        <main className="app-main">
          {isRawMaterialsRoute ? (
            authContext?.user ? (
              authContext.location ? (
                <RawMaterialsCatalogPage
                  key={`${authContext.user.id}:${authContext.location.id}`}
                  onSessionExpired={handleSessionExpired}
                />
              ) : (
                <section className="state-panel">
                  <h1>Wybierz lokalizację operacyjną</h1>
                  <p>Katalog surowców wymaga aktywnego kontekstu lokalizacji. Wybierz go w panelu konta.</p>
                </section>
              )
            ) : (
              <section className="state-panel">
                <h1>Katalog surowców</h1>
                <p>{sessionExpired ? 'Sesja wygasła. Zaloguj się ponownie w panelu konta.' : 'Zaloguj się i wybierz lokalizację, aby otworzyć katalog.'}</p>
              </section>
            )
          ) : (
            <section className="welcome-panel">
              <p className="eyebrow">OPERACJE • DANE • KONTROLA</p>
              <h1>Przestrzeń operacyjna VELTRA</h1>
              <p>Kontrolowany dostęp do danych i procesów operacyjnych.</p>
              <a className="button button-primary" href="/raw-materials">Otwórz katalog surowców</a>
            </section>
          )}
        </main>
      </div>
    </div>
  )
}

export default App
