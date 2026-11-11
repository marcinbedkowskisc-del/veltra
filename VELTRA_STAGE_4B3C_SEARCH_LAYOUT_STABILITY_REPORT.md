# VELTRA 2.0 — Stage 4B.3C
## Stabilizacja układu katalogu RAW podczas wyszukiwania

**Status: PASS**

### Cel i przyczyna

Warunkowe akapity `.refresh-status` były renderowane w normalnym przepływie układu pomiędzy filtrami a tabelą. Ich wysokość i marginesy przesuwały tabelę w dół na czas żądania, a po jego zakończeniu tabela wracała na poprzednią pozycję.

### Zmiana

- Wskaźnik ładowania znajduje się teraz wewnątrz pola wyszukiwania, po prawej stronie. Jest pozycjonowany bezwzględnie, a pole stale rezerwuje dla niego miejsce, więc jego wymiary się nie zmieniają; tekst i natywna kontrolka czyszczenia nie nachodzą na spinner.
- Zamiast widocznego komunikatu nad tabelą używany jest stale obecny, wizualnie ukryty status `role="status"`, `aria-live="polite"`, `aria-atomic="true"`. Sam spinner ma `aria-hidden="true"`. Pole pozostaje aktywne podczas pobierania.
- Oczekujący stan obejmuje bieżące żądanie katalogu także przy odświeżeniu tego samego zapytania. `AbortController` i generacja żądania uniemożliwiają starej odpowiedzi wyczyszczenie stanu nowego żądania lub zastąpienie jego wyników. Zakończenie błędem również czyści wskaźnik.
- Wskaźnik korzysta ze wspólnego stanu odświeżania katalogu, zatem nie wstawia dodatkowej przestrzeni także przy resecie i innych żądaniach katalogu.
- Lokalny adapter preview opóźnia syntetyczne odpowiedzi wyszukiwania o 900 ms, aby wskaźnik był widoczny. Produkcyjny adapter, kontrakt API i jego timing nie zostały zmienione.

### Pliki zmienione w etapie

- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx`
- `frontend/src/rawMaterials/CatalogFilters.tsx`
- `frontend/src/App.css`
- `frontend/src/visualPreviewApi.ts`
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx`

### Weryfikacja przeglądarkowa

Sprawdzono rzeczywisty render lokalnego, izolowanego preview przed wyszukiwaniem, podczas oczekiwania i po załadowaniu wyników oraz na widoku mobilnym.

- Desktop, viewport 1440×900: górna krawędź tabeli pozostawała na **566,39 px** przed żądaniem, podczas niego i po nim. Pole wyszukiwania zachowało wymiar **234,59×39,19 px**.
- Mobile, viewport 390×844: górna krawędź tabeli pozostawała na **1642,33 px** przed, podczas i po żądaniu. Pole zachowało wymiar **309×39,19 px**. Szerokość dokumentu i obszaru klienta wynosiła 375 px — nie wystąpił poziomy overflow.
- W trakcie oczekiwania spinner był widoczny, pole pozostało używalne, a komunikat dostępności był obecny. Po zakończeniu żądania wskaźnik znikał. Wymiary tabeli mogą się zmienić, gdy zmienia się liczba lub zawartość wyników; pomiar dotyczy położenia jej górnej krawędzi.
- Zrzuty przedstawiają render przeglądarkowy. Desktopowe pliki mają 923×577 px, a mobilny 390×577 px; pomiary układu wykonano przy osobnych viewportach podanych powyżej.

Zrzuty:

- `artifacts/stage4b3c/stage4b3c-catalog-idle.png`
- `artifacts/stage4b3c/stage4b3c-catalog-search-pending.png`
- `artifacts/stage4b3c/stage4b3c-catalog-search-loaded.png`
- `artifacts/stage4b3c/stage4b3c-catalog-mobile-search-pending.png`

### Testy i kontrole

- `cd frontend && npm test -- src/rawMaterials/RawMaterialsCatalogPage.test.tsx src/visualPreviewApi.test.ts` — **2 pliki, 46 testów PASS**.
- `cd frontend && npm test` — **11 plików, 119 testów PASS**.
- `cd frontend && npm run build` — **PASS**, TypeScript i Vite zakończone kodem 0.
- `cd frontend && npm run lint` — **kod wyjścia 0**, dwa ostrzeżenia: istniejące `react(only-export-components)` w `src/visualPreview.tsx:36` oraz `react(set-state-in-effect)` w `src/rawMaterials/RawMaterialsCatalogPage.tsx:407`. Drugie dotyczy ustawienia stanu oczekującego przy uruchomieniu efektu żądania; wymaga ono odwzorowania także odświeżeń tego samego zapytania i resetów.
- `git diff --check` — **PASS**.
- `curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:4179/visual-preview.html` — **200**.

### Zakres i ograniczenia

- Brak zmian backendu, kontraktu/API, bazy danych, migracji, RBAC i gotowości modułu.
- `RAW_MATERIALS = NOT_READY`; preview używa wyłącznie jawnie oznaczonych danych i odpowiedzi syntetycznych.
- Preview pozostawiono działające pod adresem `http://localhost:4179/visual-preview.html`.
- Nie wykonano wdrożenia, commitu ani pushu. Nie znaleziono zatwierdzonej integracji uploadu Google Drive; raport i zrzuty pozostają lokalnie.
- W katalogu pozostają niezacommitowane zmiany wcześniejszych etapów 4B.2B–4B.3B. Zachowano je bez zmian.

### Git — `git status --short` po etapie

```text
 M .gitignore
 M frontend/src/App.css
 M frontend/src/rawMaterials/CatalogFilters.tsx
 M frontend/src/rawMaterials/RawMaterialCreateForm.test.tsx
 M frontend/src/rawMaterials/RawMaterialCreateForm.tsx
 M frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx
 M frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx
 M frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx
 M frontend/src/rawMaterials/api.test.ts
 M frontend/src/rawMaterials/api.ts
 M frontend/src/visualPreview.tsx
 M frontend/src/visualPreviewApi.ts
 M visual-preview-receiver.mjs
?? VELTRA_STAGE_4B2B_I_RAW_EDIT_FORM_IMPLEMENTATION_REPORT.md
?? VELTRA_STAGE_4B3A_INTERACTIVE_PREVIEW_REPORT.md
?? VELTRA_STAGE_4B3B_CATALOG_UX_FIX_REPORT.md
?? VELTRA_STAGE_4B3C_SEARCH_LAYOUT_STABILITY_REPORT.md
?? artifacts/stage4b2b/
?? artifacts/stage4b3b/
?? artifacts/stage4b3c/
?? frontend/src/rawMaterials/RawMaterialEditForm.test.tsx
?? frontend/src/rawMaterials/RawMaterialEditForm.tsx
?? frontend/src/visualPreviewApi.test.ts
?? frontend/vite.interactive-preview.config.ts
```
