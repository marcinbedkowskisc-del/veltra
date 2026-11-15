# VELTRA 2.0 — Stage 7A — Architektura modułów INT i PRD — v1.1

**Data aktualizacji:** 2026-11-14  
**Status:** SKONSOLIDOWANA WERSJA ROBOCZA DO ZATWIERDZENIA (bez zgody na implementację)  
**Zakres:** wspólna domena receptur, moduł INT, moduł PRD i ich integracja z RAW, RBAC, lokalizacjami, przyszłą produkcją i historią.  
**Odbiorca:** właściciel projektu, architekt, GitHub Copilot Agent.  
**Zasada źródeł:** decyzje właściciela z pytań 1–58 i wcześniejszych ustaleń Stage 7A są wymaganiami biznesowymi; dokumenty Stage 1D/1E i ADR-001 są obowiązującymi kontraktami technicznymi; KC Technologia 1.x jest wyłącznie materiałem porównawczym. Rozstrzygnięcia po pytaniu 58 i korekty C-01–C-10 mają pierwszeństwo w zakresie, w jakim zostały jawnie zatwierdzone; pozostałe zależności i prace techniczne opisano w §16.

> **Ważne dla Copilota:** Ten dokument nie jest poleceniem pisania kodu. Najpierw zweryfikuj zgodność z aktualnym repozytorium VELTRA, obowiązującymi ADR i stanem środowiska. Nie modyfikuj infrastruktury, RAW ani RBAC, nie ustawiaj modułów na READY i nie generuj migracji bez odrębnego zadania i akceptacji.

## 1. Cel i granice

VELTRA 2.0 ma uprościć pracę technologa, a nie wprowadzać mnożenie zatwierdzeń. System musi obsłużyć wielopoziomową produkcję RAW → INT → INT → PRD, skalowanie, wydajność, koszt, alergeny, udostępnianie oraz wiarygodną historię partii. **RAW, INT i PRD to trzy odrębne moduły** z osobnymi ekranami, konfiguracją aktywacji i uprawnieniami; mogą używać wspólnych serwisów domenowych, ale nie zostają scalone w jeden moduł UI. MEN pozostaje poza Stage 7A.

**Nie obejmuje:** implementacji produkcji, magazynu, POS, MEN, drukarek etykiet, migracji danych KC, aktywowania RAW/INT/PRD oraz finalnego schematu SQL/API. Kontrakty integracyjne z tymi obszarami należy opisać tak, aby późniejsze wdrożenie nie wymagało zgadywania.

## 2. Hierarchia wymagań i stan początkowy

1. **ADR-001:** odrębna instalacja/baza dla klienta (single-tenant); wiele lokalizacji w obrębie instalacji.
2. **Stage 1D RBAC v1:** sześć zatwierdzonych ról, prawa zależne od kontekstu i zasięgu, osobne `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW`, backend deny-by-default. Zatwierdzona rola najwyższa: `ADMINISTRATOR`.
3. **SUPER ADMIN:** nie jest siódmą rolą. To dodatkowe uprawnienie systemowe przypisane wybranym użytkownikom z rolą ADMINISTRATOR; nie znosi kontroli dostępu ani zasad aktywacji.
4. **Stage 1E:** status gotowości w kodzie, niezależne lokalne `ENABLED`, wyłącznie `ADMINISTRATOR` aktywuje/dezaktywuje moduł z kontrolą hasła; stan wyłączony blokuje operacje, nie usuwa danych; zależności modułów w kodzie nie aktywują się same.
5. **RAW:** zgodnie z checkpointem po Stage 6A globalny RAW pozostaje `NOT_READY`; odbiór RAW jest odrębną sprawą. Stage 7A nie zmienia tego stanu.
6. **Fundament v0.1:** wspólny logicznie katalog z własnością i udostępnianiem, snapshoty historii; ma jednak starsze reguły lokalnego tworzenia RAW i formalnej publikacji receptur, które wymagają jawnej aktualizacji kontraktu (§16).
7. **Audyt KC 1.44.0:** `VELTRA_SOURCE_11_INT_PRD_IMPLEMENTATION_AUDIT.md`, opis istniejącego PHP/MySQL, **nie wzór docelowego schematu**. W 1.x INT funkcjonuje jako składnik, produkt PRD ma odrębny formularz; mechanizmy obliczeń i kosztów mają ograniczenia, dokumenty historyczne mogą korzystać z bieżących pól. VELTRA nie ma kopiować tych słabości.

## 3. Architektura funkcjonalna

```text
INSTALACJA KLIENTA
  ├─ RAW — katalog surowców, alergeny, źródłowe ceny / jednostki / synonimy
  ├─ INT — osobny moduł półproduktów i ich receptur
  │    └─ składniki: RAW lub INT (dowolne poprawne zagnieżdżenie; bez cykli)
  ├─ PRD — osobny moduł produktów końcowych i ich receptur
  │    └─ składniki: RAW lub INT; PRD nie jest składnikiem innej receptury
  └─ WSPÓLNE USŁUGI DOMENOWE (nie osobny moduł użytkownika)
       ├─ tożsamość SKU / grupy / udostępnienia / wersje i szkice
       ├─ resolver grafu receptur / blokady cykli / stan gotowości
       ├─ kalkulator wsadu, uzysku, skalowania, konwersji i strat
       ├─ kosztowanie rekurencyjne i uprawnienia widoczności
       ├─ propagacja alergenów z RAW
       └─ kontrakt snapshotu dla przyszłych partii i PDF receptury
```

**Rozdział odpowiedzialności:** INT przechowuje pełnoprawną recepturę półproduktu; PRD przechowuje recepturę wyrobu finalnego, ręczny skład etykietowy, ręczne wartości odżywcze, dane sprzedaży. Konfekcjonowanie jest wyłącznie trybem realizacji partii w przyszłym module PRODUKCJA, nie trybem ani recepturą PRD. RAW dostarcza słowniki i źródłowe składniki. Wspólny silnik nie znosi ograniczeń aktywacji i dostępu poszczególnych modułów.

## 4. Tożsamość, właściciel, status i katalog

