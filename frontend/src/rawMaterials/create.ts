export type RawMaterialUnit = string
export type RawMaterialPricingUnit = string
export type RawMaterialPricingBasis = 'PACKAGE' | 'NOMINAL_UNIT'
export type RawMaterialUnitOption = {
  code: string
  name: string
  symbol: string
  dimension: 'MASS' | 'VOLUME' | 'COUNT'
  baseUnitCode: string
  toBaseFactor: string
  archivedAt: string | null
  isBase: boolean
}

export const RAW_UNIT_OPTIONS_BASELINE: RawMaterialUnitOption[] = [
  { code: 'mg', name: 'miligram', symbol: 'mg', dimension: 'MASS', baseUnitCode: 'g', toBaseFactor: '0.001000000000', archivedAt: null, isBase: false },
  { code: 'g', name: 'gram', symbol: 'g', dimension: 'MASS', baseUnitCode: 'g', toBaseFactor: '1.000000000000', archivedAt: null, isBase: true },
  { code: 'kg', name: 'kilogram', symbol: 'kg', dimension: 'MASS', baseUnitCode: 'g', toBaseFactor: '1000.000000000000', archivedAt: null, isBase: false },
  { code: 'ml', name: 'mililitr', symbol: 'ml', dimension: 'VOLUME', baseUnitCode: 'ml', toBaseFactor: '1.000000000000', archivedAt: null, isBase: true },
  { code: 'l', name: 'litr', symbol: 'l', dimension: 'VOLUME', baseUnitCode: 'ml', toBaseFactor: '1000.000000000000', archivedAt: null, isBase: false },
  { code: 'pc', name: 'sztuka', symbol: 'szt.', dimension: 'COUNT', baseUnitCode: 'pc', toBaseFactor: '1.000000000000', archivedAt: null, isBase: true },
]

export type RawMaterialConversionInput = {
  unitCode: string
  toBaseFactor: string
  basis: string
}

export type RawMaterialCreateValues = {
  categoryId: string
  name: string
  description: string
  baseUnitCode: RawMaterialUnit
  nominalQuantity: string
  usableQuantity: string
  usableQuantityExplicit: boolean
  pricingBasis: RawMaterialPricingBasis
  pricingAmount: string
  pricingUnitCode: RawMaterialPricingUnit
  supplierIds: string[]
  aliases: string[]
  conversions: RawMaterialConversionInput[]
}

export type RawMaterialCreatePayload = {
  category_id: string
  name: string
  description?: string
  base_unit_code: RawMaterialUnit
  nominal_quantity?: string
  usable_quantity?: string
  pricing?: {
    basis: RawMaterialPricingBasis
    amount: string
    unit_code?: RawMaterialPricingUnit
  }
  supplier_ids?: string[]
  aliases?: string[]
  conversions?: Array<{
    unit_code: RawMaterialUnit
    to_base_factor?: string
    basis?: string
  }>
}

export function withPricingUnit(
  conversions: NonNullable<RawMaterialCreatePayload['conversions']>,
  values: RawMaterialCreateValues,
  includePricing: boolean,
): NonNullable<RawMaterialCreatePayload['conversions']> {
  if (!includePricing || !normalizeDecimalInput(values.pricingAmount) || values.pricingBasis !== 'NOMINAL_UNIT'
    || values.pricingUnitCode === values.baseUnitCode
    || conversions.some((conversion) => conversion.unit_code === values.pricingUnitCode)) return conversions
  return [...conversions, { unit_code: values.pricingUnitCode }]
}

export const UNIT_DIMENSIONS: Record<string, 'MASS' | 'VOLUME' | 'COUNT'> = {
  mg: 'MASS',
  g: 'MASS',
  kg: 'MASS',
  ml: 'VOLUME',
  l: 'VOLUME',
  pc: 'COUNT',
}

export const UNIT_LABELS: Record<string, string> = {
  mg: 'mg — miligram',
  g: 'g — gram',
  kg: 'kg — kilogram',
  ml: 'ml — mililitr',
  l: 'l — litr',
  pc: 'szt. — sztuka',
}

const UNIT_NOUNS: Record<string, string> = {
  mg: 'miligram',
  g: 'gram',
  kg: 'kilogram',
  ml: 'mililitr',
  l: 'litr',
  pc: 'sztuka',
}

