# VELTRA 2.0 — Stage 5A
## Audyt architektury wizualnej i przygotowanie projektu systemu UI

**Status: PARTIAL — audyt obecnego interfejsu i plan wykonawczy są gotowe; brak dostępnych zatwierdzonych referencji docelowych uniemożliwia porównanie i zatwierdzenie kierunku wizualnego.**

**Zakres:** wyłącznie odczyt kodu, raportów, lokalnych artefaktów i już otwartego podglądu.  
**Gotowość modułu:** `RAW_MATERIALS = NOT_READY`.

## 1. Podsumowanie

Obecny frontend jest małą aplikacją React z własnym shell’em, jednym globalnym arkuszem CSS i katalogiem RAW jako głównym widokiem danych. Wspiera katalog, filtry, sortowanie, paginację, eksport, selekcję, panel szczegółów, CREATE i wersjonowane EDIT. Istniejące zachowania obejmują kontrolę kontekstu lokalizacji i capability, obsługę stanów błędów, ochronę formularzy przed utratą zmian, konflikt wersji oraz responsywne zachowanie tabeli i overlayów.

W repozytorium znajdują się zrzuty istniejącego UI z etapów 4A–4B.3C oraz techniczne logo/obrazy startowe. Są to dowody obecnego renderu i funkcjonalnych scenariuszy, nie zatwierdzone makiety ani specyfikacja nowej identyfikacji wizualnej. Nie znaleziono zatwierdzonego zestawu kolorów, typografii, layoutu docelowego ani dostępnej makiety VELTRA 2.0. Jedyny dostępny podgląd przeglądarkowy to bieżący, jawnie oznaczony preview RAW z syntetycznymi danymi.

Rekomendacja: nie określać jeszcze konkretnych wartości tokenów ani przebudowy shell’a. Stage 5B powinien najpierw uzyskać i zatwierdzić referencje lub rozstrzygnięcia właściciela, a następnie opisać semantyczne tokeny i komponenty. Etapy 5C–5F poniżej są propozycją do przyszłej akceptacji, nie rozpoczętym zakresem.

## 2. Repozytorium i checkpoint

- Katalog główny potwierdzony: `/home/veltra/projekty/veltra`.
- Gałąź: `main`; `HEAD`: `2ae6892` (`048`); gałąź lokalna jest **1 commit do przodu** względem `origin/main`.
- Początkowy `git status --short --branch`: czyste drzewo robocze, bez zmian staged/unstaged; `git diff` i `git diff --cached` nie wykazały zmian.
- Istniejący `HEAD` zawiera checkpoint zmian Stage 4B.2B–4B.3C (45 plików w statystyce commita). Nie zmieniano historii Git.
- Poza niniejszym raportem nie utworzono ani nie zmieniono plików. Nie wykonano commitu ani pushu.

## 3. Inwentaryzacja referencji wizualnych

### Znalezione źródła

1. `artifacts/stage4a1/`, `artifacts/stage4a2/`, `artifacts/stage4b1/`, `artifacts/stage4b2a/`, `artifacts/stage4b2b/`, `artifacts/stage4b3b/` i `artifacts/stage4b3c/` zawierają zrzuty istniejącego katalogu, stanów błędów/ograniczeń, szczegółów, formularzy, filtrów i mobilnego widoku. Raporty etapów opisują je jako render rzeczywistych komponentów z syntetycznym adapterem.
2. Udostępniona strona `http://127.0.0.1:4179/visual-preview.html` była dostępna w przeglądarce. Obejrzany widok pokazuje obecny ciemny shell, syntetyczny pasek sterowania demo i katalog RAW. Nie wykonywano interakcji ani nowych pomiarów.
3. `frontend/src/assets/hero.png` przedstawia abstrakcyjny, warstwowy symbol; `react.svg` i `vite.svg` są zasobami startowymi. Nie stanowią identyfikacji ani makiety produktu.

### Braki i status autorytetu

