# VELTRA 2.0 — raport implementacyjny PA-05

Data: 2026-11-17
Status techniczny: **implementacja lokalna; weryfikacja zmian PA-05 PASS**. Nie wdrażano.

## 1. Podsumowanie

Dodano wersjonowane słowniki alergenów dla wielu jurysdykcji oraz wspólną pulę kanoniczną. Dane zatwierdzonego MASTER v1.1 są importowane do trwałych wersji słowników i mapowań źródłowych. Panel administracyjny pozwala przeglądać te dane, wyszukiwać je, zarządzać mapowaniami, edytować aktywne definicje kanoniczne i archiwizować wpisy. Deklaracje alergenów RAW korzystają z opublikowanych wersji puli, zachowując istniejące powiązania z historycznymi katalogami.

Nie wywnioskowano wyjątków prawnych ani zakresu obowiązku z hierarchii alergenów. Ziarno MASTER ma puste listy `exceptions`; treść źródłowa wskazuje, że dane są strukturalnie kompletne, ale niecertyfikowane prawnie. Automatyczne etykiety prawne wymagają odrębnego przeglądu.

## 2. Zakres i model danych

| Obszar | Realizacja |
|---|---|
| Wersjonowane słowniki źródłowe | 9 wersji z 8 jurysdykcji, wraz ze statusem, datami, organem, URL-em i metadanymi. |
| Pula kanoniczna | 54 definicje z hierarchią, polskimi nazwami i synonimami oraz oryginalnymi nazwami źródłowymi. |
| Mapowania | 178 relacji między wersjami słowników i alergenami kanonicznymi; status, reguła wykazu, zakres, progi, wyjątki i notatki pozostają jawnie przypisane do źródła. |
| Historia i audyt | Ręczne mutacje są audytowane; wersje słowników i opublikowane snapshoty RAW są chronione przed nadpisaniem lub usunięciem. |
| Zgodność historyczna RAW | Zmiana puli publikuje nowy snapshot katalogu. Istniejące deklaracje zachowują przypiętą wersję i nie są przepisywane. |
| Ręczne definicje | Nowe definicje i edycje zapisują autora oraz czas ręcznej zmiany; seedowane dane ich nie otrzymują. Duplikaty nazw i relacji źródłowych są odrzucane. |

Implementację bazy zawiera [migracja 052](backend/database/migrations/2026_11_17_000052_create_international_allergen_dictionaries.php), a zatwierdzone dane — [MASTER v1.1](backend/database/data/allergen_dictionaries_master_v1_1.json) i [AllergenDictionariesSeeder](backend/database/seeders/AllergenDictionariesSeeder.php). Migracja jest forward-only; wycofanie wymagałoby osobnej, przetestowanej migracji.

## 3. Backend, API i RAW

[InternationalAllergenDictionaryService](backend/app/Domain/Allergens/InternationalAllergenDictionaryService.php) obsługuje odczyt, walidowane mutacje, kontrolę administratora, audyt, ochronę przed duplikatami i publikację nowych snapshotów. Mutacje używają istniejącego mechanizmu signed authorization mutation tickets i zakresu `administration.allergen.manage`; nie dodano nowych ról.

Trasy administracyjne są objęte istniejącymi middleware sesji, kontekstu operacyjnego i administratora:

- `GET /api/v1/administration/allergens/dictionaries`
- `GET /api/v1/administration/allergens/canonical`
- `GET /api/v1/administration/allergens/dictionaries/{version}/entries`
- `GET /api/v1/administration/allergens/dictionaries/{version}/history`
- `POST /api/v1/administration/allergens/dictionaries/{version}/entries`
- `PATCH /api/v1/administration/allergens/entries/{entry}`
- `POST /api/v1/administration/allergens/entries/{entry}/archive`
- `PATCH /api/v1/administration/allergens/dictionaries/{version}/canonical/{allergen}`
- `POST /api/v1/administration/allergens/dictionaries/{version}/canonical/{allergen}/archive`

