import { useCallback, useEffect, useState } from 'react'
import './App.css'
import AccountLinkForm from './AccountLinkForm'
import AdministrationPanel from './AdministrationPanel'
import ApplicationShell from './ApplicationShell'
import AuthPanel, { type AuthContext } from './AuthPanel'
import { ApiError, isRecord, request } from './api'
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
  const [administrationAccess, setAdministrationAccess] = useState<{
    key: string
    status: 'allowed' | 'denied' | 'error'
    message?: string
  } | null>(null)
  const [administrationAttempt, setAdministrationAttempt] = useState(0)
  const isRawMaterialsRoute = window.location.pathname === '/raw-materials'
  const isAdministrationRoute = window.location.pathname === '/administration'
    || window.location.pathname.startsWith('/administration/')
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
    setAdministrationAccess(null)
    setAuthContext(context)
    if (context) setSessionExpired(false)
  }, [])

  const handleSessionExpired = useCallback(() => {
    setAdministrationAccess(null)
    setAuthContext(null)
    setSessionExpired(true)
    setAuthKey((key) => key + 1)
  }, [])

  const administrationKey = authContext?.user && authContext.location
    ? `${authContext.user.id}:${authContext.location.id}`
    : null

  useEffect(() => {
    if (!administrationKey) return
    const controller = new AbortController()
    void request('/api/v1/administration/access', 'GET', undefined, { signal: controller.signal })
      .then((result) => {
        if (!isRecord(result) || result.allowed !== true) throw new Error('Nieprawidłowa odpowiedź dostępu do panelu.')
        if (!controller.signal.aborted) setAdministrationAccess({ key: administrationKey, status: 'allowed' })
      })
      .catch((cause: unknown) => {
        if (controller.signal.aborted) return
        if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) {
          handleSessionExpired()
          return
        }
        setAdministrationAccess(cause instanceof ApiError && cause.status === 403
          ? { key: administrationKey, status: 'denied' }
          : { key: administrationKey, status: 'error', message: cause instanceof Error ? cause.message : 'Nie można sprawdzić dostępu do panelu.' })
      })
    return () => controller.abort()
  }, [administrationKey, administrationAttempt, authContext, handleSessionExpired])

  const administrationStatus = administrationKey && administrationAccess?.key === administrationKey
    ? administrationAccess.status
    : null

  if (isActivationRoute) {
    return (
      <main className="account-route">
        <a className="brand-link" href="/">VELTRA <span>2.0</span></a>
        <AccountLinkForm activation={window.location.pathname === '/activate'} />
      </main>
    )
  }

  const activeLocation = authContext?.location
  const contextValue = activeLocation?.name
    ?? (authContext?.user ? 'Nie wybrano lokalizacji' : 'Zaloguj się, aby wybrać lokalizację')
  const contextDetail = activeLocation
    ? `${activeLocation.code} · ${activeLocation.type === 'CENTRAL_KITCHEN' ? 'Kuchnia centralna' : 'Restauracja'}`
    : undefined
  const healthLabel = loading
    ? 'Sprawdzanie backendu'
    : health?.status === 'ok'
      ? 'Backend działa'
      : 'Backend niedostępny'
  const healthStatus = health?.status ?? (error ? 'down' : 'loading')

  return (
    <ApplicationShell
      activePath={isAdministrationRoute ? window.location.pathname : isRawMaterialsRoute ? '/raw-materials' : '/'}
      showAdministration={administrationStatus === 'allowed'}
      contexts={[{ label: 'Lokalizacja operacyjna', value: contextValue, detail: contextDetail }]}
      footerNote={developmentStageLabel}
      status={(
        <span className="topbar-health" role="status" aria-live="polite">
          <span className="status-indicator" data-status={healthStatus} aria-hidden="true" />
          {healthLabel}
        </span>
      )}
      account={<AuthPanel key={authKey} onContextChange={handleContextChange} />}
      sidebarFooter={(
        <details className="sidebar-health">
          <summary>
            <span className="status-indicator" data-status={healthStatus} aria-hidden="true" />
            <span>Status backendu</span>
          </summary>
          <section className="health-card" aria-label="Status środowiska" aria-live="polite" aria-busy={loading}>
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
        </details>
      )}
    >
      {isAdministrationRoute ? (
        !authContext?.user ? (
          <section className="state-panel"><h1>Panel Administracyjny</h1><p>{sessionExpired ? 'Sesja wygasła. Zaloguj się ponownie.' : 'Zaloguj się, aby kontynuować.'}</p></section>
        ) : !authContext.location ? (
          <section className="state-panel"><h1>Panel Administracyjny</h1><p>Wybierz lokalizację operacyjną, aby sprawdzić dostęp.</p></section>
        ) : administrationStatus === 'allowed' ? (
          <AdministrationPanel path={window.location.pathname} />
        ) : administrationStatus === 'denied' ? (
          <section className="state-panel" role="alert"><h1>Brak dostępu</h1><p>Panel Administracyjny jest dostępny wyłącznie dla administratorów.</p></section>
        ) : administrationStatus === 'error' ? (
          <section className="state-panel" role="alert">
            <h1>Nie można sprawdzić dostępu</h1>
            <p>{administrationAccess?.message}</p>
            <button type="button" className="button button-secondary" onClick={() => {
              setAdministrationAccess(null)
              setAdministrationAttempt((value) => value + 1)
            }}>Spróbuj ponownie</button>
          </section>
        ) : (
          <section className="state-panel" role="status"><h1>Panel Administracyjny</h1><p>Sprawdzanie dostępu…</p></section>
        )
      ) : isRawMaterialsRoute ? (
        authContext?.user ? (
          authContext.location ? (
            <RawMaterialsCatalogPage
              key={`${authContext.user.id}:${authContext.location.id}`}
              operatingLocationId={authContext.location.id}
              onSessionExpired={handleSessionExpired}
            />
          ) : (
            <section className="state-panel">
              <h1>Wybierz lokalizację operacyjną</h1>
              <p>Katalog surowców wymaga aktywnego kontekstu lokalizacji. Wybierz go w menu konta.</p>
            </section>
          )
        ) : (
          <section className="state-panel">
            <h1>Katalog surowców</h1>
            <p>{sessionExpired ? 'Sesja wygasła. Zaloguj się ponownie w menu konta.' : 'Zaloguj się i wybierz lokalizację, aby otworzyć katalog.'}</p>
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
    </ApplicationShell>
  )
}

export default App