- Niepowtarzalne w instalacji, automatyczne, niezmienne SKU: `INT-000001…` i `PRD-000001…` na osobnych sekwencjach. Nazwę można zmieniać bez zmiany SKU; bieżące powiązania pokazują nową nazwę, snapshoty zachowują nazwę historyczną.
- Obiekt ma stałego właściciela-lokalizację, grupę z katalogu odpowiedniego modułu, atrybuty, wersje i stan archiwizacji. Grupy INT i PRD są osobne i konfigurowalne.
- RAW tworzy i administruje nim wyłącznie właściwa centrala; restauracje nie tworzą ani nie edytują lokalnych RAW. Restauracje mogą tworzyć własne INT i PRD przy aktywnym module i RBAC. Brakujący RAW zgłasza się centrali. Ta decyzja nadpisuje odmienny zapis fundamentu v0.1, ale nie zezwala w Stage 7A na zmianę działającego modułu RAW.
- Centrala udostępnia własne receptury wybranym restauracjom w trybie read-only. Restauracja nie kopiuje receptury centrali do własnego SKU i nie może jej modyfikować; może ją przeglądać i przeliczać podglądowo. Lokalnie utworzone receptury pozostają lokalne.
- Aktywna receptura jest dostępna do przyszłej produkcji wyłącznie, gdy pozwalają na to: gotowość funkcji w kodzie, aktywacja właściwego modułu w lokalizacji, członkostwo/kontekst, RBAC, własność lub udostępnienie, oraz dostępność całego łańcucha składników. Widok kalkulatora może mieć odrębne uprawnienie od edycji.
- Cofnięcie udostępnienia RAW/INT: istniejące receptury nie są usuwane; produkcja zależna jest blokowana. Przywrócenie udostępnienia usuwa tę konkretną blokadę automatycznie. To samo zachowanie dotyczy archiwizacji INT i przywracania z archiwum.
- Archiwizacja INT/PRD nie usuwa wpisu, wersji, powiązań ani historii partii. Archiwalny INT blokuje nowe partie receptur zależnych; przywrócenie przywraca dostępność przy spełnieniu innych warunków.
- RAW, INT i PRD są aktywowane niezależnie: dostęp do funkcji PRD nie wymaga lokalnego włączenia interfejsów RAW i INT. Do rozwiązywania danych udostępnionych zależności stosować kontrolowane wewnętrzne odczyty usług, bez ujawnienia obcych danych i bez obchodzenia `NOT_READY` / `DISABLED` dla bezpośrednich operacji tych modułów.
- Selektywna aktywacja RAW, INT i PRD dla lokalizacji należy **wyłącznie do ADMINISTRATORA** według Stage 1E. Centrala może zarządzać udostępnianiem treści, ale nie zastępuje administratora przy włączaniu modułu.

### 4.1 Interfejs listy INT i PRD

W obu modułach osobne tabele i osobne konfiguracje użytkownika. Kierunek: rozwinięcie funkcjonalności listy KC 1.x (nie kopiowanie jej stylu). Widoczne: nazwa, SKU, grupa, status gotowości, jednostka wyjściowa, warunki przechowywania, termin, data zmian, akcje oraz pochodzenie (`Własne`, `Centrali`). Liczniki: wszystkie, gotowe, wymagające uzupełnienia. Filtry i wyszukiwanie SKU/nazwy/grupy/przechowywania/gotowości/pochodzenia, sortowanie, paginacja, wybór widocznych kolumn. Stan filtrów, sortowania, kolumn i rozmiaru strony zapamiętywany **per użytkownik i per moduł**. Kosztów nie wyświetlać bez uprawnień.

## 5. Edycja, wersje robocze i automatyczna gotowość

1. Nowa receptura może być niekompletna i zapisana jako **szkic**; brak wymaganych danych nie powoduje utraty pracy.
2. Podczas edycji działa automatyczny zapis **zmian roboczych**. Przycisk `Zapisz` utrwala nową wersję. Jeśli jest kompletna dla danego zastosowania, staje się aktywna bez odrębnego zatwierdzenia lub ceremonii publikacji. Jeśli nie jest kompletna, pozostaje szkicem.
3. Gdy edytowana jest receptura już aktywna, dotychczasowa kompletna wersja służy do produkcji do chwili zastąpienia jej kompletną nową wersją. Niekompletny szkic nie unieważnia aktywnej wersji.
4. Przy każdej aktywacji kompletnej zmiany system przechowuje poprzednią wersję i metadane zmiany. Historia i porównanie dostępne w UI; **przywracanie starych wersji zostanie zrealizowane w osobnym module**, nie w bezpośrednim edytorze INT/PRD.
5. Chronić współbieżną edycję: wiele osób może przeglądać, ale autozapis ani `Zapisz` nie mogą cicho nadpisać nowszej rewizji. Konflikt jest widoczny, bez utraty obu zestawów zmian.
6. Błędy i braki oznaczać przy konkretnych polach; licznik braków na górze. Gotowość waliduje backend, nie tylko JS.
7. Pole technologii to **jeden elastyczny edytor tekstu** z akapitami, listami, polskimi znakami i emoji; wszystkie uwagi dotyczące użycia składników zapisujemy tutaj, nie w osobnych polach wierszy.
8. Receptura ma co najmniej jeden składnik do uzyskania gotowości. Zero składników dozwolone tylko w szkicu. **Konfekcjonowanie nie jest wyjątkiem recepturowym**, ponieważ odbywa się wyłącznie w module PRODUKCJA.
9. Zmiana INT automatycznie oddziałuje na **nowe** użycia w aktywnych recepturach zależnych; nie wymaga otwierania 20 receptur nadrzędnych ani nowych zatwierdzeń.

### 5.1 Minimalne wymagania gotowości (C-05, zatwierdzone)

W INT i PRD ręczne `Zapisz` aktywuje nową wersję tylko, gdy kompletne są: nazwa, właściwa grupa, typ produktu, minimum jeden składnik z poprawną dodatnią ilością i jednostką, poprawny uzysk z jednostką końcową, instrukcja technologiczna, zakres temperatur, forma/warunki przechowywania, okres przydatności oraz wszystkie poprawne i dostępne zależności. SKU nadaje system. Błędy przy polach i licznik na górze; szkice niekompletne są dozwolone.

