import { administrationGroups } from './administrationNavigation'
import AllergenDictionaryManagementPage from './administration/AllergenDictionaryManagementPage'
import CategoryManagementPage from './administration/CategoryManagementPage'
import UnitManagementPage from './administration/UnitManagementPage'
import SupplierManagementPage from './administration/SupplierManagementPage'
import AdministrationUsersPage from './administration/AdministrationUsersPage'
import AdministrationPermissionsPage from './administration/AdministrationPermissionsPage'

export default function AdministrationPanel({ path, contextKey, onSessionExpired }: {
  path: string
  contextKey?: string
  onSessionExpired?: () => void
}) {
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
          selected.path === '/administration/categories' ? (
            <CategoryManagementPage />
          ) : selected.path === '/administration/units' ? (
            <UnitManagementPage />
          ) : selected.path === '/administration/allergens' ? (
            <AllergenDictionaryManagementPage />
          ) : selected.path === '/administration/suppliers' ? (
            <SupplierManagementPage />
          ) : selected.path === '/administration/users' ? (
            <AdministrationUsersPage key={contextKey} onSessionExpired={onSessionExpired} />
          ) : selected.path === '/administration/permissions' ? (
            <AdministrationPermissionsPage />
          ) : (
            <>
              <h2>{selected.label}</h2>
              <p>Ta funkcjonalność nie została jeszcze zaimplementowana.</p>
            </>
          )
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
