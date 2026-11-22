# VELTRA 2.0 — decyzja architektury mechanizmu HMAC

**Data:** 2026-11-21  
**Checkout:** `/home/veltra/projekty/veltra-pa-07b3b`  
**Zakres:** wyłącznie architektura i proporcjonalność obecnego mechanizmu podpisanych mutacji PostgreSQL

## 1. Podsumowanie wykonawcze

**Rekomendacja: OPCJA B — ZAWĘZIĆ HMAC DO WYBRANYCH OPERACJI WYSOKIEGO RYZYKA.**

Obecna implementacja po migracjach 023 i 024 już nie jest jednolitą ochroną wszystkich tabel z migracji 022. Jest rozdzielona na:

1. podpisany ticket HMAC dla wybranych mutacji RBAC, audytu i elementów bezpieczeństwa;
2. niezależne constrainty i trigery integralności domenowej, które działają bez podpisu;
3. bezpośredni DML runtime dla niechronionych tabel biznesowych RAW i dostawców;
4. osobne, bardzo wąskie scope’y dla późniejszych modułów, między innymi capability i dokumentów.

Opcja B jest najproporcjonalna, ponieważ HMAC ma uzasadnioną wartość w ochronie chronionych mutacji przed SQL-only użyciem poświadczeń runtime, ale nie chroni przed atakiem na Laravel ani rolę PostgreSQL. Nie ma podstaw, by usuwać tę ochronę z RBAC i wrażliwego audytu bez osobnego wzrostu realnego ryzyka. Jednocześnie nie należy utrzymywać obecnego ogólnego callbacku `scope + actor + closure` jako podstawowego interfejsu autoryzacji ani przywracać HMAC wszystkim prostym mutacjom biznesowym.

Najważniejsze trzy powody rekomendacji:

1. **Potwierdzony benefit SQL-only:** rola runtime nie otrzymuje scope’u migracyjnego ani DML bez ticketu na chronionych tabelach; trigger sprawdza HMAC, zakres tabeli i operacji, transakcję i termin: [AuthorizationMutationContext.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/AuthorizationMutationContext.php:49), [2026_11_05_000022...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:277).
2. **Dodatkowa ochrona ma sens tylko dla kilku skutków wysokiego ryzyka:** role i członkostwa zmieniają uprawnienia; approval events i business history mogą zmienić integralność danych; capability state może zmienić dostęp. Te mutacje są dobrym kandydatem na dodatkową bazową granicę.
3. **Proste RAW mutations mają już silniejszą, niezależną warstwę:** 023 usuwa z tabel RAW część triggerów, a 024 i 037 dodają constrainty/lifecycle/approval/supplier guards: [2026_11_05_000023...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000023_narrow_authorization_mutation_boundary.php:14), [2026_11_05_000024...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php:1), [2026_11_07_000037...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_07_000037_restore_signed_raw_material_and_supplier_mutations.php:10).

Największym ryzykiem zalecanej zmiany jest błędne odebranie HMAC tabeli, która służy jako wiarygodny proof dla kolejnej decyzji, lub pominięcie innego triggera, migracji/funkcji. Wynik byłby spadkiem SQL-only resistance i ewentualną utratą możliwości wykrywania ticketless DML.

## 2. Zweryfikowany baseline Git

**CONFIRMED:**

- Branch: `pa-07b3b-boss-raw-catalog`
- HEAD: `d0d22e65df49fc4e8744ca626ac363126447e36d`
- HEAD krótki: `d0d22e6`
- Status: brak zmian śledzonych i staged. Nieśledzone są wyłącznie wcześniej zapisane raporty PA i ten przegląd.
- Checkout recenzji: `/home/veltra/projekty/veltra-pa-07b3b`
- Nie uruchomiono testów, migracji, kontenerów, bazy danych ani skryptów mutujących.

Wcześniejsza rekomendacja dotycząca uproszczenia i zatwierdzona decyzja Stage 2C są rozdzielone od bieżącej implementacji:

- wcześniejszy report [VELTRA_SECURITY_SIMPLIFICATION_REVIEW_2026-11-21.md](/home/veltra/projekty/veltra-pa-07b3b/VELTRA_SECURITY_SIMPLIFICATION_REVIEW_2026-11-21.md);
- decyzja Stage 2C wskazuje Laravel jako granicę biznesową i ograniczone elementy HMAC jako możliwe tylko dla wysokiego ryzyka: [VELTRA_STAGE_2C_SECURITY_ARCHITECTURE_DECISION.md](/home/veltra/projekty/veltra-pa-07b3b/VELTRA_STAGE_2C_SECURITY_ARCHITECTURE_DECISION.md:1).

## 3. Bieżąca architektura HMAC

### 3.1 Generowanie ticketu

`AuthorizationMutationContext` odczytuje `APP_DB_MUTATION_SIGNING_KEY`, wymaga 64 znaków heksadekimalnych i tworzy 32-bajtowy HMAC. Payload zawiera:

- `actor_id`;
- 30-sekundowy `expires_at`;
- UUID `nonce`;
- tekstowy `scope`;
- `transaction_id`;
- opcjonalny `subject_id`.

Ticket i podpis są ustawiane jako ustawienia transakcji GUC, następnie wykonywany jest callback. Po callbacku GUC jest przywracany: [AuthorizationMutationContext.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/AuthorizationMutationContext.php:35).

**CONFIRMED:** ticket nie wiąże HMAC z konkretną nazwą tabeli, konkretnym rekordem ani pełnym payloadem DML. Trigger weryfikuje transaction ID, scope tabeli i operacji oraz termin.

**INFERENCE:** przy SQL injection wykonywanym wewnątrz aktywnego callbacku ticket może być ponownie użyty przez następujący DML o dozwolonym scope. Mechanizm nie rozróżnia legalnego SQL z callbacku od SQL injection.

**NOT VERIFIED:** czy istniejące środowiska rzeczywiście używają tego samego zestawu scope’ów i triggerów.

### 3.2 Walidacja triggera

Trigger `enforce_authorization_mutation`:

- pomija sprawdzenie dla skonfigurowanej roli migracyjnej i superusera;
- wymaga payloadu i podpisu;
- weryfikuje HMAC;
- wymaga zgodnego `txid_current`;
- wymaga niewygasłego ticketu;
- wymaga dozwolonego `scope_prefix` dla tabeli i operacji.

Źródło: [2026_11_05_000022...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:277).

### 3.3 Granice ról i GRANT/REVOKE

Migracja 022:

- tworzy osobne role runtime i migracyjną;
- odrzuca runtime dostęp do `veltra_private`;
- usuwa `PUBLIC` DML/CREATE/TRUNCATE;
- przyznaje runtime `SELECT/INSERT/UPDATE/DELETE` z allowlisty;
- chroni wiele tabel triggerem HMAC.

Źródło: [2026_11_05_000022...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:1).

### 3.4 Migracja 023 — faktyczny zakres po niej

Migracja 023 usuwa HMAC-trigger z tabel spoza swojej stałej listy `PROTECTED_TABLES`, usuwa niewymienione scope’y i pozostawia triggery tylko dla:

- `organizations`
- `locations`
- `location_management_assignments`
- `location_supply_relationships`
- `users`
- `location_memberships`
- `role_assignments`
- `security_events`
- `membership_requests`
- `permission_grants`
- `report_shares`
- `activation_tokens`
- `password_reset_tokens`
- `account_email_deliveries`
- `location_module_states`
- `raw_material_revision_approval_events`
- `raw_material_audit_events`
- `supplier_product_revisions`
- `supplier_offer_events`
- `supplier_offer_fx_normalization_events`
- `organization_exchange_rate_events`
- `raw_material_reference_price_events`
- `raw_material_financial_restatements`
- `raw_materials_commercial_idempotency`
- `raw_materials_commercial_audit_events`
- `organization_permission_grants`
- `organization_permission_grant_events`
- `raw_material_permission_upgrade_operations`
- `raw_material_permission_grant_operation_uses`

Źródło: [2026_11_05_000023...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000023_narrow_authorization_mutation_boundary.php:14).

### 3.5 Migracja 024 — niezależna integralność

