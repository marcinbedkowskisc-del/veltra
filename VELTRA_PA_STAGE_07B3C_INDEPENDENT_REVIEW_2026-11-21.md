# VELTRA 2.0 — PA-07B.3C — niezależny przegląd implementacji

**Data:** 2026-11-21
**Worktree:** `/home/veltra/projekty/veltra-pa-07b3c`
**Branch:** `pa-07b3c-approved-supplier-snapshots`
**HEAD:** `d0d22e65df49fc4e8744ca626ac363126447e36d`
**Status:** INDEPENDENT REVIEW COMPLETE

## 1. Podsumowanie wykonawcze

Audyt wykazał dwa istotne problemy:

1. **Potwierdzony błąd lifecycle proposal po `returnForChanges()`:** edycja rewizji tworzy nową rewizję, ale `syncSupplierProposal()` może skopiować stare approved snapshot zamiast zachować proposal powracającej rewizji. Ponowna rewizja i approval mogą zatwierdzić niewłaściwy zestaw dostawców.
2. **Luka integracyjna tabel proposal w PostgreSQL:** proposal header i items mogą być mutowane przez signed runtime scope bez sprawdzenia, czy rewizja jest aktualną working/pending rewizją i czy nie została już approved. Bez poprawnego guardu możliwe jest zmienienie proposal po submit przed approval.

Pozostałe mechanizmy:

- migracja 057 działa na czystej bazie i na synthetic upgrade 056→057;
- legacy association pozostaje niezmieniona i nie jest backfillowana;
- approved snapshot jest niemutowalny;
- runtime bez ticketu nie może zapisać proposal/snapshot;
- runtime nie ma UPDATE/DELETE na snapshot tables;
- DTO BOSS nie wycieka supplier ID ani danych prywatnych;
- BOSS live name działa dla snapshot identity;
- HMAC pozostaje bezpośrednio kompatybilny z nowym DML.

**Werdykt: REJECT**

PA-07B.3C nie jest gotowe do akceptacji, dopóki błąd carry-over proposal i DB lifecycle guard nie zostaną naprawione i ponownie przetestowane.

## 2. Zweryfikowana baza Git i diff

**CONFIRMED:**

- branch: `pa-07b3c-approved-supplier-snapshots`;
- HEAD: `d0d22e65df49fc4e8744ca626ac363126447e36d`;
- diff względem bazowego SHA ma 6 zmodyfikowanych śledzonych plików;
- migration 057 jest nowym plikiem nieśledzonym;
- implementation report jest nowym plikiem nieśledzonym;
- `git diff --check` nie zgłasza błędów formatowania;
- istniejący worktree PA-07B.3B nie został zmieniony.

Łączna liczba zmian implementacyjnych do audytu:

- 6 zmodyfikowanych tracked PHP/test files;
- 1 nowa migracja;
- 1 implementation report.

Nie wykonano commita ani zmiany Git history.

## 3. Migracja 057

### 3.1 Definicje

Migracja tworzy:

- `raw_material_revision_supplier_proposals`;
- `raw_material_revision_supplier_proposal_items`;
- `raw_material_approved_supplier_snapshots`;
- `raw_material_approved_supplier_snapshot_items`.

Źródła: [migration 057, proposed tables](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:17), [snapshot tables](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:47).

### 3.2 Composite ownership

Potwierdzono:

- proposal header ma `(revision_id, raw_material_id, organization_id)` FK;
- proposal items mają FK do proposal header `(organization_id, revision_id)`;
- items mają FK supplier `(supplier_id, organization_id)`;
- snapshots mają composite FK revision/material/organization;
- snapshot items mają composite FK snapshot/revision/organization i supplier/organization;
- unique `(organization_id, revision_id)` zapewnia jeden proposal/snapshot na rewizję;
- unique `(organization_id, revision_id, supplier_id)` eliminuje duplikaty.

### 3.3 State integrity

Potwierdzono:

- proposal state CHECK: `NOT_PROVIDED`, `PROPOSED_EMPTY`, `PROPOSED_NONEMPTY`;
- snapshot state CHECK: `APPROVED_EMPTY`, `APPROVED_NONEMPTY`;
- deferred consistency trigger wymaga zgodności stanu i liczby items;
- proposal item trigger odrzuca itemy przy stanie innym niż `PROPOSED_NONEMPTY`;
- approved snapshot item trigger odrzuca itemy przy stanie innym niż `APPROVED_NONEMPTY`;
- snapshot update/delete odrzuca oba snapshot header i items.

Źródła: [proposal consistency](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:143), [snapshot immutability](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:168), [snapshot consistency](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:246).

### 3.4 Snapshot approval linkage

Snapshot guard sprawdza:

- approval event exists;
- `approval_event.organization_id == snapshot.organization_id`;
- `approval_event.revision_id == snapshot.revision_id`;
- `approval_event.operation_id == snapshot.approval_operation_id`;
- `approval_event.outcome == APPROVED`;
- `approval_event.content_hash == revision.content_hash`.

Nie ma composite FK approval event `(id, revision_id, organization_id)`, ale trigger zapewnia tę zgodność przy INSERT. Snapshot jest niemutowalny po insert.

### 3.5 HMAC i privileges

Nowe proposal/snapshot:

- proposal header/items: runtime `SELECT, INSERT, UPDATE, DELETE`;
- snapshot header/items: runtime `SELECT, INSERT`, bez `UPDATE/DELETE`;
- snapshot i proposal HMAC scopes to `raw-material.mutate`;
- proposal item DELETE and snapshot INSERT are explicitly HMAC-triggered.

Źródło: [HMAC scopes i grants](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:297), [runtime revoke/grants](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:320).

Ticketless runtime test:

- `INSERT proposal`: SQLSTATE `42501`;
- snapshot UPDATE: NO privilege;
- snapshot DELETE: NO privilege.

### 3.6 Forward-only behavior

Migracja nie edytuje 022–056 i ma `down()` rzucający wyjątek. Ogranicza to przywrócenie do osobnej, forward-only korekty.

## 4. Forward upgrade 056→057

**WYNIK: PASSED — synthetic isolated PostgreSQL 17**

Wykonano:

- czysta baza `veltra_upgrade057`;
- migracje 001–056;
- synthetic organization, user, category, active supplier, RAW material, revision, approval event i legacy association;
- snapshot pre-migration count: 0;
- migracja 057: PASS;

Po migracji:

- legacy association count: **1**;
- approved revision pointer: unchanged;
- approval hash: unchanged;
- proposed headers: **0**;
- proposed items: **0**;
- approved snapshots: **0**;
- approved snapshot items: **0**;
- snapshot tables: **4**.

**CONFIRMED:** migracja 057 nie backfilluje danych ani nie fabrykuje approval evidence.

**NOT VERIFIED:** faktyczna baza produkcyjna/staging i realne legacy datasets.

## 5. Proposal lifecycle

### 5.1 Create, imported draft i ordinary revise

Create/import/ordinary revise zapisują proposal w tej samej transakcji co revision:

- [create](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:1835);
- [import](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:1922);
- [revise](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2205).

Odmowa pustego supplier set bez explicit confirmation działa: [proposal validation](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:636).

### 5.2 Return for changes

`returnForChanges()` zachowuje pending revision jako working revision:

- [returnForChanges](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2345).

Proposal pozostaje powiązany z tą rewizją.

### 5.3 Finding A — utrata proposal po return/edytorze

**Finding ID:** 3C-AUD-001

**Severity:** HIGH

**Prawdopodobne business impact:** Re-approval może zatwierdzić approved snapshot poprzedniej rewizji zamiast supplier proposal w rewizji zwróconej do zmian.

**Źródło:**

- [reviseWithPermission](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2205);
- [syncSupplierProposal](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:636);
- [snapshot-first copy](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:670);

**Aktualne zachowanie:**

