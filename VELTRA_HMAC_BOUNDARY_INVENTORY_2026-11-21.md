# VELTRA 2.0 — HMAC SECURITY BOUNDARY INVENTORY

**Data:** 2026-11-21
**Checkout:** `/home/veltra/projekty/veltra-pa-07b3b`
**Stan źródłowy:** HEAD `d0d22e65df49fc4e8744ca626ac363126447e36d`
**Zakres:** statyczna rekonstrukcja HMAC triggerów i scope’ów po migracjach 022–056

## 1. Podsumowanie wykonawcze

**CONFIRMED FROM SOURCE:** po kolejnych migracjach 022–056 skuteczna ochrona HMAC obejmuje 65 tabel. To nie oznacza, że wszystkie wymagają HMAC po przedefiniowaniu granicy Option B.

Najważniejszy wniosek: obecny kod jest już częściowo zawężony migracją 023 i wymaga nieodzownej konserwacji:

- 023 usuwa HMAC z wielu niechronionych tabel RAW i pozostawia go na listę tabel RBAC/audytu/komercyjnych.
- 024 dodaje niezależne scope’y i triggery dla ról oraz trwałe guardy integralności RAW.
- 025 i 037 przywracają część HMAC dla RAW core i supplierów.
- późniejsze migracje 029–056 rozszerzają ochronę o dokumenty, SKU, sprawdzanie dostawców, alergeny, shares, restrictions i capability.

**Proponowana minimalna Target Option B:** nie klasyfikuję żadnej z 65 tabel jako bezpiecznego kandydata do natychmiastowego usunięcia na podstawie samego kodu. 32 tabel pozostaje **KEEP HMAC**; pozostałe 33 wymagają **FURTHER ANALYSIS** przed decyzją o usunięciu. Wynik 0 removal candidates jest świadomy i konserwatywny.

Klasyfikacja nie oznacza, że każda z 33 tabel ostatecznie musi zachować HMAC. Oznacza, że bez istniejącej migawki bazy, realnego modelu SQL-only, i testów nie można udowodnić bezpiecznego usunięcia.

## 2. Zweryfikowany baseline Git

**CONFIRMED FROM SOURCE / GIT:**

- Branch: `pa-07b3b-boss-raw-catalog`
- HEAD: `d0d22e65df49fc4e8744ca626ac363126447e36d`
- Worktree: brak zmian śledzonych i staged.
- Nieśledzone raporty: 5 poprzednich raportów PA oraz dwa raporty HMAC są plikami nieśledzonymi.
- Commit: `d0d22e6 feat(boss): add approved raw catalog read API PA-07B.3B`
- Checkout nie zawiera zatwierdzonych zmian po tym commicie.
- Główna gałąź nie jest tym checkoutem i nie może być traktowana jako aktualna baza implementacyjna.

Ta recenzja nie łączyła gałęzi, nie wykonywała migracji i nie odczytywała istniejącej bazy.

## 3. Granica implementacji HMAC

Ticket jest generowany w transakcji przez [AuthorizationMutationContext.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/AuthorizationMutationContext.php:49):

- wymaga 64-heksy klucza;
- tworzy HMAC over payload;
- payload zawiera `actor_id`, `expires_at`, `nonce`, `scope`, `transaction_id`, opcjonalnie `subject_id`;
- ustawia GUC na bieżącej transakcji i przywraca poprzednie wartości.

Trigger `veltra_private.enforce_authorization_mutation` w [022](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:277):

- wymaga HMAC i niepustego ticketu;
- wiąże ticket z `txid_current()`;
- sprawdza termin, nonce i scope;
- pozwala migratorowi i superuserowi na bypass;
- nie wiąże ticketu z konkretnym rekordem ani pełnym payloadem mutacji.

`AuthorizationMutationScope` ogranicza tekst scope’ów w PHP, ale trigger nadal sprawdza relację tabela/operacja/scope: [AuthorizationMutationScope.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/AuthorizationMutationScope.php:1).

`SecurityMutation` stosuje ticket do security events i security mutations: [SecurityMutation.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/SecurityMutation.php:15).

## 4. Rekonstrukcja migracji 022–056

### Migracja 022

Migracja 022:

- tworzy prywatny scope/klucz;
- tworzy trigger HMAC na 35 tabelach;
- tworzy role least-privilege;
- odbiera runtime `TRUNCATE`, publiczny DML i CREATE.

