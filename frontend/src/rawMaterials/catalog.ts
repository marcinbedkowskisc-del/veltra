import { isRecord } from '../api'
import { formatNumber } from '../design-system/formatting'

export const PAGE_SIZES = [25, 50, 100, 200] as const
export const RAW_UNITS = ['mg', 'g', 'kg', 'ml', 'l', 'pc'] as const
export const LIFECYCLE_STATES = ['DRAFT', 'VERIFICATION', 'ACTIVE', 'BLOCKED', 'ARCHIVED'] as const

export const CATALOG_COLUMNS = [
  'Lp.',
  'SKU',
  'Nazwa',
  'Jednostka',
  'Ilość nominalna',
  'Ilość użytkowa',
  'Cena netto',
  'Koszt użytkowy',
] as const

export type SortKey =
  | 'sku'
  | 'name'
  | 'unit'
  | 'nominal_quantity'
  | 'usable_quantity'
  | 'reference_net_price'
  | 'usable_unit_cost'

export type SortDirection = 'asc' | 'desc'

export type CatalogFilters = {
  search: string
  categoryId: string
  supplierId: string
  lifecycleState: string
  unit: string
}

export type CatalogQuery = CatalogFilters & {
  view?: 'workbench'
  sort: SortKey
  direction: SortDirection
  page: number
  perPage: (typeof PAGE_SIZES)[number]
}

export type CatalogRow = {
  workflowState?: 'DRAFT' | 'VERIFICATION'
  id: string
  ordinal: number
  sku: string
  name: string
  unit: string
  nominalQuantity: string | null
  usableQuantity: string | null
  lifecycleState: string
  referencePrice?: string | null
  usableUnitCost?: string | null
  usableCostBasis?: string
}

export type CatalogMeta = {
  currentPage: number
  perPage: number
  total: number
  lastPage: number
  from: number | null
  to: number | null
}

export type CatalogPage = {
  rows: CatalogRow[]
  meta: CatalogMeta
  canReadReferencePrice: boolean | null
  canReadUsableCost: boolean | null
}

export type CategoryOption = { id: string; name: string }
export type SupplierOption = { id: string; code: string; name: string }

const COST_BASES = new Set(['PLN/100g', 'PLN/100ml', 'PLN/pc'])
const DECIMAL = /^-?\d{1,30}(?:\.\d{1,14})?$/

function requiredString(value: unknown, field: string): string {
  if (typeof value !== 'string' || value.length === 0) {
    throw new Error(`Nieprawidłowe pole odpowiedzi katalogu: ${field}.`)
  }
  return value
}

function nullableDecimal(value: unknown, field: string): string | null {
  if (value === null) return null
  if (typeof value !== 'string' || !DECIMAL.test(value)) {
    throw new Error(`Nieprawidłowe pole odpowiedzi katalogu: ${field}.`)
  }
  return value
}

function parseMeta(value: unknown): CatalogMeta {
  if (!isRecord(value)) throw new Error('Nieprawidłowa paginacja katalogu.')

  const { current_page: currentPage, per_page: perPage, total, last_page: lastPage, from, to } = value
  if (!Number.isInteger(currentPage) || !Number.isInteger(perPage) || !Number.isInteger(total)
    || !Number.isInteger(lastPage) || (from !== null && !Number.isInteger(from))
    || (to !== null && !Number.isInteger(to))) {
    throw new Error('Nieprawidłowa paginacja katalogu.')
  }

  return {
    currentPage: currentPage as number,
    perPage: perPage as number,
    total: total as number,
    lastPage: lastPage as number,
    from: from as number | null,
    to: to as number | null,
  }
}

type ParsedRow = {
  row: CatalogRow
  hasReferencePrice: boolean
  hasUsableCost: boolean
}

function parseRow(value: unknown): ParsedRow {
  if (!isRecord(value) || !isRecord(value.revision)) {
    throw new Error('Backend zwrócił nieprawidłowy wiersz katalogu.')
  }
  const row = value
  const revision = value.revision
  if (!Number.isInteger(row.ordinal) || typeof row.id !== 'string' || typeof row.sku !== 'string'
    || typeof row.lifecycle_state !== 'string') {
    throw new Error('Backend zwrócił nieprawidłowy wiersz katalogu.')
  }

  const hasReferencePrice = Object.hasOwn(row, 'reference_net_price')
  const hasUsableCost = Object.hasOwn(row, 'usable_unit_cost') && Object.hasOwn(row, 'usable_cost_basis')
  if ((Object.hasOwn(row, 'usable_unit_cost') !== Object.hasOwn(row, 'usable_cost_basis'))
    || (hasReferencePrice && row.currency_code !== 'PLN')
    || (hasUsableCost && (typeof row.usable_cost_basis !== 'string' || !COST_BASES.has(row.usable_cost_basis)))) {
    throw new Error('Backend zwrócił nieprawidłowe dane finansowe katalogu.')
  }

  const parsed: CatalogRow = {
    id: requiredString(row.id, 'id'),
    ordinal: row.ordinal as number,
    sku: requiredString(row.sku, 'sku'),
    name: requiredString(revision.name, 'revision.name'),
    unit: requiredString(revision.base_unit_code, 'revision.base_unit_code'),
    nominalQuantity: nullableDecimal(row.nominal_quantity, 'nominal_quantity'),
    usableQuantity: nullableDecimal(row.usable_quantity, 'usable_quantity'),
    lifecycleState: requiredString(row.lifecycle_state, 'lifecycle_state'),
  }
  if (Object.hasOwn(row, 'workflow_state')) {
    if (row.workflow_state !== 'DRAFT' && row.workflow_state !== 'VERIFICATION') {
      throw new Error('Backend zwrócił nieprawidłowy status rewizji roboczej.')
    }
    parsed.workflowState = row.workflow_state
  }

  if (hasReferencePrice) {
    parsed.referencePrice = nullableDecimal(row.reference_net_price, 'reference_net_price')
  }
  if (hasUsableCost) {
    parsed.usableUnitCost = nullableDecimal(row.usable_unit_cost, 'usable_unit_cost')
    parsed.usableCostBasis = row.usable_cost_basis as string
  }

  return { row: parsed, hasReferencePrice, hasUsableCost }
}

