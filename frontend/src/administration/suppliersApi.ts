import { isRecord, request, requestEnvelope } from '../api'

const API = '/api/v1/administration/suppliers'
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export type SupplierStatus = 'ACTIVE' | 'ARCHIVED'

export type SupplierDirectoryRow = {
  id: string
  displayName: string | null
  legalName: string
  taxIdentifier: string | null
  city: string | null
  primaryContact: string | null
  status: SupplierStatus
  rawMaterialCount: number
}

export type SupplierContact = {
  id: string
  fullName: string
  position: string | null
  phone: string | null
  email: string | null
  isPrimary: boolean
}

export type SupplierDetails = SupplierDirectoryRow & {
  streetAddress: string | null
  phone: string | null
  email: string | null
  website: string | null
  contacts: SupplierContact[]
}

export type SupplierDirectoryPage = {
  data: SupplierDirectoryRow[]
  meta: { currentPage: number; perPage: number; total: number; lastPage: number }
}

export type SupplierDirectoryQuery = {
  search: string
  status: 'active' | 'archived' | 'all'
  sort: 'display_name' | 'legal_name' | 'tax_identifier' | 'city' | 'primary_contact' | 'status'
  direction: 'asc' | 'desc'
  page: number
  perPage: 25 | 50 | 100
}

export type SupplierInput = {
  legal_name: string
  display_name: string | null
  tax_identifier: string | null
  street_address: string | null
  city: string | null
  phone: string | null
  email: string | null
  website: string | null
}

export type SupplierContactInput = {
  full_name: string
  position: string | null
  phone: string | null
  email: string | null
  is_primary: boolean
}

function nullableString(value: unknown, field: string): string | null {
  if (value === null) return null
  if (typeof value !== 'string') throw new Error(`Backend zwrócił nieprawidłowe dane dostawcy: ${field}.`)
  return value
}

function parseStatus(value: unknown): SupplierStatus {
  if (value !== 'ACTIVE' && value !== 'ARCHIVED') {
    throw new Error('Backend zwrócił nieprawidłowy status dostawcy.')
  }
  return value
}

function parseSupplierRow(value: unknown): SupplierDirectoryRow {
  if (!isRecord(value) || typeof value.raw_material_count !== 'number'
    || !Number.isInteger(value.raw_material_count) || value.raw_material_count < 0) {
    throw new Error('Backend zwrócił nieprawidłowy wiersz dostawcy.')
  }
  const displayName = nullableString(value.display_name, 'display_name')
  const city = nullableString(value.city, 'city')
  const primaryContact = nullableString(value.primary_contact, 'primary_contact')
  const taxIdentifier = nullableString(value.tax_identifier, 'tax_identifier')

  if (typeof value.id !== 'string' || !UUID.test(value.id)
    || typeof value.legal_name !== 'string' || value.legal_name.length === 0) {
    throw new Error('Backend zwrócił nieprawidłową tożsamość dostawcy.')
  }

  return {
    id: value.id,
    displayName,
    legalName: value.legal_name,
    taxIdentifier,
    city,
    primaryContact,
    status: parseStatus(value.lifecycle_state),
    rawMaterialCount: value.raw_material_count,
  }
}

function parseContact(value: unknown): SupplierContact {
  if (!isRecord(value) || typeof value.id !== 'string' || !UUID.test(value.id)
    || typeof value.full_name !== 'string' || value.full_name.length === 0
    || typeof value.is_primary !== 'boolean') {
    throw new Error('Backend zwrócił nieprawidłową osobę kontaktową.')
  }
  return {
    id: value.id,
    fullName: value.full_name,
    position: nullableString(value.position, 'contact.position'),
    phone: nullableString(value.phone, 'contact.phone'),
    email: nullableString(value.email, 'contact.email'),
    isPrimary: value.is_primary,
  }
}

function parseDetails(value: unknown): SupplierDetails {
  if (!isRecord(value) || !Array.isArray(value.contacts)) {
    throw new Error('Backend zwrócił nieprawidłowe szczegóły dostawcy.')
  }
  const row = parseSupplierRow(value)
  return {
    ...row,
    streetAddress: nullableString(value.street_address, 'street_address'),
    phone: nullableString(value.phone, 'phone'),
    email: nullableString(value.email, 'email'),
    website: nullableString(value.website, 'website'),
    contacts: value.contacts.map(parseContact),
  }
}