- **INT:** pole `typ produktu` ma stałą wartość `WIP` (produkt w toku / półfabrykat), bez wyboru użytkownika. Grupy INT są konfigurowalne odrębnie od PRD.
- **PRD:** obowiązkowy wybór typu z zamkniętej listy `WIP`, `RTE`, `RTC`, `RTH`. Oznaczenia opisują klasyfikację wewnętrzną, nie certyfikują bezpieczeństwa żywności; nie zastępują technologii ani HACCP.
- **PRD – grupa/marka:** osobne, obowiązkowe pole przynależności do konfigurowalnej listy, przykładowo `OH MY RAMEN`, `VICE TACOS`, `INNE`. Listę definiuje ADMINISTRATOR lub administrator z dodatkowym uprawnieniem SUPER ADMIN w Panelu Administracyjnym (PA). Typ produktu i grupa to **dwa różne atrybuty**.
- **Warunki przechowywania:** użytkownik określa zakres temperatur i warunki/formę przechowywania; trwałość w dniach. Parametry mogą mieć edytowalne wartości domyślne.
- Ręcznie wpisywany skład etykietowy i wartości odżywcze PRD nie są warunkiem aktywacji zwykłej receptury; brak odpowiednich danych może uniemożliwić konkretną późniejszą operację etykietowania, jeśli wymaga ich jej odrębny kontrakt. Wymagane jest ostrzeżenie bez zablokowania niezwiązanych operacji.
- Brak ceny sprzedaży nie blokuje gotowości ani produkcji.

## 6. Składniki i graf zależności

- INT: RAW lub INT; PRD: RAW lub INT. PRD nie występuje jako składnik, również w sytuacjach legacy.
- Jeden identyfikator składnika może pojawić się w recepturze **tylko w jednym wierszu**. Jeden wiersz zawiera referencję SKU/ID, ilość dodatnią, jednostkę i pozycję; bez `note`. Kolejność ustala się przeciąganiem, zachowana na karcie produkcyjnej.
- Wyszukiwanie na żywo po nazwie, SKU, synonimach i nazwach handlowych, z pokazaniem typu i jednostki; respektuje scope i sharing. Możliwy wybór pojedynczy lub wiele elementów z katalogu. Po dodaniu zbiorczym ilości uzupełnia się w tabeli; duplikaty są pomijane/blokowane.
- Podmiana składnika w tym samym wierszu zachowuje kolejność, **czyści ilość** i ustawia odpowiednią jednostkę nowego składnika.
- Kliknięcie INT w wierszu rozwija jego skład i umożliwia nawigację do pełnej receptury. Osobny widok drzewa: wszystkie poziomy RAW/INT, a dla INT także lista receptur, które go wykorzystują. Nawigacja kontrolowana przez RBAC.
- Jeśli w edytowanej recepturze brakuje nowego INT, można otworzyć jego pełny edytor z zachowaniem niezapisanej pracy; po utworzeniu system wraca i automatycznie dodaje INT do bieżącej tabeli. Utworzenie składnika nie omija aktywacji ani RBAC.
- Zapisywanie grafu: walidacja cykli bezpośrednich i pośrednich, spójnych referencji i dostępności. Nie narzucać nieuzasadnionego limitu biznesowego liczby poziomów; techniczne zabezpieczenia głębokości i czasu obliczeń muszą być jawne.
- Na potrzeby kalkulacji aktualnej rodzic korzysta **z aktualnej kompletnej wersji INT**. W przyszłym snapshotcie partii utrwalamy dokładnie rozwiązane wersje wszystkich zależnych INT, aby późniejsza zmiana nie poprawiała historii.

## 7. Jednostki, wsad, uzysk i skalowanie

### 7.1 Jednostki i konwersje (C-06)

- Składniki mają jednostki `g`, `kg`, `ml`, `l`, `szt.`. `kg→g` i `l→ml` wykonujemy dokładnie (×1000). Wszystkie pozycje masowe i objętościowe **sumujemy do jednej umownej liczby wsadu** po normalizacji, w konwencji `1 g` i `1 ml` = jedna jednostka obliczeniowa. **Pozycje `szt.` pomijamy w tej sumie**, ale zachowujemy je w recepturze, skalowaniu i kosztach.
- Użytkownik **wybiera jednostkę finalną** `g`, `kg`, `ml`, `l` lub `szt.`. Przy automatycznym uzysku z sumy wsadu liczba jest prezentowana w wybranej jednostce masy lub objętości (np. 7000 jednostek → 7 kg albo 7 l). To **umowny model recepturowy**, nie dowód fizycznej masy, objętości ani gęstości; nie używać tej konwencji do fizycznych stanów magazynowych, etykiety netto ani źródłowych cen zakupu.
- Przykład: `5 kg cebuli + 2 l sosu + 10 szt. papryczek` → umowna suma `7000`, przy zerowej stracie automatyczny uzysk `7 kg` albo `7 l`, zależnie od wybranej jednostki końcowej. Dziesięć sztuk nie zwiększa sumy, ale jest nadal kalkulowane w kosztach i przy skalowaniu.
- Zmiana jednostki składnika wewnątrz zgodnej rodziny (`g↔kg`, `ml↔l`) zachowuje ilość fizyczną. Konwersji fizycznej `kg↔l` bez gęstości nie deklarujemy; umowny wsad jest jedynie osobnym algorytmem.
- Ilości wprowadzane i prezentowane: maksymalnie trzy miejsca dziesiętne, bez zer końcowych. Obliczenia wewnętrzne prowadzić na decimal, zaokrąglać dopiero końcową prezentację; składniki w `szt.` po skalowaniu zaokrąglać **w górę do całej sztuki**.

### 7.2 Tryby uzysku, straty i skalowania

- **Tryb domyślny:** uzysk liczony automatycznie z umownej sumy wsadu, ze stratą technologiczną **0%**. Procentową stratę liczyć raz dla całego wsadu, nie dla poszczególnych wierszy. `uzysk = wsad × (1 − strata/100)`.
- **Tryb alternatywny:** ręczny uzysk dodatni w wybranej jednostce produktu końcowego; **nie odejmować od niego ponownie straty procentowej**. UI jasno wskazuje aktywny tryb.
- **Jednostka końcowa `szt.`:** bazową liczbę gotowych sztuk użytkownik podaje **ręcznie**, nigdy nie wyprowadza jej system z sumy wsadu. `24 kg + 26 kg → 1000 szt.` to poprawne dane bazowe.
- **Skalowanie podglądowe:** (1) według żądanego uzysku końcowego, `k = żądany uzysk / uzysk bazowy`, lub (2) według docelowej ilości wybranego składnika, `k = żądana ilość składnika / jego ilość bazowa`. Pozycje mnożymy przez `k`; `szt.` zaokrąglamy w górę. Nie zmienia to receptury bazowej ani historii.
- Kalkulator dostępny dla udostępnionych receptur centrali w restauracji jest pełny, ale **tylko do podglądu**. Brak możliwości zapisania zmiany lub skopiowania receptury centrali.

### 7.3 Testy brzegowe i ograniczenia

