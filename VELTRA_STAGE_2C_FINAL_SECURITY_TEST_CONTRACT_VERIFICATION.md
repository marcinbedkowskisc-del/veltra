# VELTRA 2.0 — Stage 2C — Końcowa weryfikacja kontraktu testów bezpieczeństwa

Data: 7 listopada 2026 r.  
Podstawa: odrębna autoryzacja właściciela „Final Security Test Contract Remediation”.  
Status: **WERYFIKACJA TECHNICZNA PASS; DO NIEZALEŻNEGO PRZEGLĄDU. NIE NADANO STAGE 2C ACCEPT.**

## 1. Wynik końcowy

| Faza | Testy | PASS | FAIL | ERROR | Pominięte | Asercje |
|---|---:|---:|---:|---:|---:|---:|
| Najpierw pięć poprawionych metod: 7, 11, 12, 13, 16 | 5 | 5 | 0 | 0 | 0 | 184 |
| Następnie 17 metod, każda w osobnym procesie PHPUnit | 17 | 17 | 0 | 0 | 0 | 304 |
| Cały backend, jedno wywołanie bez filtrów i wyłączeń | 252 | 252 | 0 | 0 | 0 | 2417 |
| W tym 17 metod bezpieczeństwa w pełnej regresji | 17 | 17 | 0 | 0 | 0 | 304 |
| Pozostała część pełnej regresji | 235 | 235 | 0 | 0 | 0 | 2113 |

Ostatnie dwa wiersze są podzbiorami pełnej regresji, nie dodatkowymi wykonaniami. W nowym zadaniu wykonano łącznie 274 przypadki testowe w trzech fazach; nie ponawiano żadnej nieudanej próby, ponieważ wszystkie nowe wykonania były zaliczone. Zachowano wszystkie wyniki historyczne, także wcześniejsze 12 PASS / 4 FAIL / 1 ERROR i 257 asercji.

Nie potwierdzono nowego defektu aplikacyjnego w autoryzowanym zakresie. Nie oznacza to braku wcześniej znanych, odroczonych ryzyk ani dowodu wszystkich głębszych zabezpieczeń PostgreSQL. Ograniczenia dowodowe opisano w sekcjach 4 i 10.

## 2. Dokładny zakres zmian

Względem utrwalonego stanu worktree na początku tego zadania zmieniono wyłącznie:

1. [backend/tests/Feature/RawMaterialPermissionBootstrapTest.php](backend/tests/Feature/RawMaterialPermissionBootstrapTest.php) — cztery wskazane metody i dwa lokalne helpery: `assertUnsignedRuntimeGrantDenied` oraz `grantSecuritySnapshot`.
2. [backend/tests/Feature/AuthorizationMutationBoundaryTest.php](backend/tests/Feature/AuthorizationMutationBoundaryTest.php) — izolacja istniejącego przypadku niepoprawnego produktu dostawcy oraz uściślenie istniejącego helpera odmów.

Zachowano wcześniejsze zmiany tych plików. Istniejąca zmiana [backend/tests/Feature/RoleAdministrationTest.php](backend/tests/Feature/RoleAdministrationTest.php) pochodzi z poprzedniego zadania; jego hash nie zmienił się w obecnym zadaniu. Nie zmieniono żadnej innej istniejącej ścieżki z bazowego zestawu hashy. Nowym plikiem dokumentacyjnym jest niniejszy raport.

Nie zmieniono kodu produkcyjnego, migracji 001–036, triggerów, polityk dostępu, konfiguracji produkcyjnej ani wspólnego [backend/tests/TestCase.php](backend/tests/TestCase.php). Nie instalowano zależności. Skrypty wykonawcze i nowe dowody zapisano poza repozytorium, w odrębnym katalogu sesji `files/stage2c-final`.

## 3. Pięć korekt i zachowane przypadki

### 3.1. Metoda 7 — replay cofniętego bootstrapu

`RawMaterialPermissionBootstrapTest::test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql`

