# VELTRA 2.0 — Stage 4B.1

## Raport panelu szczegółów surowca

**Status końcowy: PASS**

## Zakres i źródła

Dodano prawostronny panel szczegółów tylko do odczytu do istniejącego katalogu RAW. Implementacja korzysta z potwierdzonych kontraktów:

- `GET /api/v1/raw-materials/{material}` — `RawMaterialController::show` / `RawMaterialService::show`;
- `GET /api/v1/raw-materials/{material}/revisions` — `RawMaterialController::revisions` / `RawMaterialService::revisions`, wywoływany tylko wtedy, gdy odpowiedź szczegółów udostępnia `history_url`.

Szczegóły prezentują rewizję zwróconą przez serwer, stan cyklu życia, kategorię, jednostki, ilości, alergeny, aliasy i dozwolone metadane aktualizacji. Historia rewizji korzysta z oddzielnego grantu AUDIT_VIEW i nie jest pobierana, gdy backend nie udostępnił `history_url`. Kwoty są renderowane wyłącznie wtedy, gdy odpowiedź zawiera odpowiednie autoryzowane pola. Endpoint szczegółów nie zwraca powiązania dostawcy, dlatego panel go nie dopowiada.

## Zmiany

- Dodano walidowane typy i parsery odpowiedzi szczegółów oraz rewizji.
- Dodano panel z semantyką dialogu, początkowym fokusem, obsługą Escape i Tab, zamknięciem przyciskiem lub kliknięciem tła oraz przywróceniem fokusu.
- Wiersze katalogu otwierają panel z przycisku w komórce nazwy. Zachowano osiem stałych kolumn, selekcję, filtry i sortowanie.
- Obsłużono ładowanie, 403, neutralne 404, 401/419, błędy ogólne oraz 503 `RAW_MATERIALS_MODULE_UNAVAILABLE`. Po wygaśnięciu sesji lub niedostępności modułu dane katalogu i szczegółów są czyszczone. Anulowane żądania nie mogą odtworzyć zamkniętych lub nieaktualnych danych.
- Rozszerzono wyłącznie izolowany adapter demonstracyjny i odbiornik zrzutów. Dane w nim są syntetyczne; nie łączy się z backendem.

## Pliki

Zmiany Stage 4B.1:

- `frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx` — nowy panel;
- `frontend/src/rawMaterials/detail.ts` i `detail.test.ts` — typy i parsery;
- `frontend/src/rawMaterials/api.ts` — odczyt szczegółów i rewizji;
- `frontend/src/rawMaterials/CatalogTable.tsx`;
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx`;
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx`;
- `frontend/src/App.css`;
- `frontend/src/visualPreview.tsx` i `frontend/src/visualPreviewApi.ts`;
- `visual-preview-receiver.mjs` — allowlista i zapis artefaktów Stage 4B.1.

Zachowano zastane, niezatwierdzone prace Stage 4A.2, w tym `frontend/src/rawMaterials/CatalogFilters.tsx`, raport Stage 4A.2 i `artifacts/stage4a2/`. Pliki strony katalogu i jej testu zawierały już zmiany Stage 4A.2; nowe zmiany połączono z nimi bez cofania wcześniejszej pracy. Nie modyfikowano backendu ani migracji.

## Weryfikacja funkcjonalna i wizualna

Podgląd uruchomiono lokalnie na `127.0.0.1:4179`, z odbiornikiem PNG na `127.0.0.1:4180`. Chrome headless renderował rzeczywisty komponent React; ustawiono `--force-device-scale-factor=1`, rozmiary okna 1920×1080 lub 1440×900 i ograniczony budżet czasu. Odpowiedzi API były syntetyczne, a podgląd jawnie oznaczał dane demonstracyjne i `RAW_MATERIALS: NOT_READY`.