- W recepturze z samymi pozycjami `szt.` suma wsadu wynosi zero; dla takiej receptury wymagany jest **ręczny uzysk dodatni**, bez dzielenia przez zero. To reguła techniczna wynikająca z zaakceptowanego modelu.
- Jeżeli wsad ma wartość dodatnią, ale strata wynosi 100%, uzysk automatyczny byłby zerowy; receptura nie uzyska gotowości produkcyjnej z zerowym uzyskiem. Stosować czytelny błąd pola lub ręczny dodatni uzysk.
- Wartości `szt.` pominięte w sumie wsadu **nie są darmowe**: muszą zostać wycenione jako składniki, a po skalowaniu koszt planowany uwzględnia zaokrąglone ilości sztuk.
- Zachować oddzielenie liczby umownej od ilości fizycznej dla integracji z RAW/magazynem/etykietami.

## 8. Foodcost

- Dynamiczny koszt każdej pozycji, całego bazowego wsadu i jednostki wyjściowej, aktualizowany na bieżąco także po zmianach RAW i INT w wielu poziomach grafu. Ceny i koszty historycznych partii pozostają zgodne ze snapshotem partii.
- Dla wyjścia kg/l: koszt za 1 kg / 1 l oraz za 100 g / 100 ml odpowiednio. Dla `szt.` wyłącznie koszt za 1 szt., bez kosztu „100 g”.
- Kwoty pochodzą z właściwego kontekstu kosztowego: **udostępniona restauracji receptura centrali pokazuje koszt wyliczony wg cen centrali**, nie według lokalnych cen zakupu restauracji. Nie tworzymy w ramach Stage 7A niezależnego kosztu tej samej udostępnionej receptury w każdej restauracji.
- **C-08: obowiązuje bez zmian istniejąca macierz Stage 1D.** `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` może nadawać i odbierać ADMINISTRATOR **lub SZEF KUCHNI WŁAŚCIWEJ CENTRALI**, wyłącznie w przysługującym mu zakresie. ADMINISTRATOR z dodatkowym uprawnieniem SUPER ADMIN korzysta z uprawnień administratora. Wcześniejsze zdanie sugerujące odebranie tej kompetencji szefowi centrali jest wycofane.
- Foodcost i ceny poszczególnych RAW to **osobne zakresy ujawnienia**. Żaden widok, podgląd zagnieżdżonego INT, wyszukiwanie, API, PDF ani eksport nie może ujawniać kosztów nieuprawnionym. Obowiązujące `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` z Stage 1D stanowią podstawę; rozszerzenia konfiguracji widoczności nie mogą omijać ich nadawania.
- PDF kalkulacyjny może wygenerować wyłącznie ADMINISTRATOR (w tym administrator z flagą SUPER ADMIN). Dodatkowe uprawnienie SUPER ADMIN nie jest siódmą rolą. To **odrębna reguła generowania PDF**, nie zmiana uprawnień do nadawania `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW`.
- Koszt opakowań oraz robocizny konfekcjonowania jest **poza foodcostem receptury INT/PRD**. Tryb konfekcjonowania jest częścią przyszłej PRODUKCJI, nie PRD.
- Technicznie wymagana jest walidacja zgodności jednostek kosztu i ilości oraz brak cykli; w KC 1.x brak porównania niektórych jednostek pozwalał na błędne wyceny. Cache, jeśli zostanie użyty, ma rozpoznawać zmiany źródeł i nigdy nie pokazywać starej liczby jako aktualnej po błędzie przeliczenia.

## 9. Alergeny, etykieta, wartości odżywcze

### 9.1 Alergeny

Źródłem alergenów są **wyłącznie dane RAW (bezpośrednie i potencjalne) oraz jedna wspólna firmowa lista stałych alergenów zakładowych**. Nie wpisujemy ich ręcznie w INT i PRD jako drugiego źródła prawdy. Resolver przechodzi rekurencyjnie przez wszystkie INT do RAW.

```text
ALERGENY_BEZPOŚREDNIE = suma zbiorów alergenów bezpośrednich osiągalnych RAW
MOŻE_ZAWIERAĆ = (suma alergenów potencjalnych RAW + lista zakładowa)
               − ALERGENY_BEZPOŚREDNIE
```

Deduplikacja także między poziomami INT; aktualizacja RAW/INT odświeża bieżące deklaracje. Do partii później zapisać stan historyczny alergenów. Wspólna lista dla firmy to decyzja właściciela; jej poprawność operacyjna pozostaje odpowiedzialnością właściciela procesu.

### 9.2 Dane ręczne PRD

- **Skład etykietowy:** ręcznie wprowadzany tekst bez automatycznego rozwijania RAW/INT do deklaracji etykietowej.
- **Wartości odżywcze:** ręczna tabela na 100 g lub 100 ml; co najmniej energia, tłuszcz, nasycone kwasy tłuszczowe, węglowodany, cukry, białko, sól (dokładny zestaw pól i jednostek uwzględnić przy makiecie). Nie obliczać jej automatycznie z RAW.
- Zmiana składu PRD lub używanego INT **nie nadpisuje** tych ręcznych pól. Pokazać **dyskretne, nieblokujące powiadomienie** o potencjalnej potrzebie sprawdzenia składu etykietowego i wartości odżywczych.
- INT: brak wymagań etykiety produktu i próbki kontrolnej w formularzu INT. Nie przenosić pól PRD tylko dlatego, że wspólny silnik obsługuje alergeny.
- PRD: dwa niezależne, domyślnie zaznaczone przełączniki: **etykieta produktu gotowego** oraz **etykieta próbki kontrolnej**. Dopuszczone każda kombinacja (oba/tylko jeden/żaden); ustawienia przekazywane do przyszłej partii.

## 10. Przechowywanie, termin przydatności i pozostałe pola

- INT i PRD: zakres temperatur, forma przechowywania, trwałość w dniach oraz opcjonalne uwagi. Parametry są edytowalne w recepturze.
- Przy utworzeniu partii data przydatności wylicza się automatycznie z daty produkcji i okresu receptury; **uprawniony użytkownik może dokonać korekty dla konkretnej partii**. Wymóg właściwej obsługi kalendarza i strefy czasowej przenosi się do kontraktu produkcji.
- Grupy receptur są oddzielne dla INT i PRD. Domyślne wartości pól są konfigurowane centralnie, niezależnie per moduł, przez ADMINISTRATORA (w tym administratora z dodatkowym uprawnieniem SUPER ADMIN); w konkretnej recepturze uprawniony technolog może je edytować.
- Brak zdjęć, galerii i stanowisk produkcyjnych w INT/PRD.
- PRD: opcjonalna cena sprzedaży netto i VAT; system może obliczać cenę brutto i procent foodcostu względem ceny sprzedaży, tylko dla uprawnionych. Brak tych danych nie blokuje produkcji.
- Duplikowanie własnej receptury INT/PRD tworzy nowy szkic i **nowe SKU**, zachowuje do edycji skład, ilości, parametry i technologię; **nie dotyczy udostępnionych receptur centrali**, których restauracja kopiować nie może.

