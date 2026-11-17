import { isRecord } from '../api'

const DECIMAL = /^\d{1,30}(?:\.\d{1,14})?$/

export type RawMaterialUnit = {
  unitCode: string
  dimension: string
  conversionKind: string
  toBaseFactor: string
  basis: string | null
}

export type RawMaterialDetail = {
  id: string
  sku: string
  lifecycleState: string
  approvedRevisionId: string | null
  revision: {
    id: string
    revisionNo: number
    name: string
    description: string | null
    categoryId: string | null
    baseUnitCode: string
    baseUnitDimension: string
    allergenState: string
    units: RawMaterialUnit[]
    aliases: string[]
  } | null
  nominalQuantity: string | null
  usableQuantity: string | null
  yieldPercent: string | null
  referenceNetPrice?: string | null
  currencyCode?: string
  usableUnitCost?: string | null
  usableCostBasis?: string
  lastUpdate: { occurredAt: string; actor: string | null } | null
  historyUrl: string | null
}

export type RawMaterialRevision = {
  id: string
  revisionNo: number
  name: string
  categoryId: string | null
  baseUnitCode: string
  allergenState: string
  criticalChange: boolean
  createdAt: string
  approvals: Array<{
    outcome: string
    occurredAt: string
    reason: string | null
  }>
}

export type RawMaterialReferencePrice = {
  id: string
  eventNo: number
  eventType: string
  effectiveFrom: string
  netPlnPerBaseUnit: string | null
  netPlnPerPackage: string | null
  pricingInputBasis: 'PACKAGE' | 'NOMINAL_UNIT' | 'LEGACY_BASE_UNIT' | null
  pricingInputAmount: string | null
  pricingInputUnitCode: string | null
  pricingNominalQuantity: string | null
  pricingUsableQuantity: string | null
  pricingBaseUnitCode: string | null
  currencyCode: 'PLN'
}

function requiredString(value: unknown, field: string): string {
  if (typeof value !== 'string' || value.length === 0) {
    throw new Error(`Backend zwrócił nieprawidłowe szczegóły surowca: ${field}.`)
  }
  return value
}

function nullableString(value: unknown, field: string): string | null {
  if (value === null) return null
  return requiredString(value, field)
}

function nullableDecimal(value: unknown, field: string): string | null {
  if (value === null) return null
  if (typeof value !== 'string' || !DECIMAL.test(value)) {
    throw new Error(`Backend zwrócił nieprawidłowe szczegóły surowca: ${field}.`)
  }
  return value
}

function parseRevision(value: unknown): RawMaterialDetail['revision'] {
  if (value === null) return null
  if (!isRecord(value) || !Array.isArray(value.units) || !Array.isArray(value.aliases)
    || !Number.isInteger(value.revision_no)) {
    throw new Error('Backend zwrócił nieprawidłową rewizję surowca.')
  }

  const units = value.units.map((unit): RawMaterialUnit => {
    if (!isRecord(unit)) throw new Error('Backend zwrócił nieprawidłową jednostkę surowca.')
    return {
      unitCode: requiredString(unit.unit_code, 'revision.units.unit_code'),
      dimension: requiredString(unit.dimension, 'revision.units.dimension'),
      conversionKind: requiredString(unit.conversion_kind, 'revision.units.conversion_kind'),
      toBaseFactor: requiredString(unit.to_base_factor, 'revision.units.to_base_factor'),
      basis: nullableString(unit.basis, 'revision.units.basis'),
    }
  })
  if (!value.aliases.every((alias) => typeof alias === 'string')) {
    throw new Error('Backend zwrócił nieprawidłowe aliasy surowca.')
  }

  return {
    id: requiredString(value.id, 'revision.id'),
    revisionNo: value.revision_no as number,
    name: requiredString(value.name, 'revision.name'),
    description: nullableString(value.description, 'revision.description'),
    categoryId: nullableString(value.category_id, 'revision.category_id'),
    baseUnitCode: requiredString(value.base_unit_code, 'revision.base_unit_code'),
    baseUnitDimension: requiredString(value.base_unit_dimension, 'revision.base_unit_dimension'),
    allergenState: requiredString(value.allergen_state, 'revision.allergen_state'),
    units,
    aliases: value.aliases as string[],
  }
}

