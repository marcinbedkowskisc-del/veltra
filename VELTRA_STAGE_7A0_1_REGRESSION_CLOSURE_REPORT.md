# Stage 7A.0.1 — zamknięcie regresji

## Zakres i bezpieczeństwo

Gałąź `feature/stage7a-rbac-alignment`, HEAD `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`. Zachowano wcześniejsze niezacommitowane zmiany i raporty. Testy zapisujące wykonywano w izolowanej bazie `veltra_testing` na osobnym daemonie Docker oznaczonym `com.veltra.daemon-role=disposable-test`; PostgreSQL używał tymczasowego systemu plików. Nie modyfikowano operacyjnej bazy, kont, uprawnień, sekretów, kontenerów ani konfiguracji modułów. Nie wykonano migracji operacyjnej, commita, pusha, merge'a ani wdrożenia i nie rozpoczęto Stage 7A.1.

## Poprawki

- Migracja `backend/database/migrations/2026_11_24_000063_authorize_identity_disable_token_cleanup.php` dopuszcza w podpisanym kontekście `identity.disable` wyłącznie `DELETE` w `activation_tokens` i `password_reset_tokens`. Nie rozszerza grantów bazy ani nie wyłącza triggerów. Zastosowano ją wyłącznie w bazie testowej.
- `backend/tests/Feature/IdentityAuthenticationTest.php` sprawdza usuwanie obu rodzajów tokenów wyłączanego konta, zachowanie tokenów innego adresu i zmianę wersji uwierzytelnienia. `AuthorizationMutationBoundaryTest.php` sprawdza odrzucenie `INSERT` i `UPDATE` obu tabel w tym kontekście.
- Usunięto niezgodny helper `IdentityTestCase::user()` tworzący aktywną potwierdzoną tożsamość bez roli. Fixture'y korzystają z jawnego konta oczekującego lub jawnej roli i, gdy potrzebne, członkostwa. Zmiany obejmują testy Identity, autoryzacji, panelu, RAW, konfiguracji modułów i testy współbieżności w `backend/tests/Feature/`, `backend/tests/Concerns/` i `backend/tests/Support/`.
- Scenariusze historycznie zakładające wiele central dostosowano do zatwierdzonego limitu: wykorzystują wiele restauracji albo sprawdzają odmowę drugiej centrali. Testy zachowują sprawdzenie historii, ograniczeń i izolacji; nie wyłączano nowych ograniczeń.

## Przeglądarka

W rzeczywistej uwierzytelnionej przeglądarce, na syntetycznej organizacji i kontach w izolacji, administrator wyłączył konto BOSS, cofnął rolę oraz członkostwo. Reaktywacja bez roli zwróciła HTTP 409 i komunikat wymagający jednej skutecznej roli. Po jawnym przywróceniu członkostwa i roli BOSS reaktywacja powiodła się, a historia zachowała stare i nowe przypisania. BOSS zalogował się, wybrał centralę i otrzymał HTTP 403 oraz „Brak dostępu” w panelu administracyjnym. Aktywny BOSS miał nieaktywny przycisk cofnięcia ostatniej roli. Jest to test rzeczywistego UI/API, nie frontendowy smoke test.

Wcześniejszy `VELTRA_STAGE_7A0_1_FINAL_REPORT.md` zawiera uwierzytelnione E2E czterech kombinacji finansowych RAW, źródeł i historii; nie powtarzano tych niezmienionych scenariuszy. Wcześniejszy dowód odmowy PA: `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/stage7a-boss-admin-denied.png`.

## Wyniki weryfikacji