Wcześniejszy FAIL: oczekiwane `23514`, obserwowane `42501`, poprzednia linia 428. Istniejący runtime `INSERT organization_permission_grants`, z historycznym `bootstrap_operation_id`, nie miał podpisanego biletu mutacji.

Korekta: dokładne `42501`, potwierdzenie komunikatu wymagającego podpisanego biletu, rzeczywistej tożsamości runtime i identycznego stanu chronionego przed/po. Nie dopuszczono alternatywnego zestawu SQLSTATE.

Pozostawiono wszystkie dane próby i dalsze asercje: jeden historyczny grant, dokładnie jedno zdarzenie REVOKED, niezmieniony audyt i brak efektywnego View po próbie. Wynik: **PASS, 12 asercji**.

### 3.2. Metoda 11 — cofnięcie authority w zagnieżdżonych upgrade’ach

`RawMaterialPermissionBootstrapTest::test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql`

Wcześniejszy FAIL: `23514` zamiast rzeczywistego `42501`, poprzednia linia 817. Pierwszą instrukcją sekwencji SQL jest unsigned `INSERT raw_material_permission_upgrade_operations`. Signed boundary zatrzymuje już ten INSERT.

Korekta: dokładne `42501` wraz z komunikatem, rolą i pełnymi snapshotami. Zachowano cały pierwotny callback: INSERT operacji, audytu i grantu. Dwie ostatnie instrukcje nie są osiągane po odmowie pierwszej; ich pozostawienie w callbacku **nie jest dowodem ich osobnego wykonania ani odrzucenia**.

Zachowano legalne zagnieżdżone upgrade’y, cofnięcie źródła, ocenę authority w Laravel i PostgreSQL oraz odmowę kolejnego upgrade’u przez usługę. Zachowano liczniki dwóch legalnych operacji i dwóch zdarzeń audytu; powtórzono po próbie kontrolę braku efektywnych uprawnień i wyniku PostgreSQL `false` dla historycznej operacji. Wynik: **PASS, 19 asercji**.

### 3.3. Metoda 12 — administrator bez prawa delegowania

`RawMaterialPermissionBootstrapTest::test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants`

Wcześniejszy FAIL: `23514` zamiast rzeczywistego `42501`, poprzednia linia 864. Ręczne utworzenie root grantu bez audytowanego bootstrapu/upgrade’u jest wcześniej zatrzymywane przez signed boundary.

Korekta: tę samą próbę SQL objęto transakcją runtime i ścisłym helperem `42501`; dodano kontrolę braku efektywnego SupplierManage odbiorcy. Zachowano odmowę usługi dla administratora bez PermissionDelegate i zerowe stany operacji/grantów. Wynik: **PASS, 10 asercji**.

### 3.4. Metoda 13 — delegowanie po dezaktywacji grantora

`RawMaterialPermissionBootstrapTest::test_delegation_is_limited_to_effective_authority_and_revocation_is_audited`

Wcześniejszy FAIL: `23514` zamiast rzeczywistego `42501`, poprzednia linia 963. Niepodpisany INSERT delegowanego grantu przez nieaktywnego grantora napotyka najpierw signed boundary.

Korekta: dokładne `42501` z kontrolą tożsamości, komunikatu i pełnego stanu. Dodano ponowną kontrolę braku efektywnego View odbiorcy.

Zachowano i wykonano wszystkie dalsze przypadki, wcześniej nieosiągnięte przez błędną asercję SQLSTATE: reaktywację użytkownika, dezaktywację i ponowne przyznanie członkostwa bez odtworzenia grantu, odmowę eskalacji do Edit i brak dodatkowego USER_PROVIDED. Pozostały legalna delegacja, mapowanie źródła, audyt cofnięć USER_DEACTIVATED z pustym aktorem oraz HTTP 200/403. Wynik: **PASS, 25 asercji**.

### 3.5. Metoda 16 — produkt ACTIVE bez rewizji początkowej

`AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`

