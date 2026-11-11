import { isRecord } from './api'

export type PreviewMode = 'authorized' | 'view-only' | 'no-edit' | 'not-ready'
  | 'record-unavailable' | 'version-conflict' | 'demo' | 'compact' | 'restricted'
  | 'session-expired' | 'forbidden'
  | 'detail-forbidden' | 'create-demo' | 'create-saving' | 'create-not-ready' | 'create-unauthorized'
  | 'edit-demo' | 'edit-loading' | 'edit-saving' | 'edit-conflict' | 'edit-not-ready'
  | 'edit-unauthorized' | 'edit-noneditable'

type DemoRevision = {
  revisionNo: number
  name: string
  description: string | null
  categoryId: string
  baseUnitCode: string
  nominalQuantity: string
  usableQuantity: string
  aliases: string[]
  conversionUnit: string
  conversionFactor: string
  conversionBasis: string
}

type DemoProduct = {
  id: string
  ordinal: number
  sku: string
  lifecycle_state: string
  nominal_quantity: string
  usable_quantity: string
  reference_net_price: string
  currency_code: string
  usable_unit_cost: string
  usable_cost_basis: string
  revision: { name: string; base_unit_code: string }
  approvedRevision: DemoRevision | null
  workingRevision: DemoRevision | null
  revisionNo: number
  approvedRevisionId: string | null
  category_id: string
  supplier_id: string
  versionCounter: number
}

const DEMO_LOCATION_ID = '00000000-0000-4000-8000-000000000101'
const DEMO_CATEGORY_ID = '00000000-0000-4000-8000-000000000201'

function revisionId(ordinal: number, revisionNo: number): string {
  return `00000000-0000-4000-8000-${String(ordinal * 100 + revisionNo).padStart(12, '0')}`
}

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

const seedProducts = names.map<DemoProduct>((name, index) => ({
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
  approvedRevision: {
    revisionNo: 3,
    name,
    description: 'Syntetyczny opis demonstracyjny surowca.',
    categoryId: DEMO_CATEGORY_ID,
    baseUnitCode: index % 4 === 0 ? 'kg' : 'g',
    nominalQuantity: `${(250 + index * 25).toFixed(6)}`,
    usableQuantity: `${(240 + index * 24).toFixed(6)}`,
    aliases: [name, 'alias syntetyczny'],
    conversionUnit: 'pc',
    conversionFactor: '2.500000',
    conversionBasis: '1 opakowanie',
  },
  workingRevision: null,
  revisionNo: 3,
  approvedRevisionId: revisionId(index + 1, 3),
  category_id: [6, 7, 31].includes(index) ? 'CAT-OIL' : 'CAT-FOOD',
  supplier_id: index % 2 === 0 ? 'SUP-1' : 'SUP-2',
  versionCounter: 1,
})).map((product, index) => {
  if (index === 0) {
    product.lifecycle_state = 'DRAFT'
    product.revisionNo = 1
    product.approvedRevisionId = null
    product.approvedRevision = null
    product.workingRevision = {
      revisionNo: 1,
      name: product.revision.name,
      description: 'Szkic demonstracyjny do samodzielnej edycji.',
      categoryId: DEMO_CATEGORY_ID,
      baseUnitCode: product.revision.base_unit_code,
      nominalQuantity: product.nominal_quantity,
      usableQuantity: product.usable_quantity,
      aliases: [product.revision.name],
      conversionUnit: 'pc',
      conversionFactor: '2.500000',
      conversionBasis: '1 opakowanie',
    }
  } else if (index === 2) {
    product.workingRevision = {
      revisionNo: 4,
      name: `${product.revision.name} — rewizja robocza`,
      description: 'Syntetyczna, nowsza rewizja robocza.',
      categoryId: DEMO_CATEGORY_ID,
      baseUnitCode: product.revision.base_unit_code,
      nominalQuantity: product.nominal_quantity,
      usableQuantity: product.usable_quantity,
      aliases: [product.revision.name, 'alias rewizji roboczej'],
      conversionUnit: 'pc',
      conversionFactor: '2.500000',
      conversionBasis: '1 opakowanie',
    }
  } else if (index === 3) {
    product.lifecycle_state = 'VERIFICATION'
  } else if (index === 4) {
    product.lifecycle_state = 'ARCHIVED'
  } else if (index === 5) {
    product.approvedRevision = {
      revisionNo: 3,
      name: product.revision.name,
      description: 'Zatwierdzony surowiec z aliasami i przelicznikiem opakowania.',
      categoryId: DEMO_CATEGORY_ID,
      baseUnitCode: product.revision.base_unit_code,
      nominalQuantity: product.nominal_quantity,
      usableQuantity: product.usable_quantity,
      aliases: [product.revision.name, 'kasza prażona', 'gryka'],
      conversionUnit: product.revision.base_unit_code === 'kg' ? 'g' : 'pc',
      conversionFactor: product.revision.base_unit_code === 'kg' ? '0.001000' : '2.500000',
      conversionBasis: product.revision.base_unit_code === 'kg' ? '1 gram' : '1 opakowanie',
    }
  }
  return product
})

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json',
      'Content-Language': 'pl',
    },
  })
}