- Nie znaleziono makiet, specyfikacji brandu, plików Figma/Sketch/PDF ani zatwierdzonego docelowego widoku w zindeksowanych plikach repozytorium.
- W dostępnej sesji nie było zatwierdzonego źródła zewnętrznego do przeglądu referencji. Nie twierdzę, że referencje nie istnieją poza tym zakresem.
- Zrzuty Stage 4 są **referencją baseline’u UI**, nie zatwierdzeniem jego palety lub kierunku na przyszłość.
- Założenia briefu („profesjonalny, nowoczesny, uporządkowany, premium, powściągliwy i czytelny”) są kryteriami jakościowymi. Nie określają konkretnej palety, motywu jasnego/ciemnego, fontu ani docelowego układu.

### Decyzje wymagające właściciela

1. Udostępnić i wskazać autorytatywne referencje albo formalnie zatwierdzić przygotowanie nowego kierunku.
2. Rozstrzygnąć, czy docelowy produkt używa wyłącznie motywu ciemnego, jasnego, czy obu; obecny CSS nie jest dowodem zatwierdzonej polityki motywów.
3. Zatwierdzić hierarchię nawigacji, ekspozycję kontekstu organizacji/lokalizacji, docelową gęstość tabel i sposób prezentacji tabel na małych ekranach.
4. Określić wymagany standard dostępności i kontrastu oraz ewentualne wymagania brandowe/ikonograficzne.

## 4. Obecna architektura UI

### Shell i kontekst operacyjny

- `App.tsx` renderuje poziomy nagłówek z marką, nawigacją `Start` / `Surowce` i etykietą etapu, a niżej dwukolumnowy układ: boczny panel środowiska/konta oraz główny obszar treści.
- `.app-layout` w `App.css` ma sidebar o szerokości 260–310 px. Sidebar łączy status backendu z `AuthPanel`; lokalizacja operacyjna jest wybierana w tym panelu, a nie w osobnym nagłówku workspace.
- Dostęp do katalogu jest warunkowany aktywnym użytkownikiem i lokalizacją. Zmiana kontekstu remountuje stronę RAW z kluczem user/location w `App.tsx`.
- W preview część shell’a jest odtworzona demonstracyjnie w `visualPreview.tsx`; pasek scenariuszy i kontekst testowy nie należą do produkcyjnego shell’a.

### Katalog i dane

- `RawMaterialsCatalogPage.tsx` koordynuje stan zapytań, capability, filtry, stronę, selekcję, eksport, błędy oraz otwieranie CREATE/szczegółów.
- `CatalogFilters.tsx` wyświetla wyszukiwanie, kategorię, dostawcę (zależnie od capability/stanu), status i jednostkę oraz reset/eksport.
- `CatalogTable.tsx` używa semantycznego `<table>`, ośmiu kolumn, sortowalnych nagłówków, checkboxów, wartości finansowych zależnych od permission i komunikatu pustego/ładowania. `.table-scroll` umożliwia przewijanie; tabela ma `min-width: 930px` i sticky header.
- Pod tabelą są podsumowanie zakresu i wybór liczby wierszy; osobna nawigacja zapewnia paginację. `SelectionActionBar.tsx` jest paskiem akcji przyklejonym u dołu widoku.

### Szczegóły, formularze i stany

- `RawMaterialDetailDrawer.tsx` renderuje modalny, prawostronny panel ze szczegółami, rewizjami i akcją edycji. Panel ma własne przewijanie, sticky header, obsługę Escape/Tab i przywracanie fokusu.
- `RawMaterialCreateForm.tsx` i `RawMaterialEditForm.tsx` używają dialogów na wspólnym szkielecie klas `.create-form-*`; na małym ekranie formularz staje się dolnym panelem. Edycja ma dodatkowy overlay ponad drawerem.
- Formularze prezentują walidację, komunikaty, stany zapisu i błędów. Kod ustawia `aria-invalid`/`aria-describedby`, zabezpiecza niezapisane zmiany przy `beforeunload`, a dialogi obsługują fokus.
- Katalog, drawer i formularze rozróżniają m.in. ładowanie, brak dostępu, błąd, niedostępność modułu, sukces i konflikt. Widoczne alerty/statusy są głównie realizowane przez role ARIA i klasy tekstowe.

## 5. Style i komponenty — ustalenia techniczne

