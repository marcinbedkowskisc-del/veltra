import { administrationGroups } from './administrationNavigation'

export default function AdministrationPanel({ path }: { path: string }) {
  const selected = administrationGroups.flatMap((group) => group.items).find((item) => item.path === path)
  const overview = path === '/administration'

  return (
    <section className="administration">
      <header className="administration-header">
        <p className="eyebrow">KONFIGURACJA I ZARZĄDZANIE</p>
        <h1>Panel Administracyjny</h1>
        <p>Centralne miejsce ustawień i administracji VELTRA.</p>
      </header>
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
    </section>
  )
}
