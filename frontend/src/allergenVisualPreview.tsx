import { useState } from 'react'
import { createRoot } from 'react-dom/client'
import './App.css'
import './index.css'
import AdministrationPanel from './AdministrationPanel'
import ApplicationShell from './ApplicationShell'
import RawMaterialWorkflow from './rawMaterials/RawMaterialWorkflow'
import {
  previewAllergenEntries,
  previewAllergenSources,
  previewCanonicalAllergens,
} from './administration/allergenVisualPreviewData'

type PreviewView = 'allergens' | 'raw'

const RAW_ID = '00000000-0000-4000-8000-000000000501'
const RAW_REVISION_ID = '00000000-0000-4000-8000-000000000502'
const RAW_VERSION = 'preview-revision-1'
const CATALOG_ID = '00000000-0000-4000-8000-000000000503'
const catalogDefinitions = [
  { allergen_code: 'ALG_CEREALS_GLUTEN', name_pl: 'Zboża zawierające gluten', source_codes: ['EU', 'US'] },
  { allergen_code: 'ALG_MILK', name_pl: 'Mleko', source_codes: ['EU', 'US'] },
  { allergen_code: 'ALG_SESAME', name_pl: 'Sezam', source_codes: ['US', 'JP'] },
]

const initialDeclaration = {
  status: 'DECLARED',
  catalog_version_id: CATALOG_ID,
  items: [
    { allergen_code: 'ALG_CEREALS_GLUTEN', exposure_kind: 'DIRECT' },
    { allergen_code: 'ALG_MILK', exposure_kind: 'CROSS_CONTACT_POTENTIAL' },
  ],
}

function json(data: unknown): Response {
  return new Response(JSON.stringify(data), {
    status: 200,
    headers: { 'Content-Type': 'application/json', 'Content-Language': 'pl' },
  })
}

function installSyntheticApi() {
  const entries = previewAllergenEntries as Record<string, Array<Record<string, unknown>>>
  const precautionaryIds = new Set(['ALG_SOY', 'ALG_MUSTARD', 'ALG_CELERY'])
  const originalFetch = window.fetch.bind(window)

  window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input), window.location.origin)
    const path = url.pathname
    const method = init?.method ?? 'GET'

    if (path === '/api/v1/administration/allergens/dictionaries') return json({ data: previewAllergenSources })
    if (path === '/api/v1/administration/allergens/canonical') return json({ data: previewCanonicalAllergens })
    if (path === '/api/v1/administration/allergens/maybe-contains' && method === 'GET') {
      return json({ data: [...precautionaryIds].flatMap((allergenId) => {
        const canonical = previewCanonicalAllergens.find((item) => item.id === allergenId)
        return canonical ? [{ allergen_id: canonical.id, canonical_pl: canonical.canonical_pl }] : []
      }) })
    }
    if (path === '/api/v1/administration/allergens/maybe-contains' && method === 'POST') {
      const body = init?.body ? JSON.parse(String(init.body)) as Record<string, unknown> : {}
      const allergenId = body.allergen_id
      const canonical = previewCanonicalAllergens.find((item) => item.id === allergenId)
      if (!canonical) throw new Error('Wybierz alergen z dostępnej listy.')
      if (precautionaryIds.has(canonical.id)) throw new Error('Ten alergen jest już na liście MOŻE ZAWIERAĆ.')
      precautionaryIds.add(canonical.id)
      return json({ data: { allergen_id: canonical.id, canonical_pl: canonical.canonical_pl } })
    }
    if (path.startsWith('/api/v1/administration/allergens/maybe-contains/') && method === 'DELETE') {
      const allergenId = path.split('/').at(-1) ?? ''
      const canonical = previewCanonicalAllergens.find((item) => item.id === allergenId)
      if (!canonical || !precautionaryIds.delete(allergenId)) {
        throw new Error('Tego alergenu nie ma na liście MOŻE ZAWIERAĆ.')
      }
      return json({ data: { allergen_id: canonical.id, canonical_pl: canonical.canonical_pl } })
    }
    if (path.includes('/api/v1/administration/allergens/dictionaries/') && path.endsWith('/entries')) {
      const versionId = path.split('/').at(-2) ?? ''
      return json({ data: entries[versionId] ?? [] })
    }
    if (path.includes('/api/v1/administration/allergens/dictionaries/') && path.endsWith('/history')) return json({ data: [] })
    if (path === '/api/v1/raw-materials/allergen-catalog') {
      return json({ data: [{ id: CATALOG_ID, version_number: 1, is_current: true, definitions: catalogDefinitions }] })
    }
    if (path === `/api/v1/raw-materials/${RAW_ID}/completion`) {
      return json({ data: {
        lifecycle_state: 'DRAFT',
        revision_id: RAW_REVISION_ID,
        version: RAW_VERSION,
        available_actions: ['edit_allergens'],
        missing_requirements: [],
      } })
    }
    if (path === `/api/v1/raw-materials/${RAW_ID}/editor`) {
      return json({ data: {
        id: RAW_ID,
        version: RAW_VERSION,
        allergen_declaration: initialDeclaration,
      } })
    }
    if (method === 'POST' || method === 'PATCH' || method === 'PUT') {
      const pathParts = path.split('/')
      const versionId = pathParts.at(-2) ?? ''
      const body = init?.body ? JSON.parse(String(init.body)) as Record<string, unknown> : {}
      const entriesForVersion = entries[versionId] ?? []
      const existing = entriesForVersion.find((entry) => path.endsWith(`/entries/${entry.id}`))
      if (existing) return json({ data: { ...existing, ...body } })
      if (path.endsWith('/entries')) {
        const canonicalId = body.canonical_allergen_id
        const canonical = previewCanonicalAllergens.find((item) => item.id === canonicalId)
        if (!canonical) throw new Error('Nie znaleziono wybranego alergenu w podglądzie.')
        return json({ data: {
          ...entriesForVersion[0],
          id: '00000000-0000-4000-8000-000000000599',
          dictionary_version_id: versionId,
          allergen_id: canonical.id,
          canonical_pl: canonical.canonical_pl,
          source_name: body.source_name,
          status: body.status,
        } })
      }
      if (path.endsWith('/allergen-declaration')) {
        return json({ data: { id: RAW_ID, version: `${RAW_VERSION}-2` } })
      }
      return json({ data: {} })
    }

    if (path.startsWith('/api/')) throw new Error(`Nieobsługiwane żądanie syntetycznego API: ${method} ${path}`)
    return originalFetch(input, init)
  }
}

