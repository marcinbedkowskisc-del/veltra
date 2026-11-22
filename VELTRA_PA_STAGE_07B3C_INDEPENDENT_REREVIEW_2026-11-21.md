# VELTRA 2.0 — PA-07B.3C — niezależny końcowy re-review

**Data:** 2026-11-21
**Worktree:** `/home/veltra/projekty/veltra-pa-07b3c`
**Branch:** `pa-07b3c-approved-supplier-snapshots`
**HEAD:** `d0d22e65df49fc4e8744ca626ac363126447e36d`
**Werdykt:** **REJECT**

## 1. Zweryfikowana baza Git

**CONFIRMED**

- Branch: `pa-07b3c-approved-supplier-snapshots`
- HEAD: `d0d22e65df49fc4e8744ca626ac363126447e36d`
- Przechodzące śledzone zmiany i nowa migracja są zgodne z opisem implementacji PA-07B.3C.
- `git status` nie wykazał commitu ani zmian Git history.
- Bieżący diff dotyczy:
  - `backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php`
  - `backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php`
  - `backend/app/Domain/RawMaterials/RawMaterialService.php`
  - `backend/app/Http/Requests/RawMaterialDataRequest.php`
  - `backend/tests/Feature/BossRawMaterialCatalogApiTest.php`
  - `backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php`
  - nowa migracja `backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`
- Migracje 001–056 nie zostały zmodyfikowane. Nie wykonano commita, push, merge, rebase, deployu ani aktywacji capability.

## 2. Status 3C-AUD-001

**3C-AUD-001: VERIFIED FIXED**

**CONFIRMED**

Scenariusz A → B → submit → return → revise bez `supplier_ids` → submit → approve został wykonany w indywidualnym disposably PostgreSQL/PHP suite i zakończył się poprawnym zachowaniem supplier setu B.

Dowody:

- [BossRawMaterialCatalogProvenanceTest.php:275](/home/veltra/projekty/veltra-pa-07b3c/backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php:275) testuje zwrócony proposal przechodzący przez nową rewizję.
- [BossRawMaterialCatalogProvenanceTest.php:345](/home/veltra/projekty/veltra-pa-07b3c/backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php:345) testuje explicit empty i explicit replacement.
- [BossRawMaterialCatalogProvenanceTest.php:612](/home/veltra/projekty/veltra-pa-07b3c/backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php:612) testuje explicit empty snapshot.
- [RawMaterialService.php:633](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:633) implementuje osobną kolejność inheritance.
- [RawMaterialService.php:670](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:670) najpierw rozróżnia approved base i proposal bazy.
- [RawMaterialService.php:702](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:702) preferuje proposal exact base revision przed approved snapshotem.
- [RawMaterialService.php:720](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:720) zachowuje proposal dla aktualnej rewizji.

Niezależny wynik:

- `BossRawMaterialCatalogProvenanceTest`: **13 passed**, **57 assertions**.
- Obejmuje zwrócony proposal, explicit empty, replacement, legacy association, snapshot immutability i approved snapshot/live name.
- Potwierdzono, że stary approved snapshot A nie nadpisuje proposal B po return/revise bez jawnego `supplier_ids`.

## 3. Status 3C-AUD-002

**3C-AUD-002: STILL OPEN**

### Potwierdzony brak ochrony cross-revision

Guard proposal header sprawdza dla `NEW.organization_id` i `NEW.revision_id`, czy row target jest aktualną working revision:

- [migracja 057:82](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:82)
- [migracja 057:90](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:90)

Guard nie sprawdza spójności `OLD.revision_id` i `NEW.revision_id` ani unikalnej instancji proposal. W wyniku podpisany runtime UPDATE może przenieść istniejący header proposal na inną rewizję, jeżeli nowy `revision_id` jest w tym samym czasie working revision tej organizacji.

Analogiczny problem dotyczy proposal items:

- [migracja 057:125](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:125)
- [migracja 057:163](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:163)

Niezależny disposable test wykazał:

1. Podpisany UPDATE proposal przeniósł approved proposal na working revision i commitował.
2. Analogiczny UPDATE item commitował po przeniesieniu itemu.
3. Po submit i approval mutacja bezpośrednio dla wskazanej pending/approved rewizji była odrzucana `23514`, co potwierdza część guardu.
4. Brak ticketu nadal jest odrzucany `42501`.
5. Runtime snapshot UPDATE/DELETE było odrzucane przez role privilege `42501`.

**CONFIRMED BLOCKER:** lifecycle guard chroni mutację na pending/approved rewizji, ale nie chroni integralności relacji proposal/item wobec zmiany `revision_id` lub cross-revision UPDATE.

Realistyczny skutek: SQL podpisany przez aktywny prawidłowy scope może utworzyć proposal pod innym working/pending contextem i doprowadzić do niespójnego stanu approve/snapshot. To blokuje lokalną akceptację.

## 4. Deterministyczne testy współbieżności

**CONFIRMED / NOT VERIFIED**

Niezależny disposable harness PostgreSQL 17.12 wykonał 10 przeplotów i 43 asercje:

- proposal → submit: PASS;
- submit → proposal: proposal po submit odrzucone `23514`;
- proposal → approval: PASS i odpowiednio odrzucone guardem;
- approval → proposal: proposal po approval odrzucone `23514`;
- revise → submit oraz submit → revise: PASS pod kontrolem konfliktu wersji;
- revise → approval oraz approval → revise: PASS;
- archive → approval: approval odrzucił zarchiwizowanego dostawcę;
- approval → archive: approval utworzył snapshot, a kolejne archive pozostawiło snapshot ID-history immutability.

