import { useEffect, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import {
  archiveAdministrationUnit,
  createAdministrationUnit,
  fetchAdministrationUnits,
  restoreAdministrationUnit,
  updateAdministrationUnit,
  type AdministrationUnit,
  type UnitDimension,
} from './unitsApi'

type UnitForm = {
  unit: AdministrationUnit | null
  code: string
  name: string
  symbol: string
  dimension: UnitDimension
  baseUnitCode: string
  toBaseFactor: string
}

const DIMENSIONS: Array<{ value: UnitDimension; label: string }> = [
  { value: 'MASS', label: 'Masa' },
  { value: 'VOLUME', label: 'Objętość' },
  { value: 'COUNT', label: 'Sztuki' },
]

function errorText(cause: unknown): string {
  if (cause instanceof ApiError) return Object.values(cause.errors).flat()[0] ?? cause.message
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.'
}

function unitForm(unit: AdministrationUnit | null): UnitForm {
  return unit ? {
    unit, code: unit.code, name: unit.name, symbol: unit.symbol, dimension: unit.dimension,
    baseUnitCode: unit.baseUnitCode, toBaseFactor: unit.toBaseFactor,
  } : {
    unit: null, code: '', name: '', symbol: '', dimension: 'MASS', baseUnitCode: '', toBaseFactor: '',
  }
}

export default function UnitManagementPage() {
  const [units, setUnits] = useState<AdministrationUnit[]>([])
  const [baseUnits, setBaseUnits] = useState<AdministrationUnit[]>([])
  const [status, setStatus] = useState<'active' | 'archived' | 'all'>('active')
  const [dimension, setDimension] = useState<UnitDimension | ''>('')
  const [searchText, setSearchText] = useState('')
  const [search, setSearch] = useState('')
  const [form, setForm] = useState<UnitForm | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    void Promise.all([
      fetchAdministrationUnits({ status, search, dimension: dimension || undefined }, controller.signal),
      fetchAdministrationUnits({ status: 'active' }, controller.signal),
    ]).then(([result, activeUnits]) => {
        if (!controller.signal.aborted) {
          setUnits(result)
          setBaseUnits(activeUnits)
          setError(null)
        }
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) setError(errorText(cause))
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [status, search, dimension, reload])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!form) return
    setBusy(true)
    setFormError(null)
    setSuccess(null)
    try {
      const input = {
        name: form.name.trim(),
        symbol: form.symbol.trim(),
        dimension: form.dimension,
        base_unit_code: form.baseUnitCode,
        to_base_factor: form.toBaseFactor.trim().replace(',', '.'),
      }
      if (form.unit) await updateAdministrationUnit(form.unit.id, input)
      else await createAdministrationUnit({ ...input, code: form.code.trim() })
      setForm(null)
      setSuccess(form.unit ? 'Zapisano zmiany jednostki.' : 'Dodano jednostkę.')
      setLoading(true)
      setReload((value) => value + 1)
    } catch (cause) {
      setFormError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  async function changeStatus(unit: AdministrationUnit, action: 'archive' | 'restore') {
    setBusy(true)
    setError(null)
    setSuccess(null)
    try {
      if (action === 'archive') await archiveAdministrationUnit(unit.id)
      else await restoreAdministrationUnit(unit.id)
      setSuccess(action === 'archive' ? 'Zarchiwizowano jednostkę.' : 'Przywrócono jednostkę.')
      setLoading(true)
      setReload((value) => value + 1)
    } catch (cause) {
      setError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  const bases = baseUnits.filter((unit) => unit.isBase && unit.archivedAt === null)
  const formBases = bases.filter((unit) => unit.dimension === form?.dimension)

  return (
    <section className="admin-unit-page" aria-labelledby="admin-unit-title">
      <header className="admin-category-header">
        <div>
          <h2 id="admin-unit-title">Jednostki miary i przeliczniki</h2>
          <p>Wspólny słownik jednostek używany w panelu administracyjnym i RAW. Zmiany definicji nie przeliczają danych historycznych.</p>
        </div>
        <button type="button" className="button button-primary" disabled={busy || loading}
          onClick={() => { setForm(unitForm(null)); setFormError(null) }}>Dodaj jednostkę</button>
      </header>

      <form className="admin-category-filters" onSubmit={(event) => {
        event.preventDefault()
        const nextSearch = searchText.trim()
        if (nextSearch !== search) setLoading(true)
        setSearch(nextSearch)
      }}>
        <label className="create-form-field">Szukaj jednostki
          <input value={searchText} onChange={(event) => setSearchText(event.target.value)} />
        </label>
        <label className="create-form-field">Wymiar
          <select value={dimension} onChange={(event) => {
            setLoading(true)
            setDimension(event.target.value as UnitDimension | '')
          }}>
            <option value="">Wszystkie wymiary</option>
            {DIMENSIONS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
        </label>
        <label className="create-form-field">Status
          <select value={status} onChange={(event) => {
            setLoading(true)
            setStatus(event.target.value as typeof status)
          }}>
            <option value="active">Aktywne</option><option value="archived">Archiwalne</option><option value="all">Wszystkie</option>
          </select>
        </label>
        <button type="submit" className="button button-secondary">Szukaj</button>
      </form>

      {success && <p className="notice notice-success" role="status">{success}</p>}
      {error && <p className="inline-error" role="alert">{error}</p>}
      {loading ? <p role="status">Ładowanie słownika jednostek…</p> : units.length === 0
        ? <p>Brak jednostek spełniających wybrane kryteria.</p>
        : DIMENSIONS.filter((item) => !dimension || item.value === dimension).map((group) => {
          const items = units.filter((unit) => unit.dimension === group.value)
          if (!items.length) return null
          return (
            <section key={group.value} className="admin-unit-group" aria-labelledby={`unit-group-${group.value}`}>
              <h3 id={`unit-group-${group.value}`}>{group.label}</h3>
              <div className="admin-unit-list">
                {items.map((unit) => (
                  <article className="admin-unit-card" key={unit.id}>
                    <header><h4>{unit.name} <span>({unit.symbol})</span></h4>
                      <span>{unit.archivedAt ? 'Archiwalna' : 'Aktywna'}{unit.isBase ? ' · jednostka bazowa' : ''}</span></header>
                    <dl className="admin-category-details">
                      <div><dt>Kod</dt><dd>{unit.code}</dd></div>
                      <div><dt>Wymiar</dt><dd>{group.label}</dd></div>
                      <div><dt>Jednostka bazowa</dt><dd>{bases.find((base) => base.code === unit.baseUnitCode)?.name ?? unit.baseUnitCode}</dd></div>
                      <div><dt>Współczynnik do bazy</dt><dd>{unit.toBaseFactor}</dd></div>
                      <div><dt>Powiązania historyczne</dt><dd>{unit.referenceCount}</dd></div>
                    </dl>
                    <p className="field-hint">Przeliczenia w tym wymiarze są wyliczane z jednostek bazowych; masa, objętość i sztuki nie są konwertowane globalnie.</p>
                    <div className="admin-category-actions">
                      {!unit.archivedAt && <button type="button" className="button button-secondary" disabled={busy}
                        onClick={() => { setForm(unitForm(unit)); setFormError(null) }}>Edytuj</button>}
                      {unit.archivedAt
                        ? <button type="button" className="button button-secondary" disabled={busy} onClick={() => void changeStatus(unit, 'restore')}>Przywróć</button>
                        : <button type="button" className="button button-secondary" disabled={busy || unit.isBase}
                          onClick={() => void changeStatus(unit, 'archive')}>Archiwizuj</button>}
                    </div>
                    {unit.conversions.length > 0 && <ul aria-label={`Przeliczenia jednostki ${unit.name}`}>
                      {unit.conversions.map((conversion) => <li key={conversion.unitCode}>
                        1 {unit.symbol} = {conversion.factor} {conversion.symbol} ({conversion.name})
                        {conversion.archivedAt ? ' · archiwalna' : ''}
                      </li>)}
                    </ul>}
                  </article>
                ))}
              </div>
            </section>
          )
        })}

      {form && <form className="admin-category-form" onSubmit={(event) => void submit(event)} aria-label={form.unit ? 'Edycja jednostki' : 'Nowa jednostka'}>
        <h3>{form.unit ? `Edytuj: ${form.unit.name}` : 'Nowa jednostka'}</h3>
        {!form.unit && <label className="create-form-field">Kod jednostki
          <input required maxLength={24} pattern="[a-z][a-z0-9_]{0,23}" value={form.code}
            onChange={(event) => setForm({ ...form, code: event.target.value })} />
        </label>}
        <label className="create-form-field">Nazwa
          <input required maxLength={100} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
        </label>
        <label className="create-form-field">Symbol
          <input required maxLength={24} value={form.symbol} onChange={(event) => setForm({ ...form, symbol: event.target.value })} />
        </label>
        <label className="create-form-field">Wymiar
          <select disabled={Boolean(form.unit?.definitionLocked)} value={form.dimension}
            onChange={(event) => setForm({ ...form, dimension: event.target.value as UnitDimension, baseUnitCode: '' })}>
            {DIMENSIONS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
        </label>
        <label className="create-form-field">Jednostka bazowa
          <select required disabled={Boolean(form.unit?.definitionLocked)} value={form.baseUnitCode}
            onChange={(event) => setForm({ ...form, baseUnitCode: event.target.value, toBaseFactor: event.target.value === form.code ? '1' : form.toBaseFactor })}>
            <option value="">Wybierz jednostkę bazową</option>
            {formBases.map((base) => <option key={base.code} value={base.code}>{base.name} ({base.symbol})</option>)}
          </select>
        </label>
        <label className="create-form-field">Współczynnik do jednostki bazowej
          <input required inputMode="decimal" pattern="[0-9]+([.,][0-9]{1,12})?" value={form.toBaseFactor}
            disabled={Boolean(form.unit?.definitionLocked) || form.code === form.baseUnitCode}
            onChange={(event) => setForm({ ...form, toBaseFactor: event.target.value })} />
        </label>
        {form.unit?.definitionLocked && <p className="field-hint">Definicja wymiaru i przelicznika jest zablokowana, ponieważ istnieją odwołania historyczne.</p>}
        {formError && <p className="inline-error" role="alert">{formError}</p>}
        <div className="admin-category-actions">
          <button type="submit" className="button button-primary" disabled={busy || !bases.some((base) => base.code === form.baseUnitCode)}>Zapisz</button>
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => setForm(null)}>Anuluj</button>
        </div>
      </form>}
    </section>
  )
}
