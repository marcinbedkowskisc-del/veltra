# VELTRA 2.0 — Stage 7A.0.1 — Przygotowanie wdrożenia Stage6a1

## Werdykt

**READY FOR LOCAL DEPLOYMENT**

Zmiana gotowości `BOSS_RAW_CATALOG_READ` jest przygotowana w kodzie i przeszła testy celowane. Kontrole zgodności bazy `veltra_stage6a1` nie wykazały blokujących konfliktów. Ten raport nie oznacza wdrożenia: migracji, budowania obrazów Stage6a1, wymiany kontenerów ani aktywacji capability nie wykonano. Każda z tych operacji wymaga osobnego zatwierdzenia wdrożenia.

## Stan repozytorium i zakres

- Repozytorium: `/home/veltra/projekty/veltra`
- Gałąź: `feature/stage7a-rbac-alignment`
- HEAD: `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`
- Worktree pozostaje nieczysty z wcześniej zaakceptowanymi, niezacommitowanymi zmianami Stage 7A.0.1. Zachowano je; nie wykonano commit, push, merge, reset ani stash.
- Nie zmieniano `veltra-primary` ani `veltra-pa07a-preview`.
- W tym zadaniu zmieniono wyłącznie deklarację gotowości capability i powiązane testy; dodano ten raport.

## Gotowość capability i testy

[`CodeOrganizationCapabilityCatalog`](./backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php) deklaruje `BOSS_RAW_CATALOG_READ` jako `ready: true`, w zakresie organizacji, dla kuchni centralnych i restauracji, z operacjami wyłącznie `list` i `detail`.

Zmiana readiness jest odrębna od stanu aktywacji w bazie oraz od modułu `RAW_MATERIALS`:

- Endpointy statusu, konfiguracji i historii capability są w [`OrganizationCapabilityController`](./backend/app/Http/Controllers/Api/V1/OrganizationCapabilityController.php): `GET`/`PUT /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ` oraz `GET .../history`.
- Konfiguracja pozostaje dostępna wyłącznie dla uprawnionego administratora, wymaga ponownego potwierdzenia hasła i `expected_state_version`; przejścia oraz odrzucenia są audytowane.
- Testy potwierdziły gotowy, lecz domyślnie wyłączony stan; poprawną aktywację/dezaktywację, kontrolę wersji, odmowę dla niegotowego capability, granice administratora i brak wpływu na `RAW_MATERIALS`.
- W `veltra_stage6a1` nie ma wiersza stanu dla `BOSS_RAW_CATALOG_READ`; pozostaje `DISABLED`, wersja 0. Nie wykonano aktywacji ani bezpośredniej zmiany SQL.
- Nie zmieniono readiness ani aktywacji `RAW_MATERIALS`.

RAW w zamierzonej instancji Stage6a1 jest efektywnie dostępny jako `READY` i `ENABLED`: [`LocalRawMaterialCatalog`](./backend/app/Domain/Modules/LocalRawMaterialCatalog.php) stosuje lokalną integrację Stage6a1, a baza ma aktywny wpis modułu dla lokalizacji. Produkcyjny katalog [`DefinedModuleCatalog`](./backend/app/Domain/Modules/DefinedModuleCatalog.php) nadal domyślnie oznacza `RAW_MATERIALS` jako niegotowy. Stan modułu nie jest zmieniany przez gotowość ani aktywację capability BOSS.

### Wyniki walidacji

