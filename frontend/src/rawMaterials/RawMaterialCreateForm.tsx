import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import Tabs from '../design-system/Tabs'
import AllergenFields from './AllergenFields'
import RawMaterialPricingFields from './RawMaterialPricingFields'
import { supplierLabel, type CategoryOption, type SupplierOption } from './catalog'
import {
  buildRawMaterialCreatePayload,
  emptyRawMaterialCreateValues,
  conversionBasisHint,
  conversionFactorHint,
  unitDimension,
  unitLabel,
  RAW_UNIT_OPTIONS_BASELINE,
  validateRawMaterialCreate,
  type RawMaterialConversionInput,
  type RawMaterialCreatePayload,
  type RawMaterialCreateValues,
  type RawMaterialUnit,
  type RawMaterialUnitOption,
} from './create'
import {
  createRawMaterial,
  createApprovedRawMaterial,
  allergenError,
  unknownAllergens,
  reserveRawMaterialSku,
  type CreatedRawMaterial,
  type RawMaterialSkuReservation,
  type AllergenDeclaration,
  fetchSuppliers,
  fetchRawMaterialUnits,
} from './api'

type Props = {
  categories: CategoryOption[]
  canManageAllergens?: boolean
  canManageReferencePrice?: boolean
  canViewReferencePrice?: boolean
  canViewUsableCost?: boolean
  canManageSuppliers?: boolean
  canCreateApproved?: boolean
  operatingLocationId: string
  returnFocus: HTMLElement | null
  onClose: () => void
  onCreated: (material: CreatedRawMaterial) => void
  onOpenCreated: (material: CreatedRawMaterial) => void
  onSessionExpired: () => void
  onAuthorizationDenied: () => void
  onModuleUnavailable: () => void
}

type Phase =
  | 'editing'
  | 'reserving'
  | 'creating'
  | 'reservation-uncertain'
  | 'creation-uncertain'
  | 'complete'
  | 'session-expired'
  | 'forbidden'
  | 'conflict'
  | 'unavailable'

type AttemptStage = 'reservation' | 'creation'

function isSessionExpired(cause: unknown): boolean {
  return cause instanceof ApiError && (cause.status === 401 || cause.status === 419)
}

function serverFieldName(field: string): string {
  const fields: Record<string, string> = {
    category_id: 'categoryId',
    base_unit_code: 'baseUnitCode',
    nominal_quantity: 'nominalQuantity',
    usable_quantity: 'usableQuantity',
    'pricing.amount': 'pricingAmount',
    'pricing.unit_code': 'pricingUnitCode',
    'conversions.*.unit_code': 'conversions',
    'conversions.*.to_base_factor': 'conversions',
    'conversions.*.basis': 'conversions',
    'aliases.*': 'aliases',
  }
  const conversionField = field.match(/^conversions\.(\d+)\.(unit_code|to_base_factor|basis)$/)
  const aliasField = field.match(/^aliases\.(\d+)$/)
  if (conversionField) {
    const key = conversionField[2] === 'unit_code'
      ? 'unitCode'
      : conversionField[2] === 'to_base_factor' ? 'toBaseFactor' : 'basis'
    return `conversions.${conversionField[1]}.${key}`
  }
  if (aliasField) return `aliases.${aliasField[1]}`
  return fields[field] ?? field
}

function asFieldErrors(errors: Record<string, string[]>): Record<string, string> {
  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [
    serverFieldName(field),
    messages.join(' '),
  ]))
}

function isBusy(phase: Phase): boolean {
  return phase === 'reserving' || phase === 'creating'
    || phase === 'reservation-uncertain' || phase === 'creation-uncertain'
}