Wcześniejszy ERROR: istniejący fixture tworzył produkt dostawcy w domyślnym stanie ACTIVE bez pierwszej rewizji. Migracja 025 odrzuca ten stan przez `supplier_products_initial_revision`, trigger `DEFERRABLE INITIALLY DEFERRED`; wyjątek ujawniał się dopiero na późniejszym globalnym `SET CONSTRAINTS ALL IMMEDIATE`, poprzednia linia 280.

Korekta:

1. Istniejące wstawienie niepoprawnego produktu, sprawdzenie przejściowego ACTIVE i dotychczasową próbę utworzenia oferty pozostawiono jako operacje runtime.
2. Cały przypadek produktu zamknięto w zagnieżdżonej transakcji/savepoincie, wewnątrz zewnętrznej transakcji metody.
3. Na końcu tego przypadku wywoływane jest `SET CONSTRAINTS ALL IMMEDIATE`. Wymagane są dokładnie `23514` i kontekst funkcji `supplier_product_initial_revision_validate`.
4. Flaga potwierdza osiągnięcie właśnie granicy odroczonego constraintu; wcześniejszy wyjątek przy INSERT nie wystarczyłby do zaliczenia.
5. Po rollbacku savepointu wymagany jest brak tego produktu i jego rewizji. Pełne snapshoty produktów, rewizji produktów, ofert, rewizji surowców, audytu komercyjnego, audytu surowców i zdarzeń ofert muszą być identyczne.

Zagnieżdżona odmowa utworzenia oferty ma własny savepoint; nie usuwa produktu przed sprawdzeniem odroczonego constraintu. Rollback zewnętrznego savepointu przypadku usuwa dopiero niepoprawny produkt i przywraca prawidłowy stan transakcji. Końcowy globalny `SET CONSTRAINTS ALL IMMEDIATE` pozostał i został wykonany pomyślnie.

Zachowano wszystkie pozostałe negatywne operacje i legalne podpisane przepływy metody, w tym odmowę zmiany dostawcy z `23514`, niezmieniony rekord dostawcy i jego audyt oraz późniejsze próby replay grantu i bezpośredniego INSERT roli. Nie wyłączono odroczonego constraintu. Wynik: **PASS, 118 asercji**.

## 4. Granica podpisu a głębsza integralność — rozdzielenie dowodów

Źródłem odmów jest [migracja 022](backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php), funkcja `veltra_private.enforce_authorization_mutation`. Runtime bez biletu otrzymuje:

```text
SQLSTATE 42501
A signed authorization mutation ticket is required
```

Ta odmowa jest właściwa dla **gwarancji A: unsigned SQL runtime nie może stworzyć, odtworzyć ani delegować uprawnień**. Migracja 023 nadal obejmuje ochroną zarówno `organization_permission_grants`, jak i `raw_material_permission_upgrade_operations`. Dla INSERT trigger podpisu wyprzedza właściwe dalsze walidatory. Nie jest to błąd aplikacyjny.

| Zakres | Wniosek dowodowy |
|---|---|
| Metody 7, 11, 12, 13: rzeczywisty runtime, unsigned próba, odmowa i identyczny stan | **PASS — gwarancja A** |
| Metoda 7: niezależna odmowa głębszego replay guarda przy dopuszczeniu mutacji przez signed boundary | **NOT VERIFIED w tej rundzie** |
| Metoda 11: niezależna odmowa strukturalnie spreparowanej operacji/grantu przez dalszy business guard | **NOT VERIFIED w tej rundzie**; dalsze INSERT-y nie są osiągane |
| Metoda 12: niezależna odmowa root grantu bez legalnego operation/audit przez dalszy walidator | **NOT VERIFIED w tej rundzie** |
| Metoda 13: niezależna odmowa przez dalszy guard nieaktywnego grantora/nieefektywnego źródła | **NOT VERIFIED w tej rundzie** |
| Metoda 11: PostgreSQL funkcja oceniająca istniejącą historyczną authority po revoke | **PASS**, wynik `false`; to nie próba dopuszczenia spreparowanego INSERT |
| Metoda 8: migracyjny guard aktywnego historycznego duplikatu | **PASS**, odrębna istniejąca gwarancja; nie zastępuje czterech powyższych |
| Metoda 16: faktyczny odroczony guard produktu ACTIVE bez rewizji początkowej | **PASS**, dokładne `23514` na `SET CONSTRAINTS ALL IMMEDIATE` |

