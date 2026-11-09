# VELTRA 2.0 — Stage 3E
## Kontrola zgodności UX RAW_MATERIALS z aktualnym backendem

**Wynik: PARTIAL — audyt kodu wykonano, ale nie można potwierdzić dokładnego brzmienia wszystkich 100 zatwierdzonych decyzji UX.**  
**Zakres:** tylko odczyt repozytorium i dokumentacji; bez zmian aplikacji, migracji, testów, uruchamiania środowisk, wdrożenia i aktywacji.

## Zakres źródeł i ograniczenia

- Tytuły obu wskazanych dokumentów Google były widoczne w przeglądarce, ale treść dokumentów nie została załadowana do czytelnego tekstu. Edytor zwracał pustą treść, a eksport tekstowy nie powiódł się. Nie twierdzę, że przeczytałem ich treść.
- Dla numeracji i zwięzłych opisów punktów #01–#100 użyłem istniejącego lokalnego [audytu zgodności UX/API](./VELTRA_STAGE_2F_RAW_BACKEND_COMPATIBILITY_AUDIT.md). Jest to źródło wtórne, nie zatwierdzony dokument UX. Wiersze poniżej są zatem mapą roboczą do lokalnych streszczeń, a nie potwierdzeniem dokładnego tekstu źródłowych decyzji.
- Oryginalna specyfikacja Stage 1D RBAC i Stage 1E architektury również nie była dostępna jako pełny tekst źródłowy. Sprawdziłem lokalne raporty Stage 1D/1E oraz bieżący kod. Lokalny raport Stage 1E sam odnotowuje niedostępność źródłowej specyfikacji.
- Bieżące ustalenia implementacyjne pochodzą z aktualnego repozytorium. Starszy [audyt UX/API](./VELTRA_STAGE_2F_RAW_BACKEND_COMPATIBILITY_AUDIT.md), [wpływ K3](./VELTRA_STAGE_2F_RAW_K3_TECHNICAL_IMPACT.md) ani raporty etapowe nie zostały potraktowane jako dowód bieżącego zachowania.
- Nie uruchamiałem testów ani Docker. Nazwy testów w raporcie oznaczają istniejące testy obejrzane statycznie, nie testy uruchomione w Stage 3E.

## Indeks bieżącego dowodu z kodu

Skróty `CAT`, `DETAIL`, `LIFE`, `PRICE`, `SHARE`, `SKU`, `DOC`, `MOD`, `UI` użyte w macierzy wskazują dokładne pliki/metody/API:

- **CAT** — `backend/routes/api.php` (`GET/POST /api/v1/raw-materials`); `backend/app/Http/Requests/ListRawMaterialsRequest.php`; `backend/app/Domain/RawMaterials/RawMaterialService.php::list`.
- **DETAIL** — `backend/routes/api.php` (`GET /{material}`, `POST /`, `PATCH /{material}/draft`); `backend/app/Http/Requests/RawMaterialDataRequest.php`; `RawMaterialService::show`, `create`, `revise`, `reviseWithPermission`, `presentMaterial`.
- **LIFE** — te same trasy oraz `POST /{material}/{submit,approve,return,block,unblock,archive,restore}`; `RawMaterialService::submit`, `approve`, `returnForChanges`, `archive`, `restore`, `revisions`; `backend/tests/Feature/RawMaterialsTest.php`.
- **PRICE** — `backend/routes/api.php` (`GET/POST /{material}/reference-prices`, ceny ofert i eksporty); `backend/app/Domain/RawMaterials/RawMaterialCommercialService.php::referencePrices`, `setReferencePrice`, `offerPriceEvents`, `recordOfferPriceEvent`; `RawMaterialCsvCatalogExportWorkflow`; `RawMaterialPermission`.
- **SHARE** — `backend/routes/api.php` (`POST /{material}/shares/bulk`, `POST /bulk-shares/by-location`, `DELETE /{material}/shares/{location}`); `RawMaterialController::bulkShareLocations`, `bulkShareMaterials`, `revokeShare`; `RawMaterialLocationShareAdministration::bulkShareToLocations`, `bulkShareMaterialsToLocation`, `revoke`; `RawMaterialLocationSharePolicy::isPrimaryContext`, `canConsume`, `constrainVisibleMaterials`.
- **SKU** — `backend/app/Domain/Sku/SkuAllocationService.php::allocateNext`, `reserveImported`; `SkuPrefix::format`, `parse`; `RawMaterialService::create`; migracja 035 (liczniki i unikalność per organizacja).
- **DOC** — `backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php::create`, `appendVersion`, `verifyVersion`; **brak tras HTTP RAW dokumentów** w `backend/routes/api.php`.
- **MOD** — `backend/app/Domain/Modules/DefinedModuleCatalog.php`, `ModuleDefinition` (domyślnie `ready=false`), `RequireRawMaterialsAvailability`; `backend/tests/Feature/ModuleConfigurationTest.php`.
- **UI** — `frontend/src/App.tsx`, `AuthPanel.tsx`, `AccountLinkForm.tsx`; nie znaleziono widoku RAW, edytora, panelu administracyjnego ani projektów UX RAW w `frontend/src`.

Wybrane istniejące testy (nieuruchamiane w tym audycie):

