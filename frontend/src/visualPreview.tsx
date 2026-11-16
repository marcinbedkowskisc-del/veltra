import { useCallback, useState } from 'react'
import { createRoot } from 'react-dom/client'
import './App.css'
import './index.css'
import ApplicationShell from './ApplicationShell'
import RawMaterialsCatalogPage from './rawMaterials/RawMaterialsCatalogPage'
import { installVisualPreviewApi, type PreviewMode } from './visualPreviewApi'

const previewApi = installVisualPreviewApi()
const DEMO_RECORDS = [
  { id: '00000000-0000-4000-8000-000000000001', label: 'Szkic edytowalny — Mąka pszenna typ 550' },
  { id: '00000000-0000-4000-8000-000000000002', label: 'Aktywny — Mąka żytnia typ 720' },
  { id: '00000000-0000-4000-8000-000000000003', label: 'Aktywny z rewizją roboczą — Cukier trzcinowy' },
  { id: '00000000-0000-4000-8000-000000000004', label: 'Oczekuje na weryfikację — Sól morska drobna' },
  { id: '00000000-0000-4000-8000-000000000005', label: 'Zarchiwizowany — Ryż basmati' },
  { id: '00000000-0000-4000-8000-000000000006', label: 'Aliasy i przeliczniki — Kasza gryczana niepalona' },
] as const

const SCENARIOS: Array<{ value: PreviewMode; label: string }> = [
  { value: 'authorized', label: 'Pełny dostęp (CREATE + EDIT)' },
  { value: 'financial-restricted', label: 'CREATE + EDIT bez danych finansowych' },
  { value: 'view-only', label: 'Tylko VIEW' },
  { value: 'no-edit', label: 'CREATE bez EDIT' },
  { value: 'not-ready', label: 'Moduł NOT_READY' },
  { value: 'record-unavailable', label: 'Brak EDIT dla rekordu' },
  { value: 'version-conflict', label: 'Konflikt nieaktualnej wersji' },
]

function scenarioPermissions(mode: PreviewMode): string {
  if (mode === 'financial-restricted') return 'VIEW: tak · CREATE: tak · EDIT: tak · ceny i koszty: nie'
  if (mode === 'view-only') return 'VIEW: tak · CREATE: nie · EDIT: nie'
  if (mode === 'no-edit') return 'VIEW: tak · CREATE: tak · EDIT: nie'
  if (mode === 'not-ready') return 'VIEW: tak · CREATE: przyznane · EDIT: przyznane · akcje zablokowane'
  if (mode === 'record-unavailable') return 'VIEW: tak · CREATE: tak · EDIT: tak · rekord: niedostępny'
  if (mode === 'version-conflict') return 'VIEW: tak · CREATE: przyznane · EDIT: tak'
  return 'VIEW: tak · CREATE: tak · EDIT: tak · cena referencyjna: odczyt i zapis · FOODCOST_VIEW: tak'
}

function VisualPreview() {
  const legacyMode = new URLSearchParams(window.location.search).get('mode')
  const [scenario, setScenario] = useState<PreviewMode>(previewApi.getMode())
  const [previewRecordId, setPreviewRecordId] = useState(
    legacyMode?.startsWith('edit-') ? DEMO_RECORDS[0].id : '',
  )
  const [catalogInstance, setCatalogInstance] = useState(0)
  const [sessionExpired, setSessionExpired] = useState(false)
  const [canvasWidth, canvasHeight] = (new URLSearchParams(window.location.search).get('canvas') ?? '')
    .split('x')
    .map(Number)
  const width = Number.isFinite(canvasWidth) && canvasWidth > 0 ? `${canvasWidth}px` : '100%'
  const minHeight = Number.isFinite(canvasHeight) && canvasHeight > 0 ? `${canvasHeight}px` : '100vh'

  const handleSessionExpired = useCallback(() => setSessionExpired(true), [])

  function changeScenario(nextMode: PreviewMode) {
    previewApi.setMode(nextMode)
    setScenario(nextMode)
    setSessionExpired(false)
    setCatalogInstance((current) => current + 1)
  }

  function resetDemo() {
    previewApi.reset()
    setSessionExpired(false)
    setPreviewRecordId('')
    setCatalogInstance((current) => current + 1)
  }

  return (
    <div className="preview-canvas" style={{ width, minHeight }}>
      <ApplicationShell
        activePath="/raw-materials"
        contexts={[
          { label: 'Organizacja', value: 'Bistro Demonstracyjne' },
          { label: 'Lokalizacja', value: 'Kuchnia centralna', detail: 'Dane syntetyczne' },
        ]}
        footerNote="Wyłącznie podgląd syntetyczny"
        status={(
          <span className="topbar-health preview-health">
            <span className="status-indicator" data-status="down" aria-hidden="true" />
            API symulowane
          </span>
        )}
        account={(
          <div className="preview-account" aria-label="Użytkownik demonstracyjny">
            <span className="account-avatar" aria-hidden="true">UD</span>
            <span className="account-trigger-copy">
              <strong>Użytkownik demonstracyjny</strong>
              <small>Konto syntetyczne</small>
            </span>
          </div>
        )}
      >
        <section className="preview-toolbar" aria-label="Sterowanie podglądem demonstracyjnym">
          <div className="preview-toolbar-heading">
            <strong>TRYB DEMONSTRACYJNY — DANE SYNTETYCZNE</strong>
            <span>Scenariusz: {SCENARIOS.find((item) => item.value === scenario)?.label ?? scenario}</span>
          </div>
          <div className="preview-toolbar-controls">
            <label>
              <span>Scenariusz dostępu</span>
              <select value={scenario} onChange={(event) => changeScenario(event.target.value as PreviewMode)}>
                {SCENARIOS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </label>
            <label>
              <span>Przykładowy surowiec</span>
              <select
                value={previewRecordId}
                onChange={(event) => setPreviewRecordId(event.target.value)}
                disabled={scenario === 'not-ready'}
              >
                <option value="">Wybierz rekord do podglądu</option>
                {DEMO_RECORDS.map((record) => <option key={record.id} value={record.id}>{record.label}</option>)}
              </select>
            </label>
            <button type="button" className="button button-secondary" onClick={resetDemo}>
              Resetuj dane demo
            </button>
          </div>
          <p className="preview-toolbar-status">
            Uprawnienia syntetyczne: {scenarioPermissions(scenario)} · RAW_MATERIALS: {scenario === 'not-ready' ? 'NOT_READY' : 'READY (wyłącznie w symulacji)'}
          </p>
          <p>Podgląd ceny symuluje wyłącznie przykład: 2800 g nominalnie, 1500 g użytkowo, 28 PLN za opakowanie lub 10 PLN/kg.</p>
        </section>
        {sessionExpired && (
          <section className="state-panel state-error" role="alert">
            <h2>Symulacja: sesja wygasła</h2>
            <p>Żądanie demonstracyjne otrzymało HTTP 401.</p>
          </section>
        )}
        <RawMaterialsCatalogPage
          key={`${scenario}-${catalogInstance}-${previewRecordId}`}
          onSessionExpired={handleSessionExpired}
          previewDetailId={previewRecordId || undefined}
          operatingLocationId="00000000-0000-4000-8000-000000000101"
          syntheticPreview
        />
      </ApplicationShell>
    </div>
  )
}

createRoot(document.getElementById('root')!).render(<VisualPreview />)
