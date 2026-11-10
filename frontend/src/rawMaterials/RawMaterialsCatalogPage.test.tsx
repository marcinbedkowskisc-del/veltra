import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import RawMaterialsCatalogPage from './RawMaterialsCatalogPage'
import { downloadCatalogExport, exportCatalog, fetchCatalog, fetchCategories, fetchSuppliers } from './api'
import type { CatalogPage, CatalogQuery } from './catalog'

vi.mock('./api', () => ({
  downloadCatalogExport: vi.fn(),
  exportCatalog: vi.fn(),
  fetchCatalog: vi.fn(),
  fetchCategories: vi.fn(),
  fetchSuppliers: vi.fn(),
}))

const materialA = '00000000-0000-4000-8000-000000000001'
const materialB = '00000000-0000-4000-8000-000000000002'

function row(id: string, ordinal: number, extra: Partial<CatalogPage['rows'][number]> = {}): CatalogPage['rows'][number] {
  return {
    id,
    ordinal,
    sku: `RM-${String(ordinal).padStart(5, '0')}`,
    name: `Surowiec ${ordinal}`,
    unit: 'g',
    nominalQuantity: '100.000000',
    usableQuantity: '80.000000',
    lifecycleState: 'ACTIVE',
    ...extra,
  }
}

function page(
  rows: CatalogPage['rows'] = [row(materialA, 1)],
  currentPage = 1,
  total = rows.length,
  financial: Pick<CatalogPage, 'canReadReferencePrice' | 'canReadUsableCost'> = {
    canReadReferencePrice: false,
    canReadUsableCost: false,
  },
): CatalogPage {
  return {
    rows,
    meta: {
      currentPage,
      perPage: 25,
      total,
      lastPage: Math.max(1, Math.ceil(total / 25)),
      from: total === 0 ? null : (currentPage - 1) * 25 + 1,
      to: total === 0 ? null : Math.min(currentPage * 25, total),
    },
    ...financial,
  }
}

const fetchCatalogMock = vi.mocked(fetchCatalog)
const fetchCategoriesMock = vi.mocked(fetchCategories)
const fetchSuppliersMock = vi.mocked(fetchSuppliers)
const exportCatalogMock = vi.mocked(exportCatalog)
const downloadCatalogExportMock = vi.mocked(downloadCatalogExport)

