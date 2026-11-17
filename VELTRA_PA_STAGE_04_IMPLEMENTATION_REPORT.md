# VELTRA 2.0 — raport implementacyjny PA-04 (F14)

Data: 2026-11-17
Status techniczny: **weryfikacja lokalna PASS; PA-04 gotowy do odbioru przez właściciela**. Brak wdrożenia.

## 1. Podsumowanie

Dodano wspólny, globalny słownik jednostek używany przez panel administracyjny i RAW. Ekran F14 pozwala przeglądać, wyszukiwać, filtrować, tworzyć, edytować, archiwizować i przywracać jednostki. RAW pobiera definicje z tego samego katalogu; identyfikatory `mg`, `g`, `kg`, `ml`, `l`, `pc` i istniejące rewizje pozostają obsługiwane.

Nie dodano osobnego modelu ani równoległego systemu przeliczników. Przeliczenia tego samego wymiaru są obliczane z czynników względem jednostki bazowej. Konwersje między wymiarami nadal wymagają istniejącego, przypisanego do konkretnego surowca rodzaju `MATERIAL_SPECIFIC` i jawnej podstawy; brak globalnego przelicznika masa–objętość lub sztuki–masa/objętość.

## 2. Zgodność zakresu i reguły

| Obszar | Status | Realizacja |
|---|---|---|
| Lista, grupowanie po wymiarze, wyszukiwanie i status | **ISTNIEJE** | Widok F14 wyświetla nazwę, symbol, kod, wymiar, bazę, współczynnik, status, liczbę referencji historycznych i przeliczenia. Bazy do formularzy są pobierane niezależnie od filtrów widoku. |
| Tworzenie i edycja | **ISTNIEJE** | Administracyjny CRUD zachowuje kod oraz umożliwia edycję nazwy, symbolu i definicji, jeśli nie zmieni to znaczenia jednostki użytej historycznie. |
| Archiwizacja i przywracanie | **ISTNIEJE** | Archiwizacja nie usuwa definicji ani odniesień RAW. Jednostki bazowej nie można archiwizować. Zarchiwizowane jednostki nie są dostępne do nowych przypisań; istniejące przypisania pozostają widoczne. |
| Konwersje w obrębie wymiaru | **ISTNIEJE** | Jednostki używają jednej aktywnej jednostki kanonicznej i dodatniego współczynnika. Relacje prezentowane są w obie strony przez wyliczenie stosunku współczynników, bez osobnego grafu konwersji ani sprzecznych cykli. |
| Przeliczenia historyczne | **ISTNIEJE** | Zmiana wymiaru/bazy/współczynnika jest blokowana, gdy kod jest użyty przez rewizje, przypisania jednostek, importy, produkty dostawców lub zdarzenia cenowe. Historyczne rewizje nie są przeliczane ponownie. |
| Jednostki w RAW | **ISTNIEJE** | Rejestr, walidacja, formularze, katalog, szczegóły i wycena korzystają z dynamicznych definicji tego samego katalogu. Ziarno zgodności obejmuje sześć dotychczasowych kodów. |
| Rozszerzony katalog wyjątków | **NIEUSTALONE** | Nie dodano jednostek ani reguł konwersji spoza zatwierdzonego zakresu i sześciu kodów bazowych. |

Precyzja przechowywania wynosi `numeric(24,12)`, zgodnie z migracją i obecnym kontraktem danych. Formatowanie interfejsu nie jest źródłem wartości zapisywanej. Czynniki `mg→g=0.001`, `kg→g=1000`, `ml→ml=1`, `l→ml=1000` i `pc→pc=1` są seedowane migracją.

## 3. Zmienione i ponownie wykorzystane elementy

### Backend, API i baza

