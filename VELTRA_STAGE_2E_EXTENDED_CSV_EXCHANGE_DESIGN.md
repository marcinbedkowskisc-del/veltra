# VELTRA 2.0 — Stage 2E: projekt rozszerzonego kontraktu wymiany CSV

**Data:** 6 listopada 2026 r.  
**Status:** architektura częściowo wdrożona; eksport cen referencyjnych Stage 2E.6 jest zaimplementowany, pozostałe propozycje nadal wymagają decyzji właściciela
**Zakres:** projekt katalogowego importu, rozszerzonego eksportu katalogu, importu cen referencyjnych i oddzielnego eksportu danych cenowo-handlowych  
**Stan po Stage 2E.6:** wdrożono import RAW v1, osobny rozszerzony eksport katalogu RAW oraz osobny, datowany eksport cen referencyjnych i metadanych VAT/jednostki. Wymiana danych dostawców/opakowań oraz domeny INT/PRD/MEN pozostają poza zakresem implementacji.

## 1. Podsumowanie i rekomendacja

Rekomendowana architektura używa **odrębnych, ścisłych plików dla odrębnych operacji**, zamiast jednego uniwersalnego CSV. Każdy plik ma stały nagłówek, kolejność i reguły walidacji. Tożsamość SKU oraz zatwierdzone dane katalogowe nie są mieszane z cenami referencyjnymi, ofertami dostawców ani opakowaniami zakupowymi.

1. **Import katalogu** tworzy wyłącznie nowe szkice `RAW`, zachowując istniejący kod `RAW-…`. Istniejący rekord z identycznymi atrybutami daje `BEZ ZMIAN`; zmiana atrybutów nie jest upsertem i wymaga zwykłego procesu nowej rewizji. Nowe domeny `INT`, `PRD` i `MEN` pozostają poza działającym importem, dopóki nie powstaną ich modele, uprawnienia i cykle życia.
2. **Eksport katalogu rozszerzonego** zwraca wyłącznie aktywne rekordy z zatwierdzoną rewizją, z zachowaniem dokładnego SKU i nazwy. Nie zawiera cen ani danych dostawców. Istniejący eksport Stage 2E.3 pozostaje zgodny z dotychczasowym dwukolumnowym kontraktem.
3. **Import cen referencyjnych** zachowuje zatwierdzony i zaimplementowany sześciokolumnowy format Stage 2E.1–2E.2 bez zmian semantyki. Dopasowanie jest wyłącznie po dokładnym SKU `RAW-…` lub historycznym `SUR-…`; import nie tworzy składników i nie modyfikuje tożsamości ani zatwierdzonej rewizji.
4. **Eksport referencyjnych cen i metadanych** został wdrożony w Stage 2E.6 jako osobna, datowana operacja z osobną autoryzacją. Cena referencyjna, cena oferty dostawcy, cena opakowania i koszt produkcji pozostają odrębnymi pojęciami i strumieniami danych.
5. **Opakowanie** należy do wersjonowanego produktu dostawcy, a nie do tożsamości surowca. Import danych opakowaniowych i ofert, jeśli zostanie zatwierdzony, powinien stanowić kolejną, oddzielną operację.

Kontrakt katalogowego importu RAW v1 wdrożono w Stage 2E.4, rozszerzony eksport katalogu RAW w Stage 2E.5, a eksport cen referencyjnych/VAT/jednostki w Stage 2E.6. Pozostała wymiana ofert dostawców/opakowań, kategorie INT/PRD/MEN i ewentualne ponowne rozpoznawanie istniejących rekordów `SUR` nadal wymagają decyzji właściciela.

## 2. Fakty potwierdzone w repozytorium i granice danych

### 2.1 Tożsamość, rewizje i kategorie

- `raw_materials` jest istniejącym rekordem tożsamości surowca. Zawiera tenantowy kod biznesowy, stan cyklu życia i wskaźniki rewizji, a nie nazwę czy jednostkę katalogową.
- Nazwa, opis, kategoria, jednostka bazowa, wymiar, deklaracja alergenów i dane jednostek/konwersji są przypisane do `raw_material_revisions`. Rewizje są niezmienne; `approved_revision_id` wskazuje zatwierdzoną wersję.
- Kategorie są organizacyjne, mają kod, nazwę i drzewo do trzech poziomów. API tworzy i utrzymuje kategorie osobno; nie należy tworzyć ich ubocznie przy imporcie produktów.
- Cykl życia obejmuje `DRAFT`, `VERIFICATION`, `ACTIVE`, `BLOCKED` i `ARCHIVED`. Nowy RAW jest szkicem. Import nie może ustawić `ACTIVE`, `approved_revision_id`, deklaracji alergenów jako zweryfikowanej ani zgodności produktu dostawcy.
- Brak deklaracji alergenów oznacza stan `UNKNOWN`, a nie „brak alergenów”. Zatwierdzanie wymaga istniejącej ścieżki deklaracji, wymaganej atestacji rewizji i uprawnionego procesu.
- Wspólny alokator SKU jest już zaimplementowany dla liczników organizacja/prefiks; `SkuAllocationService::reserveImported()` rezerwuje importowany kod w transakcji i używa podpisanego zakresu `sku.allocate`. Nowe RAW są generowane jako `RAW`; istniejące `SUR` mają pozostać bez zmian.

### 2.2 Rozdzielenie surowca, opakowania i cen

- `raw_material_revision_units` opisuje jednostki/konwersje konkretnej rewizji surowca, w tym przeliczniki metryczne oraz jawne, specyficzne dla materiału konwersje między wymiarami.
- `supplier_products` wiąże dostawcę z surowcem. `supplier_product_revisions` przechowuje m.in. kod/GTIN produktu dostawcy, nazwę handlową, ilość i jednostkę zawartości oraz snapshot powiązania z rewizją surowca i konwersją. To nie jest SKU surowca.
- `supplier_offers` i `supplier_offer_events` reprezentują oferty/ceny handlowe oddzielnie od cen referencyjnych. Odczyt kosztów zakupów ma odrębne uprawnienia.
- Cena referencyjna jest wartością netto PLN na jednostkę bazową. Import cenowy przekazuje przygotowaną zewnętrznie cenę netto dla `kg`, `l` albo `szt.`; VELTRA normalizuje ją do zatwierdzonej jednostki bazowej bez arytmetyki zmiennoprzecinkowej.
- Historyczne koszty produkcji korzystają z odrębnych snapshotów i nie mogą być przeliczane ponownie przez import/eksport CSV.

### 2.3 Stan istniejących prac Stage 2E

