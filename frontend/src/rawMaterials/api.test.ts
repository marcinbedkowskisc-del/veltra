import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api'
import {
  fetchRawMaterialCapabilities,
  fetchRawMaterialCompletion,
  fetchRawMaterialEditor,
  reviseRawMaterial,
  changeRawMaterialLifecycle,
  fetchAllergenCatalogs,
  fetchAllergenDeclaration,
  saveAllergenDeclaration,
  fetchCatalog,
  createCategory,
  renameCategory,
} from './api'

const locationId = '00000000-0000-4000-8000-000000000101'
const materialId = '00000000-0000-4000-8000-000000000201'
const revisionId = '00000000-0000-4000-8000-000000000202'
const version = 'opaque-editor-version'
const editorData = {
  id: materialId,
  code: 'RAW-000201',
  sku: 'RAW-000201',
  lifecycle_state: 'ACTIVE',
  version,
  revision: {
    id: revisionId,
    revision_no: 4,
    name: 'Mąka pszenna',
    description: 'Opis',
    category_id: '00000000-0000-4000-8000-000000000203',
    base_unit_code: 'kg',
    nominal_quantity: '10.000000',
    usable_quantity: '8.000000',
    units: [
      { unit_code: 'kg', dimension: 'MASS', to_base_factor: '1.000000', basis: null },
      { unit_code: 'g', dimension: 'MASS', to_base_factor: '0.001000', basis: null },
      { unit_code: 'pc', dimension: 'COUNT', to_base_factor: '2.500000', basis: '1 sztuka' },
    ],
    aliases: ['Mąka pszenna', 'mąka do wypieków'],
  },
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('kontrakt cyklu zatwierdzania i alergenów', () => {
  it('pobiera workbench niezależnie od zatwierdzonego katalogu i sprawdza status rewizji', async () => {
    const data = {
      id: materialId, sku: 'RAW-000201', ordinal: 1, lifecycle_state: 'ACTIVE', workflow_state: 'VERIFICATION',
      nominal_quantity: '10', usable_quantity: '8',
      revision: { name: 'Oczekująca rewizja', base_unit_code: 'kg' },
    }
    const envelope = {
      data: [data],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1, from: 1, to: 1 },
    }
    const fetchMock = vi.fn().mockImplementation(async () => new Response(JSON.stringify(envelope)))
    vi.stubGlobal('fetch', fetchMock)
    const query = {
      view: 'workbench' as const, search: 'Oczekująca', categoryId: '', supplierId: '', lifecycleState: 'VERIFICATION',
      unit: '', sort: 'name' as const, direction: 'asc' as const, page: 1, perPage: 25 as const,
    }
    await expect(fetchCatalog(query)).resolves.toMatchObject({
      rows: [{ lifecycleState: 'ACTIVE', workflowState: 'VERIFICATION' }],
      canReadReferencePrice: false, canReadUsableCost: false,
    })
    expect(fetchMock.mock.calls[0][0]).toContain('/api/v1/raw-materials/workbench?')
    Reflect.deleteProperty(data, 'workflow_state')
    await expect(fetchCatalog(query)).rejects.toThrow('Backend nie potwierdził statusu')
  })
  it.each(['submit', 'approve'] as const)('wysyła %s z If-Match, bez generowania wersji po stronie klienta', async (action) => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: { id: materialId, sku: 'RAW-000201', lifecycle_state: action === 'submit' ? 'VERIFICATION' : 'ACTIVE', version: 'next-version' },
    })))
    vi.stubGlobal('fetch', fetchMock)
    await changeRawMaterialLifecycle(materialId, action, version, 'Jawna akceptacja')
    expect(fetchMock).toHaveBeenCalledWith(`/api/v1/raw-materials/${materialId}/${action}`, expect.objectContaining({
      method: 'POST', headers: expect.objectContaining({ 'If-Match': version }),
      body: JSON.stringify(action === 'approve' ? { reason: 'Jawna akceptacja' } : {}),
    }))
  })

  it('odrzuca nieprawidłowe potwierdzenie zapisu zamiast zgłaszać sukces', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: materialId } }))))
    await expect(changeRawMaterialLifecycle(materialId, 'submit', version, '')).rejects.toThrow('Nie można potwierdzić')
  })

  it('odczytuje opublikowany katalog i aktualną deklarację, zapisując ją z If-Match', async () => {
    const catalogId = '00000000-0000-4000-8000-000000000209'
    const declaration = { status: 'NO_KNOWN_ALLERGENS' as const, catalog_version_id: catalogId, items: [] }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{
        id: catalogId, version_number: 1, definitions: [{ allergen_code: 'TEST_WHEAT', name_pl: 'TEST — Pszenica' }],
      }] })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: materialId, version, allergen_declaration: declaration } })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: materialId, version: 'next-version' } })))
    vi.stubGlobal('fetch', fetchMock)
    await expect(fetchAllergenCatalogs()).resolves.toEqual([{
      id: catalogId, versionNumber: 1, definitions: [{ code: 'TEST_WHEAT', name: 'TEST — Pszenica' }],
    }])
    await expect(fetchAllergenDeclaration(materialId)).resolves.toEqual({ version, declaration })
    await saveAllergenDeclaration(materialId, declaration, version)
    expect(fetchMock).toHaveBeenLastCalledWith(`/api/v1/raw-materials/${materialId}/draft/allergen-declaration`, expect.objectContaining({
      method: 'PUT', body: JSON.stringify(declaration), headers: expect.objectContaining({ 'If-Match': version }),
    }))
  })
})

