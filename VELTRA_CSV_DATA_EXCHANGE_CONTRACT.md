# VELTRA 2.0 — Kontrakt wymiany danych CSV

**Status:** zatwierdzony kontrakt do przyszłej implementacji Stage 2E  
**Zakres:** eksport katalogu składników i import przygotowanych zewnętrznie średnich cen netto  
**Ograniczenia:** dokument nie wprowadza zmian w kodzie, migracjach, bazie, gotowości modułów ani statusie Stage 2C.

## 1. Cel i granice odpowiedzialności

VELTRA udostępnia identyfikatory oraz bieżące, dokładne nazwy składników, aby system zewnętrzny mógł wykonać mapowanie i obliczenia. Analiza faktur, OCR, KSeF, księgowanie, identyfikacja składników, przeliczenie opakowań i wyliczenie średniej ceny odbywają się poza VELTRA. Do importu trafia wyłącznie kompletny, już obliczony plik.

Import zapisuje cenę referencyjną składnika; nie jest zdarzeniem ceny pojedynczej oferty dostawcy ani rzeczywistym kosztem zużytej partii. Nie zmienia zamkniętych snapshotów kosztów produkcji, ofert dostawców, rewizji produktu dostawcy ani danych magazynowych.

## 2. Istniejąca tożsamość i docelowy strumień cen

- Jedyną tożsamością składnika jest istniejący rekord `raw_materials`; nie powstaje drugi katalog ani alternatywny identyfikator.
- W kolumnie `ingredient_id` należy przekazywać niezmienny kod biznesowy `raw_materials.code` w formacie `SUR-…`, unikalny w obrębie organizacji. Import rozwiązuje go wyłącznie w organizacji bieżącego kontekstu, a następnie używa wewnętrznego UUID `raw_materials.id`. UUID nie jest drugim kluczem CSV.
- `ingredient_name` pochodzi dokładnie z `raw_material_revisions.name` wskazanej przez `raw_materials.approved_revision_id`. Nie używa się aliasu, nazwy dostawcy ani nazwy z niezatwierdzonej rewizji.
- Wartości netto są przechowywane w istniejącym append-only `raw_material_reference_price_events` jako `net_pln_per_base_unit` i `effective_from`. Ponieważ CSV nie ma kolumny waluty, `price_net` i średnia cena wejściowa są **zawsze w PLN**.
- Ceny ofert pozostają w `supplier_offer_events`, z osobnymi walutami, jednostkami opakowania i rewizją produktu. Nie wolno używać strumienia ofert jako zamiennika ceny referencyjnej.

Podstawa implementacyjna: `raw_materials.code` i rewizje w migracji `2026_11_02_000011_create_raw_materials_core.php`; strumienie cen, walut i audytu w `2026_11_03_000013_create_raw_materials_commercial_domain.php`; kontrakt obliczeń i historycznych kosztów w sekcjach 5.5b, 5.7–5.8 i 10 pliku `VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md`; rozdzielenie ofert od ceny referencyjnej w `VELTRA_STAGE_2C_ARCHITECTURE.md`.

## 3. Format pliku

Kodowanie UTF-8, separator pól `;`, separator dziesiętny `.`, daty ISO `YYYY-MM-DD`. Nagłówek jest obowiązkowy i ma dokładnie podaną kolejność i nazwy. Parser obsługuje quoting CSV zgodny z regułą podwajania cudzysłowów; separator lub cudzysłów w wartości wymaga ujęcia pola w `"..."`. Rekordy mogą być zakończone LF lub CRLF. Plik z nieprawidłowym UTF-8, niepełnym rekordem lub niejednoznacznym parsowaniem jest odrzucany w całości.

```csv
ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date
SUR-000123;Mąka pszenna;4.1250;23;kg;2026-11-01
SUR-000124;Mleko 2%;3.4500;5;l;2026-11-01
SUR-000125;Jajko;0.9200;5;szt.;2026-11-01
```

Żadne dodatkowe, brakujące, powtórzone ani inaczej nazwane kolumny nie są akceptowane. Puste pola są błędem.

