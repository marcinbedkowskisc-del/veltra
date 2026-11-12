import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import RawMaterialWorkflow from './RawMaterialWorkflow'
import {
  changeRawMaterialLifecycle,
  fetchAllergenCatalogs,
  fetchAllergenDeclaration,
  fetchRawMaterialCompletion,
  saveAllergenDeclaration,
  type RawMaterialCompletion,
} from './api'

vi.mock('./api', () => ({
  changeRawMaterialLifecycle: vi.fn(),
  fetchAllergenCatalogs: vi.fn(),
  fetchAllergenDeclaration: vi.fn(),
  fetchRawMaterialCompletion: vi.fn(),
  saveAllergenDeclaration: vi.fn(),
}))

const report: RawMaterialCompletion = {
  lifecycleState: 'DRAFT', revisionId: 'revision-1', version: 'version-1',
  availableActions: ['edit_allergens', 'submit'], missingRequirements: [],
}
const props = {
  materialId: 'material-1', refreshKey: 0,
  onSaved: vi.fn(), onSessionExpired: vi.fn(), onModuleUnavailable: vi.fn(),
}

beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(fetchRawMaterialCompletion).mockResolvedValue(report)
  vi.mocked(changeRawMaterialLifecycle).mockResolvedValue()
  vi.mocked(saveAllergenDeclaration).mockResolvedValue()
  vi.mocked(fetchAllergenCatalogs).mockResolvedValue([
    { id: 'catalog-1', versionNumber: 1, definitions: [{ code: 'TEST_WHEAT', name: 'TEST — Pszenica' }] },
  ])
  vi.mocked(fetchAllergenDeclaration).mockResolvedValue({
    version: 'version-1', declaration: { status: 'UNKNOWN', catalog_version_id: null, items: [] },
  })
})
afterEach(cleanup)

it('przekazuje i zatwierdza dopiero po osobnych potwierdzeniach, z właściwą wersją i uzasadnieniem', async () => {
  const { rerender } = render(<RawMaterialWorkflow key="0" {...props} />)
  fireEvent.click(await screen.findByRole('button', { name: 'Przekaż do zatwierdzenia' }))
  expect(changeRawMaterialLifecycle).not.toHaveBeenCalled()
  fireEvent.click(screen.getByRole('button', { name: 'Potwierdź przekazanie' }))
  await waitFor(() => expect(props.onSaved).toHaveBeenCalledTimes(1))
  expect(changeRawMaterialLifecycle).toHaveBeenCalledWith('material-1', 'submit', 'version-1', '')
  vi.mocked(fetchRawMaterialCompletion).mockResolvedValue({
    ...report, lifecycleState: 'VERIFICATION', version: 'version-2', availableActions: ['approve'],
  })
  rerender(<RawMaterialWorkflow key="1" {...props} refreshKey={1} />)
  fireEvent.click(await screen.findByRole('button', { name: 'Zatwierdź rewizję' }))
  expect(screen.queryByRole('button', { name: 'Przekaż do zatwierdzenia' })).toBeNull()
  fireEvent.change(screen.getByLabelText('Uzasadnienie zatwierdzenia'), { target: { value: 'Akceptacja rewizji' } })
  fireEvent.click(screen.getByRole('button', { name: 'Potwierdź zatwierdzenie' }))
  await waitFor(() => expect(props.onSaved).toHaveBeenCalledTimes(2))
  expect(changeRawMaterialLifecycle).toHaveBeenLastCalledWith('material-1', 'approve', 'version-2', 'Akceptacja rewizji')
})

it('nie deklaruje automatycznie braku alergenów i zapisuje jawny wybór przez istniejący kontrakt', async () => {
  render(<RawMaterialWorkflow {...props} />)
  fireEvent.click(await screen.findByRole('button', { name: 'Deklaracja alergenów' }))
  const status = await screen.findByLabelText('Status deklaracji')
  expect((status as HTMLSelectElement).value).toBe('UNKNOWN')
  expect(saveAllergenDeclaration).not.toHaveBeenCalled()
  fireEvent.change(status, { target: { value: 'DECLARED' } })
  fireEvent.change(screen.getByLabelText('Wersja katalogu alergenów'), { target: { value: 'catalog-1' } })
  fireEvent.change(screen.getByLabelText('TEST — Pszenica'), { target: { value: 'DIRECT' } })
  fireEvent.click(screen.getByRole('button', { name: 'Zapisz deklarację' }))
  await waitFor(() => expect(props.onSaved).toHaveBeenCalledTimes(1))
  expect(saveAllergenDeclaration).toHaveBeenCalledWith('material-1', {
    status: 'DECLARED', catalog_version_id: 'catalog-1',
    items: [{ allergen_code: 'TEST_WHEAT', exposure_kind: 'DIRECT' }],
  }, 'version-1')
})