export default function RawMaterialCreateForm({
  categories,
  canManageAllergens = false,
  canManageReferencePrice = false,
  canViewReferencePrice = false,
  canViewUsableCost = false,
  canManageSuppliers = false,
  canCreateApproved = false,
  returnFocus,
  onClose,
  onCreated,
  onOpenCreated,
  onSessionExpired,
  onAuthorizationDenied,
  onModuleUnavailable,
}: Props) {
  const [values, setValues] = useState<RawMaterialCreateValues>(emptyRawMaterialCreateValues)
  const [tab, setTab] = useState('basic')
  const [allergens, setAllergens] = useState<AllergenDeclaration>(unknownAllergens)
  const [allergenValidation, setAllergenValidation] = useState<string | null>(null)
  const [focusError, setFocusError] = useState(0)
  const [phase, setPhase] = useState<Phase>('editing')
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({})
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [created, setCreated] = useState<CreatedRawMaterial | null>(null)
  const [supplierOptions, setSupplierOptions] = useState<SupplierOption[]>([])
  const [supplierOptionsLoaded, setSupplierOptionsLoaded] = useState(false)
  const [supplierError, setSupplierError] = useState<string | null>(null)
  const [unitOptions, setUnitOptions] = useState<RawMaterialUnitOption[]>(RAW_UNIT_OPTIONS_BASELINE)
  const [unitsLoading, setUnitsLoading] = useState(false)
  const [unitsError, setUnitsError] = useState<string | null>(null)
  const [attempted, setAttempted] = useState(false)
  const [approving, setApproving] = useState(false)
  const dialogRef = useRef<HTMLElement>(null)
  const [initialValues] = useState(emptyRawMaterialCreateValues)
  const active = useRef(true)
  const reservationKey = useRef<string | null>(null)
  const reservation = useRef<RawMaterialSkuReservation | null>(null)
  const attemptPayload = useRef<(RawMaterialCreatePayload & { allergen_declaration?: AllergenDeclaration }) | null>(null)
  const attemptApproved = useRef(false)
  const attemptStage = useRef<AttemptStage>('reservation')
  const operationLocked = useRef(false)
  const createdNotified = useRef(false)
  const dirty = JSON.stringify(values) !== JSON.stringify(initialValues) || allergens.status !== 'UNKNOWN'
  const locked = isBusy(phase) || phase === 'complete'
    || phase === 'session-expired' || phase === 'forbidden'
    || phase === 'conflict' || phase === 'unavailable' || unitsLoading || Boolean(unitsError)

  useEffect(() => {
    active.current = true
    dialogRef.current?.querySelector<HTMLElement>('select, input, textarea, button')?.focus()
    return () => {
      active.current = false
      if (returnFocus?.isConnected) returnFocus.focus()
    }
  }, [returnFocus])

  useEffect(() => {
    const controller = new AbortController()
    void fetchRawMaterialUnits(controller.signal).then((result) => {
      if (!active.current) return
      setUnitOptions(result)
      setUnitsError(null)
    }).catch((cause: unknown) => {
      if (!controller.signal.aborted && active.current) {
        setUnitsError(cause instanceof Error ? cause.message : 'Nie udało się wczytać słownika jednostek.')
      }
    }).finally(() => {
      if (!controller.signal.aborted && active.current) setUnitsLoading(false)
    })
    return () => controller.abort()
  }, [])

  useEffect(() => {
    if (!canManageSuppliers) return
    const controller = new AbortController()
    void fetchSuppliers('', controller.signal).then((suppliers) => {
      if (!active.current) return
      setSupplierOptions(suppliers)
      setSupplierOptionsLoaded(true)
      setSupplierError(null)
    }).catch((cause: unknown) => {
      if (controller.signal.aborted || !active.current) return
      setSupplierError(cause instanceof Error ? cause.message : 'Nie udało się wczytać biblioteki dostawców.')
    })
    return () => controller.abort()
  }, [canManageSuppliers])

  useEffect(() => {
    if (focusError) dialogRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
  }, [focusError, tab])

  function focusErrors(errors: Record<string, string>, allergenMessage: string | null = null) {
    const keys = Object.keys(errors)
    if (keys.some((key) => ['categoryId', 'name', 'baseUnitCode', 'nominalQuantity', 'usableQuantity', 'pricingAmount', 'pricingUnitCode'].includes(key))) setTab('basic')
    else if (allergenMessage) setTab('allergens')
    else if (keys.length) setTab('additional')
    setFocusError((value) => value + 1)
  }

  useEffect(() => {
    if (!dirty || phase === 'complete') return
    const preventNavigation = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', preventNavigation)
    return () => window.removeEventListener('beforeunload', preventNavigation)
  }, [dirty, phase])

  function setField<K extends keyof RawMaterialCreateValues>(
    key: K,
    value: RawMaterialCreateValues[K],
  ) {
    setValues((current) => ({ ...current, [key]: value }))
    setValidationErrors((current) => {
      const next = { ...current }
      delete next[String(key)]
      const nestedPrefix = key === 'aliases' ? 'aliases.' : key === 'conversions' ? 'conversions.' : null
      if (nestedPrefix) Object.keys(next).filter((field) => field.startsWith(nestedPrefix)).forEach((field) => delete next[field])
      return next
    })
    setServerErrors((current) => {
      const next = { ...current }
      delete next[String(key)]
      const nestedPrefix = key === 'aliases' ? 'aliases.' : key === 'conversions' ? 'conversions.' : null
      if (nestedPrefix) Object.keys(next).filter((field) => field.startsWith(nestedPrefix)).forEach((field) => delete next[field])
      return next
    })
    setFormError(null)
  }

  function setNominalQuantity(value: string) {
    setField('nominalQuantity', value)
  }

  function setUsableQuantity(value: string) {
    setValues((current) => ({
      ...current,
      usableQuantity: value,
      usableQuantityExplicit: value.trim() !== '',
    }))
    setValidationErrors((current) => {
      const next = { ...current }
      delete next.usableQuantity
      return next
    })
    setServerErrors((current) => {
      const next = { ...current }
      delete next.usableQuantity
      return next
    })
    setFormError(null)
  }

  function setBaseUnit(value: RawMaterialUnit) {
    const dimension = unitDimension(value, unitOptions)
    const preferred = dimension === 'MASS' ? 'kg' : dimension === 'VOLUME' ? 'l' : 'pc'
    const pricingUnitCode = unitOptions.find((unit) => unit.code === preferred && unit.archivedAt === null)?.code
      ?? unitOptions.find((unit) => unit.dimension === dimension && unit.archivedAt === null)?.code
      ?? value
    setValues((current) => ({ ...current, baseUnitCode: value, pricingUnitCode }))
  }

  function setAlias(index: number, value: string) {
    setValues((current) => ({
      ...current,
      aliases: current.aliases.map((alias, itemIndex) => itemIndex === index ? value : alias),
    }))
    setValidationErrors((current) => {
      const next = { ...current }
      delete next[`aliases.${index}`]
      return next
    })
    setServerErrors((current) => {
      const next = { ...current }
      delete next[`aliases.${index}`]
      return next
    })
    setFormError(null)
  }

  function setConversion(index: number, change: Partial<RawMaterialConversionInput>) {
    setValues((current) => ({
      ...current,
      conversions: current.conversions.map((conversion, itemIndex) => (
        itemIndex === index ? { ...conversion, ...change } : conversion
      )),
    }))
    setValidationErrors((current) => {
      const next = { ...current }
      Object.keys(next).filter((key) => key.startsWith(`conversions.${index}.`)).forEach((key) => delete next[key])
      delete next.conversions
      return next
    })
    setServerErrors((current) => {
      const next = { ...current }
      Object.keys(next).filter((key) => key.startsWith(`conversions.${index}.`)).forEach((key) => delete next[key])
      return next
    })
    setFormError(null)
  }

  function getError(key: string): string | undefined {
    return validationErrors[key] ?? serverErrors[key]
  }

  function onApiFailure(cause: unknown, stage: AttemptStage) {
    if (cause instanceof ApiError) {
      if (cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE' || cause.status === 503) {
        setPhase('unavailable')
        setFormError('Moduł RAW_MATERIALS jest niedostępny. Formularz został zablokowany.')
        onModuleUnavailable()
        return
      }
      if (isSessionExpired(cause)) {
        setPhase('session-expired')
        setFormError('Sesja wygasła. Zapis został wstrzymany; zaloguj się ponownie.')
        onSessionExpired()
        return
      }
      if (cause.status === 403) {
        setPhase('forbidden')
        setFormError('Uprawnienie do tworzenia nie jest już aktywne. Nie wysłano kolejnego żądania.')
        onAuthorizationDenied()
        return
      }
      if (cause.status === 422) {
        const errors = asFieldErrors(cause.errors)
        const allergenMessage = Object.entries(errors).find(([key]) =>
          ['allergen_declaration', 'status', 'catalog_version_id', 'items'].some((prefix) => key.startsWith(prefix)))?.[1] ?? null
        setServerErrors(errors)
        setAllergenValidation(allergenMessage)
        focusErrors(Object.fromEntries(Object.entries(errors).filter(([key]) =>
          !['allergen_declaration', 'status', 'catalog_version_id', 'items'].some((prefix) => key.startsWith(prefix)))), allergenMessage)
        setFormError(cause.message)
        setPhase('editing')
        operationLocked.current = false
        return
      }
      if (cause.status === 409) {
        if (cause.code === 'RAW_SKU_RESERVATION_EXPIRED') {
          reservation.current = null
          reservationKey.current = null
          attemptPayload.current = null
          setPhase('editing')
          operationLocked.current = false
          setFormError('Czas na wykorzystanie numeru SKU minął. Sprawdź formularz i spróbuj ponownie.')
          return
        }
        setPhase('conflict')
        setFormError(`${cause.message} Nie zmieniaj danych przy ponawianiu tej próby.`)
        operationLocked.current = false
        return
      }
      if (cause.status >= 500) {
        setPhase(stage === 'reservation' ? 'reservation-uncertain' : 'creation-uncertain')
        setFormError('Nie można potwierdzić wyniku operacji. Ponowienie tej samej próby pozwoli bezpiecznie sprawdzić jej rezultat.')
        return
      }
      setFormError(cause.message)
      setPhase('editing')
      operationLocked.current = false
      return
    }

    setPhase(stage === 'reservation' ? 'reservation-uncertain' : 'creation-uncertain')
    setFormError('Nie można potwierdzić wyniku operacji. Ponów wyłącznie tę samą próbę, aby bezpiecznie sprawdzić jej rezultat.')
  }

  async function submitCreation(payload: RawMaterialCreatePayload & { allergen_declaration?: AllergenDeclaration }, currentReservation: RawMaterialSkuReservation) {
    attemptStage.current = 'creation'
    setPhase('creating')
    setFormError(null)
    try {
      const declaration = payload.allergen_declaration
      const result = attemptApproved.current && declaration
        ? await createApprovedRawMaterial({ ...payload, allergen_declaration: declaration }, currentReservation.reservationId)
        : await createRawMaterial(payload, currentReservation.reservationId)
      if (!active.current) return
      setCreated(result)
      setPhase('complete')
      operationLocked.current = false
      if (!createdNotified.current) {
        createdNotified.current = true
        onCreated(result)
      }
    } catch (cause) {
      if (!active.current) return
      onApiFailure(cause, 'creation')
    }
  }

  async function obtainReservation(key: string, payload: RawMaterialCreatePayload & { allergen_declaration?: AllergenDeclaration }) {
    attemptStage.current = 'reservation'
    setPhase('reserving')
    setFormError(null)
    try {
      const result = await reserveRawMaterialSku(key)
      if (!active.current) return
      if (result.status !== 'RESERVED') {
        if (result.status === 'EXPIRED') {
          reservationKey.current = null
          attemptPayload.current = null
          operationLocked.current = false
          setPhase('editing')
          setFormError('Czas na wykorzystanie numeru SKU minął. Spróbuj ponownie, aby otrzymać nowy numer.')
          return
        }
        operationLocked.current = false
        setPhase('conflict')
        setFormError('Ten numer SKU został już wykorzystany. Nie utworzono kolejnego surowca.')
        return
      }
      reservation.current = result
      await submitCreation(payload, result)
    } catch (cause) {
      if (!active.current) return
      onApiFailure(cause, 'reservation')
    }
  }

  function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (phase !== 'editing' || operationLocked.current) return
    setAttempted(true)
    setFormError(null)
    setServerErrors({})
    const errors = validateRawMaterialCreate(values, true, unitOptions)
    const submitter = (event.nativeEvent as SubmitEvent).submitter
    const approve = submitter instanceof HTMLButtonElement && submitter.value === 'approve'
    const declarationError = allergenError(allergens, approve)
    setAllergenValidation(declarationError)
    setValidationErrors(errors)
    if (Object.keys(errors).length > 0 || declarationError) {
      focusErrors(errors, declarationError)
      return
    }

    const payload = { ...buildRawMaterialCreatePayload(values, canManageSuppliers && supplierOptionsLoaded,
      canManageReferencePrice && canViewReferencePrice, unitOptions),
      ...(allergens.status !== 'UNKNOWN' ? { allergen_declaration: allergens } : {}) }
    const key = reservationKey.current ?? crypto.randomUUID()
    reservationKey.current = key
    attemptPayload.current = payload
    attemptApproved.current = approve && canCreateApproved
    setApproving(attemptApproved.current)
    operationLocked.current = true
    void obtainReservation(key, payload)
  }

  function retrySameAttempt() {
    if (!operationLocked.current || !attemptPayload.current) return
    if (attemptStage.current === 'reservation') {
      if (reservationKey.current) void obtainReservation(reservationKey.current, attemptPayload.current)
      return
    }
    if (reservation.current) void submitCreation(attemptPayload.current, reservation.current)
  }

  function requestClose() {
    if (isBusy(phase)) return
    if (phase === 'complete') {
      onClose()
      return
    }
    const reservationNotice = reservation.current
      ? '\n\nNiewykorzystany numer SKU nie będzie ponownie przydzielony.'
      : ''
    if (dirty && !window.confirm(`Odrzucić niezapisane zmiany?${reservationNotice}`)) return
    onClose()
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLElement>) {
    if (event.key === 'Escape') {
      event.preventDefault()
      requestClose()
      return
    }
    if (event.key !== 'Tab') return
    const focusable = dialogRef.current?.querySelectorAll<HTMLElement>(
      'button:not(:disabled):not([tabindex="-1"]), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [role="tabpanel"]',
    )
    if (!focusable?.length) {
      event.preventDefault()
      dialogRef.current?.focus()
      return
    }
    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  function fieldProps(key: string) {
    const message = getError(key)
    return {
      'aria-invalid': Boolean(message),
      ...(message ? { 'aria-describedby': `${key.replaceAll('.', '-')}-error` } : {}),
    }
  }

  const firstQuantityError = getError('nominalQuantity') ?? getError('usableQuantity')

  return (
    <div className="create-form-backdrop" onMouseDown={(event) => {
      if (event.target === event.currentTarget) requestClose()
    }}>
      <section
        ref={dialogRef}
        className="create-form-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="raw-create-title"
        tabIndex={-1}
        onKeyDown={handleKeyDown}
      >
        <header className="create-form-header">
          <div>
            <p className="eyebrow">KATALOG SUROWCÓW</p>
            <h2 id="raw-create-title">Dodaj surowiec</h2>
            <p>{canCreateApproved ? 'Możesz zapisać szkic lub zatwierdzić kompletny surowiec.' : 'Surowiec zostanie zapisany jako szkic.'} Numer SKU zostanie nadany automatycznie.</p>
          </div>
          <button
            type="button"
            className="button button-secondary"
            onClick={requestClose}
            disabled={isBusy(phase)}
            aria-label="Zamknij formularz dodawania surowca"
          >
            Zamknij
          </button>
        </header>

        {phase === 'reservation-uncertain' && (
          <div className="create-form-notice" role="alert">
            <strong>Nie udało się potwierdzić nadania numeru.</strong>
            <p>Ponowienie użyje tej samej próby. Formularz pozostaje zablokowany do potwierdzenia wyniku.</p>
            <button type="button" className="button button-primary" onClick={retrySameAttempt}>Ponów bezpiecznie</button>
          </div>
        )}
        {canManageSuppliers && (
          <label className="create-form-field create-form-field-wide" htmlFor="create-suppliers">
            Dostawcy — powiązania informacyjne
            {supplierOptionsLoaded ? (
              <select id="create-suppliers" multiple value={values.supplierIds}
                onChange={(event) => setField('supplierIds',
                  Array.from(event.currentTarget.selectedOptions, (option) => option.value))}>
                {supplierOptions.map((supplier) => (
                  <option key={supplier.id} value={supplier.id}>{supplierLabel(supplier)}</option>
                ))}
              </select>
            ) : (
              <span className="field-hint">Wczytywanie wspólnej biblioteki dostawców…</span>
            )}
            <span className="field-hint">Powiązanie nie wyznacza ceny i nie pobiera ofert.</span>
            {supplierError && <span className="field-error" role="alert">{supplierError}</span>}
          </label>
        )}
        {phase === 'creation-uncertain' && (
          <div className="create-form-notice" role="alert">
            <strong>Nie udało się potwierdzić zapisu.</strong>
            <p>Nie zamykaj formularza ani nie zmieniaj danych. Ponowienie użyje tych samych danych, aby uniknąć utworzenia duplikatu.</p>
            <button type="button" className="button button-primary" onClick={retrySameAttempt}>Ponów bezpiecznie</button>
          </div>
        )}
        {phase === 'reserving' && <p className="create-form-status" role="status">Przygotowywanie numeru SKU…</p>}
        {phase === 'creating' && <p className="create-form-status" role="status">{approving ? 'Dodawanie i zatwierdzanie surowca…' : 'Zapisywanie szkicu surowca…'}</p>}
        {unitsError && <p className="create-form-error" role="alert">Nie można wczytać słownika jednostek: {unitsError}</p>}
        {formError && phase !== 'reservation-uncertain' && phase !== 'creation-uncertain' && (
          <p className="create-form-error" role="alert">{formError}</p>
        )}

        {phase === 'complete' && created ? (
          <section className="create-success" aria-labelledby="create-success-title">
            <p className="create-success-mark" aria-hidden="true">✓</p>
            <h3 id="create-success-title">Surowiec utworzony</h3>
            <p>Surowiec <strong>{created.sku}</strong> {created.lifecycleState === 'ACTIVE' ? 'został zatwierdzony.' : 'został zapisany jako szkic.'}</p>
            {created.lifecycleState === 'DRAFT' && <p>Surowiec nie został zatwierdzony ani opublikowany.</p>}
            <div className="create-form-actions">
              <button type="button" className="button button-secondary" onClick={requestClose}>Wróć do katalogu</button>
              <button type="button" className="button button-primary" onClick={() => onOpenCreated(created)}>Otwórz szczegóły</button>
            </div>
          </section>
        ) : (
          <form onSubmit={handleSubmit} noValidate>
            {attempted && Object.keys(validationErrors).length > 0 && (
              <p className="create-form-error" role="alert">Popraw pola oznaczone poniżej.</p>
            )}

            <Tabs label="Formularz surowca" value={tab} onChange={setTab} tabs={[
              { id: 'basic', label: `Dane podstawowe${Object.keys({ ...validationErrors, ...serverErrors }).some((key) => ['categoryId', 'name', 'baseUnitCode', 'nominalQuantity', 'usableQuantity', 'pricingAmount', 'pricingUnitCode'].includes(key)) ? ' • błąd' : ''}`, content: (
            <fieldset disabled={locked} className="create-form-fieldset">
              <legend>Dane podstawowe</legend>
              <div className="create-form-grid">
                <label className="create-form-field" htmlFor="create-category">
                  Kategoria <span className="required-mark">(wymagane)</span>
                  <select
                    id="create-category"
                    value={values.categoryId}
                    required
                    onChange={(event) => setField('categoryId', event.target.value)}
                    {...fieldProps('categoryId')}
                  >
                    <option value="">Wybierz kategorię</option>
                    {categories.filter((category) => category.archivedAt == null)
                      .map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                  </select>
                  {getError('categoryId') && <span id="categoryId-error" className="field-error">{getError('categoryId')}</span>}
                </label>

                <label className="create-form-field" htmlFor="create-name">
                  Nazwa surowca <span className="required-mark">(wymagane)</span>
                  <input
                    id="create-name"
                    type="text"
                    value={values.name}
                    maxLength={200}
                    autoComplete="off"
                    required
                    onChange={(event) => setField('name', event.target.value)}
                    {...fieldProps('name')}
                  />
                  <span className="field-hint">Maksymalnie 200 znaków.</span>
                  {getError('name') && <span id="name-error" className="field-error">{getError('name')}</span>}
                </label>

                <label className="create-form-field" htmlFor="create-base-unit">
                  Jednostka podstawowa <span className="required-mark">(wymagane)</span>
                  <select
                    id="create-base-unit"
                    value={values.baseUnitCode}
                    required
                    onChange={(event) => setBaseUnit(event.target.value as RawMaterialUnit)}
                    {...fieldProps('baseUnitCode')}
                  >
                    {unitOptions.filter((unit) => unit.archivedAt === null).map((unit) => <option key={unit.code} value={unit.code}>{unitLabel(unit.code, unitOptions)}</option>)}
                  </select>
                  <span className="field-hint">To jednostka, w której zapisuje się ilości surowca. „szt.” oznacza jedną sztukę, nie opakowanie.</span>
                  {getError('baseUnitCode') && <span id="baseUnitCode-error" className="field-error">{getError('baseUnitCode')}</span>}
                </label>

                <div className="create-form-field create-quantity-group">
                  <span>Ilości</span>
                  <div className="create-form-grid create-form-grid-inline">
                    <label htmlFor="create-nominal-quantity">Nominalna <span className="required-mark">(wymagane)</span></label>
                    <input
                      id="create-nominal-quantity"
                      type="text"
                      inputMode="decimal"
                      value={values.nominalQuantity}
                      maxLength={25}
                      required
                      onChange={(event) => setNominalQuantity(event.target.value)}
                      {...fieldProps('nominalQuantity')}
                      aria-describedby={firstQuantityError ? 'quantity-error' : 'quantity-hint'}
                    />
                    <label htmlFor="create-usable-quantity">Użytkowa</label>
                    <input
                      id="create-usable-quantity"
                      type="text"
                      inputMode="decimal"
                      value={values.usableQuantity}
                      maxLength={25}
                      onChange={(event) => setUsableQuantity(event.target.value)}
                      {...fieldProps('usableQuantity')}
                      aria-describedby={firstQuantityError ? 'quantity-error' : 'quantity-hint'}
                    />
                  </div>
                  <span id={firstQuantityError ? 'quantity-error' : 'quantity-hint'} className={firstQuantityError ? 'field-error' : 'field-hint'}>
                    {firstQuantityError ?? 'Pozostaw ilość użytkową pustą, aby automatycznie użyć ilości nominalnej. Dozwolone jest 6 miejsc po przecinku; ilość w sztukach musi być całkowita.'}
                  </span>
                </div>
                {canManageReferencePrice && canViewReferencePrice && (
                  <RawMaterialPricingFields id="create" values={values} disabled={locked} canCalculate
                    canViewCost={canViewUsableCost} error={getError('pricingAmount')} unitOptions={unitOptions}
                    onBasisChange={(value) => setField('pricingBasis', value)}
                    onAmountChange={(value) => setField('pricingAmount', value)}
                    onSessionExpired={onSessionExpired} onAuthorizationDenied={onAuthorizationDenied}
                    onModuleUnavailable={onModuleUnavailable} />
                )}
              </div>
            </fieldset>
              ) },
              { id: 'allergens', label: `Alergeny${allergenValidation ? ' • błąd' : ''}`, content: (
                <AllergenFields value={allergens} permitted={canManageAllergens} disabled={locked} error={allergenValidation}
                  onChange={(value) => { setAllergens(value); setAllergenValidation(null) }}
                  onSessionExpired={onSessionExpired} onModuleUnavailable={onModuleUnavailable} />
              ) },
              { id: 'additional', label: `Dodatkowe informacje${Object.keys({ ...validationErrors, ...serverErrors }).some((key) => key === 'description' || key.startsWith('aliases') || key.startsWith('conversions')) ? ' • błąd' : ''}`, content: (
              <div className="create-advanced-content">
                <label className="create-form-field create-form-field-wide" htmlFor="create-description">
                  Opis <span className="field-hint">(opcjonalnie, maksymalnie 10 000 znaków)</span>
                  <textarea id="create-description" value={values.description} maxLength={10000} rows={3}
                    disabled={locked} onChange={(event) => setField('description', event.target.value)} {...fieldProps('description')} />
                  {getError('description') && <span id="description-error" className="field-error">{getError('description')}</span>}
                </label>
                <section aria-labelledby="create-aliases-title">
                  <div className="create-subsection-heading">
                    <div>
                      <h3 id="create-aliases-title">Inne nazwy surowca</h3>
                      <p>Dodaj nazwy, pod którymi pracownicy mogą szukać tego samego surowca. Nie utworzą one osobnego surowca.</p>
                    </div>
                    <button
                      type="button"
                      className="button button-secondary"
                      disabled={locked || values.aliases.length >= 50}
                      onClick={() => setField('aliases', [...values.aliases, ''])}
                    >
                      Dodaj nazwę
                    </button>
                  </div>
                  {values.aliases.map((alias, index) => {
                    const error = getError(`aliases.${index}`)
                    return (
                      <div className="create-repeat-row" key={`alias-${index}`}>
                        <label className="create-form-field" htmlFor={`create-alias-${index}`}>
                          Inna nazwa {index + 1}
                          <input
                            id={`create-alias-${index}`}
                            type="text"
                            value={alias}
                            maxLength={200}
                            disabled={locked}
                            onChange={(event) => setAlias(index, event.target.value)}
                            aria-invalid={Boolean(error)}
                            aria-describedby={error ? `aliases-${index}-error` : undefined}
                          />
                          {error && <span id={`aliases-${index}-error`} className="field-error">{error}</span>}
                        </label>
                        <button
                          type="button"
                          className="button button-secondary"
                          disabled={locked}
                          onClick={() => setField('aliases', values.aliases.filter((_, itemIndex) => itemIndex !== index))}
                          aria-label={`Usuń nazwę ${index + 1}`}
                        >
                          Usuń
                        </button>
                      </div>
                    )
                  })}
                  {getError('aliases') && <p className="field-error" role="alert">{getError('aliases')}</p>}
                </section>

                <section aria-labelledby="create-conversions-title">
                  <div className="create-subsection-heading">
                    <div>
                      <h3 id="create-conversions-title">Dodatkowe jednostki miary</h3>
                      <p>
                        Jednostka podstawowa: {unitLabel(values.baseUnitCode, unitOptions)}. Przelicznik mówi, ile jednostek podstawowych
                        odpowiada jednej dodatkowej jednostce, np. 1 opakowanie = 250 g. Dla jednostek tego samego rodzaju
                        przeliczenie jest ustawiane automatycznie. Przy przeliczaniu między masą, objętością i sztukami podaj
                        przelicznik oraz opisz, czego dotyczy.
                      </p>
                    </div>
                    <button
                      type="button"
                      className="button button-secondary"
                      disabled={locked || values.conversions.length >= 20}
                      onClick={() => setField('conversions', [
                        ...values.conversions,
                        { unitCode: '', toBaseFactor: '', basis: '' },
                      ])}
                    >
                      Dodaj jednostkę
                    </button>
                  </div>
                  {values.conversions.map((conversion, index) => {
                    const unit = conversion.unitCode
                    const sameDimension = unitOptions.some((candidate) => candidate.code === unit)
                      && unitDimension(unit, unitOptions) === unitDimension(values.baseUnitCode, unitOptions)
                    const unitError = getError(`conversions.${index}.unitCode`)
                    const factorError = getError(`conversions.${index}.toBaseFactor`)
                    const basisError = getError(`conversions.${index}.basis`)
                    const factorHintId = `conversion-${index}-factor-hint`
                    const basisHintId = `conversion-${index}-basis-hint`
                    const factorErrorId = `conversion-${index}-factor-error`
                    const basisErrorId = `conversion-${index}-basis-error`
                    return (
                      <div className={`create-conversion-row${conversion.unitCode && sameDimension ? ' is-automatic' : ''}`} key={`conversion-${index}`}>
                        <label className="create-form-field create-conversion-unit" htmlFor={`create-conversion-unit-${index}`}>
                          Dodatkowa jednostka miary {index + 1}
                          <select
                            id={`create-conversion-unit-${index}`}
                            value={conversion.unitCode}
                            disabled={locked}
                            onChange={(event) => setConversion(index, { unitCode: event.target.value })}
                            aria-invalid={Boolean(unitError)}
                            aria-describedby={unitError ? `conversion-${index}-unit-error` : undefined}
                          >
                            <option value="">Wybierz jednostkę</option>
                            {unitOptions.filter((candidate) => candidate.archivedAt === null && candidate.code !== values.baseUnitCode).map((candidate) => (
                              <option key={candidate.code} value={candidate.code}>{unitLabel(candidate.code, unitOptions)}</option>
                            ))}
                          </select>
                          {unitError && <span id={`conversion-${index}-unit-error`} className="field-error">{unitError}</span>}
                        </label>
                        {conversion.unitCode && !sameDimension && (
                          <>
                            <div className="create-form-field create-conversion-factor">
                              <label htmlFor={`create-conversion-factor-${index}`}>Przelicznik</label>
                              <input
                                id={`create-conversion-factor-${index}`}
                                type="text"
                                inputMode="decimal"
                                value={conversion.toBaseFactor}
                                disabled={locked}
                                onChange={(event) => setConversion(index, { toBaseFactor: event.target.value })}
                                aria-invalid={Boolean(factorError)}
                                aria-describedby={[factorHintId, factorError ? factorErrorId : null].filter(Boolean).join(' ')}
                              />
                              <span id={factorHintId} className="field-hint">
                                {conversionFactorHint(conversion.unitCode, values.baseUnitCode, conversion.toBaseFactor, unitOptions)}
                              </span>
                              {factorError && <span id={factorErrorId} className="field-error">{factorError}</span>}
                            </div>
                            <div className="create-form-field create-conversion-basis">
                              <label htmlFor={`create-conversion-basis-${index}`}>Opis przeliczenia</label>
                              <input
                                id={`create-conversion-basis-${index}`}
                                type="text"
                                maxLength={500}
                                value={conversion.basis}
                                disabled={locked}
                                onChange={(event) => setConversion(index, { basis: event.target.value })}
                                aria-invalid={Boolean(basisError)}
                                aria-describedby={[basisHintId, basisError ? basisErrorId : null].filter(Boolean).join(' ')}
                              />
                              <span id={basisHintId} className="field-hint">
                                {conversionBasisHint(conversion.unitCode, values.baseUnitCode, conversion.toBaseFactor, unitOptions)}
                              </span>
                              {basisError && <span id={basisErrorId} className="field-error">{basisError}</span>}
                            </div>
                          </>
                        )}
                        {conversion.unitCode && sameDimension && (
                          <p className="field-hint create-conversion-auto">Przeliczenie jednostek tego samego rodzaju jest ustawiane automatycznie.</p>
                        )}
                        <button
                          type="button"
                          className="button button-secondary"
                          disabled={locked}
                          onClick={() => setField('conversions', values.conversions.filter((_, itemIndex) => itemIndex !== index))}
                          aria-label={`Usuń jednostkę przeliczeniową ${index + 1}`}
                        >
                          Usuń
                        </button>
                      </div>
                    )
                  })}
                  {getError('conversions') && <p className="field-error" role="alert">{getError('conversions')}</p>}
                </section>
              </div>
              ) },
            ]} />

            <div className="create-form-footer">
              <p>Numer SKU zostanie nadany automatycznie.{!canCreateApproved && ' Surowiec zostanie zapisany jako szkic.'}</p>
              <div className="create-form-actions">
                <button type="button" className="button button-secondary" onClick={requestClose} disabled={isBusy(phase)}>
                  Anuluj
                </button>
                <button type="submit" className="button button-primary" disabled={locked || categories.length === 0}>
                  Utwórz szkic
                </button>
                {canCreateApproved && <button type="submit" value="approve" className="button button-primary" disabled={locked || categories.length === 0}>
                  Dodaj i zatwierdź surowiec
                </button>}
              </div>
            </div>
          </form>
        )}
      </section>
    </div>
  )
}
