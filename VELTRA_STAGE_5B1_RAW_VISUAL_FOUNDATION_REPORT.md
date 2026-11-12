# VELTRA_STAGE_5B.1 — RAW Frontend Visual Foundation

## Status: PARTIAL

### Cel i zakres

Ujednolicono warstwę wizualną istniejącego frontendu RAW zgodnie z zatwierdzonym kierunkiem globalnym VELTRA 2.0 i referencją RAW Dark 2. Zmiany ograniczono do prezentacji; podgląd używa danych i odpowiedzi syntetycznych.

### Zmiany

- `frontend/src/App.css` — tokeny theme oraz spójniejsze kolory, typografia, odstępy, obramowania, cienie, stany kontrolek, katalog, tabele, drawer i formularze.
- `frontend/src/index.css` — globalne tło i typografia oparte na tokenach.
- `frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx` — prezentacyjny badge stanu cyklu życia; bez zmiany tekstu ani logiki.
- `visual-preview-receiver.mjs` — ograniczony odbiór nazw zrzutów Stage 5B.1 dla lokalnego preview.

Nie zmieniano backendu, API, migracji, RBAC, logiki uprawnień, danych ani gotowości modułu. Zachowano osiem kolumn katalogu; potwierdzono osiem nagłówków tabeli w DOM.

### Weryfikacja

- `cd frontend && npm test -- --reporter=dot` — PASS, 11 plików testowych, 119 testów.
- `cd frontend && npm run build` — PASS, TypeScript i Vite.
- `cd frontend && npm run lint` — zakończony kodem 0; jedno ostrzeżenie Fast Refresh w niezmienionym `frontend/src/visualPreview.tsx:36:10` (`react(only-export-components)`).
- `git diff --check` — PASS.
- Przegląd wizualny wykonano na syntetycznym preview.

### Zrzuty

Zapisano i sprawdzono dziewięć plików w `artifacts/stage5b1/`:

| Widok | Plik | PNG |
|---|---|---:|
| Katalog — desktop | `raw-visual-catalog-desktop.png` | 908×2807 |
| Katalog — mobile | `raw-visual-catalog-mobile.png` | 375×3625 |
| Drawer szczegółów | `raw-visual-detail-drawer.png` | 908×577 |
| Formularz create | `raw-visual-create-form.png` | 876×531 |
| Formularz edit | `raw-visual-edit-form.png` | 876×531 |
| `NOT_READY` | `raw-visual-not-ready.png` | 908×846 |
| Forbidden / restricted | `raw-visual-forbidden.png` | 908×881 |
| Empty / no-results | `raw-visual-no-results.png` | 908×963 |
| Loading / searching | `raw-visual-searching.png` | 908×2807 |

Ograniczenie: zintegrowany renderer przeglądarki udostępniał faktyczną powierzchnię 923×577 px; po pasku przewijania zawartość viewportu desktop miała 908 px. Dlatego nie uzyskano zrzutu desktopowego 1440 px. Przy tej szerokości tabela zachowuje poziome przewijanie; wszystkie osiem kolumn pozostaje w DOM. Zrzut forbidden pokazuje główny komunikat „Brak dostępu”; syntetyczny podgląd nadal pokazuje sprawdzanie uprawnienia do filtra dostawcy.

### Preview, raport i stan projektu

- Preview działał podczas weryfikacji pod adresem `http://127.0.0.1:4178/visual-preview.html`; proces preview i odbiornik zrzutów zostały zatrzymane po wykonaniu zrzutów. Istniejącego serwisu pod `4179` nie zmieniano.
- Log preview zawierał powtarzające się ostrzeżenie React o zduplikowanym kluczu `00000000-0000-4000-8000-000000000101`. Po zatrzymaniu procesu powłoka zgłosiła kod wyjścia 1; port `4178` pozostał zamknięty.
- Raportu nie wysłano na Google Drive: w dostępnych narzędziach nie było autoryzowanej ścieżki uploadu.
- `RAW_MATERIALS` pozostaje `NOT_READY`. Nie wykonano wdrożenia, commita, pusha, migracji ani aktywacji.
- Wcześniejszy nieśledzony `VELTRA_STAGE_5A_VISUAL_ARCHITECTURE_AUDIT.md` zachowano bez zmian.
- Ze względu na brak pełnowymiarowego zrzutu desktopowego wynik etapu oznaczono jako **PARTIAL**. Dalsze prace wymagają decyzji właściciela.
