import { isRecord, request, requestEnvelope } from '../api'
import { avatarFromResponse, type AvatarInfo } from '../avatars/avatarTypes'
import { parseRawPermissionData, type RawPermissionData } from './rawPermissionsApi'

const API = '/api/v1/administration'
const SECURITY = '/api/v1/security'
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export const roleValues = [
  'ADMINISTRATOR',
  'CENTRAL_KITCHEN_CHEF',
  'BOSS',
  'RESTAURANT_MANAGER',
  'RESTAURANT_CHEF',
  'EMPLOYEE',
] as const

export type BusinessRole = typeof roleValues[number]
export type UserStatus = 'active' | 'inactive' | 'all'
export type UserSort = 'name' | 'email' | 'status'

export type UserLocation = {
  id: string
  name: string
  type: 'CENTRAL_KITCHEN' | 'RESTAURANT'
  isActive?: boolean
}

export type UserRoleSummary = {
  role: BusinessRole
  locationId: string | null
  locationName: string | null
}

export type UserRow = {
  id: string
  firstName: string
  lastName: string
  email: string
  avatar: AvatarInfo
  emailVerifiedAt: string | null
  isActive: boolean
  roles: UserRoleSummary[]
  locations: UserLocation[]
}

export type UserDirectoryPage = {
  data: UserRow[]
  meta: { currentPage: number; perPage: number; total: number; lastPage: number }
}

export type UserListQuery = {
  search: string
  role: BusinessRole | ''
  locationId: string
  status: UserStatus
  sort: UserSort
  direction: 'asc' | 'desc'
  page: number
  perPage: 25 | 50 | 100
}

export type UserDetails = Omit<UserRow, 'roles'> & {
  rawPermissions: RawPermissionData
  roles: Array<UserRoleSummary & {
    id: string
    grantedAt: string
    revokedAt: string | null
  }>
  memberships: Array<{
    id: string
    locationId: string
    locationName: string
    locationType: UserLocation['type']
    locationIsActive: boolean
    isActive: boolean
    assignedAt: string
    deactivatedAt: string | null
  }>
  permissions: Array<{
    id: string
    permission: string
    locationId: string
    locationName: string
    validFrom: string
    validUntil: string | null
    revokedAt: string | null
  }>
  requests: Array<{
    id: string
    locationId: string
    locationName: string
    requesterName: string
    decision: 'approved' | 'rejected' | null
    deciderName: string | null
    requestedAt: string
    decidedAt: string | null
  }>
  history: Array<{
    action: string
    outcome: 'accepted' | 'rejected'
    occurredAt: string
    actorName: string
    locationName: string | null
  }>
  effectivePermissions: Array<{
    locationId: string
    locationName: string
    permissions: Array<{
      permission: string
      operatingContexts: Array<{ id: string; name: string }>
    }>
  }>
}

function requiredString(value: unknown, label: string): string {
  if (typeof value !== 'string' || value.length === 0) throw new Error(`Nieprawidłowe dane: ${label}.`)
  return value
}

function nullableString(value: unknown, label: string): string | null {
  if (value === null) return null
  return requiredString(value, label)
}

function nullableDisplayName(value: unknown, label: string): string | null {
  if (value === null || value === '') return null
  return requiredString(value, label)
}

function parseRole(value: unknown): BusinessRole {
  const role = roleValues.find((candidate) => candidate === value)
  if (!role) throw new Error('Backend zwrócił nieznaną rolę użytkownika.')
  return role
}

function parseLocation(value: unknown): UserLocation {
  if (!isRecord(value) || typeof value.id !== 'string' || !UUID.test(value.id)
    || (value.type !== 'CENTRAL_KITCHEN' && value.type !== 'RESTAURANT')) {
    throw new Error('Backend zwrócił nieprawidłowe przypisanie do lokalizacji.')
  }
  if ('is_active' in value && typeof value.is_active !== 'boolean') {
    throw new Error('Backend zwrócił nieprawidłowy status lokalizacji.')
  }
  return {
    id: value.id,
    name: requiredString(value.name, 'lokalizacja'),
    type: value.type,
    ...(typeof value.is_active === 'boolean' ? { isActive: value.is_active } : {}),
  }
}

