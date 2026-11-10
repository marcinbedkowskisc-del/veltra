# VELTRA 2.0 — Stage 4B.2A-P2V

## Raport weryfikacji backendu capabilities RAW

**Status: PASS**

Weryfikacja endpointu została wykonana na aktualnym kodzie repozytorium i całkowicie efemerycznej bazie PostgreSQL. Testy capabilities oraz wskazane regresje autoryzacji zakończyły się powodzeniem. Nie znaleziono błędu produkcyjnego wymagającego poprawki.

## 1. Środowisko testowe

- Docker: `29.9.2`; Docker Compose: `v5.6.1`.
- Runner zbudowano lokalnie z aktualnego katalogu `backend` i [Dockerfile](/home/veltra/projekty/veltra/backend/Dockerfile):
  `docker build --pull=false --tag veltra-stage4b2a-p2v-runner:local --file backend/Dockerfile backend`
- Runner zawierał zależności Composer i bieżące źródła przez mechanizm `COPY . .` z Dockerfile. Pliki endpointu, resolvera oraz testu capabilities zostały porównane skrótami SHA-256 pomiędzy repozytorium a obrazem; wartości były identyczne. Końcowy test został przebudowany i uruchomiony ponownie po ostatniej zmianie testu.
- Uruchomiono tymczasowy `postgres:17-alpine`, prywatną sieć `veltra-stage4b2a-p2v-net` bez portów hosta oraz bazę `veltra_testing`. Role `veltra_migrator` i `veltra_app` skonfigurowano przez repozytoryjny skrypt inicjalizacyjny, z ograniczeniami ról wymaganymi przez testowy [TestCase.php](/home/veltra/projekty/veltra/backend/tests/TestCase.php).
- Runner używał `DB_HOST=postgres`, `DB_DATABASE=veltra_testing`, roli aplikacyjnej `veltra_app`, osobnej roli setup `veltra_migrator` oraz `SANCTUM_STATEFUL_DOMAINS` zgodnego z lokalnymi domenami Compose. Dane dostępowe i klucze były wyłącznie tymczasowe, testowe i nie zostały zapisane w raporcie.
- Polecenie `php artisan migrate --force` zastosowało **45 migracji wyłącznie w nowej bazie efemerycznej**. Odczyt w tej bazie potwierdził `veltra_testing` i 45 wpisów migracji. Testowe zabezpieczenia dodatkowo sprawdzały nazwę bazy, role, właściciela tabel i ograniczone uprawnienia.

Istniejąca konfiguracja Compose kieruje `backend-test`/`migrate-test` do działającego PostgreSQL instalacyjnego, dlatego nie użyto tych usług ani istniejących wolumenów. Kontenery aplikacyjne, w tym `veltra-postgres-1`, pozostały uruchomione i niezmienione; testowa baza działała w odrębnej sieci.

## 2. Polecenia i wyniki

Polecenia wykonywano wewnątrz jednorazowego runnera z powyższą konfiguracją:

- `php artisan migrate --force` — PASS, 45 migracji w efemerycznej `veltra_testing`.
- `php artisan test tests/Feature/RawMaterialCapabilitiesApiTest.php` — **PASS, 12 testów, 88 asercji**.
- `php artisan test --filter='RawMaterialPermissionBootstrapTest|PermissionDelegationTest'` — **PASS, 20 testów, 219 asercji**.
- `php artisan test tests/Feature/RawMaterialsTest.php --filter='test_api_requires_both_module_availability_and_an_organization_grant|test_location_roles_do_not_bypass_organization_grants'` — **PASS, 2 testy, 13 asercji**.
- `php artisan test tests/Feature/RawMaterialStage2dApiTest.php --filter=test_http_endpoints_return_not_ready_without_bypassing_feature_gate` — **PASS, 1 test, 10 asercji**.
- `php -r 'token_get_all(file_get_contents($file), TOKEN_PARSE)'` dla czterech zmienionych/istotnych plików PHP — **PASS, bez błędów składni**.
- `git diff --check` — **PASS**.

Łącznie: **35 testów i 330 asercji PASS**.

## 3. Zakres weryfikacji capabilities

Testy używały rzeczywistej trasy, middleware, resolvera autoryzacji i testowej bazy — bez mockowania decyzji o przyznaniu uprawnienia. Pokryto:

- efektywny grant CREATE i zgodność wyniku z `RawMaterialAuthorization::hasEffectiveGrant`;
- VIEW bez CREATE oraz brak jakiegokolwiek grantu RAW;
- grant przyszły, wygasły, cofnięty oraz delegowany grant z cofniętym źródłem;
- ograniczenie CREATE do głównego kontekstu katalogu;
- stan `NOT_READY`: HTTP 200, zachowane `permissions.create` i `actions.create=false`;
- odrębność `readiness=READY` od `available=false` dla nieaktywnej konfiguracji lokalizacji;
- nieuwierzytelnione żądanie, nieaktualną tożsamość, brak wybranego kontekstu i metodę POST;
- `Cache-Control` zawierające `private` i `no-store`;
- brak zmiany liczby grantów, zdarzeń cofnięcia, audytów, surowców, rezerwacji SKU, liczników i przydziałów SKU po udanym GET;
- izolację kontekstu lokalizacji: próba wskazania lokalizacji z obcej organizacji przez parametr żądania nie zmieniła lokalizacji pobieranej z sesji. Resolver grantów dodatkowo ogranicza zapytanie do organizacji użytkownika.
- zgodność istniejącego resolvera oraz brak ominięcia bramki gotowości na dotychczasowych trasach RAW.

## 4. Defekty i zmiany

Nie wykryto błędu endpointu ani resolvera wymagającego poprawki. Nie zmieniano kodu produkcyjnego backendu, polityk grantów ani API.

Podczas pierwszego uruchomienia runner nie miał ustawionego `SANCTUM_STATEFUL_DOMAINS`, więc middleware poprawnie odrzuciło żądania testowe jako niepochodzące z sesji SPA. Po odtworzeniu wartości używanej przez Compose testy przeszły. Dopracowano wyłącznie fixtures/testy, aby delegacje powstawały przy dostępności modułu, a asercja alokacji sprawdzała aktualną tabelę `organization_sku_counters`. Nie były to poprawki logiki aplikacji.

## 5. Zmiany w repozytorium i zachowanie istniejącego stanu

W tym etapie:

- rozszerzono istniejący, wcześniej nieśledzony plik [RawMaterialCapabilitiesApiTest.php](/home/veltra/projekty/veltra/backend/tests/Feature/RawMaterialCapabilitiesApiTest.php);
- dodano ten raport.

Nie zmieniono produkcyjnych plików backendu w ramach P2V. Wcześniejsze, niezatwierdzone zmiany Stage 4A.2, 4B.1 i 4B.2A-P2 zostały zachowane. Końcowy `git status --short` zawiera nadal te wcześniejsze zmiany oraz test capabilities i niniejszy raport; `git diff --check` jest czysty.

## 6. Google Drive

Nie znaleziono zatwierdzonego konektora Google Drive ani bezpiecznej ścieżki uploadu dostępnej dla tej sesji. Nie ponawiano znanej, odrzuconej ścieżki selektora WSL UNC. Raport pozostaje lokalnie:

- Linux: `/home/veltra/projekty/veltra/VELTRA_STAGE_4B2A_P2V_BACKEND_VERIFICATION_REPORT.md`
- Windows: `\\wsl.localhost\Ubuntu\home\veltra\projekty\veltra\VELTRA_STAGE_4B2A_P2V_BACKEND_VERIFICATION_REPORT.md`

Upload nie został wykonany ani zweryfikowany.

## 7. Sprzątanie i granice wykonania

Usunięto wyłącznie utworzone na potrzeby testu: kontener PostgreSQL `veltra-stage4b2a-p2v-postgres` wraz z jego wolumenem, prywatną sieć `veltra-stage4b2a-p2v-net` oraz obraz runnera `veltra-stage4b2a-p2v-runner:local`. Kontenery uruchamiane przez `docker run --rm` zostały usunięte automatycznie. Nie uruchamiano globalnego czyszczenia cache Dockera.

`RAW_MATERIALS` pozostaje **NOT_READY**. Gotowość w testach była ustawiana wyłącznie w izolowanym fixture/in-memory `ModuleCatalog`; nie zmieniono katalogu produkcyjnego, stanu modułu ani danych instalacyjnych. Nie modyfikowano istniejących kontenerów ani baz, nie wykonano wdrożenia, commita ani pushu. Nie rozpoczęto formularza tworzenia ani Stage 4B.2B.
