# VELTRA 2.0 — Stage 4A.1-R
## Raport końcowej weryfikacji wizualnej i funkcjonalnej

**Data:** 2026-11-10  
**Status:** **PARTIAL**  
**Zakres:** izolowany podgląd rzeczywistego frontendu z syntetycznymi odpowiedziami; bez dostępu do danych produkcyjnych.

## 1. Podsumowanie

Wymagane sześć zrzutów istnieje pod właściwymi nazwami. Zrzuty katalogu przy 1920 × 1080 i 1440 × 900 oraz stany ograniczeń i `NOT_READY` zostały zweryfikowane jako rzeczywiste rendery frontendu. Wszystkie wymagane kontrole funkcjonalne zakończyły się powodzeniem w izolowanym smoke teście.

Wynik jest **PARTIAL**, ponieważ w rzeczywistym stanie `NOT_READY` filtr dostawcy nadal pokazuje „Sprawdzanie dostępu…” po wyświetleniu niedostępności modułu. To zachowanie pochodzi z komponentu katalogu; nie zmieniano logiki produkcyjnego komponentu w ramach naprawy harnessu. Zrzuty selekcji i filtrowania pochodzą z fizycznego obszaru zintegrowanej przeglądarki 1025 × 577, więc mają mniejszą czytelność niż zrzuty desktopowe.

`RAW_MATERIALS = NOT_READY` — stan pozostał niezmieniony.

## 2. Metoda podglądu i przyczyna problemu z wymiarami

- Istniejący harness Vite wyświetla rzeczywisty `RawMaterialsCatalogPage`; adapter `visualPreviewApi.ts` zastępuje odpowiedzi API syntetycznymi danymi. Serwer był dostępny wyłącznie pod `127.0.0.1:4179`, a odbiornik obrazów pod `127.0.0.1:4180`.
- Zrzuty katalogu w docelowych wymiarach wykonano z istniejącego Chrome dla Windows uruchomionego bez UI, z `--window-size=1920,1080` lub `--window-size=1440,900`, `--force-device-scale-factor=1` oraz ograniczonym `--virtual-time-budget`. Stany selekcji i filtrowania przechwycono w zintegrowanej przeglądarce.
- Przyczyna wcześniejszych obrazów 1025 × 577 została potwierdzona: fizyczna powierzchnia zintegrowanej przeglądarki ma 1025 × 577. `page.setViewportSize()` zmieniało emulowany viewport, ale nie rozmiar tej powierzchni. CSS harnessu już używał `100vw`/`100vh`; nie była to usterka szerokości samego katalogu. Zrzuty desktopowe z headless Chrome mają właściwe wymiary pikseli przy skali urządzenia 1.
- Wariant `NOT_READY` sprawdzono ponownie po 10 sekundach czasu wirtualnego. Obraz pozostał identyczny, co potwierdza, że komunikat o sprawdzaniu filtra nie był tylko skutkiem zbyt krótkiego oczekiwania na zrzut.
- Vite kierował nieobsłużone żądania proxy na `127.0.0.1:1`; logi pokazują oczekiwane `ECONNREFUSED` dla kontroli backendu/auth. Żądania katalogu w podglądzie obsługiwał adapter demonstracyjny.

## 3. Zmiany w harnessie

- `visual-preview-receiver.mjs`: dodano sześć wymaganych nazw plików do allowlisty odbiornika.
- `frontend/src/visualPreviewApi.ts`: dodano syntetyczne przypisania dostawców i filtrowanie po dostawcy; uporządkowano budowę odpowiedzi restricted, aby ograniczone pola finansowe były pomijane poprawnie.
- Nie zmieniano `RawMaterialsCatalogPage`, backendu, konfiguracji wdrożeniowej ani migracji.
- Wcześniejsze częściowe warianty `*-1025x577.png` pozostawiono. Pliki `raw-catalog-restricted.png` i `raw-catalog-not-ready.png` zastąpiono zweryfikowanymi renderami 1440 × 900.

## 4. Inwentarz zrzutów

Wymiary PNG odczytano programowo z nagłówków plików. Wszystkie obrazy obejrzano; pochodzą z rzeczywistego renderu komponentu, nie z ilustracji wygenerowanych.

