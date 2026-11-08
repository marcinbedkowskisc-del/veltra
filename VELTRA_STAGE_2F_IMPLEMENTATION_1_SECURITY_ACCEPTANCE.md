# VELTRA 2.0 — Stage 2F — Implementation 1 Security Acceptance

## Zakres i werdykt

Niezależna weryfikacja dotyczyła wyłącznie niezmiennej głównej kuchni centralnej K2.8/K3.16: migracji 038, przypisywania i utrwalania primary, bootstrapu, ograniczeń PostgreSQL oraz powiązanych testów autoryzacji i współbieżności.

**Werdykt: PASS dla zweryfikowanego zakresu na świeżym, odizolowanym PostgreSQL 17.11.** Nie znaleziono podatności pozwalającej zmienić, usunąć, przenieść lub odtworzyć designation primary przez runtime SQL. Bootstrap używa wyłącznie zweryfikowanego primary, a nierozstrzygnięte legacy pozostaje fail-closed. Werdykt nie oznacza wdrożenia ani zgody na migrację istniejącej instalacji.

## Środowisko i izolacja

- Repozytorium: `/home/veltra/projekty/veltra`; runner zbudowano z bieżącego katalogu `backend` na bazie lokalnego obrazu PHP/Laravel. SHA-256 migracji, modeli, bootstrapu i trzech użytych plików testowych w runnerze były identyczne z checkoutem.
- Osobny projekt Compose: `veltra-pk-security-20261108`; PostgreSQL 17.11, runner PHP i efemeryczny Redis. Baza testowa `veltra_testing` znajdowała się w osobnym klastrze i osobnym wolumenie.
- Klaster testowy: `system_identifier=7705775273414254626`; klaster instalacyjny: `7703356249159311394`. Wartości różne. Testowa sieć Compose miała `internal=true`; nie była współdzielona z siecią instalacji. Runner nie miał wolumenów instalacyjnych.
- Właścicielem bazy testowej był `veltra_test_admin`; rola służyła jedynie do inicjalizacji jednorazowego klastra i kontrolowanego syntetycznego fixture’a.
- `veltra_app`: `NOSUPERUSER`, bez `CREATEDB`, `CREATEROLE`, `BYPASSRLS`, prawa `CREATE` do bazy ani schematu. Połączenie Laravel potwierdziło `current_user=session_user=veltra_app`.
- `veltra_migrator`: odrębna rola bez superuser, `CREATEDB`, `CREATEROLE` i `BYPASSRLS`; miała `CREATE` do testowej bazy i schematu `public`, którego była właścicielem. Migracje wykonano wyłącznie tą rolą.
- Istniejące kontenery instalacyjne pozostały uruchomione. Nie wykonywano na nich migracji ani zapisów; w istniejącej bazie wykonano tylko odczyt identyfikatora klastra i stanu modułu RAW.
- Po zakończeniu usunięto projekt testowy, bazę/wolumen, obraz testowy, tymczasowy Dockerfile i losowe sekrety. Istniejące usługi nie zostały zatrzymane ani zrekonfigurowane.

## Polecenia i wyniki

Poniższe polecenia używały tymczasowego pliku sekretów Compose (jego wartości nie są raportowane; plik został usunięty po teście). Wspólne argumenty Compose:

```text
--env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/primary-kitchen-security-test.env
--project-directory /home/veltra/projekty/veltra
-p veltra-pk-security-20261108
-f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/primary-kitchen-security-test-compose.yaml
```

Wykonano:

```text
docker compose <wspólne argumenty> config --quiet
docker compose <wspólne argumenty> up -d --build
docker compose <wspólne argumenty> exec -T test-runner sh -lc 'DB_USERNAME=veltra_migrator DB_PASSWORD="$APP_DB_MIGRATION_PASSWORD" php artisan migrate --force'
docker compose <wspólne argumenty> exec -T test-runner php artisan test --compact tests/Feature/InstallationDomainTest.php tests/Feature/AccountSecurityTest.php tests/Feature/PrimaryCentralKitchenConcurrencyTest.php
docker compose <wspólne argumenty> exec -T test-runner php artisan test --compact tests/Feature/InstallationApiTest.php
docker compose <wspólne argumenty> exec -T test-runner php artisan test --compact tests/Feature/InstallationDomainTest.php tests/Feature/AccountSecurityTest.php tests/Feature/PrimaryCentralKitchenConcurrencyTest.php --filter='test_first_central_kitchen_is_primary|test_legacy_installation_without_verified_primary|test_runtime_sql_and_database_owner_cannot_change_primary_identity|test_bootstrap_uses_the_verified_primary|test_bootstrap_fails_closed_for_an_unresolved_legacy_kitchen|test_concurrent_creation_uses_the_first_committed_central_kitchen'
git diff --check
```