- Stage 2E.1 dodał model append-only batchy, wierszy weryfikacji cenowej i przejść VAT/jednostki.
- Stage 2E.2 dodał parser i workflow podglądu/commit cenowego CSV oraz zachował idempotencję, audyt, atomiczność i ponowną walidację.
- Stage 2E.3 dodał prywatny eksport katalogu, który obecnie zawiera dokładnie `ingredient_id;ingredient_name`, pobiera kod faktycznie zapisany w rekordzie i nazwę z zatwierdzonej rewizji, sortuje deterministycznie i odrzuca nazwy grożące formułą zamiast je modyfikować.
- Stage 2E.4 dodał osobny import katalogu RAW v1 z podglądem i jawnym zatwierdzeniem, rezerwacją istniejącego SKU, szkicami z niezatwierdzoną rewizją, append-only historią oraz izolacją tenantów. Nie zmieniono formatu eksportu Stage 2E.3 ani semantyki sześciokolumnowego importu cen.
- Stage 2E.5 dodał osobny profil rozszerzonego eksportu RAW z dokładnym nagłówkiem wskazanym w sekcji 6.2. Eksport korzysta z jednego zapytania snapshotowego, prywatnego storage i istniejącego mechanizmu manifestu/hash/audytu, a przy pobraniu ponownie sprawdza uprawnienia. Profil Stage 2E.3 pozostał bez zmian i jego bajtowa zgodność jest testowana.
- Wspólny alokator SKU i obsługa `RAW`/historycznego `SUR` zostały wdrożone; wymagany test 034→035 wykonano na odizolowanej, disposable bazie PostgreSQL 17.11 z historycznymi rekordami SUR, rewizjami, licznikami i historią cen. Kody SUR pozostały niezmienione, backfill przypisań `LEGACY` powiódł się, a dalsza alokacja RAW oraz regresja cen przeszły.
- W repozytorium nie znaleziono pliku `kc-fv-ksef-mapping-20261105-214857.xlsx`. Nie użyto go jako źródła pól ani kontraktu. Nawet jeśli zostanie dostarczony, jego kolumny wymagają oceny i zatwierdzenia przed użyciem w VELTRA 2.0.

## 3. Rekomendowana architektura wymiany

### 3.1 Nie jeden uniwersalny CSV

Należy rozdzielić pliki według znaczenia i uprawnień:

| Operacja | Cel | Zakres początkowy |
|---|---|---|
| Import katalogu RAW | Tworzenie nowych tożsamości i szkiców rewizji | Tylko RAW; brak automatycznej akceptacji |
| Eksport katalogu | Identyfikacja aktywnych, zatwierdzonych składników | Rozszerzenie informacyjne bez danych handlowych |
| Import cen referencyjnych | Ustanawianie historycznych cen oraz VAT/jednostki wejściowej | Istniejący kontrakt Stage 2E.1–2E.2, bez zmian |
| Eksport referencyjnych danych handlowych | Snapshot ceny referencyjnej i stanu VAT/jednostki na wybraną datę | Wdrożono w Stage 2E.6; osobna operacja i uprawnienie |
| Import/eksport produktu dostawcy i opakowania | Utrzymanie produktu/oferty danego dostawcy | Poza pierwszym zakresem; osobny kontrakt |

Wspólne mogą być kodowanie UTF-8, separator `;`, standardowe quoting, limity, prywatne pliki, audyt i format SKU. Nie należy wspólnie parsować wszystkich operacji jednym zestawem kolumn ani zmieniać znaczenia `price_net` zależnie od typu pliku.

W przyszłości mogą istnieć adaptery kategorii `RAW`, `INT`, `PRD`, `MEN`, współdzielące niewielki zestaw pojęć (SKU, typ, nazwa) i walidację transportową. Nie należy jednak akceptować typów bez istniejącej domeny. Początkowy importer dopuszcza tylko `RAW`; pozostałe wartości są jawnie odrzucane jako nieobsługiwane, a nie tworzą placeholderów.

### 3.2 Zasady transportowe wspólne

Proponowane reguły dla nowych formatów katalogowych/eksportowych:

- UTF-8; separator `;`; LF lub CRLF; kropka dziesiętna; daty ISO `YYYY-MM-DD`; standardowe cytowanie CSV i podwajanie cudzysłowów.
- Dokładny nagłówek w określonej kolejności; bez brakujących, powtórzonych, dodatkowych ani aliasowych kolumn.
- Nie wykonywać cichego trimowania, normalizacji nazw, jednostek, kategorii ani SKU. Wartość zachowuje się dokładnie albo odrzuca z numerem wiersza i polskim błędem.
- Konfigurowalne, mierzone limity rozmiaru i wierszy; pliki źródłowe i wyniki przechowywane prywatnie, SHA-256, brak publicznych URL.
- Podgląd obejmuje cały plik i nie mutuje katalogu, cen, liczników ani alergenów. Zatwierdzenie wymaga jawnego potwierdzenia operatora, ponownej autoryzacji i pełnej rewalidacji.
- Każdy błąd blokujący uniemożliwia częściowy commit. Zapisy biznesowe, rezerwacja SKU, rewizje, audyt i wynik importu są atomowe.
- Eksporty są spójnym snapshotem danych. Eksport CSV nie zmienia zatwierdzonej nazwy w celu sanitizacji; wiersz niebezpieczny dla arkusza odrzuca się z dokładnym SKU.

## 4. A. Import katalogu — proponowany kontrakt RAW v1

### 4.1 Proponowany plik

Dokładny nagłówek:

```csv
sku;entity_type;name;description;base_unit_code;category_code
```

Przykładowy plik (wartości ilustracyjne):

```csv
sku;entity_type;name;description;base_unit_code;category_code
RAW-001042;RAW;"Mąka pszenna, typ 650";"Mąka do pieczywa; przechowywać sucho";g;GRAINS
RAW-001043;RAW;Olej rzepakowy;;ml;OILS
```

`category_code` wskazuje istniejącą kategorię organizacji. Import nie tworzy kategorii ani podkategorii. `description` jest rewizjonowanym tekstem; puste pole oznacza brak opisu, nie komendę usunięcia późniejszego opisu.

### 4.2 Macierz pól importu katalogu

