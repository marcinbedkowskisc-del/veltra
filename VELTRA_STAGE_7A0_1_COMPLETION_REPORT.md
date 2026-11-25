# VELTRA 2.0 — Stage 7A.0.1: raport wykonania

## Werdykt

**PARTIALLY IMPLEMENTED — SPECIFIC LIMITATIONS.** Etap nie jest gotowy do akceptacji ani wdrożenia. Nadal nie zagwarantowano dokładnie jednej efektywnej roli każdemu provisioned userowi. Niezależność widoczności kosztu i ceny RAW oraz ochrona przed wywiedzeniem ukrytej wartości nie są spełnione jednocześnie. Wyniki testów dotyczą lokalnego kodu i izolowanego środowiska, nie działającego wdrożenia.

## Baseline i stan Git

- Zachowano integrację `main` (`8f5e0eaef0603c221b4e65885eb745f6f2a8d299`) z kandydatem RAW (`22e3320cc4abafbd5c9db3625299f9f19274ed5d`): gałąź `feature/stage7a-rbac-alignment`, HEAD `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`. Merge wcześniej wykonano bez konfliktów; nie powtarzano go.
- Zgodnie z poprzednim raportem sprawdzono wskazane pliki hardeningu PostgreSQL, Compose i wdrożenia oraz jednolitą nawigację RAW; nie oznacza to pełnej weryfikacji każdego pliku obu baseline'ów.
- Zmiany implementacyjne, migracje, testy i raporty pozostają niezacommitowane. Poprzednie raporty zachowano. `git diff --check` zakończył się sukcesem.

## Stan dziesięciu wymaganych zadań

### 1. Jedna rola — CZĘŚCIOWO

Pliki: `backend/database/migrations/2026_11_24_000060_enforce_single_business_role.php`, `backend/app/Domain/Authorization/RoleAdministration.php`, `PersonnelAdministration.php`, `BusinessAuthorization.php`, `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php`, `backend/app/Http/Controllers/Api/V1/SecurityController.php`, `backend/app/Http/Requests/SecurityRequest.php`, `backend/routes/api.php`; testy `RoleAdministrationTest.php`, `SecurityApiTest.php`, `backend/tests/Support/concurrent_role.php`.

Constraint GiST zabrania jednoczesnych różnych niecofniętych ról użytkownika, dopuszczając tę samą rolę na wielu lokalizacjach. Migracja przerywa przy historycznych konfliktach, bez automatycznego wyboru roli. Dostęp biznesowy i RAW wymaga jednej efektywnej roli. Jawne `PUT /api/v1/security/users/{user}/role` atomowo zastępuje rolę, zachowuje historię i sprawdza uprawnienia, lokalizacje oraz ochronę administratora. Testy obejmują równoczesne nadania, odmowę nieuprawnionej zamiany i ochronę administratora.

**Istotny brak:** constraint zapewnia co najwyżej jedną różną rolę, nie dokładnie jedną efektywną. Cofnięcie ostatniej roli, usunięcie członkostwa lub utworzenie użytkownika w centrali może pozostawić konto bez roli; panel nadal oferuje cofnięcie roli. Dostęp takiego konta jest blokowany, ale nie spełnia to wymaganego niezmiennika provisioningu. Potrzebne jest domknięcie transakcyjnego provisioningu, usuwania przypisań i ochrony na poziomie bazy, wraz z bezpieczną obsługą istniejących kont bez roli. Nie wykonano migracji ani analizy danych operacyjnych.

### 2. Maksymalnie jedna centrala — ZAIMPLEMENTOWANO W KODZIE I PRZETESTOWANO

Pliki: `backend/database/migrations/2026_11_24_000061_limit_central_kitchens.php`, `backend/app/Domain/Installation/InstallationService.php`, `backend/tests/Feature/SingleCentralKitchenTest.php`, `backend/tests/Support/concurrent_kitchen.php`.

