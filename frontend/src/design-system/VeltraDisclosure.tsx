import { useId, useState, type ReactNode } from 'react'
import AnimatedPanel from './AnimatedPanel'

type Props = {
  title: string
  children: ReactNode
  className?: string
}

export default function VeltraDisclosure({ title, children, className = '' }: Props) {
  const id = useId()
  const triggerId = `${id}-trigger`
  const panelId = `${id}-panel`
  const [open, setOpen] = useState(false)

  return (
    <section className={`ui-disclosure ${className}`.trim()}>
      <h3 className="ui-disclosure-heading">
        <button
          type="button"
          id={triggerId}
          className="ui-disclosure-trigger"
          aria-expanded={open}
          aria-controls={panelId}
          onClick={() => setOpen((current) => !current)}
        >
          <span>{title}</span>
          <svg className="ui-disclosure-chevron" viewBox="0 0 16 16" aria-hidden="true">
            <path d="m6 3 5 5-5 5" />
          </svg>
        </button>
      </h3>
      <AnimatedPanel id={panelId} open={open} role="region" ariaLabelledBy={triggerId} className="ui-disclosure-panel">
        {children}
      </AnimatedPanel>
    </section>
  )
}
