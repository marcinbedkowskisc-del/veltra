# VELTRA 2.0 — Stage 6A.1 analiza luki migracyjnej

**Data:** 2026-11-23  
**Branch:** `main`  
**HEAD:** `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6`  
**Zakres:** wyłącznie analiza migracji i odczyt bazy `veltra_stage6a1`. Nie uruchomiono migracji, seederów ani zapisów.

## Podsumowanie decyzji

- **Oczekuje 12 migracji**: pliki od `2026_11_17_000050` do `2026_11_23_000059` w kolejności z §3.
- Dziennik DB zawiera 49 różnych migracji, zgodnych dokładnie z plikami `000001`–`000049`. Nie znaleziono brakujących, zmienionych nazw ani rekordów historii bez odpowiadającego pliku.
- Read-only preflight wykazał **0 konfliktów** dla sprawdzonych ograniczeń jednostek oraz brak danych w supplier assignments, RAW revisions/approvals, dokumentach i workflow kompatybilności. Dwie istniejące kategorie RAW nie są zmieniane przez migrację `000050`.
- Nie potwierdzono aktualnej kopii zapasowej: trzy znalezione archiwa są czytelne jako archiwa PostgreSQL, ale pochodzą z 15 listopada i nie przeszły testu pełnego odtworzenia. Są starsze od znanej aktywności bazy z 16 listopada.
- **Nie należy jeszcze synchronizować bazy.** Działający backend z 16 listopada zawiera 49 plików migracji i odwołuje się do widoków, które `000059` usuwa. Repozytorium bieżące nie ma już tych odwołań. Dodatkowo working tree jest zmodyfikowany, więc nie może być bez weryfikacji źródłem obrazu migratora.
- Po świeżym, sprawdzonym backupie oraz uzgodnieniu zgodnego obrazu aplikacji i okna serwisowego baza może być synchronizowana w kontrolowany sposób. Migracje same nie włączają RAW/BOSS ani nie nadają użytkownikom grantów.

## 1. Stan bazy i zgodność historii

Read-only odczyt `public.migrations` wykonano jako `veltra_migrator` w bazie `veltra_stage6a1`, w transakcji `READ ONLY`.

- Rekordy historii: **49**, unikalne nazwy: **49**, najwyższy batch: **4**.
- Repozytorium zawiera **61 plików migracji**: 49 już zastosowanych i 12 oczekujących.
- Nazwy w dzienniku DB (bez `.php`) odpowiadają nazwom pierwszych 49 plików. Nie ma luki przed `000049`.
- Nie wykryto częściowo utworzonych obiektów: brak dodawanych kolumn `000050`/`000052`/`000055`, nowych tabel słowników/snapshotów/capability, kolumn avatarów, wskazanych constraints ani funkcji; wymagane przez `000059` dwa widoki nadal istnieją.
- Numeracja ma dwa powtórzenia: `000052` i `000053` występują w różnych prefiksach dat. Pełne nazwy plików są różne, a kolejność dat jednoznaczna; to niespójność numeracji, nie kolizja rekordów Laravel.

### Migracje już zastosowane

Nazwy poniżej odpowiadają rekordom DB i plikom repozytorium `.php`:

1. `2026_11_02_000001_create_installation_structure.php`
2. `2026_11_02_000002_create_user_identity_and_memberships.php`
3. `2026_11_02_000003_create_business_role_scopes.php`
4. `2026_11_02_000004_protect_administrators_and_security_history.php`
5. `2026_11_02_000005_create_membership_approval_requests.php`
6. `2026_11_02_000006_create_permission_grants.php`
7. `2026_11_02_000007_create_account_security_links.php`
8. `2026_11_02_000008_create_explicit_report_shares.php`
9. `2026_11_02_000009_preserve_usable_administrator_context.php`
10. `2026_11_02_000010_create_location_module_configuration.php`
11. `2026_11_02_000011_create_raw_materials_core.php`
12. `2026_11_03_000012_permanently_revoke_raw_material_grants_on_suspension.php`
13. `2026_11_03_000013_create_raw_materials_commercial_domain.php`
14. `2026_11_03_000014_enforce_active_supplier_product_revisions.php`
15. `2026_11_03_000015_precision_and_permission_upgrade.php`
16. `2026_11_03_000016_enforce_recursive_permission_upgrade_authority.php`
17. `2026_11_03_000017_prevent_permission_bootstrap_replay.php`
18. `2026_11_03_000018_harden_bootstrap_replay_protection.php`
19. `2026_11_04_000019_restrict_permission_operation_ledger_writes.php`
20. `2026_11_04_000020_revoke_runtime_bootstrap_trigger_execution.php`
21. `2026_11_04_000021_harden_permission_grant_search_path.php`
22. `2026_11_05_000022_enforce_signed_authorization_mutations.php`
23. `2026_11_05_000023_narrow_authorization_mutation_boundary.php`
24. `2026_11_05_000024_harden_raw_material_integrity_search_path.php`
25. `2026_11_05_000025_enforce_raw_material_business_integrity.php`
26. `2026_11_06_000026_protect_supplier_identity_and_raw_material_code_counters.php`
27. `2026_11_06_000027_authorize_membership_deactivation_role_cleanup.php`
28. `2026_11_06_000028_create_raw_material_revision_allergen_and_storage_data.php`
29. `2026_11_06_000029_create_raw_material_versioned_private_documents.php`
30. `2026_11_06_000030_add_raw_material_stage2d_permissions.php`
31. `2026_11_06_000031_create_raw_material_document_scan_results.php`
32. `2026_11_06_000032_create_supplier_compatibility_assessments.php`
33. `2026_11_06_000033_enforce_signed_stage2d_revision_mutations.php`
34. `2026_11_06_000034_create_raw_material_csv_import_history.php`
35. `2026_11_06_000035_standardize_business_sku_allocation.php`
36. `2026_11_06_000036_create_raw_material_catalog_csv_import_history.php`
37. `2026_11_07_000037_restore_signed_raw_material_and_supplier_mutations.php`
38. `2026_11_07_000038_enforce_immutable_primary_central_kitchen.php`
39. `2026_11_08_000039_enforce_super_admin_invariants.php`
40. `2026_11_08_000040_keep_primary_central_kitchen_active.php`
41. `2026_11_08_000041_create_raw_material_location_shares.php`
42. `2026_11_09_000042_add_raw_catalog_quantities_and_sku_reservations.php`
43. `2026_11_09_000043_add_raw_material_edit_versions.php`
44. `2026_11_09_000044_create_raw_document_uploads_and_revision_sets.php`
45. `2026_11_09_000045_add_raw_draft_tombstones.php`
46. `2026_11_10_000046_remove_super_admin_authorization.php`
47. `2026_11_11_000047_add_system_permission_grant_origin.php`
48. `2026_11_11_000048_pin_system_permission_manifest_content.php`
49. `2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance.php`

## 2. Wykryte warunki i możliwe konflikty danych

Zapytania były agregujące; nie odczytywano haseł, e-maili, identyfikatorów użytkowników ani treści dokumentów.

- Baza i wolumen: `veltra_stage6a1` na `veltra-stage6a1_postgres-data`; PostgreSQL healthy.
- RAW: 0 materiałów, 0 rewizji, 2 kategorie, 0 jednostek rewizji, 0 zdarzeń cenowych, 0 approval events i 0 supplier associations.
- Dokumenty: 0 dokumentów, wersji, linków i uploadów.
- Dostawcy: 0 supplierów, produktów i rewizji produktów; 0 zaszyfrowanych identyfikatorów podatkowych i 0 wierszy wymagających backfillu `legal_name`.
- Alergeny: 0 dotychczasowych wersji katalogu, definicji, deklaracji i attestations.
- Workflow wycofywany przez `000059`: 0 ocen kompatybilności, 0 grantów `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE` i 0 linków dokumentów niezgodnych z nowym warunkiem.
- Kontrole ograniczeń `000051`: 0 niezgodnych kodów/jednostek w rewizjach, importach, supplier-product revisions i cenach.
- Wymagany JSON mastera alergenów istnieje, jest poprawny i spełnia asercje seeder’a: 54 alergeny, 9 wersji słowników, 178 linków źródłowych; kody słowników są unikalne i mapowanie jurysdykcji kompletne.
- `000059` widzi dwa wymagane widoki. Odczyt katalogu potwierdził zależność `raw_material_supplier_compatibility_status` → `raw_material_approved_compatibility_document_evidence`; migracja usuwa je w bezpiecznej kolejności: najpierw widok zależny, potem jego źródło.

