# VELTRA 2.0 — Raport standaryzacji SKU

**Data:** 6 listopada 2026 r.  
**Zakres:** DEVELOPMENT  
**Wynik:** wspólna podstawa alokacji SKU i generowanie `RAW` zaimplementowane; kontrakt importu katalogu pozostaje propozycją, nie został zatwierdzony.

## Podsumowanie

Dodano wspólny mechanizm alokacji numerów biznesowych dla prefiksów `RAW`, `INT`, `PRD` i `MEN`, z niezależnym licznikiem dla każdej organizacji i kategorii. Nowe surowce otrzymują `RAW-…`. Dotychczasowe kody `SUR-…` nie są zmieniane i pozostają obsługiwane jako historyczna tożsamość istniejących surowców.

SKU jest niezmiennym kodem biznesowym, odrębnym od wewnętrznego UUID. Nie dodano domen INT/PRD/MEN ani pełnego importera katalogu. Zmiany Stage 2E.3 dotyczące eksportu pozostawiono; eksport nadal odczytuje faktycznie zapisany kod i nie generuje go na nowo.

## Zmiany implementacyjne

### Wspólny alokator

- `backend/app/Domain/Sku/SkuPrefix.php` definiuje prefiksy `RAW`, `INT`, `PRD`, `MEN` oraz kanoniczne formatowanie i parsowanie kodów `PREFIX-000001` (od 6 do 9 cyfr).
- `backend/app/Domain/Sku/SkuAllocationService.php` udostępnia alokację następnego numeru i rezerwację importowanego SKU. Wymaga istniejącej transakcji biznesowej i wiąże podpisany zakres mutacji `sku.allocate` z aktorem oraz UUID podmiotu.
- Alokacja blokuje wiersz licznika `FOR UPDATE`; klucz organizacja/prefiks i ograniczenia unikalności rejestru chronią przed kolizją przy równoległych operacjach.
- `reserveImported()` zachowuje dokładny kod, odrzuca istniejące przypisanie i podnosi licznik do wartości importowanej, jeśli jest większa. Nie użyty niższy numer może zostać zarezerwowany bez cofania licznika. Generator pozostaje powyżej wysokiego punktu i nie powtórzy zarezerwowanego numeru.
- Rejestr przypisań jest append-only. Kody i numery są unikalne w organizacji; prefix i typ encji muszą być zgodne, z wyjątkiem zachowanych `SUR` typu `RAW`.

### Migracja forward-only

`backend/database/migrations/2026_11_06_000035_standardize_business_sku_allocation.php`:

- przemianowuje `raw_material_code_counters` na `organization_sku_counters` i rozszerza zakres prefiksów;
- rozszerza ograniczenia kodu surowca i wiersza importu cen na `RAW-…` oraz `SUR-…`;
- tworzy `organization_sku_assignments` z unikalnością tenantową, typem źródła `GENERATED`/`IMPORTED`/`LEGACY` i niezmiennością;
- przewiduje rejestrację istniejących kodów `SUR` jako `LEGACY` oraz zachowanie ich licznika;
- dodaje walidację, spójność licznika z najwyższym przypisanym numerem, kontrolę podpisanego kontekstu i odmowę tworzenia nowych kodów `SUR`;
- aktualizuje istniejącą listę tabel runtime i zakresy podpisanych mutacji; ogranicza uprawnienia do rejestru.

Migracja nie zmienia kodów ani kluczy obcych istniejących surowców, rewizji, cen ani snapshotów kosztów.

### Zachowanie istniejących przepływów

- `RawMaterialService` przydziela `RAW` przez wspólną usługę. Kod jest przypisywany w transakcji tworzenia surowca, przed wstawieniem rekordu i audytu.
- Parser i persystencja cenowego CSV dopasowują dokładny, niezmieniony kod `RAW-…` lub historyczny `SUR-…` w organizacji aktora. Nie dopasowują po nazwie i nie tworzą brakującego surowca.
- Zaktualizowano zatwierdzony `VELTRA_CSV_DATA_EXCHANGE_CONTRACT.md` wyłącznie w zakresie identyfikatora: nowe rekordy używają `RAW`, historyczne `SUR` są akceptowane bez remapowania. Semantyka cen, VAT, dat efektywnych i zdarzeń pozostaje bez zmian.

