# VELTRA 2.0 — Stage 2D.1 — Raport implementacji modelu danych

**Tryb:** DEVELOPMENT ONLY  
**Zakres:** wersjonowany model deklaracji alergenów i wariantów przechowywania dla dokładnych rewizji surowców  
**Status Stage 2C:** bez zmian; 17 ograniczonych testów bezpieczeństwa nie uruchomiono  
**Aktywacja:** `RAW_MATERIALS` i VSEF pozostają nieaktywne

## Podsumowanie

Dodano migrację forward-only 028, model danych dla słownika alergenów, deklaracji przypiętych do rewizji i wariantów przechowywania oraz obsługę wersjonowanego hasha rewizji. Nie dodano endpointów, danych słownika produkcyjnego ani nowych uprawnień biznesowych.

Historyczny format SHA-256 pozostaje bez zmian. Istniejące rewizje otrzymują `content_hash_version = 1`, a ich weryfikacja nadal używa dotychczasowej reprezentacji. Nowe rewizje aplikacyjne zapisują wersję 2, która obejmuje dotychczasowy payload, jednostki i aliasy oraz kanoniczną deklarację/atest alergenowy i warianty przechowywania. Dziedziczona historyczna wartość `DECLARED` bez szczegółowych danych jest w formacie v2 jawnie oznaczona jako `LEGACY_DECLARED`; nigdy nie jest interpretowana jako „brak znanych alergenów”.

## Zmienione i dodane pliki

- `backend/database/migrations/2026_11_06_000028_create_raw_material_revision_allergen_and_storage_data.php`
- `backend/app/Domain/RawMaterials/RawMaterialService.php`
- `backend/tests/Feature/RawMaterialRevisionDataModelTest.php`

## Model i ograniczenia

### Słownik alergenów

- `raw_material_allergen_catalog_versions` przechowuje unikalne, rosnące wersje słownika w stanie `DRAFT` albo `PUBLISHED`.
- `raw_material_allergen_definitions` zawiera kod, polską nazwę, kolejność i aktywność definicji w konkretnej wersji.
- Dozwolone jest dodawanie definicji wyłącznie do wersji roboczej. Opublikowane definicje są niezmienne.
- Migracja nie wstawia żadnych wpisów słownikowych. Testy używają wyłącznie syntetycznych pozycji.

### Deklaracje alergenów

- `raw_material_revision_allergen_attestations` wiąże atest z dokładną rewizją, organizacją, opublikowaną wersją słownika, aktorem i czasem.
- `raw_material_revision_allergen_declarations` wiąże kod alergenu z ekspozycją `DIRECT` albo `CROSS_CONTACT_POTENTIAL`. Dany kod może wystąpić najwyżej raz w jednej rewizji.
- `UNKNOWN` jest zapisane w istniejącym `raw_material_revisions.allergen_state`; stan ten nie wymaga atestu i nie oznacza braku alergenów.
- Jawne `NO_KNOWN_ALLERGENS` wymaga osobnego atestu. Pusta lista wierszy sama nie oznacza braku znanych alergenów.
- Odraczane ograniczenia PostgreSQL sprawdzają spójność stanu rewizji, atestu i deklaracji przy zatwierdzeniu transakcji.

### Warianty przechowywania

- `raw_material_revision_storage_variants` przechowuje warianty dokładnej rewizji, temperaturę minimalną/maksymalną, opcjonalny zakres wilgotności, warunki i znacznik wariantu domyślnego.
- Ograniczenia sprawdzają zakres temperatury, porządek granic oraz zakres wilgotności 0–100% i wymagają podania obu granic wilgotności albo żadnej.
- Częściowy indeks unikalny pozwala na najwyżej jeden domyślny wariant w rewizji.

### Izolacja i niezmienność

- Tabele rewizyjne używają złożonych kluczy obcych wiążących `revision_id` z `organization_id`; aktor atestu także musi należeć do tej samej organizacji.
- Dane Stage 2D można wstawiać tylko dla bieżącej rewizji roboczej z wersją hasha 2.
- Dane rewizji są append-only: aktualizacje i usunięcia są odrzucane, a po zamknięciu rewizji nie można dopisywać do niej nowych danych.
- Rola `veltra_app` otrzymała tylko wymagane uprawnienia PostgreSQL: odczyt słownika, odczyt i wstawianie danych rewizyjnych. Nie dodano grantów organizacyjnych ani automatycznego nadawania uprawnień biznesowych.