- Globalne style są w `frontend/src/App.css` (~700 linii) i `frontend/src/index.css`; aplikacja nie używa CSS Modules ani istniejącej biblioteki komponentów. `package.json` nie deklaruje biblioteki UI ani systemu ikon.
- `:root` w `App.css` definiuje 13 zmiennych kolorystycznych (powierzchnie, tekst, akcent, błąd, sukces). Wiele kolorów pozostaje wpisanych bezpośrednio w selektorach dla body/header, formularzy, tabel, statusów, drawerów, dialogów i sukcesu. `index.css` powtarza bazowy kolor tekstu/tła.
- Kolory i typografia są częściowo współdzielone przez klasy `.button-*`, `.filter-field`, `.create-form-field`, `.detail-section` i `.state-*`; warianty, radiusy, odstępy, bordery i powierzchnie są jednak często definiowane lokalnie.
- Font to systemowe `system-ui`; skala typografii, odstępów, radiusów i wysokości kontrolek nie jest tokenizowana. Wymiary są zapisane bezpośrednio jako rem/px/clamp.
- Layout i styl zależą od klas CSS komponentów, lecz warunki biznesowe (np. dostępność edycji, permission do pól finansowych, `NOT_READY`) są logicznie rozstrzygane w komponentach React. CSS nie powinien stać się źródłem autoryzacji.
- Responsywność opiera się głównie na breakpointach 980 px i 720 px. Przy 720 px shell przechodzi w układ pionowy, kontrolki filtrów stają się jednokolumnowe, drawer zajmuje ekran, a formularze układają pola pojedynczo. Tabela zachowuje minimalną szerokość i własny poziomy scroll.
- Brak osobnego komponentu globalnego module navigation, dedykowanego switchera lokalizacji w headerze, wspólnego komponentu Badge/Alert/FormField/Modal oraz systemu ikon. To obserwacje strukturalne, nie automatyczna rekomendacja dodania biblioteki.
- Ryzyka responsywne do dalszej walidacji: niska czytelność bardzo gęstej tabeli na wąskim ekranie, długość shell’a po przeniesieniu sidebaru nad treść, nakładanie się fixed selection bar na treść oraz zagnieżdżenie drawer + edit dialog. Nie wykonano w tym audycie pomiarów kontrastu ani testów urządzeń.

## 6. Proponowana architektura systemu projektowego

Wartości liczbowe i konkretne kolory pozostają **nieustalone do czasu otrzymania/zatwierdzenia referencji**. Stage 5B powinien definiować semantykę i przykładowe użycie, nie odgadywać palety.

### A. Fundamenty

- Tokeny semantyczne: tło aplikacji; powierzchnia bazowa/podniesiona/aktywna; tekst podstawowy/pomocniczy/wyciszony; obramowanie; akcja/akcent; fokus; informacja; sukces; ostrzeżenie; błąd; stan ograniczenia. Oddzielić rolę od konkretnej wartości koloru.
- Zatwierdzona skala typografii, wysokości linii, grubości i szerokości tekstu; wartości liczbowe i kody używają cyfr tabelarycznych.
- Skala odstępów, grubości obramowań, radiusów, cieni/elevacji i warstw overlay wraz z zasadami użycia.
- Konwencje gęstości: wysokości wierszy/kontrolek, padding tabel, warianty kompaktowy/komfortowy, minimalny target dotykowy; do zatwierdzenia z właścicielem.
- Tokeny breakpointów, kontenera i reguły reflow; należy zdecydować, kiedy tabela przewija się poziomo, a kiedy zmienia prezentację.
- Jednolite stany hover/active/focus-visible/disabled/loading/error oraz preferencja `prefers-reduced-motion`. Statusy nie mogą być komunikowane wyłącznie kolorem.
- Użyć istniejących zasobów/ikon albo zatwierdzić prosty, spójny zestaw SVG. Nie dodawać zależności ikonograficznej bez uzasadnienia i zgody.

### B. Aplikacja i shell

- Oddzielić komponent shell’a (global header, primary navigation, workspace/location context, sidebar lub module navigation, content frame) od stron modułów.
- Utrzymać źródło prawdy dla aktywnej lokalizacji w istniejącym kontekście operacyjnym; prezentacja może się zmienić, ale nie może tworzyć drugiego stanu ani omijać backendowej walidacji.
- Ustalić jednolitą hierarchię: tytuł strony, opis/kontekst, główne akcje, narzędzia strony, treść, komunikaty.
- Dla wąskich ekranów określić zatwierdzony sposób nawigacji i kolejność klawiatury; nie zakładać konkretnego menu bez decyzji.

