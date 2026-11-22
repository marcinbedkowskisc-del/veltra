import { isRecord, request } from '../api'

const RAW = '/api/v1/raw-materials'
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export const rawPermissionOptions = [
  { value: 'RAW_MATERIALS_VIEW', label: 'Odczyt katalogu surowców' },
  { value: 'RAW_MATERIALS_CREATE', label: 'Tworzenie surowców' },
  { value: 'RAW_MATERIALS_EDIT', label: 'Edycja surowców' },
  { value: 'RAW_MATERIALS_SUBMIT', label: 'Przekazywanie do zatwierdzenia' },
  { value: 'RAW_MATERIALS_REVIEW', label: 'Przeglądanie wniosków' },
  { value: 'RAW_MATERIALS_APPROVE', label: 'Zatwierdzanie surowców' },
  { value: 'RAW_MATERIALS_BLOCK', label: 'Blokowanie surowców' },
  { value: 'RAW_MATERIALS_UNBLOCK', label: 'Odblokowywanie surowców' },
  { value: 'RAW_MATERIALS_ARCHIVE', label: 'Archiwizacja surowców' },
  { value: 'RAW_MATERIALS_RESTORE', label: 'Przywracanie surowców z archiwum' },
  { value: 'RAW_MATERIALS_DELETE_DRAFT', label: 'Usuwanie szkiców' },
  { value: 'RAW_MATERIALS_CATEGORY_MANAGE', label: 'Zarządzanie kategoriami' },
  { value: 'RAW_MATERIALS_SUPPLIER_VIEW', label: 'Odczyt dostawców' },
  { value: 'RAW_MATERIALS_SUPPLIER_MANAGE', label: 'Zarządzanie dostawcami' },
  { value: 'RAW_MATERIALS_PURCHASING_COSTS_VIEW', label: 'Podgląd cen zakupu' },
  { value: 'RAW_MATERIALS_PURCHASING_COSTS_MANAGE', label: 'Zarządzanie cenami zakupu' },
  { value: 'RAW_MATERIALS_REFERENCE_PRICE_VIEW', label: 'Podgląd cen referencyjnych' },
  { value: 'RAW_MATERIALS_REFERENCE_PRICE_MANAGE', label: 'Zarządzanie cenami referencyjnymi' },
  { value: 'RAW_MATERIALS_EXCHANGE_RATE_VIEW', label: 'Podgląd kursów walut' },
  { value: 'RAW_MATERIALS_EXCHANGE_RATE_MANAGE', label: 'Zarządzanie kursami walut' },
  { value: 'RAW_MATERIALS_ALLERGEN_MANAGE', label: 'Zarządzanie alergenami' },
  { value: 'RAW_MATERIALS_STORAGE_MANAGE', label: 'Zarządzanie warunkami przechowywania' },
  { value: 'RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE', label: 'Zatwierdzanie zgodności dostawców' },
  { value: 'RAW_MATERIALS_AUDIT_VIEW', label: 'Podgląd audytu RAW' },
  { value: 'RAW_MATERIALS_PERMISSION_DELEGATE', label: 'Delegowanie uprawnień RAW' },
] as const

export type RawPermissionId = typeof rawPermissionOptions[number]['value']
export type RawPermissionStatus = 'EFFECTIVE' | 'INACTIVE' | 'FUTURE' | 'EXPIRED' | 'REVOKED'
export type RawPermissionSource = 'BOOTSTRAP' | 'SYSTEM' | 'DELEGATED'

export type RawPermissionLineage = {
  recipientName: string
  grantedByName: string
  grantedAt: string
  source: RawPermissionSource
  revokedAt: string | null
  revokedByName: string | null
}

export type RawPermissionGrant = {
  id: string
  permission: RawPermissionId
  source: RawPermissionSource
  grantedByName: string
  grantedAt: string
  validFrom: string
  validUntil: string | null
  revokedAt: string | null
  revokedByName: string | null
  isEffective: boolean
  status: RawPermissionStatus
  lineage: RawPermissionLineage[]
}

export type RawPermissionHistoryEvent = {
  grantId: string
  permission: RawPermissionId
  action: 'GRANTED' | 'REVOKED'
  actorName: string
  recipientName: string
  occurredAt: string
}

export type RawPermissionData = {
  grants: RawPermissionGrant[]
  history: RawPermissionHistoryEvent[]
}

const permissionValues = new Set<string>(rawPermissionOptions.map(({ value }) => value))
const statuses = new Set<RawPermissionStatus>(['EFFECTIVE', 'INACTIVE', 'FUTURE', 'EXPIRED', 'REVOKED'])
const sources = new Set<RawPermissionSource>(['BOOTSTRAP', 'SYSTEM', 'DELEGATED'])

function requiredString(value: unknown, label: string): string {
  if (typeof value !== 'string' || value.length === 0) throw new Error(`Nieprawidłowe dane RAW: ${label}.`)
  return value
}

function dateString(value: unknown, label: string): string {
  const result = requiredString(value, label)
  if (!Number.isFinite(Date.parse(result))) throw new Error(`Nieprawidłowa data RAW: ${label}.`)
  return result
}

