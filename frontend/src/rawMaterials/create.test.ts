import { describe, expect, it } from 'vitest'
import {
  buildRawMaterialCreatePayload,
  conversionBasisHint,
  conversionFactorHint,
  emptyRawMaterialCreateValues,
  validateRawMaterialCreate,
} from './create'

const validValues = () => ({
  ...emptyRawMaterialCreateValues(),
  categoryId: '00000000-0000-4000-8000-000000000001',
  name: 'Mąka testowa',
})

describe('walidacja formularza tworzenia surowca', () => {
  it('pokazuje przykłady przeliczników dopasowane do wybranych jednostek', () => {
    expect(conversionFactorHint('pc', 'g', '')).toContain('1 sztuka waży 180 g')
    expect(conversionFactorHint('ml', 'g', '')).toContain('1 mililitr tego surowca waży 1,05 g')
    expect(conversionBasisHint('pc', 'g', '180')).toContain('1 sztuka waży 180 g')
  })

  it('wymaga kategorii i nazwy oraz odrzuca zbyt długie wartości', () => {
    const errors = validateRawMaterialCreate({
      ...emptyRawMaterialCreateValues(),
      name: 'x'.repeat(201),
      description: 'x'.repeat(10001),
    })

    expect(errors.categoryId).toBeTruthy()
    expect(errors.name).toBeTruthy()
    expect(errors.description).toBeTruthy()
  })

  it('akceptuje przecinek dziesiętny i maksymalnie sześć miejsc po separatorze', () => {
    const values = {
      ...validValues(),
      nominalQuantity: '12,123456',
      usableQuantity: '10,5',
      usableQuantityExplicit: true,
    }

    expect(validateRawMaterialCreate(values)).toEqual({})
    expect(buildRawMaterialCreatePayload(values)).toMatchObject({
      nominal_quantity: '12.123456',
      usable_quantity: '10.5',
    })
  })

  it('domyśla ilość użytkową z nominalnej, ale nie akceptuje samej użytkowej', () => {
    const nominalOnly = { ...validValues(), nominalQuantity: '10' }
    expect(validateRawMaterialCreate(nominalOnly)).toEqual({})
    expect(buildRawMaterialCreatePayload(nominalOnly)).toHaveProperty('nominal_quantity', '10')
    expect(buildRawMaterialCreatePayload(nominalOnly)).not.toHaveProperty('usable_quantity')
    expect(validateRawMaterialCreate({
      ...nominalOnly,
      usableQuantity: '10',
      usableQuantityExplicit: true,
    })).toEqual({})
    expect(buildRawMaterialCreatePayload({
      ...nominalOnly,
      usableQuantity: '10',
      usableQuantityExplicit: true,
    })).toHaveProperty('usable_quantity', '10')
    expect(validateRawMaterialCreate({ ...validValues(), usableQuantity: '8' }).nominalQuantity).toBeTruthy()
    expect(validateRawMaterialCreate({
      ...nominalOnly,
      usableQuantity: '10.000001',
      usableQuantityExplicit: true,
    }).usableQuantity).toBeTruthy()
    expect(validateRawMaterialCreate({ ...nominalOnly, usableQuantity: '0', usableQuantityExplicit: true }).usableQuantity).toBeTruthy()
    expect(validateRawMaterialCreate({ ...nominalOnly, usableQuantity: '1.1234567', usableQuantityExplicit: true }).usableQuantity).toBeTruthy()
  })

  it('serializuje oba alternatywne sposoby wprowadzania ceny netto', () => {
    const packagePrice = {
      ...validValues(),
      baseUnitCode: 'g' as const,
      nominalQuantity: '2800',
      pricingBasis: 'PACKAGE' as const,
      pricingAmount: '28,00',
    }
    const nominalUnitPrice = {
      ...packagePrice,
      pricingBasis: 'NOMINAL_UNIT' as const,
      pricingAmount: '10',
      pricingUnitCode: 'kg' as const,
    }

    expect(validateRawMaterialCreate(packagePrice)).toEqual({})
    expect(validateRawMaterialCreate(nominalUnitPrice)).toEqual({})
    expect(buildRawMaterialCreatePayload(packagePrice).pricing).toEqual({
      basis: 'PACKAGE',
      amount: '28.00',
    })
    expect(buildRawMaterialCreatePayload(nominalUnitPrice).pricing).toEqual({
      basis: 'NOMINAL_UNIT',
      amount: '10',
      unit_code: 'kg',
    })
    expect(validateRawMaterialCreate({ ...nominalUnitPrice, pricingUnitCode: 'l' }).pricingUnitCode).toBeTruthy()
    expect(validateRawMaterialCreate({ ...packagePrice, pricingAmount: '1.123456789012345' }).pricingAmount).toBeTruthy()
  })

  it('odrzuca jednostki nieobecne w przekazanym słowniku, także gdy jest pusty', () => {
    const values = {
      ...validValues(),
      baseUnitCode: 'unknown',
      nominalQuantity: '10',
      pricingBasis: 'NOMINAL_UNIT' as const,
      pricingAmount: '10',
      pricingUnitCode: 'unknown',
    }
    const errors = validateRawMaterialCreate(values, true, [])
    expect(errors.baseUnitCode).toBeTruthy()
    expect(errors.pricingUnitCode).toBeTruthy()
    expect(validateRawMaterialCreate({ ...values, baseUnitCode: 'g', pricingUnitCode: 'kg' }, true, []).baseUnitCode).toBeTruthy()
  })

  it('wymaga liczb całkowitych dla jednostki pc', () => {
    const values = {
      ...validValues(),
      baseUnitCode: 'pc' as const,
      nominalQuantity: '10.5',
      usableQuantity: '9',
      usableQuantityExplicit: true,
    }

    expect(validateRawMaterialCreate(values).nominalQuantity).toContain('całkowitą')
    expect(validateRawMaterialCreate({
      ...values,
      nominalQuantity: '10,000',
      usableQuantity: '9.0',
      usableQuantityExplicit: true,
    })).toEqual({})
  })

  it('serializuje aliasy i przeliczniki do kształtu backendu bez generowania SKU', () => {
    const values = {
      ...validValues(),
      baseUnitCode: 'g' as const,
      aliases: [' Mąka uniwersalna '],
      conversions: [
        { unitCode: 'kg', toBaseFactor: '', basis: '' },
        { unitCode: 'ml', toBaseFactor: '1,25', basis: 'W przeliczeniu dla tej receptury' },
      ],
    }
    const payload = buildRawMaterialCreatePayload(values)

    expect(validateRawMaterialCreate(values)).toEqual({})
    expect(payload).toEqual({
      category_id: values.categoryId,
      name: 'Mąka testowa',
      base_unit_code: 'g',
      aliases: ['Mąka uniwersalna'],
      conversions: [
        { unit_code: 'kg' },
        { unit_code: 'ml', to_base_factor: '1.25', basis: 'W przeliczeniu dla tej receptury' },
      ],
    })
    expect(payload).not.toHaveProperty('sku')
    expect(payload).not.toHaveProperty('sku_reservation_id')
  })

  it('odrzuca niekompletny przelicznik między różnymi wymiarami i powtórzone jednostki', () => {
    const values = {
      ...validValues(),
      conversions: [
        { unitCode: 'ml', toBaseFactor: '', basis: '' },
        { unitCode: 'ml', toBaseFactor: '2', basis: 'Powtórzona jednostka' },
      ],
    }
    const errors = validateRawMaterialCreate(values)

    expect(errors['conversions.0.toBaseFactor']).toBeTruthy()
    expect(errors['conversions.0.basis']).toBeTruthy()
    expect(errors['conversions.1.unitCode']).toBeTruthy()
  })
})