export function parseRawMaterialDetail(value: unknown): RawMaterialDetail {
  if (!isRecord(value)) throw new Error('Backend zwrócił nieprawidłowe szczegóły surowca.')
  const revision = parseRevision(value.revision)
  let lastUpdate: RawMaterialDetail['lastUpdate'] = null
  if (value.last_update !== null) {
    if (!isRecord(value.last_update)) throw new Error('Backend zwrócił nieprawidłową datę aktualizacji.')
    const actor = value.last_update.actor
    lastUpdate = {
      occurredAt: requiredString(value.last_update.occurred_at, 'last_update.occurred_at'),
      actor: actor === null ? null : isRecord(actor)
        ? [actor.first_name, actor.last_name].filter((part): part is string => typeof part === 'string').join(' ') || null
        : (() => { throw new Error('Backend zwrócił nieprawidłowego autora aktualizacji.') })(),
    }
  }

  const hasReferencePrice = Object.hasOwn(value, 'reference_net_price')
  const hasUsableCost = Object.hasOwn(value, 'usable_unit_cost')
  if ((hasReferencePrice && value.currency_code !== 'PLN')
    || (hasUsableCost && (!Object.hasOwn(value, 'usable_cost_basis')
      || typeof value.usable_cost_basis !== 'string'))) {
    throw new Error('Backend zwrócił nieprawidłowe dane finansowe surowca.')
  }

  const parsed: RawMaterialDetail = {
    id: requiredString(value.id, 'id'),
    sku: requiredString(value.sku, 'sku'),
    lifecycleState: requiredString(value.lifecycle_state, 'lifecycle_state'),
    approvedRevisionId: nullableString(value.approved_revision_id, 'approved_revision_id'),
    revision,
    nominalQuantity: nullableDecimal(value.nominal_quantity, 'nominal_quantity'),
    usableQuantity: nullableDecimal(value.usable_quantity, 'usable_quantity'),
    yieldPercent: nullableDecimal(value.yield_percent, 'yield_percent'),
    lastUpdate,
    historyUrl: nullableString(value.history_url, 'history_url'),
  }
  if (hasReferencePrice) {
    parsed.referenceNetPrice = nullableDecimal(value.reference_net_price, 'reference_net_price')
    parsed.currencyCode = 'PLN'
  }
  if (hasUsableCost) {
    parsed.usableUnitCost = nullableDecimal(value.usable_unit_cost, 'usable_unit_cost')
    parsed.usableCostBasis = value.usable_cost_basis as string
  }
  return parsed
}

export function parseRawMaterialRevisions(value: unknown): RawMaterialRevision[] {
  if (!Array.isArray(value)) throw new Error('Backend zwrócił nieprawidłową historię rewizji.')
  return value.map((revision): RawMaterialRevision => {
    if (!isRecord(revision) || !Number.isInteger(revision.revision_no)
      || typeof revision.critical_change !== 'boolean' || !Array.isArray(revision.approvals)) {
      throw new Error('Backend zwrócił nieprawidłową rewizję w historii.')
    }
    const approvals = revision.approvals.map((approval) => {
      if (!isRecord(approval)) throw new Error('Backend zwrócił nieprawidłowe zatwierdzenie rewizji.')
      return {
        outcome: requiredString(approval.outcome, 'approvals.outcome'),
        occurredAt: requiredString(approval.occurred_at, 'approvals.occurred_at'),
        reason: nullableString(approval.reason, 'approvals.reason'),
      }
    })
    return {
      id: requiredString(revision.id, 'id'),
      revisionNo: revision.revision_no as number,
      name: requiredString(revision.name, 'name'),
      categoryId: nullableString(revision.category_id, 'category_id'),
      baseUnitCode: requiredString(revision.base_unit_code, 'base_unit_code'),
      allergenState: requiredString(revision.allergen_state, 'allergen_state'),
      criticalChange: revision.critical_change,
      createdAt: requiredString(revision.created_at, 'created_at'),
      approvals,
    }
  })
}