Migracja 024 dodaje hardening:

- nazwane, typowane scope’y RBAC;
- trigger semantyki dla `role_assignments`;
- sprawdzenie zgodności `scope` i aktora przy grant/revoke;
- triggery ochrony lifecycle i approved revision dla RAW.

Chronione listy i guardy są niezależne od HMAC: [2026_11_05_000024...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php:1).

### 3.6 Późniejszy stan

Migracja 037 ponownie dodaje HMAC dla:

- `raw_material_categories`
- `raw_materials`
- `raw_material_revisions`
- `raw_material_revision_units`
- `raw_material_aliases`
- `organization_suppliers`

Źródło: [2026_11_07_000037...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_07_000037_restore_signed_raw_material_and_supplier_mutations.php:10).

Późniejsze migracje dodają HMAC także dla konkretnych tabel domenowych i capability, między innymi:

- `raw_material_permission_grant...`;
- history i dokumenty RAW;
- capability states/events.

Najistotniejszy stan capability: migracja 056 dodaje trigger dla `organization_capability_states` i `_events` oraz scope’y:

- `organization-capability.configure`;
- `organization-capability.audit`;

Źródło: [2026_11_20_000056...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_20_000056_create_organization_capability_state.php:114), [2026_11_20_000056...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_20_000056_create_organization_capability_state.php:320).

**NOT VERIFIED:** pełna lista triggerów HMAC na realnej instalacji. Statyczna lista migracji 022–056 nie dowodzi kolejności wykonania, pominiętych migracji ani późniejszych zmian ręcznych.

## 4. Inwentaryzacja tabel i ryzyko

| Grupa tabel chronionych HMAC | Operacje | Główne ryzyko biznesowe | Niezależne zabezpieczenia |
|---|---|---|---|
| `organizations`, `locations`, members, supply relationships | INSERT/UPDATE/DELETE | struktura instalacji, członkostwo i dostęp | FK, role Laravel, organization locks, 024 domain guards |
| `users`, `role_assignments`, `membership_requests`, `permission_grants` | INSERT/UPDATE/DELETE | nieuprawnione nadanie roli i eskalacja uprawnień | Laravel authorization, role ticket semantics, lock organizations, revocation constraints |
| `security_events`, append-only history, grant events | INSERT/UPDATE/DELETE | fałszowanie lub kasowanie historii RBAC/audytu | append-only/update-delete guards, least privilege, transaction scope |
| `raw_material_revision_approval_events`, `raw_material_audit_events` | INSERT/UPDATE/DELETE | aprobacja bez procesu, manipulacja dowodem historycznym | matching pending revision/hash, append-only triggers, approved revision guard |
| `supplier_product_revisions`, offers, FX, reference price, restatement, commercial audit | INSERT/UPDATE/DELETE | ceny i koszty suppliers, błędne dane zakupowe | FK, content/approval constraints, commercial service, transaction |
| `organization_permission_grants/events`, upgrade operations | INSERT/UPDATE/DELETE | delegacja uprawnień RAW poza autoryzacją | effective grant checks, delegated authority limits, audit events |
| RAW core/category/revision/unit/alias/supplier | INSERT/UPDATE/DELETE | zmiana master RAW i supplier identity | lifecycle/check/immutability guards, 037 supplier guard, Laravel permission checks |
| capability state/events | INSERT/UPDATE/DELETE | aktywacja capability bez administracyjnego procesu | password confirmation, state version, append-only event, 056 guard |

**CONFIRMED:** operacją chronioną HMAC jest każda `INSERT/UPDATE/DELETE` na wskazanych tabelach, jeżeli trigger jest nadal aktywny i rolą wykonującą jest runtime.

**CONFIRMED:** SQL-only napastnik z samymi poświadczeniami runtime nie może zmodyfikować chronionych tabel bez poprawnego ticketu. To wymusza obecność GUC, podpisu i scope.

**INFERENCE:** jeśli użycie poświadczeń runtime zdobędzie aktywny ticket, token nie wiąże operacji z konkretnym rekordem. Taki napastnik może wykonywać dozwolone mutacje w scope innego procesu.

