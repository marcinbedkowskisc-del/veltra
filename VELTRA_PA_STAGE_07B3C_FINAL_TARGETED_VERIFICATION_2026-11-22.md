# VELTRA 2.0 — PA-07B.3C — finalna ukierunkowana weryfikacja

**Data:** 2026-11-22
**Worktree:** `/home/veltra/projekty/veltra-pa-07b3c`
**Branch:** `pa-07b3c-approved-supplier-snapshots`
**HEAD:** `d0d22e65df49fc4e8744ca626ac363126447e36d`
**Werdykt:** **ACCEPT**

## 1. Zweryfikowana baza Git

**CONFIRMED**

- Branch: `pa-07b3c-approved-supplier-snapshots`
- HEAD: `d0d22e65df49fc4e8744ca626ac363126447e36d`
- HEAD odpowiada expectowanemu commitowi re-review.
- Worktree zawiera implementację PA-07B.3C i korektę migracji 057.
- `git diff --check`: PASS.
- Nie wykonano commitu, push, merge, rebase ani stasha.

## 2. Niemutowalna tożsamość proposal header

**CONFIRMED FIXED**

W [migracji 057:97](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:97):

- `NEW.id IS DISTINCT FROM OLD.id`
- `NEW.organization_id IS DISTINCT FROM OLD.organization_id`
- `NEW.raw_material_id IS DISTINCT FROM OLD.raw_material_id`
- `NEW.revision_id IS DISTINCT FROM OLD.revision_id`
- `NEW.created_by IS DISTINCT FROM OLD.created_by`
- `NEW.created_at IS DISTINCT FROM OLD.created_at`

Każda różnica powoduje `SQLSTATE 23514`.

Po potwierdzeniu immutable identity guard:

- dla UPDATE ponownie ustawia `target_org := OLD.organization_id`;
- dla UPDATE ponownie ustawia `target_revision := OLD.revision_id`;
- lifecycle jest następnie weryfikowany względem oryginalnej rewizji.

Dla DELETE guard używa OLD organization/revision. Dla INSERT guard używa NEW target i wymaga exact `working_revision_id`.

## 3. Niemutowalna tożsamość proposal item

**CONFIRMED FIXED**

W [migracji 057:159](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:159):

- `NEW.id IS DISTINCT FROM OLD.id`
- `NEW.organization_id IS DISTINCT FROM OLD.organization_id`
- `NEW.revision_id IS DISTINCT FROM OLD.revision_id`
- `NEW.supplier_id IS DISTINCT FROM OLD.supplier_id`

Każda różnica powoduje `23514`.

Po potwierdzeniu immutable identity:

- lifecycle itemu używa OLD organization/revision dla UPDATE;
- DELETE używa OLD context;
- INSERT używa NEW context i exact working revision;
- replacement suppliera jest nadal realizowany przez DELETE + INSERT.

## 4. Niezależna reprodukcja oryginalnego exploita

**CONFIRMED REJECTED**

Niezależny disposable PostgreSQL 17 harness potwierdził:

| Próba | Wynik |
|---|---|
| podpisany cross-revision UPDATE proposal header | `23514` |
| podpisany cross-revision UPDATE proposal item | `23514` |
| zmiana `organization_id` header/item | rejected |
| zmiana `raw_material_id` header | rejected |
| zmiana `revision_id` header/item | rejected |
| zmiana `supplier_id` item | rejected |
| zmiana `id`, `created_by`, `created_at` header | rejected |
| zmiana na historical/obsolete proposal | `23514` |
| mutacja po submit | `23514` |
| mutacja po approval | `23514` |
| ticketless mutation | `42501` |
| snapshot UPDATE/DELETE | denied przez guard/privilege |

Odrzucone statements nie zmieniły:

- proposal header count;
- proposal item count;
- proposal/item zawartości;
- approved pointerów.

Potwierdzono, że poprzedni signed cross-revision reassignment nie jest już możliwy.

**NOT VERIFIED:** realna baza produkcyjna/stagingowa i dane legacy, ponieważ weryfikacja wykonała tylko disposable PostgreSQL.

## 5. Dopuszczalne operacje

Niezależnie potwierdzono:

- editable proposal state UPDATE przez signed runtime: PASS;
- supplier replacement jako DELETE + INSERT: PASS;
- zwrócona rewizja może nadal być edytowana;
- returned proposal inheritance: PASS;
- explicit empty proposal: PASS;
- draft deletion guard: PASS;
- submit blokuje dalszą mutację: PASS;
- approval blokuje dalszą mutację: PASS;
- approved snapshots są immutable;
- 3C-AUD-001 nie uległ regresji.

## 6. Migracja i upgrade

W disposable PostgreSQL 17:

- clean migration 001–057: PASS;
- synthetic forward upgrade 056→057: PASS;
- legacy supplier association preserved;
- approval pointer preserved;
- approval hash preserved;
- 0 proposal/snapshot rows after synthetic upgrade.

Migracja 057 pozostaje uncommitted i nie jest stosowana do istniejącego persistent environment.

## 7. Rezultaty testów

Niezależne sekwencyjne uruchomienia:

| Suite | Wynik |
|---|---|
| `BossRawMaterialCatalogProvenanceTest.php` | 15 passed, 73 assertions |
| `BossRawMaterialCatalogApiTest.php` | 5 passed, 76 assertions |
| `BossRawMaterialCatalogAuthorizationTest.php` | 10 passed, 47 assertions |
| `BossRawMaterialCatalogConcurrencyTest.php` | 4 passed, 89 assertions |
| `RawMaterialsTest.php` | 161 passed, 1934 assertions |

Poprzednio wykonywany deterministic concurrency matrix objął 10 przeplotów i 43 asercje PASS.

Zmiana dotyczy tylko guardów identity i nie zmienia kolejności organization lock, dlatego powtórzenie całego concurrency matrix nie było potrzebne. Niezależny disposable harness potwierdził brak nowego lock-order/transaction risk.

## 8. Pozostałe znalezione defekty

Nie potwierdzono nowych HIGH/blocked defects w zakresie 3C-AUD-002.

Pozostające ograniczenia:

- nie weryfikowano prawdziwego produkcyjnego/stagingowego stanu migracji;
- commit i production deployment pozostają poza zakresem;
- pełny backend regression nie był wykonywany;
- `BOSS_RAW_CATALOG_READ`: `NOT_READY`;
- `RAW_MATERIALS`: `NOT_READY`.

## 9. Statusy findingów

- **3C-AUD-001:** `VERIFIED FIXED`
- **3C-AUD-002:** `VERIFIED FIXED`

## 10. Werdykt końcowy

**ACCEPT**

Warunki ACCEPT są spełnione dla scope lokalnego:

1. oryginalny signed cross-revision exploit jest odrzucany;
2. header i item identity są immutability;
3. no remaining confirmed HIGH defect in target scope;
4. returned proposal, draft, approval and supplier replacement still work;
5. relevant regressions pass.

To jest technical local acceptance, nie production deployment readiness.

## 11. Zakończenie

Nie zmieniono application source, testów, migracji, konfiguracji ani sekretów.

Nie wykonano commita, push, merge, rebase, deployu ani aktywacji capability/module.

Nie zmodyfikowano istniejących baz danych ani persistent Docker volumes.
