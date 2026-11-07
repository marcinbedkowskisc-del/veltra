# VELTRA 2.0 — Autoryzowana weryfikacja bezpieczeństwa Stage 2C

**Data wykonania:** 6 listopada 2026 r.

**Podstawa:** przekazana w tej sesji decyzja właściciela autoryzująca dokładnie 17 istniejących metod i odrębny test VEL-DEF-007.

**Snapshot:** `c68db7201e26112b5067f8f6a430c2dd27df6487`, migracje 001–036. Worktree przed zadaniem był czysty.

**Charakter:** zbiór dowodów wykonawczych, nie niezależna decyzja akceptacyjna.
**Werdykt wykonawczy:** **weryfikacja nie zakończyła się pełnym PASS; brak podstaw do Stage 2C ACCEPT.**

## 1. Wyniki zbiorcze

Wykonano wszystkie 17 istniejących metod, każdą dokładnie raz, bez modyfikacji ich kodu, dzielenia, zastępowania lub osłabiania asercji:

| Wynik PHPUnit | Liczba |
|---|---:|
| Wykonane metody | **17** |
| PASS | **3** |
| FAIL — nieudane asercje | **2** |
| ERROR — wyjątki przerywające metodę | **12** |
| Asercje raportowane przez PHPUnit | **121** |
| Skipped | **0** |

W odczytanych wynikach nie wystąpiły komunikaty ostrzeżeń PHPUnit. Każda metoda ma osobny surowy log i JUnit XML; sumy wyliczono z XML, a nie z liczby scenariuszy opisowych.

**VEL-DEF-007, osobno:** **techniczny PASS — 13/13 prób odmowy**, z rzeczywistą rolą runtime i niezmienionym stanem przed/po. To skrypt integracyjny, nie test PHPUnit; jego 13 prób nie powiększa licznika 17 metod ani 121 asercji.

Nie zaobserwowano nieoczekiwanej skutecznej zmiany chronionych danych w przeprowadzonych próbach. Wyniki FAIL/ERROR wskazują konkretne niezgodności istniejących testów z aktualnym modelem ról, fixture'ami i zabezpieczeniami; **nie są zamienione na PASS ani uznane automatycznie za podatności aplikacji**. Gwarancje nieosiągniętych części metod pozostają **NOT VERIFIED**.

## 2. Izolacja udokumentowana przed próbami

Docker Server: **29.9.2**. Utworzono nowe zasoby z etykietą `veltra.audit=veltra-2c-auth-20261106-2332`:

| Zasób | Nazwa / identyfikator |
|---|---|
| Sieć | `veltra-2c-auth-20261106-2332`, ID `2d68c6e992c55410b18f4398e999eaa304e3f57a74bbf4c79d3737fe776b54ef` |
| PostgreSQL | `veltra-2c-auth-20261106-2332-pg`, ID `51129892c1fff019bbe0f1dd804184630b70dfa45ea16f41d90e7d5e8057180e` |
| Redis | `veltra-2c-auth-20261106-2332-redis`, ID `3413066ea8527d1821e51f4a2123aa2eb42e6189afbc1ac27b20af9932c8f464` |
| Runner | `veltra-2c-auth-20261106-2332-runner`, ID `4062fdc4f783adc1fc9fb70f239f071615bc598a564353abef4d8e63e5448ea7` |

Przed migracjami zapisano i sprawdzono:

- sieć ma `Internal=true`; każdy z trzech kontenerów należy wyłącznie do niej;
- brak połączenia z `veltra_default`, brak opublikowanych portów i bind mountów;
- brak podłączenia istniejących lub nowych trwałych wolumenów; PostgreSQL używa nowego tmpfs pod `/var/lib/postgresql/data`, Redis pod `/data`, runner pod `/var/www/html`;
- runner korzysta z osobnego obrazu narzędziowego PHP `veltra-php:stage1a`, nie obrazu ani kontenera działającej aplikacji; historia obrazu nie zawiera warstwy aplikacyjnej ani sekretów;
- kod i istniejące zależności skopiowano do tmpfs, wykluczając `.env*`, `storage`, cache aplikacji i inne dane; nie używano plików poświadczeń z poprzednich sesji;
- wszystkie hasła, APP_KEY i klucz podpisywania wygenerowano od nowa wyłącznie dla tej bazy;
- PostgreSQL odpowiadał na `pg_isready`; Redis zwrócił `PONG`; runner wykonywał PHP;
- faktyczne obrazy i ich identyfikatory zapisano w `isolation-before-migrations.json`.