W [RawMaterialService](backend/app/Domain/RawMaterials/RawMaterialService.php) nowy snapshot puli jest integrowany z istniejącym kontraktem RAW. Formularze deklaracji obsługują wiele alergenów oraz osobne rodzaje „ZAWIERA” i „MOŻE ZAWIERAĆ”; nowa deklaracja wybiera bieżący katalog, a edytowana historyczna deklaracja zachowuje swoją wersję. Wyszukiwarka pokazuje również źródła powiązania.

## 4. Panel administracyjny

[AllergenDictionaryManagementPage](frontend/src/administration/AllergenDictionaryManagementPage.tsx) jest podłączona do istniejącej nawigacji panelu administracyjnego. Widok udostępnia wybór słownika i wersji, filtrowanie i wyszukiwanie wpisów, podgląd historii, edycję i archiwizację mapowań oraz edycję i archiwizację definicji kanonicznych. Walidacja odpowiedzi API odrzuca błędne kształty danych; puste obiekty JSON są zachowywane jako obiekty.

## 5. Weryfikacja

Backend działał przez izolowany wrapper `scripts/compose-test` i Docker context `veltra-test-isolated`; nie użyto Stage ani produkcji. Migrację testowano przez usługę `migrate-test`.

| Zakres | Wynik |
|---|---|
| `scripts/compose-test run --build --rm migrate-test` | **PASS**; migracja i seeding w izolowanej bazie testowej. |
| `php artisan test tests/Feature/AllergenDictionaryManagementTest.php tests/Feature/AdministrationAccessTest.php --no-coverage` w `backend-test` | **8 testów PASS, 146 asercji**; kompletność i idempotencja seeda, przyszły status źródła, puste obiekty JSON, autoryzacja, deduplikacja, audyt oraz nowe i historyczne snapshoty. |
| Pełny backend suite w `backend-test` | **461 testów PASS, 8 FAIL (4 498 asercji)**. Alergeny przechodzą. Niepowodzenia dotyczą `SystemPermissionManifestRegistryTest` oraz `RawMaterialCompatibilityAssessmentTest`; log wykazuje niedostępność ClamAV i zależne błędy wyników skanowania/oceny. Nie ustalono baseline tych błędów, więc nie przypisuje się ich tej zmianie ani nie określa jako potwierdzonych błędów bazowych. |
| `cd frontend && npm test` | **PASS: 18 plików, 210 testów**. |
| `cd frontend && npm run build` | **PASS**: `tsc -b` i build Vite. |
| `cd frontend && npm run lint` | Kod wyjścia 0; pozostało istniejące ostrzeżenie `react(only-export-components)` w `src/visualPreview.tsx:39`, poza zakresem PA-05. |
| `scripts/compose-test run --rm backend-test vendor/bin/pint --test` dla 12 zmienionych plików PHP | **PASS**. |
| `git diff --check` | **PASS**. |

Podczas końcowej weryfikacji test wykrył brak destrukturyzacji `invalid` w wierszach deklaracji alergenów. Prop został poprawnie podłączony, a komplet frontendowych testów i build wykonano ponownie z wynikiem PASS.

## 6. Ograniczenia i gotowość

1. MASTER v1.1 nie stanowi certyfikacji prawnej; danych nie należy traktować jako podstawy do automatycznego komponowania etykiet prawnych bez wymaganej weryfikacji.
2. Nie dodano domniemanych wyjątków wynikających z przynależności do grup. Wpisy ręczne mogą zawierać wyjątki wyłącznie wtedy, gdy administrator wprowadzi je jawnie.
3. Pełny backend suite nie jest zielony z powodu 8 opisanych wyżej niepowodzeń; ich baseline pozostaje niezweryfikowany. Testy PA-05 i administracyjnej autoryzacji zakończyły się powodzeniem.
4. Wykonano wyłącznie prace i testy lokalne w izolowanym środowisku testowym. Nie zmieniano Stage/produkcji i nie wykonano pushu.

Implementacja jest gotowa do lokalnego przeglądu/odbioru z uwzględnieniem ograniczeń i wyników pełnego suite opisanych powyżej.

## 7. Git

Lokalny commit implementacji: `7826df2fe3bdb7fccd7648592253601aa8d0be73` (`feat(admin): add international allergen dictionaries`). Raport jest zapisany oddzielnie. Nie wykonano pushu.