| Kolumna | Wymagana? / typ | Walidacja i cel domenowy | Tworzy/zmienia? | Rewizja / akceptacja |
|---|---|---|---|---|
| `sku` | Tak; tekst | Dokładny kanoniczny kod `RAW-` i 6–9 cyfr dla tworzenia RAW. Zachować znak w znak; zakaz pustego/duplikatu/aliasu. Jedyny klucz tożsamości; nigdy nie wyszukiwać po nazwie. | Zachowuje podany SKU i rezerwuje go w liczniku organizacja/RAW. Nigdy nie nadpisuje istniejącego kodu. | Tożsamość jest niezmienna; nie jest polem rewizji. |
| `entity_type` | Tak; enum | W v1 dokładnie `RAW`. `INT`, `PRD`, `MEN` są odrzucane, dopóki ich domeny nie będą gotowe. Zgodność typu z prefiksem jest obowiązkowa. | Nie tworzy osobnej domeny ani mapowania. | Brak odrębnej rewizji; typ jest częścią tożsamości domenowej. |
| `name` | Tak; UTF-8 tekst | Niepusta, limit według istniejącego pola rewizji. Bez trimowania/normalizacji. Dokładna nazwa trafia do rewizji roboczej. | Tworzy nową rewizję szkicu dla nowego SKU; różnica dla zajętego SKU to konflikt. | Zmiana zatwierdzonej nazwy wyłącznie przez zwykły proces rewizji i wymaganej akceptacji. |
| `description` | Nie; tekst | Może być pusty; limit i zasady białych znaków muszą odpowiadać walidacji domeny przed implementacją. Pole istnieje w rewizji surowca. | Tworzy wartość nowej rewizji; nie aktualizuje istniejącego rekordu. | Zmiana istniejącego opisu przez nową rewizję; zatwierdzanie zgodnie z normalnym cyklem. |
| `base_unit_code` | Tak; enum tekstowy | Jeden z kodów istniejącego rejestru: `mg`, `g`, `kg`, `ml`, `l`, `pc`. Nie akceptować aliasów `szt.` jako kodu jednostki bazowej. Początkowo tylko przeliczenia metryczne tego samego wymiaru i równoważność `szt.`/`pc`; nie importować niestandardowych gęstości ani konwersji bez osobnej specyfikacji. | Tworzy podstawową jednostkę i dozwolone jednostki w nowej rewizji. | Jednostka bazowa/konwersje należą do rewizji i przechodzą zwykłe review/approve. |
| `category_code` | Tak; tekst | Dokładny kod istniejącej kategorii tenantowej. Nie przyjmować nazwy kategorii ani ścieżki kategorii jako klucza. Kategoria nie jest tworzona w tym imporcie. | Wskazuje kategorię w nowej rewizji. | Kategoria rewizjonowana; zmiana istniejącego przypisania wymaga nowej rewizji. |

**Poza plikiem RAW v1:** aliasy, alergeny, atestacje, dokumenty, kody dostawcy, GTIN, ilości opakowań, ceny, VAT, dostępność, koszt produkcji, stan aktywacji oraz wewnętrzne UUID. Jeśli później zostanie zatwierdzony dodatkowy atrybut katalogowy, powinien dostać jawne pole i regułę wersjonowania, a nie trafić do swobodnego JSON/„dodatkowych kolumn”.

### 4.3 Semantyka importu i przypadki brzegowe

| Przypadek | Proponowany wynik |
|---|---|
| Nowy, wolny `RAW-…` | Podgląd `UTWORZENIE SZKICU`; przy commit atomowo rezerwuje kod i tworzy rekord `DRAFT` z niezatwierdzoną rewizją, nie aktywny produkt. |
| Istniejący SKU, identyczny typ i atrybuty | `BEZ ZMIAN` / idempotentne rozpoznanie istniejącego rekordu; nie tworzy kolejnej rewizji ani nie zmienia stanu. Identyczność liczona po kanonicznym zestawie pól importowanych i bieżącym stanie organizacji. |
| Istniejący SKU, zmieniona nazwa/opis/jednostka/kategoria | `KONFLIKT ISTNIEJĄCEGO SKU`; żadnego upsertu. Operator używa normalnego, autoryzowanego procesu rewizji. Osobny tryb aktualizacji wymaga nowej decyzji właściciela. |
| SKU przypisane do innego typu/prefiksu lub encji | `KONFLIKT SKU`; cały plik nie może zostać zatwierdzony. Nie wolno zmieniać typu ani remapować SKU. |
| Powtórzone SKU w jednym pliku | Błąd całego pliku z oboma numerami wierszy, nawet jeśli wiersze są identyczne. |
| Brak SKU | Błąd wiersza/plikowego podglądu. Nigdy nie generować numeru przez alokator jako „pomoc” importerowi. |
| Brak typu, kategorii lub jednostki | Błąd walidacji; nie zgadywać na podstawie SKU, nazwy, kategorii domyślnej ani dostawcy. |
| Istniejący historyczny `SUR-…` | Rekord i historia zachowują `SUR` bez przepisywania. W imporcie katalogowym v1 rekomendowane jest odrzucenie `SUR` dla tworzenia. Opcjonalne rozpoznanie identycznego istniejącego SUR może być zatwierdzone oddzielnie; nie może tworzyć nowego rekordu z `SUR`. |
| Pozycja z poprzedniego pliku nieobecna w powtórnym imporcie | Brak operacji. Nie archiwizować, usuwać, blokować ani wycofywać rekordu lub jego rewizji. |
| Import szkicu / rewizja `DRAFT` albo `VERIFICATION` | Pokazuje stan i wynik walidacji bez zastępowania rewizji. Commit nie zatwierdza istniejącego szkicu ani nie „przeskakuje” kolejki review. |
| Alergeny lub zgodność dostawcy nieznane | Pozostają `UNKNOWN`/`PENDING`. Nie można deklarować „brak alergenów”, zgodności ani aktywacji na podstawie brakujących kolumn. |
| Dane zmienione po podglądzie | Konflikt wersji/snapshotu. Wymaga nowego podglądu; żaden wiersz nie zostaje zapisany. |

**Idempotencja:** ten sam aktor, organizacja, operacja, klucz idempotencji i hash źródła zwracają poprzedni wynik. Ponowne przesłanie z nowym kluczem może wykazać `BEZ ZMIAN` dla identycznych wpisów; nie tworzy drugiego rekordu. Ten sam klucz z inną zawartością jest konfliktem. Kod z wcześniejszej rezerwacji nigdy nie jest używany ponownie, nawet gdy wcześniejszy workflow zakończył się odrzuceniem po utworzeniu szkicu.

## 5. Opakowania i dane handlowe

### 5.1 Opakowanie nie należy do katalogowej tożsamości RAW

`base_unit_code` identyfikuje jednostkę bazową składnika. Ilość w opakowaniu i jednostka zakupu zależą od konkretnego produktu dostawcy oraz jego rewizji. Ten sam składnik może mieć wiele produktów/opakowań, różnych dostawców, GTIN-ów, nazw handlowych i ofert. Dlatego nie należy dodawać `packaging_quantity` ani `packaging_unit` do podstawowego importu RAW i nadpisywać nimi danych master.

Jeśli właściciel zatwierdzi dalszy zakres, osobny plik rejestracji produktu dostawcy mógłby mieć nagłówek:

```csv
raw_material_sku;supplier_code;supplier_product_code;gtin;product_name;purchase_unit_code;content_quantity;content_unit_code
RAW-001042;SUPPLIER_A;FLOUR-25;05901234567890;Mąka 25 kg;BAG;25;kg
```

To jedynie przykład do przyszłej specyfikacji, nie zatwierdzony kontrakt. Import musiałby odwoływać się do istniejącego dostawcy i istniejącego RAW, tworzyć/wersjonować `supplier_product_revisions`, zapisywać snapshot zatwierdzonej rewizji surowca/jednostki i wymagać uprawnień dostawcy. Nie może ustawiać oceny zgodności jako zatwierdzonej ani tworzyć ceny oferty.

### 5.2 Oferta, cena opakowania i produkcja — rozdzielone pojęcia

