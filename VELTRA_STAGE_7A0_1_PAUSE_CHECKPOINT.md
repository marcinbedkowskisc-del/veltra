# VELTRA 2.0 — checkpoint bezpiecznej pauzy Stage 7A.0.1

## Status i granice

- **SAFELY PAUSED — READY TO RESUME.** Stan dotyczy pauzy, nie akceptacji implementacji. Prace można wznowić dopiero po wyraźnej autoryzacji właściciela.
- Repozytorium: `/home/veltra/projekty/veltra`; gałąź: `feature/stage7a-rbac-alignment`; HEAD: `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`.
- Zmiany pozostają niezacommitowane i niezastashowane. Nie wykonano pusha, merge'a do main, resetu, reverta ani wdrożenia. Nie rozpoczęto Stage 7A.1. Zachowano wcześniejsze raporty.
- Zweryfikowano `git status --short`, HEAD oraz `git diff --check`; ten ostatni nie zgłosił błędów. Stan obejmuje 86 zmodyfikowanych plików śledzonych i wymienione niżej pliki nieśledzone. `git diff --stat` nie obejmuje plików nieśledzonych.
- Raport roboczy z wynikami i ograniczeniami: `VELTRA_STAGE_7A0_1_REGRESSION_CLOSURE_REPORT.md`. Jego werdykt: **częściowo zamknięte, niegotowe do akceptacji**, ponieważ pełna regresja aktualnego drzewa nie została potwierdzona.

## Stan implementacji i ukończone zadania

1. Zintegrowany baseline znajduje się na powyższym HEAD; nie powtarzać integracji. Implementacja RBAC i RAW z wcześniejszych etapów jest w worktree, nie w dodatkowym commicie.
2. Aktywne konto z potwierdzonym adresem wymaga dokładnie jednej efektywnej roli biznesowej. Rola lokalna wymaga odpowiedniego aktywnego członkostwa i lokalizacji. Konto oczekujące lub nieaktywne może nie mieć roli; brak dostępu biznesowego pozostaje egzekwowany. Zmiana roli jest autoryzowana i atomowa, zachowuje historię i ochronę ostatniego administratora. UI uniemożliwia cofnięcie ostatniej efektywnej roli aktywnego potwierdzonego konta.
3. Limit jednej centrali na organizację jest egzekwowany w aplikacji i bazie, także przy współbieżności. BOSS ma organizacyjny odczyt, jest ograniczony do odczytu i nie ma dostępu do panelu administracyjnego.
4. Ograniczenia BOSS `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` działają niezależnie w objętych testami odpowiedziach RAW. Przy dozwolonym foodcost koszt nie wymaga dodatkowo uprawnienia do cen zakupu. Testy obejmują API, historię, selektywne eksporty i blokadę nieklasyfikowanych dokumentów, bez ukrywania ilości operacyjnych.
5. Naprawiono `IdentityService::disable`: migracja `backend/database/migrations/2026_11_24_000063_authorize_identity_disable_token_cleanup.php` dopuszcza wyłącznie operację `DELETE` na `activation_tokens` i `password_reset_tokens` w podpisanym zakresie `identity.disable`. Testy sprawdzają unieważnienie obu rodzajów tokenów, zachowanie tokenów innego adresu oraz odrzucenie `INSERT` i `UPDATE`. Nie rozszerzono grantów bazy ani nie wyłączono triggerów w implementacji.
6. Usunięto stary helper `IdentityTestCase::user()` tworzący aktywną potwierdzoną tożsamość bez roli; fixture'y dostosowano do jawnych ról, kont oczekujących, atomowej zamiany i limitu jednej centrali. Nowe testy współbieżności i adaptacje znajdują się w worktree.
7. Przeprowadzono autentyczny test przeglądarkowy reaktywacji w izolacji: administrator wyłączył konto BOSS i cofnął rolę oraz członkostwo; reaktywacja bez roli dała HTTP 409; po jawnym nadaniu członkostwa i BOSS aktywacja powiodła się, a historia pozostała dostępna. BOSS zalogował się, wybrał centralę i otrzymał HTTP 403 w panelu administracyjnym. Nieaktywny przycisk cofania ostatniej roli został sprawdzony w UI. Jest to test uwierzytelniony, nie smoke test.
8. Wcześniejsze uwierzytelnione E2E czterech kombinacji finansowych RAW, źródeł i historii opisano w `VELTRA_STAGE_7A0_1_FINAL_REPORT.md`; nie trzeba ich ponawiać bez zmian wpływających na te zachowania.