| Plik | Wymiary | Wynik wizualny |
|---|---:|---|
| `raw-catalog-1920.png` | 1920 × 1080 | Pełny widok desktopowy; tabela, filtry i informacja o danych demonstracyjnych widoczne. |
| `raw-catalog-1440.png` | 1440 × 900 | Pełny widok desktopowy; tabela i oba pola finansowe widoczne. |
| `raw-catalog-selection.png` | 1025 × 577 | Rzeczywista selekcja; widoczne 3 zaznaczone pozycje i pasek akcji. Prawa część tabeli jest przycięta przez rozmiar powierzchni przeglądarki. |
| `raw-catalog-filtered.png` | 1025 × 577 | Rzeczywiste wyszukiwanie „oliwa”; interfejs pokazuje 2 wyniki. Prawa część tabeli jest przycięta. |
| `raw-catalog-restricted.png` | 1440 × 900 | Oba pola finansowe pokazują „Brak uprawnień”; nie ujawniają wartości. |
| `raw-catalog-not-ready.png` | 1440 × 900 | Rzeczywisty komunikat niedostępności modułu. Lista kategorii jest niedostępna; filtr dostawcy pozostaje w stanie „Sprawdzanie dostępu…”. |

Pozostałe obrazy pośrednie, w tym warianty 1025 × 577 i stan wygaśniętej sesji, pozostają w `artifacts/stage4a1/`; nie zastępują żadnego z sześciu wymaganych zrzutów.

## 5. Wyniki smoke testu

Test wykonano wyłącznie na syntetycznym adapterze:

| Funkcja | Wynik i obserwacja |
|---|---|
| Wyszukiwanie i debounce | **PASS** — wyszukiwanie „oliwa” zmienia wynik z 25 do 2 po debounce; przed upływem debounce wynik pozostaje bez zmian. |
| Filtr kategorii | **PASS** — kategoria olejów zwraca 3 pozycje. |
| Filtr dostawcy | **PASS** — syntetyczny dostawca zwraca 16 pozycji. |
| Sortowanie | **PASS** — SKU rosnąco i malejąco. |
| Paginacja | **PASS** — druga strona pokazuje 7 pozycji. |
| Rozmiar strony | **PASS** — wybór 50 pokazuje wszystkie 32 syntetyczne pozycje. |
| Selekcja między stronami i „zaznacz wszystkie” na bieżącej stronie | **PASS** — potwierdzono sumę 8 zaznaczeń. |
| Czyszczenie selekcji | **PASS**. |
| Eksport CSV | **PASS** — kontrolka eksportu używa demonstracyjnej odpowiedzi CSV. |
| Ograniczenia pól finansowych | **PASS** — brak wartości finansowych w restricted; interfejs pokazuje „Brak uprawnień”. |
| Wygaśnięcie sesji | **PASS** — obsłużono syntetyczne HTTP 401. |
| `RAW_MATERIALS NOT_READY` | **PASS z uwagą wizualną** — obsłużono syntetyczne HTTP 503 i komunikat niedostępności; pozostaje opisany wyżej stan sprawdzania filtra dostawcy. |

Pierwsze wywołanie odczytu stanu testowego miało niejednoznaczny selektor dwóch alertów. Ponowiony smoke test zakończył się powodzeniem; nie uznano nieudanego odczytu za PASS.

## 6. Testy, budowanie i lint

Polecenia uruchomiono z `frontend/`, poza `git diff --check`:

- `npm test -- --reporter=dot` — **PASS**, 4 pliki testowe, 22 testy.
- `npm run build` — **PASS**, `tsc -b` i build Vite.
- `npm run lint` — **PASS**, jedno ostrzeżenie Fast Refresh w niezmienionym `src/visualPreview.tsx:10`; brak błędów lint.
- `git diff --check` — **PASS**.

## 7. Dostarczenie do Google Drive

Użyto dostępnej, uwierzytelnionej karty przeglądarki Google Drive. Nie proszono o hasło, nie odczytywano tokenów/cookies i nie obchodzono uwierzytelniania. W folderze docelowym utworzono podfolder `VELTRA_STAGE_4A1_RAW_VISUAL_VERIFICATION`; przesłanie sześciu obrazów zakończyło się i zweryfikowano ich nazwy oraz identyfikatory w widoku folderu.

