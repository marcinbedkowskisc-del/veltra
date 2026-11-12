import { afterEach, describe, expect, it, vi } from 'vitest'
import { installVisualPreviewApi } from './visualPreviewApi'

const originalFetch = window.fetch

afterEach(() => {
  window.fetch = originalFetch
  vi.restoreAllMocks()
})

async function json(response: Response): Promise<Record<string, unknown>> {
  return await response.json() as Record<string, unknown>
}

describe('isolated RAW preview API', () => {
  it('uses consistent six-digit RAW identifiers for catalog, detail, export and reservations', async () => {
    const preview = installVisualPreviewApi('authorized')
    const catalog = await json(await window.fetch('/api/v1/raw-materials?sort=sku&page=1&per_page=50'))
    const rows = catalog.data as Array<Record<string, string>>
    const skus = rows.map((row) => row.sku)
    expect(skus).toHaveLength(32)
    expect(skus.every((sku) => /^RAW-\d{6}$/.test(sku))).toBe(true)
    expect(new Set(skus).size).toBe(skus.length)
    expect(skus).toContain('RAW-000027')

    const detail = await json(await window.fetch(`/api/v1/raw-materials/${rows[0].id}`))
    expect(detail.data).toMatchObject({ sku: rows[0].sku, code: rows[0].sku })
    const exportResponse = await window.fetch('/api/v1/raw-materials/catalog-exports/selection/DEMO-EXPORT/download')
    expect(await exportResponse.text()).toContain('RAW-000001,Mąka pszenna typ 550')

    const reservationResponse = await json(await window.fetch('/api/v1/raw-materials/sku-reservations', {
      method: 'POST',
      headers: { 'Idempotency-Key': 'preview-sku-check' },
    }))
    const reservation = reservationResponse.data as Record<string, string>
    expect(reservation.sku).toMatch(/^RAW-\d{6}$/)
    expect(skus).not.toContain(reservation.sku)
    preview.reset()
  })

  it('serves synthetic lifecycle examples and correctly shaped access scenarios', async () => {
    const preview = installVisualPreviewApi('authorized')
    const catalog = await json(await window.fetch('/api/v1/raw-materials?sort=name&direction=asc&page=1&per_page=50'))
    const prices = await json(await window.fetch('/api/v1/raw-materials/00000000-0000-4000-8000-000000000002/reference-prices'))
    const rows = catalog.data as Array<Record<string, unknown>>
    expect(prices.data).toHaveLength(4)
    expect((prices.data as Array<Record<string, unknown>>)[0]).toMatchObject({ currency_code: 'PLN' })
    expect(rows.map((row) => row.lifecycle_state)).toContain('DRAFT')
    expect(rows.map((row) => row.lifecycle_state)).toContain('ACTIVE')
    expect(rows.map((row) => row.lifecycle_state)).toContain('VERIFICATION')
    expect(rows.map((row) => row.lifecycle_state)).toContain('ARCHIVED')
    for (const id of [
      '00000000-0000-4000-8000-000000000004',
      '00000000-0000-4000-8000-000000000005',
    ]) {
      const completion = await json(await window.fetch(`/api/v1/raw-materials/${id}/completion`))
      expect((completion.data as Record<string, unknown>).available_actions).toEqual([])
    }

    preview.setMode('record-unavailable')
    const recordUnavailable = await json(await window.fetch('/api/v1/security/raw-materials/capabilities'))
    const draftCompletion = await json(await window.fetch('/api/v1/raw-materials/00000000-0000-4000-8000-000000000001/completion'))
    expect(recordUnavailable.data).toMatchObject({ actions: { edit: true } })
    expect((draftCompletion.data as Record<string, unknown>).available_actions).toEqual([])

    preview.setMode('view-only')
    const viewOnly = await json(await window.fetch('/api/v1/security/raw-materials/capabilities'))
    const restrictedPrices = await window.fetch('/api/v1/raw-materials/00000000-0000-4000-8000-000000000002/reference-prices')
    expect(restrictedPrices.status).toBe(403)
    expect(viewOnly.data).toMatchObject({
      readiness: 'READY',
      permissions: { create: false, edit: false },
      actions: { create: false, edit: false },
    })

    preview.setMode('no-edit')
    const noEdit = await json(await window.fetch('/api/v1/security/raw-materials/capabilities'))
    expect(noEdit.data).toMatchObject({
      permissions: { create: true, edit: false },
      actions: { create: true, edit: false },
    })

    preview.setMode('not-ready')
    const notReady = await json(await window.fetch('/api/v1/security/raw-materials/capabilities'))
    expect(notReady.data).toMatchObject({
      readiness: 'NOT_READY',
      available: false,
      permissions: { create: true, edit: true },
      actions: { create: false, edit: false },
    })
    preview.reset()
  })

  it('blocks off-origin and unknown requests without forwarding them', async () => {
    const realFetch = vi.fn()
    window.fetch = realFetch as typeof window.fetch
    const preview = installVisualPreviewApi('authorized')

    const external = await window.fetch('https://example.invalid/api/v1/raw-materials', { method: 'POST' })
    const backend = await window.fetch('http://127.0.0.1:8000/api/v1/raw-materials', { method: 'POST' })
    const unknown = await window.fetch('/api/v1/unknown')

    expect(external.status).toBe(403)
    expect(backend.status).toBe(403)
    expect(unknown.status).toBe(404)
    expect(await json(external)).toMatchObject({ error: { code: 'DEMO_REQUEST_BLOCKED' } })
    expect(await json(backend)).toMatchObject({ error: { code: 'DEMO_REQUEST_BLOCKED' } })
    expect(await json(unknown)).toMatchObject({ error: { code: 'DEMO_REQUEST_NOT_SUPPORTED' } })
    expect(realFetch).not.toHaveBeenCalled()
    expect(preview.getStats()).toMatchObject({ requests: 3, blocked: 3 })
  })

  it('simulates creation in memory and removes it when demo data is reset', async () => {
    const preview = installVisualPreviewApi('authorized')
    const reservation = await json(await window.fetch('/api/v1/raw-materials/sku-reservations', {
      method: 'POST',
      headers: { 'Idempotency-Key': 'preview-create-001' },
    }))
    const reservationData = reservation.data as Record<string, string>
    const created = await json(await window.fetch('/api/v1/raw-materials', {
      method: 'POST',
      headers: { 'Idempotency-Key': 'preview-create-001' },
      body: JSON.stringify({
        sku_reservation_id: reservationData.reservation_id,
        category_id: '00000000-0000-4000-8000-000000000201',
        name: 'Fasola demo utworzona lokalnie',
        base_unit_code: 'kg',
        description: 'Dane tylko na czas sesji.',
        aliases: ['fasola demonstracyjna'],
        conversions: [{ unit_code: 'pc', to_base_factor: '2.000000', basis: '1 opakowanie' }],
      }),
    }))

    expect(created.data).toMatchObject({ sku: 'RAW-000042', lifecycle_state: 'DRAFT' })
    const withCreated = await json(await window.fetch('/api/v1/raw-materials?search=Fasola%20demo%20utworzona%20lokalnie&page=1&per_page=25'))
    expect(withCreated.data).toHaveLength(1)
    const completeCatalog = await json(await window.fetch('/api/v1/raw-materials?page=1&per_page=50'))
    const allRows = completeCatalog.data as Array<Record<string, unknown>>
    const ids = allRows.map((row) => row.id)
    expect(new Set(ids).size).toBe(ids.length)

    preview.reset()
    const afterReset = await json(await window.fetch('/api/v1/raw-materials?search=Fasola%20demo%20utworzona%20lokalnie&page=1&per_page=25'))
    expect(afterReset.data).toHaveLength(0)
  })

  it('creates a new active working revision and preserves the approved revision', async () => {
    const preview = installVisualPreviewApi('authorized')
    const materialId = '00000000-0000-4000-8000-000000000002'
    const editor = await json(await window.fetch(`/api/v1/raw-materials/${materialId}/editor`))
    const editorData = editor.data as Record<string, unknown>
    const revision = editorData.revision as Record<string, unknown>
    const patch = await json(await window.fetch(`/api/v1/raw-materials/${materialId}/draft`, {
      method: 'PATCH',
      headers: { 'If-Match': String(editorData.version) },
      body: JSON.stringify({
        category_id: '00000000-0000-4000-8000-000000000201',
        name: 'Mąka żytnia — nowa rewizja robocza',
        description: 'Zmiana wyłącznie demonstracyjna.',
        base_unit_code: 'g',
        nominal_quantity: '250.000000',
        usable_quantity: '240.000000',
        aliases: ['mąka robocza'],
        conversions: [{ unit_code: 'pc', to_base_factor: '2.500000', basis: '1 opakowanie' }],
      }),
    }))

    expect(revision.revision_no).toBe(3)
    expect(patch.data).toMatchObject({
      sku: 'RAW-000002',
      lifecycle_state: 'ACTIVE',
      version: 'synthetic-edit-version-2',
      revision: { revision_no: 4 },
    })
    const detail = await json(await window.fetch(`/api/v1/raw-materials/${materialId}`))
    expect(detail.data).toMatchObject({ sku: 'RAW-000002', code: 'RAW-000002' })
    expect((detail.data as Record<string, unknown>).approved_revision_id).toBe('00000000-0000-4000-8000-000000000203')
    expect(((detail.data as Record<string, unknown>).revision as Record<string, unknown>).name).toBe('Mąka żytnia typ 720')

    preview.setMode('version-conflict')
    const conflict = await window.fetch(`/api/v1/raw-materials/${materialId}/draft`, {
      method: 'PATCH',
      headers: { 'If-Match': 'stale-demo-version' },
      body: JSON.stringify({ name: 'Nie może nadpisać nowszej rewizji' }),
    })
    expect(conflict.status).toBe(409)
    const currentEditor = await json(await window.fetch(`/api/v1/raw-materials/${materialId}/editor`))
    expect(((currentEditor.data as Record<string, unknown>).revision as Record<string, unknown>).name)
      .toBe('Mąka żytnia — nowa rewizja robocza')
    preview.reset()
  })
})
