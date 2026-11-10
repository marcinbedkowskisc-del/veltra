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
  pc: 'pc — sztuka',
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

  if (values.aliases.length > 50) errors.aliases = 'Możesz dodać maksymalnie 50 aliasów.'
  const seenAliases = new Set<string>()
  values.aliases.forEach((alias, index) => {
    const trimmed = alias.trim()
    if (!trimmed) errors[`aliases.${index}`] = 'Alias nie może być pusty.'
    else if ([...trimmed].length > 200) errors[`aliases.${index}`] = 'Alias może mieć maksymalnie 200 znaków.'
    const normalized = normalizedTerm(trimmed)
    if (normalized && seenAliases.has(normalized)) {
      errors[`aliases.${index}`] = 'Aliasy muszą być unikalne.'
    }
    if (normalized) seenAliases.add(normalized)
  })

  if (values.conversions.length > 20) errors.conversions = 'Możesz dodać maksymalnie 20 przeliczników.'
  const seenUnits = new Set<string>([values.baseUnitCode])
  values.conversions.forEach((conversion, index) => {
    if (!RAW_UNITS.includes(conversion.unitCode as RawMaterialUnit)) {
      errors[`conversions.${index}.unitCode`] = 'Wybierz prawidłową jednostkę.'
      return
    }
    if (seenUnits.has(conversion.unitCode)) {
      errors[`conversions.${index}.unitCode`] = 'Jednostka bazowa i przeliczniki muszą być unikalne.'
    }
    seenUnits.add(conversion.unitCode)

    const sameDimension = UNIT_DIMENSIONS[conversion.unitCode as RawMaterialUnit]
      === UNIT_DIMENSIONS[values.baseUnitCode]
    if (!sameDimension) {
      const factor = normalizeDecimalInput(conversion.toBaseFactor)
      const basis = conversion.basis.trim()
      if (!factor || !validFactor(factor)) {
        errors[`conversions.${index}.toBaseFactor`] = 'Podaj dodatni współczynnik z maksymalnie 12 miejscami po przecinku.'
      }
      if (!basis) errors[`conversions.${index}.basis`] = 'Opisz podstawę przeliczenia dla tej jednostki.'
      else if ([...basis].length > 500) errors[`conversions.${index}.basis`] = 'Podstawa może mieć maksymalnie 500 znaków.'
    } else if (conversion.toBaseFactor.trim() && !validFactor(normalizeDecimalInput(conversion.toBaseFactor))) {
      errors[`conversions.${index}.toBaseFactor`] = 'Współczynnik musi być dodatnią liczbą z maksymalnie 12 miejscami po przecinku.'
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