### C. Dane i katalog

- Wspólny wzorzec tabeli: semantyczne nagłówki, sortowanie z `aria-sort`, selekcja, formaty liczb/jednostek, uprawnieniowo ograniczone wartości, focus/hover, sticky header tylko tam, gdzie ma sens.
- Jedna kompozycja toolbaru dla wyszukiwania, filtrów i akcji; stan aktywnych filtrów i reset pozostają widoczne i mierzalne.
- Paginacja powinna konsekwentnie komunikować zakres/całość, stan przycisków i zmianę liczby wyników. Loading nie może powodować niepotrzebnego przesunięcia layoutu.
- Uzgodnić wariant mobilny tabeli z właścicielem; zachować możliwość dostępu do wszystkich danych oraz wyraźne wskazanie przewijania, jeśli pozostanie poziomy scroll.
- Empty, loading, denied, unavailable i error to odrębne komponenty/stany semantyczne, nie wyłącznie zmiany koloru.

### D. Formularze i overlay

- Wspólny zestaw Button, Text/Numeric Input, Select, Textarea, FormField, FieldHint/Error, Alert/Status i grup pól; role wizualne nie zmieniają walidacji ani serializacji.
- Ustalić reguły rozmieszczenia błędów, wymaganych pól, pomocy, stanów zapisu i konfliktu. Zachować fokusing pierwszego błędnego pola i połączenie błędu z kontrolką.
- Ujednolicić dialog/drawer: nagłówek, obszar przewijania, akcje, Escape/Tab, fokus początkowy/powrotny, warstwy i zachowanie na mobile.
- Potwierdzenie niezapisanych zmian powinno pozostać jawne; sukces/błąd/konflikt nie mogą usuwać lokalnych zmian bez wyraźnej decyzji.
- Komponenty projektowe powinny być prezentacyjne i kontrolowane przez rodzica; nie pobierają danych, nie wyliczają uprawnień, nie tworzą identyfikatorów i nie podejmują decyzji domenowych.

## 7. Rozdzielenie domeny od prezentacji

| Warstwa | Obecne miejsce / odpowiedzialność | Granica przyszłego restylingu |
|---|---|---|
| Reguły katalogu i model UI domeny | `rawMaterials/catalog.ts`, `create.ts`, `detail.ts` — typy, walidacja, formaty i transformacje | Nie przenosić do CSS ani prezentacyjnych komponentów. |
| API i parsery | `rawMaterials/api.ts`, `frontend/src/api.ts` — endpointy, typy/parsowanie odpowiedzi i błędy | Zachować kontrakty, statusy, wersję, klucze idempotencji i fail-closed parsery. |
| Capability, kontekst, request state | `App.tsx`, `RawMaterialsCatalogPage.tsx`, drawer/formy | Restyling nie może zmieniać warunków renderowania akcji, resetu kontekstu, anulowania/staleness ani stanów błędów. |
| Interakcje modułu | `RawMaterialsCatalogPage.tsx`, `RawMaterialDetailDrawer.tsx`, `RawMaterialCreateForm.tsx`, `RawMaterialEditForm.tsx` | Wyodrębnienie komponentu jest strukturalne; zachować wywołania, kolejność i granice autoryzacji. |
| Wspólne komponenty prezentacyjne | Obecnie głównie klasy w `App.css` | Mogą otrzymywać tokeny/propsy wariantów. Bez własnych requestów ani reguł RAW. |
| Style/layout | `App.css`, `index.css` | Visual-only zmiany powinny być lokalne i nie zmieniać kolejności DOM, selektorów stanów ani dostępności. |
| Preview | `visualPreview.tsx`, `visualPreviewApi.ts`, osobny build preview | Pozostaje izolowany; żądania demonstracyjne nigdy nie trafiają do API instalacji. |

