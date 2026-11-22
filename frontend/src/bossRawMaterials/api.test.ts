import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import { fetchBossRawMaterial, fetchBossRawMaterials, type BossRawMaterial } from './api'

const materialId = '00000000-0000-4000-8000-000000000201'

const material: BossRawMaterial = {
  id: materialId,
  code: 'RAW-000201',
  name: 'Mąka pszenna',
  category: { id: '00000000-0000-4000-8000-000000000203', name: 'Mąki' },
  base_unit_code: 'kg',
  supplier_names: ['Dostawca A'],
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', 'Content-Language': 'pl' },
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('kontrakt API katalogu BOSS', () => {
  it('pobiera paginowaną listę z wyszukiwaniem wyłącznie przez trasę BOSS', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
      data: [material],
      meta: { current_page: 2, per_page: 25, total: 26, last_page: 2 },
    }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(fetchBossRawMaterials(' mąka ', 2)).resolves.toEqual({
      data: [material],
      meta: { current_page: 2, per_page: 25, total: 26, last_page: 2 },
    })
    expect(String(fetchMock.mock.calls[0][0])).toBe(
      '/api/v1/boss/raw-materials?page=2&per_page=25&search=m%C4%85ka',
    )
    expect(String(fetchMock.mock.calls[0][0])).not.toContain('/api/v1/raw-materials')
  })

  it.each([
    ['brak snapshotu', null],
    ['zatwierdzony pusty snapshot', []],
    ['zatwierdzony snapshot z dostawcą', ['Dostawca A']],
  ])('zachowuje semantykę dostawców: %s', async (_label, supplierNames) => {
    const item = { ...material, supplier_names: supplierNames }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ data: item })))

    await expect(fetchBossRawMaterial(materialId)).resolves.toEqual(item)
  })

  it('odrzuca niekompletną odpowiedź listy i detalu', async () => {
    const malformedMaterial = { ...material, supplier_names: 'legacy supplier' }
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse({
        data: [malformedMaterial],
        meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
      }))
      .mockResolvedValueOnce(jsonResponse({ data: { ...material, category: { id: 'id' } } })))

    await expect(fetchBossRawMaterials('', 1)).rejects.toThrow('nieprawidłowy katalog BOSS')
    await expect(fetchBossRawMaterial(materialId)).rejects.toThrow('nieprawidłowy katalog BOSS')
  })

  it.each([
    [403, 'BOSS_RAW_CATALOG_FORBIDDEN'],
    [503, 'BOSS_RAW_CATALOG_UNAVAILABLE'],
  ])('zachowuje odpowiedź odmowy/niedostępności %i', async (status, code) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
      error: { code, message: 'Odmowa lub niedostępność.' },
    }, status)))

    const cause = await fetchBossRawMaterials('', 1).catch((error: unknown) => error)
    expect(cause).toBeInstanceOf(ApiError)
    expect(cause).toMatchObject({ status, code })
  })
})
