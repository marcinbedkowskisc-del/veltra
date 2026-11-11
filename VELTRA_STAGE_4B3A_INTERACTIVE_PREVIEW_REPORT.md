# VELTRA 2.0 — Stage 4B.3A

## Raport interaktywnego podglądu RAW_MATERIALS

**Status:** PASS — lokalny podgląd interaktywny działa i pozostaje uruchomiony  
**URL:** http://localhost:4179/visual-preview.html  
**Gotowość produkcyjna:** `RAW_MATERIALS = NOT_READY`

## Cel i uruchomiona usługa

Podgląd renderuje istniejące komponenty React katalogu, panelu szczegółów, formularza CREATE i formularza EDIT. Serwer Vite Preview udostępnia wyłącznie statyczny build demonstracyjny na `127.0.0.1:4179`; nie używa dev-proxy API.

- Katalog: `/home/veltra/projekty/veltra/frontend/interactive-preview-dist/`
- PID procesu serwera: `140976`
- Interfejs nasłuchu: `127.0.0.1:4179`
- Polecenie builda preview: `cd frontend && ./node_modules/.bin/vite build --config vite.interactive-preview.config.ts`
- Polecenie serwera: `cd frontend && ./node_modules/.bin/vite preview --config vite.interactive-preview.config.ts --host 127.0.0.1 --port 4179 --strictPort`

HTTP GET strony preview zwrócił 200 przez `localhost:4179`. Ścieżka `/api/v1/raw-materials` zwraca statyczne 404; serwer nie przekazuje jej do backendu.

## Interakcje i dane demonstracyjne

Panel sterowania pokazuje bieżący scenariusz, syntetyczne uprawnienia, stan gotowości oraz selektor przykładów. „Resetuj dane demo” przywraca katalog i rezerwacje do stanu początkowego. Stan jest wyłącznie w pamięci karty; odświeżenie strony również zaczyna nową demonstrację.

W katalogu dostępne są wyszukiwanie, filtry, sortowanie, paginacja, zaznaczanie rekordów i eksport CSV. Dane zawierają 32 fikcyjne materiały; wybór przykładowego rekordu pozwala szybko otworzyć:

1. edytowalny szkic;
2. materiał ACTIVE z zatwierdzoną rewizją;
3. ACTIVE z nowszą rewizją roboczą;
4. rekord oczekujący na weryfikację;
5. rekord zarchiwizowany;
6. materiał z aliasami i przelicznikiem jednostki.

Scenariusze dostępu można przełączać bez przeładowania strony:

- pełny dostęp CREATE + EDIT;
- tylko VIEW;
- CREATE bez EDIT;
- `NOT_READY`;
- globalne EDIT przy niedostępnej akcji konkretnego rekordu;
- konflikt nieaktualnej wersji.

Formularze są rzeczywistymi komponentami aplikacji. CREATE demonstruje walidację, lokalną rezerwację SKU, utworzenie szkicu i dopisanie go do katalogu. EDIT demonstruje walidację, zapis nowej rewizji roboczej, zachowanie zatwierdzonej rewizji ACTIVE oraz konflikt bez nadpisania nowszej rewizji. Widoki można sprawdzać w rozmiarze desktopowym i mobilnym.

## Izolacja i bezpieczeństwo

- Adapter preview przechwytuje `window.fetch` przed wyrenderowaniem aplikacji. Obsługiwane odpowiedzi i mutacje działają wyłącznie na syntetycznych obiektach w pamięci.
- Nie ma fallbacku do natywnego `fetch`: obce originy otrzymują syntetyczne 403, a nieobsługiwane ścieżki kontrolowane 404.
- Preview zbudowano oddzielną konfiguracją do `frontend/interactive-preview-dist/`. Standardowy `frontend/dist/` nie zawiera strony ani kontrolek demo; potwierdzono brak etykiety demo w produkcyjnym buildzie.
- W inspekcji żądań przeglądarki zarejestrowano jedynie HTML, JS i CSS z `localhost:4179`; nie zarejestrowano żadnego żądania `/api/` ani żądania do zewnętrznego originu.
- Dane, identyfikatory, użytkownik, lokalizacja, SKU, rezerwacje i tokeny wersji są fikcyjne. Nie łączono się z instalacyjną bazą, nie zmieniano uprawnień ani gotowości backendu.

## Weryfikacja

| Sprawdzenie | Wynik |
|---|---|
| `cd frontend && npm test` | PASS — 11 plików, 112 testów |
| `cd frontend && npm run build` | PASS — TypeScript i produkcyjny build Vite |
| `cd frontend && npm run lint` | PASS — jedno ostrzeżenie Fast Refresh w `src/visualPreview.tsx` |
| `git diff --check` | PASS |
| Build osobnego preview | PASS |
| HTTP `localhost:4179/visual-preview.html` | 200 |
| HTTP `localhost:4179/api/v1/raw-materials` | 404 — brak proxy |
| Katalog i szczegóły | PASS — katalog 32 rekordów, szczegóły otwierają się |
| CREATE | PASS — syntetyczny szkic pojawił się w katalogu |
| EDIT szkicu i ACTIVE | PASS — nowe rewizje robocze; zatwierdzona rewizja ACTIVE pozostała widoczna |
| Konflikt wersji | PASS — komunikat konfliktu, lokalna wartość zachowana |
| VIEW-only, brak EDIT i NOT_READY | PASS — przyciski i stany zgodne ze scenariuszem |
| Mobile 390 × 844 | PASS — formularz i panel mieszczą się w viewport; szerokość dokumentu 390 px |

## Pliki zmienione w Stage 4B.3A

- `.gitignore` — wykluczenie oddzielnego builda preview.
- `frontend/src/App.css` — style panelu sterowania preview.
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx` — obsługa wstępnie wybranego rekordu demonstracyjnego.
- `frontend/src/visualPreview.tsx` — kontrolki scenariuszy i przykładów oraz etykieta syntetycznego trybu.
- `frontend/src/visualPreviewApi.ts` — dane przykładowe, scenariusze, zachowanie CREATE/EDIT w pamięci i blokowanie nieobsługiwanych żądań.
- `frontend/src/visualPreviewApi.test.ts` — testy lifecycle, uprawnień, CREATE/EDIT oraz izolacji żądań.
- `frontend/vite.interactive-preview.config.ts` — osobny build statycznego preview bez proxy.
- `VELTRA_STAGE_4B3A_INTERACTIVE_PREVIEW_REPORT.md` — niniejszy raport.

Wcześniejsze, niezacommitowane prace i raporty pozostały zachowane. Nie edytowano backendu ani istniejących danych.

## Ograniczenia i granice wykonania

Podgląd jest demonstracją UI, nie testem integracyjnym ani potwierdzeniem działania backendu. Wszystkie zmiany znikają po resecie lub odświeżeniu karty. Produkcyjny `RAW_MATERIALS` pozostaje **NOT_READY**.

Nie wykonano żadnych rzeczywistych żądań CREATE/EDIT, rezerwacji SKU, zmian danych ani połączeń z bazą. Nie wykonano migracji, wdrożenia, zmiany konfiguracji produkcyjnej, commita ani pushu. Nie było dostępnej zatwierdzonej metody uploadu Google Drive; raport zapisano lokalnie.

Serwer preview pozostaje uruchomiony dla ręcznej oceny UX.
