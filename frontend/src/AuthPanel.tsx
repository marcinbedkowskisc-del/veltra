import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, isRecord, request } from './api'
import AvatarManagement from './avatars/AvatarManagement'
import UserAvatar from './avatars/UserAvatar'
import { avatarFromResponse, avatarPhotoUrl, type AvatarInfo } from './avatars/avatarTypes'

export type AuthUser = { id: string; first_name: string; last_name: string; email: string; avatar: AvatarInfo }
export type AuthLocation = { id: string; name: string; code: string; type: 'CENTRAL_KITCHEN' | 'RESTAURANT' }
export type AuthContext = { user: AuthUser; location: AuthLocation | null }

function parseUser(value: unknown): AuthUser {
  if (!isRecord(value) || typeof value.id !== 'string' || typeof value.first_name !== 'string'
    || typeof value.last_name !== 'string' || typeof value.email !== 'string') {
    throw new Error('Nieprawidłowy profil użytkownika.')
  }
  return {
    id: value.id,
    first_name: value.first_name,
    last_name: value.last_name,
    email: value.email,
    avatar: avatarFromResponse(value),
  }
}

function isLocation(value: unknown): value is AuthLocation {
  return isRecord(value) && ['id', 'name', 'code'].every((key) => typeof value[key] === 'string')
    && (value.type === 'CENTRAL_KITCHEN' || value.type === 'RESTAURANT')
}

export default function AuthPanel({ onContextChange }: { onContextChange: (context: AuthContext | null) => void }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [locations, setLocations] = useState<AuthLocation[]>([])
  const [current, setCurrent] = useState<AuthLocation | null>(null)
  const [selected, setSelected] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [avatarEditorOpen, setAvatarEditorOpen] = useState(false)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const reset = useCallback(() => {
    setUser(null)
    setLocations([])
    setCurrent(null)
    setSelected('')
    setAvatarEditorOpen(false)
    onContextChange(null)
  }, [onContextChange])

  const loadProfile = useCallback(async () => {
    const profile = parseUser(await request('/api/v1/auth/me'))
    setUser(profile)
    const assigned = await request('/api/v1/auth/locations')
    if (!Array.isArray(assigned) || !assigned.every(isLocation)) throw new Error('Nieprawidłowa lista lokalizacji.')
    const operating = await request('/api/v1/auth/operating-location')
    if (operating !== null && !isLocation(operating)) throw new Error('Nieprawidłowy kontekst lokalizacji.')
    setLocations(assigned)
    setCurrent(operating)
    setSelected(operating?.id ?? '')
    onContextChange({ user: profile, location: operating })
  }, [onContextChange])

  const reloadAvatar = useCallback(async () => {
    try {
      setUser(parseUser(await request('/api/v1/auth/me')))
    } catch (cause) {
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) reset()
      setError(cause instanceof Error ? cause.message : 'Nie można odświeżyć profilu.')
    }
  }, [reset])

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
  }, [loadProfile, reset])

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
        if (user) onContextChange({ user, location: null })
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
    <details className="account-menu">
      <summary className="account-menu-trigger" aria-label={user ? `Konto: ${user.first_name} ${user.last_name}` : 'Otwórz konto i zaloguj się'}>
        {user
          ? <UserAvatar firstName={user.first_name} lastName={user.last_name} avatar={user.avatar}
            photoUrl={user.avatar.type === 'UPLOAD' ? avatarPhotoUrl(undefined, user.avatar.photoVersion) : undefined} size="medium" decorative />
          : <span className="account-avatar" aria-hidden="true">V</span>}
        <span className="account-trigger-copy">
          <strong>{user ? `${user.first_name} ${user.last_name}` : 'Konto i lokalizacja'}</strong>
          <small>{user?.email ?? (busy ? 'Sprawdzanie sesji…' : 'Zaloguj się')}</small>
        </span>
        <span className="account-chevron" aria-hidden="true">⌄</span>
      </summary>
      <section className="auth-panel account-menu-panel" aria-label="Konto i lokalizacja" aria-busy={busy}>
        <h2>Konto i lokalizacja</h2>
        {error && <p role="alert" className="error">{error}</p>}
        {!user ? (
          <form onSubmit={login}>
            <p>Nie jesteś zalogowany. Konta nie można zarejestrować publicznie.</p>
            <label>Adres e-mail<input type="email" name="email" autoComplete="username" required maxLength={254}
              value={email} onChange={(event) => setEmail(event.target.value)} disabled={busy} /></label>
            <label>Hasło<input type="password" name="password" autoComplete="current-password" required
              value={password} onChange={(event) => setPassword(event.target.value)} disabled={busy} /></label>
            <div className="auth-panel-actions">
              <button type="submit" className="button button-primary" disabled={busy}>{busy ? 'Sprawdzanie sesji...' : 'Zaloguj się'}</button>
            </div>
          </form>
        ) : (
          <>
            <p>Zalogowano: {user.first_name} {user.last_name}</p>
            <p>{user.email}</p>
            <button type="button" className="button button-secondary" aria-expanded={avatarEditorOpen}
              onClick={() => setAvatarEditorOpen((open) => !open)}>
              {avatarEditorOpen ? 'Zamknij ustawienia awatara' : 'Zmień awatar'}
            </button>
            {avatarEditorOpen && <AvatarManagement
              key={user.id}
              firstName={user.first_name}
              lastName={user.last_name}
              avatar={user.avatar}
              onSaved={() => void reloadAvatar()}
              onSessionExpired={reset}
            />}
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
            <div className="auth-panel-actions">
              <button className="button button-primary" disabled={busy || selected === ''} onClick={() => void act(async () => {
                const result = await request('/api/v1/auth/operating-location', 'PUT', { location_id: selected })
                if (!isLocation(result)) throw new Error('Nieprawidłowy kontekst lokalizacji.')
                setCurrent(result)
                if (user) onContextChange({ user, location: result })
              })}>Wybierz lokalizację</button>
              <button className="button button-secondary" disabled={busy} onClick={() => void act(loadProfile)}>Odśwież przypisania</button>
              <button className="button button-secondary" disabled={busy} onClick={() => void act(async () => {
                await request('/sanctum/csrf-cookie')
                await request('/api/v1/auth/logout', 'POST')
                reset()
              })}>Wyloguj się</button>
            </div>
            <p className="notice">Przypisanie nie nadaje uprawnień administracyjnych ani biznesowych.</p>
          </>
        )}
      </section>
    </details>
  )
}
