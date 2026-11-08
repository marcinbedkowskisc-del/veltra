# VELTRA 2.0 — Stage 2F — Remediation primary central kitchen

## Wynik

Naprawa potwierdzonej ścieżki została zaimplementowana lokalnie. Ukierunkowane testy i pełny backend PHPUnit przechodzą. **To nie jest niezależna akceptacja bezpieczeństwa** — wymagana jest osobna re-audytowa weryfikacja właściciela.

## Przyczyna

Status SUPER ADMIN jest wyliczany z aktywności oznaczonej primary central kitchen. `SecurityStructureController` pozwalał administratorowi wykonać podpisaną zmianę struktury, a `InstallationService::updateLocation()` nie odrzucał dezaktywacji primary. Migracja 038 chroniła usunięcie i designation, lecz nie `is_active`; trigger migracji 039 nie obejmował tabeli `locations`.

## Zmiany

- `backend/app/Domain/Installation/InstallationService.php` — odrzuca dezaktywację primary, niezależnie od roli aktora; odrzuca też utworzenie nieaktywnej kuchni, jeśli byłaby primary.
- `backend/database/migrations/2026_11_08_000040_keep_primary_central_kitchen_active.php` — nowa, forward-only migracja. Trigger PostgreSQL odrzuca nieaktywną primary przy INSERT/UPDATE (`SQLSTATE 23514`), również dla prawidłowo podpisanych mutacji. Migracja przerwie się, jeśli zastanie już nieaktywną primary; nie reaktywuje jej ani nie przypisuje nowej.
- `backend/lang/pl/messages.php` — polski komunikat reguły biznesowej.
- Testy: `backend/tests/Feature/SuperAdminAuthorizationTest.php`, `InstallationDomainTest.php`, `IdentityMembershipTest.php`, `PrimaryCentralKitchenConcurrencyTest.php` oraz `backend/tests/Support/concurrent_deactivate_primary_kitchen.php`.

Pozostałe gwarancje są zachowane: migracja 001 nadal blokuje zmianę ID, typu i organizacji lokacji; migracja 038 nadal blokuje usunięcie primary i zmianę designation. Nierozstrzygnięte instalacje legacy nie otrzymują zgadywanej primary. Dezaktywacja nie-primary nadal działa w dotychczasowych autoryzowanych warunkach.

## Weryfikacja i wyniki

| Sprawdzenie | Wynik |
|---|---:|
| Test exploitu przed poprawką | 1 niezaliczony zgodnie z oczekiwaniem: HTTP 200 zamiast 409; 3 asercje |
| Testy ukierunkowane: SUPER ADMIN, lokacje, SQL, API, moduły i RBAC | 64 zaliczone, 501 asercji |
| Testy członkostw i uwierzytelniania/odzyskiwania | 25 zaliczonych, 198 asercji |
| Pełny backend PHPUnit | **276 zaliczonych, 2672 asercje** |
| PHP lint | 7 zmienionych plików PHP — PASS |
| Pint `--test` | 7 plików — PASS |
| `git diff --check` | PASS |
| Świeże migracje PostgreSQL 17 | 40/40, od 001 do 040 — PASS |
| Forward upgrade 039 → 040 | PASS; 040 zastosowana jako kolejna migracja |

Testy pokryły: odrzucenie dezaktywacji przez zwykłego administratora i SUPER ADMIN, odmowę dla nie-administratora, dozwoloną dezaktywację drugiej kuchni, bezpośredni SQL runtime, poprawną podpisaną próbę obejścia, próby usunięcia/przeniesienia designation/zmiany organizacji, równoległą próbę dezaktywacji z niezależnego procesu PostgreSQL, utrzymanie statusu SUPER ADMIN i możliwości zarządzania, bootstrap, konto i odzyskiwanie, nierozstrzygnięte legacy, finansowe RBAC oraz gotowość modułów. RAW_MATERIALS pozostał `NOT_READY`.

Pierwszy pełny przebieg po zmianie miał 2 niepowodzenia w dawnych testach `IdentityMembershipTest`, które próbowały dezaktywować pierwszą (obecnie chronioną) kuchnię. Zmieniono wyłącznie ich fixture na drugą, nie-primary kuchnię, zachowując cel testów; końcowy pełny suite przeszedł. Diagnostyczny, samodzielny przebieg `IdentityAuthenticationTest` raz zgłosił 2 błędy zakresu podpisu przy dezaktywacji konta; ponowny przebieg razem z `IdentityMembershipTest` (25/25) oraz pełny suite (276/276) przeszedł. Kod tego niezwiązanego zakresu nie był zmieniany.

Pierwsza próba `migrate:fresh` na już zmigrowanej bazie nie powiodła się, ponieważ `migrate:fresh` pozostawiło schemat `veltra_private`, który następnie ponownie tworzy migracja 022. Wyłącznie izolowaną bazę testową odtworzono, po czym wszystkie migracje 001–040 przeszły od czystej bazy. Nie użyto tego nieudanego resetu na żadnym innym środowisku.

## Izolacja i komendy