- Migracje w świeżej bazie testowej `veltra_testing`: **001–063 zakończone poprawnie**. Testowa baza działała na odrębnym daemonie `disposable-test`; nie była użyta baza Stage6a1.
- Po testach izolowane kontenery PostgreSQL i Redis nadal działają na `unix:///run/user/1000/veltra-test-docker/docker.sock`; testowa baza zawiera migracje i dane testowe. Nie jest celem wdrożenia. Usługi Stage6a1 pozostały na tych samych obrazach i w stanie healthy.
- PHPUnit, testy celowane: **65 testów, 761 asercji, OK**, czas 1:05. Obejmowały katalog i gotowość capability, granice aktywacji i audyt, integralność/współbieżność, dostęp BOSS do katalogu RAW, zachowanie read-only oraz macierz uprawnień finansowych.
- Laravel Pint: **PASS, 6 plików** związanych z readiness i testami.
- `git diff --check` dla tych zmian: **PASS**.
- PHP CLI nie jest zainstalowane na hoście; PHPUnit i Pint uruchomiono w odizolowanych kontenerach projektu testowego.
- Pełnej regresji, testów frontendowych ani przeglądarkowego E2E nie powtarzano — ten zakres nie zmienił frontendu i wcześniejsza regresja Stage 7A.0.1 została zaakceptowana.

Pliki testowe zmienione w zakresie readiness:

- [`OrganizationCapabilityCatalogFixtures.php`](./backend/tests/Support/OrganizationCapabilityCatalogFixtures.php)
- [`OrganizationCapabilityCatalogTest.php`](./backend/tests/Unit/OrganizationCapabilityCatalogTest.php)
- [`OrganizationCapabilityTest.php`](./backend/tests/Feature/OrganizationCapabilityTest.php)
- [`OrganizationCapabilityAuditFailureTest.php`](./backend/tests/Feature/OrganizationCapabilityAuditFailureTest.php)
- [`BossRawMaterialCatalogAuthorizationTest.php`](./backend/tests/Feature/BossRawMaterialCatalogAuthorizationTest.php)

## Zweryfikowany cel wdrożenia

Wyłącznie `veltra-stage6a1` jest celem lokalnego developmentu i acceptance:

| Składnik | Stan zweryfikowany |
|---|---|
| Frontend | `127.0.0.1:5174`, healthy, obraz `veltra-stage6a1-frontend:rc-22e3320-20261124-unified-raw` |
| Backend | `127.0.0.1:8001`, healthy, obraz `veltra-stage6a1-backend:rc-795356faae84-20261123` |
| Queue | działa na obrazie backendu `rc-795356faae84-20261123` |
| PostgreSQL | healthy, obraz hardened `veltra-postgres:17-alpine`, brak portu na hoście |
| Wolumen PostgreSQL | `veltra-stage6a1_postgres-data`, zamontowany w `/var/lib/postgresql/data` |
| Redis / Mailpit | healthy; Mailpit na `127.0.0.1:8026` |
| Tożsamość bazy | `current_database() = veltra_stage6a1`; kontrolę wykonano w transakcji `READ ONLY` |

Odczyt migracji wykonany rolą administracyjną PostgreSQL w `READ ONLY`:

- 61 wpisów migracji, ostatnia: `2026_11_23_000059_retire_raw_material_supplier_compatibility_workflow`, batch 5.
- Migracje 060–063: **niezastosowane**.
- Wcześniejsze odczytowe kontrole Stage6a1 wykazały: 0 konfliktów wielu aktywnych ról, 0 aktywnych zweryfikowanych kont bez dokładnie jednej efektywnej roli i 0 organizacji z więcej niż jedną kuchnią centralną. Nie znaleziono częściowych obiektów migracji 060–062; wymagane `btree_gist` jest obecne.
- Aktualny stan bazy ma 0 wierszy `BOSS_RAW_CATALOG_READ`; aktywacja pozostaje wyłączona.

Runtime Compose labels potwierdzają, że backend jest uruchomiony z `compose.yaml`, `compose.stage6a1.yaml` i [`compose.stage6a1.release-795356faae84.yaml`](./compose.stage6a1.release-795356faae84.yaml). PostgreSQL ma dodatkowy override z session-state. Frontend ma taki sam zestaw plików oraz dodatkowy override z session-state, którego wskazany plik już nie istnieje. Nie należy odtwarzać całego runtime zestawu Compose z nieistniejącym override ani używać release override do budowania nowych obrazów — przypina on stare tagi. Do przygotowania nowych tagów służą wersjonowane w repozytorium [`compose.yaml`](./compose.yaml) i [`compose.stage6a1.yaml`](./compose.stage6a1.yaml), z wcześniej zweryfikowanym, chronionym plikiem środowiska Stage6a1. Nie używać repozytoryjnego `.env` bez sprawdzenia celu.

