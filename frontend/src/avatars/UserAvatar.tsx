import { useState } from 'react'
import AvatarArtwork from './AvatarArtwork'
import type { AvatarInfo } from './avatarTypes'
import './avatars.css'

type Props = {
  firstName: string
  lastName: string
  avatar: AvatarInfo
  size?: 'small' | 'medium' | 'large'
  photoUrl?: string
  decorative?: boolean
}

export default function UserAvatar({
  firstName, lastName, avatar, size = 'medium', photoUrl, decorative = false,
}: Props) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  const source = photoUrl
  const initials = `${firstName.trim().charAt(0)}${lastName.trim().charAt(0)}`.toLocaleUpperCase('pl') || 'V'
  const label = decorative ? undefined : `Awatar: ${firstName} ${lastName}`

  return (
    <span className={`user-avatar user-avatar-${size}`} role={decorative ? undefined : 'img'} aria-label={label} aria-hidden={decorative}>
      {avatar.type === 'LIBRARY' && avatar.libraryId
        ? <AvatarArtwork id={avatar.libraryId} />
        : avatar.type === 'UPLOAD' && source && failedUrl !== source
          ? <img src={source} alt="" onError={() => setFailedUrl(source)} />
          : <span aria-hidden="true">{initials}</span>}
    </span>
  )
}
