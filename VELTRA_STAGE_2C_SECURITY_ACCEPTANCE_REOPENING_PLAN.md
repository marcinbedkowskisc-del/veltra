# VELTRA 2.0 — Plan ponownego otwarcia akceptacji bezpieczeństwa Stage 2C

**Data przeglądu:** 6 listopada 2026 r.  
**Zakres:** bieżący worktree po Stage 2E.7, migracje 001–036 oraz wcześniejsze decyzje i dowody Stage 2C.  
**Tryb:** analiza i dokumentacja read-only.  
**Werdykt:** **NO-GO dla Stage 2C ACCEPT; wszystkie 17 zastrzeżonych testów pozostaje NOT VERIFIED.**  
**Autoryzacja testów:** ten plan nie zezwala na wykonanie żadnego zastrzeżonego testu.

## 1. Konkluzja i granice dowodów

Aktualny kod obejmuje rozszerzenia Stage 2D i Stage 2E, których nie było w poprzednim przeglądzie Stage 2C. Przejrzano wskazane decyzje i raporty, aktualne zabezpieczenia alokacji SKU, ochronę podpisanych mutacji oraz 36 plików migracji. Nie uruchamiano testów, zapytań DML/DDL, zmian ról, prób replay, migracji ani usług bazodanowych.

Wyniki Stage 2E.7 — 235 dozwolonych testów i 2113 asercji — dotyczą integracji CSV. Raport wyraźnie wyłącza dokładnie 17 testów Stage 2C. Nie stanowią one ani dowodu PASS dla tych testów, ani akceptacji bezpieczeństwa. Stage 2C pozostaje **OPEN / NOT VERIFIED**; nie potwierdzono defektu przez wykonanie prób, ale nie ma też wystarczających dowodów do formalnego ACCEPT.

Wcześniejsze ograniczenia nadal obowiązują: odizolowane środowisko samo w sobie nie jest zgodą na testy; zabronione pozostają niedozwolone próby DML/DDL, fałszowanie ticketów, replay, zmiany ról i obchodzenie zabezpieczeń. Dozwolony wykonawca może rozpocząć takie testy dopiero po osobnej, jawnej decyzji właściwego właściciela/security authority, która rozstrzyga te ograniczenia i zatwierdza testy, procedurę, wykonawcę oraz izolację.

## 2. Obecny stan gwarancji Stage 2C

| Obszar | Stan na podstawie bieżącego worktree |
|---|---|
| Least privilege ról runtime i migratora | Migracje oraz testy definiują oczekiwane rozdzielenie i atrybuty ról. Brak aktualnego, niezależnego wyniku testu rzeczywistych poświadczeń runtime dla zastrzeżonego zakresu. |
| Ledger operacji grantów, replay i trwałe cofnięcie | Implementacja zawiera ograniczenia uprawnień, mechanizmy append-only oraz guardy replay; gwarancje odmowy runtime pozostają **NOT VERIFIED**. |
| RBAC, delegowanie i propagacja revokacji | Istnieją ścieżki domenowe, audyt i testy funkcjonalne. Nie zastępują one wymaganej weryfikacji zastrzeżonych scenariuszy SQL i propagacji w aktualnym schemacie. |
| Ochrona ostatniego administratora | Istnieją reguły domenowe i ograniczenia bazy. Test SQL `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql` pozostaje **NOT VERIFIED**. |
| Podpisana granica mutacji | Mechanizm PostgreSQL w migracjach 022–024 oraz jego nowsze zastosowania istnieją w kodzie; bez aktualnego, autoryzowanego testu rzeczywistej roli runtime nie ma dowodu wymaganych odmów. |
| Izolacja tenantów i kontekst lokalizacji | W kodzie i constraintach występują powiązania organizacji/lokalizacji; nie jest to dowód wszystkich gwarancji RBAC/SQL z Stage 2C. |
| VEL-DEF-001–006 | Zachowuje się wcześniejsze odroczenie właściciela zgodnie z obowiązującą instrukcją. Odroczenie nie oznacza naprawy ani ogólnej akceptacji ryzyka. Jest rozbieżność opisów w dokumentach zamknięcia — patrz sekcja 7. |
| VEL-DEF-007 | **NOT VERIFIED.** Migracja 035 zmienia model licznika i istotnie ogranicza ryzyko w implementacji, ale brak niezależnego dowodu odmowy operacji runtime na aktualnym modelu. |