Użyto projektu Compose `veltra-super-admin-remediation-20261108`, własnej sieci `internal=true` i własnego wolumenu PostgreSQL. Baza docelowa została przed migracjami zweryfikowana jako `veltra_testing` w PostgreSQL 17.11. Role runtime `veltra_app` i migracyjna `veltra_migrator` miały `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`; migracje wykonywano jako `veltra_migrator`, testy jako `veltra_app`. Kontenery instalacyjne przed i po miały te same identyfikatory: `a61deacc4c96`, `3404f4d7ce0a`, `9f1af421474f`, `ef3880270725`, `581fcf39a65d`, `a1fed478ec4d`.

Komendy stosu i migracji (plik env zawiera wyłącznie syntetyczne wartości; nie raportowano sekretów):

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml config --quiet
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml build backend
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml up -d
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml up -d --force-recreate backend
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend sh -lc 'DB_USERNAME=veltra_migrator DB_PASSWORD="$APP_DB_MIGRATION_PASSWORD" php artisan migrate --force'
```

Przed `migrate:fresh` cel ponownie zweryfikowano jako `postgres|veltra_test_admin|t|veltra_testing`; odtworzono wyłącznie izolowaną bazę:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T postgres sh -lc 'psql -U veltra_test_admin -d postgres -c "DROP DATABASE veltra_testing WITH (FORCE)" && psql -U veltra_test_admin -d postgres -c "CREATE DATABASE veltra_testing OWNER veltra_test_admin" && psql -U veltra_test_admin -d veltra_testing -c "GRANT CONNECT ON DATABASE veltra_testing TO veltra_app, veltra_migrator; GRANT CREATE ON DATABASE veltra_testing TO veltra_migrator; GRANT USAGE, CREATE ON SCHEMA public TO veltra_migrator; GRANT USAGE ON SCHEMA public TO veltra_app"'
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend sh -lc 'DB_USERNAME=veltra_migrator DB_PASSWORD="$APP_DB_MIGRATION_PASSWORD" php artisan migrate --force'
```

Wynikiem tej `migrate` było 40/40 `DONE`. W osobnym kroku wcześniejsza baza zakończona na 039 otrzymała wyłącznie migrację 040.

Komenda odtwarzająca podatność przed naprawą:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend php artisan test tests/Feature/SuperAdminAuthorizationTest.php --filter=primary_central_kitchen_cannot_be_deactivated_but_other_kitchens_can
```

Komendy walidacji:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend php artisan test tests/Feature/SuperAdminAuthorizationTest.php tests/Feature/InstallationDomainTest.php tests/Feature/PrimaryCentralKitchenConcurrencyTest.php tests/Feature/SecurityApiTest.php tests/Feature/ModuleConfigurationTest.php tests/Feature/RoleAdministrationTest.php
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend php artisan test tests/Feature/IdentityMembershipTest.php tests/Feature/IdentityAuthenticationTest.php
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend php artisan test
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend sh -lc 'php -l app/Domain/Installation/InstallationService.php && php -l database/migrations/2026_11_08_000040_keep_primary_central_kitchen_active.php && php -l tests/Feature/SuperAdminAuthorizationTest.php && php -l tests/Feature/InstallationDomainTest.php && php -l tests/Feature/IdentityMembershipTest.php && php -l tests/Feature/PrimaryCentralKitchenConcurrencyTest.php && php -l tests/Support/concurrent_deactivate_primary_kitchen.php'
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend vendor/bin/pint --test app/Domain/Installation/InstallationService.php database/migrations/2026_11_08_000040_keep_primary_central_kitchen_active.php tests/Feature/SuperAdminAuthorizationTest.php tests/Feature/InstallationDomainTest.php tests/Feature/IdentityMembershipTest.php tests/Feature/PrimaryCentralKitchenConcurrencyTest.php tests/Support/concurrent_deactivate_primary_kitchen.php
git diff --check
```

Weryfikacja ról i liczby migracji:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -p veltra-super-admin-remediation-20261108 -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T postgres psql -U veltra_test_admin -d veltra_testing -Atc "SELECT current_database(), version(); SELECT rolname, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname IN ('veltra_app','veltra_migrator') ORDER BY rolname; SELECT count(*), min(migration), max(migration) FROM migrations;"
docker network inspect veltra-super-admin-remediation-20261108_isolated --format 'internal={{.Internal}} containers={{range .Containers}}{{.Name}} {{end}}'
docker ps --filter label=com.docker.compose.project=veltra --format '{{.ID}} {{.Names}}'
```

## Ograniczenia i bezpieczeństwo środowiska

- Migracji nie wykonano na instalacji. Kontenery, bazy, wolumeny ani konfiguracja instalacyjna nie były zmieniane; nie było wdrożenia, commit/push ani aktywacji RAW.
- Gałąź migracji odrzucająca stan „primary już nieaktywna” nie była osobno odtwarzana; wymagałaby zatwierdzonego, jawnego odzyskania danych.
- W repozytorium pozostają wcześniejsze niezacommitowane zmiany Implementation 2; nie były cofane ani modyfikowane przez tę remediation.
- **Ponowna, niezależna akceptacja bezpieczeństwa pozostaje wymagana.**
