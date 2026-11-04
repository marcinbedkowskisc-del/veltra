# VELTRA 2.0 — Niezależny re-audyt bezpieczeństwa Stage 2C (migracja 018)

**Data audytu:** 4 listopada 2026  
**Zakres:** zabezpieczenia migration 018 i rozdzielenie roli PostgreSQL runtime/migracyjnej  
**Werdykt:** **REJECT**

## Podsumowanie

Migracja 018 poprawnie zatrzymuje upgrade bazy z aktywnym historycznym duplikatem grantów i nie zmienia wtedy grantów ani historii audytowej. Na świeżej bazie zachowuje prawidłowe granty. Rola runtime nie jest superuserem ani właścicielem produkcyjnej bazy, a bezpośredni replay cofniętego grantu, także równoległy, został odrzucony.

Nie można jednak zaakceptować całej remediacji:

1. Usługa migracyjna używa `POSTGRES_USER`, który w testowanym obrazie PostgreSQL jest superuserem z prawami `CREATEDB` i `CREATEROLE`; nie jest to rola ograniczona do wymaganych migracji.
2. Rola runtime ma bezpośrednie prawo `INSERT` do append-only rejestru użyć operacji i potrafi dopisać niepowiązany, dowolny wpis. Ograniczenia UPDATE/DELETE i brak TRUNCATE nie blokują takiego dopisania.

Wyniki wskazują, że zasadniczy wektor odtworzenia cofniętego uprawnienia przez replay jest zamknięty. Pozostają jednak naruszenie integralności rejestru oraz zbyt szerokie uprawnienia ścieżki migracyjnej. Audyt nie naprawiał tych ustaleń.

## Ustalenia

### M018-01 — Rola migracyjna jest superuserem

**Severity:** 🟡 MEDIUM  
**Confidence:** 10/10  
**Status:** otwarte