### Wcześniejsze decyzje właściciela

Rejestr ryzyk w Google Drive oraz raport zamknięcia weryfikacji Stage 2C wskazują formalne odroczenie VEL-DEF-001–006. Niniejszy plan je zachowuje; nie klasyfikuje pozycji jako naprawionych ani jako bezwarunkowo zaakceptowanych ryzyk.

`VELTRA_STAGE_2C_FORMAL_CLOSURE_DECISION.md` z 6 listopada zawiera ostrożniejsze sformułowanie, że wcześniejsza ogólna intencja odraczania nie była zgodą dla każdej pozycji. Późniejsze zamknięcie weryfikacji mówi o formalnej decyzji właściciela i zastąpieniu wcześniejszego stwierdzenia o braku zgody. Zachowuje się odroczenie wymagane w bieżącym zakresie, a rozbieżność dokumentów należy uzgodnić w rejestrze przed końcowym werdyktem Stage 2C; nie wolno po cichu rozszerzać ani cofać decyzji właściciela.

Pozostałe wiążące ograniczenia z wcześniejszych decyzji: nie wykonywać 17 testów bez osobnej autoryzacji; nie uznawać VEL-DEF-007 za zamknięte na podstawie samego przepływu alokacji; utrzymywać `RAW_MATERIALS` jako `NOT_READY`; nie aktywować VSEF; nie traktować wyniku testów disposable jako gotowości konkretnej instalacji.

## 3. Wpływ migracji 001–036 na poprzednie założenia

Migracje 001–027 tworzą i utwardzają instalację, tożsamość, RBAC, granty, audyt, bootstrap oraz podpisane granice mutacji. W szczególności 017–021 dodają ochronę replay, ograniczenie zapisu do ledgeru oraz utwardzenie `search_path`; 022–024 wprowadzają i zawężają podpisaną granicę; 025–027 rozszerzają kontrolę integralności i cyklu życia członkostw. Te mechanizmy pozostają podstawą analizy, ale raporty historyczne do 027 nie dowodzą aktualnych gwarancji w schemacie 036.

Migracje 028–036 dodają dalsze tabele, uprawnienia, triggery i ścieżki aplikacyjne. Nie znaleziono statycznej przesłanki, że same usuwają wcześniejsze zabezpieczenia RBAC; tworzą jednak nowe powierzchnie mutacji, które wymagają przeglądu w aktualnym snapshotcie:

- **028–033 — Stage 2D:** dane alergenowe i przechowywania powiązane z rewizją, wersjonowane dokumenty prywatne, wyniki skanowania, oceny zgodności, nowe permissiony oraz podpisane mutacje danych rewizji. To rozszerza zakres danych i autoryzacji, których nie pokrywało założenie o migracji 027.
- **034 — historia importu cen:** dodaje historię importu i metadanych handlowych, zachowując istniejący model tenantowy i podpisanych mutacji. Jest to dodatkowy zakres audytu/niezmienności, a nie dowód bezpieczeństwa Stage 2C.
- **035 — standaryzacja SKU:** zmienia nazwę `raw_material_code_counters` na `organization_sku_counters`, dodaje `organization_sku_assignments`, przenosi historyczne kody SUR do przypisań typu `LEGACY`, wprowadza prefiksy RAW/INT/PRD/MEN i nowe ograniczenia/wyzwalacze. Poprzednie dowody odnoszące się wyłącznie do tabeli licznika 026 nie wystarczają już do oceny VEL-DEF-007.
- **036 — historia importu katalogu:** dodaje append-only historię batchy i wierszy oraz ochronę podpisanego kontekstu importu katalogu; rozszerza listę tabel i zakresów mutacji.

