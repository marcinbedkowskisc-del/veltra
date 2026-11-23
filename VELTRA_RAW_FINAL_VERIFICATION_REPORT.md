# VELTRA 2.0 — końcowa weryfikacja RAW

**Data:** 2026-11-23
**Branch / commit bazowy:** `main` / `32e3bff`
**Zakres:** tylko dwa brakujące testy regresyjne wskazane w zadaniu.
**Decyzja wdrożeniowa:** ADR-001 — jeden klient na odrębnej instancji aplikacji, serwerze i bazie.

## Wynik

| Kontrola | Wynik |
|---|---|
| Eksport CSV dla pustego wyniku | **PASS** |
| Powtórzone ID dostawcy w żądaniu RAW | **PASS — odrzucone przez walidację, bez zapisu** |
| Constraint unikalności przypisań w PostgreSQL | **PASS — duplikat odrzucony przez `23505`** |
| Wykryte defekty logiki aplikacji | **Brak** |
| Gotowość RAW do ręcznej weryfikacji na STAGING | **NIE — RAW_MATERIALS pozostaje NOT_READY** |

Nie zmieniono logiki aplikacji, kontraktu CSV, gotowości RAW/BOSS ani zabezpieczeń organizacyjnych. Nie wykonano scenariuszy między klientami ani między instalacjami.

## 1. Pusty eksport CSV

**PASS — test integracyjny API** dodany w [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php).

Test potwierdza w pojedynczej izolowanej instalacji, że:

- brak aktywnych, nieusuniętych materiałów skutkuje `201` dla utworzenia eksportu i `row_count: 0`;
- pobranie zwraca `200` oraz `text/csv; charset=UTF-8`;
- treść jest prawidłowym, jednoliniowym CSV z dokładnym istniejącym nagłówkiem `ingredient_id;ingredient_name`;
- parser CSV odczytuje wyłącznie wiersz nagłówka — nie ma rekordów ani niezamierzonych danych.

Nie zmieniono ani nie rozszerzono kontraktu.

## 2. Powtórzone przypisanie dostawcy

**PASS — test integracyjny API i constraintu bazy** dodany w [RawMaterialStage2dApiTest.php](./backend/tests/Feature/RawMaterialStage2dApiTest.php).

Test wysyła ten sam supplier ID dwukrotnie:

- API zwraca `422`;
- nie zostaje utworzony RAW ani rekord przypisania;
- po prawidłowym żądaniu pozostaje dokładnie jedno przypisanie;
- bezpośrednia próba wstawienia drugiego rekordu dla tej samej pary RAW–dostawca zostaje odrzucona przez PostgreSQL z SQLSTATE `23505`; liczba przypisań nadal wynosi jeden.

Test potwierdza działanie istniejącej walidacji `distinct` i constraintu `UNIQUE (organization_id, raw_material_id, supplier_id)`. Nie wprowadzono nowego workflow ani zmian reguł biznesowych.

## Uruchomienie i formatowanie

- Środowisko testowe: nowy projekt Compose `veltra-raw-final-verification`; jednorazowa baza PostgreSQL i Redis w `tmpfs`, sieć `internal`, bez opublikowanych portów.
- Migracje zastosowano wyłącznie w tej tymczasowej bazie. Istniejące bazy aplikacyjne nie były używane ani zmieniane.
- Końcowy przebieg: **2 testy, 21 asercji — PASS**.
- Laravel Pint `--test` dla obu zmienionych plików: **PASS**.
- `php -l` dla obu zmienionych plików: **PASS**.
- Wstępny przebieg z nieodświeżonym obrazem nie wykrył nowych testów; po przebudowaniu obrazu runnera testy wykonały się. Pierwsza wersja nowej asercji odwoływała się do nieistniejącej kolumny `raw_materials.name`; poprawiono sam test, bez zmian aplikacji, i ponowiono kontrolę z wynikiem PASS.
- Po weryfikacji usunięto kontenery, tymczasową bazę i sieć dedykowanego projektu.

## STAGING

**RAW nie jest obecnie gotowy do ręcznej weryfikacji na STAGING:** zgodnie z poprzednim raportem `RAW_MATERIALS` pozostaje `NOT_READY`. Dodane testy potwierdzają dwie brakujące ścieżki w izolowanym środowisku testowym, ale nie zmieniają readiness i nie stanowią zgody na wdrożenie.

## Zakres zmian

Dodano wyłącznie dwa testy regresyjne w istniejących plikach testowych oraz ten raport. Nie zmieniano kodu aplikacji, nie commitowano, nie wypychano ani nie wdrażano zmian.
