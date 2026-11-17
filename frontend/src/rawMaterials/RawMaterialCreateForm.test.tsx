import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import type { CategoryOption } from './catalog'
import RawMaterialCreateForm from './RawMaterialCreateForm'
import {
  createRawMaterial,
  createApprovedRawMaterial,
  fetchAllergenCatalogs,
  fetchRawMaterialUnits,
  previewRawMaterialPricing,
  reserveRawMaterialSku,
  type CreatedRawMaterial,
  type RawMaterialSkuReservation,
} from './api'

vi.mock('./api', async (importOriginal) => ({
  ...await importOriginal<typeof import('./api')>(),
  createRawMaterial: vi.fn(),
  createApprovedRawMaterial: vi.fn(),
  fetchAllergenCatalogs: vi.fn(),
  fetchRawMaterialUnits: vi.fn(),
  previewRawMaterialPricing: vi.fn(),
  reserveRawMaterialSku: vi.fn(),
}))

const categoryId = '00000000-0000-4000-8000-000000000001'
const locationId = '00000000-0000-4000-8000-000000000002'
const reservationId = '00000000-0000-4000-8000-000000000003'
const materialId = '00000000-0000-4000-8000-000000000004'
const reservation: RawMaterialSkuReservation = {
  reservationId,
  sku: 'RAW-000042',
  expiresAt: '2026-11-11T12:00:00Z',
  status: 'RESERVED',
}
const created: CreatedRawMaterial = {
  id: materialId,
  sku: 'RAW-000042',
  lifecycleState: 'DRAFT',
}
const categories: CategoryOption[] = [{ id: categoryId, name: 'Produkty spożywcze' }]
const reserveMock = vi.mocked(reserveRawMaterialSku)
const createMock = vi.mocked(createRawMaterial)
const createApprovedMock = vi.mocked(createApprovedRawMaterial)
const allergenCatalogId = '00000000-0000-4000-8000-000000000005'

function renderForm(overrides: Partial<React.ComponentProps<typeof RawMaterialCreateForm>> = {}) {
  const props: React.ComponentProps<typeof RawMaterialCreateForm> = {
    categories,
    operatingLocationId: locationId,
    returnFocus: null,
    onClose: vi.fn(),
    onCreated: vi.fn(),
    onOpenCreated: vi.fn(),
    onSessionExpired: vi.fn(),
    onAuthorizationDenied: vi.fn(),
    onModuleUnavailable: vi.fn(),
    ...overrides,
  }
  return { ...render(<RawMaterialCreateForm {...props} />), props }
}

