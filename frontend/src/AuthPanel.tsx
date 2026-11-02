import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, isRecord, request } from './api'

type User = { id: string; first_name: string; last_name: string; email: string }
type Location = { id: string; name: string; code: string; type: 'CENTRAL_KITCHEN' | 'RESTAURANT' }

function isUser(value: unknown): value is User {
  return isRecord(value) && ['id', 'first_name', 'last_name', 'email'].every((key) => typeof value[key] === 'string')
}

function isLocation(value: unknown): value is Location {
  return isRecord(value) && ['id', 'name', 'code'].every((key) => typeof value[key] === 'string')
    && (value.type === 'CENTRAL_KITCHEN' || value.type === 'RESTAURANT')
}

export default function AuthPanel() {
  const [user, setUser] = useState<User | null>(null)
  const [locations, setLocations] = useState<Location[]>([])
  const [current, setCurrent] = useState<Location | null>(null)
  const [selected, setSelected] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)

  function reset() {
    setUser(null)
    setLocations([])
    setCurrent(null)
    setSelected('')
  }

  async function loadProfile() {
    const profile = await request('/api/v1/auth/me')
    if (!isUser(profile)) throw new Error('Nieprawidłowy profil użytkownika.')
    setUser(profile)
    const assigned = await request('/api/v1/auth/locations')
    if (!Array.isArray(assigned) || !assigned.every(isLocation)) throw new Error('Nieprawidłowa lista lokalizacji.')
    const operating = await request('/api/v1/auth/operating-location')
    if (operating !== null && !isLocation(operating)) throw new Error('Nieprawidłowy kontekst lokalizacji.')
    setLocations(assigned)
    setCurrent(operating)
    setSelected(operating?.id ?? '')
  }

  useEffect(() => {
    let active = true
    async function initialize() {
      try {
        await loadProfile()
      } catch (cause) {
        if (active) {
          reset()
          if (!(cause instanceof ApiError && cause.status === 401)) {
            setError(cause instanceof Error ? cause.message : 'Nie można sprawdzić sesji.')
          }
        }
      } finally {
        if (active) setBusy(false)
      }
    }
    void initialize()
    return () => { active = false }
  }, [])

  async function act(operation: () => Promise<void>) {
    setBusy(true)
    setError(null)
    try {
      await operation()
    } catch (cause) {
      if (cause instanceof ApiError && cause.status === 401) reset()
      if (cause instanceof ApiError && cause.status === 403) {
        setCurrent(null)
        setSelected('')
        setLocations([])
      }
      setError(cause instanceof Error ? cause.message : 'Nie można wykonać operacji.')
    } finally {
      setPassword('')
      setBusy(false)
    }
  }

  function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void act(async () => {
      await request('/sanctum/csrf-cookie')
      await request('/api/v1/auth/login', 'POST', { email, password })
      await loadProfile()
    })
  }

  return (
    <section className="auth-panel" aria-label="Konto i lokalizacja" aria-busy={busy}>
      <h2>Konto i lokalizacja</h2>
      {error && <p role="alert" className="error">{error}</p>}
      {!user ? (
        <form onSubmit={login}>
          <p>Nie jesteś zalogowany. Konta nie można zarejestrować publicznie.</p>
          <label>Adres e-mail<input type="email" name="email" autoComplete="username" required maxLength={254}
            value={email} onChange={(event) => setEmail(event.target.value)} disabled={busy} /></label>
          <label>Hasło<input type="password" name="password" autoComplete="current-password" required
            value={password} onChange={(event) => setPassword(event.target.value)} disabled={busy} /></label>
          <button type="submit" disabled={busy}>{busy ? 'Sprawdzanie sesji...' : 'Zaloguj się'}</button>
        </form>
      ) : (
        <>
          <p>Zalogowano: {user.first_name} {user.last_name}</p>
          <p>{user.email}</p>
          <p aria-live="polite">Bieżąca lokalizacja: {current ? `${current.name} (${current.code})` : 'Nie wybrano'}</p>
          {locations.length === 0 && <p>Brak przypisanych aktywnych lokalizacji.</p>}
          <label>Lokalizacja operacyjna
            <select value={selected} onChange={(event) => setSelected(event.target.value)} disabled={busy}>
              <option value="">Wybierz lokalizację</option>
              {locations.map((location) => <option key={location.id} value={location.id}>
                {location.name} ({location.code}) — {location.type === 'CENTRAL_KITCHEN' ? 'Kuchnia centralna' : 'Restauracja'}
              </option>)}
            </select>
          </label>
          <button disabled={busy || selected === ''} onClick={() => void act(async () => {
            const result = await request('/api/v1/auth/operating-location', 'PUT', { location_id: selected })
            if (!isLocation(result)) throw new Error('Nieprawidłowy kontekst lokalizacji.')
            setCurrent(result)
          })}>Wybierz lokalizację</button>
          <button disabled={busy} onClick={() => void act(loadProfile)}>Odśwież przypisania</button>
          <button disabled={busy} onClick={() => void act(async () => {
            await request('/sanctum/csrf-cookie')
            await request('/api/v1/auth/logout', 'POST')
            reset()
          })}>Wyloguj się</button>
          <p className="notice">Przypisanie nie nadaje uprawnień administracyjnych ani biznesowych.</p>
        </>
      )}
    </section>
  )
}