describe('fetchRawMaterialCapabilities', () => {
  it('parsuje odpowiedź dla oczekiwanej lokalizacji i wysyła żądanie bez cache', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: {
        module: 'RAW_MATERIALS',
        location_id: locationId,
        readiness: 'READY',
        available: true,
        permissions: {
          create: true, edit: true, manage_categories: true,
          view_reference_price: false, manage_reference_price: false, manage_suppliers: false,
        },
        actions: {
          create: true, edit: true, manage_categories: true, edit_allergens: true, create_and_approve: true,
          view_reference_price: false, view_usable_cost: true, manage_reference_price: false, manage_suppliers: false,
        },
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(fetchRawMaterialCapabilities(locationId)).resolves.toEqual({
      module: 'RAW_MATERIALS',
      locationId,
      readiness: 'READY',
      available: true,
      permissions: {
        create: true, edit: true, manageCategories: true, viewReferencePrice: false, manageReferencePrice: false, manageSuppliers: false,
      },
      actions: {
        create: true, edit: true, manageCategories: true, editAllergens: true, createAndApprove: true,
        viewReferencePrice: false, viewUsableCost: true, manageReferencePrice: false, manageSuppliers: false,
      },
    })
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/security/raw-materials/capabilities',
      expect.objectContaining({ method: 'GET', cache: 'no-store', credentials: 'same-origin' }),
    )
  })

  it('zachowuje rozdzielenie EDIT od CREATE i readiness', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: {
        module: 'RAW_MATERIALS',
        location_id: locationId,
        readiness: 'NOT_READY',
        available: false,
        permissions: { create: false, edit: true },
        actions: { create: false, edit: false, edit_allergens: false, create_and_approve: false },
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } })))

    await expect(fetchRawMaterialCapabilities(locationId)).resolves.toMatchObject({
      readiness: 'NOT_READY',
      available: false,
      permissions: { create: false, edit: true },
      actions: { create: false, edit: false, editAllergens: false, createAndApprove: false },
    })
  })

  it('odrzuca akcję kategorii bez jej grantu', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: {
        module: 'RAW_MATERIALS', location_id: locationId, readiness: 'READY', available: true,
        permissions: { create: false, edit: false, manage_categories: false },
        actions: { create: false, edit: false, manage_categories: true, edit_allergens: false, create_and_approve: false },
      },
    }))))
    await expect(fetchRawMaterialCapabilities(locationId)).rejects.toThrow('nieprawidłowe uprawnienia')
  })

  describe('zarządzanie kategoriami', () => {
    it('wysyła tworzenie i zmianę nazwy przez istniejący kontrakt', async () => {
      const category = { id: 'category-1', code: 'MAKA', name: 'Mąka', depth: 1 }
      const fetchMock = vi.fn().mockImplementation(async () => new Response(JSON.stringify({ data: category })))
      vi.stubGlobal('fetch', fetchMock)
      await expect(createCategory({ code: 'MAKA', name: 'Mąka' })).resolves.toEqual(category)
      expect(fetchMock).toHaveBeenLastCalledWith('/api/v1/raw-materials/categories', expect.objectContaining({
        method: 'POST', body: JSON.stringify({ code: 'MAKA', name: 'Mąka' }),
      }))
      await renameCategory('category-1', 'Mąki')
      expect(fetchMock).toHaveBeenLastCalledWith('/api/v1/raw-materials/categories/category-1', expect.objectContaining({
        method: 'PATCH', body: JSON.stringify({ name: 'Mąki' }),
      }))
    })
  })

  it.each([
    ['inna lokalizacja', { location_id: '00000000-0000-4000-8000-000000000102' }],
    ['akcja przy niedostępnym module', { readiness: 'NOT_READY', available: false, actions: { create: true } }],
    ['nieznany moduł', { module: 'OTHER_MODULE' }],
  ])('odrzuca niespójną odpowiedź: %s', async (_label, override) => {
    const value = {
      module: 'RAW_MATERIALS',
      location_id: locationId,
      readiness: 'READY',
      available: true,
      permissions: { create: true, edit: true },
      actions: { create: true, edit: true, edit_allergens: true, create_and_approve: true },
      ...override,
    }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: value }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })))
    await expect(fetchRawMaterialCapabilities(locationId)).rejects.toThrow(
      'Backend zwrócił nieprawidłowe uprawnienia katalogu surowców.',
    )
  })

  it.each([
    ['brak permissions.edit', { permissions: { create: true } }],
    ['brak actions.edit', { actions: { create: true } }],
    ['akcja EDIT bez grantu', { permissions: { create: true, edit: false } }],
    ['akcja EDIT przy NOT_READY', {
      readiness: 'NOT_READY',
      available: false,
      actions: { create: false, edit: true },
    }],
  ])('odrzuca niekompletną lub niedozwoloną capability EDIT: %s', async (_label, override) => {
    const value = {
      module: 'RAW_MATERIALS',
      location_id: locationId,
      readiness: 'READY',
      available: true,
      permissions: { create: true, edit: true },
      actions: { create: true, edit: true },
      ...override,
    }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: value }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })))
    await expect(fetchRawMaterialCapabilities(locationId)).rejects.toThrow(
      'Backend zwrócił nieprawidłowe uprawnienia katalogu surowców.',
    )
  })

  it('zachowuje semantykę błędu modułu niedostępnego', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      error: { code: 'RAW_MATERIALS_MODULE_UNAVAILABLE', message: 'Moduł RAW_MATERIALS jest niedostępny.' },
    }), { status: 503, headers: { 'Content-Type': 'application/json' } })))
    const error = await fetchRawMaterialCapabilities(locationId).catch((cause: unknown) => cause)
    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 503, code: 'RAW_MATERIALS_MODULE_UNAVAILABLE' })
  })
})

