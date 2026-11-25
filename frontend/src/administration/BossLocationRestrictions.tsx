import { useEffect, useId, useState } from 'react'
import { ApiError, isRecord, request } from '../api'
import type { UserLocation } from './usersApi'

const categories = [
  { key: 'RAW_SUPPLIERS', label: 'Surowce i dostawcy' },
  { key: 'PURCHASE_FINANCIALS', label: 'Ceny zakupu i informacje finansowe' },
  { key: 'FOODCOST', label: 'Koszty receptur' },
  { key: 'PRODUCTS_RECIPES', label: 'Produkty i receptury' },
  { key: 'PRODUCTION', label: 'Produkcja' },
  { key: 'WAREHOUSE_INVENTORY', label: 'Magazyny i zapasy' },
  { key: 'ORDERS', label: 'Zamówienia' },
  { key: 'REPORTS_ANALYTICS', label: 'Raporty i zestawienia' },
] as const

type Category = typeof categories[number]['key']
type CategoryLocation = { category: Category; location_id: string }
type Snapshot = {
  version: number
  excluded_location_ids: string[]
  excluded_categories: Category[]
  excluded_category_locations: CategoryLocation[]
}

async function load(path: string, method: 'GET' | 'PUT', snapshot?: Snapshot, signal?: AbortSignal): Promise<Snapshot> {
  const body = snapshot ? { policy_schema_version: 2, ...snapshot } : undefined
  const data = await request(path, method, body, { signal })
  if (!isRecord(data) || typeof data.version !== 'number' || !Number.isSafeInteger(data.version) || data.version < 0
    || !Array.isArray(data.excluded_location_ids) || !data.excluded_location_ids.every((id): id is string => typeof id === 'string')
    || !Array.isArray(data.excluded_categories) || !data.excluded_categories.every((category): category is Category => categories.some((item) => item.key === category))
    || !Array.isArray(data.excluded_category_locations) || !data.excluded_category_locations.every((pair) =>
      isRecord(pair) && categories.some((item) => item.key === pair.category) && typeof pair.location_id === 'string')) {
    throw new Error('Nie udało się odczytać bieżących ograniczeń.')
  }
  return {
    version: data.version,
    excluded_location_ids: data.excluded_location_ids,
    excluded_categories: data.excluded_categories,
    excluded_category_locations: data.excluded_category_locations as CategoryLocation[],
  }
}

