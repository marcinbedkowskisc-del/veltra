# VELTRA 2.0 — raport implementacyjny PA-05

Data: 2026-11-18
Status techniczny: **poprawki po odrzuceniu wizualnym zaimplementowane; pełna weryfikacja backendu i frontendów zakończona pomyślnie. PA-05 nadal oczekuje na ponowną akceptację właściciela.** Nie wdrażano.

## 1. Podsumowanie

Po odrzuceniu wizualnym PA-05 uproszczono zarządzanie słownikami do kompaktowych, pionowych list nazw alergenów i kontekstowego menu w każdym wierszu. Podgląd syntetyczny odtwarza teraz wszystkie 178 relacji i 9 wersji bez skracania. Dane produkcyjne, API, model kanoniczny, wersjonowanie słowników i integracja RAW pozostały wspólne i niezmienione.

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

## 4. Uproszczenie UX i browser preview

Zachowano zaakceptowany układ kolumnowy oraz globalny sidebar. Każdy z 8 źródeł ma własną kolumnę z pionową listą polskich nazw, wyszukiwaniem, filtrem archiwalnych relacji i przyciskiem `+ Dodaj alergen`. Każdy aktywny wiersz ma dostępne z klawiatury menu `⋯` z etykietami `Edytuj` i `Usuń ze słownika`; archiwizacja wymaga potwierdzenia i dotyczy tylko relacji w danym źródle.

Zwykły wiersz nie pokazuje identyfikatorów `ALG_*`, reguł, zakresów, statusów technicznych ani JSON. Nazwa wersji, status, data obowiązywania i data weryfikacji są widoczne bez rozwijania szczegółów; organ i oficjalny odnośnik pozostają w opcjonalnym obszarze źródła. Edycja pokazuje wspólną nazwę kanoniczną jako tylko do odczytu, edytowalną nazwę w języku źródłowym i uwagi; niewidoczne dane prawne i techniczne są kopiowane z oryginalnego wpisu do istniejącego pełnego PATCH. Dodawanie istniejącego alergenu umożliwia wyszukiwanie puli, a wymagane klasyfikacje trzeba jawnie wybrać po sprawdzeniu źródła. Nowy alergen otrzymuje stabilny identyfikator wygenerowany w backendzie.

Podgląd `http://localhost:5175/allergen-preview.html` renderuje rzeczywiste komponenty React (`ApplicationShell`, `AdministrationPanel`, `AllergenDictionaryManagementPage` i `RawMaterialWorkflow`). Dane API są syntetyczne, ale mapowane bezpośrednio z kompletnego MASTER v1.1; nie ma połączenia ze Stage ani produkcją. Chiny pozwalają przełączać bieżącą i przyszłą wersję. RAW zachowuje dwa wiersze z typami `ZAWIERA` i `MOŻE ZAWIERAĆ`.

Zrzuty ekranowe z aktualnego podglądu (PNG, lokalnie):

| Widok | Plik |
|---|---|
| Desktop 1920 × 1080 | [desktop-1920x1080.png](review/pa05-visual-acceptance/desktop-1920x1080.png) |
| Desktop 1440 × 900 | [desktop-1440x900.png](review/pa05-visual-acceptance/desktop-1440x900.png) |
| Mobile 390 × 844 | [mobile-390x844.png](review/pa05-visual-acceptance/mobile-390x844.png) |
| Pełna lista UE — wszystkie 28 wpisów | [eu-full-28-entries.png](review/pa05-visual-acceptance/eu-full-28-entries.png) |
| Otwarte menu wiersza | [row-menu-open-1440x900.png](review/pa05-visual-acceptance/row-menu-open-1440x900.png) |
| Uproszczona edycja | [simplified-edit-form-1440x900.png](review/pa05-visual-acceptance/simplified-edit-form-1440x900.png) |
| Dodawanie istniejącego alergenu kanonicznego | [add-existing-canonical-1440x900.png](review/pa05-visual-acceptance/add-existing-canonical-1440x900.png) |
| Dodawanie nowego alergenu | [simplified-add-form-1440x900.png](review/pa05-visual-acceptance/simplified-add-form-1440x900.png) |
| Chiny — przyszła wersja, pełna kolumna | [china-future-version-source-column.png](review/pa05-visual-acceptance/china-future-version-source-column.png) |
| RAW — dwa rodzaje deklaracji | [raw-declaration-two-types-1440x900.png](review/pa05-visual-acceptance/raw-declaration-two-types-1440x900.png) |

## 5. Kompletność danych w MASTER, bazie, API i DOM

