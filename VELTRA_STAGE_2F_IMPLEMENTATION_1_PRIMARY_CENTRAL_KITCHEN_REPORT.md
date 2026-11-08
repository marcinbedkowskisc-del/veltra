# VELTRA 2.0 — Stage 2F — Raport implementacji 1

## Niezmienna główna kuchnia centralna

**Zakres:** K2.8 / K3.16
**Status:** weryfikacja zakończona na izolowanym PostgreSQL 17; 32/32 focused tests PASS
**Migracja istniejącej bazy:** nieuruchomiona; migracje 001–038 wykonano wyłącznie w świeżym, efemerycznym klastrze testowym
**Wdrożenie / RAW:** bez zmian; RAW nie był aktywowany

## Podsumowanie

Dodano trwałe oznaczenie pierwszej kuchni centralnej jako znacznika na rekordzie lokalizacji oraz ochronę PostgreSQL przed zmianą, przeniesieniem lub usunięciem tej tożsamości. Przy wstawieniu kuchni centralnej trigger blokuje wiersz organizacji, sprawdza istniejące kuchnie i sam wybiera pierwszą; indeks unikalny stanowi dodatkowe zabezpieczenie przed wieloma oznaczeniami.

Bootstrap nie wybiera już lokalizacji według kodu. Używa wyłącznie zweryfikowanej głównej kuchni. Gdy nie da się jej wiarygodnie ustalić, zatrzymuje się z komunikatem o blokadzie zamiast wybierać lub tworzyć zastępczą.

## Zmienione pliki

- `backend/database/migrations/2026_11_07_000038_enforce_immutable_primary_central_kitchen.php`
- `backend/app/Console/Commands/BootstrapAuthorization.php`
- `backend/app/Models/Organization.php`
- `backend/app/Models/Location.php`
- `backend/lang/pl/messages.php`
- `backend/tests/InstallationTestCase.php`
- `backend/tests/Feature/InstallationDomainTest.php`
- `backend/tests/Feature/AccountSecurityTest.php`
- `backend/tests/Feature/PrimaryCentralKitchenConcurrencyTest.php`

## Zmiany schematu i gwarancje

- `locations.is_primary_central_kitchen` jawnie oznacza główną kuchnię; ograniczenie sprawdza, że oznaczenie dotyczy wyłącznie `CENTRAL_KITCHEN`.
- Częściowy indeks unikalny dopuszcza najwyżej jedną główną kuchnię w organizacji.
- Trigger `BEFORE INSERT` blokuje wiersz organizacji i oznacza kuchnię tylko wtedy, gdy organizacja jest nowa dla tego kontraktu i nie ma jeszcze kuchni centralnej. Kolejność kodów, nazw i UUID nie uczestniczy w wyborze.
- Trigger odrzuca zmianę oznaczenia oraz usunięcie głównej kuchni. Stan nierozstrzygniętej instalacji jest również niezmienny dla zwykłych mutacji.
- `organizations.primary_central_kitchen_unresolved` blokuje automatyczne przypisanie w instalacjach istniejących w chwili migracji. Migracja ustawia ten stan dla każdej już istniejącej organizacji, ponieważ schemat nie zapewnia zaufanej historii utworzeń ani usunięć.
- Nowe organizacje utworzone po migracji pozostają rozstrzygalne; ich pierwsza faktycznie zapisana kuchnia centralna otrzyma oznaczenie. Wycofana transakcja nie pozostawia oznaczenia i nie blokuje następnej poprawnej próby.

## Bootstrap i współbieżność

Przed zmianą bootstrap wybierał aktywną kuchnię przez `orderBy('code')`, a przy jej braku pytał o utworzenie kolejnej. Po zmianie korzysta wyłącznie z relacji do oznaczonej kuchni. Brak oznaczenia przy istniejących lub nierozstrzygniętych danych, a także nieaktywna główna kuchnia, przerywają bootstrap; nie następuje ciche przełączenie na inną lokalizację.

Blokada wiersza organizacji serializuje równoległe próby. Test współbieżności obejmuje zatwierdzenie i wycofanie pierwszej transakcji; częściowa unikalność zabezpiecza końcowy stan.

## Testy i walidacja

Dodano testy dla pierwszej i kolejnych kuchni, instalacji legacy bez ustalonego primary, odmowy mutacji SQL, bootstrapu z prawidłowym primary i bootstrapu fail-closed, a także współbieżnego zatwierdzenia/wycofania.

