# VELTRA 2.0 — Architektura Stage 2C
## Dostawcy, produkty i opakowania, ceny referencyjne oraz waluty

**Status:** architektura po integracji zatwierdzonych decyzji D-01–D-10
**Zakres:** projekt Stage 2C; bez implementacji, migracji, zmian danych ani aktywacji modułu
**Rekomendacja:** **ACCEPT** — decyzje biznesowe D-01–D-10 są wiążącymi niezmiennikami projektu. Pozostają ryzyka wykonawcze, ale nie wymagają ponownego wyboru polityki biznesowej.
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

Poza zakresem są: zamówienia/umowy/rozrachunki dostawców, przyjęcia i partie magazynowe, faktury, płatności, workflow alergenów, dokumenty dostawców oraz obliczanie i zamykanie kosztu partii produkcyjnej. Import oficjalnych dziennych kursów NBP jest w zakresie 2C jako kontrolowane, idempotentne pobranie danych źródłowych; ręczne nadpisanie jest osobnym audytowanym workflow. Stage 2C tworzy wspólny master dostawców i kontrakty dla Purchasing/Inventory, ale nie implementuje zamówień, przyjęć ani domeny Production.

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
2. `organization_suppliers` to jedyny organizacyjny master dostawców, wspólny dla Raw Materials, Purchasing i Inventory (D-08). Właścicielem technicznym jest wspólna domena `Suppliers`; moduły konsumują ją przez jawny serwis/kontrakt, nie tworzą własnych kopii. Jeden dostawca może oferować wiele produktów i wiele ofert.
3. `supplier_products` opisuje relację dostawca–surowiec oraz zewnętrzny produkt/SKU. Produkt dostawcy, GTIN, jego prosta konfiguracja opakowania i cena nie są tożsamością surowca. Opakowanie Stage 2C zawiera jeden produkt i jedną definicję surowca; zestawy mieszane są wyłączone (D-06).
4. Konfiguracja produktu i jego przelicznik są wersjonowane. Oferta cenowa wskazuje dokładną wersję produktu i opakowania, której dotyczy.
5. `supplier_offer_events` opisują oferty/zakupowe warunki dostawcy, a `raw_material_reference_price_events` opisują osobną, centralnie zatwierdzaną wartość foodcost. Zmiana jednego rodzaju ceny nigdy nie aktualizuje drugiego.
6. Rekordy cenowe Stage 2C nie są dowodem faktury ani rzeczywistego kosztu przyjętej partii. Koszt rzeczywisty należy do przyszłej domeny Inventory/Purchasing.
7. Produkt i oferta mogą być ewidencjonowane przed oceną zgodności alergenowej; status zgodności jest jawnie nieustalony. Utworzenie, zatwierdzenie lub rejestracja oferty nie daje prawa do operacyjnego PO, przyjęcia ani użycia produkcyjnego. Te operacje wymagają zatwierdzonego review Stage 2D dla dokładnej rewizji (D-10).

### 3.2 Zatwierdzony produkt i zgodność alergenowa

Stage 2C może rejestrować produkt dostawcy, jego opakowanie i ofertę, ale nie może oznaczyć go jako zgodnego alergenowo ani jako bezpiecznego do operacyjnego użycia. Zgodność pozostaje `PENDING`/nieustalona do czasu wdrożenia Stage 2D i zatwierdzenia przez uprawnioną osobę na podstawie wersji dowodu dostawcy, dla dokładnej rewizji surowca. Brak review, review niezgodne albo review dla starej rewizji nie może być traktowane jako zgodność. Dostępność ceny nie jest równoznaczna z dopuszczeniem produktu do zakupów lub produkcji.

## 4. Model PostgreSQL

### 4.1 Wspólne reguły

- PK to UUID; każda tabela organizacyjna ma `organization_id uuid NOT NULL`, indeksy rozpoczynają się od tenant scope, a FK do encji organizacyjnych zawierają `organization_id`.
- FK historyczne stosują `ON DELETE RESTRICT`; nie ma fizycznego usuwania użytych dostawców, produktów, rewizji, ofert, cen, kursów ani zdarzeń audytu.
- Daty zdarzeń i obowiązywania są `timestamptz`; klient nie określa `created_at`, autora, organizacji, zatwierdzającego ani wyniku oceny.
- Ilości i kursy są `NUMERIC`, nigdy `float`/`real`; kwota zakupu zachowuje walutę źródłową. Skale są konwencją techniczną; implementacja wykazuje ich zakres, overflow i zgodność z HALF-EVEN.
- Nowe wartości Stage 2C wymagające kontroli skali są przechowywane jako unconstrained PostgreSQL `NUMERIC` z constraintami zakresu/precyzji. Nie używać kolumn `NUMERIC(p,s)` dla niezweryfikowanego wejścia ani końcowego wyniku, ponieważ PostgreSQL może zaokrąglić przy przypisaniu przed kontrolą constraintu. Odrzucać nadmiarowe cyfry przez `value = trunc(value, allowed_scale)`; końcowe wyniki sprawdzać funkcją `raw_materials_round_half_even_v1(value, scale)`. Nie korzystać z domyślnego `round(numeric)` PostgreSQL.
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
| `tax_identifier` | `varchar(80) NULL` | Dane wrażliwe; ograniczyć do Supplier permission, maskować w API, wyłączyć z logów/eksportów bez uprawnienia i chronić mechanizmem ochrony danych platformy. |
| `lifecycle_state` | `varchar(16) NOT NULL` | `ACTIVE`, `ARCHIVED`; stan nie kasuje produktów ani historii. |
| `created_by`, `created_at` | UUID, `timestamptz` | Autor i czas serwera; FK złożony do organizacji/użytkownika. |

