import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import RawMaterialEditForm from './RawMaterialEditForm'
import {
  fetchRawMaterialCapabilities,
  fetchRawMaterialCompletion,
  fetchRawMaterialEditor,
  fetchAllergenDeclaration,
  fetchAllergenCatalogs,
  reviseRawMaterial,
  type RawMaterialCapabilities,
  type RawMaterialCompletion,
  type RawMaterialEditor,
  type RevisedRawMaterial,
} from './api'

vi.mock('./api', async (importOriginal) => ({
  ...await importOriginal<typeof import('./api')>(),
  fetchRawMaterialCapabilities: vi.fn(),
  fetchRawMaterialCompletion: vi.fn(),
  fetchRawMaterialEditor: vi.fn(),
  fetchAllergenDeclaration: vi.fn(),
  fetchAllergenCatalogs: vi.fn(),
  reviseRawMaterial: vi.fn(),
}))

const materialId = '00000000-0000-4000-8000-000000000401'
const revisionId = '00000000-0000-4000-8000-000000000402'
const categoryId = '00000000-0000-4000-8000-000000000403'
const locationId = '00000000-0000-4000-8000-000000000404'
const version = 'opaque-edit-version'
const categories = [{ id: categoryId, name: 'Mąki' }]
const makeCapabilities = (edit = true, location = locationId): RawMaterialCapabilities => ({
  module: 'RAW_MATERIALS',
  locationId: location,
  readiness: 'READY',
  available: true,
  permissions: { create: false, edit },
  actions: { create: false, edit },
})
const makeCompletion = (edit = true, currentVersion = version): RawMaterialCompletion => ({
  lifecycleState: 'ACTIVE',
  revisionId,
  version: currentVersion,
  availableActions: edit ? ['edit'] : [],
})
const makeEditor = (extra: Partial<RawMaterialEditor> = {}): RawMaterialEditor => ({
  id: materialId,
  sku: 'RAW-000401',
  lifecycleState: 'ACTIVE',
  revisionId,
  revisionNo: 4,
  version,
  values: {
    categoryId,
    name: 'Mąka pszenna',
    description: 'Opis zachowany',
    baseUnitCode: 'kg',
    nominalQuantity: '10.000000',
    usableQuantity: '8.000000',
    aliases: ['alias zachowany'],
    conversions: [{ unitCode: 'pc', toBaseFactor: '2.500000', basis: '1 sztuka' }],
  },
  ...extra,
})
const makeSaved = (): RevisedRawMaterial => ({
  id: materialId,
  sku: 'RAW-000401',
  lifecycleState: 'ACTIVE',
  version: 'next-version',
  revision: { id: '00000000-0000-4000-8000-000000000405', revisionNo: 5 },
})

const capabilitiesMock = vi.mocked(fetchRawMaterialCapabilities)
const completionMock = vi.mocked(fetchRawMaterialCompletion)
const editorMock = vi.mocked(fetchRawMaterialEditor)
const reviseMock = vi.mocked(reviseRawMaterial)

function renderForm(overrides: Partial<React.ComponentProps<typeof RawMaterialEditForm>> = {}) {
  return render(
    <RawMaterialEditForm
      materialId={materialId}
      operatingLocationId={locationId}
      categories={categories}
      returnFocus={null}
      onClose={vi.fn()}
      onSaved={vi.fn()}
      onSessionExpired={vi.fn()}
      onAuthorizationDenied={vi.fn()}
      onModuleUnavailable={vi.fn()}
      {...overrides}
    />,
  )
}

