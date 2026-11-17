# VELTRA 2.0 — Stage 1A–1D

Uruchamialny fundament modularnego monolitu: Laravel REST API, osobny frontend
React + TypeScript + Vite, PostgreSQL oraz Redis jako cache i kolejka.
Stage 1B dodaje jedna organizacje instalacji, lokalizacje, historie zarzadzania
i uprawnienie do zaopatrywania restauracji. Stage 1C dodaje konta, sesje
logowania i niezalezne przypisania do lokalizacji. Stage 1D dodaje szesc rol,
autoryzacje zakresowa, delegacje, aktywacje e-mail i reset hasla przez przelozonego.
Bez dostaw, magazynu, zamowien ani ekranow administracyjnych.

Architektura **single-tenant**: jeden klient komercyjny = osobna instalacja
i osobna baza PostgreSQL. Organizacja nie jest tenantem wspoldzielonej platformy.
Nie ma wyboru organizacji ani dostepu miedzy instalacjami.

## Wymagania

- Ubuntu WSL2 z dzialajacym Docker Desktop i integracja WSL.
- Docker Engine i Docker Compose v2 lub nowszy.
- Dostep do rejestrow obrazow, Packagist i npm podczas pierwszego budowania.
- Wolne porty `8000` (API), `5173` (frontend) i `8025` (lokalna poczta).

PHP, Composer, Node i npm dzialaja **wylacznie w kontenerach**; nie trzeba
instalowac ich w Ubuntu. Jest to srodowisko lokalne, nie konfiguracja produkcyjna:
korzysta z serwerow developerskich Laravel i Vite oraz zaleznosci developerskich.

## Pierwsze uruchomienie

Primary korzysta z osobnego projektu `veltra-primary` oraz zewnetrznych
wolumenow `veltra-primary-postgres-data` i `veltra-primary-redis-data`.
Ponizsze polecenia wskazuja jawnie Docker Desktop, projekt i oba pliki Compose.
Takiego samego wskazania nalezy uzywac rowniez w pozostalych przykladach
`docker compose` dotyczacych Primary; bez overlayu odnosza sie do innego
projektu i nie zapewniaja opisanej ochrony wolumenow.

W katalogu glownym repozytorium, po upewnieniu sie, ze wymienione wolumeny
jeszcze nie istnieja i nie naleza do innej instalacji:

```bash
docker --context default version
docker --context default compose version

# Utworzenie ignorowanego przez Git .env z losowym haslem i kluczem aplikacji.
# Polecenie nie nadpisuje istniejacego pliku.
docker --context default run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/app" -w /app composer:2 php scripts/init-env.php

docker --context default volume create --label com.veltra.environment=primary --label com.veltra.storage=postgres veltra-primary-postgres-data
docker --context default volume create --label com.veltra.environment=primary --label com.veltra.storage=redis veltra-primary-redis-data

docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml build backend frontend
docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml up -d --wait postgres redis
docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml run --rm --no-deps migrate
docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml up -d --wait
docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml ps
```

Inicjalizator odmawia nadpisania istniejacego `.env`. Jesli plik juz istnieje,
zachowaj go i bezpiecznie uzupelnij brakujace `APP_DB_PASSWORD`,
`APP_DB_MIGRATION_PASSWORD` i `APP_DB_MUTATION_SIGNING_KEY` niezaleznymi
wartosciami, stosujac jak w inicjalizatorze `bin2hex(random_bytes(32))`.
Nie ujawniaj sekretow; plik powinien nalezec do uzytkownika i miec prawa
`0600`. Wolumeny sa zewnetrzne: Compose nie tworzy ani nie usuwa ich w ramach
cyklu zycia projektu. Nie uruchamiaj `docker compose down -v`.

Pierwsze budowanie kompiluje rozszerzenia PHP i moze potrwac kilka minut.
Gotowosc mozna sprawdzic przez `docker --context default compose -p
veltra-primary -f compose.yaml -f compose.primary.yaml ps`.

- Frontend: <http://localhost:5173>
- API health: <http://localhost:8000/api/health>
- API przez proxy frontendu: <http://localhost:5173/api/health>

Compose uruchamia `backend`, `frontend`, `postgres`, `redis`, `queue` i `mailpit`.
Skrzynka developerska: <http://localhost:8025>; SMTP tylko wewnatrz sieci Compose.
PostgreSQL i Redis nie publikuja portow na hoscie. API i frontend nasluchuja
tylko na `127.0.0.1`. Worker korzysta z Redis; nie potrzebuje tabeli `jobs`.
Nieudane zadania sa zapisywane przez sterownik `file` Laravela w kontenerze
workera (dane diagnostyczne nietrwale; Stage 1A nie dodaje zadan biznesowych).

Migracja Stage 1B tworzy cztery tabele domenowe oraz wymaga rozszerzenia
PostgreSQL `btree_gist`. Uzytkownik z lokalnego obrazu PostgreSQL ma wymagane
uprawnienia; poza tym srodowiskiem rozszerzenie powinien przygotowac administrator.
Migracje sa jawne, a nie wykonywane automatycznie przy kazdym starcie kontenera.
Nie sa dodawane przykladowe dane ani organizacja.
Migracja Stage 1C dodaje `users` i `location_memberships`, zachowujac
ograniczenia oraz historie zarzadzania Stage 1B.

Backend i worker lacza sie jako nieuprzywilejowana rola `APP_DB_USERNAME`.
Migracje wykonuje oddzielna, nie-superuzytkownikowa rola `APP_DB_MIGRATION_USERNAME`,
wlasciciel schematu i obiektow aplikacji; ma tylko `CONNECT` i `CREATE` w bazie
aplikacji i nie jest jej wlascicielem.
`POSTGRES_USER` pozostaje kontem bootstrap/administrowania i nie jest
przekazywany kontenerom aplikacji ani usludze migracji. Konto runtime ma tylko
odczyt rejestru uzyc operacji uprawnien. Wpisy do niego dopisuje trigger
uruchamiany przez PostgreSQL podczas autoryzowanego bootstrapu; rola runtime
nie ma prawa `EXECUTE` do funkcji `SECURITY DEFINER` i nie moze podpiac jej
do wlasnej tabeli tymczasowej. Migracja 022 wymaga podpisanego, krotko waznego
kontekstu transakcji dla wszystkich mutowalnych tabel aplikacji. Tabele
referencyjne walut i rejestr operacji sa tylko do odczytu dla runtime; nowe
obiekty nie otrzymuja domyslnie praw DML.

Testy backendu korzystaja z osobnego [Compose testowego](compose.testing.yaml)
i projektu `veltra-test-application`. PostgreSQL i Redis przechowuja dane
wylacznie w `tmpfs`, a uslugi sa podlaczone tylko do wewnetrznej sieci testowej.
W glownym Compose nie ma juz profili `backend-test`/`migrate-test`, ktore
wspoldzielily siec i PostgreSQL instalacji. PHPUnit laczy sie jako ograniczona
rola `veltra_app`; migracje i jawne fixture'y korzystaja z roli
`veltra_migrator`. Testy koncza sie bledem, jesli role, baza lub wymagane dane
setup nie zgadzaja sie z konfiguracja. Nie uzywaj sekretow instalacji.
Compose testowy wymaga pliku `tests/compose/test.env` zawierajacego wylacznie
syntetyczne zmienne `TEST_*`; wrapper sprawdza jego
zawartosc i nie dziedziczy `.env`, `COMPOSE_*` ani `DOCKER_*`.
Skrypt PostgreSQL przygotowuje role i granty dla nowej, nietrwalej bazy.
Skrypty
`docker-entrypoint-initdb.d` nie sa uruchamiane ponownie na istniejacym
wolumenie. Przy istniejacej instalacji administrator musi jawnie uzupelnic
`.env` o `APP_DB_MIGRATION_USERNAME` i `APP_DB_MIGRATION_PASSWORD`, a nastepnie
— po kopii zapasowej i w zatwierdzonym oknie serwisowym — odtworzyc kontener
PostgreSQL z nowymi zmiennymi i uruchomic skrypt provisioningu dla biezacej
bazy:

```bash
docker compose up -d --no-deps --force-recreate postgres
docker compose exec -T postgres sh /docker-entrypoint-initdb.d/10-create-runtime-role.sh
```

Skrypt tworzy role migracyjna i przenosi wlasnosc obiektow schematu `public`
wylacznie biezacej bazy na te role. Nie uruchamiaj go na wspoldzielonej bazie
ani bez uprzedniej weryfikacji konfiguracji. Dopiero po provisioningu mozna
uruchomic jawna migracje. Sama edycja `.env` nie zmienia ról ani hasel w bazie.

Przed migracjami 022–023 na istniejacej bazie przeprowadz udokumentowany
przeglad historii autoryzacyjnej i audytowej. Samo ustawienie zmiennej
atestacyjnej nie dowodzi poprawnosci danych i nie zastepuje przegladu. Zapisz
poza migrowana baza: zakres i czas migawki, wyniki zapytan kontrolnych,
wykryte rozbieznosci, decyzje o ich rozstrzygnieciu oraz zatwierdzenie drugiej
osoby. Migracja odrzuca niespojnosci wykrywane przez constrainty i walidatory;
nie potrafi kryptograficznie potwierdzic prawdziwosci dawnego audytu.

