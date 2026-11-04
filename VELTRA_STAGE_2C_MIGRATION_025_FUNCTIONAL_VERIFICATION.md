# VELTRA 2.0 — funkcjonalna weryfikacja migracji 025

**Data:** 4 listopada 2026  
**Zakres:** regresja funkcjonalna i zgodność migracji 025.  
**Poza zakresem:** testy penetracyjne, scenariusze SQL adwersarialne i niezależny audyt bezpieczeństwa.

## Podsumowanie

Na świeżym PostgreSQL 17 wykonano migracje 001–025 i uruchomiono nieadwersarialny zakres regresji backendu. Wszystkie **169 uruchomionych testów przeszły** z **1381 asercjami**, bez niepowodzeń, pominięć PHPUnit ani ostrzeżeń. Z powodu wyraźnego zakazu uruchamiania scenariuszy adwersarialnych z całkowitego zestawu 186 wykrytych testów wyłączono 17 przypadków; zatem nie jest to pełne wykonanie całej suity.

Upgrade z migracji 001–024 do 025 przeszedł na syntetycznej, poprawnej historii i zachował istniejące rekordy. Na drugiej bazie migracja odrzuciła aktywny produkt bez rewizji początkowej; nie zarejestrowano 025 ani nie pozostawiono jej obiektów DDL. Nie uruchamiano testów adwersarialnych. **Nie wydano werdyktu ACCEPT; niezależny re-audyt bezpieczeństwa pozostaje wymagany.**

## Izolacja i konfiguracja

- Docker Client/Server: **29.9.2**.
- PostgreSQL: **17** (`postgres:17-alpine`); Redis: **7.4** (`redis:7.4-alpine`).
- Dla każdego przebiegu utworzono osobną sieć Docker `--internal`, dedykowane kontenery PostgreSQL/Redis i magazyn `tmpfs`; portów nie publikowano.
- Utworzono syntetyczne, losowe hasła dla administratora testowego, migratora i roli runtime oraz dedykowane losowe `APP_KEY` i klucz podpisywania. Pliki `.env` miały charakter tymczasowy; nie używano ani nie kopiowano sekretów aplikacyjnych.
- Backend uruchamiano z tymczasowej kopii w `/tmp`. Katalogi `storage/framework/views` i `bootstrap/cache` były efemeryczne. Konfiguracja SPA została jawnie ustawiona w `SANCTUM_STATEFUL_DOMAINS`.
- Tymczasowe kontenery, sieci, kopie backendu, pliki środowiskowe i pliki seedów sprzątnięto po każdym przebiegu. Istniejące kontenery aplikacyjne, bazy i wolumeny pozostały nietknięte.
- W ostatnim przebiegu kontrolnym istniejące kontenery nadal działały; nie były używane do testów.

## Regresja backendu

Listowanie PHPUnit wykryło **186 przypadków**. Wykonano filtr regresji wyłączający wyłącznie:

- całą klasę `AuthorizationMutationBoundaryTest` — obejmuje bezpośrednie próby SQL, fałszowanie i obchodzenie granicy ticketów;
- całą klasę `RawMaterialPermissionBootstrapTest` — zawiera m.in. scenariusze `pg_temp`, odtwarzania uprawnień przez SQL i fałszowania grantów;
- `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`.

Łącznie **17 przypadków nie uruchomiono** z powodu zakazu testów adwersarialnych. Nie są one pominięciami zgłoszonymi przez PHPUnit.

| Wynik uruchomionego zakresu | Liczba |
|---|---:|
| Passed | 169 |
| Failed / errors | 0 |
| Skipped przez PHPUnit | 0 |
| Warnings | 0 |
| Assertions | 1381 |
| Niewykonane z powodu ograniczenia zakresu | 17 |

Wśród wykonanych testów znalazły się funkcjonalne i transakcyjne przypadki `RawMaterialsTest`, testy autoryzacji API i ról, delegowania i cofania uprawnień, integralności PostgreSQL oraz współbieżności.

### Weryfikowane przepływy

Przeszły m.in.:

- `test_block_archive_restore_and_reapproval_preserve_identity_and_history`;
- `test_unblock_state_and_immutable_audit_record_roll_back_together`;
- `test_supplier_product_and_initial_revision_roll_back_together`;
- `test_supplier_products_offer_prices_and_cost_permissions_are_isolated`.

Potwierdzono poprawne blokowanie/odblokowanie materiału i utworzenie wpisu audytowego, rollback obu zapisów w jednej transakcji, utworzenie produktu z rewizją początkową, utworzenie oferty oraz wycofanie zdarzenia cenowego bez zmiany cyklu życia oferty. Wykonany zakres obejmował także istniejące funkcjonalne testy API i uprawnień. Testy obejmujące niedozwolone scenariusze SQL pozostawiono niewykonane.

