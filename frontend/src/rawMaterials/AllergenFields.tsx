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

const SOURCE_LABELS: Record<string, string> = {
  EU: 'UE',
  US: 'FDA',
  CODEX: 'Codex',
}

function sourceLabels(sources: string[]): string {
  return sources.map((source) => SOURCE_LABELS[source] ?? source).join(', ')
}

export function AllergenDeclarationRows({
  catalog,
  items,
  disabled,
  invalid,
  onChange,
}: {
  catalog: AllergenCatalog
  items: AllergenDeclaration['items']
  disabled: boolean
  invalid: boolean
  onChange: (items: AllergenDeclaration['items']) => void
}) {
  const [query, setQuery] = useState('')
  const [rows, setRows] = useState<Array<{ key: number; code: string; exposure: 'DIRECT' | 'CROSS_CONTACT_POTENTIAL' }>>([])
  const nextKey = useRef(0)

  useEffect(() => {
    setRows(items.map((item) => ({
      key: nextKey.current++,
      code: item.allergen_code,
      exposure: item.exposure_kind,
    })))
  }, [items])

  const normalizedQuery = query.trim().toLocaleLowerCase('pl-PL')
  const filteredDefinitions = catalog.definitions.filter((definition) =>
    !normalizedQuery || `${definition.code} ${definition.name} ${sourceLabels(definition.sources)}`
      .toLocaleLowerCase('pl-PL').includes(normalizedQuery))

  function commit(nextRows: typeof rows) {
    setRows(nextRows)
    onChange(nextRows.filter((row) => row.code !== '').map((row) => ({
      allergen_code: row.code,
      exposure_kind: row.exposure,
    })))
  }

  return <div className="allergen-declaration-rows">
    <label className="create-form-field">Szukaj alergenu
      <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} />
    </label>
    {rows.map((row, index) => <div className="allergen-declaration-row" key={row.key}>
      <label className="create-form-field">Alergen
        <select value={row.code} disabled={disabled} aria-invalid={invalid && index === 0} onChange={(event) => {
          const code = event.target.value
          if (code && rows.some((item) => item.key !== row.key && item.code === code)) return
          commit(rows.map((item) => item.key === row.key ? { ...item, code } : item))
        }}>
          <option value="">Wybierz alergen</option>
          {filteredDefinitions.map((definition) => <option
            key={definition.code}
            value={definition.code}
            disabled={rows.some((item) => item.key !== row.key && item.code === definition.code)}
          >{definition.name}{definition.sources.length ? ` · ${sourceLabels(definition.sources)}` : ''}</option>)}
          {row.code && !catalog.definitions.some((definition) => definition.code === row.code)
            && <option value={row.code}>{row.code} · zapis historyczny</option>}
        </select>
      </label>
      <label className="create-form-field">Rodzaj deklaracji
        <select value={row.exposure} disabled={disabled || row.code === ''} onChange={(event) => {
          const exposure = event.target.value
          if (exposure === 'DIRECT' || exposure === 'CROSS_CONTACT_POTENTIAL') {
            commit(rows.map((item) => item.key === row.key ? { ...item, exposure } : item))
          }
        }}>
          <option value="DIRECT">ZAWIERA</option>
          <option value="CROSS_CONTACT_POTENTIAL">MOŻE ZAWIERAĆ</option>
        </select>
      </label>
      <button type="button" className="button button-secondary" disabled={disabled}
        onClick={() => commit(rows.filter((item) => item.key !== row.key))}>Usuń wiersz</button>
    </div>)}
    <button type="button" className="button button-secondary" disabled={disabled}
      aria-invalid={invalid && rows.length === 0} onClick={() => {
      setRows((current) => [...current, { key: nextKey.current++, code: '', exposure: 'DIRECT' }])
    }}>+ Dodaj alergen</button>
    {items.map((item) => {
      const definition = catalog.definitions.find((candidate) => candidate.code === item.allergen_code)
      return definition ? <p className="field-hint" key={item.allergen_code}>
        {definition.name}: {sourceLabels(definition.sources) || 'bez powiązań źródłowych'}
      </p> : null
    })}
  </div>
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
  const currentCatalog = catalogs.find((catalog) => catalog.isCurrent) ?? catalogs[0]
  const selected = catalogs.find((catalog) => catalog.id === value.catalog_version_id) ?? currentCatalog
  return <fieldset ref={fieldsetRef} className="create-form-fieldset create-form-grid" disabled={disabled || loading}>
    <legend>Deklaracja alergenów</legend>
    {loading && <p role="status">Wczytywanie katalogu alergenów…</p>}
    {loadError && <p className="inline-error" role="alert">{loadError}</p>}
    {!loading && !loadError && catalogs.length === 0 && <p className="inline-error" role="alert">Brak opublikowanego katalogu alergenów.</p>}
    <label className="create-form-field">Status deklaracji
      <select value={value.status} disabled={Boolean(loadError)}
        aria-invalid={Boolean(error) && (value.status === 'UNKNOWN' || !selected)} onChange={(event) => {
        const status = event.target.value
        if (status === 'UNKNOWN' || status === 'DECLARED' || status === 'NO_KNOWN_ALLERGENS') {
          onChange({
            status,
            catalog_version_id: status === 'UNKNOWN' ? null : currentCatalog?.id ?? null,
            items: [],
          })
        }
      }}>
        <option value="UNKNOWN">Nieznany — brak deklaracji</option>
        <option value="DECLARED">Deklaruję występujące alergeny</option>
        <option value="NO_KNOWN_ALLERGENS">Deklaruję brak znanych alergenów</option>
      </select>
    </label>
    {error && <p className="field-error" role="alert">{error}</p>}
    {value.status !== 'UNKNOWN' && selected && <>
      {value.catalog_version_id !== currentCatalog?.id && <label className="create-form-field">Wersja katalogu alergenów
        <select value={value.catalog_version_id ?? ''} aria-invalid={Boolean(error) && !value.catalog_version_id} onChange={(event) =>
          onChange({ ...value, catalog_version_id: event.target.value || null, items: [] })}>
          {catalogs.map((catalog) => <option key={catalog.id} value={catalog.id}>Katalog · wersja {catalog.versionNumber}</option>)}
        </select>
      </label>}
      {value.status === 'DECLARED' && <AllergenDeclarationRows
        catalog={selected}
        items={value.items}
        disabled={disabled}
        invalid={Boolean(error) && value.items.length === 0}
        onChange={(items) => onChange({ ...value, items })}
      />}
    </>}
    {loadError && <button type="button" className="button button-secondary" disabled={disabled} onClick={() => {
      setLoading(true)
      setLoadError(null)
      setRetry((n) => n + 1)
    }}>Spróbuj ponownie</button>}
  </fieldset>
}