| Pojęcie | Znaczenie i źródło | Proponowane pole eksportowe | Uprawnienia / granica |
|---|---|---|---|
| Cena referencyjna | Wartość netto PLN za zatwierdzoną jednostkę bazową RAW, z historią `effective_from`; źródło kosztów/foodcost wg istniejącego strumienia. | `reference_price_net_pln_per_base_unit` | Osobne `RAW_MATERIALS_REFERENCE_PRICE_VIEW`; nie jest ceną oferty. |
| Cena oferty | Kwota netto z oferty konkretnego dostawcy, waluta i czas/okres oferty; osobny strumień zdarzeń. | `supplier_offer_net_amount` i `supplier_offer_currency` tylko w dedykowanym eksporcie ofert. | `RAW_MATERIALS_SUPPLIER_VIEW` oraz `RAW_MATERIALS_PURCHASING_COSTS_VIEW`; dane tenantowe i handlowe. |
| Cena zakupu za opakowanie | Cena dla jednostki/rozmiaru konkretnego produktu dostawcy; zależy od `supplier_product_revision` i nie jest ceną za jednostkę bazową. | Nie eksportować jako niekwalifikowanego `price` ani razem z ceną referencyjną. | Dedykowany eksport kosztów i uprawnienia zakupowe; zachować walutę oraz jednostkę opakowania. |
| Koszt produkcji | Koszt konkretnej produkcji/zużycia oparty o snapshot kosztowy; nie jest ceną katalogu ani aktualną ofertą. | Nie włączać do CSV katalogu ani referencyjnego eksportu Stage 2E. | Własna domena, zakres i uprawnienia Production/Foodcost; historycznych snapshotów nie przeliczać. |

## 6. B. Eksport katalogu rozszerzony

### 6.1 Zgodność ze Stage 2E.3

Istniejący eksport `ingredient_id;ingredient_name` pozostaje bez zmian i zachowuje swój dotychczasowy endpoint. Dodano oddzielny profil rozszerzony z osobnymi endpointami; nie zmienia on konsumentów Stage 2E.3 ani importu cenowego.

### 6.2 Zaimplementowany format rozszerzony RAW

Dokładny nagłówek:

```csv
sku;entity_type;name;description;category_code;category_path_codes;base_unit_code;approved_revision_no
```

Przykładowy wiersz:

```csv
RAW-000123;RAW;"Mąka; ""biała""";"Opis; dokładny ""cytat""";FLOUR;DRY_GOODS/GRAINS/FLOUR;g;1
```

Wiersz zawiera wyłącznie aktywny RAW z niepustym i spójnym `approved_revision_id`. `name` i `description` pochodzą dokładnie z tej rewizji. `category_path_codes` jest ścieżką kodów kategorii od korzenia do liścia, rozdzieloną `/`. Eksport używa standardowego quoting CSV z separatorem `;`, UTF-8 i LF. Nie eksportuje UUID kategorii ani rekordu. `approved_revision_no` to jawny numer rewizji, nie identyfikator wewnętrzny. Dla historycznego `SUR-…` kolumna `entity_type` nadal ma wartość `RAW`; SKU jest kopiowane dokładnie z rekordu, bez generowania ani normalizacji.

### 6.3 Macierz pól katalogu eksportowego

| Kolumna | Wymagana? | Źródło i reguła |
|---|---|---|
| `sku` | Tak | Dokładna, niezmienna wartość `raw_materials.code`; dla istniejących historycznych rekordów zachować `SUR-…`, dla nowych RAW `RAW-…`. |
| `entity_type` | Tak | Stała `RAW` dla tego eksportu; nie sugerować, że istnieją jeszcze domeny INT/PRD/MEN. |
| `name` | Tak | Dokładna nazwa aktualnie zatwierdzonej rewizji; bez trimowania, aliasów ani nazw dostawców. |
| `description` | Tak, może być pusta | Opis z tej samej zatwierdzonej rewizji; puste pole oznacza brak opisu, a nie błąd/skutek usunięcia. |
| `category_code` | Tak | Kod kategorii przypisanej do tej zatwierdzonej rewizji. |
| `category_path_codes` | Tak | Deterministyczna ścieżka kodów; gdy kategoria nie ma rodzica, tylko kod liścia. |
| `base_unit_code` | Tak | Zatwierdzony kod jednostki bazowej (`mg`, `g`, `kg`, `ml`, `l`, `pc`). Nie podmieniać na jednostkę zakupu. |
| `approved_revision_no` | Tak | Numer zatwierdzonej rewizji do identyfikacji wersji danych; bez UUID i bez draft/pending rewizji. |

### 6.4 Ograniczenia bezpieczeństwa i semantyki eksportu

- Wymagane pozostają `RAW_MATERIALS_VIEW`, członkostwo i bieżący kontekst lokalizacji oraz istniejąca bramka `RAW_MATERIALS`. Pobranie ponownie sprawdza prawo dostępu i organizację.
- Ograniczyć zapytanie do organizacji aktora, spójnej zatwierdzonej rewizji i `ACTIVE`; sortować po dokładnym SKU. Snapshot musi opierać się na jednym, spójnym odczycie, bez łączenia nazwy z inną rewizją niż kategoria/jednostka.
- Nie eksportować aliasów, UUID, cen, VAT, ilości, dostawców, dokumentów, audytu, deklaracji alergenów ani stanu oceny produktu dostawcy w profilu katalogu podstawowego. Dalsze rozszerzenia tych danych wymagają oddzielnej allowlisty i decyzji o uprawnieniach.
- Zachować ochronę przed formula injection: SKU, nazwy i opisy, których wierne zapisanie mogłoby zostać wykonane jako arkuszowa formuła, powodują jawne odrzucenie z listą SKU i pól; nie dodawać apostrofu ani innego prefiksu do wartości zatwierdzonej.
- Eksport plikowy jest prywatny, bez częściowego wyniku, z limitem i TTL, SHA-256, audytem utworzenia i pobrania oraz ponowną autoryzacją przy download. Brak publicznego URL.
- Endpointy rozszerzonego profilu: `POST /api/v1/raw-materials/catalog-exports/extended` oraz `GET /api/v1/raw-materials/catalog-exports/extended/{export}/download`.

### 6.5 Eksport cen referencyjnych i metadanych — Stage 2E.6

Osobny profil jest zaimplementowany; nie zmienia dwóch profili katalogowych ani importu cenowego. Dokładny nagłówek:

```csv
as_of_date;sku;entity_type;approved_name;category_code;base_unit_code;reference_price_net_pln_per_base_unit;reference_price_effective_from;reference_price_status;vat_rate_percent;vat_input_unit;metadata_effective_from;metadata_status
```

`POST /api/v1/raw-materials/reference-price-exports` przyjmuje wymagane `as_of_date` w ścisłym formacie `YYYY-MM-DD`; `GET /api/v1/raw-materials/reference-price-exports/{export}/download` pobiera prywatny plik. Obie trasy zachowują bramkę gotowości RAW_MATERIALS i kontekst członkostwa/lokalizacji. Wymagane jest `RAW_MATERIALS_REFERENCE_PRICE_VIEW`; pobranie ponownie sprawdza uprawnienie.