installSyntheticApi()

export default function AllergenVisualPreview() {
  const [view, setView] = useState<PreviewView>('allergens')
  const [refreshKey, setRefreshKey] = useState(0)
  const rawView = view === 'raw'
  const activePath = rawView ? '/raw-materials' : '/administration/allergens'

  return (
    <ApplicationShell
      activePath={activePath}
      showAdministration={!rawView}
      contexts={[
        { label: 'Organizacja', value: 'VELTRA — podgląd' },
        { label: 'Lokalizacja', value: 'Kuchnia demonstracyjna', detail: 'Dane syntetyczne' },
      ]}
      footerNote="Podgląd PA-05F · dane syntetyczne"
      status={<span className="topbar-health"><span className="status-indicator" data-status="down" aria-hidden="true" />API symulowane</span>}
      account={<div className="preview-account" aria-label="Użytkownik demonstracyjny">
        <span className="account-avatar" aria-hidden="true">UD</span>
        <span className="account-trigger-copy"><strong>Użytkownik demonstracyjny</strong><small>Konto syntetyczne</small></span>
      </div>}
    >
      <section className="preview-toolbar" aria-label="Sterowanie podglądem PA-05">
        <div className="preview-toolbar-heading">
          <strong>PODGLĄD PA-05F — DANE SYNTETYCZNE</strong>
          <span>Pełne MASTER v1.1: 178 relacji w 9 wersjach. Bez połączenia ze Stage lub produkcją.</span>
        </div>
        <div className="preview-toolbar-controls">
          <button type="button" className={`button ${rawView ? 'button-secondary' : 'button-primary'}`}
            aria-pressed={!rawView} onClick={() => setView('allergens')}>Administracja alergenami</button>
          <button type="button" className={`button ${rawView ? 'button-primary' : 'button-secondary'}`}
            aria-pressed={rawView} onClick={() => setView('raw')}>Deklaracja RAW</button>
        </div>
        <p className="preview-toolbar-status">
          {rawView
            ? 'Surowiec demonstracyjny: Mąka pszenna · deklaracja zawiera ekspozycję bezpośrednią i ryzyko zanieczyszczenia krzyżowego.'
            : 'Źródła: UE, USA, Codex, Japonia, Chiny, Korea Południowa, Tajwan i Tajlandia · globalna lista MOŻE ZAWIERAĆ.'}
        </p>
      </section>
      {rawView
        ? <section className="state-panel">
          <h1>Surowiec · Mąka pszenna typ 550</h1>
          <p>Rewizja robocza · przykład demonstracyjny</p>
          <RawMaterialWorkflow
            key={refreshKey}
            materialId={RAW_ID}
            refreshKey={refreshKey}
            onSaved={() => setRefreshKey((current) => current + 1)}
            onSessionExpired={() => {}}
            onModuleUnavailable={() => {}}
          />
        </section>
        : <AdministrationPanel path="/administration/allergens" />}
    </ApplicationShell>
  )
}

createRoot(document.getElementById('root')!).render(<AllergenVisualPreview />)
