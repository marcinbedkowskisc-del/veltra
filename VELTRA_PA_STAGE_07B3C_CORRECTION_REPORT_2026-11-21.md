# VELTRA 2.0 — PA-07B.3C — raport korekt

**Data:** 2026-11-21
**Branch:** `pa-07b3c-approved-supplier-snapshots`
**Worktree:** `/home/veltra/projekty/veltra-pa-07b3c`
**HEAD bazowy i bieżący:** `d0d22e65df49fc4e8744ca626ac363126447e36d`
**Status:** READY FOR INDEPENDENT RE-REVIEW

## 1. Zweryfikowany baseline Git

**CONFIRMED:**

- Branch: `pa-07b3c-approved-supplier-snapshots`
- HEAD przed korektami: `d0d22e65df49fc4e8744ca626ac363126447e36d`
- Implementacja pozostawała niezatwierdzona do commita i uncommitted.
- Worktree był utworzony od bazowego commit PA-07B.3B.
- Nie wykonano commita, push, merge ani rebase.
- Nie zmieniono worktree PA-07B.3B.
- Nie zmieniono migracji 001–056.

## 2. Root cause 3C-AUD-001

Poprzedni helper `syncSupplierProposal()` priorytetyzował snapshot approved base przed proposalem bazy powrotnej.

Skutek:

- rewind/return/revise mogły zastąpić proposal B starszym snapshotem A;
- nowe rewizje bez jawnego `supplier_ids` mogły otrzymać supplier set nienależący do zwróconej rewizji;
- approved snapshot był błędnie używany jako źródło inheritance zamiast returned/working proposal.

**CONFIRMED:** błąd istniał w logice [syncSupplierProposal()](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:636).

## 3. Korekta 3C-AUD-001

Nowa kolejność:

1. jawny `supplier_ids` jest zawsze autorytatywny;
2. dla bazy bez approved statusu preferowany jest proposal dokładnej rewizji bazy;
3. approved snapshot jest fallbackiem tylko wtedy, gdy brak proposal dla approved base;
4. `NOT_PROVIDED` nie jest interpretowany jako approved empty;
5. legacy association nie jest fallbackem ani snapshotem.

Implementacja:

- parametr `copyApprovedRevisionId` został zamieniony na `copyBaseRevisionId`;
- [base revision selection](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:641);
- [proposal-before-snapshot inheritance](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:670);
- [revise passes exact baseRevisionId](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2216);
- [restore keeps approved snapshot fallback](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2805).

Kontrakt:

- proposal EMPTY/NONEMPTY bazy jest przenoszony do nowej rewizji;
- proposal NOT_PROVIDED może fallbackować do approved snapshot only for approved base;
- legacy-only data pozostaje NOT_PROVIDED.

## 4. Mandatory regression 3C-AUD-001

Dodano test:

- [test_returned_proposal_survives_new_revision_without_supplier_input](/home/veltra/projekty/veltra-pa-07b3c/backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php:275).

Testowy przepływ:

1. approved baseline;
2. revise supplier B;
3. submit;
4. return;
5. revise without supplier_ids;
6. submit;
7. approve;
8. BOSS returns supplier B.

Dodano także:

- explicit supplier replacement test;
- explicit empty snapshot test;
- legacy-only null test;
- immutable snapshot update/delete test.

## 5. Root cause 3C-AUD-002

Pierwotna migracja 057 walidowała ownership/state/count, ale proposal header i items nie sprawdzały atomicznie:

- czy rewizja jest exact working revision;
- czy materiał jest deleted;
- czy rewizja jest pending/approved;
- czy proposal mutation współbiega z submit/approve przez org lock.

### 5.1 Defect outside database

Laravel proposal sync wykonywał update items/header bez twardego PostgreSQL lifecycle guard.

### 5.2 Database surface

Proposal tables mogły być mutowane przez signed runtime scope `raw-material.mutate`, bez sprawdzenia lifecycle pointerów.

## 6. Korekta 3C-AUD-002

Migracja 057 została poprawiona w:

- [proposal header guard](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:82);
- [proposal item guard](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:125);
- [item consistency trigger](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:188);
- [header/item consistency](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:227);
- [snapshot guards](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:252);
- [HMAC scopes and privileges](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:398).

### Lifecycle guard

Proposal header/item:

- blokuje organization row `FOR UPDATE`;
- wymaga exact revision/revision_id + organization/material;
- wymaga `materials.working_revision_id = revision.id`;
- odrzuca revision pending/approved/obsolete;
- odrzuca deleted material;
- DELETE permitted only for exact working revision and accepted draft delete workflow.

Proposal item consistency:

- NOT_PROVIDED/EMPTY require zero items;
- NONEMPTY require at least one item;
- item mutation and replacement is deferred consistently.

Snapshot guard:

- locks organization row;
- rejects snapshot update/delete;
- requires approval event/revision/hash consistency;
- item guard rejects inactive or wrong-org supplier.

## 7. Delete draft correction

Draft delete:

- deletes proposal items and header before deleting material;
- uses existing `raw-material.draft.delete` HMAC scopes;
- header/item guards accept exact working revision while delete transaction is active.

Źródło: [deleteDraft cleanup](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2640).

## 8. Zmiany plików

### Aplikacja

- `backend/app/Domain/RawMaterials/RawMaterialService.php`
- `backend/app/Http/Requests/RawMaterialDataRequest.php`
- `backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php`
- `backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php`

### Migracja

- `backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`

### Testy

- `backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php`
- `backend/tests/Feature/BossRawMaterialCatalogApiTest.php`

### Raporty

- `VELTRA_PA_STAGE_07B3C_CORRECTION_REPORT_2026-11-21.md`

Nie zmieniono migracji 001–056.

## 9. Weryfikacja testowa

### PHP syntax

- `RawMaterialService.php`: PASS
- migration 057: PASS
- provenance test: PASS

### Clean migration

- fresh PostgreSQL database;
- migrations 001–057: PASS;
- migration 057 applied correctly.

### Synthetic forward upgrade

- migrations 001–056: PASS;
- synthetic legacy supplier association, active supplier and approved revision inserted;
- migration 057: PASS;
- legacy association count unchanged: 1;
- approved revision pointer unchanged;
- approval content hash unchanged;
- approved snapshots: 0;
- snapshot items: 0;
- proposal headers/items: 0.

Forward-upgrade result: PASS.

### Mandatory lifecycle tests

4 testy:

- returned proposal carry;
- signed proposal mutation after submit;
- direct proposal mutation after submit;
- signed proposal mutation after approval.

Wynik:

- **4 tests**
- **9 assertions**
- **PASS**

### Snapshot/API suites

- **18 tests**
- **133 assertions**
- **PASS**

### BOSS authorization/concurrency

- **14 tests**
- **136 assertions**
- **PASS**

### RAW supplier/approval/revision filtered suite

- **32 tests**
- **449 assertions**
- **PASS**

### Runtime role adversarial

- ticketless proposal insert: lifecycle guard returned 23514 in fixture;
- runtime snapshot UPDATE: denied by privilege;
- runtime snapshot DELETE: denied by privilege;
- proposal mutation after submit: 23514;
- proposal mutation after approval: 23514.

No snapshot UPDATE/DELETE privilege remains for runtime role.

## 10. Concurrency evidence

Existing BOSS concurrency suite passed:

- 14 tests;
- 136 assertions.

Dedicated proposal mutation vs submit/approve concurrency harness was not added. The database guard and organization lock provide serialization, but a deterministic multi-process proposal race test was not executed.

This is a remaining limitation, not an unresolved confirmed vulnerability.

## 11. Pozostałe blockers

- dedicated proposal-vs-submit and proposal-vs-approve concurrency harness;
- proposal-vs-revise and archive-vs-approve concurrency;
- real existing database upgrade state;
- full backend suite.

Nie znaleziono kontradictory behavior requiring accepted architecture changes.

## 12. Final self-assessment

**READY FOR INDEPENDENT RE-REVIEW**

Osiągnięte:

- 3C-AUD-001 resolved;
- 3C-AUD-002 resolved;
- migration 057 lifecycle guard added;
- clean migration pass;
- synthetic forward upgrade pass;
- snapshot/API and RAW regressions pass;
- runtime snapshot mutability denied.

Pozostaje niezależny re-review zarejestrowanych dowodów i dedicated concurrency harness.

## 13. Zakończenie

Nie wykonano commita, push, merge, deploymentu, migracji na istniejącej bazie ani aktywacji capability/module.

Disposable bazy użyte do testów i upgrade zostały usunięte lub pozostają wyłącznie jako disposable test containers.