## Zachowane pliki śledzone ze zmianami

Ścieżki są względne do repozytorium:

### Backend — implementacja

```text
backend/app/Domain/Authorization/AuthorizationMutationScope.php
backend/app/Domain/Authorization/BusinessAuthorization.php
backend/app/Domain/Authorization/BusinessVisibility.php
backend/app/Domain/Authorization/PersonnelAdministration.php
backend/app/Domain/Authorization/RoleAdministration.php
backend/app/Domain/Identity/AccountLinks.php
backend/app/Domain/Identity/MembershipService.php
backend/app/Domain/Installation/InstallationService.php
backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php
backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentSetService.php
backend/app/Domain/RawMaterials/OrganizationRawMaterialPermissionAdministration.php
backend/app/Domain/RawMaterials/RawMaterialAuthorization.php
backend/app/Domain/RawMaterials/RawMaterialCatalogValues.php
backend/app/Domain/RawMaterials/RawMaterialCommercialService.php
backend/app/Domain/RawMaterials/RawMaterialCsvCatalogExportWorkflow.php
backend/app/Domain/RawMaterials/RawMaterialHistoryService.php
backend/app/Domain/RawMaterials/RawMaterialLocationSharePolicy.php
backend/app/Domain/RawMaterials/RawMaterialPermission.php
backend/app/Domain/RawMaterials/RawMaterialService.php
backend/app/Http/Controllers/Api/V1/RawMaterialCapabilitiesController.php
backend/app/Http/Controllers/Api/V1/SecurityController.php
backend/app/Http/Requests/SecurityRequest.php
backend/lang/pl/messages.php
backend/routes/api.php
```

### Backend — testy i fixture'y

```text
backend/tests/AuthorizationTestCase.php
backend/tests/IdentityTestCase.php
backend/tests/Concerns/RawCatalogStage3eB1Tests.php
backend/tests/Concerns/RawDocumentsStage3eB3Tests.php
backend/tests/Concerns/RawDraftTombstoneTests.php
backend/tests/Concerns/RawEditingStage3eB2Tests.php
backend/tests/Concerns/RawFormWorkflowTests.php
backend/tests/Concerns/RawWorkbenchTests.php
backend/tests/Feature/AccountSecurityTest.php
backend/tests/Feature/AdministrationAccessTest.php
backend/tests/Feature/AdministrationUsersTest.php
backend/tests/Feature/AdministratorAuthorizationTest.php
backend/tests/Feature/AdministratorConcurrencyTest.php
backend/tests/Feature/AllergenDictionaryManagementTest.php
backend/tests/Feature/AuthorizationMutationBoundaryTest.php
backend/tests/Feature/BossLocationRestrictionsTest.php
backend/tests/Feature/BossRawMaterialCatalogApiTest.php
backend/tests/Feature/BossRawMaterialCatalogAuthorizationTest.php
backend/tests/Feature/BossRawMaterialCatalogConcurrencyTest.php
backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php
backend/tests/Feature/BusinessRoleDefinitionTest.php
backend/tests/Feature/IdentityAuthenticationTest.php
backend/tests/Feature/IdentityMembershipTest.php
backend/tests/Feature/InstallationApiTest.php
backend/tests/Feature/InstallationDomainTest.php
backend/tests/Feature/InstallationPrecautionaryAllergenTest.php
backend/tests/Feature/LocalRawMaterialIntegrationTest.php
backend/tests/Feature/ManagementConcurrencyTest.php
backend/tests/Feature/ModuleConfigurationConcurrencyTest.php
backend/tests/Feature/ModuleConfigurationDatabaseIntegrityTest.php
backend/tests/Feature/ModuleConfigurationTest.php
backend/tests/Feature/OrganizationCapabilityAuditFailureTest.php
backend/tests/Feature/OrganizationCapabilityConcurrencyTest.php
backend/tests/Feature/OrganizationCapabilityDatabaseIntegrityTest.php
backend/tests/Feature/OrganizationCapabilityTest.php
backend/tests/Feature/PermissionDelegationTest.php
backend/tests/Feature/PersonnelAdministrationTest.php
backend/tests/Feature/PolishLocalizationTest.php
backend/tests/Feature/PrimaryCentralKitchenConcurrencyTest.php
backend/tests/Feature/RawMaterialCapabilitiesApiTest.php
backend/tests/Feature/RawMaterialConcurrencyTest.php
backend/tests/Feature/RawMaterialDocumentStorageTest.php
backend/tests/Feature/RawMaterialPermissionBootstrapTest.php
backend/tests/Feature/RawMaterialRevisionDataModelTest.php
backend/tests/Feature/RawMaterialStage2dApiTest.php
backend/tests/Feature/RawMaterialsTest.php
backend/tests/Feature/ReportAuthorizationTest.php
backend/tests/Feature/RoleAdministrationTest.php
backend/tests/Feature/SecurityApiTest.php
backend/tests/Feature/SecurityConcurrencyTest.php
backend/tests/Feature/Stage6a1IdentityRecoveryTest.php
backend/tests/Feature/SupplierDirectoryTest.php
backend/tests/Feature/SystemPermissionGrantOriginTest.php
backend/tests/Feature/SystemPermissionProvisionerTest.php
backend/tests/Feature/SystemProvisioningRequestValidatorTest.php
backend/tests/Feature/UserAvatarTest.php
backend/tests/Support/concurrent_boss_raw_catalog.php
backend/tests/Support/concurrent_create_location.php
```