Zmiany w shell’u, strukturze nawigacji, DOM formularzy/tabel lub modelu mobile są **strukturalne** i wymagają osobnych regresji. Przenoszenie logiki permission/request do nowego design systemu jest niepożądane. Szczególnej ochrony wymagają: CREATE i skutki rezerwacji SKU, dwupoziomowe EDIT, `If-Match`, niejednoznaczny zapis, lifecycle, filtrowanie/paginacja, kontekst lokalizacji, ochrona przed spóźnionymi odpowiedziami i bramka `NOT_READY`.

## 8. Macierz luk

Ponieważ brak zatwierdzonego wzorca docelowego, „cel” w tabeli jest zasadą projektową do zatwierdzenia, nie opisem nieistniejącej makiety.

| Obszar | Stan obecny / dowód | Zasada docelowa do zatwierdzenia | Referencja | Zmiana i kategoria | Ryzyko / złożoność / decyzja |
|---|---|---|---|---|---|
| Fundamenty i tokeny | 13 zmiennych kolorów, lecz wiele literalnych wartości; brak tokenów typografii/spacing/radius | Semantyczny, spójny zestaw tokenów bez arbitralnych wartości | Brak; `App.css` to baseline | Visual-only: zastąpienie literalnych wartości tokenami; potem kontrolowany przegląd selektorów | Średnie / średnia / wysoka zależność od referencji i motywu |
| Shell i nawigacja | Header z 2 linkami; status i konto/lokalizacja we wspólnym sidebarze | Stabilna hierarchia moduł/przestrzeń operacyjna/kontekst | Bieżące UI nie jest targetem | Structural: zmiana kompozycji shell’a | Wysokie / wysoka / wymaga akceptacji IA i lokalizacji |
| Nagłówek strony/akcje | Tytuł i główna akcja po przeciwnych stronach nagłówka katalogu | Powtarzalny tytuł, kontekst i obszar akcji | Brak target screenshotu | Visual-only, chyba że zmienia się kolejność/kompozycja | Niskie–średnie / mała–średnia / zatwierdzić wzorzec |
| Filtry/search | Flex-wrap toolbar, pola i akcje w jednej powierzchni; debounce i stabilny layout | Spójny toolbar, czytelne aktywne kryteria, zachowanie bez skoku layoutu | Etap 4B.3C dowodzi aktualnego zachowania, nie targetu | Visual-only: kontrolki/spacing; Structural tylko przy zmianie układu | Średnie / średnia / mobilny wariant do decyzji |
| Tabela/paginacja | 8 kolumn, `min-width: 930px`, poziomy scroll, sort, paginacja, selekcja | Gęstość profesjonalna, pełna dostępność wszystkich danych i czytelny overflow | Zrzuty Stage 4A–4B pokazują aktualny render | Visual-only dla kolorów/typografii; Structural dla kolumn/mobile/pagination | Wysokie / średnia–wysoka / decyzja modelu mobilnego |
| Statusy/komunikaty | Różne klasy i role ARIA; status niekiedy kolorowy plus tekst | Wspólny Alert/Badge z ikoną/tekstem i jednoznacznym stanem | Kod komponentów; brak targetu | Visual-only: kształt/kolor; Behavior tylko po osobnej decyzji | Średnie / mała–średnia / zatwierdzić semantykę/statusy |
| Drawer i historia | Drawer 40rem, sticky header i wewnętrzny scroll; focus/keyboard handling | Spójne reguły drawer/dialog i warstw, zachowane operacje klawiaturą | Zrzuty Stage 4B.1 są baseline’em | Visual-only możliwe; zmiana modalności/DOM = Structural | Wysokie / średnia / wymaga regresji fokusowej |
| CREATE/EDIT | Wspólny formularz dialogowy, walidacja, dirty state, CREATE i wersjonowany EDIT | Powtarzalne pola, walidacja, komunikaty, footer i overlay | Zrzuty Stage 4B.2A/2B-I są baseline’em | Visual-only dla stylu; grouping/layout = Structural; zmiana interakcji = Behavioral | Bardzo wysokie / wysoka / nie modyfikować kontraktów bez osobnej zgody |
| Mały ekran | Shell stackuje sidebar nad treścią; pola formularza jednokolumnowe; tabela scrolluje poziomo | Mobilny dostęp do kontekstu, akcji i wszystkich danych | Istniejące mobile screenshots, bez targetu | Structural dla shell/table; visual-only dla skali i spacingu | Wysokie / średnia–wysoka / decyzje IA i tabeli |
| Ikony/branding | Głównie tekst i znak `+`; dostępne obrazki startowe | Spójna, zatwierdzona identyfikacja i ikonografia | Brak zatwierdzonego brand assetu | Visual-only, chyba że dochodzi nowy asset | Średnie / mała–średnia / wymaga dostarczenia/akceptacji brandu |