Folder: [VELTRA_STAGE_4A1_RAW_VISUAL_VERIFICATION](https://drive.google.com/drive/folders/144Hp3MWtdJoj6ApJBFRTOUcgsXjWbuc5)

| Plik | Zweryfikowany link |
|---|---|
| `raw-catalog-1920.png` | [Otwórz](https://drive.google.com/file/d/1UEkr1FoaHsPBZYLyZs17D3w0JWqaT8Q9/view) |
| `raw-catalog-1440.png` | [Otwórz](https://drive.google.com/file/d/1hgPAERqHFiD8dki4RygefSMR7dHwTEgi/view) |
| `raw-catalog-selection.png` | [Otwórz](https://drive.google.com/file/d/1Egi7AgnO4rA0ixTnmjCdSnwUzXSOGqFT/view) |
| `raw-catalog-filtered.png` | [Otwórz](https://drive.google.com/file/d/1BtY8fUxF3zemzxFnVOD1pA0t_iNPv3MX/view) |
| `raw-catalog-restricted.png` | [Otwórz](https://drive.google.com/file/d/1exODktf4A6eqbXp3YvpzFATddV95SnUS/view) |
| `raw-catalog-not-ready.png` | [Otwórz](https://drive.google.com/file/d/1J86aqIHkfNuh40dcm9NCE2PQHB3wJwFa/view) |

Ten raport został przesłany do tego samego podfolderu po zapisaniu; jego obecność została potwierdzona w wykazie plików. Link do raportu można otworzyć z folderu powyżej.

## 8. Git i zakres zmian

`git diff --stat` dla zmian śledzonych obejmował wyłącznie:

- `frontend/src/visualPreviewApi.ts`
- `visual-preview-receiver.mjs`

W `git status --short` są również lokalny raport odzyskiwania, niniejszy raport i wymagane zrzuty jako pliki nieśledzone. Nie zaobserwowano zmian backendu, migracji, instalacji ani niepowiązanych plików. Raportu odzyskiwania nie nadpisano. Nie wykonano commitu ani pushu.

## 9. Sprzątnięcie i działania niewykonane

- Zatrzymano wyłącznie procesy uruchomione dla tego zadania: Vite (PID 24518/24520) i odbiornik (PID 29203). Potwierdzono zamknięcie portów `127.0.0.1:4179` i `127.0.0.1:4180`.
- Usunięto dedykowany profil Chrome headless, sześć tymczasowych kopii do uploadu oraz pośrednie pliki zrzutów w `C:\Users\Public`. Pliki źródłowe w repozytorium i przesłane pliki Drive pozostawiono.
- Nie instalowano zależności; nie uruchamiano migracji; nie dotykano kontenerów ani usług instalacyjnych; nie łączono się z produkcyjnym API ani bazą.
- Nie wdrażano, nie commitowano, nie pushowano i nie aktywowano `RAW_MATERIALS`.

## 10. Pozostałe ograniczenie i rekomendacja

Przed uznaniem walidacji stanu niedostępności za całkowicie dopracowaną należy rozważyć, czy komponent ma resetować stan sprawdzania filtra dostawcy po przejściu katalogu do `unavailable`. Nie wprowadzono tej zmiany, ponieważ wykracza poza naprawę izolowanego harnessu. Pozostałe wymagane zrzuty i smoke test są dostępne lokalnie oraz w zweryfikowanym folderze Drive.

**Rekomendacja:** przejrzeć obserwację `NOT_READY` z właścicielem/koordynatorem; nie aktywować modułu ani nie rozpoczynać Stage 4B w ramach tego zadania.

**Plik lokalny (WSL):** `/home/veltra/projekty/veltra/VELTRA_STAGE_4A1_RAW_VISUAL_VERIFICATION_REPORT.md`  
**Plik lokalny (Windows):** `\\wsl.localhost\Ubuntu\home\veltra\projekty\veltra\VELTRA_STAGE_4A1_RAW_VISUAL_VERIFICATION_REPORT.md`  
**Google Drive:** folder [VELTRA_STAGE_4A1_RAW_VISUAL_VERIFICATION](https://drive.google.com/drive/folders/144Hp3MWtdJoj6ApJBFRTOUcgsXjWbuc5)
