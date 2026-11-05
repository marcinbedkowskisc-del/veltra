# VELTRA 2.0 — Raport Stage 2E.1: model danych CSV

**Data:** 5 listopada 2026 r.  
**Środowisko:** development; wyłącznie dedykowane, disposable PostgreSQL 17  
**Werdykt:** fundamenty persystencji Stage 2E.1 zaimplementowane i zweryfikowane

## Zakres i wynik

Dodano forward-only migrację 034 oraz usługę domenową zapisującą przygotowane, już znormalizowane wiersze importu. Implementacja używa istniejącego kodu składnika `SUR-…`, strumienia cen referencyjnych, idempotencji komercyjnej i audytu. Nie tworzy drugiego katalogu ani równoległego silnika cen.

### Dodane struktury i ochrona danych

Migracja `2026_11_06_000034_create_raw_material_csv_import_history.php` tworzy:

- `raw_material_import_batches` — niezmienny batch z organizacją, aktorem, SHA-256 źródła, idempotencją, statusem i licznością wierszy;
- `raw_material_import_rows` — append-only wynik weryfikacji każdego wiersza, zachowujący wartości źródłowe, wartości znormalizowane, datę biznesową, serwerowy czas weryfikacji i odnośniki do historii;
- `raw_material_import_metadata_events` — append-only bazę początkową VAT/jednostki i kolejne zmiany metadanych.

Tabele mają ograniczenia tenant-scoped i klucze obce do istniejących składników, batchy oraz zdarzeń cenowych. Trigger sprawdza spójność statusu/liczby wierszy batcha; niezmienność jest egzekwowana w bazie. Nowy podpisany zakres `raw-material.csv-import` jest związany z aktorem i organizacją. Zapis używa istniejącego uprawnienia `ReferencePriceManage`, wspólnych tabel idempotencji/audytu oraz jednej transakcji.

### Kontrakt persystencji

`RawMaterialCsvImportPersistenceService::recordPreparedBatch()`:

- dopasowuje wyłącznie kod `SUR-…` w organizacji aktora i wymaga aktywnego składnika oraz dokładnej nazwy bieżącej zatwierdzonej rewizji;
- zapisuje weryfikację `BEZ ZMIAN` bez tworzenia zdarzenia cenowego ani nowej wersji metadanych;
- zapisuje początkową, zaufaną bazę VAT/jednostki od podanej daty efektywnej; wcześniejszy brak historii nie jest uzupełniany syntetycznymi danymi;
- rozdziela biznesowe `effective_date` od nadawanego przez bazę czasu weryfikacji/zapisu;
- zapisuje zmianę VAT/jednostki bez sztucznego zdarzenia ceny; `PRICE_SET` tworzy tylko przy rzeczywistej zmianie znormalizowanej ceny;
- rozpoznaje konflikt stanu dla tej samej daty i odrzuca batch atomowo, nie zapisując historii ceny ani metadanych;
- zwraca poprzedni wynik dla powtórzonej pary aktor/klucz idempotencji/żądanie, a ponowne użycie klucza z innym żądaniem odrzuca.

## Weryfikacja

Weryfikację wykonano na odizolowanym runnerze PHP 8.4 z dedykowanym PostgreSQL 17.11. Testy używały `veltra_testing`; pełną sekwencję migracji sprawdzono na osobnej, pustej bazie disposable. Nie dotknięto oryginalnych baz danych, wolumenów ani kontenerów aplikacyjnych.

| Sprawdzenie | Wynik |
|---|---|
| Migracja 034 na testowej bazie | PASS; migracja zastosowana |
| Świeża sekwencja migracji 001–034 | PASS; 34/34 migracje wykonane |
| Ukierunkowane testy CSV | PASS; 3 testy, 42 asercje |
| Pełna dozwolona regresja | PASS; 204 testy, 1689 asercji |
| Pominięte testy w przebiegu regresji | 17 dokładnie; brak dodatkowych pominięć zgłoszonych przez PHPUnit |
| PHP lint | PASS; wszystkie 4 zmienione pliki PHP |
| Pint | PASS; wszystkie 4 zmienione pliki PHP |
| `git diff --check` | PASS |

Regresję uruchomiono z filtrem wykluczającym wszystkie 15 testów `RawMaterialPermissionBootstrapTest`, `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` oraz `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`. Żaden z tych 17 testów nie został wykonany.

W pierwszym przebiegu testów ukierunkowanych wykryto dwa problemy implementacyjne: odrzucony atomowo batch mógł zapisać odwołanie do niezapisanego zdarzenia ceny, a test bezpośredniego INSERT używał niezgodnej sygnatury callbacka. Oba problemy poprawiono. Końcowy przebieg ukierunkowany i pełna regresja przeszły pomyślnie.

Pierwsza próba świeżej bazy została zatrzymana przez kontrolę migracji 022, ponieważ baza testowa była błędnie utworzona z rolą migratora jako właścicielem. Bazę przygotowano ponownie z rozdzieleniem właściciela administracyjnego i roli migracyjnej oraz minimalnymi uprawnieniami schematu; pełne migracje 001–034 przeszły. Był to błąd konfiguracji disposable środowiska, nie potwierdzona wada aplikacji.

## Ograniczenia i dalszy zakres

- To fundament persystencji, nie kompletny przepływ CSV. Usługa przyjmuje już sparsowane i znormalizowane wiersze; nie wykonuje parsowania CSV, konwersji jednostek ani obliczania ceny za jednostkę bazową.
- Nie dodano uploadu, prywatnego przechowywania pliku, eksportu katalogu, podglądu/commit HTTP ani interfejsu frontendowego.
- Nie dodano integracji faktur, OCR, KSeF, księgowości, cen dostawców ani magazynu.
- Pełny przepływ Stage 2E.2 musi zapewnić prywatne przetwarzanie pliku, walidację, normalizację względem jednostek zatwierdzonej rewizji, jawne potwierdzenie, ponowną autoryzację i kontrolę gotowości.
- Brak aktywacji `RAW_MATERIALS` i `VSEF`; `RAW_MATERIALS` pozostaje `NOT_READY`, a `VSEF` nieaktywne. Nie deklaruje się gotowości produkcyjnej ani sukcesu operacji HTTP.
- Status Stage 2C pozostaje `OPEN / NOT VERIFIED`; VEL-DEF-007 pozostaje `NOT VERIFIED`; 17 ograniczonych testów pozostaje niewykonanych.

## Potwierdzone wady

Po poprawkach i końcowej regresji nie stwierdzono nierozwiązanych wad Stage 2E.1. Raport nie zamyka bramek bezpieczeństwa ani nie autoryzuje przejścia do Stage 2E.2.

**Commity i push nie były wykonywane.**
