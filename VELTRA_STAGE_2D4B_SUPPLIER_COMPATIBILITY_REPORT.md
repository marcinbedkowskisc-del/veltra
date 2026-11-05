# VELTRA 2.0 — Stage 2D.4B — Raport zgodności produktów dostawców

**Data weryfikacji:** 2026-11-05  
**Tryb:** DEVELOPMENT ONLY  
**Werdykt:** implementacja i dozwolona regresja zakończone powodzeniem  
**Stage 2C:** bez zmian; testy zastrzeżone nie były uruchamiane  
**RAW_MATERIALS:** brak aktywacji; istniejąca bramka gotowości pozostaje obowiązująca  
**VSEF:** bez zmian

## Zakres implementacji

Dodano niezmienny, dopisywany model ocen zgodności produktów dostawców. Każda ocena wiąże tę samą organizację, dokładną rewizję produktu dostawcy, dokładną zatwierdzoną rewizję surowca, zastosowany atest alergenowy i opublikowaną wersję słownika, dokładną wersję dokumentu oraz jej SHA-256. Rekord zawiera również wynik `COMPATIBLE` albo `INCOMPATIBLE`, uzasadnienie, aktora i czas oceny.

Decyzję biznesową składa uprawniony użytkownik z uzasadnieniem i potwierdzeniem przeglądu deklaracji alergenów. Wynik skanowania `CLEAN` kwalifikuje plik wyłącznie jako bezpieczny technicznie; nie ustala zgodności biznesowej. Dowód jest dopuszczany tylko wtedy, gdy bieżący autorytatywny wynik skanowania dokładnej wersji i sumy kontrolnej to `CLEAN`. Wynik późniejszego skanowania `PENDING`, `INFECTED` lub `ERROR` powoduje, że ocena przestaje być aktualna.

Widok stanu zwraca `PENDING`, jeżeli nie ma aktualnej oceny albo zmieniła się rewizja produktu, zatwierdzona rewizja surowca, atest lub aktualność skanowania. Historyczne oceny pozostają zachowane i nie są modyfikowane ani usuwane.

Dodano jawne uprawnienie `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`. Migracja nie nadaje go automatycznie użytkownikom ani rolom. Zapis oceny, audytu i klucza idempotencji jest transakcyjny. Trasy odczytu i decyzji pozostają za istniejącą autoryzacją i bramką gotowości `RAW_MATERIALS`; nie dodano tras uploadu ani pobierania dokumentów.

## Migracja i izolacja

- Dodano forward-only migrację `2026_11_06_000032_create_supplier_compatibility_assessments.php`.
- Na czystej, jednorazowej bazie PostgreSQL 17 pomyślnie zastosowano migracje 001–032. Odczytowa kontrola potwierdziła rejestrację migracji 032, utworzenie tabeli i triggera oraz brak uprawnienia `UPDATE` do tabeli ocen dla roli runtime `veltra_app`.
- Funkcja triggera sprawdza między innymi, czy aktor oceny odpowiada aktorowi z podpisanego kontekstu mutacji, oraz wymusza niezmienność ocen i aktualność powiązanych rewizji, atestu i dowodu.
- Testy uruchomiono w odizolowanej sieci Docker `internal=true` na bazie `veltra_testing`. PostgreSQL i Redis korzystały z `tmpfs`, PostgreSQL nie publikował portów, runner nie miał mountów. Kod testowany w runnerze zsynchronizowano z plikami lokalnymi.
- Przed testami odtworzono wyłącznie kontener PostgreSQL tej disposable sieci, aby sprawdzić pełny przebieg migracji od czystej bazy. Nie używano ani nie modyfikowano kontenerów, baz czy wolumenów aplikacyjnych.

## Wyniki weryfikacji

| Sprawdzenie | Wynik |
|---|---:|
| Migracje 001–032 na świeżej bazie disposable PostgreSQL 17 | **PASS** |
| Testy ukierunkowane Stage 2D.4B | **6 testów, 65 asercji; PASS** |
| Pełna dozwolona regresja | **199 testów, 1618 asercji; PASS** |
| PHP lint zmienionych plików PHP | **PASS — 9 plików** |
| Laravel Pint `--test` | **PASS — 9 plików** |
| Ochrona dowodów `PENDING`, `INFECTED` i `ERROR` | **PASS** |
| Aktualność i dokładne powiązanie dowodu `CLEAN` | **PASS** |
| Izolacja organizacji, autoryzacja, niezmienność i unieważnianie po nowych rewizjach | **PASS** |
| Atomowość zapisu oceny i audytu przy błędzie audytu | **PASS** |
| HTTP oraz blokada przy niegotowym `RAW_MATERIALS` | **PASS — żądanie zablokowane kodem 503** |

Przed wykonaniem pełnej regresji lista testów nie zawierała 17 zastrzeżonych przypadków: 15 testów `RawMaterialPermissionBootstrapTest` oraz wskazanych testów `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` i `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`. Nie uruchomiono ich ani nie odtwarzano.

Pierwsze uruchomienie nowego testu HTTP wykazało wyłącznie błędną ścieżkę JSON w asercji testowej (`error.code` zamiast `code`). Poprawiono samą asercję zgodnie z istniejącym formatem odpowiedzi. Po poprawce wszystkie 6 testów ukierunkowanych oraz pełna regresja zakończyły się powodzeniem; nie jest to potwierdzony defekt aplikacji.

## Ograniczenia i pozostałe warunki

- `RAW_MATERIALS` nie został aktywowany ani oznaczony jako gotowy. Test żądania HTTP potwierdził odpowiedź 503 za istniejącą bramką; wynik nie jest raportowany jako udane wykonanie operacji biznesowej przy niegotowym module.
- Ocena biznesowa nadal wymaga jawnego uprawnienia i zatwierdzonego kontekstu rewizji. Nie ma automatycznych nadań uprawnień.
- Przed użyciem produkcyjnym wymagane są odrębne zatwierdzenie wdrożenia oraz operacyjna polityka utrzymania i monitorowania świeżości sygnatur ClamAV. Wynik `CLEAN` nie zastępuje biznesowej oceny zgodności.
- Nie dodano frontendu, importu/eksportu, etykiet, parsowania faktur, OCR, KSeF ani funkcji magazynowych.
- Nie wykonano wdrożenia, commitów ani pushy; nie rozpoczęto Stage 2E.

## Pliki objęte Stage 2D.4B

- `backend/database/migrations/2026_11_06_000032_create_supplier_compatibility_assessments.php`
- `backend/app/Domain/RawMaterials/RawMaterialCompatibilityAssessmentService.php`
- `backend/app/Domain/RawMaterials/RawMaterialCommercialService.php`
- `backend/app/Domain/RawMaterials/RawMaterialPermission.php`
- `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`
- `backend/app/Http/Requests/RawMaterialCompatibilityAssessmentRequest.php`
- `backend/routes/api.php`
- `backend/lang/pl/messages.php`
- `backend/tests/Feature/RawMaterialCompatibilityAssessmentTest.php`

**Podsumowanie:** Stage 2D.4B jest zaimplementowany i zweryfikowany w DEVELOPMENT. Nie stwierdzono potwierdzonych defektów aplikacji w badanym zakresie. Nie oznacza to akceptacji Stage 2C ani gotowości produkcyjnej.
