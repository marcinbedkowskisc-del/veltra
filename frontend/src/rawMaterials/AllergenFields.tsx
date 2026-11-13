import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { fetchAllergenCatalogs, type AllergenCatalog, type AllergenDeclaration } from './api'

type Props = {
  value: AllergenDeclaration
  onChange: (value: AllergenDeclaration) => void
  disabled: boolean
  permitted: boolean
  error: string | null
  onSessionExpired: () => void
  onModuleUnavailable: () => void
}

export default function AllergenFields({ value, onChange, disabled, permitted, error, onSessionExpired, onModuleUnavailable }: Props) {
  const [catalogs, setCatalogs] = useState<AllergenCatalog[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [retry, setRetry] = useState(0)
  const fieldsetRef = useRef<HTMLFieldSetElement>(null)

  useEffect(() => {
    if (!permitted) return
    const controller = new AbortController()
    void fetchAllergenCatalogs(controller.signal).then((result) => {
      if (!controller.signal.aborted) setCatalogs(result)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted) return
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired()
      else if (cause instanceof ApiError && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') onModuleUnavailable()
      else setLoadError(cause instanceof Error ? cause.message : 'Nie udało się pobrać katalogu alergenów.')
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [permitted, retry, onSessionExpired, onModuleUnavailable])

  useEffect(() => {
    if (!loading && !disabled && error) fieldsetRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
  }, [loading, disabled, error])

  if (!permitted) return <p>Brak uprawnienia do zmiany deklaracji alergenów.</p>
  const selected = catalogs.find((catalog) => catalog.id === value.catalog_version_id)
  return <fieldset ref={fieldsetRef} className="create-form-fieldset create-form-grid" disabled={disabled || loading}>
    <legend>Deklaracja alergenów</legend>
    {loading && <p role="status">Wczytywanie katalogu alergenów…</p>}
    {loadError && <p className="inline-error" role="alert">{loadError}</p>}
    {!loading && !loadError && catalogs.length === 0 && <p className="inline-error" role="alert">Brak opublikowanego katalogu alergenów.</p>}
    <label className="create-form-field">Status deklaracji
      <select value={value.status} disabled={Boolean(loadError)} aria-invalid={Boolean(error) && value.status === 'UNKNOWN'} onChange={(event) => {
        const status = event.target.value
        if (status === 'UNKNOWN' || status === 'DECLARED' || status === 'NO_KNOWN_ALLERGENS') {
          onChange({ status, catalog_version_id: null, items: [] })
        }
      }}>
        <option value="UNKNOWN">Nieznany — brak deklaracji</option>
        <option value="DECLARED">Deklaruję występujące alergeny</option>
        <option value="NO_KNOWN_ALLERGENS">Deklaruję brak znanych alergenów</option>
      </select>
    </label>
    {error && <p className="field-error" role="alert">{error}</p>}
    {value.status !== 'UNKNOWN' && <>
      <label className="create-form-field">Wersja katalogu alergenów
        <select value={value.catalog_version_id ?? ''} aria-invalid={Boolean(error) && !value.catalog_version_id} onChange={(event) =>
          onChange({ ...value, catalog_version_id: event.target.value || null, items: [] })}>
          <option value="">Wybierz wersję</option>
          {catalogs.map((catalog) => <option key={catalog.id} value={catalog.id}>Katalog · wersja {catalog.versionNumber}</option>)}
        </select>
      </label>
      {value.status === 'DECLARED' && selected?.definitions.map((definition) => {
        const item = value.items.find((candidate) => candidate.allergen_code === definition.code)
        return <label key={definition.code} className="create-form-field">{definition.name}
          <select value={item?.exposure_kind ?? ''} aria-invalid={Boolean(error) && value.catalog_version_id !== null && value.items.length === 0} onChange={(event) => {
            const items = value.items.filter((candidate) => candidate.allergen_code !== definition.code)
            const exposure = event.target.value
            if (exposure === 'DIRECT' || exposure === 'CROSS_CONTACT_POTENTIAL') {
              items.push({ allergen_code: definition.code, exposure_kind: exposure })
            }
            onChange({ ...value, items })
          }}>
            <option value="">Nie deklaruję</option>
            <option value="DIRECT">Występuje bezpośrednio</option>
            <option value="CROSS_CONTACT_POTENTIAL">Możliwy kontakt krzyżowy</option>
          </select>
        </label>
      })}
    </>}
    {loadError && <button type="button" className="button button-secondary" disabled={disabled} onClick={() => {
      setLoading(true)
      setLoadError(null)
      setRetry((n) => n + 1)
    }}>Spróbuj ponownie</button>}
  </fieldset>
}