Nie wygenerowano fałszywych biletów, nie ujawniono sekretów podpisujących, nie wyłączono ochrony podpisanych mutacji ani nie przeniesiono negatywnych prób na migratora. Nie twierdzimy, że zmiana oczekiwanego SQLSTATE autonomicznie zweryfikowała gwarancje B.

Jeżeli niezależny przegląd wymaga osobnego dowodu działania tych głębszych guardów pod mutacją przepuszczoną przez signed boundary, potrzebny jest odrębny projekt testu i jawna autoryzacja właściciela. Nie wykonano takiego obejścia w tym zadaniu.

## 5. Przegląd diff przed wykonaniem

Przed testami odczytano cały przyrostowy diff względem kopii dwóch plików utrwalonych przed edycjami; zachowano go jako `task-diff-*.php.patch`. Faktyczny HEAD zapisany w baseline środowiska to `2f4c1abb932f474d9415a8ea1d73c343d2539929`. Historyczne remediacje były już zawarte w tym stanie; zapisany przed edycjami `task-baseline.patch` jest pusty. Punktem odniesienia są rzeczywiste kopie i hashe, nie starsza informacja o dirty worktree z poprzedniej rundy.

Potwierdzono:

- dokładne nazwy wszystkich metod pozostały bez zmian;
- żadna pierwotna operacja negatywna ani dalsza asercja nie została usunięta; zastąpiono cztery błędne oczekiwania `23514` ścisłym kontraktem wcześniejszej odmowy `42501`;
- dane wszystkich czterech prób SQL pozostały niezmienione; zachowano także nieosiągalne po odmowie dalsze instrukcje metody 11;
- dodano kontrole roli, komunikatu, stanu i skuteczności cofnięcia; nie poszerzono akceptowanych SQLSTATE;
- istniejąca lista SQLSTATE w ogólnym helperze metody 16 nie została poszerzona; nowy przypadek constraintu wymaga dokładnego `23514` i właściwej funkcji;
- żaden negatywny runtime SQL nie został przełączony na setup/migratora;
- historyczny fixture metody 8 pozostał niezmieniony: tylko dwa wcześniej autoryzowane business triggery, przez setup, z przywróceniem; nie dotyczy to signed boundary;
- nie osłabiono produkcyjnych zabezpieczeń ani asercji trwałego cofnięcia, delegowania i wygaśnięcia.

Surowy diff i `pre-execution-review.json` zachowano przed wykonaniem. Dodatkowe kontrole hashy po całej regresji potwierdziły brak dalszych zmian źródeł.

## 6. Nowe środowisko i dowód izolacji

Dedykowany prefiks zasobów: `veltra-2c-final-20261107-0108`. Nie wykorzystano kontenerów poprzedniej rundy.

| Właściwość | Dowód / stan |
|---|---|
| Łączność Docker | Client/Server 29.9.2; zapis `docker-version.json` |
| PostgreSQL | 17.11, obraz `postgres:17-alpine`, nowy kontener `-pg` |
| Redis | nowy `-redis`, obraz `redis:7.4-alpine`, ping PONG |
| Runner | nowy `-runner`, PHP 8.4.27 / PHPUnit 12.5.42 |
| ClamAV | nowy `-clamav`, obraz `clamav/clamav:1.4`, lokalny ping gotowości |
| Sieć | nowa, wyłącznie wewnętrzna, `Internal=true` |
| Powiązania sieciowe | każdy nowy kontener tylko w tej sieci; brak połączenia z siecią aplikacji |
| Publikowane porty | brak |
| Istniejące storage | brak bind mountów i brak istniejących wolumenów; PostgreSQL, Redis i kod runnera w nowych tmpfs |
| ClamAV storage | brak mountów; nowa warstwa kontenera |
| Sekrety i dane | nowe syntetyczne hasła/klucze i syntetyczne organizacje/użytkownicy; nie odczytywano aplikacyjnych `.env` ani sekretów |
| Kopia kodu do runnera | lokalna kopia backendu/vendor, bez `.env*`, istniejącego storage i cache |
| Migracje | 36, od 001 do 036, wykonane wyłącznie rolą migratora w nowej bazie |

