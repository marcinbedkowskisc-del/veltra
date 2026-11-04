# VELTRA 2.0 — Stage 2C: naprawa infrastruktury testów

## Podsumowanie i werdykt

Infrastruktura testowa została rozdzielona na uprzywilejowane przygotowanie
fixture'ów i ograniczone operacje aplikacji. Baza instalacyjna, istniejące
kontenery i wolumeny VELTRA nie były używane do testów ani modyfikowane.

Pełna dozwolona regresja nie zakończyła się PASS: **167 testów przeszło,
3 zakończyły się błędem, a 0 asercji nie przeszło**. Wykryto co najmniej jeden
potwierdzony błąd integracji aplikacji z bazą. Nie wprowadzano poprawek kodu
produkcyjnego ani zmian architektury po jego wykryciu.

**Werdykt: NO-GO dla zamknięcia Stage 2C.** Nie jest to niezależna akceptacja
bezpieczeństwa. Niezależny re-audyt pozostaje wymagany; `RAW_MATERIALS` pozostaje
`NOT_READY` i nieaktywne. Stage 2D nie rozpoczęto, a VSEF nie aktywowano.

## Analiza 123 wcześniejszych niepowodzeń

Punkt wyjścia z poprzedniej bramki:

- 187 wykrytych testów;
- 17 testów bezpieczeństwa wyłączonych z wykonania;
- 170 uruchomionych: 47 PASS, 123 FAIL, 469 asercji, 0 pominiętych przez PHPUnit.

W raporcie poprzedniej bramki i jej logu odnotowano:

| Obserwacja | Liczba | Klasyfikacja |
|---|---:|---|
| Odmowa `A signed authorization mutation ticket is required` przy bezpośrednim DML | 91 | Fixture'y i sprawdzenia SQL wykonywane połączeniem runtime zamiast osobnym połączeniem setup; same w sobie nie dowodzą błędu chronionego workflow aplikacji. |
| Odmowa `TRUNCATE organizations CASCADE` | 22 | Błędna rola do resetowania bazy testowej; `veltra_app` nie może i nie powinien otrzymać `TRUNCATE`. |
| Odmowa `ALTER TABLE` dla organizacji testowej | 1 | Fixture wymagał właściciela tabeli, a uruchamiał DDL przez rolę runtime. |
| Oczekiwano SQLSTATE `23505`/`23514`, otrzymano `42501` | 9 | Asercja trafiała najpierw na ograniczenie uprawnień, zanim mogła sprawdzić właściwy constraint. |

Liczby te **nakładają się** i nie tworzą rozłącznego podziału wszystkich 123
niepowodzeń. Nie zaklasyfikowano automatycznie pozostałych błędów jako
infrastrukturalnych; końcowa regresja na prawidłowo rozdzielonych rolach ujawniła
problemy aplikacyjne opisane niżej.

## Wprowadzone zmiany

### Rozdzielenie połączeń i transakcji

- `backend/tests/TestCase.php` ustawia `pgsql_setup` wyłącznie z jawnych
  poświadczeń `TEST_DB_SETUP_USERNAME` i `TEST_DB_SETUP_PASSWORD`.
- Setup fail-closed sprawdza bazę `veltra_testing`, `session_user`,
  `veltra_migrator`, brak superusera oraz właściciela tabel. Połączenie domyślne
  pozostaje `pgsql`; setup nie przełącza globalnego połączenia Laravel.
- Testy sprawdzają, że aplikacja łączy się jako `veltra_app`, tabela należy do
  `veltra_migrator`, a runtime nie ma `TRUNCATE` ani `CREATE` w schemacie `public`.
  Brak osobnych poświadczeń setup kończy test błędem zamiast cichego fallbacku.
- Fixture'y, testowe DML/DDL, asercje constraintów i czyszczenie bazy korzystają
  jawnie z połączenia setup. Asercje wymagające sprawdzenia ograniczeń runtime
  pozostają na `pgsql`.
- `backend/tests/InstallationTestCase.php` nie obejmuje już całego testu
  `DatabaseTransactions`. Reset przed i po teście jest wykonywany rolą
  migracyjną w disposable bazie. Zatwierdzone fixture'y są widoczne dla
  niezależnych procesów używanych w testach współbieżności.
- Testowe podklasy identity/authorization oraz testy feature i concurrency
  zostały zaktualizowane tak, aby rozdzielać fixture'y od operacji aplikacji.
  Asercje nie zostały usunięte ani osłabione; bezpośrednie sprawdzenia SQL
  przeniesiono na setup, a ścieżki aplikacyjne pozostawiono na runtime.

