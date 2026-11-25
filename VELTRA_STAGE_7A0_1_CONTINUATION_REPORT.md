# VELTRA 2.0 — Stage 7A.0.1: raport kontynuacji

## Werdykt

**PARTIALLY IMPLEMENTED — SPECIFIC LIMITATIONS**

Zintegrowano baseline i wykonano ograniczoną zmianę widoczności centrali dla BOSS. Nie wdrożono większości wymagań Stage 7A.0.1, w szczególności pojedynczej roli, limitu centrali ani finansowych ograniczeń BOSS i ich powiązania z RAW. Zmiany nie są gotowe do akceptacji ani wdrożenia. Nie rozpoczęto Stage 7A.1.

## 1. Integracja baseline

- Stan początkowy: `main`, `8f5e0eaef0603c221b4e65885eb745f6f2a8d299`.
- Zaakceptowany RAW: `22e3320cc4abafbd5c9db3625299f9f19274ed5d`.
- Utworzono lokalną gałąź `feature/stage7a-rbac-alignment` z `main` i wykonano autoryzowany merge `--no-ff`. HEAD: `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`; rodzicami są powyższe dwa commity. Konfliktów merge nie było.
- Porównanie wskazanych plików z `main` potwierdziło zachowanie `docker/postgres`, konfiguracji Compose, plików wydania i README. Porównanie wskazanych plików frontendu z kandydatem RAW potwierdziło zachowanie jednolitej nawigacji RAW. To nie jest pełna weryfikacja funkcjonalna każdego pliku obu drzew.
- Zachowano wcześniejszy raport BLOCKED `VELTRA_STAGE_7A0_1_ARCHITECTURE_RBAC_ALIGNMENT_REPORT.md`, raport RBAC oraz nieśledzony raport wdrożenia RAW. `main` i gałęzi release nie modyfikowano.
- Poza commitem integracyjnym zmiany implementacyjne i raport pozostają niescommitowane.

## 2. Wykonane zmiany

W `backend/app/Domain/Authorization/BusinessVisibility.php`, w `decideBoss`, usunięto ograniczenie widoczności `LOCATION_VIEW`, `REPORTS_VIEW` i `REPORTS_EXPORT` do restauracji. Decyzja dla centrali nadal wymaga uprawnionego i aktywnego kontekstu, zgodności organizacji oraz przechodzi istniejące kontrole ograniczeń, pochodzenia i dostępności modułu. Dla raportu wymagany jest konkretny cel.

Testy potwierdzają decyzję `LOCATION_VIEW` dla centrali z kontekstu restauracji, możliwość autoryzacji eksportu opartego na tym uprawnieniu oraz odmowę po wyłączeniu centrali przez indywidualne ograniczenie BOSS. Istniejący mechanizm `ReportAuthorization::canViewShared` nadal wymaga osobnego udostępnienia raportu. Sama ta zmiana **nie zapewnia** dostępu do wszystkich danych źródłowych centrali i nie implementuje raportów, drukowania ani eksportów danych biznesowych.

Zmieniono testy `backend/tests/Feature/BusinessRoleDefinitionTest.php`, `BossLocationRestrictionsTest.php` i `ReportAuthorizationTest.php`. Nie dodano ani nie zmieniono migracji, UI ani kodu RAW.

## 3. Niewykonane wymagania i ograniczenia

- **Pojedyncza rola:** nie wdrożono ograniczenia różnych aktywnych ról, atomowej wymiany roli, ponownej oceny grantów i lokalizacji ani wymaganych testów współbieżności. Istniejące przypisania mogą nadal łączyć role. Zachowana ochrona ostatniego administratora nie została w tym zadaniu ponownie sprawdzona dedykowanym testem akceptacyjnym.
- **Jedna centrala:** nie wdrożono limitu wszystkich central ani testu współbieżnego tworzenia. Istniejące ograniczenie jednej organizacji oraz reguły głównej centrali nie są równoznaczne z limitem jednej centrali. Testy historycznie używają wielu central i wymagają dostosowania do nowej reguły bez niszczenia historii.
- **BOSS:** brak pełnego dostępu do rzeczywistych danych biznesowych, przepisów, historii i danych źródłowych całej organizacji. Nie zapewniono zakazu wszystkich zapisów przy niezależnych grantach lub połączeniu ról; istniejące ścieżki alternatywnych uprawnień pozostają do wyrównania.
- **Finanse:** `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` nie zostały domyślnie nadane BOSS. Nie podłączono kategorii `FOODCOST` i `PURCHASE_FINANCIALS` do wszystkich odpowiednich decyzji ani nie zapewniono pierwszeństwa zakazów przed grantami. Cztery kombinacje finansowe nie zostały wdrożone ani przetestowane. Cofnięcie grantu nie jest tym samym co nadrzędny zakaz.
- **RAW:** nie zmieniono autoryzacji szczegółów, archiwum, historii, zapisów ani filtracji finansów w API, sortowaniu, eksporcie i dokumentach. Ograniczony katalog BOSS zachowuje istniejące warunki readiness i aktywacji; testy katalogu nie dowodzą pełnego dostępu do RAW.
- **Panel administracyjny:** nie wdrożono jawnej wymiany roli, prezentacji efektywnych uprawnień ani pełnej konfiguracji dwóch skutecznych ograniczeń finansowych.
- **Dane istniejące:** nie sprawdzano operacyjnej bazy pod kątem sprzecznych ról ani wielu central. Nie można stwierdzić jej zgodności. Przyszłe migracje muszą zatrzymywać się przy konfliktach i zachować historię, bez automatycznego wyboru kont lub lokalizacji.

