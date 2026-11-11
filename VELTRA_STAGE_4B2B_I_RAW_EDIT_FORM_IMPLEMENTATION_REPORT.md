# VELTRA 2.0 — Stage 4B.2B-I

## Raport implementacji bezpiecznego formularza edycji surowca

**Status:** PASS — implementacja i weryfikacja frontendu zakończone  
**Gotowość modułu:** `RAW_MATERIALS = NOT_READY`  
**Zakres:** frontend, testy i syntetyczny podgląd; bez zmian backendu

## 1. Podsumowanie

Dodano formularz edycji podstawowych danych istniejącego surowca. Formularz pobiera bieżące dane z endpointu edytora, nie modyfikuje identyfikatora surowca ani SKU, waliduje pełny zestaw pól edytowalnych i wysyła wersjonowany `PATCH` z niezmienionym tokenem `If-Match`.

Edycja jest dostępna wyłącznie po spełnieniu obu bramek: globalne capabilities muszą zezwalać na `actions.edit`, a `available_actions` rekordu musi zawierać `edit`. Uprawnienia i wersja są ponownie sprawdzane przed pobraniem edytora oraz bezpośrednio przed zapisem. Nie zmieniano API backendu ani gotowości modułu; podgląd oraz odpowiedzi API użyte do zrzutów są syntetyczne.

## 2. Autoryzacja i pobieranie danych

- Akcja edycji pojawia się wyłącznie, gdy bieżący kontekst ma `actions.edit === true`, a odpowiedź `GET /{id}/completion` zawiera `edit` w `available_actions`.
- Przed otwarciem formularza frontend odświeża capabilities i completion, a następnie pobiera `GET /{id}/editor`. Przed zapisem ponownie sprawdza capabilities oraz completion i porównuje identyfikator rewizji oraz nieprzezroczysty token wersji.
- Dane wejściowe formularza pochodzą z odpowiedzi edytora. Nie są odgadywane na podstawie listy ani szczegółów rekordu.
- Parser odpowiedzi odrzuca niekompletne lub niespójne dane, w tym błędne aliasy, jednostki, bazową jednostkę i wymiary przeliczeń.

## 3. Zapis i obsługa wyników

- Zapis używa pełnego zestawu edytowalnych danych podstawowych i `If-Match`; identyfikatory oraz token są wysyłane bez modyfikacji.
- Operacja tworzy nową rewizję roboczą. Dla aktywnego surowca zatwierdzona rewizja pozostaje obowiązująca do odrębnego zatwierdzenia.
- SKU, identyfikator mastera, alergeny, przechowywanie i dokumenty nie są edytowane w tym formularzu.
- Błąd walidacji jest prezentowany przy polach. Przy konflikcie 409 lokalne zmiany pozostają do czasu jawnej decyzji o odświeżeniu.
- Przy niejednoznacznym wyniku zapisu formularz nie ponawia `PATCH`; odczytuje stan serwera i zachowuje lokalne wartości do rozstrzygnięcia.
- Zmiana kontekstu lokalizacji zamyka formularz i szczegóły; spóźnione odpowiedzi są ignorowane. Żądanie zapisu, które zostało już wysłane, nie jest przedstawiane jako anulowane.
- Po sukcesie odświeżane są katalog i szczegóły tego samego materiału, bez resetowania filtrów i strony.

## 4. Ograniczenia kontraktu

- Istniejących ilości nominalnej i użytkowej nie można wyczyścić przez ten kontrakt. Formularz blokuje takie czyszczenie; brak obu wartości przy PATCH zachowuje wartości istniejące. Nie zmieniano backendu, aby obejść to ograniczenie.
- Pola alergenów i przechowywania wymagają osobnych uprawnień i endpointów i nie należą do tego formularza.
- Nie dodano tras, migracji, uprawnień, grantów ani workflow backendowego.
- Nie wykonano uploadu do Google Drive: w tej sesji nie był dostępny zatwierdzony workflow uploadu. Raport i zrzuty pozostają w repozytorium.

## 5. Zmienione pliki

- `frontend/src/rawMaterials/api.ts` — typy i fail-closed parsery capabilities, completion, editor oraz odpowiedzi PATCH.
- `frontend/src/rawMaterials/RawMaterialEditForm.tsx` — formularz, walidacja, stan dirty, ponowna autoryzacja i obsługa konfliktu/niepewnego zapisu.
- `frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx` — dwupoziomowa bramka akcji, integracja formularza i odświeżenie szczegółów.
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx` — odświeżenie katalogu i zachowanie kontekstu rekordu.
- `frontend/src/rawMaterials/api.test.ts` — testy kontraktu API i parserów.
- `frontend/src/rawMaterials/RawMaterialEditForm.test.tsx` — testy formularza, walidacji, autoryzacji i wyników zapisu.
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx` — testy integracji i regresji katalogu.
- `frontend/src/App.css` — style dialogu edycji.
- `frontend/src/visualPreview.tsx`, `frontend/src/visualPreviewApi.ts`, `visual-preview-receiver.mjs` — izolowany podgląd i zapis syntetycznych zrzutów.
- `artifacts/stage4b2b/` — zrzuty wymienione poniżej.

Nie zmieniono logiki tworzenia surowca; testy regresji CREATE przechodzą.

## 6. Weryfikacja

| Zakres | Wynik |
|---|---:|
| Pełny frontend suite | PASS — 108 testów w 10 plikach |
| TypeScript i build Vite | PASS |
| Lint | PASS — jedno ostrzeżenie `react(only-export-components)` w `src/visualPreview.tsx` |
| `git diff --check` | PASS |
| Zrzuty syntetyczne | PASS — HTTP 201 dla wszystkich zapisów |

Ostrzeżenie lint dotyczy eksportów komponentów i nie jest błędem. Build wygenerował frontend bez błędów.

## 7. Syntetyczne zrzuty ekranu

Zrzuty obejmują akcję, ładowanie, wstępnie wypełniony formularz, niezapisane zmiany, walidację, zapis, sukces, konflikt, `NOT_READY`, brak autoryzacji, rekord nieedytowalny oraz widok mobilny. Zrzuty desktopowe mają wymiary 1440 × 900 px; mobilny 390 × 844 px.

- `artifacts/stage4b2b/raw-material-edit-action.png`
- `artifacts/stage4b2b/raw-material-edit-loading.png`
- `artifacts/stage4b2b/raw-material-edit-prefilled.png`
- `artifacts/stage4b2b/raw-material-edit-unsaved.png`
- `artifacts/stage4b2b/raw-material-edit-validation.png`
- `artifacts/stage4b2b/raw-material-edit-saving.png`
- `artifacts/stage4b2b/raw-material-edit-success.png`
- `artifacts/stage4b2b/raw-material-edit-conflict.png`
- `artifacts/stage4b2b/raw-material-edit-not-ready.png`
- `artifacts/stage4b2b/raw-material-edit-unauthorized.png`
- `artifacts/stage4b2b/raw-material-edit-noneditable.png`
- `artifacts/stage4b2b/raw-material-edit-mobile.png`

Wszystkie odpowiedzi podglądu pochodzą z syntetycznego adaptera; rzeczywisty backend pozostawał wyłączony.

## 8. Granice wykonania

Nie wykonano wdrożenia, migracji, zmian danych ani konfiguracji backendu, commitowania ani pushowania. `RAW_MATERIALS` pozostaje **NOT_READY**.