Izolację utrwalono **przed migracjami i przed jakąkolwiek próbą negatywną** w `isolation-before-migrations.json`: ID zasobów, wewnętrzna sieć, mounty, brak bindów/wolumenów/portów i Redis PONG. Przed testami ponownie sprawdzono stan żywych kontenerów. Oddzielny `scanner-isolation.json` i ping potwierdzają izolację oraz gotowość nowego ClamAV przed pełną regresją.

### Role i aktywne zabezpieczenia przed testami

| Właściwość | Runtime | Migrator/setup | Właściciel bazy |
|---|---|---|---|
| Rola | `veltra_app` | `veltra_migrator` | `veltra_test_admin` |
| Faktyczny current_user / session_user w połączeniu aplikacyjnym | oba `veltra_app` | oba `veltra_migrator` | tylko administracja disposable i snapshoty |
| Właściciel `veltra_testing` | nie | nie | tak |
| SUPERUSER / CREATEDB / CREATEROLE / BYPASSRLS runtime i migratora | wszystkie false | wszystkie false | odrębna rola administracyjna |
| CREATE baza / public | false / false | true / true | administracja nowej bazy |
| USAGE veltra_private | false | true | administracja nowej bazy |
| TRUNCATE organizations | false | true | tylko setup/sprzątanie fixture |
| INSERT ledgeru | false | true | nie używano tej roli do negatywnych prób |
| Członkostwo innych ról | brak | brak | nie używano do eskalacji runtime |
| Właściciel tabel | nie | 57 public + 5 veltra_private | właściciel bazy, nie rola normalnej aplikacji |

Normalnym połączeniem aplikacji i testów negatywnych pozostało `pgsql` runtime. Wszystkie niesystemowe triggery miały `tgenabled=O`, w tym signed boundary, replay i odroczony constraint produktu. Na końcu regresji liczba nieaktywnych niesystemowych triggerów nadal wynosiła zero.

## 7. Indywidualne wyniki wszystkich 17 metod

Każda pozycja ma osobny JUnit, stdout/stderr, dokładne polecenie i porządkowy snapshot przed/po. Poniższe XML znajdują się w podkatalogu dowodów `authorized-seventeen/`; pliki `.txt`, `-command.json`, `-before.txt` i `-after.txt` mają ten sam numer.

