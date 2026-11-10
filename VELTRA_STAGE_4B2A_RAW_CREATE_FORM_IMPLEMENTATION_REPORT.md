# VELTRA 2.0 — Stage 4B.2A

## Raport implementacji formularza tworzenia RAW_MATERIALS

**Status: PASS — implementacja, testy i syntetyczna weryfikacja wizualna zakończone.**

`RAW_MATERIALS` pozostaje **NOT_READY**. Testowy podgląd używał jawnie syntetycznych capabilities i odpowiedzi API; nie wykonywał operacji na backendzie ani danych instalacyjnych.

## 1. Zakres i wynik

Zaimplementowano formularz tworzenia szkicu surowca, zintegrowany z katalogiem i istniejącym panelem szczegółów. Akcja tworzenia jest dostępna wyłącznie po świeżym potwierdzeniu `actions.create === true` dla bieżącej lokalizacji; uprawnienia są ponownie pobierane przed otwarciem formularza. Brak lub błąd capabilities blokuje akcję.

Formularz zawiera wymagane pola kategorii, nazwy i jednostki bazowej oraz opcjonalne: opis, ilości, aliasy i przeliczniki. Walidacja i komunikaty są po polsku. Nie udostępnia edycji SKU ani identyfikatora, danych finansowych, alergenów ani dostawcy.

Mapowanie żądania tworzenia odpowiada kontraktowi backendu: `categoryId` → `category_id`, `name` → `name`, `baseUnitCode` → `base_unit_code`, `description` → `description`, ilości → `nominal_quantity` i `usable_quantity`, a aliasy i przeliczniki → `aliases` i `conversions`. Walidowane są m.in. wymagane wartości, dozwolone jednostki, precyzja ilości, spójność ilości, aliasy i reguły konwersji.

## 2. Zapis i obsługa błędów

- SKU rezerwuje serwer dopiero po lokalnej walidacji, przez `POST /api/v1/raw-materials/sku-reservations` z `Idempotency-Key`. Samo otwarcie formularza nie powoduje rezerwacji.
- Utworzenie szkicu używa `POST /api/v1/raw-materials` z identyfikatorem rezerwacji. Formularz nie generuje SKU po stronie klienta.
- Podwójne wysłanie jest blokowane. Przy niejednoznacznym wyniku ponowienie używa tego samego klucza/rezerwacji i identycznego payloadu; formularz nie wykonuje ślepego ponowienia z nowymi danymi.
- Obsłużono stany walidacji, konfliktu, odmowy dostępu, wygaśnięcia sesji, niedostępności modułu, błędów serwera/sieci i niepewnego wyniku.
- Testy regresyjne obejmują odpowiedzi HTTP 401/419, 403, 409, 422 i 503 oraz bramkowanie capabilities, brak dostępu w stanie `NOT_READY`, zmianę lokalizacji, idempotencję, walidację, zapis sukcesu i regresje katalogu.
- Po sukcesie interfejs używa identyfikatora i stanu zwróconych przez API, informuje o utworzeniu szkicu, odświeża katalog i pozwala otworzyć szczegóły. Nie zatwierdza ani nie publikuje rekordu.
- Zmiana kontekstu lokalizacji zamyka formularz i odrzuca jego stan; odpowiedzi po odmontowaniu nie aktualizują UI.

## 3. Zmiany w tym etapie

- `frontend/src/api.ts` — zachowanie poprawnie sparsowanych błędów walidacji API.
- `frontend/src/rawMaterials/api.ts` — typy i parsery capabilities, rezerwacji SKU i utworzenia oraz istniejące wywołania API.
- `frontend/src/rawMaterials/create.ts` — pola, reguły walidacji i mapowanie payloadu.
- `frontend/src/rawMaterials/RawMaterialCreateForm.tsx` — formularz, dostępność klawiaturowa, walidacja, stany zapisu i bezpieczne ponowienie.
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx` — bramkowanie akcji capabilities, odświeżenie uprawnień przed otwarciem, integracja z katalogiem i szczegółami.
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx`
- `frontend/src/rawMaterials/RawMaterialCreateForm.test.tsx`
- `frontend/src/rawMaterials/create.test.ts`
- `frontend/src/rawMaterials/createApi.test.ts`
- `frontend/src/App.css` — style formularza i responsywność.
- `frontend/src/visualPreview.tsx`, `frontend/src/visualPreviewApi.ts` — wyłącznie syntetyczne scenariusze podglądu; poprawiono generator UUID demonstracyjnej rezerwacji, który wcześniej zwracał błędny format i blokował przejście do zapisu.
- `visual-preview-receiver.mjs` — dozwolone nazwy zrzutów Stage 4B.2A i katalog docelowy `artifacts/stage4b2a/`.

