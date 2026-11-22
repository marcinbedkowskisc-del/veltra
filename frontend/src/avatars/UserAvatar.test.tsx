import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import UserAvatar from './UserAvatar'
import { avatarPhotoUrl, parseAvatar } from './avatarTypes'

afterEach(cleanup)

describe('UserAvatar', () => {
  it('renders initials and a bundled gallery illustration accessibly', () => {
    const { rerender } = render(<UserAvatar firstName="Jan" lastName="Test" avatar={{ type: 'INITIALS', libraryId: null }} />)
    expect(screen.getByRole('img', { name: 'Awatar: Jan Test' }).textContent).toBe('JT')

    rerender(<UserAvatar firstName="Jan" lastName="Test" avatar={{ type: 'LIBRARY', libraryId: 'portrait-001' }} />)
    expect(screen.getByRole('img', { name: 'Awatar: Jan Test' }).querySelector('svg')).toBeTruthy()
  })

  it('falls back to initials when the private photo endpoint cannot load', () => {
    render(<UserAvatar firstName="Jan" lastName="Test" avatar={{ type: 'UPLOAD', libraryId: null }} photoUrl="/private/avatar" />)
    const image = screen.getByRole('img', { name: 'Awatar: Jan Test' }).querySelector('img')
    expect(image?.getAttribute('src')).toBe('/private/avatar')
    fireEvent.error(image!)
    expect(screen.getByRole('img', { name: 'Awatar: Jan Test' }).textContent).toBe('JT')
  })

  it('rejects inconsistent API avatar state', () => {
    expect(() => parseAvatar({ type: 'UPLOAD', library_id: 'portrait-001' })).toThrow('niespójny awatar')
    expect(() => parseAvatar({ type: 'LIBRARY', library_id: 'portrait-999' })).toThrow('nieprawidłowy awatar z galerii')
  })

  it('uses a non-sensitive photo revision to refresh private image URLs after replacement', () => {
    const revision = 'a'.repeat(64)
    expect(avatarPhotoUrl(undefined, revision)).toBe(`/api/v1/auth/avatar/photo?v=${revision}`)
    expect(avatarPhotoUrl('user-1', revision)).toBe(`/api/v1/administration/users/user-1/avatar/photo?v=${revision}`)
  })
})
