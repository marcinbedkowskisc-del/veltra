const roles = [
  {
    name: 'Administrator',
    scope: 'Cała organizacja',
    access: 'Zarządza kontami, lokalizacjami, rolami i uprawnieniami. Dostęp zależy od aktywności lokalizacji i dostępności modułu.',
  },
  {
    name: 'Szef kuchni centrali',
    scope: 'Przypisana kuchnia centralna i zarządzane restauracje',
    access: 'Zarządza personelem, rolami i uprawnieniami w obsługiwanym zakresie oraz ma dostęp do powiązanych danych operacyjnych i raportów.',
  },
  {
    name: 'BOSS',
    scope: 'Kuchnie centralne i restauracje organizacji',
    access: 'Ma dostęp do listy dostępnych lokalizacji. Nie otrzymuje dostępu do cen zakupu ani kosztów receptur.',
  },
  {
    name: 'Manager restauracji',
    scope: 'Przypisana restauracja',
    access: 'Przegląda lokalizację i zarządza personelem. Może delegować obsługiwane uprawnienia modułów pracownikom.',
  },
  {
    name: 'Szef kuchni restauracji',
    scope: 'Przypisana restauracja',
    access: 'Przegląda lokalizację, receptury i oblicza ilości. Uprawnienia specjalne nie wynikają z tej roli.',
  },
  {
    name: 'Pracownik',
    scope: 'Przypisana restauracja',
    access: 'Przegląda lokalizację. Operacje modułów wymagają jawnej delegacji i dostępnego modułu.',
  },
]

export default function AdministrationPermissionsPage() {
  return (
    <section className="admin-permissions-page" aria-labelledby="admin-permissions-title">
      <header className="admin-category-header">
        <div>
          <p className="eyebrow">UŻYTKOWNICY I UPRAWNIENIA</p>
          <h2 id="admin-permissions-title">Role i uprawnienia</h2>
          <p>Informacja o obowiązujących zasadach Stage 1D. Uprawnienia są sprawdzane przez API dla każdej operacji.</p>
        </div>
      </header>

      <div className="admin-permissions-notice" role="note">
        <strong>Zakres roli BOSS można ograniczyć według lokalizacji.</strong>
        <span>Ograniczenia ustawia administrator w szczegółach konta. Nie zmieniają one niezależnych uprawnień wynikających z innych ról.</span>
      </div>

      <div className="admin-permissions-table-wrap">
        <table className="admin-permissions-table">
          <caption>Zakres bieżących ról i uprawnień użytkowników</caption>
          <thead>
            <tr><th scope="col">Rola</th><th scope="col">Zakres</th><th scope="col">Dostęp w skrócie</th></tr>
          </thead>
          <tbody>
            {roles.map((role) => (
              <tr key={role.name}>
                <th scope="row">{role.name}</th>
                <td>{role.scope}</td>
                <td>{role.access}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="admin-permissions-footnote">
        Ceny zakupu i koszty receptur są uprawnieniami specjalnymi, niezależnymi od roli pracownika. Operacje modułów są dostępne wyłącznie wtedy, gdy moduł jest aktywny w danej lokalizacji.
      </p>
    </section>
  )
}