Źródłem porównania jest wyłącznie `backend/database/data/allergen_dictionaries_master_v1_1.json` (MASTER v1.1). Liczby bazy zweryfikowano po migracji i seedowaniu izolowanej bazy PostgreSQL; odpowiedzi API sprawdzono przez endpointy administracyjne w teście Feature; preview mapuje komplet danych i liczy renderowane wiersze DOM.

| Wersja słownika | MASTER | Izolowana baza | API | Syntetyczny preview | DOM React |
|---|---:|---:|---:|---:|---:|
| UE | 28 | 28 | 28 | 28 | 28 |
| USA / FDA | 24 | 24 | 24 | 24 | 24 |
| Codex Alimentarius | 25 | 25 | 25 | 25 | 25 |
| Japonia | 29 | 29 | 29 | 29 | 29 |
| Chiny — bieżąca | 8 | 8 | 8 | 8 | 8 (domyślna) |
| Chiny — przyszła | 8 | 8 | 8 | 8 | 8 (po przełączeniu wersji) |
| Korea Południowa | 19 | 19 | 19 | 19 | 19 |
| Tajwan | 17 | 17 | 17 | 17 | 17 |
| Tajlandia | 20 | 20 | 20 | 20 | 20 |
| **Relacje łącznie** | **178** | **178** | **178** | **178** | **178 zweryfikowanych dla 9 wersji** |

W normalnym układzie kolumn widocznych jest 8 jurysdykcji i 170 relacji, bo Chiny pokazują jedną wybraną wersję naraz; po przełączeniu bieżącej na przyszłą lista Chin nadal zawiera 8 wpisów. Zestaw 178 relacji pozostaje kompletny w fixture’ach/API i został przetestowany z DOM dla wszystkich wersji, w tym przyszłej Chin.

**Potwierdzona przyczyna poprzedniego niekompletnego wyglądu:** frontend nie ucinał odpowiedzi. Poprzedni `allergenVisualPreviewData.ts` ręcznie zawierał zaledwie 2–4 wybrane relacje na słownik i wpisy `entry_count` równe liczbie przykładów. Seeder oraz `InternationalAllergenDictionaryService::dictionaryEntries` zwracają komplet danych: query API kończy się `.get()` bez paginacji/limitu, a komponent iteruje całą odpowiedź. Preview zmieniono na bezpośrednie mapowanie kompletnego MASTER v1.1; nie dodano mechanizmu „Pokaż więcej”.

## 6. Analiza ośmiu wcześniejszych niepowodzeń backendu

Pierwotny wynik `461 passed, 8 failed (4 498 assertions)` został rozłożony na przyczyny zamiast traktować go jako pojedynczą regresję:

| Niepowodzenie (dokładny test) | Klasyfikacja i dowód |
|---|---|
| `SystemPermissionManifestRegistryTest::test_registry_limits_the_approved_upgrade_to_its_explicit_scope` | Nieaktualne oczekiwanie testu: test zakładał brak wersji 2, ale `SystemPermissionManifestRegistry` jawnie udostępnia manifest `RECOVERY` v2. Istniejący `SystemPermissionProvisionerTest` potwierdza v2 i brak v3. Zaktualizowano test, by sprawdzał kontrakt odzyskiwania oraz odrzucenie v3; nie zmieniano manifestu. PA-05 ma osobną kontrolę administracyjną i nie wymaga wpisu PA w manifeście grantów RAW. |
| `RawMaterialCompatibilityAssessmentTest::test_business_decisions_bind_exact_clean_evidence_and_are_immutable_audited_and_idempotent` | Błąd zależny od skanera, nie regresja PA-05: przy niedostępnym ClamAV CLEAN evidence wracał jako ERROR i blokował ocenę. |
| `RawMaterialCompatibilityAssessmentTest::test_pending_infected_and_error_scans_are_rejected_as_assessment_evidence` | Błąd zależny od skanera, nie regresja PA-05: test oczekiwał autorytatywnych wyników skanowania, a niedostępny skaner zwracał ERROR. |
| `RawMaterialCompatibilityAssessmentTest::test_a_new_supplier_or_raw_material_revision_makes_old_assessment_pending` | Błąd kaskadowy od braku CLEAN evidence z ClamAV; test przeszedł w pełnym odizolowanym suite. |
| `RawMaterialCompatibilityAssessmentTest::test_http_workflow_returns_current_assessment_and_remains_behind_readiness_gate` | Błąd kaskadowy od braku CLEAN evidence z ClamAV; po odtworzeniu skanera workflow HTTP przeszedł. |
| `RawMaterialCompatibilityAssessmentTest::test_recipient_compatibility_history_excludes_assessments_for_superseded_raw_revisions` | Błąd kaskadowy od braku CLEAN evidence z ClamAV; historia po zmianie rewizji przeszła w pełnym suite. |
| `RawMaterialCompatibilityAssessmentTest::test_assessment_and_audit_roll_back_together_when_audit_insert_fails` | Błąd kaskadowy przy przygotowaniu dokumentu CLEAN przez helper testowy w środowisku bez działającego skanera; po izolowanym rerunie pełny przypadek rollbacku przeszedł. |
| `RawMaterialDocumentStorageTest::test_clamav_scans_synthetic_clean_and_eicar_samples` | Niedostępna zależność środowiskowa w pierwszym uruchomieniu; test wymaga ClamAV. W istniejącym odizolowanym suite test przechodzi i sprawdza czysty oraz EICAR-owy plik. Nie pomijano skanowania ani nie osłabiano blokad. |

