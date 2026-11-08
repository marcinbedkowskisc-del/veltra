# VELTRA 2.0 — Stage 2F — audyt zgodności backendu RAW

**Data audytu:** 2026-11-08  
**Tryb:** wyłącznie odczyt; bez implementacji, migracji, testów wykonawczych, zmian infrastruktury ani aktywacji modułów.  
**Specyfikacja:** [zatwierdzony snapshot decyzji 1–100](https://docs.google.com/document/d/1NECl8Hqq_auHzST6kJ-rovgSuMm30JhbxFWpx7ZIamM/edit). Odczytano całą treść dokumentu, łącznie z decyzjami 1–100, ryzykami K1–K17 i sekcjami architektonicznymi.

## Podsumowanie wykonawcze

Backend ma już znaczący fundament RAW: organizacyjne granice danych, wersjonowane i niezmienne rewizje, przepływ DRAFT → VERIFICATION → ACTIVE, oddzielne uprawnienia do cen zakupu i referencyjnych, historię cen, wersjonowane alergeny i przechowywanie, archiwizację, import/eksport CSV oraz chroniony i współbieżny allocator SKU.

Nie jest to jednak zgodna implementacja kontraktu UI/UX 1–100. Najistotniejsze rozbieżności lub braki kontraktu backendowego:

1. SKU jest przydzielane dopiero podczas utworzenia szkicu, w transakcji tworzenia; nie istnieje rezerwacja przy otwarciu formularza ani gwarancja trwałego zużycia numeru po jego porzuceniu. Generator dopuszcza do 999 999 999, a kontrakt wskazuje limit 999 999.
2. Katalog API nie zwraca wymaganych ilości, ceny i kosztu; nie obsługuje filtrów dostawcy/jednostki, sortowania po nazwie jako domyślnego ani żądanego zestawu stron 25/50/100/200. Zapytanie listy jawnie wyklucza rekordy bez zatwierdzonej rewizji, więc szkice DRAFT nie są listowane.
3. Model RAW nie zawiera ilości nominalnej/użytkowej ani uzysku. Nie ma backendowego obliczenia kosztu jednostki użytkowej ani zakresu walidacji uzysku.
4. Dane i uprawnienia RAW są organizacyjne, nie lokalizacyjne; nie ma jawnego właściciela lokalizacji ani modelu udostępniania materiałów z kuchni centralnej. To pozostawia K3 nierozstrzygnięte wobec modelu katalogu lokalnego/współdzielonego.
5. Istnieją uprawnienia `RAW_MATERIALS_REFERENCE_PRICE_MANAGE`, ale w ścieżkach zapisu/eksportu nie ma jawnego sprawdzenia roli `ADMINISTRATOR`. RAW ma własny zestaw uprawnień, niezależny od ogólnych `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW`; wymagane mapowanie ról nie jest potwierdzone.
6. Są zapisy audytowe i endpoint listy rewizji, ale brak odczytowego endpointu pełnej historii `raw_material_audit_events`; odpowiedź kartoteki nie zwraca autora ani czasu ostatniej aktualizacji.
7. Rewizje są tworzone transakcyjnie i historycznie niezmienne, ale żądanie edycji nie przekazuje oczekiwanej rewizji bazowej. Nie potwierdzono serwerowego konfliktu dla nieaktualnego formularza.
8. Istnieje serwis dokumentów z prywatnym przechowywaniem, skanowaniem i niezmienną historią wersji, lecz nie znaleziono tras HTTP RAW do jego wywołania. Brakuje tymczasowych uploadów, API listowania/pobierania/podglądu oraz kontrolowanego odłączenia dokumentu od bieżącej rewizji.
9. Fizyczne usunięcie szkicu nie jest dostępne. Nie znaleziono serwerowego kontraktu kompletności, który zwracałby rozdzielone błędy zapisu i braki do aktywacji.
10. Definicja produkcyjna `RAW_MATERIALS` ma `ready: false` (wartość domyślna), a bramka API i serwis konfiguracji respektują NOT_READY. Nie odczytywano istniejącej bazy ani jej stanu konfiguracji.

**Rekomendacja:** **CONDITIONAL GO wyłącznie do planowania implementacji.** Przed planowaniem kontraktów i zakresu należy rozstrzygnąć własność/lokalizację danych, mapowanie administratora i uprawnień finansowych, zakres kompletności aktywacji oraz politykę SKU. **NO-GO dla implementacji lub aktywacji `RAW_MATERIALS` na podstawie samego zamknięcia pytań UX.** Specyfikacja jawnie nie autoryzuje zmian ani aktywacji.

## Metoda i zakres dowodów

- Przeczytano cały zatwierdzony snapshot Google Docs przed rozpoczęciem porównania.
- Ocenę oparto na bieżącym kodzie, trasach, walidacji, schematach migracji i testach; archiwalne raporty projektowe nie stanowiły dowodu implementacji.
- Nie uruchamiano testów ani komend zmieniających stan, nie łączono się z bazą, nie odczytywano sekretów i nie uruchamiano kontenerów.
- Repozytorium zawierało już zmiany Stage 2B–2E w katalogu roboczym. Nie modyfikowano ich; jedynym plikiem utworzonym w ramach tego audytu jest niniejszy raport.
- Audyt dotyczy zgodności **backendu**. Klasyfikacja FRONTEND ONLY oznacza, że zachowanie należy do warstwy UI i nie wymaga odrębnego kontraktu backendowego; nie jest potwierdzeniem, że zachowanie istnieje w kodzie frontendu.

## Inwentarz bieżącej implementacji

### Model RAW i rewizje

- [RawMaterialService](./backend/app/Domain/RawMaterials/RawMaterialService.php): katalog, szczegóły, tworzenie/rewidowanie, wysyłanie do weryfikacji, zwrot do zmian, zatwierdzanie, blokowanie, archiwizacja i przywracanie.
- [RawMaterialDataRequest](./backend/app/Http/Requests/RawMaterialDataRequest.php), [ListRawMaterialsRequest](./backend/app/Http/Requests/ListRawMaterialsRequest.php) i żądania Stage 2D: walidacja danych, filtrów, alergenów i wariantów przechowywania.
- Migracja [011 — model RAW](./backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php): tożsamość, stan lifecycle, rewizje, jednostki, aliasy, audyt oraz klucze złożone ograniczające rekordy do organizacji.
- Rewizja przechowuje nazwę, opis, kategorię, jednostkę bazową, jednostki/konwersje, aliasy i stan alergenów. Nie znaleziono pól ilości nominalnej/użytkowej, uzysku ani lokalizacyjnego właściciela materiału.
- Migracje [028 — alergeny i przechowywanie](./backend/database/migrations/2026_11_06_000028_create_raw_material_revision_allergen_and_storage_data.php) i [033 — podpisane mutacje Stage 2D](./backend/database/migrations/2026_11_06_000033_enforce_signed_stage2d_revision_mutations.php) chronią deklaracje i warianty rewizji.

### API, autoryzacja i ceny

- [Trasy RAW API](./backend/routes/api.php) obejmują listę/szczegóły, rewizje, lifecycle, kategorie, dostawców/produkty/oferty, ceny referencyjne, alergeny, przechowywanie oraz importy/eksporty CSV.
- Cała grupa tras RAW wymaga sesji SPA, Sanctum, zweryfikowanego kontekstu tożsamości i operacyjnego, limitu żądań oraz [RequireRawMaterialsAvailability](./backend/app/Http/Middleware/RequireRawMaterialsAvailability.php).
- [RawMaterialAuthorization](./backend/app/Domain/RawMaterials/RawMaterialAuthorization.php) sprawdza aktywność i weryfikację użytkownika, aktywność organizacji/lokalizacji, członkostwo w kontekście i skuteczne uprawnienie. Odczyty danych filtrują po `organization_id`; nie filtrują po właścicielu lokalizacji.
- [RawMaterialPermission](./backend/app/Domain/RawMaterials/RawMaterialPermission.php) rozdziela `PurchasingCostsView/Manage` od `ReferencePriceView/Manage`. [RawMaterialCommercialService](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php) dodatkowo ukrywa źródło ceny zakupu przy odczycie ceny referencyjnej bez uprawnienia kosztowego; test potwierdza to w `test_reference_price_view_does_not_disclose_purchasing_source_without_cost_permission`.
- Cena zakupu dostawcy i cena referencyjna są odrębnymi strumieniami zdarzeń. Ceny przechowują wartości `numeric`, konwersje opierają się o rewizję produktu i ilość bazową, a historia jest append-only. Import cen ma test obejmujący wyłącznie rzeczywiste zmiany; zachowanie ręcznego endpointu dla ponownego ustawienia identycznej ceny pozostaje niezweryfikowane.

### SKU, CSV i dokumenty

- [SkuAllocationService](./backend/app/Domain/Sku/SkuAllocationService.php) blokuje organizację/licznik, przydziela unikalne numery i zapisuje niezmienne przypisania; [SkuPrefix](./backend/app/Domain/Sku/SkuPrefix.php) obsługuje RAW/INT/PRD/MEN.
- Migracja [035 — allocator SKU](./backend/database/migrations/2026_11_06_000035_standardize_business_sku_allocation.php) ustanawia osobne liczniki i niezmienne przypisania, zachowując `SUR` jako format historyczny. Unikalność jest ograniczona do organizacji, nie do całej bazy.
- Importy i eksporty są osobnymi przepływami. [RawMaterialCsvCatalogExportWorkflow](./backend/app/Domain/RawMaterials/RawMaterialCsvCatalogExportWorkflow.php) ma stałe profile katalogu, katalogu rozszerzonego i cen referencyjnych; eksport jest ograniczony do aktywnych zatwierdzonych rekordów, audytowany i ponownie autoryzowany przy pobraniu.
- [RawMaterialDocumentService](./backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php) przechowuje pliki prywatnie, przypisuje wersję do rewizji, weryfikuje sumę kontrolną, uruchamia skanowanie i pozostawia niezmienne linki/zdarzenia. W [api.php](./backend/routes/api.php) nie znaleziono tras HTTP dokumentów.

### Dostępność modułu i architektura

- [DefinedModuleCatalog](./backend/app/Domain/Modules/DefinedModuleCatalog.php) deklaruje `RAW_MATERIALS` bez `ready: true`; [ModuleDefinition](./backend/app/Domain/Modules/ModuleDefinition.php) domyślnie ustawia `ready: false`.
- [ConfiguredModuleAvailability](./backend/app/Domain/Authorization/ConfiguredModuleAvailability.php) wymaga gotowości, zgodności typu lokalizacji, konfiguracji `enabled` i gotowości zależności. [ModuleConfigurationService](./backend/app/Domain/Modules/ModuleConfigurationService.php) odrzuca włączenie modułu NOT_READY.
- Test [RawMaterialStage2dApiTest](./backend/tests/Feature/RawMaterialStage2dApiTest.php), `test_http_endpoints_return_not_ready_without_bypassing_feature_gate`, potwierdza 503 dla niezgotowego RAW w kontrolowanej konfiguracji testowej.
- Model wdrożenia w [ADR-001](./VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md) wymaga odrębnej instancji/bazy/plików dla klienta, a równocześnie zachowuje kontrolę organizacji i lokalizacji wewnątrz instancji. Kod używa `organization_id`, lecz nie rozstrzyga dodatkowego zakresu właściciela restauracyjnego i udostępniania z kuchni centralnej.

## Macierz zgodności decyzji 1–100

Skróty dowodów w tabeli wskazują konkretne pliki/metody/API. „UI” oznacza zachowanie prezentacyjne, które nie wymaga zmiany backendu.

| # | Wymaganie zatwierdzone | Klasyfikacja | Dowód backendowy / ustalenie |
|---:|---|---|---|
| 1 | Wiersz otwiera prawy panel; pełna kartoteka osobno | FRONTEND ONLY | GET `/api/v1/raw-materials/{material}` przez `RawMaterialController::show` i `RawMaterialService::show`; payload szczegółów istnieje, układ panelu należy do UI. |
| 2 | Stały zestaw kolumn, bez konfiguracji użytkownika | FRONTEND ONLY | Tabela jest wymaganiem widoku. Uwaga: backend nie dostarcza wszystkich danych z decyzji 4; patrz #4. |
| 3 | Szukanie nazwy/SKU; filtry kategorii, dostawcy, statusu i jednostki | BACKEND CHANGE REQUIRED | `ListRawMaterialsRequest` i `RawMaterialService::list` mają szukanie, kategorię i status; brak filtrów dostawcy i jednostki. |
| 4 | Kolumny obejmują SKU, jednostkę, nominalną/użytkową ilość, cenę i koszt | BACKEND CHANGE REQUIRED | `RawMaterialService::list` zwraca kod, nazwę, kategorię, bazową jednostkę i stan. Migracje 011/013 nie definiują ilości nominalnej/użytkowej ani uzysku; kosztu nie ma w payloadzie listy. |
| 5 | Cena netto jest za kupowane opakowanie; bez fikcyjnego drugiego opakowania/ceny i bez ikony SKU w tabeli | BACKEND SUPPORTED | `supplier_offer_events.net_amount_per_purchase_unit` w migracji 013 opisuje cenę jednostki zakupu; UI nie powinno dodawać osobnego „drugiego opakowania”. |
| 6 | Cena referencyjna jest zewnętrzna, wprowadzana/importowana, nie jest ceną konkretnego dostawcy | BACKEND SUPPORTED | Osobne `raw_material_reference_price_events` i `supplier_offer_events` (migracja 013); `RawMaterialCommercialService::setReferencePrice` dopuszcza źródło oferty jako pochodzenie, nie jako tożsamość ceny referencyjnej. |
| 7 | Ręczne i CSV wprowadzanie ceny z zachowaniem historii | BACKEND SUPPORTED | POST `/reference-prices`, POST `/imports` i `/imports/{preview}/commit`; append-only ceny, test `test_stage2e7_integrated_catalog_pricing_and_as_of_exports`. |
| 8 | Historia tylko dla rzeczywistych zmian; audyt importu także bez zmiany wartości | NOT VERIFIED | `RawMaterialCsvImportPersistenceService::compareWithHistory` i test `test_csv_import_records_metadata_baseline_and_only_genuine_history_changes` pokrywają import. Nie znaleziono potwierdzenia, że ręczny `setReferencePrice` odrzuca identyczną wartość. |
| 9 | Miniwykres ostatnich 6 miesięcy | FRONTEND ONLY | GET `/{material}/reference-prices` zwraca historię i obsługuje datę `as_of`; wybranie zakresu sześciu miesięcy i wykres są prezentacją. |
| 10 | Wyłącznie ADMINISTRATOR zmienia/importuje cenę referencyjną | BACKEND CHANGE REQUIRED | Zapis wymaga `ReferencePriceManage`, import wymaga własnych grantów RAW; w `setReferencePrice`, `RawMaterialCsvImportWorkflow` i trasach nie ma jawnego sprawdzenia `BusinessRole::Administrator`. |
| 11 | Cena zmienia się po zapisie, bez osobnego workflow zatwierdzania | BACKEND SUPPORTED | `setReferencePrice` dodaje zdarzenie po autoryzacji i walidacji współbieżności, bez dodatkowego workflow akceptacji. Ograniczenie do administratora pozostaje luką #10. |
| 12 | Poprzedni pomysł osobnego zatwierdzania ceny jest zastąpiony | BACKEND SUPPORTED | Nie ma osobnej akceptacji ceny referencyjnej; historia ceny jest rejestrowana przez endpoint zdarzeń. |
| 13 | Ilość użytkowa domyślnie równa nominalnej; ręczna korekta | BACKEND CHANGE REQUIRED | Brak pól tych ilości w `RawMaterialDataRequest`, `RawMaterialService::presentMaterial` i schemacie rewizji. |
| 14 | Koszt na 100 g/100 ml/szt. zależnie od właściwej jednostki | BACKEND CHANGE REQUIRED | Wartość referencyjna jest przechowywana na jednostkę bazową, ale model nie ma ilości użytkowej ani funkcji kosztu w przeliczeniu na 100 g/100 ml/szt. |
| 15 | UI pokazuje 2 miejsca, backend liczy z większą precyzją | BACKEND SUPPORTED | Ceny i konwersje używają `numeric`, a normalizacja `raw_materials_round_half_even_v1` obsługuje precyzję do 14 miejsc. Zaokrąglenie prezentacyjne należy do UI. |
| 16 | Brak edycji inline; edycja wyłącznie w pełnoekranowym edytorze | FRONTEND ONLY | API rozdziela GET szczegółów od PATCH `/draft`; sposób ich wywołania należy do UI. |
| 17 | Niekompletny rekord może być zapisany jako DRAFT przy spełnieniu minimum; aktywacja według backendu | BACKEND SUPPORTED | POST `/api/v1/raw-materials` tworzy DRAFT i waliduje minimum: kategoria, nazwa, jednostka. Zastrzeżenie: rekordy bez zatwierdzonej rewizji nie trafiają do listy (`RawMaterialService::list`). |
| 18 | Kopiowanie wybranych sekcji do nowego rekordu, nowego SKU, bez historii i z ponowną deklaracją alergenów | FRONTEND ONLY | POST `/raw-materials` tworzy nową tożsamość i rewizję; alergen jest oddzielnym endpointem rewizji. Nie ma dedykowanego endpointu klonowania; UI może przekazać wybrane pola i wymusić ponowną attestację. |
| 19 | Archiwizacja; usuwanie tylko szkicu nigdy niezatwierdzonego bez zależności; przywracanie kontrolowane | BACKEND CHANGE REQUIRED | Archiwizacja/przywracanie istnieje; nie ma DELETE szkicu ani kontroli zależności na ścieżce usuwania. |
| 20 | Archiwum oglądane wyłącznie w osobnym module; archiwizacja nie przenosi rekordu | FRONTEND ONLY | Archiwizacja zmienia lifecycle na `ARCHIVED`; filtr listy akceptuje `ARCHIVED`. Osobne menu/moduł jest UI, ale backend nie ustanawia osobnej granicy widoczności. |
| 21 | Domyślnie nazwa A–Z; sort globalny po nagłówku | BACKEND CHANGE REQUIRED | `RawMaterialService::list` domyślnie sortuje `code`; dozwolone są `code`, `name`, `lifecycle_state`. Brak sortowania dla pozostałych wymaganych kolumn. |
| 22 | Rozmiary 25/50/100/200, domyślnie 50 | BACKEND CHANGE REQUIRED | `ListRawMaterialsRequest` pozwala na dowolne 1–100, a `RawMaterialService::list` domyślnie używa 20; 200 jest odrzucane. |
| 23 | Zaznaczenie wielu rekordów; eksport i archiwizacja zbiorcza | BACKEND CHANGE REQUIRED | Eksporty mają stałe profile dla całego katalogu; archiwizacja przyjmuje jeden `material` na żądanie. Brak wejścia dla ID zaznaczenia i endpointu operacji zbiorczej. |
| 24 | Zaznaczenie trwa między stronami; „zaznacz wszystkie” dotyczy strony | FRONTEND ONLY | Zachowanie stanu zaznaczenia jest prezentacyjne; API zwraca paginację. |
| 25 | Pływający pasek akcji zbiorczych | FRONTEND ONLY | Wyłącznie układ i interakcja UI; operacje zbiorcze backendowo nie są obecnie dostępne (patrz #23). |
| 26 | Przypięty nagłówek panelu, zwijane sekcje | FRONTEND ONLY | GET szczegółów istnieje; układ i przewijanie należą do UI. |
| 27 | Zmiana rekordu w panelu według bieżących filtrów/sortu | BACKEND CHANGE REQUIRED | API listy nie obsługuje filtrów dostawcy/jednostki, a sortowanie ogranicza się do trzech pól; kontekst nawigacji nie jest pełnym katalogowym kontraktem. |
| 28 | Edytor uruchamiany z panelu lub menu; obowiązuje RBAC | BACKEND SUPPORTED | Mutacje przez osobne trasy i `RawMaterialAuthorization::assertAllowed`; UI może uruchamiać je z obu miejsc. |
| 29 | Po zapisie powrót do dokładnego kontekstu katalogu | FRONTEND ONLY | Stan strony/filtrów/scrolla/panelu nie jest stanem backendowej mutacji. |
| 30 | Niezależne przewijanie katalogu/panelu, przypięty nagłówek | FRONTEND ONLY | Zachowanie layoutu. |
| 31 | Trzy szerokości panelu zapamiętane dla użytkownika | FRONTEND ONLY | Preferencja UI; brak potrzeby API. |
| 32 | Sekcje alergenów i przechowywania otwarte domyślnie, wykres w panelu | FRONTEND ONLY | Dane pochodzą z API Stage 2D i historii cen; ustawienie domyślnego widoku jest UI. |
| 33 | Wykres po podstawach, przed alergenami | FRONTEND ONLY | Kolejność sekcji UI. |
| 34 | Wykres 6 miesięcy z tooltipem daty/ceny | FRONTEND ONLY | Endpoint ceny zwraca datowane zdarzenia; tooltip i ograniczenie okresu to UI. |
| 35 | Alergeny jako dwa bloki tekstu, stan „może zawierać”, ostrzeżenie niepełnej deklaracji | BACKEND SUPPORTED | Request i tabela deklaracji rozróżniają `DIRECT`/`CROSS_CONTACT_POTENTIAL`, `UNKNOWN`, `DECLARED`, `NO_KNOWN_ALLERGENS`; UI renderuje bloki i ostrzeżenie. |
| 36 | Trzy presety przechowywania; temperatury są propozycją, nie uniwersalnym wymogiem | FRONTEND ONLY | `RawMaterialStorageVariantsRequest` i migracja 028 przyjmują jawne zakresy; wartości presetów nie są twardo wpisane jako reguły produktowe backendu. |
| 37 | Centralna biblioteka dostawców, wielu dostawców na surowiec | BACKEND SUPPORTED | `organization_suppliers` jest wspólne dla organizacji, a `supplier_products` wiąże wiele produktów/dostawców z materiałem; endpointy `suppliers` i `supplier-products`. Zasięg lokalny/centralny wymaga rozstrzygnięcia K3. |
| 38 | Wiele załączników, wieloplikowy uploader bez sztywnego małego limitu | BACKEND CHANGE REQUIRED | Serwis dokumentów przyjmuje pojedynczy plik na wywołanie, limituje go do 20 MiB i nie ma tras HTTP RAW. |
| 39 | Import w osobnym module; eksport w katalogu | FRONTEND ONLY | Backend ma odrębne endpointy `/imports`, `/catalog-imports` i `/catalog-exports`; lokalizacja modułów jest UI. |
| 40 | Ręczny wybór kolumn eksportu z kontrolą uprawnień | BACKEND CHANGE REQUIRED | `RawMaterialCsvCatalogExportWorkflow` obsługuje stałe profile i stałe zestawy kolumn; brak parametru kolumn/whitelisty dynamicznej. |
| 41 | Domyślne kolumny tabeli zaznaczone; bez zapamiętywania szablonów | FRONTEND ONLY | Stan wyboru kolumn UI. |
| 42 | Pełna kartoteka tylko do odczytu, osobny pełny edytor | FRONTEND ONLY | API oferuje odczyt GET i mutacje osobnymi metodami; semantyka ekranów należy do UI. |
| 43 | Kartoteka: produkcja/zakupy w dwóch kolumnach, pionowo na małym ekranie | FRONTEND ONLY | Układ UI; dane kosztowe mają osobne endpointy/uprawnienia. |
| 44 | Ilość nominalna/użytkowa i procent uzysku w kartotece | BACKEND CHANGE REQUIRED | Brak tych pól w rewizji, walidacji i odpowiedzi `presentMaterial`; nie ma obliczenia uzysku. |
| 45 | Pełne historie w module Historia i audyt; bez alternatywnego silnika historii | BACKEND CHANGE REQUIRED | Rewizje i zdarzenia zatwierdzeń mają endpoint, lecz brak odczytowego endpointu `raw_material_audit_events`; znaleziono wyłącznie zapis audytu. |
| 46 | Czas/autor ostatniej aktualizacji i link do historii | BACKEND CHANGE REQUIRED | `raw_materials` ma `updated_at`, lecz brak `updated_by`; `presentMaterial` nie zwraca tych pól. Brak pełnego endpointu historii. |
| 47 | „+ Dodaj surowiec” w prawym górnym rogu nagłówka | FRONTEND ONLY | Pozycja przycisku UI; POST tworzenia istnieje. |
| 48 | Dwa rzędy nagłówka: dodawanie, potem szukanie/filtry/eksport | FRONTEND ONLY | Kompozycja UI; eksport i część filtrów backendowo ograniczone (patrz #3/#40). |
| 49 | Aktywne filtry widoczne w polach, „Wyczyść filtry” | FRONTEND ONLY | Prezentacja stanu filtrów; backend przyjmuje część filtrów. |
| 50 | Debounce ~300 ms, nowe zapytanie od strony 1 | FRONTEND ONLY | Czas i reset paginacji należą do UI; backend obsługuje parametr `search`. |
| 51 | Fragment nazwy/SKU, bez rozróżniania wielkości liter; bez fuzzy search | BACKEND SUPPORTED | `RawMaterialService::list` używa fragmentowego wyszukiwania nazwy, kodu i aliasu bez fuzzy matching. |
| 52 | Pusty wynik: komunikat i czyszczenie wyszukiwania/filtrów | FRONTEND ONLY | API zwraca `meta.total`; render pustego stanu należy do UI. |
| 53 | Skeleton zamiast starych danych w czasie ładowania | FRONTEND ONLY | Zachowanie pobierania w UI. |
| 54 | Błąd pobierania i retry bez utraty filtrów/sortu/strony | FRONTEND ONLY | Zachowanie UI; API przyjmuje query ponownie. |
| 55 | Zakres i liczba widocznych rekordów obok paginacji | FRONTEND ONLY | API zwraca `current_page`, `per_page`, `total`, `last_page`; zakres można obliczyć po stronie UI. |
| 56 | Przyklejone nagłówki tabeli | FRONTEND ONLY | Układ UI. |
| 57 | Osobne, subtelne zaznaczenie aktywnego wiersza | FRONTEND ONLY | Stan UI, odrębny od checkboxa. |
| 58 | Panel nakłada się na tabelę, bez zwężania | FRONTEND ONLY | Układ UI. |
| 59 | Dyskretny uchwyt otwierania/zamykania panelu | FRONTEND ONLY | Interakcja UI. |
| 60 | Uchwyt w środku krawędzi i animacja 250–300 ms | FRONTEND ONLY | Animacja UI. |
| 61 | Powrót z modułu odtwarza cały kontekst katalogu | FRONTEND ONLY | Trwałość stanu UI; każdy ponowny request nadal podlega autoryzacji i bramce modułu. |
| 62 | Stan katalogu przez sesję/refresh; nowe logowanie zaczyna domyślnie | FRONTEND ONLY | Przechowywanie preferencji/stanu UI. Backend nie może traktować odtworzonego stanu jako autoryzacji. |
| 63 | Toast sukcesu dopiero po odpowiedzi backendu | FRONTEND ONLY | Backend zwraca wynik operacji; timing i wygląd toastu są UI. |
| 64 | Archiwizacja z oknem potwierdzenia; bez „Cofnij” w toaście | BACKEND SUPPORTED | POST `/{material}/archive` wymaga powodu i ogranicza do ACTIVE/BLOCKED; potwierdzenie i brak cofnięcia w toaście są UI. |
| 65 | Ochrona wyjścia z niezapisanymi zmianami | FRONTEND ONLY | Dialog i obsługa lokalnego stanu formularza; endpointy nadal walidują i autoryzują. |
| 66 | Błędy przy polach i w panelu kompletności z nawigacją | FRONTEND ONLY | Błędy walidacji HTTP mogą wskazywać pola; prezentacja/nawigacja należy do UI. Panel kompletności opisuje #76. |
| 67 | Walidacja pola po blur, potem podczas poprawiania; całość przy zapisie | FRONTEND ONLY | Walidacja interakcyjna UI; backend ma walidację żądań. |
| 68 | Gwiazdka pól minimalnych i osobne wymagania aktywacji | BACKEND CHANGE REQUIRED | Minimalne wymagania zapisu są w requestach; brak kanonicznego kontraktu listy braków aktywacji. |
| 69 | Zablokowany przycisk i wskaźnik podczas zapisu | FRONTEND ONLY | Stan oczekiwania UI. |
| 70 | Przyklejony pasek „Zapisz/Anuluj” | FRONTEND ONLY | Układ UI. |
| 71 | Wskaźnik niezapisanych zmian i nieaktywny zapis bez zmian | FRONTEND ONLY | Porównanie lokalnego formularza UI. |
| 72 | Jedna przewijana strona, nawigacja sekcji i aktywna sekcja | FRONTEND ONLY | Nawigacja UI. |
| 73 | Ikony tylko dla błędów/braków aktywacji | FRONTEND ONLY | Oznaczenia UI, oparte o dane walidacyjne. |
| 74 | Przypięty, niezależnie przewijany panel kompletności | FRONTEND ONLY | Układ UI; kontrakt danych kompletności opisuje #76. |
| 75 | Tytuł/status/liczba błędów i braków, bez procentów | BACKEND CHANGE REQUIRED | `show` nie zwraca kanonicznych błędów/braków ani dostępnego następnego kroku; `submit`/`approve` walidują tylko część warunków. |
| 76 | Rozdzielone błędy blokujące zapis i braki wymagane do aktywacji, klikalne do pól | BACKEND CHANGE REQUIRED | Brak wspólnego serwisowego wyniku kompletności. `submit` sprawdza głównie istnienie rewizji roboczej; `approve` sprawdza deklarację alergenów/hash. Szczegółowa lista aktywacyjna wymaga zatwierdzenia biznesowego. |
| 77 | Po kompletności pokazać uprawniony, dostępny następny krok; bez autoaktywacji | BACKEND CHANGE REQUIRED | Workflow submit/approve istnieje i nie autoaktywuje, ale nie znaleziono endpointu zwracającego capabilities/allowed-next-action dla bieżącego użytkownika i stanu. |
| 78 | Nawigacja edytora w grupach Produkcja i Zakupy/administracja | FRONTEND ONLY | Grupowanie sekcji UI; dane zakupowe mają oddzielne endpointy. |
| 79 | Widoczne sekcje z prawem odczytu; brak ukrytych pól w API | BACKEND SUPPORTED | Ogólny `show` nie emituje cen zakupu/referencyjnych; endpointy finansowe mają osobne uprawnienia, a źródło zakupu jest redagowane. Szczegóły alergenów są osobnym endpointem `revisionStage2d` z `AuditView`. |
| 80 | Pola tylko do odczytu jako etykiety, nie wyszarzone inputy | FRONTEND ONLY | Prezentacja UI. |
| 81 | Konflikt rewizji blokuje nadpisanie; pobranie aktualnej wersji z ostrzeżeniem | BACKEND CHANGE REQUIRED | `RawMaterialDataRequest` nie ma `expected_revision_id`/tokenu wersji; `reviseWithPermission` blokuje organizację i zapisuje nową rewizję, ale nie porównuje wersji otwartej przez klienta. |
| 82 | Ctrl+S i Esc z ochroną zmian | FRONTEND ONLY | Skróty klawiaturowe UI. |
| 83 | Po utworzeniu „Zapisz i wróć” lub „Zapisz i dodaj kolejny” | FRONTEND ONLY | POST tworzenia zwraca utworzony rekord; wybór kolejnego widoku jest UI. |
| 84 | Split button dwóch wariantów zapisu, bez zapamiętywania preferencji | FRONTEND ONLY | Interakcja UI. |
| 85 | SKU generowane przez system, bez ręcznej edycji, odrębne od ID | BACKEND SUPPORTED | `RawMaterialService::create` generuje kod przez `SkuAllocationService::allocateNext`; request nie przyjmuje SKU. |
| 86 | SKU widoczne w formularzu przed pierwszym zapisem, przydział backendowy | BACKEND CHANGE REQUIRED | Kod jest generowany dopiero w POST tworzenia; brak endpointu rezerwacji SKU przed zapisem. |
| 87 | Przydzielone SKU nigdy nie wraca do puli po porzuceniu formularza | BACKEND CHANGE REQUIRED | Przydział jest częścią transakcji tworzenia DRAFT; rollback/nieudane tworzenie nie pozostawia trwałej rezerwacji. |
| 88 | Stały format `RAW-000001` i dokładnie sześć cyfr | BACKEND CHANGE REQUIRED | `SkuPrefix::format` dopełnia do 6 cyfr, ale dopuszcza 999 999 999 i format 7–9 cyfr; migracja 035 także dopuszcza do 9 cyfr. |
| 89 | Osobne liczniki RAW/INT/PRD/MEN; unikalne pełne SKU | BACKEND SUPPORTED | Migracja 035 tworzy oddzielne liczniki prefiksów i unikalność `(organization_id, sku)`; `test_sku_prefixes_allocate_independently...` i test współbieżności potwierdzają mechanizm. Zakres unikalności jest organizacyjny. |
| 90 | Numeracja niezależna w instancji klienta, bez generatora globalnego/prefiksu klienta | NOT VERIFIED | Nie ma globalnego generatora; liczniki są per organizacja. ADR-001 nakazuje oddzielną instancję klienta, ale kod nie dowodzi ani nie wymusza, że jedna instancja ma dokładnie jedną organizację. |
| 91 | Kopiowanie SKU z panelu/kartoteki z toastem; bez ikony w tabeli | FRONTEND ONLY | Sam kod SKU jest dostępny w API; kopiowanie do schowka i toast są UI. |
| 92 | Zamknąć UX bez kolejnych pytań i kontynuować Stage 2F | FRONTEND ONLY | Decyzja procesowa; raport weryfikuje kontrakty, nie autoryzuje implementacji. |
| 93 | Usuwanie szkicu z dialogiem i weryfikacją backendu | BACKEND CHANGE REQUIRED | Brak endpointu DELETE i logiki sprawdzającej zależności; schemat zachowuje historię i ogranicza usuwanie rekordów RAW. |
| 94 | Bez globalnego undo/redo/resetu formularza | FRONTEND ONLY | Zachowanie formularza UI. |
| 95 | Podgląd PDF/obrazów w aplikacji, inne pliki do pobrania | BACKEND CHANGE REQUIRED | Serwis może zweryfikować metadane/sumę i skan, ale nie zwraca zawartości; brak HTTP route list/download/stream. |
| 96 | Usunięcie dokumentu oznaczane w edytorze, zatwierdzane przy zapisie, anulowalne | BACKEND CHANGE REQUIRED | Linki wersji dokumentu do rewizji są niezmienne; brak API oznaczenia/odłączenia w nowej rewizji z zachowaniem starych linków. |
| 97 | Upload tymczasowy, przypisanie przy zapisie surowca, sprzątanie porzuconych plików | BACKEND CHANGE REQUIRED | `RawMaterialDocumentService::create` zapisuje plik i od razu tworzy dokument, wersję, link do rewizji i zdarzenia; brak staging API/TTL powiązanego ze szkicem. |
| 98 | Częściowy sukces wieloplikowego uploadu, ponowienie/usunięcie pliku z kolejki | BACKEND CHANGE REQUIRED | Brak endpointu wieloplikowego i kontraktu per-plik; serwis operuje na pojedynczym pliku/wywołaniu. |
| 99 | Bez dodatkowego podsumowania przed zwykłym zapisem | FRONTEND ONLY | UI może bezpośrednio wykonać istniejące żądania; backend zachowuje walidację i autoryzację. |
| 100 | Drobne UX według standardu; zmiany funkcji/bezpieczeństwa/danych/architektury wymagają zgody | BACKEND SUPPORTED | Proces/specyfikacja, nie funkcja API. Audyt nie wprowadza zmian; decyzje wymagające zmian opisano do osobnego zatwierdzenia. |

## Krytyczne konflikty i rozbieżności

### 1. SKU — rezerwacja, trwałość i zakres numeru (K1)

**Dowód:** `RawMaterialService::create` przydziela SKU wewnątrz transakcji tworzenia. `SkuAllocationService::allocateNext` blokuje licznik i zapisuje przypisanie, co wspiera współbieżność, ale nie ma operacji rezerwacji przed zapisem. Limit jest do 999 999 999, a nie do 999 999. Migracja 035 zapisuje unikalność per organizacja i pozostawia `SUR` tylko jako historyczny prefiks.

**Wpływ:** SKU nie może być pokazane przy otwarciu formularza i nie ma trwałości rezerwacji przy porzuceniu. Numery 1 000 000+ łamią sześciocyfrowy kontrakt.

**Proponowane podejście:** zaprojektować osobny, trwały rekord rezerwacji powiązany z organizacją, użytkownikiem i tokenem formularza; rezerwację atomowo przypiąć do nowego materiału bez cofania licznika. Zatwierdzić zakres/limit sześciu cyfr, zachować historyczne `SUR` i dodać testy wyścigu, porzuconej rezerwacji, podwójnego zapisu, importu oraz granicy 999 999. Zmiana wymaga migracji forward-only.

### 2. Katalog API i dane handlowo-produkcyjne

**Dowód:** lista zwraca tylko rekordy z `approved_revision_id`, ma domyślne sortowanie po kodzie i stronę 20; request ogranicza `per_page` do 100. Brak filtrów dostawcy/jednostki. Model rewizji nie przechowuje ilości nominalnej/użytkowej ani uzysku.

**Wpływ:** UI nie może wiernie wyświetlić kolumn 4/13/14/44, otworzyć na liście szkiców dla edytora, spełnić wymaganych filtrów i ustawień paginacji ani obliczyć kosztu użytkowego zgodnie z kontraktem.

**Proponowane podejście:** przed zmianą schematu zatwierdzić semantykę jednostek/uzysku, źródło ceny i uprawnienia pól; następnie rozszerzyć schemat rewizji i prezentację, dodać bezpieczne filtry, sorty, zakresy stron oraz osobną widoczność DRAFT/PENDING dla uprawnionych użytkowników. Nie mieszać cen zakupu, referencyjnych i wyceny magazynowej.

### 3. Własność materiału, lokalizacja i udostępnianie (K3)

**Dowód:** tabele RAW, dostawców i komercyjne są organizacyjne. `RawMaterialService::list`, `show`, `RawMaterialCommercialService` filtrują po `organization_id`; lokalizacja jest używana w autoryzacji i audycie, nie jako właściciel rekordu. Nie znaleziono pól `owner_location_id`, modelu udostępnienia ani reguł widoczności materiału po lokalizacji.

**Wpływ:** nie można wykazać izolacji danych restauracji ani kontrolowanego udostępniania danych z kuchni centralnej w ramach wspólnej organizacji. Jest to nierozstrzygnięcie architektoniczne, nie detal UI; nie należy zakładać, że katalog ma być globalny dla wszystkich lokalizacji.

**Proponowane podejście:** uzgodnić macierz własności/udostępnień dla kuchni centralnej i restauracji, widoczność dostawców/cen oraz uprawnienia lokalizacyjne. Dopiero potem projektować klucze, zapytania i testy granic; zachować zgodność z ADR-001 (osobna instancja klienta, ale nadal egzekwowane granice wewnętrzne).

### 4. ADMINISTRATOR, ceny i izolacja finansowa (K2)

**Dowód:** RAW ma odrębne `ReferencePrice*` i `PurchasingCosts*`; API referencyjne oraz eksport cen wymagają właściwych grantów, a źródło oferty jest redagowane bez `PurchasingCostsView`. Jednak zapisy/CSV nie sprawdzają jawnie `BusinessRole::Administrator`. Ogólne `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` nie są używane jako warunki RAW.

**Wpływ:** rozdział odczytu źródła ceny zakupu jest obecny, ale wymóg „wyłącznie ADMINISTRATOR” i mapowanie kontraktu Stage 1D na granty RAW nie są potwierdzone.

**Proponowane podejście:** zatwierdzić, czy uprawnienie `ReferencePriceManage` może być nadawane/delegowane wyłącznie administratorowi, oraz jak mapuje się na istniejący RBAC Stage 1D. Następnie wymusić regułę w istniejącej warstwie autoryzacji, bez duplikowania całej logiki ról w SQL; dodać testy negatywne/pozytywne dla API, CSV i download.

### 5. Historia, konflikt rewizji i kompletność

**Dowód:** rewizje i audyt są zapisywane, rewizje/hash są niezmienne, ale nie ma endpointu odczytu pełnego audytu. `updated_at` jest na materiale, brak `updated_by`, brak pól wersji oczekiwanej w `RawMaterialDataRequest`. `submit` nie sprawdza kompletności danych poza stanem rewizji; `approve` weryfikuje alergeny/hash.

**Wpływ:** brak bezpiecznego kontraktu dla widoku historii/ostatniego autora, konfliktu starych formularzy oraz autorytatywnego panelu kompletności. Konkurencyjne zapisy mogą tworzyć nowe rewizje bez informacji, że klient edytował starszą wersję.

**Proponowane podejście:** zatwierdzić checklistę aktywacji; wydzielić wspólny walidator kompletności używany przez odczyt panelu i przejścia lifecycle; dodać oczekiwaną rewizję/hash do zapisu; dodać audytowany, RBAC-chroniony odczyt historii oraz autora/czas aktualizacji.

### 6. Załączniki i cykl życia dokumentu (K5, K15)

**Dowód:** model dokumentów jest wersjonowany, prywatny, checksum-owany i append-only; istnieje serwis testowany bez kontrolera HTTP. Utworzenie pliku i rewizji materiału to oddzielne operacje. Link do wersji jest niezmienny.

**Wpływ:** interfejs nie ma dostępnych tras do listowania, tymczasowego uploadu, pobierania/podglądu, ponowienia uploadu ani oznaczenia usunięcia. Brakuje wspólnego kontraktu atomowości/idempotencji między zapisaniem rewizji a uploadem.

**Proponowane podejście:** zdefiniować staging obiektów z TTL, identyfikatorami per plik, idempotencją i bezpiecznym sprzątaniem; przy zapisie rewizji tworzyć niezmienne linki dla wybranych obiektów. Usunięcie oznaczać jako brak linku w nowej rewizji/zdarzenie lifecycle, nigdy kasować historycznych payloadów. Dodać autoryzowane API streamingu/podglądu i testy błędów częściowych oraz kompensacji.

### 7. Szkice i usuwanie

**Dowód:** DRAFT/edycja/submit/approve/archiwizacja/przywrócenie istnieją. Brak endpointu DELETE; tabele ograniczają fizyczne usuwanie i mają relacje `ON DELETE RESTRICT`.

**Wpływ:** decyzja 93 nie ma implementowalnego backendowego kontraktu. Aktualne zachowanie jest bezpieczniejsze niż niekontrolowane kasowanie, ale nie spełnia zatwierdzonego przepływu.

**Proponowane podejście:** nie obchodzić niezmienności przez `DELETE`. Dodać jawny, audytowany command wyłącznie dla szkicu bez zatwierdzeń, dokumentów, importów ani innych zależności; transakcyjnie sprawdzić wszystkie relacje i odmówić, jeśli istnieje zależność. Zachować ślad usunięcia, o ile polityka retencji tego wymaga.

## Ryzyka K1–K17 ze snapshotu

| Ryzyko | Status audytu | Potwierdzenie / dalsza decyzja |
|---|---|---|
| K1 — SKU przed zapisem, brak zwrotu, współbieżność, limit | **BACKEND CHANGE REQUIRED** | Współbieżność i unikalność per organizacja są wspierane; brak trwałej rezerwacji i obowiązuje limit dziewięciu cyfr. |
| K2 — separacja cen/kosztów i eksport | **CZĘŚCIOWO WSPIERANE** | Odrębne granty RAW, redakcja źródła i ponowna autoryzacja download istnieją; rola ADMINISTRATOR i mapowanie do ogólnych uprawnień Stage 1D nie są wymuszone. |
| K3 — lokalny właściciel i centralne udostępnianie | **ARCHITEKTURALNY CONFLICT / DO ROZSTRZYGNIĘCIA** | Wszystkie rekordy RAW są organizacyjne, bez owner-location ani modelu share. |
| K4 — cena referencyjna vs zakupy vs wycena magazynu; NUMERIC/jednostki | **CZĘŚCIOWO WSPIERANE** | Rozdzielone zdarzenia i arytmetyka NUMERIC są; nominalna/użytkowa ilość, uzysk i koszt użytkowy nie istnieją. Nie znaleziono podstawy do wniosku o wycenie magazynowej RAW. |
| K5 — historia dokumentów, upload i usunięcie | **CZĘŚCIOWO WSPIERANE** | Wersje/linki historyczne są niezmienne; brak API/staging/soft-unlink dla UI. |
| K6 — usuwanie szkicu z zależnościami | **BACKEND CHANGE REQUIRED** | Usuwanie jest niedostępne; nie ma commandu z kontrolą zależności. |
| K7 — możliwość przejścia RAW_MATERIALS do READY | **NOT READY** | Produkcyjna definicja ma `ready=false`; nie próbowano zmieniać ustawień ani odczytywać istniejącej bazy. |
| K8 — presety temperatur | **FRONTEND / WYMAGA WALIDACJI PRODUKTOWEJ** | Backend przechowuje zakresy, nie narzuca proponowanych presetów jako prawnych norm. |
| K9 — alergeny, słownik, downstream, niezmienność | **CZĘŚCIOWO WSPIERANE / DOWNSTREAM NOT VERIFIED** | Wersjonowany słownik, attestacja, typy ekspozycji i hash rewizji istnieją; nie znaleziono zależności downstream stosujących deklaracje. |
| K10 — uzysk tylko dla zgodnych jednostek i ilości > 0 | **BACKEND CHANGE REQUIRED** | Brak wielkości wejściowych i serwerowego obliczenia uzysku. |
| K11 — przywracanie stanu z ponowną autoryzacją i bramką | **BACKEND SUPPORTED** | Każde żądanie weryfikuje członkostwo/grant i dostępność modułu; odtworzenie UI pozostaje odpowiedzialnością frontendu. |
| K12 — eksport z zakresem, filtrem, kolumnami, uprawnieniami i audytem | **CZĘŚCIOWO WSPIERANE** | Zakres organizacji, profile, granty, audyt i ponowna autoryzacja istnieją; brak wybranych ID/filtrów/kolumn. |
| K13 — dostępność nakładanego panelu | **FRONTEND** | Kontrakt backendowy nie rozstrzyga focus/keyboard/a11y; nie audytowano implementacji UI. |
| K14 — debounce, anulowanie, błędy, pusty wynik | **FRONTEND** | API obsługuje query/paginację; anulowanie i ochrona przed starymi odpowiedziami to klient. |
| K15 — atomowość rewizji i uploadów, idempotencja, konflikt | **BACKEND CHANGE REQUIRED** | Dokumenty i rewizje zapisują się osobnymi operacjami; brak upload API, idempotencji dokumentów i tokenu rewizji. |
| K16 — kontrola na podstawie bieżącego repozytorium | **WYKONANO** | Audyt opiera dowody na kodzie/migracjach/testach, nie na historycznych raportach. |
| K17 — VSEF nie jest automatycznie zatwierdzonym standardem | **ZACHOWANO OGRANICZENIE** | Snapshot wyraźnie określa VSEF jako projekt; raport nie traktuje go jako wdrożonej lub autoryzującej specyfikacji. |

## Prace backendowe wymagające osobnego zatwierdzenia

1. **Najpierw kontrakt biznesowo-architektoniczny:** zakres lokalizacji i udostępniania (K3), znaczenie ADMINISTRATOR względem `ReferencePriceManage`, checklisty aktywacji/kompletności, dokładny limit SKU i polityka usuwania.
2. **Katalog i model ilości:** dodać rewizyjne ilości nominalne/użytkowe, walidację wymiaru i dodatniości, obliczenie uzysku oraz kosztu z jawnie zdefiniowaną jednostką/zaokrągleniem; nie utożsamiać referencyjnej ceny ani ceny zakupu z wyceną magazynu.
3. **Kontrakt listy:** pokazać uprawnione DRAFT-y bez ujawniania ich osobom tylko do odczytu; dodać zatwierdzone filtry i sorty, parametry 25/50/100/200 z defaultem 50 oraz payload właściwych pól z redakcją finansową per uprawnienie.
4. **SKU reservation:** endpoint rezerwacji przed zapisem i trwały nieodwracalny licznik; obsłużyć import, idempotencję, porzucone formularze i 999 999 bez naruszenia `SUR`.
5. **Konkurencja i kompletność:** oczekiwany identyfikator/hash rewizji, wspólny walidator używany przy prezentacji braków i przejściu lifecycle, wynik allowed-next-action.
6. **Historia:** RBAC-chroniony odczyt zdarzeń audytu oraz autor/czas aktualizacji bez osłabiania append-only.
7. **Eksport i operacje zbiorcze:** wybrane ID, filtry i dozwolona lista kolumn; wymagane uprawnienia odczytu dla każdego pola; audyt selekcji i pobrania; określić zachowanie częściowego/atomowego archiwizowania.
8. **Dokumenty:** HTTP API, staging TTL, status per plik, idempotencja/kompensacja, bezpieczne pobieranie/podgląd, soft detach na nowej rewizji i sprzątanie wyłącznie niepowiązanych uploadów.
9. **Szkice:** kontrolowana komenda usuwania szkicu z kontrolą wszystkich zależności, lifecycle, RBAC i audytem.

Nie należy wykonywać tych zmian w ramach niniejszego zadania. Zmiana `ready=false` ani lokalnego ustawienia modułu nie jest elementem rozwiązania kompatybilności.

## Prace wyłącznie frontendowe

Do implementacji po zatwierdzeniu backendowych kontraktów należą: układ tabeli i nagłówka, prawy panel, szerokości/animacje/przewijanie, kartoteka odczytowa i edytor, wybór sekcji do kopiowania, potwierdzenia i toasty, stan zaznaczenia, skeleton/puste stany/retry, debounce i anulowanie żądań, utrwalanie kontekstu, skróty klawiaturowe, walidacja po blur i nawigacja po sekcjach, formatowanie wizualne, miniwykres, kopiowanie SKU, dialog usunięcia/archiwizacji i odpowiednie zachowania dostępności.

„Frontend only” nie oznacza upoważnienia UI do generowania SKU, wyliczania uprawnień, odczytywania ukrytych pól, oznaczania rewizji jako kompletnej ani samodzielnego aktywowania materiału.

## Niezweryfikowane założenia

- Nie odczytywano stanu istniejącej bazy, więc faktyczne `enabled` dla lokalizacji nie jest tu raportowane. Kod definiuje RAW jako NOT_READY i bramka odrzuca go, niezależnie od UI.
- Nie ustalono, czy jedna instalacja zawsze zawiera dokładnie jedną organizację; dlatego nie potwierdzono unikalności SKU w całej instalacji przy unikalności per organizacja.
- Nie ustalono kanonicznego mapowania ról/atrybutu Administrator do grantów RAW ani zgodności z ogólnymi `FOODCOST_VIEW`/`PURCHASE_PRICES_VIEW`.
- Nie zdefiniowano pełnej checklisty obowiązkowej dla aktywacji, w tym tego, czy dostawca, cena, przechowywanie lub dokument są blokujące.
- Nie potwierdzono zakazu ręcznego utworzenia zdarzenia ceny o tej samej wartości w późniejszej dacie; importer ma test zmian rzeczywistych, ale zachowanie ręcznej ścieżki wymaga osobnego testu.
- Nie znaleziono kodu downstream, który dziedziczy alergeny RAW do innych modułów; nie przesądzono, czy zależność jest poza repozytorium, czy jeszcze nie istnieje.
- Nie audytowano frontendu; nie można potwierdzić, które zachowania 1–100 są już wizualnie zaimplementowane.
- Testy wskazane w raporcie są dowodami statycznie odczytanymi z kodu testowego; nie były uruchamiane w tym zadaniu ze względu na tryb strict read-only.

## Kolejność rozwiązywania ryzyk

1. Rozstrzygnąć K3 (własność i zakres lokalizacji), K2 (rola administratora/finanse), K7 (kryteria gotowości; nie zmieniać readiness) oraz K1 (rezerwacja i limit SKU).
2. Zatwierdzić kontrakt danych ilości/uzysku/kosztu i walidacji aktywacji; dopiero potem planować migrację i API.
3. Zamknąć bezpieczeństwo konfliktów rewizji, historii/audytu i odczytu pól finansowych.
4. Zatwierdzić transakcje, retry i retencję dla załączników oraz reguły usuwania szkiców.
5. Rozszerzyć eksport i operacje zbiorcze z kontrolą zakresu/uprawnień.
6. Dopiero po niezależnej akceptacji gotowości modułu planować jego aktywację. Nigdy nie traktować zamknięcia UI/UX jako `READY`.

## Rekomendacja końcowa

### CONDITIONAL GO — wyłącznie do planowania implementacji

Można rozpocząć planowanie Stage 2F, jeśli plan wyraźnie odseparuje frontendowe prace prezentacyjne od zmian backendu i najpierw uzyska osobne decyzje dla K1–K4, K7 i checklisty aktywacji. Zestaw obecnych tras i serwisów daje solidną bazę, ale nie jest zgodny z pełnym zatwierdzonym kontraktem 1–100 bez opisanych zmian.

**NO-GO dla rozpoczęcia implementacji backendowej lub aktywacji RAW_MATERIALS na podstawie samego snapshotu.** `RAW_MATERIALS` pozostaje NOT_READY według kodu, a decyzje 1–100 nie autoryzują migracji, wdrożenia ani aktywacji. Audyt kończy się na tym raporcie; nie wprowadzono implementacji.