Każdy wiersz pochodzi ze spójnego pojedynczego odczytu aktywnego RAW i zatwierdzonej rewizji, z dokładnym SKU oraz nazwą/kategorią/jednostką tej rewizji. Cena wybierana jest niezależnie jako ostatnie zdarzenie `effective_from <= as_of_date`, z rozstrzygnięciem równych dat przez malejący `event_no`. Metadane VAT/jednostki są wybierane niezależnie według daty efektywnej, czasu zapisu i identyfikatora. Cena i VAT/jednostka nigdy nie są wyprowadzane z bieżącego stanu drugiego strumienia.

Status ceny to `KNOWN` lub `NO_REFERENCE_PRICE`; status metadanych to `KNOWN` lub `UNKNOWN`. Cena wycofana albo brak zdarzenia cenowego daje pustą kwotę i datę ceny oraz `NO_REFERENCE_PRICE`. Brak metadanych daje puste pola VAT, jednostki wejściowej i daty metadanych oraz `UNKNOWN`. Ceny są renderowane bez arytmetyki zmiennoprzecinkowej. Historyczna `vat_input_unit` nie zastępuje `base_unit_code`. Profil korzysta z istniejącego prywatnego storage, manifestu, SHA-256, limitów, TTL, audytu i ochrony formula injection.

## 7. C. Import cen referencyjnych — kontrakt bez zmian

### 7.1 Format pozostaje sześciokolumnowy

```csv
ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date
RAW-001042;Mąka pszenna, typ 650;4.1250;23;kg;2026-11-01
SUR-000125;Jajko;0.9200;5;szt.;2026-11-01
```

Jest to zaimplementowany format Stage 2E.2 i obowiązującego `VELTRA_CSV_DATA_EXCHANGE_CONTRACT.md`. `ingredient_id` przyjmuje dokładne istniejące `RAW-…` i historyczne `SUR-…`. `SUR` nie jest przemapowywany. Brak SKU w bazie organizacji oznacza błąd — import cen nigdy nie tworzy składnika.

### 7.2 Macierz pól importu cenowego

| Kolumna | Reguła bez zmian | Pole poza zakresem/ochrona |
|---|---|---|
| `ingredient_id` | Dokładny SKU istniejącego RAW/SUR w organizacji; tylko klucz dopasowania. | Nie zmienia `raw_materials.code`, UUID ani przypisania SKU. |
| `ingredient_name` | Dokładny tekst do weryfikacji przeciw aktualnej zatwierdzonej nazwie. | Nigdy nie jest kluczem i nie aktualizuje nazwy ani rewizji. |
| `price_net` | Nieujemna dziesiętna cena netto PLN za wskazane `unit`; konwersja do jednostki bazowej z HALF_EVEN_V1. | Nie jest ofertą dostawcy ani ceną opakowania/kosztem produkcji. |
| `vat_rate` | Procent 0–100; wersjonowana metadana wejścia. | Nie zmienia danych katalogowych ani stawki na rekordzie/rewizji. |
| `unit` | Dokładnie `kg`, `l`, `szt.`; wyraża jednostkę wejściowej ceny. | Nie zmienia `base_unit_code` ani konwersji zatwierdzonej rewizji. |
| `effective_date` | Data biznesowa dla as-of ceny i przejścia VAT/jednostki. | Nie jest znacznikiem czasu serwera ani momentem zatwierdzenia. |

W standardowym imporcie cen nie dodawać opcjonalnych kolumn `name`, `category`, `base_unit_code`, `supplier`, `package_quantity`, `currency` ani `sku_new`. Dodatkowe kolumny są odrzucane przez ścisły parser; nazwa mismatch blokuje wiersz, a zmiana jednostki bazowej wymaga zwykłej rewizji katalogu.

### 7.3 Niezmienione statusy, historia i atomowość

Semantyka pozostaje: `BEZ ZMIAN`, `BAZA POCZĄTKOWA METADANYCH`, `ZMIANA DANYCH`, `ZMIANA CENY`, `ZMIANA CENY I DANYCH`, `KONFLIKT DATY`. VAT/jednostka przed pierwszą zatwierdzoną bazą są nieznane. Zmiana samej daty nie tworzy zdarzenia ceny. Konflikt dla tego samego składnika i dnia odrzuca batch bez modyfikacji historii. Idempotencja, jawny commit, ponowna walidacja, historia wierszy, audyt i zapis all-or-nothing pozostają obowiązujące.

Nie zmieniać istniejącego append-only `raw_material_reference_price_events`, snapshotów kosztów ani implementacji Stage 2E.1–2E.2 w ramach prac nad importem katalogu. Eksport katalogowy również nie może ujawniać tych cen.

## 8. D. Eksport cen referencyjnych i metadanych

### 8.1 Wdrożony, osobny snapshot datowany

Profil Stage 2E.6 jest osobnym plikiem „snapshot referencyjny”, niełączonym z eksportem katalogowym ani ofertami. Nagłówek i przykładowe wiersze:

```csv
as_of_date;sku;entity_type;approved_name;category_code;base_unit_code;reference_price_net_pln_per_base_unit;reference_price_effective_from;reference_price_status;vat_rate_percent;vat_input_unit;metadata_effective_from;metadata_status
2026-01-03;RAW-000123;RAW;Mąka pszenna;FLOUR;g;0.004125;2026-01-01;KNOWN;23.00;l;2026-01-03;KNOWN
2026-01-03;SUR-000124;RAW;Historyczny surowiec;GRAINS;kg;1.23456789012345;2026-01-02;KNOWN;;;;UNKNOWN
2026-01-03;RAW-000125;RAW;Bez znanej ceny;GRAINS;g;;;NO_REFERENCE_PRICE;;;;UNKNOWN
```

`as_of_date` jest wymaganym parametrem i występuje w każdym wierszu. Cena i metadane mogą mieć różne ostatnie daty efektywne; pola dat są niezależne. Gdy na dzień snapshotu nie istnieje zdarzenie ceny albo jej ostatnim stanem jest wycofanie, cena i data są puste, a status to `NO_REFERENCE_PRICE`. Gdy nie istnieje baza VAT/jednostki, oba pola metadanych i ich data są puste, a status to `UNKNOWN`. Nie wyprowadzać VAT/jednostki z obecnego stanu ani z oferty.

### 8.2 Wdrożone uprawnienia, zakres i historia