function parseRoleSummary(value: unknown): UserRoleSummary {
  if (!isRecord(value)) throw new Error('Backend zwrócił nieprawidłowe przypisanie roli.')
  const locationId = nullableString(value.location_id, 'lokalizacja roli')
  if (locationId !== null && !UUID.test(locationId)) throw new Error('Backend zwrócił nieprawidłową lokalizację roli.')
  return {
    role: parseRole(value.role),
    locationId,
    locationName: nullableString(value.location_name, 'nazwa lokalizacji roli'),
  }
}

function parseUser(value: unknown): UserRow {
  if (!isRecord(value) || !Array.isArray(value.roles) || !Array.isArray(value.locations)
    || typeof value.is_active !== 'boolean') {
    throw new Error('Backend zwrócił nieprawidłowe dane użytkownika.')
  }
  const id = requiredString(value.id, 'identyfikator użytkownika')
  if (!UUID.test(id)) throw new Error('Backend zwrócił nieprawidłowe dane użytkownika.')
  return {
    id,
    firstName: requiredString(value.first_name, 'imię'),
    lastName: requiredString(value.last_name, 'nazwisko'),
    email: requiredString(value.email, 'e-mail'),
    avatar: avatarFromResponse(value),
    emailVerifiedAt: nullableString(value.email_verified_at, 'weryfikacja e-mail'),
    isActive: value.is_active,
    roles: value.roles.map(parseRoleSummary),
    locations: value.locations.map(parseLocation),
  }
}

export async function fetchUserDirectory(query: UserListQuery, signal?: AbortSignal): Promise<UserDirectoryPage> {
  const params = new URLSearchParams({
    status: query.status,
    sort: query.sort,
    direction: query.direction,
    page: String(query.page),
    per_page: String(query.perPage),
  })
  if (query.search.trim()) params.set('search', query.search.trim())
  if (query.role) params.set('role', query.role)
  if (query.locationId) params.set('location_id', query.locationId)
  const response = await requestEnvelope(`${API}/users?${params}`, 'GET', undefined, { signal })
  if (!Array.isArray(response.data) || !isRecord(response.meta)
    || !Number.isInteger(response.meta.current_page) || !Number.isInteger(response.meta.per_page)
    || !Number.isInteger(response.meta.total) || !Number.isInteger(response.meta.last_page)) {
    throw new Error('Backend zwrócił nieprawidłową listę użytkowników.')
  }
  return {
    data: response.data.map(parseUser),
    meta: {
      currentPage: Number(response.meta.current_page),
      perPage: Number(response.meta.per_page),
      total: Number(response.meta.total),
      lastPage: Number(response.meta.last_page),
    },
  }
}

export async function fetchUserLocations(signal?: AbortSignal): Promise<UserLocation[]> {
  const result = await request(`${API}/locations`, 'GET', undefined, { signal })
  if (!Array.isArray(result)) throw new Error('Backend zwrócił nieprawidłową listę lokalizacji.')
  return result.map((value) => {
    if (!isRecord(value) || typeof value.is_active !== 'boolean') throw new Error('Backend zwrócił nieprawidłową lokalizację.')
    return parseLocation(value)
  })
}

