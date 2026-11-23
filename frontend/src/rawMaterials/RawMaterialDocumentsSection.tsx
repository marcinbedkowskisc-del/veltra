import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import {
  fetchRawMaterialDocumentFile,
  fetchRawMaterialDocuments,
  retryRawMaterialDocumentUpload,
  uploadRawMaterialDocuments,
  type RawMaterialDocument,
  type RawMaterialDocumentListing,
  type RawMaterialUploadResult,
} from './api'

const MAX_UPLOAD_BYTES = 20 * 1024 * 1024
const ALLOWED_EXTENSIONS = /\.(pdf|png|jpe?g|txt|csv)$/i

const documentTypeLabels: Record<RawMaterialDocument['documentType'], string> = {
  ALLERGEN_DECLARATION: 'Deklaracja alergenów',
  SUPPLIER_CERTIFICATE: 'Certyfikat dostawcy',
  SPECIFICATION: 'Specyfikacja',
  SAFETY_DATA_SHEET: 'Karta charakterystyki',
  OTHER: 'Inny dokument',
}

const documentStatusLabels: Record<RawMaterialDocument['status'], string> = {
  PENDING: 'Oczekiwanie na skanowanie AV',
  CLEAN: 'Skan AV: czysty',
  INFECTED: 'Zablokowany — wykryto zagrożenie',
  ERROR: 'Błąd skanowania AV',
}

type Props = {
  materialId?: string
  operatingLocationId: string
  view?: 'current' | 'editor'
  mode: 'read' | 'create' | 'edit'
  detachIds?: string[]
  refreshKey?: number
  disabled?: boolean
  onUploadIdsChange?: (ids: string[]) => void
  onDetachIdsChange?: (ids: string[]) => void
  onBusyChange?: (busy: boolean) => void
  onSessionExpired: () => void
  onAuthorizationDenied: () => void
  onModuleUnavailable: () => void
}

type UploadRow = {
  key: string
  file: File
  result: RawMaterialUploadResult | null
  error: string | null
  processing: boolean
}

type ListingState =
  | { key: string; status: 'loading' }
  | { key: string; status: 'ready'; listing: RawMaterialDocumentListing }
  | { key: string; status: 'forbidden' | 'unavailable' | 'session-expired' | 'error'; message: string }

type Preview = {
  key: string
  documentId: string
  url: string
  mediaType: string
  filename: string
}

