import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AdministrationUsersPage from './AdministrationUsersPage'

const userId = '11111111-1111-4111-8111-111111111111'
const restaurantId = '22222222-2222-4222-8222-222222222222'
const kitchenId = '33333333-3333-4333-8333-333333333333'
const inactiveLocationId = '44444444-4444-4444-8444-444444444444'
const secondRestaurantId = '55555555-5555-4555-8555-555555555555'

const user = {
  id: userId,
  first_name: 'Jan',
  last_name: 'Test',
  email: 'jan@example.test',
  email_verified_at: '2026-11-01T10:00:00Z',
  is_active: true,
  roles: [],
  locations: [],
}

const locations = [
  { id: restaurantId, name: 'Restauracja A', type: 'RESTAURANT', is_active: true },
  { id: kitchenId, name: 'Kuchnia A', type: 'CENTRAL_KITCHEN', is_active: true },
  { id: inactiveLocationId, name: 'Nieaktywna restauracja', type: 'RESTAURANT', is_active: false },
  { id: secondRestaurantId, name: 'Restauracja B', type: 'RESTAURANT', is_active: true },
]

const details = {
  ...user,
  roles: [],
  memberships: [
    {
      id: '55555555-5555-4555-8555-555555555555',
      location_id: restaurantId,
      location_name: 'Restauracja A',
      location_type: 'RESTAURANT',
      location_is_active: true,
      is_active: true,
      assigned_at: '2026-11-01T10:00:00Z',
      deactivated_at: null,
    },
    {
      id: '66666666-6666-4666-8666-666666666666',
      location_id: kitchenId,
      location_name: 'Kuchnia A',
      location_type: 'CENTRAL_KITCHEN',
      location_is_active: true,
      is_active: true,
      assigned_at: '2026-11-01T10:00:00Z',
      deactivated_at: null,
    },
    {
      id: '77777777-7777-4777-8777-777777777777',
      location_id: inactiveLocationId,
      location_name: 'Nieaktywna restauracja',
      location_type: 'RESTAURANT',
      location_is_active: false,
      is_active: true,
      assigned_at: '2026-11-01T10:00:00Z',
      deactivated_at: null,
    },
  ],
  permissions: [],
  effective_permissions: [],
  raw_permissions: { grants: [], history: [] },
  requests: [{
    id: '88888888-8888-4888-8888-888888888888',
    location_id: restaurantId,
    location_name: 'Restauracja A',
    requester_name: 'Jan Test',
    decision: null,
    decider_name: '',
    requested_at: '2026-11-01T10:00:00Z',
    decided_at: null,
  }],
  history: [],
}

