# VELTRA 2.0 — Stage 7A — Architektura modułów INT i PRD — v1

**Data opracowania:** 2026-11-13  
**Status:** PROJEKT SPECYFIKACJI DO PRZEGLĄDU WŁAŚCICIELA (nie jest zgodą na implementację)  
**Zakres:** wspólna domena receptur, moduł INT, moduł PRD i ich integracja z RAW, RBAC, lokalizacjami, przyszłą produkcją i historią.  
**Odbiorca:** właściciel projektu, architekt, GitHub Copilot Agent.  
**Zasada źródeł:** decyzje właściciela z pytań 1–58 i wcześniejszych ustaleń Stage 7A są wymaganiami biznesowymi; dokumenty Stage 1D/1E i ADR-001 są obowiązującymi kontraktami technicznymi; KC Technologia 1.x jest wyłącznie materiałem porównawczym. Konflikty wskazano jawnie w §16.

> **Ważne dla Copilota:** Ten dokument nie jest poleceniem pisania kodu. Najpierw zweryfikuj zgodność z aktualnym repozytorium VELTRA, obowiązującymi ADR i stanem środowiska. Nie modyfikuj infrastruktury, RAW ani RBAC, nie ustawiaj modułów na READY i nie generuj migracji bez odrębnego zadania i akceptacji.

## 1. Cel i granice

VELTRA 2.0 ma uprościć pracę technologa, a nie wprowadzać mnożenie zatwierdzeń. System musi obsłużyć wielopoziomową produkcję RAW → INT → INT → PRD, skalowanie, wydajność, koszt, alergeny, udostępnianie oraz wiarygodną historię partii. **RAW, INT i PRD to trzy odrębne moduły** z osobnymi ekranami, konfiguracją aktywacji i uprawnieniami; mogą używać wspólnych serwisów domenowych, ale nie zostają scalone w jeden moduł UI. MEN pozostaje poza Stage 7A.

**Nie obejmuje:** implementacji produkcji, magazynu, POS, MEN, drukarek etykiet, migracji danych KC, aktywowania RAW/INT/PRD oraz finalnego schematu SQL/API. Kontrakty integracyjne z tymi obszarami należy opisać tak, aby późniejsze wdrożenie nie wymagało zgadywania.

## 2. Hierarchia wymagań i stan początkowy

1. **ADR-001:** odrębna instalacja/baza dla klienta (single-tenant); wiele lokalizacji w obrębie instalacji.
2. **Stage 1D RBAC v1:** sześć zatwierdzonych ról, prawa zależne od kontekstu i zasięgu, osobne `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW`, backend deny-by-default. Zatwierdzona rola najwyższa: `ADMINISTRATOR`.
3. **Stage 1E:** status gotowości w kodzie, niezależne lokalne `ENABLED`, wyłącznie `ADMINISTRATOR` aktywuje/dezaktywuje moduł z kontrolą hasła; stan wyłączony blokuje operacje, nie usuwa danych; zależności modułów w kodzie nie aktywują się same.
4. **RAW:** zgodnie z checkpointem po Stage 6A globalny RAW pozostaje `NOT_READY`; odbiór RAW jest odrębną sprawą. Stage 7A nie zmienia tego stanu.
5. **Fundament v0.1:** wspólny logicznie katalog z własnością i udostępnianiem, snapshoty historii; ma jednak starsze reguły lokalnego tworzenia RAW i formalnej publikacji receptur, które wymagają jawnej aktualizacji kontraktu (§16).
6. **Audyt KC 1.44.0:** `VELTRA_SOURCE_11_INT_PRD_IMPLEMENTATION_AUDIT.md`, opis istniejącego PHP/MySQL, **nie wzór docelowego schematu**. W 1.x INT funkcjonuje jako składnik, produkt PRD ma odrębny formularz; mechanizmy obliczeń i kosztów mają ograniczenia, dokumenty historyczne mogą korzystać z bieżących pól. VELTRA nie ma kopiować tych słabości.

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