Nie potwierdzono regresji PA-05 w tych ośmiu przypadkach. Potwierdzono nieaktualną asercję testową dla manifestu i środowiskową niedostępność ClamAV, która powodowała pozostałe niepowodzenia oraz ich skutki kaskadowe.

## 7. Weryfikacja końcowa

Backend działał wyłącznie przez izolowany wrapper `scripts/compose-test` i Docker context `veltra-test-isolated`. Pełne uruchomienie `composer test` osiągnęło własny timeout procesu Composer (300 s), dlatego pełną suitę ponowiono bezpośrednio przez `php artisan test --no-coverage`; to uruchomienie zakończyło się poprawnie.

| Zakres | Wynik |
|---|---|
| Migracja i seedowanie przez istniejący izolowany wrapper Compose | **PASS**; baza testowa PostgreSQL i zależności testowe uruchomione tylko w `veltra-test-isolated`. |
| Fokusowy backend PA-05, administracyjny RBAC, RAW compatibility, rewizje i dokumenty | **211 testów PASS, 2 414 asercji**. |
| Pełny backend suite: `scripts/compose-test run --rm backend-test php artisan test --no-coverage --compact` | **469 testów PASS, 4 584 asercje, 0 failures**. W tym manifest uprawnień, alergenowe API/seed, RAW compatibility, ClamAV/EICAR, administracyjny dostęp i autoryzacja. |
| PHP Pint dla zmienionych plików backendu | **PASS** (`vendor/bin/pint --test`). |
| `cd frontend && npm test` | **PASS: 19 plików, 219 testów**; testy obejmują pełne dane w fixture/DOM, widoczne metadane wersji, menu, edycję, archiwizację, formularze i China future. |
| `cd frontend && npm run build` | **PASS**: TypeScript i build Vite. |
| `cd frontend && npm run lint` | Kod wyjścia 0; jedyne ostrzeżenie pozostało w istniejącym `src/visualPreview.tsx:39` (`react(only-export-components)`). |
| `npx vite build --config vite.interactive-preview.config.ts` | **PASS**; istniejący podgląd RAW i nowy podgląd PA-05 zbudowane w konfiguracji preview. |
| Browser preview | **PASS**; rzeczywiste komponenty sprawdzone przy 1920 × 1080, 1440 × 900, 1024 × 768 i 390 × 844; odpowiednio 4/3/2/1 kolumn oraz brak poziomego overflow. Zrzuty są w `review/pa05-visual-acceptance/`; preview działa pod `http://localhost:5175/allergen-preview.html`. |
| `git diff --check` | **PASS**. |

## 8. Ograniczenia i gotowość PA-05

1. Pliki `VELTRA_PA_FUNCTIONAL_UX_SPEC_v1_2026-11-17.md` i `VELTRA_ALLERGEN_DICTIONARIES_MASTER_v1.1.md` nie występują w tym checkoutcie. Wymagania wizualne zastosowano z briefu właściciela, a dane MASTER porównano z dostępnym `backend/database/data/allergen_dictionaries_master_v1_1.json`; przed prawnym zatwierdzeniem należy porównać z oryginalnym dokumentem.
2. MASTER v1.1 nie stanowi certyfikacji prawnej. Nie wyprowadzono wyjątków z hierarchii i nie należy automatycznie komponować etykiet prawnych bez odrębnej weryfikacji.
3. Podgląd przeglądarkowy używa syntetycznych odpowiedzi API mapowanych z kompletnego MASTER v1.1; nie jest testem integracyjnym z backendem.
4. Nie użyto Stage ani produkcji, nie wykonano deployu ani pushu. Nie rozpoczęto PA-06.
5. Pozostało istniejące ostrzeżenie lint w `src/visualPreview.tsx:39`, poza zakresem zmian.

