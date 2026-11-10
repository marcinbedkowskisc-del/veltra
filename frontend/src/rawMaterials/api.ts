import { isRecord, request, requestDownload, requestEnvelope } from '../api'
import {
  catalogSearchParams,
  parseCategories,
  parseCatalogEnvelope,
  parseSuppliers,
  type CatalogPage,
  type CatalogQuery,
  type CategoryOption,
  type SupplierOption,
} from './catalog'
import { parseRawMaterialDetail, parseRawMaterialRevisions, type RawMaterialDetail, type RawMaterialRevision } from './detail'
import type { RawMaterialCreatePayload } from './create'

const RAW_API = '/api/v1/raw-materials'
const RAW_CAPABILITIES_API = '/api/v1/security/raw-materials/capabilities'

export type RawMaterialCapabilities = {
  module: 'RAW_MATERIALS'
  locationId: string
  readiness: 'READY' | 'NOT_READY'
  available: boolean
  permissions: { create: boolean; edit: boolean }
  actions: { create: boolean; edit: boolean }
}

export type RawMaterialSkuReservation = {
  reservationId: string
  sku: string
  expiresAt: string
  status: 'RESERVED' | 'EXPIRED' | 'CONSUMED'
}

export type CreatedRawMaterial = {
  id: string
  sku: string
  lifecycleState: 'DRAFT'
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const RAW_SKU = /^RAW-\d{6}$/

function isReservationStatus(value: unknown): value is RawMaterialSkuReservation['status'] {
  return value === 'RESERVED' || value === 'EXPIRED' || value === 'CONSUMED'
}

function parseSkuReservation(value: unknown): RawMaterialSkuReservation {
  if (!isRecord(value) || typeof value.reservation_id !== 'string' || !UUID.test(value.reservation_id)
    || typeof value.sku !== 'string' || !RAW_SKU.test(value.sku)
    || typeof value.expires_at !== 'string' || Number.isNaN(Date.parse(value.expires_at))
    || !isReservationStatus(value.status)) {
    throw new Error('Backend zwrócił nieprawidłową rezerwację SKU.')
  }

  return {
    reservationId: value.reservation_id,
    sku: value.sku,
    expiresAt: value.expires_at,
    status: value.status,
  }
}

function parseCreatedRawMaterial(value: unknown): CreatedRawMaterial {
  if (!isRecord(value) || typeof value.id !== 'string' || !UUID.test(value.id)
    || typeof value.code !== 'string' || !RAW_SKU.test(value.code)
    || value.sku !== value.code || value.lifecycle_state !== 'DRAFT') {
    throw new Error('Backend zwrócił nieprawidłowe potwierdzenie utworzenia surowca.')
  }

  return {
    id: value.id,
    sku: value.code,
    lifecycleState: 'DRAFT',
  }
}

function parseRawMaterialCapabilities(value: unknown, expectedLocationId: string): RawMaterialCapabilities {
  if (!isRecord(value) || value.module !== 'RAW_MATERIALS'
    || value.location_id !== expectedLocationId
    || (value.readiness !== 'READY' && value.readiness !== 'NOT_READY')
    || typeof value.available !== 'boolean'
    || !isRecord(value.permissions) || typeof value.permissions.create !== 'boolean'
    || typeof value.permissions.edit !== 'boolean'
    || !isRecord(value.actions) || typeof value.actions.create !== 'boolean'
    || typeof value.actions.edit !== 'boolean'
    || (value.available && value.readiness !== 'READY')
    || (value.actions.create && (!value.permissions.create || !value.available))
    || (value.actions.edit && (!value.permissions.edit || !value.available))) {
    throw new Error('Backend zwrócił nieprawidłowe uprawnienia katalogu surowców.')
  }

  return {
    module: 'RAW_MATERIALS',
    locationId: value.location_id,
    readiness: value.readiness,
    available: value.available,
    permissions: { create: value.permissions.create, edit: value.permissions.edit },
    actions: { create: value.actions.create, edit: value.actions.edit },
  }
}

export async function fetchRawMaterialCapabilities(
  expectedLocationId: string,
  signal?: AbortSignal,
): Promise<RawMaterialCapabilities> {
  const value = await request(RAW_CAPABILITIES_API, 'GET', undefined, { signal })
  return parseRawMaterialCapabilities(value, expectedLocationId)
}

export async function fetchCatalog(query: CatalogQuery, signal?: AbortSignal): Promise<CatalogPage> {
  const params = catalogSearchParams(query)
  const envelope = await requestEnvelope(`${RAW_API}?${params}`, 'GET', undefined, { signal })
  return parseCatalogEnvelope(envelope)
}

export async function fetchCategories(signal?: AbortSignal): Promise<CategoryOption[]> {
  return parseCategories(await request(`${RAW_API}/categories`, 'GET', undefined, { signal }))
}

export async function reserveRawMaterialSku(idempotencyKey: string): Promise<RawMaterialSkuReservation> {
  return parseSkuReservation(await request(
    `${RAW_API}/sku-reservations`,
    'POST',
    {},
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ))
}

export async function createRawMaterial(
  payload: RawMaterialCreatePayload,
  reservationId: string,
): Promise<CreatedRawMaterial> {
  return parseCreatedRawMaterial(await request(
    RAW_API,
    'POST',
    { ...payload, sku_reservation_id: reservationId },
  ))
}

export async function fetchSuppliers(search: string, signal?: AbortSignal): Promise<SupplierOption[]> {
  const params = new URLSearchParams({ per_page: '100', lifecycle_state: 'ACTIVE' })
  if (search.trim()) params.set('search', search.trim())
  const envelope = await requestEnvelope(`${RAW_API}/suppliers?${params}`, 'GET', undefined, { signal })
  return parseSuppliers(envelope.data)
}

export async function fetchRawMaterialDetail(id: string, signal?: AbortSignal): Promise<RawMaterialDetail> {
  return parseRawMaterialDetail(await request(`${RAW_API}/${encodeURIComponent(id)}`, 'GET', undefined, { signal }))
}

export async function fetchRawMaterialRevisions(id: string, signal?: AbortSignal): Promise<RawMaterialRevision[]> {
  return parseRawMaterialRevisions(await request(`${RAW_API}/${encodeURIComponent(id)}/revisions`, 'GET', undefined, { signal }))
}

export async function exportCatalog(
  payload: Record<string, unknown>,
  idempotencyKey: string,
): Promise<{ exportId: string; downloadUrl: string }> {
  const value = await request(`${RAW_API}/catalog-exports/selection`, 'POST', payload, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })
  if (typeof value !== 'object' || value === null
    || !('export_id' in value) || typeof value.export_id !== 'string'
    || !('download_url' in value) || typeof value.download_url !== 'string') {
    throw new Error('Backend zwrócił nieprawidłowy eksport katalogu.')
  }
  const downloadUrl = new URL(value.download_url, window.location.origin)
  if (downloadUrl.origin !== window.location.origin
    || !downloadUrl.pathname.startsWith(`${RAW_API}/catalog-exports/selection/`)
    || !downloadUrl.pathname.endsWith('/download')) {
    throw new Error('Backend zwrócił nieprawidłowy adres pobierania eksportu.')
  }

  return { exportId: value.export_id, downloadUrl: `${downloadUrl.pathname}${downloadUrl.search}` }
}

export async function downloadCatalogExport(path: string): Promise<{ blob: Blob; filename: string }> {
  return requestDownload(path)
}