Nie zmieniano implementacji backendu, kontraktu API, RBAC ani migracji w ramach tego etapu. Istniejące niezatwierdzone zmiany innych etapów zachowano.

## 4. Testy i kontrole

Polecenia uruchomiono w katalogu `frontend/`, poza `git diff --check`, który uruchomiono w katalogu repozytorium:

| Polecenie | Wynik |
|---|---|
| `npm test -- src/rawMaterials/RawMaterialCreateForm.test.tsx src/rawMaterials/create.test.ts src/rawMaterials/createApi.test.ts src/rawMaterials/RawMaterialsCatalogPage.test.tsx` | PASS — 4 pliki, 54 testy |
| `npm test` | PASS — 9 plików, 74 testy |
| `npm run build` | PASS — `tsc -b` i build Vite |
| `npm run lint` | PASS, jeden nieblokujący warning |
| `git diff --check` | PASS |

Warning lint dotyczy znanego wcześniej ostrzeżenia Fast Refresh w `frontend/src/visualPreview.tsx` (`react(only-export-components)`). Ostrzeżenie nadal występuje; nie wprowadzano niezwiązanego refaktoru w celu jego usunięcia.

## 5. Podgląd i zrzuty

Podgląd działał na `127.0.0.1:4179`, a odbiornik PNG wyłącznie na `127.0.0.1:4180`. Zrzuty zostały wykonane z uruchomionego komponentu React przez przeglądarkę. Odpowiedzi API, capabilities i dane zapisu pochodziły z lokalnego adaptera demonstracyjnego; nie są dowodem operacji na żywym backendzie.

| Plik | Stan | Wymiary |
|---|---|---|
| `artifacts/stage4b2a/raw-material-create-empty.png` | pusty formularz | 1440 × 900 |
| `artifacts/stage4b2a/raw-material-create-filled.png` | formularz wypełniony syntetycznymi danymi | 1440 × 900 |
| `artifacts/stage4b2a/raw-material-create-validation.png` | błędy wymaganych pól | 1440 × 900 |
| `artifacts/stage4b2a/raw-material-create-saving.png` | stan zapisywania szkicu | 1440 × 900 |
| `artifacts/stage4b2a/raw-material-create-success.png` | potwierdzenie syntetycznego sukcesu | 1440 × 900 |
| `artifacts/stage4b2a/raw-material-create-not-ready.png` | `NOT_READY`; kontrolki katalogu nieaktywne | 1440 × 900 |
| `artifacts/stage4b2a/raw-material-create-unauthorized.png` | brak akcji tworzenia bez uprawnienia | 1440 × 900 |
| `artifacts/stage4b2a/raw-material-create-mobile.png` | formularz w wąskim widoku | 390 × 844 |

Wymiary odczytano z nagłówków rzeczywistych plików PNG. W widokach desktopowych device scale factor wynosił 1; w widoku mobilnym viewport miał 390 × 844. Zrzuty mobilne pochodzą z tego samego rzeczywistego komponentu i potwierdzają zastosowanie układu responsywnego.

## 6. Google Drive

W tej sesji nie była dostępna zatwierdzona integracja przesyłania Google Drive. Nie używano haseł, tokenów, cookies ani alternatywnej ścieżki uwierzytelniania; upload i odczyt zwrotny nie zostały wykonane.

Raport lokalny:

- Linux: `/home/veltra/projekty/veltra/VELTRA_STAGE_4B2A_RAW_CREATE_FORM_IMPLEMENTATION_REPORT.md`
- Windows: `\\wsl.localhost\Ubuntu\home\veltra\projekty\veltra\VELTRA_STAGE_4B2A_RAW_CREATE_FORM_IMPLEMENTATION_REPORT.md`

Zrzuty pozostają w `/home/veltra/projekty/veltra/artifacts/stage4b2a/` i mogą zostać ręcznie przesłane do autoryzowanego folderu raportowego.

## 7. Stan repozytorium i granice wykonania

Drzewo robocze pozostaje zmienione i zawiera wcześniejsze, niezatwierdzone zmiany z innych etapów. Nie resetowano, nie czyszczono ani nie nadpisywano tych zmian. `git diff --check` zakończył się powodzeniem.

Nie wykonano commita, pushu, wdrożenia ani migracji. Nie zmieniano danych instalacyjnych ani kontenerów instalacyjnych. Żądania tworzenia w podglądzie były symulowane i nie trafiały do backendu.

Po weryfikacji zatrzymano wyłącznie tymczasowe procesy Vite (PID 173633) i odbiornika zrzutów (PID 173598); porty 4179 i 4180 przestały odpowiadać.

**Potwierdzenie końcowe: `RAW_MATERIALS = NOT_READY`. Stage 4B.2B nie został rozpoczęty.**