beforeEach(() => {
  fetchCatalogMock.mockResolvedValue(page())
  fetchCategoriesMock.mockResolvedValue([{ id: 'category-1', name: 'Mąki' }])
  fetchSuppliersMock.mockResolvedValue([{ id: 'supplier-1', code: 'SUP-01', name: 'Dostawca A' }])
  exportCatalogMock.mockResolvedValue({
    exportId: 'export-1',
    downloadUrl: '/api/v1/raw-materials/catalog-exports/selection/export-1/download',
  })
  downloadCatalogExportMock.mockResolvedValue({ blob: new Blob(['sku,name']), filename: 'katalog.csv' })
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

describe('katalog surowców', () => {
  it('renderuje dokładnie osiem stałych kolumn i domyślnie pobiera nazwę ASC, stronę 1 po 25', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    const table = await screen.findByRole('table', { name: 'Katalog surowców' })

    expect(within(table).getAllByRole('columnheader')).toHaveLength(8)
    expect(within(table).getByText('Surowiec 1')).toBeTruthy()
    expect(fetchCatalogMock).toHaveBeenCalledWith(expect.objectContaining({
      sort: 'name',
      direction: 'asc',
      page: 1,
      perPage: 25,
    }), expect.any(AbortSignal))
  })

  it('debounce wyszukiwania wynosi 300 ms', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fetchCatalogMock.mockClear()
    vi.useFakeTimers()

    fireEvent.change(screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' }), {
      target: { value: 'mąka' },
    })
    expect((screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' }) as HTMLInputElement).value)
      .toBe('mąka')
    await act(async () => { await vi.advanceTimersByTimeAsync(299) })
    expect(fetchCatalogMock).not.toHaveBeenCalled()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(1)
      await Promise.resolve()
    })
    expect(fetchCatalogMock).toHaveBeenCalledWith(
      expect.objectContaining({ search: 'mąka', page: 1 }),
      expect.any(AbortSignal),
    )
  })

  it('mapuje kategorię i dostawcę na filtrowanie po stronie serwera', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    await screen.findByRole('option', { name: 'Mąki' })

    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według kategorii' }), {
      target: { value: 'category-1' },
    })
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ categoryId: 'category-1', page: 1 }),
      expect.any(AbortSignal),
    ))

    const supplierInput = await screen.findByRole('searchbox', { name: 'Dostawca' })
    vi.useFakeTimers()
    fireEvent.change(supplierInput, { target: { value: 'Dostawca A' } })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300)
      await Promise.resolve()
    })
    const supplierOption = screen.getByRole('button', { name: 'Dostawca A (SUP-01)' })
    fireEvent.click(supplierOption)
    await act(async () => { await Promise.resolve() })
    expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ supplierId: 'supplier-1', page: 1 }),
      expect.any(AbortSignal),
    )
  })

  it('sortuje przez nowe żądania serwerowe, bez lokalnego sortowania strony', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')

    fireEvent.click(screen.getByRole('button', { name: 'Sortuj według: SKU' }))
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'sku', direction: 'asc', page: 1 }),
      expect.any(AbortSignal),
    ))
    fireEvent.click(screen.getByRole('button', { name: 'Sortuj według: SKU' }))
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'sku', direction: 'desc' }),
      expect.any(AbortSignal),
    ))
  })

  it('zmiana rozmiaru strony wysyła do API tylko obsługiwane wartości i wraca na stronę 1', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.change(screen.getByRole('combobox', { name: 'Liczba wierszy na stronę' }), {
      target: { value: '50' },
    })

    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ perPage: 50, page: 1 }),
      expect.any(AbortSignal),
    ))
  })

  it('zachowuje zaznaczenie przez strony, a zaznacz wszystko obejmuje tylko bieżącą stronę', async () => {
    fetchCatalogMock.mockImplementation(async (query: CatalogQuery) => query.page === 1
      ? page([row(materialA, 1)], 1, 26)
      : page([row(materialB, 26)], 2, 26))

    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz Surowiec 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Następna' }))
    await screen.findByText('Surowiec 26')

    expect(screen.getByText('Zaznaczono: 1')).toBeTruthy()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz wszystkie widoczne na tej stronie' }))
    expect(screen.getByText('Zaznaczono: 2')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Poprzednia' }))
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz wszystkie widoczne na tej stronie' }))

    expect(screen.getByText('Zaznaczono: 1')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Następna' }))
    await screen.findByText('Surowiec 26')
    expect((screen.getByRole('checkbox', { name: 'Zaznacz Surowiec 26' }) as HTMLInputElement).checked).toBe(true)
  })

  it('czyści zaznaczenie po zmianie filtrów i udostępnia eksport zaznaczonych przez istniejący endpoint', async () => {
    const createObjectURL = vi.fn(() => 'blob:csv')
    const revokeObjectURL = vi.fn()
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: createObjectURL })
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: revokeObjectURL })
    const anchorClick = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')

    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz Surowiec 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Eksportuj zaznaczone' }))
    await screen.findByText('Plik CSV został przygotowany i pobrany.')
    expect(exportCatalogMock).toHaveBeenCalledWith(
      expect.objectContaining({ material_ids: [materialA] }),
      expect.any(String),
    )
    expect(downloadCatalogExportMock).toHaveBeenCalledWith(
      '/api/v1/raw-materials/catalog-exports/selection/export-1/download',
    )
    expect(anchorClick).toHaveBeenCalled()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz Surowiec 1' }))
    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według kategorii' }), {
      target: { value: 'category-1' },
    })
    expect(screen.queryByText('Zaznaczono: 1')).toBeNull()
  })

  it('pokazuje finansowe wartości tylko gdy backend zwrócił pola i nie udostępnia ich przy braku grantu', async () => {
    fetchCatalogMock.mockResolvedValue(page([row(materialA, 1, {
      referencePrice: '123.456',
      usableUnitCost: '9.995',
      usableCostBasis: 'PLN/100g',
    })], 1, 1, { canReadReferencePrice: true, canReadUsableCost: true }))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('123,46')
    expect(screen.getByText('PLN/100g')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Sortuj według: Cena netto' })).toBeTruthy()

    cleanup()
    fetchCatalogMock.mockResolvedValue(page([row(materialA, 1)], 1, 1, {
      canReadReferencePrice: false,
      canReadUsableCost: false,
    }))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findAllByText('Brak uprawnień')
    expect(screen.queryByRole('button', { name: 'Sortuj według: Cena netto' })).toBeNull()
  })

  it('wyświetla stany ładowania, pustego wyniku, 403 i NOT_READY', async () => {
    let resolveCatalog: ((value: CatalogPage) => void) | undefined
    fetchCatalogMock.mockReturnValue(new Promise((resolve) => { resolveCatalog = resolve }))
    const { unmount } = render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    expect(screen.getByText('Ładowanie katalogu…')).toBeTruthy()
    await act(async () => { resolveCatalog?.(page([], 1, 0)) })
    expect(await screen.findByText('Katalog nie zawiera jeszcze surowców.')).toBeTruthy()
    unmount()

    fetchCatalogMock.mockRejectedValueOnce(new ApiError(403, 'Brak dostępu.'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    expect(await screen.findByText('Brak dostępu')).toBeTruthy()
    cleanup()

    fetchCatalogMock.mockRejectedValueOnce(new ApiError(503, 'Moduł RAW_MATERIALS jest niedostępny.', 'RAW_MATERIALS_MODULE_UNAVAILABLE'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    expect(await screen.findByText('Moduł niedostępny')).toBeTruthy()
  })

  it('ukrywa filtr dostawcy po potwierdzonym 403 z jego endpointu', async () => {
    fetchSuppliersMock.mockRejectedValue(new ApiError(403, 'Brak uprawnień.'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')

    await waitFor(() => expect(screen.queryByRole('searchbox', { name: 'Dostawca' })).toBeNull())
  })

  it('wywołuje odświeżenie logowania po wygaśnięciu sesji', async () => {
    const onSessionExpired = vi.fn()
    fetchCatalogMock.mockRejectedValue(new ApiError(401, 'Sesja wygasła.'))
    render(<RawMaterialsCatalogPage onSessionExpired={onSessionExpired} />)

    await waitFor(() => expect(onSessionExpired).toHaveBeenCalledTimes(1))
  })
})