function documentError(cause: unknown): string {
  if (cause instanceof ApiError) {
    if (cause.status === 401 || cause.status === 419) return 'Sesja wygasła. Zaloguj się ponownie, aby wyświetlić dokumenty.'
    if (cause.status === 403) return 'Brak uprawnień do dokumentów w bieżącym kontekście.'
    if (cause.status === 404) return 'Dokument lub jego rewizja nie są już dostępne.'
    if (cause.status === 413 || cause.code === 'RAW_MATERIAL_UPLOAD_REQUEST_TOO_LARGE') {
      return 'Łączny rozmiar plików przekracza limit 20 MiB. Wyślij mniejszą partię.'
    }
    if (cause.code === 'RAW_MATERIAL_DOCUMENT_FORMAT_UNSUPPORTED') {
      return 'Nieprawidłowy format lub zawartość pliku. Dozwolone są PDF, PNG, JPEG, TXT i CSV.'
    }
    if (cause.status === 503 && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
      return 'Moduł RAW_MATERIALS jest niedostępny w bieżącej lokalizacji.'
    }
    return cause.message
  }
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji na dokumencie.'
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MiB`
}

function isSessionExpired(cause: unknown): boolean {
  return cause instanceof ApiError && (cause.status === 401 || cause.status === 419)
}

function isAccessDenied(cause: unknown): boolean {
  return cause instanceof ApiError && cause.status === 403
}

function isModuleUnavailable(cause: unknown): boolean {
  return cause instanceof ApiError && cause.status === 503
    && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE'
}

export default function RawMaterialDocumentsSection({
  materialId,
  operatingLocationId,
  view = 'current',
  mode,
  detachIds = [],
  refreshKey = 0,
  disabled = false,
  onUploadIdsChange,
  onDetachIdsChange,
  onBusyChange,
  onSessionExpired,
  onAuthorizationDenied,
  onModuleUnavailable,
}: Props) {
  const requestKey = `${materialId ?? 'new'}:${operatingLocationId}:${view}:${refreshKey}`
  const [listingState, setListingState] = useState<ListingState>({ key: requestKey, status: 'loading' })
  const [uploads, setUploads] = useState<UploadRow[]>([])
  const uploadsRef = useRef<UploadRow[]>([])
  const [uploading, setUploading] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const [validationError, setValidationError] = useState<string | null>(null)
  const [fileBusyId, setFileBusyId] = useState<string | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [preview, setPreview] = useState<Preview | null>(null)
  const fileController = useRef<AbortController | null>(null)
  const uploadController = useRef<AbortController | null>(null)
  const activeRequestKey = useRef(requestKey)

  const updateUploads = useCallback((update: (current: UploadRow[]) => UploadRow[]) => {
    const next = update(uploadsRef.current)
    uploadsRef.current = next
    setUploads(next)
    onUploadIdsChange?.(next.flatMap((row) =>
      row.result?.status === 'STAGED' && row.result.uploadId ? [row.result.uploadId] : []))
  }, [onUploadIdsChange])

  const clearPrivateData = useCallback((
    status: 'forbidden' | 'unavailable' | 'session-expired',
    message: string,
  ) => {
    setListingState({ key: requestKey, status, message })
    setPreview(null)
    setFileError(null)
    setUploadError(null)
    updateUploads(() => [])
    onDetachIdsChange?.([])
    setUploading(false)
    onBusyChange?.(false)
  }, [onBusyChange, onDetachIdsChange, requestKey, updateUploads])

  function setBusy(busy: boolean) {
    setUploading(busy)
    onBusyChange?.(busy)
  }

  useEffect(() => {
    activeRequestKey.current = requestKey
    if (!materialId) {
      return
    }

    let active = true
    let timer: number | undefined
    let controller: AbortController | null = null
    const load = async () => {
      controller = new AbortController()
      try {
        const listing = await fetchRawMaterialDocuments(materialId, view, controller.signal)
        if (!active || activeRequestKey.current !== requestKey) return
        setListingState({ key: requestKey, status: 'ready', listing })
        if (listing.documents.some((document) => document.status === 'PENDING')) {
          timer = window.setTimeout(() => void load(), 2_000)
        }
      } catch (cause) {
        if (!active || controller?.signal.aborted || activeRequestKey.current !== requestKey) return
        const message = documentError(cause)
        if (isSessionExpired(cause)) {
          clearPrivateData('session-expired', message)
          onSessionExpired()
        } else if (isAccessDenied(cause)) {
          clearPrivateData('forbidden', message)
          onAuthorizationDenied()
        } else if (isModuleUnavailable(cause)) {
          clearPrivateData('unavailable', message)
          onModuleUnavailable()
        } else {
          setListingState({ key: requestKey, status: 'error', message })
        }
      }
    }

    void load()
    return () => {
      active = false
      if (timer !== undefined) window.clearTimeout(timer)
      controller?.abort()
      fileController.current?.abort()
      uploadController.current?.abort()
    }
  }, [clearPrivateData, materialId, onAuthorizationDenied, onModuleUnavailable, onSessionExpired, requestKey, view])

  useEffect(() => () => {
    if (preview) URL.revokeObjectURL(preview.url)
    fileController.current?.abort()
  }, [preview])

  function handleAccessFailure(cause: unknown): boolean {
    if (isSessionExpired(cause)) {
      clearPrivateData('session-expired', documentError(cause))
      onSessionExpired()
      return true
    }
    if (isAccessDenied(cause)) {
      clearPrivateData('forbidden', documentError(cause))
      onAuthorizationDenied()
      return true
    }
    if (isModuleUnavailable(cause)) {
      clearPrivateData('unavailable', documentError(cause))
      onModuleUnavailable()
      return true
    }
    return false
  }

  async function sendUploads(rows: UploadRow[]) {
    setBusy(true)
    setUploadError(null)
    updateUploads((current) => current.map((row) =>
      rows.some((item) => item.key === row.key) ? { ...row, processing: true, error: null } : row))
    const controller = new AbortController()
    uploadController.current = controller
    try {
      const results = await uploadRawMaterialDocuments(
        rows.map(({ file, key }) => ({ file, key })),
        controller.signal,
      )
      if (activeRequestKey.current !== requestKey) return
      updateUploads((current) => current.map((row) => {
        const result = results.find((item) => item.key === row.key)
        return result ? { ...row, result, error: null, processing: false } : row
      }))
    } catch (cause) {
      if (controller.signal.aborted || activeRequestKey.current !== requestKey) return
      if (!handleAccessFailure(cause)) {
        const message = documentError(cause)
        setUploadError(message)
        updateUploads((current) => current.map((row) =>
          rows.some((item) => item.key === row.key) ? { ...row, error: message, processing: false } : row))
      }
    } finally {
      if (uploadController.current === controller) uploadController.current = null
      if (activeRequestKey.current === requestKey) setBusy(false)
    }
  }

  function handleFileSelection(event: React.ChangeEvent<HTMLInputElement>) {
    const selected = Array.from(event.currentTarget.files ?? [])
    event.currentTarget.value = ''
    setValidationError(null)
    const totalBytes = selected.reduce((total, file) => total + file.size, 0)
    const invalid = selected.find((file) => !ALLOWED_EXTENSIONS.test(file.name) || file.size === 0)
    if (invalid) {
      setValidationError('Dozwolone są niepuste pliki PDF, PNG, JPEG, TXT i CSV.')
      return
    }
    if (totalBytes > MAX_UPLOAD_BYTES || selected.some((file) => file.size > MAX_UPLOAD_BYTES)) {
      setValidationError('Łączny rozmiar plików w jednej partii nie może przekraczać 20 MiB.')
      return
    }
    if (!selected.length) return

    const rows = selected.map((file): UploadRow => ({
      key: crypto.randomUUID(),
      file,
      result: null,
      error: null,
      processing: true,
    }))
    updateUploads((current) => [...current, ...rows])
    void sendUploads(rows)
  }

  async function retryUpload(row: UploadRow) {
    if (row.processing || uploading) return
    if (!row.result?.uploadId) {
      await sendUploads([row])
      return
    }
    setBusy(true)
    setUploadError(null)
    updateUploads((current) => current.map((item) =>
      item.key === row.key ? { ...item, processing: true, error: null } : item))
    const controller = new AbortController()
    uploadController.current = controller
    try {
      const result = await retryRawMaterialDocumentUpload(row.result.uploadId, row.key, controller.signal)
      if (activeRequestKey.current !== requestKey) return
      updateUploads((current) => current.map((item) =>
        item.key === row.key ? { ...item, result, error: null, processing: false } : item))
    } catch (cause) {
      if (controller.signal.aborted || activeRequestKey.current !== requestKey) return
      if (!handleAccessFailure(cause)) {
        const message = documentError(cause)
        setUploadError(message)
        updateUploads((current) => current.map((item) =>
          item.key === row.key ? { ...item, error: message, processing: false } : item))
      }
    } finally {
      if (uploadController.current === controller) uploadController.current = null
      if (activeRequestKey.current === requestKey) setBusy(false)
    }
  }

  function removeUpload(key: string) {
    updateUploads((current) => current.filter((row) => row.key !== key))
  }

  function toggleDetached(documentId: string) {
    const next = detachIds.includes(documentId)
      ? detachIds.filter((id) => id !== documentId)
      : [...detachIds, documentId]
    onDetachIdsChange?.(next)
  }

  async function openDocument(document: RawMaterialDocument, action: 'preview' | 'download') {
    const path = action === 'preview' ? document.previewUrl : document.downloadUrl
    if (!path || fileBusyId) return
    fileController.current?.abort()
    const controller = new AbortController()
    fileController.current = controller
    setFileBusyId(document.id)
    setFileError(null)
    setPreview(null)
    try {
      const file = await fetchRawMaterialDocumentFile(path, document.mediaType, document.filename, controller.signal)
      if (controller.signal.aborted || activeRequestKey.current !== requestKey) return
      const url = URL.createObjectURL(file.blob)
      if (action === 'preview') {
        setPreview({ key: requestKey, documentId: document.id, url, mediaType: file.mediaType, filename: file.filename })
      } else {
        const anchor = window.document.createElement('a')
        anchor.href = url
        anchor.download = file.filename
        anchor.rel = 'noopener'
        anchor.click()
        window.setTimeout(() => URL.revokeObjectURL(url), 0)
      }
    } catch (cause) {
      if (controller.signal.aborted || activeRequestKey.current !== requestKey) return
      if (!handleAccessFailure(cause)) setFileError(documentError(cause))
    } finally {
      if (activeRequestKey.current === requestKey) setFileBusyId(null)
    }
  }

  const currentListing = !materialId
    ? { key: requestKey, status: 'ready' as const, listing: { revisionId: '', documents: [] } }
    : listingState.key === requestKey ? listingState : { key: requestKey, status: 'loading' as const }
  const listedDocuments = currentListing.status === 'ready' ? currentListing.listing.documents : []
  const editable = mode !== 'read' && !disabled

  return (
    <section className="raw-documents" aria-labelledby={`raw-documents-title-${mode}`}>
      <h3 id={`raw-documents-title-${mode}`}>Dokumenty</h3>
      {mode === 'create' && (
        <p className="field-hint">Pliki są najpierw wysyłane do prywatnego stagingu i skanowane. Zostaną przypięte do rewizji dopiero po zapisaniu surowca.</p>
      )}
      {mode === 'edit' && (
        <p className="field-hint">Nowe pliki i odłączenie istniejących dokumentów zostaną zastosowane dopiero po zapisaniu nowej rewizji.</p>
      )}

      {editable && (
        <div className="raw-documents-upload">
          <label className="create-form-field" htmlFor={`raw-document-files-${mode}`}>
            Dodaj pliki
            <input
              id={`raw-document-files-${mode}`}
              type="file"
              multiple
              accept=".pdf,.png,.jpg,.jpeg,.txt,.csv"
              disabled={uploading}
              onChange={handleFileSelection}
            />
          </label>
          <p className="field-hint">PDF, PNG, JPEG, TXT lub CSV; maksymalnie 20 MiB na jedną partię. Wynik uploadu nie oznacza zatwierdzenia dokumentu.</p>
          {validationError && <p className="field-error" role="alert">{validationError}</p>}
          {uploadError && <p className="field-error" role="alert">{uploadError}</p>}
          {uploading && <p className="create-form-status" role="status">Wysyłanie i skanowanie plików przez AV…</p>}
          {uploads.length > 0 && (
            <ul className="raw-document-upload-list" aria-label="Pliki oczekujące na zapis">
              {uploads.map((row) => (
                <li key={row.key}>
                  <div>
                    <strong>{row.result?.filename ?? row.file.name}</strong>
                    <span>{formatBytes(row.result?.sizeBytes ?? row.file.size)} · Inny dokument</span>
                    {row.processing && <span role="status">Wysyłanie i skanowanie…</span>}
                    {!row.processing && row.result?.status === 'STAGED' && (
                      <span role="status">Skan AV czysty; oczekuje na zapis materiału i przypięcie do rewizji.</span>
                    )}
                    {!row.processing && row.result?.status === 'FAILED' && (
                      <span role="alert">Nie uzyskano czystego wyniku AV: {row.result.message}</span>
                    )}
                    {row.error && <span role="alert">{row.error}</span>}
                  </div>
                  <div className="raw-document-actions">
                    {!row.processing && (row.error || row.result?.status === 'FAILED') && (
                      <button type="button" className="button button-secondary" disabled={uploading}
                        onClick={() => void retryUpload(row)}>
                        {row.result?.uploadId ? 'Ponów skan' : 'Ponów upload'}
                      </button>
                    )}
                    <button type="button" className="button button-secondary" disabled={row.processing || uploading}
                      aria-label={`Usuń ${row.result?.filename ?? row.file.name} z kolejki`}
                      onClick={() => removeUpload(row.key)}>
                      Usuń z kolejki
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
          {uploads.some((row) => row.result?.uploadId) && (
            <p className="field-hint">Usunięcie pliku z kolejki nie kasuje stagingu na serwerze; nieprzypięty upload wygasa po godzinie.</p>
          )}
        </div>
      )}

      {materialId && (
        <>
          {currentListing.status === 'loading' && <p className="create-form-status" role="status">Wczytywanie dokumentów…</p>}
          {currentListing.status === 'forbidden' && <p className="field-error" role="alert">{currentListing.message}</p>}
          {currentListing.status === 'session-expired' && <p className="field-error" role="alert">{currentListing.message}</p>}
          {currentListing.status === 'unavailable' && <p className="field-error" role="alert">{currentListing.message}</p>}
          {currentListing.status === 'error' && <p className="field-error" role="alert">{currentListing.message}</p>}
          {currentListing.status === 'ready' && listedDocuments.length === 0 && (
            <p className="field-hint">Brak dokumentów przypiętych do tej rewizji.</p>
          )}
          {currentListing.status === 'ready' && listedDocuments.length > 0 && (
            <ul className="raw-document-list" aria-label="Dokumenty przypięte do rewizji">
              {listedDocuments.map((document) => {
                const detached = detachIds.includes(document.id)
                return (
                  <li key={document.id} className={detached ? 'is-detached' : undefined}>
                    <div className="raw-document-metadata">
                      <strong>{document.filename}</strong>
                      <span>{documentTypeLabels[document.documentType]} · {document.mediaType} · {formatBytes(document.sizeBytes)}</span>
                      <span role={document.status === 'INFECTED' || document.status === 'ERROR' ? 'alert' : 'status'}>
                        {documentStatusLabels[document.status]}
                      </span>
                      {detached && <span role="status">Odłączenie nastąpi po zapisaniu nowej rewizji.</span>}
                    </div>
                    <div className="raw-document-actions">
                      {document.previewUrl && (
                        <button type="button" className="button button-secondary" disabled={fileBusyId !== null}
                          onClick={() => void openDocument(document, 'preview')}>
                          {fileBusyId === document.id ? 'Wczytywanie…' : 'Podgląd'}
                        </button>
                      )}
                      {document.downloadUrl && (
                        <button type="button" className="button button-secondary" disabled={fileBusyId !== null}
                          onClick={() => void openDocument(document, 'download')}>
                          Pobierz
                        </button>
                      )}
                      {editable && mode === 'edit' && (
                        <button type="button" className="button button-secondary" disabled={disabled}
                          onClick={() => toggleDetached(document.id)}>
                          {detached ? 'Anuluj odłączenie' : 'Odłącz przy zapisie'}
                        </button>
                      )}
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
          {fileError && <p className="field-error" role="alert">{fileError}</p>}
          {preview?.key === requestKey && (
            <div className="raw-document-preview">
              <div className="raw-document-preview-heading">
                <strong>Podgląd: {preview.filename}</strong>
                <button type="button" className="button button-secondary" onClick={() => setPreview(null)}>Zamknij podgląd</button>
              </div>
              {preview.mediaType.startsWith('image/') ? (
                <img src={preview.url} alt={`Podgląd dokumentu ${preview.filename}`} />
              ) : (
                <iframe title={`Podgląd dokumentu ${preview.filename}`} src={preview.url} sandbox="" referrerPolicy="no-referrer" />
              )}
            </div>
          )}
        </>
      )}
    </section>
  )
}
