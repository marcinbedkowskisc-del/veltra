import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { AllergenDeclarationRows } from './AllergenFields'
import {
  changeRawMaterialLifecycle,
  fetchAllergenCatalogs,
  fetchAllergenDeclaration,
  fetchRawMaterialCompletion,
  saveAllergenDeclaration,
  type AllergenCatalog,
  type AllergenDeclaration,
  type RawMaterialCompletion,
} from './api'

type Props = {
  materialId: string
  refreshKey: number
  onSaved: () => void
  onSessionExpired: () => void
  onModuleUnavailable: () => void
}

export default function RawMaterialWorkflow({ materialId, refreshKey, onSaved, onSessionExpired, onModuleUnavailable }: Props) {
  const [completion, setCompletion] = useState<RawMaterialCompletion | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [validationErrors, setValidationErrors] = useState<string[]>([])
  const [reload, setReload] = useState(0)
  const [confirmation, setConfirmation] = useState<'submit' | 'approve' | null>(null)
  const [reason, setReason] = useState('')
  const [catalogs, setCatalogs] = useState<AllergenCatalog[]>([])
  const [allergens, setAllergens] = useState<{ version: string; declaration: AllergenDeclaration } | null>(null)
  const context = useRef<AbortController | null>(null)
  const locked = useRef(false)
  const section = useRef<HTMLElement | null>(null)
  const focusForm = useCallback((form: HTMLFormElement | null) => {
    form?.querySelector<HTMLElement>('select, textarea, button')?.focus()
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    context.current = controller
    locked.current = false
    void fetchRawMaterialCompletion(materialId, controller.signal).then((report) => {
      if (!controller.signal.aborted) setCompletion(report)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired()
      else if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') onModuleUnavailable()
      else setError(cause instanceof Error ? cause.message : 'Nie udało się sprawdzić dostępnych działań.')
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [materialId, refreshKey, reload, onSessionExpired, onModuleUnavailable])

  async function perform(operation: () => Promise<void>) {
    const signal = context.current?.signal
    if (!signal || signal.aborted || locked.current) return
    locked.current = true
    setBusy(true)
    setError(null)
    setValidationErrors([])
    try {
      await operation()
    } catch (cause: unknown) {
      if (signal.aborted) return
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired()
      else if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') onModuleUnavailable()
      else {
        setError(cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.')
        if (cause instanceof ApiError) setValidationErrors([...new Set(Object.values(cause.errors).flat())])
        if (!(cause instanceof ApiError && cause.status === 422)) {
          setCompletion(null)
          setAllergens(null)
          setConfirmation(null)
        }
      }
    } finally {
      if (!signal.aborted) {
        locked.current = false
        setBusy(false)
      }
    }
  }

  async function openAllergens() {
    const signal = context.current?.signal
    await perform(async () => {
      const [report, editor, versions] = await Promise.all([
        fetchRawMaterialCompletion(materialId, signal),
        fetchAllergenDeclaration(materialId, signal),
        fetchAllergenCatalogs(signal),
      ])
      if (signal?.aborted) return
      if (!report.availableActions.includes('edit_allergens') || editor.version !== report.version) {
        throw new Error('Uprawnienia lub wersja rewizji zmieniły się. Wczytaj aktualny stan.')
      }
      setCompletion(report)
      setCatalogs(versions)
      setAllergens(editor)
      setConfirmation(null)
    })
  }

  function saved(signal: AbortSignal | undefined) {
    if (signal?.aborted) return
    setAllergens(null)
    setConfirmation(null)
    section.current?.focus()
    onSaved()
  }

  const missing = completion?.missingRequirements ?? []
  const currentCatalog = catalogs.find((catalog) => catalog.isCurrent) ?? catalogs[0]
  const selectedCatalog = catalogs.find((catalog) => catalog.id === allergens?.declaration.catalog_version_id) ?? currentCatalog

  return (
    <section ref={section} tabIndex={-1} aria-label="Cykl zatwierdzania RAW" className="detail-section" aria-busy={loading || busy}>
      <h3>Praca nad rewizją</h3>
      {loading && <p role="status">Sprawdzanie dostępnych działań…</p>}
      {error && <p className="inline-error" role="alert">{error}</p>}
      {validationErrors.length > 0 && <ul className="inline-error" role="alert">
        {validationErrors.map((message) => <li key={message}>{message}</li>)}
      </ul>}
      {!loading && !completion && <button type="button" className="button button-secondary" onClick={() => {
        setLoading(true)
        setError(null)
        setValidationErrors([])
        setReload((value) => value + 1)
      }}>
        Wczytaj aktualny stan
      </button>}
      {missing.length > 0 && <div className="create-form-notice" role="status">
        <p>Do zatwierdzenia wymagane jest uzupełnienie danych:</p>
        <ul>{missing.map((problem) => <li key={problem.code}>{problem.message}</li>)}</ul>
        <p>Uzupełnij dane przed przekazaniem. Oczekującej rewizji nie można edytować; powrót do szkicu wymaga odrębnego uprawnienia.</p>
      </div>}
      {completion && !confirmation && !allergens && <div className="create-form-actions">
        {completion.availableActions.includes('edit_allergens') && <button type="button" className="button button-secondary" disabled={busy}
          onClick={() => void openAllergens()}>Deklaracja alergenów</button>}
        {completion.version && completion.availableActions.includes('submit') && <button type="button" className="button button-primary" disabled={busy}
          onClick={() => setConfirmation('submit')}>Przekaż do zatwierdzenia</button>}
        {completion.version && completion.availableActions.includes('approve') && <button type="button" className="button button-primary" disabled={busy}
          onClick={() => { setReason(''); setConfirmation('approve') }}>Zatwierdź rewizję</button>}
      </div>}
      {confirmation && completion?.version && <form ref={focusForm} className="create-form-notice" onSubmit={(event) => {
        event.preventDefault()
        const signal = context.current?.signal
        const version = completion.version
        const action = confirmation
        if (!version) return
        void perform(async () => {
          await changeRawMaterialLifecycle(materialId, action, version, reason.trim())
          saved(signal)
        })
      }}>
        <p>{confirmation === 'submit'
          ? 'Przekazać tę rewizję do zatwierdzenia? Zapis nie zatwierdzi jej automatycznie.'
          : 'Zatwierdzić tę rewizję i udostępnić ją w katalogu zatwierdzonych?'}</p>
        {confirmation === 'approve' && <label className="create-form-field">Uzasadnienie zatwierdzenia
          <textarea required maxLength={1000} value={reason} disabled={busy} onChange={(event) => setReason(event.target.value)} />
        </label>}
        <div className="create-form-actions">
          <button type="submit" className="button button-primary" disabled={busy || confirmation === 'approve' && !reason.trim()}>
            {confirmation === 'submit' ? 'Potwierdź przekazanie' : 'Potwierdź zatwierdzenie'}
          </button>
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => {
            section.current?.focus()
            setConfirmation(null)
          }}>Anuluj</button>
        </div>
      </form>}
      {allergens && <form ref={focusForm} className="create-form-fieldset create-form-grid" aria-label="Deklaracja alergenów" onSubmit={(event) => {
        event.preventDefault()
        const signal = context.current?.signal
        void perform(async () => {
          await saveAllergenDeclaration(materialId, allergens.declaration, allergens.version)
          saved(signal)
        })
      }}>
        <h4 className="create-form-field-wide">Deklaracja alergenów</h4>
        {catalogs.length === 0 && <p className="inline-error create-form-field-wide" role="alert">Brak opublikowanego katalogu alergenów. Zatwierdzanie wymaga przygotowania katalogu przez administratora instalacji.</p>}
        <label className="create-form-field">Status deklaracji
          <select value={allergens.declaration.status} disabled={busy} onChange={(event) => {
            const status = event.target.value
            if (status !== 'UNKNOWN' && status !== 'DECLARED' && status !== 'NO_KNOWN_ALLERGENS') return
            setAllergens({ ...allergens, declaration: {
              status,
              catalog_version_id: status === 'UNKNOWN' ? null : currentCatalog?.id ?? null,
              items: [],
            } })
          }}>
            <option value="UNKNOWN">Nieznany — brak deklaracji</option>
            <option value="DECLARED">Deklaruję występujące alergeny</option>
            <option value="NO_KNOWN_ALLERGENS">Deklaruję brak znanych alergenów</option>
          </select>
        </label>
        {allergens.declaration.status !== 'UNKNOWN' && selectedCatalog && <>
          {allergens.declaration.catalog_version_id !== currentCatalog?.id && <label className="create-form-field">Wersja katalogu alergenów
            <select required disabled={busy} value={allergens.declaration.catalog_version_id ?? ''} onChange={(event) =>
              setAllergens({ ...allergens, declaration: { ...allergens.declaration, catalog_version_id: event.target.value || null, items: [] } })}>
              {catalogs.map((catalog) => <option key={catalog.id} value={catalog.id}>Katalog · wersja {catalog.versionNumber}</option>)}
            </select>
          </label>}
          {allergens.declaration.status === 'DECLARED' && <AllergenDeclarationRows
            catalog={selectedCatalog}
            items={allergens.declaration.items}
            disabled={busy}
            invalid={false}
            onChange={(items) => setAllergens({ ...allergens, declaration: { ...allergens.declaration, items } })}
          />}
        </>}
        <div className="create-form-actions create-form-field-wide">
          <button type="submit" className="button button-primary" disabled={busy
            || allergens.declaration.status !== 'UNKNOWN' && !selectedCatalog
            || allergens.declaration.status === 'DECLARED' && allergens.declaration.items.length === 0}>Zapisz deklarację</button>
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => {
            section.current?.focus()
            setAllergens(null)
          }}>Anuluj</button>
        </div>
      </form>}
    </section>
  )
}