Wszystkie 17 metod nadal istnieje pod wskazanymi nazwami. W przeglądzie statycznym nie znaleziono jawnej referencji tych metod do starej nazwy `raw_material_code_counters`; test granicy mutacji odwołuje się do nowej `organization_sku_counters`. Nie jest to dowód, że każda asercja i fixture działają z migracjami 028–036. Ich kompatybilność wykonawcza pozostaje niepotwierdzona do autoryzowanego przebiegu na bazie z migracjami 001–036.

## 4. VEL-DEF-007 — licznik SKU po migracji 035

### Co zmieniła migracja 035

W bieżącym kodzie `SkuAllocationService` wykonuje alokację i rezerwację w transakcji, blokuje organizację/licznik, zapisuje zmianę licznika i przypisanie SKU przez podpisany scope `sku.allocate`. Migracja 035:

- zabrania usuwania licznika i zmian wartości, które nie są ściśle większe;
- wymaga, aby licznik odpowiadał najwyższemu przypisanemu/zarezerwowanemu numerowi;
- sprawdza podpisany scope, aktora, organizację i podmiot przy zapisie przypisania;
- czyni przypisania append-only oraz wiąże nowy rekord RAW z właściwym przypisaniem;
- ogranicza bezpośrednie granty runtime do licznika i przypisań; triggery podpisanych mutacji pozostają aktywne.

Statycznie daje to mocniejsze bariery niż model migracji 026: odrzucenie obniżenia licznika wynika z walidacji niezmiennika, a pozostałe zmiany runtime powinny przejść przez podpisany kontekst i zgodność odroczoną. Ścieżka migratora ma odrębne zaufanie i jest poza gwarancją dla runtime. Nie wykonano operacji w bazie, więc nie stwierdzono eksperymentalnie odmowy; nie wyklucza się też błędu konfiguracji lub interakcji triggerów.

### Pozostała luka dowodowa

`AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` sprawdza m.in. bezpośrednią próbę zwiększenia licznika z rolą runtime i niezmienność jego wartości po odmowie. Nie sprawdza wprost obniżenia licznika ani wszystkich wariantów aktualizacji/usunięcia nowego licznika i przypisania. Testy alokacji/współbieżności potwierdzają ścieżkę poprawną, nie odmowę rollbacku.

Wniosek: 035 **materialnie zmniejsza ryzyko projektowe**, ale nie zamyka VEL-DEF-007. Samo wykonanie obecnych 17 metod nie powinno być przedstawiane jako dowód odmowy obniżenia, jeżeli zaakceptowany test tego nie obejmuje. Potrzebna jest osobna, jawna autoryzacja zakresu prób licznika runtime — bez rekonstruowania, dzielenia ani podmieniania którejkolwiek z 17 metod. Dopóki brak takiej autoryzacji i dowodu, VEL-DEF-007 pozostaje **NOT VERIFIED**.

## 5. Zastrzeżone testy — status i plan dowodowy

Status każdego wiersza: **NOT VERIFIED — nie uruchamiać bez odrębnej autoryzacji**. Zakres operacji poniżej opisuje istniejącą metodę, nie stanowi instrukcji jej samodzielnego odtwarzania. Wszystkie wykonania dynamiczne wymagają osobnej bazy PostgreSQL 17, syntetycznych danych, odrębnych ról setup/migrator i runtime, braku mountów/połączeń do istniejących baz i wolumenów aplikacyjnych oraz potwierdzonego planu sprzątnięcia.