| Kolumna | Kontrakt |
|---|---|
| `ingredient_id` | Wymagany, dokładny kod `SUR-…`. Jedyny klucz dopasowania składnika. Nie podlega normalizacji do innego rekordu. |
| `ingredient_name` | Wymagana, dokładna nazwa zatwierdzonej rewizji w chwili eksportu. Tylko do kontroli przez operatora; nigdy nie jest kluczem wyszukiwania. |
| `price_net` | Wymagana, średnia cena netto w PLN za ilość określoną w `unit`. Zapis dziesiętny ASCII z kropką, bez separatorów tysięcy, waluty, spacji i notacji wykładniczej; wartość nieujemna, w granicach precyzji zatwierdzonej dla cen (`NUMERIC`, bez float). |
| `vat_rate` | Wymagana stawka VAT jako procent, nie ułamek: od `0` do `100`, maksymalnie dwie cyfry po kropce. Jest metadanymi podatkowymi wejścia, nie składnikiem ceny netto. |
| `unit` | Dokładnie jedna z wartości `kg`, `l`, `szt.`; bez zamiennych aliasów. Jednostka, w której podano `price_net`. |
| `effective_date` | Wymagana data kalendarzowa `YYYY-MM-DD`, bez czasu i strefy. Mapowana na `effective_from` istniejącego strumienia ceny referencyjnej. |

Wiersze z ujemną ceną, wartością spoza zakresu, nieobsługiwaną jednostką, niepoprawną datą, NaN/Infinity, dodatkowym tekstem lub nieakceptowaną precyzją są odrzucane. Zera nie są odrzucane przez sam format — obowiązujące ograniczenia bazy i zatwierdzone reguły biznesowe rozstrzygają dopuszczalność wartości.

## 4. Eksport katalogu

1. Eksport wymaga istniejącej autoryzacji katalogu (`RAW_MATERIALS_VIEW`), membership i kontekstu lokalizacji; nadal podlega bramce `RAW_MATERIALS`. Nie nadaje uprawnień i nie omija `NOT_READY`.
2. Eksport cenowy nie jest objęty tym kontraktem. Eksportuje się tylko składniki `ACTIVE` z zatwierdzoną rewizją, jeden wiersz na składnik.
3. `ingredient_id` otrzymuje niezmienny `raw_materials.code`, a `ingredient_name` dokładną wartość z aktualnie wskazanej zatwierdzonej rewizji. Nie eksportuje się nazwy z rewizji `DRAFT`/`VERIFICATION` ani aliasów.
4. Sortowanie jest deterministyczne po `ingredient_id`. Eksport katalogowy ma wyłącznie nagłówek `ingredient_id;ingredient_name`; nie zawiera pól cenowych. Import jest osobnym plikiem i wymaga pełnego sześciokolumnowego nagłówka z sekcji 3.
5. Jeśli nazwa zaczyna się (po białych znakach) od `=`, `+`, `-`, `@`, tabulatora lub znaku sterującego mogącego zostać wykonanym jako formuła arkusza, eksport CSV nie dodaje prefiksu i nie zmienia nazwy. Eksport zgłasza jawny błąd z identyfikatorem wymagającym obsługi; nie może jednocześnie zmienić nazwy i twierdzić, że jest dokładna. Konsument importu powinien traktować wszystkie pola jako tekst/dane, nie formuły.
6. Eksport większej liczby rekordów jest prywatnym zadaniem z kontrolą zakresu, ponowną autoryzacją przy pobraniu, limitem/stronicowaniem i audytem utworzenia oraz pobrania. Plik nie ma publicznego URL.

## 5. Identyfikacja, kontrola nazwy i kwalifikacja wiersza

Każdy wiersz rozpatruje się wyłącznie w obrębie organizacji aktora:

1. Wyszukaj `raw_materials.code = ingredient_id`. Nigdy nie wyszukuj po `ingredient_name`, nie próbuj „najlepszego dopasowania” i nie remapuj kodu.
2. Brak kodu w organizacji oznacza `NIEZNANY_IDENTYFIKATOR`; cały import nie przechodzi walidacji.
3. Rekord `BLOCKED`, `ARCHIVED`, bez zatwierdzonej rewizji lub nieaktywny nie jest kwalifikowany do zmiany ceny. Raportuje się konkretny błąd; nie reaktywuje się ani nie tworzy składnika.
4. Porównaj `ingredient_name` znak w znak z nazwą bieżącej zatwierdzonej rewizji. Brak zgodności daje `NIEZGODNA_NAZWA` z eksportowaną nazwą i aktualną nazwą VELTRA. Wiersz nie może być zatwierdzony do importu do czasu ponownego eksportu/świadomego przygotowania pliku; nazwa nie zmienia identyfikacji i nie następuje automatyczna zmiana, remapowanie ani korekta nazwy.
5. Import sprawdza ponownie nazwę, stan, bieżącą rewizję i oczekiwaną wersję strumienia cen podczas zatwierdzenia. Zmiana od podglądu powoduje konflikt i wymaga ponownego podglądu.

## 6. Jednostki i normalizacja ceny

Zewnętrzny system odpowiada za przeliczenie opakowań do ceny za wskazaną jednostkę. VELTRA wykonuje wyłącznie jednoznaczną, zatwierdzoną konwersję do jednostki bazowej dokładnej zatwierdzonej rewizji, by zapisać istniejący strumień ceny referencyjnej:

- `kg` ↔ jednostki masy (`mg`, `g`, `kg`) z przelicznikiem metrycznym;
- `l` ↔ jednostki objętości (`ml`, `l`) z przelicznikiem metrycznym;
- `szt.` ↔ `pc` w relacji 1:1;
- brak konwersji między masą i objętością ani między jednostką sztukową a masą/objętością. Nie wyprowadza się gęstości ani masy sztuki w imporcie.

Wartość zapisana to `price_net` podzielone przez liczbę jednostek bazowych mieszczących się w jednej jednostce z CSV, obliczone dokładnie na typach dziesiętnych z zatwierdzoną precyzją i regułą zaokrąglenia `HALF_EVEN_V1`. Przykład: `4.125 PLN/kg` dla rewizji bazowej `g` daje `0.004125 PLN/g`; `3.45 PLN/l` dla bazowego `ml` daje `0.00345 PLN/ml`. Nie zaokrąglać do dwóch miejsc w obliczeniach pośrednich. Brak poprawnej konwersji lub nadmierna precyzja to błąd, nie powód do zgadywania.

Jednostkę z CSV i pierwotne `price_net` zachowuje się w historii importu nawet po normalizacji. Cena referencyjna w istniejącym strumieniu pozostaje ceną netto PLN na jednostkę bazową.

## 7. Daty efektywności, korekty i historia

