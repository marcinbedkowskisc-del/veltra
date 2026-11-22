import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AvatarManagement from './AvatarManagement'
import type { AvatarInfo } from './avatarTypes'

vi.mock('./AvatarCropper', () => ({
  default: ({ onCropped }: { onCropped: (photo: Blob) => void }) => (
    <button type="button" onClick={() => onCropped(new Blob(['cropped'], { type: 'image/webp' }))}>Przytnij testowo</button>
  ),
}))

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } })
}

afterEach(() => {
  cleanup()
  restoreObjectUrlMethods()
  vi.unstubAllGlobals()
})

let originalCreateObjectURL = Object.getOwnPropertyDescriptor(URL, 'createObjectURL')
let originalRevokeObjectURL = Object.getOwnPropertyDescriptor(URL, 'revokeObjectURL')

function stubObjectUrlMethods() {
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn().mockReturnValue('blob:source') })
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() })
}

function restoreObjectUrlMethods() {
  if (originalCreateObjectURL) Object.defineProperty(URL, 'createObjectURL', originalCreateObjectURL)
  else Reflect.deleteProperty(URL, 'createObjectURL')
  if (originalRevokeObjectURL) Object.defineProperty(URL, 'revokeObjectURL', originalRevokeObjectURL)
  else Reflect.deleteProperty(URL, 'revokeObjectURL')
}

describe('AvatarManagement', () => {
  it('shows all bundled choices, saves a library selection and does not fall back to RAW endpoints', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ data: null }))
    const onSaved = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    render(<AvatarManagement firstName="Jan" lastName="Test" avatar={{ type: 'INITIALS', libraryId: null }} onSaved={onSaved} />)

    expect(screen.getAllByRole('button', { name: /Wybierz awatar/ })).toHaveLength(64)
    fireEvent.click(screen.getByRole('button', { name: 'Wybierz awatar portrait-001' }))
    expect(screen.getByRole('button', { name: 'Wybierz awatar portrait-001' }).getAttribute('aria-pressed')).toBe('true')
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz wybrany awatar' }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/auth/avatar')
    expect(JSON.parse(fetchMock.mock.calls[0][1].body as string)).toEqual({ type: 'LIBRARY', library_id: 'portrait-001' })
    expect(String(fetchMock.mock.calls[0][0])).not.toContain('raw-materials')
    expect(onSaved).toHaveBeenCalledOnce()
  })

  it('uses the administrator-scoped private photo URL for the selected target user', () => {
    render(<AvatarManagement firstName="Jan" lastName="Target" userId="user-target"
      avatar={{ type: 'UPLOAD', libraryId: null }} onSaved={vi.fn()} />)
    const image = screen.getByRole('img', { name: 'Awatar: Jan Target' }).querySelector('img')
    expect(image?.getAttribute('src')).toBe('/api/v1/administration/users/user-target/avatar/photo')
  })

  it('crops a selected photo before sending multipart data and supports user switching', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ data: null }))
    vi.stubGlobal('fetch', fetchMock)
    stubObjectUrlMethods()
    const avatar: AvatarInfo = { type: 'INITIALS', libraryId: null }
    const { rerender } = render(<AvatarManagement key="user-a" userId="user-a" firstName="Jan" lastName="A"
      avatar={avatar} onSaved={vi.fn()} />)
    fireEvent.change(screen.getByLabelText('Wybierz zdjęcie'), {
      target: { files: [new File(['source'], 'photo.png', { type: 'image/png' })] },
    })
    fireEvent.click(await screen.findByRole('button', { name: 'Przytnij testowo' }))
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz przycięte zdjęcie' }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/administration/users/user-a/avatar/photo')
    const request = fetchMock.mock.calls[0][1] as RequestInit
    expect(request.body).toBeInstanceOf(FormData)
    expect((request.body as FormData).get('photo')).toBeInstanceOf(File)
    expect(request.headers).not.toHaveProperty('Content-Type')

    rerender(<AvatarManagement key="user-b" userId="user-b" firstName="Anna" lastName="B"
      avatar={avatar} onSaved={vi.fn()} />)
    fireEvent.click(screen.getByRole('button', { name: 'Wybierz awatar abstract-001' }))
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz wybrany awatar' }))
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/administration/users/user-b/avatar')
  })

  it.each([
    [401, 'onSessionExpired'],
    [419, 'onSessionExpired'],
    [403, 'denied'],
    [503, 'unavailable'],
  ])('handles HTTP %i without success-shaped fallback (%s)', async (status, expectation) => {
    const fetchMock = vi.fn().mockResolvedValue(json({ message: 'Unavailable' }, status))
    const onSessionExpired = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    render(<AvatarManagement firstName="Jan" lastName="Test" avatar={{ type: 'INITIALS', libraryId: null }}
      onSaved={vi.fn()} onSessionExpired={onSessionExpired} />)
    fireEvent.click(screen.getByRole('button', { name: 'Wybierz awatar portrait-002' }))
    fireEvent.click(screen.getByRole('button', { name: 'Zapisz wybrany awatar' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(onSessionExpired).toHaveBeenCalledTimes(expectation === 'onSessionExpired' ? 1 : 0)
  })

  it('rejects unsupported files before creating a preview or request', () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    stubObjectUrlMethods()
    render(<AvatarManagement firstName="Jan" lastName="Test" avatar={{ type: 'INITIALS', libraryId: null }} onSaved={vi.fn()} />)
    fireEvent.change(screen.getByLabelText('Wybierz zdjęcie'), {
      target: { files: [new File(['bad'], 'vector.svg', { type: 'image/svg+xml' })] },
    })
    expect(screen.getByRole('alert').textContent).toContain('do 5 MB')
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
