import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { RAW_UNITS, type CategoryOption } from './catalog'
import {
  buildRawMaterialCreatePayload,
  emptyRawMaterialCreateValues,
  conversionBasisHint,
  conversionFactorHint,
  UNIT_DIMENSIONS,
  UNIT_LABELS,
  validateRawMaterialCreate,
  type RawMaterialConversionInput,
  type RawMaterialCreatePayload,
  type RawMaterialCreateValues,
  type RawMaterialUnit,
} from './create'
import {
  createRawMaterial,
  reserveRawMaterialSku,
  type CreatedRawMaterial,
  type RawMaterialSkuReservation,
} from './api'

type Props = {
  categories: CategoryOption[]
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
  operatingLocationId,
  returnFocus,
  onClose,
  onCreated,
  onOpenCreated,
  onSessionExpired,
  onAuthorizationDenied,
  onModuleUnavailable,
}: Props) {
  const [values, setValues] = useState<RawMaterialCreateValues>(emptyRawMaterialCreateValues)
  const [phase, setPhase] = useState<Phase>('editing')
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({})
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [created, setCreated] = useState<CreatedRawMaterial | null>(null)
  const [attempted, setAttempted] = useState(false)
  const dialogRef = useRef<HTMLElement>(null)
  const [initialValues] = useState(emptyRawMaterialCreateValues)
  const active = useRef(true)
  const reservationKey = useRef<string | null>(null)
  const reservation = useRef<RawMaterialSkuReservation | null>(null)
  const attemptPayload = useRef<RawMaterialCreatePayload | null>(null)
  const attemptStage = useRef<AttemptStage>('reservation')
  const operationLocked = useRef(false)
  const createdNotified = useRef(false)
  const dirty = JSON.stringify(values) !== JSON.stringify(initialValues)
  const locked = isBusy(phase) || phase === 'complete'
    || phase === 'session-expired' || phase === 'forbidden'
    || phase === 'conflict' || phase === 'unavailable'

  useEffect(() => {
    active.current = true
    dialogRef.current?.querySelector<HTMLElement>('select, input, textarea, button')?.focus()
    return () => {
      active.current = false
      if (returnFocus?.isConnected) returnFocus.focus()
    }
  }, [returnFocus])

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
        setServerErrors(asFieldErrors(cause.errors))
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

  async function submitCreation(payload: RawMaterialCreatePayload, currentReservation: RawMaterialSkuReservation) {
    attemptStage.current = 'creation'
    setPhase('creating')
    setFormError(null)
    try {
      const result = await createRawMaterial(payload, currentReservation.reservationId)
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

  async function obtainReservation(key: string, payload: RawMaterialCreatePayload) {
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
    const errors = validateRawMaterialCreate(values)
    setValidationErrors(errors)
    if (Object.keys(errors).length > 0) {
      dialogRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
      return
    }

    const payload = buildRawMaterialCreatePayload(values)
    const key = reservationKey.current ?? crypto.randomUUID()
    reservationKey.current = key
    attemptPayload.current = payload
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
      'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled)',
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
            <p>Surowiec zostanie zapisany jako szkic. Numer SKU zostanie nadany automatycznie.</p>
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

        <p className="create-form-context">Kontekst lokalizacji: <code>{operatingLocationId}</code></p>

        {phase === 'reservation-uncertain' && (
          <div className="create-form-notice" role="alert">
            <strong>Nie udało się potwierdzić nadania numeru.</strong>
            <p>Ponowienie użyje tej samej próby. Formularz pozostaje zablokowany do potwierdzenia wyniku.</p>
            <button type="button" className="button button-primary" onClick={retrySameAttempt}>Ponów bezpiecznie</button>
          </div>
        )}
        {phase === 'creation-uncertain' && (
          <div className="create-form-notice" role="alert">
            <strong>Nie udało się potwierdzić zapisu.</strong>
            <p>Nie zamykaj formularza ani nie zmieniaj danych. Ponowienie użyje tych samych danych, aby uniknąć utworzenia duplikatu.</p>
            <button type="button" className="button button-primary" onClick={retrySameAttempt}>Ponów bezpiecznie</button>
          </div>
        )}
        {phase === 'reserving' && <p className="create-form-status" role="status">Przygotowywanie numeru SKU…</p>}
        {phase === 'creating' && <p className="create-form-status" role="status">Zapisywanie szkicu surowca…</p>}
        {formError && phase !== 'reservation-uncertain' && phase !== 'creation-uncertain' && (
          <p className="create-form-error" role="alert">{formError}</p>
        )}

        {phase === 'complete' && created ? (
          <section className="create-success" aria-labelledby="create-success-title">
            <p className="create-success-mark" aria-hidden="true">✓</p>
            <h3 id="create-success-title">Surowiec utworzony</h3>
            <p>Surowiec <strong>{created.sku}</strong> został zapisany jako szkic. Identyfikator: <code>{created.id}</code>.</p>
            <p>Surowiec nie został zatwierdzony ani opublikowany.</p>
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
                    {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
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

                <label className="create-form-field create-form-field-wide" htmlFor="create-description">
                  Opis <span className="field-hint">(opcjonalnie, maksymalnie 10 000 znaków)</span>
                  <textarea
                    id="create-description"
                    value={values.description}
                    maxLength={10000}
                    rows={3}
                    onChange={(event) => setField('description', event.target.value)}
                    {...fieldProps('description')}
                  />
                  {getError('description') && <span id="description-error" className="field-error">{getError('description')}</span>}
                </label>

                <label className="create-form-field" htmlFor="create-base-unit">
                  Jednostka podstawowa <span className="required-mark">(wymagane)</span>
                  <select
                    id="create-base-unit"
                    value={values.baseUnitCode}
                    required
                    onChange={(event) => setField('baseUnitCode', event.target.value as RawMaterialUnit)}
                    {...fieldProps('baseUnitCode')}
                  >
                    {RAW_UNITS.map((unit) => <option key={unit} value={unit}>{UNIT_LABELS[unit]}</option>)}
                  </select>
                  <span className="field-hint">To jednostka, w której zapisuje się ilości surowca. „szt.” oznacza jedną sztukę, nie opakowanie.</span>
                  {getError('baseUnitCode') && <span id="baseUnitCode-error" className="field-error">{getError('baseUnitCode')}</span>}
                </label>

                <div className="create-form-field create-quantity-group">
                  <span>Ilości <span className="field-hint">(opcjonalnie)</span></span>
                  <div className="create-form-grid create-form-grid-inline">
                    <label htmlFor="create-nominal-quantity">Nominalna</label>
                    <input
                      id="create-nominal-quantity"
                      type="text"
                      inputMode="decimal"
                      value={values.nominalQuantity}
                      maxLength={25}
                      onChange={(event) => setField('nominalQuantity', event.target.value)}
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
                      onChange={(event) => setField('usableQuantity', event.target.value)}
                      {...fieldProps('usableQuantity')}
                      aria-describedby={firstQuantityError ? 'quantity-error' : 'quantity-hint'}
                    />
                  </div>
                  <span id={firstQuantityError ? 'quantity-error' : 'quantity-hint'} className={firstQuantityError ? 'field-error' : 'field-hint'}>
                    {firstQuantityError ?? 'Jeśli podasz tylko ilość nominalną, ilość użytkowa przyjmie tę samą wartość. Dozwolone jest 6 miejsc po przecinku; ilość w sztukach musi być całkowita.'}
                  </span>
                </div>
              </div>
            </fieldset>

            <details className="create-advanced">
              <summary>Inne nazwy i dodatkowe jednostki miary <span>(opcjonalnie)</span></summary>
              <div className="create-advanced-content">
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
                        Jednostka podstawowa: {UNIT_LABELS[values.baseUnitCode]}. Przelicznik mówi, ile jednostek podstawowych
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
                    const unit = conversion.unitCode as RawMaterialUnit
                    const sameDimension = RAW_UNITS.includes(unit)
                      && UNIT_DIMENSIONS[unit] === UNIT_DIMENSIONS[values.baseUnitCode]
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
                            {RAW_UNITS.filter((candidate) => candidate !== values.baseUnitCode).map((candidate) => (
                              <option key={candidate} value={candidate}>{UNIT_LABELS[candidate]}</option>
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
                                {conversionFactorHint(conversion.unitCode, values.baseUnitCode, conversion.toBaseFactor)}
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
                                {conversionBasisHint(conversion.unitCode, values.baseUnitCode, conversion.toBaseFactor)}
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
            </details>

            <div className="create-form-footer">
              <p>Numer SKU zostanie nadany automatycznie. Surowiec zostanie zapisany jako szkic.</p>
              <div className="create-form-actions">
                <button type="button" className="button button-secondary" onClick={requestClose} disabled={isBusy(phase)}>
                  Anuluj
                </button>
                <button type="submit" className="button button-primary" disabled={locked || categories.length === 0}>
                  Utwórz szkic
                </button>
              </div>
            </div>
          </form>
        )}
      </section>
    </div>
  )
}
