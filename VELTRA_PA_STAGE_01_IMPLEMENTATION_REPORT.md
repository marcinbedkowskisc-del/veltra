# VELTRA 2.0 — raport implementacyjny PA-01

Data: 2026-11-17. **Wynik: PARTIAL — implementacja lokalna i walidacja frontendu wykonane; rzeczywiste testy backendowego RBAC są zablokowane.** Nie jest to potwierdzenie odbioru bezpieczeństwa ani zgoda na wdrożenie.

## 1. Podsumowanie

Dodano lokalnie wejście `/administration`, podstawowy układ, sześć podstron-placeholderów oraz osobną bramkę administratora `/api/v1/administration/access`. Na serwerze wykorzystywane są istniejące mechanizmy tożsamości, kontekstu i RBAC, bez zależności od dostępności RAW. Sekcje nie wykonują operacji biznesowych. Testy React i kompilacja przeszły; rzeczywiste testy HTTP/PostgreSQL backendu **nie zostały wykonane**.

Zapoznano się z [audytem PA](VELTRA_PA_TECHNICAL_GAP_AUDIT_2026-11-17.md) oraz dokumentami PA UX, UI standards, RBAC 1D, architektury modułów 1E i decyzjami F01–F43 w folderze VELTRA.

## 2. Pliki utworzone i zmodyfikowane

Nowe:
- [RequireAdministrator.php](backend/app/Http/Middleware/RequireAdministrator.php) — middleware.
- [AdministrationAccessTest.php](backend/tests/Feature/AdministrationAccessTest.php) — scenariusze HTTP/RBAC na izolowanej bazie testowej.
- [AdministrationPanel.tsx](frontend/src/AdministrationPanel.tsx) — układ i placeholdery.
- [AdministrationPanel.test.tsx](frontend/src/AdministrationPanel.test.tsx) — testy dostępu i stanów UI.
- Niniejszy raport.

Zmienione:
- [api.php](backend/routes/api.php) — chroniona grupa PA i jeden endpoint odczytowy.
- [App.tsx](frontend/src/App.tsx) — kontrola dostępu, stany błędów i routing PA.
- [ApplicationShell.tsx](frontend/src/ApplicationShell.tsx) — warunkowe wejście do PA.
- [App.css](frontend/src/App.css) — układ responsywny PA.
- [README.md](README.md) — kontrakt PA-01.

Przed pracą nieśledzone były oba wcześniejsze audyty RAW i PA; pozostały nienaruszone. Nie dodano ani nie uruchomiono migracji.

## 3. Frontend

Po zalogowaniu i wybraniu aktywnego kontekstu frontend wykonuje kontrolny odczyt PA. Wejście w menu i zawartość panelu są widoczne wyłącznie po poprawnej odpowiedzi `allowed: true`. Bez sesji wyświetlane jest żądanie logowania; bez kontekstu — prośba o jego wybór; 403 daje odmowę; błędy sieci/serwera i niepoprawne odpowiedzi pokazują błąd z możliwością ponowienia. 401/419 korzysta z istniejącej obsługi wygaśnięcia sesji.

Linki sekcji wykonują zwykłą nawigację, a na nowej stronie dostęp jest sprawdzany ponownie. Kontrola jest ponawiana po zmianie lub odświeżeniu kontekstu; spóźniona odpowiedź poprzedniego kontekstu jest ignorowana. Widoczność w UI nie zastępuje autoryzacji serwera. Dostęp nie jest sprawdzany okresowo w otwartej karcie: skuteczność cofnięcia na następnym żądaniu serwerowym zapewnia bramka backendowa, a nie frontendowy cache.

Sidebar zawiera zatwierdzone grupy i nazwy sekcji oraz aktywny link `aria-current`. Sekcje informują, że funkcjonalność nie jest jeszcze zaimplementowana. Menu jest statyczne, dostępne klawiaturą i nie jest akordeonem. Przy wąskim ekranie sidebar i treść układają się jeden pod drugim.

## 4. Backend i API

`GET /api/v1/administration/access` zwraca `{"data":{"allowed":true}}` tylko po przejściu kolejno przez sesję SPA, Sanctum, `ValidateIdentityContext`, `RequireOperatingContext` i `RequireAdministrator`. Middleware wywołuje `BusinessAuthorization::eligible` i `hasRole(..., Administrator)`, z odczytem efektywnej roli przy każdym żądaniu. Zwracane są standardowe odpowiedzi 401/403; brak wymagań grantów RAW i middleware dostępności RAW. Istnieje limit `throttle:security`. Endpoint jest wyłącznie odczytowy, a jego pomyślna odpowiedź ma `Cache-Control: no-store`.

Nie zmieniono hierarchii ról, obsługi grantów, istniejących endpointów personelu, gotowości modułów ani mechanizmów ostatniego administratora. Dodana grupa jest granicą dla przyszłych endpointów PA; przyszłe operacje muszą pozostać w tej grupie i mieć własne wymagane kontrole domenowe.

## 5. Wyniki testów