- Frontend: pełny Vitest **32 pliki, 327 testów — OK**. `npm run build` i `npm run lint` — OK; pozostały ostrzeżenia o rozmiarze bundla i w `src/visualPreview.tsx`.
- Backend: ostatni zakończony pełny przebieg, jeszcze przed dostosowaniem części testów jednej centrali, wykonał **601 testów, 5814 asercji: 22 błędy i 2 niepowodzenia**. Dotyczyły starych fixture'ów lub scenariuszy wielu central; nie jest to wynik aktualnego drzewa. Pierwszy pełny przebieg z limitem 512 MB miał 137 błędów; wcześniejszy przebieg z limitem 128 MB przerwał się z powodu pamięci.
- Po dalszych poprawkach przebieg dziewięciu klas instalacji, provisioningu i konfiguracji: **90 testów, 688 asercji, 2 niepowodzenia**. Oba poprawiono; kontrolny przebieg obejmujący te przypadki i współbieżność: **11 testów, 105 asercji — OK**.
- Końcowy bezpiecznie wybrany zestaw Identity, granic mutacji, cyklu konta, ról, administracji, BOSS/RAW, konfiguracji i współbieżności: **107 testów, 1191 asercji — OK**. Pierwsze uruchomienie tego zestawu miało błąd spowodowany danymi pozostałymi po przerwanym teście; po sprawdzeniu pustej izolowanej bazy ponowiony zestaw przeszedł.
- `pint --test` dla 92 zmienionych plików PHP i `git diff --check` — OK.
- Końcowy szeroki przebieg aktualnego backendu bez fixture'ów omijających ograniczenia: **575 testów, 5799 asercji — OK**. Wykluczono 26 przypadków; pełny wynik: `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/regression-bounded-final.log`.

## Ograniczenie pełnego przebiegu

W już wykonanych pełnych i niektórych wcześniejszych testach uruchomiono istniejące fixture'y, które w izolowanej bazie tymczasowo usuwały `organizations_single_installation_unique` lub wyłączały istniejące triggery, a następnie je przywracały. Dotyczy to m.in. `withForeignOrganizationFixture` i `createLegacyUndesignatedKitchen` w `backend/tests/InstallationTestCase.php` oraz scenariuszy w `Stage6a1IdentityRecoveryTest.php` i `BossRawMaterialCatalogProvenanceTest.php`. Było to niezgodne z granicą „nie wyłączać ograniczeń”; przepraszam. Nie dotyczyło środowiska operacyjnego, a po przerwaniu sprawdzono przywrócenie wymienionego ograniczenia i badanych triggerów. Nie wyłączano nowych ograniczeń jednej roli ani jednej centrali.

Po wykryciu tych fixture'ów kolejny pełny przebieg przerwano. Bez ich przeprojektowania albo osobnego rozstrzygnięcia nie można wykonać pełnej obecnej suite'y przy zachowaniu tego zakazu i jednocześnie zachować scenariusze wymagające drugiej organizacji lub historycznie niespójnego stanu. Dlatego nie należy traktować nieprzerwanej pełnej regresji aktualnego drzewa jako potwierdzonej.

## Wymagania przed lokalnym wdrożeniem

Migracje 060–063 wymagają osobnego zatwierdzenia i wykonania w docelowym środowisku. Przed nimi należy sprawdzić niejednoznaczne przypisania ról, aktywne potwierdzone konta bez skutecznej roli oraz istniejące centrale; migracje nie wybierają automatycznie rozstrzygnięć. `BOSS_RAW_CATALOG_READ` pozostaje w kodzie `NOT_READY`: testy przeglądarkowe RAW używały gotowości ustawionej wyłącznie w syntetycznej kopii testowej. Przed lokalnym korzystaniem z tej funkcji konieczna jest odrębna decyzja i przygotowanie jej gotowości; nie zmieniono konfiguracji operacyjnej.

## Werdykt

**CZĘŚCIOWO ZAMKNIĘTE — NIE GOTOWE DO AKCEPTACJI.** Wyłączanie kont i ukierunkowane scenariusze przeszły, podobnie jak 575 dopuszczalnych testów backendu i pełny frontend. Pełna regresja aktualnego drzewa pozostaje niepotwierdzona: 26 przypadków wymaga rozwiązania wskazanego konfliktu fixture'ów z zakazem wyłączania ograniczeń.