- `effective_date` jest datą biznesową, od której mają obowiązywać wartości z wiersza. Dla rzeczywistej zmiany znormalizowanej ceny mapuje się na `raw_material_reference_price_events.effective_from`; dla początkowej bazy lub rzeczywistej zmiany VAT/jednostki jest datą efektywności niezmiennej wersji metadanych. Czas przyjęcia/weryfikacji importu jest odrębnym znacznikiem czasu serwera i nie stanowi daty zmiany biznesowej.
- Daty porównuje się as-of: ustala się pełny stan ceny netto PLN na jednostkę bazową, VAT i jednostkę wejściową obowiązujący w `effective_date`, korzystając z ostatnich niezmiennych przejść obowiązujących na tę datę. Do pierwszej poprawnie zatwierdzonej bazy metadanych VAT/jednostka przed jej `effective_date` pozostają nieznane. `event_no` porządkuje zdarzenia cenowe w istniejącym strumieniu, ale nie rozstrzyga konfliktu dwóch różnych wartości biznesowych z tą samą datą.
- Format dopuszcza datę przeszłą, bieżącą lub przyszłą. Podgląd oznacza daty przyszłe i wsteczne oraz wskazuje okres obowiązywania i późniejsze przejścia, na które wpis może wpłynąć. Zatwierdzenie nie może usuwać ani aktualizować późniejszego przejścia: backdating ma jawnie pokazać zmieniony przedział do następnego przejścia. Jeśli ten wpływ nie jest zamierzony, wiersz wymaga korekty daty lub odrzucenia; nie wolno automatycznie przesuwać daty.
- Każda rzeczywista zmiana znormalizowanej ceny jest nowym `PRICE_SET`; istniejących zdarzeń nie aktualizuje się ani nie usuwa. Sama nowa `effective_date`, nowy czas importu ani ponowna weryfikacja tych samych wartości nigdy nie tworzą zdarzenia ceny ani duplikatu wersji stanu. Wartości identyczne z pełnym stanem obowiązującym w tej dacie zapisują wyłącznie weryfikację w historii batcha.
- Jeżeli dla składnika i `effective_date` istnieje już ustanowiony stan biznesowy, identyczny import jest weryfikacją bez zmian; jakakolwiek różnica w cenie znormalizowanej, VAT lub jednostce to konflikt daty. Zwykły import odrzuca konflikt, nie modyfikuje historii i nie wybiera wartości według czasu przesłania ani `event_no`. CSV ma rozdzielczość daty, więc korekty historyczne lub sprzeczne stany z tą samą datą wymagają osobnego, jawnie autoryzowanego procesu poza tym kontraktem.
- Cena netto, VAT i jednostka wejściowa są zachowane w odtwarzalnych, niezmiennych historiach. Zmiana przyszłych wartości nie zmienia zamkniętego kosztu produkcji; produkcja nadal korzysta z istniejącego snapshotu rzeczywistej partii albo historycznej ceny referencyjnej oznaczonej jako szacowana.
- Brak składnika w pliku oznacza wyłącznie „brak operacji”. Import nigdy nie usuwa, nie wycofuje, nie zeruje ani nie archiwizuje cen, metadanych lub rekordów, których wiersza nie zawiera.

## 8. Duplikaty i „BEZ ZMIAN”

