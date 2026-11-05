# VELTRA 2.0 — Niezależny przegląd kodu Stage 2D

**Data:** 2026-11-05  
**Zakres:** implementacja Stage 2D.1–2D.5, porównana z zatwierdzonym planem Stage 2D  
**Tryb:** READ-ONLY / DEVELOPMENT ONLY  
**Werdykt:** **FAIL**

## Recenzent i podstawa niezależności

Przegląd wykonał odrębny agent recenzencki `stage2d-independent-review` (typ `code-review`) w osobnym kontekście, niezależnie od autora wcześniejszej implementacji i raportu Stage 2D.6. Recenzent odczytał kod, migracje, testy, architekturę i raporty; nie edytował plików, nie uruchamiał testów ani migracji. To przegląd oddzielnego recenzenta, nie audyt zewnętrzny ani akceptacja bezpieczeństwa Stage 2C.

## Ustalenia

### 1. Rewizja v2 może zostać zatwierdzona bez atestu alergenowego

**Waga:** WYSOKA  
**Pewność:** wysoka  
**Lokalizacje:**

- `backend/app/Domain/RawMaterials/RawMaterialService.php:469, 674–683, 902, 1085, 1163–1172`
- `backend/database/migrations/2026_11_06_000028_create_raw_material_revision_allergen_and_storage_data.php:276–285`
- Wymaganie architektoniczne: `VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md:154`

**Dowody i zachowanie:** przy utworzeniu rewizji v2 na podstawie historycznej rewizji v1 w stanie `DECLARED`, ale bez atestu, serwis zachowuje brak atestu i oznacza snapshot jako `LEGACY_DECLARED`. Rewizja nadal ma `allergen_state = DECLARED`, a sprawdzenie zatwierdzenia wymaga tego stanu, nie obecności atestu v2. Trigger spójności w migracji dopuszcza `DECLARED` bez atestu.

**Skutek:** rewizja bez wymaganego atestu przypisanego do aktora i czasu może zostać zatwierdzona. Osłabia to wymóg atestacji przed aktywacją. Przejrzane testy sprawdzają zachowanie hash v1 oraz rozróżnienie `UNKNOWN` i `NO_KNOWN_ALLERGENS`, ale nie przypadek ponownego utworzenia i zatwierdzenia rewizji z historycznego `DECLARED` v1 bez atestu.

**Zakres ustalenia:** problem dotyczy przejścia historycznej rewizji v1 do zatwierdzanej rewizji v2; nie podważa samej zgodności historycznych odczytów hash v1. Recenzent zaleca wymagać poprawnego atestu v2 przy zatwierdzeniu, również dla danych dziedziczonych.

### 2. Tabele deklaracji i wariantów nie wymagają podpisanej granicy mutacji

**Waga:** ŚREDNIA  
**Pewność:** wysoka dla uprawnień bazy opisanych poniżej  
**Lokalizacje:**

- `backend/database/migrations/2026_11_06_000028_create_raw_material_revision_allergen_and_storage_data.php:162–239, 316–320`
- Porównawcze mechanizmy w migracjach 029 i 032

**Dowody i zachowanie:** migracja 028 przyznaje `veltra_app` `INSERT` dla atestów alergenowych, deklaracji i wariantów przechowywania. Triggery tych tabel egzekwują niezmienność i spójność rewizji/katalogu, lecz nie wymagają podpisanego biletu autoryzacji ani rejestracji zakresu mutacji. W przeciwieństwie do nich migracje 029 i 032 rejestrują zakresy mutacji i instalują triggery `veltra_private.enforce_authorization_mutation()`.

**Skutek:** zapis wykonany z użyciem roli runtime poza sprawdzoną ścieżką serwisu może dopisać dane do kwalifikującej się rewizji roboczej bez wymaganego uprawnienia biznesowego i bez odpowiadającego mu zdarzenia audytowego. Testy sprawdzają kontrole uprawnień i rollback audytu przez serwis, ale nie próbują zapisu do tych trzech tabel jako `veltra_app` bez biletu mutacji.

**Zakres ustalenia:** stwierdzono możliwość na poziomie uprawnień i triggerów bazy; nie wykazano SQL injection ani publicznej trasy umożliwiającej bezpośredni zapis. Recenzent zaleca objąć te tabele podpisaną granicą mutacji, kontrolując aktora i zakres uprawnienia oraz spójność audytu.