**Gotowość:** PA-05 jest technicznie zweryfikowane i gotowe do ponownego odbioru wizualnego przez właściciela. Odbiór prawny MASTER oraz sprawdzenie niedostępnego pliku UX spec pozostają odrębnymi decyzjami.

## 9. Git

Poprzednia implementacja PA-05 i jej commity pozostają nietknięte; właściciel wypchnął je wcześniej. Poniżej zapisano nowe lokalne commity z korektami i dowodami. Nie wykonano pushu.

| Zakres | Lokalny commit |
|---|---|
| Poprawka nieaktualnego oczekiwania testowego manifestu (commit właściciela, pozostawiony bez zmian) | `a890b12b22a2c41a1b3bae9d1ea3cbc24e066d48` |
| Korekta UX, podgląd i testy PA-05 (commit właściciela, pozostawiony bez zmian) | `f7467f8b0c5d14d2eef72bd317de9125c98e97ba` |
| Uproszczony UX, widoczne metadane wersji, kompletne preview i zrzuty | `37d8d35` |
| Stabilne ID kanoniczne i testy kompletności API/seed | `6ad4219` |

## 10. Final polish — nazwy alergenów i język interfejsu

Nazwy widoczne na listach PA są prezentowane po polsku i wielkimi literami. Dodano mapowanie wyłącznie w warstwie prezentacji, według istniejącego ID alergenu:

| ID | Nazwa wyświetlana |
|---|---|
| `ALG_KHORASAN` | PSZENICA KHORASAN (KAMUT) |
| `ALG_TREE_NUTS` | ORZECHY DRZEWNE |
| `ALG_WALNUT` | ORZECHY WŁOSKIE |
| `ALG_CLAMS` | MAŁŻE |
| `ALG_ABALONE` | UCHO MORSKIE |
| `ALG_YAM` | POCHRZYN |

Pozostałe 48 nazw przechodzi przez formatowanie `toLocaleUpperCase('pl-PL')`. Sprawdzono indywidualnie wszystkie 54 pozycje MASTER v1.1. Jedyna niewybrana forma z ukośnikiem to `KAŁAMARNICA / KALMAR`: oba człony są polskimi nazwami, więc pozostawiono je do decyzji właściciela zamiast arbitralnie wybierać jeden. Żadne nazwy MASTER, synonimy, nazwy oryginalne, identyfikatory ani relacje nie zostały zmienione; test potwierdza zachowanie 54 tożsamości i 178 relacji.

Tekst formularza edycji zastąpiono zatwierdzonym, prostszym wyjaśnieniem: „Ta nazwa jest wspólna dla wszystkich słowników. Zmiany wprowadzone poniżej dotyczą tylko wybranego słownika.” Uproszczono również teksty formularzy, walidacji, błędów API i powiadomień. Zachowano ostrzeżenie, że usunięcie dotyczy tylko wybranego słownika, a historia i wcześniejsze deklaracje surowca pozostają bez zmian. Potwierdzenia edycji i archiwizacji pozostają objęte testami.

Zmiany nie obejmują formularzy, typów deklaracji, snapshotów, historii ani workflow RAW; brak wpływu na zachowanie RAW. Zmiany PHP dotyczą wyłącznie komunikatów — obsługa słownika, autoryzacja, audyt i klasyfikacja prawna pozostały bez zmian.

## 11. PA-05F — instalacyjna lista „MOŻE ZAWIERAĆ”

### Zakres i implementacja

Dodano jedną trwałą listę dla całej instalacji VELTRA, bez wymiaru organizacji, lokalizacji ani modułu. Tabela `installation_precautionary_allergens` przechowuje wyłącznie odwołania do istniejącego katalogu alergenów; klucz główny `allergen_id` zapobiega duplikatom, a klucz obcy chroni definicje katalogu. Dodawanie i usuwanie korzysta z istniejącego uprawnienia administracyjnego oraz mechanizmu audytu. Usunięcie z listy nie usuwa ani nie archiwizuje alergenu, powiązań słownikowych ani danych RAW.

Administracja ma osobną zakładkę „MOŻE ZAWIERAĆ” obok niezmienionego porównania ośmiu słowników międzynarodowych. Widok pokazuje polskie nazwy wielkimi literami, zwarte wiersze z menu ⋯ i wyszukiwany wybór istniejących pozycji; pozycje już na liście są wykluczone z wyboru. Nie pokazuje identyfikatorów ani metadanych technicznych.