## Zgodność historycznych hashy

Kanoniczna reprezentacja historyczna (payload, jednostki, aliasy, sortowanie i opcje JSON) pozostała niezmieniona w ścieżce `content_hash_version = 1`. Kolumna ma domyślną wartość 1, więc istniejące rekordy i bezpośrednio tworzone rekordy zgodności historycznej zachowują dawny algorytm.

Nowy format 2 dodaje znacznik wersji, stan deklaracji, numer wersji słownika, atestującego i czas atestu, posortowane deklaracje oraz posortowane warianty przechowywania. Dane te są częścią obliczania i ponownej weryfikacji hasha. Test potwierdził zgodność starego hasha v1 oraz obliczania v2 przed i po utworzeniu kolejnej rewizji.

## Weryfikacja i wyniki

Wszystkie operacje wykonano wyłącznie na disposable PostgreSQL 17 / Redis z testową bazą `veltra_testing`, w sieci wewnętrznej `veltra-stage2c-security-test-internal`. Migracje i fixtures korzystały z `veltra_migrator`; testy aplikacyjne korzystały z ograniczonej roli `veltra_app`.

| Weryfikacja | Wynik |
|---|---:|
| Migracje świeżej bazy 001–028 | **28/28 PASS** |
| `RawMaterialRevisionDataModelTest.php` | **6/6 PASS** |
| Asercje nowych testów | **32** |
| Niepowodzenia / błędy / ostrzeżenia / pominięte | **0 / 0 / 0 / 0** |
| PHP lint — migracja, serwis i testy | **3/3 PASS** |
| Pint — te same pliki | **PASS** |
| `git diff --check` | **PASS** |

Testy pokryły:

- poprawne deklaracje `DIRECT` i `CROSS_CONTACT_POTENTIAL` oraz poprawny wariant przechowywania;
- odrzucenie duplikatu i niepoprawnego rodzaju deklaracji;
- odrzucenie nieprawidłowych granic temperatury i wilgotności;
- odrzucenie drugiego domyślnego wariantu oraz powiązania między organizacjami;
- odrzucenie modyfikacji, usunięcia i dopisania danych po zatwierdzeniu rewizji;
- rozróżnienie `UNKNOWN` i jawnego `NO_KNOWN_ALLERGENS`;
- zachowanie hasha historycznego v1 i weryfikację hasha v2.

**Nie uruchomiono** żadnego z 17 ograniczonych testów bezpieczeństwa Stage 2C ani ich odpowiedników. Nie wykonano pełnej regresji aplikacyjnej; raport nie przypisuje jej nowych wyników.

## Uwagi dotyczące czystej instalacji

Pierwsze próby `migrate:fresh` wykazały pozostałości po wcześniejszym użyciu tej samej disposable bazy: pomocniczy schemat `veltra_private` oraz trzy nazwane funkcje PostgreSQL, których `migrate:fresh` nie usuwa razem z tabelami. Po sprawdzeniu `current_database() = veltra_testing` i `current_user = veltra_migrator` usunięto wyłącznie te konkretne pozostałości z bazy efemerycznej. Następna czysta instalacja zakończyła się powodzeniem dla migracji 001–028. Nie zmieniano istniejących baz, wolumenów ani kontenerów aplikacyjnych.

Po testach potwierdzono, że sześć pierwotnych kontenerów VELTRA nadal działa; oryginalny PostgreSQL i backend zgłaszały stan healthy. Disposable PostgreSQL, Redis i runner pozostały w odrębnej sieci wewnętrznej. Żaden test Stage 2C, deployment, commit ani push nie został wykonany.

## Nierozstrzygnięte / poza zakresem

- Brak potwierdzonych defektów implementacji w wykonanych testach.
- Interfejs/API do zarządzania słownikiem, deklaracjami i wariantami należy do dalszych zatwierdzonych kroków Stage 2D; ten etap nie udostępnia nowych endpointów.
- Słownik produkcyjny nie został zasilony.
- Stage 2C pozostaje bez zmian i nie został przez te wyniki niezależnie zaakceptowany.