## Pozostałe obszary i wymagania

Poza dwoma ustaleniami powyżej recenzent przejrzał deklaracje i warianty, prywatne wersje dokumentów i SHA-256, bramkę ClamAV oraz obsługę błędów, decyzje i dezaktualizację ocen zgodności, izolację organizacji, RBAC, audyt, cykl życia oraz zgodność hashy v1/v2. Testy traktowano jako dowody wspierające, nie jako samodzielny dowód poprawności. W dostępnych materiałach nie wskazano dodatkowych konkretnych defektów.

Następujące elementy są warunkami produkcyjnymi, a nie ustaleniami o błędzie implementacji: `RAW_MATERIALS` pozostaje `NOT_READY`; nie zatwierdzono produkcyjnej zawartości słownika; prywatny storage wymaga decyzji o docelowym backendzie, retencji, kopii zapasowych i uzgadnianiu osieroconych obiektów; operacyjna konfiguracja ClamAV wymaga zatwierdzenia polityki aktualizacji i monitorowania świeżości sygnatur.

Nadal odroczone i niezaimplementowane są: jeden międzynarodowy katalog ze znacznikami źródła widocznymi wyłącznie w interfejsie zarządzania, listy alergenów krytycznych dla zakładów, dziedziczenie ich przez produkty, reguły braku duplikatów między „ALERGENY” i „MOŻE ZAWIERAĆ”, etykiety bez znaczników kraju/źródła oraz Stage 2E.

## Weryfikacja testów i ograniczenia

- Najnowszy raport Stage 2D.5 podaje **199 testów / 1624 asercje — PASS** jako dozwoloną regresję; w tym przeglądzie testów nie uruchamiano.
- Dokładnie 17 zastrzeżonych testów Stage 2C nie zostało uruchomionych ani rekonstruowanych. Ich brak nie jest zaliczeniem.
- Recenzent nie uruchamiał żadnego testu, migracji ani pełnej regresji; oceniał rzeczywisty kod, definicje bazodanowe, testy i dokumentację.
- Przegląd nie zamyka ani nie zmienia statusu Stage 2C. Akceptacja bezpieczeństwa pozostaje **OPEN / NOT VERIFIED**; VEL-DEF-007 pozostaje nierozstrzygnięte.
- `RAW_MATERIALS` i `VSEF` nie zostały aktywowane. Nie przeprowadzono wdrożenia.

## Zakres przejrzanych materiałów

- Zatwierdzony plan `VELTRA_STAGE_2D_IMPLEMENTATION_PLAN.md` oraz architektura `VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md`.
- Migracje `backend/database/migrations/2026_11_06_000028_create_raw_material_revision_allergen_and_storage_data.php` do `2026_11_06_000032_create_supplier_compatibility_assessments.php`.
- Serwisy rewizji, deklaracji, zgodności i uprawnień w `backend/app/Domain/RawMaterials/`; serwisy dokumentów/skanowania i zadanie skanowania w `backend/app/Domain/RawMaterials/Documents/` oraz `backend/app/Jobs/`.
- Kontrolery, requesty, trasy i komunikaty polskie dotyczące Stage 2D.
- Testy `RawMaterialRevisionDataModelTest.php`, `RawMaterialStage2dApiTest.php`, `RawMaterialDocumentStorageTest.php` i `RawMaterialCompatibilityAssessmentTest.php`.
- Raporty Stage 2D.1–2D.6 oraz kontrakt CSV dla przyszłego Stage 2E.

**Ograniczenia:** przegląd jest niezależny od autora implementacji, lecz nie jest zewnętrznym audytem. Nie obejmował uruchomienia testów, zastrzeżonych scenariuszy Stage 2C ani weryfikacji środowiska produkcyjnego. Lokalny worktree zawierał istniejące zmiany Stage 2D; recenzent odczytywał bieżący stan, nie zmieniając go.

**Werdykt końcowy: FAIL** — dwa konkretne ustalenia wymagają rozstrzygnięcia przed uznaniem, że dowody z przeglądu spełniają wszystkie wymagania zatwierdzonego planu. Raport nie zmienia automatycznie statusu zamknięcia Stage 2D ani statusu Stage 2C.