function nullableString(value: unknown, label: string): string | null {
  if (value === null) return null
  return requiredString(value, label)
}

function permissionId(value: unknown): RawPermissionId {
  if (typeof value !== 'string' || !permissionValues.has(value)) {
    throw new Error('Backend zwrócił nieznane uprawnienie RAW.')
  }
  return value as RawPermissionId
}

function source(value: unknown): RawPermissionSource {
  if (typeof value !== 'string' || !sources.has(value as RawPermissionSource)) {
    throw new Error('Backend zwrócił nieznane źródło uprawnienia RAW.')
  }
  return value as RawPermissionSource
}

function parseLineage(value: unknown): RawPermissionLineage {
  if (!isRecord(value) || typeof value.source !== 'string' || !sources.has(value.source as RawPermissionSource)) {
    throw new Error('Backend zwrócił nieprawidłową linię delegowania RAW.')
  }
  return {
    recipientName: requiredString(value.recipient_name, 'odbiorca delegacji'),
    grantedByName: requiredString(value.granted_by_name, 'grantor delegacji'),
    grantedAt: dateString(value.granted_at, 'data nadania w linii delegowania'),
    source: source(value.source),
    revokedAt: value.revoked_at === null ? null : dateString(value.revoked_at, 'data cofnięcia w linii delegowania'),
    revokedByName: nullableString(value.revoked_by_name, 'osoba cofająca delegację'),
  }
}

function parseGrant(value: unknown): RawPermissionGrant {
  if (!isRecord(value) || typeof value.is_effective !== 'boolean' || !Array.isArray(value.lineage)
    || typeof value.status !== 'string' || !statuses.has(value.status as RawPermissionStatus)) {
    throw new Error('Backend zwrócił nieprawidłowy grant RAW.')
  }
  const id = requiredString(value.id, 'identyfikator grantu')
  if (!UUID.test(id)) throw new Error('Backend zwrócił nieprawidłowy grant RAW.')
  const status = value.status as RawPermissionStatus
  if (value.is_effective !== (status === 'EFFECTIVE')) throw new Error('Backend zwrócił niespójny status grantu RAW.')
  return {
    id,
    permission: permissionId(value.permission),
    source: source(value.source),
    grantedByName: requiredString(value.granted_by_name, 'osoba nadająca uprawnienie'),
    grantedAt: dateString(value.granted_at, 'data nadania'),
    validFrom: dateString(value.valid_from, 'początek obowiązywania'),
    validUntil: value.valid_until === null ? null : dateString(value.valid_until, 'koniec obowiązywania'),
    revokedAt: value.revoked_at === null ? null : dateString(value.revoked_at, 'data cofnięcia'),
    revokedByName: nullableString(value.revoked_by_name, 'osoba cofająca uprawnienie'),
    isEffective: value.is_effective,
    status,
    lineage: value.lineage.map(parseLineage),
  }
}

function parseHistoryEvent(value: unknown): RawPermissionHistoryEvent {
  if (!isRecord(value) || (value.action !== 'GRANTED' && value.action !== 'REVOKED')) {
    throw new Error('Backend zwrócił nieprawidłowe zdarzenie historii RAW.')
  }
  const grantId = requiredString(value.grant_id, 'identyfikator grantu w historii')
  if (!UUID.test(grantId)) throw new Error('Backend zwrócił nieprawidłowe zdarzenie historii RAW.')
  return {
    grantId,
    permission: permissionId(value.permission),
    action: value.action,
    actorName: requiredString(value.actor_name, 'osoba wykonująca zmianę RAW'),
    recipientName: requiredString(value.recipient_name, 'odbiorca zmiany RAW'),
    occurredAt: dateString(value.occurred_at, 'data zmiany RAW'),
  }
}

export function parseRawPermissionData(value: unknown): RawPermissionData {
  if (!isRecord(value) || !Array.isArray(value.grants) || !Array.isArray(value.history)) {
    throw new Error('Backend zwrócił nieprawidłowy wykaz uprawnień RAW.')
  }
  return {
    grants: value.grants.map(parseGrant),
    history: value.history.map(parseHistoryEvent),
  }
}

export function rawPermissionLabel(permission: RawPermissionId): string {
  return rawPermissionOptions.find((option) => option.value === permission)?.label ?? permission
}

export const rawDelegablePermissionOptions = rawPermissionOptions.filter(({ value }) => (
  value !== 'RAW_MATERIALS_DELETE_DRAFT' && value !== 'RAW_MATERIALS_PERMISSION_DELEGATE'
))

export async function grantRawPermission(
  userId: string,
  permission: RawPermissionId,
  reason: string,
  validFrom?: string,
  validUntil?: string,
): Promise<void> {
  await request(`${RAW}/permissions/${encodeURIComponent(userId)}`, 'POST', {
    permission,
    reason,
    ...(validFrom ? { valid_from: validFrom } : {}),
    ...(validUntil ? { valid_until: validUntil } : {}),
  })
}

export async function revokeRawPermission(grantId: string, reason: string): Promise<void> {
  await request(`${RAW}/permissions/${encodeURIComponent(grantId)}`, 'DELETE', { reason })
}