## Weryfikacja

Testy uruchomiono na osobnych kontenerach PostgreSQL 17.11, Redis 7.4 i ClamAV 1.4.6. Sieć testowa była wewnętrzna; katalog danych PostgreSQL i przestrzeń robocza testów były efemeryczne. Sonda PDO przed migracjami potwierdziła `veltra_testing`, rolę `veltra_app` bez uprawnień superuser oraz adres serwera w sieci testowej. Kontenery aplikacyjne, ich sieć i wolumeny nie były podłączone ani modyfikowane.

| Sprawdzenie | Wynik |
|---|---|
| Świeża sekwencja migracji 001–035 | **PASS, 35/35** |
| Testy ukierunkowane: generowanie RAW, rezerwacja SKU, izolacja organizacji, CSV RAW/SUR | **PASS, 4 testy, 40 asercji** |
| Pełna dozwolona regresja | **PASS, 216 testów, 1839 asercji** |
| Testy Stage 2C ograniczone przez właściciela | **17 wykluczonych; 0 uruchomionych** |
| PHP lint | **PASS** |
| Pint | **PASS** |
| `git diff --check` | **PASS** |

Filtr regresji wykluczył wszystkie 15 przypadków `RawMaterialPermissionBootstrapTest`, `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` oraz `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`. Listę PHPUnit sprawdzono przed uruchomieniem: 233 testy łącznie, 216 dozwolonych.

W trakcie weryfikacji poprawiono składnię zmiany nazwy tabeli w migracji, uprawnienia funkcji triggerów do odczytu schematu prywatnego, walidację odroczoną licznika oraz fixture historycznego `SUR`. Jedno pełne uruchomienie miało przejściową porażkę asercji tokenu w teście konta; test pojedynczy i końcowy pełny przebieg przeszły. Nie pozostał potwierdzony błąd regresji.

## Ograniczenia i ryzyka

- Nie łączono się z produkcyjną bazą. Raport nie przyjmuje założenia, że jest pusta, mimo zatwierdzonego planu rozpoczęcia produkcji bez migracji rekordów v1.x.
- Testy kompatybilności tworzą historyczne kody `SUR` na schemacie po migracji i weryfikują, że pozostają używalne. Świeża sekwencja 001–035 nie zawierała historycznych rekordów, dlatego backfill istniejących rekordów `SUR` przez migrację 035 nie został osobno sprawdzony na bazie z danymi. Przed zastosowaniem migracji poza środowiskiem disposable wymagany jest test upgrade 034→035 z reprezentatywnymi danymi i kopią zapasową.
- Rezerwacja SKU jest podstawą dla przyszłego importera. Nie zaimplementowano jeszcze tworzenia katalogowych rekordów RAW z importowanym SKU ani przepływów INT/PRD/MEN.
- `RAW_MATERIALS` pozostaje `NOT_READY`; nie aktywowano `RAW_MATERIALS` ani `VSEF`. Wyniki nie stanowią akceptacji Stage 2C, gotowości produkcyjnej ani zgody na wdrożenie.

## Proponowany następny krok

Właściciel powinien zatwierdzić lub skorygować osobny projekt `VELTRA_CSV_CATALOG_IMPORT_CONTRACT_PROPOSAL.md`. Dopiero po zatwierdzeniu kontraktu, zdefiniowaniu schematów domen INT/PRD/MEN oraz weryfikacji upgrade’u 034→035 z danymi należy rozpocząć implementację pełnego importera katalogu.

**Nie wykonano commitów, pushy, wdrożeń ani aktywacji funkcji.**
