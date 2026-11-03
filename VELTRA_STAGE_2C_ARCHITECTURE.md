# VELTRA 2.0 — Architektura Stage 2C
## Dostawcy, produkty i opakowania, ceny referencyjne oraz waluty

**Status:** propozycja architektury do przeglądu biznesowego  
**Zakres:** projekt Stage 2C; bez implementacji, migracji, zmian danych ani aktywacji modułu  
**Rekomendacja:** **CONDITIONAL ACCEPT** — projekt jest spójny z Stage 2A i Stage 2B, ale przed implementacją wymagają decyzji kwestie wskazane w rozdziale 12 i w raporcie przeglądowym.  
**Gotowość modułu:** `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywny.

## 1. Cel i granice

Stage 2C rozszerza istniejący wspólny katalog organizacji o dostawców surowców, produkty dostawców, ich identyfikatory i konfiguracje opakowań, historyczne oferty zakupu, jedną centralną cenę referencyjną netto na surowiec oraz zdarzenia kursów walut. Stage 2C nie tworzy lokalnych kopii surowców ani nie utożsamia produktu lub opakowania dostawcy z definicją master.

Projekt zachowuje zatwierdzone decyzje Stage 2A B1–B8. Szczególnie:

- odczyt katalogu nie ujawnia cen; cena referencyjna i koszt zakupu wymagają rozłącznych, jawnych uprawnień (B1);
- blokada/archiwizacja nie usuwa referencji ani historii i nie dopuszcza nowych użyć (B2);
- deklaracja alergenów i zgodność produktu pozostają odrębnymi zatwierdzanymi procesami Stage 2D (B3);
- rzeczywisty koszt partii Inventory/Production ma pierwszeństwo przed ceną referencyjną; cena referencyjna jest źródłem **szacunku**, a nie kosztem rzeczywistym (B4);
- `RAW_MATERIALS` pozostaje osobnym kluczem Stage 1E, z bramką per lokalizacja i stanem NOT_READY do pełnej implementacji oraz weryfikacji (B5);
- granty są organizacyjne, z kontrolowaną delegacją; role lokalizacyjne nie przyznają dostępu do wspólnego katalogu (B6);
- PLN jest walutą raportową; zakupy w walutach obcych zachowują kwotę źródłową, kurs i precyzję, bez przedwczesnego zaokrąglania (B7);
- archiwizację i restore obsługują audytowane przejścia istniejącego lifecycle, bez usuwania historii i kodu (B8).

Poza zakresem są: zamówienia/umowy/rozrachunki dostawców, przyjęcia i partie magazynowe, faktury, płatności, automatyczny import notowań kursowych, workflow alergenów, dokumenty dostawców oraz obliczanie i zamykanie kosztu partii produkcyjnej. Stage 2C przygotowuje kontrakty, ale nie implementuje domen Inventory, Purchasing ani Production.

## 2. Stan istniejący i zgodność z Stage 2B

Przegląd objął zatwierdzoną [specyfikację Stage 2A](./VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md), [końcowy przegląd Stage 2A](./VELTRA_STAGE_2A_FINAL_ARCHITECTURE_REVIEW.md), [raport implementacji Stage 2B](./VELTRA_STAGE_2B_IMPLEMENTATION_REPORT.md), [raport remediacji bezpieczeństwa Stage 2B](./VELTRA_STAGE_2B_SECURITY_REMEDIATION_REPORT.md), [końcowy raport RBAC Stage 2B](./VELTRA_STAGE_2B_FINAL_RBAC_FIX_REPORT.md), [końcowy audyt bezpieczeństwa Stage 2B](./VELTRA_STAGE_2B_FINAL_SECURITY_AUDIT.md) oraz aktualne migracje, serwisy, permissiony, moduł i trasy backendu.

| Obszar | Stan faktyczny istotny dla Stage 2C |
|---|---|
| Organizacja i tenant isolation | Dane są zakresowane po `organization_id`; Stage 2B używa złożonych FK, `ON DELETE RESTRICT`, blokad wiersza organizacji i PostgreSQL. Nowe tabele muszą zachować te same granice. |
| Katalog surowców | `raw_materials` to wspólna tożsamość organizacyjna z niezmiennym `SUR-*`; zatwierdzone i robocze dane znajdują się w rewizjach. Zmiany surowca i jednostek wymagają istniejącego workflow rewizji, a nie modyfikowania snapshotu. |
| Jednostki | `raw_material_revision_units` zawiera kody `mg`, `g`, `kg`, `ml`, `l`, `pc`, wymiar, rodzaj konwersji, `numeric(24,12)` factor i podstawę. Konwersja zależna od surowca jest właściwa dla konkretnej rewizji. |
| Autoryzacja | Stage 2B ma organizacyjne granty i sprawdza aktualny łańcuch delegacji, członkostwo i aktywność. `RawMaterialPermission` zawiera prawa katalogowe, ale nie zawiera jeszcze praw dostawców, cen referencyjnych, zakupowych ani FX. Migracja Stage 2B ma allowlistę permissionów w `CHECK`; Stage 2C musi rozszerzyć zarówno kod, jak i constrainty, nie zmieniając historycznych grantów ani omijając trwałego unieważniania po zawieszeniu. |
| Gotowość | `DefinedModuleCatalog` zawiera `RAW_MATERIALS` z permission kotwiczącym `RAW_MATERIALS_VIEW`; `ModuleDefinition.ready` domyślnie wynosi `false`. To jest właściwy stan. Obecność klucza nie oznacza gotowości ani aktywacji. |
| Dostawy lokalizacji | Istniejąca tabela `location_supply_relationships` opisuje relację kuchnia centralna → restauracja. Nie jest katalogiem kontrahentów ani dostawców produktów i nie wolno jej używać do modelowania Stage 2C. |
| Ceny i kursy | W aktualnym backendzie nie ma tabel cen surowców, cen ofert ani kursów wymiany. Schemat Stage 2A dla Stage 2C jest propozycją, nie działającą funkcją. |
| API | Trasy są wersjonowane `/api/v1`; kontrolery korzystają z request validation i odpowiedzi zasobów/`data`. Komunikaty dla użytkownika są po polsku. |
| Audyt i współbieżność | Stage 2B zapisuje audyt append-only oraz serializuje mutacje organizacji. Zapis zdarzenia biznesowego i jego audytu musi być atomowy; aktualne granty i ich unieważnienia pozostają niezmienne. |

Wyniki audytu Stage 2B są warunkiem wejściowym, nie zamiennikiem kontroli Stage 2C: końcowy audyt bezpieczeństwa Stage 2B ma werdykt ACCEPT, ale wskazuje ograniczenia operacyjne i nie autoryzuje wdrożenia. Etap 2C nie może ustawić `ready=true`, aktywować lokalizacji ani dodać produkcyjnego bypassu NOT_READY.

## 3. Zasady domenowe

### 3.1 Własność i rozdzielenie pojęć

1. `raw_materials` pozostaje jedyną centralną definicją składnika, należącą do organizacji.
2. `organization_suppliers` to organizacyjny rejestr kontrahentów; jeden dostawca może oferować wiele produktów i wiele ofert.
3. `supplier_products` opisuje relację dostawca–surowiec oraz zewnętrzny produkt/SKU. Produkt dostawcy, GTIN, jego opakowanie i cena nie są tożsamością surowca.
4. Konfiguracja produktu i jego przelicznik są wersjonowane. Oferta cenowa wskazuje dokładną wersję produktu i opakowania, której dotyczy.
5. `supplier_offer_events` opisują oferty/zakupowe warunki dostawcy, a `raw_material_reference_price_events` opisują osobną, centralnie zatwierdzaną wartość foodcost. Zmiana jednego rodzaju ceny nigdy nie aktualizuje drugiego.
6. Rekordy cenowe Stage 2C nie są dowodem faktury ani rzeczywistego kosztu przyjętej partii. Koszt rzeczywisty należy do przyszłej domeny Inventory/Purchasing.

### 3.2 Zatwierdzony produkt i zgodność alergenowa

Stage 2C może rejestrować produkt dostawcy, jego opakowanie i ofertę, ale nie może oznaczyć go jako zgodnego alergenowo ani jako bezpiecznego do operacyjnego użycia. Zgodność pozostaje `PENDING`/nieustalona do czasu wdrożenia Stage 2D i zatwierdzenia przez uprawnioną osobę na podstawie wersji dowodu dostawcy, dla dokładnej rewizji surowca. Brak review, review niezgodne albo review dla starej rewizji nie może być traktowane jako zgodność. Dostępność ceny nie jest równoznaczna z dopuszczeniem produktu do zakupów lub produkcji.

## 4. Model PostgreSQL

### 4.1 Wspólne reguły

- PK to UUID; każda tabela organizacyjna ma `organization_id uuid NOT NULL`, indeksy rozpoczynają się od tenant scope, a FK do encji organizacyjnych zawierają `organization_id`.
- FK historyczne stosują `ON DELETE RESTRICT`; nie ma fizycznego usuwania użytych dostawców, produktów, rewizji, ofert, cen, kursów ani zdarzeń audytu.
- Daty zdarzeń i obowiązywania są `timestamptz`; klient nie określa `created_at`, autora, organizacji, zatwierdzającego ani wyniku oceny.
- Ilości i kursy są `NUMERIC`, nigdy `float`/`real`; kwota zakupu zachowuje walutę źródłową. Pola i skale proponowane poniżej wymagają potwierdzenia decyzją D-04.
- Każdy historyczny wiersz cenowy/FX/wersji konfiguracji jest append-only, chroniony triggerem PostgreSQL przed `UPDATE` i `DELETE`. Bieżące wartości wyznacza się przez projekcję najnowszego zdarzenia obowiązującego w danym czasie, a nie przez edycję rekordu historii.
- Nowe tabele oraz API używają kluczy stabilnych i kaskadowego org scope. Tenant scope z requestu nie jest źródłem prawdy: organizacja pochodzi z uwierzytelnionego aktora.

### 4.2 `organization_suppliers`

Jeden rekord oznacza dostawcę w zakresie jednej organizacji. Nie przechowuje danych bankowych, danych logowania ani niepotrzebnych danych kontaktowych.

| Kolumna | Typ | Reguła |
|---|---|---|
| `id` | `uuid` PK | Stabilna tożsamość. |
| `organization_id` | `uuid NOT NULL` | FK do organizacji, `RESTRICT`; `UNIQUE(id, organization_id)`. |
| `supplier_code` | `varchar(40) NOT NULL` | Kod wewnętrzny, unikalny w organizacji; nie jest numerem podatkowym. |
| `display_name` | `varchar(200) NOT NULL` | Niepusta nazwa widoczna po polsku/oryginalna nazwa dostawcy. |
| `legal_name` | `varchar(240) NULL` | Opcjonalna nazwa prawna; dostęp ograniczony do właściwego permission. |
| `tax_identifier` | `varchar(80) NULL` | Opcjonalny identyfikator; szyfrowanie/retencja i ekspozycja wymagają decyzji organizacyjnej. |
| `lifecycle_state` | `varchar(16) NOT NULL` | `ACTIVE`, `ARCHIVED`; stan nie kasuje produktów ani historii. |
| `created_by`, `created_at` | UUID, `timestamptz` | Autor i czas serwera; FK złożony do organizacji/użytkownika. |

Indeksy: `UNIQUE(organization_id, supplier_code)`, indeks wyszukiwania `(organization_id, lifecycle_state, display_name)`. Normalizacja nazwy może wskazywać możliwy duplikat, ale nie scala rekordów automatycznie.

### 4.3 `supplier_products` i niezmienne wersje produktu/opakowania

`supplier_products` to stabilna relacja dostawcy z centralnym surowcem. Wiele rekordów może istnieć dla jednego surowca, w tym kilka SKU tego samego dostawcy.

| Tabela | Istotne kolumny | Klucze/reguły |
|---|---|---|
| `supplier_products` | `id`, `organization_id`, `supplier_id`, `raw_material_id`, `lifecycle_state`, `created_by`, `created_at` | `UNIQUE(id, organization_id)`; FK złożone do dostawcy i surowca; stan `ACTIVE`/`ARCHIVED`; brak fizycznego delete. |
| `supplier_product_revisions` | `id`, `organization_id`, `supplier_product_id`, `revision_no`, `raw_material_revision_id`, `supplier_sku`, `gtin`, `product_name`, `purchase_unit_code`, `components_hash`, `content_hash`, `created_by`, `created_at` | `UNIQUE(supplier_product_id, revision_no)` i złożone FK do dokładnego produktu, organizacji, materiału/revizji. Immutable; nowa konfiguracja/SKU tworzy nową rewizję. |
| `supplier_product_packaging_components` | `id`, `organization_id`, `product_revision_id`, `component_no`, `component_count numeric(24,9)`, `content_quantity numeric(24,9)`, `content_unit_code varchar(24)`, `raw_material_revision_unit_id uuid`, `to_base_factor_snapshot numeric(24,12)`, `base_quantity_snapshot numeric(30,12)` | Unikalny `(product_revision_id, component_no)`; wszystkie komponenty wskazują ten sam produkt, surowiec i rewizję bazową. Ilości/factor dodatnie. Immutable. |

`purchase_unit_code` opisuje jednostkę zamawianą, np. `PACKAGE`, `CASE`, `BAG`, a nie nową jednostkę konwersji surowca. Konfiguracja opakowania określa ilość zawartości i jednostkę pomiaru, liczbę komponentów oraz przelicznik do jednostki bazowej z konkretnej zatwierdzonej rewizji surowca. Suma `base_quantity_snapshot` to niezmienna ilość bazowa na jedną jednostkę zakupu. Serwer wylicza wynik dziesiętnie i porównuje go z wejściem/limitem; klient nie wysyła wiążącego współczynnika.

Każda rewizja produktu przechowuje `raw_material_revision_id` i snapshot podstawy/przelicznika, nawet jeśli jednostka bazowa aktualnie się nie zmieniła. Zmiana konwersji w nowej rewizji surowca nie przelicza już istniejących ofert; wymaga nowej rewizji produktu i nowych zdarzeń ofertowych przypiętych do tej konfiguracji. GTIN i SKU normalizuje serwer; unikalność SKU (gdy niepuste) egzekwuje się w obrębie dostawcy i organizacji. Nie zakłada się globalnej unikalności SKU.

### 4.4 `supplier_offers` i `supplier_offer_events`

`supplier_offers` to trwała tożsamość oferty handlowej, odrębna od produktu dostawcy i od ceny referencyjnej.

| Tabela | Istotne kolumny | Klucze/reguły |
|---|---|---|
| `supplier_offers` | `id`, `organization_id`, `supplier_product_id`, `offer_reference`, `created_by`, `created_at` | FK złożony do produktu; unikalny `offer_reference` w zakresie produktu, jeżeli istnieje. Nie przechowuje mutable „aktualnej ceny”. |
| `supplier_offer_events` | `id`, `organization_id`, `offer_id`, `supplier_product_revision_id`, `event_no`, `event_type`, `valid_from`, `recorded_at`, `net_amount_per_purchase_unit`, `currency_code`, `minimum_purchase_quantity`, `source_code`, `source_reference`, `source_document_version_id NULL`, `recorded_by`, `reason_code`, `operation_id` | `event_type` = `PRICE_SET` lub `WITHDRAWN`; `event_no` rośnie pod lockiem oferty; zdarzenie wycofania nie ma kwoty. FK do dokładnej wersji produktu/opakowania. `PRICE_SET` wymaga `net_amount_per_purchase_unit >= 0`, ISO 4217 i dodatniego MOQ, jeśli podane. Append-only. |
| `supplier_offer_fx_normalization_events` | `id`, `organization_id`, `supplier_offer_event_id`, `exchange_rate_event_id`, `rate_snapshot numeric(24,12)`, `reporting_amount_pln_per_purchase_unit numeric(30,14)`, `reporting_amount_pln_per_base_unit numeric(30,14)`, `normalized_at`, `recorded_by`, `operation_id` | Append-only snapshot wyliczenia PLN dla dokładnego eventu ceny i rewizji opakowania. FK złożone; korekta tworzy nowy event korekcyjny, nie aktualizuje snapshotu. |

Cena oferty jest kwotą netto w walucie źródłowej za dokładnie jedną jednostkę zakupu z rewizji produktu. Ilość minimalna jest wyrażona w tych samych jednostkach zakupu. Kwota pozostaje źródłowa; do raportowania system używa osobnego, niezmiennego snapshotu kursu i wyliczonego PLN. Cena w walucie obcej może być zachowana jako `SOURCE_ONLY`, gdy nie ma zatwierdzonego kursu; w takim stanie nie wolno jej sumować/porównywać w PLN ani używać do normalizacji ceny referencyjnej. Późniejsze uzyskanie kursu tworzy `supplier_offer_fx_normalization_events`, nie aktualizuje źródłowego eventu. Zmiana warunków/ceny/terminu to nowe zdarzenie, nie UPDATE poprzedniej oferty. Na tym etapie oferta nie oznacza zamówienia, potwierdzenia dostawy ani kosztu partii.

### 4.5 `raw_material_reference_price_events`

Jeden strumień zdarzeń na surowiec reprezentuje centralną cenę referencyjną netto używaną jako wejście foodcost. Propozycja techniczna: wartością kanoniczną do porównywania i foodcost jest PLN za jednostkę bazową. Jeśli cenę ustalono na podstawie oferty w walucie obcej, zachowujemy źródłową kwotę, walutę, wersję produktu/oferty i snapshot FX, z którego wyprowadzono PLN.

| Kolumna | Typ/propozycja | Reguła |
|---|---|---|
| `id`, `organization_id`, `raw_material_id` | UUID | PK i FK złożone do surowca tej organizacji. |
| `event_no` | `integer` | Monotoniczny w strumieniu surowca, przydzielany pod blokadą. |
| `event_type` | enum/check | `PRICE_SET` albo `PRICE_WITHDRAWN`; withdrawal wyłącza cenę od jego `effective_from`. |
| `effective_from`, `recorded_at` | `timestamptz` | Czas skuteczności i niezmienny czas zarejestrowania. Wybór strefy biznesowej wymaga zatwierdzenia D-02. |
| `net_pln_per_base_unit` | `numeric(30,14) NULL` | Kanoniczna cena PLN netto/baza dla `PRICE_SET`; brak kwoty dla withdrawal. |
| `source_net_amount`, `source_currency_code` | `numeric(24,8) NULL`, `char(3) NULL` | Źródło ceny, jeśli dotyczy; nie jest wyświetlane bez właściwego permission. |
| `source_offer_event_id`, `source_product_revision_id` | UUID NULL | Opcjonalne pochodzenie; FK złożone i RESTRICT. Cena referencyjna może być ustalona niezależnie od oferty. |
| `exchange_rate_event_id`, `rate_snapshot` | UUID NULL, `numeric(24,12) NULL` | Kurs dokładnie wykorzystany dla źródła obcego; przy `PLN` zapis tożsamości 1 lub jawnie zatwierdzony odpowiednik. |
| `recorded_by`, `reason_code`, `operation_id` | UUID, kody, UUID | Autor, powód i korelacja audytu. |

Ograniczenia: `UNIQUE(raw_material_id, event_no)`, kwoty nieujemne, kod waluty z rejestru ISO 4217, walidacja spójności pola źródłowego i kursu, brak UPDATE/DELETE. Zapis ceny wymaga `RAW_MATERIALS_REFERENCE_PRICE_MANAGE`; oferta i źródłowa cena zakupu wymagają dodatkowo odrębnego permission kosztów zakupu. Nie wolno tworzyć kopii ceny w zwykłym endpointcie katalogu.

Odczyt „as of” sortuje po `effective_from DESC`, a przy tej samej chwili po `event_no DESC`; wybiera ostatnie zdarzenie, które może być również wycofaniem. Wsteczna korekta jest nowym zdarzeniem i nie przepina zapisanych snapshotów. Kwestia, czy korekta ma zmieniać przyszłe wyniki „as of” dla już zamkniętych okresów, wymaga decyzji D-03; nie może zmienić zamkniętej partii ani historycznego snapshotu.

### 4.6 `organization_exchange_rate_events`

Kurs jest zdarzeniem organizacji, a nie konfigurowalnym, mutowalnym ustawieniem. Proponowany kierunek pary: liczba PLN za jedną jednostkę waluty obcej; kurs PLN/PLN jest tożsamością 1 i nie wymaga zewnętrznego notowania.

| Kolumna | Typ/propozycja | Reguła |
|---|---|---|
| `id`, `organization_id` | UUID | PK, zakres organizacji. |
| `base_currency_code` | `char(3)` | Zawsze `PLN`. |
| `quote_currency_code` | `char(3)` | ISO 4217; różna od `PLN` dla zewnętrznego kursu. |
| `pln_per_quote_unit` | `numeric(24,12)` | Wartość dodatnia i skończona; żadnych kursów zerowych/ujemnych. |
| `effective_at`, `recorded_at` | `timestamptz` | Czas obowiązywania źródłowego kursu i czas wprowadzenia do VELTRA. |
| `source_code`, `source_rate_id` | `varchar`, `varchar NULL` | Źródło, identyfikator notowania/serii; tekst z allowlisty lub walidowanego rejestru, nie dowolny URL. |
| `revision_no`, `supersedes_id` | integer, UUID NULL | Korekta tego samego punktu effective-time tworzy nowy event; nie zmienia historycznego rekordu. |
| `recorded_by`, `reason_code`, `operation_id` | UUID/kody | Autor, przyczyna i ślad audytowy. |

Indeks as-of: `(organization_id, quote_currency_code, effective_at DESC, revision_no DESC)`. Unikalność zdarzeń obejmuje organizację, walutę, chwilę obowiązywania i `revision_no`. FX event jest append-only. Każdy price event, który wymaga konwersji, zachowuje zarówno FK do kursu, jak i wartość kursu użytego w obliczeniu, walutę, kierunek, czas effective-time, źródło oraz kwotę PLN. Snapshot jest źródłem prawdy dla zapisanej ceny; późniejsza korekta kursu nie przelicza jej automatycznie.

Nie pobierać kursu z sieci synchronicznie podczas zapisu oferty/ceny. Dostarczenie notowań, źródło autorytatywne, godzina publikacji, dni bez notowania, kurs ręczny i korekta to decyzje biznesowo-finansowe D-01/D-02. Do chwili ich zatwierdzenia przewidziany jest kontrolowany, ręczny zapis autoryzowanym użytkownikiem, z rejestrowanym źródłem i powodem; nie jest to zgoda na domyślanie kursu.

### 4.7 `raw_materials_financial_audit` albo wspólny audyt domenowy

Preferowany wariant to użycie istniejącego append-only `raw_material_audit_events`, o ile jego kontrakt dopuszcza wymagane zdarzenia finansowe i metadane. Zdarzenia obejmują co najmniej `SUPPLIER_CREATED`, `SUPPLIER_PRODUCT_REVISION_CREATED`, `OFFER_PRICE_SET`, `OFFER_WITHDRAWN`, `REFERENCE_PRICE_SET`, `REFERENCE_PRICE_WITHDRAWN`, `FX_RATE_RECORDED` i `FX_RATE_CORRECTED`. Nie zapisują pełnych danych płatniczych, dokumentów, sekretów ani kwot poza uprawnionym zakresem audytu.

Jeśli tabela/constraint Stage 2B nie może bezpiecznie obsłużyć finansowych eventów, Stage 2C może dodać wyspecjalizowaną tabelę append-only z indeksem `(organization_id, occurred_at)`; nie może zmieniać znaczenia ani osłabiać obecnego audytu. Audyt i mutacja finansowa commitują atomowo. Błąd audytu oznacza rollback mutacji.

## 5. Cena zakupu, normalizacja i waluty

### 5.1 Normalizacja jednostki

Dla produktu z jedną jednostką zakupu:

`ilość_bazowa_na_jednostkę_zakupu = Σ(liczba_komponentów × ilość_zawartości × współczynnik_do_bazy_z_rewizji_surowca)`

`cena_źródłowa_na_jednostkę_bazową = kwota_netto_na_jednostkę_zakupu / ilość_bazowa_na_jednostkę_zakupu`

`cena_PLN_na_jednostkę_bazową = cena_źródłowa_na_jednostkę_bazową × PLN_na_jednostkę_waluty_źródłowej`

Operacje wykonuje PostgreSQL `NUMERIC` albo sprawdzona arytmetyka dziesiętna; nigdy binary float. Zapis obejmuje wszystkie operand-y oraz snapshot wyniku. Nie wolno normalizować przez niejawne, aktualne przeliczniki jednostek ani kurs „dzisiejszy”.

Warunki walidacji:

- dodatnia, ograniczona liczba komponentów i ilość zawartości; zakresy i maksima konfiguracyjne wymagają ustalenia przed implementacją;
- jednostka zawartości występuje w konkretnej zatwierdzonej rewizji `raw_material_revision_units`;
- wymiar musi być zgodny albo istnieje jawny, zatwierdzony przelicznik material-specific z basis; brak/UNKNOWN konwersji oznacza odmowę normalizacji;
- zapisuje się identyfikator rewizji surowca, wiersz jednostki, współczynnik, ilość wejściową, ilość bazową oraz wersję konfiguracji opakowania;
- zmiana quantity, UOM lub opakowania to nowa rewizja produktu; stara oferta pozostaje związana ze starą rewizją.

### 5.2 Precyzja i rounding

Propozycja zgodna ze Stage 2A: oferta źródłowa `numeric(24,8)`, przelicznik i kurs `numeric(24,12)`, ilości `numeric(24,9)` lub większa skala dla ułamkowych opakowań, wynik jednostkowy PLN minimum `numeric(30,14)`. Operacje pośrednie zachowują większą precyzję niż docelowe kolumny. Kwota do prezentacji może być zwykle formatowana do dwóch miejsc dziesiętnych, ale format prezentacji nie jest wartością obliczeniową.

Nie przyjmować arbitralnego `rounding_mode` z żądania API. Wartość źródłowa, surowy wynik arytmetyczny lub jego dokładne operand-y, wynik utrwalony i reguła skalowania muszą być możliwe do odtworzenia. Reguła punktu rounding (wyświetlenie, raport, wartość księgowa) i tryb rounding wymagają jawnej decyzji finansowej D-04.

### 5.3 PLN oraz waluty

- `PLN` jest reporting currency; każdy przeliczony koszt/opportunity record zachowuje źródłową kwotę i walutę oraz PLN snapshot.
- Kody walut są ograniczone do ISO 4217; obsługę zmian listy kodów należy wersjonować. Nie przyjmować arbitrary trzech liter.
- Waluty nie mogą być porównywane ani sumowane bez użycia konkretnego kursu. Nie stosować kursu jednego dostawcy/oferty do drugiej bez jawnego wskazania.
- Nie wykonywać konwersji przez triangulację walut, jeżeli brak bezpośredniego kursu PLN/quote i jawnego zatwierdzenia D-01.
- Cena referencyjna ma jeden aktywny wynik na surowiec i czas, w PLN/base unit (propozycja D-05). Cena zakupu pozostaje w currency quote; kwota PLN jest pomocniczym snapshotem raportowym.

## 6. Oddzielenie kosztu szacowanego od rzeczywistego (B4)

Stage 2C ceny oznaczają dostawcę/ofertę albo cenę referencyjną, nie faktyczny koszt partii. Kanoniczny kontrakt przyszłego snapshotu Production/Inventory pozostaje:

1. Jeśli Inventory udostępni koszt rzeczywiście skonsumowanej partii, koszt produkcyjny pochodzi z tej partii (`cost_source = INVENTORY_BATCH`, `is_estimated = false`), z jej transakcji/ilości/kursu.
2. Jeśli rzeczywisty koszt partii nie jest dostępny, Production może użyć historycznej ceny referencyjnej PLN obowiązującej dla czasu zużycia (`cost_source = REFERENCE_PRICE_ESTIMATE`, `is_estimated = true`).
3. Snapshot utrwala `raw_material_id`, dokładną `raw_material_revision_id`, `supplier_product_revision_id`/ofertę tylko jeśli jest źródłem, ilość i jednostkę wejściową/bazową, współczynnik konwersji, kwotę, walutę, `exchange_rate_event_id`, kurs, wartość PLN, czas i identyfikator źródłowego eventu ceny.
4. Zamknięty batch nie przelicza się ponownie, jeżeli później zmieni się dostawca, opakowanie, cena, kurs, rewizja surowca albo koszt rzeczywisty zostanie uzupełniony. Ewentualna korekta księgowa ma osobny, jawny i audytowany workflow domeny Production/Finance.
5. Późniejsze Inventory/Purchasing nie może nadpisać ceny referencyjnej ceną zamówienia/rachunku, ani traktować historii ofert jako rzeczywistego kosztu partii.

## 7. RBAC, readiness i prywatność

### 7.1 Proponowane uprawnienia

Wszystkie są nowymi kluczami organizacyjnymi; nazwy i podział wymagają biznesowej akceptacji. Nie są nadawane rolom automatycznie.

| Permission | Dostęp |
|---|---|
| `RAW_MATERIALS_SUPPLIER_VIEW` | Zatwierdzone/aktywne dane dostawcy, SKU i opakowań bez kwot kosztów; nie ujawnia tax identifier, jeśli nie zatwierdzono go jako pola widocznego dla tej roli. |
| `RAW_MATERIALS_SUPPLIER_MANAGE` | Utworzenie, rewizje, blokowanie/archiwizacja dostawcy, produktu i konfiguracji opakowania. |
| `RAW_MATERIALS_PURCHASING_COSTS_VIEW` | Kwoty ofert zakupu i ich historia; odrębne od katalogu i cen referencyjnych. |
| `RAW_MATERIALS_PURCHASING_COSTS_MANAGE` | Rejestracja i wycofanie historycznych zdarzeń cen oferty oraz ich metadanych. |
| `RAW_MATERIALS_REFERENCE_PRICE_VIEW` | Kwota centralnej ceny netto PLN/base unit, jej historia i źródłowa kwota FX. |
| `RAW_MATERIALS_REFERENCE_PRICE_MANAGE` | Dodanie ceny referencyjnej/wycofania/korekty z reason, bez edycji historii. |
| `RAW_MATERIALS_EXCHANGE_RATE_MANAGE` | Dodanie/korekta kursu organizacyjnego; zdarzenie audytu wymagane. |

`RAW_MATERIALS_REFERENCE_PRICE_VIEW` nie przyznaje `RAW_MATERIALS_PURCHASING_COSTS_VIEW` ani odwrotnie. Zwykłe `RAW_MATERIALS_VIEW`, widoki katalogu, filtry, sort, paginacja, statystyki, cache, idempotency response i eksport nie zwracają kwot, waluty źródłowej ani szczegółów rate snapshot. Endpointy cenowe autoryzują oddzielnie każde żądanie.

Widoczność kursów prezentowanych bezpośrednio wymaga jednego z dedykowanych permissions ceny referencyjnej/kosztu zakupu dla powiązanego kontekstu. Zapis kursu jest oddzielną capability. Rola lokalizacyjna nigdy nie daje grantów organizacyjnych. Delegowanie/expiry/revoke/audyt zachowują Stage 2B model; grant utracony przy zawieszeniu nie odzyskuje skuteczności przez reaktywację.

### 7.2 Moduł

Każda trasa wymaga łącznie: aktywnego i zweryfikowanego aktora, aktywnego membership/lokalizacji w tej organizacji, właściwego jawnego org-scoped permission oraz istniejącego fail-closed checku `RAW_MATERIALS` dla zaufanej lokalizacji. `ready=false`, brak aktywacji, brak modułu albo niespójny scope oznacza odmowę. Nie ma testowego wyjątku w runtime, parametru `organization_id` uznanego za zaufany ani bypassu dla Administratora. Rozszerzenie tabeli/allowlist permissionów nie może włączyć gotowości.

## 8. API — kontrakty Stage 2C

Wszystkie poniższe trasy są **propozycją**. Uwierzytelnienie i dostępność modułu są kontrolowane w middleware; szczegółowe capability sprawdza domenowa polityka/serwis na każdej ścieżce. API nie przyjmuje `organization_id`, aktora, permission, statusu zgodności ani wyniku FX z body. ID są wyszukiwane zawsze w zakresie organizacji. Lista ma `page`, `per_page` (maks. 100), filtry/sort allowlist i odpowiedź `data`/`meta`.

### 8.1 Dostawcy i produkty

- `GET /api/v1/raw-material-suppliers` — `RAW_MATERIALS_SUPPLIER_VIEW`; nie zwraca ofert ani identyfikatorów podatkowych bez osobnego zatwierdzonego zakresu.
- `POST /api/v1/raw-material-suppliers` — `RAW_MATERIALS_SUPPLIER_MANAGE`; tworzy tenant-scoped dostawcę.
- `GET /api/v1/raw-materials/{rawMaterialId}/supplier-products` — zatwierdzone rewizje produktu/opakowania; nie zwraca cen.
- `POST /api/v1/raw-materials/{rawMaterialId}/supplier-products` — `SUPPLIER_MANAGE`; tworzy produkt i pierwszą wersję konfiguracji.
- `POST /api/v1/supplier-products/{productId}/revisions` — nowa niezmienna rewizja; wymaga `expected_revision_id` albo numeru wersji i ponownej walidacji rewizji surowca.
- `POST /api/v1/supplier-products/{productId}/archive` — jawny lifecycle event, bez usuwania historii.

Przykładowy payload dodania produktu:

```json
{
  "supplier_id": "uuid",
  "product_name": "Mąka pszenna typ 500",
  "supplier_sku": "M500-10",
  "gtin": "5900000000000",
  "purchase_unit_code": "BAG",
  "packaging_components": [{
    "component_count": "1",
    "content_quantity": "10",
    "content_unit_code": "kg"
  }],
  "raw_material_revision_id": "uuid"
}
```

Serwer weryfikuje scope dostawcy/surowca, aktywną zatwierdzoną rewizję, unit registry i konwersje z tej rewizji, oblicza bazową ilość i zapisuje niezmienny snapshot. Jeśli produkt jest `PENDING` pod kątem B3, odpowiedź i eksport nie mogą sugerować zgodności.

### 8.2 Oferty i koszty zakupu

- `GET /api/v1/supplier-products/{productId}/offers` — `SUPPLIER_VIEW`; kwota/currency/historyczne eventy tylko po `PURCHASING_COSTS_VIEW`, inaczej odpowiedź pozbawiona tych pól albo endpoint 403; rekomendowana osobna trasa cenowa poniżej.
- `POST /api/v1/supplier-products/{productId}/offers` — utworzenie tożsamości oferty z permission `PURCHASING_COSTS_MANAGE` i `SUPPLIER_MANAGE`.
- `GET /api/v1/supplier-offers/{offerId}/price-events` — `PURCHASING_COSTS_VIEW`.
- `POST /api/v1/supplier-offers/{offerId}/price-events` — `PURCHASING_COSTS_MANAGE`, nowe zdarzenie `PRICE_SET` lub `WITHDRAWN`, `Idempotency-Key` wymagany.

Przykładowe ustawienie ceny:

```json
{
  "event_type": "PRICE_SET",
  "net_amount_per_purchase_unit": "42.50000000",
  "currency_code": "EUR",
  "valid_from": "2026-11-03T11:00:00Z",
  "minimum_purchase_quantity": "1",
  "source_code": "SUPPLIER_QUOTE",
  "reason_code": "QUOTE_UPDATED"
}
```

Nieznany kurs/niezatwierdzone źródło nie skutkuje domyślnym kursem 1. Zapis ceny źródłowej może być oddzielony od PLN normalizacji do momentu wskazania skutecznego FX zgodnie z D-01/D-02; API ma wtedy zwrócić jawny stan `FX_RATE_REQUIRED` i nie przedstawiać kwoty jako przeliczonej. Błędy i pola `message`/`errors` są po polsku, kody są stabilne, np. `SUPPLIER_PRODUCT_REVISION_STALE`, `RAW_MATERIALS_FX_RATE_REQUIRED`, `RAW_MATERIALS_PRICE_CONFLICT`.

### 8.3 Cena referencyjna i kursy

- `GET /api/v1/raw-materials/{id}/reference-prices?as_of=...` — `REFERENCE_PRICE_VIEW`.
- `POST /api/v1/raw-materials/{id}/reference-prices` — `REFERENCE_PRICE_MANAGE`; wymaga `Idempotency-Key`, expected event version, daty skuteczności, kwoty PLN/base unit, powodu oraz opcjonalnego, jawnego źródła oferty/FX.
- `POST /api/v1/raw-materials/{id}/reference-prices/withdraw` — tworzy `PRICE_WITHDRAWN`; nigdy nie usuwa poprzedniej ceny.
- `GET /api/v1/exchange-rates?currency=EUR&as_of=...` — widoczność wg uprawnień przypisanego use-case; bez katalogowego price grantu nie ujawnia się kursowi publiczny endpoint.
- `POST /api/v1/exchange-rates` — `EXCHANGE_RATE_MANAGE`, źródło, quote currency, rate, effective_at i reason.
- `POST /api/v1/exchange-rates/{rateId}/corrections` — dodaje event korekcyjny; nie edytuje wskazanego rate.

Przykładowa odpowiedź finansowa:

```json
{
  "data": {
    "raw_material_id": "uuid",
    "event_id": "uuid",
    "event_no": 8,
    "effective_from": "2026-11-03T11:00:00Z",
    "net_pln_per_base_unit": "0.00425000000000",
    "reporting_currency": "PLN",
    "source": {
      "amount": "42.50000000",
      "currency": "EUR",
      "exchange_rate_event_id": "uuid",
      "pln_per_currency_unit": "4.123456789012"
    }
  }
}
```

Odpowiedź powyżej nie może trafić na zwykły endpoint katalogowy ani do osoby z wyłącznie `RAW_MATERIALS_VIEW`.

### 8.4 Polskie błędy i konflikt

Wspólny envelope:

```json
{
  "error": {
    "code": "RAW_MATERIALS_FX_RATE_REQUIRED",
    "message": "Brak zatwierdzonego kursu dla wskazanej waluty i daty.",
    "details": {"currency_code": "EUR"}
  }
}
```

Walidacja zwraca polskie komunikaty. `details` nie zawiera kwoty, nazwy dostawcy ani danych podatkowych, jeżeli aktor nie ma odpowiadającego permission. ID zasobu z innej organizacji zwraca odpowiedź niedającą się odróżnić od zasobu nieistniejącego.

## 9. Transakcje, konkurencja, idempotencja

1. Mutacja dostawcy/produktu/oferty/ceny/referencyjnej ceny/kursu rozpoczyna krótką transakcję PostgreSQL i blokuje `organizations.id FOR UPDATE` (lub węższy, konsekwentny lock parent row, jeżeli zostanie osobno wykazany równoważny invariant).
2. Pod lockiem ponownie sprawdza aktora, membership, permission, status i wersję surowca/produktu, aktywność modułu, expected version oraz event idempotency. Nie ufa preview ani walidacji wykonanej przed transakcją.
3. Wstawia immutable event i audit event w tej samej transakcji. Wyjątek lub błąd audytu oznacza rollback wszystkich zmian.
4. Unikalność i sekwencje `event_no`/`revision_no` są egzekwowane constraintem; przy konfliktach API zwraca 409 z polskim komunikatem, nie sukces ani cichy retry.
5. `Idempotency-Key` ma zakres `(organization_id, actor_user_id, endpoint/operation)` i `request_hash`. Ten sam klucz/hash odtwarza poprzedni rezultat wyłącznie po ponownej autoryzacji; inny hash to 409.
6. Kurs „as-of” i price event selection używają wspólnej reguły rozstrzygania effective-time oraz `revision_no`. Korekty nie aktualizują historii i nie zmieniają zapisanych snapshots.
7. Jeśli operacja obejmuje wiele rekordów, kolejność blokad jest stabilna (organizacja, surowiec/dostawca, produkt/oferta po UUID sortowanym rosnąco); wszystkie zmiany albo commitują razem, albo żadne.
8. Długie pobieranie notowań/dokumentów lub połączenia z dostawcą nie są wykonywane w transakcji ani pod blokadą organizacji.

## 10. Audyt i retencja

Każde zdarzenie zachowuje `organization_id`, `operation_id`, actor (albo jawny aktor systemowy zgodny z polityką audytu), czas serwera, subject ID, event code, reason, hash stanu przed/po i minimalne metadata. Dla źródła zewnętrznego zachowuje się identyfikator notowania/źródła, ale nie tokeny ani niezweryfikowane URL. Dane ofert i ceny mają audyt czytelny wyłącznie w dozwolonym finansowym scope.

Rewizje produktu/opakowania, price events, FX events, reference-price events i audyt są append-only. Korekta oznacza nowy event z `supersedes_id`/powodem; nie ma kaskadowego UPDATE. FK do referencji historycznych stosuje `RESTRICT`. Backup i retencja muszą zachować razem zdarzenia, kursy, rewizje, referencje oraz dokumenty Stage 2D wskazywane przez FK.

## 11. Zależności i roadmapa integracji

| Zależność | Wymaganie dla Stage 2C |
|---|---|
| Stage 2B | Korzystać z istniejącego `organization_id`, master, revisions, immutable units, triggerów append-only, grant chains i org lock. Nie nadpisywać tych struktur. Rozszerzyć permission enum/DB allowlist w sposób zgodny ze starymi grantami i pokryć migracją izolowaną. |
| Stage 2D — alergeny/dowody | Produkty Stage 2C mogą istnieć, ale zgodność pozostaje `PENDING` do review na podstawie dokumentu dostawcy i dokładnej rewizji alergenowej; brak zgodności blokuje zatwierdzone użycie. Nie implementować review w 2C. |
| Inventory | Oferta i referencyjna cena nie zastępują ceny/składowej kosztu przyjętej partii. Inventory definiuje rzeczywisty koszt partii i immutable receipt snapshot. |
| Purchasing | Konsumuje dostawcę, produkt/konkretną rewizję opakowania i ofertę. PO, faktura, negocjacje, zobowiązania i faktyczne ceny fakturowe są osobnym zakresem. |
| Production/Foodcost | Foodcost używa centralnej reference-price event i oznacza wynik szacowany. Production przy zamknięciu wybiera realny koszt skonsumowanej partii, jeśli istnieje; w innym razie snapshot ceny referencyjnej z B4. |
| HACCP | Nie konsumuje samej oferty jako dowodu bezpieczeństwa; wymagane dane deklaracji i zgodności z 2D oraz przyszłe kontrole partii. |
| Stage 1E | `RAW_MATERIALS` pozostaje NOT_READY; żadna migracja/definicja klucza lub grant nie może aktywować go per lokalizacja. |

## 12. Decyzje biznesowe do zatwierdzenia

Poniższe punkty nie zmieniają zatwierdzonych B1–B8. Są potrzebne do ustalenia konkretnej polityki operacyjno-finansowej.

| ID | Decyzja | Opcje | Rekomendacja | Uzasadnienie i konsekwencje |
|---|---|---|---|---|
| D-01 | Autorytatywne źródło FX i sposób publikacji | (A) ręcznie wprowadzane kursy z dokumentem/źródłem; (B) oficjalne źródło centralne (np. NBP) z importem zatwierdzanym; (C) źródło księgowe/umowny kurs organizacji; automatyczny feed lub ręczna kontrola. | B jako źródło domyślne po akceptacji Finance, z audytowanym override ręcznym; bez łączenia kilku źródeł w jednym przeliczeniu. | Zapewnia powtarzalność. Wymaga ustalenia licencji/availability/odpowiedzialności za awarie; kurs źródłowy zachować także po zmianie feedu. Nie zakładamy, że NBP jest już zatwierdzone. |
| D-02 | Czas obowiązywania, dni bez kursu i backdating | (A) data faktury/operacji; (B) data tabeli źródłowej; (C) data przyjęcia do systemu; wybór strefy czasowej i reguły dni bez publikacji. | Zachować osobno source effective date i recorded timestamp; używać source effective date do wyboru kursu po zatwierdzeniu reguły; brak kursu = jawny błąd, nie kurs 1 ani cichy carry-forward. | Umożliwia odtworzenie i rozróżnia spóźniony import od czasu źródła. Wymaga jasnej strefy/time-of-day i reguły weekend/święto przed API i raportami. |
| D-03 | Korekty wsteczne cen/kursów | (A) korekta wpływa tylko na nowe obliczenia; (B) przelicza otwarte raporty as-of; (C) restatement wyłącznie jawnie wybranych okresów. | C: zachować oryginalny snapshot; wszelkie restatementy tworzą nowy audytowany wynik i nie modyfikują zamkniętych partii. | Chroni historię i audyt. Wymaga odrębnego workflow/permission dla korekt finansowych; nie wdrożyć przeliczenia ukrytego w read query. |
| D-04 | Rounding księgowy i skale | (A) round half-up; (B) half-even; (C) reguła systemu księgowego; punkt rounding: linia, jednostka, suma, eksport. | Bez rounding pośredniego; końcowe rozliczenie zgodnie z pisemną polityką księgową (rekomendacja do uzgodnienia z systemem Finance), prezentacja 2 miejsc. | Zmiana punktu rounding może zmienić sumy faktur/raportów. Potrzebne przykłady testowe z kwotami granicznymi, ale nie wpływa na raw snapshots. |
| D-05 | Denominacja centralnej ceny referencyjnej | (A) zawsze PLN/base unit; (B) zachowuje walutę źródłową i osobno PLN; (C) wiele równoległych walut referencyjnych. | A jako jedyna cena kanoniczna PLN/base-unit; jeżeli pochodzenie jest foreign, przechować niezmienną źródłową kwotę, walutę i rate snapshot. | Utrzymuje jedną referencyjną wartość B1 i porównywalny foodcost B7. Wariant C naruszałby „jedną cenę referencyjną” w praktyce; wymagałby jawnej zmiany wymogu. |
| D-06 | Znaczenie opakowania i wycena wielopoziomowa | (A) jeden zakupowy unit z sumaryczną zawartością; (B) hierarchia case→pack→unit; (C) dowolny zestaw komponentów. | A w pierwszym wdrożeniu; liczba identycznych komponentów może być >1, a wynik sumaryczny jest snapshotem. | Ogranicza niejednoznaczność i upraszcza konwersje. Części/zestawy o różnych składach wymagają rozszerzenia po przeglądzie testów; nie wolno imputować brakujących ilości. |
| D-07 | Wielowalutowe oferty, progi i ważność | Czy ta sama oferta może mieć równoległe progi ilościowe/waluty/terminy, MOQ i termin ważności; czy `supplier_offer` ma własne SLA? | Dopuścić wiele offer identities/price events, jeden event wycenia jedną walutę, jednostkę zakupu i warunek MOQ; bez automatycznego wyboru „najlepszej” oferty. | Umożliwia wieloźródłowe zakupy bez mieszania jednostek. Automatyczny ranking wymaga osobnej polityki uwzględniającej dostawę, VAT, jakość i B3. |
| D-08 | Wspólny master dostawcy dla przyszłego Purchasing | (A) dostawcy żyją w domenie Surowców; (B) organizacyjny Supplier master wspólny dla Purchasing; (C) migracja do wspólnego master później. | B: projektować `organization_suppliers` jako kandydat na wspólny, tenant-scoped Supplier master z właścicielem domeny ustalonym przed kodowaniem. | Ogranicza duplikaty kontrahenta w Purchasing. Wymaga wyznaczenia ownera i pól wrażliwych; bez akceptacji nie tworzyć drugiej tabeli Supplier w przyszłym Purchasing. |
| D-09 | Prawa do odczytu danych kontrahenta i FX | (A) dane dostawcy widoczne ogólnym `RAW_MATERIALS_VIEW`; (B) osobny `SUPPLIER_VIEW`; waluty widoczne wraz z ceną czy przez nowe granty FX. | B; kwoty tylko przez osobne B1 price permissions; użycie kursu widoczne tylko w kontekście uprawnionej ceny, a admin kursów osobnym `EXCHANGE_RATE_MANAGE`. | Minimalizuje ujawnienie komercyjne i danych podatkowych. Wymaga jawnego bootstrap/delegacji nowych kluczy, bez automatycznego rozszerzenia wszystkich grantów katalogowych. |
| D-10 | Wymóg compatibility przed zakupem | Czy Stage 2C pozwala jedynie ewidencjonować ofertę, czy też wybrać ją do realnego PO przed pełnym Stage 2D. | Ewidencja oferty możliwa; wybór produktu do operacyjnego PO/zużycia wymaga B3 review. | Stage 2C nie implementuje dowodów/allergen workflow; akceptacja ceny nie może być interpretowana jako bezpieczeństwo. |

## 13. Plan weryfikacji implementacji

Wszystkie integracyjne/migracyjne testy uruchamiać wyłącznie na disposable PostgreSQL/Redis w zweryfikowanym, odizolowanym środowisku bez mountów aplikacyjnych. Nie wolno używać `migrate:fresh`/rollback/destructive suites do bazy aplikacyjnej.

| Obszar | Wymagane testy Stage 2C |
|---|---|
| Migracje i baza | FK composite tenant/material/supplier/revision; constraints ilości, waluty i event state; immutable trigger dla ofert/cen/FX; append-only audyt; idempotency; migration rollback/forward policy na izolowanym PostgreSQL. |
| RBAC/readiness | Macierz grant × endpoint; ogólny `RAW_MATERIALS_VIEW` nie ujawnia kosztu, source currency, offer, FX ani ceny referencyjnej; ceny referencyjne i zakupowe nie przyznają się wzajemnie; delegation, expiry/revoke/suspension; NOT_READY/disabled fail-closed na każdym route. |
| Tenant isolation | Podmiana supplier/material/product/offer/rate UUID w każdej metodzie; cross-org composite FK i odpowiedzi nieujawniające istnienia zasobu. |
| Produkt/opakowanie | Normalizacja pack quantity przez dokładną material revision; błędny wymiar, nieznany UOM, factor niezgodny z DB, stale revision, zero/ujemny/overflow quantity, duplicate SKU/GTIN. |
| Ceny/as-of | Wiele dostawców/ofert, concurrency na event numbering, valid-from future/backdated, withdrawal, same-time corrections, expected version, replay idempotency i rollback audytu. |
| FX | PLN identity, foreign direct quote, brak kursu, rate zero/ujemny, błędna waluta, stale/revised FX, korekta wsteczna i zachowanie istniejących source/PLN snapshots. |
| Precyzja | Graniczne wartości numeric, brak float, podział kwoty przez ilość niepodzielną, brak round intermediate; dokładne walidacje wartości oczekiwanych i końcowej prezentacji. |
| B4 history | Price event nie jest actual batch cost; snapshot z inventory batch ma precedence; brak inventory daje `is_estimated=true`; zamknięte snapshots pozostają identyczne po zmianie ceny/kursu/pack conversion. |
| B3/dependencies | Brak, pending, incompatible i stale compatibility review nie jest zatwierdzonym produktem; Stage 2C nie ustawia kompatybilności. |
| Audyt i błędy | Fault injection powoduje pełny rollback, brak audytu fałszywego sukcesu, brak kwot w error/logu i odpowiedzi dla nieuprawnionego; polskie messages. |
| Regresja | Pełny backend suite Stage 1A–1F/2B, test readiness NOT_READY oraz testy API i concurrency. |

## 14. Kryteria akceptacji Stage 2C

1. W organizacji może istnieć wiele dostawców, produktów i ofert dla jednego `raw_material_id`, bez tworzenia dodatkowej definicji master.
2. Produkt/konfiguracja jest historycznie odtwarzalna i wskazuje dokładną rewizję jednostek surowca oraz opakowania.
3. Każdy event kosztu zakupu zachowuje kwotę, walutę, jednostkę zakupu, effective time, źródło i product revision; nie aktualizuje ceny referencyjnej.
4. Cena referencyjna jest jednym centralnym event stream per materiał i zachowuje cenę PLN/base unit oraz (gdy ma zastosowanie) źródłową kwotę i niezmienny kurs.
5. Żaden odczyt/filtr/agregacja/cache/export katalogowy nie ujawnia ceny ani kosztu bez odrębnego permission; rozdzielone prawa nie można wzajemnie odziedziczyć.
6. Zapis historycznego kosztu nie zmienia się po późniejszej zmianie product package, conversion, price event, FX event lub supplier record.
7. Brak kompatybilności Stage 2D nie jest interpretowany jako zgodność ani zgoda na zakupowe/produkcyjne użycie.
8. Dane/zdarzenie/audyt zatwierdzają się atomowo; konflikty concurrency/replay są jawne i nie tworzą częściowych danych.
9. Wszystkie odczyty i zapisy są tenant-scoped; FK/trigger PostgreSQL i API wspólnie wymuszają izolację.
10. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne; nie ma produkcyjnego bypassu; Stage 1A–1F oraz Stage 2B security nie ulegają regresji.

## 15. Podsumowanie

Projekt proponuje rozszerzenie Stage 2B o tenant-scoped dostawców, wersje produktów/opakowań, append-only oferty i ceny, jedną referencyjną cenę foodcost oraz audytowane kursy PLN. Nie zmienia zatwierdzonych B1–B8 i nie myli ceny zakupu z kosztem rzeczywistej partii. Przed implementacją należy zatwierdzić D-01–D-10 albo jawnie zdecydować, które nierozstrzygnięte pozycje są warunkami wejścia. Moduł pozostaje `NOT_READY`.
