# VELTRA 2.0 — Stage 7A.0.1 — Raport wznowienia

## Podsumowanie i stan repozytorium

- Repozytorium: `/home/veltra/projekty/veltra`
- Gałąź: `feature/stage7a-rbac-alignment`
- HEAD: `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315` — zgodny z oczekiwanym.
- Wszystkie wcześniejsze zmiany implementacji, raporty i dowody zachowano. Nie wykonano commita, pusha, merge'a, resetu, stashowania, wdrożenia ani migracji operacyjnej. Nie rozpoczęto Stage 7A.1.
- Wznowienie zmieniło wyłącznie oczekiwane typy wyjątków w jednym istniejącym teście; kod aplikacji nie był modyfikowany.

## Rozstrzygnięcie 26 przypadków testowych

Zidentyfikowane fixture'y odtwarzały stany sprzeczne z obowiązującymi niezmiennikami: drugą organizację w instalacji, dodatkową/niedookreśloną centralę albo dane wymagające wyłączenia ograniczeń i triggerów. Takie założenia nie są poprawnymi fixture'ami dla zatwierdzonego modelu. Zastąpiono je testami zgodnymi z aktywnymi regułami bazy; nie wyłączano ograniczeń, triggerów ani zabezpieczeń produkcyjnych.

| Pierwotny cel | Ocena założenia | Zachowane / zastępcze pokrycie |
|---|---|---|
| Izolacja między dwiema istniejącymi organizacjami w jednej bazie | Założenie niezgodne z `organizations_single_installation_unique`; instalacja dopuszcza jedną organizację. | Sprawdzono odmowę dla nieznanych identyfikatorów organizacji/lokalizacji, ochronę kontekstu przed wyborem obcego klienta oraz integralność powiązań i granice zapisu. Nie twierdzimy, że zastępuje to runtime'owy test dwóch istniejących tenantów. |
| Wielokrotne lub historycznie nieoznaczone centrale | Założenie niezgodne z limitem jednej centrali na organizację; nie wolno obchodzić constraintu ani triggera, by odtwarzać taki stan. | Testy sprawdzają wybór i aktywność jedynej centrali, odmowę nieznanej lokalizacji oraz poprawne zachowanie w organizacji z wieloma restauracjami. Zachowano testy współbieżności tworzenia centrali. |
| Nieprawidłowe, osierocone lub historyczne powiązania | Część scenariuszy wymagała wyłączenia triggera/constraintu; nie odzwierciedla dozwolonego stanu bazy. | Zastępcze testy sprawdzają odmowę przed zapisem, klucze obce, provenance, niezmienność i obsługę prawidłowej historii bez obchodzenia ochrony bazy. |
| Aktywne konto bez roli albo fikcyjny wariant przypisania | Nieaktualne wobec wymagania jednej efektywnej roli dla aktywnego, w pełni provisionowanego użytkownika. | Fixture'y przypisują rolę jawnie; testy zachowują cykl kont oczekujących/nieaktywnych, atomową zmianę roli, ochronę administratora i integralność historycznych przypisań. |
| Dostęp RAW w wariantach niezgodnych z nowym modelem | Scenariusze wymagające obcych organizacji lub niespójnego źródła nie są odtwarzane przez naruszenie constraintów. | Zachowano testy katalogu, grantów, SKU, udostępniania, rewizji, eksportu, dokumentów, audytu i odmowy dla niewidocznych źródeł w prawidłowej organizacji. |

Przebieg skoncentrowany na dostosowanych 26 przypadkach: **26 testów, 176 asercji — OK**. Nie usunięto zabezpieczeń ani nie wyłączono znaczących negatywnych asercji. Ograniczenie: bez modelu wielu organizacji w jednej instalacji nie da się w tym środowisku runtime'owo dowieść izolacji pomiędzy dwoma istniejącymi tenantami; zastępcze testy sprawdzają odrzucenie obcych/nieznanych identyfikatorów i granice integralności.

## Dodatkowe błędy ujawnione przez pełną suite

Pierwszy pełny przebieg wykonał 601 testów / 5 971 asercji i ujawnił dwa błędy w `SystemPermissionProvisionerTest`. Oba wynikały z oczekiwanego typu wyjątku w teście, nie z przyznania uprawnień:

- `test_missing_approval_is_rejected_and_audited` oczekiwał `AuthorizationException`, podczas gdy niepoprawne zatwierdzenie prawidłowo powoduje `SystemPermissionProvisioningException`. Zmieniono wyłącznie typ przechwytywanego wyjątku; asercje braku grantów i audytu odmowy pozostały.
- `test_wrong_administrator_and_invalid_permissions_are_rejected` oczekiwał wyjątku provisionera dla użytkownika bez roli administratora, podczas gdy walidator celowo odrzuca tę próbę przez `AuthorizationException`. Zmieniono oczekiwany typ dla tego przypadku; asercja braku grantów i test nieprawidłowych uprawnień pozostały.

Po zmianie celowane uruchomienie tych przypadków przeszło: **2 testy, 6 asercji — OK**.

## Zmienione pliki

### Dostosowanie fixture'ów i scenariuszy 26 testów

