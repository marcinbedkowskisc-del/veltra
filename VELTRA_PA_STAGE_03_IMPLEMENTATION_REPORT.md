# VELTRA 2.0 — raport implementacji PA-03 (F10)

## Status końcowy

Implementacja F10 jest przygotowana lokalnie i przeszła weryfikację frontendową
oraz backendową. Migrację wykonano na świeżej, jednorazowej bazie PostgreSQL
działającej w zatwierdzonym izolowanym kontekście `veltra-test-isolated`.
Wszystkie 9 plików backendowych przeszło: **221 testów, 2 620 asercji, 0
niepowodzeń**. Commit PA-03 zostanie utworzony po końcowym przeglądzie diffu;
nie wykonano push ani deploymentu.

## Zakres wdrożonej funkcjonalności

- Widok `/administration/categories` w istniejącym Panelu Administracyjnym
  wyświetla hierarchię kategorii RAW, ścieżkę wyników wyszukiwania, status,
  opisy i liczbę kartotek RAW powiązanych z kategorią.
- Obsługiwane są listowanie z paginacją, wyszukiwanie po nazwie/opisie,
  filtrowanie aktywnych/archiwalnych/wszystkich, tworzenie kategorii głównej i
  podkategorii, edycja nazwy/opisu, archiwizacja oraz przywracanie.
- W interfejsie nie ma operacji usuwania ani zmiany rodzica. Kod i relacje
  historyczne pozostają niezmienne. Formularz wyjaśnia ograniczenie parent/depth
  wynikające z istniejącego triggera bazy.
- UI informuje o zachowaniu referencji RAW, a odpowiedzi backendu są źródłem
  walidacji i błędów operacji.
- W katalogu RAW nowe przypisania mogą wskazywać tylko aktywne kategorie.
  Edycja surowca zachowuje i pokazuje jego bieżące przypisanie do kategorii
  archiwalnej, a filtry katalogu oznaczają taki status.
- Zachowano dotychczasowy menedżer kategorii RAW dostępny na podstawie
  istniejącego grant-u `RAW_MATERIALS_CATEGORY_MANAGE`. Korzysta on nadal z
  tego samego słownika i istniejących endpointów RAW; nie utworzono drugiego
  modelu ani słownika. Endpointy RAW nie zastępują administratorowego API PA.

## Reguły hierarchii i archiwizacji

Zastosowano zatwierdzoną decyzję właściciela:

1. Archiwizacja odbywa się od najniższego poziomu w górę, bez kaskady.
2. Kategoria z dowolnym aktywnym potomkiem nie może zostać zarchiwizowana.
3. Przywrócenie podkategorii jest możliwe tylko wtedy, gdy jej bezpośredni
   rodzic jest aktywny.
4. Archiwizacja i przywracanie nie zmieniają przypisań surowców ani rewizji.

Operacje są sprawdzane backendowo w transakcji i serializowane blokadą
organizacji. Istniejący trigger nadal chroni niezmienność `parent_id` i `depth`,
a FK kategorii do rewizji zachowuje dotychczasowe powiązania.

## API i dane

Nowe endpointy w istniejącej grupie PA:

- `GET /api/v1/administration/categories` — lista, hierarchia, wyszukiwanie i
  paginacja;
- `POST /api/v1/administration/categories` — utworzenie kategorii głównej lub
  podkategorii;
- `PATCH /api/v1/administration/categories/{category}` — aktualizacja nazwy i
  opisu, bez zmiany kodu ani rodzica;
- `POST /api/v1/administration/categories/{category}/archive`;
- `POST /api/v1/administration/categories/{category}/restore`.

Trasy korzystają z istniejących middleware sesji, uwierzytelnienia, kontekstu
tożsamości i lokalizacji oraz `RequireAdministrator`. Serwis ponownie sprawdza
aktywną rolę administratora i używa istniejącego podpisanego kontekstu mutacji
RAW oraz audytu. Autoryzacja PA nie wymaga gotowości modułu RAW ani grantów RAW.
Nie zmieniono ról ani ich hierarchii.

Pozostają zachowane istniejące endpointy RAW `GET/POST
/api/v1/raw-materials/categories` i `PATCH
/api/v1/raw-materials/categories/{category}` z ich dotychczasowymi kontrolami
RAW. Nie dodano endpointu usuwania.