**Rozdział odpowiedzialności:** INT przechowuje pełnoprawną recepturę półproduktu; PRD przechowuje recepturę wyrobu finalnego, ręczny skład etykietowy, ręczne wartości odżywcze, dane sprzedaży i tryb konfekcjonowania. RAW dostarcza słowniki i źródłowe składniki. Wspólny silnik nie znosi ograniczeń aktywacji i dostępu poszczególnych modułów.

## 4. Tożsamość, właściciel, status i katalog

- Niepowtarzalne w instalacji, automatyczne, niezmienne SKU: `INT-000001…` i `PRD-000001…` na osobnych sekwencjach. Nazwę można zmieniać bez zmiany SKU; bieżące powiązania pokazują nową nazwę, snapshoty zachowują nazwę historyczną.
- Obiekt ma stałego właściciela-lokalizację, grupę z katalogu odpowiedniego modułu, atrybuty, wersje i stan archiwizacji. Grupy INT i PRD są osobne i konfigurowalne.
- Centrala udostępnia własne receptury wybranym restauracjom w trybie read-only. Restauracja nie kopiuje receptury centrali do własnego SKU i nie może jej modyfikować; może ją przeglądać i przeliczać podglądowo. Lokalnie utworzone receptury pozostają lokalne.
- Aktywna receptura jest dostępna do przyszłej produkcji wyłącznie, gdy pozwalają na to: gotowość funkcji w kodzie, aktywacja właściwego modułu w lokalizacji, członkostwo/kontekst, RBAC, własność lub udostępnienie, oraz dostępność całego łańcucha składników. Widok kalkulatora może mieć odrębne uprawnienie od edycji.
- Cofnięcie udostępnienia RAW/INT: istniejące receptury nie są usuwane; produkcja zależna jest blokowana. Przywrócenie udostępnienia usuwa tę konkretną blokadę automatycznie. To samo zachowanie dotyczy archiwizacji INT i przywracania z archiwum.
- Archiwizacja INT/PRD nie usuwa wpisu, wersji, powiązań ani historii partii. Archiwalny INT blokuje nowe partie receptur zależnych; przywrócenie przywraca dostępność przy spełnieniu innych warunków.
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
8. Receptura ma co najmniej jeden składnik do uzyskania gotowości. Zero składników dozwolone tylko w szkicu (z wyjątkiem specyficznego trybu konfekcjonowania, którego warunki walidacji wymagają osobnej definicji).
9. Zmiana INT automatycznie oddziałuje na **nowe** użycia w aktywnych recepturach zależnych; nie wymaga otwierania 20 receptur nadrzędnych ani nowych zatwierdzeń.

### 5.1 Minimalne wymagania gotowości (zasada)

Wymagania zależą od sposobu użycia receptury. Dla standardowej produkcji minimum to: identyfikacja i poprawna jednostka wyjścia, ≥1 poprawny składnik, dodatnie ilości, skuteczne rozwiązanie zależności, poprawny uzysk i technologia, właściwa dostępność modułów/składników. Parametry przechowywania i inne dane mogą być obowiązkowe stosownie do rodzaju produktu i czynności. **Nie wymagać automatycznie wszystkich pól PRD dla każdego przypadku**; szczegółową macierz pól per zastosowanie trzeba domknąć w review (§16). Brak ceny sprzedaży nie blokuje produkcji. Dyskretne powiadomienia o możliwie nieaktualnych danych odżywczych/etykietowych nie blokują produkcji.

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

### 7.1 Jednostki i konwersje

