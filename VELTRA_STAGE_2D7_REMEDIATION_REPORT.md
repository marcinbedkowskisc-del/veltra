# VELTRA 2.0 — Raport remediacji Stage 2D.7

## Status

Naprawiono dwa ustalenia opisane w [niezależnym przeglądzie Stage 2D](./VELTRA_STAGE_2D_INDEPENDENT_REVIEW.md). Werdykt tamtego przeglądu pozostaje **FAIL**; ten raport nie zmienia werdyktu ani automatycznie nie zamyka Stage 2D.

## Ustalenie 1 — HIGH — brak atestu alergenowego przy przejściu v1→v2

**Przyczyna źródłowa:** zatwierdzanie sprawdzało `allergen_state = DECLARED`, lecz nie wymagało atestu przypiętego do zatwierdzanej rewizji v2. Rewizja wywiedziona z danych v1 mogła więc zachować `DECLARED` i zostać zatwierdzona jako v2 bez nowego, wiarygodnego atestu.

**Zmiany:** zatwierdzanie v2 wymaga teraz atestu dokładnie tej rewizji, z opublikowaną wersją słownika, aktorem należącym do tej samej organizacji, poprawnym statusem oraz spójnym zbiorem deklaracji. Przyszły znacznik czasu jest odrzucany, a hash rewizji nadal jest weryfikowany. `LEGACY_DECLARED` nie zastępuje atestu. Rewizje v1, ich hashe i dotychczasowe odczyty pozostają obsługiwane; atesty nie są dopisywane wstecz.

Przywrócenie zwrócone do zmian zachowuje poprzednią zatwierdzoną rewizję i wraca do stanu `ACTIVE`, gdy taki snapshot istnieje. Pozwala to przejść istniejącą ścieżką edycji i jawnie dodać atest przed ponownym zatwierdzeniem, bez naruszania historii.

**Pokrycie regresyjne:** test odtwarza historyczną rewizję v1 `DECLARED` bez atestu, tworzy z niej v2 i potwierdza odrzucenie zatwierdzenia; następnie dodaje jawny atest do dokładnej rewizji, zatwierdza ją i sprawdza zgodność hasha. Zaktualizowano też testy edycji i przywracania tak, by przed zatwierdzeniem nowej rewizji wykonywały wymagane atestowanie.

## Ustalenie 2 — MEDIUM — brak podpisanej granicy mutacji

**Przyczyna źródłowa:** tabele atestów, deklaracji i wariantów miały grant `INSERT` dla `veltra_app`, lecz brakowało triggerów wymuszających podpisany kontekst mutacji.

**Zmiany:** dodano forward-only migrację 033. Usuwa ona ogólny zakres `raw-material.mutate` dla trzech tabel i wymaga podpisanego kontekstu z osobnymi zakresami `raw-material.allergen.manage` lub `raw-material.storage.manage`. Trigger sprawdza zgodność zakresu, organizacji/subject i aktora z tej organizacji, a następnie istniejący mechanizm weryfikuje podpis. Usługa sprawdza odpowiednie uprawnienia biznesowe i zapisuje atesty/deklaracje oraz warianty przez `runForSubject`; automatyczne granty nie są dodawane.

**Pokrycie regresyjne:** bezpośrednie inserty przez rolę runtime do każdej z trzech tabel są odrzucane (`SQLSTATE 42501`); test odrzuca też podpisany kontekst z niezgodnym subject. Legalne operacje serwisowe z podpisanym kontekstem, atomowość audytu i izolacja organizacji są objęte regresją Stage 2D.

## Dowody weryfikacji

- **Izolacja:** testowy PostgreSQL 17.11 działał na `tmpfs`; sieć wewnętrzna zawierała wyłącznie usługi testowe. Runner łączył się z bazą `veltra_testing` jako `veltra_app`. Nie użyto kontenerów, baz ani wolumenów aplikacyjnych.
- **Świeża migracja:** na nowej, pustej bazie `veltra_stage2d7_fresh_final` wykonano pomyślnie wszystkie **33 migracje**, od 001 do 033. Zweryfikowano rejestrację trzech zakresów migracji 033.
- **Testy Stage 2D:** 14 testów / 104 asercje PASS dla modelu rewizji i API Stage 2D; 22 testy / 242 asercje PASS dla cyklu surowców.
- **Pełna dozwolona regresja:** **201 testów / 1647 asercji PASS**, 0 niepowodzeń i 0 pominiętych testów. Wyłączono dokładnie 17 ograniczonych testów Stage 2C: 15 z `RawMaterialPermissionBootstrapTest` oraz dwa wskazane wcześniej przypadki z `AuthorizationMutationBoundaryTest` i `RoleAdministrationTest`. Nie uruchamiano ich ani nie odtwarzano.
- **PHP lint:** 5 zmienionych plików — PASS.
- **Pint:** 5 zmienionych plików — PASS.
- **`git diff --check`:** PASS.

## Ograniczenia i pozostałe statusy

- Akceptacja bezpieczeństwa Stage 2C pozostaje **OPEN / NOT VERIFIED**. Dokładnie 17 ograniczonych testów nie wykonano, a **VEL-DEF-007** pozostaje nierozstrzygnięte.
- Migracja 033 została zweryfikowana wyłącznie na disposable PostgreSQL; nie została wdrożona do środowiska produkcyjnego.
- Nie uruchamiano Stage 2E, nie aktywowano `RAW_MATERIALS`/`VSEF`, nie wykonywano deploymentu ani commitów/pushy.
- Niezależny werdykt Stage 2D pozostaje bez zmian; wymagane jest osobne niezależne potwierdzenie remediacji.
