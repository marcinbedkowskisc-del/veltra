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
import {
  UNIT_DIMENSIONS,
  type RawMaterialCreatePayload,
  type RawMaterialCreateValues,
  type RawMaterialUnit,
} from './create'

const RAW_API = '/api/v1/raw-materials'
const RAW_CAPABILITIES_API = '/api/v1/security/raw-materials/capabilities'
const RAW_UNITS = ['mg', 'g', 'kg', 'ml', 'l', 'pc'] as const
const DECIMAL = /^\d{1,30}(?:\.\d{1,14})?$/

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

export type RawMaterialCompletion = {
  lifecycleState: string
  revisionId: string
  version: string | null
  availableActions: string[]
}

export type RawMaterialEditor = {
  id: string
  sku: string
  lifecycleState: 'DRAFT' | 'ACTIVE'
  revisionId: string
  revisionNo: number
  version: string
  values: RawMaterialCreateValues
}

export type RawMaterialEditPayload = {
  category_id: string
  name: string
  description: string | null
  base_unit_code: RawMaterialUnit
  nominal_quantity?: string
  usable_quantity?: string
  aliases: string[]
  conversions: Array<{
    unit_code: RawMaterialUnit
    to_base_factor?: string
    basis?: string
  }>
  change_reason?: string
}

