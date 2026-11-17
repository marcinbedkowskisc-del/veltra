import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import UnitManagementPage from './UnitManagementPage'
import {
  archiveAdministrationUnit,
  createAdministrationUnit,
  fetchAdministrationUnits,
  restoreAdministrationUnit,
  updateAdministrationUnit,
  type AdministrationUnit,
} from './unitsApi'

vi.mock('./unitsApi', () => ({
  archiveAdministrationUnit: vi.fn(),
  createAdministrationUnit: vi.fn(),
  fetchAdministrationUnits: vi.fn(),
  restoreAdministrationUnit: vi.fn(),
  updateAdministrationUnit: vi.fn(),
}))

const gram: AdministrationUnit = {
  id: 'gram-id',
  code: 'g',
  name: 'gram',
  symbol: 'g',
  dimension: 'MASS',
  baseUnitCode: 'g',
  toBaseFactor: '1.000000000000',
  archivedAt: null,
  isBase: true,
  definitionLocked: true,
  referenceCount: 6,
  conversions: [],
}
const kilogram: AdministrationUnit = {
  ...gram,
  id: 'kilogram-id',
  code: 'kg',
  name: 'kilogram',
  symbol: 'kg',
  baseUnitCode: 'g',
  toBaseFactor: '1000.000000000000',
  isBase: false,
  definitionLocked: false,
  referenceCount: 0,
  conversions: [{ unitCode: 'g', name: 'gram', symbol: 'g', archivedAt: null, factor: '1000.000000000000' }],
}
const milliliter: AdministrationUnit = {
  ...gram,
  id: 'milliliter-id',
  code: 'ml',
  name: 'mililitr',
  symbol: 'ml',
  dimension: 'VOLUME',
  baseUnitCode: 'ml',
  toBaseFactor: '1.000000000000',
  isBase: true,
  referenceCount: 0,
}
const liter: AdministrationUnit = {
  ...milliliter,
  id: 'liter-id',
  code: 'l',
  name: 'litr',
  symbol: 'l',
  isBase: false,
  toBaseFactor: '1000.000000000000',
}

beforeEach(() => {
  vi.mocked(fetchAdministrationUnits).mockResolvedValue([gram, kilogram, milliliter, liter])
  vi.mocked(createAdministrationUnit).mockResolvedValue({ ...kilogram, code: 'mg_custom', name: 'miligram testowy' })
  vi.mocked(updateAdministrationUnit).mockResolvedValue({ ...kilogram, name: 'kilogram poprawiony' })
  vi.mocked(archiveAdministrationUnit).mockResolvedValue({ ...kilogram, archivedAt: '2026-11-17T10:00:00Z' })
  vi.mocked(restoreAdministrationUnit).mockResolvedValue(kilogram)
})

afterEach(() => {
  cleanup()
  vi.resetAllMocks()
})

describe('administracja jednostkami miary', () => {
  it('grupuje jednostki według wymiaru i pokazuje bazę oraz przelicznik', async () => {
    render(<UnitManagementPage />)
    expect(await screen.findByRole('heading', { name: 'Masa' })).toBeTruthy()
    expect(screen.getAllByText('1000.000000000000')).toHaveLength(2)
    expect(within(screen.getByRole('region', { name: 'Masa' }))
      .getAllByText('Powiązania historyczne')).toHaveLength(2)
  })

  it('tworzy jednostkę w obrębie wymiaru i odświeża słownik', async () => {
    render(<UnitManagementPage />)
    await screen.findByRole('heading', { name: 'Masa' })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj jednostkę' }))
    fireEvent.change(screen.getByLabelText('Kod jednostki'), { target: { value: 'mg_custom' } })
    fireEvent.change(screen.getByLabelText('Nazwa'), { target: { value: 'miligram testowy' } })
    fireEvent.change(screen.getByLabelText('Symbol'), { target: { value: 'mgt' } })
    fireEvent.change(screen.getByLabelText('Jednostka bazowa'), { target: { value: 'g' } })
    fireEvent.change(screen.getByLabelText('Współczynnik do jednostki bazowej'), { target: { value: '0.001' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz' }))
    await waitFor(() => expect(createAdministrationUnit).toHaveBeenCalledWith({
      code: 'mg_custom', name: 'miligram testowy', symbol: 'mgt', dimension: 'MASS',
      base_unit_code: 'g', to_base_factor: '0.001',
    }))
    expect(await screen.findByRole('status')).toBeTruthy()
  })

  it('zachowuje bazę wyboru po wyszukaniu jednostki innej niż bazowa', async () => {
    vi.mocked(fetchAdministrationUnits).mockImplementation(async (query) =>
      query.search ? [liter] : [gram, kilogram, milliliter, liter])
    render(<UnitManagementPage />)
    await screen.findByRole('heading', { name: 'Masa' })
    fireEvent.change(screen.getByLabelText('Szukaj jednostki'), { target: { value: 'litr' } })
    fireEvent.click(screen.getByRole('button', { name: 'Szukaj' }))
    await screen.findByRole('heading', { name: 'Objętość' })
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj jednostkę' }))
    const form = screen.getByRole('form', { name: 'Nowa jednostka' })
    fireEvent.change(within(form).getByLabelText('Wymiar'), { target: { value: 'VOLUME' } })
    expect(within(form).getByRole('option', { name: 'mililitr (ml)' })).toBeTruthy()
  })

  it('przekazuje ograniczenie historycznej definicji i pokazuje błąd archiwizacji', async () => {
    vi.mocked(archiveAdministrationUnit).mockRejectedValue(new ApiError(409, 'Nie można zarchiwizować jednostki bazowej.'))
    render(<UnitManagementPage />)
    await screen.findByRole('heading', { name: 'Masa' })
    fireEvent.click(screen.getAllByRole('button', { name: 'Edytuj' })[0])
    const form = screen.getByRole('form', { name: 'Edycja jednostki' })
    expect((within(form).getByLabelText('Wymiar') as HTMLSelectElement).disabled).toBe(true)
    expect(screen.getByText(/Definicja wymiaru i przelicznika jest zablokowana/)).toBeTruthy()
    fireEvent.click(screen.getAllByRole('button', { name: 'Archiwizuj' })[1])
    expect(await screen.findByRole('alert')).toBeTruthy()
  })
})
