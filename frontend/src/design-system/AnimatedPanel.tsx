import { useLayoutEffect, useRef, useState, type ReactNode } from 'react'

type Props = {
  id: string
  open: boolean
  children: ReactNode
  className?: string
  contentClassName?: string
  role?: React.AriaRole
  ariaLabelledBy?: string
}

export default function AnimatedPanel({
  id,
  open,
  children,
  className = '',
  contentClassName = '',
  role,
  ariaLabelledBy,
}: Props) {
  const panelRef = useRef<HTMLDivElement>(null)
  const contentRef = useRef<HTMLDivElement>(null)
  const lastOpenChildren = useRef<ReactNode>(children)
  const wasOpen = useRef(open)
  const [closingChildren, setClosingChildren] = useState<ReactNode>(null)
  const closeTimer = useRef<number | null>(null)

  useLayoutEffect(() => {
    if (closeTimer.current !== null) {
      window.clearTimeout(closeTimer.current)
      closeTimer.current = null
    }

    if (open) {
      lastOpenChildren.current = children
      wasOpen.current = true
      return
    }
    if (!wasOpen.current || lastOpenChildren.current === null) return

    wasOpen.current = false
    // Keep outgoing content mounted until its height transition has completed.
    setClosingChildren(lastOpenChildren.current)
    const reducedMotion = typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches
    closeTimer.current = window.setTimeout(
      () => setClosingChildren(null),
      reducedMotion ? 1 : 280,
    )
    return () => {
      if (closeTimer.current !== null) window.clearTimeout(closeTimer.current)
    }
  }, [open, children])

  const renderedChildren = open ? children : closingChildren ?? children

  useLayoutEffect(() => {
    const panel = panelRef.current
    const content = contentRef.current
    if (!panel || !content) return

    function updateHeight() {
      if (panel && content) panel.style.height = open ? `${content.getBoundingClientRect().height}px` : '0px'
    }

    updateHeight()
    if (!open || typeof ResizeObserver === 'undefined') return

    const observer = new ResizeObserver(updateHeight)
    observer.observe(content)
    return () => observer.disconnect()
  }, [open, renderedChildren])

  return (
    <div
      id={id}
      ref={panelRef}
      role={role}
      aria-labelledby={ariaLabelledBy}
      aria-hidden={!open}
      inert={!open}
      data-open={open}
      className={`ui-animated-panel ${className}`.trim()}
    >
      <div ref={contentRef} className={`ui-animated-panel-content ${contentClassName}`.trim()}>
        {renderedChildren}
      </div>
    </div>
  )
}
