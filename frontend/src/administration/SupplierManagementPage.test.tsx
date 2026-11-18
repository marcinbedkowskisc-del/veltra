import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SupplierManagementPage from './SupplierManagementPage'
import {
  archiveAdministrationSupplier,
  createAdministrationSupplier,
  createAdministrationSupplierContact,
  fetchAdministrationSupplier,
  fetchAdministrationSuppliers,
  fetchSimilarSupplierNames,
  removeAdministrationSupplierContact,
  restoreAdministrationSupplier,
  updateAdministrationSupplier,
  updateAdministrationSupplierContact,
  type SupplierDetails,
  type SupplierDirectoryPage,
} from './suppliersApi'

vi.mock('./suppliersApi', () => ({
  archiveAdministrationSupplier: vi.fn(),
  createAdministrationSupplier: vi.fn(),
  createAdministrationSupplierContact: vi.fn(),
  fetchAdministrationSupplier: vi.fn(),
  fetchAdministrationSuppliers: vi.fn(),
  fetchSimilarSupplierNames: vi.fn(),
  removeAdministrationSupplierContact: vi.fn(),
  restoreAdministrationSupplier: vi.fn(),
  updateAdministrationSupplier: vi.fn(),
  updateAdministrationSupplierContact: vi.fn(),
}))

const supplierId = '00000000-0000-4000-8000-000000000001'
const contactId = '00000000-0000-4000-8000-000000000002'
const supplierPage: SupplierDirectoryPage = {
  data: [{
    id: supplierId,
    displayName: 'Dostawca BANASIK',
    legalName: 'BANASIK sp. z o.o.',
    taxIdentifier: '1234567890',
    city: 'Kraków',
    primaryContact: 'Anna Nowak',
    status: 'ACTIVE',
    rawMaterialCount: 3,
  }],
  meta: { currentPage: 1, perPage: 25, total: 1, lastPage: 1 },
}
const supplierDetails: SupplierDetails = {
  ...supplierPage.data[0],
  streetAddress: 'ul. Przykładowa 10',
  phone: '+48 12 123 45 67',
  email: 'biuro@example.test',
  website: 'https://example.test',
  contacts: [{
    id: contactId,
    fullName: 'Anna Nowak',
    position: 'Opiekun klienta',
    phone: '+48 12 123 45 67',
    email: 'anna@example.test',
    isPrimary: true,
  }],
}

beforeEach(() => {
  vi.mocked(fetchAdministrationSuppliers).mockResolvedValue(supplierPage)
  vi.mocked(fetchAdministrationSupplier).mockResolvedValue(supplierDetails)
  vi.mocked(fetchSimilarSupplierNames).mockResolvedValue([])
  vi.mocked(createAdministrationSupplier).mockResolvedValue(supplierId)
  vi.mocked(updateAdministrationSupplier).mockResolvedValue(supplierId)
  vi.mocked(archiveAdministrationSupplier).mockResolvedValue(supplierId)
  vi.mocked(restoreAdministrationSupplier).mockResolvedValue(supplierId)
  vi.mocked(createAdministrationSupplierContact).mockResolvedValue(contactId)
  vi.mocked(updateAdministrationSupplierContact).mockResolvedValue(contactId)
  vi.mocked(removeAdministrationSupplierContact).mockResolvedValue(contactId)
})

afterEach(() => {
  cleanup()
  vi.resetAllMocks()
})

describe('administracja dostawcami', () => {
  it('pokazuje katalog i formularz z nazwą potoczną oraz firmowymi danymi dostawcy', async () => {
    render(<SupplierManagementPage />)
    expect(await screen.findByRole('button', { name: 'Dostawca BANASIK' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj dostawcę' }))
    fireEvent.change(screen.getByLabelText('Nazwa firmy'), { target: { value: 'BANASIK sp. z o.o.' } })
    fireEvent.change(screen.getByLabelText('Nazwa potoczna (opcjonalnie)'), { target: { value: 'Dostawca BANASIK' } })
    fireEvent.change(screen.getByLabelText('NIP (opcjonalnie)'), { target: { value: '123-456-78-90' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz dostawcę' }))
    await waitFor(() => expect(createAdministrationSupplier).toHaveBeenCalledWith({
      legal_name: 'BANASIK sp. z o.o.',
      display_name: 'Dostawca BANASIK',
      tax_identifier: '123-456-78-90',
      street_address: null,
      city: null,
      phone: null,
      email: null,
      website: null,
    }))
  })

  it('ogranicza archiwizację do potwierdzonej akcji i pokazuje zachowanie powiązań', async () => {
    render(<SupplierManagementPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Szczegóły' }))
    expect(await screen.findByRole('heading', { name: 'Dostawca BANASIK' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Archiwizuj' }))
    expect(screen.getByText(/nie usunie jego danych, powiązań surowców ani historii/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Potwierdź archiwizację' }))
    await waitFor(() => expect(archiveAdministrationSupplier).toHaveBeenCalledWith(supplierId))
  })

  it('zarządza kontaktem dostawcy tylko w szczegółach administracyjnych', async () => {
    render(<SupplierManagementPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Szczegóły' }))
    fireEvent.click(await screen.findByRole('button', { name: /Osoby kontaktowe/ }))
    expect(await screen.findByText(/anna@example\.test/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Dodaj osobę' }))
    fireEvent.change(screen.getByLabelText('Imię i nazwisko'), { target: { value: 'Jan Kowalski' } })
    fireEvent.change(screen.getByLabelText('Telefon (opcjonalnie)'), { target: { value: '+48 500 000 000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz osobę' }))
    await waitFor(() => expect(createAdministrationSupplierContact).toHaveBeenCalledWith(supplierId, {
      full_name: 'Jan Kowalski',
      position: null,
      phone: '+48 500 000 000',
      email: null,
      is_primary: false,
    }))
  })

  it('wyświetla osobne i jednoznaczne potwierdzenie przed usunięciem kontaktu', async () => {
    render(<SupplierManagementPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Szczegóły' }))
    fireEvent.click(await screen.findByRole('button', { name: /Osoby kontaktowe/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Usuń' }))
    expect(screen.getByText(/Historia pozostanie zachowana/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Potwierdź' }))
    await waitFor(() => expect(removeAdministrationSupplierContact).toHaveBeenCalledWith(supplierId, contactId))
  })
})