Wymagany jest rowniez niezalezny 32-bajtowy klucz podpisujacy mutacje DB,
zapisany jako 64 znaki hex w `APP_DB_MUTATION_SIGNING_KEY`. Wygeneruj go
osobno od `APP_KEY`, przechowuj w pliku `.env` z prawami `0600` i nie umieszczaj
go w logach ani repozytorium:

```bash
openssl rand -hex 32
```

Skrypt `scripts/init-env.php` wypełnia tę zmienną niezależnym losowym kluczem
podczas tworzenia nowego pliku `.env`; powyższe polecenie służy do ręcznego
uzupełnienia istniejącej konfiguracji lub rotacji klucza.

Przed pierwsza migracja chroniona zmienna musi byc ustawiona we wszystkich
procesach backendu/workerow i w procesie migracji. Rotacja wymaga okna serwisowego:
wstrzymaj zapisy backendu i kolejki, wygeneruj nowy klucz i ustaw go w `.env`.
Nastepnie uruchom polecenie rola migracyjna, z nowym kluczem juz dostepnym
wszystkim procesom:

```bash
docker compose run --rm --entrypoint php migrate artisan veltra:rotate-database-mutation-signing-key
```

Wdroż nowa konfiguracje do wszystkich procesow, zweryfikuj chronione zapisy
i dopiero potem wznow ruch. Nie zmieniaj samego `APP_KEY` jako sposobu rotacji
klucza DB. W razie niepowodzenia nie mieszaj starych i nowych sekretow;
przywroc zgodna para aplikacja–DB i ponow polecenie migracyjne z tym samym
kluczem. Polecenie nie wypisuje sekretu i odmawia pracy bez roli migracyjnej.

## Macierz uprawnień PostgreSQL runtime

Rola runtime nie jest właścicielem bazy, schematu ani obiektów i nie ma
`SUPERUSER`, `CREATEDB`, `CREATEROLE`, członkostwa w innych rolach ani prawa
`CREATE` w schemacie `public`. Nie ma dostępu do schematu `veltra_private`.
`TRUNCATE` jest odebrane na wszystkich tabelach.

| Tabele | Uprawnienia runtime | Ochrona i granica |
|---|---|---|
| `organizations`, `locations`, `location_management_assignments`, `location_supply_relationships`, `users`, `location_memberships`, `role_assignments`, `security_events`, `membership_requests`, `permission_grants`, `report_shares`, `activation_tokens`, `password_reset_tokens`, `account_email_deliveries`, `location_module_states`, `raw_material_revision_approval_events`, `raw_material_audit_events`, `supplier_product_revisions`, `supplier_offer_events`, `supplier_offer_fx_normalization_events`, `organization_exchange_rate_events`, `raw_material_reference_price_events`, `raw_material_financial_restatements`, `raw_materials_commercial_idempotency`, `raw_materials_commercial_audit_events`, `organization_permission_grants`, `organization_permission_grant_events`, `raw_material_permission_upgrade_operations` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` | Trigger PostgreSQL wymaga krótkotrwałego podpisu ograniczonego do zamkniętego katalogu scope’ów. Nie zastępuje on autoryzacji biznesowej Laravel. |
| `organization_suppliers`, `supplier_products`, `supplier_offers`, `raw_material_categories`, `raw_material_code_counters`, `raw_materials`, `raw_material_revisions`, `raw_material_revision_units`, `raw_material_aliases` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` | Bez triggera podpisu. Autoryzację zapewniają serwisy domenowe Laravel, a baza ogranicza spójność i integralność danych. Posiadacz samych poświadczeń runtime może bezpośrednio modyfikować te tabele; jest to jawne ryzyko rezydualne, nie gwarancja ochrony przed SQL injection. |
| `raw_material_permission_grant_operation_uses`, `raw_materials_currency_codes` | `SELECT` | Zapis, aktualizacja, usuwanie i `TRUNCATE` niedozwolone. |
| `migrations` | Brak | Wyłącznie rola migracyjna zarządza wersją schematu. |

Rola runtime otrzymuje `USAGE`, `SELECT` i `UPDATE` wyłącznie na sekwencjach
dozwolonych przez listę runtime, bez sekwencji `migrations`. Provisioning nie
przywraca runtime prawa właściciela ani `TRUNCATE`.

Jesli ktorakolwiek chroniona tabela zawiera dane, migracja przerwie sie
atomowo bez recznego potwierdzenia. Po ukonczeniu przegladu ustaw zmienna
wylacznie dla jednorazowego uruchomienia `migrate` i podstaw rzeczywista nazwe
bazy:

```bash
APP_DB_AUTHORIZATION_HISTORY_ATTESTATION="REVIEWED:veltra:2026_11_05_000022" \
  docker compose run --rm migrate
```

Wartosc musi odpowiadac dokladnie nazwie bazy i migracji. Migracja zapisuje
identyfikator potwierdzenia i liste tabel zawierajacych dane w schemacie
`veltra_private`, ale nie traktuj tego wpisu jako dowodu prawidlowosci historii.
Zachowaj zewnetrzny protokol przegladu i po migracji usun atestacje z powloki.
Pusta nowa instalacja zapisuje brak danych historycznych. Migracja 023 zachowuje
podpisane ograniczenia tylko dla krytycznych tabel RBAC/audytu; dla pozostalych
tabel aplikacja korzysta z autoryzacji domenowej Laravel i integralnosci DB.

## Konfiguracja

- [`.env.example`](.env.example): parametry Compose; puste miejsca na sekrety.
- [`backend/.env.example`](backend/.env.example): dokumentacja konfiguracji Laravel.
- [`frontend/.env.example`](frontend/.env.example): publiczny URL API i cel proxy Vite.
- `APP_DB_USERNAME` i `APP_DB_PASSWORD`: osobne dane logowania runtime; nie
  uzywaj ich jako roli migracyjnej ani administratora.
- `APP_DB_MIGRATION_USERNAME` i `APP_DB_MIGRATION_PASSWORD`: osobna rola
  nie-superuzytkownikowa uzywana tylko przez jawne migracje i testy.
- `APP_DB_MUTATION_SIGNING_KEY`: niezalezny 32-bajtowy sekret, 64 znaki hex,
  uzywany wylacznie do ochrony wybranych mutacji PostgreSQL przed samymi
  poświadczeniami runtime DB. Nie chroni przed pelnym przejeciem procesu Laravel.
- `APP_DB_AUTHORIZATION_HISTORY_ATTESTATION`: jednorazowe potwierdzenie
  recznego przegladu danych istniejacej bazy przed migracja 022; pozostaw puste
  poza kontrolowanym uruchomieniem migracji.

Compose przekazuje konfiguracje bezposrednio do Laravel; nie trzeba tworzyc
`backend/.env` ani `frontend/.env`. Wygenerowany glowny `.env` zawiera sekrety
lokalnego srodowiska, ma uprawnienia `0600` i jest ignorowany przez Git.
Obraz backendu zawiera pusty techniczny `.env`, aby loader Laravel nie zglaszal
ostrzezen o brakujacym pliku; zadne sekrety nie sa kopiowane do obrazu.
Nie publikuj go ani wyniku `docker compose config` bez `--quiet`.
Nie umieszczaj sekretow w zmiennych `VITE_*` — sa widoczne w przegladarce.

Porty mozna zmienic w glownym `.env` przez `BACKEND_PORT` i `FRONTEND_PORT`.
Po zmianie konfiguracji wykonaj `docker compose up -d`.
Haslo w istniejacym wolumenie PostgreSQL nie zmienia sie automatycznie po edycji
`.env`: nie generuj ponownie konfiguracji dla istniejacej bazy.

Kod aplikacji jest kopiowany do obrazow, nie montowany z hosta. Po zmianie kodu,
testow lub lockfile wykonaj ponownie `docker compose up -d --build`.
Lockfile Composer i npm sa czescia repozytorium.

## Kontrakt health

`GET /api/health` wykonuje `SELECT 1` na PostgreSQL i `PING` do Redis:

```json
{
  "app": "VELTRA 2.0",
  "status": "ok",
  "checks": {
    "postgresql": "up",
    "redis": "up"
  }
}
```

HTTP `200` oznacza sprawne zaleznosci. Niedostepnosc ktorejkolwiek z nich oznacza
HTTP `503`, `status: "degraded"` i odpowiedni wpis `down`. Szczegoly bledow sa
logowane na stderr, ale nie sa ujawniane w odpowiedzi API. Wynik nie jest
cache'owany. Endpoint jest publiczny i nie wymaga sesji ani uwierzytelnienia.
Kontenery PHP maja ograniczony czas i liczbe prob DNS, a serwer developerski API
korzysta z czterech workerow, aby awaria zaleznosci nie blokowala innych zapytan.

