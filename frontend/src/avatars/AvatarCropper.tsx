import { useState } from 'react'
import Cropper from 'react-easy-crop'
import type { Area, Point } from 'react-easy-crop'
import 'react-easy-crop/react-easy-crop.css'

export default function AvatarCropper({
  image,
  onCropped,
}: {
  image: string
  onCropped: (photo: Blob) => void
}) {
  const [crop, setCrop] = useState<Point>({ x: 0, y: 0 })
  const [zoom, setZoom] = useState(1)
  const [area, setArea] = useState<Area | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function cropImage() {
    if (!area) return
    setError(null)
    try {
      const source = new Image()
      source.src = image
      await source.decode()
      const canvas = document.createElement('canvas')
      canvas.width = 512
      canvas.height = 512
      const context = canvas.getContext('2d')
      if (!context) throw new Error('Przeglądarka nie obsługuje przycinania zdjęć.')
      context.drawImage(source, area.x, area.y, area.width, area.height, 0, 0, 512, 512)
      const photo = await new Promise<Blob>((resolve, reject) => {
        canvas.toBlob((result) => result ? resolve(result) : reject(new Error('Nie można przygotować zdjęcia.')), 'image/webp', 0.9)
      })
      onCropped(photo)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Nie można przygotować zdjęcia.')
    }
  }

  return (
    <div className="avatar-cropper">
      <div className="avatar-crop-area" aria-label="Obszar przycinania zdjęcia">
        <Cropper
          image={image}
          crop={crop}
          zoom={zoom}
          aspect={1}
          cropShape="round"
          showGrid={false}
          onCropChange={setCrop}
          onZoomChange={setZoom}
          onCropComplete={(_area, croppedArea) => setArea(croppedArea)}
        />
      </div>
      <label>Powiększenie
        <input aria-label="Powiększenie zdjęcia" type="range" min="1" max="3" step="0.05" value={zoom}
          onChange={(event) => setZoom(Number(event.target.value))} />
      </label>
      {error && <p className="error" role="alert">{error}</p>}
      <button type="button" className="button button-secondary" disabled={!area} onClick={() => void cropImage()}>
        Przytnij zdjęcie
      </button>
    </div>
  )
}