| # | Dokładna metoda | Gwarancja i operacje występujące w teście | Izolacja, możliwy skutek i kryterium PASS |
|---:|---|---|---|
| 1 | `RawMaterialPermissionBootstrapTest::test_migration_role_is_not_superuser_or_database_owner` | Atrybuty roli migratora: odczyt `pg_roles`/`pg_database` i uprawnień. **Bez DML/DDL, zmian permissionów, ról ani replay.** | Tylko odczyt metadanych na disposable PG17. PASS: brak SUPERUSER/CREATEDB/CREATEROLE, brak własności bazy oraz wymagane uprawnienie setup. Wymaga potwierdzenia, że test odczytuje właściwą rolę migratora. |
| 2 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_write_to_permission_operation_ledger_directly` | Odczyt grantów runtime, a następnie próby `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE` na ledgerze oraz wywołania chronionej funkcji. **DML; bez zmiany ról; bez replay.** | Użyć prawdziwych poświadczeń runtime wyłącznie w disposable DB. PASS: oczekiwane odmowy/SQLSTATE, brak zmian ledgeru, brak dodatkowego członkostwa roli i brak EXECUTE nieprzeznaczonej funkcji. Ryzyko: wadliwa konfiguracja może pozostawić wpisy w ledgerze. |
| 3 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_attach_the_bootstrap_security_definer_to_a_temporary_table` | Tworzenie tabeli tymczasowej i próba utworzenia triggera wskazującego na `SECURITY DEFINER`; próba bezpośredniego wywołania funkcji. **DDL i wywołanie funkcji; bez zmian trwałych permissionów/rol; bez replay.** | Oddzielna sesja runtime i efemeryczna baza. PASS: obie próby są odrzucone, stan ledgeru bez zmian. Tabela tymczasowa istnieje tylko w sesji; błąd konfiguracji może dopuścić próbę uprzywilejowaną. |
| 4 | `RawMaterialPermissionBootstrapTest::test_permission_grant_validators_pin_public_before_temporary_schemas` | Odczyt `pg_proc.proconfig` dla walidatorów. **Bez DML/DDL, zmian permissionów/rol ani replay.** | Odczyt katalogów w PG17 po migracji 036. PASS: każda wskazana funkcja ma dokładnie wymagany `search_path=pg_catalog, public, pg_temp`. Nie dowodzi odporności rzeczywistych wywołań. |
| 5 | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` | Tworzenie relacji tymczasowych i próby ich wykorzystania do podstawienia relacji walidacyjnych/grantów. **DDL tymczasowe i DML próbne; bez zmiany ról/permissionów; bez replay.** | Runtime w osobnej sesji disposable. PASS: walidacja odrzuca shadowing, brak trwałego grantu i brak zmian ledgeru/audytu. Możliwe skutki ograniczone do sesji, chyba że walidacja nie zadziała. |
| 6 | `RawMaterialPermissionBootstrapTest::test_initial_grant_is_explicit_audited_one_time_and_never_self_granted` | Prawidłowy bootstrap przez usługę, ponowienie i próba self-grant. **DML aplikacyjne/RBAC oraz audyt; bez DDL/zmian ról PostgreSQL; bez SQL replay.** | Wyłącznie syntetyczna organizacja i użytkownicy. PASS: pojedyncze jawne granty z audytem, brak samonadania i dodatkowych wpisów po ponowieniu. Błędna ścieżka może zmienić granty i historię audytową. |
| 7 | `RawMaterialPermissionBootstrapTest::test_revoked_bootstrap_permission_cannot_be_restored_by_replaying_its_operation_id_in_sql` | Nadanie i odwołanie grantu, po czym bezpośrednia próba SQL z historycznym operation ID. **DML i replay SQL; bez DDL/zmian ról.** | Syntetyczne granty w disposable DB, z baseline audytu przed próbą. PASS: próba odrzucona, grant pozostaje cofnięty, nie powstaje nowy grant ani zdarzenie. Jeśli guard zawiedzie, może nastąpić trwałe przywrócenie grantu w bazie testowej. |
| 8 | `RawMaterialPermissionBootstrapTest::test_migration_guard_fails_closed_for_an_active_historical_duplicate` | Wyłączenie/ponowne włączenie dwóch triggerów, wstawienie aktywnego duplikatu i uruchomienie guardu. **DDL oraz DML; bez zmian ról/permissionów; bez replay.** | Tylko świeża disposable baza; test próbuje przywrócić triggery w `finally`, ale tego nie wolno traktować jako substytutu izolacji. PASS: guard fail-closed z oczekiwanym błędem i bez cichej akceptacji. Możliwy skutek to niespójny grant i zmieniony stan triggerów. |
| 9 | `RawMaterialPermissionBootstrapTest::test_bootstrap_rejects_unknown_permissions_and_invalid_passwords` | Wywołania usługi z nieznanym permissionem i nieprawidłowym hasłem. **Próby DML aplikacyjnego/RBAC; bez DDL/zmian ról/replay.** | Syntetyczni aktorzy i odbiorcy. PASS: obie próby odrzucone, zero nowych grantów. Potencjalny skutek błędu to nieautoryzowany grant. |
| 10 | `RawMaterialPermissionBootstrapTest::test_existing_organization_permission_upgrade_is_explicit_idempotent_and_permanently_revocable` | Upgrade, retry, konflikt idempotencji, bezpośrednie próby modyfikacji append-only operacji/audytu, dezaktywacja/reactivation grantora. **DML aplikacyjny i SQL; zmienia granty/audyt; bez DDL/zmiany ról PostgreSQL; bez replay SQL.** | Jednorazowa syntetyczna organizacja, kontrola stanu przed/po i transakcyjne sprzątnięcie. PASS: jawny/idempotentny upgrade, jeden audyt, konflikt dla zmienionego żądania, append-only i trwała revokacja. Możliwe skutki to granty/audyt lub błędna reaktywacja. |
| 11 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_revocation_propagates_through_nested_upgrades_and_postgresql` | Zagnieżdżone upgrade’y, revokacja źródła, sprawdzenie propagacji i bezpośrednie próby SQL. **DML aplikacyjny/SQL i audyt; bez DDL/zmian ról; obejmuje próbę fałszywego grantu, nie replay operation ID.** | Syntetyczny łańcuch authority w izolowanej bazie; zbadać skuteczność w Laravel i PostgreSQL oraz brak zmian po odmowie. Błędny wynik może zmienić granty lub authority potomków. |
| 12 | `RawMaterialPermissionBootstrapTest::test_permission_upgrade_denies_administrators_without_delegate_authority_and_rejects_forged_sql_grants` | Próby upgrade’u bez skutecznego prawa delegacji i bezpośredniego SQL fałszującego grant. **DML aplikacyjny/SQL; bez DDL/zmian ról; bez replay.** | Syntetyczni administratorzy i odbiorcy. PASS: brak grantu/operacji/audytu zaakceptowanego jako prawidłowy po odmowie; grant bez authority jest odrzucany. Możliwy skutek to eskalacja permissionów. |
| 13 | `RawMaterialPermissionBootstrapTest::test_delegation_is_limited_to_effective_authority_and_revocation_is_audited` | Delegowanie, cofnięcie, zawieszenie użytkownika/członkostwa, próba SQL niedozwolonego delegowania oraz ponowna aktywacja. **DML aplikacyjny/SQL, audyt, operacje HTTP kontekstu; bez DDL/zmian ról PostgreSQL; bez replay.** | Wyłącznie syntetyczne konta, lokalizacja i granty. PASS: brak delegacji ponad authority, prawidłowy audyt, nieskuteczność po revokacji/zawieszeniu i brak przywrócenia po reaktywacji. Błąd może tworzyć/odnawiać granty. |
| 14 | `RawMaterialPermissionBootstrapTest::test_membership_and_user_reactivation_never_restore_revoked_raw_material_grants` | Zmiana aktywności członkostwa/użytkownika i sprawdzenie grantów po reaktywacji. **DML aplikacyjny/RBAC i audyt; bez DDL/zmian ról/replay.** | Syntetyczne członkostwa w disposable DB. PASS: revokacja utrwalona, reaktywacja nie przywraca wcześniejszego grantu, audyt jest zgodny. Potencjalny skutek błędu to niejawne odtworzenie dostępu. |
| 15 | `RawMaterialPermissionBootstrapTest::test_delegated_grants_expire_with_source_permission_and_delegation_authority` | Przypadki ważności/wygaśnięcia źródłowego permissionu i authority delegacji. **DML aplikacyjny/RBAC oraz manipulacja czasem testowym; bez DDL/zmian ról/replay.** | Syntetyczne granty z jawnie kontrolowanymi datami. PASS: delegacja wygasa wraz ze źródłem/authority, niezależne granty nie są cofane przedwcześnie. Błąd może utrzymać nadmiarowy dostęp. |
| 16 | `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` | Rzeczywista rola runtime: próby DML do chronionych tabel, `TRUNCATE`, `ALTER TABLE ... DISABLE TRIGGER`, `SET ROLE`, tabela/trigger tymczasowy i fałszywy ticket; następnie poprawne przepływy Laravel. Obejmuje też bezpośrednie zwiększenie `organization_sku_counters`. **DML, DDL, próba zmiany roli/scope; bez `CREATE ROLE`; bez replay operation ID.** | Wymaga osobnych poświadczeń runtime i setup oraz świeżego PG17/Redis. PASS: zakazane operacje odrzucone, niezmieniony stan chroniony, legalne przepływy aplikacyjne działają. Potencjalne skutki są szerokie; nie wolno używać żadnej istniejącej bazy. Test nie dowodzi sam przez się odmowy obniżenia licznika. |
| 17 | `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql` | Próby domenowe revokacji/dezaktywacji ostatniego administratora i bezpośrednie SQL dotyczące użytkownika, roli, organizacji, członkostwa oraz lokalizacji. **DML aplikacyjny/SQL i audyt; bez DDL/zmian ról PostgreSQL/replay.** | Syntetyczna organizacja z jednym administratorem w disposable DB. PASS: wszystkie próby blokowane, administrator i wymagane relacje pozostają niezmienione. Błąd może odebrać jedyny dostęp administracyjny. |

