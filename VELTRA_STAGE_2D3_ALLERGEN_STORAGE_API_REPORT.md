# VELTRA 2.0 — Stage 2D.3 — Raport implementacji API alergenów i przechowywania

**Tryb:** DEVELOPMENT ONLY  
**Status Stage 2C:** bez zmian, nadal niezaakceptowany  
**Status RAW_MATERIALS:** `NOT_READY`; moduł nie został aktywowany

## Zakres wdrożenia

Dodano:

- odczyt opublikowanych wersji słownika alergenów i definicji;
- odczyt deklaracji, atestacji, wariantów przechowywania i skrótu dokładnej rewizji;
- zarządzanie deklaracją alergenną i wariantami wyłącznie przez utworzenie nowej rewizji roboczej;
- jawne stany `UNKNOWN`, `DECLARED` oraz `NO_KNOWN_ALLERGENS`; brak pozycji nie oznacza braku alergenów;
- rozdzielenie `DIRECT` i `CROSS_CONTACT_POTENTIAL`;
- kontrolę opublikowanej wersji słownika i aktywnych definicji;
- polskie walidacje oraz wpis audytowy zapisywany atomowo z rewizją;
- nowe uprawnienia `RAW_MATERIALS_ALLERGEN_MANAGE` i `RAW_MATERIALS_STORAGE_MANAGE`. Nie nadano ich automatycznie istniejącym użytkownikom ani rolom.

Zatwierdzone i historyczne rewizje pozostają niezmienne. Odczyt historii wymaga istniejącego `RAW_MATERIALS_AUDIT_VIEW`. Trasy pozostają pod istniejącą bramką dostępności modułu. Nie dodano tras dokumentów.

## Pliki objęte Stage 2D.3

- `backend/app/Domain/RawMaterials/RawMaterialPermission.php`
- `backend/app/Domain/RawMaterials/RawMaterialService.php`
- `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`
- `backend/app/Http/Requests/RawMaterialAllergenDeclarationRequest.php`
- `backend/app/Http/Requests/RawMaterialStorageVariantsRequest.php`
- `backend/database/migrations/2026_11_06_000030_add_raw_material_stage2d_permissions.php`
- `backend/routes/api.php`
- `backend/lang/pl/messages.php`
- `backend/tests/Feature/RawMaterialStage2dApiTest.php`

Migracja 030 rozszerza ograniczenie dozwolonych wartości uprawnień. Została zastosowana wyłącznie do disposable PostgreSQL; odczyt katalogu potwierdził obecność migracji i obu nowych wartości w ograniczeniu. Migracje 028–029 również były zastosowane.

## Wyniki weryfikacji

| Weryfikacja | Wynik |
|---|---:|
| Testy Stage 2D.3 | **6/6 PASS**, 52 asercje |
| Pełna dozwolona regresja | **189 PASS**, 1523 asercje |
| Awarie / błędy / ostrzeżenia / pominięte | **0 / 0 / 0 / 0** |
| Formatowanie Pint | **PASS**, 9 plików |
| HTTP tras przy `RAW_MATERIALS = NOT_READY` | **503**, bramka nie została ominięta |

Pełną regresję uruchomiono w disposable PostgreSQL 17 / Redis 7.4 z filtrem wyłączającym dokładnie 17 metod zastrzeżonych Stage 2C. Nie uruchomiono, nie zmodyfikowano ani nie odtworzono tych scenariuszy. Zaliczone testy obejmują m.in. autoryzację organizacyjną, uprawnienia, semantykę stanów alergenów, typ ekspozycji, niezmienność rewizji, audyt transakcyjny, hashe v1/v2 i dotychczasową regresję funkcjonalną.

Testy domenowe przeszły. Testów HTTP zakończonych sukcesem nie deklaruje się: bramka gotowości prawidłowo zwraca `503`, ponieważ moduł pozostaje `NOT_READY`.

## Izolacja i ograniczenia

- Testy działały wyłącznie na oddzielnym projekcie Compose, wewnętrznej sieci i efemerycznym magazynie disposable PostgreSQL/Redis.
- Po testach oba disposable serwisy były zdrowe.
- Sześć oryginalnych usług VELTRA pozostało uruchomionych; backend, frontend, Mailpit, Redis i PostgreSQL raportowały zdrowie. Nie restartowano ani nie usuwano kontenerów.
- Nie użyto oryginalnych baz, wolumenów ani sekretów; nie wdrażano zmian.
- Nie uruchomiono 17 zastrzeżonych testów. Status Stage 2C nie uległ zmianie. VSEF pozostaje nieaktywne.

**Potwierdzone defekty:** brak w wykonanych testach.  
**Pozostała blokada:** rzeczywiste endpointy API pozostają niedostępne do czasu odrębnego, zatwierdzonego procesu zmiany gotowości `RAW_MATERIALS`. Ten raport nie stanowi zgody na taką zmianę ani na uruchomienie ograniczonych testów Stage 2C.
