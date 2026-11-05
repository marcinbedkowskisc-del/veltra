# VELTRA 2.0 — raport implementacji Stage 2D.4A

**Data weryfikacji:** 2026-11-05
**Środowisko:** wyłącznie DEVELOPMENT i odizolowane kontenery testowe
**Werdykt:** implementacja oraz dozwolona regresja zakończone powodzeniem. Raport nie stanowi zgody na wdrożenie produkcyjne.

## Zakres

Dodano integrację z ClamAV dla niezmiennych wersji dokumentów. Wyniki `PENDING`, `CLEAN`, `INFECTED` i `ERROR` są dopisywane do osobnej, niezmiennej historii powiązanej z dokładnym identyfikatorem organizacji, wersją dokumentu i jej SHA-256. Wyniki skanera zapisuje wyłącznie usługa skanowania w podpisanym zakresie `raw-material.document.scan-result`; początkowy `PENDING` powstaje w istniejącym zakresie mutacji dokumentu.

Przed skanowaniem usługa sprawdza obecność payloadu, jego rozmiar i SHA-256. Błąd odczytu, brak pliku, rozbieżność sumy, błąd lub timeout ClamAV skutkuje zapisem `ERROR` i pozostawia dokument zablokowany. Widok `raw_material_approved_compatibility_document_evidence` udostępnia tylko najnowszy wynik `CLEAN` dla dokładnej sumy kontrolnej i wersji oznaczonej jako dowód zgodności. Wynik późniejszego skanu nie usuwa wcześniejszej historii.

Nowe wersje dokumentów otrzymują początkowy `PENDING` i zadanie skanowania kolejki. Nie dodano tras ani publicznych upload/download endpointów; nie zmieniono wersji dokumentów, migracji 029 ani danych aplikacyjnych.

## Migracja i izolacja

- Dodano i zastosowano forward-only migrację `2026_11_06_000031_create_raw_material_document_scan_results.php` na istniejącej, jednorazowej bazie `veltra_testing` w PostgreSQL 17.11. Migracja 030 była ostatnią wcześniejszą migracją; 031 została zarejestrowana jako wykonana.
- Potwierdzono cel połączenia `veltra_testing`, odrębną sieć Docker `internal=true`, brak publikowanych portów oraz brak wolumenów danych: PostgreSQL i Redis korzystają z `tmpfs`; runner i ClamAV nie mają mountów. Kontenery aplikacyjne VELTRA są w osobnej sieci i nie zostały użyte.
- Migracje wykonał profil migracyjny z rolą `veltra_migrator`; testy aplikacyjne działały jako `veltra_app`. Oczekiwane ograniczenie dostępu runtime do tabeli rejestru migracji potwierdziło rozdział ról.
- ClamAV działał wyłącznie w tej samej sieci wewnętrznej. Testy używały wyłącznie syntetycznych treści oraz standardowego wzorca testowego EICAR.

## Wyniki weryfikacji

| Sprawdzenie | Wynik |
|---|---:|
| Migracja 031 na disposable PostgreSQL | PASS |
| Testy dokumentów i skanowania | **11 testów, 75 asercji; 0 niepowodzeń, błędów PHPUnit, ostrzeżeń PHPUnit i pominięć** |
| Pełna dozwolona regresja | **193 testy, 1553 asercje; 0 niepowodzeń, błędów PHPUnit, ostrzeżeń PHPUnit i pominięć** |
| Wykryte przypadki regresji przed filtrem | 210 |
| Ograniczone testy Stage 2C wyłączone z przebiegu | 17 |
| PHP lint zmienionych plików | PASS |
| Pint `--test` dla 17 plików PHP | PASS |
| ClamAV: syntetyczny plik czysty / EICAR | `CLEAN` / `INFECTED` |
| Testy bramki zgodności i obsługi awarii | PASS |

Pełna regresja zakończyła się komunikatem PHPUnit `OK (193 tests, 1553 assertions)`. Lista przed wykonaniem potwierdziła 210 przypadków, a filtr wykluczył wszystkie 15 testów `RawMaterialPermissionBootstrapTest` oraz dwa wskazane testy `AuthorizationMutationBoundaryTest` i `RoleAdministrationTest`. Nie uruchomiono ich ani nie odtwarzano w osobnych scenariuszach.

Testy bramki potwierdziły blokowanie dowodów `PENDING`, `INFECTED` i `ERROR`, dopuszczenie dokładnie powiązanego `CLEAN`, odmowę bezpośredniego zapisu autorytatywnego wyniku przez runtime, niezmienność historii skanów, izolację organizacji, powiązanie z SHA-256, obsługę brakującego pliku i zachowanie wcześniejszych wersji dokumentu.

## Błędy wykryte i poprawione

1. Pierwszy rzeczywisty przebieg ujawnił niezgodność protokołu clamd: komenda `INSTREAM` wymaga prefiksu `z`, a odpowiedź `VERSION` może kończyć się znakiem nowej linii/EOF. Poprawiono ramkowanie; testy z rzeczywistym ClamAV oraz próbki `CLEAN` i EICAR przeszły.
2. Syntetyczny scenariusz dowodu zgodności próbował zatwierdzić rewizję z nieznanym stanem alergenów. Fixture testowy uzupełniono o jawne, zatwierdzone oświadczenie `NO_KNOWN_ALLERGENS`; reguł biznesowych nie osłabiono.
3. Dwa testy zastępowały skaner już po utworzeniu usługi przechowującej jego zależność. Testy poprawiono tak, aby rozwiązywały usługę po wstrzyknięciu skanera testowego.

Po poprawkach nie potwierdzono pozostałych defektów aplikacyjnych w badanym zakresie.

## Ograniczenia i decyzje przed produkcją

ClamAV uruchomił się z wbudowaną bazą sygnatur `28171` (silnik `1.4.6`). Z powodu odizolowanej sieci próba pobrania nowszej bazy z `database.clamav.net` nie powiodła się. Nie zablokowało to testu EICAR, ale świeżość sygnatur nie została potwierdzona. Przed jakimkolwiek wdrożeniem wymagane są zatwierdzona polityka aktualizacji i monitorowania świeżości sygnatur oraz operacyjna konfiguracja ClamAV; nie jest to część obecnej implementacji.

RAW_MATERIALS i VSEF, gotowość produkcyjna oraz status Stage 2C pozostają bez zmian. Ewentualna gotowość modułu ustawiona w testach dotyczyła wyłącznie fixture disposable bazy. Nie wykonano wdrożenia, commitów ani pushy.

## Pliki objęte Stage 2D.4A

- `backend/database/migrations/2026_11_06_000031_create_raw_material_document_scan_results.php`
- `backend/app/Domain/RawMaterials/Documents/ClamAvDocumentScanner.php`
- `backend/app/Domain/RawMaterials/Documents/DocumentScanner.php`
- `backend/app/Domain/RawMaterials/Documents/DocumentScannerException.php`
- `backend/app/Domain/RawMaterials/Documents/DocumentScanOutcome.php`
- `backend/app/Domain/RawMaterials/Documents/DocumentScanStatus.php`
- `backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentScanService.php`
- `backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php`
- `backend/app/Jobs/ScanRawMaterialDocumentVersion.php`
- `backend/app/Domain/Authorization/AuthorizationMutationScope.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/config/services.php`
- `backend/tests/Feature/RawMaterialDocumentStorageTest.php`
- `compose.stage2c-security-test.yaml`
