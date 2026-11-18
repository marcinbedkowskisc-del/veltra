import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AllergenDictionaryManagementPage from './AllergenDictionaryManagementPage'
import {
  previewAllergenEntries,
  previewAllergenRelationshipCount,
  previewAllergenSources,
  previewCanonicalAllergens,
} from './allergenVisualPreviewData'
import { allergenDisplayName } from './allergenDisplayNames'

const expectedEntryCounts: Record<string, number> = {
  EU_1169_2011_ANNEX_II: 28,
  US_FDA_MAJOR_ALLERGENS: 24,
  CODEX_CXS_1_1985_2024: 25,
  JP_CAA_ALLERGENS_2026_04: 29,
  CN_GB7718_2011_CURRENT: 8,
  CN_GB7718_2025_FUTURE: 8,
  KR_MFDS_ALLERGENS: 19,
  TW_TFDA_ALLERGENS_2020: 17,
  TH_FDA_NOTIFICATION_474_2026: 20,
}

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify({ data }), { status, headers: { 'Content-Type': 'application/json' } })
}

function previewApi(includeArchived = false) {
  const requests: Array<{ method: string; path: string; body: Record<string, unknown> }> = []
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input), 'http://localhost')
    const method = init?.method ?? 'GET'
    const body = init?.body ? JSON.parse(String(init.body)) as Record<string, unknown> : {}
    requests.push({ method, path: url.pathname, body })

    if (method === 'GET' && url.pathname.endsWith('/dictionaries')) return json(previewAllergenSources)
    if (method === 'GET' && url.pathname.endsWith('/canonical')) return json(previewCanonicalAllergens)
    if (method === 'GET' && url.pathname.endsWith('/entries')) {
      const versionId = url.pathname.split('/').at(-2) ?? ''
      const entries = previewAllergenEntries[versionId] ?? []
      const responseEntries = includeArchived && versionId === previewAllergenSources[0].versions[0].id
        ? [...entries, { ...entries[0], id: '00000000-0000-4000-8000-000000000998', archived_at: '2026-11-17T00:00:00Z' }]
        : entries
      return json(responseEntries)
    }
    if (method === 'POST' && url.pathname.endsWith('/archive')) {
      const id = url.pathname.split('/').at(-2)
      const entry = Object.values(previewAllergenEntries).flat().find((item) => item.id === id)
      return json({ ...entry, archived_at: '2026-11-18T00:00:00Z' })
    }
    if (method === 'PATCH' && url.pathname.includes('/entries/')) {
      const id = url.pathname.split('/').at(-1)
      const entry = Object.values(previewAllergenEntries).flat().find((item) => item.id === id)
      return json({ ...entry, ...body })
    }
    if (method === 'POST' && url.pathname.endsWith('/entries')) {
      const versionId = url.pathname.split('/').at(-2) ?? ''
      const canonicalId = typeof body.canonical_allergen_id === 'string'
        ? body.canonical_allergen_id
        : 'ALG_PREVIEW_NEW'
      const canonical = previewCanonicalAllergens.find((item) => item.id === canonicalId)
      return json({
        ...Object.values(previewAllergenEntries).flat()[0],
        id: '00000000-0000-4000-8000-000000000599',
        dictionary_version_id: versionId,
        allergen_id: canonicalId,
        canonical_pl: canonical?.canonical_pl ?? (body.new_canonical as Record<string, unknown>)?.canonical_pl,
        canonical_status: 'ACTIVE',
        source_name: body.source_name ?? canonical?.canonical_pl,
        status: body.status,
        listing_rule: body.listing_rule,
        source_scope: body.source_scope,
        note: body.note,
      })
    }
    if (method === 'GET' && url.pathname.endsWith('/history')) return json([])
    throw new Error(`Unexpected preview request: ${method} ${url.pathname}`)
  })

  vi.stubGlobal('fetch', fetchMock)
  return requests
}

function rowCount(region: HTMLElement) {
  return region.querySelectorAll('.admin-allergen-row').length
}

function openFirstMenu(region: HTMLElement) {
  fireEvent.click(within(region).getAllByRole('button', { name: /^Opcje dla:/ })[0])
}