### Wspólne wymagane kryteria dla PASS

Wynik musi pochodzić z dokładnego, niezmienionego testu i bieżącego snapshotu migracji 001–036, z odróżnieniem roli setup/migratora od `APP_DB_USERNAME` runtime. Raport powinien zawierać pełną nazwę metody, hash/snapshot kodu i migracji, identyfikację bazy/połączenia bez sekretów, surowy wynik PHPUnit/JUnit, liczniki PASS/FAIL/error/warning/skipped, oczekiwany SQLSTATE, stan danych przed/po, wynik audytu i dowód izolacji. Brak wykonania, wynik historyczny dla migracji 001–027 lub sam odczyt definicji nie jest PASS.

## 6. Wymagana autoryzacja i procedura niezależnej weryfikacji

Wcześniejszy handoff Stage 2C wymaga, aby właściwy właściciel bezpieczeństwa jawnie rozstrzygnął zakaz przed uruchomieniem testów. Decyzja musi wskazywać:

1. dokładne 17 metod z sekcji 5, ich kolejność, zakres i uprawnionego niezależnego wykonawcę;
2. zgodę na wymienione w tych metodach próby DML/DDL, modyfikację fixture’ów, próbę `SET ROLE`, fałszywego ticketu oraz replay — nie wystarczy ogólna zgoda na „testy na disposable”;
3. oddzielną zgodę na zakres VEL-DEF-007, jeżeli wymagany jest bezpośredni test obniżenia/usunięcia licznika, którego aktualna metoda #16 nie wykazuje wprost; taka zgoda nie upoważnia do zmiany, podziału ani zastąpienia żadnej z 17 metod;
4. środowisko wyłącznie disposable: osobna sieć wewnętrzna, PostgreSQL 17 i Redis, syntetyczne dane, dedykowane storage, brak połączenia z `veltra_default`, istniejącymi bazami, wolumenami, sekretami i aplikacyjnymi kontenerami;
5. zakres przygotowania schematu i ról, zatwierdzone polecenia, sposób zbierania dowodów, maksymalny zakres zmian, procedurę przerwania i sposób zniszczenia wyłącznie zasobów jednorazowych.

