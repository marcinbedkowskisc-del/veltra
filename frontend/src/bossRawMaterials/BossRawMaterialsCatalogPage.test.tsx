import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BossRawMaterialsCatalogPage from './BossRawMaterialsCatalogPage'
import type { BossRawMaterial } from './api'

const locationId = '00000000-0000-4000-8000-000000000101'
const firstMaterial: BossRawMaterial = {
  id: '00000000-0000-4000-8000-000000000201',
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

function pageResponse(
  data: BossRawMaterial[],
  currentPage = 1,
  total = data.length,
  lastPage = 1,
): Response {
  return jsonResponse({
    data,
    meta: { current_page: currentPage, per_page: 25, total, last_page: lastPage },
  })
}

function renderCatalog(onSessionExpired = vi.fn(), identityKey = 'user-1') {
  return render(
    <BossRawMaterialsCatalogPage
      key={`${identityKey}:${locationId}`}
      identityKey={identityKey}
      operatingLocationId={locationId}
      onSessionExpired={onSessionExpired}
    />,
  )
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('widok katalogu surowców BOSS', () => {
  it('renderuje listę, rozróżnia status snapshotu dostawców i pobiera detal BOSS', async () => {
    const items: BossRawMaterial[] = [
      firstMaterial,
      { ...firstMaterial, id: 'material-2', code: 'RAW-2', name: 'Cukier', supplier_names: [] },
      { ...firstMaterial, id: 'material-3', code: 'RAW-3', name: 'Sól', supplier_names: null },
    ]
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(pageResponse(items))
      .mockResolvedValueOnce(jsonResponse({ data: firstMaterial }))
    vi.stubGlobal('fetch', fetchMock)

    renderCatalog()

    expect(await screen.findByText('Mąka pszenna')).toBeTruthy()
    expect(screen.getByText('Dostawca A')).toBeTruthy()
    expect(screen.getByText('Brak dostawców w zatwierdzonej rewizji')).toBeTruthy()
    expect(screen.getByText('Brak zweryfikowanego snapshotu dostawców')).toBeTruthy()
    fireEvent.click(screen.getAllByRole('button', { name: 'Szczegóły' })[0])

    expect(await screen.findByRole('heading', { name: 'Szczegóły zatwierdzonego surowca' })).toBeTruthy()
    expect(screen.getAllByText('Dostawca A')).toHaveLength(2)
    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      '/api/v1/boss/raw-materials?page=1&per_page=25',
      `/api/v1/boss/raw-materials/${firstMaterial.id}`,
    ])
    expect(fetchMock.mock.calls.every(([url]) => !String(url).startsWith('/api/v1/raw-materials'))).toBe(true)
  })

  it('wysyła wyszukiwanie i zmianę strony jako parametry obsługiwane przez API', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(pageResponse([firstMaterial], 1, 26, 2))
      .mockResolvedValueOnce(pageResponse([{ ...firstMaterial, name: 'Mąka żytnia' }], 1, 26, 2))
      .mockResolvedValueOnce(pageResponse([{ ...firstMaterial, name: 'Mąka owsiana' }], 2, 26, 2))
    vi.stubGlobal('fetch', fetchMock)

    renderCatalog()
    await screen.findByText('Mąka pszenna')
    fireEvent.change(screen.getByRole('searchbox', { name: 'Szukaj po nazwie lub kodzie' }), {
      target: { value: 'mąka' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Szukaj' }))
    await screen.findByText('Mąka żytnia')
    fireEvent.click(screen.getByRole('button', { name: 'Następna' }))
    await screen.findByText('Mąka owsiana')

    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      '/api/v1/boss/raw-materials?page=1&per_page=25',
      '/api/v1/boss/raw-materials?page=1&per_page=25&search=m%C4%85ka',
      '/api/v1/boss/raw-materials?page=2&per_page=25&search=m%C4%85ka',
    ])
  })

  it('pokazuje jawny pusty stan bez tabeli', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(pageResponse([], 1, 0, 1)))

    renderCatalog()

    expect(await screen.findByText('Brak zatwierdzonych surowców spełniających kryteria.')).toBeTruthy()
    expect(screen.queryByRole('table')).toBeNull()
  })

  it.each([
    [403, 'BOSS_RAW_CATALOG_FORBIDDEN', 'Nie masz dostępu do katalogu BOSS.'],
    [503, 'BOSS_RAW_CATALOG_UNAVAILABLE', 'Katalog BOSS nie jest obecnie gotowy lub włączony dla organizacji.'],
  ])('czyści katalog przy HTTP %i i nie odpytuje zwykłego RAW', async (status, code, message) => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
      error: { code, message: 'Odpowiedź backendu.' },
    }, status))
    vi.stubGlobal('fetch', fetchMock)

    renderCatalog()

    expect(await screen.findByText(message)).toBeTruthy()
    expect(screen.queryByRole('table')).toBeNull()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(String(fetchMock.mock.calls[0][0])).toContain('/api/v1/boss/raw-materials')
    expect(String(fetchMock.mock.calls[0][0])).not.toContain('/api/v1/raw-materials?')
  })

  it('usuwa wcześniej wczytaną listę, gdy detal zwróci odmowę dostępu', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(pageResponse([firstMaterial]))
      .mockResolvedValueOnce(jsonResponse({
        error: { code: 'BOSS_RAW_CATALOG_FORBIDDEN', message: 'Odmowa.' },
      }, 403))
    vi.stubGlobal('fetch', fetchMock)

    renderCatalog()
    await screen.findByText('Mąka pszenna')
    fireEvent.click(screen.getByRole('button', { name: 'Szczegóły' }))

    expect(await screen.findByText('Nie masz dostępu do katalogu BOSS.')).toBeTruthy()
    expect(screen.queryByRole('table')).toBeNull()
    expect(screen.queryByText('Mąka pszenna')).toBeNull()
  })

  it('czyści poprzednią listę i anuluje żądanie po zmianie kontekstu', async () => {
    let searchSignal: AbortSignal | undefined
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(pageResponse([firstMaterial]))
      .mockImplementationOnce((_url: RequestInfo | URL, options?: RequestInit) => {
        searchSignal = options?.signal ?? undefined
        return new Promise<Response>(() => {})
      })
      .mockResolvedValueOnce(pageResponse([{ ...firstMaterial, name: 'Nowy katalog' }]))
    vi.stubGlobal('fetch', fetchMock)
    const view = renderCatalog()
    await screen.findByText('Mąka pszenna')

    fireEvent.change(screen.getByRole('searchbox', { name: 'Szukaj po nazwie lub kodzie' }), {
      target: { value: 'nowy' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Szukaj' }))
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))

    view.rerender(
      <BossRawMaterialsCatalogPage
        key="user-2:00000000-0000-4000-8000-000000000102"
        identityKey="user-2"
        operatingLocationId="00000000-0000-4000-8000-000000000102"
        onSessionExpired={vi.fn()}
      />,
    )

    expect(await screen.findByText('Nowy katalog')).toBeTruthy()
    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(screen.queryByText('Mąka pszenna')).toBeNull()
    expect(searchSignal?.aborted).toBe(true)
  })

  it.each([401, 419])('powiadamia powłokę o wygasłej sesji HTTP %i', async (status) => {
    const onSessionExpired = vi.fn()
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(pageResponse([firstMaterial]))
      .mockResolvedValueOnce(jsonResponse({ message: 'Sesja wygasła.' }, status))
    vi.stubGlobal('fetch', fetchMock)

    renderCatalog(onSessionExpired)
    await screen.findByText('Mąka pszenna')
    fireEvent.click(screen.getByRole('button', { name: 'Szczegóły' }))

    await waitFor(() => expect(onSessionExpired).toHaveBeenCalledTimes(1))
    expect(screen.queryByRole('table')).toBeNull()
  })
})