Nie użyto istniejącego manifestu Compose bezpośrednio: zawiera stałe nazwy zasobów. Nowy zestaw uruchomiono własnym skryptem z unikalnymi nazwami, bez zmian konfiguracji projektu. ClamAV nie uruchamiano — wskazane metody oraz test licznika nie wymagały połączenia ze skanerem; nie korzystano z istniejącej usługi skanowania.

Stan kontenerów aplikacyjnych sprawdzano wyłącznie przez identyfikatory, nazwy i sieci. Nie łączono się z ich bazami, nie odczytywano sekretów ani danych biznesowych.

## 3. Role, schemat i połączenia

Przed testami potwierdzono:

| Właściwość | `veltra_app` | `veltra_migrator` |
|---|---|---|
| SUPERUSER / CREATEDB / CREATEROLE / BYPASSRLS | wszystkie false | wszystkie false |
| Właściciel bazy | nie | nie |
| CREATE na bazie | false | true |
| CREATE w `public` po migracjach | false | true |
| USAGE w `veltra_private` po migracjach | false | true |
| UPDATE licznika SKU | true, ograniczone triggerami | true |
| DELETE / TRUNCATE licznika SKU | false / false | true / true |

Właścicielem bazy jest odrębny `veltra_test_admin`; żadna z dwóch ról nie ma członkostwa w innych rolach. Uprawnienie UPDATE runtime nie jest dowodem możliwości dowolnej zmiany — test VEL-DEF-007 potwierdził odmowy.

Zastosowano **36 migracji** w nowej bazie `veltra_testing` jako migrator. Testy aplikacyjne korzystały z `DB_USERNAME=veltra_app`, osobnego `pgsql_setup` jako `veltra_migrator` i rzeczywistych poświadczeń runtime zgodnie z obecnym `Tests\TestCase`.

Pierwsze wywołanie migracji zatrzymało się przed utworzeniem tabeli `migrations`: zmiana wyłącznie `putenv()` nie nadpisała środowiska PHP odczytywanego przez aplikację i połączenie pozostało runtime. Po jawnym ustawieniu środowiska CLI także w `$_ENV`/`$_SERVER`, migracje wykonano prawidłowo jako migrator. Nie zmieniono pliku konfiguracji, ról ani asercji. Zachowano oba logi; nieudanej próby nie zalicza się do testów bezpieczeństwa ani do wyniku migracji.

Środowisko testowe: PostgreSQL 17 z lokalnego obrazu `postgres:17-alpine` (pełny wynik `SELECT version()` w dowodach), PHP **8.4.27**, PHPUnit **12.5.42**, Redis z obrazu `redis:7.4-alpine`.

## 4. Indywidualne wyniki 17 metod

Nazwy poniżej są dokładne. Numery odpowiadają planowi ponownego otwarcia. Dowód `methodNN.txt` to stdout/stderr, `methodNN.xml` to JUnit. Wynik ERROR zachowano jako ERROR; w kolumnie skutku jawnie wskazano NOT VERIFIED dla nieosiągniętej gwarancji.

