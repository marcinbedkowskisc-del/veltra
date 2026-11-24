import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { formatNumber } from '../design-system/formatting'
import Tabs from '../design-system/Tabs'
import AllergenFields from './AllergenFields'
import RawMaterialPricingFields from './RawMaterialPricingFields'
import RawMaterialDocumentsSection from './RawMaterialDocumentsSection'
import { lifecycleLabel } from './catalog'
import { supplierLabel, type CategoryOption, type SupplierOption } from './catalog'
import {
  normalizeDecimalInput,
  conversionBasisHint,
  conversionFactorHint,
  unitDimension,
  unitLabel,
  validateRawMaterialCreate,
  withPricingUnit,
  type RawMaterialConversionInput,
  type RawMaterialCreateValues,
  type RawMaterialUnitOption,
} from './create'
import {
  fetchRawMaterialCapabilities,
  fetchRawMaterialCompletion,
  fetchRawMaterialEditor,
  fetchAllergenDeclaration,
  allergenError,
  unknownAllergens,
  reviseRawMaterial,
  type RawMaterialEditPayload,
  type RawMaterialEditor,
  type RevisedRawMaterial,
  type AllergenDeclaration,
  fetchSuppliers,
  fetchRawMaterialUnits,
} from './api'

type Props = {
  materialId: string
  operatingLocationId: string
  categories: CategoryOption[]
  returnFocus: HTMLElement | null
  onClose: () => void
  onSaved: (material: RevisedRawMaterial) => void
  onSessionExpired: () => void
  onAuthorizationDenied: () => void
  onModuleUnavailable: () => void
}

type Phase = 'loading' | 'editing' | 'authorizing' | 'saving' | 'conflict'
  | 'uncertain' | 'saved' | 'forbidden' | 'not-found' | 'unavailable' | 'error'

type EditorSnapshot = RawMaterialEditor

function editableValues(values: RawMaterialCreateValues): RawMaterialCreateValues {
  const quantity = (value: string) => value
    ? formatNumber(value, { maximumFractionDigits: 14 }).replace(/\s/g, '')
    : ''
  return {
    ...values,
    nominalQuantity: quantity(values.nominalQuantity),
    usableQuantity: quantity(values.usableQuantity),
  }
}

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

function serverFieldErrors(errors: Record<string, string[]>): Record<string, string> {
  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [
    serverFieldName(field),
    messages.join(' '),
  ]))
}

function buildEditPayload(
  values: RawMaterialCreateValues,
  changeReason: string,
  includePricing: boolean,
  includeSuppliers: boolean,
  initialValues: RawMaterialCreateValues,
  units: RawMaterialUnitOption[],
  documentUploadIds: string[],
  detachDocumentVersionIds: string[],
  historicalPricingUnit: boolean,
  historicalPriceConfirmed: boolean,
): RawMaterialEditPayload {
  const nominal = normalizeDecimalInput(values.nominalQuantity)
  const usable = normalizeDecimalInput(values.usableQuantity)
  const conversions: RawMaterialEditPayload['conversions'] = withPricingUnit(values.conversions.map((conversion) => {
    const unitCode = units.find((unit) => unit.code === conversion.unitCode)?.code
    if (!unitCode) throw new Error('Wybierz prawidłową jednostkę przeliczeniową.')
    if (unitDimension(unitCode, units) === unitDimension(values.baseUnitCode, units)) {
      return { unit_code: unitCode }
    }
    return {
      unit_code: unitCode,
      to_base_factor: normalizeDecimalInput(conversion.toBaseFactor),
      basis: conversion.basis.trim(),
    }
  }), values, includePricing && (!historicalPricingUnit || historicalPriceConfirmed))
  const pricingChanged = values.pricingBasis !== initialValues.pricingBasis
    || values.pricingAmount !== initialValues.pricingAmount
    || (values.pricingBasis === 'NOMINAL_UNIT' && values.pricingUnitCode !== initialValues.pricingUnitCode)
    || (historicalPricingUnit && historicalPriceConfirmed)

  return {
    category_id: values.categoryId,
    name: values.name.trim(),
    description: values.description.trim() || null,
    base_unit_code: values.baseUnitCode,
    ...(nominal ? { nominal_quantity: nominal } : {}),
    ...(values.usableQuantityExplicit && usable ? { usable_quantity: usable } : {}),
    ...(includePricing && pricingChanged && normalizeDecimalInput(values.pricingAmount) ? {
      pricing: {
        basis: values.pricingBasis,
        amount: normalizeDecimalInput(values.pricingAmount),
        ...(values.pricingBasis === 'NOMINAL_UNIT' ? { unit_code: values.pricingUnitCode } : {}),
      },
    } : {}),
    ...(includeSuppliers ? { supplier_ids: values.supplierIds } : {}),
    ...(documentUploadIds.length ? { document_upload_ids: documentUploadIds } : {}),
    ...(detachDocumentVersionIds.length ? { detach_document_version_ids: detachDocumentVersionIds } : {}),
    aliases: values.aliases.map((alias) => alias.trim()).filter(Boolean),
    conversions,
    ...(changeReason.trim() ? { change_reason: changeReason.trim() } : {}),
  }
}

function isBusy(phase: Phase): boolean {
  return phase === 'loading' || phase === 'authorizing' || phase === 'saving'
}

