# VELTRA Stage 2F — Implementation 2: SUPER ADMIN

**Wynik:** implementacja i regresja zakończone pomyślnie. Weryfikacja obejmuje wyłącznie izolowane środowisko testowe; nie wdrażano zmian i nie migrowano istniejącej instalacji. Niezależna akceptacja bezpieczeństwa pozostaje osobnym zadaniem.

## Zaimplementowane zachowanie

- SUPER ADMIN jest statusem pierwotnej tożsamości `is_initial_identity`, która zachowuje jedyną rolę `ADMINISTRATOR`. Nie dodano siódmej roli; test potwierdza sześć dotychczasowych ról.
- Status wymaga rozstrzygniętej tożsamości, aktywnej organizacji i autoryzacji oraz aktywnego członkostwa w oznaczonej primary central kitchen. Nierozstrzygnięta tożsamość historyczna pozostaje fail-closed.
- Tylko SUPER ADMIN może zarządzać innymi administratorami: ich rolami, uprawnieniami, statusem i członkostwami. Zwykli administratorzy zachowują dotychczasowe uprawnienia do zarządzania kontami niebędącymi administratorami.
- Chroniona jest pierwotna tożsamość: nie można jej usunąć, dezaktywować, przenieść ani odebrać jej roli administratora lub wymaganej przynależności do primary kitchen. Ochrona obejmuje również próby samoobsługowe. Nie ma ścieżki przeniesienia statusu.
- SUPER ADMIN może edytować wyłącznie własne dozwolone dane profilu (`first_name`, `last_name`, `email`), po ponownym potwierdzeniu hasła. Zmiana adresu zachowuje ID użytkownika, wymaga ponownej weryfikacji e-maila i unieważnia dotychczasowe sesje. Zachowano standardowe uwierzytelnianie i odzyskiwanie kont.
- Zachowano separację uprawnień finansowych, istniejące kontrole zakresu RBAC, gotowość modułów i istniejące zdarzenia bezpieczeństwa append-only. RAW nie został aktywowany.

## Zmiany w repozytorium

### Aplikacja i migracja

- `backend/app/Domain/Authorization/AuthorizationMutationScope.php`
- `backend/app/Domain/Authorization/BusinessAuthorization.php`
- `backend/app/Domain/Authorization/PermissionAdministration.php`
- `backend/app/Domain/Authorization/PersonnelAdministration.php`
- `backend/app/Domain/Authorization/RoleAdministration.php`
- `backend/app/Domain/Authorization/SuperAdminProfileAdministration.php` *(nowy)*
- `backend/app/Domain/Identity/AccountLinks.php`
- `backend/app/Domain/Identity/MembershipService.php`
- `backend/app/Domain/RawMaterials/OrganizationRawMaterialPermissionAdministration.php`
- `backend/app/Domain/RawMaterials/RawMaterialPermissionBootstrap.php`
- `backend/app/Domain/RawMaterials/RawMaterialPermissionUpgrade.php`
- `backend/app/Http/Controllers/Api/V1/SecurityController.php`
- `backend/app/Http/Requests/SecurityRequest.php`
- `backend/lang/pl/messages.php`
- `backend/routes/api.php`
- `backend/database/migrations/2026_11_08_000039_enforce_super_admin_invariants.php` *(nowa, forward-only)*

### Testy i fixture’y

- `backend/tests/AuthorizationTestCase.php`
- `backend/tests/Feature/AdministratorConcurrencyTest.php`
- `backend/tests/Feature/AuthorizationMutationBoundaryTest.php`
- `backend/tests/Feature/ModuleConfigurationTest.php`
- `backend/tests/Feature/RawMaterialCompatibilityAssessmentTest.php`
- `backend/tests/Feature/RawMaterialConcurrencyTest.php`
- `backend/tests/Feature/RawMaterialDocumentStorageTest.php`
- `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php`
- `backend/tests/Feature/RawMaterialRevisionDataModelTest.php`
- `backend/tests/Feature/RawMaterialStage2dApiTest.php`
- `backend/tests/Feature/RawMaterialsTest.php`
- `backend/tests/Feature/RoleAdministrationTest.php`
- `backend/tests/Feature/SecurityApiTest.php`
- `backend/tests/Feature/SuperAdminAuthorizationTest.php` *(nowy)*
- `backend/tests/Support/concurrent_administrator.php`
- `backend/tests/Support/concurrent_super_admin_mutation.php` *(nowy)*

Nie zmieniono wcześniej zastosowanych migracji.

## Zabezpieczenia PostgreSQL

Migracja `000039` dodaje funkcję `veltra_private.super_admin_actor` oraz wyzwalacze `zz_veltra_super_admin_guard` na `users`, `role_assignments`, `location_memberships`, grantach użytkownika i organizacji, zdarzeniach grantów, operacjach podniesienia uprawnień RAW i żądaniach członkostwa. Ochrona w bazie uzupełnia autoryzację serwisową i odrzuca m.in. bezpośrednie próby edycji chronionej tożsamości, zarządzania administratorem przez zwykłego administratora i odebrania roli pierwotnemu administratorowi. Nie zastępuje całego Laravel RBAC.