| # | Dokładna metoda | Wynik / asercje | Faktyczny rezultat i granica dowodu |
|---:|---|---|---|
| 1 | `RawMaterialPermissionBootstrapTest::test_migration_role_is_not_superuser_or_database_owner` | **FAIL / 6** | Linia 49 oczekuje CREATE na bazie dla bieżącego połączenia. Faktycznie jest to ograniczony runtime bez CREATE. Gwarancja metody niezaliczona; niezależny odczyt metadanych potwierdza rozdzielenie ról. |
| 2 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_write_to_permission_operation_ledger_directly` | **FAIL / 4** | Linia 58 wymaga, aby domyślne połączenie było inną rolą niż runtime; obecny `Tests\TestCase` wymaga właśnie runtime. Metoda zatrzymana przed próbami ledgeru: ich wynik **NOT VERIFIED**. |
| 3 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table` | **PASS / 5** | Próby podpięcia chronionej funkcji do triggera tabeli tymczasowej i wywołania funkcji zostały odrzucone; asercje stanu ledgeru spełnione. |
| 4 | `RawMaterialPermissionBootstrapTest::test_permission_grant_validators_pin_public_before_temporary_schemas` | **PASS / 5** | Metoda potwierdziła konfigurację wyszukiwania schematów wskazanych walidatorów. Nie zastępuje testów innych funkcji. |
| 5 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` | **PASS / 8** | Próba podstawienia relacji tymczasowych nie autoryzowała trwałego grantu; asercje odmowy i stanu spełnione. |
| 6 | `RawMaterialPermissionBootstrapTest::test_initial_grant_is_explicit_audited_one_time_and_never_self_granted` | **ERROR / 1** | Linia 324: niepodpisany UPDATE `organizations.authorization_initialized` w fixture odrzucony z `42501`. Właściwy scenariusz bootstrapu **NOT VERIFIED**. |
| 7 | `RawMaterialPermissionBootstrapTest::test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql` | **ERROR / 1** | Linia 379: ta sama odmowa fixture `42501`. Właściwy replay nie został osiągnięty — **NOT VERIFIED**. |
| 8 | `RawMaterialPermissionBootstrapTest::test_migration_guard_fails_closed_for_an_active_historical_duplicate` | **ERROR / 1** | Linia 435: fixture odrzucony z `42501`, przed wyłączeniem triggerów i dodaniem duplikatu. Właściwy guard **NOT VERIFIED**. |
| 9 | `RawMaterialPermissionBootstrapTest::test_bootstrap_rejects_unknown_permissions_and_invalid_passwords` | **ERROR / 1** | Linia 494: fixture odrzucony z `42501`. Właściwe odmowy haseł/permissionów **NOT VERIFIED**. |
| 10 | `RawMaterialPermissionBootstrapTest::test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable` | **ERROR / 1** | Linia 533: fixture odrzucony z `42501`. Pełna gwarancja upgrade/idempotencji/revokacji **NOT VERIFIED**. |
| 11 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql` | **ERROR / 1** | Linia 664: fixture odrzucony z `42501`. Propagacja zagnieżdżona **NOT VERIFIED**. |
| 12 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants` | **ERROR / 1** | Linia 825: fixture odrzucony z `42501`. Właściwa gwarancja authority i fałszywych grantów **NOT VERIFIED**. |
| 13 | `RawMaterialPermissionBootstrapTest::test_delegation_is_limited_to_effective_authority_and_revocation_is_audited` | **ERROR / 1** | Linia 873: fixture odrzucony z `42501`. Pełny scenariusz delegacji/audytu **NOT VERIFIED**. |
| 14 | `RawMaterialPermissionBootstrapTest::test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants` | **ERROR / 1** | Linia 1007: fixture odrzucony z `42501`. Gwarancja trwałej revokacji po reaktywacji **NOT VERIFIED**. |
| 15 | `RawMaterialPermissionBootstrapTest::test_delegated_grants_expire_with_source_permission_and_delegation_authority` | **ERROR / 1** | Linia 1112: fixture odrzucony z `42501`. Właściwy scenariusz wygasania **NOT VERIFIED**. |
| 16 | `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` | **ERROR / 82** | Linia 393 oczekuje skutecznej zmiany nazwy dostawcy; aktualny trigger z 026 odrzuca ją z `23514`. Część wcześniejszych kontroli przeszła, ale metoda jako całość niezaliczona; dalsze scenariusze **NOT VERIFIED**. |
| 17 | `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql` | **ERROR / 1** | Linia 58 inicjalizuje autoryzację przed dodaniem działającego członkostwa administratora. Aktualny constraint odrzuca fixture z `23514`. Właściwe próby ochrony ostatniego administratora **NOT VERIFIED**. |

Każdą metodę wykonywano oddzielnym procesem z `--stop-on-failure --stop-on-error`. Po każdym niezerowym wyniku wykonywanie kolejnych metod wstrzymywano do oceny logu. Kontynuowano dopiero po stwierdzeniu, że powodem był błąd założenia/fixture'u lub bezpieczna odmowa, a nie nieoczekiwany sukces operacji chronionej. Nie ponawiano żadnej metody z rolą migratora zamiast runtime.

Szablon rzeczywistego polecenia zapisano również osobno dla metod 02–17:

```text
docker exec veltra-2c-auth-20261106-2332-runner vendor/bin/phpunit
  --do-not-cache-result --colors=never --stop-on-failure --stop-on-error
  --log-junit /tmp/methodNN.xml
  --filter '^Tests\\Feature\\KLASA::DOKLADNA_METODA$'
  tests/Feature/KLASA.php