export async function fetchUserDetails(id: string, signal?: AbortSignal): Promise<UserDetails> {
  const result = await request(`${API}/users/${encodeURIComponent(id)}`, 'GET', undefined, { signal })
  if (!isRecord(result) || !Array.isArray(result.roles) || !Array.isArray(result.memberships)
    || !Array.isArray(result.permissions) || !Array.isArray(result.requests) || !Array.isArray(result.history)
    || !Array.isArray(result.effective_permissions)) {
    throw new Error('Backend zwrócił nieprawidłowe szczegóły użytkownika.')
  }
  const rawPermissions = parseRawPermissionData(result.raw_permissions)
  const base = parseUser({
    ...result,
    roles: result.roles.map((role) => isRecord(role) ? {
      role: role.role, location_id: role.location_id, location_name: role.location_name,
    } : role),
    locations: result.memberships.filter((membership) => isRecord(membership) && membership.is_active)
      .map((membership) => isRecord(membership) ? {
        id: membership.location_id,
        name: membership.location_name,
        type: membership.location_type,
      } : membership),
  })
  return {
    ...base,
    rawPermissions,
    roles: result.roles.map((value) => {
      if (!isRecord(value) || !UUID.test(requiredString(value.id, 'przypisanie roli'))) {
        throw new Error('Backend zwrócił nieprawidłowe przypisanie roli.')
      }
      const id = requiredString(value.id, 'przypisanie roli')
      if (!UUID.test(id)) throw new Error('Backend zwrócił nieprawidłowe przypisanie roli.')
      return {
        ...parseRoleSummary(value),
        id,
        grantedAt: requiredString(value.granted_at, 'data przypisania roli'),
        revokedAt: nullableString(value.revoked_at, 'data cofnięcia roli'),
      }
    }),
    memberships: result.memberships.map((value) => {
      if (!isRecord(value) || typeof value.is_active !== 'boolean' || typeof value.location_is_active !== 'boolean') {
        throw new Error('Backend zwrócił nieprawidłowe przypisanie lokalizacji.')
      }
      const locationId = requiredString(value.location_id, 'lokalizacja')
      if (!UUID.test(locationId)) throw new Error('Backend zwrócił nieprawidłową lokalizację.')
      return {
        id: requiredString(value.id, 'przypisanie lokalizacji'),
        locationId,
        locationName: requiredString(value.location_name, 'nazwa lokalizacji'),
        locationType: value.location_type === 'CENTRAL_KITCHEN' || value.location_type === 'RESTAURANT'
          ? value.location_type : (() => { throw new Error('Backend zwrócił nieprawidłowy typ lokalizacji.') })(),
        locationIsActive: value.location_is_active,
        isActive: value.is_active,
        assignedAt: requiredString(value.assigned_at, 'data przypisania'),
        deactivatedAt: nullableString(value.deactivated_at, 'data cofnięcia przypisania'),
      }
    }),
    permissions: result.permissions.map((value) => {
      if (!isRecord(value)) throw new Error('Backend zwrócił nieprawidłowe uprawnienie.')
      return {
        id: requiredString(value.id, 'uprawnienie'),
        permission: requiredString(value.permission, 'typ uprawnienia'),
        locationId: requiredString(value.location_id, 'lokalizacja uprawnienia'),
        locationName: requiredString(value.location_name, 'nazwa lokalizacji uprawnienia'),
        validFrom: requiredString(value.valid_from, 'początek uprawnienia'),
        validUntil: nullableString(value.valid_until, 'koniec uprawnienia'),
        revokedAt: nullableString(value.revoked_at, 'cofnięcie uprawnienia'),
      }
    }),
    requests: result.requests.map((value) => {
      if (!isRecord(value) || (value.decision !== null && value.decision !== 'approved' && value.decision !== 'rejected')) {
        throw new Error('Backend zwrócił nieprawidłowy wniosek o dostęp.')
      }
      return {
        id: requiredString(value.id, 'wniosek'),
        locationId: requiredString(value.location_id, 'lokalizacja wniosku'),
        locationName: requiredString(value.location_name, 'nazwa lokalizacji wniosku'),
        requesterName: typeof value.requester_name === 'string'
          ? value.requester_name : (() => { throw new Error('Backend zwrócił nieprawidłowego wnioskodawcę.') })(),
        decision: value.decision,
        deciderName: nullableDisplayName(value.decider_name, 'osoba rozpatrująca wniosek'),
        requestedAt: requiredString(value.requested_at, 'data wniosku'),
        decidedAt: nullableString(value.decided_at, 'data rozpatrzenia wniosku'),
      }
    }),
    history: result.history.map((value) => {
      if (!isRecord(value) || (value.outcome !== 'accepted' && value.outcome !== 'rejected')) {
        throw new Error('Backend zwrócił nieprawidłowy wpis historii.')
      }
      return {
        action: requiredString(value.action, 'rodzaj zmiany'),
        outcome: value.outcome,
        occurredAt: requiredString(value.occurred_at, 'data zmiany'),
        actorName: requiredString(value.actor_name, 'osoba wykonująca zmianę'),
        locationName: nullableString(value.location_name, 'lokalizacja zmiany'),
      }
    }),
    effectivePermissions: result.effective_permissions.map((value) => {
      if (!isRecord(value) || !Array.isArray(value.permissions)) {
        throw new Error('Backend zwrócił nieprawidłowe efektywne uprawnienia.')
      }
      const locationId = requiredString(value.location_id, 'lokalizacja efektywnego uprawnienia')
      if (!UUID.test(locationId)) throw new Error('Backend zwrócił nieprawidłową lokalizację efektywnego uprawnienia.')
      return {
        locationId,
        locationName: requiredString(value.location_name, 'nazwa lokalizacji efektywnego uprawnienia'),
        permissions: value.permissions.map((item) => {
          if (!isRecord(item) || !Array.isArray(item.operating_contexts)) {
            throw new Error('Backend zwrócił nieprawidłowe efektywne uprawnienie.')
          }
          return {
            permission: requiredString(item.permission, 'typ efektywnego uprawnienia'),
            operatingContexts: item.operating_contexts.map((context) => {
              if (!isRecord(context)) throw new Error('Backend zwrócił nieprawidłowy kontekst uprawnienia.')
              const contextId = requiredString(context.id, 'kontekst uprawnienia')
              if (!UUID.test(contextId)) throw new Error('Backend zwrócił nieprawidłowy kontekst uprawnienia.')
              return {
                id: contextId,
                name: requiredString(context.name, 'nazwa kontekstu uprawnienia'),
              }
            }),
          }
        }),
      }
    }),
  }
}

