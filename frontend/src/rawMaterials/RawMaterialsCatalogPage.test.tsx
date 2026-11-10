import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import RawMaterialsCatalogPage from './RawMaterialsCatalogPage'
import {
  downloadCatalogExport,
  exportCatalog,
  fetchCatalog,
  fetchCategories,
  fetchRawMaterialCapabilities,
  fetchRawMaterialDetail,
  fetchRawMaterialRevisions,
  fetchSuppliers,
  type RawMaterialCapabilities,
} from './api'
import type { CatalogPage, CatalogQuery } from './catalog'
import type { RawMaterialDetail, RawMaterialRevision } from './detail'

vi.mock('./api', () => ({
  downloadCatalogExport: vi.fn(),
  exportCatalog: vi.fn(),
  fetchCatalog: vi.fn(),
  fetchCategories: vi.fn(),
  fetchRawMaterialDetail: vi.fn(),
  fetchRawMaterialRevisions: vi.fn(),
  fetchRawMaterialCapabilities: vi.fn(),
  fetchSuppliers: vi.fn(),
}))

const materialA = '00000000-0000-4000-8000-000000000001'
const materialB = '00000000-0000-4000-8000-000000000002'
const locationA = '00000000-0000-4000-8000-000000000101'
const locationB = '00000000-0000-4000-8000-000000000102'
const detail = (id = materialA, extra: Partial<RawMaterialDetail> = {}): RawMaterialDetail => ({
  id,
  sku: id === materialA ? 'RM-00001' : 'RM-00002',
  lifecycleState: 'ACTIVE',
  approvedRevisionId: `revision-${id}`,
  revision: {
    id: `revision-${id}`,
    revisionNo: 3,
    name: id === materialA ? 'Mąka pszenna' : 'Ryż basmati',
    description: 'Opis zatwierdzonego snapshotu.',
    categoryId: 'category-1',
    baseUnitCode: 'kg',
    baseUnitDimension: 'MASS',
    allergenState: 'DECLARED',
    units: [{ unitCode: 'kg', dimension: 'MASS', conversionKind: 'BASE', toBaseFactor: '1.000000', basis: null }],
    aliases: ['alias testowy'],
  },
  nominalQuantity: '10.000000',
  usableQuantity: '8.000000',
  yieldPercent: '80.000000',
  referenceNetPrice: '123.450000',
  currencyCode: 'PLN',
  usableUnitCost: '15.431250',
  usableCostBasis: 'PLN/100g',
  lastUpdate: { occurredAt: '2025-01-15T10:30:00Z', actor: 'Użytkownik testowy' },
  historyUrl: `/api/v1/raw-materials/${id}/history`,
  ...extra,
})

const revisionHistory: RawMaterialRevision[] = [{
  id: 'revision-history-1',
  revisionNo: 2,
  name: 'Mąka pszenna — poprzednia',
  categoryId: 'category-1',
  baseUnitCode: 'kg',
  allergenState: 'UNKNOWN',
  criticalChange: true,
  createdAt: '2024-12-10T09:00:00Z',
  approvals: [{ outcome: 'APPROVED', occurredAt: '2024-12-10T09:00:00Z', reason: 'Weryfikacja' }],
}]

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
const fetchRawMaterialDetailMock = vi.mocked(fetchRawMaterialDetail)
const fetchRawMaterialRevisionsMock = vi.mocked(fetchRawMaterialRevisions)
const fetchRawMaterialCapabilitiesMock = vi.mocked(fetchRawMaterialCapabilities)
const fetchSuppliersMock = vi.mocked(fetchSuppliers)
const exportCatalogMock = vi.mocked(exportCatalog)
const downloadCatalogExportMock = vi.mocked(downloadCatalogExport)
let originalVisibilityDescriptor: PropertyDescriptor | undefined

beforeEach(() => {
  fetchCatalogMock.mockResolvedValue(page())
  fetchCategoriesMock.mockResolvedValue([{ id: 'category-1', name: 'Mąki' }])
  fetchRawMaterialDetailMock.mockImplementation(async (id) => detail(id))
  fetchRawMaterialRevisionsMock.mockResolvedValue(revisionHistory)
  fetchRawMaterialCapabilitiesMock.mockImplementation(async (locationId) => ({
    module: 'RAW_MATERIALS',
    locationId,
    readiness: 'NOT_READY',
    available: false,
    permissions: { create: false },
    actions: { create: false },
  }))
  fetchSuppliersMock.mockResolvedValue([{ id: 'supplier-1', code: 'SUP-01', name: 'Dostawca A' }])
  exportCatalogMock.mockResolvedValue({
    exportId: 'export-1',
    downloadUrl: '/api/v1/raw-materials/catalog-exports/selection/export-1/download',
  })
  downloadCatalogExportMock.mockResolvedValue({ blob: new Blob(['sku,name']), filename: 'katalog.csv' })
})