function catalogResponse(url: URL, mode: PreviewMode, products: DemoProduct[]): Response {
  if (mode === 'not-ready' || mode === 'create-not-ready' || mode === 'edit-not-ready') {
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
    const matchesLifecycle = !url.searchParams.has('lifecycle_state')
      || product.lifecycle_state === url.searchParams.get('lifecycle_state')
    const matchesUnit = !url.searchParams.has('unit')
      || product.revision.base_unit_code === url.searchParams.get('unit')
    return matchesSearch && matchesCategory && matchesSupplier && matchesLifecycle && matchesUnit
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

  const responseData = mode === 'restricted' || mode === 'view-only'
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

function detailResponse(materialId: string, mode: PreviewMode, products: DemoProduct[]): Response {
  if (mode === 'detail-forbidden') return jsonResponse({ message: 'Brak dostępu.' }, 403)
  const product = products.find((item) => item.id === materialId)
  if (!product) return jsonResponse({ message: 'Nie znaleziono surowca.' }, 404)

  const revision = product.approvedRevision ?? product.workingRevision
  if (!revision) return jsonResponse({ message: 'Nie znaleziono rewizji demonstracyjnej.' }, 404)
  const units: Array<{
    unit_code: string
    dimension: string
    conversion_kind: string
    to_base_factor: string
    basis: string | null
  }> = [{
    unit_code: revision.baseUnitCode,
    dimension: revision.baseUnitCode === 'pc' ? 'COUNT' : ['ml', 'l'].includes(revision.baseUnitCode) ? 'VOLUME' : 'MASS',
    conversion_kind: 'BASE',
    to_base_factor: '1.000000',
    basis: null,
  }]
  if (revision.conversionUnit !== revision.baseUnitCode) {
    units.push({
      unit_code: revision.conversionUnit,
      dimension: revision.conversionUnit === 'pc' ? 'COUNT' : ['ml', 'l'].includes(revision.conversionUnit) ? 'VOLUME' : 'MASS',
      conversion_kind: 'CUSTOM',
      to_base_factor: revision.conversionFactor,
      basis: revision.conversionBasis,
    })
  }
  const data = {
    id: product.id,
    code: product.sku,
    sku: product.sku,
    lifecycle_state: product.lifecycle_state,
    version: product.versionCounter,
    approved_revision_id: product.approvedRevisionId,
    revision: {
      id: revisionId(product.ordinal, revision.revisionNo),
      revision_no: revision.revisionNo,
      name: revision.name,
      description: revision.description,
      category_id: revision.categoryId,
      base_unit_code: revision.baseUnitCode,
      base_unit_dimension: revision.baseUnitCode === 'pc' ? 'COUNT' : ['ml', 'l'].includes(revision.baseUnitCode) ? 'VOLUME' : 'MASS',
      nominal_quantity: revision.nominalQuantity,
      usable_quantity: revision.usableQuantity,
      allergen_state: 'DECLARED',
      units,
      aliases: revision.aliases,
    },
    nominal_quantity: revision.nominalQuantity,
    usable_quantity: revision.usableQuantity,
    yield_percent: '96.000000',
    last_update: {
      occurred_at: '2025-01-15T10:30:00Z',
      actor: { id: 'DEMO-ACTOR', first_name: 'Użytkownik', last_name: 'demonstracyjny' },
    },
    history_url: mode === 'restricted' || mode === 'view-only' ? null : `/api/v1/raw-materials/${product.id}/history`,
    ...(mode === 'restricted' || mode === 'view-only' ? {} : {
      reference_net_price: product.reference_net_price,
      currency_code: 'PLN',
      usable_unit_cost: product.usable_unit_cost,
      usable_cost_basis: product.usable_cost_basis,
    }),
  }
  return jsonResponse({ data })
}

function revisionsResponse(materialId: string, products: DemoProduct[]): Response {
  const product = products.find((item) => item.id === materialId)
  if (!product) return jsonResponse({ message: 'Nie znaleziono surowca.' }, 404)
  const approved = product.approvedRevision
  const revisions = []
  if (approved) {
    revisions.push({
      id: revisionId(product.ordinal, approved.revisionNo),
      revision_no: approved.revisionNo,
      name: approved.name,
      category_id: approved.categoryId,
      base_unit_code: approved.baseUnitCode,
      allergen_state: 'DECLARED',
      critical_change: false,
      created_at: '2025-01-15T10:30:00Z',
      approvals: [{ outcome: 'APPROVED', actor_user_id: 'DEMO-ACTOR', occurred_at: '2025-01-15T10:30:00Z', reason: 'Weryfikacja demonstracyjna' }],
    })
  }
  if (product.workingRevision) {
    revisions.unshift({
      id: revisionId(product.ordinal, product.workingRevision.revisionNo),
      revision_no: product.workingRevision.revisionNo,
      name: product.workingRevision.name,
      category_id: product.workingRevision.categoryId,
      base_unit_code: product.workingRevision.baseUnitCode,
      allergen_state: 'DECLARED',
      critical_change: product.lifecycle_state === 'ACTIVE',
      created_at: '2025-02-01T10:30:00Z',
      approvals: [],
    })
  }
  return jsonResponse({ data: [
    ...revisions,
    {
      id: revisionId(product.ordinal, Math.max(1, (approved?.revisionNo ?? 2) - 1)),
      revision_no: Math.max(1, (approved?.revisionNo ?? 2) - 1),
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

function canEditProduct(mode: PreviewMode, product: DemoProduct): boolean {
  const globalEdit = ['authorized', 'version-conflict', 'record-unavailable', 'edit-demo', 'edit-loading', 'edit-saving', 'edit-conflict', 'edit-noneditable'].includes(mode)
  const editableLifecycle = product.lifecycle_state === 'DRAFT' || product.lifecycle_state === 'ACTIVE'
  return globalEdit && editableLifecycle && mode !== 'record-unavailable' && mode !== 'edit-noneditable'
}

function completionResponse(materialId: string, mode: PreviewMode, products: DemoProduct[]): Response {
  const product = products.find((item) => item.id === materialId)
  if (!product) return jsonResponse({ message: 'Nie znaleziono surowca.' }, 404)
  const editable = canEditProduct(mode, product)
  const currentRevision = product.workingRevision ?? product.approvedRevision
  return jsonResponse({ data: {
    lifecycle_state: product.lifecycle_state,
    revision_id: revisionId(product.ordinal, currentRevision?.revisionNo ?? product.revisionNo),
    version: editable ? `synthetic-edit-version-${product.versionCounter}` : null,
    error_count: 0,
    missing_requirement_count: 0,
    errors: [],
    missing_requirements: [],
    eligible_for_next_action: false,
    available_actions: editable ? ['edit'] : [],
  } })
}

function editorResponse(materialId: string, products: DemoProduct[]): Response {
  const product = products.find((item) => item.id === materialId)
  if (!product) return jsonResponse({ message: 'Nie znaleziono surowca.' }, 404)
  const revision = product.workingRevision ?? product.approvedRevision
  if (!revision) return jsonResponse({ message: 'Nie znaleziono rewizji demonstracyjnej.' }, 404)
  const units: Array<{
    unit_code: string
    dimension: string
    conversion_kind: string
    to_base_factor: string
    basis: string | null
  }> = [{
    unit_code: revision.baseUnitCode,
    dimension: revision.baseUnitCode === 'pc' ? 'COUNT' : ['ml', 'l'].includes(revision.baseUnitCode) ? 'VOLUME' : 'MASS',
    conversion_kind: 'BASE',
    to_base_factor: '1.000000',
    basis: null,
  }]
  if (revision.conversionUnit !== revision.baseUnitCode) {
    units.push({
      unit_code: revision.conversionUnit,
      dimension: revision.conversionUnit === 'pc' ? 'COUNT' : ['ml', 'l'].includes(revision.conversionUnit) ? 'VOLUME' : 'MASS',
      conversion_kind: 'CUSTOM',
      to_base_factor: revision.conversionFactor,
      basis: revision.conversionBasis,
    })
  }
  return jsonResponse({ data: {
    id: product.id,
    code: product.sku,
    sku: product.sku,
    lifecycle_state: product.lifecycle_state,
    version: `synthetic-edit-version-${product.versionCounter}`,
    revision: {
      id: revisionId(product.ordinal, revision.revisionNo),
      revision_no: revision.revisionNo,
      name: revision.name,
      description: revision.description,
      category_id: revision.categoryId,
      base_unit_code: revision.baseUnitCode,
      base_unit_dimension: revision.baseUnitCode === 'pc' ? 'COUNT' : ['ml', 'l'].includes(revision.baseUnitCode) ? 'VOLUME' : 'MASS',
      nominal_quantity: revision.nominalQuantity,
      usable_quantity: revision.usableQuantity,
      units,
      aliases: revision.aliases,
    },
    allergen_declaration: null,
    storage_variants: [],
  } })
}

const MODES = new Set<PreviewMode>([
  'authorized', 'view-only', 'no-edit', 'not-ready', 'record-unavailable', 'version-conflict',
  'demo', 'compact', 'restricted', 'session-expired', 'forbidden', 'detail-forbidden',
  'create-demo', 'create-saving', 'create-not-ready', 'create-unauthorized',
  'edit-demo', 'edit-loading', 'edit-saving', 'edit-conflict', 'edit-not-ready',
  'edit-unauthorized', 'edit-noneditable',
])

function initialModeFromLocation(): PreviewMode {
  const requested = new URLSearchParams(window.location.search).get('mode')
  if (!requested || requested === 'demo') return 'authorized'
  if (requested === 'create-demo' || requested === 'create-saving'
    || requested === 'edit-demo' || requested === 'edit-loading' || requested === 'edit-saving') return 'authorized'
  if (requested === 'create-unauthorized' || requested === 'edit-unauthorized') return 'view-only'
  if (requested === 'edit-conflict') return 'version-conflict'
  if (requested === 'edit-noneditable') return 'record-unavailable'
  if (requested === 'create-not-ready' || requested === 'edit-not-ready') return 'not-ready'
  return MODES.has(requested as PreviewMode) ? requested as PreviewMode : 'authorized'
}

export type VisualPreviewApiController = {
  getMode: () => PreviewMode
  setMode: (nextMode: PreviewMode) => void
  reset: () => void
  getStats: () => { requests: number; blocked: number; lastPath: string }
}

export function installVisualPreviewApi(initialMode = initialModeFromLocation()): VisualPreviewApiController {
  let mode = initialMode
  let products = seedProducts.map((product) => ({
    ...product,
    approvedRevision: product.approvedRevision ? { ...product.approvedRevision, aliases: [...product.approvedRevision.aliases] } : null,
    workingRevision: product.workingRevision ? { ...product.workingRevision, aliases: [...product.workingRevision.aliases] } : null,
  }))
  let reservations = new Map<string, { reservation_id: string; sku: string; expires_at: string; status: string }>()
  let createdMaterials = new Map<string, {
    id: string
    code: string
    sku: string
    lifecycle_state: 'DRAFT'
    version: number
    approved_revision_id: null
    revision: { name: string; description: string | null; category_id: string; base_unit_code: string }
  }>()
  let nextSku = 42
  let requests = 0
  let blocked = 0
  let lastPath = ''

  function capabilitiesResponse(): Response {
    const unavailable = mode === 'not-ready' || mode === 'create-not-ready' || mode === 'edit-not-ready'
    const authorized = ['authorized', 'no-edit', 'record-unavailable', 'version-conflict', 'not-ready', 'create-demo', 'create-saving', 'create-not-ready'].includes(mode)
    const editAuthorized = ['authorized', 'version-conflict', 'record-unavailable', 'not-ready', 'edit-demo', 'edit-loading', 'edit-saving', 'edit-conflict', 'edit-noneditable', 'edit-not-ready'].includes(mode)
    return jsonResponse({ data: {
      module: 'RAW_MATERIALS',
      location_id: DEMO_LOCATION_ID,
      readiness: unavailable ? 'NOT_READY' : 'READY',
      available: !unavailable,
      permissions: {
        create: authorized || mode === 'create-not-ready',
        edit: editAuthorized || mode === 'edit-not-ready',
      },
      actions: {
        create: authorized && !unavailable,
        edit: editAuthorized && !unavailable,
      },
    } })
  }

  const demoFetch: typeof window.fetch = async (input, init) => {
    let url: URL
    try {
      url = new URL(typeof input === 'string' ? input : input instanceof Request ? input.url : input.href, window.location.origin)
    } catch {
      blocked += 1
      return jsonResponse({ error: { code: 'DEMO_REQUEST_BLOCKED', message: 'Zablokowano nieprawidłowe żądanie w podglądzie.' } }, 400)
    }
    const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase()
    requests += 1
    lastPath = `${method} ${url.pathname}`
    if (url.origin !== window.location.origin) {
      blocked += 1
      return jsonResponse({ error: { code: 'DEMO_REQUEST_BLOCKED', message: 'Podgląd blokuje żądania poza lokalnym źródłem demonstracyjnym.' } }, 403)
    }
    if (!url.pathname.startsWith('/api/')) {
      blocked += 1
      return jsonResponse({ error: { code: 'DEMO_REQUEST_NOT_SUPPORTED', message: 'Nieobsługiwane żądanie demonstracyjne zostało zablokowane.' } }, 404)
    }

    if (url.pathname === '/api/v1/security/raw-materials/capabilities') {
      return capabilitiesResponse()
    }

    if (url.pathname === '/api/v1/raw-materials' && method === 'GET') {
      if (url.searchParams.get('search')?.trim()) {
        await new Promise((resolve) => setTimeout(resolve, 900))
      }
      return catalogResponse(url, mode, products)
    }
    const revisionPath = url.pathname.match(/^\/api\/v1\/raw-materials\/([^/]+)\/revisions$/)
    if (revisionPath && method === 'GET') {
      return revisionsResponse(decodeURIComponent(revisionPath[1]), products)
    }
    if (url.pathname === '/api/v1/raw-materials/categories' && method === 'GET') {
      return jsonResponse({ data: [
        { id: 'CAT-FOOD', name: 'Produkty spożywcze' },
        { id: 'CAT-OIL', name: 'Oleje i tłuszcze' },
        { id: '00000000-0000-4000-8000-000000000201', name: 'Nowe surowce demonstracyjne' },
      ] })
    }
    const completionPath = url.pathname.match(/^\/api\/v1\/raw-materials\/([^/]+)\/completion$/)
    if (completionPath && method === 'GET') {
      return completionResponse(decodeURIComponent(completionPath[1]), mode, products)
    }
    const editorPath = url.pathname.match(/^\/api\/v1\/raw-materials\/([^/]+)\/editor$/)
    if (editorPath && method === 'GET') {
      const materialId = decodeURIComponent(editorPath[1])
        const product = products.find((item) => item.id === materialId)
        if (!product || !canEditProduct(mode, product)) {
          return jsonResponse({ message: 'Brak syntetycznego uprawnienia do edycji.' }, 403)
        }
      if (mode === 'edit-loading') await new Promise((resolve) => setTimeout(resolve, 1800))
      return editorResponse(materialId, products)
    }
    const draftPath = url.pathname.match(/^\/api\/v1\/raw-materials\/([^/]+)\/draft$/)
    if (draftPath && method === 'PATCH') {
      const materialId = decodeURIComponent(draftPath[1])
      const product = products.find((item) => item.id === materialId)
      if (!product || !canEditProduct(mode, product)) {
        return jsonResponse({ message: 'Brak syntetycznego uprawnienia do edycji.' }, 403)
      }
      if (mode === 'version-conflict' || mode === 'edit-conflict') {
        return jsonResponse({ error: {
          code: 'RAW_MATERIAL_VERSION_CONFLICT',
          message: 'Surowiec został zmieniony. Wczytaj aktualną wersję; lokalne zmiany zostaną utracone.',
        } }, 409)
      }
      if (mode === 'edit-saving') await new Promise((resolve) => setTimeout(resolve, 2000))
      let body: Record<string, unknown>
      try {
        const parsed: unknown = JSON.parse(String(init?.body ?? '{}'))
        if (!isRecord(parsed)) throw new Error('Nieprawidłowy payload demonstracyjny.')
        body = parsed
      } catch {
        return jsonResponse({ message: 'Nieprawidłowy payload demonstracyjny.' }, 422)
      }
      if (typeof body.name !== 'string' || typeof body.category_id !== 'string'
        || typeof body.base_unit_code !== 'string' || !Array.isArray(body.aliases)
        || !Array.isArray(body.conversions)) {
        return jsonResponse({ errors: { name: ['Sprawdź dane formularza.'] } }, 422)
      }
      const requestHeaders = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined))
      if (requestHeaders.get('If-Match') !== `synthetic-edit-version-${product.versionCounter}`) {
        return jsonResponse({ error: { code: 'RAW_MATERIAL_VERSION_CONFLICT', message: 'Wersja demonstracyjna jest nieaktualna.' } }, 409)
      }
      const currentRevision = product.workingRevision ?? product.approvedRevision
      const revisionNo = (currentRevision?.revisionNo ?? product.revisionNo) + 1
      const conversions = body.conversions.filter(isRecord)
      const conversion = conversions.find((item) => item.unit_code !== body.base_unit_code)
      const aliases = body.aliases.filter((alias): alias is string => typeof alias === 'string')
      const nextRevision: DemoRevision = {
        revisionNo,
        name: body.name,
        description: typeof body.description === 'string' ? body.description : null,
        categoryId: body.category_id,
        baseUnitCode: body.base_unit_code,
        nominalQuantity: typeof body.nominal_quantity === 'string' ? body.nominal_quantity : currentRevision?.nominalQuantity ?? product.nominal_quantity,
        usableQuantity: typeof body.usable_quantity === 'string' ? body.usable_quantity : currentRevision?.usableQuantity ?? product.usable_quantity,
        aliases,
        conversionUnit: typeof conversion?.unit_code === 'string' ? conversion.unit_code : body.base_unit_code,
        conversionFactor: typeof conversion?.to_base_factor === 'string' ? conversion.to_base_factor : '1.000000',
        conversionBasis: typeof conversion?.basis === 'string' ? conversion.basis : '',
      }
      product.workingRevision = nextRevision
      product.revisionNo = revisionNo
      product.versionCounter += 1
      if (product.lifecycle_state === 'DRAFT') {
        product.revision = { name: nextRevision.name, base_unit_code: nextRevision.baseUnitCode }
        product.nominal_quantity = nextRevision.nominalQuantity
        product.usable_quantity = nextRevision.usableQuantity
        product.category_id = nextRevision.categoryId
      }
      return jsonResponse({ data: {
        id: product.id,
        code: product.sku,
        sku: product.sku,
        lifecycle_state: product.lifecycle_state,
        version: `synthetic-edit-version-${product.versionCounter}`,
        revision: {
          id: revisionId(product.ordinal, revisionNo),
          revision_no: revisionNo,
        },
      } })
    }
    if (url.pathname === '/api/v1/raw-materials/sku-reservations' && method === 'POST') {
      if (!capabilitiesResponseAllowedForCreate()) {
        return jsonResponse({ message: 'Brak uprawnień demonstracyjnych.' }, 403)
      }
      const requestHeaders = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined))
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
    if (url.pathname === '/api/v1/raw-materials' && method === 'POST') {
      if (!capabilitiesResponseAllowedForCreate()) {
        return jsonResponse({ message: 'Brak uprawnień demonstracyjnych.' }, 403)
      }
      if (mode === 'create-saving') await new Promise((resolve) => setTimeout(resolve, 2200))
      let body: Record<string, unknown>
      try {
        const parsed: unknown = JSON.parse(String(init?.body ?? '{}'))
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
      const createConversions = Array.isArray(body.conversions) ? body.conversions.filter(isRecord) : []
      const createConversion = createConversions.find((item) => item.unit_code !== body.base_unit_code)
      const ordinal = products.length + 1
      products.push({
        id: material.id,
        ordinal,
        sku: material.sku,
        lifecycle_state: material.lifecycle_state,
        nominal_quantity: typeof body.nominal_quantity === 'string' ? body.nominal_quantity : '1.000000',
        usable_quantity: typeof body.usable_quantity === 'string' ? body.usable_quantity : '1.000000',
        reference_net_price: '0.00',
        currency_code: 'PLN',
        usable_unit_cost: '0.00',
        usable_cost_basis: 'PLN/100g',
        revision: { name: material.revision.name, base_unit_code: material.revision.base_unit_code },
        approvedRevision: null,
        workingRevision: {
          revisionNo: 1,
          name: material.revision.name,
          description: material.revision.description,
          categoryId: material.revision.category_id,
          baseUnitCode: material.revision.base_unit_code,
          nominalQuantity: typeof body.nominal_quantity === 'string' ? body.nominal_quantity : '1.000000',
          usableQuantity: typeof body.usable_quantity === 'string' ? body.usable_quantity : '1.000000',
          aliases: Array.isArray(body.aliases)
            ? body.aliases.filter((alias): alias is string => typeof alias === 'string')
            : [],
          conversionUnit: typeof createConversion?.unit_code === 'string'
            ? createConversion.unit_code
            : material.revision.base_unit_code,
          conversionFactor: typeof createConversion?.to_base_factor === 'string' ? createConversion.to_base_factor : '1.000000',
          conversionBasis: typeof createConversion?.basis === 'string' ? createConversion.basis : '',
        },
        revisionNo: 1,
        approvedRevisionId: null,
        category_id: material.revision.category_id,
        supplier_id: 'SUP-1',
        versionCounter: 1,
      })
      return jsonResponse({ data: material }, 201)
    }
    if (url.pathname === '/api/v1/raw-materials/suppliers' && method === 'GET') {
      return jsonResponse({ data: { data: [
        { id: 'SUP-1', supplier_code: 'D-001', display_name: 'Dostawca demonstracyjny' },
      ] } })
    }
    const detailPath = url.pathname.match(/^\/api\/v1\/raw-materials\/([^/]+)$/)
    if (detailPath && method === 'GET') {
      return detailResponse(decodeURIComponent(detailPath[1]), mode, products)
    }
    if (url.pathname === '/api/v1/raw-materials/catalog-exports/selection' && method === 'POST') {
      return jsonResponse({ data: {
        export_id: 'DEMO-EXPORT',
        download_url: '/api/v1/raw-materials/catalog-exports/selection/DEMO-EXPORT/download',
      } })
    }
    if (url.pathname.endsWith('/download') && method === 'GET') {
      return new Response('sku,name\nRM-00001,Mąka pszenna typ 550\n', {
        headers: {
          'Content-Type': 'text/csv; charset=utf-8',
          'Content-Disposition': 'attachment; filename="demo-katalog.csv"',
        },
      })
    }

    blocked += 1
    return jsonResponse({ error: { code: 'DEMO_REQUEST_NOT_SUPPORTED', message: 'Nieobsługiwane żądanie API zostało zablokowane w trybie demonstracyjnym.' } }, 404)
  }
  window.fetch = demoFetch

  function capabilitiesResponseAllowedForCreate(): boolean {
    return ['authorized', 'no-edit', 'record-unavailable', 'version-conflict', 'create-demo', 'create-saving'].includes(mode)
  }

  return {
    getMode: () => mode,
    setMode: (nextMode) => {
      if (!MODES.has(nextMode)) throw new Error('Nieznany scenariusz demonstracyjny.')
      mode = nextMode
    },
    reset: () => {
      products = seedProducts.map((product) => ({
        ...product,
        approvedRevision: product.approvedRevision ? { ...product.approvedRevision, aliases: [...product.approvedRevision.aliases] } : null,
        workingRevision: product.workingRevision ? { ...product.workingRevision, aliases: [...product.workingRevision.aliases] } : null,
      }))
      reservations = new Map()
      createdMaterials = new Map()
      nextSku = 42
      requests = 0
      blocked = 0
      lastPath = ''
    },
    getStats: () => ({ requests, blocked, lastPath }),
  }
}
