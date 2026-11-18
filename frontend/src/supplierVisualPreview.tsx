import { createRoot } from 'react-dom/client'
import './App.css'
import './index.css'
import AdministrationPanel from './AdministrationPanel'
import ApplicationShell from './ApplicationShell'

const supplierId = '00000000-0000-4000-8000-000000000701'
const contactId = '00000000-0000-4000-8000-000000000702'

type PreviewContact = {
  id: string
  full_name: string
  position: string | null
  phone: string | null
  email: string | null
  is_primary: boolean
}

type PreviewSupplier = {
  id: string
  display_name: string | null
  legal_name: string
  tax_identifier: string | null
  street_address: string | null
  city: string | null
  phone: string | null
  email: string | null
  website: string | null
  lifecycle_state: 'ACTIVE' | 'ARCHIVED'
  raw_material_count: number
  contacts: PreviewContact[]
}

const suppliers = new Map<string, PreviewSupplier>([[supplierId, {
  id: supplierId,
  display_name: 'Dostawca BANASIK',
  legal_name: 'BANASIK sp. z o.o.',
  tax_identifier: '1234567890',
  street_address: 'ul. Przykładowa 10',
  city: 'Kraków',
  phone: '+48 12 123 45 67',
  email: 'biuro@banasik.example',
  website: 'https://banasik.example',
  lifecycle_state: 'ACTIVE',
  raw_material_count: 7,
  contacts: [{
    id: contactId,
    full_name: 'Anna Nowak',
    position: 'Opiekun klienta',
    phone: '+48 12 123 45 67',
    email: 'anna.nowak@banasik.example',
    is_primary: true,
  }],
}]])

function response(data: unknown, status = 200): Response {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json', 'Content-Language': 'pl', 'Cache-Control': 'private, no-store' },
  })
}

function bodyOf(init?: RequestInit): Record<string, unknown> {
  const rawBody = init?.body
  if (typeof rawBody !== 'string') return {}
  const parsed: unknown = JSON.parse(rawBody)
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    throw new Error('Nieprawidłowe dane w podglądzie dostawców.')
  }
  return parsed as Record<string, unknown>
}

