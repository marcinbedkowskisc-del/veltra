import { useId, useRef, type KeyboardEvent, type ReactNode } from 'react'

export type TabItem = {
  id: string
  label: string
  content: ReactNode
}

type Props = {
  tabs: TabItem[]
  value: string
  onChange: (id: string) => void
  label: string
  className?: string
}

export default function Tabs({ tabs, value, onChange, label, className = '' }: Props) {
  const tabRefs = useRef(new Map<string, HTMLButtonElement>())
  const instanceId = useId()
  const activeTab = tabs.find((tab) => tab.id === value) ?? tabs[0]
  if (!activeTab) return null

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    const currentIndex = tabs.findIndex((tab) => tab.id === activeTab.id)
    let nextIndex: number | null = null
    if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % tabs.length
    if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + tabs.length) % tabs.length
    if (event.key === 'Home') nextIndex = 0
    if (event.key === 'End') nextIndex = tabs.length - 1
    if (nextIndex === null) return
    event.preventDefault()
    const nextTab = tabs[nextIndex]
    onChange(nextTab.id)
    tabRefs.current.get(nextTab.id)?.focus()
  }

  return (
    <div className={`ui-tabs ${className}`.trim()}>
      <div className="ui-tab-list" role="tablist" aria-label={label} onKeyDown={handleKeyDown}>
        {tabs.map((tab) => {
          const selected = tab.id === activeTab.id
          return (
            <button
              key={tab.id}
              ref={(node) => {
                if (node) tabRefs.current.set(tab.id, node)
                else tabRefs.current.delete(tab.id)
              }}
              type="button"
              id={`${instanceId}-${tab.id}-tab`}
              role="tab"
              aria-selected={selected}
              aria-controls={`${instanceId}-${tab.id}-panel`}
              tabIndex={selected ? 0 : -1}
              className="ui-tab"
              onClick={() => onChange(tab.id)}
            >
              {tab.label}
            </button>
          )
        })}
      </div>
      <section
        id={`${instanceId}-${activeTab.id}-panel`}
        role="tabpanel"
        aria-labelledby={`${instanceId}-${activeTab.id}-tab`}
        className="ui-tab-panel"
        tabIndex={0}
      >
        {activeTab.content}
      </section>
    </div>
  )
}