| # | Dokładna metoda | Wynik | Asercje | JUnit |
|---:|---|---|---:|---|
| 1 | `RawMaterialPermissionBootstrapTest::test_migration_role_is_not_superuser_or_database_owner` | PASS | 9 | `method01.xml` |
| 2 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_write_to_permission_operation_ledger_directly` | PASS | 21 | `method02.xml` |
| 3 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table` | PASS | 5 | `method03.xml` |
| 4 | `RawMaterialPermissionBootstrapTest::test_permission_grant_validators_pin_public_before_temporary_schemas` | PASS | 5 | `method04.xml` |
| 5 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` | PASS | 8 | `method05.xml` |
| 6 | `RawMaterialPermissionBootstrapTest::test_initial_grant_is_explicit_audited_one_time_and_never_self_granted` | PASS | 6 | `method06.xml` |
| 7 | `RawMaterialPermissionBootstrapTest::test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql` | PASS | 12 | `method07.xml` |
| 8 | `RawMaterialPermissionBootstrapTest::test_migration_guard_fails_closed_for_an_active_historical_duplicate` | PASS | 3 | `method08.xml` |
| 9 | `RawMaterialPermissionBootstrapTest::test_bootstrap_rejects_unknown_permissions_and_invalid_passwords` | PASS | 3 | `method09.xml` |
| 10 | `RawMaterialPermissionBootstrapTest::test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable` | PASS | 21 | `method10.xml` |
| 11 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql` | PASS | 19 | `method11.xml` |
| 12 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants` | PASS | 10 | `method12.xml` |
| 13 | `RawMaterialPermissionBootstrapTest::test_delegation_is_limited_to_effective_authority_and_revocation_is_audited` | PASS | 25 | `method13.xml` |
| 14 | `RawMaterialPermissionBootstrapTest::test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants` | PASS | 18 | `method14.xml` |
| 15 | `RawMaterialPermissionBootstrapTest::test_delegated_grants_expire_with_source_permission_and_delegation_authority` | PASS | 10 | `method15.xml` |
| 16 | `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` | PASS | 118 | `method16.xml` |
| 17 | `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql` | PASS | 11 | `method17.xml` |

Pierwsze wykonania pięciu poprawionych metod zachowano oddzielnie w `corrected-five/`, nie nadpisano ich wykonaniami całej siedemnastki. Wszystkie procesy zakończyły się kodem 0; nie odnotowano pominięć.

## 8. Rzeczywiste odmowy i stan przed/po

Poniższe wyniki wystąpiły zarówno w pierwszej piątce, w indywidualnej siedemnastce, jak i w pełnej regresji:

| Próba | Faktyczne current_user / session_user | Oczekiwane / obserwowane SQLSTATE | Stan |
|---|---|---|---|
| Replay historycznego bootstrap_operation_id — metoda 7 | `veltra_app` / `veltra_app` | `42501` / `42501`, brak biletu | identyczny przed/po, cofnięcie skuteczne |
| INSERT upgrade operation po revoke authority — metoda 11 | `veltra_app` / `veltra_app` | `42501` / `42501`, brak biletu | identyczny przed/po, authority nadal false |
| Root grant bez legalnego bootstrapu/upgrade’u — metoda 12 | `veltra_app` / `veltra_app` | `42501` / `42501`, brak biletu | identyczny przed/po, brak skutecznego SupplierManage |
| Delegowany grant nieaktywnego grantora — metoda 13 | `veltra_app` / `veltra_app` | `42501` / `42501`, brak biletu | identyczny przed/po, revoke nieodwrócony |
| Zmiana business-data dostawcy — metoda 16 | `veltra_app` / `veltra_app` | `23514` / `23514` | rekord i audyt bez zmian |
| Produkt ACTIVE bez rewizji początkowej — metoda 16 | `veltra_app` / `veltra_app` | `23514` / `23514`, `supplier_product_initial_revision_validate` | osiągnięta granica SET CONSTRAINTS; produkt/rewizje nieutrwalone |

W czterech metodach grantowych pełne snapshoty obejmują granty organizacyjne, zdarzenia grantów, operacje upgrade, ledger użyć operacji, audyt surowców, zdarzenia bezpieczeństwa, role, członkostwa, permission_grants i lokalizacje; dodatkowo stan aktywności/weryfikacji/auth_version użytkowników oraz latch organizacji. Nie zapisuje się haseł ani sekretów podpisujących.

Metoda 16 porównuje siedem pełnych zbiorów rekordów wymienionych w sekcji 3.5 i osobno sprawdza brak konkretnego produktu oraz jego rewizji. Zapisuje też rzeczywiste błędy PostgreSQL dla ścisłych odmów. Te dane są w surowym stderr i w wyodrębnionym `actual-denials-and-state-evidence.json`; nie są to zrekonstruowane domniemania na podstawie samych kodów wyjścia.

Snapshoty `psql` wokół metod wykonuje administrator **wyłącznie nowej disposable bazy**. Są dowodem porządkowym stanu i triggerów, nie zastępują porównań wewnątrz testu: puste tabele po teardown same nie dowodziłyby braku przejściowej zmiany.

## 9. Pełna regresja i jakość

Pełną regresję uruchomiono po zaliczeniu pierwszych pięciu i wszystkich siedemnastu metod, jednym poleceniem PHPUnit:

```text
vendor/bin/phpunit --do-not-cache-result --colors=never
  --stop-on-failure --stop-on-error --log-junit /tmp/regression.xml