export default function BossLocationRestrictions({
  userId, locations, onSaved, canManage,
}: { userId: string; locations: UserLocation[]; onSaved: () => void; canManage: boolean }) {
  const selectId = useId()
  const path = `/api/v1/administration/users/${encodeURIComponent(userId)}/boss-location-restrictions`
  const [snapshot, setSnapshot] = useState<Snapshot | null>(null)
  const [draft, setDraft] = useState<string[]>([])
  const [draftCategories, setDraftCategories] = useState<Category[]>([])
  const [draftPairs, setDraftPairs] = useState<CategoryLocation[]>([])
  const [selected, setSelected] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<Category>(categories[0].key)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [reload, setReload] = useState(0)
  const location = locations.find(({ id }) => id === selected) ?? locations[0]
  const changed = snapshot && (draft.length !== snapshot.excluded_location_ids.length
    || draft.some((id) => !snapshot.excluded_location_ids.includes(id))
    || draftCategories.length !== snapshot.excluded_categories.length
    || draftCategories.some((category) => !snapshot.excluded_categories.includes(category))
    || draftPairs.length !== snapshot.excluded_category_locations.length
    || draftPairs.some((pair) => !snapshot.excluded_category_locations.some((savedPair) =>
      savedPair.category === pair.category && savedPair.location_id === pair.location_id)))

  useEffect(() => {
    const controller = new AbortController()
    void load(path, 'GET', undefined, controller.signal).then((result) => {
      if (controller.signal.aborted) return
      setSnapshot(result)
      setDraft(result.excluded_location_ids)
      setDraftCategories(result.excluded_categories)
      setDraftPairs(result.excluded_category_locations)
    }).catch((failure: unknown) => {
      if (!controller.signal.aborted) setError(failure instanceof Error ? failure.message : 'Nie udało się wczytać ograniczeń.')
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [path, reload])

  async function save() {
    if (!snapshot || !changed || saving) return
    setSaving(true)
    setError('')
    setSaved(false)
    try {
      const result = await load(path, 'PUT', {
        version: snapshot.version,
        excluded_location_ids: draft,
        excluded_categories: draftCategories,
        excluded_category_locations: draftPairs,
      })
      setSnapshot(result)
      setDraft(result.excluded_location_ids)
      setDraftCategories(result.excluded_categories)
      setDraftPairs(result.excluded_category_locations)
      setSaved(true)
      onSaved()
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 409) {
        setSnapshot(null)
        setError('Inny administrator zmienił ograniczenia. Wczytaj bieżący stan przed zapisaniem zmian.')
      } else {
        setError(failure instanceof Error ? failure.message : 'Nie udało się zapisać ograniczeń.')
      }
    } finally {
      setSaving(false)
    }
  }

  return <div className="admin-user-inline-form">
    <p>Zaznaczenie ogranicza dostęp BOSS, także gdy istnieje dodatkowe uprawnienie. Ceny zakupu i koszty receptur można ograniczać niezależnie.</p>
    {!canManage && snapshot && <p role="status">Zapisane ograniczenia są zachowane. Zadziałają ponownie, jeśli administrator nada tej osobie rolę BOSS.</p>}
    <section aria-label="Rodzaje informacji">
      <h4>Rodzaje informacji</h4>
      <ul>{categories.map((category) => {
        const checked = draftCategories.includes(category.key)
        const id = `${selectId}-${category.key}`
        return <li key={category.key}>
          <input id={id} type="checkbox" checked={checked} disabled={saving || !canManage}
            onChange={(event) => {
              const next = event.target.checked
              setSaved(false)
              setDraftCategories((current) => next
                ? [...current, category.key]
                : current.filter((value) => value !== category.key))
            }} />
          {' '}<label htmlFor={id}>{category.label}</label>
          <small>{checked ? 'Ograniczenie wybrane.' : 'Bez ograniczenia tej kategorii.'}</small>
        </li>
      })}</ul>
    </section>
    <section aria-label="Lokalizacje">
      <h4>Lokalizacje</h4>
      {loading && <p role="status">Wczytywanie ograniczeń…</p>}
      {error && <p role="alert">{error}</p>}
      {saved && <p role="status">Zapisano ograniczenia.</p>}
      {!loading && !snapshot && <button type="button" className="button button-secondary" onClick={() => {
        setError('')
        setLoading(true)
        setReload((value) => value + 1)
      }}>Wczytaj bieżący stan</button>}
      {snapshot && !location && <p>Brak lokalizacji.</p>}
      {snapshot && location && <form className="admin-user-inline-form" onSubmit={(event) => {
        event.preventDefault()
        void save()
      }}>
        <label htmlFor={selectId}>Wybierz lokalizację</label>
        <select id={selectId} value={location.id} disabled={saving || !canManage} onChange={(event) => setSelected(event.target.value)}>
          <optgroup label="Kuchnie centralne">{locations.filter((item) => item.type === 'CENTRAL_KITCHEN').map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</optgroup>
          <optgroup label="Restauracje">{locations.filter((item) => item.type === 'RESTAURANT').map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</optgroup>
        </select>
        <p>Stan zapisany: {snapshot.excluded_location_ids.includes(location.id) ? 'Ograniczenie BOSS zapisane' : 'Brak ograniczenia BOSS; nie potwierdza to innych uprawnień'}</p>
        <div>
          <input id={`${selectId}-exclude`} type="checkbox" checked={draft.includes(location.id)} disabled={saving || !canManage}
            onChange={(event) => {
              const checked = event.target.checked
              setSaved(false)
              setDraft((ids) => checked ? [...ids, location.id] : ids.filter((id) => id !== location.id))
            }} />
          {' '}<label htmlFor={`${selectId}-exclude`}>Wyłącz dostęp z roli BOSS do wybranej lokalizacji</label>
        </div>
        <section aria-label="Ograniczenia szczegółowe">
          <h4>Ograniczenia szczegółowe</h4>
          <label htmlFor={`${selectId}-category`}>Rodzaj informacji</label>
          <select id={`${selectId}-category`} value={selectedCategory} disabled={saving || !canManage}
            onChange={(event) => setSelectedCategory(event.target.value as Category)}>
            {categories.map((category) => <option key={category.key} value={category.key}>{category.label}</option>)}
          </select>
          <input id={`${selectId}-pair`} type="checkbox" checked={draftPairs.some((pair) =>
            pair.category === selectedCategory && pair.location_id === location.id)} disabled={saving || !canManage}
            onChange={(event) => {
              const checked = event.target.checked
              setSaved(false)
              setDraftPairs((current) => checked
                ? [...current, { category: selectedCategory, location_id: location.id }]
                : current.filter((pair) => pair.category !== selectedCategory || pair.location_id !== location.id))
            }} />
          {' '}<label htmlFor={`${selectId}-pair`}>Ogranicz tę kategorię w wybranej lokalizacji</label>
          <p>Ograniczenie szczegółowe nie przywraca dostępu wyłączonego globalnie.</p>
        </section>
        <button type="submit" className="button button-primary" disabled={!changed || saving || !canManage}>Zapisz ograniczenia</button>
      </form>}
    </section>
  </div>
}