| Kontrola | Wynik |
|---|---:|
| Migracje na pustej bazie izolowanej | **38/38 PASS**, w tym migracja 038 |
| Pełny focused suite primary/bootstrap/concurrency | **32 testy, 155 asercji — PASS** |
| Location API regressions | **13 testów, 127 asercji — PASS** |
| Ponowiony zestaw krytycznych przypadków | **7 testów, 35 asercji — PASS** |
| `git diff --check` | **PASS** |

Testy pokryły pierwszą i kolejne kuchnie centralne, niezmienność przez runtime i rolę właściciela migracji, bootstrap przy kolejności kodów sprzecznej z primary, nierozstrzygnięte legacy oraz dwie rzeczywiste ścieżki współbieżności na niezależnych połączeniach PostgreSQL: commit i rollback pierwszego INSERT.

## Dodatkowe próby adwersarialne

Utworzono wyłącznie syntetyczną organizację i primary/secondary w testowej bazie. Fixture przygotowano jednorazową rolą administracyjną testowego klastra; po przygotowaniu przywrócono normalne działanie triggerów. Wszystkie próby mutacji wykonano następnie jako ograniczony `veltra_app`, który miał prawa tabelaryczne do `UPDATE`, `DELETE` i `INSERT`, ale nie miał praw `CREATE`.

| Próba runtime SQL | Wynik |
|---|---|
| Zdjęcie znacznika primary z dotychczasowej kuchni | **Odrzucono**, SQLSTATE `42501` |
| Nadanie znacznika kuchni secondary | **Odrzucono**, SQLSTATE `42501` |
| Usunięcie primary | **Odrzucono**, SQLSTATE `42501` |
| Zmiana identyfikatora rekordu primary | **Odrzucono**, SQLSTATE `42501` |
| Zmiana `organization_id` rekordu primary | **Odrzucono**, SQLSTATE `42501` |

Po próbach pozostał dokładnie jeden primary, a pierwotny rekord nadal był oznaczony. Próby nie zmieniły stanu fixture’a.

Niezależna inspekcja schematu po migracji potwierdziła indeks częściowy `locations_one_primary_central_kitchen_per_org` oraz aktywne triggery przypisania primary, immutability, serializacji i autoryzacji mutacji. Nie znaleziono podatności w przeglądzie security-review. Testy API potwierdziły działanie standardowego tworzenia, walidacji i aktualizacji lokalizacji; designation primary nie jest wybierane przez ścieżkę bootstrapu według kodu ani ID.

## Nieudane przebiegi harnessu

- Pierwsze budowanie Compose nie zaakceptowało Dockerfile położonego poza kontekstem builda. Utworzono tymczasowy Dockerfile w kontekście backendu, używając istniejącego lokalnego obrazu; usunięto go po testach.
- Pierwsza próba migracji została przerwana, ponieważ syntetyczny klucz podpisujący nie miał wymaganego formatu 32 bajtów zapisanych jako 64 znaki hex. Wygenerowano nowy klucz testowy, odtworzono wyłącznie izolowany wolumen i migracje 001–038 przeszły. Nie był to błąd migracji ani aplikacji.
- Pierwszy skrypt weryfikujący wynik SQL miał błąd cytowania i zbyt ścisłe porównanie typu liczbowego w asercji. Poprawiona, parametryzowana weryfikacja potwierdziła odmowy i niezmieniony stan primary.

## Instalacja i RAW

`system_identifier` instalacyjnego PostgreSQL pozostał `7703356249159311394`. Kontenery instalacyjne pozostały uruchomione. Odczyt w bazie instalacyjnej zwrócił `0` skonfigurowanych i `0` włączonych wpisów `RAW_MATERIALS`; nie uruchomiono migracji ani testów przeciw tej bazie. RAW nie został aktywowany i pozostaje `NOT_READY`.

## Podatności i decyzja końcowa

**Nie stwierdzono podatności w zbadanym zakresie.** Runtime SQL nie może zmienić, usunąć, przenieść ani ponownie przypisać designation primary; ograniczenie unikalne dopuszcza najwyżej jeden primary na organizację; bootstrap używa wyłącznie zweryfikowanego primary; legacy bez ustalonej tożsamości pozostaje fail-closed.

**PASS — niezależna akceptacja bezpieczeństwa zakresu K2.8/K3.16 na świeżym, izolowanym PostgreSQL 17.11.** Instalacje istniejące nadal wymagają osobnego, zatwierdzonego ustalenia historycznej tożsamości primary przed zastosowaniem migracji. Brak wdrożenia i brak zmian w kodzie aplikacji w ramach tej weryfikacji.
