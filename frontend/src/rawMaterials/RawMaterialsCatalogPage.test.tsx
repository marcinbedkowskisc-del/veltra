import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import RawMaterialsCatalogPage from './RawMaterialsCatalogPage'
import { emptyRawMaterialCreateValues } from './create'
import {
  downloadCatalogExport,
  exportCatalog,
  fetchCatalog,
  fetchCategories,
  fetchRawMaterialCapabilities,
  fetchRawMaterialCompletion,
  fetchRawMaterialDetail,
  fetchRawMaterialEditor,
  fetchRawMaterialReferencePrices,
  fetchRawMaterialRevisions,
  fetchSuppliers,
  reserveRawMaterialSku,
  createRawMaterial,
  reviseRawMaterial,
  changeRawMaterialLifecycle,
  type RawMaterialCapabilities,
  type RawMaterialEditor,
  type RevisedRawMaterial,
} from './api'
import type { CatalogPage, CatalogQuery } from './catalog'
import type { RawMaterialDetail, RawMaterialReferencePrice, RawMaterialRevision } from './detail'

vi.mock('./api', async (importOriginal) => ({
  ...await importOriginal<typeof import('./api')>(),
  downloadCatalogExport: vi.fn(),
  exportCatalog: vi.fn(),
  fetchCatalog: vi.fn(),
  fetchCategories: vi.fn(),
  fetchRawMaterialDetail: vi.fn(),
  fetchRawMaterialCompletion: vi.fn(),
  fetchRawMaterialEditor: vi.fn(),
  fetchRawMaterialReferencePrices: vi.fn(),
  fetchRawMaterialRevisions: vi.fn(),
  fetchRawMaterialCapabilities: vi.fn(),
  fetchSuppliers: vi.fn(),
  reserveRawMaterialSku: vi.fn(),
  createRawMaterial: vi.fn(),
  reviseRawMaterial: vi.fn(),
  changeRawMaterialLifecycle: vi.fn(),
}))