Indeksy: `UNIQUE(organization_id, supplier_code)`, indeks wyszukiwania `(organization_id, lifecycle_state, display_name)`. Normalizacja nazwy może wskazywać możliwy duplikat, ale nie scala rekordów automatycznie.

### 4.3 `supplier_products` i niezmienne wersje produktu/opakowania

`supplier_products` to stabilna relacja dostawcy z centralnym surowcem. Wiele rekordów może istnieć dla jednego surowca, w tym kilka SKU tego samego dostawcy.

| Tabela | Istotne kolumny | Klucze/reguły |
|---|---|---|
| `supplier_products` | `id`, `organization_id`, `supplier_id`, `raw_material_id`, `lifecycle_state`, `created_by`, `created_at` | `UNIQUE(id, organization_id)`; FK złożone do dostawcy i surowca; stan `ACTIVE`/`ARCHIVED`; brak fizycznego delete. |
| `supplier_product_revisions` | `id`, `organization_id`, `supplier_product_id`, `revision_no`, `raw_material_revision_id`, `supplier_sku`, `gtin`, `product_name`, `purchase_unit_code`, `content_quantity`, `content_unit_code`, `raw_material_revision_unit_id`, `to_base_factor_snapshot`, `base_quantity_snapshot`, `content_hash`, `created_by`, `created_at` | `UNIQUE(supplier_product_id, revision_no)` i złożone FK do dokładnego produktu, organizacji, materiału/revizji/jednostki. Immutable; nowa konfiguracja/SKU tworzy nową rewizję. |

`purchase_unit_code` opisuje pojedynczą jednostkę zamawianą, np. `BAG` lub `CASE`, a nie nową jednostkę konwersji surowca. Każda rewizja zapisuje jedną całkowitą zawartość jednego surowca w jednym UOM, np. 10 kg na worek. Serwer oblicza `base_quantity_snapshot` z ilości, unit record i współczynnika konkretnej zatwierdzonej rewizji; klient nie wysyła wiążącego współczynnika ani wyliczonej zawartości. Mieszane/wieloskładnikowe opakowania i hierarchie case→pack→unit są poza Stage 2C.

Każda rewizja produktu przechowuje `raw_material_revision_id` i snapshot podstawy/przelicznika, nawet jeśli jednostka bazowa aktualnie się nie zmieniła. Zmiana konwersji w nowej rewizji surowca nie przelicza już istniejących ofert; wymaga nowej rewizji produktu i nowych zdarzeń ofertowych przypiętych do tej konfiguracji. GTIN i SKU normalizuje serwer; unikalność SKU (gdy niepuste) egzekwuje się w obrębie dostawcy i organizacji. Nie zakłada się globalnej unikalności SKU. Pole zgodności alergennej nie jest ustawiane przez Stage 2C; stan przed decyzją Stage 2D pozostaje jawnie `PENDING`.

### 4.4 `supplier_offers` i `supplier_offer_events`

`supplier_offers` to trwała tożsamość oferty handlowej, odrębna od produktu dostawcy i od ceny referencyjnej.

| Tabela | Istotne kolumny | Klucze/reguły |
|---|---|---|
| `supplier_offers` | `id`, `organization_id`, `supplier_product_id`, `offer_reference`, `created_by`, `created_at` | FK złożony do produktu; unikalny `offer_reference` w zakresie produktu, jeżeli istnieje. Nie przechowuje mutable „aktualnej ceny”. |
| `supplier_offer_events` | `id`, `organization_id`, `offer_id`, `supplier_product_revision_id`, `event_no`, `event_type`, `valid_from`, `valid_until NULL`, `recorded_at`, `net_amount_per_purchase_unit`, `currency_code`, `minimum_order_quantity NULL`, `quantity_from`, `quantity_until NULL`, `source_code`, `source_reference`, `source_document_version_id NULL`, `recorded_by`, `reason_code`, `operation_id` | `event_type` = `PRICE_SET` lub `WITHDRAWN`; event opisuje jedną walutę, purchase unit, przedział ceny ilościowej, MOQ i okres ważności; `event_no` rośnie pod lockiem oferty. Wycofanie nie ma kwoty. FK do dokładnej wersji produktu/opakowania. `PRICE_SET` wymaga kwoty nieujemnej, ISO 4217, `quantity_from > 0`, `quantity_until > quantity_from` gdy podane, dodatniego MOQ gdy podane oraz `valid_until > valid_from` gdy podane. Append-only. |
| `supplier_offer_fx_normalization_events` | `id`, `organization_id`, `supplier_offer_event_id`, `exchange_rate_event_id`, `rate_snapshot numeric`, `reporting_amount_pln_per_purchase_unit numeric`, `reporting_amount_pln_per_base_unit numeric`, `normalized_at`, `recorded_by`, `operation_id` | Append-only snapshot wyliczenia PLN dla dokładnego eventu ceny i rewizji opakowania. Skala kursu i finalnych wyników ma jawne CHECK/trigger; końcowe kwoty muszą równać się `raw_materials_round_half_even_v1(value, 14)`. FK złożone; korekta tworzy nowy event korekcyjny, nie aktualizuje snapshotu. |

