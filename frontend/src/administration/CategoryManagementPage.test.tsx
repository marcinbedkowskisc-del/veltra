import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import CategoryManagementPage from './CategoryManagementPage'
import {
  archiveAdministrationCategory,
  createAdministrationCategory,
  fetchAdministrationCategories,
  restoreAdministrationCategory,
  updateAdministrationCategory,
  type AdministrationCategory,
  type AdministrationCategoryPage,
} from './categoriesApi'

vi.mock('./categoriesApi', () => ({
  archiveAdministrationCategory: vi.fn(),
  createAdministrationCategory: vi.fn(),
  fetchAdministrationCategories: vi.fn(),
  restoreAdministrationCategory: vi.fn(),
  updateAdministrationCategory: vi.fn(),
}))

const root: AdministrationCategory = {
  id: '00000000-0000-4000-8000-000000000001',
  parentId: null,
  parentName: null,
  code: 'WARZYWA',
  name: 'Warzywa',
  description: 'Kategoria główna',
  depth: 1,
  archivedAt: null,
  hasChildren: true,
  rawMaterialCount: 2,
  path: ['Warzywa'],
}
const child: AdministrationCategory = {
  ...root,
  id: '00000000-0000-4000-8000-000000000002',
  parentId: root.id,
  parentName: root.name,
  code: 'KONSERWY',
  name: 'Konserwowe',
  depth: 2,
  hasChildren: false,
  rawMaterialCount: 1,
  path: ['Warzywa', 'Konserwowe'],
}
const categoryPage: AdministrationCategoryPage = {
  data: [root],
  meta: { currentPage: 1, perPage: 50, total: 1, lastPage: 1 },
}

function renderPage() {
  return render(<CategoryManagementPage />)
}

beforeEach(() => {
  vi.mocked(fetchAdministrationCategories).mockImplementation(async (query) => query.search
    ? { ...categoryPage, data: [{ ...child, path: ['Warzywa', 'Warzywa konserwowe'] }] }
    : query.parentId ? { ...categoryPage, data: [child] } : categoryPage)
  vi.mocked(createAdministrationCategory).mockResolvedValue(root)
  vi.mocked(updateAdministrationCategory).mockResolvedValue(root)
  vi.mocked(archiveAdministrationCategory).mockResolvedValue({ ...root, archivedAt: '2026-11-17T10:00:00Z' })
  vi.mocked(restoreAdministrationCategory).mockResolvedValue(root)
})

afterEach(() => {
  cleanup()
  vi.resetAllMocks()
})

