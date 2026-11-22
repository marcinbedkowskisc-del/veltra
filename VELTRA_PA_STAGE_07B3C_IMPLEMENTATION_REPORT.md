# VELTRA 2.0 — PA-07B.3C — raport implementacji

**Data:** 2026-11-21
**Branch:** `pa-07b3c-approved-supplier-snapshots`
**Worktree:** `/home/veltra/projekty/veltra-pa-07b3c`
**HEAD bazowy i bieżący:** `d0d22e65df49fc4e8744ca626ac363126447e36d`
**Status:** IMPLEMENTED IN ISOLATED WORKTREE / READY FOR INDEPENDENT REVIEW

## 1. Podsumowanie wykonawcze

Zaimplementowano revision-bound proponowany zestaw dostawców i niemutowalny approved supplier snapshot na osobnym worktree PA-07B.3C.

CONFIRMED:

- nie zmieniono istniejącego worktree PA-07B.3B;
- utworzono osobną gałąź i worktree od zaakceptowanego SHA;
- nie utworzono commita, push, merge, deploymentu ani migracji na istniejącej bazie;
- nie zmieniono `BOSS_RAW_CATALOG_READ = NOT_READY`;
- nie zmieniono `RAW_MATERIALS = NOT_READY`;
- nie wykonano aktywacji capability lub modułu;
- implementacja nie dotknęła frontend redesignu.

Kluczowe zachowanie:

1. `raw_material_supplier_associations` pozostaje legacy table zwykłego RAW i nie jest backfillowana.
2. Dodano proposed supplier set przypisany do dokładnej rewizji.
3. Dodano immutable approved snapshot przypisany do approved revision i approval event.
4. `approve()` tworzy snapshot i approval event atomowo przed zmianą approved pointer.
5. BOSS list/detail zwraca dokładnie jedno nowe pole `supplier_names: string[]|null`.
6. Legacy-only relationships zwracają `null`.
7. Jawnie zatwierdzony pusty snapshot zwraca `[]`.
8. Snapshot non-empty używa bieżących nazw słownika dostawców, bez supplier ID.
9. Archiwalny dostawca pozostaje widoczny po zatwierdzonym snapshotcie; może być nazwany jego bieżącą nazwą.

## 2. Git baseline i worktree

- Bazowy SHA: `d0d22e65df49fc4e8744ca626ac363126447e36d`
- Bazowy branch: `pa-07b3b-boss-raw-catalog`
- Branch implementacyjny: `pa-07b3c-approved-supplier-snapshots`
- Worktree: `/home/veltra/projekty/veltra-pa-07b3c`
- HEAD worktree: `d0d22e65df49fc4e8744ca626ac363126447e36d`
- Worktree ma zmiany niezacommitowane, zgodnie z zakazem commitowania.
- Istniejące worktree PA-07B.3A i PA-07B.3B nie zostały zmienione ani przeniesione.

Przed utworzeniem worktree:

- branch docelowy nie istniał;
- ścieżka `/home/veltra/projekty/veltra-pa-07b3c` nie istniała;
- worktree PA-07B.3B miał oczekiwany HEAD i bez zmian śledzonych.

## 3. Lista zmienionych i utworzonych plików

### Migracja

- `backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`

### Backend

- `backend/app/Http/Requests/RawMaterialDataRequest.php`
- `backend/app/Domain/RawMaterials/RawMaterialService.php`
- `backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php`
- `backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php`

### Testy

- `backend/tests/Feature/BossRawMaterialCatalogApiTest.php`
- `backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php`

### Raport

- `VELTRA_PA_STAGE_07B3C_IMPLEMENTATION_REPORT.md`

## 4. Schema i migracja

Numer migracji: `2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`

Migracja 057 tworzy:

1. `raw_material_revision_supplier_proposals`
2. `raw_material_revision_supplier_proposal_items`
3. `raw_material_approved_supplier_snapshots`
4. `raw_material_approved_supplier_snapshot_items`

Źródła dokładne:

- [migration 057 — proposed tables](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:17)
- [migration 057 — snapshot tables](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:47)
- [migration 057 — proposal guards](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:106)
- [migration 057 — snapshot immutability](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:210)
- [migration 057 — snapshot consistency](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:246)
- [migration 057 — HMAC scopes and grants](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:289)

Zaimplementowane invarianty:

- proposal i snapshot są wiązane organizacją, materiałem i dokładną rewizją;
- proposal ma jeden wiersz na organizację i rewizję;
- proposal items są unikalne dla pary rewizja/dostawca;
- snapshot ma jeden wiersz na organizację i rewizję;
- snapshot items są unikalne dla pary rewizja/dostawca;
- snapshot header i items są niemutowalne przez normalny runtime;
- snapshot header/approval event/revision muszą odpowiadać sobie;
- snapshot state musi pasować do liczby itemów;
- supplier w itemach musi należeć do tej samej organizacji;
- snapshot item musi wskazywać aktywnego dostawcę w momencie approval.

Migracja 057 jest forward-only. `down()` nie cofa zmian.

Nie zmieniono migracji 022–056 ani wcześniejszych tabel legacy.

## 5. Lifecycle proposed supplier set

Stan proponowanego zestawu:

- `NOT_PROVIDED` — nie podano informacji;
- `PROPOSED_EMPTY` — jawnie potwierdzono pusty zestaw;
- `PROPOSED_NONEMPTY` — jawnie wskazano co najmniej jednego dostawcę.

Implementacja:

- pole API `supplier_ids` pozostaje `sometimes`;
- jawny pusty zestaw wymaga dodatkowego pola `supplier_association_empty_confirmed=true`;
- pole jest dodane w [RawMaterialDataRequest.php](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Http/Requests/RawMaterialDataRequest.php:22);
- pusty `supplier_ids` bez potwierdzenia kończy się ValidationException;
- dla niepustego zestawu wymagany jest aktywny dostawca tej organizacji;
- proposal zapisuje się po każdej tworzonej/edytowanej rewizji;
- nowe rewizje non-supplier, takie jak zmiana alergenu, przenoszą istniejący proposal;
- nowe rewizje ACTIVE mogą jawnie lub automatycznie przenieść approved snapshot jako nowy proposal;
- snapshot zatwierdzony nie jest automatycznie traktowany jako legacy history.

Źródła:

- [sync supplier legacy i proposal](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:590)
- [proposal sync](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:636)
- [create path](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:1835)
- [import path](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:1922)
- [revise path](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2205)
- [restore proposal copy](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2785)

## 6. Integracja approval

Approval flow został rozszerzony w [RawMaterialService::approve()](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2420):

1. blokowana jest organizacja i rewalidowany actor;
2. sprawdzana jest pending revision i current approval hash;
3. tworzony jest approval event;
4. tworzony jest approved supplier snapshot:
   - brak proposal lub `NOT_PROVIDED` → brak snapshotu;
   - `PROPOSED_EMPTY` → snapshot `APPROVED_EMPTY`;
   - `PROPOSED_NONEMPTY` → ponowna walidacja aktywności dostawców i snapshot `APPROVED_NONEMPTY`;
5. snapshot i items są zapisane w tej samej transakcji co event;
6. approved pointer zmienia się dopiero po udanym zapisaniu snapshotu;
7. błąd powoduje rollback approval event, snapshotu i pointera.

Snapshot tworzy [createApprovedSupplierSnapshot()](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:777).

## 7. Legacy compatibility

Pozostawiono tabelę `raw_material_supplier_associations` i dotychczasową logikę `syncSupplierAssociations()`.

Legacy associations:

- nie są backfillowane;
- nie są przypisywane do rewizji;
- nie tworzą approval event;
- nie są projected do BOSS;
- pozostają dostępne dla zwykłego RAW;
- nie mają semantyki approved snapshot.

Proposed set i legacy associations są rozdzielone. BOSS nie łączy ich jako źródła.

## 8. Supplier name semantics

Zaatwierdzony snapshot zapisuje supplier identity, nie nazwę.

Wyświetlanie nazwy:

- `display_name`, jeśli valid i niepuste;
- inaczej `legal_name`;
- nazwy są sortowane i deduplikowane;
- rename dostawcy zmienia bieżącą nazwę, ale nie identity snapshotu;
- archived supplier pozostaje widoczny po approved snapshot z bieżącą nazwą słownika;
- supplier ID, contact, tax identifier i financial fields nie trafiają do DTO.

Implementacja nazwy BOSS znajduje się w:

- [BossRawMaterialCatalogItem.php](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php:45)
- [BossRawMaterialCatalogService.php](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php:244)
- [projection supplier names](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php:275)

## 9. Finalny kontrakt BOSS API

Dodano dokładnie jedno nowe publiczne pole:

```json
{
  "supplier_names": ["Supplier A", "Supplier B"]
}
```

Pole jest także wymagane jako `null` dla:

- `NOT_PROVIDED`;
- legacy-only;
- working/pending proposal;
- brak snapshotu.

Jawnie approved empty:

```json
{
  "supplier_names": []
}
```

Istniejące pola PA-07B.3B i paginacja pozostają bez zmian.

