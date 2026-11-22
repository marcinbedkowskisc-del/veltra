import { isLibraryAvatarId } from './avatarTypes'

const backgrounds = ['#dbeafe', '#fce7d2', '#e9d5ff', '#d1fae5', '#fde2e8', '#cffafe', '#fef3c7', '#e2e8f0']
const skins = ['#f3c9a9', '#c98c65', '#e6b38d', '#9d6248', '#f0d2b7', '#ad7658', '#d9a07b', '#80523e']
const hair = ['#27364a', '#593d32', '#b77b35', '#28232b', '#d5b98d', '#563c59', '#8a382f', '#3d5454']
const clothes = ['#27597a', '#82529b', '#39866e', '#bd5b57', '#40518a', '#ad7635', '#426b70', '#665477']

function artworkIndex(id: string): number {
  return Number(id.slice(-3))
}

function Portrait({ index }: { index: number }) {
  const background = backgrounds[(index * 3) % backgrounds.length]
  const skinTone = skins[(index * 5 + Math.floor(index / 3)) % skins.length]
  const hairTone = hair[(index * 7 + 2) % hair.length]
  const clothing = clothes[(index * 3 + 1) % clothes.length]
  const hairStyle = index % 5

  return (
    <svg viewBox="0 0 100 100" aria-hidden="true">
      <circle cx="50" cy="50" r="50" fill={background} />
      <path d="M12 100c2-20 15-31 38-31s36 11 38 31" fill={clothing} />
      <path d="M40 65h20v14H40z" fill={skinTone} />
      <ellipse cx="50" cy="45" rx="23" ry="28" fill={skinTone} />
      {hairStyle === 0 && <path d="M27 44c-3-24 8-34 25-34 19 0 26 15 21 34l-7-10c-12 3-24 1-35-5z" fill={hairTone} />}
      {hairStyle === 1 && <path d="M26 44c-1-20 8-31 24-31 19 0 26 13 24 31l-4 18-7-17-24-2-8 20z" fill={hairTone} />}
      {hairStyle === 2 && <path d="M27 39c0-17 9-27 23-27 14 0 24 9 24 27-9-8-16-11-25-10-9 0-15 3-22 10z" fill={hairTone} />}
      {hairStyle === 3 && <path d="M25 42c0-24 9-35 25-35s26 11 26 35l-8 20-3-21c-11 5-23 5-34 0l-2 21z" fill={hairTone} />}
      {hairStyle === 4 && <path d="M28 43c-4-17 5-31 22-31 18 0 27 13 23 31-6-4-9-10-11-17-9 9-20 13-34 17z" fill={hairTone} />}
      <path d="M39 48h3m16 0h3" stroke="#283342" strokeLinecap="round" strokeWidth="2.5" />
      <path d="M45 59c3 2 7 2 10 0" fill="none" stroke="#9f524a" strokeLinecap="round" strokeWidth="2" />
      {index % 4 === 0 && <path d="M31 47h13m12 0h13" stroke="#35495f" strokeWidth="2" />}
      {index % 6 === 0 && <circle cx="50" cy="74" r="3" fill="#e6c45b" />}
    </svg>
  )
}

function Abstract({ index }: { index: number }) {
  const background = backgrounds[(index * 5) % backgrounds.length]
  const primary = clothes[(index * 7) % clothes.length]
  const accent = hair[(index * 3 + 4) % hair.length]
  const offset = (index * 11) % 38
  const kind = index % 4

  return (
    <svg viewBox="0 0 100 100" aria-hidden="true">
      <circle cx="50" cy="50" r="50" fill={background} />
      {kind === 0 && <><path d={`M${offset} 100V45Q${offset + 2} 8 ${offset + 35} 12Q${offset + 66} 14 100 52v48z`} fill={primary} /><circle cx="67" cy="37" r="15" fill={accent} /></>}
      {kind === 1 && <><path d={`M0 ${offset + 15}Q50 ${offset - 30} 100 ${offset + 15}v55Q50 40 0 70z`} fill={primary} /><circle cx="50" cy="50" r="22" fill={accent} /><circle cx="50" cy="50" r="9" fill={background} /></>}
      {kind === 2 && <><path d="M50 8 91 50 50 92 9 50z" fill={primary} /><circle cx={28 + (index % 5) * 10} cy={30 + (index % 4) * 10} r="14" fill={accent} /><path d="M18 82 82 18" stroke={background} strokeWidth="7" /></>}
      {kind === 3 && <><circle cx={30 + (index % 4) * 10} cy="38" r="25" fill={primary} /><circle cx="68" cy="64" r="27" fill={accent} /><path d="M13 78 87 22" stroke={background} strokeWidth="8" /></>}
    </svg>
  )
}

export default function AvatarArtwork({ id }: { id: string }) {
  if (!isLibraryAvatarId(id)) return null
  const index = artworkIndex(id)
  return id.startsWith('portrait-')
    ? <Portrait index={index} />
    : <Abstract index={index} />
}
