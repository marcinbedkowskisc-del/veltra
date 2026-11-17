export const administrationGroups = [
  {
    id: 'dictionaries',
    label: 'Słowniki i definicje',
    items: [
      { label: 'Kategorie surowców', path: '/administration/categories' },
      { label: 'Jednostki miary i przeliczniki', path: '/administration/units' },
      { label: 'Alergeny', path: '/administration/allergens' },
    ],
  },
  {
    id: 'counterparties',
    label: 'Kontrahenci',
    items: [{ label: 'Dostawcy', path: '/administration/suppliers' }],
  },
  {
    id: 'users',
    label: 'Użytkownicy i uprawnienia',
    items: [
      { label: 'Użytkownicy', path: '/administration/users' },
      { label: 'Role i uprawnienia', path: '/administration/permissions' },
    ],
  },
]