function json(data: unknown) {
  return new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('AdministrationUsersPage', () => {
  it('renders the user avatar editor and submits an administrator-scoped selection', async () => {
    const calls: Array<{ url: string; init?: RequestInit }> = []
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      calls.push({ url, init })
      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) return Promise.resolve(json({ data: details }))
      if (url.endsWith(`/administration/users/${userId}/avatar`) && init?.method === 'PUT') return Promise.resolve(json({ data: null }))
      throw new Error(`Unexpected request: ${url}`)
    }))

    render(<AdministrationUsersPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    await screen.findByRole('heading', { name: 'Jan Test' })
    fireEvent.click(screen.getByRole('button', { name: 'Zmień awatar' }))
    expect(screen.getAllByRole('button', { name: /Wybierz awatar/ })).toHaveLength(64)
    fireEvent.click(screen.getByRole('button', { name: 'Wybierz awatar abstract-001' }))
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz wybrany awatar' }))

    await waitFor(() => expect(calls.some(({ url, init }) => url.endsWith(`/administration/users/${userId}/avatar`)
      && init?.method === 'PUT')).toBe(true))
    const mutation = calls.find(({ url, init }) => url.endsWith(`/administration/users/${userId}/avatar`) && init?.method === 'PUT')
    expect(JSON.parse(mutation?.init?.body as string)).toEqual({ type: 'LIBRARY', library_id: 'abstract-001' })
  })

  it('renders RAW grant provenance and uses only the existing RAW grant and revoke endpoints', async () => {
    const grantId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
    const grant = {
      id: grantId,
      permission: 'RAW_MATERIALS_VIEW',
      source: 'DELEGATED',
      granted_by_name: 'Ewa Delegująca',
      granted_at: '2026-11-01T10:00:00Z',
      valid_from: '2026-11-01T10:00:00Z',
      valid_until: null,
      revoked_at: null,
      revoked_by_name: null,
      is_effective: true,
      status: 'EFFECTIVE',
      lineage: [{
        recipient_name: 'Ewa Delegująca',
        granted_by_name: 'Adam Opiekun',
        granted_at: '2026-10-01T10:00:00Z',
        source: 'BOOTSTRAP',
        revoked_at: null,
        revoked_by_name: null,
      }],
    }
    let active = true
    let newGrantCreated = false
    const calls: Array<{ url: string; method: string; body: Record<string, unknown> | null }> = []
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL, init: RequestInit = {}) => {
      const url = String(input)
      const method = init.method ?? 'GET'
      const body = typeof init.body === 'string' ? JSON.parse(init.body) as Record<string, unknown> : null
      calls.push({ url, method, body })

      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) {
        const grants = [
          ...(active ? [grant] : []),
          ...(newGrantCreated ? [{
            ...grant,
            id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            permission: 'RAW_MATERIALS_CREATE',
            source: 'BOOTSTRAP',
            granted_by_name: 'Adam Opiekun',
            lineage: [],
          }] : []),
        ]
        return Promise.resolve(json({ data: { ...details, raw_permissions: {
          grants,
          history: active ? [{
            grant_id: grantId,
            permission: 'RAW_MATERIALS_VIEW',
            action: 'GRANTED',
            actor_name: 'Ewa Delegująca',
            recipient_name: 'Jan Test',
            occurred_at: '2026-11-01T10:00:00Z',
          }] : [],
        } } }))
      }
      if (url.endsWith(`/raw-materials/permissions/${grantId}`) && method === 'DELETE') {
        active = false
        return Promise.resolve(json({ data: null }))
      }
      if (url.endsWith(`/raw-materials/permissions/${userId}`) && method === 'POST') {
        newGrantCreated = true
        return Promise.resolve(json({ data: null }))
      }
      throw new Error(`Unexpected request: ${method} ${url}`)
    }))

    render(<AdministrationUsersPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    await screen.findByRole('heading', { name: 'Jan Test' })
    fireEvent.click(screen.getByRole('button', { name: 'Uprawnienia RAW' }))

    expect(await screen.findByText('Odczyt katalogu surowców')).toBeTruthy()
    expect(screen.getByText('Przypisane · efektywne')).toBeTruthy()
    expect(screen.getByText(/Adam Opiekun → Ewa Delegująca/)).toBeTruthy()
    expect(screen.getByText('Ewa Delegująca · odbiorca: Jan Test')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Cofnij grant' }))
    const revokeDialog = await screen.findByRole('alertdialog')
    fireEvent.change(within(revokeDialog).getByLabelText('Powód cofnięcia'), { target: { value: 'Zmiana zakresu obowiązków' } })
    fireEvent.click(within(revokeDialog).getByRole('button', { name: 'Potwierdź cofnięcie' }))
    expect(await screen.findByText('Grant RAW został cofnięty. Lista została odświeżona.')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Nadaj uprawnienie RAW' }))
    fireEvent.change(screen.getByLabelText('Uprawnienie RAW'), { target: { value: 'RAW_MATERIALS_CREATE' } })
    fireEvent.change(screen.getByLabelText('Powód nadania'), { target: { value: 'Zakres obowiązków' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dalej — potwierdź nadanie' }))
    const grantDialog = await screen.findByRole('alertdialog')
    fireEvent.click(within(grantDialog).getByRole('button', { name: 'Potwierdź nadanie' }))
    expect(await screen.findByText('Uprawnienie RAW zostało nadane. Lista została odświeżona.')).toBeTruthy()
    expect(await screen.findByText('Tworzenie surowców')).toBeTruthy()

    expect(calls).toContainEqual({
      url: `/api/v1/raw-materials/permissions/${grantId}`,
      method: 'DELETE',
      body: { reason: 'Zmiana zakresu obowiązków' },
    })
    expect(calls).toContainEqual({
      url: `/api/v1/raw-materials/permissions/${userId}`,
      method: 'POST',
      body: { permission: 'RAW_MATERIALS_CREATE', reason: 'Zakres obowiązków' },
    })
    expect(calls.some(({ url }) => url.includes('/raw-materials') && !url.includes('/permissions/'))).toBe(false)
  })

  it('explains that PA administrator status alone cannot grant RAW authority', async () => {
    const calls: string[] = []
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL, init: RequestInit = {}) => {
      const url = String(input)
      calls.push(`${init.method ?? 'GET'} ${url}`)
      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) return Promise.resolve(json({ data: details }))
      if (url.endsWith(`/raw-materials/permissions/${userId}`)) {
        return Promise.resolve(new Response(JSON.stringify({
          error: { code: 'FORBIDDEN', message: 'Unauthorized' },
        }), { status: 403, headers: { 'Content-Type': 'application/json' } }))
      }
      throw new Error(`Unexpected request: ${url}`)
    }))

    render(<AdministrationUsersPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    await screen.findByRole('heading', { name: 'Jan Test' })
    fireEvent.click(screen.getByRole('button', { name: 'Uprawnienia RAW' }))
    fireEvent.click(screen.getByRole('button', { name: 'Nadaj uprawnienie RAW' }))
    fireEvent.change(screen.getByLabelText('Powód nadania'), { target: { value: 'Zakres obowiązków' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dalej — potwierdź nadanie' }))
    const dialog = await screen.findByRole('alertdialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Potwierdź nadanie' }))

    expect(await within(dialog).findByText(/rola Administratora PA sama nie wystarcza/)).toBeTruthy()
    expect(calls).toContain(`POST /api/v1/raw-materials/permissions/${userId}`)
    expect(calls.some((call) => call.startsWith('GET ') && call.includes('/raw-materials'))).toBe(false)
  })

  it('clears the previous user RAW grants immediately when switching user details', async () => {
    const secondUserId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
    const secondUser = { ...user, id: secondUserId, first_name: 'Anna', last_name: 'Nowa' }
    let resolveSecondDetails: ((response: Response) => void) | undefined
    const firstGrant = {
      id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      permission: 'RAW_MATERIALS_VIEW',
      source: 'BOOTSTRAP',
      granted_by_name: 'Administrator',
      granted_at: '2026-11-01T10:00:00Z',
      valid_from: '2026-11-01T10:00:00Z',
      valid_until: null,
      revoked_at: null,
      revoked_by_name: null,
      is_effective: true,
      status: 'EFFECTIVE',
      lineage: [],
    }
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user, secondUser], meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) {
        return Promise.resolve(json({ data: { ...details, raw_permissions: { grants: [firstGrant], history: [] } } }))
      }
      if (url.endsWith(`/administration/users/${secondUserId}`)) {
        return new Promise<Response>((resolve) => { resolveSecondDetails = resolve })
      }
      throw new Error(`Unexpected request: ${url}`)
    }))

    render(<AdministrationUsersPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    await screen.findByRole('heading', { name: 'Jan Test' })
    fireEvent.click(screen.getByRole('button', { name: 'Uprawnienia RAW' }))
    expect(await screen.findByText('Odczyt katalogu surowców')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Anna Nowa' }))
    expect(await screen.findByText('Ładowanie danych użytkownika…')).toBeTruthy()
    expect(screen.queryByText('Odczyt katalogu surowców')).toBeNull()
    resolveSecondDetails?.(json({ data: {
      ...details,
      id: secondUserId,
      first_name: 'Anna',
      last_name: 'Nowa',
      raw_permissions: {
        grants: [{ ...firstGrant, id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', permission: 'RAW_MATERIALS_CREATE' }],
        history: [],
      },
    } }))

    expect(await screen.findByRole('heading', { name: 'Anna Nowa' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Uprawnienia RAW' }))
    expect(await screen.findByText('Tworzenie surowców')).toBeTruthy()
    expect(screen.queryByText('Odczyt katalogu surowców')).toBeNull()
  })

  it.each([
    { status: 419, code: 'SESSION_EXPIRED', expected: 'Sesja formularza wygasła.', expired: true },
    {
      status: 503,
      code: 'RAW_MATERIALS_MODULE_UNAVAILABLE',
      expected: 'Nadawanie i cofanie przez istniejącą ścieżkę RAW wymaga gotowości modułu RAW_MATERIALS.',
      expired: false,
    },
  ])('handles RAW mutation HTTP $status without replacing readiness or session behavior', async ({ status, code, expected, expired }) => {
    const onSessionExpired = vi.fn()
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) return Promise.resolve(json({ data: details }))
      if (url.endsWith(`/raw-materials/permissions/${userId}`)) {
        return Promise.resolve(new Response(JSON.stringify({
          error: { code, message: 'Unavailable' },
        }), { status, headers: { 'Content-Type': 'application/json' } }))
      }
      throw new Error(`Unexpected request: ${url}`)
    }))

    render(<AdministrationUsersPage onSessionExpired={onSessionExpired} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    await screen.findByRole('heading', { name: 'Jan Test' })
    fireEvent.click(screen.getByRole('button', { name: 'Uprawnienia RAW' }))
    fireEvent.click(screen.getByRole('button', { name: 'Nadaj uprawnienie RAW' }))
    fireEvent.change(screen.getByLabelText('Powód nadania'), { target: { value: 'Zakres obowiązków' } })
    fireEvent.click(screen.getByRole('button', { name: 'Dalej — potwierdź nadanie' }))
    const dialog = await screen.findByRole('alertdialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Potwierdź nadanie' }))

    expect(await within(dialog).findByText(new RegExp(expected))).toBeTruthy()
    expect(onSessionExpired).toHaveBeenCalledTimes(expired ? 1 : 0)
  })

  it('excludes inactive locations, scopes role choices, and accepts a blank pending-request decider', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) return Promise.resolve(json({ data: details }))
      throw new Error(`Unexpected request: ${url}`)
    }))

    render(<AdministrationUsersPage />)

    fireEvent.click(await screen.findByRole('button', { name: '+ Dodaj użytkownika' }))
    const createLocation = screen.getByLabelText('Przypisz do lokalizacji')
    expect(within(createLocation).getByRole('option', { name: /Restauracja A/ })).toBeTruthy()
    expect(within(createLocation).getByRole('option', { name: /Kuchnia A/ })).toBeTruthy()
    expect(within(createLocation).queryByRole('option', { name: /Nieaktywna restauracja/ })).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'Anuluj' }))
    fireEvent.click(screen.getByRole('button', { name: 'Jan Test' }))
    await screen.findByRole('heading', { name: 'Jan Test' })

    fireEvent.click(screen.getByRole('button', { name: 'Prośby o dostęp' }))
    expect(await screen.findByText('Prośba od: Jan Test')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Role i lokalizacje' }))
    fireEvent.click(screen.getByRole('button', { name: '+ Przypisz rolę' }))
    const roleSelect = screen.getAllByLabelText('Rola')[1]
    fireEvent.change(roleSelect, { target: { value: 'CENTRAL_KITCHEN_CHEF' } })

    const locationSelects = screen.getAllByLabelText('Lokalizacja')
    await waitFor(() => {
      expect(within(locationSelects[1]).getByRole('option', { name: /Kuchnia A/ })).toBeTruthy()
      expect(within(locationSelects[1]).queryByRole('option', { name: /Restauracja A/ })).toBeNull()
      expect(within(locationSelects[1]).queryByRole('option', { name: /Nieaktywna restauracja/ })).toBeNull()
    })
  })

  it('grants and revokes module delegations and displays the effective Stage 1D context', async () => {
    let granted = false
    const calls: Array<{ url: string; method: string; body: Record<string, unknown> | null }> = []
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL, init: RequestInit = {}) => {
      const url = String(input)
      const method = init.method ?? 'GET'
      const body = typeof init.body === 'string' ? JSON.parse(init.body) as Record<string, unknown> : null
      calls.push({ url, method, body })

      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) {
        return Promise.resolve(json({ data: {
          ...details,
          permissions: granted ? [{
            id: '99999999-9999-4999-8999-999999999999',
            permission: 'TEMPERATURE_REGISTER',
            location_id: restaurantId,
            location_name: 'Restauracja A',
            valid_from: '2026-11-01T10:00:00Z',
            valid_until: null,
            revoked_at: null,
          }] : [],
          effective_permissions: granted ? [{
            location_id: restaurantId,
            location_name: 'Restauracja A',
            permissions: [{
              permission: 'TEMPERATURE_REGISTER',
              operating_contexts: [{ id: restaurantId, name: 'Restauracja A' }],
            }],
          }] : [],
        } }))
      }
      if (url.endsWith(`/security/users/${userId}/locations/${restaurantId}/permissions`)) {
        granted = method === 'POST'
        return Promise.resolve(json({ data: null }))
      }
      throw new Error(`Unexpected request: ${method} ${url}`)
    }))

    render(<AdministrationUsersPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    await screen.findByRole('heading', { name: 'Jan Test' })
    fireEvent.click(screen.getByRole('button', { name: 'Uprawnienia' }))
    fireEvent.click(screen.getByRole('button', { name: '+ Dodaj dodatkowe uprawnienie' }))
    fireEvent.change(screen.getByLabelText('Uprawnienie'), { target: { value: 'TEMPERATURE_REGISTER' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz uprawnienie' }))

    expect(await screen.findByText('Dodatkowe uprawnienie zostało zapisane.')).toBeTruthy()
    fireEvent.click(await screen.findByRole('button', { name: 'Efektywne uprawnienia' }))
    expect(await screen.findByText('Rejestrowanie temperatur — kontekst: Restauracja A')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Uprawnienia' }))
    fireEvent.click(screen.getByRole('button', { name: 'Cofnij' }))
    expect(await screen.findByText('Dodatkowe uprawnienie zostało cofnięte.')).toBeTruthy()
    await waitFor(() => expect(granted).toBe(false))
    expect(await screen.findByText('Brak dodatkowych uprawnień i delegacji.')).toBeTruthy()
    expect(calls.filter(({ url }) => url.endsWith(`/security/users/${userId}/locations/${restaurantId}/permissions`))
      .map(({ method, body }) => [method, body?.permission]))
      .toEqual([['POST', 'TEMPERATURE_REGISTER'], ['DELETE', 'TEMPERATURE_REGISTER']])
  })

  it('traps keyboard focus in the protected-action dialog and restores it on Escape', async () => {
    const roleDetails = {
      ...details,
      roles: [{
        id: '99999999-9999-4999-8999-999999999999',
        role: 'EMPLOYEE',
        location_id: restaurantId,
        location_name: 'Restauracja A',
        granted_at: '2026-11-01T10:00:00Z',
        revoked_at: null,
      }],
    }
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) return Promise.resolve(json({ data: roleDetails }))
      throw new Error(`Unexpected request: ${url}`)
    }))

    render(<AdministrationUsersPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    await screen.findByRole('heading', { name: 'Jan Test' })
    fireEvent.click(screen.getByRole('button', { name: 'Role i lokalizacje' }))
    const revokeButton = screen.getByRole('button', { name: 'Cofnij rolę' })
    revokeButton.focus()
    fireEvent.click(revokeButton)

    const dialog = await screen.findByRole('alertdialog')
    const confirmButton = within(dialog).getByRole('button', { name: 'Potwierdź' })
    const cancelButton = within(dialog).getByRole('button', { name: 'Anuluj' })
    await waitFor(() => expect(document.activeElement).toBe(confirmButton))
    fireEvent.keyDown(dialog, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(cancelButton)
    fireEvent.keyDown(dialog, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull())
    expect(document.activeElement).toBe(revokeButton)
  })

  it('loads the requested user-directory page through pagination controls', async () => {
    const users = Array.from({ length: 26 }, (_, index) => ({
      ...user,
      id: `00000000-0000-4000-8000-${String(index + 1).padStart(12, '0')}`,
      first_name: 'Synthetic',
      last_name: `User ${index + 1}`,
      email: `synthetic${index + 1}@example.test`,
    }))
    const requestedPages: number[] = []
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        const page = Number(new URL(url, 'http://localhost').searchParams.get('page'))
        requestedPages.push(page)
        const start = (page - 1) * 25
        return Promise.resolve(json({
          data: users.slice(start, start + 25),
          meta: { current_page: page, per_page: 25, total: users.length, last_page: 2 },
        }))
      }
      throw new Error(`Unexpected request: ${url}`)
    }))

    render(<AdministrationUsersPage />)

    expect(await screen.findByRole('button', { name: 'Synthetic User 1' })).toBeTruthy()
    expect(screen.getByText('Strona 1 z 2')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Przejdź do strony 2' }))
    expect(await screen.findByRole('button', { name: 'Synthetic User 26' })).toBeTruthy()
    expect(await screen.findByText('Strona 2 z 2')).toBeTruthy()
    expect(requestedPages).toContain(2)
  })

  it('creates accounts and covers profile, email, link, and account-status actions', async () => {
    let firstName = user.first_name
    let lastName = user.last_name
    let email = user.email
    let emailVerifiedAt: string | null = user.email_verified_at
    let isActive = true
    const calls: Array<{ url: string; method: string; body: Record<string, unknown> | null }> = []
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL, init: RequestInit = {}) => {
      const url = String(input)
      const method = init.method ?? 'GET'
      const body = typeof init.body === 'string' ? JSON.parse(init.body) as Record<string, unknown> : null
      calls.push({ url, method, body })

      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({
          data: [{ ...user, first_name: firstName, last_name: lastName, email, email_verified_at: emailVerifiedAt, is_active: isActive }],
          meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
        }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) {
        return Promise.resolve(json({ data: {
          ...details, first_name: firstName, last_name: lastName, email, email_verified_at: emailVerifiedAt, is_active: isActive,
        } }))
      }
      if (url.endsWith(`/security/locations/${restaurantId}/personnel`) && method === 'POST') {
        return Promise.resolve(json({ data: null }))
      }
      if (url.endsWith(`/administration/users/${userId}/profile`) && method === 'PATCH') {
        firstName = String(body?.first_name)
        lastName = String(body?.last_name)
        return Promise.resolve(json({ data: null }))
      }
      if (url.endsWith(`/security/users/${userId}/email`) && method === 'PATCH') {
        email = String(body?.email)
        emailVerifiedAt = null
        return Promise.resolve(json({ data: null }))
      }
      if (url.endsWith(`/security/users/${userId}/links`) && method === 'POST') {
        return Promise.resolve(json({ data: null }))
      }
      if (url.endsWith(`/security/users/${userId}/status`) && method === 'PATCH') {
        isActive = body?.is_active === true
        return Promise.resolve(json({ data: null }))
      }
      throw new Error(`Unexpected request: ${method} ${url}`)
    }))

    render(<AdministrationUsersPage />)

    fireEvent.click(await screen.findByRole('button', { name: '+ Dodaj użytkownika' }))
    fireEvent.change(screen.getByLabelText('Imię'), { target: { value: 'Nowa' } })
    fireEvent.change(screen.getByLabelText('Nazwisko'), { target: { value: 'Osoba' } })
    fireEvent.change(screen.getByLabelText('Adres e-mail'), { target: { value: 'nowa@example.test' } })
    fireEvent.change(screen.getByLabelText('Przypisz do lokalizacji'), { target: { value: restaurantId } })
    fireEvent.click(screen.getByRole('button', { name: 'Utwórz konto i wyślij link' }))
    expect(await screen.findByText(/wysłaliśmy link aktywacyjny/)).toBeTruthy()
    expect(calls).toContainEqual({
      url: `/api/v1/security/locations/${restaurantId}/personnel`,
      method: 'POST',
      body: { first_name: 'Nowa', last_name: 'Osoba', email: 'nowa@example.test' },
    })

    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    const drawer = await screen.findByRole('region', { name: 'Jan Test' })
    fireEvent.click(within(drawer).getByRole('button', { name: 'Edytuj dane' }))
    fireEvent.change(within(drawer).getByLabelText('Imię'), { target: { value: 'Janek' } })
    fireEvent.change(within(drawer).getByLabelText('Nazwisko'), { target: { value: 'Zmieniony' } })
    fireEvent.click(within(drawer).getByRole('button', { name: 'Zapisz dane' }))
    expect(await screen.findByText('Dane użytkownika zostały zapisane.')).toBeTruthy()
    await screen.findByRole('heading', { name: 'Janek Zmieniony' })
    expect(calls).toContainEqual({
      url: `/api/v1/administration/users/${userId}/profile`,
      method: 'PATCH',
      body: { first_name: 'Janek', last_name: 'Zmieniony' },
    })

    const updatedDrawer = await screen.findByRole('region', { name: 'Janek Zmieniony' })
    fireEvent.click(within(updatedDrawer).getByRole('button', { name: 'Wyślij link do zmiany hasła' }))
    expect(await screen.findByText(/wysłaliśmy wiadomość/)).toBeTruthy()
    await screen.findByRole('heading', { name: 'Janek Zmieniony' })
    fireEvent.click(within(screen.getByRole('region', { name: 'Janek Zmieniony' })).getByRole('button', { name: 'Zmień e-mail' }))
    fireEvent.change(within(screen.getByRole('region', { name: 'Janek Zmieniony' })).getByLabelText('Nowy adres e-mail'), { target: { value: 'janek@example.test' } })
    fireEvent.click(within(screen.getByRole('region', { name: 'Janek Zmieniony' })).getByRole('button', { name: 'Zapisz i wyślij link' }))
    expect(await screen.findByText('Adres e-mail został zmieniony. Użytkownik musi ponownie go potwierdzić.')).toBeTruthy()
    await screen.findByRole('heading', { name: 'Janek Zmieniony' })
    expect(within(screen.getByRole('region', { name: 'Janek Zmieniony' }))
      .getByRole('button', { name: 'Wyślij link aktywacyjny' })).toBeTruthy()
    fireEvent.click(within(screen.getByRole('region', { name: 'Janek Zmieniony' }))
      .getByRole('button', { name: 'Wyślij link aktywacyjny' }))
    expect(await screen.findByText(/wysłaliśmy wiadomość/)).toBeTruthy()
    await screen.findByRole('heading', { name: 'Janek Zmieniony' })

    fireEvent.click(within(screen.getByRole('region', { name: 'Janek Zmieniony' })).getByRole('button', { name: 'Wyłącz konto' }))
    let dialog = await screen.findByRole('alertdialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Potwierdź' }))
    expect(await screen.findByText(/Konto zostało wyłączone/)).toBeTruthy()
    fireEvent.click(await screen.findByRole('button', { name: 'Włącz konto' }))
    dialog = await screen.findByRole('alertdialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Potwierdź' }))
    expect(await screen.findByText('Konto użytkownika jest aktywne.')).toBeTruthy()

    expect(calls.filter(({ url }) => url.endsWith(`/security/users/${userId}/links`))
      .map(({ body }) => body?.kind)).toEqual(['reset', 'activation'])
    expect(calls.filter(({ url }) => url.endsWith(`/security/users/${userId}/status`))
      .map(({ body }) => body?.is_active)).toEqual([false, true])
  })

  it('assigns and removes a role and a location through the protected controls', async () => {
    const assignmentId = '99999999-9999-4999-8999-999999999999'
    const membershipId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
    let roleGranted = false
    let roleRevoked = false
    let membershipAssigned = false
    let membershipRemoved = false
    const calls: Array<{ url: string; method: string; body: Record<string, unknown> | null }> = []
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL, init: RequestInit = {}) => {
      const url = String(input)
      const method = init.method ?? 'GET'
      const body = typeof init.body === 'string' ? JSON.parse(init.body) as Record<string, unknown> : null
      calls.push({ url, method, body })

      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) {
        const roleAssignments = roleGranted ? [{
          id: assignmentId, role: 'EMPLOYEE', location_id: restaurantId, location_name: 'Restauracja A',
          granted_at: '2026-11-01T10:00:00Z', revoked_at: roleRevoked ? '2026-11-02T10:00:00Z' : null,
        }] : []
        const additionalMemberships = membershipAssigned ? [{
          id: membershipId, location_id: secondRestaurantId, location_name: 'Restauracja B',
          location_type: 'RESTAURANT', location_is_active: true, is_active: !membershipRemoved,
          assigned_at: '2026-11-01T10:00:00Z', deactivated_at: membershipRemoved ? '2026-11-02T10:00:00Z' : null,
        }] : []
        return Promise.resolve(json({ data: { ...details, roles: roleAssignments, memberships: [...details.memberships, ...additionalMemberships] } }))
      }
      if (url.endsWith(`/security/users/${userId}/roles`) && method === 'POST') {
        roleGranted = true
        return Promise.resolve(json({ data: { id: assignmentId } }))
      }
      if (url.endsWith(`/security/roles/${assignmentId}`) && method === 'DELETE') {
        roleRevoked = true
        return Promise.resolve(json({ data: null }))
      }
      if (url.endsWith(`/security/users/${userId}/locations/${secondRestaurantId}/membership`) && method === 'POST') {
        membershipAssigned = true
        return Promise.resolve(json({ data: { id: membershipId } }))
      }
      if (url.endsWith(`/security/memberships/${membershipId}`) && method === 'DELETE') {
        membershipRemoved = true
        return Promise.resolve(json({ data: null }))
      }
      throw new Error(`Unexpected request: ${method} ${url}`)
    }))

    render(<AdministrationUsersPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    const drawer = await screen.findByRole('region', { name: 'Jan Test' })
    fireEvent.click(within(drawer).getByRole('button', { name: 'Role i lokalizacje' }))
    fireEvent.click(within(drawer).getByRole('button', { name: '+ Przypisz rolę' }))
    const roleForm = drawer.querySelector('.admin-user-inline-form')
    expect(roleForm).not.toBeNull()
    if (!(roleForm instanceof HTMLElement)) throw new Error('Role form did not open.')
    fireEvent.change(within(roleForm).getByLabelText('Rola'), { target: { value: 'EMPLOYEE' } })
    fireEvent.change(within(roleForm).getByLabelText('Lokalizacja'), { target: { value: restaurantId } })
    fireEvent.click(within(roleForm).getByRole('button', { name: 'Zapisz rolę' }))
    expect(await screen.findByText('Rola została przypisana.')).toBeTruthy()

    await screen.findByRole('button', { name: 'Role i lokalizacje' })
    const updatedDrawer = await screen.findByRole('region', { name: 'Jan Test' })
    fireEvent.click(within(updatedDrawer).getByRole('button', { name: 'Role i lokalizacje' }))
    fireEvent.click(within(updatedDrawer).getByRole('button', { name: 'Cofnij rolę' }))
    let dialog = await screen.findByRole('alertdialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Potwierdź' }))
    expect(await screen.findByText('Rola została cofnięta.')).toBeTruthy()

    await screen.findByRole('button', { name: 'Role i lokalizacje' })
    const afterRoleRemoval = await screen.findByRole('region', { name: 'Jan Test' })
    fireEvent.click(within(afterRoleRemoval).getByRole('button', { name: 'Role i lokalizacje' }))
    fireEvent.click(within(afterRoleRemoval).getByRole('button', { name: '+ Przypisz lokalizację' }))
    const membershipForm = afterRoleRemoval.querySelector('.admin-user-inline-form')
    expect(membershipForm).not.toBeNull()
    if (!(membershipForm instanceof HTMLElement)) throw new Error('Membership form did not open.')
    fireEvent.change(within(membershipForm).getByLabelText('Lokalizacja'), { target: { value: secondRestaurantId } })
    fireEvent.click(within(membershipForm).getByRole('button', { name: 'Zapisz przypisanie' }))
    expect(await screen.findByText('Lokalizacja została przypisana.')).toBeTruthy()

    await screen.findByRole('button', { name: 'Role i lokalizacje' })
    const afterMembership = await screen.findByRole('region', { name: 'Jan Test' })
    fireEvent.click(within(afterMembership).getByRole('button', { name: 'Role i lokalizacje' }))
    const membershipButtons = within(afterMembership).getAllByRole('button', { name: 'Cofnij przypisanie' })
    fireEvent.click(membershipButtons[membershipButtons.length - 1])
    dialog = await screen.findByRole('alertdialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Potwierdź' }))
    expect(await screen.findByText('Przypisanie do lokalizacji zostało cofnięte.')).toBeTruthy()

    expect(calls.filter(({ method }) => method === 'POST').map(({ url, body }) => [url, body])).toEqual([
      [`/api/v1/security/users/${userId}/roles`, { role: 'EMPLOYEE', location_id: restaurantId }],
      [`/api/v1/security/users/${userId}/locations/${secondRestaurantId}/membership`, {}],
    ])
    expect(calls.filter(({ method }) => method === 'DELETE').map(({ url }) => url)).toEqual([
      `/api/v1/security/roles/${assignmentId}`,
      `/api/v1/security/memberships/${membershipId}`,
    ])
  })

  it('approves and rejects pending access requests from user details', async () => {
    const secondRequestId = '99999999-9999-4999-8999-999999999998'
    const decisions = new Map<string, 'approved' | 'rejected' | null>([
      ['88888888-8888-4888-8888-888888888888', null],
      [secondRequestId, null],
    ])
    const requests = [
      ...details.requests,
      {
        id: secondRequestId,
        location_id: kitchenId,
        location_name: 'Kuchnia A',
        requester_name: 'Anna Test',
        decision: null,
        decider_name: null,
        requested_at: '2026-11-02T10:00:00Z',
        decided_at: null,
      },
    ]
    const calls: Array<{ url: string; body: Record<string, unknown> | null }> = []
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL, init: RequestInit = {}) => {
      const url = String(input)
      const method = init.method ?? 'GET'
      const body = typeof init.body === 'string' ? JSON.parse(init.body) as Record<string, unknown> : null
      if (url.endsWith('/administration/locations')) return Promise.resolve(json({ data: locations }))
      if (url.includes('/administration/users?')) {
        return Promise.resolve(json({ data: [user], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } }))
      }
      if (url.endsWith(`/administration/users/${userId}`)) {
        return Promise.resolve(json({ data: { ...details, requests: requests.map((request) => ({
          ...request, decision: decisions.get(request.id),
        })) } }))
      }
      if (url.includes('/security/membership-requests/') && method === 'POST') {
        calls.push({ url, body })
        const requestId = url.split('/').at(-2) ?? ''
        decisions.set(requestId, body?.approve === true ? 'approved' : 'rejected')
        return Promise.resolve(json({ data: null }))
      }
      throw new Error(`Unexpected request: ${method} ${url}`)
    }))

    render(<AdministrationUsersPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Jan Test' }))
    const drawer = await screen.findByRole('region', { name: 'Jan Test' })
    fireEvent.click(within(drawer).getByRole('button', { name: 'Prośby o dostęp' }))
    expect((await within(drawer).findAllByRole('button', { name: 'Zatwierdź' })).length).toBe(2)
    fireEvent.click(within(drawer).getAllByRole('button', { name: 'Zatwierdź' })[0])
    expect(await screen.findByText('Prośba o dostęp została zatwierdzona.')).toBeTruthy()

    const refreshedDrawer = await screen.findByRole('region', { name: 'Jan Test' })
    fireEvent.click(within(refreshedDrawer).getByRole('button', { name: 'Prośby o dostęp' }))
    fireEvent.click(await within(refreshedDrawer).findByRole('button', { name: 'Odrzuć' }))
    expect(await screen.findByText('Prośba o dostęp została odrzucona.')).toBeTruthy()
    expect(calls.map(({ body }) => body?.approve)).toEqual([true, false])
    expect([...decisions.values()].sort()).toEqual(['approved', 'rejected'])
  })
})
