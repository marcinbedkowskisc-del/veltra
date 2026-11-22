import { request } from '../api'
import type { AvatarInfo } from './avatarTypes'

function endpoint(userId?: string): string {
  return userId
    ? `/api/v1/administration/users/${encodeURIComponent(userId)}/avatar`
    : '/api/v1/auth/avatar'
}

export async function selectAvatar(avatar: AvatarInfo, userId?: string): Promise<void> {
  await request(endpoint(userId), 'PUT', {
    type: avatar.type === 'LIBRARY' ? 'LIBRARY' : 'INITIALS',
    ...(avatar.type === 'LIBRARY' ? { library_id: avatar.libraryId } : {}),
  })
}

export async function uploadAvatar(photo: Blob, userId?: string): Promise<void> {
  const form = new FormData()
  form.append('photo', photo, 'avatar.webp')
  await request(`${endpoint(userId)}/photo`, 'POST', form)
}

export async function resetAvatar(userId?: string): Promise<void> {
  await request(endpoint(userId), 'DELETE', {})
}
