# VELTRA 2.0 — niezależny audyt bezpieczeństwa Stage 2C po remediacji

**Data:** 3 listopada 2026  
**Zakres:** ponowna, tylko do odczytu ocena dwóch wcześniej potwierdzonych ustaleń oraz RBAC, ograniczeń cyklu życia i walidacji PostgreSQL.  
**Werdykt:** **CONDITIONAL ACCEPT** — dwa pierwotne problemy na głównych ścieżkach finansowych są zaadresowane, ale pozostaje ograniczona luka integralności związana z rewizją produktu po archiwizacji dostawcy.

## Podsumowanie ustaleń

| # | Ważność | Plik | Linie | Ustalenie | Pewność |
|---|---|---|---|---|---:|
| 1 | ⚪ LOW | [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php:232), [migracja Stage 2C](./backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php:494) | Usługa: 232–267; trigger rewizji: 494–529 | Po archiwizacji dostawcy nadal można utworzyć nową rewizję jego aktywnego produktu. Trigger PostgreSQL nie weryfikuje aktywności dostawcy ani produktu przy wstawianiu rewizji. | 10/10 |

Poprzednie ustalenia MEDIUM dotyczące ujawnienia danych źródłowej oferty i nowych operacji handlowych na niekwalifikujących się encjach nie zostały ponownie potwierdzone w badanych ścieżkach. Pozostają ograniczenia testowe wskazane niżej; brak testu nie jest dowodem braku problemu.

## Weryfikacja dwóch pierwotnych ustaleń

### 1. Dane źródła ceny referencyjnej bez prawa odczytu kosztów

**Ocena: naprawione w sprawdzonej ścieżce odczytu.**

`referencePrices()` sprawdza skuteczne prawo `PURCHASING_COSTS_VIEW` i przekazuje ten wynik do prezentera. `presentReferencePrice()` zwraca `source: null`, gdy prawo kosztowe nie przysługuje; przy odpowiednim prawie źródłowe dane są dostępne. Test regresyjny potwierdza oba warianty, w tym brak prawa kosztowego oraz zachowanie źródła dla uprawnionego użytkownika.

**Zakres dowodu:** test API używa źródła w PLN. Nie sprawdzono odrębnie wszystkich pól FX, endpointu restatementów, cache, eksportu ani innych przyszłych serializatorów. Odczyt listy restatementów w aktualnym kodzie nie dołącza danych źródłowej oferty, ale należy utrzymywać testy kontraktowe dla kolejnych pól/serializatorów.

### 2. Operacje finansowe na zablokowanych lub zarchiwizowanych encjach

**Ocena: naprawione dla sprawdzonych operacji finansowych; nadal obowiązuje ograniczenie w edycji rewizji produktu.**

- `createOffer()` wymaga aktywnego produktu, dostawcy i surowca oraz rewizji produktu zgodnej z aktualnie zatwierdzoną rewizją materiału.
- `recordOfferPriceEvent()` ponownie sprawdza te stany dla nowych zdarzeń `PRICE_SET`; wycofanie historycznej oferty pozostaje odrębną operacją.
- `referencePriceSource()` sprawdza status aktywności dostawcy, produktu, materiału i oferty oraz dokładną aktualną rewizję produktu.
- Triggery PostgreSQL walidują aktywny stan przy wstawianiu ofert i zdarzeń cenowych oraz przy wyborze źródła ceny referencyjnej.
- Testy API odrzucają utworzenie oferty i nowego zdarzenia cenowego dla zablokowanego materiału oraz użycie zarchiwizowanego dostawcy jako nowego źródła ceny referencyjnej. Bezpośrednie inserty PostgreSQL do tabel ofert/zdarzeń i cen referencyjnych są odrzucane dla tych przypadków.

**Granica sprawdzenia:** osobnych prób API/SQL dla zarchiwizowanego produktu, zarchiwizowanej oferty i nieaktualnej rewizji nie wykonano w tej rundzie. Kod i triggery tych zapisów zostały przejrzane, lecz nie należy utożsamiać przeglądu z dynamicznym testem każdego wariantu.

## Ustalenie 1 — rewizja produktu po archiwizacji dostawcy

**Ważność: LOW — integralność cyklu życia, nie wykazano obejścia zapisu ceny.**