export default function RawMaterialEditForm({
  materialId,
  operatingLocationId,
  categories,
  returnFocus,
  onClose,
  onSaved,
  onSessionExpired,
  onAuthorizationDenied,
  onModuleUnavailable,
}: Props) {
  const [phase, setPhase] = useState<Phase>('loading')
  const [snapshot, setSnapshot] = useState<EditorSnapshot | null>(null)
  const [values, setValues] = useState<RawMaterialCreateValues | null>(null)
  const [tab, setTab] = useState('basic')
  const [allergens, setAllergens] = useState<AllergenDeclaration>(unknownAllergens)
  const [initialAllergens, setInitialAllergens] = useState<AllergenDeclaration>(unknownAllergens)
  const [canEditAllergens, setCanEditAllergens] = useState(false)
  const [canManageReferencePrice, setCanManageReferencePrice] = useState(false)
  const [canViewReferencePrice, setCanViewReferencePrice] = useState(false)
  const [canViewUsableCost, setCanViewUsableCost] = useState(false)
  const [canManageSuppliers, setCanManageSuppliers] = useState(false)
  const [supplierOptions, setSupplierOptions] = useState<SupplierOption[]>([])
  const [supplierOptionsLoaded, setSupplierOptionsLoaded] = useState(false)
  const [supplierError, setSupplierError] = useState<string | null>(null)
  const [documentUploadIds, setDocumentUploadIds] = useState<string[]>([])
  const [detachDocumentVersionIds, setDetachDocumentVersionIds] = useState<string[]>([])
  const [documentsUploading, setDocumentsUploading] = useState(false)
  const [unitOptions, setUnitOptions] = useState<RawMaterialUnitOption[]>([])
  const [unitsLoading, setUnitsLoading] = useState(true)
  const [unitsError, setUnitsError] = useState<string | null>(null)
  const [allergenValidation, setAllergenValidation] = useState<string | null>(null)
  const [focusError, setFocusError] = useState(0)
  const [initialValues, setInitialValues] = useState<RawMaterialCreateValues | null>(null)
  const [historicalPriceConfirmed, setHistoricalPriceConfirmed] = useState(false)
  const [changeReason, setChangeReason] = useState('')
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({})
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [attempted, setAttempted] = useState(false)
  const [saved, setSaved] = useState<RevisedRawMaterial | null>(null)
  const [uncertainRevision, setUncertainRevision] = useState<number | null>(null)
  const dialogRef = useRef<HTMLElement>(null)
  const locationRef = useRef(operatingLocationId)
  const categoriesRef = useRef(categories)
  const callbacksRef = useRef({ onAuthorizationDenied, onModuleUnavailable, onSessionExpired })
  const active = useRef(true)
  const operationLocked = useRef(false)
  const dirty = values !== null && initialValues !== null
    && (JSON.stringify(values) !== JSON.stringify(initialValues)
      || JSON.stringify(allergens) !== JSON.stringify(initialAllergens)
      || documentUploadIds.length > 0 || detachDocumentVersionIds.length > 0 || documentsUploading)
  useLayoutEffect(() => {
    locationRef.current = operatingLocationId
    categoriesRef.current = categories
    callbacksRef.current = { onAuthorizationDenied, onModuleUnavailable, onSessionExpired }
  }, [categories, onAuthorizationDenied, onModuleUnavailable, onSessionExpired, operatingLocationId])

  useEffect(() => {
    active.current = true
    const controller = new AbortController()
    const expectedLocation = operatingLocationId
    const load = async () => {
      try {
        const [capabilities, completion] = await Promise.all([
          fetchRawMaterialCapabilities(expectedLocation, controller.signal),
          fetchRawMaterialCompletion(materialId, controller.signal),
        ])
        if (controller.signal.aborted || !active.current || locationRef.current !== expectedLocation) return
        if (capabilities.locationId === expectedLocation
          && (!capabilities.available || capabilities.readiness !== 'READY')) {
          setFormError('Moduł RAW_MATERIALS nie jest gotowy w tej lokalizacji. Edytor został zamknięty.')
          setPhase('unavailable')
          callbacksRef.current.onModuleUnavailable()
          return
        }
        if (capabilities.locationId !== expectedLocation || !capabilities.actions.edit
          || !completion.availableActions.includes('edit') || !completion.version) {
          setFormError('Brak aktualnego uprawnienia do edycji tego surowca.')
          setPhase('forbidden')
          callbacksRef.current.onAuthorizationDenied()
          return
        }
        const editor = await fetchRawMaterialEditor(materialId, controller.signal)
        if (controller.signal.aborted || !active.current || locationRef.current !== expectedLocation) return
        if (editor.revisionId !== completion.revisionId || editor.version !== completion.version) {
          setFormError('Dane edytora zmieniły się podczas otwierania. Wczytaj aktualny stan przed edycją.')
          setPhase('error')
          return
        }
        const permitted = completion.availableActions.includes('edit_allergens')
        const declaration = permitted ? await fetchAllergenDeclaration(materialId, controller.signal) : null
        if (controller.signal.aborted || !active.current || locationRef.current !== expectedLocation) return
        if (declaration && declaration.version !== editor.version) {
          setFormError('Dane alergenów zmieniły się podczas otwierania. Wczytaj aktualny stan przed edycją.')
          setPhase('error')
          return
        }
        if (!categoriesRef.current.some((category) => category.id === editor.values.categoryId)) {
          setFormError('Nie można bezpiecznie potwierdzić kategorii bieżącej rewizji. Edycja pozostaje zablokowana.')
          setPhase('error')
          return
        }
        const supplierPermission = capabilities.actions.manageSuppliers === true
        if (supplierPermission) {
          try {
            const suppliers = await fetchSuppliers('', controller.signal)
            if (controller.signal.aborted || !active.current || locationRef.current !== expectedLocation) return
            setSupplierOptions(suppliers)
            setSupplierOptionsLoaded(true)
            setSupplierError(null)
          } catch (cause) {
            if (controller.signal.aborted) return
            setSupplierOptionsLoaded(false)
            setSupplierError(cause instanceof Error ? cause.message : 'Nie udało się wczytać biblioteki dostawców.')
          }
        } else {
          setSupplierOptions([])
          setSupplierOptionsLoaded(false)
          setSupplierError(null)
        }
        setSnapshot(editor)
        const formattedValues = editableValues(editor.values)
        setValues(formattedValues)
        setInitialValues(formattedValues)
        setHistoricalPriceConfirmed(false)
        setCanEditAllergens(permitted)
        setCanViewReferencePrice(capabilities.actions.viewReferencePrice === true)
        setCanViewUsableCost(capabilities.actions.viewUsableCost === true)
        setCanManageSuppliers(supplierPermission)
        setCanManageReferencePrice(capabilities.actions.manageReferencePrice === true)
        setAllergens(declaration?.declaration ?? unknownAllergens())
        setInitialAllergens(declaration?.declaration ?? unknownAllergens())
        setPhase('editing')
      } catch (cause) {
        if (controller.signal.aborted || !active.current || locationRef.current !== expectedLocation) return
        handleFailure(cause, 'Nie udało się bezpiecznie wczytać danych edytora.')
      }
    }
    void load()
    dialogRef.current?.focus()

    return () => {
      active.current = false
      controller.abort()
      if (returnFocus?.isConnected) returnFocus.focus()
    }
  }, [materialId, operatingLocationId, returnFocus])

  useEffect(() => {
    const controller = new AbortController()
    void fetchRawMaterialUnits(controller.signal).then((result) => {
      if (!controller.signal.aborted && active.current) {
        setUnitOptions(result)
        setUnitsError(null)
      }
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
    if (!dirty || phase === 'saved') return
    const preventNavigation = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', preventNavigation)
    return () => window.removeEventListener('beforeunload', preventNavigation)
  }, [dirty, phase])

  useEffect(() => {
    if (phase === 'editing') {
      dialogRef.current?.querySelector<HTMLElement>('select, input, textarea')?.focus()
    }
  }, [phase])

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

  function handleFailure(cause: unknown, fallback: string) {
    if (isSessionExpired(cause)) {
      setPhase('forbidden')
      setFormError('Sesja wygasła. Zaloguj się ponownie, aby kontynuować.')
      callbacksRef.current.onSessionExpired()
      return
    }
    if (cause instanceof ApiError && cause.status === 403) {
      setPhase('forbidden')
      setFormError('Brak aktualnego uprawnienia do edycji tego surowca.')
      callbacksRef.current.onAuthorizationDenied()
      return
    }
    if (cause instanceof ApiError && cause.status === 404) {
      setPhase('not-found')
      setFormError('Surowiec jest niedostępny w bieżącym kontekście.')
      return
    }
    if (cause instanceof ApiError && (cause.code === 'RAW_MATERIALS_MODULE_UNAVAILABLE' || cause.status === 503)) {
      setPhase('unavailable')
      setFormError('Moduł RAW_MATERIALS jest niedostępny w tej lokalizacji.')
      callbacksRef.current.onModuleUnavailable()
      return
    }
    setPhase('error')
    setFormError(cause instanceof Error ? cause.message : fallback)
  }

  function setField<K extends keyof RawMaterialCreateValues>(
    key: K,
    value: RawMaterialCreateValues[K],
  ) {
    setValues((current) => current ? { ...current, [key]: value } : current)
    setValidationErrors((current) => {
      const next = { ...current }
      delete next[String(key)]
      const prefix = key === 'aliases' ? 'aliases.' : key === 'conversions' ? 'conversions.' : null
      if (prefix) Object.keys(next).filter((field) => field.startsWith(prefix)).forEach((field) => delete next[field])
      return next
    })
    setServerErrors((current) => {
      const next = { ...current }
      delete next[String(key)]
      const prefix = key === 'aliases' ? 'aliases.' : key === 'conversions' ? 'conversions.' : null
      if (prefix) Object.keys(next).filter((field) => field.startsWith(prefix)).forEach((field) => delete next[field])
      return next
    })
    setFormError(null)
  }

  function setNominalQuantity(value: string) {
    setValues((current) => current ? {
      ...current,
      nominalQuantity: value,
      ...(current.usableQuantityExplicit ? {} : { usableQuantity: '' }),
    } : current)
    setValidationErrors((current) => ({ ...current, nominalQuantity: '' }))
    setServerErrors((current) => ({ ...current, nominalQuantity: '' }))
    setFormError(null)
  }

  function setUsableQuantity(value: string) {
    setValues((current) => current ? {
      ...current,
      usableQuantity: value,
      usableQuantityExplicit: value.trim() !== '',
    } : current)
    setValidationErrors((current) => ({ ...current, usableQuantity: '' }))
    setServerErrors((current) => ({ ...current, usableQuantity: '' }))
    setFormError(null)
  }

  function setBaseUnit(value: RawMaterialCreateValues['baseUnitCode']) {
    const dimension = unitDimension(value, unitOptions)
    const preferred = dimension === 'MASS' ? 'kg' : dimension === 'VOLUME' ? 'l' : 'pc'
    const pricingUnitCode = unitOptions.find((unit) => unit.code === preferred && unit.archivedAt === null)?.code
      ?? unitOptions.find((unit) => unit.dimension === dimension && unit.archivedAt === null)?.code
      ?? value
    setValues((current) => current ? { ...current, baseUnitCode: value, pricingUnitCode } : current)
  }

  function updateAlias(index: number, value: string) {
    if (!values) return
    setField('aliases', values.aliases.map((alias, itemIndex) => itemIndex === index ? value : alias))
  }

  function updateConversion(index: number, patch: Partial<RawMaterialConversionInput>) {
    if (!values) return
    setField('conversions', values.conversions.map((conversion, itemIndex) =>
      itemIndex === index ? { ...conversion, ...patch } : conversion))
  }

  function closeForm() {
    if (isBusy(phase) || documentsUploading) return
    if (dirty && !window.confirm('Odrzucić niezapisane zmiany?')) return
    onClose()
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLElement>) {
    event.stopPropagation()
    if (event.key === 'Escape') {
      event.preventDefault()
      closeForm()
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

  async function loadCurrentEditor(discardLocalChanges: boolean) {
    if (discardLocalChanges && !window.confirm('Odrzucić lokalne zmiany i wczytać najnowszą wersję danych?')) return
    setPhase('loading')
    setFormError(null)
    setUncertainRevision(null)
    const expectedLocation = operatingLocationId
    const controller = new AbortController()
    try {
      const [capabilities, completion] = await Promise.all([
        fetchRawMaterialCapabilities(expectedLocation, controller.signal),
        fetchRawMaterialCompletion(materialId, controller.signal),
      ])
      if (!active.current || locationRef.current !== expectedLocation) return
      if (capabilities.locationId === expectedLocation
        && (!capabilities.available || capabilities.readiness !== 'READY')) {
        setFormError('Moduł RAW_MATERIALS nie jest gotowy w tej lokalizacji.')
        setPhase('unavailable')
        callbacksRef.current.onModuleUnavailable()
        return
      }
      if (capabilities.locationId !== expectedLocation || !capabilities.actions.edit
        || !completion.availableActions.includes('edit') || !completion.version) {
        setPhase('forbidden')
        callbacksRef.current.onAuthorizationDenied()
        return
      }
      const editor = await fetchRawMaterialEditor(materialId, controller.signal)
      if (!active.current || locationRef.current !== expectedLocation) return
      if (editor.revisionId !== completion.revisionId || editor.version !== completion.version) {
        setFormError('Wersja zmieniła się podczas wczytywania. Spróbuj ponownie.')
        setPhase('error')
        return
      }
      const supplierPermission = capabilities.actions.manageSuppliers === true
      if (supplierPermission) {
        try {
          const suppliers = await fetchSuppliers('', controller.signal)
          if (!active.current || locationRef.current !== expectedLocation) return
          setSupplierOptions(suppliers)
          setSupplierOptionsLoaded(true)
          setSupplierError(null)
        } catch (cause) {
          if (!active.current) return
          setSupplierOptionsLoaded(false)
          setSupplierError(cause instanceof Error ? cause.message : 'Nie udało się wczytać biblioteki dostawców.')
        }
      } else {
        setSupplierOptions([])
        setSupplierOptionsLoaded(false)
        setSupplierError(null)
      }
      const permitted = completion.availableActions.includes('edit_allergens')
      const declaration = permitted ? await fetchAllergenDeclaration(materialId, controller.signal) : null
      if (!active.current || locationRef.current !== expectedLocation) return
      if (declaration && declaration.version !== editor.version) {
        setFormError('Dane alergenów zmieniły się podczas wczytywania.')
        setPhase('error')
        return
      }
      if (!categoriesRef.current.some((category) => category.id === editor.values.categoryId)) {
        setFormError('Nie można bezpiecznie potwierdzić kategorii bieżącej rewizji.')
        setPhase('error')
        return
      }
      setSnapshot(editor)
      const formattedValues = editableValues(editor.values)
      setValues(formattedValues)
      setInitialValues(formattedValues)
      setHistoricalPriceConfirmed(false)
      setCanEditAllergens(permitted)
      setCanManageReferencePrice(capabilities.actions.manageReferencePrice === true)
      setCanViewReferencePrice(capabilities.actions.viewReferencePrice === true)
      setCanViewUsableCost(capabilities.actions.viewUsableCost === true)
      setCanManageSuppliers(supplierPermission)
      setAllergens(declaration?.declaration ?? unknownAllergens())
      setInitialAllergens(declaration?.declaration ?? unknownAllergens())
      setAllergenValidation(null)
      setValidationErrors({})
      setServerErrors({})
      setChangeReason('')
      setAttempted(false)
      setPhase('editing')
      setFormError(discardLocalChanges
        ? 'Wczytano najnowszą wersję danych. Sprawdź je przed zapisaniem zmian.'
        : null)
    } catch (cause) {
      if (!active.current || locationRef.current !== expectedLocation) return
      handleFailure(cause, 'Nie udało się wczytać aktualnej rewizji.')
    } finally {
      controller.abort()
    }
  }

  async function readUncertainServerState() {
    const expectedLocation = operatingLocationId
    const controller = new AbortController()
    try {
      const [capabilities, completion] = await Promise.all([
        fetchRawMaterialCapabilities(expectedLocation, controller.signal),
        fetchRawMaterialCompletion(materialId, controller.signal),
      ])
      if (!active.current || locationRef.current !== expectedLocation) return
      if (capabilities.locationId === expectedLocation
        && (!capabilities.available || capabilities.readiness !== 'READY')) {
        setFormError('Moduł RAW_MATERIALS nie jest gotowy w tej lokalizacji.')
        setPhase('unavailable')
        callbacksRef.current.onModuleUnavailable()
        return
      }
      if (capabilities.locationId !== expectedLocation || !capabilities.actions.edit
        || !completion.availableActions.includes('edit') || !completion.version) {
        setPhase('forbidden')
        callbacksRef.current.onAuthorizationDenied()
        return
      }
      const editor = await fetchRawMaterialEditor(materialId, controller.signal)
      if (!active.current || locationRef.current !== expectedLocation) return
      if (editor.revisionId !== completion.revisionId || editor.version !== completion.version) {
        setFormError('Odczytano niespójne dane. Lokalne zmiany zachowano; wczytaj najnowszą wersję, aby kontynuować.')
        return
      }
      setUncertainRevision(editor.revisionNo)
      setFormError('Nie można potwierdzić wyniku zapisu. Nie rozpoczęto ponownego zapisu; lokalne zmiany zachowano.')
    } catch (cause) {
      if (!active.current || locationRef.current !== expectedLocation) return
      if (isSessionExpired(cause) || (cause instanceof ApiError
        && (cause.status === 403 || cause.status === 404 || cause.status === 503))) {
        handleFailure(cause, 'Nie udało się wczytać najnowszej wersji danych.')
      } else {
        setFormError(cause instanceof Error
          ? `Nie udało się wczytać najnowszej wersji danych: ${cause.message} Lokalne zmiany zachowano.`
          : 'Nie udało się wczytać najnowszej wersji danych. Lokalne zmiany zachowano.')
      }
    } finally {
      controller.abort()
    }
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!values || !snapshot || phase !== 'editing' || operationLocked.current) return
    setAttempted(true)
    setFormError(null)
    setServerErrors({})
    if (unitsLoading || unitsError) {
      setFormError(unitsError ?? 'Ładowanie słownika jednostek.')
      return
    }
    const errors = validateRawMaterialCreate(values, true, unitOptions)
    if (snapshot.historicalPricingUnit && initialValues) {
      if (!historicalPriceConfirmed) {
        delete errors.pricingUnitCode
        const canonical = (value: string) => {
          const [integer, fraction = ''] = normalizeDecimalInput(value).split('.')
          return `${integer.replace(/^0+(?=\d)/, '')}.${fraction.replace(/0+$/, '')}`
        }
        const effectiveUsable = (v: RawMaterialCreateValues) => v.usableQuantityExplicit ? v.usableQuantity : v.nominalQuantity
        if (values.baseUnitCode !== initialValues.baseUnitCode
          || canonical(values.nominalQuantity) !== canonical(initialValues.nominalQuantity)
          || canonical(effectiveUsable(values)) !== canonical(effectiveUsable(initialValues))) {
          errors.pricingAmount = 'Cena pochodzi z wcześniejszej rewizji. Aby zmienić jednostkę lub ilości, wybierz i podaj cenę świadomie.'
        }
      } else {
        if (!normalizeDecimalInput(values.pricingAmount)) {
          errors.pricingAmount = 'Podaj nową cenę dla bieżącej rewizji.'
        }
        if (values.pricingBasis === 'NOMINAL_UNIT'
          && unitOptions.find((unit) => unit.code === values.pricingUnitCode)?.archivedAt !== null) {
          errors.pricingAmount = 'Wybierz cenę opakowania lub aktywną jednostkę ceny dla bieżącej rewizji.'
        }
      }
    }
    const allergensChanged = JSON.stringify(allergens) !== JSON.stringify(initialAllergens)
    const declarationError = allergensChanged ? allergenError(allergens, false) : null
    setAllergenValidation(declarationError)
    if (initialValues?.nominalQuantity && !values.nominalQuantity.trim() && !values.usableQuantity.trim()) {
      errors.nominalQuantity = 'Nie można wyczyścić istniejących ilości w tym kontrakcie. Podaj obie wartości.'
    }
    if (Object.keys(errors).length > 0 || declarationError) {
      setValidationErrors(errors)
      focusErrors(errors, declarationError)
      return
    }

    const expectedLocation = operatingLocationId
    const payload = { ...buildEditPayload(
      values,
      changeReason,
      canManageReferencePrice && canViewReferencePrice,
      canManageSuppliers && supplierOptionsLoaded,
      initialValues ?? values,
      unitOptions,
      documentUploadIds,
      detachDocumentVersionIds,
      snapshot.historicalPricingUnit === true,
      historicalPriceConfirmed,
    ),
      ...(allergensChanged ? { allergen_declaration: allergens } : {}) }
    operationLocked.current = true
    setPhase('authorizing')
    const controller = new AbortController()
    try {
      const [capabilities, completion] = await Promise.all([
        fetchRawMaterialCapabilities(expectedLocation, controller.signal),
        fetchRawMaterialCompletion(materialId, controller.signal),
      ])
      if (!active.current || locationRef.current !== expectedLocation) return
      if (capabilities.locationId === expectedLocation
        && (!capabilities.available || capabilities.readiness !== 'READY')) {
        setPhase('unavailable')
        setFormError('Moduł RAW_MATERIALS nie jest gotowy w tej lokalizacji.')
        callbacksRef.current.onModuleUnavailable()
        return
      }
      if (capabilities.locationId !== expectedLocation || !capabilities.actions.edit
        || !completion.availableActions.includes('edit') || !completion.version
        || (allergensChanged && !completion.availableActions.includes('edit_allergens'))
        || (payload.pricing && (!capabilities.actions.manageReferencePrice || !capabilities.actions.viewReferencePrice))
        || completion.revisionId !== snapshot.revisionId || completion.version !== snapshot.version) {
        setPhase('conflict')
        setFormError('Uprawnienie lub wersja edytora zmieniły się. Lokalne zmiany zachowano; wczytaj aktualny stan świadomie.')
        return
      }
      if (locationRef.current !== expectedLocation) return
      setPhase('saving')
      const result = await reviseRawMaterial(materialId, payload, snapshot.version)
      if (!active.current || locationRef.current !== expectedLocation) return
      setSaved(result)
      setSnapshot({ ...snapshot, lifecycleState: result.lifecycleState, revisionId: result.revision.id,
        revisionNo: result.revision.revisionNo, version: result.version })
      setPhase('saved')
      onSaved(result)
    } catch (cause) {
      if (!active.current || locationRef.current !== expectedLocation) return
      if (cause instanceof ApiError && cause.status === 409
        && cause.code === 'RAW_MATERIAL_VERSION_CONFLICT') {
        setPhase('conflict')
        setFormError('Surowiec został zmieniony przez inną operację. Nie nadpisano nowszych danych; lokalne zmiany zachowano.')
      } else if (cause instanceof ApiError && (cause.status === 428
        || (cause.status === 422 && cause.code === 'RAW_MATERIAL_VERSION_INVALID'))) {
        setPhase('conflict')
        setFormError('Wersja edycji jest nieprawidłowa lub wymagana. Wczytaj aktualny stan przed dalszą pracą.')
      } else if (cause instanceof ApiError && cause.status === 422) {
        const errors = serverFieldErrors(cause.errors)
        const allergenMessage = Object.entries(errors).find(([key]) =>
          ['allergen_declaration', 'status', 'catalog_version_id', 'items'].some((prefix) => key.startsWith(prefix)))?.[1] ?? null
        setServerErrors(errors)
        setAllergenValidation(allergenMessage)
        focusErrors(Object.fromEntries(Object.entries(errors).filter(([key]) =>
          !['allergen_declaration', 'status', 'catalog_version_id', 'items'].some((prefix) => key.startsWith(prefix)))), allergenMessage)
        setFormError(cause.message)
        setPhase('editing')
      } else if (cause instanceof Error && (!(cause instanceof ApiError) || cause.status >= 500)) {
        setPhase('uncertain')
        setFormError('Nie można potwierdzić wyniku zapisu. Nie wysłano ponownego PATCH; lokalne zmiany pozostają zachowane.')
        void readUncertainServerState()
      } else {
        handleFailure(cause, 'Nie udało się zapisać nowej rewizji.')
      }
    } finally {
      controller.abort()
      operationLocked.current = false
    }
  }

  function getError(field: string): string | undefined {
    return validationErrors[field] ?? serverErrors[field]
  }

  function fieldProps(field: string) {
    const message = getError(field)
    return {
      'aria-invalid': Boolean(message),
      ...(message ? { 'aria-describedby': `${field.replaceAll('.', '-')}-error` } : {}),
    }
  }

  return (
    <div className="create-form-backdrop edit-form-backdrop" onMouseDown={(event) => {
      if (event.target === event.currentTarget) closeForm()
    }}>
      <section
        ref={dialogRef}
        className="create-form-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="raw-edit-title"
        tabIndex={-1}
        onKeyDown={handleKeyDown}
      >
        <header className="create-form-header">
          <div>
            <p className="eyebrow">KATALOG SUROWCÓW</p>
            <h2 id="raw-edit-title">Edytuj surowiec</h2>
            <p>Zmiany zostaną zapisane jako szkic. Zatwierdzona wersja pozostanie bez zmian.</p>
          </div>
          <button type="button" className="button button-secondary" onClick={closeForm} disabled={isBusy(phase)}>
            Zamknij
          </button>
        </header>

        {phase === 'loading' && <p className="create-form-status" role="status">Sprawdzanie uprawnień i wczytywanie rewizji edytora…</p>}
        {phase === 'authorizing' && <p className="create-form-status" role="status">Ponowne sprawdzanie uprawnień i wersji…</p>}
        {phase === 'saving' && <p className="create-form-status" role="status">Zapisywanie nowej rewizji…</p>}
        {unitsError && <p className="create-form-error" role="alert">Nie można wczytać słownika jednostek: {unitsError}</p>}
        {formError && (phase === 'conflict' || phase === 'uncertain' || phase === 'error'
          || phase === 'forbidden' || phase === 'not-found' || phase === 'unavailable') && (
          <div className="create-form-notice" role="alert">
            <strong>{phase === 'conflict' ? 'Konflikt wersji' : phase === 'uncertain' ? 'Niepewny wynik zapisu'
              : phase === 'not-found' ? 'Surowiec niedostępny' : phase === 'forbidden' ? 'Brak dostępu'
                : phase === 'unavailable' ? 'Moduł niedostępny' : 'Nie można otworzyć edytora'}</strong>
            <p>{formError}</p>
            {phase === 'conflict' && (
              <button type="button" className="button button-primary" onClick={() => void loadCurrentEditor(true)}>
                Odrzuć lokalne zmiany i wczytaj aktualną wersję
              </button>
            )}
            {phase === 'uncertain' && (
              <>
                {uncertainRevision !== null && <p>W danych jest już rewizja v{uncertainRevision}; ten odczyt nie potwierdza wyniku zapisu.</p>}
                <button type="button" className="button button-primary" onClick={() => void loadCurrentEditor(true)}>
                  Świadomie wczytaj aktualny stan
                </button>
              </>
            )}
            {phase === 'error' && (
              <button type="button" className="button button-primary" onClick={() => void loadCurrentEditor(false)}>
                Spróbuj bezpiecznie wczytać edytor ponownie
              </button>
            )}
          </div>
        )}

        {phase === 'saved' && saved && (
          <section className="create-success" aria-labelledby="edit-success-title">
            <h3 id="edit-success-title">Zapisano nową rewizję roboczą v{saved.revision.revisionNo}</h3>
            <p>
              Aktualny stan surowca: <strong>{lifecycleLabel(saved.lifecycleState)}</strong>.
              Rewizja nie została automatycznie zatwierdzona ani opublikowana.
            </p>
            {saved.lifecycleState === 'ACTIVE' && (
              <p>Dotychczasowa rewizja zatwierdzona pozostaje obowiązująca do czasu osobnego zatwierdzenia nowej rewizji.</p>
            )}
            <dl className="detail-grid">
              <div><dt>SKU</dt><dd>{saved.sku}</dd></div>
            </dl>
            <div className="create-form-actions">
              <button type="button" className="button button-primary" onClick={onClose}>Wróć do szczegółów</button>
            </div>
          </section>
        )}

        {snapshot && values && phase !== 'loading' && phase !== 'saved'
          && phase !== 'forbidden' && phase !== 'not-found' && phase !== 'unavailable'
          && phase !== 'error' && (
          <>
            <section className="detail-section edit-revision-context" aria-label="Informacje o edytowanej rewizji">
              <p><strong>Wersja {snapshot.revisionNo}</strong></p>
              <dl className="detail-grid">
                <div><dt>SKU</dt><dd>{snapshot.sku}</dd></div>
              </dl>
            </section>

            {formError && phase === 'editing' && <p className="create-form-notice" role="status">{formError}</p>}

            <form onSubmit={(event) => void handleSubmit(event)} noValidate>
              {attempted && Object.keys(validationErrors).length > 0 && (
                <p className="create-form-error" role="alert">Popraw pola oznaczone poniżej.</p>
              )}
              <Tabs label="Formularz surowca" value={tab} onChange={setTab} tabs={[
                { id: 'basic', label: `Dane podstawowe${Object.keys({ ...validationErrors, ...serverErrors }).some((key) => ['categoryId', 'name', 'baseUnitCode', 'nominalQuantity', 'usableQuantity', 'pricingAmount', 'pricingUnitCode'].includes(key)) ? ' • błąd' : ''}`, content: (
              <fieldset disabled={phase !== 'editing'} className="create-form-fieldset">
                <legend>Dane podstawowe</legend>
                <div className="create-form-grid">
                  <label className="create-form-field" htmlFor="edit-category">
                    Kategoria <span className="required-mark">wymagane</span>
                    <select id="edit-category" value={values.categoryId} {...fieldProps('categoryId')}
                      onChange={(event) => setField('categoryId', event.target.value)}>
                      {categories.filter((category) => category.archivedAt == null || category.id === values.categoryId)
                        .map((category) => <option key={category.id} value={category.id}>
                          {category.name}{category.archivedAt != null ? ' (archiwalna — bieżące przypisanie)' : ''}
                        </option>)}
                    </select>
                    {getError('categoryId') && <span className="field-error" id="categoryId-error">{getError('categoryId')}</span>}
                  </label>
                  <label className="create-form-field" htmlFor="edit-name">
                    Nazwa <span className="required-mark">wymagane</span>
                    <input id="edit-name" value={values.name} maxLength={200} {...fieldProps('name')}
                      onChange={(event) => setField('name', event.target.value)} />
                    {getError('name') && <span className="field-error" id="name-error">{getError('name')}</span>}
                  </label>
                  <label className="create-form-field" htmlFor="edit-base-unit">
                    Jednostka podstawowa <span className="required-mark">wymagane</span>
                    <select id="edit-base-unit" value={values.baseUnitCode} {...fieldProps('baseUnitCode')}
                      onChange={(event) => setBaseUnit(event.target.value as RawMaterialCreateValues['baseUnitCode'])}>
                      {unitOptions.filter((unit) => unit.archivedAt === null || unit.code === values.baseUnitCode)
                        .map((unit) => <option key={unit.code} value={unit.code}>{unitLabel(unit.code, unitOptions)}{unit.archivedAt ? ' (archiwalna — bieżące przypisanie)' : ''}</option>)}
                    </select>
                    <span className="field-hint">To jednostka, w której zapisuje się ilości surowca. „szt.” oznacza jedną sztukę, nie opakowanie.</span>
                    {getError('baseUnitCode') && <span className="field-error" id="baseUnitCode-error">{getError('baseUnitCode')}</span>}
                  </label>
                  <fieldset className="create-form-fieldset create-quantity-group">
                    <legend>Ilości</legend>
                    <div className="create-form-grid create-form-grid-inline">
                      <label htmlFor="edit-nominal-quantity">Nominalna</label>
                      <input id="edit-nominal-quantity" inputMode="decimal" value={values.nominalQuantity}
                        {...fieldProps('nominalQuantity')}
                        onChange={(event) => setNominalQuantity(event.target.value)} />
                      <label htmlFor="edit-usable-quantity">Użytkowa</label>
                      <input id="edit-usable-quantity" inputMode="decimal" value={values.usableQuantity}
                        {...fieldProps('usableQuantity')}
                        onChange={(event) => setUsableQuantity(event.target.value)} />
                    </div>
                    {(getError('nominalQuantity') || getError('usableQuantity')) && (
                      <span className="field-error" id="nominalQuantity-error">
                        {getError('nominalQuantity') ?? getError('usableQuantity')}
                      </span>
                    )}
                    <span className="field-hint">Pozostaw ilość użytkową pustą, aby automatycznie użyć nominalnej. Maksymalnie 6 miejsc po przecinku.</span>
                  </fieldset>
                  {canViewReferencePrice && (<>
                    {snapshot.historicalPricingUnit && <p className="field-hint">
                      Cena zachowuje jednostkę z wcześniejszej rewizji. Zmiana ilości lub jednostki wymaga świadomego podania ceny dla bieżącej rewizji.
                    </p>}
                    <RawMaterialPricingFields id="edit" values={values}
                      disabled={phase !== 'editing' || !canManageReferencePrice || unitsLoading || Boolean(unitsError)}
                      canCalculate={canManageReferencePrice && (!snapshot.historicalPricingUnit || historicalPriceConfirmed)} canViewCost={canViewUsableCost}
                      error={getError('pricingAmount')} unitOptions={unitOptions}
                      onBasisChange={(value) => {
                        setHistoricalPriceConfirmed(true)
                        if (snapshot.historicalPricingUnit && value !== values.pricingBasis) {
                          setField('pricingAmount', '')
                        }
                        setField('pricingBasis', value)
                      }}
                      onAmountChange={(value) => { setHistoricalPriceConfirmed(true); setField('pricingAmount', value) }}
                      onSessionExpired={onSessionExpired} onAuthorizationDenied={onAuthorizationDenied}
                      onModuleUnavailable={onModuleUnavailable} />
                  </>)}
                  {canManageSuppliers && (
                    <label className="create-form-field create-form-field-wide" htmlFor="edit-suppliers">
                      Dostawcy — powiązania informacyjne
                      {supplierOptionsLoaded ? (
                        <select id="edit-suppliers" multiple value={values.supplierIds} disabled={phase !== 'editing'}
                          onChange={(event) => setField('supplierIds',
                            Array.from(event.currentTarget.selectedOptions, (option) => option.value))}>
                          {supplierOptions.map((supplier) => (
                            <option key={supplier.id} value={supplier.id}>{supplierLabel(supplier)}</option>
                          ))}
                        </select>
                      ) : (
                        <span className="field-hint">Biblioteka dostawców jest niedostępna; obecne powiązania pozostaną bez zmian.</span>
                      )}
                      <span className="field-hint">Powiązanie nie wyznacza ceny i nie pobiera ofert.</span>
                      {supplierError && <span className="field-error" role="alert">{supplierError}</span>}
                    </label>
                  )}
                </div>
              </fieldset>
                ) },
                { id: 'allergens', label: `Alergeny${allergenValidation ? ' • błąd' : ''}`, content: (
                  <AllergenFields value={allergens} permitted={canEditAllergens} disabled={phase !== 'editing'} error={allergenValidation}
                    onChange={(value) => { setAllergens(value); setAllergenValidation(null) }}
                    onSessionExpired={onSessionExpired} onModuleUnavailable={onModuleUnavailable} />
                ) },
                { id: 'additional', label: `Dodatkowe informacje${Object.keys({ ...validationErrors, ...serverErrors }).some((key) => key === 'description' || key.startsWith('aliases') || key.startsWith('conversions')) ? ' • błąd' : ''}`, content: (<>
                <div className="create-advanced-content">
                  <label className="create-form-field create-form-field-wide" htmlFor="edit-description">
                    Opis
                    <textarea id="edit-description" value={values.description} rows={3} maxLength={10000}
                      disabled={phase !== 'editing'} {...fieldProps('description')}
                      onChange={(event) => setField('description', event.target.value)} />
                    {getError('description') && <span className="field-error" id="description-error">{getError('description')}</span>}
                    <span className="field-hint">Pozostawienie pustego pola jawnie wyczyści opis.</span>
                  </label>
                  <section aria-labelledby="edit-aliases-title">
                    <div className="create-subsection-heading">
                      <div><h3 id="edit-aliases-title">Inne nazwy surowca</h3><p>Dodaj nazwy, pod którymi pracownicy mogą szukać tego samego surowca. Nie utworzą one osobnego surowca. Lista zawiera wszystkie zapisane nazwy; usunięcie nazwy usunie ją z surowca.</p></div>
                      <button type="button" className="button button-secondary" disabled={phase !== 'editing' || values.aliases.length >= 50}
                        onClick={() => setField('aliases', [...values.aliases, ''])}>Dodaj nazwę</button>
                    </div>
                    {values.aliases.map((alias, index) => (
                      <div className="create-repeat-row" key={`alias-${index}`}>
                        <label className="create-form-field" htmlFor={`edit-alias-${index}`}>Inna nazwa {index + 1}
                          <input id={`edit-alias-${index}`} value={alias} maxLength={200} {...fieldProps(`aliases.${index}`)}
                            onChange={(event) => updateAlias(index, event.target.value)} />
                          {getError(`aliases.${index}`) && <span className="field-error">{getError(`aliases.${index}`)}</span>}
                        </label>
                        <button type="button" className="button button-secondary" aria-label={`Usuń nazwę ${index + 1}`}
                          disabled={phase !== 'editing'} onClick={() => setField('aliases', values.aliases.filter((_, itemIndex) => itemIndex !== index))}>
                          Usuń
                        </button>
                      </div>
                    ))}
                    {getError('aliases') && <p className="field-error">{getError('aliases')}</p>}
                    {values.aliases.length === 0 && <p className="field-hint">Brak innych nazw surowca.</p>}
                  </section>

                  <section aria-labelledby="edit-conversions-title">
                    <div className="create-subsection-heading">
                      <div>
                        <h3 id="edit-conversions-title">Dodatkowe jednostki miary</h3>
                        <p>
                          Jednostka podstawowa: {unitLabel(values.baseUnitCode, unitOptions)}. Przelicznik mówi, ile jednostek podstawowych
                          odpowiada jednej dodatkowej jednostce, np. 1 opakowanie = 250 g. Dla jednostek tego samego rodzaju
                          przeliczenie jest ustawiane automatycznie. Przy przeliczaniu między masą, objętością i sztukami podaj
                          przelicznik oraz opisz, czego dotyczy. Zapisz wszystkie pozycje, które mają pozostać przy surowcu.
                        </p>
                      </div>
                      <button type="button" className="button button-secondary" disabled={phase !== 'editing' || values.conversions.length >= 20}
                        onClick={() => setField('conversions', [...values.conversions, { unitCode: '', toBaseFactor: '', basis: '' }])}>
                        Dodaj jednostkę
                      </button>
                    </div>
                    {values.conversions.map((conversion, index) => {
                      const unit = conversion.unitCode
                      const automatic = unitOptions.some((candidate) => candidate.code === unit)
                        && unitDimension(unit, unitOptions) === unitDimension(values.baseUnitCode, unitOptions)
                      const factorField = `conversions.${index}.toBaseFactor`
                      const basisField = `conversions.${index}.basis`
                      const factorHintId = `edit-conversion-${index}-factor-hint`
                      const basisHintId = `edit-conversion-${index}-basis-hint`
                      const factorErrorId = `${factorField.replaceAll('.', '-')}-error`
                      const basisErrorId = `${basisField.replaceAll('.', '-')}-error`
                      return (
                        <div className={`create-conversion-row${automatic ? ' is-automatic' : ''}`} key={`conversion-${index}`}>
                          <label className="create-form-field create-conversion-unit" htmlFor={`edit-conversion-unit-${index}`}>Dodatkowa jednostka miary {index + 1}
                            <select id={`edit-conversion-unit-${index}`} value={conversion.unitCode}
                              {...fieldProps(`conversions.${index}.unitCode`)}
                              onChange={(event) => updateConversion(index, { unitCode: event.target.value as RawMaterialConversionInput['unitCode'] })}>
                              <option value="">Wybierz jednostkę</option>
                              {unitOptions.filter((item) => item.code !== values.baseUnitCode
                                && (item.archivedAt === null || item.code === conversion.unitCode)).map((item) =>
                                <option key={item.code} value={item.code}>{unitLabel(item.code, unitOptions)}{item.archivedAt ? ' (archiwalna — bieżące przypisanie)' : ''}</option>)}
                            </select>
                            {getError(`conversions.${index}.unitCode`) && <span className="field-error">{getError(`conversions.${index}.unitCode`)}</span>}
                          </label>
                          {automatic ? (
                            <p className="field-hint create-conversion-auto">Przeliczenie jednostek tego samego rodzaju jest ustawiane automatycznie.</p>
                          ) : (
                            <>
                              <div className="create-form-field create-conversion-factor">
                                <label htmlFor={`edit-conversion-factor-${index}`}>Przelicznik</label>
                                <input id={`edit-conversion-factor-${index}`} inputMode="decimal" value={conversion.toBaseFactor}
                                  {...fieldProps(`conversions.${index}.toBaseFactor`)}
                                  aria-describedby={[factorHintId, getError(factorField) ? factorErrorId : null].filter(Boolean).join(' ')}
                                  onChange={(event) => updateConversion(index, { toBaseFactor: event.target.value })} />
                                <span id={factorHintId} className="field-hint">
                                  {conversionFactorHint(conversion.unitCode, values.baseUnitCode, conversion.toBaseFactor, unitOptions)}
                                </span>
                                {getError(factorField) && <span className="field-error" id={factorErrorId}>{getError(factorField)}</span>}
                              </div>
                              <div className="create-form-field create-conversion-basis">
                                <label htmlFor={`edit-conversion-basis-${index}`}>Opis przeliczenia</label>
                                <input id={`edit-conversion-basis-${index}`} value={conversion.basis} maxLength={500}
                                  {...fieldProps(`conversions.${index}.basis`)}
                                  aria-describedby={[basisHintId, getError(basisField) ? basisErrorId : null].filter(Boolean).join(' ')}
                                  onChange={(event) => updateConversion(index, { basis: event.target.value })} />
                                <span id={basisHintId} className="field-hint">
                                  {conversionBasisHint(conversion.unitCode, values.baseUnitCode, conversion.toBaseFactor, unitOptions)}
                                </span>
                                {getError(basisField) && <span className="field-error" id={basisErrorId}>{getError(basisField)}</span>}
                              </div>
                            </>
                          )}
                          <button type="button" className="button button-secondary" aria-label={`Usuń jednostkę przeliczeniową ${index + 1}`}
                            disabled={phase !== 'editing'} onClick={() => setField('conversions', values.conversions.filter((_, itemIndex) => itemIndex !== index))}>
                            Usuń
                          </button>
                        </div>
                      )
                    })}
                    {getError('conversions') && <p className="field-error">{getError('conversions')}</p>}
                    {values.conversions.length === 0 && <p className="field-hint">Brak dodatkowych jednostek.</p>}
                  </section>
                </div>
              <label className="create-form-field create-form-field-wide" htmlFor="edit-change-reason">
                Powód zmiany (opcjonalnie)
                <textarea id="edit-change-reason" value={changeReason} maxLength={500} rows={2}
                  onChange={(event) => setChangeReason(event.target.value)} />
              </label>
                </>) },
              ]} />
              <RawMaterialDocumentsSection
                key={`${materialId}:${operatingLocationId}`}
                materialId={materialId}
                operatingLocationId={operatingLocationId}
                view="editor"
                mode="edit"
                detachIds={detachDocumentVersionIds}
                onUploadIdsChange={setDocumentUploadIds}
                onDetachIdsChange={setDetachDocumentVersionIds}
                onBusyChange={setDocumentsUploading}
                disabled={phase !== 'editing'}
                onSessionExpired={onSessionExpired}
                onAuthorizationDenied={onAuthorizationDenied}
                onModuleUnavailable={onModuleUnavailable}
              />

              <div className="create-form-footer">
                <p>Zapis utworzy wersję roboczą. Przechowywanie i dokumenty zmienia się w osobnych sekcjach.</p>
                <div className="create-form-actions">
                  <button type="button" className="button button-secondary" onClick={closeForm} disabled={isBusy(phase)}>Anuluj</button>
                  <button type="submit" className="button button-primary" disabled={phase !== 'editing' || documentsUploading}>
                    Zapisz nową rewizję roboczą
                  </button>
                </div>
              </div>
            </form>

            {phase === 'uncertain' && uncertainRevision !== null && (
              <p className="create-form-status" role="status">Odczytano rewizję v{uncertainRevision}; wynik PATCH pozostaje niepewny.</p>
            )}
          </>
        )}
      </section>
    </div>
  )
}
