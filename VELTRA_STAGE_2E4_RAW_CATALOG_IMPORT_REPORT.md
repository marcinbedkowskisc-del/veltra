# VELTRA 2.0 — Raport Stage 2E.4: import katalogu RAW z CSV

**Status:** implementacja backendowa i dozwolona weryfikacja zakończone  
**Środowisko:** wyłącznie DEVELOPMENT; usługi disposable PostgreSQL 17.11, Redis i ClamAV  
**Zakres:** import katalogu RAW v1; bez implementacji INT/PRD/MEN, aktywacji modułów, wdrożeń ani zmian produkcyjnych

## 1. Wynik

Stage 2E.4 dostarcza osobny, tenant-scoped przepływ podglądu i jawnego zatwierdzenia importu katalogu RAW. Zachowuje przekazane SKU, używa `SkuAllocationService::reserveImported()`, tworzy nowe rekordy jako szkice i zapisuje historię importu append-only. Import cenowy Stage 2E.1–2E.2 oraz dwukolumnowy eksport Stage 2E.3 pozostały odrębnymi, niezmienionymi formatami.

Wymagany test migracji 034→035 **przeszedł** na izolowanej bazie disposable z reprezentatywnymi danymi historycznymi. Pełna dozwolona regresja Stage 2E.4 zakończyła się wynikiem **226 testów i 1929 asercji — PASS**. Dokładnie 17 ograniczonych testów Stage 2C wyłączono; nie wykonano Stage 2C security acceptance.

## 2. Kontrakt CSV RAW v1

Plik jest kodowany w UTF-8, używa separatora `;`, standardowego cytowania CSV i dokładnego nagłówka w podanej kolejności:

```csv
sku;entity_type;name;description;base_unit_code;category_code
RAW-001042;RAW;"Mąka pszenna, typ 650";"Mąka do pieczywa; przechowywać sucho";g;GRAINS
RAW-001043;RAW;Olej rzepakowy;;ml;OILS
```

Wartości w przykładzie są ilustracyjne. `sku` musi mieć format `RAW-` i 6–9 cyfr. `entity_type` musi być dokładnie `RAW`. `name` jest wymagana, zachowywana bez normalizacji, a jej limit wynosi 200 znaków. `description` jest opcjonalna; puste pole oznacza `NULL`. `base_unit_code` przyjmuje `mg`, `g`, `kg`, `ml`, `l` lub `pc`. `category_code` musi wskazywać istniejącą kategorię bieżącej organizacji; import nie tworzy kategorii.

Parser odrzuca błędne UTF-8, inny nagłówek lub kolejność kolumn, nieprawidłową liczbę pól, błędne cytowanie i powtórzone SKU. Limity są konfigurowalne; wartości domyślne: 5 MiB, 5000 wierszy danych i 30 minut ważności podglądu.

## 3. Zachowanie, autoryzacja i integralność

- Podgląd obejmuje pełny plik i raportuje wynik wierszy jako `UTWORZENIE`, `BEZ ZMIAN`, `KONFLIKT` lub `BŁĄD`. Konflikt lub błąd blokuje zatwierdzenie całego wsadu.
- Zajęte SKU z identycznym typem i atrybutami daje `BEZ ZMIAN`; inne atrybuty lub typ oznaczają konflikt. Nie ma upsertu ani usuwania/archiwizowania pozycji nieobecnych w późniejszym pliku.
- Commit wymaga jawnego potwierdzenia, klucza idempotencji i ponownej walidacji podglądu/snapshotu. Rezerwacja SKU, rekord, rewizja, historia i audyt są atomowe.
- Workflow zachowuje izolację organizacji, membership/kontekst lokalizacji, istniejące uprawnienia, podpisany zakres mutacji, audyt i bramkę gotowości. `RAW_MATERIALS NOT_READY` zwraca HTTP 503.
- Nowe RAW powstają jako `DRAFT` z niezatwierdzoną rewizją i stanem alergenów `UNKNOWN`. Import nie aktywuje ani nie zatwierdza rewizji, nie deklaruje zweryfikowanych alergenów, nie tworzy atestacji dostawcy ani cen referencyjnych.
- Plik źródłowy i podgląd są prywatne. Endpointy nie udostępniają publicznego URL.

## 4. Test migracji 034→035

Test wykonano przed dodaniem migracji 036, na osobnej, disposable bazie PostgreSQL 17.11. Zestaw danych obejmował historyczne kody SUR, rewizje, liczniki i historię importu cenowego.

| Weryfikacja | Wynik |
|---|---|
| Zachowanie istniejących kodów `SUR` | PASS — kody i ich historyczne referencje pozostały bez zmian. |
| Backfill przypisań SKU | PASS — rekordy historyczne otrzymały przypisania `LEGACY`. |
| Ograniczenia tenantowe i niezmienność historii | PASS — zachowano tenantową unikalność i append-only historię. |
| Zgodność importu cenowego | PASS — istniejące ceny nadal dopasowują się po dokładnym SKU SUR. |
| Liczniki i nowa alokacja | PASS — liczniki RAW/INT/PRD/MEN utworzono, stan licznika SUR zachowano, a alokacja RAW po migracji działała. |
| Testy alokatora i regresji historii cen | **2 testy, 44 asercje — PASS** |