it('nie pokazuje działań nieobecnych w ocenie RBAC backendu', async () => {
  vi.mocked(fetchRawMaterialCompletion).mockResolvedValue({ ...report, availableActions: [] })
  render(<RawMaterialWorkflow {...props} />)
  await waitFor(() => expect(screen.queryByText('Sprawdzanie dostępnych działań…')).toBeNull())
  expect(screen.queryByRole('button')).toBeNull()
})

it('pokazuje wymagania zatwierdzania i brak katalogu, zamiast wymyślać dane', async () => {
  vi.mocked(fetchAllergenCatalogs).mockResolvedValue([])
  vi.mocked(fetchRawMaterialCompletion).mockResolvedValue({
    ...report, missingRequirements: [{ code: 'ALLERGENS_REQUIRED', section: 'allergens', message: 'Wymagana deklaracja alergenów.' }],
  })
  render(<RawMaterialWorkflow {...props} />)
  expect(await screen.findByText('Wymagana deklaracja alergenów.')).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Deklaracja alergenów' }))
  expect((await screen.findByRole('alert')).textContent).toContain('Brak opublikowanego katalogu')
  fireEvent.change(screen.getByLabelText('Status deklaracji'), { target: { value: 'NO_KNOWN_ALLERGENS' } })
  expect(screen.getByRole('button', { name: 'Zapisz deklarację' }).hasAttribute('disabled')).toBe(true)
})

it('nie ponawia konfliktu wersji ani nie zgłasza sukcesu po niejednoznacznym zapisie', async () => {
  vi.mocked(changeRawMaterialLifecycle).mockRejectedValue(new ApiError(409, 'Konflikt wersji.', 'RAW_MATERIAL_VERSION_CONFLICT'))
  render(<RawMaterialWorkflow {...props} />)
  fireEvent.click(await screen.findByRole('button', { name: 'Przekaż do zatwierdzenia' }))
  fireEvent.click(screen.getByRole('button', { name: 'Potwierdź przekazanie' }))
  expect((await screen.findByRole('alert')).textContent).toContain('Konflikt wersji.')
  expect(changeRawMaterialLifecycle).toHaveBeenCalledTimes(1)
  expect(props.onSaved).not.toHaveBeenCalled()
  expect(screen.getByRole('button', { name: 'Wczytaj aktualny stan' })).toBeTruthy()
})

it('przenosi fokus do potwierdzenia i pokazuje szczegóły walidacji backendu', async () => {
  vi.mocked(fetchRawMaterialCompletion).mockResolvedValue({
    ...report, lifecycleState: 'VERIFICATION', availableActions: ['approve'],
  })
  vi.mocked(changeRawMaterialLifecycle).mockRejectedValue(new ApiError(422, 'Sprawdź dane.', null, {
    reason: ['Uzasadnienie jest zbyt długie.'],
  }))
  render(<RawMaterialWorkflow {...props} />)
  fireEvent.click(await screen.findByRole('button', { name: 'Zatwierdź rewizję' }))
  const reason = screen.getByLabelText('Uzasadnienie zatwierdzenia')
  expect(document.activeElement).toBe(reason)
  fireEvent.change(reason, { target: { value: 'Akceptacja' } })
  fireEvent.click(screen.getByRole('button', { name: 'Potwierdź zatwierdzenie' }))
  expect(await screen.findByText('Uzasadnienie jest zbyt długie.')).toBeTruthy()
  expect(props.onSaved).not.toHaveBeenCalled()
  expect(changeRawMaterialLifecycle).toHaveBeenCalledTimes(1)
})

it.each([401, 419, 403, 503])('obsługuje odmowę %s bez udostępnienia operacji', async (status) => {
  vi.mocked(fetchRawMaterialCompletion).mockRejectedValue(new ApiError(status, 'Odmowa.', status === 503 ? 'RAW_MATERIALS_MODULE_UNAVAILABLE' : null))
  render(<RawMaterialWorkflow {...props} />)
  await waitFor(() => expect(screen.queryByText('Sprawdzanie dostępnych działań…')).toBeNull())
  expect(screen.queryByRole('button', { name: 'Przekaż do zatwierdzenia' })).toBeNull()
  if (status === 401 || status === 419) expect(props.onSessionExpired).toHaveBeenCalled()
  if (status === 503) expect(props.onModuleUnavailable).toHaveBeenCalled()
})

it('ignoruje wynik zapisu po zmianie kontekstu lub zamknięciu szczegółów', async () => {
  let resolve!: () => void
  vi.mocked(changeRawMaterialLifecycle).mockReturnValue(new Promise<void>((done) => { resolve = done }))
  const { unmount } = render(<RawMaterialWorkflow {...props} />)
  fireEvent.click(await screen.findByRole('button', { name: 'Przekaż do zatwierdzenia' }))
  fireEvent.click(screen.getByRole('button', { name: 'Potwierdź przekazanie' }))
  unmount()
  await act(async () => resolve())
  expect(props.onSaved).not.toHaveBeenCalled()
})