## 11. Konfekcjonowanie — kontrakt przyszłego modułu PRODUKCJA (C-07)

**Konfekcjonowanie nie należy do modułu PRD ani INT.** To jeden z dwóch obowiązkowo wybieranych **trybów realizacji partii** na początku procesu w module PRODUKCJA:

1. `PRODUKCJA TECHNOLOGICZNA` — wytwarzanie produktu zgodnie z recepturą i przypisanymi składnikami.
2. `KONFEKCJONOWANIE` — rozdzielanie, porcjowanie, przepakowywanie lub pakowanie istniejącego produktu źródłowego, bez odtwarzania jego receptury.

W trybie KONFEKCJONOWANIE źródłem może być **dowolna uprawniona i dostępna pozycja RAW, INT lub PRD**. Wybór obejmuje wszystkie odpowiednie katalogi/grupy, ale tylko obiekty udostępnione lokalizacji i widoczne zgodnie z RBAC. Dostęp do źródła nie wymaga aktywacji UI modułów RAW/INT/PRD, o ile kontrakt wewnętrznego odczytu pozwala na legalny dostęp; nie przyznaje to uprawnienia ich edycji.

- Operacja **nie tworzy nowej receptury INT/PRD ani nowego SKU** z samego faktu konfekcjonowania.
- Źródłowy PRD jako materiał konfekcjonowany **nie staje się składnikiem receptury PRD**; należy użyć osobnej relacji partia-źródło → operacja/partia konfekcjonowania.
- Użytkownik określa liczbę i rozmiar opakowań podczas tworzenia partii. Uprawnione korekty w dozwolonym stanie partii określi późniejszy kontrakt modułu PRODUKCJA.
- Koszt źródła pozostaje kosztem źródła. Koszty materiałów opakowaniowych i pracy są rozliczane osobno i **nie zwiększają foodcostu źródłowej receptury**.
- Zachować identyfikowalność partii źródłowej, pobranych ilości, wersji danych, uzyskanych jednostek i historycznego snapshotu. Szczegóły fizycznych stanów, rozchodów i kosztów operacji należą do późniejszego projektu PRODUKCJI i MAGAZYNU.

## 12. Snapshoty, PDF i przyszła produkcja

- W chwili tworzenia nowej partii przyszły moduł Produkcja powinien zapisać snapshot: identyfikacja i SKU/wersja/nazwa PRD lub INT, składniki i ich kolejność, ilości bazowe i przeliczone, jednostki, wykorzystane wersje wszystkich zależnych INT, parametry wydajności i strat, tekst technologii, przechowywanie i termin, alergeny, wartości kosztowe i uprawnione dane etykietowe, ustawienia etykiet/próbki PRD oraz metadane kontekstu.
- Snapshot i historyczna karta produkcyjna oraz etykieta **nie mogą** uzupełniać braków aktualnymi danymi produktu w sposób cicho zmieniający historię. Niekompletne starsze snapshoty należy jawnie oznaczać jako niekompletne; strategia migracji osobno.
- Technologia drukowana w całości na karcie produkcyjnej, nawet przy wielu stronach. Tabela składników i skalowanych ilości ma zachowywać ustaloną kolejność.
- Eksport bezpośrednio z INT i PRD: **tylko PDF**. Domyślnie zaznaczony jest technologiczny PDF (składniki, uzysk, pełna technologia, warunki przechowywania). Opcjonalny PDF kalkulacyjny zawiera koszty i jest dostępny wyłącznie dla ADMINISTRATORA, w tym z SUPER ADMIN; nie wystarczy samo `FOODCOST_VIEW` ani uprawnienie szefa centrali do delegowania wglądu. Nie dodajemy eksportu Excel z modułów.
- Decyzje o dokładnej budowie i drukowaniu partii mają być respektowane przez przyszłą specyfikację Produkcji. Sam Stage 7A ich nie implementuje.

## 13. Koncepcyjny model danych (NIE migracja SQL)

| Encja / kontrakt | Cel i najważniejsze atrybuty |
|---|---|
| `catalog_items` / odpowiednik | stabilny ID, SKU, typ RAW/INT/PRD, nazwa, właściciel, archiwizacja; RAW zachowuje własny kontrakt |
| `recipe_headers` | ID obiektu INT/PRD, odrębne typ produktu i grupa, przechowywanie i temperatura, jednostka wyjściowa, tryb uzysku i strata |
| `recipe_drafts` | stan edycji roboczej, autor, rewizja do kontroli konfliktów, autozapis, wskazanie aktywnej wersji |
| `recipe_versions` | niezmienny identyfikator wersji, czas aktywacji, autor, pełne dane wersji, powiązania logiczne do INT |
| `recipe_version_items` | wersja rodzica, typ i ID komponentu RAW/INT, ilość, jednostka, kolejność; unikalność komponentu w wersji |
| `recipe_groups` | odrębne grupy INT i PRD, w tym obowiązkowa przynależność PRD/marka z listy PA |
| `catalog_shares` | udostępnienia centrala→restauracja, rodzaj obiektu, kontekst i stan, bez kopiowania |
| `facility_allergens` | jedna lista zakładowa na instalację/organizację wg decyzji biznesowej |
| `recipe_prd_details` | ręczny skład etykietowy i wartości odżywcze, opcje etykiet/próbki, opcjonalna cena/VAT |
| `module_defaults` | wartości domyślne INT/PRD; konfiguracja PA przez ADMINISTRATORA, w tym z flagą SUPER ADMIN |
| `batch_recipe_snapshots` (przyszły moduł) | niezmienny kontekst wykonania, wybrany tryb partii, źródło RAW/INT/PRD, rozwinięte wersje, ilości, koszty, alergeny i wydruki |

Nie narzucamy tych nazw jako istniejących tabel. Finalny model musi być zgodny z aktualnym PostgreSQL, architekturą usług i istniejącym katalogiem RAW. Wydzielona wersja jest kluczem dla niewzruszalnej historii, a bieżący resolver wskazuje aktywne wersje zależności. Unikalność SKU i komponentów egzekwowana w bazie **oraz** w backendzie.

## 14. Zasady implementacyjne i testy kontraktowe