Frontend pokazuje sprawdzanie, poprawny status, awarie zaleznosci lub blad
polaczenia/niepoprawnej odpowiedzi. Przycisk pozwala ponowic sprawdzenie.
Zadanie HTTP ma limit 10 sekund. Proxy `/api` zapewnia ten sam origin; CORS
nie jest potrzebny w tym srodowisku.

## Testowanie

Przygotuj ignorowany plik z syntetycznymi danymi testowymi; nie kopiuj wartosci
z `.env` ani pliku Stage:

```bash
cp tests/compose/test.env.example tests/compose/test.env
# Ustaw TEST_APP_KEY, TEST_MUTATION_SIGNING_KEY i trzy TEST_*_PASSWORD
# na losowe, syntetyczne wartosci.
```

TEST wymaga zarejestrowanego kontekstu `veltra-test-isolated` i dzialajacego
rootless daemona. Wrapper zawsze wybiera ten kontekst jawnie i sprawdza jego
socket, etykiete daemona oraz Docker Root Dir; nie uzywaj `docker context use`.
Kontekst jest przechowywany w prywatnym katalogu
`~/.config/veltra-test/docker`, niezaleznym od konfiguracji Docker Desktop.
Brak lub niezgodnosc kontekstu zatrzymuje polecenie przed operacja na zasobach.

```bash
# Start testowych zaleznosci i migracje pustej bazy w tmpfs.
scripts/compose-test up -d postgres redis clamav
scripts/compose-test run --build --rm migrate-test

# Testy aplikacji i logi.
scripts/compose-test run --build --rm backend-test composer test
scripts/compose-test ps
scripts/compose-test logs postgres redis clamav

# Reset bazy: usun zweryfikowany projekt TEST, po czym uruchom go ponownie.
scripts/compose-test-clean --target application --env-file tests/compose/test.env
scripts/compose-test-clean --target application --env-file tests/compose/test.env \
  --execute --confirm-project veltra-test-application
scripts/compose-test up -d postgres redis clamav
scripts/compose-test run --build --rm migrate-test
```

Wszystkie powyzsze polecenia operujace na kontenerach wymagaja izolowanego
kontekstu. `scripts/compose-test config --quiet` oraz
`python3 scripts/compose_test_cleanup.py --target application --env-file tests/compose/test.env --validate-only`
sprawdzaja konfiguracje bez laczenia z daemonem.

Uruchamiaj testy przez wrapper — nie przez bazowy `docker compose`:

```bash
# Izolowane przypadki health oraz testy z prawdziwym PostgreSQL i Redis.
scripts/compose-test run --build --rm backend-test php artisan test --filter=Health

# Cala podstawowa suita Laravel.
scripts/compose-test run --build --rm backend-test composer test

# Testy Stage 1B: domena, API, rzeczywiste ograniczenia PostgreSQL i wspolbieznosc.
scripts/compose-test run --build --rm backend-test php artisan test --filter='Installation|ManagementConcurrency'

# Testy Stage 1C: sesje, CSRF, konta, przypisania i interaktywne CLI.
scripts/compose-test run --build --rm backend-test php artisan test --filter='Identity|Membership'

# Format PHP oraz walidacja metadanych zaleznosci.
scripts/compose-test run --build --rm backend-test vendor/bin/pint --test
scripts/compose-test run --build --rm backend-test composer validate --strict

# TypeScript, build Vite i lint frontendu.
docker compose exec -T frontend npm run build
docker compose exec -T frontend npm run lint

# Kontrola Compose testowego bez wypisywania sekretow.
scripts/compose-test config --quiet

curl --fail-with-body http://localhost:8000/api/health
curl --fail-with-body http://localhost:5173/api/health
```

`scripts/compose-test` ustala projekt, katalog, plik env i Compose jawnie,
usuwa odziedziczone zmienne Docker/Compose oraz blokuje polecenia teardown.
Testy integracyjne korzystaja z osobnego PostgreSQL i Redis w `tmpfs`; reset
fixture'ow moze dotyczyc tylko tej bazy. Testy wspolbieznosci uruchamiaj
sekwencyjnie, nie przez `--parallel`.

`HealthTest` sprawdza poprawna odpowiedz, awarie PostgreSQL, Redis, obu zaleznosci,
nieprawidlowy PING oraz logowanie bledow i brak szczegolow wyjatkow w JSON.
`HealthIntegrationTest` wymaga uruchomionych uslug Compose: sprawdza prawdziwe
polaczenia, cache Redis, dostepnosc kolejki oraz brak tabel infrastrukturalnych
`sessions`, `cache`, `jobs`. Tabela `users` nalezy od Stage 1C do domeny
tozsamosci. Test nie wymaga pustego schematu `public`.
Testy nie uruchamiaja migracji i usuwaja swoj losowy klucz cache.

Regresja przeslaniania walidatora uprawnien przez `pg_temp` musi byc uruchomiona
z rzeczywistym kontem runtime, aby testowac efektywne ograniczenia PostgreSQL:

```bash
scripts/compose-test run --build --rm backend-test \
  php artisan test \
  --filter=test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables
```

Profile testowe B.1/B.2/B.3/B.4.1 wymagaja osobnej konfiguracji Compose;
nie uruchamiaj ich na projekcie Stage. Cleanup wrapper usuwa tylko kontenery i
siec zweryfikowanego projektu, bez `--volumes` i `--remove-orphans`.

Migracja `021` blokuje zapisy grantow podczas rekonsyliacji ledgeru. Jesli
wykryje osierocone wpisy, duplikaty lub granty bez zgodnej historii audytowej,
zatrzymuje sie bez zmian i wymaga recznego przegladu danych przed ponowieniem.

Testy wspolbieznosci uruchamiaja osobny proces PHP z niezaleznym polaczeniem
PostgreSQL. Sprawdzaja rzeczywisty stan oczekiwania `Lock` w `pg_stat_activity`,
a nastepnie rezultat po COMMIT i ROLLBACK pierwszej transakcji: zarowno pierwsze
przypisanie, jak i zmiane zarzadzajacej kuchni z zachowaniem historii.

Opcjonalny rzeczywisty test awarii lokalnego Redis:

```bash
docker compose stop redis
curl -i http://localhost:8000/api/health
# Oczekiwane HTTP 503 i checks.redis=down.
docker compose start redis
docker compose up -d --wait --wait-timeout 120
```

## Zatrzymanie i ponowne uruchomienie

```bash
# Zatrzymuje kontenery, zachowuje je oraz dane.
docker compose stop
docker compose start

# Usuwa kontenery i siec, zachowuje wolumeny PostgreSQL i Redis.
docker compose down
docker compose up -d
```

Nie uzywaj `docker compose down -v`, chyba ze swiadomie chcesz usunac wszystkie
dane tego srodowiska.

## Diagnostyka

```bash
docker info
docker compose ps -a
docker compose logs --tail=100 backend queue postgres redis frontend
docker compose exec -T postgres sh -c 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
docker compose exec -T redis redis-cli ping
```

Gdy `docker version` nie pokazuje sekcji Server, uruchom Docker Desktop i
sprawdz integracje z ta dystrybucja w Settings > Resources > WSL Integration.
Nie instaluj globalnie PHP ani Composera jako obejscia problemu Dockera.
Przy konflikcie portow zmien porty w `.env`, a nie zatrzymuj cudzych procesow.

## Struktura

```text
backend/                 Laravel REST API i testy PHPUnit
  app/Domain/Installation/ serwis aplikacyjny, typ lokalizacji i bledy domenowe
  app/Models/            organizacja, lokalizacje i obie relacje
  app/Http/              kontrolery API, walidacja i lokalna granica dostepu
  config/                PostgreSQL, Redis, cache i kolejki
  database/migrations/   cztery tabele i ograniczenia PostgreSQL
  routes/api.php         GET /api/health oraz chronione /api/v1
  tests/Feature/         health, domena, API i wspolbieznosc PostgreSQL
frontend/                React + TypeScript + Vite
  src/                   strona startowa i prezentacja statusu API
scripts/init-env.php     bezpieczne tworzenie lokalnej konfiguracji
scripts/configure-management.php jawne wlaczanie/wylaczanie API developerskiego
compose.yaml             piec uslug oraz dwa trwale wolumeny
.env.example             szablon konfiguracji Compose bez sekretow
```

## Stage 1B: granica dostepu developerskiego

**To nie jest autoryzacja produkcyjna.** Wszystkie endpointy Stage 1B, takze odczyty,
sa domyslnie wylaczone. Wymagaja jednoczesnie:

1. Rzeczywistego srodowiska aplikacji `local`.
2. Jawnego `DEV_MANAGEMENT_ENABLED=true`.
3. Skonfigurowanego `DEV_MANAGEMENT_TOKEN` dlugosci co najmniej 32 znakow.
4. Poprawnego naglowka `X-Veltra-Development-Token`.

