import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { formatNumber } from '../design-system/formatting'
import { previewRawMaterialPricing, type PricingPreview } from './api'
import { normalizeDecimalInput, validateRawMaterialCreate, type RawMaterialCreateValues } from './create'

type Props = {
  id: string
  values: RawMaterialCreateValues
  disabled: boolean
  canCalculate: boolean
  canViewCost: boolean
  error?: string
  onBasisChange: (value: RawMaterialCreateValues['pricingBasis']) => void
  onAmountChange: (value: string) => void
  onSessionExpired: () => void
  onAuthorizationDenied: () => void
  onModuleUnavailable: () => void
}

export default function RawMaterialPricingFields({
  id, values, disabled, canCalculate, canViewCost, error, onBasisChange, onAmountChange,
  onSessionExpired, onAuthorizationDenied, onModuleUnavailable,
}: Props) {
  const callbacks = useRef({ onSessionExpired, onAuthorizationDenied, onModuleUnavailable })
  useLayoutEffect(() => {
    callbacks.current = { onSessionExpired, onAuthorizationDenied, onModuleUnavailable }
  }, [onSessionExpired, onAuthorizationDenied, onModuleUnavailable])
  const [result, setResult] = useState<{ key: string; value: PricingPreview } | null>(null)
  const [failure, setFailure] = useState<{ key: string; message: string } | null>(null)
  const errors = validateRawMaterialCreate(values, true)
  const amount = normalizeDecimalInput(values.pricingAmount)
  const key = JSON.stringify([
    values.baseUnitCode, values.nominalQuantity, values.usableQuantity,
    values.usableQuantityExplicit, values.pricingBasis, amount, values.pricingUnitCode,
  ])
  const valid = canCalculate && amount !== '' && !errors.nominalQuantity && !errors.usableQuantity
    && !errors.pricingAmount && !errors.pricingUnitCode

  useEffect(() => {
    if (!valid) return
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      void previewRawMaterialPricing({
        base_unit_code: values.baseUnitCode,
        nominal_quantity: normalizeDecimalInput(values.nominalQuantity),
        ...(values.usableQuantityExplicit ? { usable_quantity: normalizeDecimalInput(values.usableQuantity) } : {}),
        pricing: {
          basis: values.pricingBasis,
          amount,
          ...(values.pricingBasis === 'NOMINAL_UNIT' ? { unit_code: values.pricingUnitCode } : {}),
        },
      }, controller.signal).then((value) => {
        if (!controller.signal.aborted) {
          setResult({ key, value })
          setFailure(null)
        }
      }).catch((cause: unknown) => {
        if (controller.signal.aborted) return
        if (cause instanceof ApiError && (cause.status === 401 || cause.status === 419)) callbacks.current.onSessionExpired()
        else if (cause instanceof ApiError && (cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE' || cause.status === 503)) callbacks.current.onModuleUnavailable()
        else if (cause instanceof ApiError && cause.status === 403) callbacks.current.onAuthorizationDenied()
        else setFailure({ key, message: cause instanceof Error ? cause.message : 'Nie można obliczyć podglądu ceny.' })
      })
    }, 250)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [key, valid, amount, values.baseUnitCode, values.nominalQuantity, values.usableQuantity,
    values.usableQuantityExplicit, values.pricingBasis, values.pricingUnitCode])

  const current = valid && result?.key === key ? result.value : null
  const currentError = valid && failure?.key === key ? failure.message : null

  return (
    <section className="create-form-field create-form-field-wide" aria-labelledby={`${id}-pricing-title`}>
      <h3 id={`${id}-pricing-title`}>Cena zakupu — cena referencyjna netto</h3>
      <p className="field-hint">Wprowadź średnią cenę obliczoną poza VELTRA. Oferty dostawców nie zmieniają jej automatycznie. Cena zostanie opublikowana po zatwierdzeniu rewizji.</p>
      <div className="create-form-grid">
        <label className="create-form-field" htmlFor={`${id}-pricing-basis`}>Podstawa ceny
          <select id={`${id}-pricing-basis`} value={values.pricingBasis} disabled={disabled}
            onChange={(event) => onBasisChange(event.target.value as RawMaterialCreateValues['pricingBasis'])}>
            <option value="PACKAGE">Całe opakowanie</option>
            <option value="NOMINAL_UNIT">Jednostka nominalna</option>
          </select>
        </label>
        <label className="create-form-field" htmlFor={`${id}-pricing-amount`}>
          {values.pricingBasis === 'PACKAGE'
            ? 'Średnia cena netto opakowania (PLN)'
            : `Średnia cena netto za ${values.pricingUnitCode} nominalne (PLN)`}
          <input id={`${id}-pricing-amount`} inputMode="decimal" value={values.pricingAmount}
            disabled={disabled} aria-invalid={Boolean(error)} onChange={(event) => onAmountChange(event.target.value)} />
          {error && <span className="field-error">{error}</span>}
        </label>
      </div>
      {current && <div role="status">
        <p>Cena netto opakowania: {formatNumber(current.netPlnPerPackage, { minimumFractionDigits: 2, maximumFractionDigits: 4 })} PLN</p>
        {canViewCost && current.usableUnitCost !== undefined && current.usableCostBasis && (
          <p>Koszt użytkowy: {formatNumber(current.usableUnitCost, { minimumFractionDigits: 4, maximumFractionDigits: 4 })} {current.usableCostBasis}</p>
        )}
      </div>}
      {currentError && <p className="inline-error" role="alert">Podgląd ceny niedostępny: {currentError}</p>}
      {valid && !current && !currentError && <p role="status">Obliczanie podglądu ceny…</p>}
    </section>
  )
}
