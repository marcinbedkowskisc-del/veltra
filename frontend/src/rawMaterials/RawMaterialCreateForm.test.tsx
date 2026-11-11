import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import type { CategoryOption } from './catalog'
import RawMaterialCreateForm from './RawMaterialCreateForm'
import {
  createRawMaterial,
  reserveRawMaterialSku,
  type CreatedRawMaterial,
  type RawMaterialSkuReservation,
} from './api'

vi.mock('./api', () => ({
  createRawMaterial: vi.fn(),
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
}

beforeEach(() => {
  vi.spyOn(crypto, 'randomUUID').mockReturnValue('00000000-0000-4000-8000-000000000099')
  reserveMock.mockResolvedValue(reservation)
  createMock.mockResolvedValue(created)
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('formularz tworzenia surowca RAW', () => {
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
    fireEvent.change(screen.getByLabelText('Nominalna'), { target: { value: '12,5' } })
    fireEvent.change(screen.getByLabelText('Użytkowa'), { target: { value: '10,25' } })
    fireEvent.click(screen.getByText('Aliasy i przeliczniki', { exact: false }))
    expect(screen.getByText(/Alternatywne nazwy tego samego surowca/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj alias' }))
    fireEvent.change(screen.getByLabelText('Alias 1'), { target: { value: '  Mąka uniwersalna  ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj jednostkę' }))
    fireEvent.change(screen.getByLabelText('Jednostka przeliczeniowa 1'), { target: { value: 'kg' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj jednostkę' }))
    fireEvent.change(screen.getByLabelText('Jednostka przeliczeniowa 2'), { target: { value: 'pc' } })
    fireEvent.change(screen.getByLabelText(/Współczynnik — jednostki bazowe na 1 jednostkę dodatkową/), {
      target: { value: '2500' },
    })
    fireEvent.change(screen.getByLabelText('Opis podstawy przeliczenia'), {
      target: { value: '1 sztuka o masie 2500 g' },
    })
    expect(screen.getByText(/1 pc = 2500 g/)).toBeTruthy()
    expect(screen.getByText(/„pc” oznacza sztukę, nie opakowanie/)).toBeTruthy()

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
    expect(screen.getByText(/DRAFT/)).toBeTruthy()
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
    expect(screen.getByRole('status').textContent).toContain('Przydzielanie numeru SKU')
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

    expect(await screen.findByText(/Nie ponawiaj zapisu z innymi danymi/)).toBeTruthy()
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