afterEach(() => {
  cleanup()
  if (originalVisibilityDescriptor) {
    Object.defineProperty(document, 'visibilityState', originalVisibilityDescriptor)
  } else {
    Reflect.deleteProperty(document, 'visibilityState')
  }
  originalVisibilityDescriptor = undefined
  vi.useRealTimers()
})

describe('katalog surowców', () => {
  it('pokazuje stan sprawdzania capabilities do czasu odpowiedzi API', async () => {
    let resolveCapabilities: ((value: RawMaterialCapabilities) => void) | undefined
    fetchRawMaterialCapabilitiesMock.mockImplementation(() => new Promise((resolve) => {
      resolveCapabilities = resolve
    }))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    await waitFor(() => expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledTimes(1))
    expect(screen.getByText('Sprawdzanie uprawnień katalogu…')).toBeTruthy()
    await act(async () => {
      resolveCapabilities?.({
        module: 'RAW_MATERIALS',
        locationId: locationA,
        readiness: 'READY',
        available: true,
        permissions: { create: true },
        actions: { create: true },
      })
    })
    await screen.findByText('Surowiec 1')
    expect(screen.queryByText('Sprawdzanie uprawnień katalogu…')).toBeNull()
  })

  it('odświeża capabilities i kategorię przed otwarciem formularza tworzenia', async () => {
    fetchRawMaterialCapabilitiesMock.mockResolvedValue({
      module: 'RAW_MATERIALS',
      locationId: locationA,
      readiness: 'READY',
      available: true,
      permissions: { create: true },
      actions: { create: true },
    })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    const action = await screen.findByRole('button', { name: 'Dodaj surowiec' })
    expect(action).toHaveProperty('disabled', false)
    expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledWith(locationA, expect.any(AbortSignal))
    fireEvent.click(action)
    expect(await screen.findByRole('dialog', { name: 'Dodaj surowiec' })).toBeTruthy()
    expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledTimes(2)
    expect(fetchCategoriesMock).toHaveBeenCalledTimes(2)
  })

  it('zamyka akcję fail-closed, jeśli świeże capabilities przed formularzem odmawia CREATE', async () => {
    fetchRawMaterialCapabilitiesMock
      .mockResolvedValueOnce({
        module: 'RAW_MATERIALS',
        locationId: locationA,
        readiness: 'READY',
        available: true,
        permissions: { create: true },
        actions: { create: true },
      })
      .mockResolvedValueOnce({
        module: 'RAW_MATERIALS',
        locationId: locationA,
        readiness: 'READY',
        available: true,
        permissions: { create: false },
        actions: { create: false },
      })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Dodaj surowiec' }))
    expect(await screen.findByText(/Brak aktualnego uprawnienia do tworzenia/)).toBeTruthy()
    expect(screen.queryByRole('dialog', { name: 'Dodaj surowiec' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()
  })

  it('nie pokazuje akcji przy grantcie bez gotowości lub dostępności modułu', async () => {
    fetchRawMaterialCapabilitiesMock.mockResolvedValue({
      module: 'RAW_MATERIALS',
      locationId: locationA,
      readiness: 'NOT_READY',
      available: false,
      permissions: { create: true },
      actions: { create: false },
    })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    await screen.findByText('Surowiec 1')
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()
    expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledWith(locationA, expect.any(AbortSignal))
  })

  it('ukrywa akcję, gdy efektywny grant CREATE nie istnieje', async () => {
    fetchRawMaterialCapabilitiesMock.mockResolvedValue({
      module: 'RAW_MATERIALS',
      locationId: locationA,
      readiness: 'READY',
      available: true,
      permissions: { create: false },
      actions: { create: false },
    })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    await screen.findByText('Surowiec 1')
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()
  })

  it('fail-closed po błędzie capabilities i odświeża wynik po powrocie karty do aktywnego stanu', async () => {
    fetchRawMaterialCapabilitiesMock.mockRejectedValueOnce(new Error('Brak połączenia.'))
    fetchRawMaterialCapabilitiesMock.mockResolvedValueOnce({
      module: 'RAW_MATERIALS',
      locationId: locationA,
      readiness: 'READY',
      available: true,
      permissions: { create: true },
      actions: { create: true },
    })
    originalVisibilityDescriptor = Object.getOwnPropertyDescriptor(document, 'visibilityState')
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    await screen.findByText('Nie można potwierdzić uprawnień do tworzenia. Akcja pozostaje ukryta.')
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()

    fireEvent(document, new Event('visibilitychange'))
    expect(await screen.findByRole('button', { name: 'Dodaj surowiec' })).toBeTruthy()
    expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledTimes(2)
  })

  it('odrzuca spóźnioną odpowiedź capabilities dla poprzedniej lokalizacji', async () => {
    let resolvePrevious: ((value: RawMaterialCapabilities) => void) | undefined
    fetchRawMaterialCapabilitiesMock.mockImplementation((locationId) => locationId === locationA
      ? new Promise((resolve) => { resolvePrevious = resolve })
      : Promise.resolve({
        module: 'RAW_MATERIALS',
        locationId,
        readiness: 'READY',
        available: true,
        permissions: { create: true },
        actions: { create: true },
      }))
    const onSessionExpired = vi.fn()
    const { rerender } = render(
      <RawMaterialsCatalogPage onSessionExpired={onSessionExpired} operatingLocationId={locationA} />,
    )
    await waitFor(() => expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledTimes(1))

    rerender(<RawMaterialsCatalogPage onSessionExpired={onSessionExpired} operatingLocationId={locationB} />)
    expect(await screen.findByRole('button', { name: 'Dodaj surowiec' })).toBeTruthy()
    await act(async () => {
      resolvePrevious?.({
        module: 'RAW_MATERIALS',
        locationId: locationA,
        readiness: 'READY',
        available: true,
        permissions: { create: true },
        actions: { create: true },
      })
    })

    expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledTimes(2)
    expect(screen.getByRole('button', { name: 'Dodaj surowiec' })).toBeTruthy()
    expect(onSessionExpired).not.toHaveBeenCalled()
  })

  it.each([401, 419])('czyści capability i zgłasza wygaśnięcie sesji po HTTP %s', async (status) => {
    const onSessionExpired = vi.fn()
    fetchRawMaterialCapabilitiesMock.mockRejectedValue(new ApiError(status, 'Sesja wygasła.'))
    render(<RawMaterialsCatalogPage onSessionExpired={onSessionExpired} operatingLocationId={locationA} />)

    await waitFor(() => expect(onSessionExpired).toHaveBeenCalledTimes(1))
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()
  })

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
    expect(screen.getByText('Sprawdzanie dostępu do filtra dostawcy…')).toBeTruthy()
    await act(async () => { resolveCatalog?.(page([], 1, 0)) })
    expect(await screen.findByText('Katalog nie zawiera jeszcze surowców.')).toBeTruthy()
    unmount()

    fetchCatalogMock.mockRejectedValueOnce(new ApiError(403, 'Brak dostępu.'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    expect(await screen.findByText('Brak dostępu')).toBeTruthy()
    cleanup()

    fetchSuppliersMock.mockClear()
    fetchCatalogMock.mockRejectedValueOnce(new ApiError(503, 'Moduł RAW_MATERIALS jest niedostępny.', 'RAW_MATERIALS_MODULE_UNAVAILABLE'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    expect(await screen.findByText('Moduł niedostępny')).toBeTruthy()
    expect(screen.queryByText('Sprawdzanie dostępu do filtra dostawcy…')).toBeNull()
    expect(fetchSuppliersMock).not.toHaveBeenCalled()
  })

  it('czyści stan dostawcy i ignoruje spóźnione odpowiedzi po wejściu katalogu w NOT_READY', async () => {
    let rejectCategories: ((cause: ApiError) => void) | undefined
    let resolveSuppliers: ((suppliers: Awaited<ReturnType<typeof fetchSuppliers>>) => void) | undefined
    let resolveRefresh: ((value: CatalogPage) => void) | undefined
    fetchCatalogMock
      .mockResolvedValueOnce(page([row(materialA, 1, {
        referencePrice: '123.456',
        usableUnitCost: '9.995',
        usableCostBasis: 'PLN/100g',
      })], 1, 1, { canReadReferencePrice: true, canReadUsableCost: true }))
      .mockReturnValueOnce(new Promise((resolve) => { resolveRefresh = resolve }))
    fetchCategoriesMock.mockReturnValue(new Promise((_, reject) => { rejectCategories = reject }))
    fetchSuppliersMock.mockReturnValue(new Promise((resolve) => { resolveSuppliers = resolve }))

    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    expect(screen.getByText('Sprawdzanie dostępu do filtra dostawcy…')).toBeTruthy()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz Surowiec 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Sortuj według: SKU' }))

    await act(async () => {
      rejectCategories?.(new ApiError(
        503,
        'Moduł RAW_MATERIALS jest niedostępny.',
        'RAW_MATERIALS_MODULE_UNAVAILABLE',
      ))
    })
    expect(await screen.findByText('Moduł niedostępny')).toBeTruthy()
    expect(screen.queryByText('Sprawdzanie dostępu do filtra dostawcy…')).toBeNull()
    expect(screen.queryByRole('searchbox', { name: 'Dostawca' })).toBeNull()
    expect(screen.queryByText('Dostawca poufny (SUP-SECRET)')).toBeNull()
    expect(screen.queryByText('123,46')).toBeNull()
    expect(screen.queryByText('Zaznaczono: 1')).toBeNull()
    expect((screen.getByRole('searchbox', {
      name: 'Szukaj surowca po nazwie lub SKU',
    }) as HTMLInputElement).disabled).toBe(true)

    await act(async () => {
      resolveSuppliers?.([{ id: 'secret-supplier', code: 'SUP-SECRET', name: 'Dostawca poufny' }])
      resolveRefresh?.(page([row(materialB, 2, { name: 'Stary wynik' })]))
      await Promise.resolve()
    })
    expect(screen.queryByText('Dostawca poufny (SUP-SECRET)')).toBeNull()
    expect(screen.queryByText('Stary wynik')).toBeNull()
    expect(screen.queryByText('Sprawdzanie dostępu do filtra dostawcy…')).toBeNull()
  })

  it('ukrywa filtr dostawcy po potwierdzonym 403 z jego endpointu', async () => {
    fetchSuppliersMock.mockRejectedValue(new ApiError(403, 'Brak uprawnień.'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')

    await waitFor(() => expect(screen.queryByRole('searchbox', { name: 'Dostawca' })).toBeNull())
  })

  it.each([401, 419])('wywołuje odświeżenie logowania po HTTP %s', async (status) => {
    const onSessionExpired = vi.fn()
    fetchCatalogMock.mockRejectedValue(new ApiError(status, 'Sesja wygasła.'))
    render(<RawMaterialsCatalogPage onSessionExpired={onSessionExpired} />)

    await waitFor(() => expect(onSessionExpired).toHaveBeenCalledTimes(1))
  })

  it('otwiera panel zatwierdzonego snapshotu i historii bez zmiany zaznaczenia', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz Surowiec 1' }))
    const trigger = screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' })
    fireEvent.click(trigger)

    const dialog = await screen.findByRole('dialog', { name: 'Szczegóły surowca' })
    expect(await within(dialog).findByText('Opis zatwierdzonego snapshotu.')).toBeTruthy()
    expect(within(dialog).getByText('v3')).toBeTruthy()
    expect(within(dialog).getByText('Mąki')).toBeTruthy()
    expect(within(dialog).getByText('123,45 PLN')).toBeTruthy()
    expect(within(dialog).getByText('15,43 PLN/100g')).toBeTruthy()
    expect(await within(dialog).findByText(/Mąka pszenna — poprzednia/)).toBeTruthy()
    expect(fetchRawMaterialDetailMock).toHaveBeenCalledWith(materialA, expect.any(AbortSignal))
    expect(fetchRawMaterialRevisionsMock).toHaveBeenCalledWith(materialA, expect.any(AbortSignal))
    expect(document.activeElement).toBe(within(dialog).getByRole('button', { name: 'Zamknij szczegóły surowca' }))

    fireEvent.keyDown(dialog, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(document.activeElement).toBe(trigger)
    expect(screen.getByText('Zaznaczono: 1')).toBeTruthy()
  })

  it('nie wyświetla nieprzyznanych pól finansowych w szczegółach', async () => {
    fetchRawMaterialDetailMock.mockResolvedValue(detail(materialA, {
      referenceNetPrice: undefined,
      currencyCode: undefined,
      usableUnitCost: undefined,
      usableCostBasis: undefined,
      historyUrl: null,
    }))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))

    const dialog = await screen.findByRole('dialog', { name: 'Szczegóły surowca' })
    expect(await within(dialog).findByText('Opis zatwierdzonego snapshotu.')).toBeTruthy()
    expect(within(dialog).queryByText('Cena netto')).toBeNull()
    expect(within(dialog).queryByText('Koszt jednostkowy')).toBeNull()
    expect(within(dialog).getByText('Historia rewizji nie jest dostępna w bieżącym zakresie uprawnień.')).toBeTruthy()
    expect(fetchRawMaterialRevisionsMock).not.toHaveBeenCalled()
  })

  it('czyści szczegóły po 403 i zachowuje wyłącznie stan odmowy dostępu', async () => {
    fetchRawMaterialDetailMock.mockRejectedValue(new ApiError(403, 'Brak dostępu.'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))

    const dialog = await screen.findByRole('dialog', { name: 'Szczegóły surowca' })
    expect(await within(dialog).findByText('Szczegóły surowca nie są dostępne w bieżącym kontekście.')).toBeTruthy()
    expect(within(dialog).queryByText('Opis zatwierdzonego snapshotu.')).toBeNull()
    expect(within(dialog).queryByText('123,45 PLN')).toBeNull()
  })

  it('pokazuje neutralny stan 404 bez ujawniania szczegółów', async () => {
    fetchRawMaterialDetailMock.mockRejectedValue(new ApiError(404, 'Nie znaleziono surowca.'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))

    const dialog = await screen.findByRole('dialog', { name: 'Szczegóły surowca' })
    expect(await within(dialog).findByText('Nie znaleziono surowca lub nie jest on dostępny w bieżącym kontekście.')).toBeTruthy()
    expect(within(dialog).queryByText('Opis zatwierdzonego snapshotu.')).toBeNull()
  })

  it('nie pokazuje historii rewizji po cofnięciu uprawnienia audytowego', async () => {
    fetchRawMaterialRevisionsMock.mockRejectedValue(new ApiError(403, 'Brak dostępu.'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))

    const dialog = await screen.findByRole('dialog', { name: 'Szczegóły surowca' })
    expect(await within(dialog).findByText('Historia rewizji nie jest dostępna w bieżącym kontekście.')).toBeTruthy()
    expect(within(dialog).getByText('Opis zatwierdzonego snapshotu.')).toBeTruthy()
    expect(within(dialog).queryByText(/Mąka pszenna — poprzednia/)).toBeNull()
  })

  it.each([401, 419])('zgłasza wygaśnięcie sesji w szczegółach po HTTP %s', async (status) => {
    const onSessionExpired = vi.fn()
    fetchRawMaterialDetailMock.mockRejectedValue(new ApiError(status, 'Sesja wygasła.'))
    render(<RawMaterialsCatalogPage onSessionExpired={onSessionExpired} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))

    expect(await screen.findByText('Sesja wygasła. Zaloguj się ponownie.')).toBeTruthy()
    expect(onSessionExpired).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('Opis zatwierdzonego snapshotu.')).toBeNull()
  })

  it('zamyka szczegóły i wyczyści katalog, gdy odpowiedź szczegółów sygnalizuje NOT_READY', async () => {
    fetchRawMaterialDetailMock.mockRejectedValue(new ApiError(
      503,
      'Moduł RAW_MATERIALS jest niedostępny.',
      'RAW_MATERIALS_MODULE_UNAVAILABLE',
    ))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))

    expect(await screen.findByText('Moduł niedostępny')).toBeTruthy()
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(screen.queryByText('Surowiec 1')).toBeNull()
  })

  it('ignoruje spóźnioną odpowiedź po zamknięciu panelu', async () => {
    let resolveDetail: ((value: RawMaterialDetail) => void) | undefined
    fetchRawMaterialDetailMock.mockReturnValue(new Promise((resolve) => { resolveDetail = resolve }))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))
    const dialog = await screen.findByRole('dialog', { name: 'Szczegóły surowca' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Zamknij szczegóły surowca' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())

    await act(async () => {
      resolveDetail?.(detail(materialA))
      await Promise.resolve()
    })
    expect(screen.queryByText('Opis zatwierdzonego snapshotu.')).toBeNull()
  })

  it('ignoruje spóźnioną odpowiedź historii po zamknięciu panelu', async () => {
    let resolveRevisions: ((value: RawMaterialRevision[]) => void) | undefined
    fetchRawMaterialRevisionsMock.mockReturnValue(new Promise((resolve) => { resolveRevisions = resolve }))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))
    const dialog = await screen.findByRole('dialog', { name: 'Szczegóły surowca' })
    await within(dialog).findByText('Opis zatwierdzonego snapshotu.')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Zamknij szczegóły surowca' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())

    await act(async () => {
      resolveRevisions?.(revisionHistory)
      await Promise.resolve()
    })
    expect(screen.queryByText(/Mąka pszenna — poprzednia/)).toBeNull()
  })
})
