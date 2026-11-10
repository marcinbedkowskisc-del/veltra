type PreviewMode = 'demo' | 'compact' | 'restricted' | 'not-ready' | 'session-expired' | 'forbidden'

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
  if (mode === 'not-ready') {
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
    const matchesUnit = !url.searchParams.has('unit')
      || product.revision.base_unit_code === url.searchParams.get('unit')
    return matchesSearch && matchesCategory && matchesUnit
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
  data = data.slice((currentPage - 1) * perPage, currentPage * perPage)
    .map((product, index) => {
      const { category_id: _categoryId, ...row } = product
      return { ...row, ordinal: (from ?? 0) + index }
    })

  if (mode === 'restricted') {
    data = data.map(({
      reference_net_price: _referencePrice,
      currency_code: _currency,
      usable_unit_cost: _unitCost,
      usable_cost_basis: _basis,
      ...row
    }) => row)
  }

  return jsonResponse({
    data,
    meta: { current_page: currentPage, per_page: perPage, total, last_page: lastPage, from, to },
  })
}

export function installVisualPreviewApi(): void {
  const mode = new URLSearchParams(window.location.search).get('mode') as PreviewMode | null ?? 'demo'
  const nativeFetch = window.fetch.bind(window)

  window.fetch = async (input, init) => {
    const url = new URL(typeof input === 'string' ? input : input instanceof Request ? input.url : input.href, window.location.origin)
    if (url.hostname === '127.0.0.1' && url.port === '4180' && url.pathname.startsWith('/screenshots/')) {
      return nativeFetch(input, init)
    }
    if (!url.pathname.startsWith('/api/')) {
      throw new Error('Zablokowano żądanie spoza demonstracyjnego API.')
    }

    if (url.pathname === '/api/v1/raw-materials' && (init?.method ?? 'GET') === 'GET') {
      return catalogResponse(url, mode)
    }
    if (url.pathname === '/api/v1/raw-materials/categories') {
      return jsonResponse({ data: [
        { id: 'CAT-FOOD', name: 'Produkty spożywcze' },
        { id: 'CAT-OIL', name: 'Oleje i tłuszcze' },
      ] })
    }
    if (url.pathname === '/api/v1/raw-materials/suppliers') {
      return jsonResponse({ data: { data: [
        { id: 'SUP-1', supplier_code: 'D-001', display_name: 'Dostawca demonstracyjny' },
      ] } })
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