- W pojedynczym pliku każdy `ingredient_id` może wystąpić najwyżej raz. Powtórzenie jest niejednoznaczne, także jeśli wiersze są identyczne; odrzuca się cały plik i wskazuje numery obu wierszy. Plik nie może sam zawierać wielu dat dla jednego składnika.
- To samo żądanie z tym samym aktorem, kluczem idempotencji i hashem pliku zwraca poprzedni wynik bez powielania wierszy weryfikacyjnych, wersji metadanych, zdarzeń cenowych ani audytu operacji. Ponowne przesłanie z nowym kluczem lub innym plikiem jest nową próbą audytową; nadal nie tworzy historii wartości, jeśli pełny stan obowiązujący dla `effective_date` jest identyczny. Ten sam klucz z inną zawartością daje konflikt.
- Dla kwalifikowanego wiersza porównuje się wszystkie trzy wartości biznesowe ze stanem obowiązującym w dacie `effective_date`: dokładną znormalizowaną cenę netto PLN na jednostkę bazową, `vat_rate` i wejściowe `unit`. Jednostkę porównuje się dokładnie jako przekazaną (`kg`, `l`, `szt.`), nawet jeżeli dwie jednostki można przeliczyć na tę samą jednostkę bazową. Jeśli dla tej daty nie istniała jeszcze wiarygodna baza VAT/jednostki, stosuje się odrębną regułę pierwszego importu poniżej, a nie status `BRAK DANYCH HISTORYCZNYCH`.
- Jeżeli wszystkie trzy wartości są identyczne ze stanem już ustanowionym dla tej daty, wiersz ma status `BEZ ZMIAN`. Do historii batcha zapisuje się niezmienny wynik weryfikacji, znacznik czasu serwera oraz źródłową `effective_date`; nie tworzy się `PRICE_SET` ani nowej wersji stanu ceny/metadanych. Nowa data efektywności sama w sobie nie jest zmianą.
- **Pierwsza zatwierdzona baza metadanych składnika:** pierwszy poprawnie zatwierdzony import ustanawia początkowy, niezmienny stan `vat_rate` i wejściowej `unit` od `effective_date` włącznie. Jest to `BAZA POCZĄTKOWA METADANYCH`, a nie późniejsza `ZMIANA DANYCH`. Brak wcześniejszej historii VAT/jednostki nie blokuje tego importu; dla dat wcześniejszych od bazy zapytania historyczne zwracają VAT/jednostkę jako nieznane, bez odtwarzania ani fabrykowania wartości.
- Podczas ustanawiania tej pierwszej bazy cena porównywana jest niezależnie z ceną referencyjną obowiązującą w `effective_date`. Jeżeli znormalizowana cena jest inna i nie ma sprzecznego zdarzenia dokładnie z tą datą, utwórz `PRICE_SET`; jeżeli cena jest identyczna, nie twórz zdarzenia ceny. W obu przypadkach zachowaj bazę metadanych i istniejące zdarzenia ceny oraz snapshoty kosztu produkcji.
- Jeżeli znormalizowana cena netto jest identyczna, ale zmienił się `vat_rate`, `unit` lub oba, wiersz ma status `ZMIANA DANYCH`. Zapisuje się niezmienną wersję historii metadanych z datą efektywności i powiązaniem z ceną netto obowiązującą w tej dacie; nie tworzy się sztucznego zdarzenia ceny.
- Jeżeli zmienia się znormalizowana cena, tworzony jest `PRICE_SET`. Przy jednoczesnej zmianie VAT/jednostki zapisuje się również niezmienny stan metadanych, a status wiersza brzmi `ZMIANA CENY I DANYCH`; jeśli zmieniła się wyłącznie cena, status brzmi `ZMIANA CENY`. W każdym przypadku zapis obejmuje tylko faktycznie zmienione tory historii, bez aktualizacji/usuwania wcześniejszych rekordów.
- Jeżeli VAT lub jednostka dla daty są nieznane dlatego, że leży ona przed pierwszą zatwierdzoną bazą, zapytanie raportuje te pola jako `NIEZNANE`; pierwszy poprawnie zatwierdzony import ustanawia bazę na swojej `effective_date`, bez wymagania sztucznej historii dla wcześniejszych dat.
- Jeżeli dla tego samego składnika i `effective_date` istnieje już ustanowiony inny stan biznesowy, zatwierdzenie jest blokowane jako `KONFLIKT DATY`. Konflikt oznacza odrzucenie wiersza/batcha i brak zmian w historii; historyczna korekta wymaga odrębnego, jawnie autoryzowanego procesu poza zwykłym CSV. Nie tworzy się drugiego przejścia tego samego dnia ani nie wybiera zwycięzcy według czasu importu.

Historia ceny i historia metadanych muszą razem pozwalać odtworzyć pełną trójkę wartości dla dowolnej daty. Weryfikacja importu jest zapisem pochodzenia/porównania, a nie zdarzeniem biznesowym; nie może powodować sztucznej historii. Wiersz `BEZ ZMIAN` pozostaje widoczny w historii batcha, bez nowej wersji stanu.

## 9. Walidacja, podgląd i atomowy commit

Import jest dwuetapowy:

1. **Przesłanie i podgląd:** prywatnie zachowaj plik i SHA-256; parsuj cały plik; wykonaj walidację składni, nagłówka, duplikatów, identyfikatorów, dokładnych nazw, stanów, uprawnień, cen, VAT, jednostek, konwersji i dat. Nie zapisuj eventów ceny.
2. **Zatwierdzenie:** jawne potwierdzenie uprawnionego aktora po pokazaniu podsumowania i zmian. W pojedynczej transakcji zablokuj mutacje organizacji zgodnie z istniejącym wzorcem, ponownie sprawdź autoryzację, gotowość, zgodność nazwy i rewizji, kompletny stan ceny/metadanych na `effective_date`, konflikty dat oraz idempotencję. Dla pierwszego poprawnie zatwierdzonego importu składnika zapisz początkową bazę VAT/jednostki, nawet jeśli starsza historia tych pól jest nieznana. Zapisz historię weryfikacji dla każdego zatwierdzonego wiersza; `PRICE_SET` twórz wyłącznie przy zmianie znormalizowanej ceny, a kolejną wersję stanu metadanych wyłącznie przy późniejszej zmianie VAT/jednostki.

