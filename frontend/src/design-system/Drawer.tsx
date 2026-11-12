import {
  useCallback,
  useEffect,
  useRef,
  type CSSProperties,
  type ReactNode,
  type RefObject,
} from 'react'

const TRANSITION_MS = 250

type Props = {
  labelledBy: string
  children: ReactNode | ((requestClose: () => void) => ReactNode)
  overlay?: ReactNode
  onClose: () => void
  onCloseStart?: () => void
  returnFocus?: HTMLElement | null
  initialFocusRef?: RefObject<HTMLElement | null>
  width?: string
  className?: string
  suspendFocusTrap?: boolean
}

export default function Drawer({
  labelledBy,
  children,
  onClose,
  onCloseStart,
  returnFocus,
  initialFocusRef,
  width = 'min(65vw, 1150px)',
  className = '',
  suspendFocusTrap = false,
  overlay,
}: Props) {
  const backdropRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLElement>(null)
  const closeTimer = useRef<number | null>(null)
  const closing = useRef(false)
  const open = useRef(false)
  const returnFocusTarget = useRef(returnFocus)
  const callbacks = useRef({ onClose, onCloseStart })

  useEffect(() => {
    returnFocusTarget.current = returnFocus
    callbacks.current = { onClose, onCloseStart }
  }, [onClose, onCloseStart, returnFocus])

  useEffect(() => {
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    let frame: number | null = null
    if (reducedMotion) {
      open.current = true
      panelRef.current?.closest('.ui-drawer-backdrop')?.classList.add('is-open')
    } else {
      frame = window.requestAnimationFrame(() => {
        if (closing.current) return
        open.current = true
        panelRef.current?.closest('.ui-drawer-backdrop')?.classList.add('is-open')
      })
    }

    return () => {
      if (frame !== null) window.cancelAnimationFrame(frame)
      if (closeTimer.current !== null) window.clearTimeout(closeTimer.current)
      document.body.style.overflow = previousOverflow
      const target = returnFocusTarget.current
      if (target?.isConnected) target.focus()
    }
  }, [])

  useEffect(() => {
    initialFocusRef?.current?.focus()
    if (!initialFocusRef?.current) {
      panelRef.current?.querySelector<HTMLElement>(
        'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])',
      )?.focus()
    }
  }, [initialFocusRef])

  const requestClose = useCallback(() => {
    if (closing.current) return
    closing.current = true
    callbacks.current.onCloseStart?.()
    open.current = false
    panelRef.current?.closest('.ui-drawer-backdrop')?.classList.remove('is-open')
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    closeTimer.current = window.setTimeout(
      () => callbacks.current.onClose(),
      reducedMotion ? 0 : TRANSITION_MS,
    )
  }, [])

  useEffect(() => {
    const backdrop = backdropRef.current
    if (!backdrop) return

    function handleKeyDown(event: globalThis.KeyboardEvent) {
      if (suspendFocusTrap || closing.current) return
      if (event.key === 'Escape') {
        event.preventDefault()
        requestClose()
        return
      }
      if (event.key !== 'Tab') return

      const focusable = panelRef.current?.querySelectorAll<HTMLElement>(
        'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])',
      )
      if (!focusable?.length) {
        event.preventDefault()
        panelRef.current?.focus()
        return
      }
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && (document.activeElement === first || !panelRef.current?.contains(document.activeElement))) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && (document.activeElement === last || !panelRef.current?.contains(document.activeElement))) {
        event.preventDefault()
        first.focus()
      }
    }

    backdrop.addEventListener('keydown', handleKeyDown)
    return () => backdrop.removeEventListener('keydown', handleKeyDown)
  }, [requestClose, suspendFocusTrap])

  // oxlint-disable-next-line react/refs -- The render prop forwards this callback into user-triggered event handlers.
  const drawerChildren = typeof children === 'function' ? children(requestClose) : children

  return (
    <div
      ref={backdropRef}
      className="ui-drawer-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) requestClose()
      }}
    >
      <section
        ref={panelRef}
        className={`ui-drawer-panel ${className}`.trim()}
        style={{ '--ui-drawer-width': width } as CSSProperties}
        role="dialog"
        aria-modal="true"
        aria-labelledby={labelledBy}
        tabIndex={-1}
      >
        {drawerChildren}
      </section>
      {overlay}
    </div>
  )
}