export type RevisedRawMaterial = {
  id: string
  sku: string
  lifecycleState: 'DRAFT' | 'ACTIVE'
  version: string
  revision: { id: string; revisionNo: number }
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

function requiredString(value: unknown, message: string): string {
  if (typeof value !== 'string' || value.length === 0) throw new Error(message)
  return value
}

function nullableString(value: unknown, message: string): string | null {
  if (value === null) return null
  return requiredString(value, message)
}

function parseRawMaterialCompletion(value: unknown): RawMaterialCompletion {
  if (!isRecord(value) || typeof value.lifecycle_state !== 'string'
    || typeof value.revision_id !== 'string' || !UUID.test(value.revision_id)
    || !(value.version === null || (typeof value.version === 'string' && value.version.length > 0))
    || !Array.isArray(value.available_actions)
    || !value.available_actions.every((action) => typeof action === 'string')) {
    throw new Error('Backend zwrócił nieprawidłową ocenę dostępnych akcji surowca.')
  }
  return {
    lifecycleState: value.lifecycle_state,
    revisionId: value.revision_id,
    version: value.version,
    availableActions: value.available_actions,
  }
}

function parseRawMaterialEditor(value: unknown, expectedId: string): RawMaterialEditor {
  const invalid = () => new Error('Backend zwrócił niepełne lub nieprawidłowe dane edytora surowca.')
  if (!isRecord(value) || value.id !== expectedId
    || typeof value.sku !== 'string' || value.sku.length === 0
    || (value.lifecycle_state !== 'DRAFT' && value.lifecycle_state !== 'ACTIVE')
    || typeof value.version !== 'string' || value.version.length === 0
    || !isRecord(value.revision) || !UUID.test(String(value.revision.id ?? ''))
    || !Number.isInteger(value.revision.revision_no) || Number(value.revision.revision_no) < 1
    || !Array.isArray(value.revision.units) || !Array.isArray(value.revision.aliases)
    || !value.revision.aliases.every((alias) => typeof alias === 'string')) {
    throw invalid()
  }

  const revisionValue = value.revision
  if (!isRecord(revisionValue)) throw invalid()
  const revision = revisionValue
  const categoryId = revision.category_id
  const baseUnitCode = revision.base_unit_code
  const name = revision.name
  if (typeof categoryId !== 'string' || !UUID.test(categoryId)
    || typeof baseUnitCode !== 'string' || !RAW_UNITS.includes(baseUnitCode as RawMaterialUnit)
    || typeof name !== 'string' || name.trim().length === 0
    || typeof revision.nominal_quantity !== 'string' && revision.nominal_quantity !== null
    || typeof revision.usable_quantity !== 'string' && revision.usable_quantity !== null
    || typeof revision.nominal_quantity === 'string' && !DECIMAL.test(revision.nominal_quantity)
    || typeof revision.usable_quantity === 'string' && !DECIMAL.test(revision.usable_quantity)
    || (revision.nominal_quantity === null) !== (revision.usable_quantity === null)) {
    throw invalid()
  }

  const description = nullableString(revision.description, invalid().message)
  const unitsValue = revision.units
  if (!Array.isArray(unitsValue)) throw invalid()
  const units = unitsValue
  const unitCodes = new Set<string>()
  let baseUnitCount = 0
  const conversions = units.flatMap((unit) => {
    if (!isRecord(unit)) throw invalid()
    if (typeof unit.unit_code !== 'string' || !RAW_UNITS.includes(unit.unit_code as RawMaterialUnit)
      || typeof unit.dimension !== 'string'
      || unit.dimension !== UNIT_DIMENSIONS[unit.unit_code as RawMaterialUnit]
      || unitCodes.has(unit.unit_code)) throw invalid()
    unitCodes.add(unit.unit_code)
    if (unit.unit_code === baseUnitCode) {
      baseUnitCount += 1
      return []
    }
    const factor = nullableString(unit.to_base_factor, invalid().message)
    const basis = nullableString(unit.basis, invalid().message)
    if (UNIT_DIMENSIONS[unit.unit_code as RawMaterialUnit] !== UNIT_DIMENSIONS[baseUnitCode as RawMaterialUnit]
      && (!factor || !basis)) throw invalid()
    return [{
      unitCode: unit.unit_code as RawMaterialUnit,
      toBaseFactor: factor ?? '',
      basis: basis ?? '',
    }]
  })
  if (baseUnitCount !== 1) throw invalid()

  return {
    id: value.id,
    sku: value.sku,
    lifecycleState: value.lifecycle_state,
    revisionId: revision.id as string,
    revisionNo: revision.revision_no as number,
    version: value.version,
    values: {
      categoryId,
      name,
      description: description ?? '',
      baseUnitCode: baseUnitCode as RawMaterialUnit,
      nominalQuantity: revision.nominal_quantity ?? '',
      usableQuantity: revision.usable_quantity ?? '',
      aliases: (revision.aliases as string[]).filter((alias) =>
        alias.trim().normalize('NFKC').toLocaleLowerCase('pl-PL')
        !== name.trim().normalize('NFKC').toLocaleLowerCase('pl-PL')),
      conversions,
    },
  }
}

function parseRevisedRawMaterial(value: unknown, expectedId: string): RevisedRawMaterial {
  if (!isRecord(value) || value.id !== expectedId
    || typeof value.sku !== 'string' || value.sku.length === 0
    || (value.lifecycle_state !== 'DRAFT' && value.lifecycle_state !== 'ACTIVE')
    || typeof value.version !== 'string' || value.version.length === 0
    || !isRecord(value.revision) || typeof value.revision.id !== 'string'
    || !UUID.test(value.revision.id) || !Number.isInteger(value.revision.revision_no)
    || Number(value.revision.revision_no) < 1) {
    throw new Error('Backend zwrócił nieprawidłowe potwierdzenie nowej rewizji surowca.')
  }
  return {
    id: value.id,
    sku: value.sku,
    lifecycleState: value.lifecycle_state,
    version: value.version,
    revision: { id: value.revision.id, revisionNo: value.revision.revision_no as number },
  }
}

export async function fetchRawMaterialCapabilities(
  expectedLocationId: string,
  signal?: AbortSignal,
): Promise<RawMaterialCapabilities> {
  const value = await request(RAW_CAPABILITIES_API, 'GET', undefined, { signal })
  return parseRawMaterialCapabilities(value, expectedLocationId)
}

export async function fetchRawMaterialCompletion(
  id: string,
  signal?: AbortSignal,
): Promise<RawMaterialCompletion> {
  return parseRawMaterialCompletion(await request(
    `${RAW_API}/${encodeURIComponent(id)}/completion`,
    'GET',
    undefined,
    { signal },
  ))
}

export async function fetchRawMaterialEditor(
  id: string,
  signal?: AbortSignal,
): Promise<RawMaterialEditor> {
  return parseRawMaterialEditor(await request(
    `${RAW_API}/${encodeURIComponent(id)}/editor`,
    'GET',
    undefined,
    { signal },
  ), id)
}

export async function reviseRawMaterial(
  id: string,
  payload: RawMaterialEditPayload,
  version: string,
): Promise<RevisedRawMaterial> {
  return parseRevisedRawMaterial(await request(
    `${RAW_API}/${encodeURIComponent(id)}/draft`,
    'PATCH',
    payload,
    { headers: { 'If-Match': version } },
  ), id)
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
