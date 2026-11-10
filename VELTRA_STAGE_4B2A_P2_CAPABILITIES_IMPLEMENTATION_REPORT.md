# VELTRA 2.0 — Stage 4B.2A-P2

## Raport implementacji endpointu capabilities RAW

**Status: PARTIAL** — implementacja i walidacja frontendu zakończone; uruchomienie testów feature backendu oraz dostarczenie do Google Drive pozostały zablokowane ograniczeniami środowiska.

## 1. Zakres i wynik

Dodano uwierzytelniony, tylko do odczytu endpoint capabilities dla `RAW_MATERIALS`. Korzysta on z istniejącego resolvera efektywnych grantów oraz z aktualnego kontekstu lokalizacji. Trasa działa poza middleware dostępności RAW, więc może zwrócić capabilities również przy `NOT_READY`.

Odpowiedź rozdziela:

- `permissions.create` — efektywny grant `RAW_MATERIALS_CREATE`, niezależnie od gotowości modułu;
- `readiness` i `available` — stan gotowości oraz dostępność modułu w bieżącej lokalizacji;
- `actions.create` — wskazanie interfejsu wynikające z grantu, ograniczenia głównego kontekstu katalogu i dostępności modułu.

Wskazanie `actions.create` nie zastępuje kontroli backendowej. Nie utworzono formularza ani nie użyto rezerwacji SKU jako sondy autoryzacji.

## 2. Zmienione i dodane pliki

- `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php` — wspólna walidacja świeżego użytkownika i lokalizacji oraz odczyt efektywnego grantu CREATE i ograniczenia głównego kontekstu.
- `backend/routes/api.php` — GET `/api/v1/security/raw-materials/capabilities` w grupie zabezpieczeń, poza middleware gotowości RAW.
- `backend/app/Http/Controllers/Api/V1/RawMaterialCapabilitiesController.php` — odpowiedź capabilities dla sesyjnego kontekstu operacyjnego.
- `backend/tests/Feature/RawMaterialCapabilitiesApiTest.php` — feature testy stanu `NOT_READY`, dostępności i głównego kontekstu, braku grantu, delegowania/cofnięcia, sesji/kontekstu i metody HTTP.
- `frontend/src/rawMaterials/api.ts` — typ, walidacja kontraktu oraz adapter bez cache.
- `frontend/src/rawMaterials/api.test.ts` — testy poprawnej i niespójnej odpowiedzi oraz obsługi błędu niedostępności modułu.
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx` — fail-closed pobieranie capabilities dla lokalizacji, odrzucanie spóźnionych odpowiedzi, odświeżanie przy powrocie karty i wyświetlanie wyłącznie nieoperacyjnego przycisku dla `actions.create`.
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx` — testy ładowania, błędu, braku grantu, `NOT_READY`, zmiany lokalizacji, spóźnionej odpowiedzi oraz 401/419.
- `frontend/src/App.tsx` — przekazanie ID aktywnej lokalizacji do strony katalogu.

Zmiana frontendowa wykracza poza prezentację: kontroluje, czy wskazanie możliwości z poprzedniej lokalizacji może pozostać widoczne. Nie zmieniano formularza, API zapisu, RBAC ani kontraktu tworzenia.

## 3. Wyniki walidacji

### Frontend

- `cd frontend && npm test` — **PASS**, 6 plików testowych, 51 testów.
- `cd frontend && npm run build` — **PASS**, TypeScript i build Vite.
- `cd frontend && npm run lint` — **PASS**, pozostało jedno ostrzeżenie Fast Refresh w niezmienianym w ramach tej funkcji `frontend/src/visualPreview.tsx`.
- `git diff --check` — **PASS**.

### Backend

- Parsowanie `RawMaterialAuthorization.php`, `RawMaterialCapabilitiesController.php`, `api.php` i `RawMaterialCapabilitiesApiTest.php` przez PHP `token_get_all(..., TOKEN_PARSE)` — **PASS**, bez błędów składni.
- `cd backend && php artisan test --filter=RawMaterialCapabilitiesApiTest` — **niewykonane**: host nie ma polecenia `php`.
- `docker compose exec -T backend php artisan test --filter=RawMaterialCapabilitiesApiTest` — **niewykonane**: działający kontener nie montuje bieżącego katalogu repozytorium i plik nowego testu w nim nie istnieje; PHPUnit zgłosił brak testów. Testów nie skopiowano do kontenera ani nie uruchomiono ponownie inną drogą.

W konsekwencji testy integracyjne endpointu i jego zachowania względem bazy/grantów wymagają uruchomienia w środowisku testowym, które ma dostęp do aktualnego kodu oraz zabezpieczonej bazy `veltra_testing`. Nie wykonano migracji ani testów na bazie instalacyjnej lub produkcyjnej.

## 4. Google Drive

W dostępnych narzędziach nie znaleziono zatwierdzonego konektora Google Drive. W poprzednim etapie selektor plików odrzucił ścieżkę WSL `\\wsl.localhost`; tej samej metody nie ponawiano. Nie użyto haseł, tokenów ani cookies, nie wykonano obejścia logowania i nie deklaruje się uploadu ani weryfikacji plików na Drive.

Raport lokalny:

- Linux: `/home/veltra/projekty/veltra/VELTRA_STAGE_4B2A_P2_CAPABILITIES_IMPLEMENTATION_REPORT.md`
- Windows: `\\wsl.localhost\Ubuntu\home\veltra\projekty\veltra\VELTRA_STAGE_4B2A_P2_CAPABILITIES_IMPLEMENTATION_REPORT.md`

## 5. Granice wykonania i pozostałe działania

- `RAW_MATERIALS` pozostaje `NOT_READY`; nie zmieniano readiness, konfiguracji dostępności ani danych lokalizacji.
- Nie zmieniano migracji, backendowych danych instalacyjnych ani kontenerów instalacji.
- Nie wykonano wdrożenia, commita ani pushu.
- Nie rozpoczęto formularza tworzenia ani Stage 4B.

Przed kontynuacją należy uruchomić nowe testy feature w dozwolonej bazie `veltra_testing`, przejrzeć wynik backendowy, a raport i artefakty przesłać do autoryzowanego folderu Drive dopiero przez obsługiwaną, uwierzytelnioną ścieżkę. Do tego czasu przycisk tworzenia pozostaje wyłącznie nieaktywnym placeholderem; właściwy formularz jest poza zakresem P2.
