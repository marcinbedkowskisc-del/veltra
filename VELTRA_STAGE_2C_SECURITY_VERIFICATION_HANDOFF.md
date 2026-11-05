# VELTRA 2.0 — przekazanie weryfikacji bezpieczeństwa Stage 2C

**Zakres:** 17 nierozstrzygniętych testów oraz VEL-DEF-007
**Stan środowiska:** disposable PostgreSQL 17 / Redis gotowe; migracje 001–027
PASS; dozwolona regresja 170/170 PASS, 1394 asercje.
**Stan dowodów:** wszystkie 17 testów pozostaje NOT VERIFIED. Nie uruchomiono
ich w tej pracy.

## Granice wykonania

Wiążące ograniczenia z poprzednich decyzji zabraniają uruchamiania wyłączonych
scenariuszy, prób DML/DDL omijających autoryzację, fałszowania biletów,
odtwarzania cofniętych operacji, zmiany ról ani obchodzenia zabezpieczeń.
Uruchomienie nowej bazy disposable **nie znosi tych ograniczeń**. Niniejsze
przekazanie nie stanowi autoryzacji do testów.

Niezależny recenzent może przedstawić wymagane dowody bez naruszania ograniczeń,
jeśli oprze się na dozwolonych przeglądach odczytowych i istniejących wynikach
funkcjonalnych. Gwarancji wymagających prób odmowy nie da się potwierdzić
wyłącznie takim materiałem. Ich weryfikacja wymaga formalnej decyzji właściwego
właściciela/urzędującego security authority, która jawnie rozstrzyga wcześniejszy
zakaz i zatwierdza zakres, procedurę, wykonawcę oraz ograniczenie do disposable
środowiska. Bez takiej decyzji recenzent nie może ich uruchomić ani odtwarzać
inną metodą.

## Dozwolony przegląd odczytowy wykonany teraz

Wyłącznie zapytania `SELECT` do katalogów w świeżej bazie disposable, bez
zmiany danych:

- `veltra_app` i `veltra_migrator`: brak `SUPERUSER`, `CREATEDB`,
  `CREATEROLE`, `BYPASSRLS`; właścicielem bazy jest osobny
  `veltra_test_admin`; `veltra_migrator` ma `CREATE` na bazie, runtime — nie.
- Runtime nie ma `CREATE` w schemacie `public` ani `USAGE` w
  `veltra_private`; nie ma `TRUNCATE` na `role_assignments`.
- Ledger operacji grantów: runtime ma `SELECT`, lecz nie ma `INSERT`, `UPDATE`,
  `DELETE` ani `TRUNCATE`.
- Cztery funkcje walidacyjne sprawdzone w teście #4 mają
  `search_path=pg_catalog, public, pg_temp`.
- W migracji 026 odczytano aktywne triggery licznika. Runtime ma uprawnienia
  `UPDATE`/`DELETE` do tabeli licznika, lecz nie `TRUNCATE`; sam katalog nie
  potwierdza, że każdy niedozwolony stan zostanie odrzucony.

To potwierdza wyłącznie powyższe właściwości w tej efemerycznej instancji.
Nie uruchamia testów #1, #2, #4 ani #16, nie potwierdza odmowy rzeczywistej
operacji, działania triggera dla przypadków brzegowych ani konfiguracji
wdrożonej instalacji. Statusy testów pozostają **NOT VERIFIED**.

## Lista wykonawcza i kryteria dowodu

Dowód PASS oznacza spełnienie warunków poniżej w aktualnym snapshotcie 027
i rzeczywistymi rolami testowymi. Dowód FAIL oznacza, że asercja testu nie
zachodzi albo niedozwolona zmiana jest zaakceptowana. Niewykonanie, brak logu
lub dowód historyczny spoza snapshotu 027 oznacza NOT VERIFIED. Nie należy
dzielić ani rekonstruować metod testowych.