Poza `local`, przy wylaczeniu lub braku poprawnej konfiguracji odpowiedz to
HTTP 404. Brak/bledny naglowek w poprawnie wlaczonym srodowisku daje HTTP 403.
Sprawdzenie dziala przed pobieraniem modeli z bazy. Sesja uzytkownika Stage 1C
nie zastepuje zadnego z tych warunkow. RBAC Stage 1D dziala pod osobnym prefiksem
`/api/v1/security`; token developerski go nie otwiera.
Publiczny `/api/health` nie wymaga tego naglowka.

Jawne wlaczenie lokalnie (skrypt zachowuje pozostale sekrety i nie wypisuje tokenu):

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/app" -w /app composer:2 php scripts/configure-management.php --enable
docker compose up -d
```

Skrypt generuje brakujacy losowy token w glownym `.env` i ustawia uprawnienia
`0600`. Ustaw wartosc tego tokenu w naglowku swojego lokalnego klienta REST.
Nie umieszczaj tokenu w URL, kodzie frontendu, plikach przykladowych ani raportach.
Frontend nie korzysta z endpointow zarzadzania Stage 1B.

Wylaczenie:

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/app" -w /app composer:2 php scripts/configure-management.php --disable
docker compose up -d
```

Nie wystawiaj serwerow developerskich ani tego mechanizmu przez publiczny tunel.
Compose nadal publikuje porty API/frontendu tylko na `127.0.0.1`; PostgreSQL
i Redis nie publikuja portow hosta.

## Stage 1B: kontrakt API

Prefiks wszystkich ponizszych endpointow: `/api/v1`.

| Metoda | Sciezka | Operacja |
|---|---|---|
| GET | `/organization` | Organizacja instalacji; 404 przed inicjalizacja |
| POST | `/organization` | Inicjalizacja: `name`, `code`; 201, ponowna proba 409 |
| GET | `/locations` | Lista z filtrami `type`, `is_active` |
| POST | `/locations` | Utworzenie: `name`, `code`, `type`, opcjonalne `is_active` |
| GET | `/locations/{id}` | Lokalizacja |
| PATCH | `/locations/{id}` | Zmiana `name`, `code`, `is_active` |
| GET | `/locations/{id}/management-assignments` | Historia zarzadzania restauracja |
| GET | `/locations/{id}/managing-kitchen` | Biezaca kuchnia zarzadzajaca lub `data: null` |
| POST | `/locations/{id}/managing-kitchen` | Pierwsze/biezace przypisanie: `managing_kitchen_id` |
| PUT | `/locations/{id}/managing-kitchen` | Zmiana kuchni: `managing_kitchen_id` |
| GET | `/locations/{id}/managed-restaurants` | Restauracje obecnie zarzadzane przez kuchnie |
| GET | `/locations/{id}/supply-relationships` | Wszystkie relacje zaopatrywania, rowniez wylaczone |
| POST | `/locations/{id}/supply-relationships` | Dodanie: `supplier_kitchen_id` |
| GET | `/locations/{id}/suppliers` | Kuchnie z aktywna relacja zaopatrywania |
| DELETE | `/supply-relationships/{id}` | Idempotentne wylaczenie; 200 z `is_active: false`, bez usuwania rekordu |

Odpowiedzi pojedynczych zasobow maja `data`, listy `data`, `links`, `meta`.
Listy przyjmuja `page` (od 1) i `per_page` (1–100, domyslnie 20).
Utworzenie zasobu zwraca 201, zmiana/odczyt 200, brak 404, walidacja 422
z `message`/`errors`, konflikt reguly biznesowej 409 z `message`.
Nieznane pola sa odrzucane. Kody musza pasowac do `[A-Z0-9_-]+`, maks. 50 znakow;
nazwy maja maks. 200 znakow. Typy to tylko `CENTRAL_KITCHEN` i `RESTAURANT`.

## Stage 1B: reguly i integralnosc

- Jedna organizacja lacznie, nie tylko jedna aktywna: blokada doradcza przy
  inicjalizacji oraz unikalna stala `installation=true` w PostgreSQL.
- UUID jako klucze glowne, referencje z `ON DELETE RESTRICT`, unikalny kod
  lokalizacji w organizacji. Typ i organizacja lokalizacji sa niezmienne.
- Kuchnia domyslnie aktywna; restauracja domyslnie nieaktywna. Nowej restauracji
  nie mozna od razu aktywowac: najpierw przypisanie, potem `PATCH is_active`.
- Zarzadzanie dopuszcza tylko kuchnia -> restauracja w tej samej organizacji.
  PostgreSQL sprawdza typy triggerami i organizacje zlozonymi kluczami obcymi.
- Okresy maja granice `[effective_from, effective_until)`, z koncem `null`
  oznaczajacym brak konca. GiST EXCLUDE blokuje nakladanie sie dowolnych okresow,
  nie tylko dwoch rekordow bez konca. Puste/odwrocone/nieskonczone okresy sa zabronione.
- Serwis wykonuje zmiany **natychmiast**, z czasem pobranym z PostgreSQL i
  precyzja mikrosekund; API nie planuje przyszlych zmian ani nie cofa historii.
  Zmiana zamyka stary okres i tworzy nowy w jednej transakcji.
- Historia zarzadzania nie moze byc usuwana ani przepisywana. Dozwolone jest
  tylko jednokrotne ustawienie konca otwartego okresu; zamkniete okresy sa niezmienne.
- Odroczone triggery sprawdzaja na koncu transakcji, czy aktywna restauracja ma
  aktualne przypisanie do aktywnej kuchni. Nie mozna wylaczyc takiej kuchni bez
  uprzedniego przepisania lub dezaktywacji restauracji.
- Modyfikacje sa serializowane blokada wiersza organizacji (rowniez przez
  triggery dla bezposrednich zapisow SQL). Jest to swiadomie prosta granica
  wspolbieznosci jednej instalacji, a nie mechanizm obslugi wielu tenantow.
- Dostawcy i zarzadzanie sa niezalezne. Wiele kuchni moze zaopatrywac wiele
  restauracji. Czesciowy indeks UNIQUE blokuje zduplikowana aktywna pare.
  Wylaczenie pozostawia rekord, ponowne wlaczenie tworzy nowy.
- Relacja zaopatrywania oznacza wylacznie kwalifikowalnosc dostawcy. Nie tworzy
  zarzadzania, nie daje dostepu do danych i nie realizuje zamowien ani dostaw.

Ograniczenia: nie ma automatyzacji opartej na uplywie czasu ani planowania
przyszlych zmian. Gdy administrator ominie serwis i zapisze okres z przyszlym
koncem bezposrednio w SQL, sam uplyw czasu nie uruchomi triggera dezaktywacji.
Konto wlasciciela PostgreSQL moze oczywiscie zmieniac/omijac schemat; nie jest to
granica autoryzacji produkcyjnej. Dobor uprawnien produkcyjnych nie nalezy do Stage 1B.

Test przekroczenia granicy organizacji tworzy wycofywany, adversarialny fixture:
usuwa **tylko w transakcji testowej** ograniczenie singletonu, zachowujac
ograniczenia relacji, i potwierdza odrzucenie przez serwis oraz zlozone FK.
Normalna instalacja nigdy nie moze utworzyc drugiej organizacji.

## Stage 1C: pierwsza tozsamosc i przypisania

Po migracji i jawnej inicjalizacji organizacji zgodnie z kontraktem Stage 1B
uruchom **w interaktywnym terminalu**, bez `-T`:

```bash
docker compose exec backend php artisan veltra:provision-initial-identity
```

Komenda pyta o imie, nazwisko i email; haslo oraz potwierdzenie sa ukryte.
Haslo wymaga co najmniej 12 znakow, malych i wielkich liter, cyfry i symbolu,
oraz najwyzej 72 bajtow (bcrypt). Nie przekazuj hasla w argumentach, zmiennych
powloczki ani historii terminala. Komenda odrzuca tryb nieinteraktywny i ponowne
provisionowanie; blokada transakcyjna i unikalny znacznik chronia przed wyscigiem.
Nie wypisuje hasla ani nie tworzy domyslnych kont.

Jest to **tozsamosc poczatkowa, nie rola ADMINISTRATOR**: nie dostaje przypisan
ani uprawnien biznesowych. W bazie instalacji nie utworzono kont testowych.
Nie ma publicznej rejestracji, publicznego inicjowania resetu ani usuwania kont.
Stage 1D dodaje osobne, autoryzowane API zarzadzania oraz kolejny krok CLI
opisany ponizej. Pierwsze logowanie wymaga teraz weryfikacji e-mail.

Jedno konto UUID nalezy do organizacji instalacji. Email jest obcinany z bialych
znakow i normalizowany do malych liter UTF-8 przez wspolna regule modelu,
provisioningu, loginu i limitera; PostgreSQL wymusza normalizacje i
unikalnosc `lower(email)` w calej instalacji. Haslo jest hashowane przez Laravel,
a odpowiedzi uzytkownika zawieraja tylko `id`, `first_name`, `last_name`, `email`.
Hashe, znaczniki wewnetrzne i sekrety sesji nie sa serializowane.