### Próby przygotowania środowiska

Początkowe uruchomienia ujawniły błędy konfiguracji wyłącznie tymczasowego runnera: nieczytelny dla użytkownika kontenera plik `.env`, brak zapisywalnego katalogu logów i brak katalogu kompilowanych widoków. W kolejnych próbach poprawiono wyłącznie ustawienia kopii testowej: odpowiednie uprawnienia dla `.env`, efemeryczne katalogi `storage`/`bootstrap/cache` oraz katalog `storage/framework/views`. Końcowe uruchomienie nie miało ostrzeżeń. Nie zmieniano kodu aplikacji w celu uzyskania PASS.

## Migracje

### Świeża instalacja

W świeżej bazie PostgreSQL 17 migracje **001–025 zakończyły się powodzeniem**. Redis odpowiadał `PONG`. W tych przebiegach nieadwersarialne testy łączyły się z tą samą świeżą bazą.

### Upgrade 024 → 025 z syntetyczną historią

Na dwóch odrębnych bazach efemerycznych zastosowano migracje 001–024, następnie wprowadzono syntetyczne rekordy historii: organizację, użytkownika, kategorię, surowiec i rewizję, jednostkę, dostawcę, produkt, ofertę i zdarzenie audytu. Dane seedowano administracyjną rolą testową na disposable DB, z pominięciem triggerów wyłącznie podczas przygotowania fixtures; były to dane sztuczne, nie wynik próby obejścia zabezpieczeń. W poprawnym wariancie produkt miał rewizję nr 1.

**Poprawny wariant:** migracja 025 zakończyła się powodzeniem. Kontrole po migracji wykazały:

- migracja 025 jest zarejestrowana;
- istnieje syntetyczny produkt i rewizja początkowa;
- syntetyczny surowiec i audyt pozostały obecne;
- dotychczasowe zdarzenie audytowe zachowało `transaction_id IS NULL` — migracja nie uzupełniała wstecznie historii;
- istnieje ograniczenie stanu cyklu życia oferty i odroczony constraint trigger wymagający rewizji produktu.

**Niespójny wariant:** pozostawiono aktywny syntetyczny produkt bez rewizji. `php artisan migrate --force` zakończył się niezerowym statusem. Po odmowie produkt nadal istniał i pozostawał aktywny, a liczba rewizji nadal wynosiła zero. Migracja 025 nie została zarejestrowana; kolumna `transaction_id`, nowe triggery i ograniczenie stanu oferty nie pozostały w bazie. To potwierdza fail-closed i atomowość dla sprawdzonego precondition.

Osobnego przypadku migracji dla historycznej oferty oznaczonej `WITHDRAWN` w tym zadaniu nie wykonano.

## Jakość kodu i konfiguracji

- PHP lint dla pięciu zmienionych plików: **PASS**.
- Pint `--test` dla tych samych pięciu plików: **PASS**.
- `sh -n docker/postgres/10-create-runtime-role.sh`: **PASS**.
- `docker compose --env-file <tymczasowy plik> -f compose.yaml config --quiet`: **PASS**.
- `git diff --check`: **PASS**.

## Defekty, ostrzeżenia i ograniczenia

- Końcowy uruchomiony zakres regresji nie wykazał defektów ani ostrzeżeń.
- Błędy z początkowego startu testów wynikały z praw dostępu i brakujących katalogów tymczasowego runnera; zostały usunięte przez poprawę konfiguracji izolowanego środowiska.
- Nie uruchomiono 17 testów zawierających bezpośrednie testy SQL/adwersarialne. Wymaga to osobnej, jawnie dozwolonej weryfikacji; nie można na tej podstawie deklarować kompletnego PASS całej suity.
- Nie wykonano testów adwersarialnych, testów nieograniczonych poświadczeń runtime ani niezależnego audytu bezpieczeństwa.
- Nie weryfikowano żadnej rzeczywistej instalacji ani jej danych historycznych. Wdrożenie 025 może odmówić migracji przy niezgodnych rekordach; ich korekta wymaga osobnego przeglądu właściciela danych i nie jest wykonywana automatycznie.

## Kontrole końcowe i status

Nie zmieniono istniejących baz, kontenerów ani wolumenów. Nie wykonano wdrożenia, commitów ani pushy. `RAW_MATERIALS` pozostaje `NOT_READY`; Stage 2D nie rozpoczęto i VSEF nie aktywowano.

Wyniki potwierdzają funkcjonalną poprawność w podanym, nieadwersarialnym zakresie oraz zgodność świeżej migracji i sprawdzonego upgrade’u. Nie stanowią niezależnej akceptacji bezpieczeństwa. **Stage 2C ACCEPT nie jest deklarowany; niezależny security re-audit pozostaje otwarty.**