**NOT VERIFIED:** actual runtime user can create temp tables or call functions; static source and prior disposable test evidence show direct attack testing, but not the live role.

## 5. Granice zagrożeń

| Threat | Co chroni HMAC | Czego nie chroni |
|---|---|---|
| Zwykły błąd aplikacji | Brak automatic protection — Laravel ticket istnieje nawet przy błędnym DML, chyba że scope nie pasuje | Nie zastępuje walidacji i autoryzacji |
| Nieautoryzowane HTTP/API | Nic bezpośrednio | Laravel RBAC i HTTP tests są główną granicą |
| SQL injection / wyciek runtime credentials | Blokuje ticketless DML na chronionych tabelach | Nie wiąże SQL z faktycznym rekordem ani nie chroni unprotected DML |
| Kompromitacja Laravel | Nic | Klucz i credentials są dostępne procesowi; napastnik może emitować ticket |
| Superuser/migrator PostgreSQL | Nic; migrator i superuser bypassują trigger | Tylko external audit/backup/granica administracyjna |
| Integralność historii | Częściowo ogranicza możliwość ticketless UPDATE/DELETE, plus append-only | Nie gwarantuje prawdziwości zdarzenia ani odporności na migratora |
| Integralność finansowa i approvals | HMAC plus INDEPENDENT DB constraints | Constrainty i Laravel approval logic są ważniejsze |

## 6. Porównanie opcji

| Kryterium | A — Zachować | B — Zawęzić | C — Usunąć |
|---|---|---|---|
| Realne zagrożenia | wysoki koszt dla średniego benefit | najlepszy stosunek benefit/koszt | słabsza SQL-only protection |
| SQL-only resistance | aktualna, ale szeroki scope | zachowana dla RBAC/audytu/approve/capability | utracona dla chronionych tabel |
| Authorization integrity | pośrednia; nie zastępuje Laravel | lepsza, jeśli scope binding jest precyzyjny | Laravel primary |
| Business-data integrity | wysokie wraz z constraintami | wysokie, constrainty pozostają | wysokie, jeśli granty i guards pozostają |
| Audyt/history | append-only + ticket; lokalnie nie odporne na superuser | append-only, ticket dla najważniejszych ledgerów | append-only tylko, bez dodatkowego ticketless barrier |
| Złożoność | wysoka | średnia | najniższa |
| Długoterminowy utrzymanie | wysoka rotacja i testing overhead | średnia, ale mniej tabel i scopes | najniższa |
| Migracja/upgrade | already complex, forward-only | requires a carefully designed forward migration | largest trigger and role cleanup |
| Performance | generic trigger per row | limited to few tables | lower trigger overhead |
| Future modules | broad ticket may accidentally spread | explicit allowlist by module | simpler, but higher grant discipline requirement |

## 7. Recommendation: B

### 7.1 Co zawęzić

Zachować lub projektować HMAC tylko dla:

1. **RBAC i członkostwo**
   - `role_assignments`;
   - `permission_grants`;
   - `organization_permission_grants`;
   - `organization_permission_grant_events`;
   - role bootstrap i permanent revocation.

   **CONFIRMED risk:** grant/revoke directly changes privileges. [RoleAdministration.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/RoleAdministration.php:127), [2026_11_05_000024...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php:55).

2. **Sensitive append-only security history**
   - `security_events`;
   - `raw_material_audit_events`;
   - `organization_permission_grant_events`;
   - capability events.

   HMAC dodaje barrier przed ticketless INSERT/UPDATE/DELETE, ale local audit authenticity still needs external export.

3. **Approved state transitions**
   - approval events i approved_revision fields only if a high-risk direct SQL threat is explicitly accepted.
   - retain independent hash, lifecycle and append-only constraints regardless of HMAC.

4. **Capability activation state**
   - retain while explicit organization activation is a controlled business decision.

### 7.2 Czego nie podpisywać ponownie przez generic HMAC

Nie uzasadnia się generic HMAC dla zwykłych kategorii, nazw jednostek, składu revisji, codziennego importu, dokumentów, cen handlowych i SKU, jeżeli:

