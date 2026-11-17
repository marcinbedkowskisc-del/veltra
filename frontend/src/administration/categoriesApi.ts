import { isRecord, request, requestEnvelope } from '../api'

const API = '/api/v1/administration/categories'
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export type AdministrationCategory = {
  id: string
  parentId: string | null
  parentName: string | null
  code: string
  name: string
  description: string | null
  depth: number
  archivedAt: string | null
  hasChildren: boolean
  rawMaterialCount: number
  path: string[]
}

export type AdministrationCategoryPage = {
  data: AdministrationCategory[]
  meta: { currentPage: number; perPage: number; total: number; lastPage: number }
}

export type CategoryListQuery = {
  status: 'active' | 'archived' | 'all'
  search?: string
  parentId?: string | null
  page?: number
  perPage?: number
}

function parseCategory(value: unknown): AdministrationCategory {
  if (!isRecord(value)
    || typeof value.id !== 'string' || !UUID.test(value.id)
    || (value.parent_id !== null && (typeof value.parent_id !== 'string' || !UUID.test(value.parent_id)))
    || (value.parent_name !== null && typeof value.parent_name !== 'string')
    || typeof value.code !== 'string' || value.code.length === 0
    || typeof value.name !== 'string' || value.name.length === 0
    || (value.description !== null && typeof value.description !== 'string')
    || !Number.isInteger(value.depth) || Number(value.depth) < 1 || Number(value.depth) > 3
    || (value.archived_at !== null && (typeof value.archived_at !== 'string' || Number.isNaN(Date.parse(value.archived_at))))
    || typeof value.has_children !== 'boolean'
    || !Number.isInteger(value.raw_material_count) || Number(value.raw_material_count) < 0
    || !Array.isArray(value.path) || !value.path.every((part) => typeof part === 'string')) {
    throw new Error('Backend zwrócił nieprawidłowe dane kategorii.')
  }

  return {
    id: value.id,
    parentId: value.parent_id,
    parentName: value.parent_name,
    code: value.code,
    name: value.name,
    description: value.description,
    depth: Number(value.depth),
    archivedAt: value.archived_at,
    hasChildren: value.has_children,
    rawMaterialCount: Number(value.raw_material_count),
    path: value.path,
  }
}

export async function fetchAdministrationCategories(
  query: CategoryListQuery,
  signal?: AbortSignal,
): Promise<AdministrationCategoryPage> {
  const params = new URLSearchParams({
    status: query.status,
    page: String(query.page ?? 1),
    per_page: String(query.perPage ?? 50),
  })
  if (query.search) params.set('search', query.search)
  if (query.parentId) params.set('parent_id', query.parentId)

  const response = await requestEnvelope(`${API}?${params}`, 'GET', undefined, { signal })
  if (!Array.isArray(response.data) || !isRecord(response.meta)
    || !Number.isInteger(response.meta.current_page) || !Number.isInteger(response.meta.per_page)
    || !Number.isInteger(response.meta.total) || !Number.isInteger(response.meta.last_page)) {
    throw new Error('Backend zwrócił nieprawidłową listę kategorii.')
  }

  return {
    data: response.data.map(parseCategory),
    meta: {
      currentPage: Number(response.meta.current_page),
      perPage: Number(response.meta.per_page),
      total: Number(response.meta.total),
      lastPage: Number(response.meta.last_page),
    },
  }
}

export async function createAdministrationCategory(
  input: { parent_id: string | null; code: string; name: string; description: string | null },
  signal?: AbortSignal,
): Promise<AdministrationCategory> {
  return parseCategory(await request(API, 'POST', input, { signal }))
}

export async function updateAdministrationCategory(
  id: string,
  input: { name: string; description: string | null },
  signal?: AbortSignal,
): Promise<AdministrationCategory> {
  return parseCategory(await request(`${API}/${encodeURIComponent(id)}`, 'PATCH', input, { signal }))
}

export async function archiveAdministrationCategory(id: string, signal?: AbortSignal): Promise<AdministrationCategory> {
  return parseCategory(await request(`${API}/${encodeURIComponent(id)}/archive`, 'POST', {}, { signal }))
}

export async function restoreAdministrationCategory(id: string, signal?: AbortSignal): Promise<AdministrationCategory> {
  return parseCategory(await request(`${API}/${encodeURIComponent(id)}/restore`, 'POST', {}, { signal }))
}
