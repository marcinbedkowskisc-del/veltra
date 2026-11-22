import { isRecord } from '../api'

export type AvatarType = 'INITIALS' | 'LIBRARY' | 'UPLOAD'
export type AvatarInfo = { type: AvatarType; libraryId: string | null; photoVersion?: string | null }

export const initialAvatar: AvatarInfo = { type: 'INITIALS', libraryId: null, photoVersion: null }

export function isLibraryAvatarId(value: unknown): value is string {
  return typeof value === 'string'
    && (/^portrait-(00[1-9]|0[1-3][0-9]|040)$/.test(value)
      || /^abstract-(00[1-9]|01[0-9]|02[0-4])$/.test(value))
}

export function parseAvatar(value: unknown): AvatarInfo {
  if (!isRecord(value) || (value.type !== 'INITIALS' && value.type !== 'LIBRARY' && value.type !== 'UPLOAD')) {
    throw new Error('Backend zwrócił nieprawidłowy awatar użytkownika.')
  }
  const libraryId = value.library_id
  const photoVersion = value.photo_version
  if (value.type === 'LIBRARY' && !isLibraryAvatarId(libraryId)) {
    throw new Error('Backend zwrócił nieprawidłowy awatar z galerii.')
  }
  if ((value.type === 'INITIALS' || value.type === 'UPLOAD') && libraryId !== null) {
    throw new Error('Backend zwrócił niespójny awatar użytkownika.')
  }
  if (value.type === 'UPLOAD' && (typeof photoVersion !== 'string' || !/^[0-9a-f]{64}$/.test(photoVersion))) {
    throw new Error('Backend zwrócił nieprawidłową wersję zdjęcia.')
  }
  if (value.type !== 'UPLOAD' && photoVersion !== null) {
    throw new Error('Backend zwrócił niespójny awatar użytkownika.')
  }
  return {
    type: value.type,
    libraryId: value.type === 'LIBRARY' && isLibraryAvatarId(libraryId) ? libraryId : null,
    photoVersion: value.type === 'UPLOAD' && typeof photoVersion === 'string' ? photoVersion : null,
  }
}

export function avatarFromResponse(value: Record<string, unknown>): AvatarInfo {
  return 'avatar' in value ? parseAvatar(value.avatar) : initialAvatar
}

export function avatarPhotoUrl(userId?: string, photoVersion?: string | null): string {
  const path = userId
    ? `/api/v1/administration/users/${encodeURIComponent(userId)}/avatar/photo`
    : '/api/v1/auth/avatar/photo'
  return photoVersion ? `${path}?v=${encodeURIComponent(photoVersion)}` : path
}