- W tabelach receptur dopuszczone `g`, `kg`, `ml`, `l`, `szt.`. Masa i objętość konwertowane we własnej rodzinie: `1000 g = 1 kg`, `1000 ml = 1 l`. Jednostka `szt.` pozostaje odrębna.
- **Konwencja robocza dla sumy wsadu:** przy sumowaniu pozycji masowych i objętościowych używamy operacyjnego 1:1 po normalizacji do kg/l, np. `1 kg + 500 ml → liczba wsadu 1,5`. **To nie jest fizyczna konwersja masy na objętość**, nie używać jej do wyceny jednostek, księgowania magazynu, przeliczania gęstości ani deklaracji netto na etykiecie.
- Zmiana katalogowej jednostki składnika w zgodnej rodzinie (g↔kg, ml↔l) automatycznie konwertuje użycia w aktywnych recepturach bez zmiany fizycznej ilości; historię partii pozostawia niezmienioną. **Nie zakładać konwersji kg↔l bez gęstości.**
- Wprowadzane ilości składników do 3 miejsc po przecinku (bez zbędnych zer). Obliczenia ze znaczną precyzją wewnętrzną; końcowe wielkości do pokazania/realizacji zaokrąglane standardowo do 3 miejsc. Wynik ilości w `szt.` **zawsze w górę do pełnej sztuki**. Zachować jawną separację wartości dokładnych i prezentacyjnych, by nie kumulować błędu na kolejnych poziomach.

### 7.2 Tryby uzysku i straty

- Domyślny tryb wyjścia: `Użyj wagi wsadu` — ilość wyjściowa jest na bieżąco aktualizowana wraz ze składnikami. Alternatywa: **ręczny uzysk** w wybranej jednostce końcowej. Wyjście dopuszcza kg/g/l/ml/szt. Przykład ręcznego wyjścia: 24 kg ciasta + 26 kg farszu → 1000 szt. pierogów.
- Dla wyniku `szt.` technolog **ręcznie podaje bazowy uzysk sztukowy**; nie wyliczać go automatycznie z kg bez dodatkowego parametru. Skalowanie na 2500 szt. daje 2,5× bazowych ilości.
- Strata technologiczna opcjonalna, domyślnie 0%, dotyczy **całego wsadu**, nigdy każdej pozycji oddzielnie. Przykład operacyjny: z liczby wsadu 12 i straty 15% → 10,2 jednostek wyniku wsadowego (z zachowaniem opisanej konwencji).
- Kalkulator reaguje na edycję na żywo, bez przeładowania. Użytkownik może przeliczyć całą recepturę według (a) docelowego uzysku albo (b) docelowej ilości **wybranego składnika**. Oba są trybami podglądowymi i **nie zmieniają zapisanej receptury bazowej**.
- Ogólna zależność skalowania: `k = żądana ilość wyjściowa / bazowy uzysk` albo `k = żądana ilość wskazanego składnika / ilość tego składnika w bazie`; wszystkie pozycje mnożymy przez `k`, z zachowaniem zasad jednostek i zaokrągleń.
- Kalkulator receptury centrali udostępnionej restauracji jest pełny, ale tylko do odczytu/wyliczeń; nie pozwala zapisać zmian w oryginale ani wykonać kopii do lokalnego SKU.

### 7.3 Rozgraniczenia do technicznego doprecyzowania

- Nie używać automatycznego `Użyj wagi wsadu` do wyprowadzania liczby `szt.`; wymaga ręcznego uzysku.
- Pozycje składników `szt.` nie mogą po cichu dodawać się do sumy kg/l. Dla mieszanych składników system powinien prezentować wsad ilościowy i sztukowy w sposób rozdzielony, dopóki nie ma jawnej zasady przeliczania.
- W przypadku straty + ręcznie podanego uzysku nie wolno podwójnie odjąć straty; znaczenie `bazowego uzysku` i kolejność obliczeń muszą zostać ustalone na poziomie kontraktu kalkulatora przed kodowaniem.
- Zaokrąglanie `szt.` w górę w planie nie może zmieniać historycznej receptury ani stosunku bazowego; koszty partii powinny uwzględniać faktycznie zaplanowane pełne sztuki.

## 8. Foodcost

