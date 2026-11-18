import { useEffect, useState, type FormEvent } from 'react'
import { ApiError } from '../api'
import DataTable, { type DataTableColumn } from '../design-system/DataTable'
import AnimatedPanel from '../design-system/AnimatedPanel'
import Pagination from '../design-system/Pagination'
import VeltraDisclosure from '../design-system/VeltraDisclosure'
import {
  archiveAdministrationSupplier,
  createAdministrationSupplier,
  createAdministrationSupplierContact,
  fetchAdministrationSupplier,
  fetchAdministrationSuppliers,
  fetchSimilarSupplierNames,
  removeAdministrationSupplierContact,
  restoreAdministrationSupplier,
  updateAdministrationSupplier,
  updateAdministrationSupplierContact,
  type SupplierContact,
  type SupplierContactInput,
  type SupplierDirectoryPage,
  type SupplierDirectoryQuery,
  type SupplierDirectoryRow,
  type SupplierDetails,
  type SupplierInput,
  type SupplierStatus,
} from './suppliersApi'

type SupplierForm = {
  id: string | null
  legalName: string
  displayName: string
  taxIdentifier: string
  streetAddress: string
  city: string
  phone: string
  email: string
  website: string
}

type ContactForm = {
  id: string | null
  fullName: string
  position: string
  phone: string
  email: string
  isPrimary: boolean
}

type StatusFilter = SupplierDirectoryQuery['status']
type SortKey = SupplierDirectoryQuery['sort']

const EMPTY_PAGE: SupplierDirectoryPage = {
  data: [],
  meta: { currentPage: 1, perPage: 25, total: 0, lastPage: 1 },
}

function errorText(cause: unknown): string {
  if (cause instanceof ApiError) {
    const validationMessage = Object.values(cause.errors).flat()[0]
    return validationMessage ?? cause.message
  }
  return cause instanceof Error ? cause.message : 'Nie udało się wykonać operacji.'
}

function statusLabel(status: SupplierStatus): string {
  return status === 'ACTIVE' ? 'Aktywny' : 'Zarchiwizowany'
}

function supplierName(supplier: Pick<SupplierDirectoryRow, 'displayName' | 'legalName'>): string {
  return supplier.displayName?.trim() || supplier.legalName
}

function supplierInput(form: SupplierForm): SupplierInput {
  return {
    legal_name: form.legalName.trim(),
    display_name: form.displayName.trim() || null,
    tax_identifier: form.taxIdentifier.trim() || null,
    street_address: form.streetAddress.trim() || null,
    city: form.city.trim() || null,
    phone: form.phone.trim() || null,
    email: form.email.trim() || null,
    website: form.website.trim() || null,
  }
}

function contactInput(form: ContactForm): SupplierContactInput {
  return {
    full_name: form.fullName.trim(),
    position: form.position.trim() || null,
    phone: form.phone.trim() || null,
    email: form.email.trim() || null,
    is_primary: form.isPrimary,
  }
}

function newSupplierForm(): SupplierForm {
  return {
    id: null,
    legalName: '',
    displayName: '',
    taxIdentifier: '',
    streetAddress: '',
    city: '',
    phone: '',
    email: '',
    website: '',
  }
}

function newContactForm(contact?: SupplierContact): ContactForm {
  return {
    id: contact?.id ?? null,
    fullName: contact?.fullName ?? '',
    position: contact?.position ?? '',
    phone: contact?.phone ?? '',
    email: contact?.email ?? '',
    isPrimary: contact?.isPrimary ?? false,
  }
}