export function parseCatalogEnvelope(value: unknown): CatalogPage {
  if (!isRecord(value) || !Array.isArray(value.data)) {
    throw new Error('Backend zwrócił nieprawidłową odpowiedź katalogu.')
  }
  const parsedRows = value.data.map(parseRow)
  const canReadReferencePrice = parsedRows.length === 0
    ? null
    : parsedRows.every(({ hasReferencePrice }) => hasReferencePrice)
  const canReadUsableCost = parsedRows.length === 0
    ? null
    : parsedRows.every(({ hasUsableCost }) => hasUsableCost)

  return {
    rows: parsedRows.map(({ row, hasReferencePrice, hasUsableCost }) => ({
      id: row.id,
      ordinal: row.ordinal,
      sku: row.sku,
      name: row.name,
      unit: row.unit,
      nominalQuantity: row.nominalQuantity,
      usableQuantity: row.usableQuantity,
      lifecycleState: row.lifecycleState,
      ...(row.workflowState ? { workflowState: row.workflowState } : {}),
      ...(canReadReferencePrice && hasReferencePrice ? { referencePrice: row.referencePrice } : {}),
      ...(canReadUsableCost && hasUsableCost
        ? { usableUnitCost: row.usableUnitCost, usableCostBasis: row.usableCostBasis }
        : {}),
    })),
    meta: parseMeta(value.meta),
    canReadReferencePrice,
    canReadUsableCost,
  }
}

export function parseCategories(value: unknown): CategoryOption[] {
  if (!Array.isArray(value)) throw new Error('Backend zwrócił nieprawidłową listę kategorii.')

  return value.map((category) => {
    if (!isRecord(category)) throw new Error('Backend zwrócił nieprawidłową listę kategorii.')
    return {
      id: requiredString(category.id, 'category.id'),
      name: requiredString(category.name, 'category.name'),
    }
  })
}

export function parseSuppliers(value: unknown): SupplierOption[] {
  if (!isRecord(value) || !Array.isArray(value.data)) {
    throw new Error('Backend zwrócił nieprawidłową listę dostawców.')
  }

  return value.data.map((supplier) => {
    if (!isRecord(supplier)) throw new Error('Backend zwrócił nieprawidłową listę dostawców.')
    return {
      id: requiredString(supplier.id, 'supplier.id'),
      code: requiredString(supplier.supplier_code, 'supplier.supplier_code'),
      name: requiredString(supplier.display_name, 'supplier.display_name'),
    }
  })
}

export function supplierLabel(supplier: SupplierOption): string {
  return `${supplier.name} (${supplier.code})`
}

export function catalogSearchParams(query: CatalogQuery): URLSearchParams {
  const params = new URLSearchParams({
    sort: query.sort,
    direction: query.direction,
    page: String(query.page),
    per_page: String(query.perPage),
  })
  if (query.search.trim()) params.set('search', query.search.trim())
  if (query.categoryId) params.set('category_id', query.categoryId)
  if (query.supplierId) params.set('supplier_id', query.supplierId)
  if (query.lifecycleState) params.set('lifecycle_state', query.lifecycleState)
  if (query.unit) params.set('unit', query.unit)

  return params
}

export function catalogExportPayload(
  query: CatalogQuery,
  materialIds?: string[],
): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    sort: query.sort,
    direction: query.direction,
  }
  if (query.search.trim()) payload.search = query.search.trim()
  if (query.categoryId) payload.category_id = query.categoryId
  if (query.supplierId) payload.supplier_id = query.supplierId
  if (query.lifecycleState) payload.lifecycle_state = query.lifecycleState
  if (query.unit) payload.unit = query.unit
  if (materialIds?.length) payload.material_ids = [...materialIds]

  return payload
}

export function formatDecimal(value: string | null): string {
  return formatNumber(value)
}

export function formatMoney(value: string | null): string {
  if (value === null) return 'Brak danych'
  if (!DECIMAL.test(value)) throw new Error('Nieprawidłowa kwota do wyświetlenia.')

  const negative = value.startsWith('-')
  const unsigned = negative ? value.slice(1) : value
  const [wholeText, fraction = ''] = unsigned.split('.')
  let whole = BigInt(wholeText)
  let cents = BigInt(fraction.padEnd(2, '0').slice(0, 2))
  if ((fraction[2] ?? '0') >= '5') cents += 1n
  if (cents === 100n) {
    whole += 1n
    cents = 0n
  }
  const formattedWhole = new Intl.NumberFormat('pl-PL').format(whole)
  const sign = negative ? '−' : ''

  return `${sign}${formattedWhole},${cents.toString().padStart(2, '0')}`
}

export function lifecycleLabel(state: string): string {
  return ({
    DRAFT: 'Szkic',
    VERIFICATION: 'Weryfikacja',
    ACTIVE: 'Aktywny',
    BLOCKED: 'Zablokowany',
    ARCHIVED: 'Zarchiwizowany',
  } as Record<string, string>)[state] ?? state
}