export async function createUser(locationId: string, profile: { first_name: string; last_name: string; email: string; role: BusinessRole; current_password?: string }): Promise<void> {
  await request(`${SECURITY}/locations/${encodeURIComponent(locationId)}/personnel`, 'POST', profile)
}

export async function updateUserProfile(id: string, profile: { first_name: string; last_name: string }): Promise<void> {
  await request(`${API}/users/${encodeURIComponent(id)}/profile`, 'PATCH', profile)
}

export async function changeUserEmail(id: string, email: string): Promise<void> {
  await request(`${SECURITY}/users/${encodeURIComponent(id)}/email`, 'PATCH', { email })
}

export async function setUserActive(id: string, isActive: boolean): Promise<void> {
  await request(`${SECURITY}/users/${encodeURIComponent(id)}/status`, 'PATCH', { is_active: isActive })
}

export async function sendUserLink(id: string, kind: 'activation' | 'reset'): Promise<void> {
  await request(`${SECURITY}/users/${encodeURIComponent(id)}/links`, 'POST', { kind })
}

export async function grantUserRole(id: string, role: BusinessRole, locationId: string | null, currentPassword?: string): Promise<void> {
  await request(`${SECURITY}/users/${encodeURIComponent(id)}/roles`, 'POST', {
    role,
    location_id: locationId,
    ...(currentPassword ? { current_password: currentPassword } : {}),
  })
}

export async function replaceUserRole(id: string, role: BusinessRole, locationIds: string[], currentPassword?: string): Promise<void> {
  await request(`${SECURITY}/users/${encodeURIComponent(id)}/role`, 'PUT', {
    role,
    location_ids: locationIds,
    ...(currentPassword ? { current_password: currentPassword } : {}),
  })
}

export async function revokeUserRole(id: string, currentPassword?: string): Promise<void> {
  await request(`${SECURITY}/roles/${encodeURIComponent(id)}`, 'DELETE', currentPassword ? { current_password: currentPassword } : {})
}

export async function assignUserLocation(userId: string, locationId: string): Promise<void> {
  await request(`${SECURITY}/users/${encodeURIComponent(userId)}/locations/${encodeURIComponent(locationId)}/membership`, 'POST', {})
}

export async function removeUserLocation(membershipId: string): Promise<void> {
  await request(`${SECURITY}/memberships/${encodeURIComponent(membershipId)}`, 'DELETE', {})
}

export async function grantUserPermission(
  userId: string,
  locationId: string,
  permission: string,
  validFrom?: string,
  validUntil?: string,
): Promise<void> {
  await request(`${SECURITY}/users/${encodeURIComponent(userId)}/locations/${encodeURIComponent(locationId)}/permissions`, 'POST', {
    permission,
    ...(validFrom ? { valid_from: validFrom } : {}),
    ...(validUntil ? { valid_until: validUntil } : {}),
  })
}

export async function revokeUserPermission(userId: string, locationId: string, permission: string): Promise<void> {
  await request(`${SECURITY}/users/${encodeURIComponent(userId)}/locations/${encodeURIComponent(locationId)}/permissions`, 'DELETE', { permission })
}

export async function decideUserRequest(id: string, approve: boolean): Promise<void> {
  await request(`${SECURITY}/membership-requests/${encodeURIComponent(id)}/decision`, 'POST', { approve })
}