Test nie korzystał z aplikacyjnej ani produkcyjnej bazy danych.

## 5. Środowisko i izolacja

Testy uruchomiono w dedykowanej sieci Docker `internal=true` z PostgreSQL 17.11, Redis i ClamAV. PostgreSQL i runner nie miały mountów ani opublikowanych portów; Redis używał wyłącznie własnego, dedykowanego wolumenu testowego. ClamAV nie miał mountów ani opublikowanych portów. Usługi testowe nie współdzieliły danych ani wolumenów z aplikacją. Nie modyfikowano istniejących kontenerów, baz ani wolumenów aplikacyjnych.

## 6. Wyniki testów i kontroli

| Zakres | Wynik |
|---|---|
| Test migracji 034→035, alokatora i historii cen | 2 testy, 44 asercje — PASS |
| Testy katalogu RAW oraz zachowanego eksportu Stage 2E.3 | 13 testów, 132 asercje — PASS |
| Współbieżna rezerwacja/alokacja SKU | 1 test, 10 asercji — PASS |
| Pełna dozwolona regresja Stage 2E.4 | **226 testów, 1929 asercji — PASS** |
| Ograniczone testy Stage 2C | Dokładnie 17 wyłączono i nie uruchomiono |
| Stage 2C security acceptance | Nie wykonano |

Scenariusze obejmowały początkowy import, co najmniej 375 rekordów, duplikaty, brak SKU, niepoprawną kategorię, identyczny rekord, konflikt, rollback, idempotentne ponowienie, zmianę danych między podglądem a commit, izolację tenantów, readiness 503, zachowanie niezatwierdzonego stanu alergenów, import cenowy oraz współbieżną alokację.

**Kontrole końcowe:** PHP lint — PASS; Pint — PASS; `git diff --check` — PASS.

## 7. Zmienione pliki

- `backend/app/Domain/RawMaterials/RawMaterialCatalogCsvParser.php` — ścisłe parsowanie RAW CSV v1.
- `backend/app/Domain/RawMaterials/RawMaterialCatalogCsvImportWorkflow.php` — podgląd, tenant-scoped walidacja, commit, idempotencja, audyt i atomowość.
- `backend/app/Domain/RawMaterials/RawMaterialService.php` — tworzenie szkicu RAW z importowanym SKU i niezatwierdzoną rewizją.
- `backend/app/Domain/Sku/SkuAllocationService.php` — spójna kolejność blokad dla rezerwacji i alokacji współbieżnej.
- `backend/app/Http/Controllers/Api/V1/RawMaterialCatalogCsvImportController.php` oraz `backend/app/Http/Requests/RawMaterialCatalogCsvUploadRequest.php` — API importu.
- `backend/routes/api.php` — trasy podglądu, zatwierdzenia i wyniku.
- `backend/database/migrations/2026_11_06_000036_create_raw_material_catalog_csv_import_history.php` — append-only historia batchy i wierszy RAW.
- `backend/config/raw_material_csv.php` — limity pliku, wierszy i czasu podglądu.
- `backend/bootstrap/app.php` — obsługa/redakcja błędów.
- `backend/tests/Feature/RawMaterialsTest.php`, `backend/tests/Feature/RawMaterialConcurrencyTest.php` oraz `backend/tests/Support/concurrent_raw_material_sku_reservation.php` — testy regresji, importu i współbieżności.
- `VELTRA_CSV_CATALOG_IMPORT_CONTRACT_PROPOSAL.md` — zaktualizowany kontrakt wdrożonego RAW v1; INT/PRD/MEN pozostają niewdrożone.
- `VELTRA_STAGE_2E_EXTENDED_CSV_EXCHANGE_DESIGN.md` — oznaczenie architektury jako częściowo wdrożonej i aktualizacja statusu migracji/testów.

## 8. Ograniczenia i dalsza praca

- Import katalogowy obsługuje tylko RAW. Alokator przewiduje liczniki INT/PRD/MEN, ale te domeny i ich importy nie są zaimplementowane.
- Rozszerzony eksport katalogu nie jest częścią Stage 2E.4. Istniejący eksport Stage 2E.3 nadal ma dokładnie dwie kolumny `ingredient_id;ingredient_name`.
- Ceny referencyjne, VAT i jednostka wejściowa pozostają wyłącznie w dotychczasowym, sześciokolumnowym imporcie cenowym. Import katalogu nie obejmuje opakowań, produktów dostawcy, ofert ani cen.
- Nie uruchomiono żadnego z 17 ograniczonych testów Stage 2C; wynik nie stanowi Stage 2C security acceptance.
- `RAW_MATERIALS` i `VSEF` nie zostały aktywowane. Nie wykonano wdrożenia, commitów ani pushy.

## 9. Weryfikacja kopii raportu

Raport przesłano do istniejącego folderu Google Drive `RAPORTY_COPILOTA`. Otwarta kopia zawiera nagłówek raportu, wyniki migracji 034→035, pełną dozwoloną regresję (226 testów, 1929 asercji), przypadki testowe i dalsze ograniczenia. Link do kopii: https://drive.google.com/file/d/1Zu3jbepJ2E-0AUBdhc8AHNlz_jaaWdrL/view