## 9. Zalecana sekwencja przyszłych etapów

### Stage 5B — Specyfikacja systemu projektowego

- **Zakres:** inwentaryzacja referencji przez właściciela; zatwierdzenie motywu, typografii, kolorów, skali, gęstości, breakpointów, fokusów i wariantów komponentów; opis kontrastów i semantyki.
- **Zależności:** dostępne i jawnie zatwierdzone referencje albo pisemne decyzje właściciela.
- **Prawdopodobne pliki:** nowy zatwierdzony dokument/token spec; `frontend/src/App.css`, `index.css` dopiero w implementacji, nie w audycie.
- **Ryzyka:** błędne utożsamienie obecnego dark UI z targetem; przypadkowe przypisanie designowi statusu zatwierdzonego.
- **Regresje/akceptacja:** zatwierdzona mapa tokenów i komponentów; kontrast/focus/reduced motion; brak zmian zachowań.
- **Artefakt:** specyfikacja systemu i zatwierdzony wzorzec bazowy.

### Stage 5C — Shell aplikacji i nawigacja

- **Zakres:** implementacja zatwierdzonego headera, nawigacji, kontekstu operacyjnego, content frame i responsive navigation.
- **Zależności:** Stage 5B; potwierdzony model lokalizacji i shell’a.
- **Prawdopodobne pliki:** `App.tsx`, `AuthPanel.tsx`, `App.css`, testy `App.test.tsx`; ewentualnie nowe, wyłącznie prezentacyjne komponenty.
- **Ryzyka:** zmiana remountu przy zmianie lokalizacji, utrata informacji o aktywnym kontekście, kolejność keyboard focus.
- **Regresje/akceptacja:** logowanie/wylogowanie, location switch, brak lokalizacji, sesja wygasła, routy i viewporty desktop/mobile; backend pozostaje autorytatywny.

### Stage 5D — Restyling katalogu RAW

- **Zakres:** tokeny i zatwierdzone komponenty dla filtra, search, tabeli, statusów, podsumowania i paginacji.
- **Zależności:** Stage 5B i shell 5C; decyzja o wariancie małego ekranu.
- **Prawdopodobne pliki:** `RawMaterialsCatalogPage.tsx`, `CatalogFilters.tsx`, `CatalogTable.tsx`, `SelectionActionBar.tsx`, `App.css` i testy katalogu.
- **Ryzyka:** filtry/sortowanie/paginacja, selection, ukryte pola finansowe, export key, race/stale response, poziomy scroll i layout shift.
- **Regresje/akceptacja:** ten sam request/state/permission contract; testy katalogu; screenshoty porównawcze; keyboard + tabela na wąskich ekranach.

### Stage 5E — Szczegóły i formularze

- **Zakres:** drawer, historia rewizji, CREATE i EDIT w zatwierdzonych komponentach form/overlay.
- **Zależności:** Stage 5B i komponenty overlay z 5C/5D.
- **Prawdopodobne pliki:** `RawMaterialDetailDrawer.tsx`, `RawMaterialCreateForm.tsx`, `RawMaterialEditForm.tsx`, `App.css` i testy form/drawer.
- **Ryzyka:** największe — skutki CREATE/rezerwacji SKU, bramki capability i per-record, `If-Match`, konflikt/niejednoznaczny zapis, unsaved changes, sesja/lokalizacja i status NOT_READY.
- **Regresje/akceptacja:** pełne istniejące testy formularzy/API/drawera oraz stany 401/419/403/409/422/503 i błędne odpowiedzi; brak realnych zapisów w preview.

### Stage 5F — Integracja i regresja wizualna