Brak nowego supplier-only endpointu, filtrów supplier, sortowania supplier, exportu, cen, kosztów i danych kontaktowych.

Testy API nadal wymagają [BossRawMaterialCatalogApiTest.php](/home/veltra/projekty/veltra-pa-07b3c/backend/tests/Feature/BossRawMaterialCatalogApiTest.php).

## 10. Authorization i HMAC impact

Bez redizajnu:

- RBAC i effective permission pozostają w Laravel;
- organization/location isolation pozostaje bez zmian;
- HMAC scope’y istniejące nie zostały nadpisane;
- migracja 057 dodaje minimalne scope’y:
  - proposal tables: `raw-material.mutate`;
  - snapshot header/items: `raw-material.mutate`;
- snapshot tables mają runtime:
  - SELECT/INSERT;
  - bez UPDATE/DELETE;
- proposal tables zachowują zwykłe INSERT/UPDATE/DELETE;
- snapshot i items są niemutowalne również triggerem PostgreSQL.

BOSS read:

- nie używa ordinary RAW grants;
- nie używa legacy associations;
- capability gate i category restrictions pozostają bez zmian;
- `RAW_MATERIALS` i `BOSS_RAW_CATALOG_READ` nie zostały aktywowane.

## 11. Tests executed

### Clean migration

- Izolowany PostgreSQL 17 tmpfs;
- migracje 001–057;
- wynik: PASS, wszystkie migracje wykonały się poprawnie.

### PHP syntax

- `RawMaterialService.php`: PASS;
- migracja 057: PASS;
- provenance test: PASS.

### Focused BOSS snapshot/API

`BossRawMaterialCatalogProvenanceTest.php` + `BossRawMaterialCatalogApiTest.php`:

- **13 tests**
- **122 assertions**
- **PASS**

Zakres:

- legacy supplier association nie trafia do BOSS;
- approved non-empty snapshot;
- live supplier rename;
- explicit approved empty;
- omitted empty field jest odrzucany;
- snapshot update/delete odrzucany;
- list/detail compatibility.

### BOSS authorization/concurrency

`BossRawMaterialCatalogAuthorizationTest.php` + `BossRawMaterialCatalogConcurrencyTest.php`:

- **14 tests**
- **136 assertions**
- **PASS**

### RAW supplier/approval/revision regression

Filtrowany test `RawMaterialsTest.php` z filtrami `supplier|approval|revision`:

- **32 tests**
- **449 assertions**
- **PASS**

### Diff and formatting

- `git diff --check`: PASS;
- brak zmian śledzonych poza implementation set.

## 12. Test failures and limitations

Nieudane próby testów:

- pierwszy parallelny przegląd authorization/concurrency i RAW został użyty równolegle na tej samej izolowanej bazie i zakończył się SQLSTATE `40P01` deadlock. Nie jest to wynik implementacji i przebieg sekwencyjny zakończył się PASS.
- jednorazowe testowe błędy były spowodowane kolejno:
  - test bez signed ticket dla legacy/rename fixture;
  - stary build obrazu testowego;
  - over-write proposal w nowej rewizji.
  Te przyczyny zostały naprawione i końcowe focused suites przechodzą.

Nie wykonano:

- dedicated forward-upgrade 056→057 with synthetic legacy rows;
- complete backend suite;
- browser/E2E;
- live database inspection;
- production/STAGING migration;
- production readiness activation.

## 13. Pozostałe blockers

1. Forward-upgrade 056→057 nie ma dedykowanego istniejącego harnessa i nie został uruchomiony.
2. Nie zweryfikowano istniejących danych i prawdziwej bazy.
3. Możliwe należy osobno zaakceptować politykę archive/rename dostawcy po zatwierdzonym snapshotcie.
4. Brak snapshotu dla istniejących historycznych rewizji jest zamierzony i oznaczony jako no snapshot.

## 14. Deviations from approved architecture

Nie zidentyfikowano konfliktu z approved architecture.

Wyjaśnienia:

- legacy associations są nadal zwykłą tabelą ordinary RAW;
- proposal state is stored in four additive tables;
- supplier names are resolved live from the dictionary;
- BOSS API returns `supplier_names` but no supplier ID;
- ordinary RAW APIs remain compatible.

## 15. Final self-assessment

**READY FOR INDEPENDENT REVIEW**

Uzasadnienie:

- clean migration pass;
- focused BOSS snapshot/API pass;
- BOSS authorization/concurrency pass;
- filtered RAW supplier/approval/revision pass;
- snapshot immutability pass;
- no readiness activation;
- no commit/deployment;
- no existing database mutation.

Przed wdrożeniem wymagane są independent migration review i osobny forward-upgrade test.
