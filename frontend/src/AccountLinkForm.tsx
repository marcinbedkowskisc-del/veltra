import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { request } from './api'

export default function AccountLinkForm({ activation }: { activation: boolean }) {
  const [link] = useState(() => {
    const values = new URLSearchParams(window.location.hash.slice(1))
    return { email: values.get('email') ?? '', token: values.get('token') ?? '' }
  })
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState(false)

  useEffect(() => {
    window.history.replaceState(null, '', window.location.pathname)
  }, [])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await request('/sanctum/csrf-cookie')
      await request(`/api/v1/account/${activation ? 'activate' : 'reset-password'}`, 'POST', {
        email: link.email, token: link.token, password, password_confirmation: confirmation,
      })
      setDone(true)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Nie można użyć linku.')
    } finally {
      setPassword('')
      setConfirmation('')
      setBusy(false)
    }
  }

  return (
    <section className="auth-panel" aria-label="Bezpieczeństwo konta">
      <h2>{activation ? 'Aktywacja konta' : 'Zmiana hasła'}</h2>
      {error && <p role="alert" className="error">{error}</p>}
      {done ? <p>Gotowe. <a href="/">Przejdź do logowania</a>.</p> : !link.email || !link.token ? (
        <p role="alert">Brak poprawnego linku. Poproś uprawnionego przełożonego o nową wiadomość.</p>
      ) : (
        <form onSubmit={(event) => void submit(event)}>
          <p>Ustaw własne hasło dla {link.email}. Minimum 12 znaków, małe i wielkie litery, cyfra i symbol; maksymalnie 72 bajty.</p>
          <label>Nowe hasło<input type="password" autoComplete="new-password" required minLength={12}
            value={password} onChange={(event) => setPassword(event.target.value)} disabled={busy} /></label>
          <label>Potwierdź hasło<input type="password" autoComplete="new-password" required minLength={12}
            value={confirmation} onChange={(event) => setConfirmation(event.target.value)} disabled={busy} /></label>
          <button type="submit" disabled={busy}>Ustaw hasło</button>
        </form>
      )}
    </section>
  )
}
