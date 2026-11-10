import { isRecord } from './api'

type PreviewMode = 'demo' | 'compact' | 'restricted' | 'not-ready' | 'session-expired' | 'forbidden'
  | 'detail-forbidden' | 'create-demo' | 'create-saving' | 'create-not-ready' | 'create-unauthorized'

const names = [
  'Mąka pszenna typ 550',
  'Mąka żytnia typ 720',
  'Cukier trzcinowy',
  'Sól morska drobna',
  'Ryż basmati',
  'Kasza gryczana niepalona',
  'Olej rzepakowy',
  'Oliwa z oliwek extra virgin',
  'Ocet jabłkowy',
  'Pomidory krojone',
  'Fasola biała',
  'Ciecierzyca sucha',
  'Soczewica czerwona',
  'Makaron penne',
  'Masło ekstra',
  'Śmietanka 30%',
  'Ser mozzarella',
  'Ser cheddar',
  'Jogurt naturalny',
  'Jaja świeże',
  'Pierś z kurczaka',
  'Wołowina gulaszowa',
  'Łosoś filet',
  'Marchew świeża',
  'Cebula żółta',
  'Czosnek polski',
  'Bulion warzywny',
  'Kakao naturalne',
  'Miód wielokwiatowy',
  'Orzechy włoskie',
  'Płatki owsiane',
  'Oliwa z oliwek do sałatek',
]

const products = names.map((name, index) => ({
  id: `00000000-0000-4000-8000-${String(index + 1).padStart(12, '0')}`,
  ordinal: index + 1,
  sku: `RM-${String(index + 1).padStart(5, '0')}`,
  lifecycle_state: 'ACTIVE',
  nominal_quantity: `${(250 + index * 25).toFixed(6)}`,
  usable_quantity: `${(240 + index * 24).toFixed(6)}`,
  reference_net_price: `${(8.35 + index * 1.17).toFixed(2)}`,
  currency_code: 'PLN',
  usable_unit_cost: `${(0.55 + index * 0.08).toFixed(2)}`,
  usable_cost_basis: 'PLN/100g',
  revision: { name, base_unit_code: index % 4 === 0 ? 'kg' : 'g' },
  category_id: [6, 7, 31].includes(index) ? 'CAT-OIL' : 'CAT-FOOD',
  supplier_id: index % 2 === 0 ? 'SUP-1' : 'SUP-2',
}))

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json',
      'Content-Language': 'pl',
    },
  })
}

function catalogResponse(url: URL, mode: PreviewMode): Response {
  if (mode === 'not-ready' || mode === 'create-not-ready') {
    return jsonResponse({
      error: {
        code: 'RAW_MATERIALS_MODULE_UNAVAILABLE',
        message: 'Moduł RAW_MATERIALS jest niedostępny w tej lokalizacji.',
      },
    }, 503)
  }
  if (mode === 'session-expired') return jsonResponse({ message: 'Sesja wygasła.' }, 401)
  if (mode === 'forbidden') return jsonResponse({ message: 'Brak uprawnień.' }, 403)

  const search = (url.searchParams.get('search') ?? '').toLocaleLowerCase('pl')
  let data = products.filter((product) => {
    const matchesSearch = !search
      || `${product.revision.name} ${product.sku}`.toLocaleLowerCase('pl').includes(search)
    const matchesCategory = !url.searchParams.has('category_id')
      || product.category_id === url.searchParams.get('category_id')
    const matchesSupplier = !url.searchParams.has('supplier_id')
      || product.supplier_id === url.searchParams.get('supplier_id')
    const matchesUnit = !url.searchParams.has('unit')
      || product.revision.base_unit_code === url.searchParams.get('unit')
    return matchesSearch && matchesCategory && matchesSupplier && matchesUnit
  })
  if (mode === 'compact') data = data.slice(0, 8)

  const sort = url.searchParams.get('sort') ?? 'name'
  const direction = url.searchParams.get('direction') === 'desc' ? -1 : 1
  const value = (product: (typeof products)[number]) => {
    if (sort === 'name') return product.revision.name
    if (sort === 'sku') return product.sku
    if (sort === 'unit') return product.revision.base_unit_code
    if (sort === 'nominal_quantity') return product.nominal_quantity
    if (sort === 'usable_quantity') return product.usable_quantity
    if (sort === 'reference_net_price') return product.reference_net_price
    return product.usable_unit_cost
  }
  data = [...data].sort((a, b) => String(value(a)).localeCompare(String(value(b)), 'pl', { numeric: true }) * direction)

  const currentPage = Number(url.searchParams.get('page') ?? 1)
  const perPage = Number(url.searchParams.get('per_page') ?? 25)
  const total = data.length
  const lastPage = Math.max(1, Math.ceil(total / perPage))
  const from = total ? (currentPage - 1) * perPage + 1 : null
  const to = total ? Math.min(currentPage * perPage, total) : null
  const rows = data.slice((currentPage - 1) * perPage, currentPage * perPage)
    .map((product, index) => {
      const { category_id: _categoryId, supplier_id: _supplierId, ...row } = product
      return { ...row, ordinal: (from ?? 0) + index }
    })

  const responseData = mode === 'restricted'
    ? rows.map(({
      reference_net_price: _referencePrice,
      currency_code: _currency,
      usable_unit_cost: _unitCost,
      usable_cost_basis: _basis,
      ...row
    }) => row)
    : rows

  return jsonResponse({
    data: responseData,
    meta: { current_page: currentPage, per_page: perPage, total, last_page: lastPage, from, to },
  })
}