- Katalog/import/ceny: `RawMaterialsTest::test_stage2e7_integrated_catalog_pricing_and_as_of_exports`, `test_catalog_csv_export_requires_view_permission_and_reauthorizes_download`, `test_reference_price_view_does_not_disclose_purchasing_source_without_cost_permission`.
- Rewizje/alergeny/dokumenty: `test_view_only_reader_cannot_discover_drafts_or_pending_revisions`, `test_draft_can_be_edited_and_returned_for_changes_without_mutating_prior_revisions`, `test_recipient_reads_only_the_current_approved_revision_and_its_documents`, `RawMaterialRevisionDataModelTest::test_approved_revision_data_cannot_be_modified_or_extended`, `RawMaterialDocumentStorageTest::test_document_versions_links_and_events_are_immutable`.
- Share i finanse: `test_explicit_location_share_gates_raw_material_access_and_preserves_revocation_history`, `test_raw_material_sharing_requires_an_authorized_administrator`, `test_bulk_share_supports_both_views_and_is_idempotent`, `test_bulk_share_rejects_all_invalid_authorized_targets_atomically`, `test_bulk_share_denies_unauthorized_and_cross_organization_targets_without_disclosure`, `test_bulk_share_audit_failure_rolls_back_every_target_and_audits_rejection`, `test_concurrent_bulk_share_requests_serialize_on_postgresql`, `test_bulk_share_waits_for_revocation_archive_and_location_deactivation`, `test_supplier_products_offer_prices_and_cost_permissions_are_isolated`.
- SKU/readiness: `RawMaterialsTest::test_sku_prefixes_allocate_independently_and_reserve_unassigned_imported_numbers`, `RawMaterialConcurrencyTest::test_parallel_creates_serialize_code_allocation_and_audit`, `ModuleConfigurationTest::test_catalog_and_new_location_states_are_fail_closed_for_both_types`, `test_production_api_cannot_enable_not_ready_modules_and_records_rejection`.

## Macierz #01–#100

Klasyfikacja opisuje bieżące pokrycie backendowe dla streszczenia danego punktu. „Severity” oznacza wpływ luki na zgodną realizację UX, nie ocenę ryzyka bezpieczeństwa całego systemu.

### #01–#25

| # | Wymaganie (streszczenie lokalnego audytu) | Klasyfikacja | Dowód w aktualnym repozytorium | Luka / severity |
|---:|---|---|---|---|
| 01 | Kliknięcie wiersza otwiera prawy panel; kartoteka osobno | FRONTEND ONLY | UI; DETAIL::`show` | Brak widoku; MINOR |
| 02 | Stały zestaw kolumn, bez konfiguracji użytkownika | FRONTEND ONLY | UI; CAT::`list` | Układ/stałość kolumn to UI; konkretne dane ograniczone przez #04; MINOR |
| 03 | Szukanie nazwy/SKU i filtry kategoria/dostawca/status/jednostka | BACKEND CHANGE REQUIRED | CAT::`list`; `ListRawMaterialsRequest` | Są search, `category_id`, `lifecycle_state`; brak filtrów dostawcy i jednostki; IMPORTANT |
| 04 | Osiem zatwierdzonych kolumn, w tym SKU/jednostka/ilości/cena/koszt | UNVERIFIED | CAT::`list`; DETAIL::`presentMaterial`; treść źródłowa UX niedostępna | Dokładnych ośmiu nazw nie da się potwierdzić z wtórnego streszczenia. Aktualna lista zwraca kod, stan, nazwę, kategorię, jednostkę bazową i stan alergenów; brak ilości użytkowej/nominalnej oraz kwoty ceny/kosztu. BLOCKER |
| 05 | Cena netto odnosi się do kupowanego opakowania, bez drugiego fikcyjnego opakowania | BACKEND SUPPORTED | PRICE::`offerPriceEvents`; pola `net_amount_per_purchase_unit`, `purchase_unit_code` w danych rewizji dostawcy | Kontrakt przechowuje kwotę na jednostkę zakupu; MINOR |
| 06 | Cena referencyjna jest osobna od ceny konkretnego dostawcy | BACKEND SUPPORTED | PRICE::`setReferencePrice`, `offerPriceEvents`; osobne zdarzenia cen referencyjnych i ofert | Rozdzielenie istnieje; MINOR |
| 07 | Ręczne i CSV wprowadzanie cen z historią | BACKEND SUPPORTED | PRICE; trasy `/reference-prices`, `/imports`, `/imports/{preview}/commit` | Ceny CSV i ręczne mają odrębne API, historia append-only; MINOR |
| 08 | Historia cen tylko dla realnej zmiany; audyt importu także bez zmiany | BACKEND CHANGE REQUIRED | PRICE::`setReferencePrice`; import przez `RawMaterialCsvImportPersistenceService::compareWithHistory` | Import porównuje stan, lecz ręczny zapis nie porównuje nowej wartości z poprzednią przed dodaniem zdarzenia; IMPORTANT |
| 09 | Wykres cen za ostatnie sześć miesięcy | FRONTEND ONLY | PRICE::`referencePrices`, `as_of` | Historia datowana jest dostępna; zakres 6 miesięcy i wykres to UI; MINOR |
| 10 | Tylko ADMINISTRATOR może zmieniać/importować cenę referencyjną | BACKEND CHANGE REQUIRED | PRICE; `RawMaterialAuthorization::assertAllowed` | Zapisy chroni RAW grant `ReferencePriceManage`, ale nie ma wymogu `BusinessRole::Administrator` w sprawdzonej ścieżce ceny; BLOCKER |
| 11 | Cena zmienia się po zapisie, bez dodatkowego zatwierdzania | BACKEND SUPPORTED | PRICE::`setReferencePrice` | Bez osobnego workflow approve; ograniczenie aktora z #10 nadal blokuje zgodność; IMPORTANT |
| 12 | Poprzedni pomysł osobnego zatwierdzania ceny jest zastąpiony | BACKEND SUPPORTED | PRICE::`setReferencePrice` | Zdarzenie jest zapisywane po autoryzacji; brak osobnego approve; MINOR |
| 13 | Ilość użytkowa domyślnie równa nominalnej; możliwość korekty | BACKEND CHANGE REQUIRED | DETAIL::`RawMaterialDataRequest`, `presentMaterial`; schemat rewizji | Brak obu ilości i relacji; BLOCKER |
| 14 | Koszt na 100 g/100 ml/szt. zależnie od właściwej jednostki | BACKEND CHANGE REQUIRED | PRICE; `RawMaterialUnitRegistry`; CAT::`list` | Nie ma ilości użytkowej ani API obliczającego koszt na wskazaną bazę; BLOCKER |
| 15 | UI pokazuje 2 miejsca, obliczenia backendu mają większą precyzję | BACKEND SUPPORTED | PRICE; `raw_materials_round_half_even_v1`, precyzja `numeric` do 14 miejsc | Precyzja i konwersje istnieją; format prezentacji to UI; MINOR |
| 16 | Brak edycji inline; edycja tylko w pełnoekranowym edytorze | FRONTEND ONLY | UI; DETAIL::`PATCH /{material}/draft` | Ekran/układ UI, lecz sama aplikacja RAW nie istnieje; MINOR |
| 17 | Niekompletny rekord zapisuje się jako DRAFT, aktywacja walidowana przez backend | BACKEND SUPPORTED | DETAIL::`create`, `RawMaterialDataRequest`; LIFE::`submit`, `approve` | Draft może nie mieć pól dodatkowych, ale wymaga kategorii/nazwy/jednostki. Różnicę kompletności aktywacji opisują #68/#76; MINOR |
| 18 | Kopiowanie wybranych sekcji do nowego SKU, bez historii, z nową deklaracją alergenów | FRONTEND ONLY | DETAIL::`show`, `create`; osobny endpoint alergii | UI może utworzyć nowy rekord z wybranymi polami; brak klonowania serwerowego nie blokuje tej operacji; alergeny trzeba ponownie zadeklarować; MINOR |
| 19 | Archiwizacja/przywracanie; usuwanie tylko nigdy niezatwierdzonego szkicu bez zależności | BACKEND CHANGE REQUIRED | LIFE::`archive`, `restore`; brak `DELETE /{material}` | Archiwizacja i restore istnieją; brak twardego usuwania draftu i sprawdzenia zależności; IMPORTANT |
| 20 | Archiwum dostępne w osobnym module/widoku, archiwizacja nie przenosi rekordu | FRONTEND ONLY | LIFE; CAT::`lifecycle_state=ARCHIVED` | Backend zmienia lifecycle, a nie lokalizację rekordu; osobny widok należy do UI; MINOR |
| 21 | Domyślnie nazwa A–Z; sort po nagłówkach | BACKEND CHANGE REQUIRED | CAT::`list`, `ListRawMaterialsRequest` | Domyślnie sortuje `code`; dozwolone tylko `code`, `name`, `lifecycle_state`; IMPORTANT |
| 22 | Rozmiary 25/50/100/200, domyślnie 50 | BACKEND CHANGE REQUIRED | CAT; request `per_page` max 100, serwis default 20 | Wartości dopuszczone i domyślna nie odpowiadają streszczeniu; 200 nieobsługiwane; IMPORTANT |
| 23 | Zaznaczenie wielu rekordów; eksport/archiwizacja wybranych | BACKEND CHANGE REQUIRED | LIFE; `RawMaterialCsvCatalogExportWorkflow`; SHARE (oddzielne bulk share) | Eksporty obejmują profil katalogu, archiwizacja przyjmuje pojedynczy materiał; brak API operacji na zaznaczonej liście; IMPORTANT |
| 24 | Zaznaczenie trwa między stronami; „zaznacz wszystko” tylko na stronie | FRONTEND ONLY | UI; CAT zwraca paginację | Zarządzanie selekcją UI; MINOR |
| 25 | Pływający pasek akcji zbiorczych | FRONTEND ONLY | UI | Układ/interakcja UI; operacje wybrane nadal zależą od #23; MINOR |

