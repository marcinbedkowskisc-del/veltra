# VELTRA 2.0 — PA-07B.3C — FINAL TARGETED FIX FOR 3C-AUD-002

**Data:** 2026-11-21
**Worktree:** `/home/veltra/projekty/veltra-pa-07b3c`
**Branch:** `pa-07b3c-approved-supplier-snapshots`
**HEAD:** `d0d22e65df49fc4e8744ca626ac363126447e36d`
**Ocena:** **READY FOR TARGETED INDEPENDENT VERIFICATION**

## 1. Potwierdzona przyczyna

Guardy proposal header i proposal items sprawdzały przede wszystkim docelową wartość `NEW.revision_id`. Nie chroniły immutable identity istniejącego rekordu `OLD`.

W konsekwencji podpisany runtime UPDATE mógł zmienić:

- `organization_id`;
- `raw_material_id`;
- `revision_id`;
- `supplier_id`;
- identyfikujące klucze proposal header/item.

Wpływ: proposal historyczny lub approved could be reassigned to an editable working revision, co naruszało relację proposal->revision i mogło przekazać zły zestaw dostawców do approval.

## 2. Zmiany guardów PostgreSQL

Zaktualizowano migrację:

- [2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php)

### Proposal header

Dodano w przypadku `UPDATE`:

- immutable `id`;
- immutable `organization_id`;
- immutable `raw_material_id`;
- immutable `revision_id`;
- immutable `created_by`;
- immutable `created_at`.

Źródło:

- [proposal guard identity checks](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:82)
- [NEW vs OLD checks](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:98)

Dla `UPDATE` lifecycle waliduje teraz oryginalną rewizję `OLD.revision_id`, nie pozwalając przypisać historycznego proposal do nowej rewizji.

Dla `DELETE` nadal waliduje oryginalny `OLD.organization_id` i `OLD.revision_id`, zachowując working-revision/draft deletion behavior.

Dla `INSERT` nadal waliduje nowy target jako exact editable working revision.

### Proposal items

Dodano w przypadku `UPDATE`:

- immutable `id`;
- immutable `organization_id`;
- immutable `revision_id`;
- immutable `supplier_id`.

Źródło:

- [proposal item guard](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:125)
- [NEW vs OLD item checks](/home/veltra/projekty/veltra-pa-07b3c/backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php:159)

Podstawienie suppliera pozostaje DELETE + INSERT w aplikacji. Nie dodano sprzedażnego reassignmentu przez UPDATE.

## 3. Korekta aplikacji

**NOT REQUIRED**

`syncSupplierProposal()` już usuwa istniejące proposal items i ponownie tworzy itemy dla replacement:

- [delete and insert supplier items](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:746)
- [delete and insert logic](/home/veltra/projekty/veltra-pa-07b3c/backend/app/Domain/RawMaterials/RawMaterialService.php:765)

Nie było potrzeby modyfikować aplikację. Zmiana została utrzymana na odpowiednim PostgreSQL boundary.

## 4. Zmienne pliki

- `backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`
- `backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php`

Migracje 001–056 nie zostały zmienione.

## 5. Testy adversarialne

W disposable PostgreSQL 17.11:

| Test | Wynik |
|---|---|
| cross-revision UPDATE proposal header | odrzucony `23514` |
| cross-revision UPDATE proposal item | odrzucony `23514` |
| UPDATE `organization_id` header/item | odrzucony |
| UPDATE `raw_material_id` header | odrzucony |
| UPDATE `revision_id` header/item | odrzucony |
| UPDATE `supplier_id` item | odrzucony |
| UPDATE immutable `id`/`created_by`/`created_at` | odrzucony |
| mutable state UPDATE na exact working revision | `PASS` |
| DELETE + INSERT supplier item w exact working revision | `PASS` |
| mutation after submit | `23514` |
| mutation after approval | `23514` |
| mutation obsolete revision | `23514` |
| ticketless runtime mutation | `42501` |
| snapshot UPDATE/DELETE | `42501` |

Post-reject count and proposal/item counts remained unchanged.

Nowe PHP testy:

- [test_signed_cross_revision_and_identity_updates_are_rejected](/home/veltra/projekty/veltra-pa-07b3c/backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php:516)
- [test_editable_state_update_and_delete_insert_item_replacement_succeed](/home/veltra/projekty/veltra-pa-07b3c/backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php:642)

Wynik klasy:

- **15 passed**
- **73 assertions**

## 6. Czysta migracja i forward upgrade

**CONFIRMED**

- Czysta migracja 001–057: **PASS**, 59 migracji.
- Synthetic upgrade 056→057: **PASS**.
- Legacy supplier association preserved.
- Approval pointer preserved.
- Approval content hash preserved.
- Proposal/snapshot: 0/0/0/0 po upgrade.
- Migration 057 remains uncommitted and was updated directly. No existing persistent database was used.

## 7. Deterministic concurrency

Poprzedni izolowany deterministic harness wykonał **10 przeplotów i 43 asercje PASS**. Korekta identity jest na poziomie guardów PostgreSQL i nie zmienia lock ordering ani transaction ordering.

Nowy disposable adversarial PostgreSQL harness potwierdził signed cross-revision, identity, submit, approval i obsolete mutations. Snapshot UPDATE/DELETE and ticketless mutation remained blocked.

## 8. Regresja

| Suite | Wynik |
|---|---|
| `BossRawMaterialCatalogProvenanceTest.php` | 15 passed, 73 assertions |
| `BossRawMaterialCatalogApiTest.php` + Authorization | 15 passed, 123 assertions |
| `BossRawMaterialCatalogConcurrencyTest.php` | 4 passed, 89 assertions |
| `RawMaterialsTest.php` | 161 passed, 1934 assertions |

Dodatkowo:

- `git diff --check`: PASS
- PHP syntax migration 057: PASS
- PHP syntax provenance test: PASS

## 9. Pozostałe blokery

- Brak wyników z realnego istniejącego staging/production database.
- Brak commitu i post-commit target independent verification.
- `BOSS_RAW_CATALOG_READ` pozostaje `NOT_READY`.
- `RAW_MATERIALS` pozostaje `NOT_READY`.

Nie znaleziono nowego blokującego defektu w zakresie supplier proposal cross-revision.

## 10. Ocena gotowości

**READY FOR TARGETED INDEPENDENT VERIFICATION**

Korekta zamyka confirmed cross-revision mutation na poziomie PostgreSQL:

- identity header jest immutable;
- identity item jest immutable;
- UPDATE nie może zmienić własności, rewizji ani supplier association;
- lifecycle nadal sprawdza OLD i NEW context;
- supplier replacement pozostaje DELETE + INSERT;
- 3C-AUD-001, approved snapshots, HMAC, draft deletion i BOSS contract remain intact.

Nie wykonano commita, push, merge, rebase, deployu ani aktywacji capability/module.
