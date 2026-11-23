import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import RawMaterialDocumentsSection from './RawMaterialDocumentsSection'
import {
  fetchRawMaterialDocumentFile,
  fetchRawMaterialDocuments,
  retryRawMaterialDocumentUpload,
  uploadRawMaterialDocuments,
  type RawMaterialDocumentListing,
  type RawMaterialUploadResult,
} from './api'

vi.mock('./api', async (importOriginal) => ({
  ...await importOriginal<typeof import('./api')>(),
  fetchRawMaterialDocumentFile: vi.fn(),
  fetchRawMaterialDocuments: vi.fn(),
  retryRawMaterialDocumentUpload: vi.fn(),
  uploadRawMaterialDocuments: vi.fn(),
}))

const materialId = '00000000-0000-4000-8000-000000000101'
const locationId = '00000000-0000-4000-8000-000000000102'
const documentId = '00000000-0000-4000-8000-000000000103'
const revisionId = '00000000-0000-4000-8000-000000000104'
const uploadId = '00000000-0000-4000-8000-000000000105'
const documentPath = `/api/v1/raw-materials/${materialId}/documents/${documentId}`

const listing: RawMaterialDocumentListing = {
  revisionId,
  documents: [{
    id: documentId,
    filename: 'specyfikacja.pdf',
    documentType: 'OTHER',
    mediaType: 'application/pdf',
    sizeBytes: 1200,
    status: 'CLEAN',
    downloadUrl: `${documentPath}/download`,
    previewUrl: `${documentPath}/preview`,
  }],
}

const listingMock = vi.mocked(fetchRawMaterialDocuments)
const uploadMock = vi.mocked(uploadRawMaterialDocuments)
const retryMock = vi.mocked(retryRawMaterialDocumentUpload)
const fileMock = vi.mocked(fetchRawMaterialDocumentFile)

function renderSection(overrides: Partial<React.ComponentProps<typeof RawMaterialDocumentsSection>> = {}) {
  const props: React.ComponentProps<typeof RawMaterialDocumentsSection> = {
    materialId,
    operatingLocationId: locationId,
    mode: 'read',
    onSessionExpired: vi.fn(),
    onAuthorizationDenied: vi.fn(),
    onModuleUnavailable: vi.fn(),
    ...overrides,
  }
  return { ...render(<RawMaterialDocumentsSection {...props} />), props }
}