| Zrzut | Widok | Wymiary PNG | Google Drive |
|---|---|---:|---|
| `raw-catalog-detail-1920.png` | Otwarty panel, szeroki pulpit | 1920×1080 | [Otwórz](https://drive.google.com/file/d/1WFhwew3QMmUZ65F4g9e3uXcZoMSS4825/view) |
| `raw-catalog-detail-1440.png` | Otwarty panel, pulpit | 1440×900 | [Otwórz](https://drive.google.com/file/d/1IEZfUEfzu7bqJlentuxCoYIljyR0SgKv/view) |
| `raw-catalog-detail-restricted.png` | Panel bez niedozwolonych pól finansowych | 1440×900 | [Otwórz](https://drive.google.com/file/d/1nm96gqevreERNbn9YaMMa7uyLakSh4Rc/view) |
| `raw-catalog-detail-forbidden.png` | Odmowa dostępu 403 bez danych szczegółów | 1440×900 | [Otwórz](https://drive.google.com/file/d/1YQjmtdXeeNYpC1OPuHbgCxTLNy_LYAQ1/view) |
| `raw-catalog-detail-not-ready.png` | Stan `NOT_READY` | 1440×900 | [Otwórz](https://drive.google.com/file/d/1xjbbWwjlwBJwzoNK1ckVUELAAZkGo897/view) |

Wszystkie pięć PNG odczytano z dysku i zweryfikowano programowo pod kątem wymiarów. Podgląd wizualny potwierdził syntetyczny charakter danych, wyraźne oddzielenie panelu, brak pól finansowych w wariancie ograniczonym, brak danych po 403 oraz widoczny stan niedostępności modułu. Przy wysokości 900 px dłuższy panel przewija się wewnętrznie.

### Testy i kontrole

- `cd frontend && npm test -- --run src/rawMaterials/RawMaterialsCatalogPage.test.tsx` — PASS, 23 testy.
- `cd frontend && npm test` — PASS, 5 plików testowych, 38 testów.
- `cd frontend && npm run build` — PASS; TypeScript `tsc -b` i build Vite.
- `cd frontend && npm run lint` — PASS; pozostało jedno wcześniejsze, nieblokujące ostrzeżenie Fast Refresh w `frontend/src/visualPreview.tsx:10`.
- `git diff --check` — PASS.

Testy regresyjne obejmują otwarcie i zamknięcie dialogu, fokus, historię rewizji, brak nieprzyznanych kwot, błędy 403/404/401/419/503, ochronę przed spóźnionymi odpowiedziami oraz zachowanie selekcji i istniejących ograniczeń finansowych.

## Dostarczenie i ograniczenia

Raport i pięć zrzutów przesłano do uwierzytelnionego folderu Drive [VELTRA_STAGE_4B1_RAW_DETAIL_DRAWER](https://drive.google.com/drive/folders/1EsKR5_jVgwSXN1uL4KldWDdPQMAsTYff). Potwierdzono ich obecność i odczytano metadane plików z widoku folderu. Nie pobierano ani nie zapisywano haseł, tokenów ani cookies. Raport przesłano po zrzutach; jego odnośnik znajduje się w komunikacie końcowym.

Rzeczywista integracja z backendem nie była testowana, ponieważ `RAW_MATERIALS` pozostaje `NOT_READY`. Panel prezentuje wyłącznie dane i pola zwrócone przez autoryzowane API; uprawnienia, dostępność modułu i projekcja danych pozostają po stronie backendu.

## Środowisko i granice wykonania

Vite, odbiornik zrzutów i uruchomiony na potrzeby sprawdzenia Chrome zostały zakończone. Potwierdzono brak nasłuchu na portach 4179 i 4180 oraz usunięto wskazane tymczasowe profile Chrome i kopie robocze zrzutów. Zachowano wyłącznie artefakty w `artifacts/stage4b1/`.

`RAW_MATERIALS = NOT_READY`. Nie zmieniano backendu, bazy danych, migracji, RBAC ani instalacji; nie wykonywano wdrożenia, commitu ani pushu. Nie rozpoczęto Stage 4B.2.
