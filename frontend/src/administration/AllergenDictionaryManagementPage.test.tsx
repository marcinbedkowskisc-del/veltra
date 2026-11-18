import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AllergenDictionaryManagementPage from './AllergenDictionaryManagementPage'
import {
  previewAllergenEntries,
  previewAllergenSources,
  previewCanonicalAllergens,
} from './allergenVisualPreviewData'

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } })
}

function previewApi() {
  const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
    const url = new URL(String(input), 'http://localhost')

    if (url.pathname.endsWith('/dictionaries')) return json({ data: previewAllergenSources })
    if (url.pathname.endsWith('/canonical')) return json({ data: previewCanonicalAllergens })
    if (url.pathname.endsWith('/entries')) {
      const versionId = url.pathname.split('/').at(-2) ?? ''
      return json({ data: previewAllergenEntries[versionId as keyof typeof previewAllergenEntries] ?? [] })
    }
    if (url.pathname.endsWith('/history')) return json({ data: [] })
    throw new Error(`Unexpected preview request: GET ${url.pathname}`)
  })

  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('zarządzanie międzynarodowymi słownikami alergenów', () => {
  it('pokazuje źródła w osobnych kolumnach i filtruje tylko wybrany słownik', async () => {
    previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await within(eu).findByText('Cereals containing gluten')
    expect(screen.getAllByRole('region').filter((item) => item.classList.contains('admin-allergen-column'))).toHaveLength(8)
    expect(screen.getAllByRole('button', { name: '+ Dodaj alergen' })).toHaveLength(8)
    expect(within(eu).getByText('EU_1169_2011_ANNEX_II')).toBeTruthy()
    expect(within(eu).getByText('Obowiązuje od: 2014-12-13')).toBeTruthy()
    expect(within(eu).getAllByText('Alergen kanoniczny').length).toBeGreaterThan(0)
    expect(within(eu).getAllByText('Wpis źródłowy').length).toBeGreaterThan(0)

    fireEvent.change(within(eu).getByLabelText('Szukaj w tym słowniku'), { target: { value: 'nieistniejący' } })
    expect(within(eu).getByText('Brak wyników dla filtra.')).toBeTruthy()
    const us = await screen.findByRole('region', { name: 'Stany Zjednoczone' })
    expect(within(us).getAllByText('Milk').length).toBeGreaterThan(0)
  })

  it('otwiera formularz dodania z istniejącą pulą kanoniczną', async () => {
    previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await within(eu).findByText('Cereals containing gluten')
    fireEvent.click(within(eu).getByRole('button', { name: '+ Dodaj alergen' }))

    expect(await screen.findByRole('heading', { name: 'Nowy wpis słownika' })).toBeTruthy()
    expect(screen.getByLabelText('Alergen kanoniczny').textContent).toContain('SEZAM · ALG_SESAME')
  })

  it('otwiera edycję wpisu źródłowego bez zmiany definicji kanonicznej', async () => {
    previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await within(eu).findByText('Cereals containing gluten')
    fireEvent.click(within(eu).getAllByRole('button', { name: 'Edytuj wpis' })[0])

    expect(await screen.findByRole('heading', { name: 'Edytuj wpis: ZBOŻA ZAWIERAJĄCE GLUTEN' })).toBeTruthy()
    expect((screen.getByLabelText('Nazwa w źródle') as HTMLInputElement).value).toBe('Cereals containing gluten')
  })
})