- Dynamiczny koszt każdej pozycji, całego bazowego wsadu i jednostki wyjściowej, aktualizowany na bieżąco także po zmianach RAW i INT w wielu poziomach grafu. Ceny i koszty historycznych partii pozostają zgodne ze snapshotem partii.
- Dla wyjścia kg/l: koszt za 1 kg / 1 l oraz za 100 g / 100 ml odpowiednio. Dla `szt.` wyłącznie koszt za 1 szt., bez kosztu „100 g”.
- Kwoty pochodzą z właściwego kontekstu kosztowego: **udostępniona restauracji receptura centrali pokazuje koszt wyliczony wg cen centrali**, nie według lokalnych cen zakupu restauracji. Nie tworzymy w ramach Stage 7A niezależnego kosztu tej samej udostępnionej receptury w każdej restauracji.
- Foodcost i ceny poszczególnych RAW to **osobne zakresy ujawnienia**. Żaden widok, podgląd zagnieżdżonego INT, wyszukiwanie, API, PDF ani eksport nie może ujawniać kosztów nieuprawnionym. Obowiązujące `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW` z Stage 1D stanowią podstawę; rozszerzenia konfiguracji widoczności nie mogą omijać ich nadawania.
- PDF kalkulacyjny dostępny wyłącznie dla SUPER ADMIN/ADMINISTRATOR według decyzji biznesowej, lecz rola SUPER ADMIN wymaga formalnej harmonizacji z RBAC (§16). Do czasu harmonizacji nie przyznawać dodatkowych praw niezdefiniowanej roli.
- Koszt opakowań jest **poza PRD** i nie wchodzi do foodcostu receptury, także w trybie konfekcjonowania.
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
- Grupy receptur są oddzielne dla INT i PRD. Domyślne wartości pól są konfigurowane centralnie, niezależnie per moduł, według decyzji właściciela wyłącznie przez SUPER ADMIN/ADMINISTRATOR (konflikt RBAC §16); w konkretnej recepturze uprawniony technolog może je edytować.
- Brak zdjęć, galerii i stanowisk produkcyjnych w INT/PRD.
- PRD: opcjonalna cena sprzedaży netto i VAT; system może obliczać cenę brutto i procent foodcostu względem ceny sprzedaży, tylko dla uprawnionych. Brak tych danych nie blokuje produkcji.
- Duplikowanie własnej receptury INT/PRD tworzy nowy szkic i **nowe SKU**, zachowuje do edycji skład, ilości, parametry i technologię; **nie dotyczy udostępnionych receptur centrali**, których restauracja kopiować nie może.

## 11. PRD — osobny tryb konfekcjonowania

PRD może być produktem uzyskiwanym przez przepakowanie/porcjowanie wskazanego **produktu źródłowego** bez przepisywania receptury składnikowej. Wielkość oraz liczba opakowań ustalane **podczas tworzenia partii**, z możliwością korekty później w dozwolonym stanie i według RBAC. Nie tworzymy automatycznie osobnego SKU dla każdego rozmiaru opakowania. **Koszt opakowań poza PRD**. Konfekcjonowanie nie oznacza obowiązku dodania „opakowania” do składników spożywczych.

**Do uzgodnienia technicznie:** typ źródła konfekcjonowania (INT czy PRD), zasada pozwalająca na PRD jako źródło konfekcjonowania bez złamania zakazu PRD jako *składnika receptury*, model zużycia produktu źródłowego, zależności i zamrażanie wersji. Tego nie wolno domyślnie uznać za dozwolone `PRD → PRD` jako składnik.

## 12. Snapshoty, PDF i przyszła produkcja

