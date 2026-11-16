import { useEffect, useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import { createCategory, fetchCategories, fetchRawMaterialCapabilities, renameCategory } from './api'
import type { CategoryOption } from './catalog'

type Props = {
  locationId: string
  categories: CategoryOption[]
  onUpdated: (categories: CategoryOption[]) => void
  onClose: () => void
  onSessionExpired: () => void
  onAuthorizationDenied: () => void
  onModuleUnavailable: () => void
}

export default function RawMaterialCategoryManager({
  locationId, categories, onUpdated, onClose, onSessionExpired, onAuthorizationDenied, onModuleUnavailable,
}: Props) {
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [parentId, setParentId] = useState('')
  const [renameId, setRenameId] = useState('')
  const [newName, setNewName] = useState('')
  const [busy, setBusy] = useState(false)
  const [needsRefresh, setNeedsRefresh] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const active = useRef<AbortController | null>(null)

  useEffect(() => () => active.current?.abort(), [])

  async function refresh() {
    if (active.current) return
    const controller = new AbortController()
    active.current = controller
    setBusy(true)
    setError(null)
    try {
      const updated = await fetchCategories(controller.signal)
      if (controller.signal.aborted) return
      onUpdated(updated)
      setNeedsRefresh(false)
      setMessage('Lista kategorii została odświeżona. Sprawdź ją przed ponowieniem zapisu.')
    } catch (cause) {
      if (controller.signal.aborted) return
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired()
      else if (cause instanceof ApiError && (cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE' || cause.status === 503)) onModuleUnavailable()
      else if (cause instanceof ApiError && cause.status === 403) onAuthorizationDenied()
      else setError('Nie można odświeżyć kategorii. Zapis pozostaje zablokowany.')
    } finally {
      if (!controller.signal.aborted) {
        active.current = null
        setBusy(false)
      }
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>, mode: 'create' | 'rename') {
    event.preventDefault()
    if (active.current || needsRefresh) return
    const controller = new AbortController()
    active.current = controller
    setBusy(true)
    setError(null)
    setMessage(null)
    let writeStarted = false
    try {
      const capability = await fetchRawMaterialCapabilities(locationId, controller.signal)
      if (controller.signal.aborted) return
      if (!capability.actions.manageCategories) {
        onAuthorizationDenied()
        return
      }
      writeStarted = true
      if (mode === 'create') {
        await createCategory({ code: code.trim(), name: name.trim(), ...(parentId ? { parent_id: parentId } : {}) }, controller.signal)
      } else {
        await renameCategory(renameId, newName.trim(), controller.signal)
      }
      if (controller.signal.aborted) return
      setNeedsRefresh(true)
      const updated = await fetchCategories(controller.signal)
      if (controller.signal.aborted) return
      onUpdated(updated)
      setNeedsRefresh(false)
      setCode('')
      setName('')
      setParentId('')
      setRenameId('')
      setNewName('')
      setMessage(mode === 'create' ? 'Utworzono kategorię.' : 'Zmieniono nazwę kategorii.')
    } catch (cause) {
      if (controller.signal.aborted) return
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired()
      else if (cause instanceof ApiError && (cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE' || cause.status === 503)) onModuleUnavailable()
      else if (cause instanceof ApiError && cause.status === 403) onAuthorizationDenied()
      else if (writeStarted && (!(cause instanceof ApiError) || cause.status >= 500)) {
        setNeedsRefresh(true)
        setError('Nie można potwierdzić wyniku zapisu. Odśwież i sprawdź listę przed ponowieniem.')
      } else setError(cause instanceof Error ? cause.message : 'Nie można zapisać kategorii.')
    } finally {
      if (!controller.signal.aborted) {
        active.current = null
        setBusy(false)
      }
    }
  }

  return (
    <section className="state-panel" aria-labelledby="category-manager-title">
      <h2 id="category-manager-title">Zarządzanie kategoriami</h2>
      <p>Kategorie należą do bieżącej organizacji. Kod kategorii jest niezmienny.</p>
      {message && <p className="inline-success" role="status">{message}</p>}
      {error && <p className="inline-error" role="alert">{error}</p>}
      {needsRefresh && <button type="button" className="button button-secondary" disabled={busy} onClick={() => void refresh()}>Odśwież kategorie</button>}
      <ul aria-label="Istniejące kategorie">
        {categories.map((category) => <li key={category.id}>{category.code ? `${category.code} — ` : ''}{category.name}</li>)}
      </ul>
      <form onSubmit={(event) => void submit(event, 'create')}>
        <fieldset className="create-form-fieldset" disabled={busy || needsRefresh}>
          <legend>Nowa kategoria</legend>
          <div className="create-form-grid">
            <label className="create-form-field">Kod
              <input value={code} onChange={(event) => setCode(event.target.value)} required maxLength={40} pattern="[A-Z0-9_-]+" />
              <span className="field-hint">Wielkie litery, cyfry, podkreślnik lub myślnik.</span>
            </label>
            <label className="create-form-field">Nazwa
              <input value={name} onChange={(event) => setName(event.target.value)} required maxLength={160} />
            </label>
            <label className="create-form-field">Kategoria nadrzędna
              <select value={parentId} onChange={(event) => setParentId(event.target.value)}>
                <option value="">Brak</option>
                {categories.filter((category) => category.depth !== undefined && category.depth < 3)
                  .map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
              </select>
            </label>
          </div>
          <button type="submit" className="button button-primary">Dodaj kategorię</button>
        </fieldset>
      </form>
      {categories.length > 0 && <form onSubmit={(event) => void submit(event, 'rename')}>
        <fieldset className="create-form-fieldset" disabled={busy || needsRefresh}>
          <legend>Zmiana nazwy</legend>
          <div className="create-form-grid">
            <label className="create-form-field">Kategoria
              <select value={renameId} onChange={(event) => setRenameId(event.target.value)} required>
                <option value="">Wybierz kategorię</option>
                {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
              </select>
            </label>
            <label className="create-form-field">Nowa nazwa
              <input value={newName} onChange={(event) => setNewName(event.target.value)} required maxLength={160} />
            </label>
          </div>
          <button type="submit" className="button button-secondary">Zmień nazwę</button>
        </fieldset>
      </form>}
      <button type="button" className="button button-secondary" disabled={busy} onClick={onClose}>Zamknij zarządzanie kategoriami</button>
    </section>
  )
}
