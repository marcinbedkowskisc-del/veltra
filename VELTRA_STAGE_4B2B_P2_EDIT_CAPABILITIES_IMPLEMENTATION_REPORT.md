# VELTRA 2.0 — Stage 4B.2B-P2

## Raport implementacji capabilities EDIT

**Status: PASS**

Rozszerzono istniejący kontrakt capabilities `RAW_MATERIALS` o EDIT. Nie implementowano formularza ani akcji edycji w UI. Zachowano dotychczasowe zachowanie CREATE, izolację kontekstu i gotowości oraz granice RBAC.

## 1. Kontrakt odpowiedzi

`GET /api/v1/security/raw-materials/capabilities` zachowuje dotychczasowe pola i dodaje wymagane pola boolowskie:

```json
{
  "data": {
    "module": "RAW_MATERIALS",
    "location_id": "00000000-0000-4000-8000-000000000001",
    "readiness": "NOT_READY",
    "available": false,
    "permissions": {
      "create": true,
      "edit": true
    },
    "actions": {
      "create": false,
      "edit": false
    }
  }
}
```

Wartości w przykładzie przedstawiają efektywne granty CREATE i EDIT przy module niedostępnym.

## 2. Ewaluacja EDIT i gotowość

- `permissions.edit` wynika wyłącznie z efektywnego grantu `RAW_MATERIALS_EDIT`, odczytywanego przez istniejący `RawMaterialAuthorization` resolver. Nie jest wywnioskowane z CREATE, VIEW, roli ani surowych przypisań.
- Zachowane są istniejące reguły grantów efektywnych, w tym delegowanie, wygaśnięcie i cofnięcie.
- `actions.edit` jest prawdziwe wyłącznie przy efektywnym EDIT, dozwolonym primary catalog context oraz dostępności modułu.
- Przy `NOT_READY` endpoint nadal zwraca HTTP 200 dla poprawnego kontekstu, grant może pozostać prawdziwy w `permissions.edit`, a `actions.edit` jest fałszywe.
- Nie zmieniono gotowości `RAW_MATERIALS`; pozostaje **NOT_READY**.
- Zachowano `Cache-Control: private, no-store`.

## 3. Autoryzacja rekordu w następnym etapie

Globalne `actions.edit` nie uprawnia samo w sobie do edycji konkretnego rekordu. Przyszły frontend musi wymagać jednocześnie:

1. `actions.edit === true` dla aktualnego kontekstu.
2. `available_actions` rekordu z `GET /api/v1/raw-materials/{material}/completion` zawierającego `edit`.

Istniejący `completion.available_actions` pozostaje autorytatywnym warunkiem edytowalności rekordu. Nie dodano drugiego resolvera, endpointu ani flagi `editable` do innych odpowiedzi szczegółowych; nie dodano też UI edycji.

## 4. Brak efektów ubocznych GET

Test endpointu porównuje przed i po żądaniu liczbę grantów, zdarzeń grantów, audytów biznesowych, surowców, rezerwacji SKU, liczników SKU i przydziałów SKU. Wszystkie wartości pozostają niezmienione. GET nie tworzy rekordów, nie rezerwuje SKU, nie zapisuje audytów ani grantów.

## 5. Zmienione pliki