1. `reviseWithPermission()` tworzy nową rewizję o nowym `revisionId`.
2. przekazuje jako `copyApprovedRevisionId` obecny `approved_revision_id`, a nie aktywną/zwróconą rewizję bazową.
3. `syncSupplierProposal()` najpierw szuka snapshotu dla przekazanego approved revision.
4. jeżeli snapshot istnieje, kopiuje jego state/items do nowego proposal i nie sprawdza istniejącego proposal zwróconej rewizji.
5. proposal tej zwróconej rewizji może zostać zastąpiony snapshotem approved poprzedniej rewizji.

**Przykład reprodukcji:**

- approved supplier set: A;
- właściciel proponuje w zwróconej rewizji supplier set B;
- return zachowuje proposal B na pending/working revision;
- kolejna rewizja bez jawnego `supplier_ids` tworzy nową rewizję;
- kod kopiuje snapshot A;
- re-approval tworzy snapshot A.

**Wniosek:** potwierdzony defekt logiczny.

**Minimalna korekta:**

- w revise najpierw sprawdzić proposal dla dokładnego `baseRevisionId/working revision`;
- copy approved snapshot tylko dla nowej rewizji bez istniejącego proposal i bez jawnym supplier input;
- dla zwróconej rewizji zachować jej proposal przy tworzeniu kolejnej rewizji;
- dodać test: return → revise bez supplier_ids → approve → snapshot remains B, not A.

**Blokuje acceptance:** TAK.

## 6. Approval atomicity

Approve lockuje organizację i weryfikuje:

- pending revision;
- approval content hash;
- proposal;
- supplier IDs;
- supplier ACTIVE.

Approval event jest wstawiany przed snapshotem, następnie snapshot header/items, następnie approved pointer:

- [approve](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:2388);
- [snapshot creation](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:777).

Poprawna aktywność supplierów jest sprawdzana i w PHP, i w triggerze snapshot item:

- [snapshot item guard](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:220).

Całość działa w jednej transakcji Laravel. Jeśli snapshot insert/items lub pointer fail, rollback cofa approval event i pointer.

Potwierdzony błąd carry-over proposal może prowadzić do snapshotu B→A i jest blockujący.

## 7. Proposal mutation after submit

**Finding ID:** 3C-AUD-002

**Severity:** HIGH

**Source:** [proposal guard](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:82), [item guard](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:109), [scope/grants](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:297).

Proposal guard sprawdza tylko:

- revision exists;
- organization/material/revision relation;
- deleted material not necessarily.

Nie sprawdza:

- `revision_id == raw_materials.working_revision_id`;
- `revision_id == raw_materials.pending_revision_id`;
- revision not already approved;
- no mutation after submit unless workflow permits.

Proposal header and items runtime grants allow signed `raw-material.mutate` UPDATE/INSERT/DELETE. Snapshot approval reads proposal and snapshots it.

**Realistic abuse:**

- SQL injection or other code in an active HMAC ticket can mutate proposal table after submit;
- approval reads modified proposal and creates snapshot from false set;
- ticketless direct runtime SQL is denied, which is confirmed.

**Minimal correction:**

- add proposal mutation guard that allows:
  - working revision for create/edit;
  - returned revision when explicitly returned;
  - reject pending and approved revision except explicit review/return workflow;
- enforce atomically in header/item guards;
- invalidate proposal items at submit/return based on approved contract.

**Blocks acceptance:** TAK.

## 8. Concurrency

Niezależnie wykonano:

- `BossRawMaterialCatalogAuthorizationTest.php`;
- `BossRawMaterialCatalogConcurrencyTest.php`;
- wyniki: **14 tests, 136 assertions, PASS**.

Nie uruchomiono nowych równoległych testów approve/archive/proposal. Nie znaleziono jednak źródłowego lock-order defectu w istniejących zmianach:

- approval uses organization `FOR UPDATE`;
- supplier correction/archive uses HMAC/commercial flow and transaction organization locking;
- snapshot is inserted in the same approval transaction.

Poprzedni SQLSTATE `40P01` przez implementera był wynikiem równoległego użycia tej samej disposable bazy fixture setupów; po sekwencyjnym rerunie nie powtórzył się. Nie jest potwierdzony jako application deadlock.

**NOT VERIFIED:** dedicated approve vs revise, archive vs approve i proposal replacement concurrency.