Dowód wskazuje też na poprawne lockowanie organization row i brak niespójnego approved pointera dla przetestowanych przeplotów.

**NOT VERIFIED:**

- mutacja proposal header i items poza opisanymi prostymi UPDATE/submit/approve scenariuszami;
- pełna reprodukcja wszystkich możliwych kolejności revise/proposal;
- końcowa liczba approval events we wszystkich przeplotach archive/approve;
- wszystkie możliwe cross-revision UPDATE payloads.

## 5. Migracja 057 i upgrade

**CONFIRMED**

- Czysta migracja 001–057: **PASS**.
- PostgreSQL: PostgreSQL 17.
- Synthetic upgrade 056→057: **PASS**.
- Zachowane:
  - legacy supplier association;
  - approval event;
  - approval content hash;
  - approved pointer.
- Po upgrade: 0 proposal headers, 0 proposal items, 0 approved snapshots i 0 snapshot items.
- Migracja nie backfilluje legacy danych.
- Runtime proposal HMAC scope i ticketless rejection działały zgodnie z migracją.
- Snapshoty i items są chronione przez ograniczone privileges oraz immutability guards:
  - [migracja 057:252](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:252)
- Snapshot guards wymagają zgodności approval event/revision/hash.
- Migracja 057 nie zmieniła migracji 001–056.

**NOT VERIFIED:** stan faktycznie zastosowanych migracji i danych w produkcji/staging.

## 6. PostgreSQL runtime i HMAC

**CONFIRMED**

- Role runtime i migracyjna nie są superuserami.
- Runtime ma INSERT/UPDATE/DELETE proposal i proposal items.
- Runtime ma SELECT/INSERT snapshotów, bez UPDATE/DELETE.
- Migration 057 dodaje scopes `raw-material.mutate` i `raw-material.draft.delete`.
- Ticketless proposal INSERT: `42501`.
- Signed proposal mutacja na pending/approved rewizji: `23514`.
- Cross-revision signed UPDATE: commituje; opisane w 3C-AUD-002.
- Snapshot runtime UPDATE/DELETE: denied.
- Role i scopes wymagają osobnej live-DB weryfikacji przed deploymentem.

## 7. Niezależna regresja

Wyniki faktycznie wykonane sekwencyjnie:

| Test suite | Wynik |
|---|---|
| `BossRawMaterialCatalogProvenanceTest.php` | 13 passed, 57 assertions |
| `BossRawMaterialCatalogAuthorizationTest.php` | 10 passed, 47 assertions |
| `BossRawMaterialCatalogConcurrencyTest.php` | 4 passed, 89 assertions |
| `RawMaterialsTest.php` | 161 passed, 1934 assertions |

Migracja i disposable concurrency:

- clean 001–057: PASS;
- synthetic 056→057: PASS;
- 10 deterministic concurrency patterns: PASS;
- clean signed/unsigned proposal and snapshot primitives: częściowo PASS, z blokującym cross-revision defect.

## 8. Potwierdzony blokujący defekt

**NOWY/BLOKUJĄCY STATUS: 3C-AUD-002 pozostał open**

Defekt jest potwierdzony w migracji 057 i dotyczy UPDATE cross-revision. Guard sprawdza nową rewizję, ale nie wiąże jednego proposal z immutable `OLD.revision_id` i nie chroni pary proposal/items przed przeniesieniem.

Nie stwierdzono innych blokujących defektów integralności w testowanych surface’ach.

## 9. Ryzyka i ograniczenia

- Pełny backend nie był wymagany i nie był wykonywany.
- Nie sprawdzono realnej istniejącej produkcyjnej/stagingowej bazy.
- Nie wykonanio wszystkich wariantów proposal item DML ani pełnej kombinatoryki approval/archive/revise.
- Równoległe uruchomienie dwóch klas na wspólnym fixture wykazało sztuczne `40P01`; ponowne sekwencyjne reruny przechodziły.
- Teartyfikacja 3C-AUD-001 jest wystarczająco potwierdzona.
- 3C-AUD-002 nie może zostać uznany za fixed do czasu korekty cross-revision immutable identity i ponownego testu.

## 10. Statusy oddzielne

- **Akceptacja implementacji lokalnej:** REJECT.
- **Commit readiness:** NOT READY.
- **Production deployment readiness:** NOT READY.
- **Capability activation readiness:** NOT READY.
- `BOSS_RAW_CATALOG_READ`: **NOT_READY**.
- `RAW_MATERIALS`: **NOT_READY**.

## 11. Werdykt końcowy

**REJECT**

ACCEPT wymagałby potwierdzonych fixów dla obu HIGH findings bez blokującego defektu integralności. 3C-AUD-001 został potwierdzony jako naprawiony. 3C-AUD-002 pozostał open z powodu podpisanego cross-revision UPDATE, który może zmienić proposal po submit i pozostawić proposal/items w niespójnym stanie.

## 12. Zakończenie

Nie zmieniono application source, testów, migracji, konfiguracji ani sekretów. Nie wykonano commita, push, merge, rebase ani deployu. Nie zmodyfikowano istniejącej aplikacyjnej bazy danych, persistent volumes, Docker resources, capability ani modułów. Wszystkie testy i disposable database worktree zostały uruchomione wyłącznie na izolowanym PostgreSQL 17.