- `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php`
- `backend/app/Http/Controllers/Api/V1/RawMaterialCapabilitiesController.php`
- `backend/tests/Feature/RawMaterialCapabilitiesApiTest.php`
- `frontend/src/rawMaterials/api.ts`
- `frontend/src/rawMaterials/api.test.ts`
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx`
- `frontend/src/visualPreviewApi.ts`
- `VELTRA_STAGE_4B2B_P2_EDIT_CAPABILITIES_IMPLEMENTATION_REPORT.md`

Wcześniejszy, nieśledzony raport audytu `VELTRA_STAGE_4B2B_P_RAW_EDIT_CONTRACT_AUDIT.md` został zachowany bez zmian.

## 6. Wyniki backendu

Testy wykonano na odizolowanej bazie PostgreSQL i świeżym obrazie runnera zbudowanym z bieżącego katalogu `backend` według repozytoryjnego Dockerfile. Skróty SHA-256 źródeł autoryzacji, kontrolera i testu capabilities były identyczne na hoście i w runnerze.

| Zakres | Wynik |
|---|---:|
| `RawMaterialCapabilitiesApiTest.php` | PASS — 19 testów, 151 asercji |
| `RawMaterialPermissionBootstrapTest` i `PermissionDelegationTest` | PASS — 20 testów, 219 asercji |
| Wybrane regresje `RawMaterialsTest.php` | PASS — 2 testy, 13 asercji |
| Bramka NOT_READY `RawMaterialStage2dApiTest.php` | PASS — 1 test, 10 asercji |
| **Łącznie** | **PASS — 42 testy, 393 asercje** |

Pokryto m.in. grant EDIT, VIEW bez EDIT, CREATE bez EDIT, EDIT bez CREATE, brak grantu RAW, wygasły/cofnięty/delegowany EDIT, cofnięte źródło delegacji, primary i secondary context, nieaktywne członkostwo, NOT_READY, READY przy lokalnej niedostępności, izolację organizacji/lokalizacji, regresje CREATE, nagłówki cache, nieuwierzytelnione żądanie oraz brak skutków ubocznych.

Przy pierwszym uruchomieniu migracja testowej bazy została prawidłowo odrzucona dla roli aplikacyjnej bez praw zapisu. Ponowiono ją istniejącą rolą migratora wyłącznie w efemerycznej bazie testowej. Początkowe błędy test fixture’ów zostały poprawione; końcowe wyniki powyżej są zielone.

## 7. Wyniki frontendu

| Zakres | Wynik |
|---|---:|
| Testy ukierunkowane adaptera i katalogu | PASS — 43 testy |
| Pełny frontend suite | PASS — 80 testów w 9 plikach |
| TypeScript i build Vite | PASS |
| Lint | PASS — jedno ostrzeżenie `react(only-export-components)` w istniejącym `src/visualPreview.tsx`, bez błędów |

Testy adaptera obejmują EDIT true/false, NOT_READY, odpowiedzi brakujące lub niespójne oraz istniejącą semantykę błędów API. Testy katalogu zachowują CREATE, sprawdzają unieważnianie odpowiedzi po zmianie kontekstu i gwarantują, że globalne EDIT nie ujawnia formularza, nie wysyła żądania edycji ani nie rezerwuje SKU.

## 8. CREATE, zgodność i ograniczenia

- Dotychczasowa semantyka CREATE i formularz tworzenia Stage 4B.2A zostały zachowane; testy regresji CREATE przechodzą.
- Parser wymaga kompletnych pól EDIT, odrzuca błędne odpowiedzi fail-closed, nie domyśla się grantu i nadal wiąże odpowiedź z oczekiwaną lokalizacją. Żądania capabilities nie są przechowywane w browser storage ani cache; ochrona przed spóźnionymi odpowiedziami pozostaje aktywna.
- Syntetyczna odpowiedź preview zawiera jawne wartości EDIT `false`.
- Nie dodano tras, migracji, uprawnień użytkownikom ani workflow edycji. Formularz edycji pozostaje poza zakresem.
- Podczas inspekcji nie znaleziono `.github/copilot-instructions.md` w repozytorium.
- Upload raportu na Google Drive nie został wykonany: nie jest dostępny zatwierdzony workflow uploadu w tej sesji. Raport zachowano lokalnie.

## 9. Granice wykonania

Nie wykonano wdrożenia, nie zmieniono instalacyjnej bazy ani kontenerów, nie dodano migracji schematu, nie zmieniono grantów ani danych, nie utworzono commita i nie wykonano pushu. Istniejące migracje uruchomiono wyłącznie w efemerycznej bazie testowej. Poprzednie etapy i ich zaakceptowana funkcjonalność zostały zachowane. `RAW_MATERIALS` pozostaje **NOT_READY**.

Etap 4B.2B-I nie został rozpoczęty.
