import { isRecord, requestEnvelope } from '../api'

export type BossRawMaterial = {
  id: string
  code: string
  name: string
  category: { id: string; name: string }
  base_unit_code: string
  supplier_names: string[] | null
}

export type BossRawMaterialsMeta = {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export type BossRawMaterialsPage = {
  data: BossRawMaterial[]
  meta: BossRawMaterialsMeta
}

const MATERIAL_KEYS = ['id', 'code', 'name', 'category', 'base_unit_code', 'supplier_names']
const META_KEYS = ['current_page', 'per_page', 'total', 'last_page']

function hasExactKeys(value: Record<string, unknown>, keys: string[]): boolean {
  return Object.keys(value).length === keys.length && keys.every((key) => key in value)
}

function isBossRawMaterial(value: unknown): value is BossRawMaterial {
  if (!isRecord(value) || !hasExactKeys(value, MATERIAL_KEYS) || !isRecord(value.category)) return false
  if (!hasExactKeys(value.category, ['id', 'name'])) return false
  if (![value.id, value.code, value.name, value.category.id, value.category.name, value.base_unit_code]
    .every((field) => typeof field === 'string')) return false

  return value.supplier_names === null
    || (Array.isArray(value.supplier_names) && value.supplier_names.every((name) => typeof name === 'string'))
}

function isMeta(value: unknown): value is BossRawMaterialsMeta {
  return isRecord(value)
    && hasExactKeys(value, META_KEYS)
    && Number.isSafeInteger(value.current_page)
    && Number(value.current_page) >= 1
    && Number.isSafeInteger(value.per_page)
    && Number(value.per_page) === 25
    && Number.isSafeInteger(value.total)
    && Number(value.total) >= 0
    && Number.isSafeInteger(value.last_page)
    && Number(value.last_page) >= 1
}

function invalidResponse(): Error {
  return new Error('Backend zwrócił nieprawidłowy katalog BOSS.')
}

export async function fetchBossRawMaterials(
  search: string,
  page: number,
  signal?: AbortSignal,
): Promise<BossRawMaterialsPage> {
  const params = new URLSearchParams({ page: String(page), per_page: '25' })
  const normalizedSearch = search.trim()
  if (normalizedSearch) params.set('search', normalizedSearch)

  const response = await requestEnvelope(
    `/api/v1/boss/raw-materials?${params.toString()}`,
    'GET',
    undefined,
    { signal },
  )
  if (!Array.isArray(response.data) || !response.data.every(isBossRawMaterial) || !isMeta(response.meta)) {
    throw invalidResponse()
  }

  return {
    data: response.data,
    meta: response.meta,
  }
}

export async function fetchBossRawMaterial(
  materialId: string,
  signal?: AbortSignal,
): Promise<BossRawMaterial> {
  const response = await requestEnvelope(
    `/api/v1/boss/raw-materials/${encodeURIComponent(materialId)}`,
    'GET',
    undefined,
    { signal },
  )
  if (!isBossRawMaterial(response.data)) throw invalidResponse()

  return response.data
}