- W chwili tworzenia nowej partii przyszły moduł Produkcja powinien zapisać snapshot: identyfikacja i SKU/wersja/nazwa PRD lub INT, składniki i ich kolejność, ilości bazowe i przeliczone, jednostki, wykorzystane wersje wszystkich zależnych INT, parametry wydajności i strat, tekst technologii, przechowywanie i termin, alergeny, wartości kosztowe i uprawnione dane etykietowe, ustawienia etykiet/próbki PRD oraz metadane kontekstu.
- Snapshot i historyczna karta produkcyjna oraz etykieta **nie mogą** uzupełniać braków aktualnymi danymi produktu w sposób cicho zmieniający historię. Niekompletne starsze snapshoty należy jawnie oznaczać jako niekompletne; strategia migracji osobno.
- Technologia drukowana w całości na karcie produkcyjnej, nawet przy wielu stronach. Tabela składników i skalowanych ilości ma zachowywać ustaloną kolejność.
- Eksport bezpośrednio z INT i PRD: **tylko PDF**. Domyślny PDF technologiczny; drugi, opcjonalny PDF kalkulacyjny tylko dla wymienionych ról po rozstrzygnięciu RBAC. Nie dodajemy eksportu Excel z modułów.
- Decyzje o dokładnej budowie i drukowaniu partii mają być respektowane przez przyszłą specyfikację Produkcji. Sam Stage 7A ich nie implementuje.

## 13. Koncepcyjny model danych (NIE migracja SQL)

| Encja / kontrakt | Cel i najważniejsze atrybuty |
|---|---|
| `catalog_items` / odpowiednik | stabilny ID, SKU, typ RAW/INT/PRD, nazwa, właściciel, archiwizacja; RAW zachowuje własny kontrakt |
| `recipe_headers` | ID obiektu INT/PRD, grupa, parametry przechowywania, jednostka wyjściowa, tryb uzysku, strata, specjalny tryb PRD |
| `recipe_drafts` | stan edycji roboczej, autor, rewizja do kontroli konfliktów, autozapis, wskazanie aktywnej wersji |
| `recipe_versions` | niezmienny identyfikator wersji, czas aktywacji, autor, pełne dane wersji, powiązania logiczne do INT |
| `recipe_version_items` | wersja rodzica, typ i ID komponentu RAW/INT, ilość, jednostka, kolejność; unikalność komponentu w wersji |
| `recipe_groups` | grupy per INT/PRD i ich właściciel/zakres |
| `catalog_shares` | udostępnienia centrala→restauracja, rodzaj obiektu, kontekst i stan, bez kopiowania |
| `facility_allergens` | jedna lista zakładowa na instalację/organizację wg decyzji biznesowej |
| `recipe_prd_details` | ręczny skład etykietowy i wartości odżywcze, opcje etykiet/próbki, opcjonalna cena/VAT |
| `module_defaults` | wartości domyślne INT/PRD, zakres administracyjny i metadane zmiany |
| `batch_recipe_snapshots` (przyszły moduł) | niezmienny kontekst wykonania, rozwinięte wersje, ilości, koszty, alergeny, wydruki historyczne |

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
15. Konfekcjonowanie PRD: wielkość i liczba opakowań dobierane przy partii, korekta dozwolona w ustalonym stanie; koszt opakowania nie wpływa na foodcost receptury.

Każdy etap wdrożeniowy powinien otrzymać odrębny, krótki prompt dla Copilota z zakresem plików, testami, zakazem działań poza zakresem oraz raportem. **Żadnej implementacji na podstawie samego tego dokumentu.**

## 15. Zalecana kolejność realizacji po osobnej akceptacji

| Faza robocza | Przedmiot | Warunek przejścia |
|---|---|---|
| 7A — architektura | Niniejszy kontrakt i przegląd konfliktów | Jawna akceptacja właściciela i aktualizacja kontraktów kolidujących |
| Fundament receptur | Model wersji, graf, kalkulator, alergeny, foodcost, ACL | Testy usług w izolacji, bez aktywacji biznesowej |
| INT | Pełny moduł półproduktów, edytor, katalog, wersje, PDF | Odbiór INT i integracji z dostępnym RAW |
| PRD | Pełny moduł wyrobów, dane ręczne, etykiety, konfekcjonowanie, PDF | Odbiór PRD i integracji INT/RAW |
| Integracje | Kontrakty produkcji, partii, snapshotów i późniejszych modułów | Odmowy przy brakach uprawnień i niezmienna historia |

