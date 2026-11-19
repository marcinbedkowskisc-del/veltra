import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import AnimatedPanel from '../design-system/AnimatedPanel'
import DataTable, { type DataTableColumn } from '../design-system/DataTable'
import Drawer from '../design-system/Drawer'
import Pagination from '../design-system/Pagination'
import VeltraDisclosure from '../design-system/VeltraDisclosure'
import BossLocationRestrictions from './BossLocationRestrictions'
import {
  assignUserLocation,
  changeUserEmail,
  createUser,
  decideUserRequest,
  fetchUserDetails,
  fetchUserDirectory,
  fetchUserLocations,
  grantUserPermission,
  grantUserRole,
  removeUserLocation,
  revokeUserPermission,
  revokeUserRole,
  roleValues,
  sendUserLink,
  setUserActive,
  updateUserProfile,
  type BusinessRole,
  type UserDetails,
  type UserDirectoryPage,
  type UserListQuery,
  type UserLocation,
  type UserRoleSummary,
  type UserRow,
  type UserSort,
  type UserStatus,
} from './usersApi'

const EMPTY_PAGE: UserDirectoryPage = {
  data: [],
  meta: { currentPage: 1, perPage: 25, total: 0, lastPage: 1 },
}

const roleLabels: Record<BusinessRole, string> = {
  ADMINISTRATOR: 'Administrator',
  CENTRAL_KITCHEN_CHEF: 'Szef kuchni centrali',
  BOSS: 'BOSS',
  RESTAURANT_MANAGER: 'Manager restauracji',
  RESTAURANT_CHEF: 'Szef kuchni restauracji',
  EMPLOYEE: 'Pracownik',
}

const permissionLabels: Record<string, string> = {
  LOCATION_VIEW: 'Podgląd lokalizacji',
  STRUCTURE_MANAGE: 'Zarządzanie strukturą',
  PERSONNEL_MANAGE: 'Zarządzanie personelem',
  RECIPES_VIEW: 'Podgląd receptur',
  QUANTITY_CALCULATE: 'Obliczanie ilości',
  FOODCOST_VIEW: 'Podgląd kosztów receptur',
  PURCHASE_PRICES_VIEW: 'Podgląd cen zakupu',
  REPORTS_VIEW: 'Przeglądanie raportów',
  REPORTS_EXPORT: 'Pobieranie zestawień',
  TEMPERATURE_REGISTER: 'Rejestrowanie temperatur',
  CLEANING_ENTRY_CREATE: 'Rejestrowanie czynności porządkowych',
  LOSS_REPORT_CREATE: 'Zgłaszanie strat',
  INVENTORY_COUNT_ENTRY: 'Wprowadzanie wyników inwentaryzacji',
}

const delegableOptions = [
  { value: 'FOODCOST_VIEW', label: permissionLabels.FOODCOST_VIEW },
  { value: 'PURCHASE_PRICES_VIEW', label: permissionLabels.PURCHASE_PRICES_VIEW },
  { value: 'REPORTS_VIEW', label: permissionLabels.REPORTS_VIEW },
  { value: 'REPORTS_EXPORT', label: permissionLabels.REPORTS_EXPORT },
  { value: 'TEMPERATURE_REGISTER', label: permissionLabels.TEMPERATURE_REGISTER },
  { value: 'CLEANING_ENTRY_CREATE', label: permissionLabels.CLEANING_ENTRY_CREATE },
  { value: 'LOSS_REPORT_CREATE', label: permissionLabels.LOSS_REPORT_CREATE },
  { value: 'INVENTORY_COUNT_ENTRY', label: permissionLabels.INVENTORY_COUNT_ENTRY },
]

type Props = {
  onSessionExpired?: () => void
}

type Confirmation = {
  kind: 'account' | 'role' | 'membership'
  id: string
  active?: boolean
  role?: BusinessRole
  label: string
  requiresPassword: boolean
}

function errorText(cause: unknown): string {
  if (cause instanceof ApiError) {
    const validationMessage = Object.values(cause.errors).flat()[0]
    return validationMessage ?? cause.message
  }
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.'
}

function fullName(user: Pick<UserRow, 'firstName' | 'lastName'>): string {
  return `${user.firstName} ${user.lastName}`.trim()
}

function roleSummary(roles: UserRoleSummary[]): string {
  if (roles.length === 0) return 'Brak przypisanej roli'
  const labels = roles.map(({ role, locationName }) => (
    locationName ? `${roleLabels[role]} — ${locationName}` : roleLabels[role]
  ))
  return labels.length > 2 ? `${labels.slice(0, 2).join('; ')} + ${labels.length - 2}` : labels.join('; ')
}

function locationSummary(locations: UserLocation[]): string {
  if (locations.length === 0) return 'Brak aktywnych lokalizacji'
  return locations.length > 2
    ? `${locations.slice(0, 2).map((location) => location.name).join(', ')} + ${locations.length - 2}`
    : locations.map((location) => location.name).join(', ')
}