Profil `migrations` przekazuje do kontenera `POSTGRES_USER` i `POSTGRES_PASSWORD`, a przykład konfiguracji ustawia `POSTGRES_USER=veltra` ([compose.yaml](./compose.yaml#L83), [.env.example](./.env.example#L6)). Oficjalna inicjalizacja obrazu PostgreSQL tworzy konto `POSTGRES_USER` jako superuser. Niezależny odczyt katalogu ról w disposable PostgreSQL potwierdził dla `veltra_admin` odpowiednio `rolsuper=true`, `rolcreatedb=true` i `rolcreaterole=true`; była to również właścicielska rola bazy.

Rola migracyjna jest oddzielona od runtime, ale nie ma ograniczeń „tylko wymagane do kontrolowanych zmian schematu”. Użycie jej poświadczeń pozwala na działania administracyjne wykraczające poza migracje i kontrolę pojedynczej bazy. To tworzy szerszy skutek w razie wycieku sekretu lub uruchomienia nieautoryzowanego kodu w jednorazowym kontenerze migracyjnym.

**Dowód:** w izolowanym PostgreSQL 17 zapytanie do `pg_roles` wykazało `veltra_admin:true:true:true`; backend runtime używał innego loginu.

### M018-02 — Runtime może dopisywać dowolne wpisy do append-only ledger

**Severity:** 🟡 MEDIUM  
**Confidence:** 10/10  
**Status:** otwarte

Provisioning przyznaje `SELECT, INSERT, UPDATE, DELETE` do wszystkich tabel schematu `public`, także do `raw_material_permission_grant_operation_uses` ([10-create-runtime-role.sh](./docker/postgres/10-create-runtime-role.sh#L24)). Trigger migracji 017 ogranicza UPDATE/DELETE, ale nie waliduje samodzielnych INSERT-ów do ledger ([migracja 017](./backend/database/migrations/2026_11_03_000017_prevent_permission_bootstrap_replay.php#L11)).

Bezpośredni test z rzeczywistymi poświadczeniami `veltra_app` potwierdził `INSERT=true`, `UPDATE=true`, `DELETE=true`. Wstawienie rekordu z istniejącą organizacją, losowym operation ID i wartością `FORGED_LEDGER_ENTRY` powiodło się; transakcję wycofano po pomiarze, aby nie pozostawić artefaktu. Trigger odrzucił osobne próby UPDATE i DELETE, a brak prawa TRUNCATE i własności tabeli uniemożliwił obejście ich przez usunięcie rejestru lub wyłączenie triggera.

Nie wykazano, że samo dopisanie takiego rekordu odtwarza uprawnienie. Jest to jednak możliwość fałszowania append-only rejestru przez konto runtime i jego potencjalnego zanieczyszczania lub zatruwania, co nie spełnia wymogu ochrony ledger przed manipulacją.

## Zweryfikowane zabezpieczenia

- **Aktywny historyczny duplikat:** na bazie z dwoma aktywnymi grantami o tej samej organizacji, operation ID i permission migracja 018 przerwała się z `SQLSTATE 23514` i komunikatem wymagającym ręcznego przeglądu.
- **Atomiczność odmowy:** po błędzie licznik migracji 018 pozostał `0`, funkcja kontrolna utworzona w migracji została wycofana, a stan pozostał: 3 wiersze grantów, 0 zdarzeń cofnięcia, 1 historyczny audit event i 2 wpisy ledger. Oba zduplikowane granty nadal były aktywne; migracja niczego nie naprawiła automatycznie.
- **Prawidłowe granty historyczne:** upgrade 001–017 → 018 zakończył się pomyślnie. Dwa granty, jeden `PERMISSION_BOOTSTRAP` audit event i dwa wpisy ledger zachowały identyfikatory oraz treść. Powtórne `migrate` zwróciło `Nothing to migrate`.
- **Świeże instalacje:** migracje 001–018 przeszły zarówno na głównej disposable bazie uruchomionej rolą migracyjną, jak i na disposable bazie testowej uruchomionej rolą runtime.
- **Rola runtime:** na bazie aplikacyjnej `veltra_app` miała `rolsuper=false`, `rolcreatedb=false`, `rolcreaterole=false`, `rolreplication=false`; nie była właścicielem bazy (właścicielem był `veltra_admin`), nie miała `CREATE` w bazie/schemacie ani członkostwa w roli migracyjnej.
- **Odmowa operacji uprzywilejowanych:** rzeczywiste poświadczenia runtime nie mogły wykonać `TRUNCATE` na ledger, wyłączyć append-only triggera, tworzyć tabeli ani wykonać `SET ROLE veltra_admin`. UPDATE/DELETE ledger odrzucono przez trigger.
- **Replay po cofnięciu:** po utworzeniu `REVOKED` eventu w disposable bazie próba INSERT z tym samym operation ID i permissionem została odrzucona przez PostgreSQL (`23514`). Nie powstał nowy grant ani audit event. Próba ponownego użycia operation ID z innym permissionem również została odrzucona; nie powstał odpowiadający jej wpis ledger.
- **Równoległy replay:** dwa jednoczesne inserty z tym samym operation ID i permissionem, wykonane poświadczeniami runtime, oba zakończyły się odmową `23514`; liczba nowych grantów wyniosła 0, a istniejący wpis ledger pozostał pojedynczy.
- **Uprawnienia i historia RBAC:** focused suite sprawdził wielostopniowe cofanie authority, idempotencję upgrade’u oraz audit integralność.
- **Rollback migracji 018:** `migrate:rollback --step=1` został jawnie odrzucony przez forward-only `down()`. Wpis migracji pozostał zapisany, a dwa granty i audit event nie uległy zmianie. Cofnięcie wymaga odtworzenia bazy lub nowej migracji naprawczej.

Testowe `REVOKED` eventy i próby SQL były wykonywane wyłącznie na disposable bazie. Nie aktywowano `RAW_MATERIALS` w instalacji aplikacyjnej ani nie zmieniano istniejących zasobów.

## Testy wykonane niezależnie w tym audycie

Zasoby testowe: PostgreSQL 17 i Redis 7.4 w sieci Docker `internal=true`; katalog danych obu usług wyłącznie na `tmpfs`, bez opublikowanych portów i bez trwałych wolumenów. Istniejące kontenery aplikacyjne nie były używane do testów ani modyfikowane.

| Test | Wynik |
|---|---:|
| `RawMaterialPermissionBootstrapTest.php` | **10 testów, 102 asercje — PASS** |
| Pełna regresja backendu (`composer test`) | **176 testów, 1456 asercji — PASS** |
| Świeże migracje 001–018 | **PASS** |
| Upgrade 001–017 z prawidłowymi grantami do 018 | **PASS**, dane i identyfikatory zachowane |
| Upgrade 001–017 z aktywnym historycznym duplikatem | **PASS**, bezpiecznie przerwany; bez zmian danych |
| Rerun migracji po udanym 018 | **PASS**, `Nothing to migrate` |
| Rerun rollback 018 | **PASS**, jawna odmowa i zachowany wpis migracji |
| Bezpośredni replay po cofnięciu, replay innego permissionu i wyścig dwóch replayów | **PASS**, wszystkie odrzucone; szczegóły powyżej |
| PHP syntax: migracje 017/018, focused test, `scripts/init-env.php` | **PASS** |
| Laravel Pint dla tych plików | **PASS** |
| `git diff --check` | **PASS** |
| Redis disposable | **PONG** |

Wyniki historyczne raportów Stage 2C nie są wliczone do tych liczb. Powyższe testy uruchomiono ponownie w ramach tego audytu.

## Ryzyka wdrożeniowe dla istniejących instalacji

1. Skrypt `docker-entrypoint-initdb.d` wykonuje się tylko przy inicjalizacji pustego wolumenu. Nie utworzy roli `APP_DB_USERNAME` ani jej grantów w istniejącym wolumenie.
2. Po zmianie Compose backend oczekuje nowych `APP_DB_USERNAME` / `APP_DB_PASSWORD`; istniejący `.env` może nie zawierać hasła runtime, a istniejąca baza może nie znać nowej roli. Bez jawnego provisioningu backend utraci połączenie z bazą. Skrypt `init-env.php` odmawia nadpisania istniejącego `.env`, więc zmiana poświadczeń wymaga kontrolowanej aktualizacji konfiguracji i bazy.
3. Default privileges PostgreSQL są powiązane z bazą i rolą tworzącą obiekty. Każda dodatkowa baza (np. testowa) wymaga własnego, poprawnego provisioningu; utworzenie loginu na poziomie klastra nie nadaje automatycznie wymaganych praw do wszystkich baz.
4. Profil migracyjny nadal korzysta z superusera. Nie należy traktować tego loginu jako ograniczonej roli migracyjnej ani udostępniać jego sekretu procesom runtime.
5. Migracja 018 jest forward-only. Aktywne duplikaty wymagają ręcznej decyzji operatora i blokują dalszy upgrade; automatyczna korekta nie jest wykonywana.

## Ograniczenia audytu

Audyt był read-only względem kodu aplikacji i istniejącej konfiguracji. Utworzono wyłącznie ten raport. Nie wykonano live migrations, deploymentu, commitów, pushy, resetów ani rebase’ów. Nie zmieniano istniejących baz, kontenerów ani wolumenów. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne; Stage 2D nie rozpoczęto.

## Werdykt

# **REJECT**

Migracja 018 zamyka potwierdzony scenariusz replay i fail-closed działa atomowo, ale konto runtime nadal może dopisywać niezweryfikowane wpisy do chronionego ledger, a konto używane do migracji pozostaje PostgreSQL superuserem z dodatkowymi uprawnieniami administracyjnymi. Wymagany jest dalszy, zatwierdzony zakres naprawczy oraz ponowna niezależna weryfikacja przed akceptacją.