Jedna osoba moze miec wiele niezaleznych przypisan do restauracji i kuchni.
Przypisanie nie daje zadnych uprawnien administracyjnych/biznesowych.
Aktywna para uzytkownik/lokalizacja jest unikalna. Zlozone FK wymuszaja wspolna
organizacje; usuniecie konta/przypisania i przepisywanie historii sa zabronione.
Odebranie przypisania zapisuje czas i powod; ponowne nadanie tworzy nowy UUID.
Nieaktywni uzytkownicy/lokalizacje nie sa dostepnymi kontekstami.
Relacja zaopatrywania nigdy nie nadaje dostepu. Zarzadzanie samo nie wystarcza:
Stage 1D wymaga jawnej roli szefa kuchni i poprawnego kontekstu.

## Stage 1C: uwierzytelnianie SPA i API

Laravel 13 + Sanctum 4 uzywa natywnego guarda `web` i sesji w Redis, bez tokenow
Bearer/PAT. Frontend i API dzialaja pod tym samym originem dzieki proxy Vite
`/api` i `/sanctum`. `SANCTUM_STATEFUL_DOMAINS` musi zawierac jawne hosty i porty
frontendowe; Compose ustawia je zgodnie z portami projektu.
Frontend nie przechowuje danych sesji w localStorage.

Przed logowaniem pobierz `GET /sanctum/csrf-cookie` z ciasteczkami.
Do kazdego POST/PUT dodaj `X-XSRF-TOKEN` z URL-dekodowanej wartosci ciasteczka
`XSRF-TOKEN`, `Accept: application/json` i wlasciwy Origin/Referer.
Klient poza lista first-party nie moze korzystac z uwierzytelniania SPA.
Brak tokenu CSRF dla niezaufanego zadania zmieniajacego stan daje 419.
Middleware Laravel moze rowniez zweryfikowac browserowy Fetch Metadata same-origin.

| Metoda | Endpoint | Ochrona / wynik |
|---|---|---|
| POST | `/api/v1/auth/login` | First-party sesja, CSRF, email i haslo; `data` profilu |
| POST | `/api/v1/auth/logout` | First-party sesja, CSRF, zalogowanie; `data: null`, uniewaznienie sesji |
| GET | `/api/v1/auth/me` | Zalogowanie, aktywne konto/organizacja, ponowna walidacja kontekstu |
| GET | `/api/v1/auth/locations` | Jak wyzej; tylko wlasne aktywne przypisania do aktywnych lokalizacji |
| PUT | `/api/v1/auth/operating-location` | Jak wyzej + CSRF; body `location_id` UUID, konieczne aktywne przypisanie |
| GET | `/api/v1/auth/operating-location` | Jak wyzej; lokalizacja lub `data: null` |

Sesja przechowuje UUID **przypisania**, nie arbitralny identyfikator lokalizacji.
Middleware odczytuje konto, organizacje, przypisanie i lokalizacje ponownie przy
kazdym chronionym zadaniu. Odebranie przypisania lub wylaczenie lokalizacji
usuwa kontekst i odrzuca pierwsze kolejne zadanie (403); ponowne nadanie
nie przywraca starego kontekstu. UI pozwala odswiezyc pozostale przypisania.
Wylaczone konto/organizacja traci sesje (401). Logout pozostaje dostepny mimo
utraty kontekstu. Bez wybranego kontekstu mozna odczytac profil i wybrac lokalizacje;
operacje `/api/v1/security` wymagaja aktywnego kontekstu i odpowiedniej roli.

Bledny email/haslo, konto wylaczone i niezweryfikowany e-mail daja ten sam komunikat 401.
Walidacja struktury daje 422, nieuprawniona lokalizacja 403, limit logowania 429.
Limit to 5 prob/minute na znormalizowany email+IP i 30/minute na IP; klucz emaila
jest hashowany. Redis przechowuje limity w dzialajacej aplikacji.
Logowanie regeneruje sesje; logout ja uniewaznia i odnawia token CSRF.
Ciasteczko sesji jest HttpOnly, domyslnie host-only/SameSite=Lax, z limitem
bezczynnosci 120 minut. Payload Redis jest szyfrowany przez Laravel.

Lokalny HTTP dopuszcza `Secure=false` tylko w `local/testing`. Inne srodowiska
wymuszaja `Secure=true`; wdrozenie wymaga HTTPS, poprawnego originu, zaufanego
reverse proxy oraz konfiguracji kluczy i dostepu do Redis/DB poza tym Compose.
Chronione poprawne odpowiedzi sa `no-store`; SQL/bindings operacji tozsamosci
nie trafiaja do standardowego logowania wyjatkow. Nie wlaczaj APP_DEBUG publicznie.
To nie jest kompletny deployment produkcyjny ani niezalezny audyt bezpieczenstwa.

Testy C korzystaja z PostgreSQL, ale z izolowanego cache/sesji `array`; rzeczywiste
HTTP i testy przegladarki zostaly dodatkowo wykonane na osobnej, tymczasowej bazie.
Fixture `backend/tests/Support/browser_identity_fixture.php` jest celowo
zablokowany poza baza `veltra_browser_stage1c`; nie jest seederem instalacji.
Nie uruchamiaj go na danych rzeczywistych. Kontenery/baza testow przegladarki
zostaly usuniete po sprawdzeniu.

**Granica pozostaje zamknieta:** logowanie ani tozsamosc poczatkowa nie otwieraja
API Stage 1B. Nie przeniesiono go pod autoryzacje sesyjna. Nowe API RBAC ma
osobne trasy i wymaga niezaleznej autoryzacji.

## Stage 1D: bootstrap i poczta

Po jawnej inicjalizacji organizacji oraz utworzeniu pierwszej tozsamosci:

```bash
# Terminal interaktywny, bez -T; potwierdz dotychczasowe haslo.
docker compose exec backend php artisan veltra:bootstrap-authorization
```

ADMINISTRATOR jest najwyzsza z szesciu rol. Znacznik pierwszej tozsamosci
sluzy wylacznie procedurom instalacyjnym i nie nadaje dodatkowych uprawnien.
Zwykle nadawanie rol i uprawnien samemu sobie jest zabronione; musi pozostac
co najmniej jeden aktywny, zweryfikowany administrator z aktywnym przypisaniem.

Walidator zadania sprawdza jedynie cele i wskazane uprawnienia; nie stanowi
autoryzacji. Kontrolowany, zatwierdzony proces instalacji lub aktualizacji jest
zaufana granica administracyjna: kazdy, kto nim zarzadza, ma uprzywilejowana
wladze wdrozeniowa. Sam manifest ani sesja aplikacji nie sa zatwierdzeniem.
Rejestr zawiera manifest `stage6a1-raw-administrator` w wersji 1:
organizacyjne `RAW_MATERIALS_VIEW` i `RAW_MATERIALS_CREATE` dla wskazanego
administratora i instalacji Stage 6A.1. Wskazana kuchnia jest kontekstem
kwalifikacji, a nie ograniczeniem organizacyjnego zasiegu grantu. Rejestr i hash
manifestu nie sa zatwierdzeniem wdrozenia ani mechanizmem nadawania uprawnien.
Po zastosowaniu migracji 047–048 dedykowana komenda
`veltra:provision-stage6a1-raw-permissions` jest uruchamiana w procesie
migracyjnym dla lokalnej bazy Stage; wymaga
jawnego `--approve-release` z dokładnym skrótem wydania i instalacji. Opcja
`--show-release` wyświetla ten skrót bez wykonywania grantu. Nie podaje się
użytkownika, lokalizacji ani uprawnień jako argumentów. Powtórzenie nie
przywraca cofniętych grantów. Dostępność RAW i aktywacja lokalizacji pozostają
oddzielnymi bramkami, a globalny status RAW pozostaje `NOT_READY`.

Wersja 2 tego samego manifestu jest jednorazowym, jawnym odzyskaniem
uprawnień dla wskazanego pierwotnego administratora tej samej instalacji.
Obejmuje `RAW_MATERIALS_EDIT`, `RAW_MATERIALS_CATEGORY_MANAGE`,
`RAW_MATERIALS_REFERENCE_PRICE_VIEW`, `RAW_MATERIALS_REFERENCE_PRICE_MANAGE`,
`RAW_MATERIALS_ALLERGEN_MANAGE`, `RAW_MATERIALS_SUBMIT` i
`RAW_MATERIALS_APPROVE`. Nie nadaje delegacji ani nie zmienia `FOODCOST_VIEW`.
Pierwsze wykonanie jest odrzucane, gdy istnieje odrębny aktywny, zweryfikowany
administrator z aktywnym członkostwem i efektywnym niedelegowanym prawem
delegacji RAW. Ponowienie potwierdza istniejącą operację bez przywracania
cofniętych grantów. Dalsze zmiany podlegają zwykłym zasadom RBAC.