| Scenariusz | Faktyczny wynik |
|---|---|
| Aktywny administrator: dostęp PA | UI **PASS** przy syntetycznej odpowiedzi API; rzeczywisty backend **NIEURUCHOMIONE**. |
| Pracownik, manager, BOSS: odmowa | UI **PASS** dla syntetycznego 403; osobne role w teście backendowym **NIEURUCHOMIONE**. |
| Bezpośredni adres PA i bezpośredni endpoint non-admin | UI adres **PASS** z syntetycznym 403; HTTP backend **NIEURUCHOMIONE**. Dokument SPA może być dostarczony przez serwer frontendu, ale nie renderuje zawartości PA bez odpowiedzi serwera. |
| Brak sesji | UI **PASS**; rzeczywisty endpoint **NIEURUCHOMIONE**. |
| Wyłączony administrator | HTTP backend **NIEURUCHOMIONE**; kod testu oczekuje 401 na kolejnym żądaniu. |
| Odebranie administratora | UI ponowna kontrola po odświeżeniu przypisań **PASS** z syntetycznym 403; rzeczywista zmiana roli i kolejne żądanie backendowe **NIEURUCHOMIONE**. |
| PA przy niedostępnym RAW | UI **PASS** bez żądania RAW na syntetycznej ścieżce; rzeczywista dostępność modułu i autoryzacja backendu **NIEURUCHOMIONE**. |
| Manager: dozwolona operacja personelu poza PA | Istniejący endpoint niezmieniony; nowy test regresyjny **NIEURUCHOMIONE**. |
| Niepoprawna odpowiedź, błąd 500, ponowienie, 401, zmiana kontekstu i spóźniona odpowiedź | Testy frontendowe **PASS**, z odpowiedziami syntetycznymi. |

Polecenia i wyniki:

- `cd frontend && npm test -- --run src/AdministrationPanel.test.tsx src/App.test.tsx src/api.test.ts src/rawMaterials/RawMaterialsCatalogPage.test.tsx src/rawMaterials/RawMaterialCategoryManager.test.tsx src/design-system/design-system.test.tsx` — **PASS**, 6 plików, 81 testów.
- `cd frontend && npm run build` — **PASS**, TypeScript i Vite; build dotyczy lokalnego kodu, nie wdrożenia.
- `cd frontend && npm run lint` — **PASS**, jedno wcześniejsze ostrzeżenie `react(only-export-components)` w `src/visualPreview.tsx:39`.
- `git diff --check` — **PASS** dla śledzonych zmian; nowe pliki nie są objęte zwykłym `git diff`.
- `cd backend && php artisan test tests/Feature/AdministrationAccessTest.php tests/Feature/SecurityApiTest.php` — **NIEURUCHOMIONE**: `php: command not found`, kod 127. Brak dostępnego lokalnego interpretera PHP. Dodatkowo nie potwierdzono odizolowanej, wcześniej przygotowanej bazy testowej; [InstallationTestCase.php](backend/tests/InstallationTestCase.php) wykonuje `TRUNCATE`, dlatego nie uruchamiano jej na istniejących zasobach. Bez wyraźnej zgody nie tworzono/migrowano bazy, nie zmieniano kontenerów i nie instalowano systemowego PHP.

### Kontrola wizualna

W przeglądarce obejrzano rzeczywisty komponent z tymczasowym lokalnym serwerem Vite i syntetycznymi odpowiedziami wyłącznie dla tożsamości/kontekstu/dostępu; nie są to rzeczywiste dane ani dowód autoryzacji backendu. Przy viewportach 1440×900 i 320×700 widoczne były nawigacja, aktywne „Alergeny” i placeholder; przy 320 px szerokość dokumentu wyniosła 320 px. Przy 375×812 fokus można było ustawić na linku, a treść układała się pod nawigacją. Zrzuty obejrzano w narzędziu przeglądarkowym, nie zapisano osobnych plików. Próba przechwytywania żądań w narzędziu przeglądarkowym zakończyła się timeoutem; alternatywny tymczasowy lokalny serwer syntetyczny umożliwił kontrolę. Przed podłączeniem syntetycznego API odczytowe żądania lokalnego podglądu do domyślnego proxy zwróciły m.in. 401/503; nie wykonywano mutacji.

## 6. Odchylenia i kwestie otwarte

- Brak akordeonu i logiki F10/F14/F23/F27/F29/F40 jest zgodny z zakresem PA-01. Dostęp wymaga aktywnej lokalizacji operacyjnej, zgodnie z istniejącą granicą tożsamości.
- Brak wyniku testów PHP i PostgreSQL jest **blokadą odbioru** scenariuszy autoryzacji, wyłączenia konta, cofnięcia roli oraz regresji personelu. Statyczny kod testu i syntetyczny frontend nie zastępują tych wyników.
- Integracja PA w rzeczywistej sesji oraz sprawdzenie przeładowania w rzeczywistym środowisku nie są zweryfikowane.
- Testy wykorzystują istniejący projektowy mechanizm testowy czyszczący bazę: należy uruchamiać je wyłącznie w jednoznacznie zweryfikowanym, odizolowanym środowisku testowym przygotowanym za osobną zgodą, bez operacji na STAGING/PROD.

## 7. Gotowość PA-02 i stan przekazania

Układ PA jest przygotowany do późniejszego zastąpienia statycznych grup wspólnym `VeltraAccordion`, ale **rekomendacja: nie uznawać PA-01 za odebrany i nie rozpoczynać PA-02 przed wykonaniem rzeczywistych testów backendowych i akceptacją właściciela**. `RAW_MATERIALS` pozostaje NOT_READY; nie zmieniano jego stanu.

Tymczasowe procesy lokalnego podglądu i syntetycznego API zostały zatrzymane. Nie wykonano migracji, zmian danych produkcyjnych, wdrożenia, commit ani push. Gałąź `main`, HEAD `1aed58a17a8e459b4313c3f612443eafc59b10db`; zmiany PA-01 pozostają lokalne i niezatwierdzone.

Google Drive: publiczny folder jest czytelny, lecz nie ma dostępnej uwierzytelnionej ścieżki zapisu; raport pozostaje lokalny i nie został przesłany.