Fazy robocze **nie są nową oficjalną numeracją Stage 7B/7C/7D/7E**; konkretna numeracja zostanie nadana w harmonogramie. Nie uruchamiać RAW ponownie ani nie aktywować INT/PRD przed spełnieniem kontraktów Stage 1E.

## 16. Rejestr jawnych niezgodności i decyzji technicznych do uzgodnienia

| ID | Problem | Stan/zakaz automatycznego rozstrzygnięcia |
|---|---|---|
| C-01 | **SUPER ADMIN** wspomniany w pytaniach 46, 53, 54; Stage 1D zna wyłącznie sześć ról i najwyższą `ADMINISTRATOR`. | Nie dodawać siódmej roli, ukrytego bypassu ani administracyjnego uprzywilejowania bez osobnej zmiany RBAC. Tymczasowo obowiązuje kontrakt roli ADMINISTRATOR. |
| C-02 | **Tworzenie RAW w restauracji:** fundament v0.1 dopuszcza lokalne RAW, późniejsze ustalenia Stage 7A wymagają centralnego tworzenia/udostępniania RAW. | Przed wdrożeniem formalnie uzgodnić zmianę kontraktu RAW, scope i migrację potencjalnych lokalnych RAW; nie przerabiać RAW w tym zadaniu. |
| C-03 | **Publikacja:** fundament v0.1 mówi szkic→zatwierdzona→opublikowana; nowe decyzje 1,3,39 eliminują dodatkowe zatwierdzanie i automatycznie aktywują kompletną zapisaną wersję. | Wymaga jawnego ADR/zmiany kontraktu wersjonowania; nie odtwarzać biurokracji v0.1 ani nie usuwać historii. |
| C-04 | **Graf zależności modułów:** czy lokalnie PRD ma formalnie wymagać `RAW ENABLED`, `INT ENABLED`, obu, czy wykorzystywać tylko udostępnione dane bez aktywacji interfejsu RAW/INT? | Stage 1E wymaga jawnych zależności w kodzie i zabrania autoaktywacji. Nie wymyślać grafu przy implementacji; rozstrzygnąć przed rejestracją modułów. |
| C-05 | **Domyślna gotowość PRD/INT** przy wymaganiach etykietowych i innych polach. | Zatwierdzono warunkową kompletność, ale nie pełną macierz dla wszystkich trybów. Nie blokować niepowiązanych operacji; wyraźnie zdefiniować wymagania technologiczne/produkcyjne. |
| C-06 | **Strata vs uzysk ręczny**, tryb `szt.` i mieszane jednostki. | Jednoznaczny wzór i semantyka bazowego uzysku wymagają technicznego doprecyzowania, bez zmiany decyzji o wsadzie, 0% domyślnie i ręcznym sztukowym uzysku. |
| C-07 | **Konfekcjonowanie PRD źródłem PRD** kontra zakaz PRD jako składnik. | Wymaga osobnego typu relacji przepakowania, a nie niejawnego dopuszczenia `PRD` jako wiersza receptury. |
| C-08 | **Foodcost centrali i uprawnienia konfiguracji cen:** Stage 1D dopuszcza nadawanie FOODCOST_VIEW i PURCHASE_PRICES_VIEW przez ADMINISTRATORA **lub szefa właściwej centrali**, nowe sformułowanie 54 wskazuje SUPER ADMIN/ADMINISTRATOR. | Rozdzielić prawo ustalania sposobu prezentacji od prawa nadawania istniejących uprawnień; zmiana macierzy wymaga osobnej akceptacji, nie może być domniemana. |
| C-09 | **Snapshot i ekspozycja alergenów/próbki:** decyzja o jednej firmowej liście zakładowej obowiązuje, ale proces etykietowania i produkcja są osobnymi modułami. | Przy projektowaniu interfejsów partii nie przypisywać źródeł alergenów automatycznie do innej lokalizacji i nie mieszać stanów historycznych z aktualnymi. |
| C-10 | **PDF kalkulacyjny** tylko wybrane role; szczegółowy zakres kolumn, koszty cenowe i dane odbiorców. | Wdrożyć filtrowanie backendowe, nie tylko ukrywanie przycisku. Szczegóły layoutu doprecyzować w projektowaniu UX. |