### 14.1 Wspólny silnik

- Serwerowe kalkulacje i walidacja; frontend zapewnia natychmiastowy podgląd, ale nie jest źródłem prawdy.
- Decimal/fixed-point dla ilości i kosztów zamiast niekontrolowanego float; jawne reguły zaokrągleń.
- Ochrona cykli i odporność na zmiany grafu w transakcjach; unikalne wiersze składników.
- Gotowość jako obliczany stan zależny od konkretnej operacji, a nie „ręcznie zatwierdzona pieczątka”.
- Brak automatycznego aktywowania modułów w lokalizacjach; fail-closed przy `NOT_READY`, `DISABLED`, braku uprawnień i cofnięciu shares.
- Dynamiczny foodcost i alergeny bez upublicznienia danych finansowych w odpowiedziach API, cache lub PDF.

### 14.2 Minimalne scenariusze odbioru

1. Tworzenie szkicu z pustymi polami, autozapis, brak aktywacji do czasu kompletności.
2. Aktywna v1 i szkic v2: nowe partie używają v1 do zapisania kompletnej v2; historia partii ze starą nazwą/składem pozostaje bez zmian.
3. INT-A z RAW, INT-B z INT-A, PRD z INT-B: zmiana INT-A aktualizuje przyszłe kalkulacje PRD i alergeny, nie historyczny snapshot.
4. Cykl INT-A→INT-B→INT-A odrzucony; powtórny identyczny składnik w recepturze odrzucony; PRD nie może być składnikiem.
5. 24 kg + 26 kg → 1000 szt.; kalkulator 2500 szt. → 60 kg + 65 kg; sztuki jako składnik zaokrąglane w górę.
6. 10 kg + 2000 ml, strata 15% → podgląd zgodny z konwencją wsadu, bez udawania fizycznej gęstości; odrębny test jednostek `szt.`.
7. Zmiana `1500 g` na `1,5 kg` nie zmienia kosztu ani fizycznej ilości; stare snapshoty nietknięte.
8. Ten sam SKU INT zmienia skład; dane etykietowe i odżywcze PRD pozostają, wyświetla się dyskretne ostrzeżenie.
9. Cofnięcie udziału RAW/INT i archiwizacja INT blokują nową produkcję zależną; ponowne udostępnienie/przywrócenie znosi blokadę.
10. Restauracja czyta i skaluje recepturę centrali; nie edytuje, nie kopiuje do własnego SKU i nie widzi niedozwolonych cen.
11. Osobne `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` chronią kolumny, API i PDF, także pośrednie INT; zakaz eksportu PDF kalkulacyjnego dla nieuprawnionych.
12. `READY` w kodzie + `ENABLED` lokalnie + RBAC + shares są konieczne; brak któregokolwiek odmawia dostępu. RAW globalnie `NOT_READY` nie jest obchodzone testowym skrótem.
13. Test współbieżnego autozapisu dwóch osób pokazuje konflikt bez utraty nowszych danych.
14. PDF technologiczny zawiera wszystkie składniki i technologię; PDF kalkulacyjny dostępny według właściwej polityki.
15. Konfekcjonowanie w module PRODUKCJA: obowiązkowy wybór trybu partii, źródło RAW/INT/PRD, właściwe RBAC, wielkość/liczba opakowań przy partii; osobny koszt opakowań i pracy.
16. C-06: `5 kg + 2 l + 10 szt.` → suma wsadu `7000`, przy końcowym `kg` uzysk 7 kg, przy `l` 7 l; 10 szt. pozostaje w kosztach.
17. C-06: surowce tylko w sztukach → uzysk ręczny, zero dzielenia przez zero; strata 100% nie tworzy gotowej receptury z zerowym uzyskiem.
18. C-08: szef właściwej centrali nadal nadaje/odbiera `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` we własnym scope, lecz nie może generować PDF kalkulacyjnego, jeśli nie jest ADMINISTRATOREM.

Każdy etap wdrożeniowy powinien otrzymać odrębny, krótki prompt dla Copilota z zakresem plików, testami, zakazem działań poza zakresem oraz raportem. **Żadnej implementacji na podstawie samego tego dokumentu.**

## 15. Zalecana kolejność realizacji po osobnej akceptacji

| Faza robocza | Przedmiot | Warunek przejścia |
|---|---|---|
| 7A — architektura | Niniejszy kontrakt i przegląd konfliktów | Jawna akceptacja właściciela i aktualizacja kontraktów kolidujących |
| Fundament receptur | Model wersji, graf, kalkulator, alergeny, foodcost, ACL | Testy usług w izolacji, bez aktywacji biznesowej |
| INT | Pełny moduł półproduktów, edytor, katalog, wersje, PDF | Odbiór INT i integracji z dostępnym RAW |
| PRD | Pełny moduł wyrobów, klasyfikacja WIP/RTE/RTC/RTH, dane ręczne, przełączniki etykiet, PDF; **bez konfekcjonowania** | Odbiór PRD i integracji INT/RAW |
| Integracje | Kontrakty PRODUKCJI, wyboru trybu partii, konfekcjonowania RAW/INT/PRD, snapshotów i późniejszych modułów | Odmowy przy brakach uprawnień i niezmienna historia |

Fazy robocze **nie są nową oficjalną numeracją Stage 7B/7C/7D/7E**; konkretna numeracja zostanie nadana w harmonogramie. Nie uruchamiać RAW ponownie ani nie aktywować INT/PRD przed spełnieniem kontraktów Stage 1E.

## 16. Rejestr rozstrzygnięć C-01–C-10 i zaległych prac integracyjnych (stan 2026-11-14)

**Uwaga dotycząca numeracji:** w rozmowie pytania 6–8/10 mylnie używały C-06 także dla klasyfikacji typu produktu. **C-06 w rejestrze od początku oznacza algorytm uzysku/strat.** Typy WIP/RTE/RTC/RTH zapisano w C-05. Podobnie pytanie 10/10 o koszt konfekcjonowania jest **uzupełnieniem C-07**, nie zastępuje oryginalnego C-08.