const materialA = '00000000-0000-4000-8000-000000000001'
const materialB = '00000000-0000-4000-8000-000000000002'
const locationA = '00000000-0000-4000-8000-000000000101'
const locationB = '00000000-0000-4000-8000-000000000102'
const detail = (id = materialA, extra: Partial<RawMaterialDetail> = {}): RawMaterialDetail => ({
  id,
  sku: id === materialA ? 'RAW-000001' : 'RAW-000002',
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

const editableEditor = (id = materialA): RawMaterialEditor => ({
  id,
  sku: id === materialA ? 'RAW-000001' : 'RAW-000002',
  lifecycleState: 'ACTIVE',
  revisionId: `revision-${id}`,
  revisionNo: 3,
  version: `version-${id}`,
  values: {
    ...emptyRawMaterialCreateValues(),
    categoryId: 'category-1',
    name: id === materialA ? 'Mąka pszenna' : 'Ryż basmati',
    description: 'Opis roboczy.',
    baseUnitCode: 'kg',
    nominalQuantity: '10.000000',
    usableQuantity: '8.000000',
    usableQuantityExplicit: true,
    aliases: ['alias'],
    conversions: [],
  },
})

const revisedMaterial: RevisedRawMaterial = {
  id: materialA,
  sku: 'RAW-000001',
  lifecycleState: 'ACTIVE',
  version: 'next-version',
  revision: { id: 'revision-next', revisionNo: 4 },
}

function row(id: string, ordinal: number, extra: Partial<CatalogPage['rows'][number]> = {}): CatalogPage['rows'][number] {
  return {
    id,
    ordinal,
    sku: `RAW-${String(ordinal).padStart(6, '0')}`,
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
const fetchRawMaterialCompletionMock = vi.mocked(fetchRawMaterialCompletion)
const fetchRawMaterialEditorMock = vi.mocked(fetchRawMaterialEditor)
const fetchRawMaterialReferencePricesMock = vi.mocked(fetchRawMaterialReferencePrices)
const fetchRawMaterialRevisionsMock = vi.mocked(fetchRawMaterialRevisions)
const fetchRawMaterialCapabilitiesMock = vi.mocked(fetchRawMaterialCapabilities)
const fetchSuppliersMock = vi.mocked(fetchSuppliers)
const reserveRawMaterialSkuMock = vi.mocked(reserveRawMaterialSku)
const createRawMaterialMock = vi.mocked(createRawMaterial)
const reviseRawMaterialMock = vi.mocked(reviseRawMaterial)
const exportCatalogMock = vi.mocked(exportCatalog)
const downloadCatalogExportMock = vi.mocked(downloadCatalogExport)
let originalVisibilityDescriptor: PropertyDescriptor | undefined

beforeEach(() => {
  fetchCatalogMock.mockResolvedValue(page())
  fetchCategoriesMock.mockResolvedValue([{ id: 'category-1', name: 'Mąki' }])
  fetchRawMaterialDetailMock.mockImplementation(async (id) => detail(id))
  fetchRawMaterialCompletionMock.mockResolvedValue({
    lifecycleState: 'ACTIVE',
    revisionId: '00000000-0000-4000-8000-000000000301',
    version: null,
    availableActions: [],
  })
  fetchRawMaterialEditorMock.mockRejectedValue(new Error('Brak domyślnej rewizji edytora.'))
  fetchRawMaterialReferencePricesMock.mockResolvedValue([])
  fetchRawMaterialRevisionsMock.mockResolvedValue(revisionHistory)
  fetchRawMaterialCapabilitiesMock.mockImplementation(async (locationId) => ({
    module: 'RAW_MATERIALS',
    locationId,
    readiness: 'NOT_READY',
    available: false,
    permissions: { create: false, edit: false },
    actions: { create: false, edit: false },
  }))
  reviseRawMaterialMock.mockRejectedValue(new Error('Brak domyślnej odpowiedzi PATCH.'))
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
  it('odświeża szkice, szczegóły i zatwierdzony katalog po SUBMIT i APPROVE bez przeładowania aplikacji', async () => {
    let state: 'DRAFT' | 'VERIFICATION' | 'ACTIVE' = 'DRAFT'
    fetchCatalogMock.mockImplementation(async (query) => query.view === 'workbench'
      ? page(state === 'ACTIVE' ? [] : [row(materialA, 1, { lifecycleState: state, workflowState: state })])
      : page(state === 'ACTIVE' ? [row(materialA, 1)] : []))
    fetchRawMaterialDetailMock.mockImplementation(async (id) => detail(id, { lifecycleState: state }))
    fetchRawMaterialCompletionMock.mockImplementation(async () => ({
      lifecycleState: state, revisionId: 'revision-test', version: `version-${state}`,
      availableActions: state === 'DRAFT' ? ['submit'] : state === 'VERIFICATION' ? ['approve'] : [],
      missingRequirements: [],
    }))
    vi.mocked(changeRawMaterialLifecycle).mockImplementation(async (_id, action) => {
      state = action === 'submit' ? 'VERIFICATION' : 'ACTIVE'
    })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)
    fireEvent.click(screen.getByRole('tab', { name: 'Szkice i oczekujące' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Przekaż do zatwierdzenia' }))
    fireEvent.click(screen.getByRole('button', { name: 'Potwierdź przekazanie' }))
    expect(await screen.findByText('· Weryfikacja')).toBeTruthy()
    fireEvent.click(await screen.findByRole('button', { name: 'Zatwierdź rewizję' }))
    fireEvent.change(screen.getByLabelText('Uzasadnienie zatwierdzenia'), { target: { value: 'Akceptacja' } })
    fireEvent.click(screen.getByRole('button', { name: 'Potwierdź zatwierdzenie' }))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' })).toBeNull())
    fireEvent.click(screen.getByRole('button', { name: 'Zamknij szczegóły surowca' }))
    fireEvent.click(screen.getByRole('tab', { name: 'Zatwierdzone' }))
    expect(await screen.findByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' })).toBeTruthy()
    expect(changeRawMaterialLifecycle).toHaveBeenNthCalledWith(1, materialA, 'submit', 'version-DRAFT', '')
    expect(changeRawMaterialLifecycle).toHaveBeenNthCalledWith(2, materialA, 'approve', 'version-VERIFICATION', 'Akceptacja')
  })
  it('pobiera szkice osobnym kontraktem, odróżnia status i resetuje filtry przy zmianie zakładki', async () => {
    fetchCatalogMock.mockImplementation(async (query) => query.view === 'workbench'
      ? page([row(materialB, 1, { name: 'Szkic testowy', lifecycleState: 'DRAFT', workflowState: 'DRAFT' })])
      : page())
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)
    await screen.findByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' })
    fireEvent.click(screen.getByRole('tab', { name: 'Szkice i oczekujące' }))
    await screen.findByRole('button', { name: 'Pokaż szczegóły: Szkic testowy' })
    expect(screen.getByRole('button', { name: 'Pokaż szczegóły: Szkic testowy' }).hasAttribute('disabled')).toBe(false)
    expect(screen.queryByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' })).toBeNull()
    expect(screen.getByText('· Szkic')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Eksport CSV' }).hasAttribute('disabled')).toBe(true)
    expect(screen.queryByLabelText('Dostawca')).toBeNull()
    fireEvent.change(screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' }), { target: { value: 'testowy' } })
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(expect.objectContaining({
      view: 'workbench', search: 'testowy', page: 1,
    }), expect.any(AbortSignal)))
    fireEvent.click(screen.getByRole('tab', { name: 'Zatwierdzone' }))
    await screen.findByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' })
    expect(fetchCatalogMock.mock.calls.at(-1)?.[0].view).toBeUndefined()
    expect(fetchCatalogMock.mock.calls.at(-1)?.[0].search).toBe('')
  })
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
        permissions: { create: true, edit: false },
        actions: { create: true, edit: false },
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
      permissions: { create: true, edit: false },
      actions: { create: true, edit: false },
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

  it('po utworzeniu szkicu otwiera jego robocze szczegóły w odpowiednim widoku', async () => {
    fetchRawMaterialCapabilitiesMock.mockResolvedValue({
      module: 'RAW_MATERIALS', locationId: locationA, readiness: 'READY', available: true,
      permissions: { create: true, edit: true }, actions: { create: true, edit: true },
    })
    reserveRawMaterialSkuMock.mockResolvedValue({
      reservationId: materialB, sku: 'RAW-000001', expiresAt: '2026-11-13T12:00:00Z', status: 'RESERVED',
    })
    createRawMaterialMock.mockResolvedValue({ id: materialA, sku: 'RAW-000001', lifecycleState: 'DRAFT' })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Dodaj surowiec' }))
    const form = await screen.findByRole('dialog', { name: 'Dodaj surowiec' })
    fireEvent.change(within(form).getByLabelText(/Kategoria/), { target: { value: 'category-1' } })
    fireEvent.change(within(form).getByLabelText(/Nazwa surowca/), { target: { value: 'Szkic testowy' } })
    fireEvent.change(within(form).getByLabelText(/Nominalna/), { target: { value: '10' } })
    fireEvent.click(within(form).getByRole('button', { name: 'Utwórz szkic' }))
    fireEvent.click(await within(form).findByRole('button', { name: 'Otwórz szczegóły' }))
    await waitFor(() => expect(fetchRawMaterialDetailMock).toHaveBeenCalledWith(materialA, expect.any(AbortSignal), true))
    expect(screen.getByRole('tab', { name: 'Szkice i oczekujące' }).getAttribute('aria-selected')).toBe('true')
  })

  it('zamyka akcję fail-closed, jeśli świeże capabilities przed formularzem odmawia CREATE', async () => {
    fetchRawMaterialCapabilitiesMock
      .mockResolvedValueOnce({
        module: 'RAW_MATERIALS',
        locationId: locationA,
        readiness: 'READY',
        available: true,
        permissions: { create: true, edit: false },
        actions: { create: true, edit: false },
      })
      .mockResolvedValueOnce({
        module: 'RAW_MATERIALS',
        locationId: locationA,
        readiness: 'READY',
        available: true,
        permissions: { create: false, edit: false },
        actions: { create: false, edit: false },
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
      permissions: { create: true, edit: false },
      actions: { create: false, edit: false },
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
      permissions: { create: false, edit: false },
      actions: { create: false, edit: false },
    })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    await screen.findByText('Surowiec 1')
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()
  })

  it('akceptuje globalne EDIT bez udostępniania formularza lub rezerwacji SKU', async () => {
    fetchRawMaterialCapabilitiesMock.mockResolvedValue({
      module: 'RAW_MATERIALS',
      locationId: locationA,
      readiness: 'READY',
      available: true,
      permissions: { create: false, edit: true },
      actions: { create: false, edit: true },
    })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    await screen.findByText('Surowiec 1')
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Edytuj surowiec' })).toBeNull()
    expect(reserveRawMaterialSkuMock).not.toHaveBeenCalled()
    expect(createRawMaterialMock).not.toHaveBeenCalled()
  })

  it('pokazuje akcję dopiero po capabilities i completion oraz odświeża ten sam rekord po zapisie', async () => {
    let resolveCatalogRefresh: ((value: CatalogPage) => void) | undefined
    fetchRawMaterialCapabilitiesMock.mockResolvedValue({
      module: 'RAW_MATERIALS',
      locationId: locationA,
      readiness: 'READY',
      available: true,
      permissions: { create: false, edit: true },
      actions: { create: false, edit: true },
    })
    fetchRawMaterialCompletionMock.mockResolvedValue({
      lifecycleState: 'ACTIVE',
      revisionId: `revision-${materialA}`,
      version: `version-${materialA}`,
      availableActions: ['edit'],
    })
    fetchRawMaterialEditorMock.mockImplementation(async (id) => editableEditor(id))
    reviseRawMaterialMock.mockResolvedValue(revisedMaterial)
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)

    await screen.findByText('Surowiec 1')
    fetchCatalogMock.mockImplementationOnce(() => new Promise((resolve) => {
      resolveCatalogRefresh = resolve
    }))
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))
    const detailDialog = await screen.findByRole('dialog', { name: 'Mąka pszenna' })
    const editAction = await within(detailDialog).findByRole('button', { name: 'Edytuj surowiec' })
    fireEvent.click(editAction)
    const editDialog = await screen.findByRole('dialog', { name: 'Edytuj surowiec' })
    expect(await within(editDialog).findByLabelText(/Nazwa/)).toBeTruthy()
    fireEvent.click(within(editDialog).getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    expect(await screen.findByText('Zapisano nową rewizję roboczą v4')).toBeTruthy()
    await waitFor(() => expect(fetchCatalogMock.mock.calls.length).toBeGreaterThan(1))
    expect(screen.getByText('Aktualizowanie katalogu…').closest('[role="status"]')).toBeTruthy()
    expect(document.querySelector('.search-loading-spinner')).toBeTruthy()
    await act(async () => { resolveCatalogRefresh?.(page()) })
    await waitFor(() => expect(document.querySelector('.search-loading-spinner')).toBeNull())
    await waitFor(() => expect(fetchRawMaterialDetailMock.mock.calls.length).toBeGreaterThan(1))
    expect(screen.getByRole('dialog', { name: 'Edytuj surowiec' })).toBeTruthy()
    expect(fetchRawMaterialCompletionMock).toHaveBeenCalledWith(materialA, expect.any(AbortSignal))
    expect(reserveRawMaterialSkuMock).not.toHaveBeenCalled()
    expect(createRawMaterialMock).not.toHaveBeenCalled()
  })

  it('nie pokazuje akcji edycji bez rekordu edit nawet przy globalnym EDIT', async () => {
    fetchRawMaterialCapabilitiesMock.mockResolvedValue({
      module: 'RAW_MATERIALS',
      locationId: locationA,
      readiness: 'READY',
      available: true,
      permissions: { create: false, edit: true },
      actions: { create: false, edit: true },
    })
    fetchRawMaterialCompletionMock.mockResolvedValue({
      lifecycleState: 'ACTIVE',
      revisionId: `revision-${materialA}`,
      version: null,
      availableActions: [],
    })
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} operatingLocationId={locationA} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))
    await screen.findByText('Opis zatwierdzonego snapshotu.')

    expect(screen.queryByRole('button', { name: 'Edytuj surowiec' })).toBeNull()
    expect(fetchRawMaterialEditorMock).not.toHaveBeenCalled()
    expect(reviseRawMaterialMock).not.toHaveBeenCalled()
  })

  it('zamyka edytor i odrzuca jego kontekst po zmianie lokalizacji', async () => {
    fetchRawMaterialCapabilitiesMock.mockImplementation(async (locationId) => ({
      module: 'RAW_MATERIALS',
      locationId,
      readiness: 'READY',
      available: true,
      permissions: { create: false, edit: locationId === locationA },
      actions: { create: false, edit: locationId === locationA },
    }))
    fetchRawMaterialCompletionMock.mockResolvedValue({
      lifecycleState: 'ACTIVE',
      revisionId: `revision-${materialA}`,
      version: `version-${materialA}`,
      availableActions: ['edit'],
    })
    fetchRawMaterialEditorMock.mockImplementation(async (id) => editableEditor(id))
    const onSessionExpired = vi.fn()
    const { rerender } = render(
      <RawMaterialsCatalogPage onSessionExpired={onSessionExpired} operatingLocationId={locationA} />,
    )
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))
    const detailDialog = await screen.findByRole('dialog', { name: 'Mąka pszenna' })
    fireEvent.click(await within(detailDialog).findByRole('button', { name: 'Edytuj surowiec' }))
    await screen.findByRole('dialog', { name: 'Edytuj surowiec' })

    rerender(<RawMaterialsCatalogPage onSessionExpired={onSessionExpired} operatingLocationId={locationB} />)
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Edytuj surowiec' })).toBeNull())
    expect(screen.queryByRole('dialog', { name: 'Mąka pszenna' })).toBeNull()
    expect(reviseRawMaterialMock).not.toHaveBeenCalled()
    expect(onSessionExpired).not.toHaveBeenCalled()
  })

  it('fail-closed po błędzie capabilities i odświeża wynik po powrocie karty do aktywnego stanu', async () => {
    fetchRawMaterialCapabilitiesMock.mockRejectedValueOnce(new Error('Brak połączenia.'))
    fetchRawMaterialCapabilitiesMock.mockResolvedValueOnce({
      module: 'RAW_MATERIALS',
      locationId: locationA,
      readiness: 'READY',
      available: true,
      permissions: { create: true, edit: false },
      actions: { create: true, edit: false },
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
        permissions: { create: false, edit: false },
        actions: { create: false, edit: false },
      }))
    const onSessionExpired = vi.fn()
    const { rerender } = render(
      <RawMaterialsCatalogPage onSessionExpired={onSessionExpired} operatingLocationId={locationA} />,
    )
    await waitFor(() => expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledTimes(1))

    rerender(<RawMaterialsCatalogPage onSessionExpired={onSessionExpired} operatingLocationId={locationB} />)
    await screen.findByText('Surowiec 1')
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()
    await act(async () => {
      resolvePrevious?.({
        module: 'RAW_MATERIALS',
        locationId: locationA,
        readiness: 'READY',
        available: true,
        permissions: { create: true, edit: true },
        actions: { create: true, edit: true },
      })
    })

    expect(fetchRawMaterialCapabilitiesMock).toHaveBeenCalledTimes(2)
    expect(screen.queryByRole('button', { name: 'Dodaj surowiec' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Edytuj surowiec' })).toBeNull()
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
    await within(table).findByText('Surowiec 1')
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

  it('wyszukuje ze strony drugiej jednym żądaniem i nie podmienia wyników przed debounce', async () => {
    fetchCatalogMock.mockImplementation(async (query: CatalogQuery) =>
      page([row(query.page === 1 ? materialA : materialB, query.page)], query.page, 51))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    await waitFor(() => expect((screen.getByRole('button', { name: 'Następna' }) as HTMLButtonElement).disabled).toBe(false))
    fireEvent.click(screen.getByRole('button', { name: 'Następna' }))
    await screen.findByText('Surowiec 2')
    const table = screen.getByRole('table', { name: 'Katalog surowców' })
    fetchCatalogMock.mockClear()
    vi.useFakeTimers()

    const search = screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' })
    fireEvent.change(search, { target: { value: 'mą' } })
    fireEvent.change(search, { target: { value: 'mąka' } })
    await act(async () => { await vi.advanceTimersByTimeAsync(299) })
    expect(fetchCatalogMock).not.toHaveBeenCalled()
    expect(within(table).getByText('Surowiec 2')).toBeTruthy()
    expect(screen.getByRole('table', { name: 'Katalog surowców' })).toBe(table)

    await act(async () => { await vi.advanceTimersByTimeAsync(1) })
    expect(fetchCatalogMock).toHaveBeenCalledTimes(1)
    expect(fetchCatalogMock).toHaveBeenCalledWith(
      expect.objectContaining({ search: 'mąka', page: 1 }),
      expect.any(AbortSignal),
    )
  })

  it('łączy oczekującą frazę z filtrem i X usuwa tylko frazę bez dodatkowego żądania', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    await screen.findByRole('option', { name: 'Mąki' })
    fetchCatalogMock.mockClear()
    const search = screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' })
    fireEvent.change(search, { target: { value: 'mąka' } })
    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według kategorii' }), {
      target: { value: 'category-1' },
    })
    expect(fetchCatalogMock).toHaveBeenCalledTimes(1)
    expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ search: 'mąka', categoryId: 'category-1', page: 1 }),
      expect.any(AbortSignal),
    )
    await act(async () => { await new Promise((resolve) => window.setTimeout(resolve, 350)) })
    expect(fetchCatalogMock).toHaveBeenCalledTimes(1)

    fireEvent.click(screen.getByRole('button', { name: 'Wyczyść: Szukaj surowca po nazwie lub SKU' }))
    expect(document.activeElement).toBe(search)
    expect((search as HTMLInputElement).value).toBe('')
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenCalledTimes(2))
    expect(fetchCatalogMock).toHaveBeenCalledTimes(2)
    expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ search: '', categoryId: 'category-1', page: 1 }),
      expect.any(AbortSignal),
    )
  })

  it('anuluje żądanie przy kolejnych zmianach filtra i ignoruje jego spóźnioną odpowiedź', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    let resolveStale: ((result: CatalogPage) => void) | undefined
    let staleSignal: AbortSignal | undefined
    fetchCatalogMock
      .mockImplementationOnce((_query, signal) => {
        staleSignal = signal
        return new Promise((resolve) => { resolveStale = resolve })
      })
      .mockResolvedValueOnce(page([row(materialB, 2, { name: 'Wynik filtra jednostki' })]))
    const table = screen.getByRole('table', { name: 'Katalog surowców' })
    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według statusu' }), { target: { value: 'DRAFT' } })
    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według jednostki' }), { target: { value: 'kg' } })
    expect(staleSignal?.aborted).toBe(true)
    expect(await screen.findByText('Wynik filtra jednostki')).toBeTruthy()
    await act(async () => { resolveStale?.(page([row(materialA, 1, { name: 'Nieaktualny filtr' })])) })
    expect(screen.queryByText('Nieaktualny filtr')).toBeNull()
    expect(screen.getByRole('table', { name: 'Katalog surowców' })).toBe(table)
  })

  it('izoluje wiersze i opóźnioną odpowiedź po zmianie klucza kontekstu użytkownika i lokalizacji', async () => {
    let resolveOld: ((result: CatalogPage) => void) | undefined
    let resolveNew: ((result: CatalogPage) => void) | undefined
    let oldSignal: AbortSignal | undefined
    fetchCatalogMock
      .mockResolvedValueOnce(page([row(materialA, 1, { name: 'Poprzedni kontekst' })]))
      .mockImplementationOnce((_query, signal) => {
        oldSignal = signal
        return new Promise((resolve) => { resolveOld = resolve })
      })
      .mockImplementationOnce(() => new Promise((resolve) => { resolveNew = resolve }))
    const onSessionExpired = vi.fn()
    const { rerender } = render(
      <RawMaterialsCatalogPage key={`user-a:${locationA}`} operatingLocationId={locationA} onSessionExpired={onSessionExpired} />,
    )
    await screen.findByText('Poprzedni kontekst')
    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według statusu' }), { target: { value: 'DRAFT' } })
    expect(oldSignal?.aborted).toBe(false)

    rerender(
      <RawMaterialsCatalogPage key={`user-b:${locationB}`} operatingLocationId={locationB} onSessionExpired={onSessionExpired} />,
    )
    expect(oldSignal?.aborted).toBe(true)
    expect(screen.queryByText('Poprzedni kontekst')).toBeNull()
    await act(async () => { resolveOld?.(page([row(materialA, 1, { name: 'Spóźnione dane poprzedniego kontekstu' })])) })
    expect(screen.queryByText('Spóźnione dane poprzedniego kontekstu')).toBeNull()
    await act(async () => { resolveNew?.(page([row(materialB, 1, { name: 'Nowy kontekst' })])) })
    expect(await screen.findByText('Nowy kontekst')).toBeTruthy()
  })

  it('pokazuje dostępny wskaźnik podczas bieżącego wyszukiwania bez komunikatu nad tabelą', async () => {
    let resolveSearch: ((result: CatalogPage) => void) | undefined
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fetchCatalogMock.mockImplementationOnce(() => new Promise((resolve) => {
      resolveSearch = resolve
    }))
    vi.useFakeTimers()

    const search = screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' })
    fireEvent.change(search, { target: { value: 'mąka' } })
    expect((search as HTMLInputElement).disabled).toBe(false)
    expect(screen.queryByText('Aktualizowanie wyników wyszukiwania…')).toBeNull()

    await act(async () => { await vi.advanceTimersByTimeAsync(300) })

    expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ search: 'mąka' }),
      expect.any(AbortSignal),
    )
    expect(screen.getByText('Aktualizowanie wyników wyszukiwania…').closest('[role="status"]')).toBeTruthy()
    expect(document.querySelector('.search-loading-spinner')).toBeTruthy()
    expect(document.querySelector('.refresh-status')).toBeNull()
    expect((search as HTMLInputElement).disabled).toBe(false)
    expect((search as HTMLInputElement).value).toBe('mąka')

    await act(async () => { resolveSearch?.(page([row(materialB, 2, { name: 'Mąka znaleziona' })])) })

    expect(screen.getByText('Mąka znaleziona')).toBeTruthy()
    expect(document.querySelector('.search-loading-spinner')).toBeNull()
    expect(screen.queryByText('Aktualizowanie wyników wyszukiwania…')).toBeNull()
  })

  it('utrzymuje wskaźnik resetu przy bieżącym żądaniu i ignoruje spóźnioną odpowiedź wyszukiwania', async () => {
    let resolveStale: ((result: CatalogPage) => void) | undefined
    let resolveReset: ((result: CatalogPage) => void) | undefined
    let staleSignal: AbortSignal | undefined
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fetchCatalogMock
      .mockImplementationOnce((_query, signal) => {
        staleSignal = signal
        return new Promise((resolve) => { resolveStale = resolve })
      })
      .mockImplementationOnce(() => new Promise((resolve) => { resolveReset = resolve }))
    vi.useFakeTimers()

    fireEvent.change(screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' }), {
      target: { value: 'stare zapytanie' },
    })
    await act(async () => { await vi.advanceTimersByTimeAsync(300) })
    expect(screen.getByText('Aktualizowanie wyników wyszukiwania…')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Wyczyść filtry' }))
    expect(staleSignal?.aborted).toBe(true)
    expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ search: '' }),
      expect.any(AbortSignal),
    )
    expect(screen.getByText('Aktualizowanie katalogu…')).toBeTruthy()

    await act(async () => { resolveStale?.(page([row(materialB, 2, { name: 'Nieaktualny wynik' })])) })
    expect(screen.queryByText('Nieaktualny wynik')).toBeNull()
    expect(screen.getByText('Aktualizowanie katalogu…')).toBeTruthy()

    await act(async () => { resolveReset?.(page([row(materialA, 1, { name: 'Wynik po resecie' })])) })
    expect(screen.getByText('Wynik po resecie')).toBeTruthy()
    expect(screen.queryByText('Aktualizowanie katalogu…')).toBeNull()
    expect(document.querySelector('.search-loading-spinner')).toBeNull()
  })

  it('czyści wskaźnik i zachowuje stan błędu po nieudanym żądaniu wyszukiwania', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fetchCatalogMock.mockRejectedValueOnce(new Error('Błąd wyszukiwania testowego.'))
    vi.useFakeTimers()

    fireEvent.change(screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' }), {
      target: { value: 'błąd' },
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300)
      await Promise.resolve()
      await Promise.resolve()
    })

    expect(screen.getByText(/Nie udało się odświeżyć katalogu/)).toBeTruthy()
    expect(document.querySelector('.search-loading-spinner')).toBeNull()
    expect(screen.queryByText('Aktualizowanie wyników wyszukiwania…')).toBeNull()
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

  it('resetuje pojedynczy filtr i nie wysyła żądania, gdy filtry są już domyślne', async () => {
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    await screen.findByRole('option', { name: 'Mąki' })
    const catalogElement = document.querySelector('.catalog-page')
    const tableElement = document.querySelector('.table-scroll')

    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według kategorii' }), {
      target: { value: 'category-1' },
    })
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ categoryId: 'category-1' }),
      expect.any(AbortSignal),
    ))
    expect(document.querySelector('.catalog-page')).toBe(catalogElement)
    expect(document.querySelector('.table-scroll')).toBe(tableElement)

    const resetButton = screen.getByRole('button', { name: 'Wyczyść filtry' })
    expect(resetButton.tagName).toBe('BUTTON')
    expect(resetButton.getAttribute('type')).toBe('button')
    fireEvent.click(resetButton)
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ categoryId: '', page: 1 }),
      expect.any(AbortSignal),
    ))
    expect((screen.getByRole('combobox', {
      name: 'Filtruj według kategorii',
    }) as HTMLSelectElement).value).toBe('')
    expect(document.querySelector('.catalog-page')).toBe(catalogElement)
    expect(document.querySelector('.table-scroll')).toBe(tableElement)

    const requestCount = fetchCatalogMock.mock.calls.length
    fireEvent.click(resetButton)
    expect(fetchCatalogMock).toHaveBeenCalledTimes(requestCount)
  })

  it('czyści jednocześnie wyszukiwanie i wszystkie filtry, wraca na stronę 1 i zachowuje sortowanie', async () => {
    fetchCatalogMock.mockImplementation(async (query: CatalogQuery) =>
      page([row(materialA, query.page)], query.page, 51))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    await screen.findByRole('option', { name: 'Mąki' })
    await screen.findByRole('searchbox', { name: 'Dostawca' })

    fireEvent.click(screen.getByRole('button', { name: 'Sortuj według: SKU' }))
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'sku', direction: 'asc' }),
      expect.any(AbortSignal),
    ))
    fireEvent.click(screen.getByRole('button', { name: 'Sortuj według: SKU' }))
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'sku', direction: 'desc' }),
      expect.any(AbortSignal),
    ))

    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według kategorii' }), {
      target: { value: 'category-1' },
    })
    const supplierInput = screen.getByRole('searchbox', { name: 'Dostawca' })
    fireEvent.change(supplierInput, { target: { value: 'Dostawca A' } })
    fireEvent.click(await screen.findByRole('button', { name: 'Dostawca A (SUP-01)' }))
    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według statusu' }), {
      target: { value: 'DRAFT' },
    })
    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według jednostki' }), {
      target: { value: 'kg' },
    })
    fireEvent.change(screen.getByRole('searchbox', { name: 'Szukaj surowca po nazwie lub SKU' }), {
      target: { value: 'mąka' },
    })
    vi.useFakeTimers()
    await act(async () => { await vi.advanceTimersByTimeAsync(300) })
    vi.useRealTimers()

    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({
        search: 'mąka',
        categoryId: 'category-1',
        supplierId: 'supplier-1',
        lifecycleState: 'DRAFT',
        unit: 'kg',
        sort: 'sku',
        direction: 'desc',
        page: 1,
      }),
      expect.any(AbortSignal),
    ))
    await waitFor(() => expect((screen.getByRole('button', { name: 'Następna' }) as HTMLButtonElement).disabled).toBe(false))
    fireEvent.click(screen.getByRole('button', { name: 'Następna' }))
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ page: 2, sort: 'sku', direction: 'desc' }),
      expect.any(AbortSignal),
    ))

    fireEvent.click(screen.getByRole('button', { name: 'Wyczyść filtry' }))
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({
        search: '',
        categoryId: '',
        supplierId: '',
        lifecycleState: '',
        unit: '',
        sort: 'sku',
        direction: 'desc',
        page: 1,
      }),
      expect.any(AbortSignal),
    ))
    expect((screen.getByRole('searchbox', {
      name: 'Szukaj surowca po nazwie lub SKU',
    }) as HTMLInputElement).value).toBe('')
    expect((screen.getByRole('searchbox', { name: 'Dostawca' }) as HTMLInputElement).value).toBe('')
    await screen.findByText('Strona 1 z 3')
  })

  it('anuluje stare żądanie katalogu, aby jego odpowiedź nie nadpisała wyników po resecie', async () => {
    let resolveStale: ((result: CatalogPage) => void) | undefined
    let staleSignal: AbortSignal | undefined
    fetchCatalogMock
      .mockResolvedValueOnce(page())
      .mockImplementationOnce((_query, signal) => {
        staleSignal = signal
        return new Promise((resolve) => { resolveStale = resolve })
      })
      .mockResolvedValueOnce(page([row(materialB, 2, { name: 'Nowy wynik' })]))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    await screen.findByRole('option', { name: 'Mąki' })

    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według kategorii' }), {
      target: { value: 'category-1' },
    })
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenCalledTimes(2))
    expect(staleSignal?.aborted).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'Wyczyść filtry' }))
    expect(await screen.findByText('Nowy wynik')).toBeTruthy()
    expect(staleSignal?.aborted).toBe(true)
    await act(async () => {
      resolveStale?.(page([row(materialA, 1, { name: 'Stary wynik' })]))
    })
    expect(screen.queryByText('Stary wynik')).toBeNull()
    expect(screen.getByText('Nowy wynik')).toBeTruthy()
  })

  it('zachowuje poprzednie wiersze i kontener podczas pobierania nowych wyników filtra', async () => {
    let resolveRefresh: ((result: CatalogPage) => void) | undefined
    fetchCatalogMock
      .mockResolvedValueOnce(page())
      .mockImplementationOnce(() => new Promise((resolve) => { resolveRefresh = resolve }))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    await screen.findByRole('option', { name: 'Mąki' })
    const tableViewport = document.querySelector('.table-scroll')

    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według kategorii' }), {
      target: { value: 'category-1' },
    })
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenCalledTimes(2))
    expect(screen.getByText('Surowiec 1')).toBeTruthy()
    expect(screen.getByText('Aktualizowanie wyników…')).toBeTruthy()
    expect(document.querySelector('.table-scroll')).toBe(tableViewport)

    await act(async () => { resolveRefresh?.(page([row(materialB, 2, { name: 'Nowy wynik' })])) })
    expect(await screen.findByText('Nowy wynik')).toBeTruthy()
    expect(document.querySelector('.table-scroll')).toBe(tableViewport)
  })

  it('dopasowuje tabelę do faktycznych wierszy i umieszcza paginację bezpośrednio pod nią', async () => {
    fetchCatalogMock.mockResolvedValue(page([
      row(materialA, 1),
      row(materialB, 2),
    ], 1, 2))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)

    const table = await screen.findByRole('table', { name: 'Katalog surowców' })
    await within(table).findByText('Surowiec 2')
    expect(within(table).getAllByRole('row')).toHaveLength(3)
    const tableContainer = table.parentElement
    const pagination = screen.getByRole('navigation', { name: 'Stronicowanie katalogu' })
    expect(tableContainer?.nextElementSibling).toBe(pagination)
    expect(tableContainer?.getAttribute('style') ?? '').not.toMatch(/height|min-height/)
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

  it('utrzymuje paginację zgodną z wyświetlonymi wynikami podczas zmiany rozmiaru strony', async () => {
    let resolveRefresh: ((result: CatalogPage) => void) | undefined
    fetchCatalogMock
      .mockResolvedValueOnce(page([row(materialA, 1)], 1, 51))
      .mockImplementationOnce(() => new Promise((resolve) => { resolveRefresh = resolve }))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    expect(screen.getByText('Strona 1 z 3')).toBeTruthy()
    fireEvent.change(screen.getByRole('combobox', { name: 'Liczba wierszy na stronę' }), {
      target: { value: '50' },
    })
    expect(screen.getByText('Strona 1 z 3')).toBeTruthy()
    const updated = page([row(materialB, 1)], 1, 51)
    updated.meta.perPage = 50
    updated.meta.lastPage = 2
    await act(async () => { resolveRefresh?.(updated) })
    expect(await screen.findByText('Strona 1 z 2')).toBeTruthy()
  })

  it('pokazuje polską etykietę sztuki i pozwala przejść bezpośrednio do numeru strony', async () => {
    fetchCatalogMock.mockImplementation(async (query: CatalogQuery) => page(
      [row(materialA, query.page, { name: `Surowiec ${query.page}`, unit: 'pc' })],
      query.page,
      300,
    ))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')

    expect(within(screen.getByRole('row', { name: /Surowiec 1/ })).getByText('szt. — sztuka')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Sortuj według: SKU' }))
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'sku', direction: 'asc' }),
      expect.any(AbortSignal),
    ))
    fireEvent.change(screen.getByRole('combobox', { name: 'Filtruj według kategorii' }), {
      target: { value: 'category-1' },
    })
    await waitFor(() => expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ categoryId: 'category-1', sort: 'sku', direction: 'asc' }),
      expect.any(AbortSignal),
    ))

    expect(screen.getByRole('button', { name: 'Przejdź do strony 5' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Przejdź do strony 12' })).toBeTruthy()
    expect(screen.getByText('…')).toBeTruthy()
    await waitFor(() => expect((screen.getByRole('button', { name: 'Przejdź do strony 5' }) as HTMLButtonElement).disabled).toBe(false))
    fireEvent.click(screen.getByRole('button', { name: 'Przejdź do strony 5' }))

    expect(await screen.findByText('Surowiec 5')).toBeTruthy()
    expect(fetchCatalogMock).toHaveBeenLastCalledWith(
      expect.objectContaining({ page: 5, perPage: 25, categoryId: 'category-1', sort: 'sku', direction: 'asc' }),
      expect.any(AbortSignal),
    )
    expect(screen.getByRole('button', { name: 'Przejdź do strony 5' }).getAttribute('aria-current')).toBe('page')
    expect(screen.getByText('Strona 5 z 12')).toBeTruthy()
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

  it('ładuje istniejącą historię cen wyłącznie przy przyznanym uprawnieniu', async () => {
    fetchCatalogMock.mockResolvedValue(page([row(materialA, 1)], 1, 1, {
      canReadReferencePrice: true,
      canReadUsableCost: false,
    }))
    const prices: RawMaterialReferencePrice[] = [{
      id: 'price-event-1',
      eventNo: 2,
      eventType: 'PRICE_SET',
      effectiveFrom: '2025-02-01',
      netPlnPerBaseUnit: '12.50000000',
      netPlnPerPackage: '25.00000000',
      pricingInputBasis: 'NOMINAL_UNIT',
      pricingInputAmount: '10.00000000000000',
      pricingInputUnitCode: 'kg',
      pricingNominalQuantity: '2800.000000',
      pricingUsableQuantity: '1500.000000',
      pricingBaseUnitCode: 'g',
      currencyCode: 'PLN',
    }, {
      id: 'price-event-2',
      eventNo: 1,
      eventType: 'PRICE_SET',
      effectiveFrom: '2025-01-01',
      netPlnPerBaseUnit: '10.00000000',
      netPlnPerPackage: '20.00000000',
      pricingInputBasis: null,
      pricingInputAmount: null,
      pricingInputUnitCode: null,
      pricingNominalQuantity: null,
      pricingUsableQuantity: null,
      pricingBaseUnitCode: null,
      currencyCode: 'PLN',
    }]
    fetchRawMaterialReferencePricesMock.mockResolvedValue(prices)
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))

    const dialog = await screen.findByRole('dialog', { name: 'Mąka pszenna' })
    expect(await within(dialog).findByRole('img', { name: /Historia cen netto w PLN/ })).toBeTruthy()
    expect(within(dialog).getByText('Historia cen')).toBeTruthy()
    expect(within(dialog).getByText(/Podstawa: jednostka nominalna \(kg\)/)).toBeTruthy()
    expect(within(within(dialog).getByRole('list', { name: 'Szczegóły historii cen' }))
      .getByText(/nominalna/)).toBeTruthy()
    expect(fetchRawMaterialReferencePricesMock).toHaveBeenCalledWith(materialA, expect.any(AbortSignal))
  })

  it('otwiera panel zatwierdzonego snapshotu i historii bez zmiany zaznaczenia', async () => {
    fetchCatalogMock.mockResolvedValue(page(
      [row(materialA, 1)],
      1,
      25,
      { canReadReferencePrice: true, canReadUsableCost: true },
    ))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz Surowiec 1' }))
    const trigger = screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' })
    fireEvent.click(trigger)

    const dialog = await screen.findByRole('dialog', { name: 'Mąka pszenna' })
    expect(await within(dialog).findByText('Opis zatwierdzonego snapshotu.')).toBeTruthy()
    expect(dialog.querySelector('#detail-identity-title')?.closest('.detail-section')?.classList.contains('detail-section-wide')).toBe(false)
    expect(dialog.querySelector('#detail-quantities-title')?.closest('.detail-section')?.classList.contains('detail-section-wide')).toBe(false)
    expect(dialog.querySelector('#detail-prices-title')?.closest('.detail-section')?.classList.contains('detail-section-wide')).toBe(true)
    expect(dialog.querySelector('.detail-content')?.children).toHaveLength(3)
    await waitFor(() => expect(dialog.closest('.ui-drawer-backdrop')?.classList.contains('is-open')).toBe(true))
    expect(within(dialog).getByText('Wersja 3 — zatwierdzona i obowiązująca')).toBeTruthy()
    expect(within(dialog).getByText('Mąki')).toBeTruthy()
    expect(within(dialog).getByText('123,45 PLN')).toBeTruthy()
    expect(within(dialog).getByText('15,43 PLN/100g')).toBeTruthy()
    expect(fetchRawMaterialDetailMock).toHaveBeenCalledWith(materialA, expect.any(AbortSignal))
    expect(fetchRawMaterialRevisionsMock).toHaveBeenCalledWith(materialA, expect.any(AbortSignal))
    const closeButton = within(dialog).getByRole('button', { name: 'Zamknij szczegóły surowca' })
    expect(document.activeElement).toBe(closeButton)
    expect(dialog.closest('.ui-drawer-backdrop')?.classList.contains('is-open')).toBe(true)
    fireEvent.keyDown(dialog, { key: 'Tab' })
    fireEvent.keyDown(dialog, { key: 'Tab', shiftKey: true })
    const focusable = dialog.querySelectorAll<HTMLElement>(
      'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])',
    )
    expect(focusable.length).toBeGreaterThan(1)
    focusable[0].focus()
    fireEvent.keyDown(dialog, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(focusable[focusable.length - 1])
    focusable[focusable.length - 1].focus()
    fireEvent.keyDown(dialog, { key: 'Tab' })
    expect(document.activeElement).toBe(focusable[0])

    expect(document.body.style.overflow).toBe('hidden')
    fireEvent.click(within(dialog).getByRole('tab', { name: 'Historia zmian' }))
    expect(await within(dialog).findByText('Wersja 2 · Mąka pszenna — poprzednia')).toBeTruthy()
    expect(within(dialog).getByRole('heading', { name: 'Historia zmian' })).toBeTruthy()
    fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(screen.getByRole('dialog', { name: 'Mąka pszenna' })).toBeTruthy()
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(document.activeElement).toBe(trigger)
    expect(document.body.style.overflow).toBe('')
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
    fireEvent.click(within(dialog).getByRole('tab', { name: 'Historia zmian' }))
    expect(within(dialog).getByText('Historia zmian nie jest dostępna w bieżącym zakresie uprawnień.')).toBeTruthy()
    expect(fetchRawMaterialRevisionsMock).not.toHaveBeenCalled()
    expect(fetchRawMaterialReferencePricesMock).not.toHaveBeenCalled()
  })

  it('czyści cenę szczegółów po cofnięciu uprawnienia do historii cen', async () => {
    fetchCatalogMock.mockResolvedValue(page([row(materialA, 1, {
      referencePrice: '12.5',
    })], 1, 25, { canReadReferencePrice: true, canReadUsableCost: true }))
    fetchRawMaterialReferencePricesMock.mockRejectedValue(new ApiError(403, 'Brak dostępu.'))
    render(<RawMaterialsCatalogPage onSessionExpired={vi.fn()} />)
    await screen.findByText('Surowiec 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pokaż szczegóły: Surowiec 1' }))

    const dialog = await screen.findByRole('dialog', { name: 'Mąka pszenna' })
    expect(await within(dialog).findByText('Historia cen nie jest dostępna w bieżącym kontekście.')).toBeTruthy()
    expect(within(dialog).queryByText('Cena netto')).toBeNull()
    expect(within(dialog).queryByText('12,50 PLN')).toBeNull()
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

    const dialog = await screen.findByRole('dialog', { name: 'Mąka pszenna' })
    fireEvent.click(within(dialog).getByRole('tab', { name: 'Historia zmian' }))
    expect(await within(dialog).findByText('Historia zmian nie jest dostępna w bieżącym kontekście.')).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('tab', { name: 'Przegląd' }))
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
    const dialog = await screen.findByRole('dialog', { name: 'Mąka pszenna' })
    await within(dialog).findByText('Opis zatwierdzonego snapshotu.')
    fireEvent.click(within(dialog).getByRole('tab', { name: 'Historia zmian' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Zamknij szczegóły surowca' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())

    await act(async () => {
      resolveRevisions?.(revisionHistory)
      await Promise.resolve()
    })
    expect(screen.queryByText(/Mąka pszenna — poprzednia/)).toBeNull()
  })
})
