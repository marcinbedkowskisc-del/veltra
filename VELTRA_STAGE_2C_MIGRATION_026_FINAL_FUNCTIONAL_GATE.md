# VELTRA 2.0 — Stage 2C: końcowa bramka funkcjonalna migracji 026

**Data:** 4 listopada 2026  
**Zakres:** dozwolona regresja nieadwersarialna, PostgreSQL 17, Redis oraz migracje 001–026.  
**Status:** bramka **NIEZALICZONA**; nie wydano ACCEPT dla Stage 2C.

## Podsumowanie

Migracje 001–026 przeszły na świeżej bazie PostgreSQL 17.11, a syntetyczny upgrade z 025 do 026 zachował testową organizację i licznik. Uruchomiono pełny zestaw przypadków dopuszczonych filtrem wyłączającym dokładnie 17 znanych testów bezpieczeństwa. Testy połączyły się z PostgreSQL jako ograniczona rola aplikacyjna `veltra_app`, a nie jako migrator.

Wynik zestawu runtime: **170 testów: 47 PASS, 123 FAIL, 469 asercji, 0 PHPUnit skipped**. Siedemnaście przypadków objętych ograniczeniem nie zostało uruchomionych. Wiele testów funkcjonalnych nie doszło do właściwego scenariusza, ponieważ ich wspólne fixture’y zapisują chronione rekordy bez podpisanego kontekstu lub wykonują `TRUNCATE`/DDL, których rola runtime celowo nie może wykonać. Nie zmieniano testów ani uprawnień, aby wymusić PASS.

To oznacza, że testy tej rundy **nie potwierdzają** żądanych workflow RAW_MATERIALS pod rolą `veltra_app`; wcześniejsze zielone wyniki ograniczonych testów uruchomionych inną rolą nie zastępują tej weryfikacji. Nie stwierdzono podstaw, by przypisać wszystkie odmowy błędom aplikacji: obserwowane operacje wskazują przede wszystkim na niezgodność fixture’ów testowych z restrykcyjnymi uprawnieniami. Niezależny przegląd powinien potwierdzić tę ocenę i ustalić właściwy, rozdzielający fixture/setup od operacji aplikacyjnych sposób testowania.

## Izolacja i konfiguracja ról