function locationTypeLabel(location: Pick<UserLocation, 'type'>): string {
  return location.type === 'CENTRAL_KITCHEN' ? 'Kuchnia centralna' : 'Restauracja'
}

function permissionLabel(permission: string): string {
  return permissionLabels[permission] ?? 'Dodatkowe uprawnienie'
}

function assignmentActive(validFrom: string, validUntil: string | null, revokedAt: string | null, now: number): boolean {
  return revokedAt === null && new Date(validFrom).getTime() <= now
    && (validUntil === null || new Date(validUntil).getTime() > now)
}

function historyLabel(action: string): string {
  if (action.startsWith('role.grant.')) return `Przypisano rolę: ${roleLabels[action.slice('role.grant.'.length) as BusinessRole] ?? 'użytkownika'}`
  if (action.startsWith('role.revoke.')) return `Cofnięto rolę: ${roleLabels[action.slice('role.revoke.'.length) as BusinessRole] ?? 'użytkownika'}`
  if (action.startsWith('permission.grant.')) return `Przyznano: ${permissionLabel(action.slice('permission.grant.'.length))}`
  if (action.startsWith('permission.revoke.')) return `Cofnięto: ${permissionLabel(action.slice('permission.revoke.'.length))}`
  const labels: Record<string, string> = {
    'account.create': 'Utworzono konto',
    'account.deactivate': 'Wyłączono konto',
    'account.reactivate': 'Włączono konto',
    'account.email_change': 'Zmieniono adres e-mail',
    'account.profile_update': 'Zmieniono dane użytkownika',
    'boss.location_restrictions': 'Zmieniono ograniczenia lokalizacji roli BOSS',
    'membership.assign': 'Przypisano lokalizację',
    'membership.remove': 'Cofnięto przypisanie do lokalizacji',
    'membership.request': 'Złożono prośbę o dostęp do lokalizacji',
    'membership.approve': 'Zatwierdzono prośbę o dostęp',
    'membership.reject': 'Odrzucono prośbę o dostęp',
    'email.request_unknown': 'Zarejestrowano prośbę o wysłanie wiadomości',
    'email.request_unavailable': 'Nie można było wysłać wiadomości dotyczącej konta',
  }
  return labels[action] ?? 'Zarejestrowano zmianę dotyczącą konta'
}