export function unitLabel(unitCode: string, units: RawMaterialUnitOption[] = []): string {
  const known = units.find((unit) => unit.code === unitCode)
  if (known && !Object.hasOwn(UNIT_LABELS, unitCode)) return `${known.symbol} — ${known.name}`
  return UNIT_LABELS[unitCode] ?? unitCode
}

export function unitShortLabel(unitCode: string): string {
  return unitLabel(unitCode).split(' — ')[0]
}

function unitNoun(unitCode: string, units: RawMaterialUnitOption[]): string {
  return UNIT_NOUNS[unitCode] ?? units.find((unit) => unit.code === unitCode)?.name ?? unitCode
}

export function unitDimension(unitCode: string, units: RawMaterialUnitOption[] = []): 'MASS' | 'VOLUME' | 'COUNT' | undefined {
  return units.find((unit) => unit.code === unitCode)?.dimension ?? UNIT_DIMENSIONS[unitCode]
}

function conversionExampleParts(unitCode: string, baseUnitCode: string, factor: string, units: RawMaterialUnitOption[]) {
  const unit = unitNoun(unitCode, units)
  const baseUnit = units.find((item) => item.code === baseUnitCode)?.symbol ?? unitShortLabel(baseUnitCode)
  const baseDimension = unitDimension(baseUnitCode, units)
  const unitDimensionValue = unitDimension(unitCode, units)
  const examples: Record<string, string> = {
    'ml:g': '1,05',
    'ml:kg': '0,00105',
    'l:g': '1050',
    'l:kg': '1,05',
    'g:ml': '0,95',
    'g:l': '0,00095',
    'kg:ml': '950',
    'kg:l': '0,95',
    'pc:mg': '180000',
    'pc:g': '180',
    'pc:kg': '0,18',
    'pc:ml': '250',
    'pc:l': '0,25',
  }
  const sampleFactor = factor || examples[`${unitCode}:${baseUnitCode}`] || '250'
  const verb = unitCode === 'pc' && baseDimension === 'MASS'
    || unitCode === 'ml' && baseDimension === 'MASS'
    || unitCode === 'l' && baseDimension === 'MASS'
    ? 'waży'
    : unitCode === 'pc' && baseDimension === 'VOLUME'
      ? 'zawiera'
      : unitDimensionValue === 'MASS' && baseDimension === 'VOLUME'
        ? 'zajmuje'
        : 'odpowiada'
  const subject = (unitCode === 'ml' || unitCode === 'l') && baseDimension === 'MASS'
    ? `${unit} tego surowca`
    : unit

  return { subject, verb, sampleFactor, baseUnit }
}

export function conversionFactorHint(unitCode: string, baseUnitCode: string, factor: string, units: RawMaterialUnitOption[] = []): string {
  const example = conversionExampleParts(unitCode, baseUnitCode, factor, units)
  return `Wpisz, ile jednostek podstawowych odpowiada jednej wybranej jednostce. Przykład: jeśli 1 ${example.subject} ${example.verb} ${example.sampleFactor} ${example.baseUnit}, wpisz ${example.sampleFactor}.`
}

export function conversionBasisHint(unitCode: string, baseUnitCode: string, factor: string, units: RawMaterialUnitOption[] = []): string {
  const example = conversionExampleParts(unitCode, baseUnitCode, factor, units)
  return `Wyjaśnij, czego dotyczy przeliczenie, np. „1 ${example.subject} ${example.verb} ${example.sampleFactor} ${example.baseUnit}” dla tego surowca.`
}

export function emptyRawMaterialCreateValues(): RawMaterialCreateValues {
  return {
    categoryId: '',
    name: '',
    description: '',
    baseUnitCode: 'g',
    nominalQuantity: '',
    usableQuantity: '',
    usableQuantityExplicit: false,
    pricingBasis: 'PACKAGE',
    pricingAmount: '',
    pricingUnitCode: 'kg',
    supplierIds: [],
    aliases: [],
    conversions: [],
  }
}

export function normalizeDecimalInput(value: string): string {
  return value.trim().replace(',', '.')
}

function normalizedTerm(value: string): string {
  return value.trim().normalize('NFKC').toLocaleLowerCase('pl-PL')
}

function validDecimal(value: string, maxFractionDigits: number): boolean {
  const expression = new RegExp(`^\\d{1,18}(?:\\.\\d{1,${maxFractionDigits}})?$`)
  return expression.test(value) && /[1-9]/.test(value)
}