Migracja ogranicza również audyt systemowego cofania grantów podczas autoryzowanej dezaktywacji do wąskiego zakresu `account.deactivate` i właściwego zdarzenia `USER_DEACTIVATED`. Zachowano dotychczasowe bariery podpisanych mutacji; nie dodano ogólnego mechanizmu podpisywania.

## Środowisko i izolacja

- Projekt Compose: `veltra-super-admin-regression`; konfiguracja testowa znajduje się poza repozytorium w katalogu sesji.
- Osobny PostgreSQL 17 (`postgres:17-alpine`), wolumen danych i wewnętrzna sieć Compose `isolated`; usługi Redis i ClamAV również należą wyłącznie do tego projektu. Źródłem backendu jest bieżący katalog `backend` repozytorium.
- Testowy cel to baza `veltra_testing` na hoście `postgres` **w tym odrębnym projekcie Compose**, nie baza o tej samej nazwie w instalacji.
- `veltra_app` i `veltra_migrator` mają `NOSUPERUSER`, `NOCREATEDB` i `NOCREATEROLE`. Aplikacja łączy się jako `veltra_app`; migracje korzystają z odrębnego `veltra_migrator`. Rola `veltra_test_admin` ma uprawnienia administracyjne wyłącznie w kontenerze testowym i nie jest rolą aplikacji.
- Końcowe, tylko odczytowe sprawdzenie katalogu ról zwróciło: `veltra_app:false:false:false`, `veltra_migrator:false:false:false`, `veltra_test_admin:true:true:true` (kolejno superuser, createdb, createrole).
- Przed i po przebiegach porównano kontenery instalacyjne; ich identyfikatory pozostały niezmienione. Nie wykonano migracji ani zapisu do instalacyjnej bazy.

## Weryfikacja

Polecenia aplikacyjne wykonywano w serwisie `backend` izolowanego projektu Compose; wartości sekretów testowych nie są zawarte w tym raporcie.

Ponowne, końcowe uruchomienie testów ukierunkowanych:

```sh
docker compose --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env \
  -p veltra-super-admin-regression \
  -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml \
  exec -T backend php artisan test \
  tests/Feature/SuperAdminAuthorizationTest.php \
  tests/Feature/AuthorizationMutationBoundaryTest.php \
  tests/Feature/RoleAdministrationTest.php \
  tests/Feature/RawMaterialPermissionBootstrapTest.php \
  tests/Feature/AdministratorConcurrencyTest.php
```

Wynik: **32 zaliczone, 464 asercje**, 64,33 s.

Pozostałe wyniki z wykonanej regresji:

- Pełny backend PHPUnit: **270 zaliczonych, 2632 asercje**, 290,28 s.
- Migracje na świeżej bazie PostgreSQL 17: **39/39**.
- PHP lint dla zmienionych plików PHP: zaliczony.
- Pint w trybie kontrolnym: **31 plików**, zaliczony.
- `git diff --check`: zaliczony.
- Osobna kontrola `RAW_MATERIALS` pozostającego `NOT_READY`: **1 test, 54 asercje**.
- Testy skanowania ClamAV i regresje Redis/RAW przechodziły w pełnym suite.
- Próby bezprawnych mutacji SQL odrzucono oczekiwanymi błędami uprawnień lub ograniczeń. Test współbieżności administratorów używał osobnych procesów/połączeń PostgreSQL i zakończył się pomyślnie.

Pierwszy pełny przebieg wykazał osiem błędów: brak usług Redis/ClamAV w izolowanym Compose, nieprawidłowy host Redis w konfiguracji testowej oraz fixture bez aktora wymagającego nowych reguł. Dodano te zależności wyłącznie do testowego stosu i poprawiono fixture. Testy ukierunkowane ujawniły też dwie luki implementacji: bootstrap uprawnień RAW nie sprawdzał prawa aktora do zarządzania kontem-administratorem, a zwykły administrator mógł próbować odebrać własną rolę podpisaną mutacją. Dodano kontrolę serwisową oraz ograniczenie PostgreSQL; ponowny pełny suite przeszedł.

## Ograniczenia i werdykt

- **Werdykt: GOTOWE jako Implementation 2 w zakresie lokalnej implementacji i regresji.** Nie jest to niezależna akceptacja bezpieczeństwa ani zgoda na wdrożenie.
- Nie uruchamiano migracji wobec istniejącej instalacji, nie zmieniano jej kontenerów ani konfiguracji, nie wykonywano wdrożenia, commita ani push.
- Osobna niezależna ocena bezpieczeństwa pozostaje do wykonania zgodnie z osobną autoryzacją. Nie stwierdzono blokera uniemożliwiającego zakończenie tej implementacji.
- Nie potwierdzono przesłania raportu do Google Drive; status synchronizacji należy zgłosić osobno po sprawdzeniu dostępności uwierzytelnionego dostępu.