Źródło: [2026_11_05_000022_enforce_signed_authorization_mutations.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:60).

**CONFIRMED FROM SOURCE:** migracja używała najpierw klucza pochodnego od `APP_KEY`, ale migracja 023 później ponownie zapisuje klucz pochodny od `APP_DB_MUTATION_SIGNING_KEY`.

### Migracja 023

Migracja 023 usuwa wszystkie generic HMAC triggery spoza stałej listy `PROTECTED_TABLES` i ogranicza scope’y:

- pozostawia 29 tabel chronionych;
- usuwa trigger z zwykłego RAW master data i suppliers;
- nie odwraca migracji: `down()` rzuca wyjątek.

Źródło: [2026_11_05_000023_narrow_authorization_mutation_boundary.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000023_narrow_authorization_mutation_boundary.php:14).

### Migracja 024

Migracja 024:

- dodaje role-specific scope dla grantu i revoke;
- dodaje trigger zgodności role/aktor/scope;
- hardenuje `search_path`;
- potwierdza potrzebę HMAC dla role assignments i append-only/integrity tables.

Źródło: [2026_11_05_000024_harden_raw_material_integrity_search_path.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php:55).

### Migracja 025 i 037

- 025 dodaje scope i trigger dla odblokowania RAW: `raw_materials`, `raw_material_audit_events`.
- 037 przywraca HMAC dla category/master/revision/unit/alias i organization suppliers.

Źródła:

- [2026_11_05_000025_enforce_raw_material_business_integrity.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000025_enforce_raw_material_business_integrity.php:13)
- [2026_11_07_000037_restore_signed_raw_material_and_supplier_mutations.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_07_000037_restore_signed_raw_material_and_supplier_mutations.php:10)

### Migracje 029–056

Późniejsze migracje dodają triggery lub scope’y do:

- prywatnych dokumentów i ich powiązań;
- scan results;
- supplier compatibility;
- allergen attestations/declarations/storage variants;
- CSV import history;
- SKU counters/assignments/reservations;
- location shares;
- document uploads/revision sets;
- supplier associations i contacts;
- dictionary definition tables;
- precautionary allergens;
- BOSS location restrictions;
- organization capability states/events.

Przykładowe źródła:

- [migration 029](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_06_000029_create_raw_material_versioned_private_documents.php:175)
- [migration 033](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_06_000033_enforce_signed_stage2d_revision_mutations.php:129)
- [migration 041](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_08_000041_create_raw_material_location_shares.php:268)
- [migration 044](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_09_000044_create_raw_document_uploads_and_revision_sets.php:140)
- [migration 052](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_17_000052_create_international_allergen_dictionaries.php:220)
- [migration 054](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_19_000054_create_boss_location_restriction_revisions.php:21)
- [migration 056](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_20_000056_create_organization_capability_state.php:112)

## 5. Pelna inwentaryzacja tabel HMAC

### KEEP HMAC — 32 tabel

| Tabela | HMAC od | Scope / gwarancja |
|---|---|---|
| `organizations` | 022 | identity/installation mutation |
| `locations` | 022 | installation mutation |
| `location_management_assignments` | 022 | installation mutation |
| `location_supply_relationships` | 022 | supply mutation |
| `users` | 022 | identity/account/personnel |
| `location_memberships` | 022 | membership/personnel |
| `role_assignments` | 022; 024 semantics | role.grant/revoke/membership |
| `security_events` | 022 | append-only security history |
| `membership_requests` | 022 | membership/personnel |
| `permission_grants` | 022 | permission grant/revoke |
| `report_shares` | 022 | report authorization |
| `activation_tokens` | 022 | account token security |
| `password_reset_tokens` | 022 | account reset security |
| `account_email_deliveries` | 022 | account verification security |
| `location_module_states` | 022 | module activation |
| `raw_material_revision_approval_events` | 022 | approved revision evidence |
| `raw_material_audit_events` | 022; 024/045 | append-only audit and domain events |
| `supplier_product_revisions` | 022 | commercial/approval evidence |
| `supplier_offer_events` | 022 | append-only offer history |
| `supplier_offer_fx_normalization_events` | 022 | append-only financial normalization |
| `organization_exchange_rate_events` | 022 | append-only FX history |
| `raw_material_reference_price_events` | 022 | financial history |
| `raw_material_financial_restatements` | 022 | financial restatement history |
| `raw_materials_commercial_idempotency` | 022 | commercial replay protection |
| `raw_materials_commercial_audit_events` | 022 | append-only commercial audit |
| `organization_permission_grants` | 022 | organization RAW delegation |
| `organization_permission_grant_events` | 022 | append-only grant history |
| `raw_material_permission_upgrade_operations` | 022 | permission upgrade evidence |
| `raw_material_permission_grant_operation_uses` | 022 | replay protection |
| `organization_capability_states` | 056 | capability activation state |
| `organization_capability_events` | 056 | append-only capability history |
| `boss_location_restriction_revisions` | 054 | BOSS restriction visibility |