describe('kontrakt edycji surowca', () => {
  it('parsuje completion per rekord i fail-closed odrzuca błędne akcje', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: {
        lifecycle_state: 'ACTIVE',
        revision_id: revisionId,
        version,
        available_actions: ['edit', 'submit'],
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    await expect(fetchRawMaterialCompletion(materialId)).resolves.toEqual({
      lifecycleState: 'ACTIVE',
      revisionId,
      version,
      availableActions: ['edit', 'submit'],
    })

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: { lifecycle_state: 'ACTIVE', revision_id: revisionId, version, available_actions: 'edit' },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    await expect(fetchRawMaterialCompletion(materialId)).rejects.toThrow('nieprawidłową ocenę')
  })

  it('wczytuje wyłącznie kompletną rewizję edytora, usuwa alias głównej nazwy i zachowuje wersję bez zmian', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: editorData }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)
    await expect(fetchRawMaterialEditor(materialId)).resolves.toEqual({
      id: materialId,
      sku: 'RAW-000201',
      lifecycleState: 'ACTIVE',
      revisionId,
      revisionNo: 4,
      version,
      values: {
        categoryId: '00000000-0000-4000-8000-000000000203',
        name: 'Mąka pszenna',
        description: 'Opis',
        baseUnitCode: 'kg',
        nominalQuantity: '10.000000',
        usableQuantity: '8.000000',
        usableQuantityExplicit: true,
        pricingBasis: 'PACKAGE',
        pricingAmount: '',
        pricingUnitCode: 'kg',
        supplierIds: [],
        aliases: ['mąka do wypieków'],
        conversions: [
          { unitCode: 'g', toBaseFactor: '0.001000', basis: '' },
          { unitCode: 'pc', toBaseFactor: '2.500000', basis: '1 sztuka' },
        ],
      },
    })
    expect(fetchMock).toHaveBeenCalledWith(
      `/api/v1/raw-materials/${materialId}/editor`,
      expect.objectContaining({ method: 'GET', cache: 'no-store' }),
    )
  })

  it.each([
    ['inna tożsamość materiału', { id: '00000000-0000-4000-8000-000000000299' }],
    ['brak tokena', { version: '' }],
    ['brak aliasów', { revision: { ...editorData.revision, aliases: undefined } }],
    ['brak konwersji', { revision: { ...editorData.revision, units: undefined } }],
    ['niedozwolona jednostka', {
      revision: { ...editorData.revision, units: [...editorData.revision.units, {
        unit_code: 'other', dimension: 'MASS', to_base_factor: '1', basis: null,
      }] },
    }],
  ])('odrzuca niekompletny edytor: %s', async (_label, override) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      data: { ...editorData, ...override },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    await expect(fetchRawMaterialEditor(materialId)).rejects.toThrow('niepełne lub nieprawidłowe')
  })

  it('wysyła pełny payload PATCH i nieprzezroczysty token If-Match', async () => {
    const revised = {
      id: materialId,
      sku: 'RAW-000201',
      lifecycle_state: 'ACTIVE',
      version: 'next-opaque-version',
      revision: { id: revisionId, revision_no: 5 },
    }
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: revised }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)
    const payload = {
      category_id: '00000000-0000-4000-8000-000000000203',
      name: 'Mąka pszenna',
      description: null,
      base_unit_code: 'kg' as const,
      nominal_quantity: '10',
      usable_quantity: '8',
      aliases: [],
      conversions: [],
    }
    await expect(reviseRawMaterial(materialId, payload, version)).resolves.toMatchObject({
      id: materialId,
      lifecycleState: 'ACTIVE',
      revision: { revisionNo: 5 },
    })
    expect(fetchMock).toHaveBeenCalledWith(
      `/api/v1/raw-materials/${materialId}/draft`,
      expect.objectContaining({
        method: 'PATCH',
        cache: 'no-store',
        headers: expect.objectContaining({ 'If-Match': version }),
        body: JSON.stringify(payload),
      }),
    )
  })
})
