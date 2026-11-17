import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App'

const user = { id: 'user-1', first_name: 'Jan', last_name: 'Test', email: 'jan@example.test' }
const location = { id: 'location-1', name: 'Kuchnia', code: 'CK1', type: 'CENTRAL_KITCHEN' }

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } })
}

function mockApi(access: () => Response | Promise<Response>, authenticated = true) {
  return vi.fn().mockImplementation((input: RequestInfo | URL) => {
    const url = String(input)
    if (url.endsWith('/health')) return Promise.resolve(json({ app: 'VELTRA 2.0', status: 'ok', checks: { postgresql: 'up', redis: 'up' } }))
    if (url.endsWith('/auth/me')) return Promise.resolve(authenticated ? json({ data: user }) : json({ message: 'Unauthenticated' }, 401))
    if (url.endsWith('/auth/locations')) return Promise.resolve(json({ data: [location] }))
    if (url.endsWith('/auth/operating-location')) return Promise.resolve(json({ data: location }))
    if (url.endsWith('/administration/access')) return Promise.resolve(access())
    throw new Error(`Unexpected request: ${url}`)
  })
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.history.replaceState(null, '', '/')
})

describe('Panel Administracyjny', () => {
  it('pokazuje administratorowi wejście, nawigację i wybraną niedostępną funkcję', async () => {
    const fetchMock = mockApi(() => json({ data: { allowed: true } }))
    vi.stubGlobal('fetch', fetchMock)
    window.history.replaceState(null, '', '/administration/allergens')

    render(<App />)

    const nav = await screen.findByRole('navigation', { name: 'Nawigacja Panelu Administracyjnego' })
    expect(screen.getByRole('link', { name: 'Panel Administracyjny' }).getAttribute('href')).toBe('/administration')
    expect(within(nav).getByRole('link', { name: 'Alergeny' }).getAttribute('aria-current')).toBe('page')
    for (const name of ['Kategorie surowców', 'Jednostki miary i przeliczniki', 'Alergeny', 'Dostawcy', 'Użytkownicy', 'Role i uprawnienia']) {
      expect(within(nav).getByRole('link', { name })).toBeTruthy()
    }
    expect(screen.getByText('Ta funkcjonalność nie została jeszcze zaimplementowana.')).toBeTruthy()
    expect(fetchMock).not.toHaveBeenCalledWith(expect.stringContaining('/raw-materials'), expect.anything())
  })

  it('ukrywa wejście i odmawia dostępu przez bezpośredni adres', async () => {
    vi.stubGlobal('fetch', mockApi(() => json({ message: 'Forbidden' }, 403)))
    window.history.replaceState(null, '', '/administration/users')
    render(<App />)

    expect(await screen.findByRole('heading', { name: 'Brak dostępu' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Panel Administracyjny' })).toBeNull()
    expect(screen.queryByRole('navigation', { name: 'Nawigacja Panelu Administracyjnego' })).toBeNull()
  })

  it('wymaga zalogowania bez wykonywania kontroli PA dla gościa', async () => {
    const fetchMock = mockApi(() => json({ data: { allowed: true } }), false)
    vi.stubGlobal('fetch', fetchMock)
    window.history.replaceState(null, '', '/administration')
    render(<App />)

    expect(await screen.findByText('Zaloguj się, aby kontynuować.')).toBeTruthy()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/me', expect.anything()))
    expect(fetchMock).not.toHaveBeenCalledWith('/api/v1/administration/access', expect.anything())
  })

  it('zamyka dostęp po błędzie sesji i nie traktuje błędu sieci jako odmowy', async () => {
    vi.stubGlobal('fetch', mockApi(() => json({ message: 'Expired' }, 401)))
    window.history.replaceState(null, '', '/administration')
    const rendered = render(<App />)
    expect(await screen.findByText('Sesja wygasła. Zaloguj się ponownie.')).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Panel Administracyjny' })).toBeNull()

    rendered.unmount()
    vi.stubGlobal('fetch', mockApi(() => json({ message: 'Failure' }, 500)))
    render(<App />)
    expect(await screen.findByRole('heading', { name: 'Nie można sprawdzić dostępu' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Spróbuj ponownie' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Panel Administracyjny' })).toBeNull()
  })

  it('nie ufa nieprawidłowej odpowiedzi i pozwala ponowić kontrolę', async () => {
    const access = vi.fn().mockReturnValueOnce(json({ data: {} })).mockReturnValueOnce(json({ data: { allowed: true } }))
    vi.stubGlobal('fetch', mockApi(access))
    window.history.replaceState(null, '', '/administration')
    render(<App />)

    expect(await screen.findByRole('heading', { name: 'Nie można sprawdzić dostępu' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Spróbuj ponownie' }))
    expect(await screen.findByRole('navigation', { name: 'Nawigacja Panelu Administracyjnego' })).toBeTruthy()
  })

  it('ponownie sprawdza dostęp po odświeżeniu przypisań i ukrywa panel po odmowie', async () => {
    const access = vi.fn().mockReturnValueOnce(json({ data: { allowed: true } })).mockReturnValueOnce(json({ message: 'Forbidden' }, 403))
    vi.stubGlobal('fetch', mockApi(access))
    window.history.replaceState(null, '', '/administration')
    render(<App />)

    expect(await screen.findByRole('navigation', { name: 'Nawigacja Panelu Administracyjnego' })).toBeTruthy()
    fireEvent.click(screen.getByLabelText('Konto: Jan Test'))
    fireEvent.click(screen.getByRole('button', { name: 'Odśwież przypisania' }))
    expect(await screen.findByRole('heading', { name: 'Brak dostępu' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Panel Administracyjny' })).toBeNull()
  })

  it('ignoruje spóźnioną odpowiedź po zmianie lokalizacji', async () => {
    let resolveOld: ((response: Response) => void) | undefined
    const secondLocation = { ...location, id: 'location-2', name: 'Druga', code: 'CK2' }
    let checks = 0
    const fetchMock = mockApi(() => {
      checks += 1
      return checks === 1
        ? new Promise<Response>((resolve) => { resolveOld = resolve })
        : json({ message: 'Forbidden' }, 403)
    })
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url.endsWith('/auth/locations')) return Promise.resolve(json({ data: [location, secondLocation] }))
      if (url.endsWith('/auth/operating-location') && init?.method === 'PUT') return Promise.resolve(json({ data: secondLocation }))
      return fetchMock(input, init)
    }))
    window.history.replaceState(null, '', '/administration')
    render(<App />)

    await waitFor(() => expect(resolveOld).toBeDefined())
    fireEvent.click(screen.getByLabelText('Konto: Jan Test'))
    fireEvent.change(screen.getByLabelText('Lokalizacja operacyjna'), { target: { value: secondLocation.id } })
    fireEvent.click(screen.getByRole('button', { name: 'Wybierz lokalizację' }))
    expect(await screen.findByRole('heading', { name: 'Brak dostępu' })).toBeTruthy()
    resolveOld?.(json({ data: { allowed: true } }))
    await waitFor(() => expect(screen.queryByRole('link', { name: 'Panel Administracyjny' })).toBeNull())
    expect(screen.queryByRole('navigation', { name: 'Nawigacja Panelu Administracyjnego' })).toBeNull()
  })
})