**Atomowość całego batcha:** commit jest all-or-nothing. Jakikolwiek konflikt lub błąd podczas zapisu wycofuje wszystkie eventy ceny, wersje metadanych i zapisy zatwierdzenia; nie ma częściowego sukcesu ani cichego pomijania wierszy. Odrębny zapis `REJECTED` może po rollbacku zachować wynik próby w osobnej transakcji, bez maskowania pierwotnego błędu. Preview może przedstawiać błędy wielu wierszy, ale batch z choć jednym błędem jest niezatwierdzalny.

Implementacja musi wykorzystać i rozszerzyć zatwierdzony Stage 2E kontrakt `raw_material_import_batches`, `raw_material_import_rows`, `raw_materials_commercial_idempotency` i istniejące `raw_materials_commercial_audit_events`; nie tworzyć równoległego katalogu cen, własnej tabeli dostawców ani mechanizmu aktualizującego rekordy historyczne.

## 10. Podsumowanie dla operatora i audyt

Podgląd i wynik commit podają co najmniej:

- batch ID, organizację, aktora, czas serwera, hash pliku i klucz idempotencji;
- liczbę wierszy wejściowych;
- liczniki `BEZ ZMIAN`, `BAZA POCZĄTKOWA METADANYCH`, `ZMIANA DANYCH`, `ZMIANA CENY`, `ZMIANA CENY I DANYCH`, `KONFLIKT DATY`, `BŁĘDY` i `KONFLIKTY`;
- dla każdego wiersza: numer, `ingredient_id`, nazwę z CSV i bieżącą nazwę, status, jednostkę, cenę wejściową, VAT, źródłową `effective_date`, czas weryfikacji/commit, cenę znormalizowaną oraz ID istniejącego lub utworzonego zdarzenia ceny i wersji metadanych (jeśli dotyczy);
- wskazanie błędów i przyczyn po polsku, bez fałszywego statusu sukcesu.

Audyt append-only obejmuje co najmniej `IMPORT_PREVIEWED`, `IMPORT_COMMITTED` albo `IMPORT_REJECTED`, batch/operation ID, znacznik czasu weryfikacji, źródłową `effective_date`, weryfikowane wartości/stany oraz wyłącznie zdarzenia cenowe i wersje metadanych, które faktycznie utworzono. Wiersz `BEZ ZMIAN` zapisuje weryfikację batcha, lecz nie powoduje sztucznego eventu cenowego ani wersji stanu. Nie zapisuje zawartości faktur (nie są częścią pliku), sekretów ani niepotrzebnych danych osobowych.

## 11. Uprawnienia i readiness

- Eksport identyfikatorów i nazw: istniejące `RAW_MATERIALS_VIEW`.
- Podgląd/import ceny referencyjnej: `RAW_MATERIALS_REFERENCE_PRICE_MANAGE`; dodatkowe uprawnienia do kosztów zakupowych nie są domyślnie wymagane, bo import nie czyta ofert ani nie tworzy zdarzeń kosztu zakupu.
- Każda operacja wymaga aktywnej tożsamości, membership, organizacji i lokalizacji, kontroluje efektywny grant i `RAW_MATERIALS` readiness w istniejącym middleware. Nie przyznaje się uprawnień automatycznie.
- `RAW_MATERIALS` pozostaje `NOT_READY`; specyfikacja nie zezwala na uruchomienie endpointów ani import do czasu osobnej, zatwierdzonej decyzji o gotowości. VSEF nie ma związku z tym kontraktem.

## 12. Wymagane przygotowanie implementacji Stage 2E

