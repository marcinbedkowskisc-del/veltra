import { describe, expect, it } from 'vitest'
import {
  catalogExportPayload,
  catalogSearchParams,
  formatDecimal,
  formatMoney,
  parseCatalogEnvelope,
  parseCategories,
  parseSuppliers,
  type CatalogQuery,
} from './catalog'

const query: CatalogQuery = {
  search: '  mąka  ',
  categoryId: 'category-1',
  supplierId: 'supplier-1',
  lifecycleState: 'ACTIVE',
  unit: 'kg',
  sort: 'name',
  direction: 'asc',
  page: 2,
  perPage: 25,
}

function row(financial: Record<string, unknown> = {}) {
  return {
    id: 'material-1',
    sku: 'RM-00001',
    ordinal: 1,
    nominal_quantity: '100.000000',
    usable_quantity: '82.500000',
    lifecycle_state: 'ACTIVE',
    revision: { name: 'Mąka pszenna', base_unit_code: 'g' },
    ...financial,
  }
}

function envelope(data: unknown[]) {
  return {
    data,
    meta: { current_page: 1, per_page: 25, total: data.length, last_page: 1, from: data.length ? 1 : null, to: data.length || null },
  }
}

describe('kontrakt katalogu RAW', () => {
  it('mapuje wyszukiwanie, filtry, sortowanie i paginację na parametry API', () => {
    const params = catalogSearchParams(query)
    expect(Object.fromEntries(params)).toEqual({
      sort: 'name',
      direction: 'asc',
      page: '2',
      per_page: '25',
      search: 'mąka',
      category_id: 'category-1',
      supplier_id: 'supplier-1',
      lifecycle_state: 'ACTIVE',
      unit: 'kg',
    })
  })

  it('nie wysyła paginacji w eksporcie oraz zachowuje filtry i zaznaczone UUID', () => {
    const payload = catalogExportPayload(query, ['material-1', 'material-2'])
    expect(payload).toEqual({
      search: 'mąka',
      category_id: 'category-1',
      supplier_id: 'supplier-1',
      lifecycle_state: 'ACTIVE',
      unit: 'kg',
      sort: 'name',
      direction: 'asc',
      material_ids: ['material-1', 'material-2'],
    })
    expect(payload).not.toHaveProperty('page')
    expect(payload).not.toHaveProperty('per_page')
  })

  it('ustawia domyślne uprawnienia finansowe na ograniczone i nie zachowuje nieobecnych pól', () => {
    const result = parseCatalogEnvelope(envelope([row()]))
    expect(result.canReadReferencePrice).toBe(false)
    expect(result.canReadUsableCost).toBe(false)
    expect(result.rows[0]).not.toHaveProperty('referencePrice')
    expect(result.rows[0]).not.toHaveProperty('usableUnitCost')
    expect(result.rows[0]).toMatchObject({
      id: 'material-1',
      ordinal: 1,
      sku: 'RM-00001',
      name: 'Mąka pszenna',
      unit: 'g',
      nominalQuantity: '100.000000',
      usableQuantity: '82.500000',
    })
  })

  it('rozpoznaje odrębne przyznanie ceny i kosztu z pól faktycznie zwróconych przez API', () => {
    const result = parseCatalogEnvelope(envelope([row({
      reference_net_price: '123.45000000000000',
      currency_code: 'PLN',
      usable_unit_cost: '15.00000000000000',
      usable_cost_basis: 'PLN/100g',
    })]))
    expect(result.canReadReferencePrice).toBe(true)
    expect(result.canReadUsableCost).toBe(true)
    expect(result.rows[0]).toMatchObject({
      referencePrice: '123.45000000000000',
      usableUnitCost: '15.00000000000000',
      usableCostBasis: 'PLN/100g',
    })
  })

  it('nie udostępnia pól finansowych przy niespójnej odpowiedzi i nie określa dostępu przy pustej stronie', () => {
    const inconsistent = parseCatalogEnvelope(envelope([
      row({ reference_net_price: '20.00', currency_code: 'PLN' }),
      { ...row(), id: 'material-2', sku: 'RM-00002', ordinal: 2 },
    ]))
    expect(inconsistent.canReadReferencePrice).toBe(false)
    expect(inconsistent.rows.every((item) => !Object.hasOwn(item, 'referencePrice'))).toBe(true)

    const empty = parseCatalogEnvelope(envelope([]))
    expect(empty.canReadReferencePrice).toBeNull()
    expect(empty.canReadUsableCost).toBeNull()
  })

  it('parsuje dostępne dane filtrów i zachowuje ich UUID', () => {
    expect(parseCategories([{ id: 'category-1', name: 'Mąki' }])).toEqual([{ id: 'category-1', name: 'Mąki' }])
    expect(parseSuppliers({
      data: [{ id: 'supplier-1', supplier_code: 'SUP-01', display_name: 'Dostawca A' }],
      meta: { current_page: 1, per_page: 100, total: 1 },
    })).toEqual([{ id: 'supplier-1', code: 'SUP-01', name: 'Dostawca A' }])
  })

  it('formatuje kwoty i ilości bez konwersji do liczb zmiennoprzecinkowych', () => {
    expect(formatMoney('123456789012345.67500000000000').replace(/\s/g, ' ')).toBe('123 456 789 012 345,68')
    expect(formatMoney('0.004')).toBe('0,00')
    expect(formatDecimal('82.500000')).toBe('82,5')
    expect(formatDecimal(null)).toBe('—')
  })
})