- [migracja 051](backend/database/migrations/2026_11_17_000051_create_raw_material_unit_dictionary.php:11) tworzy `raw_material_units`, seeduje sześć kodów, dodaje klucze obce do danych RAW i ochronę triggerami. `down()` jest celowo forward-only.
- [RawMaterialUnitRegistry](backend/app/Domain/RawMaterials/RawMaterialUnitRegistry.php:8) rozwiązuje definicje, pilnuje wymiaru/archiwizacji i wylicza czynniki.
- [RawMaterialService](backend/app/Domain/RawMaterials/RawMaterialService.php:276) obsługuje listowanie i CRUD F14, audyt zmian oraz opcje jednostek dla RAW. Ponownie używa istniejącej autoryzacji administratora, kontekstu mutacji i audytu.
- [AdministrationUnitController](backend/app/Http/Controllers/Api/V1/AdministrationUnitController.php) udostępnia CRUD F14.
- [RawMaterialController](backend/app/Http/Controllers/Api/V1/RawMaterialController.php:688) udostępnia te same definicje dla RAW.
- [api.php](backend/routes/api.php:52) dodaje trasy PA; trasa RAW `/api/v1/raw-materials/units` pozostaje w istniejącej grupie RAW, z jej autoryzacją i kontrolą dostępności modułu. Nie dodano ról ani middleware.
- Zaktualizowano walidację, wycenę, import katalogu i obsługę produktów dostawców, aby korzystały z katalogu zamiast zamkniętej listy jednostek.

### Frontend

- [UnitManagementPage](frontend/src/administration/UnitManagementPage.tsx:43) i [unitsApi](frontend/src/administration/unitsApi.ts) realizują administracyjny ekran i kontrakt HTTP.
- [AdministrationPanel](frontend/src/AdministrationPanel.tsx) podłącza ekran do istniejącej nawigacji PA; nie dodano drugiego sidebara.
- Formularze i widoki RAW pobierają definicje przez istniejący API. Zachowano prezentację już przypisanych, zarchiwizowanych jednostek w istniejących danych.
- Rozszerzono testy formularzy RAW i katalogu oraz dodano testy ekranu F14. Nie wprowadzono nowej biblioteki UI.

### Struktury i zabezpieczenia danych

`raw_material_units` ma klucz główny `id`, unikatowe indeksy dla `code` i `(code, dimension)` oraz ograniczenia formatu kodu, wymiaru, niepustej nazwy/symbolu i dodatniego współczynnika. Złożony FK wymusza jednostkę bazową w tym samym wymiarze. Trigger odrzuca usunięcie/zmianę kodu, archiwizację bazy i zmianę znaczenia jednostki referowanej przez dane historyczne. Odpowiednie FK z `ON DELETE RESTRICT` chronią istniejące rekordy RAW.

## 4. Endpointy

- `GET /api/v1/administration/units?status=active|archived|all&dimension=…&search=…`
- `POST /api/v1/administration/units`
- `PATCH /api/v1/administration/units/{uuid}`
- `POST /api/v1/administration/units/{uuid}/archive`
- `POST /api/v1/administration/units/{uuid}/restore`
- `GET /api/v1/raw-materials/units`

Trasy PA wymagają istniejącej kontroli administratora. Trasa RAW zachowuje autoryzację RAW, kontekst operacyjny i wymóg dostępności modułu.

## 5. Weryfikacja

### Środowisko testowe

Testy backendu działały przez istniejący wrapper `scripts/compose-test` i Docker context `veltra-test-isolated`, na `veltra_testing`. Konfiguracja Compose ustawia PostgreSQL na `tmpfs` (`/var/lib/postgresql/data`) i sieć `internal`; nie użyto domyślnego Dockera, Stage ani produkcji. Połączenie testów używa ograniczonej roli `veltra_app`, a migracje i fixture setup — `veltra_migrator`.

Przed właściwym uruchomieniem przebudowano obraz `backend-test`, aby testy wykonywały bieżące źródła. Testowy reset bazy usuwa również tabele osiągalne przez `TRUNCATE ... CASCADE`, w tym globalne definicje wskutek FK do użytkowników; dlatego test fixture odtwarza sześć zatwierdzonych seedów po czyszczeniu. Nie zmienia to migracji ani produkcyjnego katalogu.

### Faktyczne wyniki