function installSyntheticApi() {
  window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input), window.location.origin)
    if (!url.pathname.startsWith('/api/v1/administration/suppliers')) {
      return response({ message: 'Podgląd dopuszcza wyłącznie syntetyczne API dostawców.' }, 404)
    }

    const method = init?.method ?? 'GET'
    const parts = url.pathname.split('/').filter(Boolean)

    if (url.pathname === '/api/v1/administration/suppliers/similar-names' && method === 'GET') {
      const officialName = (url.searchParams.get('official_name') ?? '').toLocaleLowerCase('pl-PL')
      const excludedId = url.searchParams.get('exclude_supplier_id')
      const matches = [...suppliers.values()]
        .filter((supplier) => supplier.id !== excludedId && supplier.lifecycle_state === 'ACTIVE')
        .filter((supplier) => `${supplier.legal_name} ${supplier.display_name ?? ''}`
          .toLocaleLowerCase('pl-PL').includes(officialName))
        .map((supplier) => ({
          id: supplier.id,
          legal_name: supplier.legal_name,
          display_name: supplier.display_name,
          similarity: 92,
        }))
      return response({ data: matches })
    }

    if (url.pathname === '/api/v1/administration/suppliers' && method === 'GET') {
      const status = url.searchParams.get('status') ?? 'active'
      const search = (url.searchParams.get('search') ?? '').toLocaleLowerCase('pl-PL')
      const rows = [...suppliers.values()]
        .filter((supplier) => status === 'all' || supplier.lifecycle_state.toLowerCase() === status)
        .filter((supplier) => `${supplier.display_name ?? ''} ${supplier.legal_name} ${supplier.tax_identifier ?? ''} ${supplier.city ?? ''} ${supplier.contacts.map((contact) => contact.full_name).join(' ')}`
          .toLocaleLowerCase('pl-PL').includes(search))
        .map((supplier) => ({
          id: supplier.id,
          display_name: supplier.display_name,
          legal_name: supplier.legal_name,
          tax_identifier: supplier.tax_identifier,
          city: supplier.city,
          lifecycle_state: supplier.lifecycle_state,
          raw_material_count: supplier.raw_material_count,
          primary_contact: supplier.contacts.find((contact) => contact.is_primary)?.full_name ?? null,
        }))
      const page = Number(url.searchParams.get('page') ?? 1)
      const perPage = Number(url.searchParams.get('per_page') ?? 25)
      return new Response(JSON.stringify({
        data: rows.slice((page - 1) * perPage, page * perPage),
        meta: {
          current_page: page,
          per_page: perPage,
          total: rows.length,
          last_page: Math.max(1, Math.ceil(rows.length / perPage)),
        },
      }), {
        headers: { 'Content-Type': 'application/json', 'Content-Language': 'pl', 'Cache-Control': 'private, no-store' },
      })
    }

    if (url.pathname === '/api/v1/administration/suppliers' && method === 'POST') {
      const body = bodyOf(init)
      const id = `00000000-0000-4000-8000-${String(suppliers.size + 703).padStart(12, '0')}`
      suppliers.set(id, {
        id,
        display_name: typeof body.display_name === 'string' ? body.display_name : null,
        legal_name: String(body.legal_name ?? ''),
        tax_identifier: typeof body.tax_identifier === 'string' ? body.tax_identifier : null,
        street_address: typeof body.street_address === 'string' ? body.street_address : null,
        city: typeof body.city === 'string' ? body.city : null,
        phone: typeof body.phone === 'string' ? body.phone : null,
        email: typeof body.email === 'string' ? body.email : null,
        website: typeof body.website === 'string' ? body.website : null,
        lifecycle_state: 'ACTIVE',
        raw_material_count: 0,
        contacts: [],
      })
      return response({ id }, 201)
    }

    const supplier = suppliers.get(parts[4] ?? '')
    if (!supplier) return response({ message: 'Nie znaleziono dostawcy demonstracyjnego.' }, 404)
    if (parts.length === 5 && method === 'GET') {
      return response({
        ...supplier,
        primary_contact: supplier.contacts.find((contact) => contact.is_primary)?.full_name ?? null,
      })
    }
    if (parts.length === 5 && method === 'PATCH') {
      const body = bodyOf(init)
      Object.assign(supplier, {
        legal_name: body.legal_name ?? supplier.legal_name,
        display_name: body.display_name ?? null,
        tax_identifier: body.tax_identifier ?? null,
        street_address: body.street_address ?? null,
        city: body.city ?? null,
        phone: body.phone ?? null,
        email: body.email ?? null,
        website: body.website ?? null,
      })
      return response({ id: supplier.id })
    }
    if (parts.length === 6 && (parts[5] === 'archive' || parts[5] === 'restore')) {
      supplier.lifecycle_state = parts[5] === 'archive' ? 'ARCHIVED' : 'ACTIVE'
      return response({ id: supplier.id, lifecycle_state: supplier.lifecycle_state })
    }
    if (parts.length === 6 && parts[5] === 'contacts' && method === 'POST') {
      const body = bodyOf(init)
      const contact: PreviewContact = {
        id: `00000000-0000-4000-8000-${String(suppliers.size + supplier.contacts.length + 704).padStart(12, '0')}`,
        full_name: String(body.full_name ?? ''),
        position: typeof body.position === 'string' ? body.position : null,
        phone: typeof body.phone === 'string' ? body.phone : null,
        email: typeof body.email === 'string' ? body.email : null,
        is_primary: body.is_primary === true,
      }
      if (contact.is_primary) supplier.contacts.forEach((current) => { current.is_primary = false })
      supplier.contacts.push(contact)
      return response({ id: contact.id }, 201)
    }

    const contactIndex = parts.findIndex((part) => part === 'contacts')
    if (contactIndex >= 0 && parts[contactIndex + 1]) {
      const contact = supplier.contacts.find((item) => item.id === parts[contactIndex + 1])
      if (!contact) return response({ message: 'Nie znaleziono osoby kontaktowej.' }, 404)
      if (method === 'DELETE') {
        supplier.contacts = supplier.contacts.filter((item) => item.id !== contact.id)
        return response({ id: contact.id })
      }
      if (method === 'PATCH') {
        const body = bodyOf(init)
        if (body.is_primary === true) supplier.contacts.forEach((current) => { current.is_primary = false })
        Object.assign(contact, {
          full_name: body.full_name ?? contact.full_name,
          position: body.position ?? null,
          phone: body.phone ?? null,
          email: body.email ?? null,
          is_primary: body.is_primary ?? contact.is_primary,
        })
        return response({ id: contact.id })
      }
    }

    return response({ message: 'Nieobsługiwana operacja podglądu.' }, 404)
  }
}

installSyntheticApi()

export default function SupplierVisualPreview() {
  return (
    <ApplicationShell
      activePath="/administration/suppliers"
      showAdministration
      contexts={[
        { label: 'Organizacja', value: 'VELTRA — podgląd' },
        { label: 'Lokalizacja', value: 'Kuchnia demonstracyjna', detail: 'Dane syntetyczne' },
      ]}
      footerNote="Podgląd PA-06 · dane syntetyczne"
      status={<span className="topbar-health"><span className="status-indicator" data-status="down" aria-hidden="true" />API symulowane</span>}
      account={<div className="preview-account" aria-label="Użytkownik demonstracyjny">
        <span className="account-avatar" aria-hidden="true">UD</span>
        <span className="account-trigger-copy"><strong>Administrator demonstracyjny</strong><small>Konto syntetyczne</small></span>
      </div>}
    >
      <section className="preview-toolbar" aria-label="Sterowanie podglądem PA-06">
        <div className="preview-toolbar-heading">
          <strong>PODGLĄD PA-06 — DANE SYNTETYCZNE</strong>
          <span>Rzeczywisty widok administracyjny React. Brak połączenia ze Stage lub produkcją.</span>
        </div>
        <p className="preview-toolbar-status">
          Przykładowa kartoteka BANASIK · widoczne dane firmy i kontaktów · powiązane surowce: 7.
        </p>
      </section>
      <AdministrationPanel path="/administration/suppliers" />
    </ApplicationShell>
  )
}

createRoot(document.getElementById('root')!).render(<SupplierVisualPreview />)