Nie znaleziono potwierdzonego konfliktu danych w obecnym Stage 6A.1. Wyniki są migawką odczytu; przed wykonaniem trzeba je powtórzyć po wstrzymaniu zapisów.

## 3. Oczekujące migracje — dokładna kolejność i wpływ

Kolejność odpowiada sortowaniu pełnych nazw plików Laravel. Wszystkie zależności bazowe wskazane niżej są obecne dzięki migracjom `000001`–`000049`, chyba że zaznaczono zależność od wcześniejszej migracji z tej listy.

### 1. `2026_11_17_000050_add_raw_material_category_description_and_archive.php`

- **Obiekty:** dodaje `description`, `archived_at`, `archived_by` do `raw_material_categories`; FK `raw_material_categories_archive_actor_fk`, check `raw_material_categories_archive_state_check` i indeks `raw_material_categories_lifecycle_idx`.
- **Dane/obszar:** bez backfillu; istniejące wartości nowych nullable columns będą `NULL`. RAW/PA — metadane i cykl życia kategorii; bez wpływu na BOSS lub RBAC.
- **Zależności/ryzyko:** `raw_material_categories`, `users`; 2 kategorie obecne, brak wykrytego konfliktu.
- **Cofanie:** istnieje `down()`, ale usuwa dodane kolumny, a więc także późniejsze opisy i dane archiwizacji.

### 2. `2026_11_17_000051_create_raw_material_unit_dictionary.php`

- **Obiekty:** tworzy `raw_material_units` z PK, unique/check constraints i FK bazowej jednostki; wstawia sześć definicji (`mg`, `g`, `kg`, `ml`, `l`, `pc`). Dodaje `raw_material_revisions_unit_definition_fk`, `raw_material_revision_units_definition_fk`, `raw_material_catalog_import_rows_unit_fk`, `supplier_product_revisions_content_unit_fk`, `raw_revision_pricing_unit_fk`, `raw_reference_price_unit_fk` oraz checki `raw_revision_pricing_input_check` i `raw_reference_price_input_check`; tworzy `raw_material_unit_definition_guard`, zastępuje `raw_materials_child_immutable`, dodaje `raw_material_units_authorization_ticket` oraz DB privileges/allowlist.
- **Dane/obszar:** 6 wierszy słownika; bez aktualizacji istniejących wierszy RAW. Ograniczenia będą walidowane względem obecnych wartości, w tym tych zapisanych w historii cen i supplier-product revisions.
- **Zależności/ryzyko:** wymaga obiektów RAW/pricing/supplier z wcześniejszych migracji. Sprawdzone konflikty kodu/wymiaru i checków cen: 0.
- **Cofanie:** wyłącznie forward-only; `down()` celowo rzuca wyjątek.

### 3. `2026_11_17_000052_create_international_allergen_dictionaries.php`