function validFactor(value: string): boolean {
  return /^\d+(?:\.\d{1,12})?$/.test(value) && /[1-9]/.test(value)
}

function decimalGreaterThan(left: string, right: string): boolean {
  const [leftInteger, leftFraction = ''] = left.split('.')
  const [rightInteger, rightFraction = ''] = right.split('.')
  const normalizedLeftInteger = leftInteger.replace(/^0+(?=\d)/, '')
  const normalizedRightInteger = rightInteger.replace(/^0+(?=\d)/, '')
  if (normalizedLeftInteger.length !== normalizedRightInteger.length) {
    return normalizedLeftInteger.length > normalizedRightInteger.length
  }
  if (normalizedLeftInteger !== normalizedRightInteger) {
    return normalizedLeftInteger > normalizedRightInteger
  }
  const precision = Math.max(leftFraction.length, rightFraction.length)
  return leftFraction.padEnd(precision, '0') > rightFraction.padEnd(precision, '0')
}

export function validateRawMaterialCreate(
  values: RawMaterialCreateValues,
  requireNominal = false,
  units: RawMaterialUnitOption[] = [],
): Record<string, string> {
  const errors: Record<string, string> = {}
  const name = values.name.trim()
  const description = values.description.trim()

  if (!values.categoryId) errors.categoryId = 'Wybierz kategorię surowca.'
  if (!name) errors.name = 'Podaj nazwę surowca.'
  else if ([...name].length > 200) errors.name = 'Nazwa może mieć maksymalnie 200 znaków.'
  if ([...description].length > 10000) errors.description = 'Opis może mieć maksymalnie 10 000 znaków.'

  const nominal = normalizeDecimalInput(values.nominalQuantity)
  const usable = normalizeDecimalInput(values.usableQuantity)
  if (requireNominal && !nominal) errors.nominalQuantity = 'Podaj ilość nominalną opakowania.'
  if (usable && !nominal) errors.nominalQuantity = 'Podaj ilość nominalną razem z użytkową.'
  if (nominal && !validDecimal(nominal, 6)) {
    errors.nominalQuantity = 'Wpisz dodatnią liczbę z maksymalnie 18 cyframi przed przecinkiem i 6 po nim.'
  } else if (nominal && unitDimension(values.baseUnitCode, units) === 'COUNT' && /\.[0-9]*[1-9]/.test(nominal)) {
    errors.nominalQuantity = 'Ilość w sztukach musi być liczbą całkowitą.'
  }
  if (values.usableQuantityExplicit && !usable) {
    errors.usableQuantity = 'Podaj ilość użytkową albo wyczyść pole, aby użyć ilości nominalnej.'
  } else if (values.usableQuantityExplicit && !validDecimal(usable, 6)) {
    errors.usableQuantity = 'Wpisz dodatnią liczbę z maksymalnie 18 cyframi przed przecinkiem i 6 po nim.'
  } else if (usable && unitDimension(values.baseUnitCode, units) === 'COUNT' && /\.[0-9]*[1-9]/.test(usable)) {
    errors.usableQuantity = 'Ilość w sztukach musi być liczbą całkowitą.'
  }
  if (usable && nominal && validDecimal(usable, 6) && validDecimal(nominal, 6)
    && decimalGreaterThan(usable, nominal)) {
    errors.usableQuantity = 'Ilość użytkowa nie może być większa od ilości nominalnej.'
  }
  const pricingAmount = normalizeDecimalInput(values.pricingAmount)
  if (pricingAmount && !/^\d{1,17}(?:\.\d{1,14})?$/.test(pricingAmount)) {
    errors.pricingAmount = 'Podaj cenę nieujemną z maksymalnie 14 miejscami po przecinku.'
  }
  if (pricingAmount && !nominal) errors.nominalQuantity = 'Cena wymaga ilości nominalnej.'
  if (values.pricingBasis === 'NOMINAL_UNIT'
    && unitDimension(values.pricingUnitCode, units) !== unitDimension(values.baseUnitCode, units)) {
    errors.pricingUnitCode = 'Jednostka ceny nie odpowiada jednostce surowca.'
  }

  if (values.aliases.length > 50) errors.aliases = 'Możesz dodać maksymalnie 50 innych nazw surowca.'
  const seenAliases = new Set<string>()
  values.aliases.forEach((alias, index) => {
    const trimmed = alias.trim()
    if (!trimmed) errors[`aliases.${index}`] = 'Nazwa nie może być pusta.'
    else if ([...trimmed].length > 200) errors[`aliases.${index}`] = 'Nazwa może mieć maksymalnie 200 znaków.'
    const normalized = normalizedTerm(trimmed)
    if (normalized && seenAliases.has(normalized)) {
      errors[`aliases.${index}`] = 'Inne nazwy surowca muszą być unikalne.'
    }
    if (normalized) seenAliases.add(normalized)
  })

  if (values.conversions.length > 20) errors.conversions = 'Możesz dodać maksymalnie 20 dodatkowych jednostek miary.'
  const seenUnits = new Set<string>([values.baseUnitCode])
  values.conversions.forEach((conversion, index) => {
    if (!/^[a-z][a-z0-9_]{0,23}$/.test(conversion.unitCode)
      || (units.length > 0 && !units.some((unit) => unit.code === conversion.unitCode))) {
      errors[`conversions.${index}.unitCode`] = 'Wybierz prawidłową jednostkę.'
      return
    }
    if (seenUnits.has(conversion.unitCode)) {
      errors[`conversions.${index}.unitCode`] = 'Jednostka podstawowa i dodatkowe jednostki miary muszą być różne.'
    }
    seenUnits.add(conversion.unitCode)

    const sameDimension = unitDimension(conversion.unitCode, units)
      === unitDimension(values.baseUnitCode, units)
    if (!sameDimension) {
      const factor = normalizeDecimalInput(conversion.toBaseFactor)
      const basis = conversion.basis.trim()
      if (!factor || !validFactor(factor)) {
        errors[`conversions.${index}.toBaseFactor`] = 'Podaj dodatni przelicznik z maksymalnie 12 miejscami po przecinku.'
      }
      if (!basis) errors[`conversions.${index}.basis`] = 'Opisz, czego dotyczy przeliczenie dla tej jednostki.'
      else if ([...basis].length > 500) errors[`conversions.${index}.basis`] = 'Opis przeliczenia może mieć maksymalnie 500 znaków.'
    } else if (conversion.toBaseFactor.trim() && !validFactor(normalizeDecimalInput(conversion.toBaseFactor))) {
      errors[`conversions.${index}.toBaseFactor`] = 'Przelicznik musi być dodatnią liczbą z maksymalnie 12 miejscami po przecinku.'
    }
  })

  return errors
}