### #26–#50

| # | Wymaganie (streszczenie lokalnego audytu) | Klasyfikacja | Dowód w aktualnym repozytorium | Luka / severity |
|---:|---|---|---|---|
| 26 | Przypięty nagłówek panelu, zwijane sekcje | FRONTEND ONLY | UI; DETAIL::`show` | Prezentacja UI; MINOR |
| 27 | Nawigacja następny/poprzedni zgodnie z bieżącym katalogiem | BACKEND CHANGE REQUIRED | CAT::`list` | Brakuje filtrów/sortów z pełnego katalogowego kontekstu (#03/#21); UI może nawigować tylko po aktualnie dostępnych parametrach; IMPORTANT |
| 28 | Edytor uruchamiany z panelu lub menu, obowiązuje RBAC | BACKEND SUPPORTED | DETAIL; `RawMaterialAuthorization::assertAllowed` | Mutacje i odczyt mają osobne endpointy oraz granty; brak UI; MINOR |
| 29 | Po zapisie powrót do dokładnego kontekstu katalogu | FRONTEND ONLY | UI | Stan filtrów/strony/scrolla/panelu należy do UI; MINOR |
| 30 | Niezależne przewijanie katalogu i panelu | FRONTEND ONLY | UI | Układ UI; MINOR |
| 31 | Trzy szerokości panelu zapamiętane dla użytkownika | FRONTEND ONLY | UI | Preferencja UI; MINOR |
| 32 | Sekcje alergenów/przechowywania i wykres w panelu | FRONTEND ONLY | LIFE::`revisionStage2d`; PRICE::`referencePrices` | Dane endpointów dostępne, kolejność i widok to UI; MINOR |
| 33 | Wykres po sekcji podstawowej, przed alergenami | FRONTEND ONLY | UI | Kolejność UI; MINOR |
| 34 | Wykres 6 miesięcy z tooltipem daty/ceny | FRONTEND ONLY | PRICE::`referencePrices` | API zwraca historię datowaną; okres i tooltip UI; MINOR |
| 35 | Alergeny: obecne/możliwy kontakt krzyżowy/nieznane/bez znanych | BACKEND SUPPORTED | `RawMaterialAllergenDeclarationRequest`; `RawMaterialService::revisionStage2d`; `RawMaterialRevisionDataModelTest` | Rozróżniane statusy i typ ekspozycji; MINOR |
| 36 | Trzy presety przechowywania; temperatury jako propozycje | FRONTEND ONLY | `RawMaterialStorageVariantsRequest`; `revisionStage2d` | API zapisuje jawne warianty, nie narzuca presetów; UI może je dostarczyć bez nowej reguły backendu; MINOR |
| 37 | Wspólna biblioteka dostawców, wielu dostawców na surowiec | BACKEND SUPPORTED | PRICE; `/suppliers`, `/{material}/supplier-products`, `/supplier-products/{product}/offers` | Model i endpointy wspólne dla organizacji; recipient widzi tylko dostępne/current RAW zgodnie z share; MINOR |
| 38 | Wiele załączników i uploader bez małego sztywnego limitu | BACKEND CHANGE REQUIRED | DOC::`create` | Operacja serwisowa przyjmuje jeden plik, max 20 MiB; brak tras HTTP RAW dokumentów i uploadu wieloplikowego; IMPORTANT |
| 39 | Import w osobnym module, eksport w katalogu | FRONTEND ONLY | CAT; trasy `/imports`, `/catalog-imports`, `/catalog-exports` | Endpointy osobne; rozmieszczenie modułów jest UI; MINOR |
| 40 | Ręczny wybór kolumn eksportu z kontrolą uprawnień | BACKEND CHANGE REQUIRED | `RawMaterialCsvCatalogExportWorkflow::create`, `createExtended`, `createReferencePrices` | Eksporty używają stałych profili i kolumn; brak wyboru/whitelisty kolumn w żądaniu; IMPORTANT |
| 41 | Domyślne kolumny tabeli zaznaczone, bez szablonów użytkownika | FRONTEND ONLY | UI | Stan UI; MINOR |
| 42 | Kartoteka tylko do odczytu, osobny pełny edytor | FRONTEND ONLY | DETAIL; osobne GET i PATCH/POST | Rozdział API istnieje; układ/tryb ekranu UI; MINOR |
| 43 | Kartoteka produkcja/zakupy w dwóch kolumnach, responsywna | FRONTEND ONLY | UI; PRICE (odrębne uprawnienia danych komercyjnych) | Układ UI; ceny/koszty pobierać z odrębnie chronionych API; MINOR |
| 44 | Ilość nominalna/użytkowa i procent uzysku w kartotece | BACKEND CHANGE REQUIRED | DETAIL::`presentMaterial`; schemat `raw_material_revisions` | Brak tych pól i obliczenia uzysku; BLOCKER |
| 45 | Pełna historia i audyt w module Historia | BACKEND CHANGE REQUIRED | LIFE::`revisions` zwraca rewizje i approval events; brak odczytowej trasy `raw_material_audit_events` | Nie ma API pełnego dziennika audytu RAW; IMPORTANT |
| 46 | Czas/autor ostatniej aktualizacji i link do historii | BACKEND CHANGE REQUIRED | DETAIL::`presentMaterial`; tabela materiałów | `updated_at` nie jest zwracane, `updated_by` nie istnieje, brak pełnego endpointu historii; IMPORTANT |
| 47 | „+ Dodaj surowiec” w prawym górnym rogu | FRONTEND ONLY | UI; DETAIL::`POST /api/v1/raw-materials` | Położenie przycisku UI; MINOR |
| 48 | Dwa rzędy nagłówka: dodawanie, wyszukiwanie/filtry/eksport | FRONTEND ONLY | UI; CAT/PRICE | Rozmieszczenie UI; filtrów i eksportu wybranych kolumn brakuje według #03/#40; MINOR |
| 49 | Aktywne filtry widoczne w polach i „Wyczyść” | FRONTEND ONLY | UI; CAT | Renderowanie stanu i reset UI; MINOR |
| 50 | Debounce ~300 ms, nowy search od strony 1 | FRONTEND ONLY | UI; CAT::`search`, `page` | Czas debounce i reset paginacji UI; MINOR |

### #51–#75

| # | Wymaganie (streszczenie lokalnego audytu) | Klasyfikacja | Dowód w aktualnym repozytorium | Luka / severity |
|---:|---|---|---|---|
| 51 | Wyszukiwanie fragmentu nazwy/SKU bez rozróżniania wielkości liter; bez fuzzy | BACKEND SUPPORTED | CAT::`list` | Fragmentowe wyszukanie nazwy/kodu/aliasu, bez fuzzy; MINOR |
| 52 | Pusty wynik: komunikat i czyszczenie filtrów | FRONTEND ONLY | CAT zwraca `meta.total` | Render pustego stanu UI; MINOR |
| 53 | Skeleton zamiast starych danych podczas ładowania | FRONTEND ONLY | UI | Stan ładowania UI; MINOR |
| 54 | Retry bez utraty filtrów/sortu/strony | FRONTEND ONLY | UI; CAT przyjmuje parametry listy | Zachowanie UI; MINOR |
| 55 | Zakres i liczba widocznych rekordów obok paginacji | FRONTEND ONLY | CAT zwraca `current_page`, `per_page`, `total`, `last_page` | UI może wyliczyć zakres; MINOR |
| 56 | Przyklejone nagłówki tabeli | FRONTEND ONLY | UI | CSS/UI; MINOR |
| 57 | Osobne zaznaczenie aktywnego wiersza | FRONTEND ONLY | UI | Stan UI; MINOR |
| 58 | Panel nakłada się na tabelę, bez zwężania | FRONTEND ONLY | UI | Układ UI; MINOR |
| 59 | Dyskretny uchwyt otwierania/zamykania panelu | FRONTEND ONLY | UI | Interakcja UI; MINOR |
| 60 | Uchwyt na środku krawędzi i animacja 250–300 ms | FRONTEND ONLY | UI | Animacja/pozycjonowanie UI; MINOR |
| 61 | Powrót z modułu odtwarza kontekst katalogu | FRONTEND ONLY | UI; ponowne żądanie nadal przechodzi middleware | Zachowanie UI; MINOR |
| 62 | Stan katalogu przez sesję/refresh, nowe logowanie zaczyna domyślnie | FRONTEND ONLY | UI; backend autoryzuje każde żądanie na nowo | Preferencja UI; odtworzony stan nie może zastępować autoryzacji; MINOR |
| 63 | Toast sukcesu dopiero po odpowiedzi backendu | FRONTEND ONLY | UI; mutacje zwracają odpowiedzi API | Timing/tekst UI; MINOR |
| 64 | Archiwizacja z potwierdzeniem, bez „Cofnij” w toaście | BACKEND SUPPORTED | LIFE::`archive`; `POST /{material}/archive` wymaga powodu | Lifecycle i audyt istnieją; dialog/toast UI; MINOR |
| 65 | Ochrona wyjścia z niezapisanymi zmianami | FRONTEND ONLY | UI | Obsługa stanu formularza; MINOR |
| 66 | Błędy przy polach i panel kompletności z nawigacją | FRONTEND ONLY | DETAIL request validation; UI | Renderowanie błędów/nawigacja UI, ale kontrakt kompletności jest luką #75/#76; IMPORTANT |
| 67 | Walidacja po blur, potem podczas poprawiania, całość przy zapisie | FRONTEND ONLY | DETAIL::`RawMaterialDataRequest` waliduje serwerowo | Walidacja interakcyjna UI; MINOR |
| 68 | Pola minimalne kontra osobne warunki aktywacji | BACKEND CHANGE REQUIRED | DETAIL::`RawMaterialDataRequest`; LIFE::`submit`, `approve` | Brak kanonicznego, wspólnego zestawu warunków kompletności; approve waliduje alergeny/hash, nie zwraca braków do formularza; IMPORTANT |
| 69 | Zablokowany przycisk i wskaźnik podczas zapisu | FRONTEND ONLY | UI | Stan zapisu UI; MINOR |
| 70 | Przyklejony pasek „Zapisz/Anuluj” | FRONTEND ONLY | UI | Układ UI; MINOR |
| 71 | Wskaźnik zmian i zapis nieaktywny bez zmian | FRONTEND ONLY | UI | Porównanie lokalnego stanu UI; MINOR |
| 72 | Jedna przewijana strona z aktywną nawigacją sekcji | FRONTEND ONLY | UI | Układ/nawigacja UI; MINOR |
| 73 | Ikony tylko dla błędów/braków aktywacji | FRONTEND ONLY | UI; kontrakt braków wymaga #76 | Renderowanie UI; BLOCKER dla zgodnego panelu kompletności |
| 74 | Przypięty, niezależnie przewijany panel kompletności | FRONTEND ONLY | UI; dane kompletności wymagają #76 | Układ UI; IMPORTANT |
| 75 | Panel: tytuł/status/liczba błędów i braków, bez procentów | BACKEND CHANGE REQUIRED | DETAIL::`show`; LIFE::`submit`, `approve` | Brak kanonicznego wyniku kompletności i następnego kroku; IMPORTANT |

### #76–#100

| # | Wymaganie (streszczenie lokalnego audytu) | Klasyfikacja | Dowód w aktualnym repozytorium | Luka / severity |
|---:|---|---|---|---|
| 76 | Oddziel błędy blokujące zapis od braków do aktywacji, klikalne do pól | BACKEND CHANGE REQUIRED | DETAIL/LIFE | Brak wspólnego serwisowego wyniku kompletności; szczegółowe warunki aktywacji wymagają potwierdzenia źródłowej decyzji; BLOCKER |
| 77 | Pokazuj dozwolony następny krok, bez automatycznej aktywacji | BACKEND CHANGE REQUIRED | LIFE::`submit`, `approve` | Workflow nie autoaktywuje po samym zapisie, ale brak API capabilities/allowed-next-action; IMPORTANT |
| 78 | Nawigacja edytora w grupach Produkcja i Zakupy/administracja | FRONTEND ONLY | UI; PRICE | Grupowanie/układ UI; MINOR |
| 79 | Widoczne tylko sekcje z prawem odczytu; brak ukrytych pól w API | BACKEND SUPPORTED | DETAIL::`presentMaterial`; PRICE; `RawMaterialPermission` | Odczyt ogólny nie emituje cen zakupowych; ceny referencyjne i zakupowe mają osobne granty; test redakcji źródła ceny; MINOR |
| 80 | Pola read-only jako etykiety, nie wyszarzone inputy | FRONTEND ONLY | UI | Prezentacja UI; MINOR |
| 81 | Konflikt rewizji blokuje nadpisanie; pobranie aktualnej wersji z ostrzeżeniem | BACKEND CHANGE REQUIRED | DETAIL::`reviseWithPermission`, `RawMaterialDataRequest` | Blokada organizacji serializuje zapisy, lecz żądanie nie przekazuje oczekiwanego ID/wersji edytowanej rewizji; brak konfliktu dla nieaktualnego formularza; BLOCKER |
| 82 | Ctrl+S i Esc z ochroną zmian | FRONTEND ONLY | UI | Skróty klawiaturowe UI; MINOR |
| 83 | Po utworzeniu „Zapisz i wróć” lub „Zapisz i dodaj kolejny” | FRONTEND ONLY | DETAIL::`create` zwraca nowy rekord | Wybór kolejnego widoku UI; MINOR |
| 84 | Split button dwóch wariantów zapisu bez zapamiętywania | FRONTEND ONLY | UI | Interakcja UI; MINOR |
| 85 | SKU systemowe, niemodyfikowalne, różne od UUID | BACKEND SUPPORTED | SKU::`RawMaterialService::create`, `SkuAllocationService::allocateNext`; `RawMaterialDataRequest` nie przyjmuje SKU | System tworzy SKU podczas zapisu, poza wymogiem przydziału przy otwarciu formularza (#86); MINOR |
| 86 | Przydziel SKU przy otwarciu formularza, widoczne przed pierwszym zapisem | BACKEND CHANGE REQUIRED | SKU; brak endpointu rezerwacji SKU w `routes/api.php` | SKU jest alokowane dopiero w POST tworzenia; BLOCKER |
| 87 | Porzucone/nieudane formularze nie zwalniają numeru | BACKEND CHANGE REQUIRED | SKU::`allocateNext`; alokacja i materiał są w tej samej transakcji | Brak trwałej rezerwacji przed formularzem; rollback tworzenia wycofuje alokację; BLOCKER |
| 88 | Dokładnie format `RAW-000001` i sześć cyfr | BACKEND CHANGE REQUIRED | SKU::`SkuPrefix::format`, `parse`; migracja 035 | Format przyjmuje 6–9 cyfr i max 999 999 999; limit 999 999 nie jest egzekwowany; BLOCKER |
| 89 | Osobne sekwencje RAW/INT/PRD/MEN i unikalne pełne SKU | BACKEND SUPPORTED | SKU; `organization_sku_counters`, `organization_sku_assignments`; test prefiksów i współbieżności | Liczniki i unikalność są per organizacja, nie globalne; MINOR |
| 90 | Numeracja niezależna w obrębie instalacji klienta | UNVERIFIED | SKU liczniki per organizacja; [ADR-001](./VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md) wymaga osobnej instancji klienta | Nie wykazano ani nie wymuszono jednej organizacji na instalację; brak unikalności SKU między organizacjami w tej samej bazie; BLOCKER do potwierdzenia zakresu |
| 91 | Kopiowanie SKU z panelu/kartoteki z toastem; bez ikony w tabeli | FRONTEND ONLY | DETAIL zwraca `code`; UI | Clipboard/toast i ukrycie ikony to UI; MINOR |
| 92 | Zamknąć UX bez kolejnych pytań i kontynuować Stage 2F | FRONTEND ONLY | Nie jest kontraktem API | Wskazówka procesowa, nie dowód ukończenia ani autoryzacja dalszych zmian; MINOR |
| 93 | Usuwanie szkicu z dialogiem i weryfikacją backendu | BACKEND CHANGE REQUIRED | LIFE; brak trasy DELETE dla RAW | Brak usuwania nigdy-niezatwierdzonego szkicu oraz kontroli zależności; IMPORTANT |
| 94 | Brak globalnego undo/redo/resetu formularza | FRONTEND ONLY | UI | Zachowanie edytora UI; MINOR |
| 95 | Podgląd PDF/obrazów i pobieranie innych załączników | BACKEND CHANGE REQUIRED | DOC::`verifyVersion`; brak tras HTTP dla dokumentów | Serwis tworzy/skanuje i weryfikuje metadane, ale API nie listuje, nie strumieniuje ani nie pobiera plików; IMPORTANT |
| 96 | Usunięcie dokumentu oznaczane w edytorze, zatwierdzane przy zapisie i anulowalne | BACKEND CHANGE REQUIRED | DOC::`appendVersion`; linki wersji są historyczne | Brak kontraktu odłączenia dokumentu od nowej rewizji przy zachowaniu historycznych linków; IMPORTANT |
| 97 | Upload tymczasowy, przypisanie przy zapisie, sprzątanie porzuconych plików | BACKEND CHANGE REQUIRED | DOC::`create`, `appendVersion` | Dokument jest zapisywany i wiązany bezpośrednio z rewizją; brak staging/TTL/attach przy zapisie; IMPORTANT |
| 98 | Częściowy sukces uploadu wielu plików, ponowienie/usunięcie pliku z kolejki | BACKEND CHANGE REQUIRED | DOC::`create`; brak HTTP batch upload | Brak wieloplikowego API i wyników per plik. Nie jest to sprzeczne z atomowym bulk share K3.9–K3.10: dotyczy innej operacji; IMPORTANT |
| 99 | Zwykły zapis bez dodatkowego ekranu podsumowania | FRONTEND ONLY | DETAIL::`create`, `revise` | Nawigacja UI; backend nadal waliduje/autoryzuje; MINOR |
| 100 | Drobne UX w standardzie; zmiany danych/funkcji/bezpieczeństwa wymagają akceptacji | UNVERIFIED | Repozytorium i proces nie dowodzą treści źródłowej decyzji | Wymóg governance; oryginalnego tekstu UX nie można sprawdzić; MINOR |

## Priorytetowe braki backendu przed zgodną integracją frontendową

1. **BLOKER — kontrakt ośmiu kolumn i wyliczeń (#04, #13–#14, #44).** Źródłowego dokumentu UX nie udało się odczytać, więc nie podaję wymyślonej listy ośmiu pól. Niezależnie od tego aktualny model nie ma ilości nominalnej/użytkowej, uzysku ani kosztu na 100 g/100 ml/szt.; `list` nie zwraca cen.
2. **BLOKER — alokacja SKU przy otwarciu formularza (#86–#90).** Trzeba zatwierdzić i dodać trwały endpoint rezerwacji (niezależny od tworzenia szkicu), zachowanie numerów porzuconych i twardy limit 6 cyfr. Obecne liczniki są per organizacja; ADR-001 izoluje klienta per instancja, ale nie ustanawia w kodzie jednej organizacji na instancję. To wymaga potwierdzenia zakresu unikalności.
3. **BLOKER — autoryzacja cen referencyjnych (#10).** Bieżący serwis sprawdza RAW grant, ale brak wyraźnej kontroli roli ADMINISTRATOR z UX. Zachować odrębne `RAW_MATERIALS_REFERENCE_PRICE_*`, `RAW_MATERIALS_PURCHASING_COSTS_*`, `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW`; nie zastępować jednych drugimi.
4. **BLOKER — konflikt edycji i kompletność (#68, #75–#77, #81).** Dodać token/ID oczekiwanej rewizji i odrzucenie starego formularza; udostępnić serwerowy wynik walidacji zapisu/aktywacji i dozwolony następny krok. Nie tworzyć nowych wymagań aktywacji przed potwierdzeniem źródła decyzji.
5. **WAŻNE — katalog (#03, #21–#23).** Dodać zatwierdzone filtry dostawcy/jednostki, sorty i wielkości stron; operacje archiwizacji/eksportu dla zaznaczonego zbioru. Nie utożsamiać ich z istniejącym bulk share.
6. **WAŻNE — historia/audyt (#45–#46, #08).** Zapewnić read-only historię audytu RAW i dane aktualizacji wymagane przez UX; zdefiniować deduplikację identycznych ręcznych cen.
7. **WAŻNE — cykl dokumentów (#38, #95–#98).** Zdefiniować trasy upload tymczasowego, listy/pobrania/podglądu, przypisania do rewizji, odłączenia bez niszczenia historii oraz kontrakt wieloplikowy.
8. **WAŻNE — usuwanie draftu (#19, #93).** Endpointu nie ma. Przed dodaniem trzeba ściśle ograniczyć go do szkicu nigdy niezatwierdzonego i wykonać kontrolę zależności, zgodnie z zatwierdzoną decyzją.

## Funkcje możliwe wyłącznie w frontendzie

Na podstawie lokalnych streszczeń punkty: **#01–02 (sam układ), #09 (wykres), #16, #18, #20, #24–26, #29–36, #39, #41–43, #47–50, #52–67, #69–74 (sam układ/interakcje), #78, #80, #82–84, #91–92, #94, #99**. Nie oznacza to, że te widoki istnieją — bieżący `frontend/src` nie zawiera żadnego RAW UX. Punkty zależne od brakujących danych/endpointów (np. #04, #23, #66, #73–76) nie mogą być uznane za całkowicie frontend-only.

## API i granice autoryzacji

W kodzie istnieją następujące kontrakty (nie oznacza to ich dostępności produkcyjnej — bramka `RAW_MATERIALS` zwraca 503):

- **Katalog:** `GET /api/v1/raw-materials` z `search`, `category_id`, `lifecycle_state`, `sort` (`code|name|lifecycle_state`), `direction`, `page`, `per_page` (1–100, domyślnie 20); `GET /{material}`; paginacja z `current_page/per_page/total/last_page`.
- **Tworzenie/edycja:** `POST /api/v1/raw-materials` oraz `PATCH /{material}/draft`; `category_id`, `name`, `base_unit_code` wymagane, SKU nie jest przyjmowane przez request. `GET /{material}/revisions`; POST-y submit/approve/return/block/unblock/archive/restore.
- **Alergeny/przechowywanie:** `GET /allergen-catalog`, `PUT /{material}/draft/allergen-declaration`, `PUT /{material}/draft/storage-variants`, `GET /{material}/revisions/{revision}/stage2d`.
- **Ceny:** `GET/POST /{material}/reference-prices` (historia `as_of`, zapis z `expected_event_no` i kluczem idempotencji); ceny ofert `/supplier-offers/{offer}/price-events`; eksport cen `/reference-price-exports` oraz pobranie autoryzowane manifestem.
- **Import/eksport katalogu:** `/imports`, `/catalog-imports`, `/catalog-exports`, `/catalog-exports/extended`, osobne trasy commit/result/download. Profile i kolumny są stałe; nie ma wyboru dowolnego zestawu kolumn.
- **Udostępnianie:** `POST /{material}/shares/bulk` z `location_ids`; `POST /bulk-shares/by-location` z `location_id` i `material_ids`; `DELETE /{material}/shares/{location}`. Bulk 3D.1 ma limit 100, deduplikuje cele i jest atomowy. Wynik zawiera `outcome` i `data` (m.in. `created`, `already_active`, `duplicates`, `invalid_targets`); dozwolone błędne cele wracają jako `422`, nieautoryzowany/obcy cel jako ogólna odmowa bez ujawniania informacji.

Wszystkie trasy RAW przechodzą middleware sesji SPA, Sanctum, walidacji aktywnej tożsamości/kontekstu operacyjnego, audytu odrzuceń i `RequireRawMaterialsAvailability`. `DefinedModuleCatalog` tworzy `RAW_MATERIALS` z `ModuleDefinition::ready=false`; `ModuleConfigurationTest` obejmuje NOT_READY i odmowę aktywacji. `RawMaterialAuthorization` sprawdza aktywność aktora/organizacji/lokalizacji, członkostwo i właściwy grant. CRUD katalogu wymaga kontekstu primary; polityka share wymaga jawnego aktywnego share dla odbiorcy.

### K3 i bulk share

- **K3.4 — druga kuchnia centralna:** aktualny kod traktuje primary przez jawny `is_primary_central_kitchen`; każda inna lokalizacja tej organizacji podlega wymogowi aktywnego share. Nie ma filtra „tylko RESTAURANT” w `RawMaterialLocationSharePolicy::canConsume`/`constrainVisibleMaterials` ani w walidacji celów bulk, więc wtórna `CENTRAL_KITCHEN` jest technicznie odbiorcą po udostępnieniu. Nie znalazłem osobnego testu tworzącego drugą centralną kuchnię; istniejący test udostępnienia sprawdza restaurację. Stare twierdzenie w początkowej macierzy K3 o braku tej granicy jest nieaktualne względem bieżącego kodu.
- **K3.9–K3.10:** wcześniejsze zalecenia o częściowym sukcesie są sprzeczne z późniejszą decyzją z 2026-11-09. Rejestr [aneksu decyzji](./VELTRA_STAGE_2F_RAW_DECISION_AMENDMENTS_2026-11-09.md) wymaga pełnej atomowości i nie ujawniania celów spoza zakresu; obecny `RawMaterialLocationShareAdministration::bulkShare` realizuje tę regułę, ponownie walidując pod blokadami i zapisując odmowę poza rollbackiem.
- **K3.18–K3.20:** historyczny wymóg dwóch administratorów został wycofany. Formalne wycofanie ma należeć wyłącznie do pierwotnej tożsamości SUPER ADMIN; zwykły ADMINISTRATOR nie może go wykonywać. Nie istnieje endpoint formalnego wycofania, więc nie ma ścieżki, którą ADMIN mógłby wywołać. Osobne wyłączenie modułu w API konfiguracji lokalizacyjnej nie jest formalnym wycofaniem. Wymagane są świeża reautentykacja hasłem i pełny audyt, ale stany, zależności, safety blocks, skutki i odzyskiwanie pozostają niedookreślone.
- **K3.21 / Production:** brak zatwierdzonego backendowego cyklu rozpoczęcia partii/zużycia, zamrożonej rewizji RAW, kontynuacji po revoke lub źródła food-safety block. Produkcyjny start, historyczne powiązanie rewizji i wyjątki bezpieczeństwa pozostają **BLOCKED BY PRODUCTION / OWNER DECISION** zgodnie z [raportem preflight 3C](./VELTRA_STAGE_2F_IMPLEMENTATION_3C_RAW_HISTORICAL_INTEGRITY_REPORT.md). Nie wolno symulować ich w katalogowym UI ani w RAW tabelach.

## Niezgodności starszych materiałów

1. Starszy audyt K3 opisuje brak share dla wtórnych central; bieżące 3A/3B i `RawMaterialLocationSharePolicy` filtrują odbiorców po aktywnym udostępnieniu także dla nie-primary kitchen.
2. Stare K3.9–K3.10 dopuszczające/celujące w częściowe bulk są zastąpione pełną atomowością 3D.1.
3. Stare K3.18–K3.20 opisujące drugiego administratora są zastąpione wyłączną kontrolą pierwotnej tożsamości SUPER ADMIN. Historyczne pliki pozostawiono; obowiązujący zapis zmian znajduje się w aneksie.
4. Zapis o częściowym wyniku w uploadzie wielu dokumentów (#98 w lokalnym streszczeniu UX) dotyczy osobnej operacji. Nie zmienia atomowości udostępniania materiałów.

## Proponowane minimalne kontrakty — wyłącznie propozycje do zatwierdzenia

1. `POST /api/v1/raw-materials/sku-reservations` → trwała, niemodyfikowalna rezerwacja SKU RAW, zwracająca `reservation_id` i `sku`; rezerwacja nie jest zwalniana dla porzuconego formularza. Zapis szkicu konsumuje rezerwację w transakcji. Limit zatwierdzić jako 999999, z testami równoległymi.
2. Wprowadzić `expected_revision_id` (lub równoważny token) do wszystkich mutacji rewizji; niezgodność bieżącej wersji zwraca 409 wraz z identyfikatorem aktualnej wersji. Nie dopuścić, by równoczesne żądanie zastępowało niezauważalnie zmianę innego edytora.
3. Udostępnić `GET /{material}/completion` z rozdzieleniem błędów uniemożliwiających zapis od braków do aktywacji oraz dozwolonym następnym krokiem. Zawartość walidacji aktywacji musi pochodzić z dokładnego, dostępnego tekstu zatwierdzonego UX/K3.
4. Uzupełnić query katalogu i payload po odczytaniu źródłowych 100 decyzji: zatwierdzone osiem kolumn, filtry/sorty, rozmiary stron, pola ilości i jednostkowe obliczenia kosztu.
5. Dodać osobne read-only API historii audytu, a dla dokumentów kompletne API listy/upload-staging/attach/download oraz odłączenia wersji z zachowaniem historii. Kontrakt retencji/TTL i zachowania plików musi być zatwierdzony.
6. Rozważyć usuwanie szkicu tylko z warunkiem: nigdy nie zatwierdzony, brak zależności, właściwy grant i trwały audyt. Nie tworzyć endpointu przed potwierdzeniem dokładnej reguły.

Powyższe są propozycjami kontraktów, nie decyzjami biznesowymi ani zmianami kodu.

## Rekomendowana kolejność prac frontendowych

1. Udostępnić tekstowe, zatwierdzone źródło decyzji #01–#100 i potwierdzić dokładne osiem kolumn oraz formuły. Zamknąć braki owner-decision z K3 i formalnego retirement.
2. Przygotować wyłącznie strukturę/konwencje UI i klient API; zachować fail-closed status. Nie przedstawiać `RAW_MATERIALS` jako aktywnego, skoro backend zwraca 503.
3. Po uzgodnieniu kontraktu katalogu zbudować listę, filtrację, paginację oraz read-only panel na istniejących endpointach; oznaczyć brakujące pola z #04 jako blokadę, nie zastępować danymi pozornymi.
4. Po udostępnieniu prealokacji SKU, konfliktów rewizji i completion API zbudować edytor. W obecnym kontrakcie nie da się spełnić przedzapisowego SKU ani bezpiecznie ostrzec o starym formularzu.
5. Podłączyć rewizje, alergeny, przechowywanie, ceny i dostawców z zachowaniem osobnych grantów finansowych; potem dokumenty po dodaniu tras upload/list/download.
6. Dodać administration/share w dwóch kierunkach, korzystając z istniejącego atomowego API 3D.1; dopiero osobno planować wybrane masowe archiwizacje/eksporty.
7. Testować dostępność, tenant scope, admin-only cen, rewizje, share/revoke i podział ról w kontraktowych testach API, zanim UI będzie uznawane za gotowe.

## Status końcowy

- **RAW_MATERIALS:** `NOT_READY`; brak aktywacji. Normalne endpointy RAW są zamknięte bramką dostępności.
- **Frontend RAW:** niezaimplementowany w aktualnym `frontend/src`.
- **Backend:** część katalogu/revizji/cen/share istnieje, ale zgodna implementacja pełnego UX wymaga wskazanych luk.
- **Production:** start/zużycie, immutable RAW revision reference, continuation after revoke i safety block są zablokowane do zatwierdzenia/udostępnienia domeny Production i jej kontraktu.
- **Formal retirement:** brak API; nie utożsamiać z disable per location; pozostałe decyzje właściciela wymieniono powyżej.
- **Zmiany wykonane w Stage 3E:** wyłącznie ten raport. Nie zmieniono kodu, konfiguracji, bazy, kontenerów ani wolumenów; nie wykonano migracji, testów, deploymentu, commitu ani push.
- **Dysk Google:** uwierzytelniona sesja i folder `RAPORTY_COPILOTA` były dostępne; wyszukanie pełnej nazwy potwierdziło brak istniejącej kopii. Upload nie powiódł się, ponieważ przeglądarka próbowała odczytać lokalny plik Linux jako `C:\home\veltra\...`, gdzie pliku nie ma. Nie ponawiałem prób; pełny raport pozostaje zapisany lokalnie.
