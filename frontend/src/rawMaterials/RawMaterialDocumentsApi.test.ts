import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  fetchRawMaterialDocumentFile,
  fetchRawMaterialDocuments,
  retryRawMaterialDocumentUpload,
  uploadRawMaterialDocuments,
} from './api'

const materialId = '00000000-0000-4000-8000-000000000201'
const revisionId = '00000000-0000-4000-8000-000000000202'
const documentId = '00000000-0000-4000-8000-000000000203'
const uploadId = '00000000-0000-4000-8000-000000000204'
const documentPath = `/api/v1/raw-materials/${materialId}/documents/${documentId}`

afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/'
})

describe('klient dokumentów RAW', () => {
  it('używa wyłącznie listy dokumentów i zachowuje zakres widoku edytora', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: {
        revision_id: revisionId,
        documents: [{
          id: documentId,
          filename: 'specyfikacja.pdf',
          document_type: 'OTHER',
          media_type: 'application/pdf',
          size_bytes: 50,
          status: 'CLEAN',
          download_url: `${documentPath}/download?view=editor`,
          preview_url: `${documentPath}/preview?view=editor`,
        }],
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(fetchRawMaterialDocuments(materialId, 'editor')).resolves.toMatchObject({
      revisionId,
      documents: [{ filename: 'specyfikacja.pdf', status: 'CLEAN', documentType: 'OTHER' }],
    })
    expect(fetchMock).toHaveBeenCalledWith(
      `/api/v1/raw-materials/${materialId}/documents?view=editor`,
      expect.objectContaining({ method: 'GET', credentials: 'same-origin', cache: 'no-store' }),
    )
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('wysyła pola plików i kluczy zgodne z kontraktem oraz ponawia staging przez dedykowaną trasę', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{
        key: 'idempotency-key',
        upload_id: uploadId,
        filename: 'zalacznik.txt',
        media_type: 'text/plain',
        size_bytes: 3,
        status: 'STAGED',
        error_code: null,
        message: 'Plik oczekuje na zapis surowca.',
        expires_at: '2026-11-11T12:00:00Z',
      }] }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: {
        upload_id: uploadId,
        filename: 'zalacznik.txt',
        media_type: 'text/plain',
        size_bytes: 3,
        status: 'STAGED',
        error_code: null,
        message: 'Plik oczekuje na zapis surowca.',
        expires_at: '2026-11-11T12:00:00Z',
      } }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=csrf%3Atoken; Path=/'
    const file = new File(['abc'], 'zalacznik.txt', { type: 'text/plain' })

    await expect(uploadRawMaterialDocuments([{ file, key: 'idempotency-key' }])).resolves.toMatchObject([
      { uploadId, status: 'STAGED', filename: 'zalacznik.txt' },
    ])
    const request = fetchMock.mock.calls[0][1] as RequestInit
    const formData = request.body as FormData
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/raw-materials/document-uploads')
    expect(formData.get('files[0]')).toBeInstanceOf(File)
    expect(formData.get('keys[0]')).toBe('idempotency-key')
    expect(request.headers).toMatchObject({ 'X-XSRF-TOKEN': 'csrf:token' })

    await retryRawMaterialDocumentUpload(uploadId, 'idempotency-key')
    expect(fetchMock.mock.calls[1][0]).toBe(`/api/v1/raw-materials/document-uploads/${uploadId}/retry`)
    expect(fetchMock.mock.calls[1][1]).toMatchObject({ method: 'POST' })
    expect(fetchMock.mock.calls).toHaveLength(2)
  })

  it('wymaga oczekiwanego typu MIME przed zwróceniem pliku', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(new Blob(['PDF']), {
      status: 200,
      headers: {
        'Content-Type': 'application/pdf',
        'Content-Disposition': 'inline; filename="specyfikacja.pdf"',
      },
    }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(fetchRawMaterialDocumentFile(
      `${documentPath}/preview`,
      'application/pdf',
      'specyfikacja.pdf',
    )).resolves.toMatchObject({ filename: 'specyfikacja.pdf', mediaType: 'application/pdf' })
    expect(fetchMock).toHaveBeenCalledWith(`${documentPath}/preview`, expect.objectContaining({
      method: 'GET',
      credentials: 'same-origin',
      headers: expect.objectContaining({ Accept: 'application/pdf' }),
    }))

    fetchMock.mockResolvedValueOnce(new Response(new Blob(['HTML']), {
      status: 200,
      headers: { 'Content-Type': 'text/html' },
    }))
    await expect(fetchRawMaterialDocumentFile(
      `${documentPath}/download`,
      'application/pdf',
      'specyfikacja.pdf',
    )).rejects.toThrow('Backend zwrócił plik w nieobsługiwanym formacie.')
  })
})