## Procedura wdrożenia po osobnym zatwierdzeniu

Poniższe czynności nie zostały wykonane. Przed każdą z nich należy ponownie potwierdzić Docker context `default`, projekt/kontenery Stage6a1, bazę `veltra_stage6a1`, zewnętrzny wolumen oraz bezpieczny plik środowiskowy Stage6a1. W poleceniach `<STAGE_ENV>` oznacza jego dokładną ścieżkę podaną przez operatora; nie ujawniać zawartości ani sekretów.

### 1. Backup i sprawdzenie odtwarzalności

W uzgodnionym oknie wstrzymać zapisy aplikacji i kolejki. Umieścić backupy w zabezpieczonym katalogu, z prawami tylko dla właściciela; dump globals zawiera wrażliwe dane ról:

```bash
umask 077
mkdir -p /secure/backup/veltra-stage6a1-stage7a
docker --context default exec veltra-stage6a1-postgres-1 \
  sh -lc 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' \
  > /secure/backup/veltra-stage6a1-stage7a/database.dump
docker --context default exec veltra-stage6a1-postgres-1 \
  sh -lc 'pg_dumpall -U "$POSTGRES_USER" --globals-only' \
  > /secure/backup/veltra-stage6a1-stage7a/globals.sql
sha256sum /secure/backup/veltra-stage6a1-stage7a/database.dump \
  /secure/backup/veltra-stage6a1-stage7a/globals.sql
docker --context default run --rm -v /secure/backup/veltra-stage6a1-stage7a:/backup \
  postgres:17-alpine pg_restore --list /backup/database.dump
```

Przed wdrożeniem wykonać próbne odtworzenie do **oddzielnej** testowej instancji/wolumenu, nie nadpisując oryginalnego wolumenu. Wykonać backup ponownie bezpośrednio przed migracjami.

### 2. Zbudowanie wymaganych obrazów

Zbudować backend i frontend z dokładnie zweryfikowanego worktree. Compose nada tagi lokalne; nie budować PostgreSQL, którego działający obraz jest już hardened:

```bash
docker --context default compose -p veltra-stage6a1 \
  --env-file <STAGE_ENV> -f compose.yaml -f compose.stage6a1.yaml \
  build backend frontend
```

Obrazy wymagane dla usług aplikacyjnych: `veltra-stage6a1-backend:local` (backend, migrate, queue) i `veltra-stage6a1-frontend:local`.

### 3. Migracje i rollout

Po sprawdzeniu backupu, preconditionów ról/lokalizacji i zatwierdzeniu okna migracyjnego:

```bash
docker --context default compose -p veltra-stage6a1 --profile migrations \
  --env-file <STAGE_ENV> -f compose.yaml -f compose.stage6a1.yaml \
  run --rm --no-deps migrate
docker --context default compose -p veltra-stage6a1 --profile migrations \
  --env-file <STAGE_ENV> -f compose.yaml -f compose.stage6a1.yaml \
  run --rm --no-deps migrate php artisan migrate:status
```

Laravel zastosuje kolejno:

1. `2026_11_24_000060_enforce_single_business_role`
2. `2026_11_24_000061_limit_central_kitchens`
3. `2026_11_24_000062_require_effective_business_role`
4. `2026_11_24_000063_authorize_identity_disable_token_cleanup`

Potwierdzić status `Ran` dla wszystkich czterech przed wymianą aplikacji. Jeśli walidacja migracji odrzuci istniejące dane, przerwać; nie przypisywać ról ani nie scalać lokalizacji automatycznie.

