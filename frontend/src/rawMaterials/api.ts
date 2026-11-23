import { isRecord, request, requestDownload, requestEnvelope, requestFile } from '../api'
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
import {
  parseRawMaterialDetail,
  parseRawMaterialReferencePrices,
  parseRawMaterialRevisions,
  type RawMaterialDetail,
  type RawMaterialReferencePrice,
  type RawMaterialRevision,
} from './detail'
import {
  type RawMaterialPricingBasis,
  type RawMaterialCreatePayload,
  type RawMaterialCreateValues,
  type RawMaterialPricingUnit,
  type RawMaterialUnit,
  type RawMaterialUnitOption,
} from './create'

const RAW_API = '/api/v1/raw-materials'
const RAW_CAPABILITIES_API = '/api/v1/security/raw-materials/capabilities'
const RAW_UNIT_CODE = /^[a-z][a-z0-9_]{0,23}$/
const RAW_DIMENSIONS = ['MASS', 'VOLUME', 'COUNT'] as const
const DECIMAL = /^\d{1,30}(?:\.\d{1,14})?$/

export type RawMaterialCapabilities = {
  module: 'RAW_MATERIALS'
  locationId: string
  readiness: 'READY' | 'NOT_READY'
  available: boolean
  permissions: {
    create: boolean
    edit: boolean
    manageCategories?: boolean
    viewReferencePrice?: boolean
    manageReferencePrice?: boolean
    manageSuppliers?: boolean
  }
  actions: {
    create: boolean
    edit: boolean
    manageCategories?: boolean
    editAllergens?: boolean
    createAndApprove?: boolean
    viewReferencePrice?: boolean
    viewUsableCost?: boolean
    manageReferencePrice?: boolean
    manageSuppliers?: boolean
  }
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
  lifecycleState: 'DRAFT' | 'ACTIVE'
}

export type RawMaterialCompletion = {
  lifecycleState: string
  revisionId: string
  version: string | null
  availableActions: string[]
  missingRequirements?: Array<{ code: string; message: string; section: string }>
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
  pricing?: {
    basis: RawMaterialPricingBasis
    amount: string
    unit_code?: RawMaterialPricingUnit
  }
  supplier_ids?: string[]
  document_upload_ids?: string[]
  detach_document_version_ids?: string[]
  aliases: string[]
  conversions: Array<{
    unit_code: RawMaterialUnit
    to_base_factor?: string
    basis?: string
  }>
  change_reason?: string
  allergen_declaration?: AllergenDeclaration
}

export type RevisedRawMaterial = {
  id: string
  sku: string
  lifecycleState: 'DRAFT' | 'ACTIVE'
  version: string
  revision: { id: string; revisionNo: number }
}

export type RawMaterialDocumentStatus = 'PENDING' | 'CLEAN' | 'INFECTED' | 'ERROR'
export type RawMaterialDocumentType =
  | 'ALLERGEN_DECLARATION'
  | 'SUPPLIER_CERTIFICATE'
  | 'SPECIFICATION'
  | 'SAFETY_DATA_SHEET'
  | 'OTHER'

export type RawMaterialDocument = {
  id: string
  filename: string
  documentType: RawMaterialDocumentType
  mediaType: string
  sizeBytes: number
  status: RawMaterialDocumentStatus
  downloadUrl: string | null
  previewUrl: string | null
}

export type RawMaterialDocumentListing = {
  revisionId: string
  documents: RawMaterialDocument[]
}

export type RawMaterialUploadResult = {
  key: string
  uploadId: string | null
  filename: string | null
  mediaType: string | null
  sizeBytes: number | null
  status: 'STAGED' | 'FAILED'
  errorCode: string | null
  message: string
  expiresAt: string | null
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const RAW_SKU = /^RAW-\d{6}$/
const DOCUMENT_TYPES: readonly RawMaterialDocumentType[] = [
  'ALLERGEN_DECLARATION',
  'SUPPLIER_CERTIFICATE',
  'SPECIFICATION',
  'SAFETY_DATA_SHEET',
  'OTHER',
]
const DOCUMENT_MEDIA_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'text/plain', 'text/csv']

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

