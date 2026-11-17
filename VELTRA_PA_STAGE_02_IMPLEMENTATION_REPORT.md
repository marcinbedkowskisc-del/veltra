# VELTRA 2.0 — raport implementacji PA-02

## Podsumowanie

Lokalnie dodano współdzielony `VeltraAccordion` i włączono go do istniejącej,
jedynej nawigacji aplikacji. Sekcje PA pozostają placeholderami. Nie zmieniano
backendu, RBAC, uwierzytelniania, bazy danych ani konfiguracji Docker.

## Pliki PA-02

- Nowe: `frontend/src/design-system/VeltraAccordion.tsx`,
  `frontend/src/AdministrationNavigation.tsx`.
- Zmienione: `frontend/src/design-system/design-system.css`,
  `frontend/src/design-system/design-system.test.tsx`,
  `frontend/src/AdministrationPanel.test.tsx`,
  `frontend/src/administrationNavigation.ts`,
  `frontend/src/ApplicationShell.tsx`, `frontend/src/App.css`, `README.md`.
  `administrationNavigation.ts` pochodzi z PA-01A i nie był jeszcze śledzony
  przez Git; w PA-02 dodano stabilne identyfikatory grup.
- Ten raport: `VELTRA_PA_STAGE_02_IMPLEMENTATION_REPORT.md`.

W drzewie pozostają także wcześniejsze, niezacommitowane zmiany PA-01 i PA-01A.

## Działanie

- Akordeon obsługuje niezależne otwieranie wielu grup oraz tryb pojedynczy,
  stan kontrolowany i niekontrolowany, opcjonalne ikony i wyłączone sekcje.
- Przyciski korzystają z natywnej obsługi klawiatury, widocznego fokusu,
  `aria-expanded` i powiązania przycisku z panelem. Zamknięte panele są
  oznaczone `aria-hidden` i `inert`, aby wykluczyć ich zawartość z fokusu.
- Wysokość panelu, przezroczystość i obrót chevronu animowane są przez 250 ms;
  `ResizeObserver` dostosowuje wysokość do zmieniającej się zawartości.
  Przy preferencji ograniczenia ruchu przejścia są wyłączane.
- W PA grupa aktualnej strony otwiera się automatycznie, aktywny link jest
  oznaczony `aria-current`, a otwarte grupy są zachowywane w `sessionStorage`
  bieżącej karty. Niedostępność lub błędny stan storage są logowane w konsoli.
- Trasy i dotychczasowe warunki widoczności PA pozostały bez zmian. Zwykła
  nawigacja aplikacji pozostaje poza grupami PA.

## Wykonane testy

Polecenia uruchomiono w katalogu `frontend`:

| Polecenie | Wynik |
|---|---|
| `npm test -- --run src/AdministrationPanel.test.tsx src/design-system/design-system.test.tsx` | PASS: 2 pliki, 22 testy |
| `npm test` | PASS: 15 plików, 197 testów |
| `npm run lint` | PASS, kod 0; istniejące ostrzeżenie `react(only-export-components)` w `src/visualPreview.tsx:39` |
| `npm run build` | PASS: `tsc -b` i produkcyjny build Vite |
| `git diff --check` | PASS |

Testy obejmują m.in. oba tryby, stan kontrolowany, szybkie przełączanie,
relacje ARIA, ukrycie paneli, aktualizację wysokości, aktywną trasę, zachowanie
otwartych grup i widoczność PA dla użytkowników bez uprawnień.

## Kontrola w przeglądarce

Podgląd uruchomiono lokalnie z **syntetycznymi odpowiedziami API**, nie z
produkcyjnym backendem. Na desktopie i przy 320 × 700 potwierdzono pojedynczy
sidebar, wybór sekcji, aktywny link i rozwinięcie właściwej grupy. Na mobile
nawigacja modułów przewija się wewnętrznie poziomo; dokument nie miał
poziomego overflow. Linki Pulpit i RAW prowadziły pod istniejące adresy, ale
podgląd nie weryfikował działania biznesowego RAW.

Sprawdzono jednoczesne otwarcie grup, przejście między stronami i odświeżenie,
Enter/Space, widoczny fokus, Tab pomijający zamknięty panel, szybkie
przełączanie, obrót chevronu oraz `prefers-reduced-motion: reduce`
(`transition-property: none`). Dla dynamicznie dodanej długiej treści wysokość
rosła płynnie od ok. 32 px przez 126 px po 70 ms do 616 px, bez ucięcia po
zakończeniu. Po testach zatrzymano własne procesy podglądu; porty 5192 i 5193
nie nasłuchiwały.

## Ograniczenia i gotowość PA-03

- Przeglądarka korzystała z syntetycznego API; nie jest to test integracyjny z
  rzeczywistą sesją i backendem. Backendowych testów nie uruchamiano ponownie w
  PA-02. Wcześniejszy wynik PA-01: 35 testów, 431 asercji.
- W przeglądarkach bez `ResizeObserver` otwarta treść ma wysokość `auto`, więc
  pozostaje widoczna, ale animacja wysokości nie jest gwarantowana.
- Kontrola była wykonana w dostępnej przeglądarce, nie stanowi testu wszystkich
  przeglądarek ani czytników ekranu.
- Raport zapisano lokalnie. Brak dostępnej uwierzytelnionej ścieżki uploadu
  pliku na Google Drive; wcześniejsze próby przez interfejs zakończyły się
  przekroczeniem czasu.

PA-02 jest gotowe do odbioru i stanowi podstawę PA-03, z uwzględnieniem
powyższych ograniczeń integracyjnych. PA-03 nie został rozpoczęty. Nie wykonano
wdrożenia, commitu ani push.
