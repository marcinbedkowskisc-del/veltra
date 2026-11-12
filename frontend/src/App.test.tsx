import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('integracja z powłoką VELTRA', () => {
  it('pokazuje dostępne moduły i udostępnia logowanie z menu konta', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/health')) {
        return Promise.resolve(new Response(JSON.stringify({
          app: 'VELTRA 2.0',
          status: 'ok',
          checks: { postgresql: 'up', redis: 'up' },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
      }
      return Promise.resolve(new Response(JSON.stringify({ message: 'Unauthenticated.' }), {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
      }))
    }))
    window.history.replaceState(null, '', '/')

    render(<App />)

    expect((await screen.findByRole('link', { name: 'RAW' })).getAttribute('href')).toBe('/raw-materials')
    expect(screen.getByRole('link', { name: 'Pulpit' }).getAttribute('href')).toBe('/')
    expect(screen.getByText('Organizacja').closest('[aria-disabled="true"]')).toBeTruthy()
    expect(screen.getByText('Zaloguj się, aby wybrać lokalizację')).toBeTruthy()
    fireEvent.click(screen.getByText('Konto i lokalizacja', { selector: 'strong' }))
    expect(await screen.findByRole('heading', { name: 'Konto i lokalizacja' })).toBeTruthy()
    expect(screen.getByLabelText('Adres e-mail')).toBeTruthy()
  })
})