export function parseRawMaterialReferencePrices(value: unknown): RawMaterialReferencePrice[] {
  if (!Array.isArray(value)) throw new Error('Backend zwrócił nieprawidłową historię cen surowca.')
  return value.map((event): RawMaterialReferencePrice => {
    if (!isRecord(event) || !Number.isInteger(event.event_no) || event.currency_code !== 'PLN') {
      throw new Error('Backend zwrócił nieprawidłową historię cen surowca.')
    }
    const invalidPriceHistory = () => new Error('Backend zwrócił nieprawidłową historię cen surowca.')
    const priceString = (item: unknown) => {
      if (typeof item !== 'string' || item.length === 0) throw invalidPriceHistory()
      return item
    }
    const priceDecimal = (item: unknown) => {
      if (item === null) return null
      if (typeof item !== 'string' || !DECIMAL.test(item)) throw invalidPriceHistory()
      return item
    }
    const pricingInputBasis = event.pricing_input_basis ?? null
    if (pricingInputBasis !== null
      && !['PACKAGE', 'NOMINAL_UNIT', 'LEGACY_BASE_UNIT'].includes(String(pricingInputBasis))) {
      throw invalidPriceHistory()
    }
    const pricingInputUnitCode = event.pricing_input_unit_code ?? null
    if (pricingInputUnitCode !== null
      && (typeof pricingInputUnitCode !== 'string' || !/^[a-z][a-z0-9_]{0,23}$/.test(pricingInputUnitCode))) {
      throw invalidPriceHistory()
    }
    const pricingBaseUnitCode = event.pricing_base_unit_code ?? null
    if (pricingBaseUnitCode !== null
      && (typeof pricingBaseUnitCode !== 'string' || !/^[a-z][a-z0-9_]{0,23}$/.test(pricingBaseUnitCode))) {
      throw invalidPriceHistory()
    }
    const pricingInputAmount = priceDecimal(event.pricing_input_amount ?? null)
    const pricingNominalQuantity = priceDecimal(event.pricing_nominal_quantity ?? null)
    const pricingUsableQuantity = priceDecimal(event.pricing_usable_quantity ?? null)
    if ((pricingInputBasis === 'PACKAGE' && pricingInputUnitCode !== null)
      || (pricingInputBasis === 'NOMINAL_UNIT' && pricingInputUnitCode === null)) {
      throw invalidPriceHistory()
    }
    if (pricingInputBasis !== null && (
      pricingInputAmount === null
      || pricingNominalQuantity === null
      || pricingUsableQuantity === null
      || pricingBaseUnitCode === null
      || (pricingInputBasis !== 'PACKAGE' && pricingInputUnitCode === null)
    )) {
      throw invalidPriceHistory()
    }
    const effectiveFrom = priceString(event.effective_from)
    if (!/^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom)) {
      throw new Error('Backend zwrócił nieprawidłową datę obowiązywania ceny.')
    }
    return {
      id: priceString(event.id),
      eventNo: event.event_no as number,
      eventType: priceString(event.event_type),
      effectiveFrom,
      netPlnPerBaseUnit: priceDecimal(event.net_pln_per_base_unit),
      netPlnPerPackage: priceDecimal(event.net_pln_per_package),
      pricingInputBasis: pricingInputBasis as RawMaterialReferencePrice['pricingInputBasis'],
      pricingInputAmount,
      pricingInputUnitCode: pricingInputUnitCode as string | null,
      pricingNominalQuantity,
      pricingUsableQuantity,
      pricingBaseUnitCode: pricingBaseUnitCode as string | null,
      currencyCode: 'PLN',
    }
  })
}
