# VELTRA 2.0 — Stage 3E-B.1

## 1. Wynik i granice

Zaimplementowano lokalny backend katalogu RAW, ilości nominalnych/użytkowych, referencyjnej ceny opakowania, kosztu użytkowego i trwałych rezerwacji SKU przed zapisem. Pełna końcowa regresja: **306 testów PASS, 3096 asercji**.

**RAW_MATERIALS pozostaje NOT_READY.** Nie wdrażano ani nie aktywowano modułu. Nie zmieniano frontendu, Production, formalnego wycofania RAW ani pakietów B.2/B.3. Nie wykonano commit/push. Migrację wykonano wyłącznie w dedykowanej bazie testowej; nie wykonywano poleceń w kontenerach instalacji.

### Źródła

Odczytano oba dokumenty UX przez eksport tekstowy oraz raport kompatybilności z Drive:

- [100 zatwierdzonych decyzji UX](https://docs.google.com/document/d/1NECl8Hqq_auHzST6kJ-rovgSuMm30JhbxFWpx7ZIamM/edit).
- [Uporządkowana specyfikacja UI/UX](https://docs.google.com/document/d/1cQ_-fPrz5jXzrJvaaAxiXQGbjHqBvrMBOESbYKa0KQk/edit).
- [Raport kompatybilności](https://drive.google.com/file/d/1tUfpPa_zYgSe1j3C1UVe04pEFSDhJDfN/view).

Zweryfikowano aktualny kod RBAC 1D, autoryzacji biznesowej 1E, ADR-001, alokatora K3 oraz RAW 3A–3D.1. Zachowano bieżące all-or-nothing bulk sharing 3D.1; nie przywracano starszych, sprzecznych zapisów o częściowym udostępnianiu lub formalnym retirement.

## 2. Katalog i API

`GET /api/v1/raw-materials` obsługuje stałe osiem kolumn; nie wprowadzono konfiguracji kolumn:

| Kolumna UX | Pole odpowiedzi |
|---|---|
| Lp. | `ordinal`, obliczane po globalnym sortowaniu i paginacji |
| SKU | `sku`; zachowano zgodnościowe `code` |
| Nazwa | `revision.name` |
| Jednostka | `revision.base_unit_code` |
| Ilość nominalna | `nominal_quantity` |
| Ilość użytkowa | `usable_quantity` |
| Cena netto | `reference_net_price`, PLN za zakupione opakowanie |
| Koszt użytkowy | `usable_unit_cost` i `usable_cost_basis` |

Pozostałe istniejące pola identyfikacyjne i metadane API zachowano. Nie dodano kolumny Lp. w bazie.

- `search`: fragment nazwy lub SKU, bez rozróżniania wielkości liter; zachowano dotychczasowe wyszukiwanie aliasów.
- Filtry: `category_id`, `supplier_id`, `lifecycle_state`, `unit`.
- `sort`: `code`/`sku`, `name`, `unit`, `nominal_quantity`, `usable_quantity`, `reference_net_price`, `usable_unit_cost`, `lifecycle_state`; `direction=asc|desc`.
- Domyślnie `name asc`; dodatkowy klucz `materials.id` zapewnia deterministyczność, wartości NULL są na końcu.
- `per_page=25|50|100|200`, domyślnie 50; `page>=1`.
- `meta`: `current_page`, `per_page`, `total`, `last_page`, `from`, `to`.
- Katalog korzysta wyłącznie z zatwierdzonych rewizji, odrzuca archiwum i zachowuje zakres organizacji oraz jawnych udostępnień lokalizacyjnych. Filtr ARCHIVED nie omija wykluczenia archiwum.
- Filtr dostawcy wymaga dodatkowo `RAW_MATERIALS_SUPPLIER_VIEW`; uwzględnia aktywnego dostawcę i produkt oraz jego najnowszą rewizję powiązaną z aktualną zatwierdzoną rewizją RAW.

`GET /api/v1/raw-materials/{material}` otrzymał te same obliczane wartości i ochronę finansową; istniejące zasady dostępu do szkiców i zatwierdzonych rewizji pozostają w mocy.

## 3. Ilości, jednostki i obliczenia

Tworzenie i rewizja RAW przyjmują `nominal_quantity`, `usable_quantity` jako teksty dziesiętne w jednostce `base_unit_code`.

- W bazie `numeric(24,6)`: do 18 cyfr całkowitych i 6 ułamkowych, wartości dodatnie.
- Rejestr jednostek: `mg`, `g`, `kg`, `ml`, `l`, `pc`; sztuki muszą być całkowite.
- Przy podaniu nominalnej i pominięciu użytkowej użytkowa domyślnie równa się nominalnej.
- Obie wartości mogą być NULL dla historycznych rewizji i niekompletnych szkiców. Nie uzupełniano fikcyjnych ilości w historii.
- Korekta wymaga istniejących uprawnień edycji i tworzy rewizję, zamiast zmieniać zatwierdzoną historię. Edycja innych pól zachowuje ilości; zmiana jednostki przy istniejących ilościach wymaga jawnego podania ilości.
- `yield_percent = usable_quantity * 100 / nominal_quantity`, 6 miejsc, wyłącznie informacyjne. Nie dodano ręcznie wpisywanej wydajności ani niezatwierdzonego limitu użytkowa <= nominalna.
- Nowe ilości uczestniczą w hashu rewizji. Zachowano dotychczasowy format hasha dla historycznych rewizji z oboma polami NULL.

Koszt użytkowy = cena opakowania / ilość użytkowa × przelicznik:

| Jednostka ilości | Przelicznik | Baza kosztu |
|---|---:|---|
| mg | 100000 | PLN/100g |
| g | 100 | PLN/100g |
| kg | 0.1 | PLN/100g |
| ml | 100 | PLN/100ml |
| l | 0.1 | PLN/100ml |
| pc | 1 | PLN/pc |

Nie stosowano zmiennoprzecinkowych obliczeń pieniężnych. PostgreSQL `numeric` i istniejące HALF_EVEN zachowują 14 miejsc dla kwot; prezentacja dwóch miejsc należy do przyszłej integracji UI. Brak danych oznacza NULL, nie fikcyjną cenę lub koszt.

## 4. Cena referencyjna i uprawnienia

`POST /api/v1/raw-materials/{material}/reference-prices` przyjmuje alternatywnie:

- nową `net_pln_per_package`, wymagającą zatwierdzonej ilości nominalnej;
- dotychczasową `net_pln_per_base_unit`, zgodnie z istniejącym kontraktem.

Pola są wzajemnie wykluczające; cena opakowania nie łączy się z `source_offer_event_id`. Pozostają `event_type`, `effective_from`, `expected_event_no`, `reason_code` i dotychczasowa idempotencja. Wycofanie ceny nie przyjmuje kwoty.

Dokładna cena opakowania jest przechowywana jako `numeric(31,14)` niezależnie od zaokrąglonego przeliczenia bazowego. Historia cen zwraca dodatkowo `net_pln_per_package`. Dla starszych zdarzeń i istniejących importów katalog wylicza cenę opakowania z ceny bazowej i nominalnej ilości. Odczyt wybiera najnowsze obowiązujące zdarzenie według daty i numeru; wycofanie daje NULL także w koszcie.

Zapis nie tworzy dodatkowej ścieżki zatwierdzania ceny. Zachowano istniejące daty obowiązywania i audyt. Niezmieniona ręczna cena zwraca `changed=false`, nie tworzy kolejnego zdarzenia ceny, ale zapisuje próbę `REFERENCE_PRICE_UNCHANGED`. Porównanie uwzględnia dokładną cenę opakowania, nie tylko zaokrągloną cenę bazową.

| Operacja / wartość | Wymagania dodatkowe do zwykłego dostępu RAW |
|---|---|
| Cena referencyjna w katalogu i szczegółach | skuteczny `RAW_MATERIALS_REFERENCE_PRICE_VIEW` |
| Koszt użytkowy | powyższy grant oraz skuteczny `FOODCOST_VIEW` w kontekście lokalizacji |
| Sort po wartości finansowej | uprawnienia odczytu tej konkretnej wartości |
| Zapis, wycofanie i import ceny | ADMINISTRATOR, odpowiedni grant RAW, kontekst primary |
| Wartości ofert / zakupów | odrębne istniejące granty; sam grant ceny referencyjnej ich nie daje |

Bez uprawnień pola finansowe są pomijane, a sortowanie po nich odrzucane 403. Sam FOODCOST nie ujawnia ceny referencyjnej. Dotychczasowe profile CSV oraz odrębna ochrona eksportów, ofert, zakupów i restatementów pozostają zachowane; zwykły eksport katalogowy nie otrzymał nowych pól finansowych. Nie nadawano automatycznie grantów administratorom ani SUPER ADMIN.

## 5. Rezerwacja SKU przed zapisem

Nowy kontrakt:

```http
POST /api/v1/raw-materials/sku-reservations
Idempotency-Key: <8–120 znaków A-Z a-z 0-9 . _ : ->
Content-Type: application/json

{}
```

Odpowiedź 201: `data.reservation_id`, `data.sku`, `data.expires_at`, `data.status`. Obowiązują istniejące middleware sesji, kontekstu, modułu, autoryzacji RAW i dodatkowy limiter.

`POST /api/v1/raw-materials` przyjmuje opcjonalne `sku_reservation_id`. Rezerwacja jest związana z aktorem, organizacją i lokalizacją. Pod blokadą organizacji następuje ponowna autoryzacja, claim i utworzenie materiału z wcześniej przypisanym numerem, bez drugiej alokacji. Powtórzenie tej samej treści zwraca zapamiętaną odpowiedź, inna treść daje 409. Kolejność kluczy obiektów nie zmienia hasha żądania.

- Przy rezerwacji istniejący alokator zapisuje trwały assignment; przyszły UUID materiału jest identyfikatorem rezerwacji.
- TTL: 24 godziny. Stany: RESERVED, EXPIRED, CONSUMED. Porzucenie lub wygaśnięcie nigdy nie odzyskuje numeru.
- Limity: 20 żądań/minutę na aktora, 100 nowych rezerwacji w ruchomych 24 h na aktora, 1000 na organizację.
- Ponowienie klucza idempotencji nie przydziela kolejnego SKU. Claim i odpowiedź konsumpcji są trwałe.
- Nowe RAW: dokładnie RAW-000001…RAW-999999. Wyczerpanie daje deterministyczny 409, bez zwiększenia licznika ponad zakres.
- Zachowano oddzielne sekwencje i zakresy INT/PRD/MEN. Nie przepisywano historycznych, szerszych RAW; istniejące historyczne importy NO_CHANGE nadal są rozpoznawane.
- Nowy import nie przejmuje numeru zarezerwowanego formularzem i nie tworzy siedmiocyfrowego RAW.
- Częściowy indeks UNIQUE pełnego SKU dla RAW wymusza unikalność instalacyjną. Zmieniono dwa sztuczne testy wieloorganizacyjne, które wcześniej dopuszczały ten sam RAW w dwóch organizacjach; aktualna architektura ADR-001 pozostaje jednoorganizacyjna.
- Starsze API tworzenia bez rezerwacji pozostaje zgodne wstecznie. Nowa integracja formularza ma używać rezerwacji; frontend nie został wykonany.

Rezerwacje mają bazodanowy guard niezmienności tożsamości i jednokrotnej konsumpcji, podpisany scope mutacji oraz brak runtime DELETE/TRUNCATE. Nie dodano mechanizmu ponownego użycia numerów.

## 6. Dokładna lista plików

### Zmienione istniejące pliki

1. [backend/README.md](backend/README.md) — dokumentacja kontraktów.
2. [RawMaterialAuthorization.php](backend/app/Domain/RawMaterials/RawMaterialAuthorization.php) — ADMINISTRATOR i primary dla zarządzania ceną.
3. [RawMaterialCatalogCsvImportWorkflow.php](backend/app/Domain/RawMaterials/RawMaterialCatalogCsvImportWorkflow.php) — zakres nowych RAW i zgodność historycznych importów.
4. [RawMaterialCommercialService.php](backend/app/Domain/RawMaterials/RawMaterialCommercialService.php) — dokładna cena opakowania, deduplikacja, historia.
5. [RawMaterialService.php](backend/app/Domain/RawMaterials/RawMaterialService.php) — katalog, ilości, rewizje, konsumpcja rezerwacji.
6. [SkuPrefix.php](backend/app/Domain/Sku/SkuPrefix.php) — izolowany sześciocyfrowy zakres RAW.
7. [RawMaterialController.php](backend/app/Http/Controllers/Api/V1/RawMaterialController.php) — endpoint rezerwacji.
8. [ListRawMaterialsRequest.php](backend/app/Http/Requests/ListRawMaterialsRequest.php) — query katalogu.
9. [RawMaterialDataRequest.php](backend/app/Http/Requests/RawMaterialDataRequest.php) — ilości i identyfikator rezerwacji.
10. [RawMaterialReferencePriceRequest.php](backend/app/Http/Requests/RawMaterialReferencePriceRequest.php) — alternatywne rodzaje ceny i walidacja.
11. [AppServiceProvider.php](backend/app/Providers/AppServiceProvider.php) — limiter rezerwacji.
12. [api.php](backend/routes/api.php) — trasa API.
13. [RawMaterialsTest.php](backend/tests/Feature/RawMaterialsTest.php) — integracja nowych testów, fixture oraz aktualizacja oczekiwań ADMIN/unikalności.

### Nowe pliki

14. [RawMaterialCatalogValues.php](backend/app/Domain/RawMaterials/RawMaterialCatalogValues.php) — wspólna walidacja, obliczenia i bezpieczna prezentacja.
15. [RawMaterialSkuReservationService.php](backend/app/Domain/RawMaterials/RawMaterialSkuReservationService.php) — cykl życia rezerwacji.
16. [RawMaterialSkuReservationRequest.php](backend/app/Http/Requests/RawMaterialSkuReservationRequest.php) — kontrakt pustego body.
17. [Migracja 042](backend/database/migrations/2026_11_09_000042_add_raw_catalog_quantities_and_sku_reservations.php) — ilości rewizji, dokładna cena opakowania, rezerwacje, scope/guard i indeks RAW; forward-only.
18. [RawCatalogStage3eB1Tests.php](backend/tests/Concerns/RawCatalogStage3eB1Tests.php) — 16 nowych testów.
19. [concurrent_raw_catalog.php](backend/tests/Support/concurrent_raw_catalog.php) — worker testów współbieżności.
20. [compose.stage3e-b1-test.yaml](compose.stage3e-b1-test.yaml) — izolowane środowisko testowe.
21. [Niniejszy raport](VELTRA_STAGE_3E_B1_RAW_CATALOG_PRICE_SKU_REPORT.md).

Istniejący nieśledzony [raport kompatybilności](VELTRA_STAGE_3E_RAW_UX_BACKEND_COMPATIBILITY.md) pochodził sprzed zadania i nie został zmieniony.

## 7. Walidacja i izolacja

Użyto wyłącznie projektu Compose `veltra-stage3e-b1-test`, bazy `veltra_testing` i PostgreSQL **17.11**. Oddzielna sieć `internal=true`, osobne wolumeny storage/cache, dane PostgreSQL i Redis w tmpfs; bez publikacji portów. Runner używa istniejącego obrazu narzędziowego testów 3D.1 i aktualnych źródeł, nie obrazu instalacyjnego.

Runtime `veltra_app` i migrator `veltra_migrator` nie mają SUPERUSER/CREATEDB/CREATEROLE. Runtime nie jest właścicielem tabel, nie ma CREATE w public ani TRUNCATE organizations. Testy współbieżne również działają jako runtime. Gotowość RAW przełączano wyłącznie zatwierdzonymi fixtures testowymi, nie konfiguracją produkcyjną.

Migracje wykonano od zera wyłącznie w tym środowisku. Dane uwierzytelniające są syntetyczne, poza repozytorium. Przed usunięciem zasobów zachowano logi i JUnit w obszarze sesji. Po walidacji usunięto tylko cztery kontenery, dwa wolumeny i sieć projektu testowego; potwierdzono brak jego wolumenów/sieci oraz dalsze działanie kontenerów instalacji.

| Końcowe sprawdzenie | Wynik |
|---|---|
| 16 nowych testów + istniejący test eksportu ceny | **17 PASS, 243 asercje**, 54.37 s |
| Pełny backend | **306 PASS, 3096 asercji**, 370.90 s |
| Pint całego backendu | **PASS, 272 pliki** |
| `git diff --check` | **PASS** |

Nowe testy obejmują kolumny, filtrowanie, sortowanie, paginację, izolację dostawcy/jednostki, ilości i niezmienność rewizji, wszystkie jednostki kosztu i granice zaokrągleń, brak wycieku finansowego w odczytach/CSV, ADMIN-only, deduplikację i audyt ceny, dokładną cenę opakowania, wycofanie ceny, rozdział FOODCOST, kolizje importów, rezerwacje/porzucenie/expiry/claim/idempotencję, limiter HTTP, równoległe alokacje i tworzenie oraz wyczerpanie sześciu cyfr. Pełna regresja obejmuje istniejący RBAC, readiness, sharing, bulk 3D.1 i historyczne przypisania SKU.

Pierwszy pełny przebieg miał 304 PASS i 1 FAIL: nowy CHECK ceny opakowania błędnie odrzucał NULL przy PRICE_WITHDRAWN wskutek trójwartościowej logiki SQL. Poprawiono CHECK, dodano osobny test wycofania i ponowiono migracje na świeżej izolowanej bazie, testy celowane oraz pełny backend. Powyżej podano wyniki końcowe, nie wyniki pośrednie.

Główne wykonane komendy, z tą samą jawną konfiguracją testową:

```sh
TEST_ENV=/home/veltra/.copilot/session-state/5dcc2b87-76d2-4e26-9865-f279b40e7535/files/stage3e-b1-test.env
docker compose --env-file "$TEST_ENV" -f compose.stage3e-b1-test.yaml exec -T runner php artisan test --compact --do-not-cache-result --filter='stage3e_b1|test_reference_price_export_uses_independent_as_of_histories_and_exact_decimals' --log-junit /tmp/stage3e-b1-focused.xml
docker compose --env-file "$TEST_ENV" -f compose.stage3e-b1-test.yaml exec -T runner php artisan test --compact --do-not-cache-result --log-junit /tmp/stage3e-b1-full.xml
docker compose --env-file "$TEST_ENV" -f compose.stage3e-b1-test.yaml exec -T --user "$(id -u):$(id -g)" runner vendor/bin/pint --test
git diff --check
docker compose --env-file "$TEST_ENV" -f compose.stage3e-b1-test.yaml down --volumes
```

## 8. Bezpieczeństwo, zgodność i dalsze granice

- Zachowano primary central kitchen, jawne udostępnianie, izolację odbiorców, widoczność zatwierdzonych rewizji, sześć ról, ochronę tożsamości SUPER ADMIN, oddzielne granty finansowe i all-or-nothing bulk sharing.
- Kwoty są chronione niezależnie, także przed bocznym ujawnieniem przez sortowanie. Nie zmieniano ofert dostawców, kosztów zakupowych ani wyceny magazynu.
- Trwałe assignmenty, blokady organizacji, bazodanowa unikalność i testy wieloprocesowe potwierdzają brak podwójnego numerowania w objętych scenariuszach.
- Sześciocyfrowy wymóg odizolowano do nowych RAW. Historyczne szersze numery pozostają; ich porządkowanie nie jest częścią B.1.
- Wykonano testy zabezpieczeń i regresji, nie deklaruje się osobnego pełnego audytu bezpieczeństwa ani gotowości całego RAW.
- Nie pozostał niewykonany test wymagany w wykonanym przebiegu. Ograniczeniem pozostaje brak wdrożenia i wykonania migracji w instalacji — zgodnie z zakazem użytkownika.
- Frontend, dalsze pakiety B.2/B.3, konfigurowalne kolumny/nowe profile eksportu, Production, frozen batch revisions, formal retirement, wdrożenie i aktywacja pozostają poza zakresem. Nie rozpoczęto ich automatycznie.

## 9. Dostarczenie raportu

Wersja lokalna:

`/home/veltra/projekty/veltra/VELTRA_STAGE_3E_B1_RAW_CATALOG_PRICE_SKU_REPORT.md`

Folder docelowy Drive: [RAPORTY_COPILOTA](https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t).

Podjęto próbę standardowego przesłania rzeczywistego pliku Linux. Przeglądarka po stronie Windows zwróciła ENOENT, interpretując ścieżkę jako `C:\home\veltra\projekty\veltra\VELTRA_STAGE_3E_B1_RAW_CATALOG_PRICE_SKU_REPORT.md`. Anulowano okno wyboru pliku. **Raportu nie przesłano na Drive; nie można było zweryfikować treści zdalnej.** Zachowano kompletny raport pod powyższą ścieżką Linux, zgodnie z dopuszczonym wariantem lokalnym.

**STOP po Stage 3E-B.1.**
