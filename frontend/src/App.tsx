import { useEffect, useState } from 'react'
import './App.css'
import AuthPanel from './AuthPanel'
import AccountLinkForm from './AccountLinkForm'

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

  return (
    <main>
      <p className="eyebrow">STAGE 1A / FUNDAMENT TECHNICZNY</p>
      <h1>VELTRA 2.0</h1>
      <section aria-label="Status środowiska" aria-live="polite" aria-busy={loading}>
        <h2>Status backendu</h2>
        {loading && <p>Sprawdzanie połączenia...</p>}
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
      </section>
      <button disabled={loading} onClick={() => setAttempt((value) => value + 1)}>
        Sprawdź ponownie
      </button>
      {window.location.pathname === '/activate' || window.location.pathname === '/reset-password'
        ? <AccountLinkForm activation={window.location.pathname === '/activate'} />
        : <AuthPanel />}
    </main>
  )
}

export default App