**Zasada:** punkty C-01–C-10 są jawnie wymienione i **nie są nowymi zatwierdzonymi wymaganiami**. Nie wolno ich rozstrzygać przez zgadywanie ani implementację, która omija wcześniejsze kontrakty.

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
| 46 | Domyślne wartości konfiguruje centralnie SUPER ADMIN/ADMINISTRATOR; konflikt roli C-01. |
| 47 | Osobny tryb konfekcjonowania PRD z produktem źródłowym. |
| 48 | Koszt opakowań poza PRD. |
| 49 | Bez zdjęć receptur INT i PRD. |
| 50 | Wielkość/liczba opakowań ustalana podczas partii, z możliwością korekty. |
| 51 | Bez stanowisk produkcyjnych w tym zakresie. |
| 52 | Eksport z modułów INT/PRD tylko PDF. |
| 53 | Domyślny PDF technologiczny, opcjonalny kalkulacyjny tylko SUPER ADMIN/ADMINISTRATOR; C-01. |
| 54 | Udostępniona receptura korzysta z kosztu centrali; widoczność foodcostu i cen niezależnie ustawiana przez SUPER ADMIN/ADMINISTRATOR; C-01/C-08. |
| 55 | Restauracja nie może kopiować receptury centrali, może tylko korzystać i przeliczać. |
| 56 | Pełny kalkulator receptury centrali w restauracji, wyłącznie podgląd bez zapisu. |
| 57 | Problemy przy polach i licznik braków u góry. |
| 58 | Zmiana nazwy aktualizuje bieżące powiązania; SKU i historyczne snapshoty niezmienne. |

## 18. Wykaz materiałów źródłowych

- `VELTRA 2.0 — ADR-001 — Model wdrożenia klienta` (źródło projektu).
- `VELTRA 2.0 - Stage 1D - zatwierdzona specyfikacja RBAC v1 - 2026-11-02`.
- `VELTRA 2.0 - Stage 1E - specyfikacja architektury modulow v1`.
- `VELTRA 2.0 - Fundament architektury i modelu danych - projekt v0.1`.
- `VELTRA 2.0 — VSEF — Standard bezpiecznego wytwarzania (propozycja v1)` — źródło standardów wytwarzania, bez automatycznego awansu propozycji do zatwierdzonej polityki.
- `VELTRA_SOURCE_01_ARCHITECTURE.md` … `VELTRA_SOURCE_09_EXECUTIVE_SNAPSHOT.md` — dokumentacja referencyjna KC 1.x.
- `VELTRA_SOURCE_11_INT_PRD_IMPLEMENTATION_AUDIT.md` — audyt repozytorium KC Technologia 1.44.0; lokalizacja w Google Drive: https://drive.google.com/file/d/1h6iShc9KyEbnGhwXRHSkqsk5g2h96ocz/view?usp=drivesdk.
- Rozmowa projektowa Stage 7A, decyzje właściciela z pytań **1–58** oraz wcześniejsze potwierdzenia dotyczące RAW/INT/PRD, wsadu, strat, alergenów i snapshotów.

---

**Status końcowy:** kompletna **wersja robocza architektury** do review. Wymaga przeglądu konfliktów §16 i zatwierdzenia przez właściciela; nie jest dowodem wdrożenia ani upoważnieniem do prac w repozytorium. Po akceptacji przygotować odrębne, etapowe zlecenia Copilota — najpierw kontrakt wspólnego silnika, następnie INT, a później PRD.
