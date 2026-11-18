# VELTRA 2.0 — raport implementacyjny PA-05

Data: 2026-11-18
Status techniczny: **weryfikacja wizualna i regresyjna PA-05 PASS; gotowe do odbioru właściciela**. Nie wdrażano.

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

## 4. Porównanie z zatwierdzonym UX PA

Wcześniejszy widok [AllergenDictionaryManagementPage](frontend/src/administration/AllergenDictionaryManagementPage.tsx) pokazywał jeden wybrany słownik naraz, co nie spełniało wymogu porównania źródeł z F27/F29 w [PA Functional UX Spec v1](https://drive.google.com/file/d/1uX7tTwoknjoPowCs5-TmbozcTKzPkSgo/view). Widok skorygowano bez zmiany pozostałych części aplikacji:

- 8 źródeł/jurysdykcji renderuje się równocześnie; Chiny mają dwie wybieralne wersje słownika, bieżącą i przyszłą.
- Siatka ma 4 kolumny przy 1920 px, 3 przy 1440 px, 2 na pośredniej szerokości i 1 na mobile. Potwierdzono brak poziomego overflow przy 390 px.
- Wpisy są pionowo ułożone w obrębie źródła. Każda kolumna pokazuje nazwę źródła i jurysdykcję, kod i status wersji, daty wejścia w życie/weryfikacji oraz własne wyszukiwanie, filtr statusu i akcję `+ Dodaj alergen`.
- Karta rozdziela etykietami **Alergen kanoniczny** i **Wpis źródłowy** definicję wspólną od relacji specyficznej dla danego słownika.
- Zachowano pojedynczą globalną nawigację VELTRA wraz z istniejącą nawigacją panelu administracyjnego.

Do przeglądu uruchomiono istniejący izolowany serwer Vite pod `http://localhost:5175/allergen-preview.html`. Pokazuje rzeczywiste komponenty React (`AdministrationPanel`, `AllergenDictionaryManagementPage`, `ApplicationShell`, `RawMaterialWorkflow`) z oznaczonymi, syntetycznymi odpowiedziami API opartymi na wybranych przykładach i strukturze MASTER v1.1. Nie używa Stage/produkcji ani prawdziwego backendu. Formularz dodawania pokazuje istniejący alergen kanoniczny „Sezam”; edycja zachowuje rozróżnienie między mapowaniem źródłowym a definicją kanoniczną. Widok RAW pokazuje `ZAWIERA` oraz `MOŻE ZAWIERAĆ`.

Zrzuty z rzeczywistego podglądu (PNG, lokalnie):

| Widok | Plik |
|---|---|
| Desktop 1920 × 1080 | [desktop-1920x1080.png](review/pa05-visual-acceptance/desktop-1920x1080.png) |
| Desktop 1440 × 900 | [desktop-1440x900.png](review/pa05-visual-acceptance/desktop-1440x900.png) |
| Mobile 390 × 844 — widok aplikacji/nawigacji | [mobile-390x844.png](review/pa05-visual-acceptance/mobile-390x844.png) |
| Mobile 390 × 844 — pojedyncza kolumna źródłowa | [mobile-source-column-390x844.png](review/pa05-visual-acceptance/mobile-source-column-390x844.png) |
| Dodawanie istniejącego alergenu kanonicznego | [add-existing-canonical-1440x900.png](review/pa05-visual-acceptance/add-existing-canonical-1440x900.png) |
| Edycja wpisu źródłowego | [edit-source-entry-1440x900.png](review/pa05-visual-acceptance/edit-source-entry-1440x900.png) |
| RAW — dwa rodzaje deklaracji | [raw-declaration-two-types-1440x900.png](review/pa05-visual-acceptance/raw-declaration-two-types-1440x900.png) |

## 5. Analiza ośmiu wcześniejszych niepowodzeń backendu

Pierwotny wynik `461 passed, 8 failed (4 498 assertions)` został rozłożony na przyczyny zamiast traktować go jako pojedynczą regresję:

| Niepowodzenie | Klasyfikacja i dowód |
|---|---|
| `SystemPermissionManifestRegistryTest::registry limits the approved upgrade to its explicit scope` | Nieaktualne oczekiwanie testu: test zakładał brak wersji 2, ale `SystemPermissionManifestRegistry` jawnie udostępnia manifest `RECOVERY` v2. Istniejący `SystemPermissionProvisionerTest` potwierdza v2 i brak v3. Zaktualizowano test, by sprawdzał kontrakt odzyskiwania oraz odrzucenie v3; nie zmieniano manifestu. PA-05 ma osobną kontrolę administracyjną i nie wymaga wpisu PA w manifeście grantów RAW. |
| 6 przypadków `RawMaterialCompatibilityAssessmentTest`: decyzje oparte na CLEAN, odrzucenie skanów pending/infected/error, zmiana rewizji dostawcy/surowca, workflow HTTP, historia po superseded revision oraz rollback audytu. Test autoryzacji, izolacji organizacji i readiness gate przeszedł już w pierwotnym uruchomieniu. | Błędy zależne od skanera, a nie od PA-05: przy niedostępnym ClamAV wyniki oczekiwane jako `CLEAN`/`INFECTED` wracały jako `ERROR`; ocena oparta na dokumencie CLEAN kończyła się następnie błędem nieprawidłowego dowodu/HTTP 409. Po uruchomieniu izolowanego ClamAV wszystkie sześć przypadków przeszło. |
| `RawMaterialDocumentStorageTest::clamav scans synthetic clean and eicar samples` | Niedostępna zależność środowiskowa w pierwszym uruchomieniu; test wymagał ClamAV. Po uruchomieniu istniejącej izolowanej usługi skanowania test przechodzi i nadal sprawdza zarówno czysty, jak i EICAR-owy plik. Nie pomijano skanowania ani nie osłabiano blokad. |

Nie potwierdzono regresji PA-05 w tych ośmiu przypadkach. Potwierdzono nieaktualną asercję testową dla manifestu i środowiskową niedostępność ClamAV, która powodowała pozostałe niepowodzenia oraz ich skutki kaskadowe.

## 6. Weryfikacja końcowa

Backend działał wyłącznie przez izolowany wrapper `scripts/compose-test` i Docker context `veltra-test-isolated`. Pełne uruchomienie `composer test` osiągnęło własny timeout procesu Composer (300 s), dlatego pełną suitę ponowiono bezpośrednio przez `php artisan test --no-coverage`; to uruchomienie zakończyło się poprawnie.

| Zakres | Wynik |
|---|---|
| `scripts/compose-test run --build --rm migrate-test` | **PASS**; migracja i seedowanie w izolowanej bazie testowej. |
| Manifest, PA-05, administracyjny RBAC, RAW compatibility i document scanning | **28 testów PASS, 318 asercji**. |
| Pełny backend suite: `php artisan test --no-coverage` w izolowanym środowisku backendowym | **469 testów PASS, 4 584 asercje, 0 failures**; w tym `SystemPermissionManifestRegistryTest`, RAW compatibility, ClamAV/EICAR, PA allergen dictionaries, administration access i authorization. |
| PHP Pint dla zmienionego `SystemPermissionManifestRegistryTest.php` | **PASS** (`vendor/bin/pint --test`). |
| `cd frontend && npm test` | **PASS: 19 plików, 213 testów**; dodano testy liczby kolumn, odizolowanego wyszukiwania, dodawania istniejącego alergenu i otwierania edycji. |
| `cd frontend && npm run build` | **PASS**: TypeScript i build Vite. |
| `cd frontend && npm run lint` | Kod wyjścia 0; jedyne ostrzeżenie pozostało w istniejącym `src/visualPreview.tsx:39` (`react(only-export-components)`). |
| `npx vite build --config vite.interactive-preview.config.ts` | **PASS**; istniejący podgląd RAW i nowy podgląd PA-05 zbudowane w konfiguracji preview. |
| Browser preview | **PASS**; rzeczywiste komponenty sprawdzone przy 1920 × 1080, 1440 × 900 i 390 × 844; 4/3/1 kolumn oraz brak overflow na mobile. |
| `git diff --check` | **PASS**. |

## 7. Ograniczenia i gotowość PA-05

1. MASTER v1.1 nie stanowi certyfikacji prawnej. Nie wyprowadzono wyjątków z hierarchii i nie należy automatycznie komponować etykiet prawnych bez odrębnej weryfikacji.
2. Podgląd przeglądarkowy używa wyłącznie syntetycznych odpowiedzi z realistycznymi przykładami MASTER v1.1; nie jest testem integracyjnym prawdziwego backendu.
3. Nie użyto Stage ani produkcji, nie wykonano deployu ani pushu. Nie rozpoczęto PA-06.
4. Pozostało istniejące ostrzeżenie lint w `src/visualPreview.tsx:39`, poza zakresem zmian.

**Gotowość:** implementacja PA-05 i lokalna weryfikacja techniczna są gotowe do odbioru właściciela. Odbiór prawny danych MASTER i ostateczna akceptacja właściciela pozostają odrębnymi decyzjami.

## 8. Git

Poprzednia implementacja PA-05 i jej commity pozostają nietknięte; właściciel wypchnął je wcześniej. Poniżej zapisano nowe lokalne commity z korektami i dowodami. Nie wykonano pushu.

| Zakres | Lokalny commit |
|---|---|
| Poprawka nieaktualnego oczekiwania testowego manifestu | `a890b12b22a2c41a1b3bae9d1ea3cbc24e066d48` |
| Korekta UX, podgląd i testy PA-05 | `f7467f8b0c5d14d2eef72bd317de9125c98e97ba` |
