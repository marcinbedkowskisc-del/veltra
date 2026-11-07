# VELTRA 2.0 — Diagnoza niezaliczonych testów bezpieczeństwa Stage 2C

**Data:** 7 listopada 2026 r.

**Tryb:** wyłącznie analiza istniejących źródeł i dowodów oraz dokumentacja. W tej pracy nie uruchomiono testów, usług, migracji, zapytań bazodanowych ani symulacji ataków. Nie zmieniono kodu, fixture'ów, konfiguracji ani poprzednich dowodów.

**Snapshot źródeł:** `c68db7201e26112b5067f8f6a430c2dd27df6487`, migracje 001–036. Przed triage jedynym nieśledzonym plikiem był poprzedni raport wykonawczy; zachowano go bez zmian.

**Werdykt:** cztery wykazane grupy niezgodności testów wyjaśniają **2 FAIL i 12 ERROR**. Nie daje to podstaw do przemianowania tych wyników na PASS. Nieosiągnięte gwarancje pozostają **NOT VERIFIED**. Nie nadano Stage 2C ACCEPT, nie zamknięto VEL-DEF-007, nie zmieniono odroczenia VEL-DEF-001–006 ani gotowości RAW_MATERIALS/VSEF.

## 1. Materiał dowodowy i jego integralność

Przeanalizowano:

- [Raport autoryzowanego wykonania](./VELTRA_STAGE_2C_AUTHORIZED_SECURITY_VERIFICATION_REPORT.md) oraz jego [zweryfikowaną kopię Google Drive](https://drive.google.com/file/d/1F4yhFgHIBgJ3Wy7qMEpkrnUxVxdGBeGX/view).
- [Plan ponownego otwarcia Stage 2C](./VELTRA_STAGE_2C_SECURITY_ACCEPTANCE_REOPENING_PLAN.md), w tym listę 17 metod i obowiązujące ograniczenia.
- [RawMaterialPermissionBootstrapTest.php](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php), [AuthorizationMutationBoundaryTest.php](./backend/tests/Feature/AuthorizationMutationBoundaryTest.php), [RoleAdministrationTest.php](./backend/tests/Feature/RoleAdministrationTest.php), [TestCase.php](./backend/tests/TestCase.php), bazowe klasy fixture'ów oraz odpowiednie usługi autoryzacji, członkostw, konfiguracji modułów, grantów i SKU.
- Bieżące 36 [migracji](./backend/database/migrations/), a nie wyłącznie historyczny schemat Stage 2C.
- Oryginalne `method01.txt`–`method17.txt`, odpowiadające JUnit XML, konfigurację wykonania, role, dowody izolacji, snapshoty i sprzątnięcia, `vel-def-007.jsonl` oraz skrypt odrębnej weryfikacji SKU.

Katalog oryginalnych dowodów:

```text
/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/stage2c-authorized-evidence/
```

[Pakiet surowych dowodów i skryptów](/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/stage2c-authorized-evidence.tar.gz) pozostaje niezmieniony. Zawiera 101 pozycji archiwum, w tym katalog, manifest i skrypty; **nie zawiera kopii źródeł migracji**, tylko ich hashe w manifeście źródeł. Źródła należy zestawić z wskazanym snapshotem repozytorium.

W triage wykonano wyłącznie odczytowe parsowanie plików i porównania SHA-256:

| Kontrola istniejących plików | Wynik |
|---|---|
| `evidence-sha256.json` | 99 wpisów, 0 niezgodności |
| `source-hashes.json` wobec bieżącego repozytorium | 66 wpisów, 0 niezgodności; obejmuje 36 migracji i pliki testów Feature |
| Ponowne zsumowanie historycznych JUnit XML | 17 metod, 121 asercji, 2 failures, 12 errors, 0 skipped |

To kontrola zachowanych dowodów, **nie nowy przebieg testowy**. Manifest nie jest niezależnym podpisem ani dowodem pełnej poprawności każdej gwarancji.

## 2. Wynik zbiorczy i klasyfikacja

| Grupa | Metody | Poprzedni wynik | Najwcześniejsza wykazana przyczyna |
|---|---|---|---|
| T-01 — role/połączenia | 1–2 | 2 FAIL | Założenie testów, że domyślne połączenie aplikacji jest migratorem; obecny harness wymaga runtime |
| Bez stwierdzonej niezgodności | 3–5 | 3 PASS | Brak awarii w poprzednim przebiegu |
| T-02 — podpisane fixture'y | 6–15 | 10 ERROR | Niepodpisany UPDATE `organizations.authorization_initialized` na runtime, odrzucony z `42501` |
| T-03 — niezmienność dostawcy | 16 | 1 ERROR | Test oczekuje sukcesu zmiany `display_name`, migracja 026 wymaga odmowy `23514` |
| T-04 — ostatni administrator | 17 | 1 ERROR | Fixture ustawia latch autoryzacji przed utworzeniem wymaganego członkostwa, odmowa `23514` |

Nie wykazano, że błędne hasła, niedostępność PostgreSQL/Redis, niewykonane migracje albo dodatkowe uprawnienia runtime były przyczyną tych 14 wyników. Oryginalne wykonanie zakończyło wszystkie 36 migracji. Początkowa nieudana próba migracji z niepełnym nadpisaniem zmiennych CLI była osobnym, udokumentowanym problemem przygotowania środowiska; nie jest przyczyną wskazanych awarii metod.

### Wpływ migracji 001–036

| Migracje | Znaczenie dla diagnozy |
|---|---|
| 001–003 | Instalacja, tożsamość, członkostwa i role stanowią podstawę poprawnego fixture'u administratora |
| 004–009 | Ochrona administratora, audytu, grantów i kont; 009 wymaga działającego członkostwa zweryfikowanego administratora — bezpośrednia przyczyna T-04 |
| 010–014 | Moduły, RAW i dane handlowe; fixture modułu musi uwzględniać obecną podpisaną granicę |
| 015–016 | Precyzja i upgrade uprawnień, rekursywne źródła delegacji; wymagania metod 10–15 pozostają wiążące |
| 017–021 | Replay, ledger, ograniczenie wywołań triggerów i `search_path`; istotne dla metod 2–8, nie wolno ich usuwać dla PASS |
| 022–024 | Podpisane mutacje, zawężenie granicy i bezpieczny `search_path`; 022 powoduje odmowę niepodpisanego setupu T-02, dopuszczając oddzielny zaufany setup migratora |
| 025–027 | Integralność RAW, niezmienność dostawcy i licznika, podpisane usuwanie ról przy deaktywacji członkostwa; 026 wyjaśnia T-03 |
| 028–033 | Alergeny, dokumenty prywatne, nowe uprawnienia, skany, oceny zgodności i podpisane dane rewizji; zwiększają aktualną powierzchnię, nie cofają powyższych wymagań |
| 034 | Historia importu cen i metadanych; nie uzasadnia rozszerzenia praw runtime ani wyłączenia ochrony administratora |
| 035 | Nowy licznik SKU i append-only przypisania, scope `sku.allocate`, monotoniczność i spójność odroczona; właściwa podstawa oceny VEL-DEF-007 |
| 036 | Historia importu katalogu i jej podpisana ochrona; nie zmienia przyczyn T-01–T-04 |

Przegląd źródeł nie zastępuje testu wykonawczego. Nie twierdzi się, że 17 metod obejmuje wszystkie powierzchnie dodane przez Stage 2D/2E.

## 3. Pełna macierz diagnostyczna 17 metod

Wiersze 1–15 odnoszą się do klasy `RawMaterialPermissionBootstrapTest`; 16 do `AuthorizationMutationBoundaryTest`; 17 do `RoleAdministrationTest`. Podano pełne nazwy kwalifikowane. Numery linii odnoszą się do źródeł bieżącego snapshotu. Dowodem każdego poprzedniego wyniku jest odpowiednia para `methodNN.txt`/`methodNN.xml`.

**Wyjątek A:** `Illuminate\Database\QueryException`, przyczyna `PDOException`, `SQLSTATE[42501]`, komunikat PostgreSQL: `A signed authorization mutation ticket is required`. Jest to dokładny wspólny wyjątek dla metod 6–15.

| # | Dokładna metoda | Poprzedni wynik / asercje | Asercja lub wyjątek i linia; najwcześniejsza przyczyna | Klasyfikacja i rzeczywiste pokrycie | Najmniejsza uzasadniona korekta |
|---:|---|---|---|---|---|
| 1 | `RawMaterialPermissionBootstrapTest::test_migration_role_is_not_superuser_or_database_owner` | **FAIL / 6** | Linia 49: `assertSame(1, (int) $role->can_create_schema_objects)`, faktycznie `0`; `Failed asserting that 0 is identical to 1.` Zapytanie od linii 33 działa na domyślnym runtime | Przestarzałe założenie i pomylenie połączeń w teście, nie błędna rola aplikacji. Gwarancja dotycząca migratora nie została tą metodą zweryfikowana; część ograniczeń runtime osiągnięta | Zapytanie metadanych przez jawne `setupConnection()`/`pgsql_setup`; potwierdzić jego tożsamość. Nie zmieniać defaultu aplikacji |
| 2 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_write_to_permission_operation_ledger_directly` | **FAIL / 4** | Linia 58: `assertNotSame(config('database.connections.pgsql.username'), $runtimeUsername, ...)`; oba wskazują runtime. `Failed asserting that two strings are not identical.` Komunikat asercji: `The test connection must use a separate migration role.` | Przestarzałe założenie testu. Próby zapisu ledgeru **NOT VERIFIED**, ponieważ nie utworzono jeszcze testowego PDO | Sprawdzić zgodność `pgsql` z runtime i różnicę względem jawnego setupu. Zachować rzeczywiste PDO runtime oraz wszystkie próby ledgeru |
| 3 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table` | **PASS / 5** | Brak nieudanej asercji/wyjątku kończącego metodę | Gwarancja osiągnięta w zakresie prób podpięcia/wywołania funkcji i sprawdzeń stanu tej metody | Bez korekty; pozostawić w ponownym przebiegu |
| 4 | `RawMaterialPermissionBootstrapTest::test_permission_grant_validators_pin_public_before_temporary_schemas` | **PASS / 5** | Brak awarii | Gwarancja osiągnięta dla wskazanych w metodzie walidatorów; nie dla wszystkich funkcji bazy | Bez korekty |
| 5 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` | **PASS / 8** | Brak awarii | Gwarancja osiągnięta w testowanym zakresie shadowingu, grantu, ledgeru i audytu | Bez korekty |
| 6 | `RawMaterialPermissionBootstrapTest::test_initial_grant_is_explicit_audited_one_time_and_never_self_granted` | **ERROR / 1** | Linia 324: wyjątek A przy niepodpisanym ustawieniu latcha przed `grantInitial()` | Nieprawidłowy fixture T-02. Bootstrap, audyt, jednokrotność i zakaz self-grant **NOT VERIFIED** | Kontrolowany setup latcha po spełnieniu invariantów; właściwe wywołania bootstrapu i asercje bez zmian |
| 7 | `RawMaterialPermissionBootstrapTest::test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql` | **ERROR / 1** | Linia 379: wyjątek A; replay nieosiągnięty | Fixture T-02. Ochrona replay po revoke **NOT VERIFIED** | Poprawić wyłącznie setup; zachować grant, revoke, próbę replay i sprawdzenia stanu |
| 8 | `RawMaterialPermissionBootstrapTest::test_migration_guard_fails_closed_for_an_active_historical_duplicate` | **ERROR / 1** | Linia 435: wyjątek A, przed przygotowaniem historycznego duplikatu | Fixture T-02. Guard duplikatów **NOT VERIFIED**. Statycznie widoczny następny konflikt: DDL fixture'u od linii 451 na runtime | Poprawić latch; osobno rozstrzygnąć jawny setup migratora dla historycznego fixture'u i dokładną zgodę na istniejący zakres DDL — sekcja 4.2 |
| 9 | `RawMaterialPermissionBootstrapTest::test_bootstrap_rejects_unknown_permissions_and_invalid_passwords` | **ERROR / 1** | Linia 494: wyjątek A, przed właściwymi odmowami | Fixture T-02. Nieznane uprawnienie i błędne hasło **NOT VERIFIED** | Poprawić setup, nie usuwać żadnej negatywnej próby |
| 10 | `RawMaterialPermissionBootstrapTest::test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable` | **ERROR / 1** | Linia 533: wyjątek A | Fixture T-02. Jawność, idempotencja upgrade'u i trwałe revoke **NOT VERIFIED** | Poprawić setup; pozostawić domenowy upgrade i jego asercje |
| 11 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql` | **ERROR / 1** | Linia 664: wyjątek A; następnie kod zawiera niepodpisane tworzenie modułu przy linii 667 | Fixture T-02. Rekursywna propagacja revoke **NOT VERIFIED** | Poprawić latch oraz setup modułu przez istniejący podpisany serwis, pozostawiając próby SQL runtime |
| 12 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants` | **ERROR / 1** | Linia 825: wyjątek A | Fixture T-02. Odmowa administratorowi bez authority i forged-grant **NOT VERIFIED** | Poprawić setup; nie podpisywać ani nie przenosić forged INSERT na migratora |
| 13 | `RawMaterialPermissionBootstrapTest::test_delegation_is_limited_to_effective_authority_and_revocation_is_audited` | **ERROR / 1** | Linia 873: wyjątek A; dalej niepodpisany INSERT modułu przy linii 875 | Fixture T-02. Ograniczenia delegacji, audyt i cofnięcie **NOT VERIFIED** | Poprawić latch i moduł; właściwe delegacje nadal przez serwisy, odmowy na runtime |
| 14 | `RawMaterialPermissionBootstrapTest::test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants` | **ERROR / 1** | Linia 1007: wyjątek A; później helper `enableRawMaterials()` zawiera bezpośredni INSERT | Fixture T-02. Brak odtworzenia grantów po reaktywacji **NOT VERIFIED** | Poprawić latch i helper modułu; zachować wszystkie etapy deaktywacji, reaktywacji i sprawdzenia trwałości revoke |
| 15 | `RawMaterialPermissionBootstrapTest::test_delegated_grants_expire_with_source_permission_and_delegation_authority` | **ERROR / 1** | Linia 1112: wyjątek A; później helpery modułu i `insertBootstrapGrants()` wykonują niepodpisany setup | Fixture T-02. Wygaśnięcie źródła i prawa delegowania **NOT VERIFIED** | Poprawić latch, moduł i jawny setup czasowych grantów; zachować rzeczywisty upływ ważności, brak dodatkowych eventów i wszystkie sprawdzenia efektywnych grantów |
| 16 | `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` | **ERROR / 82** | Linia 393, wywołanie helpera w linii 251: `QueryException`/`PDOException`, `SQLSTATE[23514]`, `Supplier identity, business details or archive history are immutable`; test oczekuje `assertSame(1, UPDATE display_name)` | Przestarzałe oczekiwanie T-03. 82 asercje i wcześniejsze podpisane przepływy osiągnięte, ale nie cała gwarancja. Późniejsze operacje **NOT VERIFIED** | Oczekiwać odmowy zmiany dostawcy, dokładnego kodu i niezmienności danych/audytu; zachować pozytywne podpisane przepływy i resztę macierzy |
| 17 | `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql` | **ERROR / 1** | Linia 58: `updateFixture(...authorization_initialized => true)` przed kuchnią i członkostwem z linii 59–60. `QueryException`/`PDOException`, `SQLSTATE[23514]`, `An active verified administrator with an operating membership must remain` | Błędna kolejność fixture'u T-04, nie odrzucenie prawidłowej autoryzacji. Właściwe próby ochrony ostatniego administratora **NOT VERIFIED** | Utworzyć aktywny kontekst i członkostwo przed latch'em, zachować wszystkie późniejsze odmowy domenowe i bazodanowe |

Jedna asercja w metodach kończących się na fixture nie dowodzi osiągnięcia ich celu biznesowego. Liczba asercji jest liczbą z PHPUnit, nie liczbą niezależnie zweryfikowanych gwarancji.

## 4. Konkretne korekty T-01–T-04 — do późniejszej autoryzacji

### 4.1. T-01: jawna inspekcja migratora, aplikacja nadal runtime

[TestCase.php](./backend/tests/TestCase.php), linie 39–94, rozdziela:

- `pgsql`: `current_user=session_user=veltra_app`, baza `veltra_testing`, bez SUPERUSER, TRUNCATE i CREATE w `public`; nie jest właścicielem tabel.
- `pgsql_setup`: `current_user=session_user=veltra_migrator`, ta sama disposable baza, bez SUPERUSER, właściciel tabel schematu.
- `withSetupConnection()` od linii 97 nie przełącza globalnego defaultu; przekazuje odrębny obiekt i ponownie kontroluje runtime po operacji.

**Metoda 1:** zachować pięć dotychczasowych oczekiwań roli migratora: brak superuser/createdb/createrole/własności bazy oraz wymagane CREATE na bazie. Zmienić wyłącznie źródło zapytania na jawne połączenie setup, dodać tożsamość roli i tej samej bazy. Własność tabel przez migratora nie oznacza własności bazy — baza ma osobnego właściciela.

**Metoda 2:** zamiast różnicy względem domyślnego `pgsql` wymagać zgodności domyślnego połączenia z runtime i różnicy względem setupu. Na rzeczywistym PDO dodatkowo potwierdzić `current_user`, `session_user`, nazwę bazy i brak członkostw ról. Zachować SELECT ledgeru, zakazy INSERT/UPDATE/DELETE/TRUNCATE, kontrolę SECURITY DEFINER/`search_path`/EXECUTE i istniejące próby DML.

**Zakazane rozwiązania:** nadanie runtime CREATE, ustawienie migratora jako defaultu aplikacji, `SET ROLE` maskujące tożsamość runtime, wykonywanie negatywnych prób ledgeru na migratorze. W oryginalnym dowodzie ograniczenia runtime były prawidłowe; to testy pomyliły adresata asercji.

### 4.2. T-02: legalne przygotowanie latcha i pozostałych fixture'ów

**Przyczyna:** [migracja 022](./backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php), linie 295–311, rozróżnia zaufany setup migratora od runtime i odrzuca brak podpisanego ticketu. Ten mechanizm nie powinien być zmieniany.

**Najmniejsza korekta aktualnych fixture'ów:** użyć istniejącego `updateFixture()` z [TestCase.php](./backend/tests/TestCase.php), linie 135–145, do ustawienia latcha na **oddzielnym kontrolowanym setupie**, po sprawdzeniu aktywnej organizacji, aktywnego/zweryfikowanego administratora, roli i działającego członkostwa. Obecne fixture'y już tworzą użytkownika przez `user()` i rolę przez `role()`; [IdentityTestCase.php](./backend/tests/IdentityTestCase.php), linie 22–33, oraz [AuthorizationTestCase.php](./backend/tests/AuthorizationTestCase.php), linie 13–25, używają `pgsql_setup`. Zastąpienie dziesięciu niepodpisanych UPDATE tym istniejącym mechanizmem jest mniejsze i bardziej spójne niż przebudowa wszystkich aktorów.

To **nie jest test pozytywnego podpisanego bootstrapu administratora** i nie wolno go tak raportować. Jest to kontrolowany syntetyczny setup przewidziany przez obecną architekturę testów. Nie wymaga wyłączania triggerów, zmiany klucza ani grantów; wszystkie zwykłe constrainty integralności pozostają aktywne. Od tego punktu właściwe operacje i próby odmów muszą nadal używać runtime.

**Legalny wariant w pełni domenowy:** `IdentityService::provisionInitial()` → zgodna weryfikacja konta → aktywny kontekst i `MembershipService::assign()` → `RoleAdministration::bootstrapInitial()`. Ostatnia metoda, [linie 119–142](./backend/app/Domain/Authorization/RoleAdministration.php), sama podpisuje scope `administrator.bootstrap`, tworzy rolę, ustawia latch i zapisuje zdarzenie. Wymaga początkowej tożsamości i braku istniejącej roli administratora. **Nie można po prostu dopisać jej po obecnym `role()`**: istniejący administrator spowoduje odmowę. Ten wariant oznacza większą przebudowę fixture'ów i nie jest rekomendowany jako minimalny patch.

Nie zaleca się owijania dowolnego UPDATE w niskopoziomowy signer jako substytutu walidacji domenowej ani ręcznego konstruowania ticketu.

**Dodatkowe statycznie widoczne blokery — nie są nowymi wynikami ERROR:**

1. **Metoda 8, linie 449–480:** historyczny duplikat przygotowywany jest przez istniejące `ALTER TABLE ... DISABLE/ENABLE TRIGGER` i INSERT na domyślnym runtime. Samo naprawienie latcha nie uprawni runtime do DDL. Należy odseparować ten historyczny setup na `withSetupConnection()` i pozostawić właściwe wywołanie guarda oraz asercję `23514`/`manual review` na pierwotnej ścieżce testowanej. Jednak ta metoda z definicji przygotowuje historycznie niepoprawne dane, chwilowo wyłączając dwa nazwane triggery biznesowe. **Nie jest to ogólna zgoda na wyłączanie zabezpieczeń.** Przyszła autoryzacja musi wprost rozstrzygnąć ten istniejący zakres przygotowania danych. Nie wolno wyłączyć triggera podpisanych mutacji ani innych zabezpieczeń. Bez takiej zgody metoda pozostaje NOT VERIFIED — nie należy zastępować jej słabszym scenariuszem ani obiecywać 17 PASS.
2. **Metody 11 i 13 oraz `enableRawMaterials()` od linii 1164:** zastąpić bezpośredni INSERT `LocationModuleState` istniejącym `ModuleConfigurationService::configure()` z aktorem, kontekstem, hasłem, `module_ids` i `location_ids`. Przykład tego legalnego podpisanego przepływu już istnieje w [AuthorizationMutationBoundaryTest.php](./backend/tests/Feature/AuthorizationMutationBoundaryTest.php), linie 222–228. Zachować istniejący wyłącznie procesowy `ModuleCatalogFixtures::readyWithRawMaterials()` do autoryzowanych testów underlying workflows; bez niego serwis prawidłowo odmówi przy NOT_READY ([ModuleConfigurationService.php](./backend/app/Domain/Modules/ModuleConfigurationService.php), linie 199–201). Nie zmieniać katalogu produkcyjnego, globalnej gotowości ani lokalizacji aplikacji.
3. **Metoda 15, `insertBootstrapGrants()` od linii 1181:** przenieść wyłącznie syntetyczny setup audytu i czasowych grantów do istniejącego `withSetupTransaction()`, z użyciem przekazanego obiektu połączenia dla wszystkich INSERT-ów. Zachować te same daty, źródła, operation_id i relacje audytowe. Podobny kontrolowany fixture istnieje w [RawMaterialConcurrencyTest.php](./backend/tests/Feature/RawMaterialConcurrencyTest.php), od linii 382. Nie przenosić właściwych delegacji ani prób odmów na setup.

`RawMaterialPermissionBootstrap::grantInitial()` nie przyjmuje dat ważności, a `OrganizationRawMaterialPermissionAdministration::grant()` tworzy delegację zależną od authority, nie równoważny czasowy grant źródłowy bootstrapu. Dlatego nie należy pozornie „naprawiać” helpera 15 przez zamianę modelu uprawnień albo dodanie funkcjonalności aplikacyjnej. Kontrolowany fixture zachowuje cel tej metody; zwykłe testy bootstrapu nadal muszą przechodzić przez `grantInitial()`.

### 4.3. T-03: odmowa mutacji dostawcy, podpisane przepływy nadal dostępne

[Migracja 026](./backend/database/migrations/2026_11_06_000026_protect_supplier_identity_and_raw_material_code_counters.php), linie 14–37, zakazuje zmiany tożsamości/danych biznesowych i historii archiwizacji dostawcy. Obecna odmowa jest zgodna z kontraktem; nie ma podstaw do zmiany triggera lub serwisu.

Minimalny patch dotyczyłby wyłącznie `assertNineTableRuntimeDmlMatrix()` w [AuthorizationMutationBoundaryTest.php](./backend/tests/Feature/AuthorizationMutationBoundaryTest.php), przy linii 393:

- odczytać nazwę i pozostałe istotne dane dostawcy przed próbą;
- oczekiwać odmowy UPDATE na tym samym rzeczywistym połączeniu runtime;
- sprawdzić konkretnie `23514` oraz niezmienność rekordu i liczby zdarzeń audytu;
- zachować późniejsze próby na pozostałych tabelach, bez uznawania ich z góry za PASS.

Istniejący `assertRuntimeDenied()` od linii 463 wykonuje operację w transakcji połączenia runtime, więc obsługuje rollback/savepoint po błędzie zamiast zostawiać transakcję PostgreSQL w stanie aborted. Dopuszcza jednak trzy kody. Można go minimalnie rozszerzyć o oczekiwany SQLSTATE dla tego wywołania albo zastosować równoważny lokalny wzorzec z transakcją i ścisłą asercją. **Nie wystarcza sam catch ani brak wyjątku wychodzącego z helpera.**

Pozytywna część granicy podpisanej nie znika: wcześniej metoda wywołuje `ModuleConfigurationService::configure()`, `RawMaterialService::createCategory()/create()` oraz `RawMaterialCommercialService::createSupplier()` i sprawdza jego idempotency record (linie 222–250). Te operacje oraz dalsze pozytywne ścieżki muszą pozostać. Niezmienność dostawcy nie jest powodem do usunięcia testów prawidłowo autoryzowanych mutacji.

### 4.4. T-04: prawidłowy stan zanim zaczniemy usuwać ostatniego administratora

[Migracja 009](./backend/database/migrations/2026_11_02_000009_preserve_usable_administrator_context.php), linie 11–27, wymaga po inicjalizacji aktywnego, zweryfikowanego administratora z działającym członkostwem i aktywną lokalizacją. `updateFixture()` korzysta już z migratora, ale **nie wyłącza tego invariantu**. Dlatego to nie jest drugi błąd podpisu ani problem połączenia.

Minimalna kolejność w [RoleAdministrationTest.php](./backend/tests/Feature/RoleAdministrationTest.php), linie 54–61:

1. Aktywny syntetyczny użytkownik i weryfikacja email.
2. Przypisanie roli administratora kontrolowanym istniejącym helperem.
3. Utworzenie aktywnego kontekstu/kuchni.
4. `MembershipService::assign($actor, $context)`.
5. Dopiero teraz `updateFixture($actor->organization, ['authorization_initialized' => true])`.
6. Potwierdzenie prawidłowego stanu i dopiero potem oryginalne próby revoke, deaktywacji konta/członkostwa/lokalizacji i cofnięcia latcha.

Pierwsza metoda tego samego pliku, linie 19–35, pokazuje zgodny alternatywny przepływ `provisionInitial()`/`bootstrapInitial()` po zapewnieniu członkostwa. Wymiana całego fixture'u na ten wariant nie jest konieczna do minimalnej naprawy kolejności.

Zachować rozróżnienie prób: domenowe na runtime, świadome próby constraintów na istniejącym setupie oraz runtime-deaktywację członkostwa. Nie przedstawiać wszystkich sprawdzeń tej metody jako bezpośrednich prób SQL runtime — część celowo bada invariant również przy zaufanym setupie.

## 5. Czy potwierdzono defekt bezpieczeństwa aplikacji?

**Nie potwierdzono nowej podatności aplikacji jako przyczyny 2 FAIL i 12 ERROR.** Rozpoznane odmowy PostgreSQL odpowiadają wymaganym zabezpieczeniom. Nie jest to stwierdzenie, że aplikacja jest wolna od podatności albo że nieosiągnięte scenariusze są bezpieczne.

Jest istotny wyjątek wymagający jawnego odnotowania, bez zmiany jego wcześniejszej klasyfikacji: metoda 16 przed błędem dostawcy osiągnęła skuteczny bezpośredni UPDATE nazwy kategorii przy liniach 381–382. To znane, odroczone zachowanie **VEL-DEF-001**, a nie nowy błąd fixture'u ani nowo dowiedziona zdalna eskalacja HTTP. Późniejszy rollback i pusty snapshot po teardown nie unieważniają faktu, że zapis wewnątrz transakcji był skuteczny. Nie wolno tego opisywać jako uniwersalnej odmowy wszystkich niepodpisanych mutacji.

Zachowuje się obowiązujące odroczenie **VEL-DEF-001–006**. Nie otwiera się ich automatycznie i nie dodaje ich napraw do korekt testów. Rozbieżność opisów wcześniejszych decyzji, wskazana już w planie ponownego otwarcia, pozostaje sprawą uzgodnienia dokumentacji przez właściciela; triage nie cofa ani nie rozszerza decyzji.

## 6. VEL-DEF-007 — przegląd 13 odmów

Dowody: `vel-def-007.jsonl`, `source-hashes.json`, role i izolacja w oryginalnym katalogu oraz [skrypt wykonawczy](/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/stage2c-vel-def-007.php).

**Rzeczywista tożsamość:** `session_user=veltra_app`, `current_user=veltra_app`, `current_database=veltra_testing`, potwierdzona na początku JSONL i wymagana przez skrypt przed fixture'em. Nie używano `SET ROLE` migratora do prób.

**Ważna precyzja oczekiwań:** oryginalny skrypt akceptował dla każdej odmowy zbiór **`{42501, 23514}`**, nie konkretny kod przypisany do przypadku. Tabela rozróżnia tę rzeczywistą asercję od interpretacji obserwowanego kodu. Nie wolno retrospektywnie twierdzić, że wykonał ścisłą asercję konkretnego SQLSTATE dla każdej operacji.

| # | Nazwa przypadku / operacja | Oczekiwane w oryginalnym skrypcie | Faktyczny SQLSTATE | Ocena i kontrola stanu |
|---:|---|---|---|---|
| 1 | `unsigned_counter_decrease`: 51 → 1 | Odmowa, `42501` lub `23514` | `23514` | PASS; zgodne z monotonicznością; bez zmian |
| 2 | `unsigned_counter_increase`: 51 → 52 | Jak wyżej | `42501` | PASS; zgodne z brakiem autoryzacji; bez zmian |
| 3 | `unsigned_counter_prefix_change`: RAW → INT | Jak wyżej | `23514` | PASS; naruszenie invariantu licznika; bez zmian |
| 4 | `unsigned_counter_delete` | Jak wyżej | `42501` | PASS; bez zmian |
| 5 | `unsigned_counter_truncate` | Jak wyżej | `42501` | PASS; bez zmian |
| 6 | `unsigned_assignment_update`: zmiana podmiotu | Jak wyżej | `42501` | PASS; bez zmian |
| 7 | `unsigned_assignment_delete` | Jak wyżej | `42501` | PASS; bez zmian |
| 8 | `unsigned_assignment_truncate` | Jak wyżej | `42501` | PASS; bez zmian |
| 9 | `unsigned_assignment_insert`: RAW-000052 | Jak wyżej | `42501` | PASS; bez zmian |
| 10 | `signed_counter_decrease`: 51 → 1 | Odmowa również przy `sku.allocate`, `42501` lub `23514` | `23514` | PASS; podpis nie pozwala obniżyć licznika; bez zmian |
| 11 | `signed_counter_without_assignment`: 51 → 52 | Jak wyżej, także po wymuszeniu constraintów odroczonych | `23514` | PASS; licznik bez przypisania nie przechodzi; bez zmian |
| 12 | `signed_assignment_update`: numer 50 → 49 | Odmowa przy `sku.allocate`, `42501` lub `23514` | `42501` | PASS; bez zmian |
| 13 | `signed_assignment_delete` | Jak wyżej | `42501` | PASS; bez zmian |

Przypadki podpisane mają ważny kontekst wygenerowany w zaufanym procesie aplikacji. Nie są dowodem sfałszowania podpisu przez SQL ani przyznania runtime dostępu do klucza.

### Stan przed i po

Pełne snapshoty w JSONL są identyczne, włącznie z identyfikatorami, aktorami i datami:

| Element | Stan przed | Stan po |
|---|---|---|
| Organizacja | `01a1b30a-afd5-7391-9da0-4b73964ae479` | Identyczna |
| Licznik | Jeden rekord, prefiks RAW, `last_value=51` | Identyczny |
| Rezerwacja importowana | `RAW-000050`, numer 50, RAW, `IMPORTED`, podmiot `32ea71a6-34b0-4c69-87c0-8ea8fc8598e0` | Identyczna |
| Alokacja generowana | `RAW-000051`, numer 51, RAW, `GENERATED`, podmiot `1fdee9e6-5a7f-459e-bcc7-506744c9976b` | Identyczna |
| Aktor przypisań | `01a1b30a-b0c2-72a8-9646-ad1d5d0334a9` | Identyczny |
| Daty licznika i przypisań | `2026-11-06 22:46:24+00` | Identyczne |

Są to wyłącznie syntetyczne identyfikatory testowe. Baseline powstał przez legalne `reserveImported()` i `allocateNext()`. Każda próba miała własną transakcję, `SET CONSTRAINTS ALL IMMEDIATE`, rollback po odmowie i porównanie pełnego snapshotu. Nie jest to jedynie porównanie pustej bazy po teardown.

### Wystarczalność i ograniczenia

**Rekomendacja:** materiał jest wystarczający do przekazania do **niezależnego przeglądu zamknięcia wąsko zdefiniowanego VEL-DEF-007**: w 13 wskazanych próbach rzeczywisty runtime nie utrwalił niedozwolonej zmiany licznika/przypisań po migracji 035. Nie wystarcza do automatycznego CLOSED ani ACCEPT Stage 2C.

Ograniczenia:

- Jedna syntetyczna organizacja i prefiks RAW; brak wyczerpującego pokrycia tenantów, pozostałych prefiksów i kombinacji importów.
- Brak dowodu odporności na administratora bazy, migratora, kradzież klucza lub dowolne RCE w zaufanym procesie Laravel.
- Asercja zbioru kodów zamiast dokładnego kodu per przypadek; JSONL nie zapisuje treści/constraintu każdej spodziewanej odmowy.
- Rollback i niezmienność snapshotu dowodzą braku utrwalonej zmiany w tych próbach, a nie ogólnej poprawności wszystkich ścieżek aplikacji.
- Surowe dowody i manifest są odtwarzalne, ale nie zastępują niezależnej oceny ani decyzji właściciela.

Jeśli recenzent wymaga uzupełnienia, osobno autoryzować kolejny przebieg z kodem oczekiwanym per przypadek i bezpiecznym zapisem nazwy constraintu/komunikatu. Nie poprawiać starych logów ani nie zmieniać historycznych 13 PASS. Tego uzupełnienia nie wykonano.

## 7. Minimalny plan remediacji — rozdzielenie A/B/C

### A. Wyłącznie kod testów i fixture'ów

| Plik / metody | Proponowany zakres | Asercje i granice do zachowania |
|---|---|---|
| [RawMaterialPermissionBootstrapTest.php](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php), metody 1–2 | Jawny wybór ról zgodnie z sekcją 4.1 | Least privilege migratora i runtime, ledger DML, `search_path`, SECURITY DEFINER, brak EXECUTE/członkostw |
| Ten sam plik, metody 6–15 | Latch przez istniejący kontrolowany setup po prawidłowych preconditions | Runtime bez ticketu nadal otrzymuje odmowę; nie zmieniać testowanych grantów/revoke/replay |
| Ten sam plik, metoda 8 | Oddzielić historyczny setup DDL od właściwej próby; wymaga szczególnej zgody opisanej w 4.2 | Guard historycznego duplikatu nadal musi odmówić; brak wyłączania podpisanej ochrony |
| Ten sam plik, metody 11, 13–15 i `enableRawMaterials()` | Podpisany `ModuleConfigurationService::configure()` zamiast bezpośredniego INSERT modułu | Tylko istniejący testowy katalog w procesie disposable; globalne NOT_READY bez zmian |
| Ten sam plik, `insertBootstrapGrants()` dla 15 | `withSetupTransaction()` i jawne połączenie dla syntetycznych czasowych grantów/audytu | Te same expiry, zależności authority i brak fabrykowanych eventów wygaśnięcia |
| [AuthorizationMutationBoundaryTest.php](./backend/tests/Feature/AuthorizationMutationBoundaryTest.php), metoda 16 / `assertNineTableRuntimeDmlMatrix()` | Zastąpić oczekiwany sukces dostawcy ścisłą odmową i kontrolą stanu, z transakcyjną obsługą błędu | Zachować pozytywne signed flows, realny runtime, wszystkie inne próby i jawne odroczenia |
| [RoleAdministrationTest.php](./backend/tests/Feature/RoleAdministrationTest.php), metoda 17 | Przenieść kontekst i membership przed latch | Zachować constraint ostatniego administratora i wszystkie istniejące negatywne sprawdzenia |

**Nie ma potrzeby zmieniać bazowego TestCase.php**, migracji ani produkcyjnych helperów do podpisywania. Należy wykorzystać już istniejące mechanizmy setupu, bez tworzenia ogólnego obejścia zabezpieczeń.

### B. Środowisko i połączenia

- Nowy, dedykowany PostgreSQL 17/Redis, osobna sieć internal, wyłącznie syntetyczne dane i sekrety, brak sieci/portów/mountów aplikacji.
- Rozdzielone poświadczenia runtime i migratora; osobny właściciel bazy. Migracje przez migratora, zwykła aplikacja i negatywne próby przez runtime.
- Konfigurację migratora przekazać jawnie do procesu CLI/Laravel; nie polegać na samym `putenv()`, gdy `$_ENV`/`$_SERVER` nadal zawierają runtime. Nie zapisywać tej korekty w konfiguracji aplikacji.
- Kontrole `current_database`, `current_user`, `session_user`, owner, praw DDL/DML i członkostw przed testami; przerwać przy rozbieżności.
- Żadne poszerzenie grantów runtime nie jest uzasadnione. Potwierdzić, że setup-only operacje nie zmieniają defaultu ani testowego PDO runtime.

### C. Aplikacja

**Brak uzasadnionych zmian aplikacyjnych do naprawy T-01–T-04.** Nie zmieniać podpisanych mutacji, ochrony dostawcy, licznika SKU, ostatniego administratora ani readiness. Odroczonych VEL-DEF-001–006 nie włączać do tego patcha.

Jeśli po poprawieniu testów ujawni się rzeczywista skuteczna niedozwolona mutacja, zatrzymać przebieg, zachować dowody i zgłosić ją osobno. Nie zakładać z góry, że każdy następny błąd będzie problemem fixture'u.

## 8. Proponowany kontrolowany ponowny przebieg

**Wymagana nowa jawna zgoda właściciela** na zmiany wskazanych testów/fixture'ów oraz wykonanie wszystkich 17 metod po zmianach. Poprzednia zgoda dotyczyła metod bez zmian i nie pokrywa tego automatycznie. Osobno rozstrzygnąć historyczny setup metody 8 oraz ewentualne uzupełnienie VEL-DEF-007.

Proponowana kolejność:

1. Zachować poprzedni raport, komplet logów/XML, manifesty i archiwum; nowy katalog dowodów, nowy identyfikator przebiegu. Nie nadpisywać FAIL/ERROR.
2. Po zgodzie wykonać wyłącznie zatwierdzone zmiany A; przejrzeć diff pod kątem usuniętych/poszerzonych asercji i przypadkowego użycia setupu w próbach runtime. Zachować nazwy i pełny zakres 17 metod.
3. Utworzyć nowe disposable usługi zgodnie z B; przed próbami udokumentować izolację, role, listę 36 migracji i hash zmienionych testów/źródeł.
4. Potwierdzić niezmienione produkcyjne NOT_READY oraz brak aktywacji VSEF. Testowy katalog underlying workflows ograniczyć do procesu, bez zmiany konfiguracji aplikacji.
5. Uruchomić wszystkie 17 metod w kontrolowanym trybie z indywidualnym stdout/stderr i JUnit, tożsamością połączeń oraz dowodami stanu. Po każdym niezerowym wyniku ocenić przyczynę przed dalszym wykonaniem.
6. W szczególności sprawdzić realne odmowy ledgeru i replay, rekursywne revoke i expiry, signed/unsigned granice, niezmienność dostawcy oraz ostatniego administratora. Przy nieoczekiwanej skutecznej zmianie chronionej natychmiast zatrzymać testowanie.
7. Nowe FAIL/ERROR/NOT VERIFIED raportować bez zmiany klasyfikacji. Nie uznawać samego „bezpiecznego błędu fixture'u” za zaliczenie docelowej gwarancji. Nie podawać oczekiwanej liczby asercji 121 jako nowego progu — zmienione, silniejsze kontrole mogą zmienić licznik.
8. Po autoryzowanych zmianach uruchomić odpowiednią dozwoloną regresję, PHP lint, Pint i kontrolę diff; zakres rozszerzonego wykonania uzgodnić w tej samej nowej zgodzie. W niniejszym triage nie uruchamiano tych narzędzi wykonawczych.
9. Zabezpieczyć dowody i usunąć tylko nowe zasoby disposable. Wykonać niezależny przegląd wyników, potem uzyskać osobną decyzję właściciela. Nawet 17 PASS nie jest automatycznym Stage 2C ACCEPT ani gotowością do wydania.

## 9. Konkluzja i granica zakończenia

Diagnoza wskazuje minimalny zakres zmian w **trzech plikach testów**, bez potrzeby zmian aplikacji, schematu czy grantów runtime. Wykryte dalsze blokery fixture'ów są ustaleniami statycznymi, nie zastępczym wykonaniem nieosiągniętych testów.

**Stan historycznego przebiegu pozostaje:** 3 PASS, 2 FAIL, 12 ERROR, 121 asercji; VEL-DEF-007 osobno 13/13 odmów z opisanymi ograniczeniami. Nie przeprowadzono nowych testów. Nie potwierdzono nowej podatności jako przyczyny awarii; znane zachowanie VEL-DEF-001 jawnie zachowano w ocenie.

Nie dokonano napraw, migracji, zmian danych ani konfiguracji, deploymentu, commitów lub pushy. RAW_MATERIALS pozostaje NOT_READY, VSEF nieaktywny, Stage 2C bez ACCEPT, VEL-DEF-007 bez automatycznego CLOSED. Następny krok wymaga nowej, wyraźnej autoryzacji.
