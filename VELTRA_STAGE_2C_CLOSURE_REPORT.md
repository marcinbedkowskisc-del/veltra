# VELTRA 2.0 — formalne zamknięcie Stage 2C

## Werdykt

**CONDITIONAL ACCEPT — Stage 2C nie jest w pełni zamknięta.**

Indywidualne poprawki dotyczące ujawnienia kosztów, lifecycle, retry NBP i powtarzalności migracji otrzymały akceptację w raportach o ograniczonym zakresie. Nie stanowi to akceptacji całej Stage 2C. W tej rundzie niezależnie odtworzono wcześniejsze ustalenie LOW dotyczące utraty precyzji obliczeń HALF-EVEN dla dużej kwoty. Pozostaje też nierozstrzygnięta, wspierana ścieżka przyznania nowych permissionów w już zainicjalizowanej organizacji.

`RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Nie zmieniano gotowości modułu. Nie utworzono szkicu architektury Stage 2D, ponieważ warunek pełnego `ACCEPT` nie został spełniony.

## Zakres konsolidacji

Przejrzano architekturę i jej niezależny przegląd, raport implementacji, pierwotny audyt bezpieczeństwa, re-audyt, raporty dwóch remediacji, audyt remediacji i końcowy audyt bezpieczeństwa. Oceniono osobno wyniki przypisane do konkretnych napraw oraz kompletność dowodów dla całej Stage 2C. Raporty cząstkowe nie są traktowane jako dowód testów niewykonanych w ich zakresie.

## Status wcześniejszych ustaleń

| Ustalenie | Status na podstawie raportów | Znaczenie dla zamknięcia |
|---|---|---|
| Dwa pierwotne ustalenia MEDIUM: ujawnienie danych kosztowych oraz pominięcie lifecycle w operacjach komercyjnych | Naprawione i niezależnie sprawdzone w API/PostgreSQL w końcowym audycie bezpieczeństwa. | Nie pozostają otwartymi ustaleniami MEDIUM. |
| LOW: dodanie rewizji produktu po archiwizacji produktu lub dostawcy | Naprawione w serwisie i triggerze PostgreSQL; niezależny audyt potwierdził testy pozytywne i negatywne API/SQL. | Poprawka zaakceptowana; nie jest to akceptacja całej Stage 2C. |
| LOW: retry NBP, idempotencja i konflikt zmienionych parametrów | Audyt remediacji potwierdził replay bez kontaktu z upstream i konflikt HTTP 409 dla zmienionych parametrów. | Poprawka zaakceptowana w ograniczonym zakresie. |
| LOW: powtarzane migracje PostgreSQL i `SQLSTATE 42723` | Audyt remediacji potwierdził dwukrotne `migrate:fresh` po zmianie deklaracji funkcji oraz upgrade istniejącego schematu 001–013 wyłącznie migracją 014. | Naprawa powtarzalności potwierdzona. Migracja 014 pozostaje forward-only i wymaga planu operacyjnego; `migrate:fresh` nadal nie wolno uruchamiać na zachowywanych danych. |
| LOW: precyzja HALF-EVEN dla dużej kwoty | Nie została objęta ostatnim audytem remediacji; raport naprawy stwierdził jedynie, że nie odtworzono jej ponownie. W bieżącej rundzie odtworzono ją bezpośrednio na PostgreSQL. | **Otwarte; blokuje pełne zamknięcie.** |
| Ścieżka dodania nowych permissionów w już zainicjalizowanej organizacji | Pierwotny audyt nie znalazł wspieranej ścieżki addytywnego bootstrapu. Późniejsze raporty nie przedstawiają dowodu rozwiązania tego problemu. Bootstrap początkowy odmawia, gdy organizacja ma już granty; zwykła delegacja wymaga, aby delegujący już miał dane permission. | **Otwarte jako bramka wdrożeniowa dla istniejących instalacji Stage 2B.** Nie wolno obchodzić przez automatyczny grant ani bezpośredni zapis SQL. |
| Dynamiczny test dwóch organizacji/tenantów w jednej instalacji | Nie wykonano; wymuszony constraint jednej organizacji na instalację jest zgodny z wiążącym ADR-001. | Nie jest podstawą do osłabienia constraintu. Testy izolacji klientów powinny dotyczyć oddzielnych wdrożeń; zakresy organizacji/lokalizacji nadal podlegają kontroli w każdej instancji. |

## Niezależny test precyzji wykonany w tej rundzie

Wykonano wyłącznie odczyt SQL na kontenerze `veltra-stage2c-remediate-pg`, z bazą `veltra_testing` i użytkownikiem `veltra_test`. Przed zapytaniem potwierdzono ustawienia połączenia runnera oraz przez `docker inspect`, że runner, PostgreSQL i Redis nie mają mountów i są połączeni tylko z siecią `veltra-stage2c-remediate`. Nie wykonano operacji zapisujących ani destrukcyjnych.

Zapytanie:

```sql
SELECT
    raw_materials_round_half_even_v1('50000000.12345678901234'::numeric, 14)::text,
    raw_materials_round_half_even_v1('0.000000000000005'::numeric, 14)::text,
    raw_materials_round_half_even_v1('0.000000000000015'::numeric, 14)::text;