- istnieje Laravel domain authorization;
- runtime ma najmniejsze DML granty;
- PostgreSQL ma FK, CHECK, lifecycle i approved-revision invariants;
- operacja nie tworzy nowego centralnego dowodu uprawnienia.

Te mutacje powinny pozostać pod Jawną granicą Laravel i odrębnymi triggerami integralności. [2026_11_05_000024...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php:55).

## 8. Co pozostaje bez zmian

1. Laravel jako primary authorization boundary.
2. Least-privilege runtime and migration roles.
3. Transactions and organization locks.
4. Foreign keys, unique constraints and immutability.
5. Approved revision → approval event → content hash invariants.
6. Append-only history and runtime revoke UPDATE/DELETE.
7. Narrow triggers for lifecycle, approved revision and role semantics.
8. Independent audit export for protection from DB administrator compromise.

## 9. Co byłoby zredukowane

- Utrata ochrony ticketless DML na tabelach przeniesionych poza HMAC.
- Brak dalszego separate DB proof dla zwykłych SQL injection w tych tabelach.
- W przyszłości zależność od dokładności grantów i triggerów dla ochrony przed runtime credential use.
- Potencjalnie mniej sygnatur, ale nadal testy regressji HMAC dla zachowanych tabel.

## 10. Pozostałe ryzyka

- **Compromised Laravel:** klucz i ticket mogą być wygenerowane przez napastnika.
- **Compromised runtime SQL credentials:** zachowane chronione mutacje są zabezpieczone, ale część RAW/capability może zostać bez HMAC.
- **Superuser/migrator:** może wyłączyć HMAC i zmienić dane.
- **Nonce:** ticket może być użyty wielokrotnie w tej samej transakcji; brak replay database table.
- **Scope binding:** obecny scope and actor not exact record/payload.
- **Live migration state:** not verified.
- **Key rotation:** no confirmed overlap and rollback procedure.

## 11. Migracja i bezpieczeństwo istniejących instalacji

**CONFIRMED:**

- migracje 023 i 024 mają `down()` rzucający runtime exception i są forward-only;
- migracja 024 wymaga migracji 022 i 023 oraz konkretnych relacji i triggerów;
- migracja 056 dodaje scope’y i triggery dla capability;
- poprzednia migracja 022 może wykryć existing data i wymaga manualnej atestacji.

Źródła:

- [2026_11_05_000023...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000023_narrow_authorization_mutation_boundary.php:105);
- [2026_11_05_000024...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php:330);
- [2026_11_20_000056...php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_20_000056_create_organization_capability_state.php:320).

**NOT VERIFIED — WYMAGA OSOBNEGO AUTORYZOWANIA:**

- czy migracje 022–056 zostały zastosowane na konkretnej bazie;
- jaka jest wersja schematu i triggerów;
- jaka jest bieżąca wartość klucza i klucze historyczne;
- jakie role posiadają uprawnienia DML/DDL;
- czy istnieją rekordy legacy i wynik atestacji 022;
- czy istniejący runtime/user różni się od deklarowanego w source.

Minimalna read-only weryfikacja przed implementacją:

```sql
SELECT migration FROM migrations
WHERE migration LIKE '2026_11_%'
ORDER BY migration;

SELECT rolname, rolsuper, rolcreaterole, rolcreatedb
FROM pg_roles
WHERE rolname IN ('veltra_app', 'veltra_migrator');

SELECT n.nspname, c.relname, t.tgname,
       p.proname, t.tgenabled
FROM pg_trigger t
JOIN pg_class c ON c.oid = t.tgrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN pg_proc p ON p.oid = t.tgfoid
WHERE n.nspname='public' AND NOT t.tgisinternal;

SELECT table_name, operation, scope_prefix
FROM veltra_private.authorization_mutation_scopes
ORDER BY table_name, operation, scope_prefix;

SELECT count(*) AS signing_keys,
       count(*) FILTER (WHERE octet_length(signing_key)=32) AS valid_keys
FROM veltra_private.mutation_signing_keys;
```

Podane zapytania są do osobnego uzgodnienia i wykonania przez uprawnionego owner/DBA. Ten raport ich nie wykonywał.