- **Obiekty:** tworzy `international_allergen_sources`, `international_allergen_dictionary_versions`, `international_allergen_canonical_definitions`, `international_allergen_source_entries`, `raw_material_allergen_pool_versions` z PK/FK/unique/check constraints; named indexes `international_allergen_dictionary_versions_source_idx`, `international_allergen_canonical_name_idx`, `international_allergen_source_entries_dictionary_idx`; dodaje `canonical_allergen_id` i `source_codes` do `raw_material_allergen_definitions`. Tworzy immutability triggers `international_allergen_dictionary_version_guard`, `international_allergen_canonical_guard`, `international_allergen_source_entry_guard`, `raw_material_allergen_pool_version_guard` oraz authorization-ticket triggers dla versions, canonical definitions, source entries, katalogu/definicji/puli RAW; rejestruje scopes i DB privileges.
- **Dane/obszar:** w ramach migracji uruchamia `AllergenDictionariesSeeder` (nie wymaga osobnego seeder command). Wprowadza 9 wersji słowników, 54 definicje kanoniczne i 178 linków źródłowych oraz — gdy brak snapshotu seed — tworzy nową wersję katalogu RAW z 54 definicjami i publikuje ją. Nie zmienia istniejących rewizji/attestations; obecnie ich liczba wynosi 0.
- **Zależności/ryzyko:** dotychczasowe RAW allergen catalog tables i zatwierdzony JSON master. Plik i oczekiwane liczby sprawdzono; nowe tabele nie istnieją, więc nie wykryto kolizji. Jest to zamierzona zmiana danych referencyjnych RAW, nie zmiana readiness/aktywacji.
- **Cofanie:** forward-only.

### 4. `2026_11_18_000052_create_supplier_directory.php`

- **Obiekty:** rozszerza `organization_suppliers` o `street_address`, `city`, `phone`, `email`, `website`, `tax_identifier_fingerprint`, dopuszcza `display_name=NULL` i zmienia check; uzupełnia `legal_name` z `display_name` gdy brak. Dodaje `organization_suppliers_tax_fingerprint_idx` oraz tabelę `supplier_contacts` z FK/checkami i indeksami `supplier_contacts_search_idx`, `supplier_contacts_supplier_idx`, `supplier_contacts_one_primary_active`. Dodaje `supplier_contacts_authorization_ticket`, `supplier_contacts_serialize`, `supplier_contacts_preserve`; tworzy `supplier_contact_preserve` i zastępuje `raw_materials_commercial_supplier_preserve`; ustawia DB privileges/scopes.
- **Dane/obszar:** próbuje odszyfrować istniejące `tax_identifier_ciphertext` aplikacyjnym kluczem i zapisać tylko HMAC fingerprint; nie zapisuje jawnego identyfikatora. Nie backfilluje kontaktów. Obecnie 0 supplierów, 0 zaszyfrowanych identyfikatorów i 0 legal-name backfills, więc w aktualnej bazie nie ma wierszy do przetworzenia.
- **Zależności/ryzyko:** `organization_suppliers`, `veltra_private.authorization_mutation_scopes`, poprawny klucz aplikacji i integralne ciphertexty. Przy pojawieniu się supplierów przed wykonaniem trzeba ponownie potwierdzić możliwość odszyfrowania; nie odczytywać ani nie raportować wartości.
- **Cofanie:** forward-only.

### 5. `2026_11_18_000053_create_installation_precautionary_allergens.php`

- **Obiekty:** tworzy `installation_precautionary_allergens`, PK i FK do definicji kanonicznych oraz użytkowników, `installation_precautionary_allergens_authorization_ticket`, allowlist/scopes i DB grants.
- **Dane/obszar:** tabela startuje pusta; ustawienia alergenne instalacji/PA, nie zmienia RAW readiness ani BOSS.
- **Zależności/ryzyko:** wymaga definicji kanonicznych z migracji `000052`.
- **Cofanie:** forward-only.

### 6. `2026_11_19_000053_preserve_actor_when_revoking_membership_permissions.php`