```

Wynik rzeczywisty:

```text
50000000.12345678901200 | 0.00000000000000 | 0.00000000000002
```

Dwa małe przypadki zgodne są z HALF-EVEN przy skali 14, ale duża kwota nie zachowuje wejściowych cyfr `...12345678901234`; wynik kończy się `...12345678901200`. To potwierdza historyczne ustalenie LOW i dowodzi, że testy samych remisów dodatnich/ujemnych nie wystarczają do potwierdzenia deklarowanej precyzji. Przyczyny implementacyjnej nie rozstrzygnięto w tym audycie. Wymagane są poprawka oraz test regresyjny na wartościach dużej części całkowitej, testach granicznych i bezpośredniej ścieżce PostgreSQL; następnie niezależna weryfikacja.

### Testy a raporty wcześniejsze

Podczas tej rundy wykonano jedno niezależne zapytanie SQL reprodukujące precyzję; nie uruchamiano zestawu PHPUnit, pełnej regresji backendu ani migracji.

Odrębnie, raport końcowego audytu remediacji wcześniej wykazał **25 testów / 284 asercje PASS**, **170 testów / 1399 asercji PASS**, powtarzane odtworzenie 14 migracji oraz test upgrade’u 001–013 stosujący wyłącznie migrację 014. Są to wyniki wcześniejszego audytu, a nie testy wykonane w tej rundzie. Wcześniejszy końcowy audyt bezpieczeństwa raportował także `CONDITIONAL ACCEPT`, ograniczenia dowodu wielotenantowego i forward-only charakter migracji 014.

## ADR-001 — wiążący model wdrożenia

Lokalną dokumentację uzupełniono o [ADR-001 — Model wdrożenia klienta](./VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md), zgodnie z zatwierdzonym dokumentem źródłowym. Reguła: **jeden klient = jeden serwer = jedna instancja aplikacji = odrębne dane klienta**. Każdy klient ma osobną bazę PostgreSQL, Redis, pliki, konfigurację i kopie zapasowe. Izolacja infrastrukturalna nie zastępuje istniejącej autoryzacji organizacji i lokalizacji.

## Etykieta etapu w aplikacji

Zamiast zahardkodowanej etykiety `STAGE 1A / FUNDAMENT TECHNICZNY` interfejs korzysta teraz z centralnej wartości `developmentStageLabel` w `frontend/src/config/developmentStage.ts`. Wartość `ETAP 2C / WERYFIKACJA KOŃCOWA` wskazuje bieżący zatwierdzony etap prac; nie deklaruje gotowości wdrożeniowej i nie jest automatycznie wyliczana z commitów ani plików. Nie zmienia statusu `RAW_MATERIALS`.

## Warunki zamknięcia

1. Usunąć błąd precyzji HALF-EVEN dla dużych kwot i dodać testy obejmujące tę reprodukcję oraz graniczne wartości, w tym PostgreSQL.
2. Zdefiniować i zweryfikować bezpieczną, jawną, audytowaną ścieżkę nadania nowych permissionów istniejącej organizacji Stage 2B; bez automatycznych grantów i bez SQL omijającego reguły aplikacji.
3. Przeprowadzić niezależny audyt po tych zmianach oraz ponownie ocenić wszystkie otwarte ograniczenia.
4. Utrzymać `RAW_MATERIALS` jako `NOT_READY` i nieaktywne do czasu odrębnej decyzji.

Do spełnienia warunków Stage 2C nie uzyskuje pełnego `ACCEPT`; **Stage 2D pozostaje wstrzymana**. Niniejszy raport nie autoryzuje wdrożenia, migracji produkcyjnej ani aktywacji modułu.