function parseCreatedRawMaterial(value: unknown, approved = false): CreatedRawMaterial {
  if (!isRecord(value) || typeof value.id !== 'string' || !UUID.test(value.id)
    || typeof value.code !== 'string' || !RAW_SKU.test(value.code)
    || value.sku !== value.code || value.lifecycle_state !== (approved ? 'ACTIVE' : 'DRAFT')) {
    throw new Error('Backend zwrócił nieprawidłowe potwierdzenie utworzenia surowca.')
  }

  return {
    id: value.id,
    sku: value.code,
    lifecycleState: approved ? 'ACTIVE' : 'DRAFT',
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
    || typeof value.actions.edit_allergens !== 'boolean'
    || typeof value.actions.create_and_approve !== 'boolean'
    || (value.available && value.readiness !== 'READY')
    || (value.actions.create && (!value.permissions.create || !value.available))
    || (value.actions.edit && (!value.permissions.edit || !value.available))
    || (value.actions.manage_categories === true && (value.permissions.manage_categories !== true || !value.available))
    || (value.actions.edit_allergens && !value.available)
    || (value.actions.create_and_approve && (!value.actions.create || !value.actions.edit_allergens))) {
    throw new Error('Backend zwrócił nieprawidłowe uprawnienia katalogu surowców.')
  }

  return {
    module: 'RAW_MATERIALS',
    locationId: value.location_id,
    readiness: value.readiness,
    available: value.available,
    permissions: {
      create: value.permissions.create,
      edit: value.permissions.edit,
      manageCategories: value.permissions.manage_categories === true,
      viewReferencePrice: value.permissions.view_reference_price === true,
      manageReferencePrice: value.permissions.manage_reference_price === true,
      manageSuppliers: value.permissions.manage_suppliers === true,
    },
    actions: { create: value.actions.create, edit: value.actions.edit,
      manageCategories: value.actions.manage_categories === true,
      editAllergens: value.actions.edit_allergens, createAndApprove: value.actions.create_and_approve,
      viewReferencePrice: value.actions.view_reference_price === true,
      viewUsableCost: value.actions.view_usable_cost === true,
      manageReferencePrice: value.actions.manage_reference_price === true,
      manageSuppliers: value.actions.manage_suppliers === true },
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
  let missingRequirements: RawMaterialCompletion['missingRequirements']
  if (Object.hasOwn(value, 'missing_requirements')) {
    if (!Array.isArray(value.missing_requirements)) throw new Error('Nieprawidłowa lista braków zatwierdzania.')
    missingRequirements = value.missing_requirements.map((problem) => {
      if (!isRecord(problem) || typeof problem.code !== 'string'
        || typeof problem.message !== 'string' || typeof problem.section !== 'string') {
        throw new Error('Nieprawidłowa lista braków zatwierdzania.')
      }
      return { code: problem.code, message: problem.message, section: problem.section }
    })
  }
  return {
    lifecycleState: value.lifecycle_state,
    revisionId: value.revision_id,
    version: value.version,
    availableActions: value.available_actions,
    ...(missingRequirements ? { missingRequirements } : {}),
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
    || typeof baseUnitCode !== 'string' || !RAW_UNIT_CODE.test(baseUnitCode)
    || typeof name !== 'string' || name.trim().length === 0
    || typeof revision.nominal_quantity !== 'string' && revision.nominal_quantity !== null
    || typeof revision.usable_quantity !== 'string' && revision.usable_quantity !== null
    || typeof revision.nominal_quantity === 'string' && !DECIMAL.test(revision.nominal_quantity)
    || typeof revision.usable_quantity === 'string' && !DECIMAL.test(revision.usable_quantity)
    || (revision.nominal_quantity === null) !== (revision.usable_quantity === null)) {
    throw invalid()
  }
  const usableQuantityExplicit = revision.usable_quantity_explicit === true
    || (revision.usable_quantity_explicit === undefined
      && typeof revision.usable_quantity === 'string'
      && revision.usable_quantity !== revision.nominal_quantity)
  if (usableQuantityExplicit && revision.usable_quantity === null) throw invalid()
  let pricingBasis: RawMaterialPricingBasis = 'PACKAGE'
  let pricingAmount = ''
  let pricingUnitCode: RawMaterialPricingUnit = baseUnitCode
  if (value.pricing_input !== null && value.pricing_input !== undefined) {
    if (!isRecord(value.pricing_input)
      || (value.pricing_input.basis !== 'PACKAGE' && value.pricing_input.basis !== 'NOMINAL_UNIT')
      || typeof value.pricing_input.amount !== 'string' || !DECIMAL.test(value.pricing_input.amount)) {
      throw invalid()
    }
    pricingBasis = value.pricing_input.basis
    pricingAmount = value.pricing_input.amount
    if (pricingBasis === 'NOMINAL_UNIT') {
      if (typeof value.pricing_input.unit_code !== 'string'
        || !RAW_UNIT_CODE.test(value.pricing_input.unit_code)) throw invalid()
      pricingUnitCode = value.pricing_input.unit_code
    }
  }
  const supplierIds = value.supplier_ids === undefined
    ? []
    : Array.isArray(value.supplier_ids) && value.supplier_ids.every((id) => typeof id === 'string' && UUID.test(id))
      ? value.supplier_ids as string[]
      : (() => { throw invalid() })()

  const description = nullableString(revision.description, invalid().message)
  const unitsValue = revision.units
  if (!Array.isArray(unitsValue)) throw invalid()
  const units = unitsValue
  const unitCodes = new Set<string>()
  let baseUnitCount = 0
  const conversions = units.flatMap((unit) => {
    if (!isRecord(unit)) throw invalid()
    if (typeof unit.unit_code !== 'string' || !RAW_UNIT_CODE.test(unit.unit_code)
      || typeof unit.dimension !== 'string' || !RAW_DIMENSIONS.includes(unit.dimension as typeof RAW_DIMENSIONS[number])
      || unitCodes.has(unit.unit_code)) throw invalid()
    unitCodes.add(unit.unit_code)
    if (unit.unit_code === baseUnitCode) {
      baseUnitCount += 1
      return []
    }
    const factor = nullableString(unit.to_base_factor, invalid().message)
    const basis = nullableString(unit.basis, invalid().message)
    if (unit.dimension !== revision.base_unit_dimension
      && (!factor || !basis)) throw invalid()
    return [{
      unitCode: unit.unit_code,
      toBaseFactor: factor ?? '',
      basis: basis ?? '',
    }]
  })
  const baseUnit = units.find((unit) => isRecord(unit) && unit.unit_code === baseUnitCode)
  const pricingUnit = units.find((unit) => isRecord(unit) && unit.unit_code === pricingUnitCode)
  if (baseUnitCount !== 1 || !baseUnit || !isRecord(baseUnit)
    || baseUnit.dimension !== revision.base_unit_dimension || baseUnit.conversion_kind !== 'BASE'
    || pricingBasis === 'NOMINAL_UNIT' && (!pricingUnit || !isRecord(pricingUnit)
      || pricingUnit.dimension !== revision.base_unit_dimension
      || !['BASE', 'METRIC'].includes(String(pricingUnit.conversion_kind)))) throw invalid()

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
      usableQuantity: usableQuantityExplicit ? revision.usable_quantity ?? '' : '',
      usableQuantityExplicit,
      pricingBasis,
      pricingAmount,
      pricingUnitCode,
      supplierIds,
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

function isDocumentType(value: unknown): value is RawMaterialDocumentType {
  return typeof value === 'string' && DOCUMENT_TYPES.some((type) => type === value)
}

function documentUrl(
  value: unknown,
  materialId: string,
  versionId: string,
  action: 'preview' | 'download',
  view: 'current' | 'editor',
): string | null {
  if (value === null) return null
  if (typeof value !== 'string') throw new Error('Backend zwrócił nieprawidłowy adres dokumentu.')
  const url = new URL(value, window.location.origin)
  const expectedPath = `${RAW_API}/${encodeURIComponent(materialId)}/documents/${encodeURIComponent(versionId)}/${action}`
  if (url.origin !== window.location.origin || url.pathname !== expectedPath
    || url.search !== (view === 'editor' ? '?view=editor' : '')) {
    throw new Error('Backend zwrócił nieprawidłowy adres dokumentu.')
  }
  return `${url.pathname}${url.search}`
}

function parseRawMaterialDocuments(
  value: unknown,
  materialId: string,
  view: 'current' | 'editor',
): RawMaterialDocumentListing {
  if (!isRecord(value) || typeof value.revision_id !== 'string' || !UUID.test(value.revision_id)
    || !Array.isArray(value.documents)) {
    throw new Error('Backend zwrócił nieprawidłową listę dokumentów surowca.')
  }
  const documents = value.documents.map((item): RawMaterialDocument => {
    if (!isRecord(item) || typeof item.id !== 'string' || !UUID.test(item.id)
      || typeof item.filename !== 'string' || !item.filename
      || !isDocumentType(item.document_type)
      || typeof item.media_type !== 'string' || !DOCUMENT_MEDIA_TYPES.includes(item.media_type)
      || !Number.isSafeInteger(item.size_bytes) || Number(item.size_bytes) < 0
      || !['PENDING', 'CLEAN', 'INFECTED', 'ERROR'].includes(String(item.status))) {
      throw new Error('Backend zwrócił nieprawidłowy dokument surowca.')
    }
    const status = item.status as RawMaterialDocumentStatus
    const downloadUrl = documentUrl(item.download_url, materialId, item.id, 'download', view)
    const previewUrl = documentUrl(item.preview_url, materialId, item.id, 'preview', view)
    if ((status === 'CLEAN') !== (downloadUrl !== null)
      || (previewUrl !== null && (!['application/pdf', 'image/png', 'image/jpeg'].includes(item.media_type)
        || status !== 'CLEAN'))) {
      throw new Error('Backend zwrócił niespójny stan dokumentu surowca.')
    }
    return {
      id: item.id,
      filename: item.filename,
      documentType: item.document_type,
      mediaType: item.media_type,
      sizeBytes: Number(item.size_bytes),
      status,
      downloadUrl,
      previewUrl,
    }
  })

  return { revisionId: value.revision_id, documents }
}

function parseUploadResult(value: unknown, key: string): RawMaterialUploadResult {
  if (!isRecord(value) || (value.key !== undefined && value.key !== key)
    || (value.upload_id !== undefined && value.upload_id !== null
      && (typeof value.upload_id !== 'string' || !UUID.test(value.upload_id)))
    || (value.filename !== undefined && value.filename !== null && typeof value.filename !== 'string')
    || (value.media_type !== undefined && value.media_type !== null && typeof value.media_type !== 'string')
    || (value.size_bytes !== undefined && value.size_bytes !== null
      && (!Number.isSafeInteger(value.size_bytes) || Number(value.size_bytes) < 0))
    || (value.status !== 'STAGED' && value.status !== 'FAILED')
    || (value.error_code !== undefined && value.error_code !== null && typeof value.error_code !== 'string')
    || typeof value.message !== 'string'
    || (value.expires_at !== undefined && value.expires_at !== null && typeof value.expires_at !== 'string')) {
    throw new Error('Backend zwrócił nieprawidłowy wynik uploadu dokumentu.')
  }
  if (value.status === 'STAGED' && (typeof value.upload_id !== 'string' || typeof value.filename !== 'string'
    || !value.filename
    || typeof value.media_type !== 'string' || typeof value.size_bytes !== 'number')) {
    throw new Error('Backend nie potwierdził poprawnego stagingu dokumentu.')
  }

  return {
    key,
    uploadId: typeof value.upload_id === 'string' ? value.upload_id : null,
    filename: typeof value.filename === 'string' ? value.filename : null,
    mediaType: typeof value.media_type === 'string' ? value.media_type : null,
    sizeBytes: typeof value.size_bytes === 'number' ? value.size_bytes : null,
    status: value.status,
    errorCode: typeof value.error_code === 'string' ? value.error_code : null,
    message: value.message,
    expiresAt: typeof value.expires_at === 'string' ? value.expires_at : null,
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

export async function fetchRawMaterialDocuments(
  id: string,
  view: 'current' | 'editor',
  signal?: AbortSignal,
): Promise<RawMaterialDocumentListing> {
  const query = view === 'editor' ? '?view=editor' : ''
  const value = await request(`${RAW_API}/${encodeURIComponent(id)}/documents${query}`, 'GET', undefined, { signal })
  return parseRawMaterialDocuments(value, id, view)
}

export async function uploadRawMaterialDocuments(
  files: Array<{ file: File; key: string }>,
  signal?: AbortSignal,
): Promise<RawMaterialUploadResult[]> {
  const body = new FormData()
  files.forEach(({ file, key }, index) => {
    body.append(`files[${index}]`, file, file.name)
    body.append(`keys[${index}]`, key)
  })
  const response = await requestEnvelope('/api/v1/raw-materials/document-uploads', 'POST', body, {
    signal,
    timeoutMs: 60_000,
  })
  if (!Array.isArray(response.data) || response.data.length !== files.length) {
    throw new Error('Backend zwrócił niepełny wynik uploadu dokumentów.')
  }
  const results = response.data.map((item, index) => parseUploadResult(item, files[index].key))
  if (new Set(results.map((result) => result.key)).size !== files.length) {
    throw new Error('Backend zwrócił powielone wyniki uploadu dokumentów.')
  }
  return results
}

export async function retryRawMaterialDocumentUpload(
  uploadId: string,
  key: string,
  signal?: AbortSignal,
): Promise<RawMaterialUploadResult> {
  const value = await request(
    `${RAW_API}/document-uploads/${encodeURIComponent(uploadId)}/retry`,
    'POST',
    {},
    { signal, timeoutMs: 60_000 },
  )
  return parseUploadResult(value, key)
}

export async function fetchRawMaterialDocumentFile(
  path: string,
  mediaType: string,
  filename: string,
  signal?: AbortSignal,
): Promise<{ blob: Blob; filename: string; mediaType: string }> {
  return requestFile(path, [mediaType], filename, { signal, timeoutMs: 60_000 })
}

export async function fetchCatalog(query: CatalogQuery, signal?: AbortSignal): Promise<CatalogPage> {
  const params = catalogSearchParams(query)
  const path = query.view === 'workbench' ? `${RAW_API}/workbench` : RAW_API
  const envelope = await requestEnvelope(`${path}?${params}`, 'GET', undefined, { signal })
  const result = parseCatalogEnvelope(envelope)
  if (query.view === 'workbench' && result.rows.some((row) => !row.workflowState)) {
    throw new Error('Backend nie potwierdził statusu rewizji roboczej.')
  }
  return result
}

export async function changeRawMaterialLifecycle(id: string, action: 'submit' | 'approve', version: string, reason: string): Promise<void> {
  const value = await request(`${RAW_API}/${encodeURIComponent(id)}/${action}`, 'POST',
    action === 'approve' ? { reason } : {}, { headers: { 'If-Match': version } })
  if (!isRecord(value) || value.id !== id || typeof value.sku !== 'string'
    || (value.lifecycle_state !== (action === 'submit' ? 'VERIFICATION' : 'ACTIVE') && value.lifecycle_state !== 'ACTIVE')
    || typeof value.version !== 'string' || value.version.length === 0) {
    throw new Error('Nie można potwierdzić wyniku zmiany statusu. Wczytaj aktualne dane przed ponowieniem.')
  }
}

export type AllergenCatalog = {
  id: string
  versionNumber: number
  isCurrent: boolean
  definitions: Array<{ code: string; name: string; sources: string[] }>
}
export type AllergenDeclaration = {
  status: 'UNKNOWN' | 'DECLARED' | 'NO_KNOWN_ALLERGENS'
  catalog_version_id: string | null
  items: Array<{ allergen_code: string; exposure_kind: 'DIRECT' | 'CROSS_CONTACT_POTENTIAL' }>
}

export const unknownAllergens = (): AllergenDeclaration => ({
  status: 'UNKNOWN', catalog_version_id: null, items: [],
})

export function allergenError(value: AllergenDeclaration, required: boolean): string | null {
  if (value.status === 'UNKNOWN') return required ? 'Wybierz deklarację alergenów.' : null
  if (!value.catalog_version_id) return 'Wybierz katalog alergenów.'
  if (value.status === 'DECLARED' && value.items.length === 0) return 'Wybierz co najmniej jeden alergen.'
  return null
}

export async function fetchAllergenCatalogs(signal?: AbortSignal): Promise<AllergenCatalog[]> {
  const value = await request(`${RAW_API}/allergen-catalog`, 'GET', undefined, { signal })
  if (!Array.isArray(value)) throw new Error('Nieprawidłowy katalog alergenów.')
  return value.map((catalog) => {
    if (!isRecord(catalog) || typeof catalog.id !== 'string' || !UUID.test(catalog.id)
      || typeof catalog.version_number !== 'number' || !Number.isInteger(catalog.version_number) || catalog.version_number < 1
      || !(catalog.is_current === undefined || typeof catalog.is_current === 'boolean')
      || !Array.isArray(catalog.definitions)) {
      throw new Error('Nieprawidłowy katalog alergenów.')
    }
    return { id: catalog.id, versionNumber: catalog.version_number, definitions: catalog.definitions.map((definition) => {
      if (!isRecord(definition) || typeof definition.allergen_code !== 'string' || typeof definition.name_pl !== 'string'
        || !(definition.source_codes === undefined || Array.isArray(definition.source_codes)
          && definition.source_codes.every((source) => typeof source === 'string'))) {
        throw new Error('Nieprawidłowy katalog alergenów.')
      }
      return {
        code: definition.allergen_code,
        name: definition.name_pl,
        sources: Array.isArray(definition.source_codes) ? definition.source_codes : [],
      }
    }), isCurrent: catalog.is_current === true }
  })
}

export async function saveAllergenDeclaration(id: string, input: AllergenDeclaration, version: string): Promise<void> {
  const result = await request(`${RAW_API}/${encodeURIComponent(id)}/draft/allergen-declaration`, 'PUT', input,
    { headers: { 'If-Match': version } })
  if (!isRecord(result) || result.id !== id || typeof result.version !== 'string' || result.version.length === 0) {
    throw new Error('Nie można potwierdzić zapisu deklaracji. Wczytaj aktualne dane przed ponowieniem.')
  }
}

export async function fetchAllergenDeclaration(id: string, signal?: AbortSignal): Promise<{ version: string; declaration: AllergenDeclaration }> {
  const value = await request(`${RAW_API}/${encodeURIComponent(id)}/editor`, 'GET', undefined, { signal })
  if (!isRecord(value) || value.id !== id || typeof value.version !== 'string' || value.version.length === 0) {
    throw new Error('Nieprawidłowa wersja deklaracji alergenów.')
  }
  const declaration = value.allergen_declaration
  if (declaration === null) {
    return { version: value.version, declaration: { status: 'UNKNOWN', catalog_version_id: null, items: [] } }
  }
  if (!isRecord(declaration)
    || !(declaration.catalog_version_id === null || typeof declaration.catalog_version_id === 'string' && UUID.test(declaration.catalog_version_id))
    || !Array.isArray(declaration.items)) {
    throw new Error('Nieprawidłowa deklaracja alergenów.')
  }
  const status = declaration.status
  if (status !== 'UNKNOWN' && status !== 'DECLARED' && status !== 'NO_KNOWN_ALLERGENS') {
    throw new Error('Nieprawidłowy status deklaracji alergenów.')
  }
  const items = declaration.items.map((item): AllergenDeclaration['items'][number] => {
    if (!isRecord(item) || typeof item.allergen_code !== 'string'
      || item.exposure_kind !== 'DIRECT' && item.exposure_kind !== 'CROSS_CONTACT_POTENTIAL') {
      throw new Error('Nieprawidłowa pozycja deklaracji alergenów.')
    }
    return { allergen_code: item.allergen_code, exposure_kind: item.exposure_kind }
  })
  return { version: value.version, declaration: { status, catalog_version_id: declaration.catalog_version_id, items } }
}

export async function fetchCategories(signal?: AbortSignal): Promise<CategoryOption[]> {
  return parseCategories(await request(`${RAW_API}/categories`, 'GET', undefined, { signal }))
}

export async function fetchRawMaterialUnits(signal?: AbortSignal): Promise<RawMaterialUnitOption[]> {
  const value = await request(`${RAW_API}/units`, 'GET', undefined, { signal })
  if (!Array.isArray(value)) throw new Error('Backend zwrócił nieprawidłowy słownik jednostek RAW.')
  return value.map((unit) => {
    if (!isRecord(unit) || typeof unit.code !== 'string' || !RAW_UNIT_CODE.test(unit.code)
      || typeof unit.name !== 'string' || typeof unit.symbol !== 'string'
      || typeof unit.dimension !== 'string' || !RAW_DIMENSIONS.includes(unit.dimension as typeof RAW_DIMENSIONS[number])
      || typeof unit.base_unit_code !== 'string' || !RAW_UNIT_CODE.test(unit.base_unit_code)
      || typeof unit.to_base_factor !== 'string'
      || !(unit.archived_at === null || typeof unit.archived_at === 'string')
      || typeof unit.is_base !== 'boolean') {
      throw new Error('Backend zwrócił nieprawidłową jednostkę RAW.')
    }
    return {
      code: unit.code,
      name: unit.name,
      symbol: unit.symbol,
      dimension: unit.dimension as RawMaterialUnitOption['dimension'],
      baseUnitCode: unit.base_unit_code,
      toBaseFactor: unit.to_base_factor,
      archivedAt: unit.archived_at,
      isBase: unit.is_base,
    }
  })
}

export type PricingPreview = {
  netPlnPerPackage: string
  usableUnitCost?: string
  usableCostBasis?: 'PLN/100g' | 'PLN/100ml' | 'PLN/pc'
}

export async function previewRawMaterialPricing(
  payload: {
    base_unit_code: RawMaterialUnit
    nominal_quantity: string
    usable_quantity?: string
    pricing: { basis: RawMaterialPricingBasis; amount: string; unit_code?: RawMaterialPricingUnit }
  },
  signal?: AbortSignal,
): Promise<PricingPreview> {
  const value = await request(`${RAW_API}/pricing-preview`, 'POST', payload, { signal })
  if (!isRecord(value) || typeof value.net_pln_per_package !== 'string' || !DECIMAL.test(value.net_pln_per_package)
    || (value.usable_unit_cost !== undefined && (typeof value.usable_unit_cost !== 'string'
      || !DECIMAL.test(value.usable_unit_cost)
      || !['PLN/100g', 'PLN/100ml', 'PLN/pc'].includes(String(value.usable_cost_basis))))
    || (value.usable_unit_cost === undefined && value.usable_cost_basis !== undefined)) {
    throw new Error('Backend zwrócił nieprawidłowy podgląd ceny.')
  }
  return {
    netPlnPerPackage: value.net_pln_per_package,
    ...(typeof value.usable_unit_cost === 'string'
      && (value.usable_cost_basis === 'PLN/100g' || value.usable_cost_basis === 'PLN/100ml' || value.usable_cost_basis === 'PLN/pc')
      ? { usableUnitCost: value.usable_unit_cost, usableCostBasis: value.usable_cost_basis } : {}),
  }
}

export async function createCategory(
  payload: { code: string; name: string; parent_id?: string },
  signal?: AbortSignal,
): Promise<CategoryOption> {
  return parseCategories([await request(`${RAW_API}/categories`, 'POST', payload, { signal })])[0]
}

export async function renameCategory(id: string, name: string, signal?: AbortSignal): Promise<CategoryOption> {
  return parseCategories([await request(`${RAW_API}/categories/${encodeURIComponent(id)}`, 'PATCH', { name }, { signal })])[0]
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
  payload: RawMaterialCreatePayload & { allergen_declaration?: AllergenDeclaration },
  reservationId: string,
): Promise<CreatedRawMaterial> {
  return parseCreatedRawMaterial(await request(
    RAW_API,
    'POST',
    { ...payload, sku_reservation_id: reservationId },
  ))
}

export async function createApprovedRawMaterial(
  payload: RawMaterialCreatePayload & { allergen_declaration: AllergenDeclaration },
  reservationId: string,
): Promise<CreatedRawMaterial> {
  return parseCreatedRawMaterial(await request(
    `${RAW_API}/create-approved`, 'POST', { ...payload, sku_reservation_id: reservationId },
  ), true)
}

export async function fetchSuppliers(search: string, signal?: AbortSignal): Promise<SupplierOption[]> {
  const params = new URLSearchParams({ per_page: '100', lifecycle_state: 'ACTIVE' })
  if (search.trim()) params.set('search', search.trim())
  const envelope = await requestEnvelope(`${RAW_API}/suppliers?${params}`, 'GET', undefined, { signal })
  return parseSuppliers(envelope.data)
}

export async function fetchRawMaterialDetail(id: string, signal?: AbortSignal, working = false): Promise<RawMaterialDetail> {
  const path = `${RAW_API}/${encodeURIComponent(id)}${working ? '/editor' : ''}`
  return parseRawMaterialDetail(await request(path, 'GET', undefined, { signal }))
}

export async function fetchRawMaterialRevisions(id: string, signal?: AbortSignal): Promise<RawMaterialRevision[]> {
  return parseRawMaterialRevisions(await request(`${RAW_API}/${encodeURIComponent(id)}/revisions`, 'GET', undefined, { signal }))
}

export async function fetchRawMaterialReferencePrices(
  id: string,
  signal?: AbortSignal,
): Promise<RawMaterialReferencePrice[]> {
  const response = await requestEnvelope(
    `${RAW_API}/${encodeURIComponent(id)}/reference-prices`,
    'GET',
    undefined,
    { signal },
  )
  return parseRawMaterialReferencePrices(response.data)
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