Cena oferty jest kwotą netto w walucie źródłowej za dokładnie jedną jednostkę zakupu z rewizji produktu. MOQ i progi cenowe są wyrażone w tych samych jednostkach zakupu; cena może mieć wiele progów, walut i okresów ważności, bez automatycznego wyboru dostawcy ani oferty. Dla tej samej oferty, rewizji i waluty aktywne przedziały ilościowe nie mogą nakładać się w czasie, w którym obowiązują jednocześnie; wykluczenie jest wymuszane w PostgreSQL (exclusion constraint lub równoważny trigger serializowany pod lockiem oferty), nie wyłącznie walidacją API. Kwota pozostaje źródłowa; do raportowania system używa osobnego, niezmiennego snapshotu kursu i wyliczonego PLN. Cena w walucie obcej może być zachowana jako `SOURCE_ONLY`, gdy brak dostępnego kursu; nie wolno jej sumować/porównywać w PLN ani używać do normalizacji ceny referencyjnej. Późniejsze uzyskanie kursu tworzy `supplier_offer_fx_normalization_events`, nie aktualizuje źródłowego eventu. Zmiana warunków/ceny/terminu to nowe zdarzenie, nie UPDATE poprzedniej oferty. Na tym etapie oferta nie oznacza zamówienia, potwierdzenia dostawy ani kosztu partii.

### 4.5 `raw_material_reference_price_events`

Jeden strumień zdarzeń na surowiec reprezentuje centralną cenę referencyjną netto używaną jako wejście foodcost. Propozycja techniczna: wartością kanoniczną do porównywania i foodcost jest PLN za jednostkę bazową. Jeśli cenę ustalono na podstawie oferty w walucie obcej, zachowujemy źródłową kwotę, walutę, wersję produktu/oferty i snapshot FX, z którego wyprowadzono PLN.

| Kolumna | Typ/propozycja | Reguła |
|---|---|---|
| `id`, `organization_id`, `raw_material_id` | UUID | PK i FK złożone do surowca tej organizacji. |
| `event_no` | `integer` | Monotoniczny w strumieniu surowca, przydzielany pod blokadą. |
| `event_type` | enum/check | `PRICE_SET` albo `PRICE_WITHDRAWN`; withdrawal wyłącza cenę od jego `effective_from`. |
| `effective_from`, `recorded_at` | `timestamptz` | Czas skuteczności i niezmienny czas zarejestrowania; interpretacja operacyjnej daty FX stosuje `Europe/Warsaw`. |
| `net_pln_per_base_unit` | `numeric NULL` | Kanoniczna cena PLN netto/baza dla `PRICE_SET`; brak kwoty dla withdrawal. Constraint wymaga wyniku HALF-EVEN do skali 14. |
| `source_net_amount`, `source_currency_code` | `numeric NULL`, `char(3) NULL` | Źródło ceny, jeśli dotyczy; CHECK nie dopuszcza więcej niż 8 istotnych miejsc dziesiętnych; pole nie jest wyświetlane bez właściwego permission. |
| `source_offer_event_id`, `source_product_revision_id` | UUID NULL | Opcjonalne pochodzenie; FK złożone i RESTRICT. Cena referencyjna może być ustalona niezależnie od oferty. |
| `exchange_rate_event_id`, `rate_snapshot` | UUID NULL, `numeric NULL` | Kurs dokładnie wykorzystany dla źródła obcego; CHECK skali do 12; przy `PLN` zapis tożsamości 1 lub jawnie zatwierdzony odpowiednik. |
| `recorded_by`, `reason_code`, `operation_id` | UUID, kody, UUID | Autor, powód i korelacja audytu. |

Ograniczenia: `UNIQUE(raw_material_id, event_no)`, kwoty nieujemne, kod waluty z rejestru ISO 4217, walidacja spójności pola źródłowego i kursu, brak UPDATE/DELETE. Zapis ceny wymaga `RAW_MATERIALS_REFERENCE_PRICE_MANAGE`; oferta i źródłowa cena zakupu wymagają dodatkowo odrębnego permission kosztów zakupu. Nie wolno tworzyć kopii ceny w zwykłym endpointcie katalogu.

Odczyt „as of” sortuje po `effective_from DESC`, a przy tej samej chwili po `event_no DESC`; wybiera ostatnie zdarzenie, które może być również wycofaniem. Wsteczna korekta jest nowym zdarzeniem i nie przepina zapisanych snapshotów. Korekta historyczna nie modyfikuje oryginalnego wyniku: wymaga osobnego, audytowanego restatementu ze wskazaniem źródła, zakresu i oryginalnego snapshotu (D-03). Zamknięte snapshoty produkcyjne pozostają niezmienne; ich ewentualny restatement należy do domeny Production/Finance.

### 4.6 `organization_exchange_rate_events`

Kurs jest zdarzeniem organizacji, a nie konfigurowalnym, mutowalnym ustawieniem. Kierunek pary: liczba PLN za jedną jednostkę waluty obcej; kurs PLN/PLN jest tożsamością 1 i nie wymaga zewnętrznego notowania. Źródłem podstawowym są oficjalne dzienne kursy NBP (D-01). Dla konwersji raportowej przyjmuje się średni kurs NBP z Tabeli A jako jednolitą konwencję techniczną; nie stanowi on reguły podatkowej ani księgowej.

