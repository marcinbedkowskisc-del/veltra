import { lazy, Suspense, useEffect, useState } from 'react'
import { ApiError } from '../api'
import { resetAvatar, selectAvatar, uploadAvatar } from './avatarApi'
import { avatarPhotoUrl, isLibraryAvatarId, type AvatarInfo } from './avatarTypes'
import AvatarArtwork from './AvatarArtwork'
import UserAvatar from './UserAvatar'
import './avatars.css'

const AvatarCropper = lazy(() => import('./AvatarCropper'))

const libraryIds = [
  ...Array.from({ length: 40 }, (_, index) => `portrait-${String(index + 1).padStart(3, '0')}`),
  ...Array.from({ length: 24 }, (_, index) => `abstract-${String(index + 1).padStart(3, '0')}`),
]

type Props = {
  firstName: string
  lastName: string
  avatar: AvatarInfo
  userId?: string
  onSaved: () => void
  onSessionExpired?: () => void
}

export default function AvatarManagement({ firstName, lastName, avatar, userId, onSaved, onSessionExpired }: Props) {
  const [selection, setSelection] = useState<AvatarInfo>(avatar)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)
  const [croppedPhoto, setCroppedPhoto] = useState<Blob | null>(null)
  const [croppedUrl, setCroppedUrl] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl)
  }, [previewUrl])

  useEffect(() => () => {
    if (croppedUrl) URL.revokeObjectURL(croppedUrl)
  }, [croppedUrl])

  async function perform(action: () => Promise<void>, success: string, nextAvatar?: AvatarInfo) {
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await action()
      setMessage(success)
      setSelection(nextAvatar ?? selection)
      setPreviewUrl(null)
      setCroppedPhoto(null)
      setCroppedUrl(null)
      onSaved()
    } catch (cause) {
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired?.()
      setError(cause instanceof Error ? cause.message : 'Nie udało się zapisać awatara.')
    } finally {
      setBusy(false)
    }
  }

  function chooseFile(file: File | undefined) {
    setError(null)
    setMessage(null)
    setCroppedPhoto(null)
    setCroppedUrl(null)
    if (previewUrl) URL.revokeObjectURL(previewUrl)
    if (!file) {
      setPreviewUrl(null)
      return
    }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
      setPreviewUrl(null)
      setError('Wybierz zdjęcie JPG, PNG lub WebP o rozmiarze do 5 MB.')
      return
    }
    setPreviewUrl(URL.createObjectURL(file))
  }

  function setCropped(photo: Blob) {
    setCroppedPhoto(photo)
    setCroppedUrl(URL.createObjectURL(photo))
  }

  const previewAvatar: AvatarInfo = croppedPhoto ? { type: 'UPLOAD', libraryId: null } : selection

  return (
    <section className="avatar-management" aria-label="Zarządzanie awatarem">
      <div className="avatar-management-current">
        <UserAvatar firstName={firstName} lastName={lastName}
          avatar={previewAvatar}
          photoUrl={croppedUrl ?? (previewAvatar.type === 'UPLOAD' ? avatarPhotoUrl(userId, previewAvatar.photoVersion) : undefined)}
          size="large" />
        <div>
          <h4>Awatar użytkownika</h4>
          <p>Wybierz ilustrację, dodaj zdjęcie albo przywróć inicjały.</p>
        </div>
      </div>
      {error && <p role="alert" className="error">{error}</p>}
      {message && <p role="status" className="success">{message}</p>}

      <fieldset className="avatar-gallery">
        <legend>Galeria 64 awatarów</legend>
        <div className="avatar-gallery-grid">
          {libraryIds.map((id) => (
            <button type="button" key={id} className="avatar-gallery-option" aria-label={`Wybierz awatar ${id}`}
              aria-pressed={selection.type === 'LIBRARY' && selection.libraryId === id}
              disabled={busy}
              onClick={() => isLibraryAvatarId(id) && setSelection({ type: 'LIBRARY', libraryId: id })}>
              <AvatarArtwork id={id} />
            </button>
          ))}
        </div>
      </fieldset>
      <div className="avatar-management-actions">
        <button type="button" className="button button-primary" disabled={busy || selection.type !== 'LIBRARY'}
          onClick={() => void perform(() => selectAvatar(selection, userId), 'Zapisano awatar z galerii.', selection)}>
          Zapisz wybrany awatar
        </button>
        <button type="button" className="button button-secondary" disabled={busy || avatar.type === 'INITIALS'}
          onClick={() => void perform(() => resetAvatar(userId), 'Przywrócono awatar z inicjałami.', { type: 'INITIALS', libraryId: null })}>
          Przywróć inicjały
        </button>
      </div>

      <div className="avatar-upload">
        <h4>Zdjęcie profilowe</h4>
        <label>Wybierz zdjęcie
          <input type="file" accept="image/jpeg,image/png,image/webp" disabled={busy}
            onChange={(event) => chooseFile(event.target.files?.[0])} />
        </label>
        {previewUrl && <Suspense fallback={<p role="status">Przygotowywanie narzędzia przycinania…</p>}>
          <AvatarCropper image={previewUrl} onCropped={setCropped} />
        </Suspense>}
        {croppedPhoto && <button type="button" className="button button-primary" disabled={busy}
          onClick={() => void perform(() => uploadAvatar(croppedPhoto, userId), 'Zapisano zdjęcie profilowe.', { type: 'UPLOAD', libraryId: null })}>
          Zapisz przycięte zdjęcie
        </button>}
      </div>
    </section>
  )
}
