const groups = [
  {
    label: 'Słowniki i definicje',
    items: [
      { label: 'Kategorie surowców', path: '/administration/categories' },
      { label: 'Jednostki miary i przeliczniki', path: '/administration/units' },
      { label: 'Alergeny', path: '/administration/allergens' },
    ],
  },
  {
    label: 'Kontrahenci',
    items: [{ label: 'Dostawcy', path: '/administration/suppliers' }],
  },
  {
    label: 'Użytkownicy i uprawnienia',
    items: [
      { label: 'Użytkownicy', path: '/administration/users' },
      { label: 'Role i uprawnienia', path: '/administration/permissions' },
    ],
  },
]

export default function AdministrationPanel({ path }: { path: string }) {
  const selected = groups.flatMap((group) => group.items).find((item) => item.path === path)
  const overview = path === '/administration'

  return (
    <section className="administration">
      <header className="administration-header">
        <p className="eyebrow">KONFIGURACJA I ZARZĄDZANIE</p>
        <h1>Panel Administracyjny</h1>
        <p>Centralne miejsce ustawień i administracji VELTRA.</p>
      </header>
      <div className="administration-layout">
        <nav className="administration-sidebar" aria-label="Nawigacja Panelu Administracyjnego">
          <a href="/administration" aria-current={overview ? 'page' : undefined}>Przegląd</a>
          {groups.map((group) => (
            <section key={group.label} aria-label={group.label}>
              <h2>{group.label}</h2>
              <ul>
                {group.items.map((item) => (
                  <li key={item.path}>
                    <a href={item.path} aria-current={path === item.path ? 'page' : undefined}>{item.label}</a>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </nav>
        <div className="administration-content state-panel">
          {overview ? (
            <>
              <h2>Przegląd</h2>
              <p>Wybierz sekcję z menu. Funkcje administracyjne będą dostępne w kolejnych etapach.</p>
            </>
          ) : selected ? (
            <>
              <h2>{selected.label}</h2>
              <p>Ta funkcjonalność nie została jeszcze zaimplementowana.</p>
            </>
          ) : (
            <>
              <h2>Nie znaleziono sekcji</h2>
              <p>Wybierz dostępną pozycję z menu administracyjnego.</p>
            </>
          )}
        </div>
      </div>
    </section>
  )
}
