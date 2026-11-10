import { afterEach, describe, expect, it, vi } from 'vitest'
import { createRawMaterial, reserveRawMaterialSku } from './api'

const reservationId = '00000000-0000-4000-8000-000000000003'
const materialId = '00000000-0000-4000-8000-000000000004'
const categoryId = '00000000-0000-4000-8000-000000000001'

function jsonResponse(body: unknown, status = 201): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', 'Content-Language': 'pl' },
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('kontrakt API tworzenia surowca', () => {
  it('rezerwuje SKU po stronie serwera z wymaganym Idempotency-Key', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: {
      reservation_id: reservationId,
      sku: 'RAW-000042',
      expires_at: '2026-11-11T12:00:00Z',
      status: 'RESERVED',
    } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(reserveRawMaterialSku('raw-create-idempotency-key-001')).resolves.toMatchObject({
      reservationId,
      sku: 'RAW-000042',
      status: 'RESERVED',
    })
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/raw-materials/sku-reservations', expect.objectContaining({
      method: 'POST',
      headers: expect.objectContaining({ 'Idempotency-Key': 'raw-create-idempotency-key-001' }),
      body: '{}',
      cache: 'no-store',
    }))
  })

  it('wysyła payload i identyfikator rezerwacji do POST /api/v1/raw-materials', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ data: {
      id: materialId,
      code: 'RAW-000042',
      sku: 'RAW-000042',
      lifecycle_state: 'DRAFT',
      version: 1,
      approved_revision_id: null,
      revision: { name: 'Mąka testowa' },
    } }))
    vi.stubGlobal('fetch', fetchMock)
    const payload = {
      category_id: categoryId,
      name: 'Mąka testowa',
      base_unit_code: 'g' as const,
      nominal_quantity: '10,5',
      usable_quantity: '9',
    }

    await expect(createRawMaterial(payload, reservationId)).resolves.toEqual({
      id: materialId,
      sku: 'RAW-000042',
      lifecycleState: 'DRAFT',
    })
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/raw-materials', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ ...payload, sku_reservation_id: reservationId }),
    }))
  })

  it('odrzuca niekompletne potwierdzenie i zachowuje szczegóły 422 z backendu', async () => {
    const malformedFetch = vi.fn().mockResolvedValue(jsonResponse({ data: { id: materialId } }))
    vi.stubGlobal('fetch', malformedFetch)
    await expect(createRawMaterial({ category_id: categoryId, name: 'X', base_unit_code: 'g' }, reservationId))
      .rejects.toThrow('Backend zwrócił nieprawidłowe potwierdzenie utworzenia surowca.')

    const validationFetch = vi.fn().mockResolvedValue(jsonResponse({
      message: 'Dane wymagają poprawy.',
      errors: { name: ['Nazwa jest już używana.'] },
    }, 422))
    vi.stubGlobal('fetch', validationFetch)
    await expect(createRawMaterial({ category_id: categoryId, name: 'X', base_unit_code: 'g' }, reservationId))
      .rejects.toMatchObject({ status: 422, errors: { name: ['Nazwa jest już używana.'] } })
  })
})
