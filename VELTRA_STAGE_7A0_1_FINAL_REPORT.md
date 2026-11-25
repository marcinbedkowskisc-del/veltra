# VELTRA 2.0 — Stage 7A.0.1: raport końcowy

## Werdykt: CZĘŚCIOWE — NIE GOTOWE DO AKCEPTACJI

Końcowe poprawki roli i macierzy finansowej wdrożono i zweryfikowano w ukierunkowanych testach, ale regresja uwierzytelniania ma dwa błędy, a część starszych fixture'ów testowych nadal nie spełnia nowego ograniczenia bazy. Nie wykonano pełnej regresji. Nie należy uznawać etapu za ukończony.

Gałąź `feature/stage7a-rbac-alignment`, HEAD `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`. Zmiany pozostają niezacommitowane. Poprzednie raporty zachowano.

## Jedna rola i cykl życia — ZAIMPLEMENTOWANE, WERYFIKACJA CZĘŚCIOWA

- `backend/database/migrations/2026_11_24_000060_enforce_single_business_role.php` blokuje różne niecofnięte role jednego użytkownika, dopuszczając tę samą rolę w wielu lokalizacjach. `2026_11_24_000062_require_effective_business_role.php` dodaje odroczone constraint triggers: aktywny użytkownik z potwierdzonym adresem musi mieć dokładnie jedną efektywną rolę. Rola lokalna wymaga aktywnego członkostwa i lokalizacji.
- Migracje sprawdzają istniejące konflikty i przerywają bez automatycznego wyboru roli; migrację 062 zastosowano wyłącznie w izolowanej bazie. Sprawdzenie migracji na istniejących danych operacyjnych nie było autoryzowane.
- `RoleAdministration::replace` wykonuje autoryzowaną zamianę w transakcji z zachowaniem historii, wielu lokalizacji i ochrony administratora. Cofnięcie ostatniej efektywnej roli aktywnego potwierdzonego konta jest blokowane; podobnie usunięcie ostatniego członkostwa albo wyłączenie lokalizacji pozbawiające roli.
- `PersonnelAdministration::requestPersonnel`, `SecurityRequest`, `AccountLinks` i formularz PA wymagają jawnej odpowiedniej roli nowego konta przed zakończeniem aktywacji. Zatwierdzenie członkostwa konta bez roli nie wybiera za nie roli. Konta oczekujące i nieaktywne nie mają dostępu biznesowego.
- Administrator może jawnie przypisać członkostwo i rolę nieaktywnemu kontu, a następnie je reaktywować; bez wymaganej roli reaktywacja potwierdzonego konta jest odrzucana. `MembershipService` dopuszcza to wyłącznie przez jawnie przekazaną opcję używaną w autoryzowanej ścieżce administracyjnej. UI wyłącza cofnięcie ostatniej roli i udostępnia przygotowanie nieaktywnego konta.
- Istotne pliki: `backend/app/Domain/Authorization/{RoleAdministration,PersonnelAdministration,BusinessAuthorization}.php`, `backend/app/Domain/Identity/{AccountLinks,MembershipService}.php`, `backend/app/Domain/Installation/InstallationService.php`, `frontend/src/administration/{AdministrationUsersPage.tsx,usersApi.ts}`.

## Niezależne finanse RAW — ZAIMPLEMENTOWANE, PRZETESTOWANE W WYBRANYCH POWIERZCHNIACH

`RawMaterialCatalogValues::canReadCost` dla BOSS wymaga FOODCOST_VIEW, ale nie wymaga już PURCHASE_PRICES_VIEW. Inne role zachowują dotychczasową zależność. Dane ilościowe pozostają widoczne; nie próbowano blokować wnioskowania matematycznego z legalnie widocznych danych.

| Konfiguracja | Cena zakupu | Obliczony koszt | Wynik uwierzytelnionego UI katalogu |
|---|---|---|---|
| A | tak | tak | 28,00 PLN/opak.; 1,87 PLN/100g |
| B | nie | nie | oba pola „Brak uprawnień” |
| C | nie | tak | cena „Brak uprawnień”; koszt 1,87 PLN/100g |
| D | tak | nie | cena 28,00 PLN/opak.; koszt „Brak uprawnień” |

W każdej konfiguracji UI pokazał ilości 2800 i 1500. Uwierzytelnione odpowiedzi listy API potwierdziły obecność/nieobecność `reference_net_price` i `usable_unit_cost`; dla C koszt API wynosił `1.86666666666667`. Dla C szczegóły UI pokazały koszt i ilości bez ceny, a zakładka historii pokazała wersję surowca; historia cen zgłaszała brak uprawnienia.

`BossRawFinancialMatrixTest` sprawdza cztery konfiguracje rzeczywistych odpowiedzi listy i szczegółów, sortowania, historii cen, historii zmian, selektywnych eksportów CSV i odrzucenia archiwizacji. Ponownie sprawdza pobranie eksportu ceny po późniejszym ograniczeniu — 403. Dokumenty nie są klasyfikowane według zawartości: przy ograniczeniu którejkolwiek kategorii BOSS nie może ich listować ani pobierać. Nie jest to dowód klasyfikacji zawartości dokumentów ani przeglądu wszystkich hipotetycznych przyszłych endpointów.

Mechanizmy w `RawMaterialAuthorization`, `RawMaterialHistoryService`, `RawMaterialCommercialService`, `RawMaterialCsvCatalogExportWorkflow` i usługach dokumentów stosują decyzje widoczności. `BossLocationRestrictions` pozwala ograniczać koszty i ceny niezależnie, także wobec dodatkowych grantów.

## Pozostałe wymagane scenariusze