| Sprawdzenie | Wynik |
|---|---|
| PHP lint — 9 zmienionych plików | **PASS 9/9** |
| Pint — 9 zmienionych plików | **PASS 9/9** |
| Świeże migracje PostgreSQL 17 | **PASS 38/38**, w tym 038 |
| `InstallationDomainTest` + `PrimaryCentralKitchenConcurrencyTest` | **PASS 23 testy / 106 asercji** |
| `AccountSecurityTest` + powyższe testy | **PASS 32 testy / 155 asercji** |
| Osobny wybór primary, fail-closed, interaktywny bootstrap | **PASS 3 testy / 19 asercji** (uwzględnione w zestawie 32) |
| Rzeczywista współbieżność: commit i rollback pierwszego INSERT | **PASS 2 scenariusze** (uwzględnione w zestawie 23) |
| Runtime SQL bez podpisu i zmiana przez migrującego właściciela | **PASS** — mutacje odrzucone (uwzględnione w zestawie 23) |
| `git diff --check` | **PASS** |
| Baza / instalacyjne kontenery | **Bez zmian** |

W trakcie iteracji były nieudane przebiegi: Pint wskazał nieużywany import; testy ujawniły fixture’y z oczekującymi triggerami PostgreSQL, błąd argumentu callbacku, brak domen Sanctum w izolowanym runnerze oraz nieizolowaną asercję testową pobierającą dowolny token. Usunięto import, poprawiono wyłącznie testy/harness i konfigurację efemerycznego stacka; nie zmieniono zatwierdzonych reguł. Po poprawkach cały zestaw 32 testów przeszedł.

## Środowisko istniejące przed testami — 2026-11-08

### Ustalenia