export default function AdministrationUsersPage({ onSessionExpired }: Props) {
  const [locations, setLocations] = useState<UserLocation[]>([])
  const [locationsLoading, setLocationsLoading] = useState(true)
  const [locationsError, setLocationsError] = useState<string | null>(null)
  const [searchText, setSearchText] = useState('')
  const [search, setSearch] = useState('')
  const [role, setRole] = useState<BusinessRole | ''>('')
  const [locationId, setLocationId] = useState('')
  const [status, setStatus] = useState<UserStatus>('active')
  const [sort, setSort] = useState<UserSort>('name')
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [directory, setDirectory] = useState(EMPTY_PAGE)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [details, setDetails] = useState<UserDetails | null>(null)
  const [detailsError, setDetailsError] = useState<string | null>(null)
  const [detailsReload, setDetailsReload] = useState(0)
  const [statusReferenceTime, setStatusReferenceTime] = useState(0)
  const [message, setMessage] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [createForm, setCreateForm] = useState({ firstName: '', lastName: '', email: '', locationId: '' })
  const [profileOpen, setProfileOpen] = useState(false)
  const [profileForm, setProfileForm] = useState({ firstName: '', lastName: '' })
  const [emailOpen, setEmailOpen] = useState(false)
  const [email, setEmail] = useState('')
  const [roleOpen, setRoleOpen] = useState(false)
  const [roleForm, setRoleForm] = useState<{ role: BusinessRole; locationId: string; password: string }>({
    role: 'EMPLOYEE',
    locationId: '',
    password: '',
  })
  const [membershipOpen, setMembershipOpen] = useState(false)
  const [membershipLocationId, setMembershipLocationId] = useState('')
  const [permissionOpen, setPermissionOpen] = useState(false)
  const [permissionForm, setPermissionForm] = useState({ permission: 'FOODCOST_VIEW', locationId: '' })
  const [confirmation, setConfirmation] = useState<Confirmation | null>(null)
  const [confirmationPassword, setConfirmationPassword] = useState('')
  const [confirmationReturnFocus, setConfirmationReturnFocus] = useState<HTMLElement | null>(null)
  const confirmationButtonRef = useRef<HTMLButtonElement>(null)
  const confirmationPasswordRef = useRef<HTMLInputElement>(null)

  const query = useMemo<UserListQuery>(() => ({
    search,
    role,
    locationId,
    status,
    sort,
    direction,
    page,
    perPage: 25,
  }), [search, role, locationId, status, sort, direction, page])

  useEffect(() => {
    const controller = new AbortController()
    void fetchUserLocations(controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) {
          setLocations(result)
          setLocationsError(null)
          setCreateForm((current) => ({
            ...current,
            locationId: current.locationId
              || result.find((item) => item.isActive && item.type === 'RESTAURANT')?.id
              || result.find((item) => item.isActive)?.id || '',
          }))
        }
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) {
          setLocationsError(errorText(cause))
          if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired?.()
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLocationsLoading(false)
      })
    return () => controller.abort()
  }, [onSessionExpired])

  useEffect(() => {
    const controller = new AbortController()
    void fetchUserDirectory(query, controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) {
          setDirectory(result)
          setError(null)
        }
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) {
          setError(errorText(cause))
          if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired?.()
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [query, reloadKey, onSessionExpired])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchText.trim())
      setPage(1)
    }, 250)
    return () => window.clearTimeout(timer)
  }, [searchText])

  useEffect(() => {
    if (!selectedId) return
    const controller = new AbortController()
    void fetchUserDetails(selectedId, controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) {
          setDetails(result)
          setStatusReferenceTime(Date.now())
        }
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) {
          setDetailsError(errorText(cause))
          if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired?.()
        }
      })
    return () => controller.abort()
  }, [selectedId, detailsReload, onSessionExpired])

  function reloadDirectory() {
    setLoading(true)
    setError(null)
    setReloadKey((current) => current + 1)
  }

  function reloadDetails() {
    setDetails(null)
    setDetailsError(null)
    setDetailsReload((current) => current + 1)
  }

  async function perform(action: () => Promise<void>, successMessage: string, closeForm?: () => void) {
    setBusy(true)
    setActionError(null)
    setMessage(null)
    try {
      await action()
      closeForm?.()
      setMessage(successMessage)
      reloadDirectory()
      if (selectedId) reloadDetails()
    } catch (cause) {
      setActionError(errorText(cause))
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired?.()
    } finally {
      setBusy(false)
    }
  }

  function sortBy(nextSort: UserSort) {
    if (sort === nextSort) setDirection((current) => current === 'asc' ? 'desc' : 'asc')
    else {
      setSort(nextSort)
      setDirection('asc')
    }
    setPage(1)
  }

  function openUser(id: string) {
    if (selectedId !== id) {
      setDetails(null)
      setDetailsError(null)
    }
    setSelectedId(id)
  }

  async function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!createForm.locationId) {
      setActionError('Wybierz lokalizację, do której ma zostać przypisany nowy użytkownik.')
      return
    }
    await perform(() => createUser(createForm.locationId, {
      first_name: createForm.firstName.trim(),
      last_name: createForm.lastName.trim(),
      email: createForm.email.trim(),
    }), 'Jeśli adres jest nowy, wysłaliśmy link aktywacyjny. Istniejące konto nie zostało powielone.', () => {
      setCreateOpen(false)
      setCreateForm((current) => ({ ...current, firstName: '', lastName: '', email: '' }))
    })
  }

  async function submitProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedId) return
    await perform(() => updateUserProfile(selectedId, {
      first_name: profileForm.firstName.trim(),
      last_name: profileForm.lastName.trim(),
    }), 'Dane użytkownika zostały zapisane.', () => setProfileOpen(false))
  }

  async function submitEmail(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedId) return
    await perform(() => changeUserEmail(selectedId, email.trim()), 'Adres e-mail został zmieniony. Użytkownik musi ponownie go potwierdzić.', () => setEmailOpen(false))
  }

  async function submitRole(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedId) return
    const globalRole = roleForm.role === 'ADMINISTRATOR' || roleForm.role === 'BOSS'
    if (!globalRole && !roleForm.locationId) {
      setActionError('Wybierz lokalizację dla tej roli.')
      return
    }
    await perform(() => grantUserRole(
      selectedId,
      roleForm.role,
      globalRole ? null : roleForm.locationId,
      roleForm.role === 'ADMINISTRATOR' ? roleForm.password : undefined,
    ), 'Rola została przypisana.', () => {
      setRoleOpen(false)
      setRoleForm((current) => ({ ...current, password: '' }))
    })
  }

  async function submitMembership(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedId || !membershipLocationId) return
    await perform(() => assignUserLocation(selectedId, membershipLocationId), 'Lokalizacja została przypisana.', () => setMembershipOpen(false))
  }

  async function submitPermission(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!selectedId || !permissionForm.locationId) return
    await perform(() => grantUserPermission(selectedId, permissionForm.locationId, permissionForm.permission),
      'Dodatkowe uprawnienie zostało zapisane.', () => setPermissionOpen(false))
  }

  function openConfirmation(next: Confirmation) {
    const activeElement = document.activeElement
    setConfirmationReturnFocus(activeElement instanceof HTMLElement ? activeElement : null)
    setConfirmationPassword('')
    setConfirmation(next)
  }

  function closeConfirmation() {
    setConfirmation(null)
    setConfirmationPassword('')
  }

  async function confirmChange() {
    if (!confirmation) return
    const action = confirmation
    const password = confirmationPassword
    const active = action.active
    closeConfirmation()
    if (action.kind === 'account' && active !== undefined) {
      await perform(() => setUserActive(action.id, active), active
        ? 'Konto użytkownika jest aktywne.' : 'Konto zostało wyłączone. Użytkownik nie może zalogować się do VELTRA.')
    } else if (action.kind === 'role') {
      await perform(() => revokeUserRole(action.id, action.requiresPassword ? password : undefined), 'Rola została cofnięta.')
    } else if (action.kind === 'membership') {
      await perform(() => removeUserLocation(action.id), 'Przypisanie do lokalizacji zostało cofnięte.')
    }
  }

  const columns: Array<DataTableColumn<UserRow>> = [
    {
      id: 'user',
      header: 'Użytkownik',
      sortKey: 'name',
      sortDirection: sort === 'name' ? direction : null,
      sortLabel: 'Sortuj według imienia i nazwiska',
      onSort: () => sortBy('name'),
      cell: (user) => (
        <button type="button" className="admin-user-link" onClick={() => {
          openUser(user.id)
          setActionError(null)
          setMessage(null)
        }}>
          {fullName(user)}
        </button>
      ),
    },
    {
      id: 'email',
      header: 'E-mail',
      sortKey: 'email',
      sortDirection: sort === 'email' ? direction : null,
      sortLabel: 'Sortuj według adresu e-mail',
      onSort: () => sortBy('email'),
      cell: (user) => (
        <span>
          {user.email}
          <small className="admin-user-subline">{user.emailVerifiedAt ? 'Adres potwierdzony' : 'Adres niepotwierdzony'}</small>
        </span>
      ),
    },
    { id: 'roles', header: 'Rola', cell: (user) => roleSummary(user.roles) },
    { id: 'locations', header: 'Lokalizacje', cell: (user) => locationSummary(user.locations) },
    {
      id: 'status',
      header: 'Status konta',
      sortKey: 'status',
      sortDirection: sort === 'status' ? direction : null,
      sortLabel: 'Sortuj według statusu konta',
      onSort: () => sortBy('status'),
      cell: (user) => <span className="status-pill" data-status={user.isActive ? 'active' : 'inactive'}>
        {user.isActive ? 'Konto aktywne' : 'Konto nieaktywne'}
      </span>,
    },
    {
      id: 'actions',
      header: 'Akcje',
      cell: (user) => <button type="button" className="button button-secondary" onClick={() => openUser(user.id)}>Szczegóły</button>,
    },
  ]

  const activeLocations = locations.filter((location) => location.isActive !== false)
  const activeRestaurants = activeLocations.filter((location) => location.type === 'RESTAURANT')
  const assignedLocationIds = new Set(details?.memberships.filter((membership) => membership.isActive).map((membership) => membership.locationId) ?? [])
  const roleLocations = activeLocations.filter((location) => (
    assignedLocationIds.has(location.id)
    && (roleForm.role === 'CENTRAL_KITCHEN_CHEF'
      ? location.type === 'CENTRAL_KITCHEN'
      : location.type === 'RESTAURANT')
  ))
  const availableMembershipLocations = activeLocations.filter((location) => !assignedLocationIds.has(location.id))
  const globalRole = roleForm.role === 'ADMINISTRATOR' || roleForm.role === 'BOSS'

  return (
    <section className="admin-users-page" aria-labelledby="admin-users-title">
      <header className="admin-users-heading">
        <div>
          <p className="eyebrow">UŻYTKOWNICY I UPRAWNIENIA</p>
          <h2 id="admin-users-title">Użytkownicy</h2>
          <p>Przeglądaj konta, przypisania i dodatkowe uprawnienia w tej instalacji.</p>
        </div>
        <button type="button" className="button button-primary" onClick={() => {
          setCreateOpen((open) => !open)
          setActionError(null)
        }}>+ Dodaj użytkownika</button>
      </header>

      {message && <p className="success admin-users-message" role="status">{message}</p>}
      {actionError && <p className="error admin-users-message" role="alert">{actionError}</p>}
      {locationsError && <p className="error admin-users-message" role="alert">{locationsError}</p>}

      <AnimatedPanel id="admin-user-create" open={createOpen} className="admin-user-form-panel">
        <form className="admin-user-form" onSubmit={(event) => void submitCreate(event)}>
          <h3>Nowy użytkownik</h3>
          <p>Użytkownik ustawi własne hasło po otrzymaniu linku aktywacyjnego.</p>
          <div className="create-form-grid">
            <label>Imię
              <input required maxLength={100} value={createForm.firstName} onChange={(event) => setCreateForm({ ...createForm, firstName: event.target.value })} />
            </label>
            <label>Nazwisko
              <input required maxLength={100} value={createForm.lastName} onChange={(event) => setCreateForm({ ...createForm, lastName: event.target.value })} />
            </label>
            <label>Adres e-mail
              <input required type="email" maxLength={254} value={createForm.email} onChange={(event) => setCreateForm({ ...createForm, email: event.target.value })} />
            </label>
            <label>Przypisz do lokalizacji
              <select required value={createForm.locationId} onChange={(event) => setCreateForm({ ...createForm, locationId: event.target.value })}>
                <option value="">Wybierz lokalizację</option>
                {activeLocations.map((location) => <option key={location.id} value={location.id}>{location.name} — {locationTypeLabel(location)}</option>)}
              </select>
            </label>
          </div>
          <div className="admin-user-form-actions">
            <button type="submit" className="button button-primary" disabled={busy || locationsLoading || activeLocations.length === 0}>Utwórz konto i wyślij link</button>
            <button type="button" className="button button-secondary" onClick={() => setCreateOpen(false)}>Anuluj</button>
          </div>
          {activeLocations.length === 0 && !locationsLoading && <p role="status">Brak aktywnych lokalizacji, do których można przypisać nowe konto.</p>}
        </form>
      </AnimatedPanel>

      <form className="admin-category-filters admin-user-filters" onSubmit={(event) => {
        event.preventDefault()
        setSearch(searchText.trim())
        setPage(1)
      }}>
        <label>Szukaj po imieniu, nazwisku lub e-mailu
          <input type="search" value={searchText} onChange={(event) => setSearchText(event.target.value)} />
        </label>
        <label>Rola
          <select value={role} onChange={(event) => {
            setRole(event.target.value as BusinessRole | '')
            setPage(1)
          }}>
            <option value="">Wszystkie role</option>
            {roleValues.map((value) => <option key={value} value={value}>{roleLabels[value]}</option>)}
          </select>
        </label>
        <label>Lokalizacja
          <select value={locationId} onChange={(event) => {
            setLocationId(event.target.value)
            setPage(1)
          }}>
            <option value="">Wszystkie lokalizacje</option>
            {locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}
          </select>
        </label>
        <label>Status konta
          <select value={status} onChange={(event) => {
            setStatus(event.target.value as UserStatus)
            setPage(1)
          }}>
            <option value="active">Aktywne</option>
            <option value="inactive">Nieaktywne</option>
            <option value="all">Wszystkie</option>
          </select>
        </label>
        <button type="submit" className="button button-secondary">Szukaj</button>
      </form>

      <p className="admin-user-result-count" aria-live="polite">
        {loading ? 'Ładowanie listy użytkowników…' : `Liczba użytkowników: ${directory.meta.total}`}
      </p>
      <DataTable
        rows={directory.data}
        columns={columns}
        getRowId={(user) => user.id}
        ariaLabel="Lista użytkowników"
        loading={loading}
        loadingMessage="Ładowanie użytkowników…"
        emptyMessage={search || role || locationId || status !== 'active'
          ? 'Nie znaleziono użytkowników spełniających wybrane kryteria.'
          : 'Nie ma jeszcze użytkowników do wyświetlenia.'}
        error={error}
        wrapperClassName="admin-user-table"
      />
      {directory.meta.total > directory.meta.perPage && (
        <Pagination currentPage={directory.meta.currentPage} totalPages={directory.meta.lastPage} onPageChange={setPage} disabled={loading} />
      )}

      <AnimatedPanel id="admin-user-details" open={selectedId !== null} className="admin-user-details-panel">
        {selectedId && (
          <section aria-labelledby="admin-user-details-title">
            <header className="admin-user-details-heading">
              <div>
                <p className="eyebrow">SZCZEGÓŁY KONTA</p>
                <h3 id="admin-user-details-title">{details ? fullName(details) : 'Użytkownik'}</h3>
              </div>
              <button type="button" className="button button-quiet" onClick={() => {
                setSelectedId(null)
                setDetails(null)
                setDetailsError(null)
              }}>Zamknij</button>
            </header>
            {!details && !detailsError && <p role="status">Ładowanie danych użytkownika…</p>}
            {detailsError && <div className="error admin-user-details-error" role="alert">
              <span>{detailsError}</span>
              <button type="button" className="button button-secondary" onClick={reloadDetails}>Spróbuj ponownie</button>
            </div>}
            {details && (
              <>
                <section className="admin-user-summary">
                  <div><strong>Adres e-mail</strong><span>{details.email}</span><small>{details.emailVerifiedAt ? 'Adres potwierdzony' : 'Adres niepotwierdzony'}</small></div>
                  <div><strong>Status konta</strong><span>{details.isActive ? 'Konto aktywne' : 'Konto nieaktywne'}</span></div>
                  <div><strong>Przypisane lokalizacje</strong><span>{locationSummary(details.locations)}</span></div>
                </section>

                <section className="admin-user-action-bar" aria-label="Działania na koncie">
                  <button type="button" className="button button-secondary" onClick={() => {
                    setProfileForm({ firstName: details.firstName, lastName: details.lastName })
                    setProfileOpen((open) => !open)
                  }}>Edytuj dane</button>
                  <button type="button" className="button button-secondary" onClick={() => {
                    setEmail(details.email)
                    setEmailOpen((open) => !open)
                  }}>Zmień e-mail</button>
                  <button type="button" className="button button-secondary" disabled={busy} onClick={() => void perform(
                    () => sendUserLink(details.id, details.emailVerifiedAt ? 'reset' : 'activation'),
                    details.emailVerifiedAt ? 'Jeśli konto kwalifikuje się do tej operacji, wysłaliśmy wiadomość.' : 'Jeśli konto kwalifikuje się do tej operacji, wysłaliśmy wiadomość.',
                  )}>{details.emailVerifiedAt ? 'Wyślij link do zmiany hasła' : 'Wyślij link aktywacyjny'}</button>
                  <button type="button" className="button button-secondary" disabled={busy} onClick={() => openConfirmation({
                    kind: 'account',
                    id: details.id,
                    active: !details.isActive,
                    label: details.isActive
                      ? 'Wyłączenie konta zablokuje użytkownikowi dostęp do VELTRA. System nie pozwoli wyłączyć ostatniego administratora.'
                      : 'Użytkownik odzyska możliwość logowania. Cofnięte wcześniej przypisania nie zostaną przywrócone.',
                    requiresPassword: false,
                  })}>{details.isActive ? 'Wyłącz konto' : 'Włącz konto'}</button>
                </section>

                {profileOpen && <form className="admin-user-inline-form" onSubmit={(event) => void submitProfile(event)}>
                  <h4>Dane użytkownika</h4>
                  <div className="create-form-grid">
                    <label>Imię<input required maxLength={100} value={profileForm.firstName} onChange={(event) => setProfileForm({ ...profileForm, firstName: event.target.value })} /></label>
                    <label>Nazwisko<input required maxLength={100} value={profileForm.lastName} onChange={(event) => setProfileForm({ ...profileForm, lastName: event.target.value })} /></label>
                  </div>
                  <button type="submit" className="button button-primary" disabled={busy}>Zapisz dane</button>
                </form>}

                {emailOpen && <form className="admin-user-inline-form" onSubmit={(event) => void submitEmail(event)}>
                  <h4>Zmiana adresu e-mail</h4>
                  <p>Po zmianie użytkownik musi ponownie potwierdzić adres. Dotychczasowe sesje zostaną unieważnione.</p>
                  <label>Nowy adres e-mail<input required type="email" maxLength={254} value={email} onChange={(event) => setEmail(event.target.value)} /></label>
                  <button type="submit" className="button button-primary" disabled={busy}>Zapisz i wyślij link</button>
                </form>}

                {details.roles.some((assignment) => assignment.role === 'BOSS') && (
                  <VeltraDisclosure title="Ograniczenia dostępu">
                    <BossLocationRestrictions key={details.id} userId={details.id} locations={locations}
                      onSaved={() => setDetailsReload((current) => current + 1)}
                      canManage={details.isActive && details.roles.some((assignment) => assignment.role === 'BOSS' && assignment.revokedAt === null)} />
                  </VeltraDisclosure>
                )}

                <VeltraDisclosure title="Role i lokalizacje">
                  <div className="admin-user-section-actions">
                    <button type="button" className="button button-secondary" disabled={!details.isActive} onClick={() => setRoleOpen((open) => !open)}>+ Przypisz rolę</button>
                    <button type="button" className="button button-secondary" disabled={!details.isActive} onClick={() => {
                      setMembershipLocationId(availableMembershipLocations[0]?.id ?? '')
                      setMembershipOpen((open) => !open)
                    }}>+ Przypisz lokalizację</button>
                  </div>
                  {roleOpen && <form className="admin-user-inline-form" onSubmit={(event) => void submitRole(event)}>
                    <h4>Nowa rola</h4>
                    <div className="create-form-grid">
                      <label>Rola
                        <select value={roleForm.role} onChange={(event) => setRoleForm({
                          role: event.target.value as BusinessRole,
                          locationId: '',
                          password: '',
                        })}>
                          {roleValues.map((value) => <option key={value} value={value}>{roleLabels[value]}</option>)}
                        </select>
                      </label>
                      {!globalRole && <label>Lokalizacja
                        <select required value={roleForm.locationId} onChange={(event) => setRoleForm({ ...roleForm, locationId: event.target.value })}>
                          <option value="">Wybierz lokalizację</option>
                          {roleLocations.map((location) => <option key={location.id} value={location.id}>{location.name} — {locationTypeLabel(location)}</option>)}
                        </select>
                      </label>}
                      {roleForm.role === 'ADMINISTRATOR' && <label>Potwierdź aktualnym hasłem
                        <input required type="password" autoComplete="current-password" value={roleForm.password} onChange={(event) => setRoleForm({ ...roleForm, password: event.target.value })} />
                      </label>}
                    </div>
                    <button type="submit" className="button button-primary" disabled={busy}>Zapisz rolę</button>
                  </form>}
                  {membershipOpen && <form className="admin-user-inline-form" onSubmit={(event) => void submitMembership(event)}>
                    <h4>Nowe przypisanie do lokalizacji</h4>
                    <label>Lokalizacja
                      <select required value={membershipLocationId} onChange={(event) => setMembershipLocationId(event.target.value)}>
                        <option value="">Wybierz lokalizację</option>
                        {availableMembershipLocations.map((location) => <option key={location.id} value={location.id}>{location.name} — {locationTypeLabel(location)}</option>)}
                      </select>
                    </label>
                    <button type="submit" className="button button-primary" disabled={busy || !details.isActive || availableMembershipLocations.length === 0}>Zapisz przypisanie</button>
                  </form>}
                  <h4>Przypisane role</h4>
                  {details.roles.length === 0 ? <p>Brak przypisanych ról.</p> : (
                    <ul className="admin-user-record-list">
                      {details.roles.map((assignment) => <li key={assignment.id}>
                        <div><strong>{roleLabels[assignment.role]}</strong><span>{assignment.locationName ?? 'Cała instalacja'}</span><small>{assignment.revokedAt ? 'Rola cofnięta' : 'Rola aktywna'}</small></div>
                        {!assignment.revokedAt && <button type="button" className="button button-quiet" onClick={() => openConfirmation({
                          kind: 'role',
                          id: assignment.id,
                          role: assignment.role,
                          label: assignment.role === 'ADMINISTRATOR'
                            ? 'Cofnięcie roli administratora wymaga aktualnego hasła. Nie można usunąć ostatniego aktywnego administratora.'
                            : `Czy cofnąć rolę „${roleLabels[assignment.role]}”?`,
                          requiresPassword: assignment.role === 'ADMINISTRATOR',
                        })}>Cofnij rolę</button>}
                      </li>)}
                    </ul>
                  )}
                  <h4>Przypisane lokalizacje</h4>
                  {details.memberships.length === 0 ? <p>Brak przypisań do lokalizacji.</p> : (
                    <ul className="admin-user-record-list">
                      {details.memberships.map((membership) => <li key={membership.id}>
                        <div><strong>{membership.locationName}</strong><span>{membership.locationType === 'CENTRAL_KITCHEN' ? 'Kuchnia centralna' : 'Restauracja'}</span>
                          <small>{membership.isActive ? 'Przypisanie aktywne' : 'Przypisanie cofnięte'}{!membership.locationIsActive ? ' · lokalizacja nieaktywna' : ''}</small>
                        </div>
                        {membership.isActive && <button type="button" className="button button-quiet" onClick={() => openConfirmation({
                          kind: 'membership',
                          id: membership.id,
                          label: `Cofnięcie przypisania zablokuje dostęp do lokalizacji „${membership.locationName}”.`,
                          requiresPassword: false,
                        })}>Cofnij przypisanie</button>}
                      </li>)}
                    </ul>
                  )}
                </VeltraDisclosure>

                <VeltraDisclosure title="Uprawnienia">
                  <p>Dodatkowe uprawnienia są niezależne od ról i dotyczą wskazanej lokalizacji oraz okresu.</p>
                  <button type="button" className="button button-secondary" disabled={!details.isActive} onClick={() => {
                    setPermissionForm({ permission: 'FOODCOST_VIEW', locationId: activeRestaurants[0]?.id ?? '' })
                    setPermissionOpen((open) => !open)
                  }}>+ Dodaj dodatkowe uprawnienie</button>
                  {permissionOpen && <form className="admin-user-inline-form" onSubmit={(event) => void submitPermission(event)}>
                    <div className="create-form-grid">
                      <label>Uprawnienie
                        <select value={permissionForm.permission} onChange={(event) => setPermissionForm({ ...permissionForm, permission: event.target.value })}>
                          {delegableOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                      </label>
                      <label>Restauracja
                        <select required value={permissionForm.locationId} onChange={(event) => setPermissionForm({ ...permissionForm, locationId: event.target.value })}>
                          <option value="">Wybierz restaurację</option>
                          {activeRestaurants.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}
                        </select>
                      </label>
                    </div>
                    <p>Dostęp do cen zakupu i kosztów receptur jest nadawany osobno. Moduły oznaczone jako niedostępne nie otrzymają nowych uprawnień.</p>
                    <button type="submit" className="button button-primary" disabled={busy || !details.isActive || activeRestaurants.length === 0}>Zapisz uprawnienie</button>
                  </form>}
                  {details.permissions.length === 0 ? <p>Brak dodatkowych uprawnień i delegacji.</p> : (
                    <ul className="admin-user-record-list">
                      {details.permissions.map((grant) => {
                        const active = assignmentActive(grant.validFrom, grant.validUntil, grant.revokedAt, statusReferenceTime)
                        return <li key={grant.id}>
                          <div>
                            <strong>{permissionLabel(grant.permission)}</strong>
                            <span>{grant.locationName}</span>
                            <small>{grant.revokedAt ? 'Cofnięte' : active ? 'Aktywne' : new Date(grant.validFrom).getTime() > statusReferenceTime ? 'Zaplanowane' : 'Wygasłe'}
                              {' · '}{new Date(grant.validFrom).toLocaleString('pl-PL')}
                              {grant.validUntil ? ` — ${new Date(grant.validUntil).toLocaleString('pl-PL')}` : ''}
                            </small>
                          </div>
                          {!grant.revokedAt && delegableOptions.some((option) => option.value === grant.permission) && (
                            <button type="button" className="button button-quiet" disabled={busy} onClick={() => void perform(
                              () => revokeUserPermission(details.id, grant.locationId, grant.permission),
                              'Dodatkowe uprawnienie zostało cofnięte.',
                            )}>Cofnij</button>
                          )}
                        </li>
                      })}
                    </ul>
                  )}
                </VeltraDisclosure>

                <VeltraDisclosure title="Efektywne uprawnienia">
                  <p>Dostęp wyliczony przez reguły Stage 1D, z uwzględnieniem lokalizacji i kontekstu operacyjnego użytkownika.</p>
                  {details.effectivePermissions.length === 0 ? <p>Brak efektywnych uprawnień w aktywnych kontekstach.</p> : (
                    <ul className="admin-user-record-list">
                      {details.effectivePermissions.map((location) => <li key={location.locationId}>
                        <div>
                          <strong>{location.locationName}</strong>
                          {location.permissions.map((permission) => (
                            <span key={permission.permission}>
                              {permissionLabel(permission.permission)} — kontekst: {permission.operatingContexts.map((context) => context.name).join(', ')}
                            </span>
                          ))}
                        </div>
                      </li>)}
                    </ul>
                  )}
                </VeltraDisclosure>

                <VeltraDisclosure title="Prośby o dostęp">
                  {details.requests.length === 0 ? <p>Brak zapisanych próśb o dostęp.</p> : (
                    <ul className="admin-user-record-list">
                      {details.requests.map((request) => <li key={request.id}>
                        <div><strong>{request.locationName}</strong><span>Prośba od: {request.requesterName || 'Użytkownik'}</span>
                          <small>{request.decision === 'approved' ? 'Zatwierdzona' : request.decision === 'rejected' ? 'Odrzucona' : 'Oczekuje na decyzję'}</small>
                        </div>
                        {request.decision === null && <span className="admin-user-inline-actions">
                          <button type="button" className="button button-secondary" disabled={busy} onClick={() => void perform(
                            () => decideUserRequest(request.id, true), 'Prośba o dostęp została zatwierdzona.',
                          )}>Zatwierdź</button>
                          <button type="button" className="button button-quiet" disabled={busy} onClick={() => void perform(
                            () => decideUserRequest(request.id, false), 'Prośba o dostęp została odrzucona.',
                          )}>Odrzuć</button>
                        </span>}
                      </li>)}
                    </ul>
                  )}
                </VeltraDisclosure>

                <VeltraDisclosure title="Historia zmian">
                  {details.history.length === 0 ? <p>Brak zapisanych zmian.</p> : (
                    <ol className="admin-user-history">
                      {details.history.map((event, index) => <li key={`${event.occurredAt}-${index}`}>
                        <strong>{historyLabel(event.action)}</strong>
                        <span>{event.actorName}{event.locationName ? ` · ${event.locationName}` : ''}</span>
                        <time dateTime={event.occurredAt}>{new Date(event.occurredAt).toLocaleString('pl-PL')}</time>
                        {event.outcome === 'rejected' && <small>Operacja nie została wykonana.</small>}
                      </li>)}
                    </ol>
                  )}
                </VeltraDisclosure>
              </>
            )}
          </section>
        )}
      </AnimatedPanel>

      {confirmation && <Drawer
        labelledBy="admin-user-confirmation-title"
        descriptionId="admin-user-confirmation-description"
        dialogRole="alertdialog"
        returnFocus={confirmationReturnFocus}
        initialFocusRef={confirmation.requiresPassword ? confirmationPasswordRef : confirmationButtonRef}
        width="min(32rem, calc(100vw - 2rem))"
        className="admin-user-confirmation-dialog"
        onClose={closeConfirmation}
      >
        <h3 id="admin-user-confirmation-title">Potwierdź zmianę</h3>
        <p id="admin-user-confirmation-description">{confirmation.label}</p>
        {confirmation.requiresPassword && <label>Aktualne hasło
          <input ref={confirmationPasswordRef} type="password" autoComplete="current-password" value={confirmationPassword} onChange={(event) => setConfirmationPassword(event.target.value)} />
        </label>}
        <div className="admin-user-form-actions">
          <button ref={confirmationButtonRef} type="button" className="button button-danger" disabled={busy || (confirmation.requiresPassword && !confirmationPassword)} onClick={() => void confirmChange()}>Potwierdź</button>
          <button type="button" className="button button-secondary" onClick={closeConfirmation}>Anuluj</button>
        </div>
      </Drawer>}
    </section>
  )
}
