import { useId, useState, type ReactNode } from 'react'
import AnimatedPanel from './AnimatedPanel'

export type AccordionItem = {
  id: string
  title: string
  content: ReactNode
  icon?: ReactNode
  disabled?: boolean
  active?: boolean
}

type Props = {
  items: AccordionItem[]
  mode?: 'multiple' | 'single'
  expandedIds?: string[]
  defaultExpandedIds?: string[]
  onExpandedChange?: (ids: string[]) => void
  className?: string
}

function AccordionSection({ item, expanded, onToggle }: { item: AccordionItem; expanded: boolean; onToggle: () => void }) {
  const instanceId = useId()
  const buttonId = `${instanceId}-button`
  const panelId = `${instanceId}-panel`

  return (
    <section className="ui-accordion-item">
      <h2 className="ui-accordion-heading">
        <button
          type="button"
          id={buttonId}
          className="ui-accordion-trigger"
          aria-expanded={expanded}
          aria-controls={panelId}
          aria-describedby={item.active ? `${instanceId}-active` : undefined}
          data-active={item.active || undefined}
          disabled={item.disabled}
          onClick={onToggle}
        >
          {item.icon && <span className="ui-accordion-icon" aria-hidden="true">{item.icon}</span>}
          <span className="ui-accordion-title">{item.title}</span>
          <svg className="ui-accordion-chevron" viewBox="0 0 16 16" aria-hidden="true">
            <path d="m6 3 5 5-5 5" />
          </svg>
        </button>
        {item.active && <span id={`${instanceId}-active`} className="ui-accordion-sr-only">Zawiera bieżącą stronę</span>}
      </h2>
      <AnimatedPanel
        id={panelId}
        open={expanded}
        role="region"
        ariaLabelledBy={buttonId}
        className="ui-accordion-panel"
        contentClassName="ui-accordion-content"
      >
        {item.content}
      </AnimatedPanel>
    </section>
  )
}

export default function VeltraAccordion({
  items,
  mode = 'multiple',
  expandedIds,
  defaultExpandedIds = [],
  onExpandedChange,
  className = '',
}: Props) {
  const [uncontrolledIds, setUncontrolledIds] = useState(() => (
    mode === 'single' ? defaultExpandedIds.slice(0, 1) : defaultExpandedIds
  ))
  const ids = expandedIds ?? uncontrolledIds
  const currentIds = mode === 'single' ? ids.slice(0, 1) : ids

  function toggle(id: string) {
    const next = currentIds.includes(id)
      ? currentIds.filter((current) => current !== id)
      : mode === 'single' ? [id] : [...currentIds, id]
    if (expandedIds === undefined) setUncontrolledIds(next)
    onExpandedChange?.(next)
  }

  return (
    <div className={`ui-accordion ${className}`.trim()}>
      {items.map((item) => (
        <AccordionSection key={item.id} item={item} expanded={currentIds.includes(item.id)} onToggle={() => toggle(item.id)} />
      ))}
    </div>
  )
}