function uploadResult(status: 'STAGED' | 'FAILED' = 'STAGED'): RawMaterialUploadResult {
  return {
    key: 'idempotency-key',
    uploadId,
    filename: 'zalacznik.txt',
    mediaType: 'text/plain',
    sizeBytes: 3,
    status,
    errorCode: status === 'FAILED' ? 'RAW_MATERIAL_UPLOAD_NOT_CLEAN' : null,
    message: status === 'FAILED' ? 'Nie uzyskano czystego wyniku skanowania.' : 'Plik oczekuje na zapis surowca.',
    expiresAt: '2026-11-11T12:00:00Z',
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  listingMock.mockResolvedValue(listing)
  uploadMock.mockImplementation(async (files) => files.map(({ key }) => ({ ...uploadResult(), key })))
  retryMock.mockImplementation(async (_id, key) => ({ ...uploadResult(), key }))
  fileMock.mockResolvedValue({ blob: new Blob(['PDF']), filename: 'specyfikacja.pdf', mediaType: 'application/pdf' })
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('sekcja dokumentów RAW', () => {
  it('pokazuje metadane, skan AV i tylko autoryzowane akcje dostępne dla czystego pliku', async () => {
    renderSection()

    expect(await screen.findByText('specyfikacja.pdf')).toBeTruthy()
    expect(screen.getByText('Inny dokument · application/pdf · 1.2 KiB')).toBeTruthy()
    expect(screen.getByText('Skan AV: czysty')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Podgląd' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Pobierz' })).toBeTruthy()
  })

  it('nie udostępnia plików w stanie PENDING, INFECTED ani ERROR', async () => {
    listingMock.mockResolvedValueOnce({
      revisionId,
      documents: (['PENDING', 'INFECTED', 'ERROR'] as const).map((status, index) => ({
        ...listing.documents[0],
        id: `00000000-0000-4000-8000-00000000011${index}`,
        filename: `dokument-${status.toLowerCase()}.txt`,
        mediaType: 'text/plain',
        status,
        downloadUrl: null,
        previewUrl: null,
      })),
    })
    renderSection()

    expect(await screen.findByText('Oczekiwanie na skanowanie AV')).toBeTruthy()
    expect(screen.getByText('Zablokowany — wykryto zagrożenie')).toBeTruthy()
    expect(screen.getByText('Błąd skanowania AV')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Podgląd' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Pobierz' })).toBeNull()
  })

  it('oddziela staging od przypięcia i ponawia wyłącznie skan udostępniony przez backend', async () => {
    uploadMock.mockImplementationOnce(async (files) => files.map(({ key }) => ({ ...uploadResult('FAILED'), key })))
    const onUploadIdsChange = vi.fn()
    renderSection({
      materialId: undefined,
      mode: 'create',
      onUploadIdsChange,
    })
    const file = new File(['abc'], 'zalacznik.txt', { type: 'text/plain' })
    fireEvent.change(screen.getByLabelText('Dodaj pliki'), { target: { files: [file] } })

    expect(await screen.findByText(/Nie uzyskano czystego wyniku AV/)).toBeTruthy()
    expect(onUploadIdsChange).toHaveBeenLastCalledWith([])
    expect(screen.getByText(/dopiero po zapisaniu surowca/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Ponów skan' }))

    expect(await screen.findByText(/Skan AV czysty; oczekuje na zapis materiału/)).toBeTruthy()
    expect(retryMock).toHaveBeenCalledWith(uploadId, expect.any(String), expect.any(AbortSignal))
    expect(onUploadIdsChange).toHaveBeenLastCalledWith([uploadId])
  })

  it('odrzuca nieobsługiwane pliki przed wysłaniem do stagingu', () => {
    renderSection({ materialId: undefined, mode: 'create' })
    fireEvent.change(screen.getByLabelText('Dodaj pliki'), {
      target: { files: [new File(['x'], 'skrypt.exe', { type: 'application/octet-stream' })] },
    })

    expect(screen.getByRole('alert').textContent).toContain('Dozwolone są niepuste pliki')
    expect(uploadMock).not.toHaveBeenCalled()
  })

  it('pobiera podgląd jako blob i zwalnia URL po jego zamknięciu', async () => {
    const createObjectURL = vi.fn(() => 'blob:raw-document')
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL })
    renderSection()
    await screen.findByText('specyfikacja.pdf')
    fireEvent.click(screen.getByRole('button', { name: 'Podgląd' }))

    expect(await screen.findByTitle('Podgląd dokumentu specyfikacja.pdf')).toBeTruthy()
    expect(fileMock).toHaveBeenCalledWith(listing.documents[0].previewUrl, 'application/pdf', 'specyfikacja.pdf', expect.any(AbortSignal))
    fireEvent.click(screen.getByRole('button', { name: 'Zamknij podgląd' }))
    await waitFor(() => expect(revokeObjectURL).toHaveBeenCalledWith('blob:raw-document'))
  })

  it('pobiera plik przez autoryzowany endpoint backendu', async () => {
    const createObjectURL = vi.fn(() => 'blob:raw-download')
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    renderSection()
    await screen.findByText('specyfikacja.pdf')
    fireEvent.click(screen.getByRole('button', { name: 'Pobierz' }))

    await waitFor(() => expect(fileMock).toHaveBeenCalledWith(
      listing.documents[0].downloadUrl,
      'application/pdf',
      'specyfikacja.pdf',
      expect.any(AbortSignal),
    ))
    await waitFor(() => expect(click).toHaveBeenCalled())
  })

  it('czyści dokumenty po odmowie dostępu i informuje o capability niedostępnej', async () => {
    const { props, unmount } = renderSection()
    expect(await screen.findByText('specyfikacja.pdf')).toBeTruthy()
    fileMock.mockRejectedValueOnce(new ApiError(403, 'Brak uprawnień', 'FORBIDDEN'))
    fireEvent.click(screen.getByRole('button', { name: 'Podgląd' }))
    await waitFor(() => expect(props.onAuthorizationDenied).toHaveBeenCalled())
    expect(screen.queryByText('specyfikacja.pdf')).toBeNull()
    unmount()

    listingMock.mockRejectedValueOnce(new ApiError(503, 'Moduł niedostępny', 'RAW_MATERIALS_MODULE_UNAVAILABLE'))
    const unavailable = vi.fn()
    renderSection({ onModuleUnavailable: unavailable })
    expect(await screen.findByText(/Moduł RAW_MATERIALS jest niedostępny/)).toBeTruthy()
    expect(unavailable).toHaveBeenCalled()
  })

  it('ignores a stale list response after the operating context changes', async () => {
    let resolveOld!: (value: RawMaterialDocumentListing) => void
    listingMock.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve }))
    listingMock.mockResolvedValueOnce({
      revisionId,
      documents: [{ ...listing.documents[0], filename: 'nowy-kontekst.pdf' }],
    })
    const { rerender } = renderSection()
    await waitFor(() => expect(listingMock).toHaveBeenCalledTimes(1))
    rerender(
      <RawMaterialDocumentsSection
        materialId={materialId}
        operatingLocationId="00000000-0000-4000-8000-000000000106"
        mode="read"
        onSessionExpired={vi.fn()}
        onAuthorizationDenied={vi.fn()}
        onModuleUnavailable={vi.fn()}
      />,
    )
    expect(screen.queryByText('specyfikacja.pdf')).toBeNull()
    expect(await screen.findByText('nowy-kontekst.pdf')).toBeTruthy()
    await act(async () => resolveOld(listing))
    expect(screen.queryByText('specyfikacja.pdf')).toBeNull()
  })

  it('unieważnia widoczną listę po wygaśnięciu sesji', async () => {
    const { props, unmount } = renderSection()
    expect(await screen.findByText('specyfikacja.pdf')).toBeTruthy()
    unmount()
    listingMock.mockRejectedValueOnce(new ApiError(419, 'Sesja wygasła'))
    renderSection({ refreshKey: 1, onSessionExpired: props.onSessionExpired })
    await waitFor(() => expect(props.onSessionExpired).toHaveBeenCalled())
    expect(screen.queryByText('specyfikacja.pdf')).toBeNull()
  })
})
