import { useEffect, useState } from 'react'
import VeltraAccordion from './design-system/VeltraAccordion'
import { administrationGroups } from './administrationNavigation'

const storageKey = 'veltra.administration.expandedGroups.v1'

function selectedGroup(path: string) {
  return administrationGroups.find((group) => group.items.some((item) => item.path === path))?.id
}

function readExpanded(): string[] {
  try {
    const saved = window.sessionStorage.getItem(storageKey)
    if (!saved) return []
    const parsed: unknown = JSON.parse(saved)
    if (!Array.isArray(parsed) || !parsed.every((id) => typeof id === 'string')) {
      throw new Error('Nieprawidłowy stan nawigacji administracyjnej.')
    }
    return administrationGroups.filter((group) => parsed.includes(group.id)).map((group) => group.id)
  } catch (error) {
    console.warn('Nie można odczytać stanu nawigacji administracyjnej.', error)
    return []
  }
}

function saveExpanded(ids: string[]) {
  try {
    window.sessionStorage.setItem(storageKey, JSON.stringify(ids))
  } catch (error) {
    console.warn('Nie można zapisać stanu nawigacji administracyjnej.', error)
  }
}

export default function AdministrationNavigation({ activePath }: { activePath: string }) {
  const [expanded, setExpanded] = useState(() => {
    const saved = readExpanded()
    const active = selectedGroup(activePath)
    return active && !saved.includes(active) ? [...saved, active] : saved
  })

  useEffect(() => {
    saveExpanded(expanded)
  }, [expanded])

  function handleExpandedChange(ids: string[]) {
    setExpanded(ids)
    saveExpanded(ids)
  }

  return (
    <div className="module-administration">
      <a className="module-administration-link" href="/administration" aria-current={activePath === '/administration' ? 'page' : undefined}>
        Przegląd
      </a>
      <VeltraAccordion
        expandedIds={expanded}
        onExpandedChange={handleExpandedChange}
        items={administrationGroups.map((group) => ({
          id: group.id,
          title: group.label,
          active: group.id === selectedGroup(activePath),
          content: (
            <ul>
              {group.items.map((item) => (
                <li key={item.path}>
                  <a className="module-administration-link" href={item.path} aria-current={activePath === item.path ? 'page' : undefined}>
                    {item.label}
                  </a>
                </li>
              ))}
            </ul>
          ),
        }))}
      />
    </div>
  )
}
