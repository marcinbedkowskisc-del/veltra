import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AnimatedPanel from './AnimatedPanel'
import VeltraDisclosure from './VeltraDisclosure'

const originalMatchMedia = Object.getOwnPropertyDescriptor(window, 'matchMedia')

describe('VeltraDisclosure', () => {
  afterEach(() => {
    cleanup()
    vi.useRealTimers()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    if (originalMatchMedia) Object.defineProperty(window, 'matchMedia', originalMatchMedia)
    else Reflect.deleteProperty(window, 'matchMedia')
  })

  it('keeps the panel accessible and announces its expanded state', () => {
    const { getByRole } = render(<VeltraDisclosure title="Informacje o źródle"><p>Źródło danych</p></VeltraDisclosure>)
    const trigger = getByRole('button', { name: 'Informacje o źródle' })
    const panelId = trigger.getAttribute('aria-controls')
    const panel = panelId ? document.getElementById(panelId) : null

    expect(trigger.getAttribute('aria-expanded')).toBe('false')
    expect(panel?.getAttribute('aria-hidden')).toBe('true')
    expect(panel?.hasAttribute('inert')).toBe(true)

    fireEvent.click(trigger)

    expect(trigger.getAttribute('aria-expanded')).toBe('true')
    expect(panel?.getAttribute('aria-hidden')).toBe('false')
    expect(getByRole('region', { name: 'Informacje o źródle' }).textContent).toContain('Źródło danych')
  })

  it('keeps rapid repeated toggles responsive without a stale close timer', () => {
    vi.useFakeTimers()
    const { getByRole } = render(<VeltraDisclosure title="Informacje o źródle"><p>Źródło danych</p></VeltraDisclosure>)

    const trigger = getByRole('button', { name: 'Informacje o źródle' })
    const panelId = trigger.getAttribute('aria-controls')
    const panel = panelId ? document.getElementById(panelId) : null

    fireEvent.click(trigger)
    fireEvent.click(trigger)
    fireEvent.click(trigger)

    expect(trigger.getAttribute('aria-expanded')).toBe('true')
    act(() => vi.advanceTimersByTime(300))
    expect(panel?.getAttribute('aria-hidden')).toBe('false')
  })

  it('removes closing content promptly when reduced motion is requested', () => {
    vi.useFakeTimers()
    const matchMedia = vi.fn(() => ({
      matches: true,
      media: '(prefers-reduced-motion: reduce)',
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }))
    Object.defineProperty(window, 'matchMedia', { configurable: true, value: matchMedia })
    function Harness() {
      const [open, setOpen] = useState(true)
      return <>
        <button type="button" onClick={() => setOpen(false)}>Close panel</button>
        <AnimatedPanel id="reduced-motion-panel" open={open}>
          {open && <p>Źródło danych</p>}
        </AnimatedPanel>
      </>
    }
    const { getByRole } = render(<Harness />)
    const panel = document.getElementById('reduced-motion-panel')
    fireEvent.click(getByRole('button', { name: 'Close panel' }))

    expect(panel?.textContent).toContain('Źródło danych')
    expect(matchMedia).toHaveBeenCalledWith('(prefers-reduced-motion: reduce)')
    act(() => vi.advanceTimersByTime(2))
    expect(panel?.textContent).not.toContain('Źródło danych')
    expect(panel?.getAttribute('aria-hidden')).toBe('true')
  })
})