### Frontend

```text
frontend/src/administration/AdministrationUsersPage.test.tsx
frontend/src/administration/AdministrationUsersPage.tsx
frontend/src/administration/BossLocationRestrictions.tsx
frontend/src/administration/usersApi.ts
```

## Zachowane pliki nowe i nieśledzone

```text
backend/database/migrations/2026_11_24_000060_enforce_single_business_role.php
backend/database/migrations/2026_11_24_000061_limit_central_kitchens.php
backend/database/migrations/2026_11_24_000062_require_effective_business_role.php
backend/database/migrations/2026_11_24_000063_authorize_identity_disable_token_cleanup.php
backend/tests/Feature/BossFinancialPermissionsTest.php
backend/tests/Feature/BossRawFinancialMatrixTest.php
backend/tests/Feature/EffectiveBusinessRoleTest.php
backend/tests/Feature/SingleCentralKitchenTest.php
backend/tests/Support/concurrent_kitchen.php
backend/tests/Support/concurrent_role.php
VELTRA_STAGE6A1_UNIFIED_RAW_NAVIGATION_DEPLOYMENT.md
VELTRA_STAGE_7A0_RBAC_VERIFICATION_REPORT.md
VELTRA_STAGE_7A0_1_ARCHITECTURE_RBAC_ALIGNMENT_REPORT.md
VELTRA_STAGE_7A0_1_CONTINUATION_REPORT.md
VELTRA_STAGE_7A0_1_COMPLETION_REPORT.md
VELTRA_STAGE_7A0_1_FINAL_REPORT.md
VELTRA_STAGE_7A0_1_REGRESSION_CLOSURE_REPORT.md
VELTRA_STAGE_7A0_1_PAUSE_CHECKPOINT.md
```

Pliki nieśledzone nie są objęte zwykłym `git diff`; nie wolno ich usuwać ani pomijać przy późniejszym przeglądzie.

## Wyniki, logi i dowody