Po odrębnym zatwierdzeniu i wdrożeniu właściwej wersji kodu operator procesu
migracyjnego wybiera wersję 2, sprawdza cel, listę uprawnień i skrót, a następnie
podaje dokładny wyświetlony skrót jako `--approve-release`. Oba polecenia należy
wykonać z katalogu repozytorium i ze wskazaniem istniejącego pliku środowiska
Stage, bez ujawniania jego zawartości:

```bash
docker compose -p veltra-stage6a1 --profile migrations --env-file /sciezka/do/stage.env -f compose.yaml -f compose.stage6a1.yaml run --rm --no-deps migrate php artisan veltra:provision-stage6a1-raw-permissions --manifest-version=2 --show-release
docker compose -p veltra-stage6a1 --profile migrations --env-file /sciezka/do/stage.env -f compose.yaml -f compose.stage6a1.yaml run --rm --no-deps migrate php artisan veltra:provision-stage6a1-raw-permissions --manifest-version=2 --approve-release=SKROT_Z_POPRZEDNIEGO_POLECENIA
```

W audycie aktorem jest proces systemowy i rola sesji migracyjnej, nie
uwierzytelniony użytkownik aplikacji; rejestrowane są cel, zakres, lista praw,
przyczyna i czas. Skrót wybiera dokładne wydanie i instalację, ale nie jest
samodzielnym poświadczeniem właściciela.

Komenda tworzy potrzebna poczatkowa kuchnie/przypisanie i oczekujaca role
ADMINISTRATOR. Nie wypisuje hasla ani linku. Otworz Mailpit lokalnie i uzyj
wiadomosci aktywacyjnej; odbiorca ustawia wlasne nowe haslo. Dopiero wtedy rola
jest skuteczna i zamyka sie jednokierunkowa blokada bootstrapu. Przed aktywacja
komende mozna ponowic po cooldownie, takze po wygaśnięciu linku lub awarii SMTP.
Nie powstaja domyslne konta ani dane organizacji przez migracje/seedery.

Lokalny SMTP jest celowo przechwytywany przez Mailpit (obraz przypiety digestem).
Nie sluzy do wysylki na rzeczywiste adresy. Interfejs na loopback nie ma
uwierzytelniania: nie wystawiaj portu `8025` przez tunel ani do sieci publicznej.
Port zmienia `MAILPIT_PORT`. Tokeny w skrzynce sa sekretami odbiorcy.
Poza developmentem skonfiguruj rzeczywisty SMTP, TLS, dane nadawcy, HTTPS
oraz `FRONTEND_URL`. Compose jest tylko lokalny; nie jest deploymentem produkcyjnym.
Transport `log` jest zabroniony dla linkow; wysylka jest synchroniczna,
bez tokenow w kolejce lub aplikacyjnych logach.

Aktywacja: 24 godziny. Reset: 30 minut. Laravel przechowuje tylko hashe tokenow.
Nowe wydanie uniewaznia poprzedni link danego rodzaju; zmiana hasla/e-maila
uniewaznia oba rodzaje i poprzednie sesje przez wersje konta. E-mail zmienia
wylacznie **inny** administrator; wymagane ponowne potwierdzenie adresu.
Samodzielna zmiana adresu nie jest udostepniona.
Linki maja sekret w fragmencie URL, nie w query string; frontend usuwa fragment
z historii po odczycie. Odswiezenie formularza wymaga ponownego otwarcia linku.
Wspolne limity: 3 wiadomosci/konto/60 minut, minimum 60 sekund odstepu
i 20/IP/godzine. Rezerwacja jest atomowa z blokada organizacji; awaria SMTP
zuzywa rezerwacje i wymaga nowego linku po cooldownie.

### Stage 6A.2-FIX: odzyskanie lokalnej aktywacji Stage 6A.1

`veltra:recover-stage6a1-identity` jest jednorazowa sciezka operacyjna dla
utraconego hasla **nieaktywowanej** poczatkowej tozsamosci Stage 6A.1.
Nie jest endpointem HTTP ani uniwersalnym resetem; normalny bootstrap pozostaje
bez zmian. Komenda nie ustawia hasla, nie zmienia RBAC ani `NOT_READY`.

Domyslnie odmawia dzialania. Administrator hosta musi najpierw sprawdzic
projekt Compose `veltra-stage6a1`, jego wylaczna siec i wolumen
`veltra-stage6a1_postgres-data`, brak portu PostgreSQL na hoscie oraz
publikowanie API/Mailpit wylacznie na loopback. Dopiero wtedy przygotowuje
w kontenerze backendu plik `/run/veltra-stage6a1-recovery.json`, nalezacy
do root, bez praw zapisu (`0444`), z nastepujacymi niesekretnymi polami:
`compose_project`, `postgres_volume`, `container_hostname`, `database_oid`,
`server_address`, `mailpit_address`, `organization_id`, `user_id`, `expires_at`
(czas Unix; najwyzej 24 godziny). Wartosc kazdego identyfikatora musi pochodzic
z weryfikacji biezacych kontenerow i odczytu konkretnej bazy, nie z domyslow.
To poswiadczenie administratora hosta, nie kryptograficzny dowod izolacji.
Nie montuj Docker socket do aplikacji. Nie przygotowuj pliku dla innych instalacji.

Komenda sprawdza `local`, kontener, hostname, rzeczywista nazwe/OID/adres/role
polaczenia PostgreSQL, identyfikatory instalacji i konta, lokalny SMTP bez
alternatywnego URL oraz dokladnie jeden oczekujacy bootstrap bez danych
operacyjnych, dodatkowych kont, lokalizacji, uprawnien i wlaczonych modulow.
Zmiana kontenera lub adresow wymaga ponownego sprawdzenia izolacji i nowego pliku.
Samo ustawienie `APP_ENV` lub nazwy bazy nie wystarcza.

Po udostepnieniu biezacego kodu komendy w tym lokalnym kontenerze:

```bash
# Tylko kontrola; bez zapisu, wysylki i bez wymaganego TTY.
docker exec veltra-stage6a1-backend-1 php artisan veltra:recover-stage6a1-identity --check
# Wlasciciel samodzielnie wpisuje potwierdzenie; bez -T, -n i automatyzacji.
docker exec -it veltra-stage6a1-backend-1 php artisan veltra:recover-stage6a1-identity
```

Wymagana fraza: `ODZYSKAJ STAGE6A1`. Po potwierdzeniu stan jest ponownie
sprawdzany pod blokada organizacji. Istniejacy `AccountLinks` zastepuje token
i wysyla wiadomosc, a `SecurityMutation` zapisuje `initial_identity.local_recovery`.
Zachowane sa limity wysylek. W tej komendzie zewnetrzna transakcja obejmuje
zastapienie linku i audyt: blad SMTP wycofuje te zmiany, zachowujac poprzedni
stan, i zapisuje odrzucenie osobno. Nie uzywaj poprzedniego ujawnionego linku.
SMTP nie jest transakcyjny: przy utracie potwierdzenia lub bledzie commit
wiadomosc moze dotrzec z niewaznym linkiem. Nie oznacza to sukcesu; po
sprawdzeniu stanu ponow procedure i uzyj najnowszej skutecznie wyslanej wiadomosci.

Otworz samodzielnie `http://localhost:8026/`, wybierz najnowsza wiadomosc
i ustaw wlasne haslo w istniejacym formularzu aktywacji. Nie udostepniaj linku
narzedziom przegladarki, zrzutom ani raportom. Zaloguj sie przez
`http://localhost:5174/`. Po aktywacji komenda odmawia dalszego dzialania.
Usun tylko plik poswiadczenia z tego kontenera po zakonczeniu odzyskania.

### Stage 6A.3: lokalne testy RAW bez globalnego READY

Domyslnie wylaczone. `LocalRawMaterialCatalog` korzysta z tego samego katalogu
i przeksztalcenia co fixture `readyWithRawMaterials()`, ale zmienia tylko RAW.
Produkcyjne definicje pozostaja `NOT_READY`; zadne inne moduly nie sa wlaczane.

Lokalny katalog RAW jest dostepny tylko w projekcie `veltra-stage6a1`
uruchomionym z `compose.stage6a1.yaml`: aplikacja sprawdza lokalne srodowisko,
konfiguracje URL, rzeczywista baze `veltra_stage6a1`, role runtime, adres
PostgreSQL oraz aktywna, zainicjowana organizacje i pierwsza aktywowana
tozsamosc. Nie wymaga pliku ani odnawiania poswiadczenia akceptacji RAW.
Nie jest to przelacznik wylaczajacy autoryzacje ani konfiguracja produkcyjna.

Administrator wykonuje interaktywnie:
`docker exec -it veltra-stage6a1-backend-1 php artisan veltra:configure-stage6a1-raw`.
Komenda wybiera pierwsza tozsamosc oraz jedyna aktywna glowna kuchnie tej
organizacji, wywoluje standardowy `ModuleConfigurationService`, wymaga hasla administratora
i zapisuje audyt. Nie nadaje uprawnien; granty Stage sa inicjalizowane osobnym
zatwierdzonym manifestem opisanym powyzej.
Sesje, CSRF, RBAC, stany lokalizacji i guard dostepnosci pozostaja obowiazkowe.
Normalna aplikacja: <http://localhost:5174/>. Katalog zawiera zatwierdzone
rewizje; CREATE tworzy szkic i nie publikuje go automatycznie.

