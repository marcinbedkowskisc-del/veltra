# VELTRA 2.0 — Stage 2F — Implementation 2: Independent Security Acceptance

**Werdykt bezpieczeństwa: FAIL.** Niezależne testy odtworzyły ścieżkę, w której zwykły administrator może dezaktywować primary central kitchen, przez co pierwotna tożsamość przestaje być rozpoznawana jako SUPER ADMIN i traci możliwość zarządzania innymi administratorami. W tej akceptacji nie zmieniano kodu aplikacji ani migracji.

## 1. Zakres i wynik

Przejrzano zatwierdzone decyzje K2.1–K2.9 w `VELTRA_STAGE_2F_K2_APPROVED_DECISIONS_SNAPSHOT.md`, raport implementacji oraz bieżący kod usług autoryzacji, struktury, identity/recovery, ról, członkostw i uprawnień RAW. Wykonano niezależny build i testy na nowej, odizolowanej bazie PostgreSQL 17.

### Znaleziona podatność

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | 🟡 MEDIUM | [BusinessAuthorization.php](./backend/app/Domain/Authorization/BusinessAuthorization.php#L48) | 48 | Dezaktywacja primary central kitchen odbiera pierwotnej tożsamości status SUPER ADMIN i możliwość zarządzania administratorami. Zwykły administrator może wykonać tę zmianę przez API, gdy kuchnia nie obsługuje aktywnej restauracji. | 9/10 |

**Dowód odtworzenia:** tymczasowy test syntetyczny utworzył primary kitchen bez aktywnych zarządzanych restauracji, drugą aktywną kuchnię, pierwotną tożsamość oraz dwóch zwykłych administratorów z członkostwami w drugiej kuchni. Jako jeden ze zwykłych administratorów wysłano w aktywnym kontekście drugiej kuchni:

```http
PATCH /api/v1/security/locations/{primary_kitchen_id}
Content-Type: application/json

{"is_active": false}
```

Wynik testu: **HTTP 200**, `primary_active=false`, `super_admin_status=false`. Następna próba pierwotnej tożsamości nadania roli dla innego administratora zakończyła się `AuthorizationException`. Test oczekujący, że primary i status pozostaną nienaruszone, zakończył się celowo niepowodzeniem: **1 failed (4 assertions)**.

Powiązane ścieżki:

- [SecurityStructureController.php](./backend/app/Http/Controllers/Api/V1/SecurityStructureController.php#L41) dopuszcza mutację struktury dla aktora z rolą ADMINISTRATOR; przy dezaktywacji sprawdza jedynie, czy pozostanie używalny administrator.
- [InstallationService.php](./backend/app/Domain/Installation/InstallationService.php#L63) blokuje dezaktywację kuchni tylko wtedy, gdy zarządza aktywną restauracją. Nie wyklucza primary kitchen.
- [BusinessAuthorization::isSuperAdmin](./backend/app/Domain/Authorization/BusinessAuthorization.php#L39) wymaga, aby primary kitchen była aktywna.
- Funkcja `super_admin_actor` w migracji [2026_11_08_000039_enforce_super_admin_invariants.php](./backend/database/migrations/2026_11_08_000039_enforce_super_admin_invariants.php#L15) również wymaga aktywnej primary. Migracja tworzy guardy dla kont, ról, członkostw i grantów, ale nie dla `locations` — patrz lista triggerów w liniach 323–346.
- Migracja [2026_11_07_000038_enforce_immutable_primary_central_kitchen.php](./backend/database/migrations/2026_11_07_000038_enforce_immutable_primary_central_kitchen.php#L62) chroni niezmienność flagi designation, ale nie blokuje zmiany `is_active`.

Jest to naruszenie ochrony K2.6 przed utratą statusu SUPER ADMIN. Nie zmieniono ani nie przeniesiono flagi `is_initial_identity` ani oznaczenia primary; utrata wynika z zależności wyliczanego statusu od aktywności lokacji.

## 2. Środowisko testowe i separacja

- Utworzono nowy projekt Compose `veltra-super-admin-security-acceptance-20261108` na podstawie aktualnego katalogu źródłowego `backend`.
- Stos ma własny kontener PostgreSQL 17.11, sieć `internal=true` i własny wolumen `veltra-super-admin-security-acceptance-20261108_super-admin-pgdata`; zawiera również oddzielne Redis i ClamAV. Nie dołączono go do sieci instalacyjnej.
- Cel wszystkich testów: baza `veltra_testing` w kontenerze `veltra-super-admin-security-acceptance-20261108-postgres-1`.
- Świeża baza otrzymała **39/39 migracji**, w tym 038 i 039. Właścicielem tabeli `organizations` jest `veltra_migrator`.
- Zweryfikowane role: `veltra_app` i `veltra_migrator` mają `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`; połączenie aplikacyjne zwróciło `veltra_testing|veltra_app|veltra_app|false`. `veltra_test_admin` jest rolą administracyjną tylko wewnątrz jednorazowego kontenera testowego, nie używano jej jako roli aplikacji.
- Testowy `TestCase` dodatkowo potwierdza w każdym teście, że połączenie domyślne używa `veltra_app`, tabela należy do `veltra_migrator`, a rola runtime nie ma uprawnień do `TRUNCATE` ani `CREATE` w schemacie.
- Identyfikatory sześciu kontenerów instalacyjnych były takie same przed i po testach: `veltra-queue-1`, `veltra-backend-1`, `veltra-frontend-1`, `veltra-mailpit-1`, `veltra-redis-1`, `veltra-postgres-1`. Nie wykonywano operacji na instalacyjnej bazie, kontenerach ani danych.

Kontrole środowiska:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml \
  exec -T postgres psql -U veltra_test_admin -d veltra_testing -Atc \
  "SELECT current_database(), version(); SELECT rolname, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname IN ('veltra_app','veltra_migrator','veltra_test_admin') ORDER BY rolname;"

docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml \
  exec -T postgres sh -lc 'PGPASSWORD="$APP_DB_PASSWORD" psql -h 127.0.0.1 -U veltra_app -d veltra_testing -Atc "SELECT current_database(), current_user, session_user, (SELECT rolsuper FROM pg_roles WHERE rolname=current_user);"'

docker ps --format '{{.ID}} {{.Names}}'
docker ps --filter label=com.docker.compose.project=veltra --format '{{.ID}} {{.Names}}'
docker network inspect veltra-super-admin-security-acceptance-20261108_isolated --format 'internal={{.Internal}} containers={{range .Containers}}{{.Name}} {{end}}'
```

## 3. Wykonane testy

Polecenia poniżej używały pliku środowiskowego z syntetycznymi sekretami; wartości sekretów nie są raportowane.

### Budowa, uruchomienie i migracje

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml build backend

docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml up -d

docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml \
  exec -T backend sh -lc 'DB_USERNAME=veltra_migrator DB_PASSWORD="$APP_DB_MIGRATION_PASSWORD" php artisan migrate --force'
```

Wynik migracji: wszystkie migracje od `2026_11_02_000001` do `2026_11_08_000039` zakończone `DONE` w nowej bazie.

### Testy skupione i pełna regresja

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml \
  exec -T backend php artisan test \
  tests/Feature/SuperAdminAuthorizationTest.php \
  tests/Feature/SecurityApiTest.php \
  tests/Feature/AdministratorConcurrencyTest.php \
  tests/Feature/AuthorizationMutationBoundaryTest.php \
  tests/Feature/RawMaterialPermissionBootstrapTest.php \
  tests/Feature/RawMaterialConcurrencyTest.php \
  tests/Feature/InstallationDomainTest.php \
  tests/Feature/PrimaryCentralKitchenConcurrencyTest.php \
  tests/Feature/AccountSecurityTest.php
```

Wynik: **68 passed, 685 assertions**, 87,70 s. Pokryte m.in. status jedynej pierwotnej tożsamości, sześć ról, fail-closed legacy, zarządzanie kontami, ochrona konta i członkostwa SUPER ADMIN, samoedycja i ponowna weryfikacja e-mail, blokada samoodebrania roli, bootstrap i niezmienność primary oraz regresje API.

Tymczasowy test exploitu primary został skopiowany do kontenera przez `docker cp` i uruchomiony poleceniem:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml \
  exec -T backend php artisan test tests/Feature/TemporaryPrimaryKitchenDeactivationAcceptanceTest.php
```

Wynik: **1 failed (4 assertions)**, z reprodukcją HTTP 200 oraz utratą statusu i możliwości zarządzania administratorami. Test plikowy usunięto z repozytorium i kontenera po zebraniu wyniku.

Niezależne próby runtime SQL wykonano tymczasowym testem `TemporarySuperAdminSqlAcceptanceTest` pod domyślnym, zweryfikowanym połączeniem `pgsql`/`veltra_app`:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml \
  exec -T backend php artisan test tests/Feature/TemporarySuperAdminSqlAcceptanceTest.php
```

Wynik końcowy: **1 passed (14 assertions)**; brak częściowego stanu po odmowie. Sprawdzono próbę ustawienia `is_initial_identity` dla drugiego użytkownika, usunięcia tej flagi z początkowej tożsamości, bezpośredniego nadania roli ADMINISTRATOR, dezaktywacji członkostwa, usunięcia chronionego użytkownika i bezpośredniego przyznania `FOODCOST_VIEW`. Wszystkie próby runtime zostały odrzucone (SQLSTATE `42501` dla prób ograniczonych uprawnieniami; usunięcie tożsamości również odrzucone). Pierwszy test-runner wynik błędnie oczekiwał `23514` przy zmianie `is_initial_identity`; rzeczywista odmowa nastąpiła wcześniej na ACL jako `42501`. Po dostosowaniu oczekiwanego SQLSTATE test przeszedł. To był błąd oczekiwania testu, nie obejście zabezpieczenia.

Pełny backend suite:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-security-acceptance-20261108 \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml \
  exec -T backend php artisan test
```

Wynik: **270 passed (2632 assertions)**, 287,60 s. W pełnym suite pozytywnie przeszły m.in. testy concurrency dla równoczesnych zmian statusu administratora, nadania ról SUPER ADMIN, API, Stage 1D/1E, resetu i aktywacji kont, oraz gotowości RAW.

Łącznie w raportowanych uruchomieniach PHPUnit wykonano **341 testowych wykonań** i uzyskano **3337 asercji**; wyniki obejmują powtórzenia testów między selektywnym a pełnym suite. Dwa testy pomocnicze miały początkową nieudaną iterację: oczekiwany, odtworzony exploit oraz korekta oczekiwanego SQLSTATE w teście ACL. Nie ukryto tych rezultatów.

## 4. Bezpośrednie SQL, audyt i współbieżność

- Ograniczony `veltra_app` nie może zmienić `is_initial_identity`, nadawać roli administratora, bezpośrednio dezaktywować członkostwa ani tworzyć grantów bez prawidłowej autoryzacji. Stan tożsamości, ról, członkostw i grantów po odmowie pozostał niezmieniony.
- Istniejące testy mutacji podpisanych również przeszły: próby bez podpisu są odrzucane, zakres podpisu jest ograniczony, aktor audytu musi odpowiadać aktorowi biletu. Testy asercji audytu sprawdzają zdarzenia zaakceptowane i odrzucone.
- Rzeczywiste procesy/połączenia PostgreSQL w testach współbieżności potwierdziły serializację zmian statusu administratora dla zatwierdzenia i rollbacku, konkurencyjnych nadań ról SUPER ADMIN oraz równoległego tworzenia kuchni centralnych. Wszystkie te istniejące testy przeszły.
- Różnica: API zwykłego administratora dla `structure.update` może wykonać prawidłowo podpisaną zmianę `locations.is_active`; nie jest to surowe SQL bez biletu. Dla primary kitchen brakuje jednak odrębnej ochrony biznesowej, co umożliwia odtworzoną podatność.

## 5. Regresje i ograniczenia

- Zachowano sześć ról; test nie wykazał siódmej roli ani dodatkowych rekordów grantów użytkownika/organizacji dla SUPER ADMIN. Status specjalny nie jest używany do automatycznego nadania osobnego grantu finansowego; obowiązuje rola ADMINISTRATOR i istniejące reguły.
- Oznaczenie primary pozostaje niezmienne, bootstrap wybiera zweryfikowane primary, a nierozstrzygnięte legacy pozostaje fail-closed. Wykryta podatność dotyczy aktywności primary, nie jej flagi tożsamości.
- Testy pełnego suite potwierdzają `RAW_MATERIALS` jako `NOT_READY`; modułu nie aktywowano.
- W bieżącym backendzie nie znaleziono odrębnego workflow formalnego wycofania/retirement RAW ani testu zatwierdzenia przez dwóch różnych administratorów. Kod Implementation 2 nie modyfikował tego obszaru, dlatego nie ma podstaw do raportowania runtime PASS dla tej konkretnej gwarancji; pozostaje to niezweryfikowane.
- Testy sprawdziły kod zbudowany z bieżącego katalogu repozytorium. Nie uruchomiono wdrożenia ani migracji istniejącej instalacji. Nie zmieniano kodu aplikacji, migracji produkcyjnych, konfiguracji instalacji ani reguł biznesowych. Pliki dwóch tymczasowych testów usunięto po wykonaniu.
- **Ocena końcowa: FAIL / brak akceptacji bezpieczeństwa Implementation 2** do czasu osobnej, zatwierdzonej naprawy i ponownej niezależnej weryfikacji. Wykrycie podatności nie jest zgodą na jej naprawę w ramach tego zadania.
