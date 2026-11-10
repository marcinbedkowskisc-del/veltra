import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import { fetchRawMaterialCapabilities } from './api'

const locationId = '00000000-0000-4000-8000-000000000101'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('fetchRawMaterialCapabilities', () => {
  it('parsuje odpowiedź dla oczekiwanej lokalizacji i wysyła żądanie bez cache', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: {
        module: 'RAW_MATERIALS',
        location_id: locationId,
        readiness: 'READY',
        available: true,
        permissions: { create: true, edit: true },
        actions: { create: true, edit: true },
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(fetchRawMaterialCapabilities(locationId)).resolves.toEqual({
      module: 'RAW_MATERIALS',
      locationId,
      readiness: 'READY',
      available: true,
      permissions: { create: true, edit: true },
      actions: { create: true, edit: true },
    })
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/security/raw-materials/capabilities',
      expect.objectContaining({ method: 'GET', cache: 'no-store', credentials: 'same-origin' }),
    )
  })

  it('zachowuje rozdzielenie EDIT od CREATE i readiness', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: {
        module: 'RAW_MATERIALS',
        location_id: locationId,
        readiness: 'NOT_READY',
        available: false,
        permissions: { create: false, edit: true },
        actions: { create: false, edit: false },
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } })))

    await expect(fetchRawMaterialCapabilities(locationId)).resolves.toMatchObject({
      readiness: 'NOT_READY',
      available: false,
      permissions: { create: false, edit: true },
      actions: { create: false, edit: false },
    })
  })

  it.each([
    ['inna lokalizacja', { location_id: '00000000-0000-4000-8000-000000000102' }],
    ['akcja przy niedostępnym module', { readiness: 'NOT_READY', available: false, actions: { create: true } }],
    ['nieznany moduł', { module: 'OTHER_MODULE' }],
  ])('odrzuca niespójną odpowiedź: %s', async (_label, override) => {
    const value = {
      module: 'RAW_MATERIALS',
      location_id: locationId,
      readiness: 'READY',
      available: true,
      permissions: { create: true, edit: true },
      actions: { create: true, edit: true },
      ...override,
    }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: value }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })))

    await expect(fetchRawMaterialCapabilities(locationId)).rejects.toThrow(
      'Backend zwrócił nieprawidłowe uprawnienia katalogu surowców.',
    )
  })

  it.each([
    ['brak permissions.edit', { permissions: { create: true } }],
    ['brak actions.edit', { actions: { create: true } }],
    ['akcja EDIT bez grantu', { permissions: { create: true, edit: false } }],
    ['akcja EDIT przy NOT_READY', {
      readiness: 'NOT_READY',
      available: false,
      actions: { create: false, edit: true },
    }],
  ])('odrzuca niekompletną lub niedozwoloną capability EDIT: %s', async (_label, override) => {
    const value = {
      module: 'RAW_MATERIALS',
      location_id: locationId,
      readiness: 'READY',
      available: true,
      permissions: { create: true, edit: true },
      actions: { create: true, edit: true },
      ...override,
    }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: value }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })))

    await expect(fetchRawMaterialCapabilities(locationId)).rejects.toThrow(
      'Backend zwrócił nieprawidłowe uprawnienia katalogu surowców.',
    )
  })

  it('zachowuje semantykę błędu modułu niedostępnego', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      error: { code: 'RAW_MATERIALS_MODULE_UNAVAILABLE', message: 'Moduł RAW_MATERIALS jest niedostępny.' },
    }), { status: 503, headers: { 'Content-Type': 'application/json' } })))

    const error = await fetchRawMaterialCapabilities(locationId).catch((cause: unknown) => cause)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({
      status: 503,
      code: 'RAW_MATERIALS_MODULE_UNAVAILABLE',
    })
  })
})