**CONFIRMED FROM SOURCE:** te tabele są wymagane dla autoryzacji, odwołania uprawnień, capability, approval evidence lub financial/audit history.

**INFERRED:** bez realnego SQL-only modelu i DB verification nie można formalnie wykazać, że każda z nich potrzebuje HMAC, ale usunięcie po grupach jest zbyt ryzykowne na obecnym etapie.

### REMOVAL CANDIDATE — 0 tabel

**CONFIRMED FROM SOURCE:** obecny source inventory nie uzasadnia bezpiecznego oznaczenia żadnej tabeli jako natychmiastowego removal candidate.

Powody konserwatywnego wyniku:

- nawet pozorne tabele RAW mogą dostarczać dowód approval/cena/allergen/history;
- 023 i 024 już usunęły broad protections z zwykłych tabel RAW;
- 037 ponownie je przywrócił po późniejszym business integrity review;
- żadne odrębne dowody nie pokazują, że SQL-only HMAC protection na danych catalog/dokument/import nie zmniejszy integralności.

### FURTHER ANALYSIS — 33 tabele

| Tabela | Obecny trigger / migracja | Powód dalszej analizy |
|---|---|---|
| `raw_material_categories` | 037 | identity/categories affect catalog and approved projection |
| `raw_materials` | 025/037 | approval/lifecycle and approved revision evidence |
| `raw_material_revisions` | 037 | working/pending/approved content |
| `raw_material_revision_units` | 037 | revision hash and unit conversion |
| `raw_material_aliases` | 037 | revision content hash |
| `organization_suppliers` | 037 | supplier identity and commercial master |
| `raw_material_documents` | 029 | private document ownership |
| `raw_material_document_versions` | 029 | document version integrity |
| `raw_material_document_version_links` | 029 | revision/document binding |
| `raw_material_document_lifecycle_events` | 029 | append-only lifecycle |
| `raw_material_document_scan_results` | 031 | scan result evidence |
| `raw_material_supplier_compatibility_assessments` | 032 | supplier compatibility approval |
| `raw_material_revision_allergen_attestations` | 033 | allergen approval evidence |
| `raw_material_revision_allergen_declarations` | 033 | approved content integrity |
| `raw_material_revision_storage_variants` | 033 | approved content integrity |
| `raw_material_catalog_import_batches` | 036 | import integrity and replay |
| `raw_material_catalog_import_rows` | 036 | import row integrity |
| `organization_sku_counters` | 035 | SKU counter integrity |
| `organization_sku_assignments` | 035 | durable SKU identity |
| `raw_material_location_shares` | 041 | cross-location visibility |
| `raw_material_sku_reservations` | 042 | quantity reservation integrity |
| `raw_material_document_uploads` | 044 | upload authorization and expiry |
| `raw_material_revision_document_sets` | 044 | revision/document relation |
| `raw_material_supplier_associations` | 049 | supplier/material commercial relation |
| `raw_material_units` | 051 | unit definition/content |
| `international_allergen_dictionary_versions` | 052 | allergen source provenance |
| `international_allergen_canonical_definitions` | 052 | allergen catalog integrity |
| `international_allergen_source_entries` | 052 | allergen source provenance |
| `raw_material_allergen_catalog_versions` | 052 | approved allergen catalog |
| `raw_material_allergen_definitions` | 052 | allergen data integrity |
| `raw_material_allergen_pool_versions` | 052 | pool integrity |
| `supplier_contacts` | 052 | supplier contact identity |
| `installation_precautionary_allergens` | 053 | safety-related operational declaration |

## 6. Mapping Laravel → PostgreSQL

Główne serwisy i ich mutacje:

- `RoleAdministration.php` — role bootstrap/grant/revoke.
- `SecurityMutation.php` — security events i audit accepted/rejected.
- `OrganizationRawMaterialPermissionAdministration.php` — permission grants/revoke.
- `RawMaterialService.php` — approve/return/revise/archive/unblock i audit RAW.
- `RawMaterialCommercialService.php`, `RawMaterialPricingService.php` — financial/commercial records.
- `RawMaterialLocationShareAdministration.php` — shares i visibility.
- `OrganizationCapabilityService.php` — capability state i append-only events.
- `BossLocationRestrictions.php` — visibility restriction revisions.
- `RawMaterialDocument*Service.php` i import workflows — protected document/import tables.

Źródła:

- [RoleAdministration.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/RoleAdministration.php:127)
- [SecurityMutation.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/SecurityMutation.php:15)
- [OrganizationRawMaterialPermissionAdministration.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/OrganizationRawMaterialPermissionAdministration.php:46)
- [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:2135)
- [OrganizationCapabilityService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/OrganizationCapabilityService.php:80)
- [BossLocationRestrictions.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/BossLocationRestrictions.php:100)

## 7. Independent integrity protections

**CONFIRMED FROM SOURCE:**

- append-only triggers dla security/commercial/RAW history;
- FK i composite constraints;
- approved revision/matching approval event checks;
- lifecycle transition guards;
- role ticket semantics in migration 024;
- organization locks used with mutations;
- runtime role least-privilege;
- dedicated subject guards for Stage 2D, import, location shares and supplier corrections;
- capability state version and event-pair guards.

Te kontrolki są częściowo niezależne od HMAC:

- constrainty i append-only zachowują integralność także bez ticketu;
- lecz większość constraintów nie autoryzuje wartości biznesowych;
- HMAC chroni przed DML bez ticketu z runtime credentials;
- HMAC nie chroni przed HMAC-generującym Laravel;
- append-only nie dowodzi prawdziwości zdarzenia.

## 8. SQL-only threat analysis

| Obszar | Wynik | Nowa możliwość po usunięciu HMAC |
|---|---|---|
| Role/permission/grants | PROTECTION REDUCED | SQL-only runtime może bezpośrednio nadać role/granty |
| Security/audit history | PROTECTION REDUCED | Ticketless UPDATE/DELETE/INSERT może zmienić lokalne ledger history |
| Approval events/revisions | PROTECTION REDUCED | SQL-only może tworzyć/fałszować dowody zatwierdzenia |
| Financial/commercial history | PROTECTION REDUCED | SQL-only może wpisywać ceny, restatements i FX history |
| Capability states/events | PROTECTION REDUCED | SQL-only może aktywować state bez admin flow |
| Location restrictions | PROTECTION REDUCED | SQL-only może zmieniać BOSS visibility |
| RAW master/dictionaries | NOT VERIFIED / POTENTIAL PROTECTION REDUCED | Bez HMAC zależność od grantów i constraintów; wymagany live model |
| Import/documents/SKU | NOT VERIFIED | Wymaga migration/constraint inventory i SQL-only test |

**NOT VERIFIED IN DATABASE:** testy SQL-only z realną rolą, live trigger state i actual runtime grants.

## 9. Proposed minimal Option B target

Minimalnie, bez change implementation:

- zachować HMAC dla 32 tabel classified KEEP;
- nie usunąć HMAC z 33 tabel FURTHER ANALYSIS;
- zachować generic trigger function and private scope table until target migration is independently approved;
- zachować role/migrator separation;
- zachować append-only and approval integrity functions;
- introduce a forward-only migration only after a live or disposable database verifies exact trigger set;
- do not rewrite migrations 022–056.

Scopes that remain needed:

- `identity.*`, `account.*`, `membership.*`, `personnel.*`, `permission.*`, `role.*`;
- `security.event`, `module.audit`, `organization-capability.*`;
- `report.*`, `boss.location_restrictions`;
- `raw-material.mutate`, `raw-material.commercial`, `raw-material.share`, approval/audit-related scopes.

Scopes that could become obsolete require a table/operation check; this source review alone is insufficient.

## 10. Risk matrix

| Proposed removal | HMAC benefit | Independent controls | Lost guarantee | Residual risk | Complexity reduction | Confidence | Recommendation |
|---|---|---|---|---|---|---|---|
| None | N/A | N/A | None proposed | Live DB may differ | None | high for source, low for live DB | no removal in this phase |

This conservative matrix differs from an exhaustive candidate matrix because no table has sufficient evidence for removal. A valid result is fewer removals than initially expected.

