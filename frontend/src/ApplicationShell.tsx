import { useEffect, useRef, type ReactNode } from 'react'
import AdministrationNavigation from './AdministrationNavigation'

type ContextItem = {
  label: string
  value: string
  detail?: string
}

type Props = {
  activePath: string
  showAdministration?: boolean
  contexts: ContextItem[]
  account: ReactNode
  status: ReactNode
  sidebarFooter?: ReactNode
  footerNote: string
  children: ReactNode
}

const MODULES = [
  { label: 'Pulpit', icon: '⌂', href: '/' },
  { label: 'Organizacja', icon: '▤' },
  { label: 'Konta i uprawnienia', icon: '♙' },
  { label: 'Lokalizacje', icon: '⌖' },
  { label: 'Moduły', icon: '◈' },
  { label: 'Finanse', icon: '¤' },
  { label: 'RAW', icon: '▦', href: '/raw-materials' },
  { label: 'Ustawienia', icon: '⚙' },
  { label: 'Dziennik zdarzeń', icon: '≡' },
]

export default function ApplicationShell({
  activePath,
  showAdministration = false,
  contexts,
  account,
  status,
  sidebarFooter,
  footerNote,
  children,
}: Props) {
  const isAdministrationRoute = activePath === '/administration' || activePath.startsWith('/administration/')
  const navRef = useRef<HTMLElement>(null)

  useEffect(() => {
    if (!showAdministration || !isAdministrationRoute || !navRef.current) return
    const nav = navRef.current
    const selected = nav.querySelector<HTMLElement>('.module-administration [aria-current="page"]')
    const groups = nav.querySelector<HTMLElement>('.module-administration')
    const primary = nav.querySelector<HTMLElement>('.module-nav-primary')
    const entry = primary?.querySelector<HTMLElement>('a[href="/administration"]')

    if (selected) {
      for (const container of [groups, nav]) {
        if (!container || container.scrollHeight <= container.clientHeight) continue
        const item = selected.getBoundingClientRect()
        const bounds = container.getBoundingClientRect()
        if (item.bottom > bounds.bottom) container.scrollTop += item.bottom - bounds.bottom
        else if (item.top < bounds.top) container.scrollTop += item.top - bounds.top
      }
    }
    if (primary && entry && primary.scrollWidth > primary.clientWidth) {
      const item = entry.getBoundingClientRect()
      const bounds = primary.getBoundingClientRect()
      if (item.right > bounds.right) primary.scrollLeft += item.right - bounds.right
      else if (item.left < bounds.left) primary.scrollLeft += item.left - bounds.left
    }
  }, [activePath, isAdministrationRoute, showAdministration])

  return (
    <div className="app-shell app-shell-workspace">
      <aside className="module-sidebar" aria-label="Nawigacja modułowa">
        <a className="brand-link shell-brand" href="/" aria-label="VELTRA 2.0 — strona główna">
          <span className="brand-mark" aria-hidden="true">V</span>
          <span className="brand-name">VELTRA <b>2.0</b></span>
        </a>
        <nav ref={navRef} className="module-nav" aria-label="Moduły">
          <div className="module-nav-primary">
            {[...MODULES, ...(showAdministration ? [{ label: 'Panel Administracyjny', icon: '⚙', href: '/administration' }] : [])].map((item) => item.href ? (
              <a
                key={item.label}
                className={`module-nav-item${item.href === '/administration' && isAdministrationRoute ? ' is-active' : ''}`}
                href={item.href}
                aria-current={activePath === item.href && item.href !== '/administration' ? 'page' : undefined}
              >
                <span className="module-nav-icon" aria-hidden="true">{item.icon}</span>
                <span>{item.label}</span>
              </a>
            ) : (
              <span
                key={item.label}
                className="module-nav-item is-disabled"
                aria-disabled="true"
                aria-label={`${item.label}, niedostępne w tym prototypie`}
                title="Moduł niedostępny w tym prototypie"
              >
                <span className="module-nav-icon" aria-hidden="true">{item.icon}</span>
                <span className="module-nav-label">{item.label}<small>Niedostępne</small></span>
              </span>
            ))}
          </div>
          {showAdministration && isAdministrationRoute && (
            <AdministrationNavigation key={activePath} activePath={activePath} />
          )}
        </nav>
        <div className="sidebar-footer">
          {sidebarFooter}
          <div className="sidebar-meta">
            <strong>VELTRA 2.0</strong>
            <small>{footerNote}</small>
          </div>
        </div>
      </aside>

      <div className="app-workspace">
        <header className="app-topbar">
          <div className="app-context-list" aria-label="Kontekst operacyjny">
            {contexts.map((item) => (
              <div className="app-context-item" key={item.label}>
                <span>{item.label}</span>
                <strong>{item.value}</strong>
                {item.detail && <small>{item.detail}</small>}
              </div>
            ))}
          </div>
          <div className="app-topbar-actions">
            {status}
            {account}
          </div>
        </header>
        <main className="app-main">{children}</main>
      </div>
    </div>
  )
}
