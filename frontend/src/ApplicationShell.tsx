import type { ReactNode } from 'react'

type ContextItem = {
  label: string
  value: string
  detail?: string
}

type Props = {
  activePath: string
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
  contexts,
  account,
  status,
  sidebarFooter,
  footerNote,
  children,
}: Props) {
  return (
    <div className="app-shell app-shell-workspace">
      <aside className="module-sidebar" aria-label="Nawigacja modułowa">
        <a className="brand-link shell-brand" href="/" aria-label="VELTRA 2.0 — strona główna">
          <span className="brand-mark" aria-hidden="true">V</span>
          <span className="brand-name">VELTRA <b>2.0</b></span>
        </a>
        <nav className="module-nav" aria-label="Moduły">
          {MODULES.map((item) => item.href ? (
            <a
              key={item.label}
              className="module-nav-item"
              href={item.href}
              aria-current={activePath === item.href ? 'page' : undefined}
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