function enterValidBasicFields() {
  fireEvent.change(screen.getByLabelText(/Kategoria/), { target: { value: categoryId } })
  fireEvent.change(screen.getByLabelText(/Nazwa surowca/), { target: { value: 'Mąka testowa' } })
  fireEvent.change(screen.getByLabelText(/Nominalna/), { target: { value: '10' } })
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.spyOn(crypto, 'randomUUID').mockReturnValue('00000000-0000-4000-8000-000000000099')
  reserveMock.mockResolvedValue(reservation)
  createMock.mockResolvedValue(created)
  createApprovedMock.mockResolvedValue({ ...created, lifecycleState: 'ACTIVE' })
  vi.mocked(fetchAllergenCatalogs).mockResolvedValue([{ id: allergenCatalogId, versionNumber: 1, isCurrent: true, definitions: [] }])
  vi.mocked(fetchRawMaterialUnits).mockResolvedValue([
    { code: 'mg', name: 'miligram', symbol: 'mg', dimension: 'MASS', baseUnitCode: 'g', toBaseFactor: '0.001000000000', archivedAt: null, isBase: false },
    { code: 'g', name: 'gram', symbol: 'g', dimension: 'MASS', baseUnitCode: 'g', toBaseFactor: '1.000000000000', archivedAt: null, isBase: true },
    { code: 'kg', name: 'kilogram', symbol: 'kg', dimension: 'MASS', baseUnitCode: 'g', toBaseFactor: '1000.000000000000', archivedAt: null, isBase: false },
    { code: 'ml', name: 'mililitr', symbol: 'ml', dimension: 'VOLUME', baseUnitCode: 'ml', toBaseFactor: '1.000000000000', archivedAt: null, isBase: true },
    { code: 'l', name: 'litr', symbol: 'l', dimension: 'VOLUME', baseUnitCode: 'ml', toBaseFactor: '1000.000000000000', archivedAt: null, isBase: false },
    { code: 'pc', name: 'sztuka', symbol: 'szt.', dimension: 'COUNT', baseUnitCode: 'pc', toBaseFactor: '1.000000000000', archivedAt: null, isBase: true },
  ])
  vi.mocked(previewRawMaterialPricing).mockResolvedValue({
    netPlnPerPackage: '28.00000000000000', usableUnitCost: '1.86666666666667', usableCostBasis: 'PLN/100g',
  })
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('formularz tworzenia surowca RAW', () => {
  it('pokazuje i serializuje cenę wyłącznie przy przyznanym uprawnieniu', async () => {
    renderForm()
    expect(screen.queryByRole('heading', { name: /Cena zakupu/ })).toBeNull()
    cleanup()

    renderForm({ canManageReferencePrice: true, canViewReferencePrice: true })
    expect(screen.getByRole('heading', { name: /Cena zakupu/ })).toBeTruthy()
    enterValidBasicFields()
    fireEvent.change(screen.getByLabelText('Podstawa ceny'), { target: { value: 'NOMINAL_UNIT' } })
    fireEvent.change(screen.getByLabelText('Średnia cena netto za kg nominalne (PLN)'), { target: { value: '10' } })
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))

    await screen.findByRole('heading', { name: 'Surowiec utworzony' })
    expect(createMock).toHaveBeenCalledWith(expect.objectContaining({
      nominal_quantity: '10',
      pricing: { basis: 'NOMINAL_UNIT', amount: '10', unit_code: 'kg' },
      conversions: [{ unit_code: 'kg' }],
    }), reservationId)
  })

  it('pokazuje obliczenie backendu dla obu podstaw i ukrywa koszt bez FOODCOST_VIEW', async () => {
    const { rerender, props } = renderForm({
      canManageReferencePrice: true, canViewReferencePrice: true, canViewUsableCost: true,
    })
    fireEvent.change(screen.getByLabelText(/Nominalna/), { target: { value: '2800' } })
    fireEvent.change(screen.getByLabelText('Użytkowa'), { target: { value: '1500' } })
    fireEvent.change(screen.getByLabelText('Średnia cena netto opakowania (PLN)'), { target: { value: '28' } })
    expect(await screen.findByText('Cena netto opakowania: 28,00 PLN')).toBeTruthy()
    expect(screen.getByText('Koszt użytkowy: 1,8667 PLN/100g')).toBeTruthy()
    expect(previewRawMaterialPricing).toHaveBeenLastCalledWith(expect.objectContaining({
      nominal_quantity: '2800', usable_quantity: '1500', pricing: { basis: 'PACKAGE', amount: '28' },
    }), expect.any(AbortSignal))

    fireEvent.change(screen.getByLabelText('Podstawa ceny'), { target: { value: 'NOMINAL_UNIT' } })
    fireEvent.change(screen.getByLabelText('Średnia cena netto za kg nominalne (PLN)'), { target: { value: '10' } })
    await waitFor(() => expect(previewRawMaterialPricing).toHaveBeenLastCalledWith(expect.objectContaining({
      pricing: { basis: 'NOMINAL_UNIT', amount: '10', unit_code: 'kg' },
    }), expect.any(AbortSignal)))
    expect(await screen.findByText('Koszt użytkowy: 1,8667 PLN/100g')).toBeTruthy()
    rerender(<RawMaterialCreateForm {...props} canViewUsableCost={false} />)
    expect(screen.queryByText(/Koszt użytkowy:/)).toBeNull()
    expect(screen.getByText('Cena netto opakowania: 28,00 PLN')).toBeTruthy()
  })

  it('ignoruje spóźniony wynik podglądu po zmianie ceny i ukrywa dane po utracie uprawnienia', async () => {
    let resolveOld!: (value: { netPlnPerPackage: string }) => void
    vi.mocked(previewRawMaterialPricing)
      .mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve }))
      .mockResolvedValueOnce({ netPlnPerPackage: '30.00000000000000' })
    const { rerender, props } = renderForm({ canManageReferencePrice: true, canViewReferencePrice: true })
    fireEvent.change(screen.getByLabelText(/Nominalna/), { target: { value: '2800' } })
    fireEvent.change(screen.getByLabelText('Średnia cena netto opakowania (PLN)'), { target: { value: '28' } })
    await waitFor(() => expect(previewRawMaterialPricing).toHaveBeenCalledTimes(1))
    fireEvent.change(screen.getByLabelText('Średnia cena netto opakowania (PLN)'), { target: { value: '30' } })
    expect(await screen.findByText('Cena netto opakowania: 30,00 PLN')).toBeTruthy()
    await act(async () => resolveOld({ netPlnPerPackage: '28.00000000000000' }))
    expect(screen.queryByText('Cena netto opakowania: 28,00 PLN')).toBeNull()
    rerender(<RawMaterialCreateForm {...props} canViewReferencePrice={false} />)
    expect(screen.queryByRole('heading', { name: /Cena zakupu/ })).toBeNull()
    expect(screen.queryByText(/Cena netto opakowania:/)).toBeNull()
  })

  it('jawnie zgłasza błąd podglądu i odmawia dostępu po odpowiedzi 403', async () => {
    vi.mocked(previewRawMaterialPricing).mockRejectedValueOnce(new Error('Błąd połączenia.'))
    const { props } = renderForm({ canManageReferencePrice: true, canViewReferencePrice: true })
    fireEvent.change(screen.getByLabelText(/Nominalna/), { target: { value: '2800' } })
    fireEvent.change(screen.getByLabelText('Średnia cena netto opakowania (PLN)'), { target: { value: '28' } })
    expect(await screen.findByText(/Podgląd ceny niedostępny: Błąd połączenia/)).toBeTruthy()
    vi.mocked(previewRawMaterialPricing).mockRejectedValueOnce(new ApiError(403, 'Brak dostępu', 'FORBIDDEN'))
    fireEvent.change(screen.getByLabelText('Średnia cena netto opakowania (PLN)'), { target: { value: '30' } })
    await waitFor(() => expect(props.onAuthorizationDenied).toHaveBeenCalled())
    expect(screen.queryByText(/Cena netto opakowania:/)).toBeNull()
  })

  it('zachowuje dane w zakładkach i zatwierdza kompletny surowiec jednym żądaniem', async () => {
    renderForm({ canManageAllergens: true, canCreateApproved: true })
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('tab', { name: 'Dodatkowe informacje' }))
    fireEvent.change(screen.getByLabelText(/Opis/), { target: { value: 'Opis testowy' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj nazwę' }))
    fireEvent.change(screen.getByLabelText('Inna nazwa 1'), { target: { value: 'Alias testowy' } })
    fireEvent.click(screen.getByRole('tab', { name: 'Alergeny' }))
    await screen.findByLabelText('Status deklaracji')
    await waitFor(() => expect(screen.getByLabelText<HTMLSelectElement>('Status deklaracji').disabled).toBe(false))
    fireEvent.change(screen.getByLabelText('Status deklaracji'), { target: { value: 'NO_KNOWN_ALLERGENS' } })
    fireEvent.click(screen.getByRole('tab', { name: 'Dane podstawowe' }))
    expect(screen.getByLabelText<HTMLInputElement>(/Nazwa surowca/).value).toBe('Mąka testowa')
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj i zatwierdź surowiec' }))
    await screen.findByRole('heading', { name: 'Surowiec utworzony' })
    expect(createApprovedMock).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Mąka testowa', description: 'Opis testowy', aliases: ['Alias testowy'],
      allergen_declaration: { status: 'NO_KNOWN_ALLERGENS', catalog_version_id: allergenCatalogId, items: [] },
    }), reservationId)
    expect(createMock).not.toHaveBeenCalled()
  })

  it('kieruje do błędnej zakładki i nie rezerwuje SKU bez deklaracji', async () => {
    renderForm({ canManageAllergens: true, canCreateApproved: true })
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj i zatwierdź surowiec' }))
    expect(await screen.findByText('Wybierz deklarację alergenów.')).toBeTruthy()
    expect(screen.getByRole('tab', { name: 'Alergeny • błąd' }).getAttribute('aria-selected')).toBe('true')
    await waitFor(() => expect(document.activeElement).toBe(screen.getByLabelText('Status deklaracji')))
    expect(reserveMock).not.toHaveBeenCalled()
  })

  it('ponawia niepewne zatwierdzenie z tym samym payloadem i rezerwacją', async () => {
    createApprovedMock.mockRejectedValueOnce(new Error('Brak odpowiedzi.'))
    renderForm({ canManageAllergens: true, canCreateApproved: true })
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('tab', { name: 'Alergeny' }))
    await waitFor(() => expect(screen.getByLabelText<HTMLSelectElement>('Status deklaracji').disabled).toBe(false))
    fireEvent.change(screen.getByLabelText('Status deklaracji'), { target: { value: 'NO_KNOWN_ALLERGENS' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj i zatwierdź surowiec' }))
    await screen.findByRole('button', { name: 'Ponów bezpiecznie' })
    const first = createApprovedMock.mock.calls[0]
    fireEvent.click(screen.getByRole('button', { name: 'Ponów bezpiecznie' }))
    await screen.findByRole('heading', { name: 'Surowiec utworzony' })
    expect(createApprovedMock.mock.calls[1]).toEqual(first)
    expect(reserveMock).toHaveBeenCalledTimes(1)
    expect(createMock).not.toHaveBeenCalled()
  })

  it('nie oferuje zatwierdzenia użytkownikowi bez uprawnionej akcji', () => {
    renderForm()
    expect(screen.queryByRole('button', { name: 'Dodaj i zatwierdź surowiec' })).toBeNull()
  })

  it('pokazuje wymagane pola, a rezerwację SKU wykonuje dopiero po poprawnym submit', async () => {
    renderForm()
    expect(screen.getByRole('dialog', { name: 'Dodaj surowiec' })).toBeTruthy()
    expect(screen.getByLabelText(/Kategoria/)).toHaveProperty('required', true)
    expect(screen.getByLabelText(/Nazwa surowca/)).toHaveProperty('required', true)
    expect(reserveMock).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))
    expect(await screen.findByText('Wybierz kategorię surowca.')).toBeTruthy()
    expect(screen.getByText('Podaj nazwę surowca.')).toBeTruthy()
    expect(reserveMock).not.toHaveBeenCalled()

    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))
    expect(await screen.findByRole('heading', { name: 'Surowiec utworzony' })).toBeTruthy()
    expect(reserveMock).toHaveBeenCalledTimes(1)
  })

  it('wysyła mapowany payload i używa serwerowej rezerwacji SKU/idempotencji', async () => {
    renderForm()
    enterValidBasicFields()
    fireEvent.change(screen.getByLabelText(/Nominalna/), { target: { value: '12,5' } })
    fireEvent.change(screen.getByLabelText('Użytkowa'), { target: { value: '10,25' } })
    fireEvent.click(screen.getByRole('tab', { name: 'Dodatkowe informacje' }))
    expect(screen.getByText(/nazwy, pod którymi pracownicy mogą szukać/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj nazwę' }))
    fireEvent.change(screen.getByLabelText('Inna nazwa 1'), { target: { value: '  Mąka uniwersalna  ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj jednostkę' }))
    fireEvent.change(screen.getByLabelText('Dodatkowa jednostka miary 1'), { target: { value: 'kg' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj jednostkę' }))
    fireEvent.change(screen.getByLabelText('Dodatkowa jednostka miary 2'), { target: { value: 'pc' } })
    fireEvent.change(screen.getByLabelText('Przelicznik'), {
      target: { value: '2500' },
    })
    fireEvent.change(screen.getByLabelText('Opis przeliczenia'), {
      target: { value: '1 sztuka o masie 2500 g' },
    })
    const factorInput = screen.getByLabelText('Przelicznik')
    const basisInput = screen.getByLabelText('Opis przeliczenia')
    expect(factorInput.getAttribute('aria-describedby')).toBe('conversion-1-factor-hint')
    expect(basisInput.getAttribute('aria-describedby')).toBe('conversion-1-basis-hint')
    expect(screen.getByText(/jeśli 1 sztuka waży 2500 g, wpisz 2500/)).toBeTruthy()
    expect(screen.getByText(/Wyjaśnij, czego dotyczy przeliczenie/)).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))
    expect(await screen.findByRole('heading', { name: 'Surowiec utworzony' })).toBeTruthy()
    const [key] = reserveMock.mock.calls[0]
    expect(key).toMatch(/^[A-Za-z0-9._:-]{8,120}$/)
    expect(createMock).toHaveBeenCalledWith({
      category_id: categoryId,
      name: 'Mąka testowa',
      base_unit_code: 'g',
      nominal_quantity: '12.5',
      usable_quantity: '10.25',
      aliases: ['Mąka uniwersalna'],
      conversions: [
        { unit_code: 'kg' },
        { unit_code: 'pc', to_base_factor: '2500', basis: '1 sztuka o masie 2500 g' },
      ],
    }, reservationId)
    expect(screen.getByText(/RAW-000042/)).toBeTruthy()
    expect(screen.queryByText(materialId)).toBeNull()
    expect(screen.queryByText(locationId)).toBeNull()
    expect(screen.getByText(/został zapisany jako szkic/)).toBeTruthy()
    expect(screen.getByText(/nie został zatwierdzony ani opublikowany/)).toBeTruthy()
  })

  it('chroni przed podwójnym submit i blokuje formularz podczas rezerwacji', async () => {
    let resolveReservation: ((value: RawMaterialSkuReservation) => void) | undefined
    reserveMock.mockImplementation(() => new Promise((resolve) => {
      resolveReservation = resolve
    }))
    renderForm()
    enterValidBasicFields()
    const submit = screen.getByRole('button', { name: 'Utwórz szkic' })
    fireEvent.click(submit)
    fireEvent.click(submit)

    await waitFor(() => expect(reserveMock).toHaveBeenCalledTimes(1))
    expect(screen.getByRole('status').textContent).toContain('Przygotowywanie numeru SKU')
    await act(async () => resolveReservation?.(reservation))
    expect(await screen.findByRole('heading', { name: 'Surowiec utworzony' })).toBeTruthy()
    expect(createMock).toHaveBeenCalledTimes(1)
  })

  it('ostrzega przy odrzucaniu zmian i respektuje anulowanie potwierdzenia', () => {
    const onClose = vi.fn()
    renderForm({ onClose })
    fireEvent.change(screen.getByLabelText(/Nazwa surowca/), { target: { value: 'Nazwa tymczasowa' } })
    vi.mocked(window.confirm).mockReturnValueOnce(false)
    fireEvent.click(screen.getByRole('button', { name: 'Anuluj' }))
    expect(window.confirm).toHaveBeenCalled()
    expect(onClose).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Anuluj' }))
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it.each([401, 419])('blokuje zapis po wygaśnięciu sesji HTTP %s', async (status) => {
    const onSessionExpired = vi.fn()
    reserveMock.mockRejectedValue(new ApiError(status, 'Sesja wygasła.'))
    renderForm({ onSessionExpired })
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))

    expect(await screen.findByText(/Sesja wygasła/)).toBeTruthy()
    expect(onSessionExpired).toHaveBeenCalledTimes(1)
    expect(createMock).not.toHaveBeenCalled()
  })

  it('zamyka ścieżkę zapisu i zgłasza odmowę HTTP 403', async () => {
    const onAuthorizationDenied = vi.fn()
    reserveMock.mockRejectedValue(new ApiError(403, 'Brak uprawnień.'))
    renderForm({ onAuthorizationDenied })
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))

    expect(await screen.findByText(/Uprawnienie do tworzenia nie jest już aktywne/)).toBeTruthy()
    expect(onAuthorizationDenied).toHaveBeenCalledTimes(1)
    expect(createMock).not.toHaveBeenCalled()
  })

  it('pokazuje błędy walidacji HTTP 422 przy odpowiednich polach', async () => {
    createMock.mockRejectedValue(new ApiError(422, 'Sprawdź dane.', null, {
      name: ['Nazwa jest już używana.'],
    }))
    renderForm()
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))

    expect(await screen.findByText('Nazwa jest już używana.')).toBeTruthy()
    expect(screen.getByLabelText(/Nazwa surowca/).getAttribute('aria-invalid')).toBe('true')
    expect(screen.getByRole('button', { name: 'Utwórz szkic' })).toHaveProperty('disabled', false)
  })

  it('nie ponawia konfliktu HTTP 409 innym payloadem', async () => {
    createMock.mockRejectedValue(new ApiError(409, 'Konflikt.', 'RAW_SKU_RESERVATION_CONFLICT'))
    renderForm()
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))

    expect(await screen.findByText(/Nie zmieniaj danych przy ponawianiu tej próby/)).toBeTruthy()
    expect(createMock).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('button', { name: 'Ponów bezpiecznie' })).toBeNull()
  })

  it('blokuje formularz po HTTP 503 i przekazuje stan niedostępności', async () => {
    const onModuleUnavailable = vi.fn()
    reserveMock.mockRejectedValue(new ApiError(503, 'Moduł niedostępny.', 'RAW_MATERIALS_MODULE_UNAVAILABLE'))
    renderForm({ onModuleUnavailable })
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))

    expect(await screen.findByText(/Moduł RAW_MATERIALS jest niedostępny/)).toBeTruthy()
    expect(onModuleUnavailable).toHaveBeenCalledTimes(1)
    expect(createMock).not.toHaveBeenCalled()
  })

  it('po niejednoznacznym wyniku ponawia dokładnie tę samą rezerwację i zapis', async () => {
    createMock.mockRejectedValueOnce(new Error('Przekroczono czas oczekiwania na odpowiedź backendu.'))
    renderForm()
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))

    expect(await screen.findByRole('button', { name: 'Ponów bezpiecznie' })).toBeTruthy()
    const firstPayload = createMock.mock.calls[0]
    fireEvent.click(screen.getByRole('button', { name: 'Ponów bezpiecznie' }))
    expect(await screen.findByRole('heading', { name: 'Surowiec utworzony' })).toBeTruthy()
    expect(reserveMock).toHaveBeenCalledTimes(1)
    expect(createMock).toHaveBeenCalledTimes(2)
    expect(createMock.mock.calls[1]).toEqual(firstPayload)
  })

  it('ignoruje spóźnioną odpowiedź po odmontowaniu formularza', async () => {
    let resolveReservation: ((value: RawMaterialSkuReservation) => void) | undefined
    reserveMock.mockImplementation(() => new Promise((resolve) => {
      resolveReservation = resolve
    }))
    const { unmount } = renderForm()
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))
    await waitFor(() => expect(reserveMock).toHaveBeenCalledTimes(1))
    unmount()

    await act(async () => resolveReservation?.(reservation))
    expect(createMock).not.toHaveBeenCalled()
  })

  it('przekazuje serwerowy identyfikator do istniejącego widoku szczegółów', async () => {
    const onOpenCreated = vi.fn()
    renderForm({ onOpenCreated })
    enterValidBasicFields()
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz szkic' }))
    await screen.findByRole('heading', { name: 'Surowiec utworzony' })
    fireEvent.click(screen.getByRole('button', { name: 'Otwórz szczegóły' }))

    expect(onOpenCreated).toHaveBeenCalledWith(created)
  })
})