- **Obiekty:** zastępuje funkcję triggera `revoke_membership_authorization()`.
- **Dane/obszar:** nie aktualizuje istniejących ról/grantów; przy przyszłej dezaktywacji membership zapisze aktora w `permission_grants.revoked_by` (jak dotychczas unieważniając powiązane assignmenty i granty). Wpływ na przyszły audyt autoryzacji.
- **Zależności/ryzyko:** istniejące `role_assignments`, `permission_grants` i trigger dezaktywacji członkostwa.
- **Cofanie:** `down()` odtwarza poprzednią funkcję; nie cofa ani nie modyfikuje zapisów już istniejących.

### 7. `2026_11_19_000054_create_boss_location_restriction_revisions.php`

- **Obiekty:** tworzy append-only `boss_location_restriction_revisions` z PK `(organization_id,user_id,version)`, JSON checkami, aktorem/czasem; trigger `boss_location_restriction_revisions_authorization_ticket`, scopes i DB grants.
- **Dane/obszar:** brak backfillu i brak automatycznego ustawiania ograniczeń; BOSS/audyt.
- **Zależności/ryzyko:** `organizations`, `users` i mechanizm signed authorization. Tabela nie istnieje przed migracją.
- **Cofanie:** forward-only.

### 8. `2026_11_19_000055_extend_boss_visibility_policy.php`

- **Obiekty:** do tabeli z `000054` dodaje `previous_categories`, `excluded_categories`, `previous_category_locations`, `excluded_category_locations` (JSONB default `[]`) i checks; dodaje FK `boss_restrictions_user_organization_fk` i `boss_restrictions_actor_organization_fk`; tworzy `veltra_private.validate_boss_visibility_revision`, trigger `boss_location_restrictions_validate` oraz append-only `boss_location_restrictions_append_only`/`prevent_boss_visibility_revision_mutation`.
- **Dane/obszar:** jeżeli tabela zawiera wiersze pomiędzy `000054` i `000055`, nowe pola otrzymują `[]`; nie włącza capability ani nie zmienia filtrów już zapisanych. Wymaga braku równoległych zapisów podczas synchronizacji.
- **Zależności/ryzyko:** bezpośrednio `000054`; nowa tabela jest obecnie nieobecna, więc nie ma istniejących wierszy do walidacji.
- **Cofanie:** forward-only.

### 9. `2026_11_20_000056_create_organization_capability_state.php`

- **Obiekty:** tworzy `organization_capability_events` i `organization_capability_states`, indeksy `organization_capability_events_history_idx`/`organization_capability_events_operation_idx`, checks/FK/unique constraints, triggery `organization_capability_states_authorization_ticket`, `organization_capability_states_guard`, `organization_capability_events_append_only`, `organization_capability_events_authorization_ticket`, deferred `organization_capability_events_pair_deferred` i `organization_capability_state_audit_deferred`. Dodaje `veltra_private.guard_organization_capability_state`, `reject_organization_capability_event_mutation`, `require_organization_capability_event_pair`, `require_organization_capability_state_audit`, allowlist/scopes i DB privileges.
- **Dane/obszar:** brak seedów i wierszy stanu. Samo utworzenie tabel nie aktywuje `BOSS_RAW_CATALOG_READ`. Katalog kodowy tej capability nadal ma `ready=false`, więc BOSS pozostaje `NOT_READY`; pusty stan nie oznacza aktywacji.
- **Zależności/ryzyko:** organizacje, użytkownicy, lokalizacje i podpisana granica mutacji. Obie tabele są obecnie nieobecne.
- **Cofanie:** forward-only.

### 10. `2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`

