# VELTRA 2.0 — raport napraw po końcowym audycie Stage 2C

**Zakres:** dwie kwestie LOW wskazane w końcowym niezależnym audycie: retry synchronizacji NBP przy niedostępności upstream oraz ponowne migracje PostgreSQL po `migrate:fresh`.

**Status:** poprawki zaimplementowane i przetestowane; wymagany jest niezależny przegląd ponaprawczy. Ten raport nie zmienia historycznego werdyktu audytu ani sam nie stanowi akceptacji Stage 2C.

## 1. Idempotencja synchronizacji NBP przy retry

### Przyczyna

`syncNbpRate()` pobierał dane z NBP przed sprawdzeniem, czy klucz idempotencji ma już zapisaną odpowiedź. Ponowienie wcześniej zakończonej operacji podczas awarii NBP mogło więc zwrócić błąd upstream zamiast odtworzyć wynik.

### Poprawka

W [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php) przed żądaniem HTTP wykonywany jest chroniony transakcyjnie odczyt istniejącego wyniku idempotencji. Sprawdzenie blokuje wiersz organizacji, weryfikuje uprawnienie ponownie i porównuje stabilny zamiar żądania (`currency_code`, `operation_date`). Zgodny retry zwraca poprzednią odpowiedź bez kontaktu z NBP; inny zamiar dla tego samego klucza nadal kończy się konfliktem 409.

Po braku wcześniejszego wyniku pobranie kursu odbywa się poza transakcją. Właściwa mutacja ponownie sprawdza uprawnienie i idempotencję w transakcji, co obsługuje wyścig równoległych żądań. Odpowiedź istniejących rekordów ze starszym hashem obejmującym `source_hashes` jest odtwarzana po sprawdzeniu waluty i daty w zapisanej odpowiedzi.

Regresja w [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php) sprawdza:

- odtworzenie nowego wyniku po zmianie symulowanego NBP na HTTP 503 bez wysłania nowego żądania;
- odtworzenie historycznego wpisu idempotencji w starszym formacie podczas awarii NBP;
- konflikt 409 dla ponownego użycia klucza z inną walutą, również bez żądania do upstream.

## 2. Powtarzalność migracji PostgreSQL

### Przyczyna

Laravel `migrate:fresh` usuwa tabele i zależne triggery, lecz nie uruchamia metod `down()` wcześniejszych migracji. Funkcje PL/pgSQL pozostawały w schemacie, a kolejny `CREATE FUNCTION` o tej samej nazwie i sygnaturze kończył się `SQLSTATE 42723`.

### Poprawka

W 11 migracjach, które tworzą funkcje PL/pgSQL, wszystkie 43 deklaracje `CREATE FUNCTION` zmieniono na `CREATE OR REPLACE FUNCTION`. Dzięki temu ponowne tworzenie schematu zastępuje pozostawione definicje funkcji, a czysta instalacja zachowuje ich dotychczasowe treści. Nie zmieniano logiki triggerów ani ograniczeń danych.

Weryfikacja na PostgreSQL 17 wyłącznie w jednorazowym kontenerze:

1. Zastosowano wszystkie 14 migracji.
2. Wykonano `migrate:fresh --force` — wszystkie migracje zakończyły się powodzeniem.
3. Wykonano drugi kolejny `migrate:fresh --force` — wszystkie migracje ponownie zakończyły się powodzeniem.
4. Sprawdzono rejestr migracji: 14/14, ostatnia migracja `2026_11_03_000014_enforce_active_supplier_product_revisions`.

Zmiana nie oznacza, że `migrate:fresh` jest bezpieczne dla danych aplikacji: polecenie nadal usuwa tabele i dane. Nie uruchamiano go na istniejącej bazie aplikacji.

## 3. Wyniki weryfikacji po poprawkach

Wszystkie testy wykonano na odizolowanych, jednorazowych zasobach PostgreSQL 17 i Redis 7.4, w sieci Docker `internal=true`, bez portów hosta ani trwałych mountów. Runner również nie miał mountów. Wolumenów ani kontenerów aplikacyjnych nie użyto do testów destrukcyjnych.

| Weryfikacja | Wynik |
|---|---:|
| Skupione testy Stage 2B/2C (`RawMaterialsTest`, `RawMaterialPermissionBootstrapTest`, `RawMaterialConcurrencyTest`) | **25 testów, 284 asercje — PASS** |
| Pełny backend | **170 testów, 1399 asercji — PASS** |
| Pełne migracje PostgreSQL 17 | **14/14 — PASS** |
| Dwa kolejne `migrate:fresh` | **2/2 — PASS** |
| PHP syntax — zmienione i powiązane pliki PHP | **PASS** |
| Pint — 16 plików PHP | **PASS** |
| `git diff --check` | **PASS** |

## 4. Zakres zmian i pozostałe ograniczenia

- Zmieniono preflight idempotencji NBP, test regresyjny oraz deklaracje funkcji PostgreSQL w 11 istniejących migracjach.
- Nie zmieniano zatwierdzonego modelu jednej organizacji na instalację; dynamiczna weryfikacja izolacji dwóch tenantów nadal nie jest możliwa w tej architekturze.
- Migracja 000014 pozostaje celowo forward-only. Wdrożenie wymaga planu rollbacku opartego na migracji naprzód lub odtworzeniu kopii.
- Dawna obserwacja o precyzji HALF-EVEN dla dużej kwoty nie została ponownie odtworzona jako podatność i nie była przedmiotem tych poprawek.
- `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Nie wykonano migracji w istniejącej bazie, deploymentu, commitów ani pushy.
- Niezależny audyt ponaprawczy pozostaje wymagany przed końcową akceptacją Stage 2C.
