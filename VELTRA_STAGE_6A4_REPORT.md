# Stage 6A.4 — PASS: szkice RAW i lokalny cykl zatwierdzania

## Wynik i odbiór

Aplikacja działa pod <http://localhost:5174/>; API <http://localhost:8001/>.
Konto testowe: `raw.steward@veltra.test`; dotychczasowe hasło pozostaje bez zmian.
W zakładce **Szkice i oczekujące** dostępny jest niezmieniony `RAW-000001`.
Właściciel może otworzyć edycję, jawnie uzupełnić deklarację alergenów,
przekazać szkic i zatwierdzić go zgodnie z istniejącym RBAC.
Nie uruchomiono następnego etapu.

## Zmiany i kontrakty

- [RawMaterialService](backend/app/Domain/RawMaterials/RawMaterialService.php),
  [kontroler](backend/app/Http/Controllers/Api/V1/RawMaterialController.php)
  i [trasy](backend/routes/api.php): osobny `GET /api/v1/raw-materials/workbench`.
  VIEW + (EDIT lub AUDIT_VIEW), lokalizacja główna, standardowy guard dostępności.
  Serwerowe wyszukiwanie, filtry i paginacja; robocze/oczekujące rewizje również
  aktywnych surowców. Bez danych finansowych; filtr dostawców/sorty finansowe: 422.
- Zatwierdzony katalog nie zmienia zasad widoczności. Istniejący `/editor`
  dostarcza dodatkowo ilości, wydajność i autoryzowane metadane historii,
  aby drawer nie pokazywał starego zatwierdzonego snapshotu jako nowego szkicu.
- [Katalog](frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx),
  [tabela](frontend/src/rawMaterials/CatalogTable.tsx),
  [filtry](frontend/src/rawMaterials/CatalogFilters.tsx),
  [parser](frontend/src/rawMaterials/catalog.ts),
  [API](frontend/src/rawMaterials/api.ts),
  [drawer](frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx)
  i [workflow](frontend/src/rawMaterials/RawMaterialWorkflow.tsx):
  wspólne Tabs/Drawer i istniejące style formularzy; statusy, potwierdzenia,
  deklaracje alergenów i wspólne odświeżanie po zapisie.
- `If-Match`, CSRF, effective RBAC, izolacja kontekstu, audyt i niezmienne SKU
  pozostają obowiązkowe. Brak automatycznego ponowienia konfliktu/niejasnego
  zapisu; szczegóły walidacji są widoczne. Formularze otrzymują fokus;
  spóźnione odpowiedzi po zamknięciu/zmianie kontekstu są ignorowane.
- Dodano [testy backendu](backend/tests/Concerns/RawWorkbenchTests.php)
  w istniejącym RawMaterialsTest oraz testy workflow, katalogu i API.
  Zaktualizowano [README](README.md). Bez migracji i zmian grantów.

## Rzeczywisty PostgreSQL i HTTP

Wszystkie poniższe operacje wykonano przez normalny interfejs i istniejące API,
w sesji opiekuna, wyłącznie w `veltra_stage6a1`:

| Operacja na `RAW-000002` | HTTP | Wynik |
|---|---:|---|
| CREATE | 201 | DRAFT, rewizja 1 |
| EDIT | 200 | DRAFT, rewizja 2, zmieniona nazwa |
| Jawna deklaracja alergenów | 200 | DRAFT, rewizja 3 |
| SUBMIT | 200 | VERIFICATION, nadal rewizja 3 |
| APPROVE przez autora | 200 | ACTIVE, zatwierdzona rewizja 3 |
| Katalog zatwierdzonych / historia rewizji | 200 / 200 | rekord i 3 rewizje widoczne |
| APPROVE z wersją sprzed SUBMIT | 409 | RAW_MATERIAL_VERSION_CONFLICT, bez zatwierdzenia |
| Workbench bez sesji / historia cen opiekuna | 401 / 403 | odmowy dostępu |

SQL potwierdził: `RAW-000001` DRAFT/rewizja 1 bez zmian; `RAW-000002` ACTIVE,
3 rewizje, 1 approval event. Audyt RAW-000002: CREATED ×1, REVISION_CREATED ×2,
SUBMITTED ×1, APPROVED ×1, SKU_RESERVED ×1. SKU nie zmieniło się.
Po zatwierdzeniu RAW-000002 zniknął z workbench i pojawił się w zatwierdzonych.

Brak katalogu alergenów był rzeczywistą blokadą APPROVE. Właściciel osobno
zatwierdził jednorazowe przygotowanie danych testowych. Transakcja pod rolą
właściciela schematu zachowała triggery DRAFT → PUBLISHED; powstała wersja 1
z `TEST_WHEAT` i `TEST_MILK`, z jednoznacznie testowymi nazwami.
Przed zapisem potwierdzono LocalStage6a1Instance, DB/OID, organizację,
sieć/wolumen i porty loopback; nie użyto produkcji/STAGING.
Skrypt operacji:
`/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/files/stage6a4-local-allergen-catalog.sql`.
Ponowne wykonanie odmawia, jeżeli katalog już istnieje.

## Testy

Runner testował aktualne `backend/app`, `backend/tests` i `backend/routes`
przez bind mount; odrębna baza `veltra_testing`, bez resetowania runtime.

```bash
docker compose -p veltra-stage6a1 --profile testing \
  --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
  -f compose.yaml \
  -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml \
  -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-test-services.yaml \
  run --rm --no-deps \
  -v /home/veltra/projekty/veltra/backend/app:/var/www/html/app:ro \
  -v /home/veltra/projekty/veltra/backend/tests:/var/www/html/tests:ro \
  -v /home/veltra/projekty/veltra/backend/routes:/var/www/html/routes:ro \
  backend-test php artisan test --filter=RawMaterial
```