- Aktualny kod backendu, szeroka regresja z wyłączeniem 26 przypadków obchodzących ograniczenia: **575 testów, 5799 asercji — OK**. Log: `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/regression-bounded-final.log`.
- Końcowy bezpiecznie wybrany zestaw Identity, RBAC, RAW, konfiguracji i współbieżności: **107 testów, 1191 asercji — OK**, `files/regression-safe-final-clean.log`. Przed nim pierwszy przebieg 107 testów miał jeden błąd wskutek pozostałości danych po przerwanym przebiegu; po potwierdzeniu pustej bazy przeszedł.
- Instalacja i pozostałe poprawiane klasy: przebieg 90 testów i 688 asercji miał 2 niepowodzenia; po poprawkach ich scenariusze i współbieżność: **11 testów, 105 asercji — OK**, `files/regression-central-final.log`. Poprzedni log: `files/regression-central-adapted.log`.
- Pełny backend na wcześniejszym stanie: **601 testów, 5814 asercji, 22 błędy i 2 niepowodzenia**, `files/regression-full-current.log`. Nie jest to wynik aktualnego drzewa. Jeszcze wcześniejszy pełny przebieg: 601 testów, 4705 asercji i 137 błędów, `files/regression-phpunit-final-512.log`; przebieg z limitem PHP 128 MB przerwał się z powodu pamięci.
- Frontend: pełny Vitest **32 pliki, 327 testów — OK**. `npm run build` i `npm run lint` — OK; ostrzeżenia: bundle ponad 500 kB i `src/visualPreview.tsx:39` (`react/only-export-components`). Szczegóły zapisano także w raporcie regresji.
- `pint --test`: **92 zmienione pliki PHP — OK**. `git diff --check` — OK.
- Tekstowy dowód uwierzytelnionej reaktywacji znajduje się w tym checkpointcie i raporcie regresji. Wcześniejszy zrzut odmowy dostępu BOSS do PA: `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/stage7a-boss-admin-denied.png`. Wcześniejsza macierz E2E A–D znajduje się w raporcie finalnym.
- Katalog `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/` zachowuje także `regression-focused.log`, `regression-retarget*.log`, `regression-raw-retarget.log`, `regression-corrections*.log`, `regression-phpunit-initial.log`, `regression-phpunit-final.log`, `regression-full-final.log` (przerwany), `regression-safe-final.log` i syntetyczny `regression-browser-fixture.php`. Wszystkie logi i PNG pozostawiono na miejscu. W tym zestawieniu `files/` oznacza ten katalog sesji.

## Nierozwiązane kwestie i przerwane prace

1. **Pełna aktualna suite backendu nie jest potwierdzona.** Wykluczono 26 przypadków, ponieważ istniejące fixture'y wymagają czasowego pominięcia ograniczenia jednej organizacji albo wyłączania triggerów, aby odtworzyć obcy tenant lub niespójny stan historyczny. Przykłady: `withForeignOrganizationFixture` i `createLegacyUndesignatedKitchen` w `backend/tests/InstallationTestCase.php`, a także scenariusze w `Stage6a1IdentityRecoveryTest.php`, `BossRawMaterialCatalogProvenanceTest.php` i `RawMaterialPermissionBootstrapTest.php`.
2. Wcześniejsze pełne i niektóre celowane przebiegi wykonały takie istniejące fixture'y w **izolowanej** bazie: czasowo usunęły ograniczenie `organizations_single_installation_unique` lub wyłączyły istniejące triggery i je przywróciły. Było to niezgodne z zakazem wyłączania ograniczeń. Po wykryciu przerwano kolejny pełny przebieg, a przed zatrzymaniem środowiska potwierdzono ponowną obecność tego ograniczenia i włączenie sprawdzanych triggerów. Nie wyłączono nowych ograniczeń jednej roli ani jednej centrali. Baza operacyjna nie była modyfikowana.
3. Bieżące testy dopuszczalne przeszły, lecz brak pełnej regresji jest blokerem akceptacji. Nie przedstawiać wcześniejszych 22 błędów i 2 niepowodzeń jako aktualnego wyniku: poprawione scenariusze zostały następnie sprawdzone w przebiegach celowanych i szerokim.
4. `BOSS_RAW_CATALOG_READ` pozostaje w kodzie `NOT_READY`; wcześniejsze E2E używało gotowości ustawionej wyłącznie w syntetycznej kopii testowej. Nie zmieniono gotowości ani aktywacji operacyjnej.
5. Migracje 060–063 nie zostały uruchomione operacyjnie. Przed ewentualnym, odrębnie zatwierdzonym wdrożeniem trzeba ocenić istniejące niejednoznaczne role, aktywne potwierdzone konta bez skutecznej roli oraz liczbę central; nie rozstrzygać ich automatycznie.