function mutationKey(): string {
  return crypto.randomUUID()
}

async function mutation(path: string, method: string, body?: object): Promise<string> {
  const result = await request(path, method, body, {
    headers: { 'Idempotency-Key': mutationKey() },
  })
  if (!isRecord(result) || typeof result.id !== 'string' || !UUID.test(result.id)) {
    throw new Error('Backend zwrócił nieprawidłowe potwierdzenie zmiany dostawcy.')
  }
  return result.id
}

export async function fetchAdministrationSuppliers(
  query: SupplierDirectoryQuery,
  signal?: AbortSignal,
): Promise<SupplierDirectoryPage> {
  const params = new URLSearchParams({
    status: query.status,
    sort: query.sort,
    direction: query.direction,
    page: String(query.page),
    per_page: String(query.perPage),
  })
  if (query.search.trim()) params.set('search', query.search.trim())
  const response = await requestEnvelope(`${API}?${params}`, 'GET', undefined, { signal })
  if (!Array.isArray(response.data) || !isRecord(response.meta)
    || !Number.isInteger(response.meta.current_page) || !Number.isInteger(response.meta.per_page)
    || !Number.isInteger(response.meta.total) || !Number.isInteger(response.meta.last_page)) {
    throw new Error('Backend zwrócił nieprawidłową listę dostawców.')
  }
  return {
    data: response.data.map(parseSupplierRow),
    meta: {
      currentPage: Number(response.meta.current_page),
      perPage: Number(response.meta.per_page),
      total: Number(response.meta.total),
      lastPage: Number(response.meta.last_page),
    },
  }
}

export async function fetchAdministrationSupplier(id: string, signal?: AbortSignal): Promise<SupplierDetails> {
  const result = await request(`${API}/${encodeURIComponent(id)}`, 'GET', undefined, { signal })
  return parseDetails(result)
}

export async function fetchSimilarSupplierNames(
  officialName: string,
  excludeSupplierId?: string,
  signal?: AbortSignal,
): Promise<Array<{ id: string; legalName: string; displayName: string | null }>> {
  const params = new URLSearchParams({ official_name: officialName })
  if (excludeSupplierId) params.set('exclude_supplier_id', excludeSupplierId)
  const result = await request(`${API}/similar-names?${params}`, 'GET', undefined, { signal })
  if (!isRecord(result) || !Array.isArray(result.data)) throw new Error('Backend zwrócił nieprawidłowe podobne nazwy.')
  return result.data.map((value) => {
    if (!isRecord(value) || typeof value.id !== 'string' || !UUID.test(value.id)
      || typeof value.legal_name !== 'string' || !Number.isInteger(value.similarity)) {
      throw new Error('Backend zwrócił nieprawidłową podobną nazwę.')
    }
    return {
      id: value.id,
      legalName: value.legal_name,
      displayName: nullableString(value.display_name, 'display_name'),
    }
  })
}

export async function createAdministrationSupplier(input: SupplierInput): Promise<string> {
  return mutation(API, 'POST', input)
}

export async function updateAdministrationSupplier(id: string, input: SupplierInput): Promise<string> {
  return mutation(`${API}/${encodeURIComponent(id)}`, 'PATCH', input)
}

export async function archiveAdministrationSupplier(id: string): Promise<string> {
  return mutation(`${API}/${encodeURIComponent(id)}/archive`, 'POST', {})
}

export async function restoreAdministrationSupplier(id: string): Promise<string> {
  return mutation(`${API}/${encodeURIComponent(id)}/restore`, 'POST', {})
}

export async function createAdministrationSupplierContact(
  supplierId: string,
  input: SupplierContactInput,
): Promise<string> {
  return mutation(`${API}/${encodeURIComponent(supplierId)}/contacts`, 'POST', input)
}

export async function updateAdministrationSupplierContact(
  supplierId: string,
  contactId: string,
  input: SupplierContactInput,
): Promise<string> {
  return mutation(`${API}/${encodeURIComponent(supplierId)}/contacts/${encodeURIComponent(contactId)}`, 'PATCH', input)
}

export async function removeAdministrationSupplierContact(supplierId: string, contactId: string): Promise<string> {
  return mutation(`${API}/${encodeURIComponent(supplierId)}/contacts/${encodeURIComponent(contactId)}`, 'DELETE')
}