| Kolumna | Typ/propozycja | Reguła |
|---|---|---|
| `id`, `organization_id` | UUID | PK, zakres organizacji. |
| `base_currency_code` | `char(3)` | Zawsze `PLN`. |
| `quote_currency_code` | `char(3)` | ISO 4217; różna od `PLN` dla zewnętrznego kursu. |
| `pln_per_quote_unit` | `numeric` | Wartość dodatnia; CHECK skali do 12 i zakresu; żadnych kursów zerowych/ujemnych. |
| `rate_date`, `recorded_at` | `date`, `timestamptz` | Data tabeli NBP i niezmienny czas pobrania/zapisu. |
| `source_code`, `source_rate_id`, `source_payload_hash` | `varchar`, `varchar NULL`, `char(64)` | Źródło `NBP_TABLE_A` albo `MANUAL_OVERRIDE`, identyfikator tabeli/pozycji i hash utrwalonej odpowiedzi źródłowej; nie przechowywać dowolnego URL ani sekretów. |
| `revision_no`, `supersedes_id` | integer, UUID NULL | Korekta tego samego punktu effective-time tworzy nowy event; nie zmienia historycznego rekordu. |
| `override_of_id`, `recorded_by`, `reason_code`, `operation_id` | UUID NULL, UUID/kody | Manualne nadpisanie wskazuje pierwotny kurs NBP, autora z `RAW_MATERIALS_EXCHANGE_RATE_MANAGE`, obowiązkowy powód i ślad audytowy. Nadpisanie nie edytuje ani nie usuwa NBP eventu. |

Indeks as-of: `(organization_id, quote_currency_code, rate_date DESC, revision_no DESC)`. Unikalność obejmuje organizację, walutę, datę i rewizję. Dla daty operacji wyliczonej w `Europe/Warsaw` wybierany jest najnowszy dostępny kurs NBP z `rate_date <= operation_date`; weekend/święto korzysta z ostatniej wcześniejszej publikacji. Nie używa się kursu przyszłego, kursu 1 dla obcej waluty ani kursu spoza NBP bez jawnego nadpisania. Brak jakiegokolwiek dopuszczalnego kursu blokuje normalizację PLN; kwota źródłowa może pozostać `SOURCE_ONLY`. Kontrolowana synchronizacja pobiera NBP poza transakcją, jest idempotentna i zachowuje identyfikator tabeli/hash odpowiedzi.

Manual override jest osobnym append-only eventem ograniczonym do autoryzowanego grantobiorcy, z obowiązkowym powodem, datą operacji, wskazaniem zastępowanego kursu NBP i audytem. Nie zastępuje źródłowego eventu. Każdy price event, który wymaga konwersji, zachowuje FK do kursu i snapshot: wartość, kierunek, datę operacji w Warszawie, datę NBP, źródło/override oraz PLN. Snapshot jest źródłem prawdy dla zapisanej ceny; późniejsza korekta kursu nie przelicza go automatycznie.

Nie pobierać kursu z sieci synchronicznie podczas zapisu oferty/ceny. Kontrolowana synchronizacja używa NBP; ręczny override jest osobnym, autoryzowanym i audytowanym zdarzeniem. Awarie synchronizacji nie powodują użycia fikcyjnego lub ukrytego kursu zastępczego.

### 4.7 Restatement i audyt finansowy

Restatement jest nowym, niezmiennym wynikiem, nigdy edycją pierwotnego snapshotu. Minimalna tabela `raw_material_financial_restatements` zawiera: `id`, `organization_id`, `subject_type`, `subject_id`, `original_snapshot_id/hash`, `supersedes_restatement_id NULL`, użyte price/FX event IDs i ich snapshoty, `restated_value`, `currency_code`, `operation_date`, `reason_code`, `recorded_by`, `recorded_at`, `operation_id`. Unikalność/idempotency i FK tenantowe wiążą restatement z oryginałem; write wymaga właściwego permission finansowego, a korekta wsteczna nie zmienia zwykłego odczytu historycznego ani zamkniętych kosztów. Stage 2C definiuje ten wzorzec dla swoich zdarzeń; restatement kosztu produkcyjnego pozostaje odpowiedzialnością Production/Finance.

Preferowany wariant to użycie istniejącego append-only `raw_material_audit_events`, o ile jego kontrakt dopuszcza wymagane zdarzenia finansowe i metadane. Zdarzenia obejmują co najmniej `SUPPLIER_CREATED`, `SUPPLIER_PRODUCT_REVISION_CREATED`, `OFFER_PRICE_SET`, `OFFER_WITHDRAWN`, `REFERENCE_PRICE_SET`, `REFERENCE_PRICE_WITHDRAWN`, `FX_RATE_IMPORTED`, `FX_RATE_OVERRIDE_CREATED`, `FX_RATE_CORRECTED` i `FINANCIAL_RESTATEMENT_CREATED`. Nie zapisują pełnych danych płatniczych, dokumentów, sekretów ani kwot poza uprawnionym zakresem audytu.

Jeśli tabela/constraint Stage 2B nie może bezpiecznie obsłużyć finansowych eventów, Stage 2C może dodać wyspecjalizowaną tabelę append-only z indeksem `(organization_id, occurred_at)`; nie może zmieniać znaczenia ani osłabiać obecnego audytu. Audyt i mutacja finansowa commitują atomowo. Błąd audytu oznacza rollback mutacji.

## 5. Cena zakupu, normalizacja i waluty

### 5.1 Normalizacja jednostki

Dla produktu z jedną jednostką zakupu i jedną jednorodną zawartością:

`ilość_bazowa_na_jednostkę_zakupu = ilość_zawartości × współczynnik_do_bazy_z_rewizji_surowca`

`cena_źródłowa_na_jednostkę_bazową = kwota_netto_na_jednostkę_zakupu / ilość_bazowa_na_jednostkę_zakupu`

`cena_PLN_na_jednostkę_bazową = cena_źródłowa_na_jednostkę_bazową × PLN_na_jednostkę_waluty_źródłowej`

Operacje wykonuje PostgreSQL `NUMERIC` albo sprawdzona arytmetyka dziesiętna; nigdy binary float. Zapis obejmuje wszystkie operand-y oraz snapshot wyniku. Nie wolno normalizować przez niejawne, aktualne przeliczniki jednostek ani kurs „dzisiejszy”.

Warunki walidacji:

- dodatnia i ograniczona ilość zawartości; limity techniczne są walidowane przed zapisem, bez nowych reguł biznesowych;
- jednostka zawartości występuje w konkretnej zatwierdzonej rewizji `raw_material_revision_units`;
- wymiar musi być zgodny albo istnieje jawny, zatwierdzony przelicznik material-specific z basis; brak/UNKNOWN konwersji oznacza odmowę normalizacji;
- zapisuje się identyfikator rewizji surowca, wiersz jednostki, współczynnik, ilość wejściową, ilość bazową oraz wersję konfiguracji opakowania;
- zmiana quantity, UOM lub opakowania to nowa rewizja produktu; stara oferta pozostaje związana ze starą rewizją.

### 5.2 Precyzja i rounding

Konwencja techniczna limitów: kwota źródłowa maksymalnie 24 cyfry/8 miejsc dziesiętnych, kurs/przelicznik maksymalnie 24 cyfry/12 miejsc, ilość maksymalnie 24 cyfry/9 miejsc, końcowa cena PLN maksymalnie 30 cyfr/14 miejsc. Dla Stage 2C zapis typów to unconstrained `NUMERIC` z constraintami zakresu i skali, tak aby PostgreSQL nie zaokrąglał danych wejściowych przed walidacją. Operacje pośrednie zachowują pełną precyzję dziesiętną dostępną w arytmetyce `NUMERIC`; końcowy wynik oblicza wersjonowana, niezmienna funkcja dziesiętna `raw_materials_round_half_even_v1(value, scale)` do skali docelowej. Constraint/trigger PostgreSQL wymaga, aby utrwalona wartość końcowa była równa wynikowi tej funkcji; bezpośredni zapis SQL z innym rounding jest odrzucany. Nie obcinać operandów przed obliczeniem; przepełnienie powoduje jawny błąd i rollback. Kwota do prezentacji może być formatowana do dwóch miejsc dziesiętnych, ale format prezentacji nie jest wartością obliczeniową.

Tryb `HALF-EVEN` jest stałym wymogiem normalizacji Stage 2C, nie parametrem API (D-04). Zapisuje się operandy/snapshoty oraz wersję reguły skalowania. Testy DB sprawdzają wartości tie dla liczb dodatnich i ujemnych, zero, carry/overflow, oraz bezpośredni SQL. Księgowe i podatkowe zaokrąglanie pozostaje osobną regułą odpowiednich domen; Stage 2C nie interpretuje formatowania jako rozliczenia księgowego.

### 5.3 PLN oraz waluty

- `PLN` jest reporting currency; każda przeliczona oferta/cena referencyjna zachowuje źródłową kwotę i walutę oraz PLN snapshot.
- Kody walut są ograniczone do ISO 4217; obsługę zmian listy kodów należy wersjonować. Nie przyjmować arbitrary trzech liter.
- Waluty nie mogą być porównywane ani sumowane bez użycia konkretnego kursu. Nie stosować kursu jednego dostawcy/oferty do drugiej bez jawnego wskazania.
- Konwersja do PLN używa bezpośredniego kursu NBP dla waluty quote albo jawnego, audytowanego override; triangulacja jest wyłączona.
- Cena referencyjna ma jedną kanoniczną wartość netto PLN/base unit na surowiec i czas (D-05). Cena zakupu pozostaje w currency quote; kwota PLN jest snapshotem raportowym.

## 6. Oddzielenie kosztu szacowanego od rzeczywistego (B4)

Stage 2C ceny oznaczają dostawcę/ofertę albo cenę referencyjną, nie faktyczny koszt partii. Kanoniczny kontrakt przyszłego snapshotu Production/Inventory pozostaje:

1. Jeśli Inventory udostępni koszt rzeczywiście skonsumowanej partii, koszt produkcyjny pochodzi z tej partii (`cost_source = INVENTORY_BATCH`, `is_estimated = false`), z jej transakcji/ilości/kursu.
2. Jeśli rzeczywisty koszt partii nie jest dostępny, Production może użyć historycznej ceny referencyjnej PLN obowiązującej dla czasu zużycia (`cost_source = REFERENCE_PRICE_ESTIMATE`, `is_estimated = true`).
3. Snapshot utrwala `raw_material_id`, dokładną `raw_material_revision_id`, `supplier_product_revision_id`/ofertę tylko jeśli jest źródłem, ilość i jednostkę wejściową/bazową, współczynnik konwersji, kwotę, walutę, `exchange_rate_event_id`, kurs, wartość PLN, czas i identyfikator źródłowego eventu ceny.
4. Zamknięty batch nie przelicza się ponownie, jeżeli później zmieni się dostawca, opakowanie, cena, kurs, rewizja surowca albo koszt rzeczywisty zostanie uzupełniony. Ewentualna korekta księgowa ma osobny, jawny i audytowany workflow domeny Production/Finance.
5. Późniejsze Inventory/Purchasing nie może nadpisać ceny referencyjnej ceną zamówienia/rachunku, ani traktować historii ofert jako rzeczywistego kosztu partii.