API administracyjne udostępnia odczyt, dodawanie i usuwanie wpisów. `InstallationPrecautionaryAllergenService` zachowuje administratora, kontekst i zapis audytowy zgodne z istniejącymi wzorcami VELTRA. `EffectivePrecautionaryAllergenService` udostępnia wielokrotnego użytku obliczenie: łączy globalne wpisy z zachowanymi deklaracjami ostrożnościowymi danego surowca, deduplikuje po identyfikatorze katalogowym i usuwa wyłącznie dokładne identyfikatory obecne w deklaracjach „ZAWIERA”. Wynik nie zmienia globalnej listy ani zapisów konkretnego surowca.

Relacje nadrzędny/potomny nie są traktowane jako równoznaczne. Przykładowo bezpośrednia deklaracja PSZENICY nie usuwa osobnej pozycji ZBOŻA ZAWIERAJĄCE GLUTEN. Nie wprowadzono reguł klasyfikacji ani generowania etykiet; ich prawny zakres wymaga osobnego zatwierdzenia.

### Podgląd i weryfikacja PA-05F

Podgląd `http://localhost:5175/allergen-preview.html` renderuje rzeczywisty komponent administracyjny React z syntetycznym API; nie łączy się z Stage ani produkcją. Zakładka pokazuje przykładową listę SOJA, GORCZYCA i SELER, formularz wyszukiwania i dodawania istniejącego alergenu oraz menu usunięcia. Widoki listy i formularza zostały sprawdzone w przeglądarce w bieżącej sesji.

| Zakres | Wynik |
|---|---|
| Pełna backend suite w izolowanym Compose/PostgreSQL | **PASS: 473 testy, 4 660 asercji, 0 failures**; 823,26 s. |
| Izolowane testy backendowe PA-05F, słowników, RBAC i RAW | **PASS: 12 testów, 222 asercje**; obejmują trwałość instalacyjną, odmowę dla nie-administratora, deduplikację, obliczanie po ID oraz niezmienność deklaracji i historii RAW. |
| PHP Pint dla zmienionych plików | **PASS** (`vendor/bin/pint --test`). |
| Pełna suita frontendowa | **PASS: 19 plików, 223 testy**. |
| TypeScript i build produkcyjny | **PASS** (`npm run build`). |
| Lint | Kod wyjścia 0; pozostało istniejące ostrzeżenie `src/visualPreview.tsx:39` (`react(only-export-components`). |
| Build podglądu interaktywnego | **PASS** (`npx vite build --config vite.interactive-preview.config.ts`). |
| `git diff --check` | **PASS**. |

Zmiany nie modyfikują seedów MASTER v1.1, formularzy i przepływów RAW, typów deklaracji, rewizji ani historycznych snapshotów. Test RAW potwierdza, że operacje na liście globalnej nie zmieniają zapisanych deklaracji ani historii. Podgląd korzysta z danych syntetycznych, więc nie jest testem integracyjnym API z bazą.

**Ograniczenia:** lista i obliczenie efektywne są gotowe do ponownego użycia przez przyszłe moduły, ale nie uruchamiają drukowania ani generowania etykiet. Nie rozstrzygają też prawnej równoważności kategorii. PA-05F pozostaje częścią PA-05 i wymaga końcowego odbioru właściciela; PA-06 nie rozpoczęto.

**Lokalny commit implementacji PA-05F:** `9252a1e` (`feat(allergens): add installation-wide precautionary list`). Nie wykonano pushu ani deployu.

Weryfikacja tej poprawki:

| Zakres | Wynik |
|---|---|
| Testy PA-05 i parserów API | **12 testów PASS**. |
| Pełna suita frontendowa: `cd frontend && npm test` | **19 plików, 221 testów PASS**. |
| TypeScript i build: `cd frontend && npm run build` | **PASS** (`tsc -b` i Vite). |
| Lint: `cd frontend && npm run lint` | Kod wyjścia 0; pozostało istniejące ostrzeżenie `src/visualPreview.tsx:39`. |
| Backend `AllergenDictionaryManagementTest` przez `scripts/compose-test` | **2 testy, 38 asercji PASS**. |
| PHP Pint dla zmienionego serwisu i testu | **2 pliki PASS**. |
| `git diff --check` | **PASS**. |

Pełnego backend suite nie uruchamiano ponownie w ramach tej poprawki tekstowej; wynik pełnej suity z wcześniejszej weryfikacji pozostaje udokumentowany w sekcji 7. Preview, Stage i produkcja nie były używane. Nie wykonano pushu ani deployu; PA-06 nie rozpoczęto.

Commit poprawki: `2c1151669f18ae9d4fb6811a9287ffae8c078e0f`. Raport zapisano w następnym lokalnym commicie dokumentacyjnym. PA-05 nadal oczekuje na końcową akceptację właściciela.