### Compose i dokumentacja

- `compose.yaml`: usługa `backend-test` używa teraz `veltra_app` jako
  `DB_USERNAME` i przekazuje dane `veltra_migrator` wyłącznie jako
  `TEST_DB_SETUP_*`. `migrate-test` nadal wykonuje migracje rolą migracyjną.
- `README.md`: opisuje rozdzielenie ról, wymagania dla `veltra_testing`, reset
  bazy testowej i zakaz używania danych/sekretów produkcyjnych.

Nie zmieniono kodu aplikacji, migracji produkcyjnych, grantów istniejącej bazy
ani definicji istniejących kontenerów. Zmiany obejmują testy backendu, usługę
Compose przeznaczoną wyłącznie do testów oraz dokumentację.

## Izolacja i konfiguracja weryfikacji

Przed migracjami i testami potwierdzono:

- Docker: klient i serwer **29.9.2**.
- PostgreSQL **17.11**, Redis **7.4.12**, PHP **8.4.27**.
- Nowa sieć Docker miała `internal=true` i zawierała wyłącznie nowe kontenery
  PostgreSQL, Redis i runner PHP; nie była połączona z `veltra_default`.
- PostgreSQL i Redis nie miały bind mountów ani wolumenów; ich dane znajdowały
  się na dedykowanych tmpfs. Nie publikowano portów hosta.
- Kod backendu był zamontowany w runnerze tylko do odczytu; katalogi runtime
  i cache runnera były efemeryczne. Konfiguracja zawierała wyłącznie syntetyczne,
  tymczasowe poświadczenia.
- Sześć istniejących kontenerów aplikacji pozostało uruchomionych i zdrowych;
  nie podłączano ich do testowej sieci ani nie używano ich baz i wolumenów.

Role w nowej instancji PostgreSQL:

| Rola | Użycie | Sprawdzone ograniczenia |
|---|---|---|
| `veltra_migrator` | Migracje i jawne przygotowanie/cleanup fixture'ów | `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, `NOBYPASSRLS`; nie jest właścicielem bazy. Jest właścicielem schematu/tabel testowych. |
| `veltra_app` | Domyślne połączenie testów i operacje Laravel | `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, `NOBYPASSRLS`; nie jest właścicielem tabel, nie ma `TRUNCATE` ani `CREATE` w `public`. |

Tożsamość obu połączeń była weryfikowana przez bootstrap testów. Testy
współbieżności uruchamiały dodatkowe procesy Laravel z konfiguracją runtime;
połączenie setup nie było ustawiane jako połączenie domyślne aplikacji.
Połączenie `veltra_migrator` jest dopuszczalne wyłącznie dla przygotowania
disposable bazy — nie jest rolą runtime.

## Migracje

| Scenariusz | Wynik |
|---|---|
| Świeża baza `veltra_testing`, migracje 001–026 jako `veltra_migrator` | **PASS**, 26 migracji |
| Syntetyczny upgrade `veltra_upgrade` z migracji 001–025 do 026 | **PASS**, migracja 026 zastosowana |
| Zachowanie syntetycznej organizacji i licznika w upgrade | **PASS**; `UPGRADE-026`, `SUR=0` zachowane przed i po migracji |
| Ochronne triggery licznika po upgrade | **PASS**, znaleziono 4 oczekiwane triggery |

Upgrade używał osobnej bazy i syntetycznych rekordów organizacji oraz licznika;
nie zawierał historycznych rekordów surowców ani danych z trwałej instalacji.
Nie jest to dowód kompatybilności z każdą historyczną bazą.

## Regresja backendu

`phpunit --list-tests` wykrył 187 przypadków. Przed wykonaniem sprawdzono
filtrem listę wybraną do testów: 170 przypadków; poniższe 17 pozostało
wyłączonych. Regresję uruchomiono jako `veltra_app`, z Redis dostępnym na
dedykowanej sieci.

| Wynik końcowy | Liczba |
|---|---:|
| Wykryte | 187 |
| Wyłączone ograniczeniem | 17 |
| Uruchomione | 170 |
| PASS | 167 |
| Asercyjne FAIL | 0 |
| Błędy PHPUnit | 3 |
| Ostrzeżenia PHPUnit | 0 |
| Pominięte przez PHPUnit | 0 |
| Asercje | 1382 |

Początkowy przebieg po rozdzieleniu połączeń nie zawierał jeszcze
`SANCTUM_STATEFUL_DOMAINS` i `FRONTEND_URL` w efemerycznej konfiguracji.
Wywołał przez to błędy pierwszoosobowej sesji w testach API; konfigurację
uzupełniono, a powyższy wynik końcowy pochodzi z ponownego pełnego przebiegu.
Nie uruchomiono żadnego z 17 wyłączonych przypadków.

### Trzy błędy pozostałe w pełnej regresji

1. `BusinessRoleDefinitionTest::test_multiple_explicit_kitchen_roles_require_matching_operating_context`
   oraz `PermissionDelegationTest::test_overlapping_scheduled_delegations_merge_and_revocation_includes_future_grants`
   kończą się SQLSTATE `42501`. `MembershipService::deactivate()` podpisuje
   operację zakresem `membership.deactivate`, po czym trigger PostgreSQL
   aktualizuje `role_assignments`. Migracja 024 dopuszcza dla tej aktualizacji
   zakres `membership.remove`, ale nie `membership.deactivate`. To potwierdzony
   błąd integracji workflowu aplikacyjnego z ograniczeniem DB, odtworzony
   rzeczywistym połączeniem `veltra_app`.
2. `RoleAdministrationTest::test_admin_grants_require_fresh_password_and_rejections_are_durable`
   kończy się SQLSTATE `23514` przy `RoleAdministration::bootstrapInitial()`.
   Test weryfikuje tożsamość przed bootstrapem, a membership operacyjny tworzy
   dopiero po nim. Bootstrap ustawia `authorization_initialized`, podczas gdy
   odroczony constraint wymaga aktywnego, zweryfikowanego administratora
   z membership operacyjnym. Obecny serwis nie wyjaśnia ani nie sprawdza tego
   warunku przed ustawieniem flagi; należy rozstrzygnąć i zweryfikować właściwą
   kolejność inicjalizacji.

Pierwszy problem blokuje ścieżkę deaktywacji membershipu z aktywnym przypisaniem
roli. Drugi blokuje zaobserwowaną kolejność bootstrapu zweryfikowanej tożsamości
bez wcześniejszego membershipu. Nie zmieniano tych zachowań ani testów, aby
uzyskać PASS. Nie są to błędy infrastruktury fixture'ów i wymagają osobnej,
wąsko określonej remediacji oraz ponownej regresji.

W wyjściu Laravel pojawił się wpis logu `testing.ERROR` dla celowo testowanego
404 przy próbie odczytu draftu przez użytkownika bez uprawnienia
(`RawMaterialsTest::test_view_only_reader_cannot_discover_drafts_or_pending_revisions`).
Test przeszedł; nie był to warning ani błąd PHPUnit.

### Dokładny spis 17 niewykonanych testów

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

Nie uruchamiano scenariuszy SQL/adwersarialnych ani nie ponawiano wcześniej
zablokowanych operacji.

## Kontrole końcowe

| Kontrola | Wynik |
|---|---|
| PHP lint backendu poza `vendor` i `storage` | **PASS**, 203 pliki |
| Pint `--test` dla 22 zmienionych plików PHP | **PASS** |
| `sh -n docker/postgres/10-create-runtime-role.sh` | **PASS** |
| `docker compose --env-file <efemeryczny plik testowy> config --quiet` | **PASS** |
| `git diff --check` | **PASS** |
| Redis `PING` | **PONG** |

## Ograniczenia i dalsze kroki

1. Naprawić wyłącznie po zatwierdzeniu błąd zakresu mutacji podczas
   `MembershipService::deactivate()` i potwierdzić, że legalne cofnięcie
   membershipu/roli pozostaje atomowe.
2. Ustalić poprawną kolejność i kontrakt bootstrapu zweryfikowanego
   administratora względem utworzenia membershipu operacyjnego; obsłużyć
   nieprawidłową kolejność czytelnym błędem domenowym albo poprawić dozwolony
   workflow.
3. Po remediacji uruchomić ponownie tę samą regresję na nowej disposable bazie,
   nadal wyłączając 17 przypadków. Nie osłabiać asercji i nie zmieniać
   uprawnień `veltra_app`.
4. Niezależny re-audyt bezpieczeństwa nadal pozostaje do wykonania; niniejsza
   praca nie testowała 17 scenariuszy i nie zamyka żadnego warunku akceptacji
   bezpieczeństwa.
5. Dopiero po przejściu regresji i niezależnym przeglądzie można ponownie
   oceniać gotowość Stage 2C. Do tego czasu brak podstaw do ACCEPT.

Zatrzymano się po udokumentowaniu błędów. Nie wykonano commitów, pushy,
wdrożeń ani migracji na istniejących bazach.