Częściowy unikalny indeks obejmuje również nieaktywne centrale; tworzenie dodatkowo blokuje organizację. Migracja zatrzymuje się przy istniejących duplikatach, bez ich usuwania. Testy obejmują pierwszą centralę, odmowę drugiej, współbieżność oraz restauracje. Zgodność faktycznych danych operacyjnych pozostaje **NIEZWERYFIKOWANA**.

### 3. Odczyt BOSS w organizacji — CZĘŚCIOWO

Pliki: `backend/app/Domain/Authorization/BusinessAuthorization.php`, `BusinessVisibility.php`; testy `BusinessRoleDefinitionTest.php`, `BossLocationRestrictionsTest.php`, `ReportAuthorizationTest.php`, `BossFinancialPermissionsTest.php`.

Decyzje odczytu obejmują centralę oraz obecne i przyszłe restauracje, z uwzględnieniem organizacji, aktywnego kontekstu i indywidualnych ograniczeń. Testy sprawdzają lokalizacje, istniejące uprawnienia do przepisów, kalkulacji i raportów. RAW opisano poniżej. Nie zweryfikowano wszystkich możliwych istniejących powierzchni danych ani pełnej ścieżki raportowania i drukowania; nie oznacza to kompletnej akceptacji organizacyjnego odczytu.

### 4. BOSS tylko do odczytu — CZĘŚCIOWO

Pliki: `BusinessAuthorization.php`, `BusinessVisibility.php`, `RoleAdministration.php`, `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php`, `RawMaterialPermission.php`, `OrganizationRawMaterialPermissionAdministration.php`, `AuthorizationMutationScope.php`; testy `BossRawMaterialCatalogAuthorizationTest.php`, `RoleAdministrationTest.php`, `RawMaterialsTest.php`.

Decyzja biznesowa dopuszcza BOSS tylko do dozwolonych odczytów i nie uwzględnia niezależnych grantów jako obejścia. Nowe granty zapisu RAW dla BOSS są odrzucane, a przy nadaniu lub zamianie na BOSS istniejące niezgodne granty zapisu RAW są cofane z audytem. Testowano odmowy oraz ścieżki grantów. Nie wykonano wyczerpującej weryfikacji każdej operacji wszystkich modułów.

### 5. Domyślne finanse BOSS — ZAIMPLEMENTOWANO W KODZIE I PRZETESTOWANO

Pliki: `BusinessAuthorization.php`, `BusinessVisibility.php`, `backend/tests/Feature/BossFinancialPermissionsTest.php`. `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` są domyślnie dostępne w uprawnionym kontekście BOSS; ograniczenia mają pierwszeństwo. Testy obejmują cztery konfiguracje i lokalizacje. Widoczność rzeczywistych wartości RAW ma odrębne ograniczenia opisane w zadaniach 6–7.

### 6. Niezależne ograniczenia finansowe — CZĘŚCIOWO

Pliki: `BusinessVisibility.php`, istniejący `BossLocationRestrictions.php`, `RawMaterialAuthorization.php`, `frontend/src/administration/BossLocationRestrictions.tsx`, `backend/tests/Feature/BossFinancialPermissionsTest.php`, `RawMaterialsTest.php`.

Kategorie `FOODCOST` i `PURCHASE_FINANCIALS` można ustawić niezależnie dla BOSS; wyłączenia mają pierwszeństwo przed domyślnym uprawnieniem i niezależnym grantem. Cztery kombinacje efektywnych uprawnień przetestowano dla centrali, restauracji i przyszłych restauracji.

**Konkretny konflikt w RAW:** przy FOODCOST włączonym i cenach wyłączonych koszt jednostkowy nie jest zwracany, ponieważ obecny kod wymaga również dostępu do ceny. Wymagana konfiguracja C nie działa zatem w pełni. Z drugiej strony cena oraz dostępne ilości mogą umożliwić matematyczne wyliczenie ukrytego kosztu w konfiguracji D. Nie wykazano ochrony przed pochodnym ujawnieniem; potrzebne jest uzgodnienie bezpiecznej projekcji danych źródłowych pozwalającej spełnić obie wymagane własności. Nie należy traktować czterech poprawnych flag uprawnień jako dowodu czterech poprawnych projekcji RAW.