```

### Wybrane surowe komunikaty

```text
#01
Failed asserting that 0 is identical to 1.
Tests: 1, Assertions: 6, Failures: 1.

#02
The test connection must use a separate migration role.
Failed asserting that two strings are not identical.
Tests: 1, Assertions: 4, Failures: 1.

#03: OK (1 test, 5 assertions)
#04: OK (1 test, 5 assertions)
#05: OK (1 test, 8 assertions)

#06–15
SQLSTATE[42501]: Insufficient privilege:
A signed authorization mutation ticket is required

#16
SQLSTATE[23514]: Check violation:
Supplier identity, business details or archive history are immutable
Tests: 1, Assertions: 82, Errors: 1.

#17
SQLSTATE[23514]: Check violation:
An active verified administrator with an operating membership must remain
Tests: 1, Assertions: 1, Errors: 1.
```

## 5. VEL-DEF-007 — osobny test bezpieczeństwa

Skrypt `stage2c-vel-def-007.php` powstał wyłącznie w przestrzeni sesji; nie dodano ani nie zmieniono testów repozytorium. Skrypt nie zastępuje żadnej z 17 metod.

### Fixture i warunki

1. Znormalizowana tożsamość połączenia: `session_user=current_user=veltra_app`, baza `veltra_testing`; inna tożsamość przerywa skrypt.
2. Syntetyczną organizację i pierwszego użytkownika utworzono przez `InstallationService` i `IdentityService`. Weryfikację email ustawiono w istniejącym podpisanym kontekście `accountConsume`, bez przepisywania stanu approval.
3. Przez `SkuAllocationService::reserveImported()` zarezerwowano `RAW-000050`, a `allocateNext()` wygenerował `RAW-000051`. Obie poprawne operacje zatwierdzono przed próbami; licznik wynosił **51**, przypisania miały źródła **IMPORTED** i **GENERATED**.
4. Każdą próbę wykonano w osobnej transakcji runtime. Po wykonaniu operacji wymuszano `SET CONSTRAINTS ALL IMMEDIATE`, aby sukces instrukcji z błędem odroczonym nie został uznany za sukces obejścia.
5. Oczekiwano odmowy `42501` lub `23514`. Inny błąd przerywa skrypt jako ERROR; nie jest sukcesem bezpieczeństwa. Skuteczna operacja bez błędu powoduje natychmiastowy FAIL, zapis stanu w transakcji, rollback i zatrzymanie wszystkich dalszych prób.
6. Po każdej oczekiwanej odmowie transakcję wycofywano, a pełne snapshoty liczników i przypisań porównywano z baseline. W ostatnim snapshotcie zgadzały się również UUID, wartości, źródła i daty utworzenia.

| Próba | Kontekst | SQLSTATE | Wynik / stan |
|---|---|---|---|
| Obniżenie `last_value` do 1 | bez ticketu | `23514` | PASS, bez zmian |
| Zwiększenie do 52 bez przydziału | bez ticketu | `42501` | PASS, bez zmian |
| Zmiana prefiksu licznika na INT | bez ticketu | `23514` | PASS, bez zmian |
| DELETE licznika | bez ticketu | `42501` | PASS, bez zmian |
| TRUNCATE liczników | bez ticketu | `42501` | PASS, bez zmian |
| UPDATE podmiotu istniejącego przypisania | bez ticketu | `42501` | PASS, bez zmian |
| DELETE przypisania | bez ticketu | `42501` | PASS, bez zmian |
| TRUNCATE przypisań | bez ticketu | `42501` | PASS, bez zmian |
| INSERT nowego nieautoryzowanego przypisania | bez ticketu | `42501` | PASS, bez zmian |
| Obniżenie `last_value` do 1 | ważny `sku.allocate` | `23514` | PASS, bez zmian |
| Zwiększenie do 52 bez pasującego przypisania | ważny `sku.allocate`, kontrola odroczona | `23514` | PASS, bez zmian |
| UPDATE numeru istniejącego przypisania | ważny `sku.allocate` | `42501` | PASS, bez zmian |
| DELETE przypisania | ważny `sku.allocate` | `42501` | PASS, bez zmian |

**Stan przed i po:** jeden licznik RAW równy **51**, dokładnie dwa niezmienione przypisania `RAW-000050` i `RAW-000051`. Końcowa baza testowa miała zero rekordów `location_module_states`, zero grantów RAW i zero wpisów ledgeru grantów.

**Wniosek wykonawczy:** dostarczono aktualny pozytywny dowód odmów runtime dla badanego zakresu VEL-DEF-007 po migracji 035. Nie jest to dowód przeciw administratorowi bazy, migratorowi ani dowolnemu RCE w zaufanym procesie Laravel. Nie badano wszystkich prefiksów, wszystkich kombinacji tenantów ani wszystkich sposobów błędnej konfiguracji. Formalne zamknięcie VEL-DEF-007 pozostawiono niezależnemu recenzentowi i właścicielowi; statusów istniejących rejestrów nie zmieniano.

## 6. Stan przed/po oraz ograniczenia dowodu

- Przed próbami: 36 migracji, zero organizacji i przypisań.
- Dla metod 02–17 zapisano oddzielnie liczebności organizacji, użytkowników, grantów, ledgeru, liczników i przypisań oraz listę triggerów i ich stanów `tgenabled`, przed i po każdej metodzie. Wszystkie 16 par porównań jest identycznych.
- Metoda 01 jest odczytowa; baseline zapisano w dowodzie ról/stanu przed testami, a pusty stan po niej także w baseline metody 02.
- `InstallationTestCase::tearDown()` czyści syntetyczne organizacje przez odrębne połączenie setup. Dlatego identyczność snapshotów po teardown **nie dowodzi sama w sobie**, że nic nie zmieniło się wewnątrz metody. Nie używa się jej do przemianowania ERROR/FAIL na PASS. Dowód poszczególnych gwarancji pochodzi z osiągniętych asercji i błędów; nieosiągniętych scenariuszy nie uznano za zweryfikowane.
- Metoda 16 zawiera oczekiwaną przez test skuteczną zmianę nazwy kategorii w zakresie odroczonego VEL-DEF-001. Nie przedstawia się jej jako nowej nieoczekiwanej zmiany ani jako powodu automatycznego ponownego otwarcia odroczenia. Przepływ zakończył się później bezpieczną odmową zmiany dostawcy i rollbackiem transakcji.
- Nie potwierdzono nieoczekiwanego skutecznego zapisu chronionego, który uruchamiałby warunek obowiązkowego zatrzymania wszystkich dalszych testów. Nie wykonywano dodatkowych prób RBAC/replay zastępujących niesprawne metody.

## 7. Potwierdzone niezgodności testów — bez napraw

Nie potwierdzono podatności aplikacyjnej w osiągniętym zakresie. Zidentyfikowano następujące **defekty pokrycia/zgodności testów**, a nie dowody exploitu:

| ID | Lokalizacja | Dowód i wpływ |
|---|---|---|
| T-01 | `RawMaterialPermissionBootstrapTest.php:31–60`, `Tests\TestCase.php:44–67` | Metody 01/02 zachowały założenie o domyślnym migratorze, sprzeczne z obecnym wymaganiem domyślnego runtime. Dwie asercje FAIL; próby ledgeru w 02 nieosiągnięte. |
| T-02 | `RawMaterialPermissionBootstrapTest.php:324,379,435,494,533,664,825,873,1007,1112` | Dziesięć metod ustawia `authorization_initialized` przez niepodpisany domyślny runtime, zamiast zgodnego przygotowania fixture'u. Wszystkie kończą się ERROR 42501 przed właściwymi gwarancjami. |
| T-03 | `AuthorizationMutationBoundaryTest.php:393` | Metoda oczekuje aktualizacji chronionych danych dostawcy, której migracja 026 już zabrania. ERROR 23514 po 82 asercjach; reszta metody niewykonana. |
| T-04 | `RoleAdministrationTest.php:58–60` | Fixture inicjalizuje autoryzację zanim utworzy obowiązkowe członkostwo administratora; natychmiastowy constraint odrzuca go. ERROR 23514, właściwy scenariusz nieosiągnięty. |

Nie poprawiano tych metod, konfiguracji ani kodu aplikacji. Ich przyszła remediacja wymaga osobnego zakresu zgody — aktualna autoryzacja wprost zabrania zmiany 17 metod. Nie nadawano runtime CREATE ani nie uruchamiano aplikacji jako migrator w celu uzyskania PASS.

VEL-DEF-001–006 pozostają odroczone zgodnie z decyzją właściciela. Nie zmieniano rejestru ryzyk, gotowości `RAW_MATERIALS`, VSEF ani statusu Stage 2C.

## 8. Surowe dowody i odtwarzalność

Dowody utrwalono poza repozytorium:

```text
/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/
  stage2c-authorized-setup.py
  stage2c-run-methods.py
  stage2c-vel-def-007.php
  stage2c-authorized-cleanup.py
  stage2c-authorized-evidence/
    baseline.txt
    isolation-before-migrations.json
    role-setup.txt
    roles-before-migrations.txt
    roles-and-state-before-tests.txt
    migrations.txt
    migrations-final.txt
    postgres-version.txt
    source-hashes.json
    authorized-methods.json
    method01.txt ... method17.txt
    method01.xml ... method17.xml
    method02-before.txt ... method17-before.txt
    method02-after.txt ... method17-after.txt
    method02-command.json ... method17-command.json
    vel-def-007.jsonl
    final-database-state.txt
    cleanup.json