## Stan środowiska testowego i zasobów

- Testy wykonywano na osobnym daemonie `unix:///run/user/1000/veltra-test-docker/docker.sock`, ID `25b00a87-90c6-4ce2-900b-be9adf2f13eb`, etykieta `com.veltra.daemon-role=disposable-test`; `APP_ENV=testing`, host `postgres`, baza `veltra_testing`, runtime `veltra_app`, setup `veltra_migrator`.
- PostgreSQL testowy używał `tmpfs` na `/var/lib/postgresql/data`. Zatrzymano wyłącznie nazwane tymczasowe kontenery tej pracy: `veltra-regression-browser`, oba runnery backendu (`...-2e050adc345a`, `...-c6578d660764`), `veltra-test-application-clamav-1`, `veltra-test-application-postgres-1` i `veltra-test-application-redis-1`. Zweryfikowano brak uruchomionych kontenerów na izolowanym socketcie. Żadnego wolumenu nie usuwano; dane testowej bazy na tmpfs należy traktować jako nietrwałe i środowisko odtworzyć przed kolejnymi testami.
- Zatrzymano testowy Vite na `127.0.0.1:15173` oraz powiązane sesje runnerów; lista sesji nie wykazała aktywnego procesu testów. Testowa strona przeglądarki `e7e7d720-e5e4-4a5e-85d3-2e7c6511820d` nie była już dostępna przy próbie jej zamknięcia. Udostępniona karta `52c71ac4-c6f9-4978-8da1-1ff62e96d575` wskazuje na zatrzymany port testowy; jej wyłącznej własności nie potwierdzono, więc jej nie zamykano. Nie dotykano strony `127.0.0.1:5174` ani żadnych usług operacyjnych.
- Osobny daemon testowy może pozostać dostępny; nie zatrzymywano go, ponieważ jego wyłącznej własności dla tej pojedynczej operacji pauzy nie ustalono. Przed ponownym użyciem należy ponownie zweryfikować etykietę, socket, tożsamość bazy i brak zasobów operacyjnych.

## Dokładne wznowienie — tylko po osobnej autoryzacji

1. Potwierdzić gałąź, HEAD, `git status --short` i zachować wszystkie obecne śledzone oraz nieśledzone pliki. Przeczytać `VELTRA_STAGE_7A0_1_REGRESSION_CLOSURE_REPORT.md`; nie powtarzać integracji baseline, ogólnego audytu RBAC, ukończonych E2E ani już udokumentowanych przebiegów bez konkretnej potrzeby.
2. Najpierw rozstrzygnąć, jak zachować sens 26 wykluczonych testów przy bezwzględnym zakazie wyłączania ograniczeń i triggerów. Nie uruchamiać ich w obecnej postaci ani nie obchodzić zakazu. Nie osłabiać negatywnych asercji; jeżeli nie da się zachować scenariusza zgodnie z ograniczeniami, przedstawić właścicielowi konkretny konflikt i uzyskać decyzję.
3. Jeśli właściciel zezwoli na dalsze prace, odtworzyć zweryfikowane izolowane środowisko i uruchamiać testy zapisujące wyłącznie w `veltra_testing`. Ponownie sprawdzić tożsamość środowiska przed zapisami; nie używać równolegle tej samej bazy do testów PHPUnit i E2E. Do pełnego backendu używać `php -d memory_limit=512M vendor/bin/phpunit`, ale dopiero po bezpiecznym rozwiązaniu problemu fixture'ów.
4. Po ewentualnych zmianach uruchomić najmniejsze adekwatne testy, następnie wymagany pełny przebieg tylko jeśli jest zgodny z granicami, oraz właściwe sprawdzenia formatowania/builda. Zachować nowe rzeczywiste wyniki i w razie potrzeby uaktualnić raport; nie deklarować akceptacji bez pełnej wymaganej weryfikacji.
5. Nadal nie wykonywać migracji operacyjnych, zmian kont lub uprawnień, modyfikacji operacyjnych kontenerów, wdrożenia, commita, pusha, merge'a ani Stage 7A.1 bez osobnego upoważnienia.

**SAFELY PAUSED — READY TO RESUME**
