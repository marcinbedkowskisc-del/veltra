# VELTRA 2.0 — raport końcowej poprawki bezpieczeństwa Stage 2C

## Podsumowanie

Usunięto ustalenie LOW dotyczące dopisywania rewizji produktu dostawcy po archiwizacji produktu lub dostawcy. Blokada działa w ścieżce API oraz w PostgreSQL, również przy bezpośrednim `INSERT`. Historia istniejących rewizji pozostaje niezmienna.

## Przyczyna i zakres poprawki

`reviseProduct()` sprawdzał, czy produkt jest aktywny, ale nie weryfikował stanu dostawcy powiązanego z produktem. Z kolei trigger `supplier_product_revisions_validate` sprawdzał zatwierdzoną rewizję surowca i jednostkę, lecz nie stan lifecycle produktu ani dostawcy. Bezpośredni zapis SQL mógł więc ominąć kontrolę warstwy aplikacji.

Zmiany:

- [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php) — w transakcyjnej ścieżce `reviseProduct()` sprawdzany jest aktywny dostawca należący do organizacji produktu. Brak lub nieaktywny dostawca skutkuje odpowiedzią not-found, bez ujawniania stanu zasobu.
- [2026_11_03_000014_enforce_active_supplier_product_revisions.php](./backend/database/migrations/2026_11_03_000014_enforce_active_supplier_product_revisions.php) — nowa, addytywna migracja zastępuje funkcję PostgreSQL wywoływaną przez istniejący trigger. Funkcja wymaga jednocześnie aktywnego produktu i aktywnego dostawcy; w przeciwnym razie odrzuca zapis SQLSTATE `23514`. Zachowuje dotychczasowe walidacje surowca, jednostki, snapshotów oraz hasha.
- [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php) — regresja obejmuje pozytywną rewizję aktywnego produktu i negatywne próby po archiwizacji dostawcy lub produktu, przez API i bezpośredni SQL.

Nowa migracja jest konieczna, ponieważ edycja już wykonanej migracji `000013` nie aktualizowałaby istniejących instalacji. Migracja wsteczna celowo odmawia wycofania wymogu lifecycle, aby rollback nie przywrócił podatnego zachowania.

## Dowody weryfikacji

Weryfikację wykonano na odizolowanych zasobach:

- PostgreSQL 17 i Redis 7.4 działały w osobnej sieci Docker `internal`.
- Kontenery testowe używały wyłącznie `tmpfs`; nie miały mountów ani opublikowanych portów. Runner backendu również nie miał mountów.
- Istniejące usługi aplikacji pozostały w innej sieci Docker; ich trwałe wolumeny PostgreSQL i Redis nie były podłączone do testów.
- Nowa, pusta instancja PostgreSQL poprawnie wykonała wszystkie 14 migracji, od `000001` do `000014`.

Wyniki końcowej sekwencji testowej:

| Weryfikacja | Wynik |
|---|---:|
| Regresja lifecycle API i bezpośredniego SQL | 1 test, 13 asercji — PASS |
| `RawMaterialsTest.php` | 18 testów, 197 asercji — PASS |
| Pełny backend | 170 testów, 1392 asercje — PASS |
| Migracje na czystym PostgreSQL 17 | 14/14 — PASS |
| PHP syntax — zmienione pliki PHP | PASS |
| Pint — zmienione pliki PHP | 3 pliki — PASS |
| `git diff --check` | PASS |

Test potwierdził, że:

1. Aktywna para dostawca–produkt może otrzymać kolejną rewizję; liczba rewizji zwiększa się zgodnie z oczekiwaniem.
2. Po archiwizacji dostawcy API odrzuca żądanie, nie dodaje rewizji, a bezpośredni `INSERT` jest odrzucany przez PostgreSQL.
3. Po archiwizacji produktu API odrzuca żądanie, nie dodaje rewizji, a bezpośredni `INSERT` jest odrzucany przez PostgreSQL.

## Ograniczenia i ryzyka

- Nie wykonywano migracji ani zapisów na istniejącej bazie aplikacji. Nowa migracja wymaga zwykłego zastosowania w odrębnym, zatwierdzonym procesie wdrożeniowym.
- Podczas ponownego użycia jednorazowej bazy `migrate:fresh` usunął tabele, ale pozostawił funkcję PostgreSQL `serialize_installation_mutation`; kolejne uruchomienie migracji zakończyło się `SQLSTATE 42723` (duplikat funkcji). Nie zmieniano tego niezwiązanego problemu. Poprawną sekwencję od `000001` do `000014` zweryfikowano na nowym, czystym kontenerze.
- Testy lifecycle nie zastępują niezależnego audytu bezpieczeństwa po poprawce.
- `RAW_MATERIALS` pozostaje bez zmian w istniejącym środowisku; aktywację i zmianę statusu modułu wykluczono z zakresu.

## Wniosek

Ustalenie LOW jest naprawione i potwierdzone w testach API oraz PostgreSQL. **Rekomendacja dla tej poprawki: CONDITIONAL ACCEPT** — akceptacja Stage 2C nadal wymaga niezależnego ponownego audytu. Nie wykonano commitów, pushy, wdrożenia ani migracji na istniejącej bazie aplikacji.