- **Zakres:** spójność między modułami, testy responsywności/dostępności, stabilizacja screenshotów i dokumentacja komponentów.
- **Zależności:** 5B–5E.
- **Prawdopodobne pliki:** testy frontendowe, harness `visualPreview.tsx`/`visualPreviewApi.ts`, katalog zatwierdzonych artefaktów.
- **Ryzyka:** różnice viewport/device scale, niestabilne dane, włączenie żywego API lub niezatwierdzonych zrzutów jako wzorca.
- **Regresje/akceptacja:** komplet testów i porównania w stałych wymiarach; dowód braku requestów backendowych w preview; dokumentacja ograniczeń.

## 10. Ryzyka i ocena

| Ryzyko | Poziom | Ograniczenie |
|---|---|---|
| Brak autorytatywnej referencji spowoduje fikcyjne kolory/układ przedstawione jako zatwierdzone | Wysoki | W 5B uzyskać zatwierdzone źródła; do tego czasu nie ustalać wartości. |
| Restyling ukryje/ujawni akcje lub dane finansowe | Krytyczny | Permission/capability pozostają w istniejących resolverach/warunkach; testować role i pola ograniczone. |
| Zmiana komponentu/DOM naruszy wersjonowanie EDIT, If-Match albo rezerwację SKU w CREATE | Krytyczny | Oddzielić klasy CSS od logiki; pełne testy API/form i bezpieczne syntetyczne preview. |
| Zmiana shell’a zmieni aktywny kontekst lub utraci izolację po zmianie lokalizacji | Wysoki | Utrzymać aktualny źródłowy stan i testy remount/resetu kontekstu. |
| Nowy układ tabeli utrudni dostęp do kolumn lub zmieni zachowanie scrolla | Wysoki | Zatwierdzić model mobile; testować wszystkie kolumny, keyboard, horizontal scroll i focus. |
| Kolor jako jedyny nośnik stanu albo niski kontrast | Średni, niezmierzony | Wymagać kontroli kontrastu i tekstowej/semantycznej etykiety; obecnego audytu nie traktować jako pomiaru WCAG. |
| Fixed selection bar/dialogi kolidują przy małych viewportach | Średni | Testy viewportu i overlay/focus w 5D/5F, bez zmiany zachowania w tym audycie. |

## 11. Strategia testów dla wdrożenia

1. **Testy jednostkowe istniejące:** uruchomić cały frontend `cd frontend && npm test`; zachować testy katalogu, API/parserów, CREATE, EDIT, drawer i adaptera preview. Wizualna zmiana nie może osłabić testów logiki.
2. **Build i lint:** `cd frontend && npm run build`, `cd frontend && npm run lint`, `git diff --check`. Sprawdzić wynik lint względem bazowych ostrzeżeń raportowanych przez Stage 4B.3C-FIX.
3. **Testy integracyjne komponentów:** wyszukiwanie/debounce, filtry/reset, sortowanie, paginacja, selekcja/eksport, uprawnienia do pól finansowych, otwieranie i zamykanie drawer/dialogów, fokus, błędy i zmiana lokalizacji.
4. **Przeglądarka, tylko syntetycznie:** użyć istniejącego izolowanego `visualPreview.tsx` i `visualPreviewApi.ts`; asercją ma być brak przekazania żądań do produkcyjnego API. Pokryć VIEW/CREATE/EDIT, NOT_READY, 403, 409 i stany błędów bez prawdziwych zapisów.
5. **Viewporty:** stałe desktop 1440×900 i 1920×1080 oraz mobile 390×844; dodatkowo wąskie 320–375 px i progi obok istniejących 720/980 px. Rejestrować wymiary screenshotu i wykrywać poziomy overflow poza celowym przewijaniem tabeli.
6. **Stabilność layoutu:** porównać położenie nagłówka/tabeli podczas loading/search, puste wyniki, liczbę wierszy, statusy, filtry i akcje; loading nie powinien wstawiać skaczących komunikatów.
7. **Dostępność:** keyboard-only (Tab/Shift+Tab/Escape, focus trap i restore), nazwy kontrolek, sort state, `aria-live`, błędy powiązane z polami, reduced motion oraz kontrast wg standardu zatwierdzonego w 5B.
8. **Regresje krytyczne:** CREATE bez generowania SKU po stronie klienta; EDIT z ponowną autoryzacją i niezmienionym `If-Match`; konflikt bez nadpisania; zachowanie niezapisanych zmian; izolacja organizacji/lokalizacji i `RAW_MATERIALS = NOT_READY`.