Migracja addytywna
`backend/database/migrations/2026_11_17_000050_add_raw_material_category_description_and_archive.php`
dodaje do istniejącej `raw_material_categories` nullable `description`,
`archived_at` i `archived_by`, FK aktora w obrębie organizacji, constraint
spójności stanu archiwizacji i indeks listowania. Nie zmienia istniejących
identyfikatorów, kodów, hierarchii ani rewizji RAW; stare rekordy pozostają
aktywne z pustym opisem. Migrację uruchomiono pomyślnie po raz pierwszy na
testowej bazie `veltra_testing`; wszystkie 50 migracji zakończyło się
powodzeniem.

W bazie testowej odczytowo potwierdzono nowe kolumny oraz:

- `raw_material_categories_archive_actor_fk` — FK `(archived_by,
  organization_id)` do użytkownika w tej samej organizacji;
- `raw_material_categories_archive_state_check` — `archived_at` i
  `archived_by` są ustawione albo puste równocześnie;
- `raw_material_categories_lifecycle_idx` — indeks
  `(organization_id, archived_at, parent_id, name)`.

Zachowano istniejący unique `(organization_id, code)`, FK rodzica oraz
ograniczenie niezmienności `parent_id`/`depth`. Przed migracją testowa baza nie
miała tabel; po migracji nie zawierała organizacji ani użytkowników. Data
PostgreSQL była w `tmpfs`, bez persistent volume, w wewnętrznej sieci testowej.
Wszystkie migracje i testy działały wyłącznie w tym disposable środowisku.

## Zmienione i dodane pliki

Backend:

- Zmieniono `backend/app/Domain/RawMaterials/RawMaterialService.php`:
  operacje PA, zapytania drzewa/wyszukiwania, archiwizacja/przywracanie,
  walidacja aktywnego rodzica, obsługa duplikatu kodu oraz kompatybilność
  istniejącego RAW.
- Dodano `backend/app/Http/Controllers/Api/V1/AdministrationCategoryController.php`.
- Dodano `backend/app/Http/Requests/AdministrationCategoryListRequest.php`,
  `AdministrationCategoryCreateRequest.php` i
  `AdministrationCategoryUpdateRequest.php`.
- Zmieniono `backend/routes/api.php`, `backend/bootstrap/app.php` i
  `backend/app/Http/Requests/RawMaterialCategoryRequest.php`.
- Dodano migrację opisaną w sekcji „API i dane”.
- Rozszerzono `backend/tests/Feature/AdministrationAccessTest.php` o testy
  dostępności niezależnej od RAW, odmów dla ról nieadministracyjnych i sesji
  niezalogowanej, CRUD hierarchii, wyszukiwania, duplikatu kodu, blokad
  archiwizacji/przywracania, cofnięcia roli oraz wyłączonego konta.
- Rozszerzono `backend/tests/Feature/RawMaterialCapabilitiesApiTest.php` o test
  zachowania referencji istniejącego surowca po archiwizacji kategorii.

Frontend:

- Dodano `frontend/src/administration/categoriesApi.ts`,
  `CategoryManagementPage.tsx` i `CategoryManagementPage.test.tsx`.
- Zmieniono `frontend/src/AdministrationPanel.tsx` i `frontend/src/App.css`.
- Zmieniono `frontend/src/rawMaterials/catalog.ts`,
  `CatalogFilters.tsx`, `RawMaterialCreateForm.tsx`,
  `RawMaterialEditForm.tsx` i `RawMaterialsCatalogPage.tsx`.
- Zachowano istniejące `frontend/src/rawMaterials/RawMaterialCategoryManager.tsx`
  i jego testy regresyjne.

## Weryfikacja — rzeczywiste wyniki

Frontend (uruchomiono po ostatnich zmianach):

- `cd frontend && npm test` — **PASS**, 16 plików testowych, 205 testów.
- `cd frontend && npm run lint` — **PASS**, pozostało jedno ostrzeżenie w
  niezmienionym `frontend/src/visualPreview.tsx:39`
  (`react(only-export-components)`).
