# VELTRA 2.0 — Remediacja testów bezpieczeństwa Stage 2C i ponowna weryfikacja

**Data:** 7 listopada 2026 r.

**Podstawa:** jawna decyzja właściciela o wąskich zmianach testów i ponownym wykonaniu 17 metod, zgodnie z [raportem triage](./VELTRA_STAGE_2C_SECURITY_TEST_FAILURE_TRIAGE.md).

**Snapshot bazowy:** `c68db7201e26112b5067f8f6a430c2dd27df6487`, migracje 001–036. Bieżące poprawki pozostają niezatwierdzone w Git.

**Werdykt wykonawczy: NIEPEŁNY PASS — Stage 2C bez ACCEPT.** Zatwierdzone korekty T-01–T-04 wdrożono; wykonanie odsłoniło dalsze niezgodności istniejących asercji. Końcowo **12/17 metod PASS, 4 FAIL, 1 ERROR, 257 asercji, 0 skipped**. Pełna regresja: **252 testy, 2370 asercji, 247 PASS, 4 FAIL, 1 ERROR, 0 skipped**.

Nie zmieniono kodu aplikacji, migracji, uprawnień, triggerów produkcyjnych ani wspólnego TestCase. Nie potwierdzono nowej podatności aplikacji. Bezpiecznych odmów i nieosiągniętych gwarancji **nie przemianowano na PASS**.

## 1. Zachowanie wcześniejszych dowodów

Zachowano bez zmian:

- [Oryginalny raport wykonawczy](./VELTRA_STAGE_2C_AUTHORIZED_SECURITY_VERIFICATION_REPORT.md) i [jego kopię Drive](https://drive.google.com/file/d/1F4yhFgHIBgJ3Wy7qMEpkrnUxVxdGBeGX/view).
- Wszystkie 17 pierwotnych JUnit XML i surowych logów, oryginalne manifesty, snapshoty, skrypty oraz [archiwum dowodowe poprzedniego wykonania](/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/stage2c-authorized-evidence.tar.gz).
- Oryginalne `vel-def-007.jsonl` i skrypt 13/13 odmów; **nie wykonywano odrębnego skryptu VEL-DEF-007 ponownie**.
- [Raport triage](./VELTRA_STAGE_2C_SECURITY_TEST_FAILURE_TRIAGE.md), stanowiący zatwierdzony zakres prac.

Przed i po wykonaniu sprawdzono 99 wpisów oryginalnego `evidence-sha256.json`: **0 niezgodności**. Osobno potwierdzono niezmieniony SHA-256 poprzedniego archiwum i obu raportów. Nowy katalog dowodów nie nadpisuje poprzedniego.

Oryginalny wynik nadal wynosi **3 PASS, 2 FAIL, 12 ERROR, 121 asercji**. Jest to inny przebieg, nie wynik „skorygowany wstecz”.

## 2. Dokładny zakres zmian źródłowych

Zmodyfikowano wyłącznie trzy zatwierdzone istniejące pliki:

### 2.1. [RawMaterialPermissionBootstrapTest.php](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php)

- **T-01, metoda 1:** zapytanie o migratora działa przez istniejące `setupConnection()`. Dodano asercje `current_user=session_user=veltra_migrator`, bazy `veltra_testing` oraz kontrolę niezmienionego połączenia runtime. Zachowano brak SUPERUSER/CREATEDB/CREATEROLE/własności bazy oraz wymagane CREATE migratora.
- **T-01, metoda 2:** domyślne `pgsql` musi odpowiadać runtime, a setup musi być odrębny. Na rzeczywistym PDO sprawdzane są `current_user=session_user=veltra_app` i baza. Wszystkie istniejące próby ledgeru pozostały na tym PDO runtime.
- **T-02, metody 6–15:** niepodpisane UPDATE latcha zastąpiono istniejącym `updateFixture()` po przygotowaniu wymaganych administratorów i członkostw. To jawny kontrolowany setup, nie test podpisanego bootstrapu administratora.
- Bezpośrednie fixture'y modułu w metodach 11 i 13 oraz `enableRawMaterials()` zastąpiono `ModuleConfigurationService::configure()` z aktorem, kontekstem, hasłem i wskazaną lokalizacją. Zachowano wyłącznie procesowy testowy `ModuleCatalogFixtures::readyWithRawMaterials()`.
- W metodach 10 i 13 niepodpisaną deaktywację użytkownika zastąpiono istniejącym podpisanym `IdentityService::disable()`, jak w metodzie 14. Zachowano sprawdzenia propagacji revoke i audytu, w tym brak aktora dla tego typu deaktywacji. Syntetyczną reaktywację użytkownika w metodach 10, 13 i 14 przeniesiono do istniejącego `updateFixture()`; nie przeniesiono tam prób odmów.
- **Metoda 8:** wyłącznie historyczny setup duplikatu działa przez `withSetupTransaction()` i przekazane połączenie migratora. W transakcji chwilowo wyłączane i przywracane są tylko dwa wcześniej istniejące triggery biznesowe:
  - `organization_permission_grants_validate`;
  - `organization_permission_grants_bootstrap_replay_guard`.
- **Metoda 15:** `insertBootstrapGrants()` używa istniejącego `withSetupTransaction()` do syntetycznych czasowych grantów i odpowiadającego audytu. Zachowano daty, źródła, operation_id, prawo delegowania, rzeczywiste oczekiwanie 31+10 sekund i wszystkie asercje expiry.

### 2.2. [AuthorizationMutationBoundaryTest.php](./backend/tests/Feature/AuthorizationMutationBoundaryTest.php)

- **T-03, metoda 16:** zamiast oczekiwać sukcesu zmiany `organization_suppliers.display_name`, metoda wymaga odmowy **SQLSTATE 23514**.
- Dodano sprawdzenie istnienia dostawcy, pełny snapshot rekordu oraz pełny snapshot zdarzeń audytu handlowego przed/po; zachowano również dotychczasowy licznik audytu.
- `assertRuntimeDenied()` otrzymał opcjonalny ścisły oczekiwany SQLSTATE dla tego jednego wywołania. Dotychczasowy warunek odmowy pozostał; pozostałe wywołania nie zostały poszerzone.
- Zachowano transakcyjną obsługę odmowy/savepoint, pozytywne podpisane przepływy i wszystkie pozostałe operacje macierzy.

### 2.3. [RoleAdministrationTest.php](./backend/tests/Feature/RoleAdministrationTest.php)

- **T-04, metoda 17:** aktywna lokalizacja i `MembershipService::assign()` występują przed ustawieniem `authorization_initialized=true`.
- Po pierwszym wykonaniu wykryto dalszy problem fixture'u: ignorowano świeży model zwrócony przez `updateFixture()`. Cache relacji `$actor->organization` nadal miał pierwotne `false`, więc `forceFill(false)->save()` nie wysyłało UPDATE. W Eloquent [Model.php](./backend/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php), linie 1431–1433, brak dirty attributes powoduje sukces bez `performUpdate()`.
- Zachowano zwrócony model w `$organization`, dodano asercję początkowego `true` i użyto tego modelu do **tej samej** próby cofnięcia latcha. Nie zmieniono oczekiwanego `23514` ani połączenia próby.
- Wszystkie dotychczasowe próby revoke/deaktywacji i bezpośredniego SQL zachowano.

Nowy raport jest dodatkowym plikiem dokumentacyjnym wymaganym przez zlecenie. Skrypty wykonawcze i dowody znajdują się poza repozytorium. Nie modyfikowano [TestCase.php](./backend/tests/TestCase.php), [InstallationTestCase.php](./backend/tests/InstallationTestCase.php) ani żadnych usług aplikacji.

## 3. Obowiązkowy przegląd zachowania gwarancji

Przed pierwszym wykonaniem odczytano pełny diff i zapisano go jako `reviewed-diff.patch`; przed ponowieniem metody 17 sprawdzono jej dodatkowy diff i zapisano `reviewed-diff-final.patch`. Przegląd był przeglądem wykonawcy, nie niezależną akceptacją bezpieczeństwa.

| Kontrola | Wynik |
|---|---|
| Zmienione źródła wyłącznie w trzech zatwierdzonych plikach | Potwierdzono przez `git diff --name-only` i hashe |
| Dokładne nazwy i liczba istniejących metod | Zachowane; porównano listy metod z HEAD i kodem po korektach |
| Negatywne próby runtime/ledgeru nie przeniesione na migratora | Potwierdzono; default `pgsql` pozostaje `veltra_app`, osobne PDO również runtime |
| Migrator używany tylko do inspekcji migratora i jawnych fixture'ów | Potwierdzono; część oryginalnych prób invariantów metody 17 celowo nadal korzysta z setupu |
| Niepodpisane forged INSERT/replay pozostają niepodpisane | Potwierdzono; nie dodano signera do prób, aby ominąć wcześniejszą odmowę |
| Signed-mutation protection i SECURITY DEFINER | Bez zmian, nie wyłączano ich dla fixture'u historycznego |
| Historyczny setup metody 8 | Tylko dwa jawnie autoryzowane triggery, przywracane w `finally`, transakcyjnie; metoda PASS |
| Supplier denial | Zaostrzona do ścisłego `23514`, identyczny rekord i identyczny audyt |
| Bootstrap, authority, delegacja, revoke, replay i expiry | Zachowano wszystkie istniejące próby i asercje; cztery niezgodne oczekiwania kodu nadal skutkują FAIL |
| Ostatni administrator | Zachowano oryginalne odmowy i oczekiwane kody; dodano prawidłowy stan wejściowy |
| Produkcyjne źródła, triggery, permissiony, konfiguracja i readiness | Bez zmian |

Nie rozszerzono zbiorów dopuszczalnych SQLSTATE, nie usunięto żadnej wcześniej niezaliczanej operacji i nie wprowadzono skip. Nie zmieniono `23514` na `42501` dla czterech dalszych niezgodności w celu uzyskania PASS.

## 4. Nowe środowisko disposable i izolacja przed testami

**Docker Server:** 29.9.2.

**Prefiks zasobów i etykieta:** `veltra-2c-rerun-20261107-0032`, `veltra.audit=veltra-2c-rerun-20261107-0032`.

| Zasób | Nazwa / identyfikator |
|---|---|
| Sieć internal | `veltra-2c-rerun-20261107-0032`, ID `3a39a51f5c582b572b7e1ba90f401f2ff0346c4e1190d9612bcaa6a0a52641cd` |
| PostgreSQL | `veltra-2c-rerun-20261107-0032-pg`, ID `9407a91c78752c8a29adcea9d455cb0393d812739c87727f0b07d2f668f52c4f` |
| Redis | `veltra-2c-rerun-20261107-0032-redis`, ID `fd4ff43627866657473b9eae8db238708b97d29e808b659d256edffca45a97c3` |
| Runner | `veltra-2c-rerun-20261107-0032-runner`, ID `a710de1c1548248a3362867bfe5d255f7547f9418524ba3e6e0712829b69bb9a` |
| ClamAV do pełnej regresji | `veltra-2c-rerun-20261107-0032-clamav`, ID `c3e55100da72d58fc612871834f659bc4ee5be6a28786251682b2529be754b2e` |

Przed migracjami zapisano `isolation-before-migrations.json`. Przed regresją osobno zapisano `scanner-isolation.json` i odpowiedź gotowości nowego skanera.

- Sieć ma `Internal=true`; kontenery należą wyłącznie do niej, bez sieci aplikacji.
- Brak bind mountów, named/anonymous volumes i opublikowanych portów hosta.
- PostgreSQL ma nowy tmpfs `/var/lib/postgresql/data`, Redis `/data`, runner `/var/www/html`. ClamAV korzysta wyłącznie z warstw własnego nowego kontenera i danych obrazu, bez mountów; aktualizator FreshClam wyłączony, sieć nadal internal.
- Użyto `postgres:17-alpine`, `redis:7.4-alpine`, obrazu narzędziowego PHP `veltra-php:stage1a` i `clamav/clamav:1.4`, nie kontenerów aplikacji.
- Nowe losowe hasła, APP_KEY i klucz podpisywania, wyłącznie syntetyczne. Nie używano wcześniejszych env/sekretów.
- Do tmpfs skopiowano kod i istniejące zależności, pomijając `.env*`, `storage`, cache konfiguracji i cache PHPUnit. Nie instalowano pakietów.
- Potwierdzono `pg_isready`, Redis `PONG`, wykonywanie PHP i gotowość nowego ClamAV przed zależnymi testami.
- Pełna regresja otrzymała jawne hosty nowych usług ClamAV i testowego Redis; nie używano usług aplikacji.

Faktyczna wersja PostgreSQL: **17.11**, PHP **8.4.27**, PHPUnit **12.5.42**.

### Role i stan przed próbami

Zastosowano **36/36 migracji** jako `veltra_migrator`; log zachowano. Baza `veltra_testing` ma właściciela `veltra_test_admin`, nie runtime ani migratora.

| Właściwość | Runtime `veltra_app` | Setup `veltra_migrator` |
|---|---|---|
| Faktyczny `current_user` / `session_user` | oba `veltra_app` | oba `veltra_migrator` |
| SUPERUSER / CREATEDB / CREATEROLE / BYPASSRLS | wszystkie false | wszystkie false |
| Właściciel bazy | nie | nie |
| CREATE na bazie i w `public` | false / false | true / true |
| USAGE `veltra_private` | false | true |
| TRUNCATE `organizations` | false | true, wyłącznie setup |
| INSERT ledgeru grantów | false | true, wyłącznie zaufany setup |
| Członkostwa innych ról | brak | brak |
| Własność tabel `public` i `veltra_private` | brak | odpowiednio 57 i 5 tabel |

Przed testami potwierdzono wszystkie niesystemowe triggery aktywne (`tgenabled=O`), ponownie sprawdzono je po metodzie 8 i na końcu. Nie było wyłączenia ochrony podpisanych mutacji.

## 5. Indywidualne wyniki 17 metod

Każda metoda ma osobny stdout/stderr, JUnit XML, zapis polecenia i snapshot przed/po. Po niezerowym wyniku pętla zatrzymywała się; odczytywano wynik i źródła przed rozpoczęciem następnej metody. Zapisano indywidualne `failure-review-*.json`.

Poniższa tabela przedstawia **ostatni indywidualny wynik każdej metody**, nie ukrywa wcześniejszej próby metody 17. Wyniki pełnej regresji potwierdziły ten sam końcowy podział.

| # | Dokładna metoda | Wynik | Asercje | Dowód XML |
|---:|---|---|---:|---|
| 1 | `RawMaterialPermissionBootstrapTest::test_migration_role_is_not_superuser_or_database_owner` | PASS | 9 | `method01.xml` |
| 2 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_write_to_permission_operation_ledger_directly` | PASS | 21 | `method02.xml` |
| 3 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table` | PASS | 5 | `method03.xml` |
| 4 | `RawMaterialPermissionBootstrapTest::test_permission_grant_validators_pin_public_before_temporary_schemas` | PASS | 5 | `method04.xml` |
| 5 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` | PASS | 8 | `method05.xml` |
| 6 | `RawMaterialPermissionBootstrapTest::test_initial_grant_is_explicit_audited_one_time_and_never_self_granted` | PASS | 6 | `method06.xml` |
| 7 | `RawMaterialPermissionBootstrapTest::test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql` | **FAIL** | 3 | `method07.xml` |
| 8 | `RawMaterialPermissionBootstrapTest::test_migration_guard_fails_closed_for_an_active_historical_duplicate` | PASS | 3 | `method08.xml` |
| 9 | `RawMaterialPermissionBootstrapTest::test_bootstrap_rejects_unknown_permissions_and_invalid_passwords` | PASS | 3 | `method09.xml` |
| 10 | `RawMaterialPermissionBootstrapTest::test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable` | PASS | 21 | `method10.xml` |
| 11 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql` | **FAIL** | 9 | `method11.xml` |
| 12 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants` | **FAIL** | 4 | `method12.xml` |
| 13 | `RawMaterialPermissionBootstrapTest::test_delegation_is_limited_to_effective_authority_and_revocation_is_audited` | **FAIL** | 14 | `method13.xml` |
| 14 | `RawMaterialPermissionBootstrapTest::test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants` | PASS | 18 | `method14.xml` |
| 15 | `RawMaterialPermissionBootstrapTest::test_delegated_grants_expire_with_source_permission_and_delegation_authority` | PASS | 10 | `method15.xml` |
| 16 | `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` | **ERROR** | 107 | `method16.xml` |
| 17 | `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql` | PASS | 11 | `attempt02/method17.xml` |

### Pełna historia prób, bez nadpisywania

| Zestaw | Testy / wykonania | Asercje | PASS | FAIL | ERROR | Skipped |
|---|---:|---:|---:|---:|---:|---:|
| Oryginalna weryfikacja, zachowana | 17 | 121 | 3 | 2 | 12 | 0 |
| Pierwsza runda po korektach w tym zadaniu | 17 | 254 | 11 | 5 | 1 | 0 |
| Dodatkowa próba metody 17 po korekcie referencji modelu | 1 | 11 | 1 | 0 | 0 | 0 |
| Końcowy zestaw ostatnich wyników 17 różnych metod | 17 | 257 | 12 | 4 | 1 | 0 |

W indywidualnej fazie wykonano zatem **18 wywołań 17 różnych metod**, a nie „17 metod dokładnie raz”. Pierwszy FAIL metody 17 pozostał w `method17.txt/xml`; wynik po poprawce znajduje się w nowym podkatalogu `attempt02/`.

## 6. Pozostałe niezgodności — bez osłabiania asercji

### 6.1. Cztery FAIL: oczekiwane 23514, faktyczne 42501

| Metoda | Aktualna linia | Oczekiwanie / obserwacja | Skutek dla gwarancji |
|---|---:|---|---|
| 7 | [428](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php) | Replay INSERT: `23514` / `42501` | Odmowa runtime wystąpiła, ale metoda przerwała się przed końcowymi kontrolami grantów/audytu; szczegółowy guard replay pozostaje niezaliczony tą metodą |
| 11 | [817](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php) | Forged upgrade po revoke: `23514` / `42501` | Wcześniejsze sprawdzenia propagacji revoke osiągnięte; nie osiągnięto końcowych liczników po odmowie |
| 12 | [864](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php) | Forged root grant: `23514` / `42501` | Domenowa odmowa upgrade bez authority osiągnięta; niezgodne oczekiwanie kodu bezpośredniego SQL |
| 13 | [963](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php) | Delegacja przez zawieszonego grantora w SQL: `23514` / `42501` | Wcześniejsze delegacje, audyt, revoke i HTTP 403 osiągnięte; późniejsze reaktywacje i próba eskalacji w tej metodzie nieosiągnięte |

Kod `42501` odpowiada wcześniejszej granicy uprawnień/podpisanej mutacji, zanim test może oczekiwać biznesowego `23514`. Bieżąca [migracja 022](./backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php) wymaga podpisu dla runtime. Surowe wyniki PHPUnit zachowują oba kody z porównania; testy chwytają pierwotny QueryException, więc w tych FAIL nie zachowują pełnego komunikatu PostgreSQL. Nie przedstawia się samego kodu jako dowodu, który konkretny dalszy guard został osiągnięty.

**Nie zmieniono tych asercji na szerszy zbiór kodów ani nie podpisano forged SQL**, aby dotrzeć do kolejnej warstwy. To wymagałoby nowej decyzji o dokładnym kontrakcie i zakresie weryfikacji. Nie można twierdzić, że biznesowy guard replay został przetestowany przez samą odmowę podpisu. Osobno metoda 8 rzeczywiście przetestowała historyczny guard duplikatu i uzyskała PASS.

### 6.2. Metoda 16: dalsza niezgodność, odroczony constraint produktu

Po poprawce dostawcy metoda osiągnęła **107 asercji**, w tym:

- ścisłą odmowę `23514` zmiany dostawcy;
- niezmieniony pełny rekord i audyt handlowy;
- pozytywne podpisane operacje aplikacji;
- pozostałe istniejące próby macierzy i końcowe próby grantów/ról.

Następnie końcowe [SET CONSTRAINTS ALL IMMEDIATE](./backend/tests/Feature/AuthorizationMutationBoundaryTest.php) w **linii 280** wywołało:

```text
SQLSTATE[23514]
Aktywny produkt dostawcy wymaga rewizji początkowej
supplier_product_initial_revision_validate()
```

Istniejąca dalsza część macierzy wstawia `supplier_products` jako ACTIVE bez rewizji 1 i oczekuje przejściowego sukcesu INSERT. [Migracja 025](./backend/database/migrations/2026_11_05_000025_enforce_raw_material_business_integrity.php), linie 103–146, wymaga rewizji przez constraint trigger `DEFERRABLE INITIALLY DEFERRED`. Stan jest odrzucany przy wymuszeniu constraintów, przed zatwierdzeniem transakcji.

To **nie jest dowód utrwalenia nieprawidłowego produktu ani nowa podatność aplikacji**. Jest to dalsze oczekiwanie starszego testu niezgodne z aktualną ochroną. W zatwierdzonym T-03 poprawiono wyłącznie mutację danych dostawcy; nie usunięto, nie pominięto ani nie przerobiono pozostałego przypadku, żeby uzyskać PASS. Wynik całej metody pozostaje **ERROR**, nawet jeśli poprawiona odmowa dostawcy została osiągnięta.

### 6.3. Metoda 17: dodatkowy FAIL fixture'u został naprawiony

Pierwszy FAIL w linii 76 miał komunikat `PostgreSQL did not reject the operation with SQLSTATE 23514.` Analiza źródeł wykazała operację bez dirty attributes na nieaktualnym modelu, nie skuteczny UPDATE chronionego pola. Nie użyto tego komunikatu jako dowodu obejścia latcha.

Po użyciu świeżego modelu i dodatkowej asercji stanu wejściowego metoda uzyskała **PASS / 11 asercji**, również w pełnej regresji. Wszystkie oryginalne oczekiwania odmów pozostały.

## 7. Pełna dozwolona regresja backendu

Przed wykonaniem zinwentaryzowano suite przez `--list-tests`. Wykonano całą listę **252 przypadków**, bez wyłączenia żadnej z 17 autoryzowanych metod i bez uruchamiania dodatkowego skryptu VEL-DEF-007. Nie dodano ani nie odtworzono nowych scenariuszy ataku.

Regresję podzielono na sześć rozłącznych partii, kończących się na znanych FAIL/ERROR; użyto `--stop-on-failure --stop-on-error`. Po każdej takiej partii odczytano wynik, porównano z indywidualną diagnozą i zapisano decyzję o kontynuacji. Filtry są pozytywnymi selektorami kolejnych rozłącznych części suite, **nie wyłączeniem niezaliczonych testów**.

| Partia | Testy | Asercje | FAIL | ERROR | Skipped |
|---:|---:|---:|---:|---:|---:|
| 1, do metody 16 | 16 | 211 | 0 | 1 | 0 |
| 2, do metody 7 | 144 | 1156 | 1 | 0 | 0 |
| 3, do metody 11 | 4 | 36 | 1 | 0 | 0 |
| 4, metoda 12 | 1 | 4 | 1 | 0 | 0 |
| 5, metoda 13 | 1 | 14 | 1 | 0 | 0 |
| 6, pozostałe przypadki | 86 | 949 | 0 | 0 | 0 |
| **Łącznie** | **252** | **2370** | **4** | **1** | **0** |

Sprawdzono programowo zgodność liczności metod i przypadków z listą suite, również dla datasetów: **brak pominięć i duplikatów**. Wszystkie 17 autoryzowanych metod wystąpiło w tej regresji dokładnie raz.

Wynik końcowy to **247 PASS, 4 FAIL, 1 ERROR**. W samym dotychczas dozwolonym zbiorze poza siedemnastką: **235 testów / 2113 asercji / 0 failures / 0 errors / 0 skipped**. Nie stwierdzono dodatkowej regresji poza pięcioma opisanymi niezgodnościami w autoryzowanej siedemnastce.

Pełna suite **nie jest zielona**. Wyniki partii niezerowych zachowano; skrypt orkiestracji po rozpoznaniu znanego wyniku zezwalał na kontynuację, co nie zmienia kodu wyjścia PHPUnit zapisanego w dowodach.

### Jakość

| Kontrola | Wynik |
|---|---|
| PHP lint: `app`, `bootstrap`, `config`, `database`, `routes`, `tests` | **242 pliki, exit 0** |
| Pełny `vendor/bin/pint --test` | **246 plików, PASS, exit 0** |
| `git diff --check` | **PASS, exit 0** |
| Porównanie kopii źródeł runnera i zatwierdzonych plików | Zgodne |
| Weryfikacja źródeł produkcyjnych/konfiguracji | Bez zmian; diff obejmuje tylko trzy pliki testów |

Pint początkowo sformatował wyłącznie trzy zatwierdzone pliki; końcowa kontrola całego backendu była wyłącznie `--test`. Żaden formatter nie zmienił pozostałych źródeł.

## 8. Stan przed/po, sprzątnięcie i ograniczenia dowodu

- Dla 18 indywidualnych wykonań zachowano 18 par snapshotów liczebności oraz listy triggerów przed/po; wszystkie pary identyczne.
- Setup i teardown bazowych testów czyszczą dane przez odrębnego migratora. Dlatego identyczność snapshotów po teardown **nie dowodzi sama w sobie**, że nie wystąpiły przejściowe zapisy wewnątrz transakcji.
- Metoda 16 zachowuje wcześniejsze oczekiwania przejściowych zapisów z zakresu odroczonych ryzyk, w tym zmiany nazwy kategorii VEL-DEF-001. Nie przedstawia się ich jako uniwersalnej odmowy wszystkich niepodpisanych mutacji ani jako automatycznego ponownego otwarcia odroczenia.
- Nie potwierdzono nieoczekiwanej skutecznej niedozwolonej mutacji wymagającej zatrzymania wszystkich dalszych testów. Dodatkowy FAIL metody 17 wynikał z niewysłania UPDATE, a produkt w metodzie 16 został odrzucony przez odroczony constraint.
- Na końcu baza miała zero organizacji, użytkowników, stanów modułów, grantów, ledgeru, liczników i przypisań SKU; triggery były aktywne.

Usunięto **dokładnie cztery własne kontenery i własną sieć**, weryfikując ID, etykiety, nazwy i przynależność do sieci przed usunięciem. Skasowano dwa pliki syntetycznych poświadczeń. Nie używano `prune`, nie usuwano żadnego istniejącego wolumenu i nie uruchamiano poleceń na bazach aplikacji.

`cleanup.json` potwierdza brak własnych zasobów po zakończeniu oraz te same ID i sieci sześciu istniejących kontenerów aplikacji. Źródła po testach są zgodne z końcowym manifestem.

## 9. VEL-DEF-007 i odroczenia

**Oryginalny wynik 13/13 PASS zachowano bez zmian.** Nie wykonano dodatkowych odrębnych prób VEL-DEF-007. Istniejąca próba licznika w metodzie 16 pozostała częścią jawnie autoryzowanego zakresu tej metody, nie nowym testem zastępującym VEL-DEF-007.

Pozostają ograniczenia z [triage](./VELTRA_STAGE_2C_SECURITY_TEST_FAILURE_TRIAGE.md): jedna organizacja/prefiks RAW, runtime, asercja zbioru `{42501, 23514}` zamiast kodu per przypadek, brak dowodu przeciw migratorowi/administratorowi/RCE i brak automatycznego niezależnego zamknięcia.

**Nie nadano CLOSED.** VEL-DEF-001–006 pozostają odroczone na dotychczasowych zasadach; nie zmieniano ich rejestru ani statusów.

## 10. Dostępne nowe dowody

Katalog:

```text
/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/stage2c-rerun/
```

Najważniejsze pliki w podkatalogu `stage2c-authorized-evidence/`:

- `baseline.txt`, `existing-containers.json`, `isolation-before-migrations.json`;
- `scanner-isolation.json`, `scanner-ready.txt`;
- `role-setup.txt`, `migrations.txt`, `roles-and-triggers-before-tests.txt`, `connection-identities.json`;
- `reviewed-diff.patch`, `reviewed-diff-final.patch`, `pre-execution-review.json`;
- `source-hashes.json`, `final-source-hashes.json`, `tracked-hashes.json`, `tracked-hashes-final.json`;
- `original-preserved.json`, `integrity-final.json`;
- `authorized-methods.json`, `method01`–`method17`: log, JUnit, polecenie i snapshoty;
- `failure-review-07/11/12/13/16/17.json`;
- `attempt02/method17`: odrębny log, JUnit, polecenie i snapshoty ponowionej metody;
- `regression-plan.json`, sześć kompletów `regressionNN.txt/xml`, zapisanych poleceń i ocen;
- `php-lint.txt`, `pint.txt`, `diff-check.txt`, `summary.json`;
- `final-database-state.txt`, `cleanup.json`.

Przenośne [archiwum nowych dowodów](/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/stage2c-rerun-evidence.tar.gz) zawiera logi, XML, snapshoty, diffy, manifest SHA-256 i skrypty. Nie zawiera bazy, vendor, archiwum kodu aplikacji ani wygenerowanych plików env z sekretami. Pełne źródła należy zestawić ze snapshotem repozytorium i zachowanym diffem.

## 11. Przydatność do niezależnego przeglądu i następny krok

**Tak — materiał nadaje się do niezależnego przeglądu wykonania, granic i pozostałych braków. Nie — nie daje obecnie podstaw do pozytywnego Stage 2C ACCEPT.**

Korekty pozwoliły osiągnąć dalsze gwarancje, w tym rzeczywiste odmowy ledgeru, historyczny guard duplikatów, trwałą revokację i expiry oraz ostatniego administratora. Pięć niezaliczonych metod nadal wymaga rozstrzygnięcia zgodności kontraktu testowego.

Przed kolejnymi zmianami należy jawnie uzgodnić:

1. Dla metod 7, 11, 12 i 13: czy kontraktem jest ścisła odmowa niepodpisanej operacji `42501` i wszystkie końcowe kontrole stanu, czy osobna weryfikacja dalszego guarda biznesowego. Nie należy po prostu akceptować dowolnego błędu ani dopisywać signera bez zgody na taki zakres.
2. Dla metody 16: jak poprawnie oczekiwać istniejącej odroczonej odmowy nieprawidłowego produktu, zachowując każdą dotychczasową operację i pozytywne podpisane ścieżki. Nie wyłączać constraintu i nie usuwać `SET CONSTRAINTS ALL IMMEDIATE`.
3. Po zatwierdzeniu dokładnego zakresu: nowy przegląd diff i kontrolowane ponowienie z nowymi dowodami. Niniejsze FAIL/ERROR pozostają w historii.

Nie wdrażano tych dodatkowych zmian w celu wymuszenia PASS. Nie potwierdzono nowego defektu aplikacyjnego wymagającego poprawki produkcyjnej.

**RAW_MATERIALS pozostaje NOT_READY; VSEF nieaktywny. Brak deploymentu, commitów i pushy. Brak automatycznej akceptacji Stage 2C lub zamknięcia ryzyk.** Wykonawca nie zastępuje niezależnego recenzenta ani odrębnej decyzji właściciela.