## 7. RBAC, readiness i prywatność

### 7.1 Proponowane uprawnienia

Wszystkie są nowymi kluczami organizacyjnymi zgodnie z D-09. Nie są nadawane rolom automatycznie ani dziedziczone przez zwykły odczyt katalogu.

| Permission | Dostęp |
|---|---|
| `RAW_MATERIALS_SUPPLIER_VIEW` | Zatwierdzone/aktywne dane dostawcy, SKU i opakowań bez kwot kosztów; nie ujawnia tax identifier, jeśli nie zatwierdzono go jako pola widocznego dla tej roli. |
| `RAW_MATERIALS_SUPPLIER_MANAGE` | Utworzenie, rewizje, blokowanie/archiwizacja dostawcy, produktu i konfiguracji opakowania. |
| `RAW_MATERIALS_PURCHASING_COSTS_VIEW` | Kwoty ofert zakupu i ich historia; odrębne od katalogu i cen referencyjnych. |
| `RAW_MATERIALS_PURCHASING_COSTS_MANAGE` | Rejestracja i wycofanie historycznych zdarzeń cen oferty oraz ich metadanych. |
| `RAW_MATERIALS_REFERENCE_PRICE_VIEW` | Kwota centralnej ceny netto PLN/base unit, jej historia i źródłowa kwota FX. |
| `RAW_MATERIALS_REFERENCE_PRICE_MANAGE` | Dodanie ceny referencyjnej/wycofania/korekty z reason, bez edycji historii. |
| `RAW_MATERIALS_EXCHANGE_RATE_VIEW` | Odczyt źródła, daty i wartości kursu wyłącznie w dozwolonym kontekście finansowym. |
| `RAW_MATERIALS_EXCHANGE_RATE_MANAGE` | Dodanie/korekta kursu organizacyjnego; zdarzenie audytu wymagane. |

`RAW_MATERIALS_REFERENCE_PRICE_VIEW` nie przyznaje `RAW_MATERIALS_PURCHASING_COSTS_VIEW` ani odwrotnie. Zwykłe `RAW_MATERIALS_VIEW`, widoki katalogu, filtry, sort, paginacja, statystyki, cache, idempotency response i eksport nie zwracają kwot, waluty źródłowej ani szczegółów rate snapshot. Endpointy cenowe autoryzują oddzielnie każde żądanie.

Odczyt kursu wymaga `RAW_MATERIALS_EXCHANGE_RATE_VIEW` albo odpowiedniego permission ceny/kosztu dla konkretnego obliczenia; administracja NBP i manual override wymagają `RAW_MATERIALS_EXCHANGE_RATE_MANAGE`. Dostęp do danych dostawcy, kosztów zakupu i kursów jest odrębny. Rola lokalizacyjna nigdy nie daje grantów organizacyjnych. Delegowanie/expiry/revoke/audyt zachowują Stage 2B model; grant utracony przy zawieszeniu nie odzyskuje skuteczności przez reaktywację.

