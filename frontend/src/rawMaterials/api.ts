import { request, requestDownload, requestEnvelope } from '../api'
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

const RAW_API = '/api/v1/raw-materials'

export async function fetchCatalog(query: CatalogQuery, signal?: AbortSignal): Promise<CatalogPage> {
  const params = catalogSearchParams(query)
  const envelope = await requestEnvelope(`${RAW_API}?${params}`, 'GET', undefined, { signal })
  return parseCatalogEnvelope(envelope)
}

export async function fetchCategories(signal?: AbortSignal): Promise<CategoryOption[]> {
  return parseCategories(await request(`${RAW_API}/categories`, 'GET', undefined, { signal }))
}

export async function fetchSuppliers(search: string, signal?: AbortSignal): Promise<SupplierOption[]> {
  const params = new URLSearchParams({ per_page: '100', lifecycle_state: 'ACTIVE' })
  if (search.trim()) params.set('search', search.trim())
  const envelope = await requestEnvelope(`${RAW_API}/suppliers?${params}`, 'GET', undefined, { signal })
  return parseSuppliers(envelope.data)
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