export function buildRawMaterialCreatePayload(
  values: RawMaterialCreateValues,
  includeSupplierIds = false,
  includePricing = true,
  units: RawMaterialUnitOption[] = [],
): RawMaterialCreatePayload {
  const nominal = normalizeDecimalInput(values.nominalQuantity)
  const usable = normalizeDecimalInput(values.usableQuantity)
  const aliases = values.aliases.map((alias) => alias.trim()).filter(Boolean)
  const conversions = withPricingUnit(values.conversions.map((conversion) => {
    const unitCode = conversion.unitCode as RawMaterialUnit
    if (unitDimension(unitCode, units) === unitDimension(values.baseUnitCode, units)) {
      return { unit_code: unitCode }
    }
    return {
      unit_code: unitCode,
      to_base_factor: normalizeDecimalInput(conversion.toBaseFactor),
      basis: conversion.basis.trim(),
    }
  }), values, includePricing)

  return {
    category_id: values.categoryId,
    name: values.name.trim(),
    ...(values.description.trim() ? { description: values.description.trim() } : {}),
    base_unit_code: values.baseUnitCode,
    ...(nominal ? { nominal_quantity: nominal } : {}),
    ...(values.usableQuantityExplicit && usable ? { usable_quantity: usable } : {}),
    ...(includePricing && normalizeDecimalInput(values.pricingAmount) ? {
      pricing: {
        basis: values.pricingBasis,
        amount: normalizeDecimalInput(values.pricingAmount),
        ...(values.pricingBasis === 'NOMINAL_UNIT' ? { unit_code: values.pricingUnitCode } : {}),
      },
    } : {}),
    ...(includeSupplierIds ? { supplier_ids: values.supplierIds } : {}),
    ...(aliases.length ? { aliases } : {}),
    ...(conversions.length ? { conversions } : {}),
  }
}
