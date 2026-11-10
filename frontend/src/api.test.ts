import { afterEach, describe, expect, it, vi } from 'vitest'
import { request, requestEnvelope } from './api'

afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/'
})

describe('klient API', () => {
  it('zachowuje sesję same-origin i przekazuje token CSRF przy mutacji', async () => {
    document.cookie = 'XSRF-TOKEN=csrf%3Atoken; Path=/'
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { ok: true } }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)

    await request('/api/v1/auth/logout', 'POST')

    expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/logout', expect.objectContaining({
      credentials: 'same-origin',
      cache: 'no-store',
      headers: expect.objectContaining({
        'X-XSRF-TOKEN': 'csrf:token',
        'Content-Type': 'application/json',
      }),
    }))
  })

  it('zachowuje metadane paginacji odpowiedzi katalogu', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: [{ id: 'material-1' }],
      meta: { current_page: 2, total: 26 },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } })))

    await expect(requestEnvelope('/api/v1/raw-materials')).resolves.toEqual({
      data: [{ id: 'material-1' }],
      meta: { current_page: 2, total: 26 },
    })
  })

  it('przekazuje kod błędu bramki NOT_READY jako błąd rozpoznawalny przez UI', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      error: { code: 'RAW_MATERIALS_MODULE_UNAVAILABLE', message: 'Moduł RAW_MATERIALS jest niedostępny.' },
    }), { status: 503, headers: { 'Content-Type': 'application/json' } })))

    await expect(request('/api/v1/raw-materials')).rejects.toMatchObject({
      status: 503,
      code: 'RAW_MATERIALS_MODULE_UNAVAILABLE',
      message: 'Moduł RAW_MATERIALS jest niedostępny.',
    })
  })
})