Po uzyskaniu decyzji zalecana kolejność:

1. Przed testami niezależnie udokumentować identyfikatory kontenerów, sieć, porty, mounty, wolumeny, poświadczenia/role bez ujawniania sekretów i nieosiągalność istniejących zasobów; wykazać, że baza i storage są nowe.
2. Zastosować migracje 001–036 w jednorazowej bazie. Przygotować wyłącznie syntetyczne organizacje, role i dane przez zatwierdzone mechanizmy.
3. Wykonać dokładnie zatwierdzone, istniejące metody bez zmian i bez równoległego uruchamiania innych testów na tych samych zasobach.
4. Zebrać wymagany dowód PASS dla każdej metody oraz ślad odmowy i niezmienionego stanu. Jeżeli wystąpi niespodziewana zmiana albo test ujawni usterkę, zatrzymać dalsze próby i przedstawić wynik bez naprawiania go w ramach audytu.
5. Zweryfikować testy integracyjne niezależnie; nie utożsamiać istniejącej regresji Stage 2E ani testów concurrency z próbą bezpośredniego rollbacku licznika.
6. Usunąć wyłącznie jawnie zidentyfikowane zasoby disposable zgodnie z zatwierdzonym planem; ponownie potwierdzić brak modyfikacji aplikacyjnych baz i wolumenów.
7. Przekazać materiał niezależnemu recenzentowi do werdyktu. Tylko osobna, formalna decyzja po ocenie dowodów może zmienić status Stage 2C.