- Operacja wymaga `RAW_MATERIALS_REFERENCE_PRICE_VIEW`, kontekstu organizacji/lokalizacji i gotowości `RAW_MATERIALS`; samo `RAW_MATERIALS_VIEW` nie autoryzuje ceny.
- Zakres ogranicza się do aktywnych RAW z zatwierdzoną rewizją, w tym dokładnie zachowanych historycznych identyfikatorów `SUR`. Zarchiwizowane rekordy pozostają poza zakresem.
- Snapshot jest ograniczony do organizacji aktora, wyznaczany jednym zapytaniem SQL i sortowany po SKU. Prywatny plik ma limit rozmiaru/wierszy i TTL, manifest z SHA-256, audyt utworzenia/pobrania oraz ponowną autoryzację pobrania.
- Cena pochodzi z append-only `raw_material_reference_price_events`: `effective_from <= as_of_date`, kolejność `effective_from DESC, event_no DESC`; `PRICE_WITHDRAWN` oznacza brak obowiązującej ceny. Kwota jest pobierana jako tekst dziesiętny.
- VAT i jednostka wejściowa pochodzą niezależnie z append-only `raw_material_import_metadata_events`: `effective_from <= as_of_date`, kolejność `effective_from DESC, recorded_at DESC, id`. Brak zdarzenia oznacza `UNKNOWN`, bez domyślnej stawki/jednostki.
- Historyczne koszty zamkniętej produkcji nie są przeliczane. Tabela korekt finansowych nie jest źródłem efektywnej historii ceny i nie służy do dopowiadania brakujących zdarzeń.

### 8.3 Czego ten eksport nie zawiera

Snapshot nie zawiera ceny oferty dostawcy, kwoty netto opakowania, waluty oferty, kursu, rzeczywistej ceny partii, ilości zakupu ani kosztu produkcji. Jeśli potrzebny będzie eksport ofert, będzie osobną operacją o uprawnieniach `RAW_MATERIALS_SUPPLIER_VIEW` i `RAW_MATERIALS_PURCHASING_COSTS_VIEW`, ze wskazaniem SKU RAW, kodu dostawcy/produktu, GTIN, rewizji opakowania, jednostki, ilości, waluty i okresu oferty. Nie używać ogólnej kolumny `price`.

## 9. Wspólne reguły autoryzacji, preview i commit

### 9.1 Autoryzacja

- Katalogowy import tworzenia szkiców powinien użyć istniejącego `RAW_MATERIALS_CREATE`, organizacyjnego membership i kontekstu lokalizacji; właściciel ma zatwierdzić, czy wymagać również osobnego uprawnienia importu. Nie nadawać go automatycznie.
- Import referencyjny zachowuje istniejące `RAW_MATERIALS_REFERENCE_PRICE_MANAGE`; eksport katalogu zachowuje `RAW_MATERIALS_VIEW`; nowy eksport referencyjny wymaga `RAW_MATERIALS_REFERENCE_PRICE_VIEW`; oferty wymagają własnych uprawnień dostawcy/kosztów.
- Wszystkie endpointy nadal podlegają `RequireRawMaterialsAvailability`; realne żądania pozostają `503` w stanie `RAW_MATERIALS NOT_READY`. Test fixtures nie mogą być sposobem na ominięcie produkcyjnej bramki.
- Operacje są tenant-scoped od początku do końca. Nie akceptować organization ID, location ID ani wewnętrznych UUID z CSV jako autorytatywnego kontekstu.
- Powiązane tabele mutowalne wymagają istniejących, podpisanych zakresów; SKU zachowuje `sku.allocate`, a rewizje/katalog i ceny wykorzystują właściwe już granice. Nowy zakres wolno dodać tylko wraz ze zdefiniowanym subject binding, audytem i testami ograniczeń.

### 9.2 Podgląd, konflikt współbieżności i commit

1. Przechowuj oryginalny plik prywatnie, oblicz SHA-256 i sparsuj ściśle do kompletnego wyniku podglądu z numerami wierszy.
2. Zapisz snapshot wartości i wersji, z którymi wynik został porównany: stan SKU/encji, hash pól katalogowych lub rewizji, kategorię, dostępność kodu oraz gotowość/uprawnienia.
3. Podgląd nigdy nie rezerwuje SKU i nie tworzy rewizji, cen ani metadanych.
4. Przy explicit commit wymagaj `confirmed=true`, idempotency key, zgodnego SHA-256 oraz aktualnego aktora/organizacji/kontekstu. W jednej transakcji blokuj organizację, ponownie sprawdź RBAC/readiness, zatwierdzony snapshot i wszystkie konflikty.
5. Rezerwację `RAW-…`, nowy rekord `DRAFT`, rewizję roboczą z `UNKNOWN` alergenami, historię importu i audyt zapisz atomowo. Każdy błąd cofa wszystkie wiersze biznesowe.
6. Nowe rekordy nadal przechodzą osobno `DRAFT` → `VERIFICATION` → zatwierdzenie przez istniejący workflow. Sam upload/commit nie jest zatwierdzeniem produktu.
7. Eksport czyta spójny snapshot, nigdy nie zapisuje pliku częściowego jako dostępnego i wiąże manifest z tenantem, aktorem, kontekstem, hash, terminem i wybraną datą as-of.

## 10. Wpływ na istniejący kod i migracje

| Obszar | Zgodność / wymagany kierunek |
|---|---|
| `RAW` / `SUR` | Zachować `raw_materials.code` jako jedyną tożsamość; dokładne RAW/SUR obsługują parser cenowy i migracja 035. Test 034→035 na disposable PG17.11 potwierdził zachowanie istniejących kodów SUR i backfill przypisań `LEGACY`. Import katalogu RAW v1 tworzy wyłącznie SKU RAW; nie globalnie przepisywać SUR. |
| Alokator SKU | Import RAW v1 używa `SkuAllocationService::reserveImported()` i istniejącego append-only rejestru alokacji. Alokator wspiera RAW/INT/PRD/MEN, lecz domeny INT/PRD/MEN nie są zaimplementowane. |
| Import cenowy | Bez zmian parsera/semantyki; zachować kontrakt 6 kolumn, statusy, historię, efektywne daty, idempotencję i ograniczenia. Tylko dokładny RAW/SUR; brak auto-create i żadnych kolumn katalogowych. |
| Eksport Stage 2E.3 | Zachować dotychczasowy endpoint i dwie kolumny dla kompatybilności. Rozszerzony profil to nowy jawny format/operacja, nie zmiana nagłówka istniejącego pliku. |
| Cykl rewizji | Użyć istniejących rewizji, jednostek, kategorii, hash i audytu. Import tworzy roboczą rewizję; submit/review/approve pozostają istniejącą ścieżką. Nie tworzyć alternatywnej tabeli katalogu. |
| Alergeny | Import nie może podawać „UNKNOWN” jako deklaracji braku ani wstawiać deklaracji/atestacji. Zachować wymóg dokładnej rewizji, wersji opublikowanego słownika i podpisanego zakresu alergenów dla zwykłego zatwierdzenia. |
| Izolacja i uprawnienia | Użyć scoped queries i złożonych FK; zachować membership/lokalizację, RBAC, readiness i kontrole przy download. Ceny i dane dostawcy nigdy nie dziedziczą dostępności z `RAW_MATERIALS_VIEW`. |
| Historia katalogowego importu | Migracja 034 pozostaje historią cenową. Stage 2E.4 dodał osobną forward-only migrację 036 z tenant-scoped, append-only historią batchy i wierszy importu RAW; nie przeciążono tabel cenowych. |
| Eksport katalogu rozszerzony | Stage 2E.5 wykorzystuje istniejące tabele i manifest, bez migracji. Osobny profil rozszerza odczyt zatwierdzonej rewizji o kategorie i jednostkę; Stage 2E.3 pozostaje niezmieniony. |
| Eksport referencyjny | Może użyć istniejących append-only cen i metadanych as-of oraz prywatnego eksportu. Nowe tabele nie są uzasadnione, chyba że przyjęta polityka wymaga trwałej historii eksportów poza obecnym manifestem/audytem. |
| Opakowania i oferty | Użyć istniejących dostawców, produktów/rewizji i eventów ofert; odrębna autoryzacja. Nie tworzyć równoległej tabeli „catalog package” ani łączyć cen w jednym polu. |
| Migration 034→035 | **Zweryfikowana na disposable PG17.11:** istniejące kody SUR pozostały bez zmian; przypisania SKU uzupełniły się jako `LEGACY`; liczniki RAW/INT/PRD/MEN utworzono, a licznik SUR zachował stan. Test alokatora i regresji historii cen: 2 testy, 44 asercje — PASS. Test nie dotyczył żadnej bazy ani wolumenu aplikacyjnego. |