- `cd frontend && npm run build` — **PASS**, `tsc -b` i build Vite.
- Dodatkowy celowany przebieg PA/RAW — **PASS**, 70 testów w 3 plikach przed
  ekstrakcją komponentu drzewa; po ekstrakcji test PA kategorii — **PASS**, 8/8.
  Końcowy pełny przebieg obejmuje wszystkie te pliki i potwierdza 205/205.
- `git diff --check` — **PASS**.

Backend i baza:

- Środowisko przywrócono przez istniejący `scripts/compose-test`, który
  waliduje konfigurację i daemon, a następnie jawnie wybiera kontekst
  `veltra-test-isolated`. Jest to rootless daemon z osobnym data root,
  oznaczony `com.veltra.daemon-role=disposable-test`; nie użyto domyślnego
  Docker daemon ani Stage/production.
- Migracja i inicjalizacja:
  `scripts/compose-test run --build --rm migrate-test` — **PASS**, wszystkie
  50 migracji. Sprawdzono kolumny, constrainty, indeks, istniejące relacje
  kategorii oraz pusty stan biznesowy przed testami.
- Testy celowane po korekcie:
  `scripts/compose-test run --build --rm backend-test php artisan test --compact --do-not-cache-result tests/Feature/AdministrationAccessTest.php tests/Feature/RawMaterialCapabilitiesApiTest.php`
  — **30 passed, 292 assertions**.
- Pełna regresja PA-03/RAW/RBAC:
  `scripts/compose-test run --build --rm backend-test php artisan test --compact --do-not-cache-result tests/Feature/AdministrationAccessTest.php tests/Feature/RawMaterialCapabilitiesApiTest.php tests/Feature/RawMaterialsTest.php tests/Feature/AuthorizationMutationBoundaryTest.php tests/Feature/SecurityApiTest.php tests/Feature/AdministratorAuthorizationTest.php tests/Feature/RoleAdministrationTest.php tests/Feature/PersonnelAdministrationTest.php tests/Feature/BusinessRoleDefinitionTest.php`
  — **221 passed, 2 620 assertions, 0 failed**.
- PHP Pint — **PASS**, wszystkie 11 zmienionych plików backendowych.
- Pierwszy pełny przebieg wykazał 219 testów zaliczonych i 2 błędy testowe
  (2 599 asercji), bez potwierdzonego defektu aplikacji:
  - test hierarchy PATCH oczekiwał 200 przy próbie zmiany `parent_id`, ale API
    poprawnie zwróciło 422 z komunikatem `Nieznane pola: parent_id.`. Test
    zmieniono tak, by asercyjnie potwierdzał 422 i niezmienionego rodzica;
  - w teście zgodności RAW przełączenie z administratora na stewarda w tym
    samym teście zwróciło 401. Helper testowy otrzymał
    `Auth::forgetGuards()` i `$this->flushSession()` przed `actingAs`, zgodnie
    ze sprawdzonym wzorcem izolacji sesji.
- Nie zmieniono middleware, autoryzacji produkcyjnej ani RBAC. W logach
  `testing.ERROR` występują oczekiwane odmowy z testów negatywnych; końcowy
  wynik runnera potwierdza brak niepowodzeń.

## Ryzyka i nierozwiązane kwestie

- Nie wykonano testów na Stage/production i nie weryfikowano deploymentu; nie
  należały do zakresu.
- Testy potwierdziły API, migrację i istniejącą kompatybilność RAW wyłącznie w
  lokalnym środowisku disposable; nie zastępuje to późniejszej akceptacji
  właściciela ani runtime smoke testów po odrębnie zatwierdzonym wdrożeniu.
- Nie przesłano raportu na Google Drive: w dostępnych narzędziach nie ma
  autoryzowanej funkcji uploadu Drive. Raport pozostaje lokalnie.

## Git, commit i gotowość

- Branch: `main`, bazowy HEAD pozostaje `f05ab9d8c2f8fdeefe304f8924ef34b6d8363b59`.
- Commit PA-03 zostanie utworzony lokalnie po końcowym sprawdzeniu formatowania
  i diffu; hash oraz końcowy stan working tree zostaną dopisane po utworzeniu.
- Nie wykonano push, deploymentu ani modyfikacji Stage/production.
- **Gotowość PA-04: nie rozpoczynać** przed akceptacją PA-03 przez właściciela.