#### Lokalne wdrozenie Stage 6A.1

```bash
cd /home/veltra/projekty/veltra
python3 scripts/deploy_stage6a1.py --env-file /sciezka/do/stage.env \
  --backup-dir /sciezka/do/prywatnych-kopii-stage
```

Skrypt wymaga czystego, zatwierdzonego drzewa Git oraz istniejacych wolumenow
Stage. Sprawdza kontekst `default`, projekt, konfiguracje, porty i rzeczywiste
kontenery/wolumeny PostgreSQL i Redis; tworzy kopie `pg_dump` poza wolumenem,
sprawdza jej odczyt przez `pg_restore` i wypisuje SHA-256. To nie jest test
odtworzenia bazy. Nastepnie buduje obrazy, uruchamia migracje i odtwarza tylko
backend, kolejke i frontend, bez odtwarzania PostgreSQL ani Redis. Dla nowych
migracji wyswietla ich liste i wymaga recznego potwierdzenia po przegladzie
ich skutkow. Operacje destrukcyjne wymagaja osobnej decyzji wlasciciela.
Skrypt sprawdza zdrowie aplikacji i zaleznosci oraz podaje commit i URL.
Przy bledzie przerywa; przed ponowieniem sprawdz stan i zachowana kopie.

### Stage 6A.4: szkice i zatwierdzanie RAW

Normalny katalog zachowuje zatwierdzone snapshoty. Zakladka
**Szkice i oczekujace** korzysta z osobnego `GET /api/v1/raw-materials/workbench`.
Wymaga standardowego VIEW oraz EDIT lub AUDIT_VIEW, dostepnosci modulu
i kontekstu lokalizacji glownej. Wyszukiwanie, filtry i paginacja sa serwerowe;
`workflow_state` opisuje robocza lub oczekujaca rewizje, takze dla aktywnego
surowca majacego nowsza rewizje. Ten widok nie udostepnia danych finansowych,
filtra dostawcow ani eksportu zatwierdzonego katalogu.

Drawer pobiera rewizje robocza/oczekujaca przez istniejacy `/editor`, ktory
udostepnia rowniez ilosci, wydajnosc oraz autoryzowane metadane historii.
SUBMIT i APPROVE wymagaja jawnego potwierdzenia i `If-Match`; widocznosc
przyciskow wynika z `/completion.available_actions`, a backend ponownie
sprawdza uprawnienia i wersje. Konflikt nie powoduje automatycznego ponowienia.

Przed przekazaniem uzupelnij **Deklaracje alergenow**, korzystajac z
opublikowanego katalogu i istniejacego PUT `/draft/allergen-declaration`.
Wybor deklaracji jest jawny; aplikacja nie zaznacza automatycznie braku
alergenow. Brak opublikowanego katalogu blokuje zatwierdzenie.
Powrot niekompletnej oczekujacej rewizji wymaga odrebnego prawa REVIEW.
Self-approval pozostaje dozwolone przez istniejacy kontrakt.

Stage 6A.4 nie zmienia grantow, modelu rewizji ani globalnego NOT_READY.
Wylacznie po osobnym zatwierdzeniu wlasciciela przygotowano w bazie
`veltra_stage6a1` jeden opublikowany katalog **TEST Stage 6A.4**, z definicjami
`TEST_WHEAT` i `TEST_MILK`. To dane testowe, nie katalog do eksploatacji.

### Stage 6A.5: formularze i laczne zatwierdzanie RAW

Formularze CREATE i EDIT maja zakladki danych podstawowych, alergenow
i informacji dodatkowych. Deklaracje mozna zapisac wraz z danymi rewizji,
jesli uzytkownik ma efektywne `RAW_MATERIALS_ALLERGEN_MANAGE`.
Niezmieniona deklaracja pozostaje zachowana przez istniejace wersjonowanie.

`POST /api/v1/raw-materials/create-approved` laczy utworzenie, przekazanie
i zatwierdzenie w jednej transakcji. Wymaga rezerwacji SKU, jawnej kompletnej
deklaracji alergenow, roli ADMINISTRATOR oraz efektywnych grantow CREATE,
ALLERGEN_MANAGE, SUBMIT i APPROVE w prawidlowym kontekscie lokalizacji glownej.
Backend ponownie sprawdza te warunki; samo widoczne dzialanie interfejsu nie
jest autoryzacja. Ponowienie uzywa tej samej rezerwacji i danych; wynik zostaje
zapamietany tylko po powodzeniu calej transakcji. Pozostali uzytkownicy
korzystaja z istniejacego procesu szkicu. Globalny stan RAW pozostaje NOT_READY.

### Zarzadzanie kategoriami RAW

Panel katalogu umozliwia tworzenie kategorii i zmiane ich nazw, gdy lista
kategorii jest dostepna, a `capabilities.actions.manage_categories` potwierdza
efektywny grant `RAW_MATERIALS_CATEGORY_MANAGE`, glowny kontekst i dostepnosc
modulu. Backend ponawia autoryzacje zapisu; kod kategorii pozostaje niezmienny.
Po niepotwierdzonym zapisie nalezy odswiezyc i sprawdzic liste przed ponowieniem.
Zmiana w kodzie nie stanowi wdrozenia ani zgody na aktywacje RAW lub Stage.

## Stage 1D: zakresy i macierz rol

Kazde zadanie wymaga aktywnego, zweryfikowanego konta oraz jawnego aktywnego
przypisania do wybranego kontekstu. Wybrany kontekst nie tworzy przypisan
do celow operacji. Szef dziala z przypisanej kuchni na jej aktualne restauracje
(albo w wybranej restauracji przez swoja role w jej kuchni); inna przypisana
kuchnia wymaga przelaczenia kontekstu. Relacja dostaw nie bierze udzialu w decyzji.

| Rola | Zakres / prawa domyslne |
|---|---|
| ADMINISTRATOR | Instalacja; struktura, konta, role, uprawnienia. Nadal obowiazuje granica modulow. |
| CENTRAL_KITCHEN_CHEF | Jawnie przypisana kuchnia i jej aktualnie zarzadzane restauracje; personel, raporty i prawa finansowe. Bez struktury ani rol ADMINISTRATOR/BOSS/szef kuchni. |
| BOSS | Odczyt i eksport dozwolonych raportow wszystkich restauracji, takze przyszlych; bez edycji, zatwierdzen i danych zrodlowych kuchni. |
| RESTAURANT_MANAGER | Wlasna restauracja: nowy personel, role EMPLOYEE/RESTAURANT_CHEF, delegacje z allowlisty. Bez zarzadzania kierownikami i kontami globalnymi. |
| RESTAURANT_CHEF | Wlasna restauracja: kontrakt widocznosci receptur i kalkulatora ilosci. Bez domyslnych praw finansowych. |
| EMPLOYEE | Podstawowy odczyt lokalizacji; operacje tylko z jawnej delegacji i przy dostepnym module. |

Nowy pracownik restauracji dostaje role EMPLOYEE; istniejaca tozsamosc nie jest
duplikowana, a wniosek o dodatkowe przypisanie wymaga administratora lub
uprawnionego szefa zarzadzajacej kuchni. Nowa osoba kuchni nie dostaje roli
restauracyjnej; role szefa kuchni nadaje jawnie administrator.
API bezposredniego przypisania istniejącej tozsamosci jest tylko dla administratora.
Kierownik nie usuwa przypisan osoby z chroniona rola takze w innej lokalizacji.
Globalne wylaczenie/wlaczenie konta jest tylko dla administratora.

Zmiana roli ADMINISTRATOR wymaga aktualnego hasla przy kazdym zadaniu
(5 potwierdzen/minute/konto+IP). Samoodwolanie jest mozliwe, jesli pozostaje
inny aktywny zweryfikowany administrator z dostepnym kontekstem.
Blokada organizacji i odroczone triggery PostgreSQL chronia tez przed wyscigami,
usuwaniem ostatniego przypisania i wylaczaniem ostatniej lokalizacji administratora.

`FOODCOST_VIEW`, `PURCHASE_PRICES_VIEW`, `REPORTS_VIEW` i `REPORTS_EXPORT`
sa osobnymi prawami na restauracje, nadawanymi przez administratora lub jej
uprawnionego szefa kuchni. Eksport wymaga odczytu raportu **i** prawa do zrodla
wybranego po stronie serwera. BOSS nie dostaje cen/foodcostu przez prawo eksportu.
Jawne udostepnienie raportu kuchni BOSS zapisuje osobny niezmienny referencyjny
grant. `ReportCatalog` domyslnie nie zwraca zadnego raportu: to kontrakt integracji,
nie gotowy system raportowania ani generowania eksportow.