| ID | Decyzja biznesowa | Stan wdrożeniowo-kontraktowy |
|---|---|---|
| C-01 | 6 ról RBAC pozostaje. SUPER ADMIN = dodatkowe uprawnienie przypisywane wybranym ADMINISTRATOROM, nie siódma rola. | **Ustalone**; osobno dopisać flagę/uprawnienie do kontraktu RBAC bez bypassów bezpieczeństwa. |
| C-02 | RAW tworzy/edytuje tylko centrala; restauracje korzystają z udostępnionych RAW; mogą tworzyć własne INT/PRD. | **Ustalone**; starszy fundament v0.1 należy formalnie skorygować; bez zmian implementacji RAW w Stage 7A. |
| C-03 | Autosave szkicu; ręczny `Zapisz` aktywuje kompletną nową wersję. Poprzednia kompletna pozostaje aktywna do tej chwili. Bez osobnej publikacji/akceptacji. | **Ustalone**; zmienić stary opis publikacji w fundamencie v0.1, zachować historię i snapshoty. |
| C-04 | INT/PRD/RAW jako odrębne moduły, niezależna lokalna aktywacja UI. PRD może konsumować udostępnione dane RAW/INT bez włączania ich UI. | **Ustalone**; jawny kontrakt odczytu usług i polityka odmów zgodna ze Stage 1E. |
| C-05 | Kompletny zestaw pól technologicznych i magazynowych obowiązkowy; INT typ WIP na stałe, PRD wybór WIP/RTE/RTC/RTH; osobna obowiązkowa grupa PRD z konfiguracji PA. | **Ustalone**; walidacje implementować dla INT/PRD. Operacyjne reguły etykietowania do późniejszego kontraktu. |
| C-06 | Wszystkie pozycje g/kg/ml/l sumują się umownie po normalizacji, `szt.` są pomijane z sumy. Jednostkę finalną wybiera użytkownik. Strata domyślnie 0%; alternatywnie ręczny uzysk. `szt.` na wyjściu tylko z ręcznego uzysku. | **Ustalone**; dopracować testy brzegowe, precyzję i rozdział obliczeń od fizycznego magazynu. |
| C-07 | KONFEKCJONOWANIE to tryb **PRODUKCJI**, wybierany obowiązkowo obok PRODUKCJI TECHNOLOGICZNEJ. Źródło może być RAW, INT albo PRD; nowe SKU/receptura nie powstają. Koszty źródła bez opakowań i pracy; te rozliczać osobno. | **Ustalone dla granicy modułów**; implementacja, traceability, rozchody i korekty należą do przyszłej PRODUKCJI/MAGAZYNU. |
| C-08 | **Bez zmiany Stage 1D**: `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` nadaje/odbiera ADMINISTRATOR lub szef właściwej centrali w dozwolonym zakresie. Widoczność obu zakresów osobna; PDF kalkulacyjny odrębnie tylko ADMINISTRATOR. | **Ustalone**; nie odbierać kompetencji szefowi centrali, nie dodawać ról. |
| C-09 | Alergeny z RAW + wspólna lista zakładowa; produkcja zamraża kompletny snapshot przy partii; historyczny wydruk wyłącznie ze snapshotu. | **Ustalone biznesowo**; szczegóły formatu, niezmienności, korekt i etykiet przenieść do kontraktu modułu PRODUKCJA/ETYKIETY. |
| C-10 | Eksport tylko PDF; domyślnie technologiczny; kalkulacyjny opcjonalnie tylko ADMINISTRATOR (w tym z flagą SUPER ADMIN). | **Ustalone biznesowo**; layout i filtrowanie danych po stronie backendu do późniejszych testów i projektu UI. |

### 16.1 Co pozostaje zadaniem technicznym, a nie nowym pytaniem biznesowym

1. Odrębnie od implementacji INT/PRD zaktualizować kontrakty Stage 1D/fundament v0.1 tylko w zatwierdzonym zakresie C-01/C-02/C-03; nie wprowadzać zmian w działających modułach bez zlecenia.
2. Zweryfikować kontrakty Stage 1E dla niezależnej aktywacji UI i wewnętrznego odczytu składników (§4).
3. Dla produkcji ustalić schemat snapshotu, identyfikowalność źródła konfekcjonowania, prawidłowe stany partii, korekty, etykiety i osobne koszty opakowań/pracy; nie dorabiać tego do PRD.
4. Techniczna implementacja PDF musi bronić dostępu na backendzie, także przez bezpośrednie URL, API, cache i wydruk historyczny.
5. Zweryfikować z aktualnym repozytorium rzeczywisty status implementacji Stage 1D/1E/RAW. Dokumentacja jest kontraktem, **nie dowodem działania kodu**.

**Status rejestru:** wszystkie 10 zagadnień mają odpowiedź biznesową. **Nie jest to równoznaczne z zakończoną harmonizacją kontraktów, walidacją repozytorium ani odbiorem implementacji.**

## 17. Rejestr decyzji użytkownika — pytania 1–58

Zapis skrócony stanowi indeks wymagań. Gdy decyzja koliduje z wcześniejszym kontraktem, obowiązuje oznaczenie konfliktu z §16, a nie ciche pierwszeństwo jednej strony.

