# VELTRA 2.0 — Stage 3E-B.4.1
## RAW_MATERIALS — implementacja nieodwracalnego tombstone’u szkicu

**Tryb:** implementacja backendu i weryfikacja wyłącznie w izolowanym środowisku testowym
**Data:** 2026-11-09
**Status:** implementacja lokalna; bez wdrożenia i aktywacji; `RAW_MATERIALS = NOT_READY`

## 1. Podsumowanie

Zrealizowano zatwierdzony wariant B z raportu B.4: zamiast fizycznego
usuwania kwalifikujący się szkic otrzymuje nieodwracalny tombstone. Master,
rewizje, audyt, trwałe przypisanie SKU i kod pozostają w bazie. Tombstone
znika ze zwykłych odczytów i przepływów biznesowych; fizyczny `DELETE` nadal
jest odrzucany.

Operacja wymaga bieżącej wersji B.2 (`If-Match`), uprawnienia
`RAW_MATERIALS_DELETE_DRAFT` i primary central kitchen. Dozwolony jest tylko
materiał w stanie `DRAFT`, bez historii zatwierdzenia lub zwrotu i bez
trwałych zależności. Operacja jest atomowa z wpisem audytu.

## 2. Zmiany

- Dodano forward-only migrację 045:
  `backend/database/migrations/2026_11_09_000045_add_raw_draft_tombstones.php`.
  Dodaje metadane tombstone’u, ograniczony indeks katalogu, niedelegowalną
  capability oraz bazowe funkcje i triggery integralności.
- Zaktualizowano `raw_materials_preserve_master()`: nadal blokuje fizyczny
  `DELETE`, a po tombstone blokuje każdą dalszą zmianę mastera. Sam tombstone
  wymaga podpisanego scope’u, poprawnego audytu w tej samej transakcji,
  kwalifikującego się lifecycle i braku zależności.
- Dodano endpoint `DELETE /api/v1/raw-materials/{material}/draft`, zwracający
  `204`. Sprawdzenie uprawnień, blokada organizacji, odczyt materiału, wersja,
  kwalifikacja, pojedynczy wpis `DRAFT_DELETED` i aktualizacja mastera odbywają
  się w jednej transakcji.
- Nowe uprawnienie jest wyłączone ze zwykłego delegowania w PHP i przez
  trigger PostgreSQL. Zachowano istniejący model ról, ochronę SUPER ADMIN,
  izolację organizacji/lokalizacji i wymóg primary central kitchen.
- Dodano filtr tombstone’ów w zwykłych odczytach oraz operacjach dokumentów,
  historii, udostępnień, importu, eksportu, handlu i ocen zgodności. Import
  katalogu rozpoznaje zachowany SKU tombstone’u jako konflikt, zamiast
  traktować go jako wolny do ponownego użycia.
- Zależności są sprawdzane zarówno przy próbie tombstone’u, jak i przy
  utworzeniu nowego powiązania. Triggery blokują organizację, a potem materiał,
  co serializuje konkurencyjne operacje.
- Zaktualizowano `backend/README.md` i dodano wyłącznie testowy plik
  `compose.stage3e-b41-test.yaml`.

### Zależności blokujące

Funkcja PostgreSQL `raw_materials_draft_has_dependencies()` odrzuca szkice
mające: dowolne zdarzenie zatwierdzenia/zwrotu rewizji, produkt dostawcy,
zdarzenie ceny referencyjnej, restatement finansowy, wiersz lub zdarzenie
importu (cen/metadata albo importu katalogu), istniejące udostępnienie
lokalizacji (także cofnięte), ocenę zgodności, zdarzenie audytu komercyjnego
RAW albo trwałe powiązanie/set dokumentów. Pusty set dokumentów i nieprzypięty
upload stagingowy nie są zależnością.

Rezerwacje i assignmenty SKU nie są usuwane ani zwalniane. Przypisanie
pozostaje związane z tombstonowanym identyfikatorem. Rewizje i ich dzieci
pozostają append-only/immutable; nie zmieniono ich guardów ani FK `RESTRICT`.

## 3. Weryfikacja

Wszystkie operacje bazodanowe poniżej dotyczyły wyłącznie nowego projektu
Compose `veltra-stage3e-b41-test`: PostgreSQL 17 bez opublikowanego portu,
tymczasowy katalog danych, wewnętrzna sieć i odrębne wolumeny. Migracje
wykonano jako nieuprzywilejowany `veltra_migrator`, a testy jako ograniczony
`veltra_app`.

| Sprawdzenie | Wynik |
|---|---|
| `docker compose ... config --quiet` dla B.4.1 | PASS |
| Migracje 001–045 od zera na PostgreSQL 17 | PASS |
| Testy B.4.1 (`--filter=stage3e_b41`) | 12 testów, 92 asercje — PASS |
| `RawMaterialsTest.php` | 152 testy, 1 773 asercje — PASS |
| Testy skanera/dokumentów i zgodności dostawcy | 18 testów, 151 asercji — PASS |
| Pełny backend PHPUnit | 371 testów, 3 692 asercje — PASS |
| Pint dla całego backendu (288 plików) | PASS |
| `php -l` dla PHP w `app`, `tests`, `database`, `routes`, `lang` | PASS |
| `git diff --check` | PASS |

Testy B.4.1 sprawdzają kwalifikujący się tombstone i zachowanie historii/SKU,
wersję bieżącą i konflikty, stany zwrócony/weryfikacja/zatwierdzony, dokumenty
i upload stagingowy, zależność handlową, zakaz tworzenia powiązania po
tombstone, zakaz fizycznego DELETE, niedelegowalność uprawnienia, wyścig dwóch
żądań, wyścig z rewizją i utworzeniem zależności oraz rollback audytu po
wymuszonej awarii aktualizacji mastera.

## 4. Zachowane granice i ograniczenia

- Zdarzenie `DRAFT_DELETED` pozostaje append-only w audycie, ale usunięty
  materiał nie jest dostępny przez zwykły endpoint historii (`404`); nie
  dodano osobnego interfejsu do przeglądania audytu tombstone’ów.
- Testy bezpośrednio obejmują kluczowe ścieżki zależności (historię
  zatwierdzeń, dokumenty i audyt komercyjny); nie są osobnym testem
  integracyjnym dla każdego rodzaju importu, udostępnienia i oceny zgodności.
  Ich relacje oraz guardy są uwzględnione w funkcji i triggerach migracji 045.
- Nie dodano frontendu, nie zmieniono archiwizacji ani formalnego wycofania
  RAW i nie zmieniono bramki gotowości modułu.
- Nie wykonywano ponownego odczytu dokumentów Google Drive. Wykorzystano
  zatwierdzoną specyfikację dostępną w kontekście implementacji oraz lokalny
  raport B.4; wcześniejszy raport B.4 odnotowuje ograniczenia zdalnej
  weryfikacji źródeł.

## 5. Środowiska i stan wydania

Nie uruchamiano migracji ani testów na bazie instalacyjnej. Nie wdrażano,
nie aktywowano modułu, nie commitowano ani nie pushowano zmian. Migracja 045
jest forward-only i nie została zastosowana poza odrębną testową bazą.
Po weryfikacji usunięto kontenery, sieć i wolumeny projektu B.4.1; pozostałe
kontenery instalacyjne nie zostały zmienione.
`RAW_MATERIALS` pozostaje `NOT_READY`; przed wydaniem wymagane są dalsze
decyzje i procesy poza zakresem tego etapu.