Istniejąca, już zainicjalizowana organizacja otrzymuje nowe permissiony wyłącznie przez jawną, autoryzowaną operację administracyjną. Administrator musi mieć aktywne członkostwo, skuteczne `RAW_MATERIALS_PERMISSION_DELEGATE` i ponownie potwierdzić hasło; wskazuje odrębnego odbiorcę, dokładny zestaw permissionów, uzasadnienie i klucz idempotencji. Operacja zapisuje append-only rekord oraz audyt, a granty nie mogą być przywrócone po utracie lub odwołaniu źródłowego prawa delegowania. Migracje i start aplikacji nie nadają grantów automatycznie; ponowienie identycznego żądania jest bezskutkowe, a użycie klucza z innymi parametrami kończy się konfliktem. Ta procedura nie zmienia gotowości ani aktywacji `RAW_MATERIALS`.

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
  "content_quantity": "10",
  "content_unit_code": "kg",
  "raw_material_revision_id": "uuid"
}
```

Serwer weryfikuje scope dostawcy/surowca, aktywną zatwierdzoną rewizję, unit registry i konwersje z tej rewizji, oblicza bazową ilość i zapisuje niezmienny snapshot. Utworzenie produktu jest dozwolone przy statusie zgodności `PENDING`; odpowiedź i eksport muszą ten stan ujawniać, nie sugerując zatwierdzenia.

### 8.2 Oferty i koszty zakupu

- `GET /api/v1/supplier-products/{productId}/offers` — `RAW_MATERIALS_SUPPLIER_VIEW`; wyłącznie oferta bez wartości kosztowej.
- `POST /api/v1/supplier-products/{productId}/offers` — utworzenie tożsamości oferty z `RAW_MATERIALS_PURCHASING_COSTS_MANAGE` i `RAW_MATERIALS_SUPPLIER_MANAGE`; dozwolone przed zatwierdzeniem zgodności.
- `GET /api/v1/supplier-offers/{offerId}/price-events` — `PURCHASING_COSTS_VIEW`.
- `POST /api/v1/supplier-offers/{offerId}/price-events` — `PURCHASING_COSTS_MANAGE`, nowe zdarzenie `PRICE_SET` lub `WITHDRAWN`, `Idempotency-Key` wymagany.

Przykładowe ustawienie ceny:

```json
{
  "event_type": "PRICE_SET",
  "net_amount_per_purchase_unit": "42.50000000",
  "currency_code": "EUR",
  "valid_from": "2026-11-03",
  "valid_until": null,
  "minimum_order_quantity": "1",
  "quantity_from": "1",
  "quantity_until": "10",
  "source_code": "SUPPLIER_QUOTE",
  "reason_code": "QUOTE_UPDATED"
}
```

Oferta przyjmuje wiele append-only zdarzeń dla różnych progów/walut/okresów; API odrzuca nakładające się przedziały tej samej oferty, rewizji i waluty. Rejestracja ceny źródłowej jest możliwa bez dostępnego kursu i zwraca jawny stan `SOURCE_ONLY`/`FX_RATE_REQUIRED`; nie przedstawia kwoty jako PLN. Dostępne NBP jest wybierane według `operation_date` w strefie `Europe/Warsaw`, a nie czasu klienta/serwera.

### 8.3 Cena referencyjna i kursy

- `GET /api/v1/raw-materials/{id}/reference-prices?as_of=...` — `REFERENCE_PRICE_VIEW`.
- `POST /api/v1/raw-materials/{id}/reference-prices` — `REFERENCE_PRICE_MANAGE`; wymaga `Idempotency-Key`, expected event version, daty skuteczności, kwoty PLN/base unit, powodu oraz opcjonalnego, jawnego źródła oferty/FX.
- `POST /api/v1/raw-materials/{id}/reference-prices/withdraw` — tworzy `PRICE_WITHDRAWN`; nigdy nie usuwa poprzedniej ceny.
- `GET /api/v1/exchange-rates?currency=EUR&operation_date=YYYY-MM-DD` — `RAW_MATERIALS_EXCHANGE_RATE_VIEW` albo odpowiedni permission finansowy dla konkretnej operacji; odpowiedź wskazuje rate date, NBP table ID, fallback date i źródło.
- `POST /api/v1/exchange-rates/nbp/sync` — kontrolowane/idempotentne pobranie oficjalnych danych NBP przez uprawniony proces z `RAW_MATERIALS_EXCHANGE_RATE_MANAGE`; pobranie sieciowe odbywa się poza transakcją.
- `POST /api/v1/exchange-rates/{rateId}/overrides` — ręczne nadpisanie daty operacyjnej, rate i powodu; wymagane `RAW_MATERIALS_EXCHANGE_RATE_MANAGE`; tworzy nowy event powiązany z NBP, bez modyfikacji źródła.
- `POST /api/v1/exchange-rates/{rateId}/corrections` — dodaje audytowany event korekcyjny/restatement reference; nie edytuje wskazanego rate ani wcześniejszych snapshotów.

Przykładowa odpowiedź finansowa:

```json
{
  "data": {
    "raw_material_id": "uuid",
    "event_id": "uuid",
    "event_no": 8,
    "effective_from": "2026-11-03",
    "net_pln_per_base_unit": "0.00425000000000",
    "reporting_currency": "PLN",
    "source": {
      "amount": "42.50000000",
      "currency": "EUR",
      "exchange_rate_event_id": "uuid",
      "pln_per_currency_unit": "4.123456789012",
      "operation_date": "2026-11-03",
      "rate_date": "2026-11-03",
      "source_code": "NBP_TABLE_A",
      "rounding": "HALF_EVEN"
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
6. Kurs as-of wybiera ostatni dostępny NBP `rate_date <= operation_date` (data lokalna `Europe/Warsaw`); price event selection ma jawny effective-time i `event_no`. Korekty nie aktualizują historii ani zapisanych snapshots; restatement jest osobnym zdarzeniem.
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

## 12. Wiążące decyzje biznesowe D-01–D-10

Decyzje poniżej zostały zatwierdzone; poniższa tabela stanowi wiążący kontrakt, a nie listę pytań ani opcji do ponownego rozstrzygnięcia.

| ID | Wiążąca reguła | Konsekwencja architektoniczna |
|---|---|---|
| D-01 | Oficjalne kursy NBP; kontrolowane, audytowane nadpisania ręczne. | Import idempotentny zachowuje identyfikator publikacji i hash źródła. Nadpisanie jest osobnym, niezmiennym zdarzeniem z uprawnieniem FX, powodem i referencją do kursu NBP; brak niejawnego kursu zastępczego. |
| D-02 | Kurs obowiązuje dla daty operacji w `Europe/Warsaw`; weekendy i święta używają najnowszego wcześniejszego kursu NBP. Nie wymyśla się ani nie zakłada kursu. | Snapshot zapisuje lokalną datę operacji, datę tabeli NBP, źródło i czas rejestracji. Brak dostępnego kursu blokuje przeliczenie PLN; obca kwota może pozostać `SOURCE_ONLY`. |
| D-03 | Korekta historyczna tworzy osobny audytowany restatement; oryginalny snapshot jest niezmienny. | Restatement wskazuje oryginał, podstawę i wynik. Nie aktualizuje historycznych cen ani zamkniętych kosztów; restatement Production należy do tej domeny. |
| D-04 | Pełna precyzja obliczeń pośrednich; `HALF-EVEN` wyłącznie dla końcowego wyniku. Księgowe i podatkowe zasady pozostają osobne. | Arytmetyka dziesiętna/`NUMERIC`, bez `float` i zaokrągleń operandów; tryb nie jest parametrem API. Skala prezentacji nie zastępuje wartości obliczeniowej ani reguł księgowych. |
| D-05 | Jedna kanoniczna cena referencyjna netto w PLN na jednostkę bazową; zachowane źródłowa kwota, waluta i historia FX. | Jeden event stream per surowiec; źródłowa oferta i snapshot kursu są przypięte, jeśli dotyczy. |
| D-06 | Prosta jednostka zakupu z obliczaną całkowitą zawartością; bez opakowań mieszanych w 2C. | Jedna rewizja produktu zawiera jeden surowiec, UOM i ilość całkowitą oraz wyliczoną ilość bazową; brak hierarchii opakowań i komponentów mieszanych. |
| D-07 | Wiele ofert może mieć waluty, progi ilościowe, MOQ i okresy ważności; system nie wybiera automatycznie dostawcy/oferty. | Każde zdarzenie określa warunek, walutę, jednostkę, przedział ilościowy i okres ważności; nakładające się pasma są odrzucane. Ranking i wybór pozostają jawne po stronie użytkownika/przyszłego modułu Purchasing. |
| D-08 | Jeden wspólny, tenant-scoped master dostawców dla Raw Materials, Purchasing i Inventory. | `organization_suppliers` jest własnością wspólnej domeny Supplier; moduły współdzielą identyfikator/kontrakt, bez tabel kopii ani utożsamiania go z relacją lokalizacji. |
| D-09 | Osobne RBAC dla danych dostawców, kosztów zakupu i zarządzania kursami. | Dedykowane permissiony VIEW/MANAGE; rozdzielone od katalogu i od ceny referencyjnej, bez automatycznego grantu lub dziedziczenia z ról lokalnych. |
| D-10 | Rejestracja produktów/ofert przed zatwierdzeniem alergenów jest dozwolona; operacyjne zakupy i produkcja wymagają zatwierdzonej zgodności Stage 2D. | 2C nie implementuje ani nie symuluje weryfikacji. Każdy zapis/użycie w systemie zależnym sprawdza zatwierdzenie zgodne z dokładną rewizją; `PENDING`/brak/nieaktualna weryfikacja blokuje operację. |

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
2. Produkt/konfiguracja jest historycznie odtwarzalna i wskazuje dokładną rewizję jednostek surowca oraz prostego opakowania.
3. Każdy event kosztu zakupu zachowuje kwotę, walutę, jednostkę zakupu, progi/MOQ, okres ważności, źródło i rewizję produktu; nie aktualizuje ceny referencyjnej.
4. Cena referencyjna jest jednym centralnym event stream per materiał i zachowuje cenę PLN/base unit oraz (gdy ma zastosowanie) źródłową kwotę i niezmienny kurs NBP/override.
5. Żaden odczyt/filtr/agregacja/cache/export katalogowy nie ujawnia ceny ani kosztu bez odrębnego permission; rozdzielone prawa nie można wzajemnie odziedziczyć.
6. Zapis historycznego kosztu nie zmienia się po późniejszej zmianie product package, conversion, price event, FX event lub supplier record.
7. Brak kompatybilności Stage 2D nie jest interpretowany jako zgodność ani zgoda na zakupowe/produkcyjne użycie.
8. Dane/zdarzenie/audyt zatwierdzają się atomowo; konflikty concurrency/replay są jawne i nie tworzą częściowych danych.
9. Wszystkie odczyty i zapisy są tenant-scoped; FK/trigger PostgreSQL i API wspólnie wymuszają izolację.
10. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne; nie ma produkcyjnego bypassu; Stage 1A–1F oraz Stage 2B security nie ulegają regresji.

## 15. Podsumowanie

Projekt rozszerza Stage 2B o wspólny tenant-scoped master dostawców, wersje prostych produktów/opakowań, append-only oferty i ceny, jedną referencyjną cenę foodcost oraz kursy NBP z kontrolowanym override. Zawiera wiążące D-01–D-10, zachowuje B1–B8 i nie myli ceny oferty/szacunku z kosztem rzeczywistej partii. Pozostałe ryzyka są techniczne: walidacja zakresu/skali `NUMERIC`, niezawodność i replay synchronizacji NBP, constraints braku nakładających się progów, kompatybilność istniejącego append-only audytu i migracja addytywna. To ryzyka do zamknięcia testami/projektem migracji, nie otwarte decyzje biznesowe. **Rekomendacja architektury: ACCEPT; nie jest to zgoda na rozpoczęcie implementacji.** `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne.

## 16. Wiążący model wdrożenia klienta — ADR-001

Zgodnie z [ADR-001 — Model wdrożenia klienta](./VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md), reguła wdrożeniowa brzmi: **jeden klient = jeden serwer = jedna instancja aplikacji = odrębne dane klienta**. Każdy klient otrzymuje izolowane wdrożenie, bazę PostgreSQL, instancję Redis, pliki, konfigurację i kopie zapasowe. Nie należy zmieniać tej reguły przez współdzielenie infrastruktury lub danych między klientami.

Izolacja wdrożeń nie znosi istniejących granic autoryzacji organizacji i lokalizacji wewnątrz instancji. Zapytania, granty, członkostwa oraz operacje domenowe nadal muszą egzekwować zakres organizacji i lokalizacji zgodnie z architekturą Stage 2B. Constraint jednej organizacji instalacyjnej nie uzasadnia osłabienia tych kontroli.