function detailResponse(materialId: string, mode: PreviewMode): Response {
  if (mode === 'detail-forbidden') return jsonResponse({ message: 'Brak dostępu.' }, 403)
  const product = products.find((item) => item.id === materialId)
  if (!product) return jsonResponse({ message: 'Nie znaleziono surowca.' }, 404)

  const data = {
    id: product.id,
    code: product.sku,
    sku: product.sku,
    lifecycle_state: product.lifecycle_state,
    version: 3,
    approved_revision_id: `REV-${product.ordinal}-3`,
    revision: {
      id: `REV-${product.ordinal}-3`,
      revision_no: 3,
      name: product.revision.name,
      description: 'Syntetyczny opis demonstracyjny surowca do izolowanej weryfikacji interfejsu.',
      category_id: product.category_id,
      base_unit_code: product.revision.base_unit_code,
      base_unit_dimension: product.revision.base_unit_code === 'pc' ? 'COUNT' : 'MASS',
      nominal_quantity: product.nominal_quantity,
      usable_quantity: product.usable_quantity,
      allergen_state: 'DECLARED',
      units: [{
        unit_code: product.revision.base_unit_code,
        dimension: product.revision.base_unit_code === 'pc' ? 'COUNT' : 'MASS',
        conversion_kind: 'BASE',
        to_base_factor: '1.000000',
        basis: null,
      }],
      aliases: ['wariant demonstracyjny', 'alias syntetyczny'],
    },
    nominal_quantity: product.nominal_quantity,
    usable_quantity: product.usable_quantity,
    yield_percent: '96.000000',
    last_update: {
      occurred_at: '2025-01-15T10:30:00Z',
      actor: { id: 'DEMO-ACTOR', first_name: 'Użytkownik', last_name: 'demonstracyjny' },
    },
    history_url: mode === 'restricted' ? null : `/api/v1/raw-materials/${product.id}/history`,
    ...(mode === 'restricted' ? {} : {
      reference_net_price: product.reference_net_price,
      currency_code: 'PLN',
      usable_unit_cost: product.usable_unit_cost,
      usable_cost_basis: product.usable_cost_basis,
    }),
  }
  return jsonResponse({ data })
}