| Nr | Zatwierdzone rozstrzygnięcie |
|---:|---|
| 1 | Szkic niekompletnej receptury; kompletność daje gotowość bez dodatkowego zatwierdzenia. |
| 2 | Wymagania kompletności zależą od zastosowania. |
| 3 | Ostatnia kompletna wersja działa do automatycznego zastąpienia kompletną nową. |
| 4 | INT i PRD: temperatura/zakres, forma przechowywania, trwałość, uwagi. |
| 5 | Termin z daty produkcji i trwałości; uprawniona korekta per partia. |
| 6 | Tworzenie INT z edytora innej receptury bez utraty pracy i automatyczne dodanie. |
| 7 | Automatyczna propagacja aktualnego foodcostu RAW→INT→PRD; historia partii niezmienna. |
| 8 | Jedna wspólna lista alergenów zakładowych dla firmy. |
| 9 | Cofnięcie RAW/INT zachowuje receptury, blokuje nowe partie zależne, odblokowuje po przywróceniu. |
| 10 | Wynik w sztukach: uzysk bazowy wpisany ręcznie; skalowanie proporcjonalne. |
| 11 | Osobne konfigurowalne grupy INT i PRD. |
| 12 | Wyszukiwanie składników: nazwa, SKU, synonimy, nazwy handlowe, na żywo. |
| 13 | Wartości odżywcze PRD ręcznie na 100 g / 100 ml. |
| 14 | INT bez wymagań etykiet i próbek. |
| 15 | PRD: dwie etykiety (produkt/próbka) domyślnie włączone, niezależne przełączniki. |
| 16 | Globalnie unikalne, niezmienne, automatyczne SKU, osobne sekwencje INT i PRD. |
| 17 | Centrala udostępnia wybranym restauracjom; lokalne receptury pozostają lokalne. |
| 18 | Archiwizacja zamiast kasowania; historia i przywracanie. |
| 19 | Archiwizacja używanego INT blokuje nowe partie zależne do przywrócenia. |
| 20 | Uwagi o składnikach wyłącznie w opisie technologii, nie w wierszach. |
| 21 | Kolejność składników przeciąganiem, zachowana na karcie produkcyjnej. |
| 22 | Duplikowanie całej **własnej** receptury do nowego SKU/szkicu; wyjątek: decyzja 55 dla centrali. |
| 23 | Rozwijane drzewo RAW/INT i odwrotna lista zastosowań INT. |
| 24 | Kalkulator na żywo, podgląd skalowania po uzysku albo ilości jednego składnika. |
| 25 | Foodcost na żywo: pozycja/wsad/jednostka; kg/l również 100 g/100 ml; szt. tylko 1 szt.; RBAC. |
| 26 | Ilości do 3 miejsc dziesiętnych, bez zbędnych zer. |
| 27 | Pełna precyzja obliczeń, standardowe zaokrąglenie prezentacji do 3 miejsc. |
| 28 | Składniki w sztukach zaokrąglane w górę do pełnych sztuk. |
| 29 | Reguły nazw handlowych/synonimów RAW już były ustalone; bez nowej zmiany RAW. |
| 30 | Historia i porównanie wersji; przywracanie w osobnym module. |
| 31 | g/kg, ml/l, szt. i automatyczna konwersja zgodnych jednostek. |
| 32 | Rozwinięta tabela KC 1.x, ale **osobno** moduły INT i PRD. |
| 33 | Zapamiętanie filtrów/sortowania/kolumn/paginacji per użytkownik i moduł. |
| 34 | Jedna tabela w module z filtrem własne/centrala; centrala tylko do odczytu. |
| 35 | Zmiana INT aktualizuje PRD; dyskretne nieblokujące ostrzeżenie o etykiecie i wartościach. |
| 36 | Zmiana jednostki katalogowej w rodzinie automatycznie przelicza receptury bez zmiany ilości fizycznej. |
| 37 | Opcjonalna cena netto i VAT PRD; brutto i % foodcost; bez blokady produkcji. |
| 38 | Zmiana składników PRD zachowuje dane odżywcze i pokazuje dyskretne ostrzeżenie. |
| 39 | Autozapis szkicu; ręczny `Zapisz` aktywuje kompletną wersję. |
| 40 | Konflikty równoczesnej edycji wykrywane, bez cichego nadpisania. |
| 41 | Domyślne wartości pól zamiast osobnych szablonów technologicznych. |
| 42 | Dodawanie składników pojedynczo lub wybór wielu w katalogu. |
| 43 | Wybór wielu dodaje wiersze; ilości wpisuje się potem w tabeli. |
| 44 | Podmiana składnika w wierszu zachowuje kolejność, czyści ilość. |
| 45 | INT rozwijany w tabeli i link do pełnej receptury. |
| 46 | Domyślne wartości konfiguruje ADMINISTRATOR, w tym z dodatkowym uprawnieniem SUPER ADMIN; brak nowej roli. |
| 47 | **Skorygowane po pytaniu 58:** konfekcjonowanie wyłącznie jako tryb modułu PRODUKCJA; źródło RAW/INT/PRD. |
| 48 | Koszt opakowań poza PRD. |
| 49 | Bez zdjęć receptur INT i PRD. |
| 50 | Wielkość/liczba opakowań ustalana podczas partii PRODUKCJI, z możliwością uprawnionej korekty. |
| 51 | Bez stanowisk produkcyjnych w tym zakresie. |
| 52 | Eksport z modułów INT/PRD tylko PDF. |
| 53 | Domyślny PDF technologiczny; kalkulacyjny opcjonalny tylko ADMINISTRATOR, także z uprawnieniem SUPER ADMIN. |
| 54 | Udostępniona receptura korzysta z kosztu centrali; widoczność foodcostu i cen osobno, nadawanie uprawnień według niezmienionego Stage 1D (także szef właściwej centrali). |
| 55 | Restauracja nie może kopiować receptury centrali, może tylko korzystać i przeliczać. |
| 56 | Pełny kalkulator receptury centrali w restauracji, wyłącznie podgląd bez zapisu. |
| 57 | Problemy przy polach i licznik braków u góry. |
| 58 | Zmiana nazwy aktualizuje bieżące powiązania; SKU i historyczne snapshoty niezmienne. |

## 18. Wykaz materiałów źródłowych

- `VELTRA_STAGE_7A_INT_PRD_ARCHITECTURE_V1.md` — dokument bazowy, zastępowany w treści przez niniejszą v1.1.
- `VELTRA 2.0 — ADR-001 — Model wdrożenia klienta` (źródło projektu).
- `VELTRA 2.0 - Stage 1D - zatwierdzona specyfikacja RBAC v1 - 2026-11-02`.
- `VELTRA 2.0 - Stage 1E - specyfikacja architektury modulow v1`.
- `VELTRA 2.0 - Fundament architektury i modelu danych - projekt v0.1`.
- `VELTRA 2.0 — VSEF — Standard bezpiecznego wytwarzania (propozycja v1)` — źródło standardów wytwarzania, bez automatycznego awansu propozycji do zatwierdzonej polityki.
- `VELTRA_SOURCE_01_ARCHITECTURE.md` … `VELTRA_SOURCE_09_EXECUTIVE_SNAPSHOT.md` — dokumentacja referencyjna KC 1.x.
- `VELTRA_SOURCE_11_INT_PRD_IMPLEMENTATION_AUDIT.md` — audyt repozytorium KC Technologia 1.44.0; lokalizacja w Google Drive: https://drive.google.com/file/d/1h6iShc9KyEbnGhwXRHSkqsk5g2h96ocz/view?usp=drivesdk.
- Rozmowa projektowa Stage 7A, decyzje właściciela z pytań **1–58**, decyzje i korekty **C-01–C-10 (2026-11-14)** oraz wcześniejsze potwierdzenia dotyczące RAW/INT/PRD, wsadu, strat, alergenów i snapshotów.

---

**Status końcowy:** skonsolidowana **wersja robocza v1.1** po rozstrzygnięciu C-01–C-10. Wymaga akceptacji właściciela, harmonizacji wcześniejszych kontraktów i sprawdzenia repozytorium; nie jest dowodem wdrożenia ani upoważnieniem do prac w repozytorium. Po akceptacji przygotować odrębne, etapowe zlecenia Copilota — najpierw kontrakt wspólnego silnika, następnie INT, a później PRD.