Nie uruchamiano testów ani builda w tym etapie — nie zmieniano kodu aplikacji; walidacja dotyczyła odczytu i dostępności podglądu.

## 12. Wykonanie, ograniczenia i następny krok

- **Plik dodany:** `VELTRA_STAGE_5A_VISUAL_ARCHITECTURE_AUDIT.md`.
- **Kod aplikacji:** bez zmian.
- **Referencje:** porównanie z zatwierdzonym targetem jest zablokowane brakiem dostępnego źródła.
- **Preview:** obejrzano istniejącą kartę `http://127.0.0.1:4179/visual-preview.html`; nie uruchamiano ani nie zatrzymywano procesu, nie generowano nowych screenshotów.
- **Walidacja:** nie uruchomiono builda, lint ani testów; nie było zmian ich dotyczących.
- **Google Drive:** zatwierdzona integracja/uploadu nie była dostępna; dostarczenie Drive nie jest potwierdzone. Raport pozostaje lokalnie.
- **Deployment / migracje / commit / push:** brak.
- **RAW_MATERIALS:** `NOT_READY`, bez zmian.
- **Procesy tymczasowe:** nie uruchamiano nowych; istniejący preview pozostawiono bez zmian.

**Warunek przed Stage 5B:** właściciel powinien wskazać zatwierdzone referencje lub zatwierdzić przygotowanie nowego kierunku oraz rozstrzygnąć motyw, shell/nawigację, gęstość i mobilny wariant tabeli. Do tego czasu nie rozpoczynać implementacji redesignu.

## 13. Dokładne źródła sprawdzone

### Instrukcje i raporty

- `.github/copilot-instructions.md`
- `VELTRA_STAGE_4B3C_SEARCH_LAYOUT_STABILITY_REPORT.md`
- `VELTRA_STAGE_4B3C_FIX_REACT_STATE_WARNING_REPORT.md`
- `VELTRA_STAGE_4B3B_CATALOG_UX_FIX_REPORT.md`
- `VELTRA_STAGE_4B3A_INTERACTIVE_PREVIEW_REPORT.md`
- `VELTRA_STAGE_4B2B_I_RAW_EDIT_FORM_IMPLEMENTATION_REPORT.md`
- `VELTRA_STAGE_4B1_RAW_DETAIL_DRAWER_REPORT.md`
- `VELTRA_STAGE_4B2A_RAW_CREATE_FORM_REPORT.md`
- `VELTRA_STAGE_4A1_RAW_VISUAL_VERIFICATION_REPORT.md`

### Kod i konfiguracja

- `frontend/package.json`
- `frontend/src/App.tsx`, `frontend/src/App.css`, `frontend/src/index.css`, `frontend/src/main.tsx`
- `frontend/src/AuthPanel.tsx`, `frontend/src/AccountLinkForm.tsx`
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx`
- `frontend/src/rawMaterials/CatalogFilters.tsx`, `CatalogTable.tsx`, `SelectionActionBar.tsx`
- `frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx`
- `frontend/src/rawMaterials/RawMaterialCreateForm.tsx`
- `frontend/src/rawMaterials/RawMaterialEditForm.tsx` (zakres odczytu: deklaracje i początkowy przepływ ładowania)
- `frontend/src/visualPreview.tsx`
- `frontend/src/assets/hero.png`
- `frontend/visual-preview.html`, `frontend/src/visualPreviewApi.ts` — lokalizacja i izolacja preview potwierdzone także w raporcie Stage 4B.3A; nie czytano całej implementacji adaptera w tym etapie.

### Artefakty graficzne

- Wyszukano pliki PNG/JPG/JPEG/WebP/SVG/PDF/Figma/Sketch w repozytorium z pominięciem `.git`, `node_modules` i `backend/vendor`.
- Obejrzano już otwartą stronę lokalnego preview; nie otwierano plików artefaktów screenshot po screenshotcie.

**Nie rozpoczęto Stage 5B ani żadnego wdrożenia redesignu.**