function completeLegalMetadata() {
  fireEvent.change(screen.getByLabelText('Status na liście'), { target: { value: 'MANDATORY' } })
  fireEvent.change(screen.getByLabelText('Jak alergen widnieje w źródle'), { target: { value: 'DIRECT' } })
  fireEvent.change(screen.getByLabelText('Gdzie obowiązuje'), { target: { value: 'LEGAL_LIST' } })
}

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('zarządzanie międzynarodowymi słownikami alergenów', () => {
  it('renders every seeded entry and the complete 178-relationship MASTER preview', async () => {
    previewApi()
    render(<AllergenDictionaryManagementPage />)

    const expectedVisibleCodes = [
      'EU_1169_2011_ANNEX_II',
      'US_FDA_MAJOR_ALLERGENS',
      'CODEX_CXS_1_1985_2024',
      'JP_CAA_ALLERGENS_2026_04',
      'CN_GB7718_2011_CURRENT',
      'KR_MFDS_ALLERGENS',
      'TW_TFDA_ALLERGENS_2020',
      'TH_FDA_NOTIFICATION_474_2026',
    ]

    await screen.findByRole('region', { name: 'Unia Europejska' })
    await waitFor(() => {
      const visibleColumns = screen.getAllByRole('region')
        .filter((element) => element.classList.contains('admin-allergen-column'))
      expect(visibleColumns).toHaveLength(8)
      expect(visibleColumns.reduce((count, item) => count + rowCount(item), 0)).toBe(170)
    })

    const columns = screen.getAllByRole('region').filter((element) => element.classList.contains('admin-allergen-column'))
    expect(columns.map(rowCount).sort((a, b) => a - b)).toEqual([8, 17, 19, 20, 24, 25, 28, 29])
    for (const code of expectedVisibleCodes) {
      const version = previewAllergenSources.flatMap((source) => source.versions).find((item) => item.code === code)
      expect(version).toBeDefined()
      expect(previewAllergenEntries[version!.id as keyof typeof previewAllergenEntries]).toHaveLength(expectedEntryCounts[code])
    }
    expect(previewAllergenRelationshipCount).toBe(178)
    expect(Object.values(previewAllergenEntries).flat()).toHaveLength(178)
    expect(screen.queryByText(/ALG_/)).toBeNull()
    expect(screen.queryByText(/DIRECT|LEGAL_LIST/)).toBeNull()
    expect(screen.queryByText(/kanoniczn|powiązan|identyfikator|JSON/i)).toBeNull()
    expect(screen.getAllByRole('button', { name: '+ Dodaj alergen' })).toHaveLength(8)
  })

  it('displays preferred Polish names for all 54 allergens without changing identities or relationships', () => {
    const canonicalSnapshot = previewCanonicalAllergens.map(({ id, canonical_pl, synonyms_pl }) => ({
      id,
      canonical_pl,
      synonyms_pl,
    }))
    const relationshipSnapshot = Object.values(previewAllergenEntries).flat()
      .map(({ id, dictionary_version_id, allergen_id }) => ({ id, dictionary_version_id, allergen_id }))
    const displayNames = previewCanonicalAllergens.map((item) => allergenDisplayName(item.id, item.canonical_pl))

    expect(previewCanonicalAllergens).toHaveLength(54)
    expect(displayNames).toHaveLength(54)
    expect(displayNames.every((name) => name === name.toLocaleUpperCase('pl-PL'))).toBe(true)
    expect(displayNames).toEqual(expect.arrayContaining([
      'PSZENICA KHORASAN (KAMUT)',
      'ORZECHY DRZEWNE',
      'ORZECHY WŁOSKIE',
      'MAŁŻE',
      'UCHO MORSKIE',
      'POCHRZYN',
    ]))
    expect(allergenDisplayName('ALG_SQUID', 'KAŁAMARNICA / KALMAR')).toBe('KAŁAMARNICA / KALMAR')
    expect(previewCanonicalAllergens.map(({ id, canonical_pl, synonyms_pl }) => ({
      id,
      canonical_pl,
      synonyms_pl,
    }))).toEqual(canonicalSnapshot)
    expect(Object.values(previewAllergenEntries).flat()
      .map(({ id, dictionary_version_id, allergen_id }) => ({ id, dictionary_version_id, allergen_id })))
      .toEqual(relationshipSnapshot)
    expect(previewAllergenRelationshipCount).toBe(178)
  })

  it('shows the preferred Polish name in a source list while retaining its canonical identity', async () => {
    previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await waitFor(() => expect(rowCount(eu)).toBe(28))
    const euVersion = previewAllergenSources[0].versions[0]
    const walnut = previewAllergenEntries[euVersion.id as keyof typeof previewAllergenEntries]
      .find((entry) => entry.allergen_id === 'ALG_WALNUT')!

    expect(within(eu).getByText('ORZECHY WŁOSKIE')).toBeTruthy()
    expect(within(eu).queryByText('ORZECHY WŁOSKIE / WALNUT')).toBeNull()
    expect(walnut).toMatchObject({
      allergen_id: 'ALG_WALNUT',
      dictionary_version_id: euVersion.id,
    })
  })

  it('shows each dictionary version status and dates without opening source details', async () => {
    previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    const version = previewAllergenSources.find((source) => source.name_pl === 'Unia Europejska')!.versions[0]

    expect(within(eu).getByText('Status: Bieżąca')).toBeTruthy()
    expect(within(eu).getByText(`Obowiązuje od: ${version.effective_from ?? 'nie określono'}`)).toBeTruthy()
    expect(within(eu).getByText(`Zweryfikowano: ${version.last_verified_on ?? 'nie określono'}`)).toBeTruthy()
  })

  it('switches China to its future dictionary and renders its full version', async () => {
    previewApi()
    render(<AllergenDictionaryManagementPage />)

    const china = await screen.findByRole('region', { name: 'Chiny' })
    await waitFor(() => expect(rowCount(china)).toBe(8))
    const selector = within(china).getByRole('combobox', { name: 'Wersja słownika — Chiny' })
    const future = previewAllergenSources.flatMap((source) => source.versions)
      .find((version) => version.code === 'CN_GB7718_2025_FUTURE')!
    fireEvent.change(selector, { target: { value: future.id } })

    await waitFor(() => expect(rowCount(china)).toBe(8))
    expect((within(china).getByRole('option', { name: /Przyszły wykaz/ }) as HTMLOptionElement).selected).toBe(true)
    expect(within(china).getAllByText('Przyszły wykaz · od 2027-03-16')).toHaveLength(2)
  })

  it('keeps archived relationships available through the source filter', async () => {
    previewApi(true)
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await waitFor(() => expect(rowCount(eu)).toBe(28))
    fireEvent.change(within(eu).getByRole('combobox', { name: 'Pokaż wpisy dla Unia Europejska' }), {
      target: { value: 'archived' },
    })

    await waitFor(() => expect(rowCount(eu)).toBe(1))
    expect(within(eu).getByText('Archiwalne alergeny')).toBeTruthy()
  })

  it('opens an accessible row menu and returns focus on Escape', async () => {
    previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await waitFor(() => expect(rowCount(eu)).toBe(28))
    const trigger = within(eu).getAllByRole('button', { name: /^Opcje dla:/ })[0]
    fireEvent.click(trigger)

    const edit = await screen.findByRole('menuitem', { name: 'Edytuj' })
    expect(screen.getByRole('menuitem', { name: 'Usuń ze słownika' })).toBeTruthy()
    expect(document.activeElement).toBe(edit)
    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(document.activeElement).toBe(trigger))
  })

  it('preserves hidden legal and technical fields when editing a source name', async () => {
    const requests = previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await waitFor(() => expect(rowCount(eu)).toBe(28))
    openFirstMenu(eu)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Edytuj' }))

    expect(screen.getByText('Ta nazwa jest wspólna dla wszystkich słowników. Zmiany wprowadzone poniżej dotyczą tylko wybranego słownika.')).toBeTruthy()
    fireEvent.change(screen.getByLabelText('Nazwa używana w tym słowniku'), { target: { value: 'Updated source name' } })
    fireEvent.change(screen.getByLabelText('Uwagi'), { target: { value: 'Updated note' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz' }))

    await waitFor(() => expect(requests.some((request) => request.method === 'PATCH')).toBe(true))
    const entryId = requests.find((request) => request.method === 'PATCH')!.path.split('/').at(-1)
    const original = previewAllergenEntries[previewAllergenSources[0].versions[0].id as keyof typeof previewAllergenEntries]
      .find((entry) => entry.id === entryId)!
    const submitted = requests.find((request) => request.method === 'PATCH')!.body
    expect(submitted).toMatchObject({
      source_name: 'Updated source name',
      status: original.status,
      listing_rule: original.listing_rule,
      legal_effective_from: original.legal_effective_from,
      transition_end: original.transition_end,
      source_scope: original.source_scope,
      threshold: original.threshold,
      exceptions: original.exceptions,
      metadata: original.metadata,
      note: 'Updated note',
    })
  })

  it('archives only the selected source relationship after confirmation', async () => {
    const requests = previewApi()
    const confirm = vi.fn(() => true)
    vi.stubGlobal('confirm', confirm)
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await waitFor(() => expect(rowCount(eu)).toBe(28))
    openFirstMenu(eu)
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Usuń ze słownika' }))

    await waitFor(() => expect(requests.some((request) => request.method === 'POST' && request.path.endsWith('/archive'))).toBe(true))
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Historia i wcześniejsze deklaracje alergenów surowca pozostaną bez zmian.'))
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Usunięta zostanie tylko ta pozycja w wybranym słowniku.'))
    expect(requests.some((request) => request.path.includes('/canonical/'))).toBe(false)
  })

  it('adds an existing canonical allergen without creating a duplicate canonical definition', async () => {
    const requests = previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await waitFor(() => expect(rowCount(eu)).toBe(28))
    fireEvent.click(within(eu).getByRole('button', { name: '+ Dodaj alergen' }))
    const existingIds = new Set(previewAllergenEntries[previewAllergenSources[0].versions[0].id as keyof typeof previewAllergenEntries]
      .map((entry) => entry.allergen_id))
    const unlinked = previewCanonicalAllergens.find((item) => !existingIds.has(item.id))!
    fireEvent.change(screen.getByLabelText('Szukaj alergenu'), { target: { value: unlinked.canonical_pl } })
    fireEvent.change(screen.getByLabelText('Nazwa alergenu'), { target: { value: unlinked.id } })
    fireEvent.change(screen.getByLabelText('Nazwa używana w tym słowniku'), { target: { value: 'Verified source name' } })
    completeLegalMetadata()
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz' }))

    await waitFor(() => expect(requests.some((request) => request.method === 'POST' && request.path.endsWith('/entries'))).toBe(true))
    const submitted = requests.find((request) => request.method === 'POST' && request.path.endsWith('/entries'))!.body
    expect(submitted.canonical_allergen_id).toBe(unlinked.id)
    expect(submitted).not.toHaveProperty('new_canonical')
  })

  it('creates a new canonical allergen without exposing an internal identifier or JSON', async () => {
    const requests = previewApi()
    render(<AllergenDictionaryManagementPage />)

    const eu = await screen.findByRole('region', { name: 'Unia Europejska' })
    await waitFor(() => expect(rowCount(eu)).toBe(28))
    fireEvent.click(within(eu).getByRole('button', { name: '+ Dodaj alergen' }))
    fireEvent.change(screen.getByLabelText('Sposób dodania'), { target: { value: 'new' } })
    await waitFor(() => expect((screen.getByLabelText('Sposób dodania') as HTMLSelectElement).value).toBe('new'))
    fireEvent.change(screen.getByLabelText('Nazwa alergenu'), { target: { value: 'Nowy alergen' } })
    expect(screen.queryByText('Identyfikator zostanie utworzony automatycznie.')).toBeNull()
    fireEvent.change(screen.getByLabelText('Nazwa używana w tym słowniku'), { target: { value: 'New allergen name' } })
    completeLegalMetadata()
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz' }))

    await waitFor(() => expect(requests.some((request) => request.method === 'POST' && request.path.endsWith('/entries'))).toBe(true))
    const submitted = requests.find((request) => request.method === 'POST' && request.path.endsWith('/entries'))!.body
    expect((submitted.new_canonical as Record<string, unknown>).id).toBeUndefined()
    expect(submitted).toMatchObject({
      new_canonical: { canonical_pl: 'Nowy alergen', parent_id: null },
      status: 'MANDATORY',
      listing_rule: 'DIRECT',
      source_scope: 'LEGAL_LIST',
    })
  })
})