```

Zachowano stdout/stderr i JUnit każdej metody oraz rzeczywiste komunikaty SQLSTATE, nie tylko streszczenie. `source-hashes.json` zawiera SHA-256 migracji i plików testów Feature; porównanie po wykonaniu wykazało **zero zmienionych hashy**. Skrypty używają wyłącznie nowych syntetycznych poświadczeń. Pliki z faktycznymi wygenerowanymi hasłami usunięto po likwidacji usług; nie są częścią pakietu dowodowego.

Manifest `evidence-sha256.json` pozwala sprawdzić integralność logów i skryptów. Przenośny pakiet tych dowodów i skryptów: `stage2c-authorized-evidence.tar.gz` w powyższym katalogu `files/`. Pakiet nie zawiera bazy ani wygenerowanych sekretów.

## 9. Sprzątnięcie i brak zmian aplikacji

Po zebraniu końcowego stanu usunięto dokładnie trzy zapisane kontenery oraz zapisaną sieć. Przed usunięciem ponownie sprawdzono ich ID, nazwy, etykietę i członkostwo sieci. Nie wykonywano `prune`, usuwania aplikacyjnych wolumenów ani globalnych operacji Docker.

Po sprzątnięciu:

- brak kontenerów i sieci oznaczonych własną etykietą audytu;
- sześć istniejących kontenerów aplikacyjnych nadal działa, ma identyczne ID i sieci jak w baseline;
- nie zmieniono kodu produkcyjnego, konfiguracji projektu, migracji ani żadnej z 17 metod;
- nie wykonano deploymentu, commitów ani pushy;
- nie aktywowano `RAW_MATERIALS` ani VSEF.

Jedyny nowy plik w repozytorium to niniejszy raport. Skrypty i surowe dowody znajdują się w przestrzeni sesji.

## 10. Dalsza decyzja

**Nie nadano Stage 2C ACCEPT.** Trzy metody uzyskały PASS, ale 14 metod nie zakończyło się poprawnie; ich nieosiągnięte gwarancje pozostają NOT VERIFIED. Odrębny test VEL-DEF-007 dostarcza pozytywnego dowodu wykonawczego w opisanym zakresie, bez samodzielnej zmiany statusu ryzyka.

Niezależny przegląd tych wyników nie został w tej pracy zakończony. Wstępnie skierowany recenzent miał wyłącznie uprawnienia odczytowe i nie dostarczył wykonawczych wyników; nie wliczono jego analizy do liczników testów. Właściwe wykonanie i dowody opisane powyżej zebrano narzędziami tej sesji. Formalny werdykt wymaga niezależnego przeglądu materiału oraz odrębnej decyzji właściciela. Jeśli właściciel zdecyduje o remediacji testów, powinna ona mieć osobną autoryzację i zachować pełne historyczne wyniki niniejszego przebiegu.