```text
backend/tests/InstallationTestCase.php
backend/tests/Feature/AccountSecurityTest.php
backend/tests/Feature/AdministrationUsersTest.php
backend/tests/Feature/AdministratorAuthorizationTest.php
backend/tests/Feature/BossLocationRestrictionsTest.php
backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php
backend/tests/Feature/IdentityMembershipTest.php
backend/tests/Feature/InstallationDomainTest.php
backend/tests/Feature/OrganizationCapabilityDatabaseIntegrityTest.php
backend/tests/Feature/OrganizationCapabilityTest.php
backend/tests/Feature/RawMaterialCapabilitiesApiTest.php
backend/tests/Feature/RawMaterialDocumentStorageTest.php
backend/tests/Feature/RawMaterialPermissionBootstrapTest.php
backend/tests/Feature/RawMaterialRevisionDataModelTest.php
backend/tests/Feature/RawMaterialsTest.php
backend/tests/Feature/SingleCentralKitchenTest.php
backend/tests/Feature/Stage6a1IdentityRecoveryTest.php
backend/tests/Feature/SystemPermissionProvisionerTest.php
backend/tests/Feature/SystemProvisioningRequestValidatorTest.php
```

W ostatnim wznowieniu zmieniono tylko `backend/tests/Feature/SystemPermissionProvisionerTest.php`, zgodnie z opisem powyżej. Wcześniejsze implementacje Stage 7A.0.1 i powiązane testy pozostały niezacommitowane i nietknięte.

## Pełna regresja i inne sprawdzenia

- Izolowana pełna suite backendu, polecenie `php -d memory_limit=512M vendor/bin/phpunit`: **601 testów, 5 975 asercji — OK**. Czas: **16:35.594**, pamięć raportowana przez PHPUnit: **103 MB**.
- Log pełnego, końcowego przebiegu: `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/regression-resume-full.log`.
- Log pierwszego pełnego przebiegu z dwoma błędami zachowano osobno: `.../files/regression-resume-full-2errors.log`.
- Log przebiegu przerwanego na limicie 900 s zachowano osobno: `.../files/regression-resume-full-timeout-900s.log`. Nie liczy się on jako wynik testów.
- Testy frontendowe, build i lint nie powtórzono: wznowienie nie zmieniło frontendu. Wcześniejszy zweryfikowany wynik z raportu regresji pozostaje: **327 testów / 32 pliki — OK**, `npm run build` i `npm run lint` — OK, z odnotowanymi wcześniej ostrzeżeniami.
- Wcześniejsze uwierzytelnione E2E (w tym macierz finansowa RAW i reaktywacja konta) nie zostały powtórzone, ponieważ zmiana dotyczyła wyłącznie oczekiwanych wyjątków testu backendowego. Ich dowody i wyniki opisano w checkpointcie pauzy oraz wcześniejszych raportach.
- `git diff --check` — bez błędów.

## Środowisko testowe i bezpieczeństwo

Testy uruchomiono w bazie `veltra_testing` przez zweryfikowany daemon `unix:///run/user/1000/veltra-test-docker/docker.sock` z etykietą `com.veltra.daemon-role=disposable-test`; PostgreSQL działał z danymi na `tmpfs`. Runner PHPUnit został usunięty po zakończeniu. Po teście zatrzymano wyłącznie trzy potwierdzone kontenery tymczasowego środowiska: PostgreSQL, Redis i ClamAV. Zweryfikowano, że daemon nie ma już uruchomionych kontenerów. Nie dotykano kontenerów ani baz operacyjnych.

## Migracje, wdrożenie i ograniczenia

- Migracje `2026_11_24_000060`–`000063` nie były wykonywane operacyjnie. Przed osobno zatwierdzonym wdrożeniem wymagają przeglądu aktywnych potwierdzonych kont bez efektywnej roli, niejednoznacznych przypisań ról i liczby/konfiguracji central. Nie należy automatycznie rozstrzygać istniejących niejednoznaczności.
- `BOSS_RAW_CATALOG_READ` pozostaje `NOT_READY`; wcześniejsze E2E korzystało z gotowości przygotowanej wyłącznie w syntetycznym środowisku. Lokalne wdrożenie korzystające z tego dostępu wymaga odrębnego przygotowania gotowości/modułu zgodnie z zatwierdzonym procesem. Nie zmieniano konfiguracji operacyjnej.
- Izolacja pomiędzy dwiema istniejącymi organizacjami nie została runtime'owo zweryfikowana, ponieważ aktualny niezmiennik bazy dopuszcza jedną organizację na instalację. Nie jest to nierozwiązany błąd testu; jest to granica modelu i zakresu zastępczych testów.

## Werdykt

**Stage 7A.0.1 — GOTOWE DO ODBIORU TESTOWEGO:** wszystkie 601 testów backendu bieżącego drzewa przeszły, w tym 26 dostosowanych przypadków. Nie pozostał błąd suite. **Wdrożenie lokalne nie jest gotowe bez osobnego przejrzenia i uruchomienia wymaganych migracji oraz przygotowania gotowości `BOSS_RAW_CATALOG_READ`.** Nie dokonano żadnej zmiany operacyjnej.
