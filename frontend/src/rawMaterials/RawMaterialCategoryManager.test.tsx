import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RawMaterialCategoryManager from './RawMaterialCategoryManager'
import { createCategory, fetchCategories, fetchRawMaterialCapabilities, renameCategory } from './api'

vi.mock('./api', () => ({
  createCategory: vi.fn(),
  fetchCategories: vi.fn(),
  fetchRawMaterialCapabilities: vi.fn(),
  renameCategory: vi.fn(),
}))

const location = '00000000-0000-4000-8000-000000000101'
const category = { id: 'category-1', name: 'Mąki', code: 'MAKA', depth: 1 }
const capability = {
  module: 'RAW_MATERIALS' as const, locationId: location, readiness: 'READY' as const, available: true,
  permissions: { create: false, edit: false, manageCategories: true },
  actions: { create: false, edit: false, manageCategories: true },
}

function renderManager() {
  const callbacks = {
    onUpdated: vi.fn(), onClose: vi.fn(), onSessionExpired: vi.fn(),
    onAuthorizationDenied: vi.fn(), onModuleUnavailable: vi.fn(),
  }
  const view = render(<RawMaterialCategoryManager locationId={location} categories={[category]} {...callbacks} />)
  return { ...view, ...callbacks }
}

beforeEach(() => {
  vi.mocked(fetchRawMaterialCapabilities).mockResolvedValue(capability)
  vi.mocked(fetchCategories).mockResolvedValue([category])
  vi.mocked(createCategory).mockResolvedValue(category)
  vi.mocked(renameCategory).mockResolvedValue(category)
})

afterEach(() => {
  cleanup()
  vi.resetAllMocks()
})

describe('zarządzanie kategoriami RAW', () => {
  it('ponownie sprawdza uprawnienie, tworzy kategorię i odświeża wybór', async () => {
    const { onUpdated } = renderManager()
    fireEvent.change(screen.getByLabelText(/^Kod/), { target: { value: 'PIECZYWO' } })
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'Pieczywo' } })
    fireEvent.change(screen.getByLabelText('Kategoria nadrzędna'), { target: { value: category.id } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj kategorię' }))
    await waitFor(() => expect(createCategory).toHaveBeenCalledWith(
      { code: 'PIECZYWO', name: 'Pieczywo', parent_id: category.id }, expect.any(AbortSignal),
    ))
    await waitFor(() => expect(onUpdated).toHaveBeenCalledWith([category]))
    expect(screen.getByRole('status').textContent).toContain('Utworzono kategorię')
  })

  it('nie wykonuje zapisu po cofnięciu uprawnienia', async () => {
    vi.mocked(fetchRawMaterialCapabilities).mockResolvedValue({
      ...capability, actions: { ...capability.actions, manageCategories: false },
    })
    const { onAuthorizationDenied } = renderManager()
    fireEvent.change(screen.getByLabelText(/^Kod/), { target: { value: 'TEST' } })
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'Test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj kategorię' }))
    await waitFor(() => expect(onAuthorizationDenied).toHaveBeenCalledOnce())
    expect(createCategory).not.toHaveBeenCalled()
  })

  it('nie zapisuje po zamknięciu panelu podczas sprawdzania uprawnień', async () => {
    let resolveCapability!: (value: typeof capability) => void
    vi.mocked(fetchRawMaterialCapabilities).mockReturnValue(new Promise((resolve) => { resolveCapability = resolve }))
    const { unmount } = renderManager()
    fireEvent.change(screen.getByLabelText(/^Kod/), { target: { value: 'TEST' } })
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'Test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj kategorię' }))
    unmount()
    await act(async () => { resolveCapability(capability) })
    expect(createCategory).not.toHaveBeenCalled()
  })

  it('blokuje ponowienie niepotwierdzonego zapisu do czasu odświeżenia', async () => {
    vi.mocked(createCategory).mockRejectedValue(new Error('Brak połączenia'))
    renderManager()
    fireEvent.change(screen.getByLabelText(/^Kod/), { target: { value: 'TEST' } })
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'Test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj kategorię' }))
    expect((await screen.findByRole('alert')).textContent).toContain('Nie można potwierdzić wyniku zapisu')
    expect(screen.getByRole('button', { name: 'Dodaj kategorię' }).matches(':disabled')).toBe(true)
    fireEvent.click(screen.getByRole('button', { name: 'Odśwież kategorie' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Dodaj kategorię' }).matches(':disabled')).toBe(false))
  })

  it('zmienia nazwę przez istniejące API', async () => {
    renderManager()
    fireEvent.change(screen.getByLabelText('Kategoria'), { target: { value: category.id } })
    fireEvent.change(screen.getByLabelText('Nowa nazwa'), { target: { value: 'Mąki zbożowe' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zmień nazwę' }))
    await waitFor(() => expect(renameCategory).toHaveBeenCalledWith(category.id, 'Mąki zbożowe', expect.any(AbortSignal)))
  })
})