describe('administracja kategoriami surowców', () => {
  it('ładuje drzewo stronicowane i rozwija dzieci tylko po otwarciu rodzica', async () => {
    renderPage()
    const rootTrigger = await screen.findByRole('button', { name: /Warzywa · WARZYWA/ })
    expect(fetchAdministrationCategories).toHaveBeenCalledWith(
      { status: 'active', search: '', page: 1 },
      expect.any(AbortSignal),
    )
    expect(screen.getByText('Kategoria główna')).toBeTruthy()
    fireEvent.click(rootTrigger)
    expect(await screen.findByRole('button', { name: /Konserwowe · KONSERWY/ })).toBeTruthy()
    expect(fetchAdministrationCategories).toHaveBeenCalledWith(
      { status: 'active', parentId: root.id, page: 1 },
      expect.any(AbortSignal),
    )
  })

  it('wyszukuje kategorię i pokazuje pełną ścieżkę hierarchii', async () => {
    renderPage()
    await screen.findByRole('button', { name: /Warzywa · WARZYWA/ })
    fireEvent.change(screen.getByLabelText('Szukaj po nazwie lub opisie'), { target: { value: 'konserwowe' } })
    fireEvent.click(screen.getByRole('button', { name: 'Szukaj' }))
    expect(await screen.findByText('Warzywa › Warzywa konserwowe')).toBeTruthy()
    expect(fetchAdministrationCategories).toHaveBeenLastCalledWith(
      { status: 'active', search: 'konserwowe', page: 1 },
      expect.any(AbortSignal),
    )
  })

  it('tworzy kategorię główną z obowiązkowym kodem i opcjonalnym opisem', async () => {
    renderPage()
    await screen.findByRole('button', { name: /Warzywa · WARZYWA/ })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj kategorię główną' }))
    fireEvent.change(screen.getByLabelText(/^Kod kategorii/), { target: { value: 'PIECZYWO' } })
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'Pieczywo' } })
    fireEvent.change(screen.getByLabelText('Opis (opcjonalnie)'), { target: { value: 'Opis kategorii' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz kategorię' }))
    await waitFor(() => expect(createAdministrationCategory).toHaveBeenCalledWith({
      parent_id: null,
      code: 'PIECZYWO',
      name: 'Pieczywo',
      description: 'Opis kategorii',
    }))
    expect(await screen.findByRole('status')).toBeTruthy()
  })

  it('tworzy podkategorię z niezmiennym rodzicem', async () => {
    renderPage()
    const rootTrigger = await screen.findByRole('button', { name: /Warzywa · WARZYWA/ })
    fireEvent.click(rootTrigger)
    await screen.findByRole('button', { name: /Konserwowe · KONSERWY/ })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj podkategorię' }))
    const form = document.querySelector<HTMLElement>('.admin-category-form')
    expect(form && within(form).getByText('Warzywa')).toBeTruthy()
    fireEvent.change(screen.getByLabelText(/^Kod kategorii/), { target: { value: 'MROZONE' } })
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'Mrożone' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz kategorię' }))
    await waitFor(() => expect(createAdministrationCategory).toHaveBeenCalledWith({
      parent_id: root.id,
      code: 'MROZONE',
      name: 'Mrożone',
      description: null,
    }))
  })

  it('edytuje nazwę i opis bez udostępniania zmiany rodzica', async () => {
    renderPage()
    const rootTrigger = await screen.findByRole('button', { name: /Warzywa · WARZYWA/ })
    fireEvent.click(rootTrigger)
    fireEvent.click(await screen.findByRole('button', { name: 'Edytuj' }))
    expect(screen.getByText(/Hierarchii nie można zmienić/)).toBeTruthy()
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'Warzywa i owoce' } })
    fireEvent.change(screen.getByLabelText('Opis (opcjonalnie)'), { target: { value: 'Nowy opis' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz kategorię' }))
    await waitFor(() => expect(updateAdministrationCategory).toHaveBeenCalledWith(root.id, {
      name: 'Warzywa i owoce',
      description: 'Nowy opis',
    }))
  })

  it('wyświetla blokadę archiwizacji zwróconą przez backend i obsługuje przywrócenie', async () => {
    vi.mocked(archiveAdministrationCategory).mockRejectedValue(
      new ApiError(409, 'Nie można zarchiwizować kategorii, ponieważ ma aktywnego potomka.'),
    )
    const archived = { ...child, archivedAt: '2026-11-17T10:00:00Z' }
    vi.mocked(fetchAdministrationCategories).mockImplementation(async (query) => (
      query.status === 'archived' ? { ...categoryPage, data: [archived] } : categoryPage
    ))
    renderPage()
    const rootTrigger = await screen.findByRole('button', { name: /Warzywa · WARZYWA/ })
    fireEvent.click(rootTrigger)
    fireEvent.click(await screen.findByRole('button', { name: 'Archiwizuj' }))
    const confirmation = screen.getByRole('group', { name: 'Potwierdzenie archiwizacji: Warzywa' })
    expect(within(confirmation).getByText(/Nie można archiwizować kategorii z aktywnym potomkiem/)).toBeTruthy()
    fireEvent.click(within(confirmation).getByRole('button', { name: 'Potwierdź archiwizację' }))
    expect(await screen.findByRole('alert')).toBeTruthy()

    fireEvent.change(screen.getByLabelText('Status kategorii'), { target: { value: 'archived' } })
    const archivedTrigger = await screen.findByRole('button', { name: /Konserwowe · KONSERWY · Archiwalna/ })
    fireEvent.click(archivedTrigger)
    fireEvent.click(await screen.findByRole('button', { name: 'Przywróć' }))
    await waitFor(() => expect(restoreAdministrationCategory).toHaveBeenCalledWith(archived.id))
  })

  it('pokazuje błędy walidacji zwrócone przez API', async () => {
    vi.mocked(createAdministrationCategory).mockRejectedValue(
      new ApiError(422, 'Sprawdź poprawność danych.', null, { code: ['Kod kategorii jest już używany.'] }),
    )
    renderPage()
    await screen.findByRole('button', { name: /Warzywa · WARZYWA/ })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj kategorię główną' }))
    fireEvent.change(screen.getByLabelText(/^Kod kategorii/), { target: { value: 'PIECZYWO' } })
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'Pieczywo' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz kategorię' }))
    expect((await screen.findByRole('alert')).textContent).toContain('Kod kategorii jest już używany.')
  })

  it('pokazuje błędy wczytywania', async () => {
    vi.mocked(fetchAdministrationCategories).mockRejectedValueOnce(new Error('Brak połączenia'))
    renderPage()
    expect((await screen.findByRole('alert')).textContent).toContain('Brak połączenia')
  })
})
