import { useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import Drawer from '../design-system/Drawer'
import {
  grantRawPermission,
  rawDelegablePermissionOptions,
  rawPermissionLabel,
  revokeRawPermission,
  type RawPermissionData,
  type RawPermissionGrant,
  type RawPermissionId,
} from './rawPermissionsApi'

type Props = {
  userId: string
  userName: string
  targetEligible: boolean
  targetLimitation: string | null
  permissions: RawPermissionData
  onChanged: () => void
  onSessionExpired?: () => void
}

type Confirmation =
  | { kind: 'grant' }
  | { kind: 'revoke'; grant: RawPermissionGrant }

const sourceLabels = {
  BOOTSTRAP: 'Nadanie początkowe',
  SYSTEM: 'Nadanie systemowe',
  DELEGATED: 'Delegacja',
} as const

const statusLabels = {
  EFFECTIVE: 'Efektywne obecnie',
  INACTIVE: 'Przypisane, ale nieefektywne',
  FUTURE: 'Zaplanowane',
  EXPIRED: 'Wygasłe',
  REVOKED: 'Cofnięte',
} as const

function formatDate(value: string | null): string {
  return value === null ? 'Bez terminu końcowego' : new Date(value).toLocaleString('pl-PL')
}

function mutationError(cause: unknown): string {
  if (cause instanceof ApiError) {
    if (cause.status === 403) {
      return 'Operację zablokowały istniejące zasady RAW. Wymagane są efektywne uprawnienie do delegowania i źródłowe prawo; rola Administratora PA sama nie wystarcza.'
    }
    if (cause.status === 503 && cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE') {
      return 'Nadawanie i cofanie przez istniejącą ścieżkę RAW wymaga gotowości modułu RAW_MATERIALS. Odczyt grantów w PA pozostaje dostępny.'
    }
    const validationMessage = Object.values(cause.errors).flat()[0]
    return validationMessage ?? cause.message
  }
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji RAW.'
}

export default function RawPermissionsManagement({
  userId,
  userName,
  targetEligible,
  targetLimitation,
  permissions,
  onChanged,
  onSessionExpired,
}: Props) {
  const [grantOpen, setGrantOpen] = useState(false)
  const [permission, setPermission] = useState<RawPermissionId>(rawDelegablePermissionOptions[0].value)
  const [reason, setReason] = useState('')
  const [validFrom, setValidFrom] = useState('')
  const [validUntil, setValidUntil] = useState('')
  const [pendingRevoke, setPendingRevoke] = useState<RawPermissionGrant | null>(null)
  const [revokeReason, setRevokeReason] = useState('')
  const [confirmation, setConfirmation] = useState<Confirmation | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [returnFocus, setReturnFocus] = useState<HTMLElement | null>(null)
  const grantConfirmRef = useRef<HTMLButtonElement>(null)
  const revokeReasonRef = useRef<HTMLTextAreaElement>(null)

  function showConfirmation(next: Confirmation, trigger: HTMLElement) {
    setError(null)
    setReturnFocus(trigger)
    setConfirmation(next)
  }

  function closeConfirmation() {
    setConfirmation(null)
    setPendingRevoke(null)
    setRevokeReason('')
  }

  function requestGrant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const trigger = event.currentTarget.querySelector<HTMLButtonElement>('[data-raw-grant-confirm-trigger]')
    if (trigger) showConfirmation({ kind: 'grant' }, trigger)
  }

  async function confirmGrant() {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      await grantRawPermission(userId, permission, reason.trim(), validFrom || undefined, validUntil || undefined)
      setConfirmation(null)
      setGrantOpen(false)
      setReason('')
      setValidFrom('')
      setValidUntil('')
      setNotice('Uprawnienie RAW zostało nadane. Lista została odświeżona.')
      onChanged()
    } catch (cause) {
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired?.()
      setError(mutationError(cause))
    } finally {
      setBusy(false)
    }
  }

  async function confirmRevoke() {
    if (!pendingRevoke) return
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      await revokeRawPermission(pendingRevoke.id, revokeReason.trim())
      setConfirmation(null)
      setPendingRevoke(null)
      setRevokeReason('')
      setNotice('Grant RAW został cofnięty. Lista została odświeżona.')
      onChanged()
    } catch (cause) {
      if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) onSessionExpired?.()
      setError(mutationError(cause))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="raw-permissions" aria-label="Uprawnienia RAW użytkownika">
      <p>Uprawnienia RAW są przypisane do organizacji, niezależnie od lokalizacji. „Efektywne” oznacza, że grant i jego źródła są obecnie ważne; gotowość modułu jest odrębnym warunkiem korzystania z funkcji.</p>
      <p className="raw-permissions-limitation">
        Rola Administratora PA nie nadaje uprawnień RAW. Nadawanie i cofanie podlega istniejącej delegacji RAW oraz jej źródłowym uprawnieniom.
        Odczyt tej listy nie zależy od gotowości RAW_MATERIALS.
      </p>
      {targetLimitation && <p className="raw-permissions-limitation">{targetLimitation}</p>}
      {error && !confirmation && <p className="error" role="alert">{error}</p>}
      {notice && <p className="success" role="status">{notice}</p>}

      <div className="admin-user-section-actions">
        <button type="button" className="button button-secondary" disabled={!targetEligible || busy}
          aria-expanded={grantOpen} onClick={() => {
            setError(null)
            setNotice(null)
            setGrantOpen((open) => !open)
          }}>
          {grantOpen ? 'Zamknij nadawanie RAW' : 'Nadaj uprawnienie RAW'}
        </button>
      </div>
      {grantOpen && <form className="admin-user-inline-form raw-permission-form" onSubmit={requestGrant}>
        <h4>Nowy grant organizacyjny</h4>
        <div className="create-form-grid">
          <label>Uprawnienie RAW
            <select value={permission} onChange={(event) => setPermission(event.target.value as RawPermissionId)}>
              {rawDelegablePermissionOptions.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          <label>Obowiązuje od (opcjonalnie)
            <input type="datetime-local" value={validFrom} onChange={(event) => setValidFrom(event.target.value)} />
          </label>
          <label>Obowiązuje do (opcjonalnie)
            <input type="datetime-local" value={validUntil} onChange={(event) => setValidUntil(event.target.value)} />
          </label>
        </div>
        <label>Powód nadania
          <input required minLength={3} maxLength={80} value={reason} onChange={(event) => setReason(event.target.value)} />
        </label>
        <p>Backend sprawdzi, czy delegujący ma aktywne uprawnienie RAW_MATERIALS_PERMISSION_DELEGATE oraz efektywne prawo źródłowe o wystarczającym okresie ważności.</p>
        <button type="submit" className="button button-primary" disabled={busy || !targetEligible}
          data-raw-grant-confirm-trigger>
          Dalej — potwierdź nadanie
        </button>
      </form>}

      {permissions.grants.length === 0 ? <p>Brak zapisanych grantów RAW dla tego użytkownika.</p> : (
        <ul className="admin-user-record-list raw-permission-list">
          {permissions.grants.map((grant) => (
            <li key={grant.id}>
              <div>
                <strong>{rawPermissionLabel(grant.permission)}</strong>
                <span>{grant.isEffective ? 'Przypisane · efektywne' : `Przypisane · ${statusLabels[grant.status]}`}</span>
                <small>Źródło: {sourceLabels[grant.source]} · nadał(a): {grant.grantedByName}</small>
                <small>Nadano: {formatDate(grant.grantedAt)} · obowiązuje: {formatDate(grant.validFrom)} — {formatDate(grant.validUntil)}</small>
                {grant.revokedAt && <small>Cofnięto: {formatDate(grant.revokedAt)} · przez: {grant.revokedByName ?? 'System'}</small>}
                {grant.lineage.length > 0 && <span className="raw-permission-lineage">
                  Ścieżka delegowania: {grant.lineage.map((item) => `${item.grantedByName} → ${item.recipientName}`).join(' → ')}
                  {' → '}{grant.grantedByName} → {userName}
                </span>}
                {grant.lineage.some((item) => item.revokedAt !== null) && (
                  <small>Co najmniej jeden grant źródłowy w tej ścieżce został cofnięty.</small>
                )}
              </div>
              {grant.status !== 'REVOKED' && <button type="button" className="button button-quiet" disabled={busy}
                onClick={(event) => {
                  setPendingRevoke(grant)
                  setRevokeReason('')
                  showConfirmation({ kind: 'revoke', grant }, event.currentTarget)
                }}>
                Cofnij grant
              </button>}
            </li>
          ))}
        </ul>
      )}

      <h4>Historia zmian uprawnień RAW</h4>
      {permissions.history.length === 0 ? <p>Brak zapisanej historii grantów RAW.</p> : (
        <ol className="admin-user-history raw-permission-history">
          {permissions.history.map((event, index) => (
            <li key={`${event.grantId}-${event.action}-${index}`}>
              <strong>{event.action === 'GRANTED' ? 'Nadano' : 'Cofnięto'}: {rawPermissionLabel(event.permission)}</strong>
              <span>{event.actorName} · odbiorca: {event.recipientName}</span>
              <time dateTime={event.occurredAt}>{formatDate(event.occurredAt)}</time>
            </li>
          ))}
        </ol>
      )}

      {confirmation && <Drawer
        labelledBy="raw-permission-confirm-title"
        descriptionId="raw-permission-confirm-description"
        dialogRole="alertdialog"
        returnFocus={returnFocus}
        initialFocusRef={confirmation.kind === 'grant' ? grantConfirmRef : revokeReasonRef}
        width="min(34rem, calc(100vw - 2rem))"
        className="raw-permission-confirmation"
        onClose={closeConfirmation}
      >
        {(requestClose) => (
          <>
            <h3 id="raw-permission-confirm-title">
              {confirmation.kind === 'grant' ? 'Potwierdź nadanie RAW' : 'Potwierdź cofnięcie RAW'}
            </h3>
            <p id="raw-permission-confirm-description">
              {confirmation.kind === 'grant'
                ? `Nadasz użytkownikowi ${userName} uprawnienie „${rawPermissionLabel(permission)}”.`
                : `Cofniesz grant „${rawPermissionLabel(confirmation.grant.permission)}” oraz zależne delegacje zgodnie z polityką RAW.`}
            </p>
            {error && <p className="error" role="alert">{error}</p>}
            {confirmation.kind === 'revoke' && <label>Powód cofnięcia
              <textarea ref={revokeReasonRef} required minLength={3} maxLength={500} value={revokeReason}
                onChange={(event) => setRevokeReason(event.target.value)} />
            </label>}
            <div className="admin-user-form-actions">
              <button ref={grantConfirmRef} type="button" className={confirmation.kind === 'grant' ? 'button button-primary' : 'button button-danger'}
                disabled={busy || (confirmation.kind === 'revoke' && revokeReason.trim().length < 3)}
                onClick={() => void (confirmation.kind === 'grant' ? confirmGrant() : confirmRevoke())}>
                {confirmation.kind === 'grant' ? 'Potwierdź nadanie' : 'Potwierdź cofnięcie'}
              </button>
              <button type="button" className="button button-secondary" onClick={requestClose}>Anuluj</button>
            </div>
          </>
        )}
      </Drawer>}
    </section>
  )
}