### 7. Integracja RAW — CZĘŚCIOWO

Pliki: `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php`, `RawMaterialCatalogValues.php`, `RawMaterialService.php`, `RawMaterialHistoryService.php`, `RawMaterialLocationSharePolicy.php`, `RawMaterialCommercialService.php`, `RawMaterialCsvCatalogExportWorkflow.php`, `Documents/RawMaterialDocumentService.php`, `Documents/RawMaterialDocumentSetService.php`, `backend/app/Http/Controllers/Api/V1/RawMaterialCapabilitiesController.php`; testy `RawMaterialsTest.php`, `RawMaterialCapabilitiesApiTest.php`, `RawMaterialRevisionDataModelTest.php`, `BossRawMaterialCatalogAuthorizationTest.php`.

Testy potwierdziły odczyt zatwierdzonego nieudostępnionego i zarchiwizowanego surowca oraz historii przez BOSS z restauracji, odmowę archiwizacji, filtrowanie pól finansowych i sortowania, odmowę eksportu cen po ograniczeniu oraz ponowną autoryzację pobrania już utworzonego eksportu po zmianie ograniczeń. Nieklasyfikowane dokumenty BOSS są blokowane, gdy ograniczono którąkolwiek kategorię finansową. Zachowano reguły udostępniania pozostałych ról; regresje modelu wersji, ilości i cen przeszły.

Ograniczenia z zadania 6 pozostają rzeczywiste. Ponadto produkcyjna definicja `BOSS_RAW_CATALOG_READ` pozostaje `NOT_READY`; testy używają wyłącznie testowej definicji READY i aktywacji w izolowanej bazie. Nie zmieniano gotowości ani operacyjnej aktywacji modułu. Nie sprawdzono wyczerpująco wszystkich dokumentów, alternatywnych powierzchni i danych pochodnych.

### 8. Panel administracyjny — CZĘŚCIOWO

Pliki: `frontend/src/administration/AdministrationUsersPage.tsx`, `usersApi.ts`, `BossLocationRestrictions.tsx`, `AdministrationUsersPage.test.tsx`, `backend/lang/pl/messages.php`.

Panel wywołuje atomową zamianę roli z wieloma lokalizacjami, wymaga hasła przy zmianie dotyczącej administratora i prezentuje historię zamiany; interfejs ograniczeń pozwala osobno wyłączyć obie kategorie finansowe. Test frontendu i uwierzytelniony test przeglądarkowy objęły zamianę roli i ograniczenie cen. Cofnięcie ostatniej roli nadal pozostaje dostępne, a faktyczna widoczność kosztu RAW przy konfiguracji C odbiega od prezentowanych uprawnień.

### 9. Testy backendu, API, frontendu i regresje — CZĘŚCIOWO

Wykonano ukierunkowane testy wymienione poniżej; nie jest to pełna regresja wszystkich modułów. Dostosowano stare fixture'y RAW i granic mutacji do wymagania posiadania roli oraz limitu centrali; nie pomijano testów.

### 10. Uwierzytelnione E2E w izolacji — CZĘŚCIOWO

Uruchomiono tymczasowy backend i Vite na loopback z izolowaną bazą testową oraz syntetycznymi kontami. Rzeczywista przeglądarka zalogowała administratora, wybrała centralę, zamieniła `EMPLOYEE` na `RESTAURANT_CHEF` w dwóch restauracjach i potwierdziła historię. Administrator wyłączył ceny BOSS; efektywne uprawnienia zawierały Foodcost, lecz nie ceny.