```

Nie użyto `--filter`, wyłączeń ani podziału na partie. Nowe izolowane Redis i ClamAV wskazano przez zmienne wyłącznie procesu testowego; produkcyjnych ustawień nie zmieniono. Nie uruchomiono odrębnego skryptu VEL-DEF-007.

Wynik: **252 testy, 2417 asercji, 0 failures, 0 errors, 0 skipped, exit 0**. Czas PHPUnit: **4 min 27,402 s**. Inwentaryzacja `--list-tests` wykazała 252 przypadki. Programowo porównano liczności metod/datasetów z JUnit; wszystkie 17 autoryzowanych metod wystąpiło dokładnie raz i miało identyczne wyniki/asercje jak osobne wykonania.

| Kontrola | Wynik |
|---|---|
| PHP lint po zakończeniu faz testowych | PASS, 244 pliki w kopii runnera, łącznie z public/index.php, artisan i wygenerowanym cache bootstrap |
| Pint — formatowanie | wyłącznie dwa autoryzowane pliki |
| Pint `--test` — cały backend | PASS, 246 plików |
| `git diff --check` | PASS, exit 0 |
| Hashe wszystkich bazowych plików tracked po regresji | zgodne ze stanem po zatwierdzonym diff |
| Przyrostowy zakres względem początku zadania | dokładnie dwa dozwolone pliki testowe |

Nie pozostała niezgodność infrastruktury/testów powodująca FAIL/ERROR w 17 metodach ani w całej regresji.

## 10. Ryzyka, defekty i ograniczenia

- **Nie potwierdzono nowej podatności aplikacji** w tej rundzie. Wcześniejsze cztery FAIL dotyczyły nieaktualnego oczekiwania warstwy odmowy, a ERROR — niepoprawnego fixture produktu i odroczonej granicy walidacji.
- **Gwarancje B z sekcji 4 pozostają NOT VERIFIED jako niezależne negatywne próby dalszych guardów.** Status PASS metod dotyczy rzeczywiście wykonanego, opisanego kontraktu i zachowanych dalszych zachowań, nie obejścia signed boundary.
- **VEL-DEF-001–006: dotychczasowe odroczenia właściciela pozostają bez zmian.** Metoda 16 nadal zawiera historyczne, znane próby mutacji poza zawężoną signed boundary, m.in. kategorii i rewizji surowca, których powodzenie było już jawnie objęte tymi odroczeniami. Nie usunięto ich, nie potraktowano jako nowego nieoczekiwanego sukcesu ani nie ogłoszono zabezpieczonymi.
- Asercja braku nowej nieautoryzowanej rewizji w poprawionym przypadku produktu dotyczy tego przypadku/savepointu; nie przekształca odroczonych ryzyk wcześniejszej części metody w PASS bezpieczeństwa.
- **VEL-DEF-007:** pierwotne **13/13 PASS** i identyczne snapshoty licznika/przypisań zachowano byte-for-byte; nie wykonano ponownie odrębnego skryptu. Istniejąca operacja dotycząca licznika, już zawarta w autoryzowanej metodzie 16, pozostała częścią tej metody. Nie nadano CLOSED.
- Ta runda nie jest niezależnym audytem całej aplikacji ani akceptacją ryzyka przez właściciela.

## 11. Zachowanie dowodów historycznych

Przed edycjami utrwalono hashe bieżącego stanu plików i kopie dwóch testów. Następnie potwierdzono:

- wszystkie **99 pozycji pierwotnego manifestu** bez zmian;
- wszystkie **154 pozycje manifestu poprzedniej rundy** bez zmian, razem z jej raportem;
- oba wcześniejsze archiwa dowodowe bez zmian;
- zachowanie starych indywidualnych JUnit, stdout/stderr, failure-review, snapshotów oraz dowodów VEL-DEF-007;
- brak nadpisania nowych prób między fazami.

| Zachowany raport | SHA-256 |
|---|---|
| `VELTRA_STAGE_2C_AUTHORIZED_SECURITY_VERIFICATION_REPORT.md` | `cb2583df2120d2b805538b37310aa28c443f5479dbadb72e838819a0f3d386ea` |
| `VELTRA_STAGE_2C_SECURITY_TEST_FAILURE_TRIAGE.md` | `170361ca6de0ef789f9d50618034681c9f32dc384cb6c664da20dce2a47f7605` |
| `VELTRA_STAGE_2C_SECURITY_TEST_REMEDIATION_AND_RERUN_REPORT.md` | `43890be15e98509b525d36f641dcdff5864de353fd20b71698a1f432bd480b04` |

Dowody nowych wykonań:

```text
/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/stage2c-final/
```

Najważniejsze pozycje: `stage2c-authorized-evidence/summary.json`, `corrected-five/`, `authorized-seventeen/`, `regression.xml`, `regression.txt`, `regression-coverage-review.json`, `actual-denials-and-state-evidence.json`, `historical-and-scope-review.json`, dowody izolacji i `cleanup.json`. Odrębny `evidence-sha256.json` wiąże pliki dowodowe i niniejszy raport hashami. Archiwum: `files/stage2c-final-evidence.tar.gz`. Nie obejmuje syntetycznych plików z hasłami, vendor ani kopii całego backendu.

## 12. Sprzątnięcie i nienaruszenie istniejącej infrastruktury

Po testach, przed usunięciem zasobów: 36 migracji, zero nieaktywnych niesystemowych triggerów; tabele organizacji, użytkowników, grantów, ledgeru, liczników i przypisań SKU puste po teardown.

Usunięto dokładnie **cztery własne kontenery i jedną własną sieć**, weryfikując ich wcześniej zapisane ID, nazwy, etykietę zadania i wyłączne członkostwo w nowej sieci. Usunięto dwa lokalne pliki z syntetycznymi sekretami. Nie używano `prune` ani operacji na istniejących wolumenach.

Po sprzątnięciu nie pozostał żaden kontener ani sieć z etykietą tego zadania. Sześć istniejących kontenerów aplikacji miało te same ID i te same sieci co przed zadaniem. Nie odczytywano ani nie modyfikowano ich danych, baz i sekretów. `cleanup.json` zawiera identyfikatory usuniętych zasobów i porównanie baseline.

Nie wykonano commitów, pushy, deploymentu ani aktywacji RAW_MATERIALS/VSEF. Process-local fixture gotowości RAW_MATERIALS pozostał wyłącznie w izolowanych testach; nie zmieniono produkcyjnego statusu modułu.

## 13. Rekomendacja

**Pakiet jest odpowiedni do niezależnego przeglądu technicznego Stage 2C**, ponieważ zawiera zaliczone 17/17 metod, pełną regresję 252/252, dokładne tożsamości runtime, odmowy SQLSTATE, snapshoty, diff i dowody izolacji/sprzątnięcia.

Przegląd musi jawnie uwzględnić rozróżnienie A/B, wymienione NOT VERIFIED oraz niezmienione odroczenia VEL-DEF-001–006 i status VEL-DEF-007. Ewentualna potrzeba dodatkowych dowodów głębszych guardów wymaga odrębnej autoryzacji; nie należy automatycznie utożsamiać wszystkich PASS z pełnym zamknięciem ryzyk.

**Nie nadano Stage 2C ACCEPT, nie zamknięto VEL-DEF-007 i nie zmieniono żadnego odroczenia.** Decyzja o akceptacji należy do niezależnego przeglądu i właściciela projektu.

Docelowe miejsce publikacji: istniejący folder [RAPORTY_COPILOTA](https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t). Potwierdzenie otwarcia przesłanej kopii i jej URL należy do dowodu publikacji, nie zastępuje akceptacji bezpieczeństwa.