Metoda `reviseProduct()` sprawdza, czy sam produkt ma stan `ACTIVE`, czy jego aktualna rewizja odpowiada `expected_revision_id` i czy materiał jest aktywny. Nie sprawdza stanu dostawcy powiązanego z produktem. Z tego powodu konto z prawem `SUPPLIER_MANAGE` może dodać nową rewizję do aktywnego produktu także po archiwizacji jego dostawcy.

Analogicznie trigger `raw_materials_commercial_product_revision_validate()` w migracji waliduje aktywny materiał, zatwierdzoną rewizję oraz jednostkę, lecz nie wymaga aktywnego produktu i dostawcy. Bezpośredni zapis do `supplier_product_revisions` może zatem utworzyć rewizję dla produktu archiwizowanego lub dostawcy archiwizowanego, jeśli pozostałe warunki są spełnione.

Nie wykazano, że to umożliwia nowe oferty, zdarzenia `PRICE_SET` lub ceny referencyjne: odpowiadające ścieżki sprawdzają stany cyklu życia i kwalifikację rewizji. Skutek potwierdzonego braku kontroli ogranicza się do dopisywania danych produktu po archiwizacji, co narusza oczekiwany model cyklu życia i może wprowadzić mylące dane katalogowe.

**Zalecane zamknięcie przed pełną akceptacją:** wymagać aktywnego produktu i dostawcy w `reviseProduct()` oraz w triggerze wstawiania rewizji. Dodać regresje API i bezpośredniego SQL dla zarchiwizowanego dostawcy oraz produktu; w obu przypadkach nowe rewizje powinny zostać odrzucone.

## Testy i dowody wykonane podczas tej rundy

Testy wykonano na odizolowanych, jednorazowych zasobach:

- PostgreSQL 17 — 13 migracji na pustej bazie disposable: **PASS**.
- Redis 7.4 — dostępność i stan początkowy: **PONG**, **0 kluczy**.
- `RawMaterialsTest.php`: **17 testów, 184 asercje — PASS**.
- Trzy celowane testy regresyjne — źródło ukryte bez prawa kosztowego, blokada materiału oraz archiwizacja dostawcy: **3 testy, 23 asercje — PASS**.
- Testy dołączone do raportu nie obejmowały scenariusza „rewizja po archiwizacji dostawcy”; ustalenie LOW jest wynikiem niezależnego przeglądu kodu i nie zostało odtworzone dynamicznie.
- Pełnego backend suite nie uruchomiono w tej rundzie.

Powyższe liczby dotyczą wyłącznie wykonania podczas tego ponownego audytu. Nie są sumowane z historycznymi wynikami implementacji ani wcześniejszego audytu.

## Izolacja i ograniczenia

- PostgreSQL i Redis działały w dedykowanej sieci Docker `internal=true`, bez opublikowanych portów i bez mountów.
- Przed migracją baza nie zawierała tabel publicznych; testowy Redis miał zero kluczy.
- Runner testowy nie miał mountów do kodu ani danych aplikacyjnych; użył tymczasowego obrazu testowego i zasobów disposable.
- Po zakończeniu usunięto kontenery testowe i sieć. Istniejące usługi aplikacyjne pozostały uruchomione; ich wolumenów nie użyto ani nie zmodyfikowano.
- Nie wykonywano zmian kodu ani migracji w bazie aplikacyjnej; moduł `RAW_MATERIALS` nie został aktywowany.
- Instalacja obsługuje obecnie pojedynczą organizację instalacyjną, dlatego test izolacji dwóch rzeczywistych tenantów nie był częścią tej rundy.
- Brak dynamicznych testów dla wszystkich kombinacji statusu produktu/oferty/rewizji ogranicza zakres zapewnienia dotyczącego lifecycle.

## Ocena końcowa

**CONDITIONAL ACCEPT.** Dwa pierwotne ustalenia MEDIUM są zaadresowane w zweryfikowanych ścieżkach finansowych, a testy API i PostgreSQL potwierdzają wybrane przypadki negatywne. Nadal istnieje LOW problem integralności: po archiwizacji dostawcy możliwe jest dopisanie rewizji produktu przez API oraz bezpośrednim SQL. Przed pełną akceptacją należy dodać kontrolę statusu produktu/dostawcy w usłudze i triggerze oraz regresje obejmujące oba kanały zapisu.

Ten werdykt nie autoryzuje wdrożenia, migracji na bazie aplikacyjnej, aktywacji modułu ani rozpoczęcia Stage 2D. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne.