function revisionsResponse(materialId: string): Response {
  const product = products.find((item) => item.id === materialId)
  if (!product) return jsonResponse({ message: 'Nie znaleziono surowca.' }, 404)
  return jsonResponse({ data: [
    {
      id: `REV-${product.ordinal}-3`,
      revision_no: 3,
      name: product.revision.name,
      category_id: product.category_id,
      base_unit_code: product.revision.base_unit_code,
      allergen_state: 'DECLARED',
      critical_change: false,
      created_at: '2025-01-15T10:30:00Z',
      approvals: [{ outcome: 'APPROVED', actor_user_id: 'DEMO-ACTOR', occurred_at: '2025-01-15T10:30:00Z', reason: 'Weryfikacja demonstracyjna' }],
    },
    {
      id: `REV-${product.ordinal}-2`,
      revision_no: 2,
      name: `${product.revision.name} — poprzednia rewizja`,
      category_id: product.category_id,
      base_unit_code: product.revision.base_unit_code,
      allergen_state: 'UNKNOWN',
      critical_change: true,
      created_at: '2024-12-10T09:00:00Z',
      approvals: [],
    },
  ] })
}

export function installVisualPreviewApi(): void {
  const mode = new URLSearchParams(window.location.search).get('mode') as PreviewMode | null ?? 'demo'
  const nativeFetch = window.fetch.bind(window)
  const reservations = new Map<string, { reservation_id: string; sku: string; expires_at: string; status: string }>()
  const createdMaterials = new Map<string, {
    id: string
    code: string
    sku: string
    lifecycle_state: 'DRAFT'
    version: number
    approved_revision_id: null
    revision: { name: string; description: string | null; category_id: string; base_unit_code: string }
  }>()
  let nextSku = 42

  function capabilitiesResponse(): Response {
    const unavailable = mode === 'not-ready' || mode === 'create-not-ready'
    const authorized = mode === 'create-demo' || mode === 'create-saving'
    return jsonResponse({ data: {
      module: 'RAW_MATERIALS',
      location_id: '00000000-0000-4000-8000-000000000101',
      readiness: unavailable ? 'NOT_READY' : 'READY',
      available: !unavailable,
      permissions: { create: authorized || mode === 'create-not-ready' },
      actions: { create: authorized && !unavailable },
    } })
  }

  window.fetch = async (input, init) => {
    const url = new URL(typeof input === 'string' ? input : input instanceof Request ? input.url : input.href, window.location.origin)
    if (url.hostname === '127.0.0.1' && url.port === '4180' && url.pathname.startsWith('/screenshots/')) {
      return nativeFetch(input, init)
    }
    if (!url.pathname.startsWith('/api/')) {
      throw new Error('Zablokowano żądanie spoza demonstracyjnego API.')
    }

    if (url.pathname === '/api/v1/security/raw-materials/capabilities') {
      return capabilitiesResponse()
    }

    if (url.pathname === '/api/v1/raw-materials' && (init?.method ?? 'GET') === 'GET') {
      return catalogResponse(url, mode)
    }
    const revisionPath = url.pathname.match(/^\/api\/v1\/raw-materials\/([^/]+)\/revisions$/)
    if (revisionPath && (init?.method ?? 'GET') === 'GET') {
      return revisionsResponse(decodeURIComponent(revisionPath[1]))
    }
    if (url.pathname === '/api/v1/raw-materials/categories') {
      return jsonResponse({ data: [
        { id: 'CAT-FOOD', name: 'Produkty spożywcze' },
        { id: 'CAT-OIL', name: 'Oleje i tłuszcze' },
        { id: '00000000-0000-4000-8000-000000000201', name: 'Nowe surowce demonstracyjne' },
      ] })
    }
    if (url.pathname === '/api/v1/raw-materials/sku-reservations' && init?.method === 'POST') {
      if (mode !== 'create-demo' && mode !== 'create-saving') {
        return jsonResponse({ message: 'Brak uprawnień demonstracyjnych.' }, 403)
      }
      const requestHeaders = new Headers(init.headers)
      const key = requestHeaders.get('Idempotency-Key')
      if (!key || !/^[A-Za-z0-9._:-]{8,120}$/.test(key)) {
        return jsonResponse({ message: 'Brak prawidłowego klucza idempotencji.' }, 422)
      }
      let reservation = reservations.get(key)
      if (!reservation) {
        const ordinal = nextSku++
        const sequence = String(ordinal).padStart(6, '0')
        reservation = {
          reservation_id: `00000000-0000-4000-8000-${String(ordinal).padStart(12, '0')}`,
          sku: `RAW-${sequence}`,
          expires_at: '2099-01-01T00:00:00Z',
          status: 'RESERVED',
        }
        reservations.set(key, reservation)
      }
      return jsonResponse({ data: reservation }, 201)
    }
    if (url.pathname === '/api/v1/raw-materials' && init?.method === 'POST') {
      if (mode !== 'create-demo' && mode !== 'create-saving') {
        return jsonResponse({ message: 'Brak uprawnień demonstracyjnych.' }, 403)
      }
      if (mode === 'create-saving') await new Promise((resolve) => setTimeout(resolve, 2200))
      let body: Record<string, unknown>
      try {
        const parsed: unknown = JSON.parse(String(init.body ?? '{}'))
        if (!isRecord(parsed)) {
          throw new Error('Nieprawidłowy payload demonstracyjny.')
        }
        body = parsed
      } catch {
        return jsonResponse({ message: 'Nieprawidłowy payload demonstracyjny.' }, 422)
      }
      const reserved = [...reservations.values()].find((item) => item.reservation_id === body.sku_reservation_id)
      if (!reserved) return jsonResponse({ message: 'Nie znaleziono rezerwacji SKU.' }, 409)
      const existing = createdMaterials.get(reserved.reservation_id)
      if (existing) return jsonResponse({ data: existing }, 201)
      if (typeof body.name !== 'string' || typeof body.category_id !== 'string'
        || typeof body.base_unit_code !== 'string') {
        return jsonResponse({ message: 'Sprawdź dane formularza.' }, 422)
      }
      const material = {
        id: reserved.reservation_id,
        code: reserved.sku,
        sku: reserved.sku,
        lifecycle_state: 'DRAFT' as const,
        version: 1,
        approved_revision_id: null,
        revision: {
          name: body.name,
          description: typeof body.description === 'string' ? body.description : null,
          category_id: body.category_id,
          base_unit_code: body.base_unit_code,
        },
      }
      createdMaterials.set(material.id, material)
      products.push({
        id: material.id,
        ordinal: products.length + 1,
        sku: material.sku,
        lifecycle_state: material.lifecycle_state,
        nominal_quantity: typeof body.nominal_quantity === 'string' ? body.nominal_quantity : '1.000000',
        usable_quantity: typeof body.usable_quantity === 'string' ? body.usable_quantity : '1.000000',
        reference_net_price: '0.00',
        currency_code: 'PLN',
        usable_unit_cost: '0.00',
        usable_cost_basis: 'PLN/100g',
        revision: { name: material.revision.name, base_unit_code: material.revision.base_unit_code },
        category_id: material.revision.category_id,
        supplier_id: 'SUP-1',
      })
      return jsonResponse({ data: material }, 201)
    }
    if (url.pathname === '/api/v1/raw-materials/suppliers') {
      return jsonResponse({ data: { data: [
        { id: 'SUP-1', supplier_code: 'D-001', display_name: 'Dostawca demonstracyjny' },
      ] } })
    }
    const detailPath = url.pathname.match(/^\/api\/v1\/raw-materials\/([^/]+)$/)
    if (detailPath && (init?.method ?? 'GET') === 'GET') {
      return detailResponse(decodeURIComponent(detailPath[1]), mode)
    }
    if (url.pathname === '/api/v1/raw-materials/catalog-exports/selection' && init?.method === 'POST') {
      return jsonResponse({ data: {
        export_id: 'DEMO-EXPORT',
        download_url: '/api/v1/raw-materials/catalog-exports/selection/DEMO-EXPORT/download',
      } })
    }
    if (url.pathname.endsWith('/download') && (init?.method ?? 'GET') === 'GET') {
      return new Response('sku,name\nRM-00001,Mąka pszenna typ 550\n', {
        headers: {
          'Content-Type': 'text/csv; charset=utf-8',
          'Content-Disposition': 'attachment; filename="demo-katalog.csv"',
        },
      })
    }

    return jsonResponse({ message: 'Nieznane żądanie demonstracyjne.' }, 404)
  }
}