## 11. Read-only database verification checklist

Wykonać dopiero po osobnej autorizacji właściciela. Nie wykonywać teraz.

1. Applied migrations:

```sql
SELECT migration FROM migrations
WHERE migration >= '2026_11_05_000022'
ORDER BY migration;
```

2. HMAC trigger list and enabled state:

```sql
SELECT n.nspname, c.relname, t.tgname, p.proname, t.tgenabled
FROM pg_trigger t
JOIN pg_class c ON c.oid=t.tgrelid
JOIN pg_namespace n ON n.oid=c.relnamespace
JOIN pg_proc p ON p.oid=t.tgfoid
WHERE NOT t.tgisinternal
  AND p.proname='enforce_authorization_mutation'
ORDER BY c.relname, t.tgname;
```

3. Current scope definitions:

```sql
SELECT table_name, operation, scope_prefix
FROM veltra_private.authorization_mutation_scopes
ORDER BY table_name, operation, scope_prefix;
```

4. Signing key structural validity without value:

```sql
SELECT singleton,
       octet_length(signing_key) AS key_bytes,
       migration_role,
       runtime_role
FROM veltra_private.mutation_signing_keys;
```

5. Runtime role privileges:

```sql
SELECT grantee, table_name, privilege_type
FROM information_schema.role_table_grants
WHERE grantee='veltra_app'
ORDER BY table_name, privilege_type;
```

6. Migrator privileges:

```sql
SELECT rolname, rolsuper, rolcreaterole, rolcreatedb,
       rolbypassrls
FROM pg_roles
WHERE rolname='veltra_migrator';
```

7. Integrity constraints and append-only triggers:

```sql
SELECT n.nspname, c.relname, t.tgname, p.proname, t.tgenabled
FROM pg_trigger t
JOIN pg_class c ON c.oid=t.tgrelid
JOIN pg_namespace n ON n.oid=c.relnamespace
JOIN pg_proc p ON p.oid=t.tgfoid
WHERE NOT t.tgisinternal
ORDER BY c.relname, t.tgname;
```

## 12. Future forward-only migration strategy

Jeżeli Option B zostanie zatwierdzone:

1. zaktualizować expectation based on live trigger inventory;
2. nie edytować 022–056;
3. utworzyć nową forward-only migration;
4. najpierw preflight: lista triggerów, scopes i key structure;
5. usunąć wybrane triggery tylko po testach SQL-only i DB constraints;
6. usunąć wyłącznie obsolete scope rows;
7. zachować private HMAC function if other KEEP tables remain;
8. weryfikować no trigger loss, no orphan scope, no new direct DML;
9. testować clean install i upgrade;
10. przeprowadzić independent review and rollback plan.

## 13. Required security and regression tests

- runtime role direct insert/update/delete against every KEEP table;
- no unauthorized role/permission/capability activation;
- approval event and revision hash integrity;
- financial restatement and commercial audit rejection;
- append-only UPDATE/DELETE on security and business history;
- wrong/expired/stale ticket;
- wrong transaction ID and scope;
- replay in and across transactions;
- key rotation and rollback;
- migration clean install and upgrade;
- organization/location isolation;
- migration role bypass test;
- superuser/migrator bypass documented and accepted as outside protection.

## 14. Unknowns and blockers

- Live application database state is unknown.
- Applied migration set is unknown.
- Actual runtime/migrator privileges are unknown.
- Signing key presence and synchronization are unknown.
- Trigger disabled state is unknown.
- Superuser/migrator state is unknown.
- Real SQL-only attacker capability is not verified.
- HMAC key ownership and access isolation are unknown.
- Whether external audit export exists is unknown.

All database-state conclusions remain **NOT VERIFIED IN DATABASE**.

## 15. Final recommendation

**CONFIRMED FROM SOURCE:** current state already includes a narrowed HMAC boundary and independent integrity controls.

**INFERRED:** Option B should preserve a small high-risk HMAC boundary, but further table removals are not justified by static source alone.

**NOT VERIFIED IN DATABASE:** live triggers, grants and key state.

Final recommendation for this stage:

> **No HMAC removals in this inventory. Keep 32 security/authorization/financial/capability tables. Keep 33 further-analysis tables unchanged. Create a future forward-only migration only after live read-only verification and explicit owner approval.**

Niniejszy raport nie proponuje implementacji i nie weryfikuje żadnej istniejącej bazy.