## 9. BOSS API and leakage

Potwierdzono:

- list/detail add `supplier_names`;
- `BossRawMaterialCatalogItem` serializuje tylko existing PA-07B.3B fields plus supplier_names;
- snapshot nonempty resolves live display_name/legal_name;
- snapshot empty -> `[]`;
- no snapshot/legacy -> `null`;
- proposal never joins BOSS query.

Źródła: [DTO](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php:27), [snapshot join and projection](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php:244).

Niezależny test suite BOSS:

- 13 tests;
- 122 assertions;
- PASS.

No supplier IDs, contacts, tax identifier, prices or costs are selected.

## 10. Supplier directory semantics

Potwierdzono:

- snapshot stores supplier identity ID, not display name;
- BOSS uses current display_name/legal_name;
- archived supplier remains visible by snapshot join;
- only active supplier can be newly inserted into proposal/snapshot;
- legacy supplier association remains separate.

Potential behavior after supplier deletion: supplier deletion not permitted due existing immutable/foreign key conventions. This is outside direct finding.

## 11. Legacy compatibility

Forward upgrade synthetic test passed:

- legacy association count unchanged;
- approved pointer unchanged;
- approval hash unchanged;
- no snapshot or proposal rows created;
- no backfill.

Ordinary RAW behavior remains expected from existing filtered suite:

- 32 filtered tests;
- 449 assertions;
- PASS.

## 12. Independent test evidence

### Own reruns

- BOSS snapshot/API: 13 tests / 122 assertions / PASS;
- BOSS auth/concurrency: 14 tests / 136 assertions / PASS;
- filtered RAW supplier/approval/revision: 32 tests / 449 assertions / PASS;
- clean migration: PASS;
- forward upgrade 056→057: PASS;
- ticketless runtime proposal INSERT: SQLSTATE 42501;
- runtime snapshot UPDATE/DELETE: no privilege.

### Initial implementation claim cross-check

Implementer result claims were not copied as audit evidence. Every PASS above was independently rerun by this audit.

## 13. Severity summary

### HIGH

- 3C-AUD-001: proposal loss after return/revise and false approved snapshot;
- 3C-AUD-002: proposal mutation guard missing pending/approved lifecycle check.

### MEDIUM

- None confirmed.

### LOW

- None confirmed.

### NOT VERIFIED

- dedicated concurrent approve/revise/archive/proposal replacement tests;
- real installed staging/production database state;
- full backend suite.

## 14. Required corrections

Przed acceptance:

1. Naprawić carry-over proposal:
   - pass exact base/working/pending revision or new revision's existing proposal;
   - copy approved snapshot only if no existing proposal;
   - preserve B proposal through return→revise→approval.
2. Dodać PostgreSQL lifecycle guard:
   - proposal UPDATE/INSERT/DELETE only for working/returned revision allowed by contract;
   - reject pending and approved revision mutations;
   - cover proposal item mutations;
3. Dodać testy regresyjne:
   - return→revise without supplier_ids;
   - approved snapshot A, proposal B, reapproval result B;
   - attempt proposal mutation after submit;
   - attempt proposal mutation after approval;
   - concurrent revise/submit/approve;
   - concurrent archive/approve.
4. Potwierdzić after corrected implementation through independent rerun.

## 15. Final verdict

**REJECT**

Uzasadnienie:

- potwierdzony bug może spowodować false approved supplier snapshot;
- potwierdzony brak guardu może dopuścić zmianę proposal after submit przez active signed transaction;
- forward upgrade PASS;
- clean migration PASS;
- HMAC runtime boundary PASS;
- BOSS data leakage checks PASS;
- business approval integrity nie jest jeszcze sufficient for acceptance.

Technical review status: NOT ACCEPTABLE until corrections and independent rerun.

Production readiness remains outside scope and is not declared.

## 16. Zakończenie audytu

Nie zmieniono application code, migrations, tests, configuration, Git history, existing application databases, Docker/preserved volumes, readiness lub capability activation.

Utworzono i usunięto disposable database `veltra_upgrade057`.