- **Obiekty:** tworzy cztery tabele: working-revision supplier proposals/items oraz immutable approved supplier snapshots/items; dodaje FK/unique/check constraints, triggery `raw_material_revision_supplier_proposal_guard`, `raw_material_revision_supplier_proposal_item_guard`, `raw_material_revision_supplier_proposal_item_consistency`, `raw_material_revision_supplier_proposal_consistency`, `raw_material_approved_supplier_snapshot_guard`, `raw_material_approved_supplier_snapshot_item_guard`, `raw_material_approved_supplier_snapshot_consistency` oraz authorization-ticket triggers na nowych tabelach. Funkcje `raw_material_revision_supplier_proposal_guard`, `raw_material_revision_supplier_proposal_item_guard`, `raw_material_revision_supplier_proposal_item_consistency_validate`, `raw_material_revision_supplier_proposal_consistency_validate`, `raw_material_approved_supplier_snapshot_guard`, `raw_material_approved_supplier_snapshot_item_guard`, `raw_material_approved_supplier_snapshot_consistency_validate` walidują working revision, aktywnego suppliera, zatwierdzenie/content hash i niezmienność snapshots; rejestruje scopes/allowlist i DB privileges.
- **Dane/obszar:** migracja **nie kopiuje ani nie backfilluje** istniejących supplier associations ani zatwierdzeń do snapshotów. Obecnie 0 RAW revisions, approved events, supplier associations, supplier products/revisions — nie ma istniejącej historii do utraty ani snapshot gap w tej bazie.
- **Zależności/ryzyko:** signed mutation boundary, `organization_suppliers`, revisions/materials i approval events. Chroni przyszłą historię; nie usuwa starych danych.
- **Cofanie:** forward-only.

### 11. `2026_11_22_000058_add_user_avatar_state.php`

- **Obiekty:** do `users` dodaje `avatar_type` (`NOT NULL DEFAULT 'INITIALS'`), `avatar_library_id`, `avatar_photo_key`, checki `users_avatar_state_check`, `users_avatar_library_id_check` i unique `users_avatar_photo_key_unique`.
- **Dane/obszar:** istniejące konta otrzymują wartość `INITIALS`; brak zmian haseł, statusu konta, ról lub grantów.
- **Zależności/ryzyko:** istniejąca tabela `users`; sprawdzono, że kolumn jeszcze nie ma.
- **Cofanie:** `down()` istnieje, ale usuwa wszystkie trzy kolumny i późniejszy stan avatara.

### 12. `2026_11_23_000059_retire_raw_material_supplier_compatibility_workflow.php`

- **Obiekty:** usuwa widoki `raw_material_supplier_compatibility_status` i `raw_material_approved_compatibility_document_evidence` (bez `CASCADE`, w prawidłowej kolejności); odbiera `INSERT/UPDATE/DELETE` z `veltra_app` na `raw_material_supplier_compatibility_assessments`, pozostawia `SELECT`; dodaje `organization_permission_grants_supplier_compatibility_retired_check` i `raw_material_document_links_informational_only_check` jako `NOT VALID`; usuwa internal allowlist/mutation scope dla assessment inserts.
- **Dane/obszar:** nie usuwa assessment rows, dokumentów ani grantów użytkowników. `NOT VALID` nie waliduje starych wierszy, ale warunek obowiązuje nowe/aktualizowane wiersze. Aktualne liczby konfliktowych grants/links i assessments wynoszą 0. Zmienia **uprawnienia DB roli** `veltra_app`, nie przypisania RBAC użytkowników.
- **Zależności/ryzyko:** widoki i tabela assessment z migracji `000031`/`000032`, dokument links oraz permission grants z wcześniejszych migracji. Obecny stary backend odwołuje się do obu widoków i tabeli assessment; aktualny kod w repozytorium nie ma tych odwołań. Wdrożenie migracji bez przełączenia starego backendu na zgodny kod spowoduje błędy ścieżek workflow.
- **Cofanie:** forward-only.

## 4. Readiness, aktywacja i RBAC — odrębne stany