- **Odczyt BOSS:** uwierzytelnione testy wcześniejszego przebiegu potwierdziły widoczność centrali i dwóch restauracji oraz odpowiedzi `security/access`; obecny przebieg potwierdził katalog RAW, źródłowe ilości i historię w centrali. Testy backendu obejmują archiwum, źródła i historię. Nie zbudowano przyszłych modułów.
- **Tylko odczyt:** backend odrzuca modyfikacje BOSS oraz niezależne granty zapisu; podczas zamiany na BOSS niezgodne granty RAW są cofane. Wcześniejszy uwierzytelniony E2E potwierdził 403 dla zapisu lokalizacji oraz dostęp do PA 403; nowy test API potwierdza odmowę archiwizacji.
- **Panel administracyjny:** w aktualnej uwierzytelnionej przeglądarce administrator zobaczył obowiązkowy, początkowo pusty wybór roli nowego użytkownika oraz nieaktywny przycisk „Cofnij rolę” dla aktywnego BOSS. We wcześniejszym E2E administrator wykonał atomową zmianę roli w dwóch restauracjach i zapisał ograniczenie cen BOSS. Odzyskanie nieaktywnego konta sprawdzono w testach backendu i UI, lecz nie jako pełną operację w przeglądarce.
- **Jedna centrala:** migracja `2026_11_24_000061_limit_central_kitchens.php` i `InstallationService` ograniczają liczbę central; test `SingleCentralKitchenTest` obejmuje współbieżność. Nie zmieniano istniejących operacyjnych lokalizacji.

## Testy i dowody

- PostgreSQL i aplikacja wyłącznie na izolowanym daemonie oznaczonym `com.veltra.daemon-role=disposable-test`, baza `veltra_testing`, `APP_ENV=testing`; syntetyczne konta i rekordy. Nie modyfikowano operacyjnej bazy, kont, grantów, kontenerów ani konfiguracji.
- Końcowy ukierunkowany przebieg PHPUnit: **72 testy, 652 asercje — OK**: `RoleAdministrationTest`, `SecurityApiTest`, `EffectiveBusinessRoleTest`, `PersonnelAdministrationTest`, `AdministrationUsersTest`, `BossRawFinancialMatrixTest`, `BossFinancialPermissionsTest`, `BossRawMaterialCatalogAuthorizationTest`, `RawMaterialRevisionDataModelTest`, `SingleCentralKitchenTest`. Po nim `AccountSecurityTest`: **9 testów, 49 asercji — OK**. Osobno `AuthorizationMutationBoundaryTest` był w przebiegu **26 testów, 413 asercji — OK** przed ostatnią drobną zmianą logiki zatwierdzania członkostwa.
- `IdentityAuthenticationTest`: **14 testów, 129 asercji, 2 błędy**. Szczegóły w blokerach poniżej.
- Frontend Vitest: **5 plików, 100 testów — OK**, w tym PA, katalog i projekcje RAW. Wcześniejszy równoległy przebieg miał jeden błąd oczekiwania w teście tworzenia kategorii; test przeszedł osobno i w końcowym przebiegu bez równoległego builda.
- `npm run build` i `npm run lint` — OK; istnieją ostrzeżenia o dużym bundlu i `visualPreview.tsx`. `pint --test` dla plików PHP objętych końcowymi poprawkami oraz `git diff --check` — OK.
- Prawdziwe E2E odbyło się przez logowanie syntetycznych użytkowników, wybór kontekstu i rzeczywiste UI/API, nie jest to frontend smoke test. Powyższa tabela jest tekstowym dowodem aktualnej macierzy. Dowód PNG odmowy PA z poprzedniego uwierzytelnionego E2E: `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/stage7a-boss-admin-denied.png`.
- W testowym kontenerze przeglądarkowym tymczasowo ustawiono testową gotowość RAW i `BOSS_RAW_CATALOG_READ`; kod repozytorium i konfiguracja operacyjna nie zostały tak przestawione. Produkcyjny katalog capability nadal oznacza tę funkcję jako `NOT_READY`; testy nie dowodzą jej gotowości we wdrożeniu.

## Pozostałe konkretne blokery i ograniczenia

1. `IdentityService::disable` kończy się SQLSTATE `42501`: trigger unieważniania tożsamości usuwa `password_reset_tokens`, natomiast podpisany kontekst `identity.disable` nie jest dozwolony dla tej tabeli. Dwa testy `IdentityAuthenticationTest` dotyczące wyłączenia konta nie przechodzą. Jest to osobna ścieżka od przetestowanej administracyjnej `RoleAdministration::setAccountActive`; nie maskowano błędu ani nie rozszerzano pochopnie uprawnień bazy.
2. `IdentityTestCase::user()` nadal tworzy zweryfikowanego użytkownika bez roli w osobnych transakcjach. Kluczowe testy ról, personelu, administracji i `AccountSecurityTest` dostosowano do jawnych fixture'ów, lecz inne klasy nadal korzystają ze starego helpera. Przed dostosowaniem `AccountSecurityTest::test_reset_invalidates_prior_sessions_is_single_use_and_expires_after_thirty_minutes` wykazał SQLSTATE `23514`; po dostosowaniu cała ta klasa przechodzi. Pozostałe klasy nie zostały w całości zaktualizowane ani uruchomione. Należy dostosować ich scenariusze bez wyłączania triggerów i ponowić odpowiednie regresje.
3. Nie przeprowadzono pełnego E2E wszystkich alternatywnych endpointów, dokumentów, wszystkich kombinacji finansowych w szczegółach UI ani reaktywacji konta przez przeglądarkę. Potwierdzone zakresy podano powyżej.

Nie wykonano operacyjnych migracji, wdrożenia, commita, pusha, merge'a ani prac Stage 7A.1. Raportu nie wysłano do Google Drive.