## 7. Bramki `RAW_MATERIALS` i VSEF

### Bramki, których nie należy utożsamiać

| Bramka | Bieżące ustalenie |
|---|---|
| Backend | Stage 2E.7 raportuje **DEVELOPMENT COMPLETE** dla CSV. Nie oznacza to ukończenia całego produktu ani gotowości produkcyjnej. |
| Stage 2C | **OPEN / NOT VERIFIED**; 17 testów i VEL-DEF-007 pozostają nierozstrzygnięte. |
| Stage 2D | Raport zamknięcia Stage 2D.6 określa formalne zamknięcie jako **DEVELOPMENT INCOMPLETE**, ponieważ wymagany niezależny przegląd nie został wykonany. Produkcyjny słownik alergenów i operacyjne zależności też pozostają osobnymi wymaganiami. |
| Stage 2E | Rozwój CSV 2E.1–2E.7 ukończony w zadeklarowanym zakresie; nie obejmuje importu INT/PRD/MEN, pełnej wymiany dostawców/opakowań ani Stage 2C. |
| Stage 2F | Interfejs pozostaje odrębnym etapem. Raport Stage 2B wskazuje UI jako zakres wyłączony do Stage 2F; w zbadanym worktree nie znaleziono dowodu zamknięcia Stage 2F. |
| Gotowość wydania | Niepotwierdzona. Wymaga odrębnej oceny konkretnej instalacji, ról/grantów, danych historycznych, sekretów, storage i retencji, ClamAV, kopii/odtworzenia oraz procedury wdrożenia. |
| Włączenie per lokalizacja | Osobna, jawna decyzja operacyjna. Nie wynika z zakończenia etapów developmentu i nie może omijać katalogu modułów, `NOT_READY`, uprawnień ani kontekstu lokalizacji. |

`RAW_MATERIALS` musi pozostać `NOT_READY` i nieaktywne. Nie zmieniano gotowości, nie włączano modułu dla żadnej lokalizacji i nie weryfikowano produkcyjnych żądań w ramach tego przeglądu.

### VSEF

W sprawdzonym repozytorium i materiałach Stage 2C/2D/2E nie znaleziono osobnego, autorytatywnego dokumentu „propozycji VSEF”. W raportach występuje jako nieaktywne; kontrakt CSV stwierdza, że VSEF nie jest częścią kontraktu wymiany danych. Dostępne dowody nie pozwalają stwierdzić, czy VSEF ma być standardem rozwojowo-bezpieczeństwowym, funkcją aplikacji, czy mechanizmem gotowości. Nie należy przypisywać mu żadnej z tych ról bez decyzji właściciela.

Do przyjęcia VSEF wymagane są: zlokalizowanie lub przygotowanie propozycji, decyzja właściciela i wskazanie właściciela operacyjnego, jednoznaczna definicja rodzaju VSEF, zakresu i obowiązywania, wymaganych kontroli/dowodów, relacji do Stage 2C i gotowości modułu oraz osobna decyzja o ewentualnej implementacji/aktywacji. Ten plan nie proponuje ani nie aktywuje VSEF.

## 8. Sekwencja do Stage 2C ACCEPT