Zmiana HMAC musi być forward-only migration; nie wolno edytować 022–024 ani 056 na już zastosowanej bazie.

## 12. Wymagane testy i regresja

### Bezpieczeństwo krytyczne

- runtime SQL role bez żadnego ticketu;
- insert/update/delete do każdej zachowanej chronionej tabeli;
- `TRUNCATE`, temp tables i próba zmiany triggera/search_path;
- invalid payload, invalid signature, expired ticket, wrong transaction ID i wrong scope;
- role grant/revoke/replay/delegation;
- payment/financial data and approval transition;
- audit insert accepted, update/delete rejected;
- capability activation state version and event pair;
- key rotation and unknown-key rollback.

### Niezależna regresja

- complete backend relevant to RBAC, memberships, RAW approvals, commercial data and capability;
- migration clean install;
- migration upgrade from existing synthetic data;
- forward-only narrow migration;
- independent review of trigger and grant state;
- no production data and no existing volumes.

### Contract amendments

Before implementation, owner must amend accepted Stage 2C contract to state exactly:

- which tables remain HMAC-protected;
- what HMAC proves and does not prove;
- whether HMAC is SQL-only protection or explicitly not business authorization;
- who owns key rotation and incident response;
- whether external audit export is required;
- which future modules inherit a new scope.

## 13. Minimalny plan implementacyjny na przyszłość

1. Ustal i udokumentuj threat model, zakres SQL-only attack i owner’s risk tolerance.
2. Wygenerować inventory live triggerów i roles on authorized disposable or existing snapshot.
3. Zawęzić scope’ów i tabel w forward-only migration.
4. Zamienić obecny generic callback scope/actor/closure na named capabilities dla kilku operacji.
5. Wiązać ticket z operacją, subjectem i payloadem wybranej mutacji.
6. Zachować DB constraints, append-only i testy niezależne.
7. Przetestować clean install, upgrade, rollback, key rotation i SQL-only abuse.
8. Zaktualizować contract i README.
9. Wymagać niezależnej akceptacji przed deploymentem.

## 14. Warunki, które uczyniłyby rekomendację niebezpieczną

Opcja B byłaby niebezpieczna, jeśli:

- istnieją instalacje bez cofniętego direct DML, gdzie runtime role ma szerokie granty;
- owner nie potrafi wyizolować klucza od procesów Laravel;
- nie można sprawdzić ani odtworzyć `migration 022–056` na live installation;
- Role, approval history i financial data nie mają independent constraints;
- external audit is required but not exported;
- migration may be applied without pre-migration snapshot and independent review;
- existing key rotation creates two incompatible signer/verifier states.

## 15. Pytania otwarte

- Czy realny threat model obejmuje SQL injection z runtime DB, czy tylko błędy aplikacji?
- Czy SQL-only napastnik może uzyskać active session or GUC in current architecture?
- Czy audit must remain authentic against compromised DB administrator?
- Czy BOSS_RAW catalog and capability activation require SQL-only protection?
- Czy istniejąca migracja has already been applied in production or staging?
- Czy klucz signing jest truly separate from application logs and backup?
- Jaki jest priority of financial approvals relative to key management cost?

## 16. Finalna decyzja architektury

**OPCJA B — ZAWĘZIĆ HMAC DO WYBRANYCH OPERACJI WYSOKIEGO RYZYKA.**

Takie zawężenie jest proporcjonalne do VELTRA: zachowuje SQL-only barrier dla role/permission/audit/capability, pozwala pozostałym zwykłym mutacjom korzystać z Laravel, grants i DB invariants, oraz redukuje broad trigger/rotation maintenance. Nie jest to rekomendacja usunięcia systemu integralności ani zmiany danych.

## 17. Zakończenie

**CONFIRMED:** raport został utworzony wyłącznie w trybie read-only. Nie zmieniono application code, testów, konfiguracji, migracji, Git, bazy danych, Docker, secretów, capability ani modułów.

**NOT VERIFIED:** installed database state and live migration state require separately authorized database read access.

**CONFIRMED:** this report does not implement PA-07B.3C or authorize any corrective implementation.
