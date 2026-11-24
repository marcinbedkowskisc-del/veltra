# Stage 6A.1 — lokalny commit kandydata RAW

## Wynik

- Gałąź: `release/stage6a1-raw-candidate`
- Osobny worktree: `/home/veltra/projekty/veltra-stage6a1-raw-candidate`
- Commit: `795356faae84bd2185d615dc3764c97811d07184`
- Drzewo: `6d2a64d521fe3ee980c4bfa93a4ab835f9a4cdb7`
- Jedyny rodzic: `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6`
- Komunikat: `fix(raw): preserve pricing contracts across material revisions`

Utworzono dokładnie jeden lokalny commit. Nie wykonano pusha ani merge/fast-forward do `main`.

## Zawartość commita

Wyłącznie 11 zatwierdzonych, zmodyfikowanych plików:

1. `backend/app/Domain/RawMaterials/RawMaterialService.php`
2. `backend/tests/Feature/RawMaterialRevisionDataModelTest.php`
3. `frontend/src/rawMaterials/RawMaterialCreateForm.tsx`
4. `frontend/src/rawMaterials/RawMaterialCreateForm.test.tsx`
5. `frontend/src/rawMaterials/RawMaterialEditForm.tsx`
6. `frontend/src/rawMaterials/RawMaterialEditForm.test.tsx`
7. `frontend/src/rawMaterials/RawMaterialPricingFields.tsx`
8. `frontend/src/rawMaterials/api.ts`
9. `frontend/src/rawMaterials/api.test.ts`
10. `frontend/src/rawMaterials/create.ts`
11. `frontend/src/rawMaterials/create.test.ts`

Diff obejmuje 555 dodanych i 43 usunięte linie. Przejrzano kompletny patch i porównano staged diff z oczekiwanym drzewem. Nie zawiera zmian infrastruktury, wygenerowanych artefaktów ani ujawnionych poświadczeń. Wcześniejsze, niezwiązane formatowanie w `RawMaterialService.php` nie zostało włączone.

## Weryfikacja

| Kontrola | Wynik |
|---|---|
| Baza `main` | Oczekiwany commit `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6`. |
| Patch | SHA-256 `85492cf36ac458cca8c15d52659a2bae536e8054aee8dcc683acf56c91fabc96`; zastosowanie do bazy w odrębnym indeksie i worktree: PASS. |
| Granica plików | Dokładnie 11 wskazanych plików, wszystkie jako modyfikacje tekstowe; staged diff identyczny z oczekiwanym kandydatem. |
| Drzewo | Przed commitem i po nim dokładnie `6d2a64d521fe3ee980c4bfa93a4ab835f9a4cdb7`. |
| `git diff --check` | PASS przed commitem i dla utworzonego commita. |
| Worktree kandydata | Czysty po commicie. |
| Migracje | Wszystkie 61 plików z bazy obecne bez zmian; migracji nie uruchamiano. |
| `main` i wcześniejsze zmiany | Commit i gałąź `main` bez zmian. Przed utworzeniem tego raportu porównano stan, staged i unstaged diff z migawką sprzed operacji: identyczne. Kontrolne sumy zmian Compose i nieśledzonych plików hardeningu PostgreSQL również zgodne. Ten raport jest jedynym nowym plikiem w worktree `main` w ramach zadania. |

Wcześniej, dla tego samego drzewa kandydata, odnotowano **320 testów frontendowych PASS**, **43 testy backendowe PASS** i **PASS izolowanej aktualizacji 12 migracji**. W obecnym zadaniu testów tych nie powtarzano. Szczegóły i ograniczenia: [plan przyspieszonego wydania](./VELTRA_STAGE6A1_ACCELERATED_RELEASE_PLAN.md) i [raport integracyjny RAW](./VELTRA_STAGE6A1_RAW_CONTRACT_INTEGRATION_REPORT.md).

## Stage i dalsze kroki

Nie zmieniano Stage, kontenerów, wolumenów, bazy, ról, RBAC ani readiness. Kontrola odczytowa po commicie: PostgreSQL `healthy` na `veltra-stage6a1_postgres-data`, backend i frontend `healthy`, queue `running`, API `/api/health` HTTP 200; ID kontenerów i obrazów pozostały zgodne z wcześniejszym stanem.

Przed wdrożeniem potrzebne są osobna autoryzacja właściciela, przygotowanie i weryfikacja obrazów frontend/backend/queue z tego samego commita oraz ponowny preflight Stage, backupu/recovery, uprawnień, sekretów i 12 migracji. Stary backend korzysta z widoków usuwanych przez `000059`; wymagane jest skoordynowane wdrożenie według planu. Obrazy nie zostały przebudowane ani wdrożone.