1. Uzgodnić wersję decyzji dotyczącej VEL-DEF-001–006 z rejestrem właściciela; zachować odroczenie zgodnie z bieżącym zakresem, bez oznaczania ryzyk jako naprawionych.
2. Uzyskać podpisaną, konkretną autoryzację security authority dla wszystkich zakazanych operacji i wszystkich 17 metod; przed autoryzacją pozostają niewykonane.
3. Niezależnie udowodnić izolację disposable PostgreSQL 17/Redis i aktualność schematu 001–036.
4. Wykonać dokładnie autoryzowane 17 metod. Każdy brak dowodu lub niezgodność oznacza `NOT VERIFIED`/`FAIL`, nie PASS.
5. Uzyskać osobny dowód VEL-DEF-007 dla rzeczywistej roli runtime, obejmujący konkretnie ryzyko regresji/uszkodzenia licznika po migracji 035, albo osobną, wyraźną decyzję właściciela ryzyka, jeśli formalny proces dopuszcza odroczenie.
6. Niezależnie ocenić aktualny kod, call sites Laravel, migracje, RBAC, granicę podpisanych mutacji oraz efekty odmowy i stan po odmowie; rozstrzygnąć wykryte rozbieżności i defekty przed werdyktem.
7. Uzgodnić formalny wynik z właścicielem i opublikować nową decyzję akceptacyjną. Do czasu spełnienia warunków Stage 2C pozostaje bez ACCEPT.
8. Prowadzić osobno ocenę Stage 2D, Stage 2F, gotowości wydania i włączenia `RAW_MATERIALS` per lokalizacja; żadna z nich nie jest automatycznie rozstrzygnięta przez Stage 2C ACCEPT.

## 9. Zakres wykonany i niewykonany

- Utworzono wyłącznie ten dokument.
- Nie uruchomiono testów, zapytań do baz, DML/DDL, prób adwersarialnych, zmian ról ani migracji.
- Nie zmieniono kodu, konfiguracji, danych, baz, kontenerów, wolumenów ani statusów.
- Nie wykonano commitów ani pushy.
- Nie zmieniono statusu Stage 2C, `RAW_MATERIALS` ani VSEF.

**Końcowa rekomendacja:** **NO-GO dla Stage 2C ACCEPT.** Wznowienie może nastąpić dopiero po jawnej autoryzacji ograniczonych operacji i dostarczeniu zaakceptowanych, aktualnych, niezależnych dowodów.

## Źródła

- [VELTRA_STAGE_2C_SECURITY_VERIFICATION_HANDOFF.md](./VELTRA_STAGE_2C_SECURITY_VERIFICATION_HANDOFF.md)
- [VELTRA_STAGE_2C_SECURITY_VERIFICATION_CLOSURE.md](./VELTRA_STAGE_2C_SECURITY_VERIFICATION_CLOSURE.md)
- [VELTRA_STAGE_2C_FORMAL_CLOSURE_DECISION.md](./VELTRA_STAGE_2C_FORMAL_CLOSURE_DECISION.md)
- [VELTRA_STAGE_2C_FINAL_READINESS_CHECKPOINT.md](./VELTRA_STAGE_2C_FINAL_READINESS_CHECKPOINT.md)
- [VELTRA_STAGE_2E_FINAL_DEVELOPMENT_CHECKPOINT.md](./VELTRA_STAGE_2E_FINAL_DEVELOPMENT_CHECKPOINT.md)
- [backend/database/migrations/](./backend/database/migrations/)
- [SkuAllocationService.php](./backend/app/Domain/Sku/SkuAllocationService.php)
- [RawMaterialPermissionBootstrapTest.php](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php)
- [AuthorizationMutationBoundaryTest.php](./backend/tests/Feature/AuthorizationMutationBoundaryTest.php)
- [RoleAdministrationTest.php](./backend/tests/Feature/RoleAdministrationTest.php)
- [Rejestr odłożonych problemów, ryzyk i usprawnień — Google Drive](https://docs.google.com/document/d/1uU-hjzyb4DOr3ZOz6bs89gCjgBTR43Rp4-ZIS58Ps0g/edit)
