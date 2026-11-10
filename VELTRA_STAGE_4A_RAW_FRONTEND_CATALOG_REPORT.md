# VELTRA 2.0 — Stage 4A
## Raport implementacji frontendu katalogu RAW

**Wynik:** PASS dla zakresu katalogu Stage 4A.  
**Status modułu:** `RAW_MATERIALS = NOT_READY`.

## Zakres

Zbudowano frontend katalogu RAW w istniejącej aplikacji React + TypeScript + Vite. Widok jest dostępny pod `/raw-materials`, korzysta z istniejącej powłoki aplikacji, sesji, wyboru lokalizacji i API. Autoryzacja oraz gotowość backendu pozostają nadrzędne — frontend nie omija odpowiedzi API ani nie używa danych demonstracyjnych.

Katalog zawiera dokładnie osiem kolumn. Kontrolka zaznaczania znajduje się w kolumnie „Lp.”, więc nie dodaje dziewiątej kolumny. Widok obsługuje:

- wyszukiwanie serwerowe z debounce 300 ms;
- serwerowe filtry, w tym kategorię oraz dostawcę, jeśli uprawniony endpoint dostawców jest dostępny;
- sortowanie, paginację i ładowanie wyników z API;
- selekcję rekordów po UUID utrzymywaną między stronami; selekcja jest czyszczona przy zmianach filtrów i w odpowiednich stanach odmowy dostępu;
- eksport katalogu lub zaznaczenia przez istniejący workflow eksportu, z kluczem idempotencji i ponownym pobraniem autoryzowanym przez API.

Prezentacja wartości finansowych zależy od pól faktycznie zwróconych przez API. Parser nie zachowuje niedozwolonych wartości, sortowanie po polach finansowych jest dostępne wyłącznie po potwierdzeniu uprawnienia, a kwoty formatowane są bez konwersji do `number`.

Uwzględniono stany ładowania, pustego wyniku, błędu, braku uprawnień, wygasłej sesji oraz niedostępności modułu. Błędy 401/419 są traktowane jako wygaśnięcie sesji. Potwierdzone 403 dla dostawców ukrywa filtr dostawcy; 503 z kodem `RAW_MATERIALS_MODULE_UNAVAILABLE` czyści stan katalogu i prezentuje niedostępność modułu.

Tworzenie surowca i archiwizacja są jawnie odłożone i niedostępne w tym etapie. Nie wykonują mutacji.

## Zmodyfikowane i dodane pliki

- `frontend/src/App.tsx`, `frontend/src/AuthPanel.tsx` — integracja trasy i powłoki z istniejącą sesją oraz lokalizacją.
- `frontend/src/api.ts` — koperta odpowiedzi, anulowanie żądań, obsługa błędów i bezpieczne pobieranie plików.
- `frontend/src/rawMaterials/catalog.ts`, `frontend/src/rawMaterials/api.ts` — typy i parsery kontraktów, mapowanie zapytań oraz klient API RAW.
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx` — orkiestracja katalogu, stanów, filtrów, selekcji i eksportu.
- `frontend/src/rawMaterials/CatalogFilters.tsx`, `frontend/src/rawMaterials/CatalogTable.tsx`, `frontend/src/rawMaterials/SelectionActionBar.tsx` — komponenty filtrów, tabeli i akcji selekcji.
- `frontend/src/App.css`, `frontend/src/index.css` — style powłoki i widoku, responsywność oraz stany focus.
- `frontend/src/config/developmentStage.ts` — etykieta etapu 4A.
- `frontend/vite.config.ts` — konfiguracja frontendu i testów.
- `frontend/package.json`, `frontend/package-lock.json` — Vitest, jsdom i React Testing Library.
- `frontend/README.md` — opis katalogu i komend weryfikacyjnych.
- Testy: `frontend/src/App.test.tsx`, `frontend/src/api.test.ts`, `frontend/src/rawMaterials/catalog.test.ts`, `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx`.

## Weryfikacja

Wyniki końcowego przebiegu:

| Sprawdzenie | Wynik |
|---|---|
| `cd frontend && npm test -- --reporter=dot` | PASS — 4 pliki testowe, 22 testy |
| `cd frontend && npm run build` | PASS — `tsc -b` i build Vite |
| `cd frontend && npm run lint` | PASS |
| `git diff --check` | PASS |

Testy pokrywają m.in. osiem kolumn, domyślne sortowanie i paginację, debounce wyszukiwania, mapowanie filtrów kategorii i dostawcy, eksport, stany 403/NOT_READY, niedostępność uprawnionego endpointu dostawców oraz wygaśnięcie sesji. Test filtrowania czeka teraz na załadowanie kategorii przed interakcją z polem filtra, eliminując wyścig w teście bez zmiany zachowania aplikacji.

Testy wykorzystują mocki API. Nie uruchomiono połączenia z backendem ani wizualnej sesji aplikacji; nie przygotowano zrzutów ekranu.

## Dokumentacja źródłowa i ograniczenia

Wskazane dokumenty Google Docs/Drive były niedostępne do weryfikacji treści w użytym środowisku. W analizie wykorzystano dostępne lokalne materiały etapów B.1, B.2, B.3.1 i B.4.1 oraz lokalną dokumentację projektu. Nie potwierdzono ponownie treści zdalnych dokumentów źródłowych.

## Poza zakresem i dalsze etapy

- Nie dodano interfejsu tworzenia ani archiwizacji; pozostają odłożone do kolejnych etapów.
- Nie uruchomiono backendu, migracji ani testów bazodanowych.
- Nie zmieniono backendu, schematu bazy, gotowości modułu ani zasobów instalacyjnych w ramach Stage 4A. Zmiany backendowe i raporty widoczne w roboczym katalogu pochodzą z wcześniejszych etapów i nie były edytowane w tej pracy.
- Nie wykonano aktywacji, wdrożenia, commitu ani pushu.

`RAW_MATERIALS` pozostaje `NOT_READY`; implementacja katalogu nie oznacza gotowości ani aktywacji modułu.