- Katalog roboczy: `/home/veltra/projekty/veltra`; katalog główny Git jest taki sam. Zdalne repozytorium: `KLON123/veltra`.
- Dostępne polecenia: Docker 29.9.2 i Docker Compose v5.6.1.
- Aktywne usługi Compose: `backend`, `frontend`, `mailpit`, `postgres`, `queue`, `redis`. Kontenery działały przed sprawdzeniem; backend, PostgreSQL, Redis, frontend i Mailpit zgłaszały stan healthy. Nie uruchamiano ani nie restartowano usług.
- Kontener backendu `veltra-backend-1` udostępnia PHP 8.4.27 i Composer 2.11.1; katalog `vendor` istnieje.
- PostgreSQL działa jako `postgres:17-alpine`, wersja serwera 17.11.
- Backend jest skonfigurowany na `DB_HOST=postgres`, `DB_DATABASE=veltra`, `DB_USERNAME=veltra`.
- Baza `veltra_testing` istnieje, ale należy do właściciela `veltra`, tak samo jak baza instalacyjna `veltra`. Rola `veltra` ma `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `BYPASSRLS` i `LOGIN`; nie jest to ograniczone konto testowe.
- Próba sprawdzenia ról potwierdziła, że `veltra_app` nie istnieje. Nie potwierdzono istnienia ani dostępności wymaganej roli `veltra_migrator`.
- Kontener backendu nie ma zamontowanych wolumenów (`docker inspect` zwrócił `[]`), więc nie zawiera lokalnych zmian tej implementacji.
- `docker compose config --services` nie powiodło się: wymagane zmienne Compose, w tym hasła i klucz podpisywania, nie są dostępne w środowisku polecenia. Nie ujawniano ani nie odczytywano ich wartości.

### Wykonane polecenia

Polecenia rozpoznawcze uruchomiono bez modyfikowania usług lub danych:

```text
pwd
git rev-parse --show-toplevel
git remote -v
git status --short
docker --version
docker compose version
docker compose ps --all
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
docker compose config --services
docker compose images
docker exec veltra-backend-1 sh -lc 'printf "%s\n" "-- PHP --"; php -v | head -n 2; printf "%s\n" "-- Composer --"; composer --version; printf "%s\n" "-- Backend DB identity config (non-secret) --"; for n in DB_HOST DB_DATABASE DB_USERNAME TEST_DB_SETUP_USERNAME; do v=$(printenv "$n" 2>/dev/null || true); printf "%s=%s\n" "$n" "${v:-<unset>}"; done; printf "%s\n" "-- Backend working tree paths --"; pwd; test -f /var/www/html/artisan && echo artisan-present; test -d /var/www/html/vendor && echo vendor-present'
docker inspect --format '{{json .Mounts}}' veltra-backend-1
docker exec veltra-postgres-1 sh -lc 'psql -X -U "$POSTGRES_USER" -d postgres -At -F "|" -c "SELECT current_setting('\''server_version'\''); SELECT datname, pg_get_userbyid(datdba), datallowconn FROM pg_database WHERE datistemplate = false ORDER BY datname;"'
docker exec veltra-postgres-1 sh -lc 'psql -X -U "$POSTGRES_USER" -d postgres -At -F "|" -c "SELECT rolname, rolsuper, rolcreatedb, rolcreaterole, rolbypassrls, rolcanlogin FROM pg_roles WHERE rolname IN ('\''veltra'\'', '\''veltra_app'\'', '\''veltra_migrator'\'') ORDER BY rolname; SELECT datname, has_database_privilege('\''veltra_app'\'', datname, '\''CONNECT'\''), has_database_privilege('\''veltra_migrator'\'', datname, '\''CONNECT'\'') FROM pg_database WHERE datname IN ('\''veltra'\'', '\''veltra_testing'\'') ORDER BY datname;"'
```

Wynikiem `docker compose config --services` było przerwanie interpolacji z powodu brakujących zmiennych wymaganych Compose. Na tym etapie pierwotnego rozpoznania odczyt bazy systemowej PostgreSQL był wyłącznie katalogowy i nie wykonano SQL mutacji, DDL, testów ani migracji; testy uruchomiono później wyłącznie w nowym, izolowanym stacku opisanym poniżej.

### Ocena istniejącego środowiska

Istniejąca baza `veltra_testing` nie została użyta: jej właścicielem była uprzywilejowana rola instalacyjna `veltra`, brakowało wymaganych ról aplikacji i migratora, a kontener nie zawierał lokalnych zmian. Następnie przygotowano całkowicie osobny projekt Compose opisany poniżej.

## Izolowany stack i identyfikacja bazy

- Projekt Compose: `veltra-pk-verify-20261108`; usługi testowe: osobny `postgres:17-alpine`, Redis tymczasowy i runner PHP zbudowany z bieżącego źródła repozytorium. Runner użył lokalnej warstwy backendowej z istniejącymi zależnościami; sumy plików źródłowych w obrazie zgadzały się z checkoutem. Manifesty Composer były identyczne; nie instalowano zależności na hoście.
- PostgreSQL testowy miał osobny klaster i osobny nazwany wolumen `veltra-pk-verify-20261108_primary-kitchen-test-data`; jego sieć Compose `veltra-pk-verify-20261108_isolated` miała `internal=true` i nie łączyła się z siecią instalacji. `system_identifier` klastra testowego i istniejącego klastra były różne.
- Test docelowy: baza `veltra_testing` wyłącznie w nowym kontenerze testowym, PostgreSQL 17.11, `DB_HOST=postgres`. Właścicielem bazy był `veltra_test_admin`, nie runtime ani migrator. Przed migracją schemat publiczny był pusty.
- Role: `veltra_test_admin` służył wyłącznie do inicjalizacji izolowanego klastra; `veltra_migrator` był właścicielem schematu i miał wymagane prawo `CREATE` w bazie/schemacie; `veltra_app` nie miał superuser, `CREATEDB`, `CREATEROLE`, `BYPASSRLS`, praw `CREATE` do bazy ani schematu. Test harness zweryfikował też rozdzielenie `current_user` i `session_user`, właściciela tabel oraz brak praw runtime do `TRUNCATE`.
- Sekrety testowe wygenerowano losowo wyłącznie dla efemerycznego stacka i nie zapisano ich w raporcie.
- Istniejące kontenery `veltra-backend-1`, `veltra-postgres-1`, frontend, queue, Redis i Mailpit pozostały uruchomione; nie były restartowane ani rekonfigurowane.

## Wykonane komendy i wyniki

Komendy Compose używały tymczasowego pliku środowiska z syntetycznymi sekretami oraz osobnego pliku Compose w katalogu sesji. Skrót `<C>` w poniższych zapisach oznacza argumenty:
`--env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/primary-kitchen-test.env --project-directory /home/veltra/projekty/veltra -p veltra-pk-verify-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/primary-kitchen-test-compose.yaml`.

Główne polecenia:

```text
docker compose <C> config --quiet
docker compose <C> build test-runner
docker compose <C> up -d --build
docker compose <C> exec -T test-runner php -l <każdy z 9 zmienionych plików PHP>
docker compose <C> exec -T test-runner vendor/bin/pint --test <9 zmienionych plików PHP>
docker compose <C> exec -T -e DB_USERNAME=veltra_migrator -e DB_PASSWORD="$TEST_DB_MIGRATION_PASSWORD" test-runner php artisan migrate --force
docker compose <C> exec -T test-runner php artisan test --compact tests/Feature/InstallationDomainTest.php tests/Feature/AccountSecurityTest.php tests/Feature/PrimaryCentralKitchenConcurrencyTest.php
docker compose <C> exec -T test-runner php artisan test --compact tests/Feature/AccountSecurityTest.php --filter='test_interactive_bootstrap_is_recoverable_before_verification_and_closed_after_activation|test_bootstrap_uses_the_verified_primary_instead_of_location_code|test_bootstrap_fails_closed_for_an_unresolved_legacy_kitchen'
git diff --check
```

Weryfikacja SQL przed DDL odczytała `current_database()`, `current_user`, `session_user`, adres serwera i wersję PostgreSQL przez połączenie Laravel oraz sprawdziła właściciela bazy/schematu, uprawnienia ról i pusty schemat. Migracja wykonała 38/38 plików od 001 do 038. Testy finalne: 32 passed, 155 assertions, 0 failed; suite concurrency uruchomił dwie niezależne sesje PostgreSQL dla zatwierdzenia i wycofania pierwszego INSERT.

### Nieudane przebiegi i poprawki

1. Pierwsze budowanie według Dockerfile projektu próbowało pobrać zależności Composer i napotkało błędy sieciowe `curl error 28/56`. Przerwano ten build; wykorzystano lokalny obraz backendu z Composer/PHPUnit/Pint i identycznymi manifestami Composer, kopiując do nowego obrazu bieżące źródło. Nie instalowano PHP ani zależności na hoście.
2. Pint wykrył nieużywany import `Location`; usunięto go. Końcowy Pint: 9/9 PASS.
3. Pierwsze testy wykazały błędy w nowych fixture’ach: `ALTER TABLE` przy oczekujących triggerach deferred, callback bez przekazanego połączenia i fixture próbujący usunąć kuchnię primary. Poprawiono kolejność `SET CONSTRAINTS`, wywołanie callbacku i konstrukcję syntetycznej lokalizacji obcej. Brak domen Sanctum w izolowanym runnerze powodował też odmowy logowania; uzupełniono wyłącznie efemeryczną konfigurację runnera.
4. Po nieudanym fixture baza testowa miała już zmieniony stan, więc usunięto i odtworzono wyłącznie wolumen nowego projektu testowego, ponownie sprawdzono cel i wykonano świeże migracje. Nie dotknięto istniejącego `veltra_testing`.
5. Następny pełny przebieg wykazał zależność między testami tokenów: nowy test bootstrapu pozostawiał token, a istniejąca asercja czytała pierwszy token bez filtrowania e-maila. Test bootstrapu konsumuje teraz własny token, a istniejąca asercja wiąże hash z e-mailem testowanego użytkownika. Po tej korekcie pełny zestaw focused przeszedł 32/32 bez osłabienia asercji.

Odczyt w istniejącej bazie instalacyjnej był wyłącznie agregatem stanu RAW: `0` skonfigurowanych i `0` włączonych wpisów `RAW_MATERIALS`. Kod katalogu nadal ma `ModuleDefinition::$ready = false`; RAW pozostaje `NOT_READY`.

## Pozostałe blokery i ryzyka

1. **Legacy:** każda organizacja istniejąca w chwili migracji pozostaje nierozstrzygnięta. Przed bootstrapem wymagany jest osobny, zatwierdzony przegląd wiarygodnych dowodów historycznych i ewentualna odrębna migracja forward-only. Nie wolno wskazywać kuchni na podstawie kodu, UUID ani niepotwierdzonego czasu.
2. **Wynik:** **PASS dla weryfikacji technicznej zakresu K2.8/K3.16 na świeżym PostgreSQL 17.11.** Nie jest to wdrożenie ani zgoda na zastosowanie migracji do istniejącej instalacji.
3. **Niezależny przegląd bezpieczeństwa:** nadal zalecany przed wdrożeniem, szczególnie triggerów, właścicielstwa migracji i współbieżności.
4. **Dezaktywacja a RAW:** ta implementacja nie dodaje przepływów K3.17/K3.18. Nie zmieniono gotowości ani aktywacji; odczyt stanu instalacji i definicji potwierdził `NOT_READY`.
5. **Baza instalacyjna:** nie uruchomiono na niej testów ani migracji; wykonano tylko odczyt agregatów potwierdzających brak aktywacji RAW.

Nie zmieniono istniejących migracji, konfiguracji ani ustawień modułów. Nie wykonano deploymentu, restartu istniejących usług, commitu ani pushu.