Allowlista delegacji: `TEMPERATURE_REGISTER` → `TEMPERATURES`,
`CLEANING_ENTRY_CREATE` → `CLEANING`, `LOSS_REPORT_CREATE` → `LOSSES`,
`INVENTORY_COUNT_ENTRY` → `INVENTORY`. Stage 1E udostepnia jawna konfiguracje
per lokalizacja, ale produkcyjny katalog ma wszystkie cztery moduly `NOT_READY`;
zadne API nie moze ich wlaczyc, dopoki kod nie oznaczy implementacji jako gotowej.
Brak rekordu oznacza `DISABLED`; zaleznosci nie sa wlaczane automatycznie.
Okresy sa `[valid_from, valid_until)`, z opcjonalnym koncem; czas ocenia serwer.
Nakladajace sie/przylegajace okresy laczy PostgreSQL `range_agg`, bez utraty
oryginalnych grantow. Odwolanie zamyka atomowo wszystkie granty danego prawa,
takze przyszle. Nowy jawny grant po odwolaniu jest nowa decyzja, nie wznowieniem.
Dezaktywacja przypisania zamyka jego lokalne role i granty.

## PA-01: fundament Panelu Administracyjnego

Frontend udostępnia `/administration` oraz podstrony będące wyłącznie
placeholderami. Sekcje PA pojawiają się w istniejącej nawigacji aplikacji po
otwarciu panelu; nie ma osobnego sidebara PA. Widoczność wejścia i zawartość panelu zależą od odpowiedzi
`GET /api/v1/administration/access`. Ten endpoint wymaga sesji first-party,
aktywnej zweryfikowanej tożsamości, aktywnego kontekstu i roli ADMINISTRATOR;
zwraca `{"data":{"allowed":true}}`, 401 dla braku ważnej tożsamości i 403 dla
braku kontekstu lub uprawnienia. Rola jest sprawdzana ponownie na serwerze.
Kontrola PA nie zależy od gotowości RAW ani nie włącza jego operacji. Istniejące
zakresowe operacje personelu poza PA pozostają pod dotychczasową autoryzacją.

Nawigacja PA używa wspólnego `VeltraAccordion`: grupy można rozwijać niezależnie,
a otwarte grupy są zachowywane w obrębie karty przeglądarki. Grupa aktywnej
podstrony rozwija się automatycznie. Komponent obsługuje również tryb pojedynczej
otwartej grupy i preferencję ograniczenia animacji.

## Stage 1D: API i audyt

Prefiks `/api/v1/security`; sesja Sanctum first-party, CSRF przy mutacjach,
walidowany kontekst i autoryzacja domenowa. Limit 60 zadan/minute/konto+IP.
Nieznane pola sa odrzucane. 401: tozsamosc, 403: uprawnienia/kontekst,
404: zasob, 409: konflikt, 419: CSRF, 422: walidacja, 429: limiter.

| Metoda | Sciezka bez prefiksu | Cel |
|---|---|---|
| GET | `/access?location_id=&permission=` | Wylacznie decyzja o wlasnym prawie |
| POST / PATCH | `/locations` / `/locations/{location}` | Struktura; tylko administrator |
| POST / PUT | `/locations/{location}/managing-kitchen` | Jawne zarzadzanie; tylko administrator |
| GET | `/locations/{location}/users` | Personel w dozwolonym zakresie; paginacja |
| GET | `/users/{user}/assignments?location_id=` | Zakresowe role/historia i granty; maks. 100 rekordow kazdego rodzaju |
| POST | `/locations/{location}/personnel` | Nowy personel lub wniosek dla istniejacej tozsamosci; jednolite 202 |
| GET | `/locations/{location}/membership-requests` | Wnioski dla uprawnionego zatwierdzajacego |
| POST | `/membership-requests/{request}/decision` | `approve` boolean |
| POST | `/users/{user}/locations/{location}/membership` | Jawne przypisanie; tylko administrator; bez roli |
| DELETE | `/memberships/{membership}` | Historyczne odebranie lokalnego dostepu |
| POST | `/users/{user}/roles` | `role`, opcjonalne `location_id`, `current_password` dla ADMINISTRATOR |
| DELETE | `/roles/{assignment}` | Cofniecie; `current_password` dla ADMINISTRATOR |
| PATCH | `/users/{user}/status` | Globalne `is_active`; tylko administrator |
| PATCH | `/users/{user}/email` | Inny administrator; nowy adres i nowa aktywacja |
| POST / DELETE | `/users/{user}/locations/{location}/permissions` | `permission`, przy nadaniu opcjonalne `valid_from`, `valid_until` |
| POST | `/users/{identifier}/links` | `kind`: `activation`/`reset`; zawsze ogolne 202, nigdy token |

`POST /api/v1/account/activate` i `/api/v1/account/reset-password` sa
niezalogowanymi punktami wymiany jednorazowego sekretu: first-party sesja, CSRF,
limit 10/minute/IP, `email`, `token`, `password`, `password_confirmation`.
Brak publicznego endpointu inicjujacego reset. Przelozony nigdy nie wybiera
hasla personelu. Zle/nieuprawnione/limitowane inicjacje maja ten sam komunikat
przyjecia zadania, nie gwarancje dostarczenia poczty; wynik zapisuje audyt.

`security_events` jest trwale append-only: UUID, organizacja, aktor/cel/lokalizacja
(gdy dotyczy), akcja, wynik, identyfikator przedmiotu i czas serwera.
Udana zmiana i zdarzenie sa w tej samej transakcji; odrzucony uprzywilejowany
wniosek jest rejestrowany poza wycofana transakcja. Zdarzenie techniczne
odrzucenia HTTP moze wystapic obok zdarzenia domenowego. Brak dowolnych
payloadow, hasel, tokenow, ich hashow lub danych SMTP w audycie.
To minimalny audyt bezpieczenstwa, nie Stage 1F.

Testy wspolbieznosci uruchamiaja rzeczywiste niezalezne procesy PHP/polaczenia
PostgreSQL i potwierdzaja oczekiwanie na blokade przed COMMIT/ROLLBACK.
`tests/Support/browser_authorization_fixture.php` jest dostepny wylacznie dla
tymczasowej bazy `veltra_browser_stage1d` w local; nigdy nie uruchamiaj go
na instalacji klienta.

## Stage 1E: konfiguracja dostepnosci modulow

Katalog jest definiowany w kodzie i zawiera poczatkowo tylko
`TEMPERATURES`, `CLEANING`, `LOSSES` i `INVENTORY`, zgodnie z mapowaniem
uprawnien powyzej. Wszystkie cztery obsluguja `CENTRAL_KITCHEN` i `RESTAURANT`,
nie maja wzajemnych zaleznosci i pozostaja `NOT_READY`; zadne rzeczywiste
operacje biznesowe tych modulow nie sa wystawione. Gotowosc ani zaleznosci nie
sa konfigurowalne przez administratora. Testy pozytywnej aktywacji wstrzykuja
jawny katalog fixture, ktory nie jest dostepny przez produkcyjne API.

| Metoda | Endpoint | Ochrona / wynik |
|---|---|---|
| GET | `/api/v1/security/modules/catalog` | Uwierzytelniony kontekst; katalog, gotowosc, zgodne typy i zaleznosci |
| GET | `/api/v1/security/locations/{location}/modules` | Prawo odczytu lokalizacji; jawny stan i biezaca dostepnosc modułów |
| POST | `/api/v1/security/modules/configuration` | Tylko ADMINISTRATOR; `module_ids`, `enabled`, `current_password` i jawne `location_ids` albo `all_compatible_locations: true` |
| GET | `/api/v1/security/locations/{location}/modules/history` | Tylko ADMINISTRATOR; paginowana, tylko-do-odczytu historia konfiguracji |

Kazda zmiana wymaga biezacego hasla; bledne potwierdzenia sa ograniczane do
5/minute na konto i IP. Operacje zbiorcze rozwiazuja cele do aktywnych,
zgodnych lokalizacji i sa atomowe w PostgreSQL. Blokada organizacji oraz
lokalizacji jest pobierana deterministycznie; ponowienie juz osiagnietego
stanu rejestruje probe, ale nie tworzy fikcyjnej zmiany. Zdarzenia prob i
rzeczywistych przejsc stanow rozszerzaja append-only `security_events`;
przejscie i jego audyt sa zatwierdzane razem. Dezaktywacja nie kasuje grantow,
rol ani historii, a biezaca kontrola `ModuleAvailability` odmawia dostepu
poza gotowym, jawnie wlaczonym modulem. Nie ma automatycznego dziedziczenia
ustawien z kuchni na restauracje.

Frontend obecnie nie ma autoryzowanego panelu administracyjnego, dlatego Stage
1E udostepnia stabilny backend i nie dodaje ekranu, ktory sugerowalby gotowosc
nieistniejacych funkcji biznesowych. Testy migracji, integralnosci i
wspolbieznosci uruchamiaj wylacznie na zweryfikowanym, odizolowanym PostgreSQL;
nie uzywaj bazy ani wolumenow instalacji.