## 11. Pozostała kolejność wdrożenia

1. **Decyzje dla pozostałych zakresów:** zatwierdzić dodatkowe pola, przyszłe zasady rozpoznawania `SUR`, uprawnienia i zakres cen eksportowych. Nagłówek RAW v1 i jego semantyka są wdrożone; nie rozpoczynać INT/PRD/MEN.
2. **Upgrade-test migracji 034→035 — ukończony:** test na disposable PG17.11 potwierdził zachowanie istniejących `SUR`, backfill przypisań i dalszą alokację RAW.
3. **Import katalogu RAW — ukończony w Stage 2E.4:** osobny parser, prywatny podgląd, import przez centralny alokator, szkice, historia append-only, signed mutations, audyt, idempotencja, atomowość i ponowna walidacja.
4. **Testy katalogu RAW — ukończone:** import, konflikty, duplikaty, identyczność, rollback, zmiany po podglądzie, tenant isolation, readiness, niezatwierdzone alergeny i równoległa alokacja; pełna dozwolona regresja przeszła przy wyłączeniu 17 ograniczonych testów Stage 2C.
5. **Rozszerzony eksport katalogu RAW — ukończony w Stage 2E.5:** osobny profil obok dwukolumnowego Stage 2E.3; spójny snapshot, dokładne SKU/nazwy, ścieżka kategorii, jednostka, formula injection, limity, prywatny plik, testy izolacji i reautoryzacji.
6. **Eksport ceny referencyjnej:** dopiero po zatwierdzeniu pól i reguł daty as-of, pustych stanów, widoczności cen i zakresu; odrębne uprawnienie i testy VAT/jednostek bez syntetycznych wartości.
7. **Osobny import/eksport produktów dostawcy, opakowań i ofert:** tylko po decyzji, oddzielne pliki i uprawnienia; zgodność ze snapshotami rewizji, atestacją kompatybilności i walutą oferty.
8. **INT/PRD/MEN:** dopiero po zaprojektowaniu i zatwierdzeniu ich domen, cyklu życia, pól i uprawnień; centralny alokator jest gotowy do współdzielenia, ale sam nie stanowi tych domen.

## 12. Decyzje właściciela do zatwierdzenia

| Temat | Rekomendowana opcja | Alternatywy | Konsekwencje operacyjne | Konsekwencje implementacyjne |
|---|---|---|---|---|
| Układ wymiany | Osobny, ścisły CSV na operację; wspólne zasady transportowe, bez uniwersalnego upserta. | Jeden szeroki plik z opcjonalnymi kolumnami. | Kilka zrozumiałych szablonów zamiast wielu pustych/pomyłkowych kolumn. | Osobne walidatory/workflow, mniejsze ryzyko cross-domain; brak dynamicznego schematu. |
| Zakres importu katalogu v1 | RAW-only; `sku;entity_type;name;description;base_unit_code;category_code`; nowe szkice. | Odroczyć import do po stworzeniu wszystkich domen lub dodać typy w jednym pliku. | Obecnie można przygotować katalog surowców; inne kategorie poczekają. | Wykorzystuje istniejący model; `INT/PRD/MEN` odrzucane do czasu domen. |
| `SUR` w importowaniu katalogu | RAW v1 odrzuca `SUR`; zachowanie istniejących rekordów i dokładne dopasowanie w imporcie cenowym pozostają bez zmian. | Przyszłe, jawne rozpoznanie istniejącego rekordu SUR w katalogowym CSV. | W v1 operatorzy używają wyłącznie `RAW-`; nie zmienia się historycznych identyfikatorów. | Obecny parser przyjmuje wyłącznie RAW. Obsługa SUR w katalogowym reimportcie wymaga osobnego zakresu i decyzji. |
| Reimport istniejącego SKU | Identyczne = `BEZ ZMIAN`; różne pola = konflikt; brak auto-update. | Jawny tryb aktualizacji katalogu z kontrolą rewizji. | Operator poprawia rekord zwykłym workflow, nie przez import zbiorczy. | Najmniejszy i najbezpieczniejszy zakres; tryb update wymaga osobnego permission, preview różnic i rewizji. |
| Kategorie | `category_code` musi wskazywać istniejącą kategorię; katalog kategorii importowany/zarządzany osobno. | Import kategorii i produktów w jednym pliku. | Kategorie konfiguruje się przed wsadem produktów. | Unika zależności kolejności wierszy, cykli i bocznego tworzenia struktury. |
| Jednostki i konwersje | V1 akceptuje jednostkę bazową i automatyczne przeliczenia tego samego wymiaru; brak material-specific conversions w pliku. | Dodatkowe pliki/kolumny konwersji ze współczynnikiem i podstawą. | Nietypowe przypadki wymagają zwykłej edycji/rewizji ręcznej. | Prostota i brak zgadywania; późniejsza specyfikacja musi zabezpieczyć precyzję i podstawę konwersji. |
| Dane dostawcy/opakowanie | Wyłączyć z importu RAW; osobny import `supplier_product_revision`. | Umieścić jedno opakowanie w wierszu katalogu surowca. | Można utrzymać wiele opakowań/dostawców bez duplikacji składnika. | Używa istniejącego modelu supplier products; wymaga osobnej autoryzacji i compatibility flow. |
| Alergeny i aktywacja | Import tworzy DRAFT i nie ustawia zatwierdzonych alergenów/akceptacji; aktywacja tylko istniejącym workflow. | Import może przyjmować deklarację i wysłać do weryfikacji. | Każdy nowy składnik wymaga jawnej weryfikacji i akceptacji. | Zachowuje podpisane granice Stage 2D i atestację konkretnej rewizji. |
| Eksport katalogu rozszerzony | Wdrożony zgodnie z osobnym nagłówkiem Stage 2E.5; SKU, typ, zatwierdzona nazwa, opis, kategoria/ścieżka, jednostka bazowa i numer rewizji. | Zmienić istniejący nagłówek. | Konsumenci starego CSV pozostają bez zmian. | Nowy profil bez migracji i bez modyfikacji parsera cenowego; nie jest to już otwarta decyzja w zatwierdzonym zakresie Stage 2E.5. |
| Eksport cen/metadanych | Wdrożono Stage 2E.6: oddzielny snapshot as-of, permission reference prices, osobne daty i jawne statusy `KNOWN`/`NO_REFERENCE_PRICE` oraz `KNOWN`/`UNKNOWN`. | Nie eksportować cen/metadanych przez CSV; ewentualne przyszłe zmiany formatu wymagają nowej decyzji. | Raport handlowy wymaga jawnej autoryzacji; brak ceny nie jest mylony z zerem. | Nowy profil współdzieli prywatny storage, manifest i audyt; używa istniejących strumieni zdarzeń bez ofert i migracji. |
| Eksport oferty/ceny zakupu | Odrębny przyszły eksport, z ceną, walutą, opakowaniem i dostawcą jako jawnie powiązanymi danymi. | Włączyć te pola do snapshotu ceny referencyjnej. | Dane poufne tylko dla użytkowników zakupowych. | Wymaga supplier/cost permissions, odrębnych testów tenant i audytu; niejednoznaczna kolumna `price` zakazana. |
| Trwała historia importu katalogu | W RAW v1 zastosowano osobną migrację forward-only 036 z append-only batch/row tables. | Tylko krótkotrwały podgląd w cache i ogólny event audytu. | Możliwe odtworzenie wyniku wsadu i per-wierszowego rozstrzygnięcia. | Migracja 036 nie przeciąża cenowych tabel Stage 2E.1; rozszerzenia dla innych domen wymagają osobnej decyzji. |
| Przykładowy XLSX v1.x | Rozważyć dopiero po udostępnieniu pliku jako materiału pomocniczego, bez automatycznego mapowania. | Pominąć i zebrać wymagania tylko od operatorów VELTRA. | Nie uzależnia projektu od niedostępnego arkusza. | Brak parsera XLSX i ryzyka kopiowania niezatwierdzonych pól. |