Po rzeczywistym zalogowaniu BOSS i wyborze restauracji panel administracyjny pokazał brak dostępu; dwa endpointy administracyjne i próba utworzenia lokalizacji zwróciły 403. Uwierzytelnione żądania z przeglądarki wykazały centralę i dwie restauracje oraz oczekiwane uprawnienia odczytu w tych lokalizacjach.

Lokalny zrzut ekranu: `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/stage7a-boss-admin-denied.png`. Jest to rzeczywiste uwierzytelnione E2E, nie frontend smoke. **Nie** obejmuje pełnej macierzy RAW UI, wszystkich kombinacji finansowych, ani kompletnej akceptacji. Wcześniejszy smoke bez backendu opisano osobno w poprzednim raporcie.

## Wykonane kontrole

- PHPUnit na izolowanej bazie: 99 testów, 1045 asercji, sukces — `BossRawMaterialCatalogAuthorizationTest`, `BossFinancialPermissionsTest`, `BossLocationRestrictionsTest`, `BusinessRoleDefinitionTest`, `ReportAuthorizationTest`, `RoleAdministrationTest`, `SingleCentralKitchenTest`, `SecurityApiTest`, `RawMaterialCapabilitiesApiTest`, `RawMaterialRevisionDataModelTest`, `PersonnelAdministrationTest`, `AuthorizationMutationBoundaryTest`.
- Osobny wybór `RawMaterialsTest.php` z czterema kombinacjami finansowymi BOSS, archiwum/historią oraz ponowną autoryzacją pobrania eksportu: 6 testów, 54 asercje, sukces. Wcześniejszy wybór regresji RAW: 9 testów, 174 asercje, sukces.
- Vitest: `App.test.tsx`, `RawMaterialsCatalogPage.test.tsx`, `BossRawMaterialsCatalogPage.test.tsx`, `AdministrationUsersPage.test.tsx`, `BossLocationRestrictions.test.tsx` — 5 plików, 87 testów, sukces.
- `npm run build`: type-check i build sukces; ostrzeżenie o dużym bundlu. `npm run lint`: sukces z istniejącym ostrzeżeniem w `visualPreview.tsx`.
- `vendor/bin/pint --test` dla 38 zmienionych i nowych plików PHP: sukces. `git diff --check`: sukces.
- Początkowo regresje RAW i granic mutacji nie przechodziły, ponieważ historyczne fixture'y nie miały wymaganych ról lub zakładały wiele central. Po ich dostosowaniu powyższe testy przeszły. Pełnego suite nie uruchamiano.

## Środowisko, bezpieczeństwo i dalsze kroki

Testy zapisujące używały oddzielnego demona Docker `unix:///run/user/1000/veltra-test-docker/docker.sock`, oznaczonego `com.veltra.daemon-role=disposable-test`, bazy `veltra_testing` na testowym PostgreSQL `tmpfs`, `APP_ENV=testing` oraz testowych ról bazy. Migracje i syntetyczne konta dotyczyły wyłącznie tego środowiska. Zatrzymano jedynie uruchomione do testów kontenery izolowanego demona, Vite i tymczasowy odbiornik dowodu; nie usuwano wolumenów.

Nie migrowano ani nie zmieniano operacyjnej bazy, kont, uprawnień, sekretów, konfiguracji modułów ani operacyjnych kontenerów. Nie wykonano dodatkowego commitu, pusha, merge do `main` ani wdrożenia. Stage 7A.1 nie rozpoczęto.

Przed akceptacją należy dokończyć niezmiennik dokładnie jednej efektywnej roli w całym cyklu konta i bazie, bez arbitralnego rozstrzygania danych historycznych; rozwiązać konflikt niezależnych finansów RAW i pochodnych ujawnień; następnie rozszerzyć testy API i uwierzytelnione E2E na brakujące powierzchnie i wszystkie kombinacje. Ewentualna analiza zgodności danych i migracja operacyjna wymagają osobnej autoryzacji.