1. **Synchronizacja schematu:** 12 plików oczekuje; aktualna baza ma schemat do `000049`.
2. **Gotowość kodowa modułu:** lokalna gotowość RAW w Stage 6A.1 jest `READY`; migracje jej nie ustawiają. Kodowa gotowość `BOSS_RAW_CATALOG_READ` pozostaje `NOT_READY`.
3. **Aktywacja RAW dla lokalizacji:** według ostatniego potwierdzonego read-only stanu jedna aktywna lokalizacja ma `RAW_MATERIALS` włączony. Żadna z oczekujących migracji nie zmienia `location_module_states`.
4. **BOSS readiness/aktywacja:** `000056` dodaje tylko tabelę stanu/audytu i nie wstawia wiersza. Nie aktywuje capability; kod nadal raportuje `NOT_READY`.
5. **RBAC użytkowników:** żadna z oczekujących migracji nie nadaje ani nie odbiera użytkownikom ról/grantów. `000059` zmienia DB privileges dla wycofywanej tabeli oraz ogranicza przyszłe zapisy starym permission code; nie usuwa istniejących rekordów grantów.

Nie uruchamiać aktywacji, seederów poza seederem osadzonym w `000052`, ani zmian grantów w ramach synchronizacji.

## 5. Backup i wymagane zabezpieczenia

| Archiwum | Rozmiar | Modyfikacja | SHA-256 | `pg_restore --list` |
|---|---:|---|---|---|
| `stage6a1-before-raw-pricing-20261115.dump` | 659 013 B | 2026-11-15 22:26 +01 | `1de95810e64a6abe44b0751d51f24d6ed621584cf76ce737ea56cd1671245477` | PASS |
| `veltra-stage6a1-before-provisioning.dump` | 637 311 B | 2026-11-15 09:47 +01 | `b940ca04c507d2af6a6b2b19071e7475732f27122ef024c7e1160349abeca197` | PASS |
| `veltra-stage6a1-before-phase1.dump` | 648 185 B | 2026-11-15 01:07 +01 | `07ae56f12a70fb88eb0ac856c6ad976677c3ee10c2732a23200345b272763f63` | PASS |

Wszystkie są regularnymi plikami w formacie PostgreSQL custom dump. `pg_restore --list` potwierdza czytelność nagłówka/listy archiwum, **nie** potwierdza pełnego odtwarzania ani zgodności z aktualnym stanem bazy. Pliki są sprzed znanej aktywności klastra 16 listopada; nie znaleziono aktualnego snapshotu. Nie traktować ich jako wystarczającego rollback backupu.

Przed wykonaniem wymagane:

1. Utworzyć zatwierdzoną, świeżą kopię logiczną aktualnej bazy i zweryfikować pełne odtworzenie w izolowanej bazie/wolumenie; nigdy nie odtwarzać testowo na `veltra-stage6a1_postgres-data`.
2. Wstrzymać zapisy z backendu i queue na czas migracji. `000059` usuwa widoki używane przez aktualnie działający stary backend.
3. Zbudować runner z **czystego, zatwierdzonego źródła** zawierającego wszystkie 61 migracji. Nie używać obecnego kontenera backendu: obraz `veltra-stage6a1-backend:local` powstał 16 listopada i zawiera tylko 49 plików migracji; jego kod używa usuwanych widoków. Nie budować z obecnego dirty working tree (`RawMaterialService.php` jest zmodyfikowany).
4. Tuż przed migracją ponowić kontrolę wolumenu/projektu, dziennika migracji, warunków FK/check, klucza aplikacji wymaganego przez supplier directory (jeśli pojawią się supplier ciphertexts), pliku seed i wolnego miejsca. Wymusić okno bez równoległych zapisów.
5. W tej samej zatwierdzonej zmianie skoordynować start zgodnego obrazu aplikacji z aktualnego źródła. Nie wznawiać starego backendu/queue po usunięciu widoków przez `000059`.

## 6. Minimalna procedura po osobnej autoryzacji