- Końcowy backend: **233 PASS, 2550 asercji, 423,61 s**.
  Obejmuje RAW, lifecycle, dokumenty, współbieżność, autoryzację i izolację
  oraz lokalny opt-in/standardowy guard. Celowany workbench: **4 PASS, 80 asercji**.
- `npm --prefix frontend test`: **159 PASS, 13 plików**, 11,03 s.
- `npm --prefix frontend run build`: **PASS**, obejmuje `tsc -b`.
- `npm --prefix frontend run lint`: exit 0; jeden wcześniejszy warning
  `visualPreview.tsx:37 react(only-export-components)`. Brak nowych warningów.
- Ten sam runner: `vendor/bin/pint --test
  app/Domain/RawMaterials/RawMaterialService.php
  app/Http/Controllers/Api/V1/RawMaterialController.php
  tests/Concerns/RawWorkbenchTests.php tests/Feature/RawMaterialsTest.php`:
  **PASS, 4 pliki**.
- `git diff --check`: **PASS**.

Wcześniejsze nieudane przebiegi wykryły regresję klucza formularza edycji,
blokowanie szczegółów przez flagę selekcji oraz błąd przełączania sesji fixture.
Poprawiono przyczyny i wykonano wskazane powyżej końcowe przebiegi PASS.

## Wizualizacja, ograniczenia i cleanup

Sprawdzono rzeczywistą aplikację: desktop 1365×900 oraz mobile 390×844,
bez poziomego overflow dokumentu; mobilny drawer ma szerokość 390 px.
Zweryfikowano otwieranie zakładki/rekordu/formularza klawiaturą oraz fokus
na polu deklaracji. Zrzuty desktop/mobile wykonano narzędziem przeglądarkowym
w sesji; zapis PNG pod proponowanymi ścieżkami WSL nie jest potwierdzony,
więc nie są wskazywane jako lokalne artefakty.

Niekompletnego rekordu oczekującego nie można edytować; powrót wymaga REVIEW.
Opiekun nie otrzymał dodatkowych uprawnień: deklarację trzeba uzupełnić przed
SUBMIT. Self-approval jest dozwolone w istniejącym kontrakcie.
Testowy katalog alergenów nie jest katalogiem do eksploatacji.

Opt-in wygasa **13.11.2026, 16:47:01 UTC**; późniejsze odnowienie wymaga ponownej
weryfikacji izolacji. Nie zmieniano poświadczenia ani globalnej gotowości.
Zmiany źródłowe zachowano na dysku i skopiowano tylko do bieżących lokalnych
kontenerów, bez przebudowy stacka. Odtworzenie kontenerów ze starszych obrazów
nie zachowa tej lokalnej aktualizacji kodu.

Pomocniczy `veltra-stage6a1-clamav-1` zatrzymany; testowe runnery usunięte
przez `--rm`. Pozostają działające backend, frontend, PostgreSQL, Redis,
queue i Mailpit Stage6A1. Starszego stacka nie dotykano.
Bez commitu, pushu, deploymentu, resetu bazy i operacji Google Drive.
Globalnie **RAW_MATERIALS = NOT_READY**.

## Git na zakończenie

Wszystkie wcześniejsze niezacommitowane zmiany zachowano; checkpoint
`b432ddeebfd2fcf081099f689f57eaf6606adbf1` pozostaje niezmieniony.

```text
 M README.md
 M backend/app/Domain/Modules/DefinedModuleCatalog.php
 M backend/app/Domain/RawMaterials/RawMaterialService.php
 M backend/app/Http/Controllers/Api/V1/RawMaterialController.php
 M backend/app/Providers/AppServiceProvider.php
 M backend/routes/api.php
 M backend/tests/Feature/RawMaterialsTest.php
 M backend/tests/Support/ModuleCatalogFixtures.php
 M frontend/src/rawMaterials/CatalogFilters.tsx
 M frontend/src/rawMaterials/CatalogTable.tsx
 M frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx
 M frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx
 M frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx
 M frontend/src/rawMaterials/api.test.ts
 M frontend/src/rawMaterials/api.ts
 M frontend/src/rawMaterials/catalog.ts
 M scripts/init-env.php
 M visual-preview-receiver.mjs
?? VELTRA_STAGE_5A_VISUAL_ARCHITECTURE_AUDIT.md
?? VELTRA_STAGE_5B1_RAW_VISUAL_FOUNDATION_REPORT.md
?? VELTRA_STAGE_5B4_FIX_REPORT.md
?? VELTRA_STAGE_5B5_SKU_UI_CHECKPOINT.md
?? VELTRA_STAGE_6A1_REPORT.md
?? VELTRA_STAGE_6A2_FIX_REPORT.md
?? VELTRA_STAGE_6A2_REPORT.md
?? VELTRA_STAGE_6A4_REPORT.md
?? VELTRA_STAGE_6A_LOGIN_FIX_REPORT.md
?? artifacts/stage5b1/
?? backend/app/Console/Commands/ConfigureStage6a1Raw.php
?? backend/app/Console/Commands/RecoverStage6a1Identity.php
?? backend/app/Domain/Installation/LocalStage6a1Instance.php
?? backend/app/Domain/Modules/LocalRawMaterialCatalog.php
?? backend/tests/Concerns/RawWorkbenchTests.php
?? backend/tests/Feature/LocalRawMaterialIntegrationTest.php
?? backend/tests/Feature/Stage6a1IdentityRecoveryTest.php
?? frontend/src/rawMaterials/RawMaterialWorkflow.test.tsx
?? frontend/src/rawMaterials/RawMaterialWorkflow.tsx
?? references/
```