Po migracji i osobnym zatwierdzeniu rollout’u, używając konfiguracji Stage6a1 bez release override ze starymi tagami:

```bash
docker --context default compose -p veltra-stage6a1 \
  --env-file <STAGE_ENV> -f compose.yaml -f compose.stage6a1.yaml \
  up -d --no-deps backend queue frontend
```

Nie uruchamiać `down -v`, nie usuwać wolumenów i nie odtwarzać usług PostgreSQL/Redis/Mailpit w ramach rollout’u aplikacji.

### 4. Capability readiness, aktywacja i acceptance

Po wdrożeniu backendu sprawdzić jako uprawniony administrator status `READY` / `DISABLED`. Aktywacja nie jest automatyczna. Dopiero po osobnej zgodzie administrator może użyć istniejącego endpointu `PUT /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ` z `enabled=true`, aktualnym `expected_state_version` i ponownie potwierdzonym hasłem. Następnie sprawdzić `READY`, `ENABLED`, `available=true`, wzrost wersji i wpisy historii. Nie zmieniać `RAW_MATERIALS`.

Automatyczne acceptance po wdrożeniu:

- health API (`:8001/api/health`), frontend (`:5174`) i API przez proxy; stan zdrowia backendu, queue, PostgreSQL i Redis;
- status migracji 060–063 i status capability przed/po aktywacji;
- uwierzytelniony BOSS: katalog `list`/`detail`, organizacyjny zakres kuchni centralnych i restauracji, odmowa mutacji, ograniczenia lokalizacji i filtracja niedozwolonych pól finansowych;
- administrator: status, aktywacja/dezaktywacja, konflikt wersji, historia i audyt;
- RAW: odczyt, ceny/koszty zgodne z grantami, odrzucenie zapisu BOSS, eksporty/dokumenty z zachowaniem autoryzacji oraz działający moduł `RAW_MATERIALS`;
- wszystkie testy tworzące dane i testy przeglądarkowe uruchamiać wyłącznie w izolowanym środowisku testowym. Nie tworzyć testowych kont ani grantów w Stage6a1.

### 5. Rollback

Migracje 060–063 są tylko do przodu; `migrate:rollback` nie jest procedurą rollbacku. Przy błędzie zatrzymać rollout i nie aktywować capability. Zachować oryginalny wolumen oraz logi. Odtworzyć backup bazy i globals do oddzielnego wolumenu/instancji, zweryfikować integralność i smoke tests, a dopiero po potwierdzeniu odtworzenia skierować aplikację na poprzednie obrazy. Nie uruchamiać starego backendu na bazie po migracjach bez pełnego odtworzenia stanu sprzed migracji.

## Blokery i ograniczenia

- **Brak blokera danych lub testów dla gotowości lokalnego wdrożenia.** Zgodność migracji i invariants Stage6a1 zostały sprawdzone; readiness i testy celowane przechodzą.
- Obrazy Stage6a1 nadal są starsze i nie zawierają tej zmiany; obrazy `:local` nie zostały zbudowane. Migracje 060–063 są pending. Są to zaplanowane kroki wdrożeniowe, nie wykonane operacje.
- Plik środowiskowy Stage6a1 należy wskazać przez `<STAGE_ENV>` przed użyciem poleceń; jego sekretów nie odczytywano ani nie kopiowano do raportu.
- Wskazany przez frontend runtime-only override Compose nie istnieje. Używać repozytoryjnych plików Compose Stage6a1 do budowania i rollout’u aplikacji; ponownie sprawdzić konfigurację przed wykonaniem jakiejkolwiek operacji.
- Capability pozostaje w bazie wyłączona. Wdrożenie kodu nie aktywuje jej samoistnie; aktywacja wymaga uwierzytelnionego administratora i odrębnej zgody.
- Nie zmieniono żadnej działającej bazy, konta, grantu, modułu, capability state, obrazu ani kontenera. Nie wykonano migracji, wdrożenia ani pełnej regresji.