Obecna baza cen przechowuje niezmienne referencyjne `net_pln_per_base_unit`, datę, aktora, przyczynę i audyt, ale nie zawiera VAT ani oryginalnej jednostki zewnętrznego wyliczenia. Przed implementacją trzeba rozszerzyć istniejący append-only import row/batch contract tak, aby trwale przechować: surową i znormalizowaną cenę, `vat_rate`, wejściową `unit`, niezmienny stan metadanych wraz z datą efektywności i typem (`BAZA POCZĄTKOWA METADANYCH` albo późniejsza zmiana), czas weryfikacji, dokładną nazwę/kod, organizację, hash wejścia, status wiersza i powiązane zdarzenia. Historia musi odpowiadać `NIEZNANE` VAT/jednostkę przed pierwszą bazą, nie tworząc rekordów dla wcześniejszych okresów. Wiersz `BEZ ZMIAN` wymaga historii weryfikacji bez tworzenia wersji stanu; późniejsza `ZMIANA DANYCH` wymaga nowej wersji metadanych powiązanej z obowiązującą ceną, bez eventu ceny. Powiązania muszą być kompozytowo tenant-scoped i immutable. Nie dodawać VAT do `supplier_offer_events` ani `raw_material_reference_price_events` bez odrębnej decyzji modelowej.

Zwykły import, który napotka różny stan biznesowy już ustanowiony dla tej samej daty, musi zgłosić `KONFLIKT DATY` i zostać odrzucony bez modyfikacji historii. Korekty takich stanów są poza zakresem CSV i wymagają osobnego, jawnie autoryzowanego workflow; nie wolno rozstrzygać ich automatycznie.

Zakres wymaga migracji Stage 2E dopiero po osobnej autoryzacji implementacji. Ten dokument nie zmienia żadnej struktury ani zachowania istniejącego systemu.

## 13. Kryteria akceptacji kontraktu

1. Identyfikator `SUR-…` jest jedynym kluczem dopasowania; brak/niezgodność nazwy nigdy nie remapuje składnika.
2. Eksport zawiera dokładną nazwę aktualnej zatwierdzonej rewizji i nie ujawnia cen.
3. Parser przestrzega UTF-8, `;`, kropki dziesiętnej, ścisłego nagłówka i obsługi quoting; błędny format nie daje częściowych zapisów.
4. Cena jest przeliczana wyłącznie w obrębie wymiaru i dokładnych współczynników zatwierdzonej rewizji; używa PLN i istniejącej historii cen referencyjnych.
5. VAT, wejściowa jednostka i cena źródłowa są zachowane historycznie; nie są rekonstruowane z nazwy ani z bieżącej konfiguracji.
6. Powtórzony `ingredient_id` w pliku, nieznany ID, niezgodna nazwa, nieprawidłowe wartości i wyścig wersji są jawnie raportowane; żaden wiersz nie jest cicho pomijany.
7. Pierwszy poprawnie zatwierdzony import ustanawia niezmienną bazę VAT/jednostki od `effective_date`; brak starszych metadanych jej nie blokuje, a zapytania przed bazą zwracają `NIEZNANE`.
8. Sama zmiana `effective_date` lub czasu importu nigdy nie tworzy zdarzenia ceny ani kolejnej wersji stanu; identyczne wartości ze stanem już ustanowionym dają audytowane `BEZ ZMIAN`.
9. Zmiana VAT lub jednostki przy niezmienionej cenie daje `ZMIANA DANYCH` i wersję metadanych bez eventu ceny; zmiana ceny tworzy append-only event, a zmiana obu torów zachowuje obie historie.
10. Różne wartości dla już ustanowionego stanu tego samego składnika i `effective_date` powodują `KONFLIKT DATY` i odrzucenie bez zmian; korekty wymagają osobnego, jawnie autoryzowanego workflow.
11. Pełny stan cena/VAT/jednostka jest odtwarzalny dla dat od pierwszej bazy metadanych, a wcześniejszy VAT/jednostka pozostaje `NIEZNANE`; niczego się nie zgaduje ani nie nadpisuje.
12. Batch jest atomowy, audytowany i idempotentny; brakujące wiersze nie zmieniają ani nie usuwają danych.
13. Zamknięte koszty produkcji, oferty dostawców i ich rewizje pozostają niezależne od importu.
14. Implementacja nie aktywuje `RAW_MATERIALS`/VSEF ani nie zmienia statusu Stage 2C.