| Polecenie / zakres | Wynik |
|---|---|
| `scripts/compose-test build backend-test` | PASS; obraz zbudowany z bieżącego repozytorium. |
| `php artisan test tests/Feature/AdministrationAccessTest.php tests/Feature/RawMaterialCapabilitiesApiTest.php tests/Feature/RawMaterialsTest.php tests/Feature/RawMaterialRevisionDataModelTest.php --no-coverage` w `backend-test` | **202 testy PASS, 2 340 asercji**, 396.98 s. Obejmuje CRUD PA, odmowę dla nie-adminów, kompatybilność RAW, operacje cenowe/ilościowe, jednostki i bezpośrednio związane testy rewizji/RBAC. |
| `scripts/compose-test run --rm backend-test php artisan test tests/Feature/AdministrationAccessTest.php --no-coverage` po dodaniu sprawdzeń przeliczników wzajemnych | **6 testów PASS, 103 asercje**; potwierdzono konwersje kg↔g i l↔ml oraz przelicznik niestandardowy w obie strony. |
| `php artisan test tests/Feature/RawMaterialRevisionDataModelTest.php --no-coverage --filter=archived_custom_unit` w `backend-test` | **1 test PASS, 9 asercji**. Weryfikuje zachowanie referencji po archiwizacji, blokadę zmiany definicji i odmowę nowego przypisania jednostki archiwalnej. |
| Zapytania PostgreSQL w `veltra_testing` | PASS: migracja `2026_11_17_000051_create_raw_material_unit_dictionary` jest zastosowana (batch 2); sześć seedów obecne; sprawdzono FK, check constraints i indeksy `id`, `code`, `(code, dimension)`. |
| `cd frontend && npm test` | **PASS: 17 plików, 209 testów**. |
| `cd frontend && npm run lint` | PASS, kod wyjścia 0. Pozostało istniejące ostrzeżenie `react(only-export-components)` w `src/visualPreview.tsx:39`, poza zakresem F14. |
| `cd frontend && npm run build` | PASS: `tsc -b` i build Vite. |
| `scripts/compose-test run --rm backend-test vendor/bin/pint --test` | **PASS: 329 plików**. |
| `git diff --check` | PASS. |

Wcześniejszy przebieg backendu przed przebudowaniem obrazu korzystał z nieaktualnego obrazu, a test fixture nie odtwarzał globalnych seedów po `TRUNCATE ... CASCADE`; jego błędy nie są końcowym wynikiem. Po przebudowie obrazu i poprawie fixture ponowiono testy na bieżącym kodzie; wyniki końcowe podano powyżej.

## 6. Ograniczenia i otwarte kwestie

1. Dotychczasowy import cen CSV nadal zachowuje swój jawny kontrakt `kg`, `l`, `szt.` oraz kody cenowe `kg`, `l`, `pc` ([RawMaterialCsvParser](backend/app/Domain/RawMaterials/RawMaterialCsvParser.php:91), [RawMaterialCsvImportPersistenceService](backend/app/Domain/RawMaterials/RawMaterialCsvImportPersistenceService.php:256)). Nie rozszerzono tego formatu automatycznie na nowe jednostki; wymagałoby to osobnej decyzji o zgodności plików wejściowych i dotychczasowej semantyki eksportu/importu.
2. Ostateczny katalog jednostek rozszerzonych i szczególne zasady przeliczeń nie zostały zatwierdzone; zachowano bazowe sześć kodów.
3. Lokalne kopie specyfikacji funkcjonalnej PA i standardu UI oraz audyt techniczny były dostępne i wykorzystane. Oryginalne dokumenty Stage 1D RBAC v1 i Stage 1E architektury modułów nie były dostępne w bieżącym workspace/sesji; nie zastępowano ich założeniami. Implementacja nie zmienia ról, hierarchii RBAC ani architektury aktywacji modułów.
4. Raport pozostaje lokalny: w tej sesji nie jest dostępne uwierzytelnione narzędzie zapisu do folderu Google Drive.

## 7. Git i gotowość

Commit implementacyjny: `ed301c6a9c2456a76c51d2d7be3d01f3a5abeddd` (`feat(admin): implement F14 units and conversions`; 40 plików). Raport jest dodawany oddzielnym lokalnym commitem dokumentacyjnym. Nie wykonano pushu ani wdrożenia. Plik `tests/compose/test.env`, dane logowania i artefakty testowe nie należą do zakresu commitów.

**Rekomendacja:** PA-04 jest gotowy do odbioru właściciela i przejścia do planowania PA-05. Nierozszerzony import cen CSV i brak dostępu do źródłowych dokumentów Stage 1D/1E pozostają jawnie odnotowanymi ograniczeniami.
