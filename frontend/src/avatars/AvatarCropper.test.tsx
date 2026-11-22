import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AvatarCropper from './AvatarCropper'

vi.mock('react-easy-crop', () => ({
  default: ({ onCropComplete }: { onCropComplete: (area: { x: number; y: number; width: number; height: number }, croppedArea: { x: number; y: number; width: number; height: number }) => void }) => (
    <button type="button" onClick={() => onCropComplete(
      { x: 0, y: 0, width: 40, height: 40 },
      { x: 10, y: 20, width: 40, height: 40 },
    )}>Ustaw obszar</button>
  ),
}))

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('AvatarCropper', () => {
  it('exports the selected square crop as a WebP blob for server-side normalization', async () => {
    const drawImage = vi.fn()
    const context = Object.assign(Object.create(null), { drawImage }) as CanvasRenderingContext2D
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context)
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => {
      callback(new Blob(['cropped bytes'], { type: 'image/webp' }))
    })
    vi.stubGlobal('Image', class {
      src = ''
      decode = vi.fn().mockResolvedValue(undefined)
    })
    const onCropped = vi.fn()
    render(<AvatarCropper image="blob:upload" onCropped={onCropped} />)

    fireEvent.click(screen.getByRole('button', { name: 'Ustaw obszar' }))
    fireEvent.click(screen.getByRole('button', { name: 'Przytnij zdjęcie' }))

    await vi.waitFor(() => expect(onCropped).toHaveBeenCalledOnce())
    expect(drawImage).toHaveBeenCalledWith(expect.anything(), 10, 20, 40, 40, 0, 0, 512, 512)
    expect(onCropped.mock.calls[0][0]).toBeInstanceOf(Blob)
  })
})
