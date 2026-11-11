# Stage 4B.3B — poprawki UX katalogu RAW

**Status: PASS**

## Cel i zakres

Naprawiono przewijanie izolowanego, syntetycznego preview, dodano reset filtrów katalogu oraz doprecyzowano objaśnienia aliasów, jednostki bazowej i przeliczników w formularzach CREATE/EDIT. Zmiany ograniczono do frontendu i dowodów preview.

## Zmiany

- Preview nie blokuje przewijania dokumentu stałą wysokością ani `overflow: hidden`.
- „Wyczyść filtry” czyści wyszukiwanie, kategorię, dostawcę, status, jednostkę i stronę. Zachowuje sortowanie, liczbę pozycji na stronę i kontekst lokalizacji; przy domyślnych filtrach nie wysyła zbędnego żądania.
- Formularze wyjaśniają zastosowanie aliasów, jednostki bazowej, przeliczeń serwerowych i ręcznych oraz znaczenie `pc` jako sztuki, a nie opakowania.
- Dodano testy resetu, paginacji, wielu filtrów, ochrony przed spóźnioną odpowiedzią i objaśnień formularzy.

## Weryfikacja

- `cd frontend && npm test -- src/rawMaterials/RawMaterialsCatalogPage.test.tsx src/rawMaterials/RawMaterialCreateForm.test.tsx src/rawMaterials/RawMaterialEditForm.test.tsx` — 3 pliki, 70 testów PASS.
- `cd frontend && npm test` — 11 plików, 116 testów PASS.
- `cd frontend && npm run build` — PASS (TypeScript i Vite).
- `cd frontend && npm run lint` — PASS; jedno ostrzeżenie Fast Refresh w `src/visualPreview.tsx:36`.
- `git diff --check` — PASS.
- Preview odpowiadał HTTP 200 na `http://localhost:4179/visual-preview.html`.
- W emulacji mobilnej: viewport 390×844, szerokość dokumentu 390 px. Gest dotykowy przewinął stronę (`scrollY` 0 → 538), bez poziomego overflow.
- Zrzut mobilny ma dokładnie 390×844 px; zrzuty desktopowe mają 1440×900 px.

Wstępne uruchomienia komend npm z katalogu głównego zakończyły się `ENOENT`, ponieważ manifest znajduje się w `frontend/`. Powtórzenie z poprawnego katalogu zakończyło się powodzeniem. Testów backendu nie uruchamiano — nie zmieniano backendu.

## Dowody

Wszystkie zrzuty przedstawiają wyłącznie syntetyczne dane preview:

- `artifacts/stage4b3b/stage4b3b-catalog-main-scrolling.png`
- `artifacts/stage4b3b/stage4b3b-catalog-bottom-pagination.png`
- `artifacts/stage4b3b/stage4b3b-catalog-multiple-filters.png`
- `artifacts/stage4b3b/stage4b3b-catalog-filters-cleared.png`
- `artifacts/stage4b3b/stage4b3b-create-conversions.png`
- `artifacts/stage4b3b/stage4b3b-edit-conversions.png`
- `artifacts/stage4b3b/stage4b3b-catalog-mobile.png`

## Granice i stan

- Brak synchronizacji filtrów katalogu z URL.
- Bez zmian backendu, bazy danych, migracji, wdrożenia ani operacji na danych instalacyjnych.
- `RAW_MATERIALS = NOT_READY`; gotowość nie została zmieniona.
- Odbiornik screenshotów jest pomocniczy i zostanie zatrzymany; preview na porcie 4179 pozostaje uruchomione.
- Nie utworzono commitu ani nie wykonano push.
- Istniejące niezacommitowane prace wcześniejszych etapów zachowano.