export default function SupplierManagementPage() {
  const [status, setStatus] = useState<StatusFilter>('active')
  const [searchText, setSearchText] = useState('')
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<SortKey>('display_name')
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [result, setResult] = useState<SupplierDirectoryPage>(EMPTY_PAGE)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [supplierForm, setSupplierForm] = useState<SupplierForm | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [busySupplierId, setBusySupplierId] = useState<string | null>(null)
  const [needsRefresh, setNeedsRefresh] = useState(false)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [details, setDetails] = useState<SupplierDetails | null>(null)
  const [detailsLoading, setDetailsLoading] = useState(false)
  const [detailsError, setDetailsError] = useState<string | null>(null)
  const [detailsReloadKey, setDetailsReloadKey] = useState(0)
  const [contactForm, setContactForm] = useState<ContactForm | null>(null)
  const [contactError, setContactError] = useState<string | null>(null)
  const [contactBusy, setContactBusy] = useState(false)
  const [confirmArchive, setConfirmArchive] = useState(false)
  const [confirmRemoveContact, setConfirmRemoveContact] = useState<string | null>(null)
  const [similarNames, setSimilarNames] = useState<Array<{ id: string; legalName: string; displayName: string | null }>>([])
  const [similarNameError, setSimilarNameError] = useState<string | null>(null)

  useEffect(() => {
    const controller = new AbortController()
    void fetchAdministrationSuppliers({
      status, search, sort, direction, page, perPage: 25,
    }, controller.signal)
      .then((response) => {
        if (!controller.signal.aborted) {
          setResult(response)
          setNeedsRefresh(false)
        }
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) setError(errorText(cause))
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [status, search, sort, direction, page, reloadKey])

  useEffect(() => {
    if (!selectedId) return
    const controller = new AbortController()
    void fetchAdministrationSupplier(selectedId, controller.signal)
      .then((response) => {
        if (!controller.signal.aborted) setDetails(response)
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) setDetailsError(errorText(cause))
      })
      .finally(() => {
        if (!controller.signal.aborted) setDetailsLoading(false)
      })
    return () => controller.abort()
  }, [selectedId, detailsReloadKey])

  useEffect(() => {
    const name = supplierForm?.legalName.trim() ?? ''
    if (name.length < 4) return
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      void fetchSimilarSupplierNames(name, supplierForm?.id ?? undefined, controller.signal)
        .then((matches) => {
          if (!controller.signal.aborted) {
            setSimilarNames(matches)
            setSimilarNameError(null)
          }
        })
        .catch((cause: unknown) => {
          if (!controller.signal.aborted) setSimilarNameError(errorText(cause))
        })
    }, 300)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [supplierForm?.legalName, supplierForm?.id])

  function reload() {
    setLoading(true)
    setError(null)
    setReloadKey((current) => current + 1)
  }

  function openCreate() {
    setSuccess(null)
    setFormError(null)
    setSimilarNames([])
    setSimilarNameError(null)
    setSelectedId(null)
    setSupplierForm(newSupplierForm())
  }

  function openSupplier(row: SupplierDirectoryRow) {
    setSupplierForm(null)
    setContactForm(null)
    setSuccess(null)
    if (row.id === selectedId) return
    setDetails(null)
    setDetailsError(null)
    setDetailsLoading(true)
    setSelectedId(row.id)
  }

  function openEdit(detailsToEdit: SupplierDetails) {
    setSuccess(null)
    setFormError(null)
    setSimilarNames([])
    setSimilarNameError(null)
    setSupplierForm({
      id: detailsToEdit.id,
      legalName: detailsToEdit.legalName,
      displayName: detailsToEdit.displayName ?? '',
      taxIdentifier: detailsToEdit.taxIdentifier ?? '',
      streetAddress: detailsToEdit.streetAddress ?? '',
      city: detailsToEdit.city ?? '',
      phone: detailsToEdit.phone ?? '',
      email: detailsToEdit.email ?? '',
      website: detailsToEdit.website ?? '',
    })
  }

  async function submitSupplier(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!supplierForm || busy || needsRefresh) return
    setBusy(true)
    setFormError(null)
    setSuccess(null)
    try {
      const input = supplierInput(supplierForm)
      let supplierId: string
      if (supplierForm.id) {
        supplierId = supplierForm.id
        await updateAdministrationSupplier(supplierId, input)
        setSuccess('Zmiany dostawcy zostały zapisane.')
      } else {
        supplierId = await createAdministrationSupplier(input)
        setSuccess('Dostawca został dodany.')
      }
      setSupplierForm(null)
      setSelectedId(supplierId)
      setDetailsLoading(true)
      setDetailsError(null)
      setDetailsReloadKey((current) => current + 1)
      reload()
    } catch (cause) {
      setFormError(errorText(cause))
      if (!(cause instanceof ApiError) || cause.status >= 500) setNeedsRefresh(true)
    } finally {
      setBusy(false)
    }
  }

  async function changeSupplierStatus(supplier: SupplierDetails, action: 'archive' | 'restore') {
    setBusySupplierId(supplier.id)
    setError(null)
    setSuccess(null)
    try {
      if (action === 'archive') {
        await archiveAdministrationSupplier(supplier.id)
        setSuccess(`Dostawca „${supplierName(supplier)}” został zarchiwizowany. Powiązania i dane historyczne zachowano.`)
        setConfirmArchive(false)
      } else {
        await restoreAdministrationSupplier(supplier.id)
        setSuccess(`Dostawca „${supplierName(supplier)}” został przywrócony.`)
      }
      setDetailsLoading(true)
      setDetailsError(null)
      setDetailsReloadKey((current) => current + 1)
      reload()
    } catch (cause) {
      setError(errorText(cause))
      if (!(cause instanceof ApiError) || cause.status >= 500) setNeedsRefresh(true)
    } finally {
      setBusySupplierId(null)
    }
  }

  async function submitContact(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!contactForm || !details || contactBusy || needsRefresh) return
    setContactBusy(true)
    setContactError(null)
    setSuccess(null)
    try {
      const input = contactInput(contactForm)
      if (contactForm.id) {
        await updateAdministrationSupplierContact(details.id, contactForm.id, input)
        setSuccess('Dane osoby kontaktowej zostały zapisane.')
      } else {
        await createAdministrationSupplierContact(details.id, input)
        setSuccess('Osoba kontaktowa została dodana.')
      }
      setContactForm(null)
      setDetailsLoading(true)
      setDetailsError(null)
      setDetailsReloadKey((current) => current + 1)
      reload()
    } catch (cause) {
      setContactError(errorText(cause))
      if (!(cause instanceof ApiError) || cause.status >= 500) setNeedsRefresh(true)
    } finally {
      setContactBusy(false)
    }
  }

  async function removeContact(contactId: string) {
    if (!details || contactBusy) return
    setContactBusy(true)
    setContactError(null)
    setSuccess(null)
    try {
      await removeAdministrationSupplierContact(details.id, contactId)
      setConfirmRemoveContact(null)
      setSuccess('Osoba kontaktowa została usunięta z listy. Historia zmian została zachowana.')
      setDetailsLoading(true)
      setDetailsError(null)
      setDetailsReloadKey((current) => current + 1)
      reload()
    } catch (cause) {
      setContactError(errorText(cause))
      if (!(cause instanceof ApiError) || cause.status >= 500) setNeedsRefresh(true)
    } finally {
      setContactBusy(false)
    }
  }

  function changeLegalName(legalName: string) {
    setSimilarNames([])
    setSimilarNameError(null)
    setSupplierForm((current) => current ? { ...current, legalName } : current)
  }

  function handlePageChange(nextPage: number) {
    setLoading(true)
    setError(null)
    setPage(nextPage)
  }

  function handleSort(key: SortKey) {
    setLoading(true)
    setError(null)
    setPage(1)
    if (sort === key) setDirection((current) => current === 'asc' ? 'desc' : 'asc')
    else {
      setSort(key)
      setDirection('asc')
    }
  }

  const columns: Array<DataTableColumn<SupplierDirectoryRow>> = [
    {
      id: 'display_name',
      header: 'Nazwa potoczna',
      sortKey: 'display_name',
      sortDirection: sort === 'display_name' ? direction : null,
      sortLabel: 'Sortuj według: nazwa potoczna',
      onSort: () => handleSort('display_name'),
      cell: (supplier) => (
        <button type="button" className="detail-link" onClick={() => openSupplier(supplier)}>
          {supplierName(supplier)}
        </button>
      ),
    },
    {
      id: 'legal_name',
      header: 'Nazwa firmy',
      sortKey: 'legal_name',
      sortDirection: sort === 'legal_name' ? direction : null,
      sortLabel: 'Sortuj według: nazwa firmy',
      onSort: () => handleSort('legal_name'),
      cell: (supplier) => supplier.legalName,
    },
    {
      id: 'tax_identifier',
      header: 'NIP',
      sortKey: 'tax_identifier',
      sortDirection: sort === 'tax_identifier' ? direction : null,
      sortLabel: 'Sortuj według: NIP',
      onSort: () => handleSort('tax_identifier'),
      cell: (supplier) => supplier.taxIdentifier ?? '—',
    },
    {
      id: 'city',
      header: 'Miejscowość',
      sortKey: 'city',
      sortDirection: sort === 'city' ? direction : null,
      sortLabel: 'Sortuj według: miejscowość',
      onSort: () => handleSort('city'),
      cell: (supplier) => supplier.city ?? '—',
    },
    {
      id: 'primary_contact',
      header: 'Osoba kontaktowa',
      sortKey: 'primary_contact',
      sortDirection: sort === 'primary_contact' ? direction : null,
      sortLabel: 'Sortuj według: osoba kontaktowa',
      onSort: () => handleSort('primary_contact'),
      cell: (supplier) => supplier.primaryContact ?? '—',
    },
    {
      id: 'raw_material_count',
      header: 'Surowce RAW',
      cell: (supplier) => supplier.rawMaterialCount,
    },
    {
      id: 'status',
      header: 'Status',
      sortKey: 'status',
      sortDirection: sort === 'status' ? direction : null,
      sortLabel: 'Sortuj według: status',
      onSort: () => handleSort('status'),
      cell: (supplier) => statusLabel(supplier.status),
    },
    {
      id: 'actions',
      header: 'Działania',
      cell: (supplier) => (
        <button type="button" className="button button-secondary" onClick={() => openSupplier(supplier)}>
          Szczegóły
        </button>
      ),
    },
  ]

  return (
    <section className="admin-category-page admin-supplier-page" aria-labelledby="admin-supplier-title">
      <header className="admin-category-header">
        <div>
          <h2 id="admin-supplier-title">Dostawcy</h2>
          <p>Wspólny katalog dostawców wykorzystywany w VELTRA. Dane firmy i osób kontaktowych są dostępne wyłącznie w panelu administracyjnym.</p>
        </div>
        <button type="button" className="button button-primary" disabled={busy || needsRefresh}
          onClick={openCreate}>Dodaj dostawcę</button>
      </header>

      {success && <p className="inline-success" role="status">{success}</p>}
      {error && <p className="inline-error" role="alert">{error}</p>}
      {needsRefresh && (
        <div className="admin-category-refresh" role="alert">
          <p>Nie można potwierdzić wyniku zapisu. Odśwież listę i sprawdź stan przed ponowną próbą.</p>
          <button type="button" className="button button-secondary" disabled={loading} onClick={reload}>Odśwież listę</button>
        </div>
      )}

      <form className="admin-category-filters" onSubmit={(event) => {
        event.preventDefault()
        const nextSearch = searchText.trim()
        if (nextSearch === search && page === 1) {
          reload()
          return
        }
        setLoading(true)
        setError(null)
        setPage(1)
        setSearch(nextSearch)
      }}>
        <label className="create-form-field">
          Szukaj po nazwie, NIP, miejscowości lub osobie kontaktowej
          <input type="search" value={searchText} maxLength={160}
            onChange={(event) => setSearchText(event.target.value)} />
        </label>
        <label className="create-form-field">
          Pokaż dostawców
          <select value={status} onChange={(event) => {
            setLoading(true)
            setError(null)
            setStatus(event.target.value as StatusFilter)
            setPage(1)
          }}>
            <option value="active">Aktywnych</option>
            <option value="archived">Zarchiwizowanych</option>
            <option value="all">Wszystkich</option>
          </select>
        </label>
        <div className="admin-category-filter-actions">
          <button type="submit" className="button button-secondary">Szukaj</button>
          {(search || searchText) && (
            <button type="button" className="button button-secondary" onClick={() => {
              setSearchText('')
              if (!search) return
              setLoading(true)
              setError(null)
              setSearch('')
              setPage(1)
            }}>Wyczyść wyszukiwanie</button>
          )}
        </div>
      </form>

      <div className="admin-supplier-table">
        <DataTable
          rows={result.data}
          columns={columns}
          getRowId={(supplier) => supplier.id}
          ariaLabel="Katalog dostawców"
          loading={loading}
          loadingMessage="Ładowanie dostawców…"
          emptyMessage="Nie znaleziono dostawców."
        />
      </div>
      <Pagination
        currentPage={result.meta.currentPage}
        totalPages={result.meta.lastPage}
        onPageChange={handlePageChange}
        disabled={loading}
        label="Stronicowanie dostawców"
        className="admin-category-pagination"
      />

      <AnimatedPanel id="admin-supplier-form-panel" open={supplierForm !== null}
        role="region" ariaLabelledBy="admin-supplier-form-title" className="admin-supplier-form-panel">
        {supplierForm && (
          <form className="state-panel admin-category-form" onSubmit={(event) => void submitSupplier(event)}>
            <h3 id="admin-supplier-form-title">{supplierForm.id ? 'Edytuj dane dostawcy' : 'Dodaj dostawcę'}</h3>
            <p className="field-hint">Nazwa potoczna będzie wyświetlana przy surowcach. Jeśli jej nie podasz, użyjemy nazwy firmy.</p>
            {formError && <p className="inline-error" role="alert">{formError}</p>}
            {similarNameError && <p className="inline-error" role="alert">{similarNameError}</p>}
            {similarNames.length > 0 && (
              <div className="admin-category-confirm" role="status">
                <strong>Sprawdź, czy dostawca nie jest już na liście:</strong>
                <ul>{similarNames.map((match) => (
                  <li key={match.id}>{match.displayName?.trim() || match.legalName} — {match.legalName}</li>
                ))}</ul>
              </div>
            )}
            <div className="create-form-grid">
              <label className="create-form-field">Nazwa firmy
                <input required maxLength={200} autoComplete="organization"
                  value={supplierForm.legalName}
                  onChange={(event) => changeLegalName(event.target.value)} />
              </label>
              <label className="create-form-field">Nazwa potoczna (opcjonalnie)
                <input maxLength={200} autoComplete="organization-title"
                  value={supplierForm.displayName}
                  onChange={(event) => setSupplierForm({ ...supplierForm, displayName: event.target.value })} />
              </label>
              <label className="create-form-field">NIP (opcjonalnie)
                <input inputMode="numeric" autoComplete="off" maxLength={20}
                  value={supplierForm.taxIdentifier}
                  onChange={(event) => setSupplierForm({ ...supplierForm, taxIdentifier: event.target.value })} />
              </label>
              <label className="create-form-field">Ulica i numer (opcjonalnie)
                <input autoComplete="street-address" maxLength={240}
                  value={supplierForm.streetAddress}
                  onChange={(event) => setSupplierForm({ ...supplierForm, streetAddress: event.target.value })} />
              </label>
              <label className="create-form-field">Miejscowość (opcjonalnie)
                <input autoComplete="address-level2" maxLength={120}
                  value={supplierForm.city}
                  onChange={(event) => setSupplierForm({ ...supplierForm, city: event.target.value })} />
              </label>
              <label className="create-form-field">Telefon firmy (opcjonalnie)
                <input type="tel" autoComplete="tel" maxLength={60}
                  value={supplierForm.phone}
                  onChange={(event) => setSupplierForm({ ...supplierForm, phone: event.target.value })} />
              </label>
              <label className="create-form-field">E-mail firmy (opcjonalnie)
                <input type="email" autoComplete="email" maxLength={254}
                  value={supplierForm.email}
                  onChange={(event) => setSupplierForm({ ...supplierForm, email: event.target.value })} />
              </label>
              <label className="create-form-field">Strona internetowa (opcjonalnie)
                <input type="url" autoComplete="url" maxLength={2048}
                  value={supplierForm.website}
                  onChange={(event) => setSupplierForm({ ...supplierForm, website: event.target.value })} />
              </label>
            </div>
            <div className="admin-category-actions">
              <button type="submit" className="button button-primary" disabled={busy || needsRefresh}>
                {busy ? 'Zapisywanie…' : 'Zapisz dostawcę'}
              </button>
              <button type="button" className="button button-secondary" disabled={busy}
                onClick={() => setSupplierForm(null)}>Anuluj</button>
            </div>
          </form>
        )}
      </AnimatedPanel>

      <AnimatedPanel id="admin-supplier-details-panel" open={selectedId !== null}
        role="region" ariaLabelledBy="admin-supplier-details-title" className="admin-supplier-details-panel">
        {selectedId && (
          <section className="state-panel admin-supplier-details">
            {detailsLoading && <p role="status">Ładowanie szczegółów dostawcy…</p>}
            {detailsError && <p className="inline-error" role="alert">{detailsError}</p>}
            {details && (
              <>
                <header className="admin-category-header">
                  <div>
                    <h3 id="admin-supplier-details-title">{supplierName(details)}</h3>
                    {details.displayName && <p>{details.legalName}</p>}
                  </div>
                  <div className="admin-category-actions">
                    <button type="button" className="button button-secondary" disabled={busy || needsRefresh}
                      onClick={() => openEdit(details)}>Edytuj</button>
                    {details.status === 'ACTIVE' ? (
                      <button type="button" className="button button-secondary" disabled={busy || needsRefresh}
                        onClick={() => setConfirmArchive(true)}>Archiwizuj</button>
                    ) : (
                      <button type="button" className="button button-secondary" disabled={busy || needsRefresh}
                        onClick={() => void changeSupplierStatus(details, 'restore')}>Przywróć</button>
                    )}
                    <button type="button" className="button button-secondary" onClick={() => setSelectedId(null)}>Zamknij</button>
                  </div>
                </header>

                <dl className="admin-category-details admin-supplier-company-details">
                  <div><dt>Nazwa firmy</dt><dd>{details.legalName}</dd></div>
                  <div><dt>Nazwa potoczna</dt><dd>{details.displayName ?? 'Nie podano — widoczna będzie nazwa firmy'}</dd></div>
                  <div><dt>NIP</dt><dd>{details.taxIdentifier ?? '—'}</dd></div>
                  <div><dt>Adres</dt><dd>{[details.streetAddress, details.city].filter(Boolean).join(', ') || '—'}</dd></div>
                  <div><dt>Telefon</dt><dd>{details.phone ?? '—'}</dd></div>
                  <div><dt>E-mail</dt><dd>{details.email ?? '—'}</dd></div>
                  <div><dt>Strona internetowa</dt><dd>{details.website ?? '—'}</dd></div>
                  <div><dt>Status</dt><dd>{statusLabel(details.status)}</dd></div>
                  <div><dt>Powiązane surowce RAW</dt><dd>{details.rawMaterialCount}</dd></div>
                </dl>

                {confirmArchive && details.status === 'ACTIVE' && (
                  <div className="admin-category-confirm" role="group" aria-label="Potwierdzenie archiwizacji dostawcy">
                    <p>Archiwizacja ukryje dostawcę na listach wyboru, ale nie usunie jego danych, powiązań surowców ani historii.</p>
                    <button type="button" className="button button-primary" disabled={busySupplierId === details.id}
                      onClick={() => void changeSupplierStatus(details, 'archive')}>Potwierdź archiwizację</button>
                    <button type="button" className="button button-secondary"
                      onClick={() => setConfirmArchive(false)}>Anuluj</button>
                  </div>
                )}

                <VeltraDisclosure title={`Osoby kontaktowe (${details.contacts.length})`} className="admin-supplier-contacts">
                  <div className="admin-supplier-contact-header">
                    <p>Dane kontaktowe są widoczne wyłącznie w panelu administracyjnym.</p>
                    <button type="button" className="button button-secondary" disabled={contactBusy || needsRefresh}
                      onClick={() => {
                        setContactError(null)
                        setContactForm(newContactForm())
                      }}>Dodaj osobę</button>
                  </div>
                  {contactError && <p className="inline-error" role="alert">{contactError}</p>}
                  {details.contacts.length === 0 && <p className="admin-category-empty">Brak osób kontaktowych.</p>}
                  <div className="admin-supplier-contact-list">
                    {details.contacts.map((contact) => (
                      <article key={contact.id} className="admin-supplier-contact">
                        <div>
                          <h4>{contact.fullName}{contact.isPrimary ? <span className="admin-supplier-primary">Główny kontakt</span> : null}</h4>
                          {contact.position && <p>{contact.position}</p>}
                          <p>{[contact.phone, contact.email].filter(Boolean).join(' · ') || 'Brak danych kontaktowych'}</p>
                        </div>
                        <div className="admin-category-actions">
                          <button type="button" className="button button-secondary" disabled={contactBusy || needsRefresh}
                            onClick={() => {
                              setContactError(null)
                              setContactForm(newContactForm(contact))
                            }}>Edytuj</button>
                          <button type="button" className="button button-secondary" disabled={contactBusy || needsRefresh}
                            onClick={() => setConfirmRemoveContact(contact.id)}>Usuń</button>
                        </div>
                        {confirmRemoveContact === contact.id && (
                          <div className="admin-category-confirm" role="group" aria-label={`Potwierdzenie usunięcia: ${contact.fullName}`}>
                            <p>Usunąć tę osobę z aktywnej listy kontaktów? Historia pozostanie zachowana.</p>
                            <button type="button" className="button button-primary" disabled={contactBusy}
                              onClick={() => void removeContact(contact.id)}>Potwierdź</button>
                            <button type="button" className="button button-secondary"
                              onClick={() => setConfirmRemoveContact(null)}>Anuluj</button>
                          </div>
                        )}
                      </article>
                    ))}
                  </div>
                  <AnimatedPanel id="admin-supplier-contact-form-panel" open={contactForm !== null}
                    role="region" ariaLabelledBy="admin-supplier-contact-form-title">
                    {contactForm && (
                      <form className="admin-supplier-contact-form" onSubmit={(event) => void submitContact(event)}>
                        <h4 id="admin-supplier-contact-form-title">{contactForm.id ? 'Edytuj osobę kontaktową' : 'Nowa osoba kontaktowa'}</h4>
                        <div className="create-form-grid">
                          <label className="create-form-field">Imię i nazwisko
                            <input required maxLength={160} autoComplete="name" value={contactForm.fullName}
                              onChange={(event) => setContactForm({ ...contactForm, fullName: event.target.value })} />
                          </label>
                          <label className="create-form-field">Stanowisko (opcjonalnie)
                            <input maxLength={120} autoComplete="organization-title" value={contactForm.position}
                              onChange={(event) => setContactForm({ ...contactForm, position: event.target.value })} />
                          </label>
                          <label className="create-form-field">Telefon (opcjonalnie)
                            <input type="tel" autoComplete="tel" maxLength={60} value={contactForm.phone}
                              onChange={(event) => setContactForm({ ...contactForm, phone: event.target.value })} />
                          </label>
                          <label className="create-form-field">E-mail (opcjonalnie)
                            <input type="email" autoComplete="email" maxLength={254} value={contactForm.email}
                              onChange={(event) => setContactForm({ ...contactForm, email: event.target.value })} />
                          </label>
                          <label className="create-form-field admin-supplier-primary-field">
                            <input type="checkbox" checked={contactForm.isPrimary}
                              onChange={(event) => setContactForm({ ...contactForm, isPrimary: event.target.checked })} />
                            Główna osoba kontaktowa
                          </label>
                        </div>
                        <div className="admin-category-actions">
                          <button type="submit" className="button button-primary" disabled={contactBusy || needsRefresh}>
                            {contactBusy ? 'Zapisywanie…' : 'Zapisz osobę'}
                          </button>
                          <button type="button" className="button button-secondary" disabled={contactBusy}
                            onClick={() => setContactForm(null)}>Anuluj</button>
                        </div>
                      </form>
                    )}
                  </AnimatedPanel>
                </VeltraDisclosure>
              </>
            )}
          </section>
        )}
      </AnimatedPanel>
    </section>
  )
}