Pozostałe role i obszary pozostają zasadniczo w stanie opisanym we wcześniejszym `VELTRA_STAGE_7A0_RBAC_VERIFICATION_REPORT.md`; nie należy interpretować niniejszego raportu jako potwierdzenia ich pełnej zgodności z nowymi decyzjami.

## 4. Faktycznie wykonane testy

Testy backendu wykonano na odrębnym demonie Docker `veltra-test-isolated`, z projektem `veltra-test-application`, siecią wewnętrzną i bazą `veltra_testing`. Sprawdzono efektywne `DB_HOST=postgres`, `DB_DATABASE=veltra_testing`, `APP_ENV=testing`; PostgreSQL testowy używał `tmpfs` i nie publikował portów. Migracje baseline uruchomiono tylko w tej tymczasowej bazie. Obraz runnera nie odpowiadał w pełni checkoutowi, więc przed testami skopiowano do niego aktualne katalogi backendu; po edycji skopiowano zmienione pliki.

| Zakres i polecenie | Wynik |
|---|---|
| Baseline backend: `php artisan test --filter='BusinessRoleDefinitionTest|BossRawMaterialCatalogApiTest|RawMaterialRevisionDataModelTest'` | 27 testów, 275 asercji, sukces, przed zmianą |
| Zmienione reguły: `php artisan test --filter='BusinessRoleDefinitionTest|BossLocationRestrictionsTest|ReportAuthorizationTest'` | 12 testów, 159 asercji, sukces |
| Regresja: `php artisan test --filter='BossRawMaterialCatalogAuthorizationTest|BossRawMaterialCatalogApiTest|PermissionDelegationTest'` | 18 testów, 146 asercji, sukces |
| Frontend: `npm test -- --run src/App.test.tsx src/rawMaterials/RawMaterialsCatalogPage.test.tsx src/bossRawMaterials/BossRawMaterialsCatalogPage.test.tsx` | 3 pliki, 72 testy, sukces |
| Frontend: `npm run build` | type-check i build sukces; ostrzeżenie o chunku powyżej 500 kB |
| `git diff --check` | sukces |

Nie uruchomiono pełnej regresji ani wymaganych testów akceptacyjnych niewdrożonych funkcji. Wyniki ograniczonych testów nie dowodzą kompletności RBAC.

## 5. Przeglądarka

Wykonano wyłącznie automatyczny **frontend smoke**, nie E2E: tymczasowy Vite słuchał na `127.0.0.1:15173`, a proxy API skierowano celowo na niedostępny `127.0.0.1:1`, bez kontaktu z działającą aplikacją. Przeglądarka otworzyła stronę i kliknęła `RAW`. Dowód z odczytu strony:

```text
URL: http://127.0.0.1:15173/raw-materials
heading: Katalog surowców
Zaloguj się i wybierz lokalizację, aby otworzyć katalog.
Backend niedostępny
```

Wykonano zrzut ekranu w sesji przeglądarkowej; powyższy zapis stanowi trwały dowód w raporcie. Błędy 502 pochodziły z celowo niedostępnego backendu. Test nie obejmował logowania, uprawnień, danych ani rzeczywistego backendu. W repozytorium nie znaleziono gotowej infrastruktury Playwright/Cypress/Selenium, a bezpieczna konfiguracja testowa nie publikuje portów backendu; port 5173 był już zajęty. Pełnego izolowanego browser E2E nie wykonano, a akceptacja przeglądarkowa pozostaje nieweryfikowana.

## 6. Ochrona środowiska i dalsze kroki

Nie migrowano ani nie zmieniano operacyjnej bazy, kont, grantów, konfiguracji modułów, sekretów, wolumenów ani kontenerów aplikacyjnych. Nie wykonano deploymentu, push ani merge do `main`. Po testach zatrzymano tylko uruchomiony na potrzeby sesji serwer Vite i utworzone tymczasowe kontenery na dedykowanym demonie testowym.

Przed akceptacją należy dokończyć wszystkie wskazane wyżej wymagania, wykonać bezpieczną obsługę konfliktów danych i wymagane testy, w tym współbieżność, cztery kombinacje finansowe, wszystkie powierzchnie RAW i rzeczywiste izolowane browser E2E. Osobna autoryzacja będzie potrzebna przed commitem zmian implementacyjnych i przed jakimkolwiek wdrożeniem lub migracją operacyjną.
