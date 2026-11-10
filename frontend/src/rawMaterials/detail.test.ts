import { describe, expect, it } from 'vitest'
import { parseRawMaterialDetail, parseRawMaterialRevisions } from './detail'

const response = {
  id: 'material-1',
  code: 'RM-00001',
  sku: 'RM-00001',
  lifecycle_state: 'ACTIVE',
  approved_revision_id: 'revision-1',
  revision: {
    id: 'revision-1',
    revision_no: 3,
    name: 'Mąka pszenna',
    description: 'Zatwierdzony opis.',
    category_id: 'category-1',
    base_unit_code: 'kg',
    base_unit_dimension: 'MASS',
    allergen_state: 'DECLARED',
    units: [{
      unit_code: 'kg',
      dimension: 'MASS',
      conversion_kind: 'BASE',
      to_base_factor: '1.000000',
      basis: null,
    }],
    aliases: ['mąka'],
  },
  nominal_quantity: '10.000000',
  usable_quantity: '8.000000',
  yield_percent: '80.000000',
  last_update: {
    occurred_at: '2025-01-15T10:30:00Z',
    actor: { id: 'actor-1', first_name: 'Jan', last_name: 'Kowalski' },
  },
  history_url: '/api/v1/raw-materials/material-1/history',
}

describe('szczegóły surowca', () => {
  it('parsuje zatwierdzony snapshot i zachowuje tylko pola finansowe zwrócone przez API', () => {
    const parsed = parseRawMaterialDetail({
      ...response,
      reference_net_price: '123.450000',
      currency_code: 'PLN',
      usable_unit_cost: '15.431250',
      usable_cost_basis: 'PLN/100g',
    })

    expect(parsed.revision?.name).toBe('Mąka pszenna')
    expect(parsed.lifecycleState).toBe('ACTIVE')
    expect(parsed.referenceNetPrice).toBe('123.450000')
    expect(parsed.usableUnitCost).toBe('15.431250')
  })

  it('nie tworzy pól finansowych przy odpowiedzi bez tych pól', () => {
    const parsed = parseRawMaterialDetail(response)

    expect(parsed).not.toHaveProperty('referenceNetPrice')
    expect(parsed).not.toHaveProperty('usableUnitCost')
  })

  it('odrzuca nieprawidłowy kod waluty zamiast wyświetlać kwotę', () => {
    expect(() => parseRawMaterialDetail({
      ...response,
      reference_net_price: '123.45',
      currency_code: 'EUR',
    })).toThrow('nieprawidłowe dane finansowe')
  })

  it('parsuje tylko jawny kontrakt listy rewizji', () => {
    const revisions = parseRawMaterialRevisions([{
      id: 'revision-1',
      revision_no: 3,
      name: 'Mąka pszenna',
      category_id: 'category-1',
      base_unit_code: 'kg',
      allergen_state: 'DECLARED',
      critical_change: false,
      created_at: '2025-01-15T10:30:00Z',
      approvals: [{
        outcome: 'APPROVED',
        actor_user_id: 'actor-1',
        occurred_at: '2025-01-15T10:30:00Z',
        reason: null,
      }],
    }])

    expect(revisions).toHaveLength(1)
    expect(revisions[0].revisionNo).toBe(3)
    expect(revisions[0].approvals[0].outcome).toBe('APPROVED')
  })
})
