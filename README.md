# VELTRA 2.0 — Stage 1A

Uruchamialny fundament modularnego monolitu: Laravel REST API, osobny frontend
React + TypeScript + Vite, PostgreSQL oraz Redis jako cache i kolejka.
Bez uzytkownikow, logowania, rol, modulow biznesowych i tabel aplikacyjnych.

## Wymagania

- Ubuntu WSL2 z dzialajacym Docker Desktop i integracja WSL.
- Docker Engine i Docker Compose v2 lub nowszy.
- Dostep do rejestrow obrazow, Packagist i npm podczas pierwszego budowania.
- Wolne porty `8000` (API) i `5173` (frontend).

PHP, Composer, Node i npm dzialaja **wylacznie w kontenerach**; nie trzeba
instalowac ich w Ubuntu. Jest to srodowisko lokalne, nie konfiguracja produkcyjna:
korzysta z serwerow developerskich Laravel i Vite oraz zaleznosci developerskich.

## Pierwsze uruchomienie

W katalogu glownym repozytorium:

```bash
docker version
docker compose version

# Utworzenie ignorowanego przez Git .env z losowym haslem i kluczem aplikacji.
# Polecenie nie nadpisuje istniejacego pliku.
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/app" -w /app composer:2 php scripts/init-env.php

docker compose up -d --build
docker compose ps
```

Pierwsze budowanie kompiluje rozszerzenia PHP i moze potrwac kilka minut.
Gotowosc mozna sprawdzic poleceniem `docker compose up -d --wait --wait-timeout 120`.

- Frontend: <http://localhost:5173>
- API health: <http://localhost:8000/api/health>
- API przez proxy frontendu: <http://localhost:5173/api/health>

Compose uruchamia `backend`, `frontend`, `postgres`, `redis` i `queue`.
PostgreSQL i Redis nie publikuja portow na hoscie. API i frontend nasluchuja
tylko na `127.0.0.1`. Worker korzysta z Redis; nie potrzebuje tabeli `jobs`.
Nieudane zadania sa zapisywane przez sterownik `file` Laravela w kontenerze
workera (dane diagnostyczne nietrwale; Stage 1A nie dodaje zadan biznesowych).

Nie uruchamiaj migracji: ten etap celowo nie zawiera zadnych tabel ani migracji.
Usunieto tylko niepotrzebne elementy wygenerowanych szkieletow, m.in. model
uzytkownika, fabryke i przykladowe migracje Laravel.

## Konfiguracja

- [`.env.example`](.env.example): parametry Compose; puste miejsca na sekrety.
- [`backend/.env.example`](backend/.env.example): dokumentacja konfiguracji Laravel.
- [`frontend/.env.example`](frontend/.env.example): publiczny URL API i cel proxy Vite.

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

Po uruchomieniu kontenerow:

```bash
# Izolowane przypadki health oraz testy z prawdziwym PostgreSQL i Redis.
docker compose exec -T backend php artisan test --filter=Health

# Cala podstawowa suita Laravel.
docker compose exec -T backend composer test

# Format PHP oraz walidacja metadanych zaleznosci.
docker compose exec -T backend vendor/bin/pint --test
docker compose exec -T backend composer validate --strict

# TypeScript, build Vite i lint frontendu.
docker compose exec -T frontend npm run build
docker compose exec -T frontend npm run lint

# Kontrola Compose bez wypisywania sekretow.
docker compose config --quiet

curl --fail-with-body http://localhost:8000/api/health
curl --fail-with-body http://localhost:5173/api/health
```

`HealthTest` sprawdza poprawna odpowiedz, awarie PostgreSQL, Redis, obu zaleznosci,
nieprawidlowy PING oraz logowanie bledow i brak szczegolow wyjatkow w JSON.
`HealthIntegrationTest` wymaga uruchomionych uslug Compose: sprawdza prawdziwe
polaczenia, cache Redis, dostepnosc kolejki oraz brak tabel w schemacie `public`.
Testy nie uruchamiaja migracji i usuwaja swoj losowy klucz cache.

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
  app/Http/Controllers/  HealthController
  config/                PostgreSQL, Redis, cache i kolejki
  routes/api.php         GET /api/health
  tests/Feature/         izolowane i integracyjne testy health
frontend/                React + TypeScript + Vite
  src/                   strona startowa i prezentacja statusu API
scripts/init-env.php     bezpieczne tworzenie lokalnej konfiguracji
compose.yaml             piec uslug oraz dwa trwale wolumeny
.env.example             szablon konfiguracji Compose bez sekretow
```