- Utworzono prywatną sieć Docker z `internal=true` i dedykowane kontenery PostgreSQL 17.11, Redis 7.4.12 oraz runner PHP 8.4.27. Nie publikowano portów.
- Dane PostgreSQL i Redis oraz katalogi zapisywalne runnera znajdowały się na `tmpfs`. Kod repozytorium był podmontowany tylko do odczytu. Nie użyto ani nie utworzono pliku `.env`; klucze i hasła były syntetyczne i efemeryczne.
- Migracje i przygotowanie schematu wykonywano poświadczeniami `veltra_migrator`. Zweryfikowano `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, `NOBYPASSRLS`; obiekty aplikacyjne należały do migratora.
- Uruchomiony zestaw PHPUnit łączył się jako `veltra_app` (`session_user=veltra_app`, baza `veltra_testing`). Rola nie była superuserem, nie mogła tworzyć baz ani ról, nie miała `BYPASSRLS` ani prawa tworzenia obiektów w `public`; miała dozwolone uprawnienia DML do tabel z allowlisty. Nie miała `TRUNCATE`.
- Istniejące kontenery, bazy i wolumeny VELTRA nie były używane ani modyfikowane. Testowa sieć nie była do nich podłączona.

## Migracje

| Scenariusz | Wynik |
|---|---|
| Świeża baza, migracje 001–026 rolą migracyjną | **PASS**; wszystkie 26 zarejestrowano |
| Syntetyczny upgrade: stan 001–025, następnie 026 | **PASS** |
| Zachowanie danych upgrade’u | **PASS**; syntetyczna organizacja i licznik `SUR=0` istniały przed i po 026 |
| Migrator kontra właściciel tabel | **PASS**; tabele należały do `veltra_migrator`, a nie `veltra_app` |

Upgrade używał osobnej bazy disposable `veltra_upgrade`. Przed 026 wstawiono wyłącznie syntetyczną organizację i zgodny licznik zerowy. Nie jest to weryfikacja historii ani gotowości jakiejkolwiek trwałej instalacji.

## Pełna dozwolona regresja na roli runtime

Pełna lista PHPUnit wykryła **187 przypadków**. Filtr wyłączył 15 przypadków klasy `RawMaterialPermissionBootstrapTest`, 1 przypadek `AuthorizationMutationBoundaryTest` i 1 przypadek `RoleAdministrationTest`; uruchomiono pozostałe **170**. Wynik:

| Status | Liczba |
|---|---:|
| Passed | 47 |
| Failed | 123 |
| Asercje | 469 |
| PHPUnit skipped | 0 |
| Wyłączone filtrem z powodu ograniczenia bezpieczeństwa | 17 |

Wśród zielonych testów znalazły się testy jednostkowe, health checks, `InstallationApiTest` oraz `InitialIdentityProvisioningTest`. Testy `RawMaterialsTest` nie uzyskały PASS w tym przebiegu runtime: wspólne fixture’y użytkowników i bezpośrednie asercje DB zatrzymywały się na ograniczeniach roli przed pełną walidacją scenariuszy.

Z logu przebiegu odnotowano m.in. odmowę `A signed authorization mutation ticket is required` podczas bezpośredniego zapisu fixture’ów, 22 odmowy `TRUNCATE organizations CASCADE` oraz odmowę własności przy `ALTER TABLE organizations`. Dziewięć asercji oczekujących SQLSTATE `23505`/`23514` otrzymało `42501`, bo ograniczenie ticketu zadziałało przed sprawdzanym niezmiennikiem. Kategorie te opisują nakładające się skutki konfiguracji testów, nie są diagnozą nowego exploitu ani potwierdzonym defektem logiki aplikacyjnej.

W konsekwencji wymagane workflowy **nie mają pełnego dowodu na właściwej roli runtime** w tej rundzie:

- tworzenie i archiwizacja dostawcy oraz ograniczenie niemodyfikowalności jego danych;
- alokacja i współbieżność kodów;
- tworzenie, edycja i zatwierdzanie draftów;
- rewizje produktów dostawcy, oferty i zdarzenia cenowe;
- autoryzowane przepływy RAW_MATERIALS;
- kompletna regresja RBAC i delegowania.

Nie uruchamiano wyłączonych scenariuszy, nie zmieniano konfiguracji grantów `veltra_app` ani nie ponawiano wcześniej zablokowanych operacji.

## Dokładny spis 17 niewykonanych przypadków

Cała klasa `Tests\Feature\RawMaterialPermissionBootstrapTest` (15 przypadków):

1. `test_migration_role_is_not_superuser_or_database_owner`
2. `test_runtime_cannot_write_to_permission_operation_ledger_directly`
3. `test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table`
4. `test_permission_grant_validators_pin_public_before_temporary_schemas`
5. `test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables`
6. `test_initial_grant_is_explicit_audited_one_time_and_never_self_granted`
7. `test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql`
8. `test_migration_guard_fails_closed_for_an_active_historical_duplicate`
9. `test_bootstrap_rejects_unknown_permissions_and_invalid_passwords`
10. `test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable`
11. `test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql`
12. `test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants`
13. `test_delegation_is_limited_to_effective_authority_and_revocation_is_audited`
14. `test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants`
15. `test_delegated_grants_expire_with_source_permission_and_delegation_authority`

Pozostałe:

16. `Tests\Feature\AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`
17. `Tests\Feature\RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`

Zostały wykluczone z uruchomienia z powodu zakazu ponawiania scenariuszy adwersarialnych/bezpośredniego SQL. Lista nie stanowi rekomendacji ich uruchomienia bez odrębnego, jawnego upoważnienia i zgodności z kontrolami bezpieczeństwa usługi.

## Ograniczenie biznesowe: dane dostawcy

Migracja 026 czyni `supplier_code`, `display_name`, `legal_name` i `tax_identifier_ciphertext` niemodyfikowalnymi po utworzeniu (podobnie jak pozostałe pola tożsamości). Zachowuje to istniejące tworzenie i archiwizację, lecz obecna aplikacja nie ma ścieżki korekty tych danych. Błąd w nazwie, danych prawnych lub zaszyfrowanym identyfikatorze wymagałby osobno zaprojektowanego, audytowanego procesu korekty albo zatwierdzonej, forward-only zmiany. Nie należy ręcznie zmieniać danych ani obchodzić triggera. Ten koszt operacyjny nie jest równoważny dowodowi akceptacji ryzyka.

## Kontrole standardowe

| Kontrola | Wynik |
|---|---|
| PHP lint wszystkich plików PHP backendu | **PASS**, 202 pliki |
| Pint na kodzie aplikacji, migracjach, testach i pozostałych katalogach źródłowych | **PASS**, 198 plików |
| Pint obejmujący wygenerowane `bootstrap/cache` | Początkowo zgłosił 2 wygenerowane pliki cache; po wyłączeniu wyłącznie tych plików wynik źródeł to PASS |
| `sh -n docker/postgres/10-create-runtime-role.sh` | **PASS** |
| `docker compose --env-file /dev/null config --quiet`, wyłącznie z wartościami testowymi | **PASS** |
| `git diff --check` | **PASS** |

Nie zmieniono kodu aplikacji, testów, migracji ani konfiguracji. Raport jest jedynym artefaktem dodanym w tej rundzie.

## Pakiet dowodowy i wymagania dla niezależnego audytora

To przekazanie dowodów, a nie nowy audyt bezpieczeństwa. Uprawniony niezależny recenzent powinien:

1. Potwierdzić, dlaczego chronione fixture’y nie są zgodne z połączeniem runtime, i wskazać prawidłowy sposób oddzielenia przygotowania danych od operacji aplikacji bez nadawania runtime praw migratora, właściciela, `TRUNCATE` ani DDL.
2. Po uzyskaniu odpowiedniego upoważnienia zweryfikować ponownie pełne nieadwersarialne workflowy na `veltra_app`; obecny wynik 47/123 nie daje im PASS.
3. Niezależnie przejrzeć aktualną migrację 026, warunek preflight dla historycznych kodów/liczników, monotoniczność przy współbieżności oraz ograniczenie zmian danych dostawcy.
4. Ocenić wpływ niemodyfikowalności danych dostawcy i zatwierdzić proces korekty przed wdrożeniem.
5. Rozpatrzyć ryzyka sześciu tabel F-2 indywidualnie: `organization_suppliers`, `raw_material_code_counters`, `raw_material_categories`, `raw_material_revisions`, `raw_material_revision_units`, `raw_material_aliases`. Brak HMAC sam w sobie nie jest ustaleniem; wymagane są ocena skutków i decyzja właściciela.
6. Uzupełnić niezależną ocenę RBAC, delegacji, cofania, trwałości ledgerów, ticketów mutacji, `search_path`, ról i realnej historii instalacji. Wcześniej wyłączone testy nie zostały zaliczone.
7. Osobno ocenić błędy oczekiwanego SQLSTATE: ustalić, czy są to wyłącznie próby fixture’ów niedostępne runtime, czy rzeczywiste regresje, bez osłabiania asercji i bez modyfikowania grantów na trwałej bazie.

## Końcowy werdykt

**NO-GO dla uznania końcowej bramki za zaliczoną i dla Stage 2C ACCEPT.** Migracje przeszły, lecz pełny dozwolony zestaw na właściwej roli runtime zakończył się 123 niepowodzeniami, a wymagane workflowy biznesowe nie uzyskały kompletnego dowodu. Do rozstrzygnięcia pozostają sposób uruchomienia fixture’ów przy zachowaniu separacji ról, ponowna dozwolona regresja oraz niezależny audyt bezpieczeństwa. `RAW_MATERIALS` pozostaje `NOT_READY`; nie rozpoczęto Stage 2D ani nie aktywowano VSEF.
