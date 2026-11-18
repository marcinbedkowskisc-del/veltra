import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GlobalPrecautionaryAllergenSection from './GlobalPrecautionaryAllergenSection'
import {
  addGlobalPrecautionaryAllergen,
  fetchGlobalPrecautionaryAllergens,
  removeGlobalPrecautionaryAllergen,
  type CanonicalAllergen,
  type GlobalPrecautionaryAllergen,
} from './allergensApi'

vi.mock('./allergensApi', () => ({
  addGlobalPrecautionaryAllergen: vi.fn(),
  fetchGlobalPrecautionaryAllergens: vi.fn(),
  removeGlobalPrecautionaryAllergen: vi.fn(),
}))

const canonical: CanonicalAllergen[] = [
  {
    id: 'ALG_SOYA',
    canonicalPl: 'SOJA',
    synonymsPl: [],
    recordStatus: 'ACTIVE',
    parentId: null,
    originalNames: {},
    archivedAt: null,
    sourceCodes: [],
    relationshipCount: 0,
  },
  {
    id: 'ALG_MILK',
    canonicalPl: 'MLEKO',
    synonymsPl: [],
    recordStatus: 'ACTIVE',
    parentId: null,
    originalNames: {},
    archivedAt: null,
    sourceCodes: [],
    relationshipCount: 0,
  },
]
const listedAllergen: GlobalPrecautionaryAllergen = { allergenId: 'ALG_SOYA', canonicalPl: 'SOJA' }

describe('GlobalPrecautionaryAllergenSection motion and accessibility', () => {
  beforeEach(() => {
    vi.mocked(fetchGlobalPrecautionaryAllergens).mockResolvedValue([listedAllergen])
    vi.mocked(addGlobalPrecautionaryAllergen).mockResolvedValue({ allergenId: 'ALG_MILK', canonicalPl: 'MLEKO' })
    vi.mocked(removeGlobalPrecautionaryAllergen).mockResolvedValue(listedAllergen)
  })

  it('opens and closes the inline add form with linked disclosure semantics', async () => {
    render(<GlobalPrecautionaryAllergenSection canonical={canonical} />)

    const addTrigger = screen.getByRole('button', { name: '+ Dodaj alergen' })
    await waitFor(() => expect(addTrigger.getAttribute('disabled')).toBeNull())
    const panel = document.getElementById(addTrigger.getAttribute('aria-controls') ?? '')

    expect(addTrigger.getAttribute('aria-expanded')).toBe('false')
    fireEvent.click(addTrigger)

    expect(addTrigger.getAttribute('aria-expanded')).toBe('true')
    expect(panel?.getAttribute('aria-hidden')).toBe('false')
    expect(screen.getByRole('searchbox', { name: 'Szukaj alergenu' })).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Anuluj' }))
    await waitFor(() => expect(addTrigger.getAttribute('aria-expanded')).toBe('false'))
    expect(panel?.getAttribute('aria-hidden')).toBe('true')
    expect(panel?.hasAttribute('inert')).toBe(true)
  })

  it('animates the context menu without exposing hidden actions to assistive technology', async () => {
    render(<GlobalPrecautionaryAllergenSection canonical={canonical} />)

    const menuTrigger = await screen.findByRole('button', { name: 'Opcje dla: SOJA' })
    fireEvent.click(menuTrigger)

    const menu = screen.getByRole('menu', { name: 'Opcje dla: SOJA' })
    expect(menuTrigger.getAttribute('aria-expanded')).toBe('true')
    expect(menu.getAttribute('data-open')).toBe('true')
    expect(screen.getByRole('menuitem', { name: 'Usuń z listy' })).toBeTruthy()

    fireEvent.keyDown(document, { key: 'Escape' })

    await waitFor(() => expect(menuTrigger.getAttribute('aria-expanded')).toBe('false'))
    expect(menu.getAttribute('aria-hidden')).toBe('true')
    expect(menu.hasAttribute('inert')).toBe(true)
  })
})