## 13. Status i ograniczenia

- Architektura wymiany CSV jest częściowo wdrożona: Stage 2E.1–2E.2 obejmuje import cen; Stage 2E.3 i 2E.5 dwa odrębne profile eksportu katalogu RAW; Stage 2E.4 import katalogu RAW v1; Stage 2E.6 osobny, datowany eksport ceny referencyjnej i metadanych VAT/jednostki. Wymiana dostawców/opakowań oraz importy INT/PRD/MEN pozostają propozycjami lub poza zakresem.
- Import katalogu obsługuje wyłącznie RAW. Importy INT/PRD/MEN i pełna wymiana opakowań/ofert nie są zaimplementowane.
- Test 034→035 i dozwoloną regresję Stage 2E.4 wykonano wyłącznie na disposable usługach. Regresja: 226 testów, 1929 asercji — PASS; dokładnie 17 ograniczonych testów Stage 2C pominięto. Nie wykonano Stage 2C security acceptance.
- Stage 2E.5 zweryfikowano na disposable PostgreSQL 17, Redis 7 i ClamAV. Dozwolona regresja: 229 testów, 1972 asercje — PASS; dokładnie 17 zastrzeżonych testów Stage 2C wyłączono. PHP lint: 241 plików — PASS; Pint --test: 15 zmienionych plików — PASS; `git diff --check` — PASS. Szczegóły znajdują się w raporcie Stage 2E.5.
- Stage 2E.6 zweryfikowano na disposable PostgreSQL 17, Redis 7 i ClamAV, w odrębnej sieci i bez podłączonych wolumenów/portów hosta. Dozwolona regresja: 234 testy, 2044 asercje — PASS; dokładnie 17 zastrzeżonych testów Stage 2C wyłączono. PHP lint: 4 zmienione pliki — PASS; Pint --test: 4 pliki — PASS; `git diff --check` — PASS. Szczegóły znajdują się w raporcie Stage 2E.6.
- Migracja 036 dodaje historię katalogowego importu RAW. Nie aktywowano `RAW_MATERIALS` ani `VSEF`; readiness gate pozostaje wymagany i nie stanowi akceptacji produkcyjnej.

## 14. Źródła repozytorium

- [Zatwierdzony kontrakt cenowego CSV](./VELTRA_CSV_DATA_EXCHANGE_CONTRACT.md)
- [Niezatwierdzona propozycja katalogowego importu CSV](./VELTRA_CSV_CATALOG_IMPORT_CONTRACT_PROPOSAL.md)
- [Raport standaryzacji SKU](./VELTRA_SKU_STANDARDIZATION_REPORT.md)
- [Raport modelu danych Stage 2E.1](./VELTRA_STAGE_2E1_CSV_DATA_MODEL_REPORT.md)
- [Raport workflow Stage 2E.2](./VELTRA_STAGE_2E2_CSV_IMPORT_WORKFLOW_REPORT.md)
- [Raport eksportu Stage 2E.3](./VELTRA_STAGE_2E3_CSV_CATALOG_EXPORT_REPORT.md)
- [Raport eksportu cen referencyjnych Stage 2E.6](./VELTRA_STAGE_2E6_REFERENCE_PRICE_EXPORT_REPORT.md)
- [Architektura RAW_MATERIALS Stage 2A](./VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md)
- `backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php` — tożsamość, kategorie, rewizje, jednostki i cykl życia.
- `backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php` — dostawcy, produkty dostawców, rewizje opakowań, oferty, eventy cenowe i uprawnienia.
- `backend/database/migrations/2026_11_06_000034_create_raw_material_csv_import_history.php` — historia importu cenowego.
- `backend/database/migrations/2026_11_06_000035_standardize_business_sku_allocation.php` — wspólny alokator, rejestr przypisań i zachowanie historycznych SUR.
- `backend/app/Domain/RawMaterials/RawMaterialCsvParser.php`, `RawMaterialCsvImportWorkflow.php`, `RawMaterialCsvImportPersistenceService.php` — zaimplementowany import cenowy.
- `backend/app/Domain/RawMaterials/RawMaterialCsvCatalogExportWorkflow.php` — istniejący eksport katalogu Stage 2E.3.
- `backend/app/Domain/RawMaterials/RawMaterialService.php` — tworzenie/revizja/submit/approve RAW.
- `backend/app/Domain/RawMaterials/RawMaterialUnitRegistry.php` — jednostki i konwersje.
- `backend/app/Domain/Sku/SkuAllocationService.php`, `SkuPrefix.php` — alokacja i rezerwacja kodów.
