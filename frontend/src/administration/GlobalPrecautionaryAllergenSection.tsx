import { useEffect, useLayoutEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import AnimatedPanel from '../design-system/AnimatedPanel'
import { allergenDisplayName } from './allergenDisplayNames'
import {
  addGlobalPrecautionaryAllergen,
  fetchGlobalPrecautionaryAllergens,
  removeGlobalPrecautionaryAllergen,
  type CanonicalAllergen,
  type GlobalPrecautionaryAllergen,
} from './allergensApi'

function errorText(cause: unknown): string {
  if (cause instanceof ApiError) return Object.values(cause.errors).flat()[0] ?? cause.message
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.'
}

export default function GlobalPrecautionaryAllergenSection({
  canonical,
}: {
  canonical: CanonicalAllergen[]
}) {
  const [allergens, setAllergens] = useState<GlobalPrecautionaryAllergen[]>([])
  const [loading, setLoading] = useState(true)
  const [loaded, setLoaded] = useState(false)
  const [busy, setBusy] = useState(false)
  const [formOpen, setFormOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [selectedId, setSelectedId] = useState('')
  const [openMenuId, setOpenMenuId] = useState<string | null>(null)
  const [menuPlacement, setMenuPlacement] = useState<'top' | 'bottom'>('bottom')
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const menuButtonRef = useRef<HTMLButtonElement | null>(null)
  const menuRef = useRef<HTMLDivElement | null>(null)

  useLayoutEffect(() => {
    if (!openMenuId || !menuButtonRef.current || !menuRef.current) return
    const trigger = menuButtonRef.current.getBoundingClientRect()
    const menuHeight = menuRef.current.getBoundingClientRect().height
    const spaceAbove = trigger.top
    const spaceBelow = window.innerHeight - trigger.bottom
    setMenuPlacement(spaceBelow >= menuHeight || spaceBelow >= spaceAbove ? 'bottom' : 'top')
  }, [openMenuId])

  useEffect(() => {
    let active = true
    void fetchGlobalPrecautionaryAllergens()
      .then((result) => {
        if (active) {
          setAllergens(result)
          setLoaded(true)
          setError(null)
        }
      })
      .catch((cause: unknown) => {
        if (active) setError(errorText(cause))
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [])

  useEffect(() => {
    if (!openMenuId) return
    menuRef.current?.querySelector<HTMLButtonElement>('[role="menuitem"]')?.focus()

    function closeMenu(event: KeyboardEvent) {
      if (event.key !== 'Escape') return
      menuButtonRef.current?.focus()
      setOpenMenuId(null)
    }

    function closeOnOutsideClick(event: PointerEvent) {
      const target = event.target
      if (!(target instanceof Node) || menuRef.current?.contains(target) || menuButtonRef.current?.contains(target)) return
      setOpenMenuId(null)
    }

    document.addEventListener('keydown', closeMenu)
    document.addEventListener('pointerdown', closeOnOutsideClick)
    return () => {
      document.removeEventListener('keydown', closeMenu)
      document.removeEventListener('pointerdown', closeOnOutsideClick)
    }
  }, [openMenuId])

  const listedIds = useMemo(() => new Set(allergens.map((item) => item.allergenId)), [allergens])
  const options = useMemo(() => {
    const query = search.trim().toLocaleLowerCase('pl-PL')
    return canonical.filter((item) => item.recordStatus === 'ACTIVE' && !listedIds.has(item.id)
      && `${allergenDisplayName(item.id, item.canonicalPl)} ${item.canonicalPl} ${item.synonymsPl.join(' ')}`
        .toLocaleLowerCase('pl-PL').includes(query))
      .sort((left, right) => allergenDisplayName(left.id, left.canonicalPl)
        .localeCompare(allergenDisplayName(right.id, right.canonicalPl), 'pl-PL'))
  }, [canonical, listedIds, search])

  async function addAllergen(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedId) return
    setBusy(true)
    setError(null)
    setSuccess(null)
    try {
      const added = await addGlobalPrecautionaryAllergen(selectedId)
      setAllergens((current) => [...current, added].sort((left, right) => allergenDisplayName(left.allergenId, left.canonicalPl)
        .localeCompare(allergenDisplayName(right.allergenId, right.canonicalPl), 'pl-PL')))
      setFormOpen(false)
      setSearch('')
      setSelectedId('')
      setSuccess(`Dodano „${allergenDisplayName(added.allergenId, added.canonicalPl)}” do listy MOŻE ZAWIERAĆ.`)
    } catch (cause) {
      setError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  async function removeAllergen(allergen: GlobalPrecautionaryAllergen) {
    setOpenMenuId(null)
    setBusy(true)
    setError(null)
    setSuccess(null)
    try {
      await removeGlobalPrecautionaryAllergen(allergen.allergenId)
      setAllergens((current) => current.filter((item) => item.allergenId !== allergen.allergenId))
      setSuccess(`Usunięto „${allergenDisplayName(allergen.allergenId, allergen.canonicalPl)}” z listy MOŻE ZAWIERAĆ.`)
    } catch (cause) {
      setError(errorText(cause))
    } finally {
      setBusy(false)
    }
  }

  return <section className="admin-allergen-precautionary" aria-labelledby="admin-allergen-precautionary-title">
    <header className="admin-category-header">
      <div>
        <h3 id="admin-allergen-precautionary-title">MOŻE ZAWIERAĆ</h3>
        <p>Wspólna lista dla całej instalacji VELTRA.</p>
      </div>
      <button type="button" id="admin-allergen-precautionary-form-trigger"
        className="button button-primary" disabled={busy || loading || canonical.length === 0}
        aria-expanded={formOpen} aria-controls="admin-allergen-precautionary-form-panel"
        onClick={() => { setFormOpen(true); setError(null); setSuccess(null) }}>
        + Dodaj alergen
      </button>
    </header>

    {success && <p className="notice notice-success" role="status">{success}</p>}
    {error && <p className="inline-error" role="alert">{error}</p>}

    <AnimatedPanel id="admin-allergen-precautionary-form-panel" open={formOpen}
      role="region" ariaLabelledBy="admin-allergen-precautionary-form-trigger"
      className="admin-allergen-form-panel">
      {formOpen && <form className="admin-allergen-form admin-allergen-precautionary-form" onSubmit={(event) => void addAllergen(event)}>
        <label className="create-form-field">Szukaj alergenu
          <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} />
        </label>
        <label className="create-form-field">Nazwa alergenu
          <select required value={selectedId} onChange={(event) => setSelectedId(event.target.value)}>
            <option value="">Wybierz alergen</option>
            {options.map((item) => <option key={item.id} value={item.id}>
              {allergenDisplayName(item.id, item.canonicalPl)}
            </option>)}
          </select>
        </label>
        {options.length === 0 && <p className="admin-allergen-empty admin-allergen-wide">
          {canonical.length === 0 ? 'Nie udało się wczytać alergenów.' : 'Brak pasujących alergenów do dodania.'}
        </p>}
        <div className="admin-category-actions admin-allergen-wide">
          <button type="submit" className="button button-primary" disabled={busy || !selectedId}>
            {busy ? 'Dodawanie…' : 'Dodaj do listy'}
          </button>
          <button type="button" className="button button-secondary" disabled={busy}
            onClick={() => { setFormOpen(false); setSearch(''); setSelectedId('') }}>Anuluj</button>
        </div>
      </form>}
    </AnimatedPanel>

    {loading ? <p role="status">Ładowanie listy…</p> : !loaded ? null : allergens.length === 0
      ? <p className="admin-allergen-empty">Lista MOŻE ZAWIERAĆ jest pusta.</p>
      : <ul className="admin-allergen-entries" aria-label="Lista MOŻE ZAWIERAĆ">
        {allergens.map((allergen) => <li className="admin-allergen-row" key={allergen.allergenId}>
          <span className="admin-allergen-row-name">{allergenDisplayName(allergen.allergenId, allergen.canonicalPl)}</span>
          <button type="button" className="admin-allergen-menu-button" aria-haspopup="menu"
            aria-expanded={openMenuId === allergen.allergenId}
            aria-label={`Opcje dla: ${allergenDisplayName(allergen.allergenId, allergen.canonicalPl)}`}
            disabled={busy}
            ref={(node) => { if (openMenuId === allergen.allergenId) menuButtonRef.current = node }}
            onClick={(event) => {
              menuButtonRef.current = event.currentTarget
              setOpenMenuId((current) => current === allergen.allergenId ? null : allergen.allergenId)
            }}>
            <span aria-hidden="true">⋯</span>
          </button>
          <div className="admin-allergen-context-menu" role="menu"
            aria-label={`Opcje dla: ${allergenDisplayName(allergen.allergenId, allergen.canonicalPl)}`}
            aria-hidden={openMenuId !== allergen.allergenId}
            inert={openMenuId !== allergen.allergenId}
            data-open={openMenuId === allergen.allergenId}
            data-placement={menuPlacement}
            ref={(node) => { if (openMenuId === allergen.allergenId) menuRef.current = node }}>
            <button type="button" role="menuitem" onClick={() => void removeAllergen(allergen)}>Usuń z listy</button>
          </div>
        </li>)}
      </ul>}
    {loaded && <p className="admin-allergen-count" aria-live="polite">
      {allergens.length} {allergens.length === 1 ? 'alergen' : 'alergenów'}
    </p>}
  </section>
}