| # | Istniejący test | Klasa | Minimalne kryterium PASS / wymagany dowód |
|---:|---|---|---|
| 1 | `RawMaterialPermissionBootstrapTest::test_migration_role_is_not_superuser_or_database_owner` | Odczyt katalogów | Metadane potwierdzają oczekiwane atrybuty migratora, brak własności bazy oraz wymagane uprawnienie setup. Wymaga odczytu przez właściwą rolę; testu nie uruchomiono. |
| 2 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_write_to_permission_operation_ledger_directly` | Odczyt uprawnień + zakazane próby DML | Metadane uprawnień są zgodne; przy autoryzowanej weryfikacji runtime nie może zmienić ledgeru, operacje są odrzucone oczekiwanym SQLSTATE, dane ledgeru pozostają niezmienione. |
| 3 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table` | Ograniczony DDL / wywołanie funkcji | Rzeczywista rola runtime nie może użyć chronionej funkcji poza jej zatwierdzonym kontekstem; brak skutku ubocznego w ledgerze. |
| 4 | `RawMaterialPermissionBootstrapTest::test_permission_grant_validators_pin_public_before_temporary_schemas` | Odczyt katalogów | `proconfig` każdej z czterech wskazanych funkcji ma dokładnie `search_path=pg_catalog, public, pg_temp`. Tę właściwość odczytano; pełny test pozostaje NOT VERIFIED. |
| 5 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` | Ograniczony scenariusz shadowingu/DML | Próba nie tworzy trwałego grantu; operacja jest odrzucona, a liczniki grantów, ledgeru i audytu pozostają bez zmian. |
| 6 | `RawMaterialPermissionBootstrapTest::test_initial_grant_is_explicit_audited_one_time_and_never_self_granted` | Workflow aplikacji | Prawidłowy grant jest pojedynczy i audytowany; powtórzenie bootstrapu oraz samonadanie są odrzucone bez dodatkowego grantu. |
| 7 | `RawMaterialPermissionBootstrapTest::test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql` | Ograniczony replay SQL | Cofnięty grant nie wraca po ponownym użyciu operation ID; odmowa i niezmienność grantu/audytu są udokumentowane. |
| 8 | `RawMaterialPermissionBootstrapTest::test_migration_guard_fails_closed_for_an_active_historical_duplicate` | Ograniczona manipulacja DDL/danymi | Strażnik fail-closed odrzuca zduplikowany historyczny stan; nie dochodzi do cichej akceptacji ani częściowego pozostawienia zmian. |
| 9 | `RawMaterialPermissionBootstrapTest::test_bootstrap_rejects_unknown_permissions_and_invalid_passwords` | Workflow aplikacji | Nieznane uprawnienie i błędne świeże potwierdzenie są odrzucone, bez utworzenia grantów. |
| 10 | `RawMaterialPermissionBootstrapTest::test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable` | Workflow mieszany; zawiera bezpośredni DML | Upgrade jawny i idempotentny, audyt pojedynczy, cofnięcie trwałe; chronione wpisy append-only. Pełny test wymaga odrębnej autoryzacji ze względu na próby bezpośredniej modyfikacji. |
| 11 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql` | Workflow mieszany; obejmuje bezpośrednie próby SQL | Cofnięcie nadrzędnego authority propaguje się do potomnych uprawnień w Laravel i PostgreSQL; kolejny upgrade i sfałszowane wpisy nie są akceptowane. |
| 12 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants` | Workflow + bezpośredni SQL | Administrator bez skutecznego prawa delegacji jest odrzucony; nie powstaje grant bez ważnej operacji i audytu. |
| 13 | `RawMaterialPermissionBootstrapTest::test_delegation_is_limited_to_effective_authority_and_revocation_is_audited` | Workflow mieszany; zawiera bezpośrednią próbę SQL | Delegacja nie przekracza authority; cofnięcie i zawieszenie są audytowane, nieskuteczne granty nie wracają; niedozwolony bezpośredni zapis jest odrzucony. |
| 14 | `RawMaterialPermissionBootstrapTest::test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants` | Workflow aplikacji / cykl życia | Rollback nie cofa błędnie historii; realna dezaktywacja cofa delegację; reaktywacja nie przywraca grantów bez nowego jawnego nadania. |
| 15 | `RawMaterialPermissionBootstrapTest::test_delegated_grants_expire_with_source_permission_and_delegation_authority` | Workflow aplikacji / czas | Delegowane granty tracą skuteczność po wygaśnięciu źródła lub authority, bez przedwczesnego cofnięcia niezależnych grantów. |
| 16 | `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` | Odczyt uprawnień + zakazane DML/DDL/role/ticket | Metadane są zgodne; runtime nie może wykonywać nieautoryzowanych zmian, a dozwolony podpisany przepływ aplikacji nadal działa. Weryfikacja odmów wymaga osobnego zatwierdzenia. |
| 17 | `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql` | Workflow aplikacji + zakazane SQL | Próby domenowe nie usuwają ostatniego administratora; constraint DB odrzuca niedozwolony stan, a konto, rola, członkostwo i flaga inicjalizacji pozostają spójne. Nie dzielić metody na osobne wykonania. |

### Klasy wykonania

- **Odczyt bez modyfikacji:** #1 i #4; metadane do #2, #16 oraz VEL-DEF-007
  można oglądać odczytowo, ale nie potwierdza to prób odmowy.
- **Workflow aplikacji:** #6, #9, #14 i #15. Można je rozważyć do niezależnego
  dowodu funkcjonalnego dopiero po formalnym dopuszczeniu; niniejsze zadanie
  zakazuje wykonywania całej siedemnastki.
- **Scenariusz mieszany/ograniczony:** #2, #3, #5, #7, #8, #10–#13, #16–#17.
  Wymagają prób DML/DDL, replay, shadowingu, fałszywych wpisów lub odmów ról;
  nie są dozwolone bez jawnej zmiany wcześniejszego ograniczenia. Nie zastępować
  ich nowo skonstruowanym testem.

## Warunki dla recenzenta

1. Uzyskać pisemne, jawne upoważnienie właściwego właściciela bezpieczeństwa
   **przed** jakimkolwiek testem z tej listy oraz formalne rozstrzygnięcie,
   które konkretne wcześniejsze ograniczenia są zmieniane. Zgoda na infrastrukturę
   albo na testy funkcjonalne nie wystarcza.
2. Recenzent niezależny od implementacji powinien zatwierdzić procedurę i
   sprawdzić, czy każdy zatwierdzony scenariusz mieści się w obowiązujących
   zasadach. Jeśli ograniczenia nie zostaną zmienione, pozostać przy odczycie
   metadanych i oznaczyć brakujące gwarancje NOT VERIFIED.
3. Użyć wyłącznie istniejącej sieci
   `veltra-stage2c-security-test-internal`, kontenerów
   `veltra-stage2c-sec-postgres` / `veltra-stage2c-sec-redis` oraz syntetycznej
   bazy `veltra_testing`. Nie podłączać `veltra_default`; nie używać istniejących
   baz, danych, wolumenów ani sekretów.
4. Zachować rozdzielenie: `veltra_migrator` tylko migracje/setup, `veltra_app`
   dla operacji aplikacji. Przed/po każdej autoryzowanej sesji udokumentować
   role, właścicieli, granty, sieć, porty, mounty i stan danych testowych.
5. Przekazać podpisany wynik z dokładnymi nazwami testów, snapshotem/migracją,
   tożsamością połączeń, poleceniami zatwierdzonymi w planie, surowym raportem
   PHPUnit/JUnit, liczbami PASS/FAIL/error/warning/skipped oraz opisem wszelkich
   zmian stanu. Nie raportować nieuruchomionych scenariuszy jako PASS.

## Minimalna bramka do Stage 2C ACCEPT

- Utrzymać odroczenie VEL-DEF-001–006 zgodnie z formalną decyzją właściciela;
  nie otwierać ich ponownie bez nowego, konkretnego dowodu materialnie
  poważniejszego ryzyka.
- Rozstrzygnąć VEL-DEF-007: niezależny, aktualny dowód integralności licznika
  i ograniczeń runtime albo osobna jawna decyzja właściciela ryzyka, jeśli
  proces akceptacji dopuszcza takie odroczenie. Obecny status pozostaje
  **NOT VERIFIED**.
- Uzyskać dozwolony, niezależny dowód dla krytycznych gwarancji RBAC, ostatniego
  administratora, propagacji/revokacji grantów, granicy podpisanych mutacji
  oraz ochrony ledgeru. Wykonać wyłącznie po formalnym rozstrzygnięciu zakazu;
  inaczej Stage 2C pozostaje bez ACCEPT.
- Niezależnie ocenić produkcyjne wymagania wdrożeniowe; PASS testów w disposable
  środowisku nie oznacza gotowości konkretnej instalacji.

**WHAT EXACTLY MUST HAPPEN NEXT TO REACH STAGE 2C ACCEPT?** Właściciel
bezpieczeństwa musi jawnie rozstrzygnąć wcześniejsze zakazy i zatwierdzić
procedurę niezależnej weryfikacji dla nierozstrzygniętych gwarancji; uprawniony
recenzent musi dostarczyć aktualny dowód PASS dla krytycznych zabezpieczeń oraz
rozstrzygnięcie VEL-DEF-007. Do tego czasu nie wydawać Stage 2C ACCEPT.