beforeEach(() => {
  capabilitiesMock.mockResolvedValue(makeCapabilities())
  completionMock.mockResolvedValue(makeCompletion())
  editorMock.mockResolvedValue(makeEditor())
  reviseMock.mockResolvedValue(makeSaved())
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('formularz edycji surowca', () => {
  it('zachowuje dane zakładek i zapisuje zmienione alergeny razem z rewizją', async () => {
    const catalogId = '00000000-0000-4000-8000-000000000406'
    completionMock.mockResolvedValue({ ...makeCompletion(), availableActions: ['edit', 'edit_allergens'] })
    vi.mocked(fetchAllergenDeclaration).mockResolvedValue({
      version, declaration: { status: 'UNKNOWN', catalog_version_id: null, items: [] },
    })
    vi.mocked(fetchAllergenCatalogs).mockResolvedValue([{ id: catalogId, versionNumber: 1, definitions: [] }])
    renderForm()
    await screen.findByRole('heading', { name: 'Edytuj surowiec' })
    fireEvent.change(screen.getByLabelText(/^Nazwa/), { target: { value: 'Nowa nazwa' } })
    fireEvent.click(screen.getByRole('tab', { name: 'Alergeny' }))
    await waitFor(() => expect(screen.getByLabelText<HTMLSelectElement>('Status deklaracji').disabled).toBe(false))
    fireEvent.change(screen.getByLabelText('Status deklaracji'), { target: { value: 'NO_KNOWN_ALLERGENS' } })
    fireEvent.change(screen.getByLabelText('Wersja katalogu alergenów'), { target: { value: catalogId } })
    fireEvent.click(screen.getByRole('tab', { name: 'Dodatkowe informacje' }))
    expect(screen.getByLabelText<HTMLTextAreaElement>(/Opis.*Pozostawienie/).value).toBe('Opis zachowany')
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))
    await screen.findByText('Zapisano nową rewizję roboczą v5')
    expect(reviseMock).toHaveBeenCalledWith(materialId, expect.objectContaining({
      name: 'Nowa nazwa', description: 'Opis zachowany',
      allergen_declaration: { status: 'NO_KNOWN_ALLERGENS', catalog_version_id: catalogId, items: [] },
    }), version)
  })

  it('pokazuje ilości bez zbędnych zer i zapisuje pełną precyzję', async () => {
    const editor = makeEditor()
    editor.values.nominalQuantity = '125.500000'
    editor.values.usableQuantity = '0.750000'
    editorMock.mockResolvedValue(editor)
    renderForm()

    await screen.findByDisplayValue('125,5')
    expect(screen.getByLabelText<HTMLInputElement>('Użytkowa').value).toBe('0,75')
    fireEvent.change(screen.getByLabelText('Użytkowa'), { target: { value: '0,123456' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    await screen.findByText('Zapisano nową rewizję roboczą v5')
    expect(reviseMock).toHaveBeenCalledWith(materialId, expect.objectContaining({
      nominal_quantity: '125.5',
      usable_quantity: '0.123456',
    }), version)
  })

  it('wymaga obu uprawnień przed pobraniem edytora i zachowuje pełny payload przy jawnym czyszczeniu pól', async () => {
    const onSaved = vi.fn()
    renderForm({ onSaved })

    await screen.findByRole('heading', { name: 'Edytuj surowiec' })
    expect(screen.getByLabelText<HTMLInputElement>(/^Nazwa/).value).toBe('Mąka pszenna')
    expect(screen.getByText('RAW-000401')).toBeTruthy()
    expect(screen.queryByLabelText('SKU')).toBeNull()
    expect(screen.queryByLabelText('Identyfikator surowca')).toBeNull()
    expect(screen.queryByText(materialId)).toBeNull()
    expect(screen.queryByText(revisionId)).toBeNull()
    expect(screen.getByLabelText<HTMLInputElement>('Nominalna').value).toBe('10')
    expect(screen.getByLabelText<HTMLInputElement>('Użytkowa').value).toBe('8')
    expect(editorMock).toHaveBeenCalledWith(materialId, expect.any(AbortSignal))
    fireEvent.click(screen.getByRole('tab', { name: 'Dodatkowe informacje' }))
    expect(screen.getByText(/nazwy, pod którymi pracownicy mogą szukać/)).toBeTruthy()
    expect(screen.getByText(/jeśli 1 sztuka waży 2\.500000 kg/)).toBeTruthy()
    expect(screen.getByLabelText('Przelicznik').getAttribute('aria-describedby'))
      .toBe('edit-conversion-0-factor-hint')
    expect(screen.getByLabelText('Opis przeliczenia').getAttribute('aria-describedby'))
      .toBe('edit-conversion-0-basis-hint')

    fireEvent.change(screen.getByLabelText(/Opis.*Pozostawienie pustego pola jawnie wyczyści opis/), {
      target: { value: '' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Usuń nazwę 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Usuń jednostkę przeliczeniową 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    await screen.findByText('Zapisano nową rewizję roboczą v5')
    expect(reviseMock).toHaveBeenCalledWith(materialId, {
      category_id: categoryId,
      name: 'Mąka pszenna',
      description: null,
      base_unit_code: 'kg',
      nominal_quantity: '10',
      usable_quantity: '8',
      aliases: [],
      conversions: [],
    }, version)
    expect(onSaved).toHaveBeenCalledWith(makeSaved())
    expect(screen.getByText(/Dotychczasowa rewizja zatwierdzona pozostaje obowiązująca/)).toBeTruthy()
    expect(screen.getByText(/nie została automatycznie zatwierdzona ani opublikowana/)).toBeTruthy()
  })

  it('zachowuje aliasy i przelicznik cross-dimensional przy edycji innego pola', async () => {
    renderForm()
    await screen.findByRole('heading', { name: 'Edytuj surowiec' })
    fireEvent.change(screen.getByLabelText(/^Nazwa/), { target: { value: 'Mąka zmieniona' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    await screen.findByText('Zapisano nową rewizję roboczą v5')
    expect(reviseMock).toHaveBeenCalledWith(materialId, expect.objectContaining({
      name: 'Mąka zmieniona',
      aliases: ['alias zachowany'],
      conversions: [{ unit_code: 'pc', to_base_factor: '2.500000', basis: '1 sztuka' }],
    }), version)
  })

  it('blokuje pobranie danych edytora przy braku globalnego EDIT', async () => {
    const onAuthorizationDenied = vi.fn()
    capabilitiesMock.mockResolvedValue(makeCapabilities(false))
    renderForm({ onAuthorizationDenied })

    await screen.findByRole('alert')
    expect(screen.getByText('Brak aktualnego uprawnienia do edycji tego surowca.')).toBeTruthy()
    expect(editorMock).not.toHaveBeenCalled()
    expect(reviseMock).not.toHaveBeenCalled()
    expect(onAuthorizationDenied).toHaveBeenCalledOnce()
  })

  it('blokuje edycję, jeśli completion nie udostępnia akcji edit', async () => {
    completionMock.mockResolvedValue(makeCompletion(false))
    renderForm()

    await screen.findByRole('alert')
    expect(editorMock).not.toHaveBeenCalled()
    expect(reviseMock).not.toHaveBeenCalled()
  })

  it.each([
    ['NOT_READY', { ...makeCapabilities(false), readiness: 'NOT_READY' as const, available: false }],
    ['inna lokalizacja', makeCapabilities(true, '00000000-0000-4000-8000-000000000499')],
  ])('fail-closed dla capability %s', async (_label, capabilities) => {
    capabilitiesMock.mockResolvedValue(capabilities)
    renderForm()
    await screen.findByRole('alert')
    expect(editorMock).not.toHaveBeenCalled()
    expect(reviseMock).not.toHaveBeenCalled()
  })

  it('nie wysyła PATCH po zmianie wersji podczas świeżej kontroli i zachowuje lokalną wartość', async () => {
    completionMock
      .mockResolvedValueOnce(makeCompletion())
      .mockResolvedValueOnce(makeCompletion(true, 'another-version'))
    renderForm()
    const name = await screen.findByLabelText(/^Nazwa/)
    fireEvent.change(name, { target: { value: 'Lokalna nazwa' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    expect(await screen.findByText('Konflikt wersji')).toBeTruthy()
    expect(screen.getByLabelText<HTMLInputElement>(/^Nazwa/).value).toBe('Lokalna nazwa')
    expect(reviseMock).not.toHaveBeenCalled()
  })

  it('waliduje precyzję i całkowitą ilość dla jednostki pc przed żądaniem', async () => {
    renderForm()
    await screen.findByLabelText(/^Nazwa/)
    fireEvent.change(screen.getByLabelText(/^Jednostka podstawowa/), { target: { value: 'pc' } })
    fireEvent.change(screen.getByLabelText('Nominalna'), { target: { value: '1.5' } })
    fireEvent.change(screen.getByLabelText('Użytkowa'), { target: { value: '1.5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    expect(await screen.findByText('Ilość w sztukach musi być liczbą całkowitą.')).toBeTruthy()
    expect(reviseMock).not.toHaveBeenCalled()
  })

  it('mapuje HTTP 422 na pole i zachowuje wpisane dane', async () => {
    reviseMock.mockRejectedValue(new ApiError(422, 'Sprawdź dane.', null, { name: ['Nazwa jest już używana.'] }))
    renderForm()
    const name = await screen.findByLabelText(/^Nazwa/)
    fireEvent.change(name, { target: { value: 'Nowa nazwa' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    expect(await screen.findByText('Nazwa jest już używana.')).toBeTruthy()
    expect(screen.getByLabelText<HTMLInputElement>(/^Nazwa/).value).toBe('Nowa nazwa')
    expect(screen.queryByText(/niepewny wynik zapisu/i)).toBeNull()
  })

  it('nie zamyka formularza po odrzuceniu dirty guardu', async () => {
    const onClose = vi.fn()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderForm({ onClose })
    fireEvent.change(await screen.findByLabelText(/^Nazwa/), { target: { value: 'Zmiana lokalna' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anuluj' }))

    expect(confirm).toHaveBeenCalledWith('Odrzucić niezapisane zmiany?')
    expect(onClose).not.toHaveBeenCalled()
    expect(screen.getByLabelText<HTMLInputElement>(/^Nazwa/).value).toBe('Zmiana lokalna')
  })

  it('blokuje wielokrotne wysłanie tej samej edycji', async () => {
    let resolvePatch: ((value: RevisedRawMaterial) => void) | undefined
    reviseMock.mockImplementation(() => new Promise((resolve) => { resolvePatch = resolve }))
    renderForm()
    await screen.findByLabelText(/^Nazwa/)
    const form = screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }).closest('form')
    fireEvent.submit(form as HTMLFormElement)
    fireEvent.submit(form as HTMLFormElement)

    await screen.findByText('Zapisywanie nowej rewizji…')
    expect(reviseMock).toHaveBeenCalledOnce()
    resolvePatch?.(makeSaved())
    await screen.findByText('Zapisano nową rewizję roboczą v5')
  })

  it.each([401, 419, 403, 503])('obsługuje odmowę/wygaśnięcie przed pobraniem edytora: HTTP %s', async (status) => {
    const onSessionExpired = vi.fn()
    const onAuthorizationDenied = vi.fn()
    const onModuleUnavailable = vi.fn()
    capabilitiesMock.mockRejectedValue(new ApiError(status, 'Odmowa.', 'RAW_MATERIALS_MODULE_UNAVAILABLE'))
    renderForm({ onSessionExpired, onAuthorizationDenied, onModuleUnavailable })
    await waitFor(() => {
      expect(status === 401 || status === 419
        ? onSessionExpired
        : status === 403 ? onAuthorizationDenied : onModuleUnavailable).toHaveBeenCalledOnce()
    })
    expect(editorMock).not.toHaveBeenCalled()
    expect(reviseMock).not.toHaveBeenCalled()
  })

  it('pokazuje neutralny komunikat 404 bez pobierania danych edytora', async () => {
    capabilitiesMock.mockRejectedValue(new ApiError(404, 'Nie znaleziono.'))
    renderForm()
    expect(await screen.findByText('Surowiec jest niedostępny w bieżącym kontekście.')).toBeTruthy()
    expect(editorMock).not.toHaveBeenCalled()
    expect(reviseMock).not.toHaveBeenCalled()
  })

  it('przy HTTP 409 zachowuje lokalne zmiany i wymaga jawnego potwierdzenia ich odrzucenia przed odświeżeniem', async () => {
    reviseMock.mockRejectedValue(new ApiError(409, 'Konflikt.', 'RAW_MATERIAL_VERSION_CONFLICT'))
    renderForm()
    const name = await screen.findByLabelText(/^Nazwa/)
    fireEvent.change(name, { target: { value: 'Moja lokalna zmiana' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    expect(await screen.findByText(/Nie nadpisano nowszych danych/)).toBeTruthy()
    expect(screen.getByLabelText<HTMLInputElement>(/^Nazwa/).value).toBe('Moja lokalna zmiana')
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    fireEvent.click(screen.getByRole('button', { name: 'Odrzuć lokalne zmiany i wczytaj aktualną wersję' }))
    await waitFor(() => expect(confirm).toHaveBeenCalledOnce())
    expect(screen.getByLabelText<HTMLInputElement>(/^Nazwa/).value).toBe('Mąka pszenna')
    expect(reviseMock).toHaveBeenCalledOnce()
  })

  it('po niejednoznacznym wyniku nie ponawia PATCH, ponownie odczytuje serwer i zachowuje lokalne zmiany', async () => {
    reviseMock.mockRejectedValue(new Error('Nie można połączyć się z backendem.'))
    renderForm()
    const name = await screen.findByLabelText(/^Nazwa/)
    fireEvent.change(name, { target: { value: 'Nazwa lokalna' } })
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz nową rewizję roboczą' }))

    expect(await screen.findByText('Niepewny wynik zapisu')).toBeTruthy()
    expect(await screen.findByText(/odczytano rewizję v4/i)).toBeTruthy()
    expect(screen.getByLabelText<HTMLInputElement>(/^Nazwa/).value).toBe('Nazwa lokalna')
    expect(reviseMock).toHaveBeenCalledOnce()
    expect(editorMock).toHaveBeenCalledTimes(2)
  })
})
