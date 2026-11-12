# Stage 5B.5 — PARTIAL

- **SKU:** backend generuje `RAW-000001`…`RAW-999999`; rezerwacja jest idempotentna, a kod jest zachowywany przy utworzeniu. Historyczne kody pozostają bez zmian. `RM-00027` pochodziło ze sztucznych danych preview, nie z generatora backendu.
- **Zmiany:** poprawiono syntetyczne SKU w katalogu i CSV preview oraz fixture’y testowe; dodano regresję formatu, unikalności, rezerwacji i stabilności SKU po edycji. Nie zmieniono backendu, rzeczywistych danych ani liczników.
- **Wspólne UI:** SearchField, DataTable, Pagination, Drawer, Tabs i formatowanie liczb są używane w RAW; nie znaleziono dodatkowych implementacji tych komponentów. Osobne pola dostawcy i formularze modalne pełnią inne funkcje.
- **Weryfikacja:** `cd frontend && npm test`: 141/141, 12 plików; `npm run build`: PASS; `npm run lint`: PASS z wcześniejszym ostrzeżeniem Fast Refresh `visualPreview.tsx:37`; `git diff --check`: PASS. Formatter backendu sprawdzono w efemerycznym kontenerze bez sieci i bazy: trzy poprawne numery, dwa odrzucenia i limit — PASS. Istniejące testy integracyjne SKU zidentyfikowano, lecz **nie uruchomiono**: wymagają odrębnej przygotowanej bazy; migracji zakazano. Nie jest to dowód działania alokacji transakcyjnej.
- **Preview:** http://localhost:4179/visual-preview.html — HTTP 200; w przeglądarce syntetyczny rekord jest widoczny jako `RAW-000027`. Zrzutów ekranu nie tworzono. Istniejący serwer pozostawiono; tymczasowy kontener testowy usunięto automatycznie.
- **Checkpoint:** proponowany zakres to zmodyfikowane pliki `frontend/src/` oraz nieśledzone `frontend/src/ApplicationShell.tsx` i `frontend/src/design-system/`. Wyłączyć pomocniczy `visual-preview-receiver.mjs`, zrzuty, referencje, pliki `Zone.Identifier` i lokalne raporty, o ile właściciel nie zdecyduje inaczej. Ograniczony skan typowych wzorców sekretów w zmianach kodu nie wykazał dopasowań; nie zastępuje pełnego audytu. Indeks jest pusty. Checkpoint nie jest gotowy do zatwierdzenia jako w pełni zweryfikowany bez testów integracyjnych SKU. Propozycja: `feat(ui): add shared VELTRA UI and RAW catalog`.
- **Ograniczenia:** `RAW_MATERIALS = NOT_READY`; bez migracji, wdrożenia, commitu, pushu i zapisu na Drive. Następny krok wymaga decyzji właściciela o izolowanej bazie testowej i osobnej zgody na commit.

## Status Git

```text
 M frontend/src/App.css
 M frontend/src/App.test.tsx
 M frontend/src/App.tsx
 M frontend/src/AuthPanel.tsx
 M frontend/src/index.css
 M frontend/src/rawMaterials/CatalogFilters.tsx
 M frontend/src/rawMaterials/CatalogTable.tsx
 M frontend/src/rawMaterials/RawMaterialCreateForm.test.tsx
 M frontend/src/rawMaterials/RawMaterialCreateForm.tsx
 M frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx
 M frontend/src/rawMaterials/RawMaterialEditForm.test.tsx
 M frontend/src/rawMaterials/RawMaterialEditForm.tsx
 M frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx
 M frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx
 M frontend/src/rawMaterials/api.ts
 M frontend/src/rawMaterials/catalog.test.ts
 M frontend/src/rawMaterials/catalog.ts
 M frontend/src/rawMaterials/create.test.ts
 M frontend/src/rawMaterials/create.ts
 M frontend/src/rawMaterials/detail.test.ts
 M frontend/src/rawMaterials/detail.ts
 M frontend/src/visualPreview.tsx
 M frontend/src/visualPreviewApi.test.ts
 M frontend/src/visualPreviewApi.ts
 M visual-preview-receiver.mjs
?? VELTRA_STAGE_5A_VISUAL_ARCHITECTURE_AUDIT.md
?? VELTRA_STAGE_5B1_RAW_VISUAL_FOUNDATION_REPORT.md
?? VELTRA_STAGE_5B4_FIX_REPORT.md
?? VELTRA_STAGE_5B5_SKU_UI_CHECKPOINT.md
?? artifacts/stage5b1/
?? frontend/src/ApplicationShell.tsx
?? frontend/src/design-system/
?? references/
```