1. Zabezpieczyć i przetestować świeży backup; jeśli backup lub izolowane odtworzenie nie przechodzi — **stop**, bez migracji.
2. W maintenance window zatrzymać backend/queue, pozostawić PostgreSQL i istniejący wolumen bez zmian; potwierdzić ponownie bazę `veltra_stage6a1`, projekt `veltra-stage6a1` i 49 rekordów historii.
3. Użyć jednorazowego migratora z czystego źródła HEAD `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6`, a nie istniejącego obrazu. Usługa `migrate` w `compose.yaml` ma `php artisan migrate --force`; dla migratora Stage 6A.1 użyć override’u backendu `compose.stage6a1.yaml`, `--no-deps` i jawnie potwierdzonego env-file. Proponowany kształt polecenia — **nie wykonano; ścieżkę clean checkout i target trzeba zatwierdzić**:

   ```bash
   docker compose -p veltra-stage6a1 \
     --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
     -f <CLEAN_SOURCE>/compose.yaml \
     -f <CLEAN_SOURCE>/compose.stage6a1.yaml \
     run --build --rm --no-deps migrate php artisan migrate --force
   ```

   Polecenie obejmuje wyłącznie 12 migracji w kolejności §3; migracja `000052` sama uruchamia zatwierdzony seeder alergenów. Nie uruchamia osobnych seederów i nie powinno być łączone z `up`, resetem ani `down`.
4. Po każdej migracji potwierdzić rekord w `public.migrations`; po zakończeniu oczekiwać 61 rekordów i pełnej zgodności nazw. Nie kontynuować po pierwszym błędzie.
5. Kontrole po migracji: obecność oczekiwanych tabel/kolumn/FK/checków/triggerów; sześć jednostek; 9 wersji słowników, 54 definicje, 178 linków i opublikowany seed catalog; stan danych/supplier snapshot tables; brak zmian w `role_assignments`/grant rows, `RAW_MATERIALS` nadal `READY` i włączony dla tej samej lokalizacji, BOSS nadal `NOT_READY` i nieaktywowany; health DB/API po uruchomieniu zgodnego obrazu.

### Warunki przerwania i odzyskiwanie

- Przerwać przed startem, jeśli zmieni się historia migracji, pojawią się konflikty FK/check, niezgodne obiekty częściowe, problem z kluczem/supplier ciphertext, seed count, backup, target lub image/source.
- Przy błędzie SQL zatrzymać procedurę, zachować logi i wolumen, odczytać historię/object state i ustalić, czy bieżąca migracja została wycofana transakcyjnie. Migracje ukończone wcześniej pozostają zastosowane; cała seria nie jest jedną transakcją.
- Nie używać `migrate:rollback`, `migrate:fresh`, resetu ani bezrefleksyjnego retry: większość oczekujących migracji jest forward-only.
- Odtworzenie backupu — jeśli konieczne — najpierw do izolowanego celu; odtworzenie na żywy wolumen wymaga osobnej, jawnej zgody właściciela.

## 7. Odpowiedzi

1. **Liczba oczekujących:** 12.
2. **Zmiany:** opisane per plik w §3; obejmują RAW jednostki/alergeny/supplier snapshots, PA supplier/alergen/avatary, BOSS historię i capability state oraz autoryzację techniczną.
3. **Potwierdzone ryzyko utraty/konfliktu istniejących danych:** nie wykryto konfliktów w obecnych danych. Potwierdzono natomiast ryzyko niezgodności aplikacji: działający backend używa widoków usuwanych przez `000059`. Istniejące backupy nie są świeżym rollback checkpointem.
4. **Zabezpieczenia:** świeży backup z testem odtworzenia, maintenance bez zapisów, czysty runner z aktualnego commita, uzgodniony rollout zgodnego kodu, re-check preflight i stop na pierwszym błędzie.
5. **Czy można bezpiecznie zsynchronizować:** warunkowo tak po zabezpieczeniach i skoordynowaniu zgodnego kodu; **nie** przez uruchomienie migracji z obecnego backendu ani przed świeżym backupem.
6. **Następna autoryzacja:** najpierw osobna zgoda na utworzenie świeżego backupu aktualnej bazy i jego odtworzeniowy test w izolacji. Po zaliczeniu — osobna zgoda na maintenance-window synchronizację wszystkich 12 migracji z czystego źródła wraz z uruchomieniem zgodnego obrazu aplikacji. Żadna z tych operacji nie została wykonana.

