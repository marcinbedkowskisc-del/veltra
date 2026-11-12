import { RAW_UNITS } from './catalog'

export type RawMaterialUnit = (typeof RAW_UNITS)[number]

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
  aliases?: string[]
  conversions?: Array<{
    unit_code: RawMaterialUnit
    to_base_factor?: string
    basis?: string
  }>
}

export const UNIT_DIMENSIONS: Record<RawMaterialUnit, 'MASS' | 'VOLUME' | 'COUNT'> = {
  mg: 'MASS',
  g: 'MASS',
  kg: 'MASS',
  ml: 'VOLUME',
  l: 'VOLUME',
  pc: 'COUNT',
}

export const UNIT_LABELS: Record<RawMaterialUnit, string> = {
  mg: 'mg — miligram',
  g: 'g — gram',
  kg: 'kg — kilogram',
  ml: 'ml — mililitr',
  l: 'l — litr',
  pc: 'szt. — sztuka',
}

const UNIT_NOUNS: Record<RawMaterialUnit, string> = {
  mg: 'miligram',
  g: 'gram',
  kg: 'kilogram',
  ml: 'mililitr',
  l: 'litr',
  pc: 'sztuka',
}

export function unitLabel(unitCode: string): string {
  const unit = RAW_UNITS.find((candidate) => candidate === unitCode)
  return unit ? UNIT_LABELS[unit] : unitCode
}

export function unitShortLabel(unitCode: string): string {
  return unitLabel(unitCode).split(' — ')[0]
}

function unitNoun(unitCode: string): string {
  const unit = RAW_UNITS.find((candidate) => candidate === unitCode)
  return unit ? UNIT_NOUNS[unit] : unitCode
}

function unitDimension(unitCode: string) {
  const unit = RAW_UNITS.find((candidate) => candidate === unitCode)
  return unit ? UNIT_DIMENSIONS[unit] : undefined
}

function conversionExampleParts(unitCode: string, baseUnitCode: string, factor: string) {
  const unit = unitNoun(unitCode)
  const baseUnit = unitShortLabel(baseUnitCode)
  const baseDimension = unitDimension(baseUnitCode)
  const unitDimensionValue = unitDimension(unitCode)
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

export function conversionFactorHint(unitCode: string, baseUnitCode: string, factor: string): string {
  const example = conversionExampleParts(unitCode, baseUnitCode, factor)
  return `Wpisz, ile jednostek podstawowych odpowiada jednej wybranej jednostce. Przykład: jeśli 1 ${example.subject} ${example.verb} ${example.sampleFactor} ${example.baseUnit}, wpisz ${example.sampleFactor}.`
}

export function conversionBasisHint(unitCode: string, baseUnitCode: string, factor: string): string {
  const example = conversionExampleParts(unitCode, baseUnitCode, factor)
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

export function validateRawMaterialCreate(values: RawMaterialCreateValues): Record<string, string> {
  const errors: Record<string, string> = {}
  const name = values.name.trim()
  const description = values.description.trim()

  if (!values.categoryId) errors.categoryId = 'Wybierz kategorię surowca.'
  if (!name) errors.name = 'Podaj nazwę surowca.'
  else if ([...name].length > 200) errors.name = 'Nazwa może mieć maksymalnie 200 znaków.'
  if ([...description].length > 10000) errors.description = 'Opis może mieć maksymalnie 10 000 znaków.'

  const nominal = normalizeDecimalInput(values.nominalQuantity)
  const usable = normalizeDecimalInput(values.usableQuantity)
  if (usable && !nominal) errors.nominalQuantity = 'Podaj ilość nominalną razem z użytkową.'
  if (nominal && !validDecimal(nominal, 6)) {
    errors.nominalQuantity = 'Wpisz dodatnią liczbę z maksymalnie 18 cyframi przed przecinkiem i 6 po nim.'
  } else if (nominal && values.baseUnitCode === 'pc' && /\.[0-9]*[1-9]/.test(nominal)) {
    errors.nominalQuantity = 'Ilość w sztukach musi być liczbą całkowitą.'
  }
  if (usable && !validDecimal(usable, 6)) {
    errors.usableQuantity = 'Wpisz dodatnią liczbę z maksymalnie 18 cyframi przed przecinkiem i 6 po nim.'
  } else if (usable && values.baseUnitCode === 'pc' && /\.[0-9]*[1-9]/.test(usable)) {
    errors.usableQuantity = 'Ilość w sztukach musi być liczbą całkowitą.'
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
    if (!RAW_UNITS.includes(conversion.unitCode as RawMaterialUnit)) {
      errors[`conversions.${index}.unitCode`] = 'Wybierz prawidłową jednostkę.'
      return
    }
    if (seenUnits.has(conversion.unitCode)) {
      errors[`conversions.${index}.unitCode`] = 'Jednostka podstawowa i dodatkowe jednostki miary muszą być różne.'
    }
    seenUnits.add(conversion.unitCode)

    const sameDimension = UNIT_DIMENSIONS[conversion.unitCode as RawMaterialUnit]
      === UNIT_DIMENSIONS[values.baseUnitCode]
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
): RawMaterialCreatePayload {
  const nominal = normalizeDecimalInput(values.nominalQuantity)
  const usable = normalizeDecimalInput(values.usableQuantity)
  const aliases = values.aliases.map((alias) => alias.trim()).filter(Boolean)
  const conversions = values.conversions.map((conversion) => {
    const unitCode = conversion.unitCode as RawMaterialUnit
    if (UNIT_DIMENSIONS[unitCode] === UNIT_DIMENSIONS[values.baseUnitCode]) {
      return { unit_code: unitCode }
    }
    return {
      unit_code: unitCode,
      to_base_factor: normalizeDecimalInput(conversion.toBaseFactor),
      basis: conversion.basis.trim(),
    }
  })

  return {
    category_id: values.categoryId,
    name: values.name.trim(),
    ...(values.description.trim() ? { description: values.description.trim() } : {}),
    base_unit_code: values.baseUnitCode,
    ...(nominal ? { nominal_quantity: nominal } : {}),
    ...(usable ? { usable_quantity: usable } : {}),
    ...(aliases.length ? { aliases } : {}),
    ...(conversions.length ? { conversions } : {}),
  }
}
