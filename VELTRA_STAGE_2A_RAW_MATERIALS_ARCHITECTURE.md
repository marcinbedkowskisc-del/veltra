# VELTRA 2.0 — Stage 2A: Architektura modułu surowców

**Status:** skonsolidowana specyfikacja po zatwierdzeniu decyzji B1–B8; do niezależnego przeglądu architektury
**Zakres:** dokumentacja; bez zmian kodu, migracji, bazy danych i kontenerów
**Rekomendacja:** ACCEPT dla rozpoczęcia zakresu Stage 2B określonego w tej specyfikacji; implementacja wymaga osobnego upoważnienia.

## 1. Streszczenie

Moduł Surowców jest odrębnym kluczem gotowości Stage 1E `RAW_MATERIALS`, z aktywacją i kontrolą gotowości dla każdej lokalizacji. Dane pozostają jednym wspólnym katalogiem organizacji: lokalizacja nie tworzy własnej definicji. Tożsamość i kod surowca są trwałe, a zmiany tworzą niezmienne rewizje. Jawne, organizacyjne granty RBAC (nie sama rola lokalizacyjna) kontrolują edycję i delegowanie uprawnień; dostęp do cen referencyjnych i kosztów zakupu wymaga odrębnych uprawnień.

Proponowana implementacja wykorzystuje istniejący modularny monolit Laravel 13, PostgreSQL, UUID-y, organizacyjne granice danych, transakcje i blokady. Wymaga rozszerzenia istniejącego, lokalizacyjnego modelu grantów o granty organizacyjne oraz weryfikacji jednocześnie uprawnienia aktora i aktywacji `RAW_MATERIALS` dla zaufanego kontekstu lokalizacji. Moduł pozostaje `NOT_READY` i niedostępny do czasu implementacji oraz weryfikacji wymaganych funkcji; dodanie klucza nie oznacza jego gotowości.

Decyzje biznesowe B1–B8 są zatwierdzone i zintegrowane w niniejszym dokumencie. Pozostałe ryzyka dotyczą wyborów wykonawczych, integracji z przyszłymi modułami i operacyjnego doboru bibliotek; są jawnie ograniczone w raporcie końcowym. Nie zmieniają zatwierdzonych zasad biznesowych.

## 2. Podstawa i zgodność z istniejącym systemem

Poniższe elementy wynikają z inspekcji kodu i zatwierdzonych raportów/specyfikacji. W kolejnych sekcjach nowe tabele i endpointy są **propozycjami**, a nie opisem już istniejącego kodu.

| Obszar | Stan istniejący istotny dla projektu |
|---|---|
| Architektura | Modularny monolit Laravel 13 / PHP 8.4, API REST; oddzielny frontend React/TypeScript. Aktualne trasy API są wersjonowane pod `/api/v1`. |
| Organizacja i lokalizacje | Organizacja oraz lokalizacje mają UUID; lokalizacje należą do organizacji. Relacje używają kluczy obcych złożonych z `organization_id`, by uniemożliwić łączenie rekordów różnych organizacji. Usunięcia danych operacyjnych są ograniczane przez `RESTRICT`. |
| RBAC | `BusinessAuthorization` sprawdza aktywność/tożsamość użytkownika, weryfikację, członkostwo, zakres organizacji/lokalizacji, rolę, uprawnienie i dostępność modułu. Role obejmują m.in. `ADMINISTRATOR`, `CENTRAL_KITCHEN_CHEF`, `BOSS`, `RESTAURANT_MANAGER`, `RESTAURANT_CHEF`, `EMPLOYEE`. Nie należy zakładać nowych uprawnień ról bez jawnego mapowania. |
| Uprawnienia i ceny | Istnieją m.in. `FOODCOST_VIEW` i `PURCHASE_PRICES_VIEW`. Granty `permission_grants` są lokalizacyjne; nie zapewniają jeszcze zatwierdzonego modelu delegowanych grantów organizacyjnych. |
| Stage 1E | `DefinedModuleCatalog` definiuje klucz, jedno uprawnienie kotwiczące i `ready`; stan `location_module_states` jest per lokalizacja i domyślnie wyłączony. Brak dziedziczenia stanu między kuchnią i restauracją. `ModuleAvailability` sprawdza klucz modułu dla lokalizacji; granularne uprawnienia Surowców muszą dodatkowo być sprawdzane niezależnie. |
| Transakcje i audyt | Operacje instalacyjne/modułowe blokują wiersz organizacji w PostgreSQL, wykonują modyfikację i audyt w transakcji oraz obsługują idempotentne próby. `security_events` jest chronione przed zmianą/usunięciem; migracja 000010 dodała ograniczenia kształtu zdarzeń modułowych. Nie jest to jeszcze pełny audyt domenowy Surowców. |
| API | Przykładowe listy używają `page`, `per_page` (maks. 100), filtrów i odpowiedzi zasobów pod `data`. Requesty walidują wejście przed usługą domenową. |
| Lokalizacja | Stage 1F ustawił polski locale i fallback oraz zabezpieczył obsługę błędów; wszystkie nowe teksty interfejsu, walidacji i błędów muszą być po polsku. Kody i klucze API pozostają stabilne i angielskie. |
| Zależności | `composer.json` nie deklaruje obecnie parsera XLSX, silnika PDF ani biblioteki obiektowego magazynu dokumentów. Ich wybór, licencje, limity i obsługa błędów wymagają osobnego zatwierdzenia przed Stage 2D/2E. |

### Zweryfikowane źródła repozytorium

- [Organization.php](./backend/app/Models/Organization.php), [Location.php](./backend/app/Models/Location.php) oraz migracja [000001](./backend/database/migrations/2026_11_02_000001_create_installation_structure.php) — organizacja, lokalizacje, UUID, ograniczenia relacji.
- [BusinessAuthorization.php](./backend/app/Domain/Authorization/BusinessAuthorization.php), [BusinessRole.php](./backend/app/Domain/Authorization/BusinessRole.php), [Permission.php](./backend/app/Domain/Authorization/Permission.php) — rzeczywiste reguły RBAC.
- [DefinedModuleCatalog.php](./backend/app/Domain/Modules/DefinedModuleCatalog.php), [ModuleAvailability.php](./backend/app/Domain/Authorization/ModuleAvailability.php), [ModuleConfigurationService.php](./backend/app/Domain/Modules/ModuleConfigurationService.php), migracja [000010](./backend/database/migrations/2026_11_02_000010_create_location_module_configuration.php) — katalog i bramka dostępności.
- [SecurityMutation.php](./backend/app/Domain/Authorization/SecurityMutation.php) oraz [ModuleConfigurationConcurrencyTest.php](./backend/tests/Feature/ModuleConfigurationConcurrencyTest.php) — wzorzec blokady organizacji, transakcji, audytu i testów współbieżności.
- [api.php](./backend/routes/api.php), [ListLocationsRequest.php](./backend/app/Http/Requests/ListLocationsRequest.php), [LocationController.php](./backend/app/Http/Controllers/Api/V1/LocationController.php) — wersjonowanie tras, walidacja i paginacja.
- [composer.json](./backend/composer.json), [config/app.php](./backend/config/app.php), [compose.yaml](./compose.yaml) — stos, brak parserów dokumentów i konfiguracja polskiego środowiska.
- [VELTRA_STAGE_1F_FOUNDATION_AUDIT.md](./VELTRA_STAGE_1F_FOUNDATION_AUDIT.md), [VELTRA_STAGE_1E_FINAL_VERIFICATION.md](./VELTRA_STAGE_1E_FINAL_VERIFICATION.md), [VELTRA_STAGE_1E_IMPLEMENTATION_REPORT.md](./VELTRA_STAGE_1E_IMPLEMENTATION_REPORT.md), [VELTRA_STAGE_1F_LOCALIZATION_REMEDIATION.md](./VELTRA_STAGE_1F_LOCALIZATION_REMEDIATION.md), [VELTRA_STAGE_1F_LOCALIZATION_DEPLOYMENT_REPORT.md](./VELTRA_STAGE_1F_LOCALIZATION_DEPLOYMENT_REPORT.md) oraz zatwierdzone specyfikacje Stage 1D RBAC i Stage 1E aktywacji modułów.

## 3. Decyzje zatwierdzone a propozycje

### 3.1 Wymagania zatwierdzone przez właściciela produktu

Poniższe punkty są wymaganiami biznesowymi, nie propozycjami architekta:

- Jeden współdzielony katalog surowców na organizację; zarządzanie centralne przez istniejący RBAC; lokalizacje mogą konsumować dozwolone dane, ale nie redefiniują surowców.
- Automatyczne, niezmienne kody, np. `SUR-000001`; kodu zarchiwizowanego surowca nie wolno użyć ponownie.
- Kategorie o maksymalnie trzech poziomach, aliasy wyszukiwania i wykrywanie duplikatów.
- Wiele dostawców, produktów dostawców i ofert zakupu; oferta/opakowanie dostawcy nie jest definicją surowca.
- Jedna centralnie zarządzana cena referencyjna netto dla foodcost; historia cen niezmienna. Cena referencyjna i koszt zakupu mają odrębne, jawne uprawnienia; ogólny odczyt katalogu nie ujawnia żadnej z tych wartości.
- Jedna jednostka bazowa na surowiec; automatyczne przeliczenia metryczne oraz kontrolowane konwersje właściwe dla surowca dla masy, objętości i sztuk. Zmiana konwersji nie zmienia wcześniejszych obliczeń.
- Centralna, zatwierdzona deklaracja alergenów rozróżnia alergeny bezpośrednie i możliwe zanieczyszczenie krzyżowe. Brak danych nie oznacza braku alergenów. Uprawniona osoba zatwierdza zgodność produktu dostawcy na podstawie dokumentacji dostawcy; wynik, dowód, osoba i historia są utrwalane. Produkt niezweryfikowany lub niezgodny nie jest traktowany jako zatwierdzony.
- Wydajność należy do procesu technologicznego, nie do stałego procentu surowca.
- Wiele zatwierdzonych wariantów przechowywania na surowiec; reguły te nie zastępują przyszłych kontroli partii.
- Pełne wersjonowanie dokumentów, status zatwierdzenia, autor, data akceptacji i okres obowiązywania; zachowanie starszych wersji i skojarzeń historycznych.
- Cykl życia: `DRAFT`, `VERIFICATION`, `ACTIVE`, `BLOCKED`, `ARCHIVED`. Jawna akceptacja przed pierwszym aktywowaniem; użytkownik może zatwierdzić własny rekord. Zmiany krytyczne wymagają ponownej akceptacji; dotychczasowe zatwierdzone dane obowiązują do czasu zaakceptowania zmiany krytycznej, z wyjątkiem natychmiastowej blokady bezpieczeństwa.
- Zablokowanie lub archiwizacja zachowuje zatwierdzone receptury i historyczne powiązania, ale odrzuca nowe użycie produkcyjne. Operacje już trwające wymagają weryfikacji; zagrożenie bezpieczeństwa może wymagać natychmiastowej blokady.
- Historia i integralność referencji; bez destrukcyjnego usuwania użytego surowca. Przywrócenie z archiwum jest dozwolone wyłącznie w jawnie uprawnionym, audytowanym workflow z ponowną walidacją i zatwierdzeniem; kod i historia pozostają.
- Import XLSX/CSV jest atomowy, ma walidację, wykrywanie duplikatów i podgląd; importowane rekordy zaczynają jako szkice; ponowny import nie tworzy przypadkowych duplikatów.
- Operacje zbiorcze są atomowe, poprzedzone podglądem i generują osobne zdarzenia audytu dla elementów.
- Eksport XLSX/CSV/PDF obejmuje wybrane rekordy, wynik filtra albo wszystkie dozwolone rekordy oraz wybór kolumn.
- Interfejs katalogu jest tabelaryczny, z wyszukiwaniem, filtrami, sortowaniem, wyborem kolumn i zapisanymi widokami. Cały interfejs, walidacja i błędy dla użytkownika są po polsku.

### 3.1.1 Skonsolidowane decyzje B1–B8

| Decyzja | Zatwierdzona reguła | Odzwierciedlenie w architekturze |
|---|---|---|
| B1 — ceny | Cena referencyjna i koszt zakupu wymagają rozłącznych, jawnych uprawnień; odczyt katalogu nie ujawnia kwot. | `RAW_MATERIALS_REFERENCE_PRICE_VIEW/MANAGE` oddzielone od `RAW_MATERIALS_PURCHASING_COSTS_VIEW/MANAGE`; osobne API, selekcja eksportu i testy wycieku. |
| B2 — blokada/archiwum | Zachować zatwierdzone receptury i referencje; odrzucać nowe użycie. Operacje w toku weryfikować; safety block działa natychmiast. | Master status niezależny od historycznej rewizji; check statusu i serializacja przy finalizacji zużycia; bez przepisywania/odpinania historii. |
| B3 — alergeny dostawcy | Autoryzowany approver zatwierdza zgodność na podstawie dokumentacji dostawcy; dowód i historia są trwałe. | Immutable compatibility review przypięty do dokładnej rewizji surowca i wersji dokumentu; brak aktualnego `COMPATIBLE` oznacza odmowę użycia. |
| B4 — koszt produkcji | Koszt rzeczywisty z partii inventory, jeśli dostępny; w przeciwnym razie historyczna cena referencyjna i wyraźne oznaczenie kosztu szacowanego. | Kontrakt snapshotu Production zachowuje wersję, ilości, konwersję, walutę, źródło, kurs i wynik PLN; zamknięty snapshot jest niezmienny. |
| B5 — readiness | Osobny klucz `RAW_MATERIALS`; per-location activation/readiness. Wspólny katalog organizacyjny; READY dopiero po implementacji i weryfikacji wymaganej funkcjonalności. | Każdy request sprawdza module gate i org permission; kod Stage 2A/2B nie aktywuje funkcji, katalog pozostaje fail-closed. |
| B6 — RBAC | Uprawnienia organizacyjne, z kontrolowaną delegacją; role wyłącznie lokalizacyjne nie dają prawa do modyfikacji katalogu. | Nowe granty organizacyjne, bez implicit role bypass, zakres delegacji/termin/revoke w append-only audit. |
| B7 — waluta | PLN jest bazą raportową; zakupy foreign-currency zachowują kurs i precyzję; typowa prezentacja dwóch miejsc bez przedwczesnego zaokrąglania. | Immutable FX events, zachowanie kwoty źródłowej i przeliczenia PLN, `NUMERIC`, rounding wyłącznie na granicy prezentacji/uzgodnionego agregatu. |
| B8 — przywracanie | Jawne uprawnione i audytowane restore z ponowną walidacją i zatwierdzeniem; zachować kod, historię, dokumenty i audyt. | `ARCHIVED -> VERIFICATION -> ACTIVE`, osobne `RAW_MATERIALS_RESTORE`, nowa rewizja i brak fizycznego delete. |

### 3.2 Proponowane decyzje techniczne

Poniższe wybory techniczne realizują zatwierdzone wymagania; nie zmieniają ich:

1. Rozdzielić stabilną tożsamość `raw_materials` od niezmiennych `raw_material_revisions`. `raw_materials` przechowuje kod i bieżące wskaźniki, rewizja pełen zatwierdzony zestaw danych.
2. Utrzymywać `approved_revision_id` i opcjonalne `pending_revision_id`. Krytyczna zmiana tworzy nową rewizję oczekującą; aktywna rewizja pozostaje bez zmian do zatwierdzenia. Blokada bezpieczeństwa jest oddzielnym, natychmiastowym stanem rekordu.
3. Rozszerzyć RBAC o granty organizacyjne append-only/revocable z delegacją ograniczoną do jawnych kluczy; nie dziedziczyć uprawnień do katalogu z samej roli lokalizacyjnej.
4. Każde żądanie wymaga jednocześnie: poprawnego kontekstu organizacji, jawnego grantu, członkostwa/tożsamości oraz `RAW_MATERIALS` w stanie READY i enabled dla bieżącej lokalizacji.
5. Używać kluczy złożonych obejmujących `organization_id`, FK `ON DELETE RESTRICT`, `NUMERIC` dla ilości/cen i transakcji PostgreSQL.
6. Serializować zmianę katalogu i kontrolowane przejścia stanów blokadą wiersza organizacji, zapisując dane i audyt w jednej transakcji; retry ma stabilny `idempotency_key`.
7. Przechowywać ceny dostawcy w walucie źródłowej; zapisywać niezmienny kurs oraz wartości raportowe PLN. Nie zaokrąglać wartości pośrednich do dwóch miejsc.
8. Snapshot produkcyjny używa rzeczywistego kosztu skonsumowanej partii magazynowej, jeśli istnieje; w innym przypadku pobiera cenę referencyjną obowiązującą dla czasu operacji i oznacza koszt jako szacowany.
9. Udostępnić API jako `/api/v1/...`, z `data`, stabilnymi kodami błędów, paginacją `page`/`per_page` i polskimi komunikatami.
10. Projektować wyszukiwanie i eksport jako zapytania ograniczone do organizacji, gotowości lokalizacji i granularnych uprawnień. Ceny zakupu i referencyjne są osobno chronione.
11. Rozdzielić master, rewizje, produkty dostawców, oferty, kursy, snapshots kosztów oraz wersje dokumentów; dokumenty przechowywać poza publicznym webrootem.

## 4. Granice domen i integracje

### 4.1 Zakres modułu Surowce

Surowce jest właścicielem organizacyjnej definicji surowca, kategorii, kodu, rewizji, jednostki bazowej i konwersji, deklaracji alergenów, wariantów przechowywania, dokumentów związanych z surowcem oraz relacji do dostawców, produktów i ofert (Stage 2C+). Nie przechowuje stanów magazynowych, receptur, procesu technologicznego, partii produkcyjnych ani przyszłych rejestrów HACCP.

Każda operacja odczytu i zapisu jest ograniczona do `organization_id` z uwierzytelnionego kontekstu. Lokalizacja jest kontekstem autoryzacji i użycia, nie właścicielem kopii surowca. API nigdy nie przyjmuje zaufanego `organization_id` z body jako autorytatywnego zakresu.

### 4.2 Kontrakty z przyszłymi domenami

| Domena | Kontrakt |
|---|---|
| Recipes | Pozycja receptury wskazuje `raw_material_id` i zatwierdzoną `raw_material_revision_id`, a nie tylko bieżący stan. Zmiana master nie przepina samoczynnie receptur. Blokada/archiwizacja zachowuje zaakceptowane receptury, ale blokuje nowe użycie; operacje w toku podlegają ponownej walidacji. |
| Foodcost | Pobiera cenę referencyjną wyłącznie z `RAW_MATERIALS_REFERENCE_PRICE_VIEW`; nie ujawnia cen nieuprawnionym lokalizacjom. Dla kosztu partii stosuje B4, a wartości raportowe prezentuje w PLN z zachowanym kursem i precyzją B7. |
| Production | Przy zamknięciu partii zapisuje snapshot ilości bazowej, kosztu, waluty, rewizji surowca, konwersji i źródła ceny; snapshot jest niezmienny. Bieżące wartości nie mogą wpływać na zamkniętą partię. |
| Inventory | Pozycja zapasu wskazuje master surowca i jednostkę; partia/ilość oraz zgodność przyjęcia należą do Inventory. Nie utożsamia opakowania dostawcy z jednostką bazową. |
| Purchasing | Zamówienie wskazuje dostawcę, produkt/opakowanie i ofertę. Ilość i cena zakupu zachowują własny snapshot; zmiana oferty nie przepisuje istniejących zamówień. |
| HACCP | Może konsumować zatwierdzone alergeny i reguły przechowywania, ale deklaracje master nie zastępują kontroli partii, temperatury ani procedury HACCP. |

Integracje powinny używać jawnych serwisów domenowych/API wewnętrznego, nie bezpośrednich zapisów do cudzych tabel ani odczytu „latest” bez identyfikatora wersji.

## 5. Model PostgreSQL

### 5.1 Konwencje

- UUID PK; `organization_id uuid NOT NULL` na każdym tenant-scoped rekordzie. FK do `organizations(id)` z `ON DELETE RESTRICT`.
- Relacje tenant-scoped używają `UNIQUE (id, organization_id)` i FK złożonych `(foreign_id, organization_id)`, aby baza egzekwowała granice organizacji.
- Czas jako `timestamptz`; ilości `numeric(24,9)`, konwersje `numeric(24,12)`, ceny/koszty `numeric(24,8)`; nie używać `float`, `real` ani binarnej arytmetyki zmiennoprzecinkowej dla ilości i pieniędzy.
- Kody, klucze enum i kody błędów są stabilnymi identyfikatorami angielskimi. Teksty dla użytkownika po polsku.
- Dane historyczne mają FK `RESTRICT`; rekordy użyte nie są fizycznie kasowane. Tabele rewizji, cen, zdarzeń i dokumentów są append-only (trigger blokujący `UPDATE`/`DELETE`, wyjątki tylko w jawnie projektowanych tabelach projekcji).
- Każda migracja Stage 2B+ ma osobne testy ograniczeń PostgreSQL; Laravel validation nie zastępuje constraints/triggerów.

### 5.2 Rdzeń katalogu i kategorie (Stage 2B)

| Tabela | Kolumny i typy | Klucze, ograniczenia i indeksy |
|---|---|---|
| `raw_material_categories` | `id uuid`, `organization_id uuid`, `parent_id uuid NULL`, `code varchar(40)`, `name varchar(160)`, `depth smallint`, `is_archived boolean`, `created_by uuid`, `created_at timestamptz`, `updated_at timestamptz` | `UNIQUE(id, organization_id)`, `UNIQUE(organization_id, code)`, `CHECK(depth BETWEEN 1 AND 3)`, FK złożony rodzica (RESTRICT), indeks `(organization_id, parent_id, name)`. Trigger/transakcja sprawdza depth=parent.depth+1 i brak cyklu/reparentingowi nie wolno przekroczyć 3. |
| `raw_materials` | `id uuid`, `organization_id uuid`, `code varchar(16)`, `lifecycle_state varchar(16)`, `approved_revision_id uuid NULL`, `pending_revision_id uuid NULL`, `blocked_at timestamptz NULL`, `blocked_by uuid NULL`, `block_reason_code varchar(80) NULL`, `archived_at timestamptz NULL`, `created_by uuid`, `created_at timestamptz`, `updated_at timestamptz` | `UNIQUE(id, organization_id)`, `UNIQUE(organization_id, code)`, `CHECK` dozwolonych stanów; approved/pending FK złożone `(revision_id, raw_material_id, organization_id)` do rewizji `(id, raw_material_id, organization_id)`, `DEFERRABLE` dla cyklu master↔revision; indeks `(organization_id, lifecycle_state, code)`. Trigger zabrania zmiany kodu/organizacji/ID i fizycznego usunięcia. |
| `raw_material_code_counters` | `organization_id uuid`, `prefix varchar(3)`, `last_value bigint`, `updated_at timestamptz` | PK `(organization_id, prefix)`, `CHECK(last_value >= 0)`. `UPDATE ... RETURNING` w transakcji organizacji; kod przydzielony tylko raz i nie zwalniany po archiwizacji ani nieudanym późniejszym workflow. Blokada współbieżności plus unikalność kodu. |
| `raw_material_revisions` | `id uuid`, `organization_id uuid`, `raw_material_id uuid`, `revision_no integer`, `category_id uuid`, `name varchar(200)`, `description text NULL`, `base_unit_code varchar(24)`, `allergen_state varchar(16)`, `critical_change boolean`, `change_reason text`, `created_by uuid`, `created_at timestamptz`, `content_hash char(64)` | `UNIQUE(raw_material_id, revision_no)`, `UNIQUE(id, organization_id)`, `UNIQUE(id, raw_material_id, organization_id)`, FK composite to master/category; `CHECK(revision_no > 0)`, allergen state enum; immutable trigger. Rewizja treści nie zawiera pól approval, które wymagałyby późniejszego UPDATE. |
| `raw_material_revision_approval_events` | `id uuid`, `organization_id uuid`, `revision_id uuid`, `operation_id uuid`, `outcome varchar(16)`, `actor_user_id uuid`, `occurred_at timestamptz`, `effective_at timestamptz NULL`, `reason_code varchar(80) NULL`, `content_hash char(64)` | Append-only; outcome `APPROVED`/`RETURNED`; FK złożone do rewizji/organizacji, approval zapisuje hash dokładnej zatwierdzanej treści. Constraint/trigger gwarantuje brak podmiany rewizji między odczytem i zatwierdzeniem. `approved_revision_id` master wskazuje zatwierdzoną wersję i jest zmieniany w tej samej transakcji co event. |
| `raw_material_aliases` | `id uuid`, `organization_id uuid`, `raw_material_id uuid`, `revision_id uuid`, `alias varchar(200)`, `normalized_alias varchar(220)`, `created_by uuid`, `created_at timestamptz` | FK złożony do dokładnej pary revision/material, `UNIQUE(organization_id, normalized_alias)` (bez automatycznego reassignment); indeks `(organization_id, raw_material_id)`. Alias należy do treści rewizji, jest utrwalony przed submit i nie może być dopisywany do zatwierdzonej wersji. Wersja normalizacji jest testowana; konflikt daje polskie ostrzeżenie o duplikacie. |
| `raw_material_revision_units` | `id uuid`, `organization_id uuid`, `revision_id uuid`, `unit_code varchar(24)`, `dimension varchar(12)`, `to_base_factor numeric(24,12)`, `created_at timestamptz` | `UNIQUE(revision_id, unit_code)`, FK do rewizji; factor > 0. Nie zapisuje runtime rounding scale: obliczenia zachowują pełną określoną precyzję, a presentation/rounding następuje dopiero na jawnej granicy. Metric conversions derive from controlled registry; mass↔volume/piece require approved material-specific density/factor/basis. |
| `raw_material_revision_unit_registry` (opcjonalna tabela słownikowa) | `unit_code varchar(24) PK`, `dimension varchar(12)`, `symbol varchar(12)`, `is_metric boolean`, `precision_scale smallint` | Ograniczony, kodowy słownik wersjonowany migracją. Nie umożliwia dowolnego wprowadzania jednostek w requestach. |
| `raw_material_duplicate_reviews` | `id uuid`, `organization_id uuid`, `candidate_material_id uuid`, `existing_material_id uuid`, `similarity_score numeric(5,4) NULL`, `decision varchar(16)`, `reviewed_by uuid NULL`, `reviewed_at timestamptz NULL`, `source_operation_id uuid`, `created_at timestamptz` | `CHECK(candidate != existing)`, `UNIQUE(source_operation_id, candidate_material_id, existing_material_id)`, indeks organizacja/stan. Wynik fuzzy jest sygnałem do weryfikacji, nie automatycznym scaleniem. |

`code` ma format `SUR-` + sześć cyfr co najmniej; licznik PostgreSQL jest atomowy per organizacja/prefix. Brak sekwencji „max(code)+1”, bo równoległe inserty mogłyby przydzielić ten sam identyfikator. Przyszłe wzrosty nie mogą obciąć ani zawinąć kodu. Usunięcie/archiwizacja nie obniża licznika. Jeżeli nazwy/numery kodów mają kiedyś inne prefiksy, to wymaga wersjonowanego słownika i jawnej migracji.

`content_hash` rewizji jest deterministycznym hashem kanonicznego snapshotu całego agregatu: pól rewizji oraz uporządkowanych aliasów, jednostek/konwersji, deklaracji/atestu alergenów, wariantów storage i identyfikatorów/checksum powiązanych wersji dokumentów. Submit zamyka skład rewizji; approve pod blokadą weryfikuje identyczny hash i wymogi kompletności. Zmiana któregokolwiek elementu child wymaga nowej rewizji, nigdy dopisania do zatwierdzonego snapshotu.

Jednostka bazowa jest jedną jednostką o wymiarze ustalonym w rewizji; jej współczynnik tożsamości wynosi 1 i jest niezmienny. Każdy automatyczny metric conversion musi pozostać w tym samym wymiarze. Przeliczenia mass↔volume oraz sztuk są material-specific, wymagają jawnego basis/factor i nie mogą być wyprowadzone wyłącznie z ogólnego registry.

**Duplikaty:** dokładny alias/nazwa znormalizowana ma constraint unikalności; podobieństwo (np. literówki) generuje listę potencjalnych duplikatów w podglądzie. Nie scalać automatycznie rekordów ani historii. Przed importem użytkownik musi powiązać wiersz z istniejącym surowcem albo jawnie potwierdzić nowy szkic. Wdrożenie fuzzy search może użyć PostgreSQL `pg_trgm`, ale migracja musi sprawdzić dostępność/zgodę na extension; nie zakładać jej na podstawie obecnych zależności.

### 5.3 Alergeny, przechowywanie i dokumenty (Stage 2D)

| Tabela | Kolumny i typy | Ograniczenia/zasady |
|---|---|---|
| `allergen_catalog` | `code varchar(40) PK`, `name_pl varchar(120)`, `is_active boolean`, `sort_order smallint` | Słownik zatwierdzany przez domenę jakości; kod stabilny. Lista prawnie wymaganych alergenów i wersja słownika wymagają przeglądu compliance. |
| `raw_material_revision_allergens` | `organization_id uuid`, `revision_id uuid`, `allergen_code varchar(40)`, `presence_kind varchar(20)` (`DIRECT`, `CROSS_CONTACT_POTENTIAL`), `evidence_document_version_id uuid NULL`, `created_at timestamptz` | PK `(revision_id, allergen_code, presence_kind)`; FK do rewizji i słownika. `allergen_state` musi być `DECLARED` albo jawne `UNKNOWN`; brak wierszy nie jest równoważny „bez alergenów”. „Brak znanych alergenów” jest osobnym, jawnie zatwierdzonym stanem/potwierdzeniem, nie pustą kolekcją. |
| `raw_material_revision_allergen_attestations` | `revision_id uuid PK`, `organization_id uuid`, `declaration_status varchar(20)`, `attested_by uuid`, `attested_at timestamptz`, `basis_document_version_id uuid NULL` | Jawny atest; ograniczenia wymuszają atest przed `ACTIVE`. Brak deklaracji pozostaje `UNKNOWN`, nigdy bezpiecznym brakiem alergenów. |
| `raw_material_storage_variants` | `id uuid`, `organization_id uuid`, `revision_id uuid`, `name varchar(120)`, `storage_type varchar(24)`, `min_temperature_c numeric(6,3) NULL`, `max_temperature_c numeric(6,3) NULL`, `humidity_min numeric(5,2) NULL`, `humidity_max numeric(5,2) NULL`, `conditions text`, `is_default boolean`, `created_at timestamptz` | Rekord należy do rewizji; `min <= max`; zakres wilgotności 0..100; temperatura w °C; wiele wariantów. Domyślna opcja najwyżej jedna na rewizję (partial unique index). Nie zastępuje pomiarów partii/HACCP. |
| `raw_material_documents` | `id uuid`, `organization_id uuid`, `raw_material_id uuid`, `document_type varchar(40)`, `created_at timestamptz`, `created_by uuid` | Stabilna tożsamość logicznego dokumentu; bez ścieżki podanej przez użytkownika. |
| `raw_material_document_versions` | `id uuid`, `organization_id uuid`, `document_id uuid`, `version_no integer`, `object_key varchar(512)`, `original_filename varchar(255)`, `detected_mime varchar(120)`, `byte_size bigint`, `sha256 char(64)`, `author_id uuid`, `authored_at timestamptz`, `supersedes_id uuid NULL` | Immutable payload i metadane; unikatowa `(document_id, version_no)`, FK RESTRICT, checksum i dodatni rozmiar. Brak mutowalnych kolumn approval/effective date w immutable row. |
| `raw_material_document_version_events` | `id uuid`, `organization_id uuid`, `document_version_id uuid`, `event_type varchar(16)`, `actor_user_id uuid`, `occurred_at timestamptz`, `effective_at timestamptz NULL`, `reason_code varchar(80) NULL` | Append-only `SUBMITTED`/`APPROVED`/`REJECTED`/`SUPERSEDED`; aprobata i okres obowiązywania wynikają z eventów (koniec poprzedniego okresu z eventu `SUPERSEDED`), bez UPDATE historycznego dokumentu. Obowiązujące okresy muszą być bez nakładania, wymuszane transakcyjnie/constraintem. |
| `raw_material_revision_documents` | `organization_id uuid`, `revision_id uuid`, `document_version_id uuid`, `association_kind varchar(24)`, `created_at timestamptz` | PK `(revision_id, document_version_id, association_kind)`. Powiązanie konkretnej wersji dokumentu z konkretną rewizją; nie zmienia się po aktualizacji dokumentu. |

`storage_variants` powinny być elementem payloadu rewizji (albo niezmiennymi elementami rewizji przez FK); zatwierdzona rewizja nie może pokazywać przypadkowo nowego, niezatwierdzonego zakresu temperatury. Temperatura graniczna i warunki są opisem danych master, nie walidacją pomiaru dla konkretnej partii.

### 5.4 Dostawcy, produkty i ceny (Stage 2C)

| Tabela | Kolumny i typy | Ograniczenia/zasady |
|---|---|---|
| `suppliers` | `id uuid`, `organization_id uuid`, `supplier_code varchar(40)`, `name varchar(200)`, `tax_identifier varchar(80) NULL`, `lifecycle_state varchar(16)`, `created_by uuid`, `created_at timestamptz` | Organizacyjny dostawca, unikatowy kod; wrażliwe dane widoczne wyłącznie w zakresie Purchasing/odpowiedniego uprawnienia. |
| `supplier_products` | `id uuid`, `organization_id uuid`, `supplier_id uuid`, `raw_material_id uuid`, `supplier_product_code varchar(100) NULL`, `name varchar(200)`, `package_quantity numeric(24,9)`, `package_unit_code varchar(24)`, `current_compatibility_review_id uuid NULL`, `created_at timestamptz` | Produkt/opakowanie dostawcy jest odrębne od master; ilość > 0; `UNIQUE(id, raw_material_id, organization_id)` oraz FK composite do supplier/material; kod unikatowy per dostawca, gdy podano. Bieżący wynik jest pochodną wskazanej, niezmiennej oceny, a nie niezależnym stanem do ręcznego ustawienia. |
| `supplier_product_compatibility_reviews` | `id uuid`, `organization_id uuid`, `supplier_product_id uuid`, `raw_material_id uuid`, `raw_material_revision_id uuid`, `decision varchar(16)` (`COMPATIBLE`, `INCOMPATIBLE`), `evidence_document_version_id uuid NOT NULL`, `reviewed_by uuid`, `reviewed_at timestamptz`, `reason_code varchar(80)`, `notes text NULL` | Niezmienny, zatwierdzony wynik oceny z dokumentem dostawcy i aktorem uprawnionym do zatwierdzania. Złożone FK gwarantują, że rewizja należy do surowca produktu, a FK wskazujący z `supplier_products.current_compatibility_review_id` należy do tego samego produktu/organizacji. Weryfikacja `PENDING` wynika z braku aktualnej oceny. Ocena jest skuteczna tylko dla dokładnej rewizji; nowa rewizja alergenów automatycznie czyni ją nieaktualną. Produkt bez bieżącej oceny `COMPATIBLE` nie jest zatwierdzony do użycia. |
| `supplier_offers` | `id uuid`, `organization_id uuid`, `supplier_product_id uuid`, `supplier_reference varchar(100) NULL`, `net_price numeric(24,8)`, `currency char(3)`, `valid_from timestamptz`, `created_by uuid`, `created_at timestamptz`, `source_document_version_id uuid NULL` | Append-only; oferta historyczna nie jest ceną referencyjną foodcost. `net_price >= 0`; zachować walutę źródłową zakupu i jednostkę opakowania. Odczyt kwoty wymaga jawnego permission kosztów zakupu. |
| `raw_material_reference_price_events` | `id uuid`, `organization_id uuid`, `raw_material_id uuid`, `effective_from timestamptz`, `revision_no integer`, `net_price_per_base_unit numeric(24,8)`, `currency char(3)`, `reporting_amount_pln numeric(24,8)`, `exchange_rate_event_id uuid NULL`, `set_by uuid`, `reason_code varchar(80)`, `source_offer_id uuid NULL`, `created_at timestamptz` | Niezmienny rekord; `UNIQUE(raw_material_id, effective_from, revision_no)`, `CHECK(revision_no > 0)`, kwoty ≥ 0. Korekta tej samej daty obowiązywania dodaje wyższy `revision_no`; as-of wybiera największy `effective_from <= as_of`, a następnie najwyższy revision_no. Wartość źródłowa i przeliczenie PLN są odczytywane wyłącznie z osobnym grantem ceny referencyjnej. |
| `organization_exchange_rate_events` | `id uuid`, `organization_id uuid`, `base_currency char(3)` (`PLN`), `foreign_currency char(3)`, `revision_no integer`, `pln_per_foreign_unit numeric(24,12)`, `effective_at timestamptz`, `source_code varchar(80)`, `evidence_document_version_id uuid NULL`, `recorded_by uuid`, `recorded_at timestamptz` | Niezmienny, append-only kurs; `CHECK(pln_per_foreign_unit > 0)`, `CHECK(revision_no > 0)`, `UNIQUE(organization_id, foreign_currency, effective_at, revision_no)` i indeks `(organization_id, foreign_currency, effective_at DESC)`. PLN→PLN przyjmuje dokładnie 1. Korekta tej samej daty obowiązywania dodaje wyższy revision_no, nie UPDATE; as-of rozstrzyga najpierw po czasie, potem po revision_no. Kurs zawiera walutę ISO 4217, czas obowiązywania i źródło; regułę autorytatywnego źródła należy określić w implementacji finansowej bez zmiany zatwierdzonej waluty bazowej. |

Nie modyfikować poprzedniego wiersza ceny/kursu przez ustawianie `valid_until`; przedziały wyliczać z kolejnych zdarzeń. Korekta wartości historycznej jest nowym zdarzeniem korygującym z większym `revision_no`, nie UPDATE. Kurs i jego źródło są przypięte do wyliczenia PLN w momencie zapisu.

### 5.5a Granty organizacyjne RBAC (Stage 2B)

| Tabela | Kolumny i typy | Klucze, ograniczenia i indeksy |
|---|---|---|
| `organization_permission_grants` | `id uuid`, `organization_id uuid`, `user_id uuid`, `permission varchar(80)`, `granted_by uuid`, `granted_at timestamptz`, `valid_from timestamptz`, `valid_until timestamptz NULL`, `delegation_source_id uuid NULL`, `reason_code varchar(80)` | FK złożone do aktywnego użytkownika i organizacji; `CHECK(permission IN approved_raw_material_permission_keys)`, `CHECK(valid_until IS NULL OR valid_until > valid_from)`; indeks efektywnego grantu. Rekord jest immutable. Cofnięcie nie aktualizuje ani nie usuwa wiersza — zapisuje osobny event unieważnienia; efektywny grant to ważny, nieodwołany rekord. Każda zmiana ma audyt domenowy i serializację organizacji. |
| `organization_permission_grant_events` | `id uuid`, `organization_id uuid`, `grant_id uuid`, `event_type varchar(16)`, `actor_user_id uuid`, `occurred_at timestamptz`, `reason_code varchar(80)` | Append-only `REVOKED`/`EXPIRED`/`CORRECTED`; co najwyżej jeden event unieważniający dla grantu, indeks tenant-scoped. Brak UPDATE/DELETE. |

Grant organizacyjny daje capability do wspólnego katalogu; nie zastępuje membership/tożsamości ani sprawdzenia lokalizacji/modułu. Delegowanie wymaga odrębnego `RAW_MATERIALS_PERMISSION_DELEGATE`, nie pozwala na nadanie sobie grantu, delegację kluczy nieposiadanych/delegowalnych ani delegację samego prawa delegowania; granty mają termin/zakres i są audytowane. Grantobiorca i delegujący muszą należeć do tej samej organizacji; delegujący musi zachować authority w chwili delegacji. Zwykła rola `CENTRAL_KITCHEN_CHEF`, `RESTAURANT_MANAGER` lub `ADMINISTRATOR` sama w sobie nie jest grantem modyfikacji katalogu. Początkowe przyznanie organizacyjnych permission musi przejść przez istniejący zatwierdzony proces RBAC; nie tworzyć automatycznych szerokich grantów w migracji.

### 5.5b Kontrakt kosztu produkcji (właścicielem tabeli jest Production)

Stage 2A nie tworzy tabeli produkcyjnej. Projektowany kontrakt integracyjny wymaga niezmiennego `production_raw_material_cost_snapshot` przypiętego do pozycji/zużycia zamykanej partii. Minimalne pola: `organization_id`, `production_batch_id`, `raw_material_id`, `raw_material_revision_id`, opcjonalne `inventory_batch_id`, `cost_source` (`INVENTORY_BATCH` albo `REFERENCE_PRICE_ESTIMATE`), `is_estimated`, ilość i jednostka wejściowa, ilość bazowa, `conversion_revision_id`, współczynnik konwersji, kwota jednostkowa i całkowita w walucie źródłowej, `currency`, `exchange_rate_event_id`, zastosowany kurs, waluta raportowa `PLN`, kwota raportowa PLN oraz `captured_at`. Kwoty/ilości nie są zaokrąglane do prezentacyjnych dwóch miejsc w trakcie kalkulacji.

Jeśli istnieje rzeczywisty koszt skonsumowanej partii magazynowej, snapshot używa go jako `INVENTORY_BATCH` i wiąże z partią oraz transakcją kosztową. W przeciwnym razie używa historycznej ceny referencyjnej właściwej dla czasu operacji jako `REFERENCE_PRICE_ESTIMATE`, zapisuje źródłowy event ceny/kursu i ustawia `is_estimated=true`. Zamknięty snapshot jest append-only i nie jest przeliczany przy zmianie ceny, kursu, rewizji lub konwersji. Production ma obowiązek sprawdzić blokadę/archiwizację master w tej samej transakcji, która zatwierdza nowe zużycie; historyczne snapshoty pozostają czytelne.

### 5.6 Audyt, idempotencja, import i eksport (Stage 2E)

| Tabela | Kolumny i typy | Zasady |
|---|---|---|
| `raw_material_audit_events` | `id uuid`, `organization_id uuid`, `operation_id uuid`, `actor_user_id uuid NULL`, `actor_location_id uuid NULL`, `subject_type varchar(60)`, `subject_id uuid NULL`, `event_code varchar(80)`, `outcome varchar(16)`, `reason_code varchar(80) NULL`, `before_hash char(64) NULL`, `after_hash char(64) NULL`, `occurred_at timestamptz`, `metadata jsonb` | Append-only, trigger blokuje UPDATE/DELETE; indeksy `(organization_id, occurred_at DESC)`, `(organization_id, operation_id)`, `(organization_id, subject_type, subject_id, occurred_at DESC)`. Bez sekretów, tokenów, pełnej treści dokumentu czy niepotrzebnych danych osobowych. |
| `raw_material_idempotency_records` | `id uuid`, `organization_id uuid`, `actor_user_id uuid`, `idempotency_key varchar(128)`, `request_hash char(64)`, `operation_id uuid`, `response_status smallint`, `response_body jsonb`, `created_at timestamptz`, `expires_at timestamptz` | `UNIQUE(organization_id, actor_user_id, idempotency_key)`. Ten sam klucz i hash zwraca poprzedni wynik; inny hash daje konflikt 409. Retencja odpowiedzi nie usuwa audytu biznesowego. |
| `raw_material_import_batches` | `id uuid`, `organization_id uuid`, `actor_user_id uuid`, `source_format varchar(8)`, `upload_object_key varchar(512)`, `upload_sha256 char(64)`, `idempotency_key varchar(128)`, `state varchar(16)`, `row_count integer`, `previewed_at timestamptz NULL`, `committed_at timestamptz NULL`, `created_at timestamptz` | Unikatowy idempotency key; stany `UPLOADED`, `VALIDATED`, `READY`, `COMMITTED`, `REJECTED`, `EXPIRED`; plik prywatny. |
| `raw_material_import_rows` | `id uuid`, `organization_id uuid`, `batch_id uuid`, `row_number integer`, `row_hash char(64)`, `normalized_payload jsonb`, `validation_state varchar(16)`, `messages jsonb`, `matched_material_id uuid NULL`, `created_material_id uuid NULL` | `UNIQUE(batch_id, row_number)`, payload oczyszczony i walidowany; błędy w języku polskim; wynik podglądu nie jest jeszcze rekordem katalogowym. |
| `raw_material_saved_views` | `id uuid`, `organization_id uuid`, `owner_user_id uuid`, `name varchar(120)`, `filters jsonb`, `columns jsonb`, `sort jsonb`, `is_shared boolean`, `created_at timestamptz`, `updated_at timestamptz` | Własność organizacji/użytkownika, ścisła walidacja JSON, maksymalna liczba kolumn/filtrów, żadnego surowego SQL. |
| `raw_material_export_jobs` | `id uuid`, `organization_id uuid`, `actor_user_id uuid`, `format varchar(8)`, `query_snapshot jsonb`, `column_set jsonb`, `state varchar(16)`, `result_object_key varchar(512) NULL`, `result_sha256 char(64) NULL`, `expires_at timestamptz NULL`, `created_at timestamptz` | Eksport asynchroniczny dla większych list; wynik ograniczony do autoryzowanego zakresu, prywatny, krótko dostępny przez autoryzowany endpoint. |

Wymagany audyt rejestruje również odrębne zdarzenie dla każdego elementu operacji zbiorczej. Przy błędzie atomowej transakcji brak zmian danych; po rollbacku można zapisać osobne zdarzenia `REJECTED` dla każdego elementu w nowej transakcji audytowej z tym samym `operation_id`. Taki zapis nie może maskować pierwotnego błędu ani udawać sukcesu.

### 5.7 Wersjonowanie, rewizje i obliczenia

- `raw_materials` jest niezmienną tożsamością. Zmiana danych nie aktualizuje rewizji zatwierdzonej.
- `raw_material_revisions` przechowuje pełny snapshot biznesowy wraz z kategorią, nazwą, jednostką, przelicznikami, alergenami, wariantami przechowywania i powiązanymi wersjami dokumentów.
- Początkowa rewizja musi otrzymać jawne zatwierdzenie przed ustawieniem `approved_revision_id` i `ACTIVE`.
- Zmiana krytyczna tworzy rewizję `VERIFICATION` i ustawia `pending_revision_id`; wcześniejsza `approved_revision_id` dalej obowiązuje. Po akceptacji wskaźnik zatwierdzonej rewizji zmienia się atomowo.
- Blokada bezpieczeństwa od razu ustawia master na `BLOCKED` i wyłącza nowe użycia niezależnie od zatwierdzonej rewizji. Odbiorcy muszą sprawdzić stan master, nie tylko odczytać rewizję.
- Rewizja przypięta przez recepturę lub snapshot partii ma FK `RESTRICT` i nie może być usunięta. Snapshot zamkniętej partii przechowuje też wartości użyte do kalkulacji, aby historyczny wynik był czytelny nawet po zmianie katalogu.
- Blokada/archiwizacja odrzuca nowe zużycie; zapisane receptury i historyczne partie pozostają niezmienione. Operacje w toku weryfikują status przed commit; safety block jest natychmiastowy.

### 5.8 Precyzja, konwersje, ceny i waluty

1. **Jednostka bazowa:** określona na pierwszej rewizji, np. `g`, `ml` lub `pc`; zmiana jednostki bazowej nie jest zwykłą edycją. Wymaga nowej, jawnej rewizji i sprawdzenia każdej referencji.
2. **Konwersje metryczne:** kontrolowane w słowniku jednostek i jednoznaczne w obrębie wymiaru (np. `kg` → `g`, `l` → `ml`). Przeliczenia między masą i objętością wymagają zatwierdzonej gęstości właściwej dla surowca; „sztuka” wymaga udokumentowanej masy/objętości na sztukę.
3. **Wersja współczynnika:** konwersja jest częścią niezmiennej rewizji. Konsument zapisuje `revision_id`, `unit_code`, `to_base_factor`, ilość wejściową, ilość bazową oraz zastosowaną skalę/zaokrąglenie.
4. **Zaokrąglenie:** pośrednie ilości zachowują do 9 miejsc; ceny, kursy i koszty do precyzji określonej typem `NUMERIC`. PLN jest bazową walutą raportową. Prezentacja finansowa zwykle pokazuje dwie cyfry dziesiętne, ale nie zaokrąglać wartości pośrednich; zachować dokładne wartości obliczeniowe i jawnie zaokrąglać wyłącznie warstwę prezentacji/uzgodniony końcowy punkt księgowy.
5. **Cena referencyjna:** netto za jednostkę bazową, z walutą ISO 4217 i `effective_from`; zapis obejmuje także wyliczoną kwotę PLN oraz niezmienny kurs użyty do raportowania. Konwersja oferty opakowaniowej do jednostki bazowej korzysta z zatwierdzonego przelicznika i zapisuje rewizję.
6. **Koszt historyczny:** każda zamykana partia używa kosztu rzeczywistej skonsumowanej partii magazynowej, gdy jest dostępny. W przeciwnym razie używa historycznej ceny referencyjnej właściwej dla czasu operacji, wyraźnie oznaczonej jako szacowana. Snapshot zachowuje rewizję, źródło, walutę, kurs, ilości/konwersję i obliczone kwoty.
7. **Waluty:** koszt zakupu zachowuje kwotę/walutę źródłową oraz kurs jako niezmienny event; kwota PLN jest wartością raportową, nie zastępuje źródłowej. Nie porównywać/nie agregować różnych walut bez zapisanej konwersji. Źródło kursu i precyzyjny moment obowiązywania muszą być jawnie zapisane; nie nadpisywać kursu historycznego.

## 6. Cykl życia i zatwierdzanie

### 6.1 Stany

| Stan | Znaczenie |
|---|---|
| `DRAFT` | Szkic, niewidoczny jako zatwierdzony surowiec dla operacji produkcyjnych; może zawierać niekompletne dane. |
| `VERIFICATION` | Wysłany do kontroli; walidacje kompletności i dokumentów. Nie jest aktywny. |
| `ACTIVE` | Ma co najmniej jedną zatwierdzoną rewizję i może być używany zależnie od uprawnień/modułów. |
| `BLOCKED` | Natychmiastowa blokada użycia operacyjnego, np. zagrożenie bezpieczeństwa; historia i zatwierdzona rewizja pozostają. |
| `ARCHIVED` | Nieaktywny, wycofany z nowych operacji; historia i identyfikator zachowane. Restore jest możliwy wyłącznie przez osobny autoryzowany workflow. |

`pending_revision_id` nie jest odrębnym stanem master. Rekord istniejący może pozostać `ACTIVE` ze wskazaniem oczekującej rewizji; dotychczasowa rewizja obowiązuje do czasu akceptacji. To zachowuje wymaganie ciągłości zatwierdzonych danych.

### 6.2 Przejścia

| Z | Do | Warunki | Wymagane uprawnienie/audyt |
|---|---|---|---|
| — | `DRAFT` | Utworzenie rekordu i przydział kodu; import zawsze tworzy szkice. | `RAW_MATERIALS_CREATE`; zdarzenie `CREATED`. |
| `DRAFT` | `VERIFICATION` | Wymagane pola walidują się; brak niejawnego założenia „brak alergenów”. | `RAW_MATERIALS_SUBMIT`; `SUBMITTED`. |
| `VERIFICATION` | `DRAFT` | Odrzucenie/poprawki; zapis powodu i listy braków. | `RAW_MATERIALS_REVIEW`; `RETURNED`. |
| `VERIFICATION` | `ACTIVE` | Jawna akceptacja, kompletność deklaracji, wymagane dokumenty; autor może zaakceptować własny rekord. | `RAW_MATERIALS_APPROVE`; `APPROVED` + zdarzenie rewizji. |
| `ACTIVE` | `ACTIVE` | Nowa rewizja niekrytyczna może zostać opublikowana według zatwierdzonej polityki; krytyczna pozostaje pending do zatwierdzenia. | `RAW_MATERIALS_EDIT`; krytyczne dodatkowo `APPROVE`. |
| `ACTIVE` | `BLOCKED` | Natychmiastowa blokada; powód obowiązkowy, transakcja i audyt. | Dedykowane `RAW_MATERIALS_BLOCK`; nie wymaga oczekiwania na pełną rewizję. |
| `BLOCKED` | `ACTIVE` | Usunięto przyczynę, wymagana kontrola/akceptacja i sprawdzenie zatwierdzonej rewizji. | `RAW_MATERIALS_UNBLOCK` + `APPROVE`; nie samoczynnie po upływie czasu. |
| `ACTIVE`/`BLOCKED` | `ARCHIVED` | Brak fizycznego usunięcia; sprawdzenie referencji i ostrzeżenia odbiorców. | `RAW_MATERIALS_ARCHIVE`; audyt. |
| `ARCHIVED` | `VERIFICATION` | Wyłącznie jawna prośba o przywrócenie; zachowuje ten sam master/code, tworzy nową rewizję walidacyjną i wymaga ponownego sprawdzenia aktualnych danych/dokumentów. | `RAW_MATERIALS_RESTORE` i jawna walidacja; audyt `RESTORE_SUBMITTED`. Nie aktywuje bez zatwierdzenia. |
| `VERIFICATION` po restore | `ACTIVE` | Ponowna jawna akceptacja; nie usuwa zdarzenia archiwizacji ani historii. | `RAW_MATERIALS_APPROVE`; audyt `RESTORED`. |

Blokada i archiwizacja zachowują wszystkie zatwierdzone rewizje i referencje; odrzucają nowe użycie. Zmiana jest serializowana ze startem/commitowaniem produkcyjnego zużycia. Trwające operacje muszą zostać ponownie zweryfikowane przed zatwierdzeniem; blokada bezpieczeństwa ma pierwszeństwo i uniemożliwia nowe commit-y, a już utrwalone zużycia i snapshots pozostają niezmienne. Wznowienie bieżących operacji po blokadzie wymaga ponownego sprawdzenia statusu i uprawnień.

### 6.3 Krytyczność zmiany

Serwer wylicza krytyczność z porównania poprzedniej zatwierdzonej rewizji z payloadem. Krytyczne są co najmniej zmiany identyfikacji/danych składnika, jednostki bazowej, konwersji, alergenów, warunków przechowywania i dokumentów stanowiących dowód bezpieczeństwa. Polityka klasyfikacji jest wersjonowana w kodzie; klient nie może ustawić ani wyłączyć `critical_change`. Zmiana krytyczna nie publikuje nowej rewizji bez akceptacji; stara zatwierdzona pozostaje skuteczna, poza natychmiastową blokadą. Ceny i kursy są odrębnymi niezmiennymi zdarzeniami finansowymi, nie modyfikują payloadu rewizji.

## 7. RBAC i zakres

### 7.1 Klucze uprawnień organizacyjnych

Proponowana lista grantów dla `organization_permission_grants`: `RAW_MATERIALS_VIEW`, `RAW_MATERIALS_CREATE`, `RAW_MATERIALS_EDIT`, `RAW_MATERIALS_SUBMIT`, `RAW_MATERIALS_REVIEW`, `RAW_MATERIALS_APPROVE`, `RAW_MATERIALS_BLOCK`, `RAW_MATERIALS_UNBLOCK`, `RAW_MATERIALS_ARCHIVE`, `RAW_MATERIALS_RESTORE`, `RAW_MATERIALS_CATEGORY_MANAGE`, `RAW_MATERIALS_SUPPLIER_MANAGE`, `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`, `RAW_MATERIALS_REFERENCE_PRICE_VIEW`, `RAW_MATERIALS_REFERENCE_PRICE_MANAGE`, `RAW_MATERIALS_PURCHASING_COSTS_VIEW`, `RAW_MATERIALS_PURCHASING_COSTS_MANAGE`, `RAW_MATERIALS_IMPORT`, `RAW_MATERIALS_EXPORT`, `RAW_MATERIALS_DOCUMENT_VIEW`, `RAW_MATERIALS_DOCUMENT_MANAGE`, `RAW_MATERIALS_AUDIT_VIEW`, `RAW_MATERIALS_PERMISSION_DELEGATE`.

Są to klucze zakresu Stage 2A do wdrożenia w `Permission`/walidacji DB po osobnym upoważnieniu. Dotychczasowy `permission_grants` jest lokalizacyjny, więc nie może być użyty jako grant katalogu organizacji. `PURCHASE_PRICES_VIEW` pozostaje istniejącym uprawnieniem lokalizacyjnym dla istniejących przepływów; nie mapuje się automatycznie na `RAW_MATERIALS_PURCHASING_COSTS_VIEW`. Dla encji Surowców koszty zakupu wymagają jawnego org-scoped permission z tabeli powyżej, a cena referencyjna — odrębnego `RAW_MATERIALS_REFERENCE_PRICE_VIEW`. Ani `RAW_MATERIALS_VIEW`, ani UI nie wystarczają do ujawnienia kwot.

### 7.2 Macierz operacji (proponowana, deny-by-default)

| Operacja | Uprawnienie | Kontekst i dodatkowe kontrole |
|---|---|---|
| Lista/szczegóły zatwierdzonych danych | `RAW_MATERIALS_VIEW` | Tożsamość, członkostwo i scope organizacji, aktywny kontekst lokalizacji oraz `RAW_MATERIALS` READY+enabled dla tej lokalizacji. Brak ceny i kosztu zakupu. |
| Odczyt ceny referencyjnej | `RAW_MATERIALS_REFERENCE_PRICE_VIEW` | Niezależny od listy/szczegółu check; brak grantu nie może ujawnić kwoty przez pola, filtrowanie, agregację, cache, log, import ani eksport. |
| Odczyt kosztów zakupu | `RAW_MATERIALS_PURCHASING_COSTS_VIEW` | Odrębny check dla supplier offers/products, importu, eksportu i agregacji. `RAW_MATERIALS_VIEW` oraz `PURCHASE_PRICES_VIEW` nie przyznają automatycznie tego grantu. |
| Tworzenie/edycja szkicu | `RAW_MATERIALS_CREATE` / `RAW_MATERIALS_EDIT` | Org-scoped grant; rola lokalizacyjna nigdy nie wystarcza. |
| Przekazanie do weryfikacji | `RAW_MATERIALS_SUBMIT` | Ponowna walidacja kompletności i wymaganych deklaracji. |
| Weryfikacja/akceptacja | `RAW_MATERIALS_REVIEW` / `RAW_MATERIALS_APPROVE` | Jawna akcja i actor/time. Self-approval jest dozwolone dla aktora jawnie uprawnionego do zatwierdzenia; system nie dodaje blokady rozdziału obowiązków. |
| Blokowanie/odblokowanie | `RAW_MATERIALS_BLOCK` / `RAW_MATERIALS_UNBLOCK` + `APPROVE` | Powód wymagany; blokada bezpieczeństwa ma natychmiastowy skutek; odblokowanie wymaga zatwierdzonej walidacji. |
| Archiwizacja/przywrócenie/kategorie | `RAW_MATERIALS_ARCHIVE` / `RAW_MATERIALS_RESTORE` / `RAW_MATERIALS_CATEGORY_MANAGE` | Restore zawsze zaczyna się w `VERIFICATION` i wymaga ponownego approval; brak usuwania lub zmiany kodu. |
| Dostawcy/produkty/oferty | `RAW_MATERIALS_SUPPLIER_MANAGE`, oddzielne koszty zakupu | Weryfikacja alergenu ma osobny `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE` i wymaga dokumentu dowodowego. |
| Zmiana ceny referencyjnej/kosztu zakupu | `RAW_MATERIALS_REFERENCE_PRICE_MANAGE` / `RAW_MATERIALS_PURCHASING_COSTS_MANAGE` | Rozdzielone kwoty, endpointy i audyt. Każda zmiana to nowy immutable event. |
| Delegowanie grantów | `RAW_MATERIALS_PERMISSION_DELEGATE` | Podmiot delegujący musi być uprawniony; nie może nadać sobie ani przekazać delegacji ponad swój delegowalny zestaw. Każdy grant/cofnięcie ma aktora, zakres, termin i audyt. |
| Dokument | `RAW_MATERIALS_DOCUMENT_VIEW` / `RAW_MATERIALS_DOCUMENT_MANAGE` | Ponowna autoryzacja przy każdym pobraniu; prywatny storage. |
| Import/eksport/audyt | `RAW_MATERIALS_IMPORT` / `RAW_MATERIALS_EXPORT` / `RAW_MATERIALS_AUDIT_VIEW` | Każdy element/kolumna sprawdzane backendowo; ceny wymagają właściwego, oddzielnego permission. |

### 7.3 Integracja z istniejącym RBAC

Istniejące role i granty pozostają rozdzielone: kontekst lokalizacji potwierdza membership i gotowość modułu, a `organization_permission_grants` uprawnia do operacji nad wspólnym katalogiem. Każdy endpoint wywołuje centralny `BusinessAuthorization`/dedykowany policy, który nie dziedziczy uprzywilejowanego skrótu `ADMINISTRATOR`/`BOSS` z istniejącego location-centric `allows()` dla raw-material capabilities; wymagany jest aktywny org-scoped grant. Administrator bez jawnego grantu katalogowego nie otrzymuje automatycznie dostępu do danych ani cen.

Delegowanie jest centralne, ograniczone do jawnego zestawu delegowalnych permission, zakresu jednej organizacji i okresu ważności. Permission delegacji nie może być samo delegowane; grant nie może rozszerzyć authority delegującego; cofnięcie/wygaśnięcie skutkuje natychmiast przy kolejnym żądaniu. Granty organizacyjne nie zastępują polityki membership, aktywności, weryfikacji, lokalizacji ani readiness. Nie tworzyć nowych ról biznesowych w celu obejścia RBAC.

## 8. API (proponowany kontrakt)

Wszystkie endpointy poniżej są projektowane, nie istnieją jeszcze. Użyć `auth:sanctum`, middleware walidującego tożsamość, membership, kontekst operacyjny, grant organizacyjny i `RAW_MATERIALS` READY+enabled dla bieżącej lokalizacji, throttlingu oraz `FormRequest`/serwisów. Każde żądanie sprawdza te warunki od nowa; klucz modułu nie zastępuje RBAC, a RBAC nie omija readiness. JSON ma stabilne klucze po angielsku, a wszystkie wartości `message`/`errors` po polsku. Brak surowych wyjątków SQL, ścieżek plików lub stack trace w odpowiedzi.

### 8.1 Lista i katalog

`GET /api/v1/raw-materials?search=...&category_id=...&lifecycle_state=ACTIVE&allergen=GLUTEN&has_pending_revision=false&sort=name&direction=asc&page=1&per_page=20`

Obsługiwane filtry są allowlistą, limit `per_page` 100 zgodnie z istniejącym wzorcem. Domyślnie zwracana jest wyłącznie zatwierdzona rewizja i dozwolone pola; odpowiedź katalogu nie zawiera ceny referencyjnej ani kosztu zakupu. Odrębne, nazwane endpointy finansowe ujawniają cenę referencyjną wyłącznie z `RAW_MATERIALS_REFERENCE_PRICE_VIEW`, a koszty zakupu wyłącznie z `RAW_MATERIALS_PURCHASING_COSTS_VIEW`. Sortowanie wyłącznie po enumeracji, bez wstrzykiwanego SQL. Filtr lokalizacji nie tworzy osobnego rekordu surowca.

```json
{
  "data": [{
    "id": "uuid",
    "code": "SUR-000001",
    "lifecycle_state": "ACTIVE",
    "name": "Mąka pszenna",
    "category": {"id": "uuid", "name": "Produkty sypkie"},
    "approved_revision": {"id": "uuid", "revision_no": 2},
    "base_unit": {"code": "g", "symbol": "g"},
    "allergen_state": "DECLARED",
    "allergens": [{"code": "GLUTEN", "presence_kind": "DIRECT"}]
  }],
  "links": {"first": "...", "last": "...", "prev": null, "next": "..."},
  "meta": {"current_page": 1, "per_page": 20, "total": 1}
}
```

`GET /api/v1/raw-materials/{id}` zwraca zatwierdzony snapshot. `GET /{id}/revisions` zwraca historię jedynie dla `RAW_MATERIALS_AUDIT_VIEW` lub jawnego dostępu historycznego; pending fields nie mogą ujawnić się nieuprawnionym użytkownikom.

### 8.2 Szkic, rewizja i zatwierdzanie

- `POST /api/v1/raw-materials` — utworzenie szkicu; serwer przydziela kod; nie przyjmuje własnego kodu/`organization_id`/stanu.
- `PATCH /api/v1/raw-materials/{id}/draft` — edycja szkicu. Aktywny rekord aktualizuje się przez tworzenie nowej rewizji, nie nadpisanie zatwierdzonej.
- `POST /api/v1/raw-materials/{id}/submit` — przejście do weryfikacji.
- `POST /api/v1/raw-materials/{id}/revisions/{revisionId}/approve` — body `{"reason":"..."}`; jawne self-approval wspierane.
- `POST /api/v1/raw-materials/{id}/revisions/{revisionId}/return` — body z `reason_code` i listą wymaganych poprawek.
- `POST /api/v1/raw-materials/{id}/block`, `/unblock`, `/archive`, `/restore` — osobne endpointy, reason required, żaden `PATCH lifecycle_state` nie może ominąć workflow. `restore` zachowuje ID i code, tworzy rewizję do ponownej walidacji i ustawia `VERIFICATION`; do `ACTIVE` przechodzi wyłącznie po zatwierdzeniu.
- `GET /api/v1/raw-material-categories` i POST/PATCH kategorii — depth validation do 3; blokować cykliczne przenoszenie.

Przykładowy błąd domenowy:

```json
{
  "error": {
    "code": "RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED",
    "message": "Uzupełnij i zatwierdź deklarację alergenów przed aktywacją.",
    "details": {"field": "allergen_state"}
  }
}
```

`code` jest kontraktem maszynowym, polskie `message` nie zmienia autoryzacji ani nie ujawnia, czy nieuprawniony zasób istnieje.

### 8.3 Ceny, dostawcy, alergeny, dokumenty

- `GET/POST /api/v1/raw-materials/{id}/reference-prices` — `RAW_MATERIALS_REFERENCE_PRICE_VIEW` / `..._MANAGE`; `POST` dodaje zdarzenie ceny i snapshot PLN/kursu (nie modyfikuje wcześniejszego).
- `GET/POST /api/v1/suppliers`, `GET/POST /api/v1/supplier-products`, `GET/POST /api/v1/supplier-products/{id}/compatibility-reviews`, `GET/POST /api/v1/supplier-products/{id}/offers`. Kwoty ofert wymagają oddzielnego `RAW_MATERIALS_PURCHASING_COSTS_VIEW`; tworzenie ofert wymaga `..._MANAGE`.
- `POST /api/v1/raw-materials/{id}/documents` przesyła nową wersję; `POST /documents/{documentId}/versions/{versionId}/approve` akceptuje wersję; `GET /documents/{documentId}/versions/{versionId}/download` autoryzuje i strumieniuje plik.
- `GET /api/v1/raw-materials/{id}/allergens` prezentuje stan jawny (`UNKNOWN` / zatwierdzona deklaracja) oraz oddzielne `DIRECT`/`CROSS_CONTACT_POTENTIAL`.

Endpointy dostawcy nie przyjmują klientowskiego `COMPATIBLE` jako stanu. Wymagają wersji dokumentu dostawcy i aktora z `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`; zapisują niezmienny wynik wraz z aktorem, czasem i rewizją deklaracji, której dotyczy. Odczyt traktuje brak bieżącego zgodnego review dla aktualnej rewizji jako `PENDING`.

### 8.4 Podgląd i operacje zbiorcze

- `POST /api/v1/raw-materials/bulk/preview`: `operation`, selekcja ID lub podpisany zestaw filtrów, parametry zmian, klucz idempotencji. Odpowiedź wyszczególnia dla każdego wiersza `accepted`, `reason_code`, `before_version` i skutki.
- `POST /api/v1/raw-materials/bulk/commit`: potwierdza `preview_id`, `idempotency_key` i oczekiwane wersje. Serwer ponownie autoryzuje i waliduje wszystkie rekordy. Dowolny konflikt powoduje rollback wszystkich zmian.
- `POST /api/v1/raw-materials/imports` przesyła CSV/XLSX prywatnie i zwraca `batch_id`.
- `POST /api/v1/raw-materials/imports/{batchId}/validate`, `GET /.../preview`, `POST /.../commit`.
- `POST /api/v1/raw-materials/exports` tworzy job z formatem, kolumnami, zakresem (`selected`, `filtered`, `all_permitted`) oraz filtrem. `GET /exports/{jobId}`, `GET /exports/{jobId}/download`.

Idempotency key wymagany przy commit/bulk/import. Hash żądania jest przypisany do klucza; ponowienie identycznego żądania zwraca poprzedni wynik, zmienione żądanie z tym samym kluczem zwraca HTTP 409. Przed odpowiedzią z cache idempotencji system ponownie sprawdza aktualny grant, readiness i scope; cofnięty grant nie może ujawnić historycznej odpowiedzi zawierającej cenę/dokument. Podgląd nie jest autoryzacją commit: zmiany uprawnień, danych, limitu widoczności lub wersji w czasie między preview a commit powodują ponowną walidację/odrzucenie.

## 9. Współbieżność, transakcje i integralność

1. Każda mutacja katalogu, grantów organizacyjnych, blokady/archiwizacji/restore i zatwierdzenia rozpoczyna transakcję i blokuje `organizations.id FOR UPDATE` w stałej kolejności. Operacje obejmujące rekordy organizacji nie blokują ich w różnej kolejności.
2. W transakcji ponownie sprawdza się tożsamość/membership, org-scoped RBAC, `RAW_MATERIALS` READY+enabled dla bieżącej lokalizacji, stan, bieżącą rewizję, idempotency key, konflikty i expected version. Walidacja z wcześniejszego preview nie jest zaufana.
3. W tej samej transakcji zapisuje się zmiany, niezmienną rewizję/zdarzenie cen oraz zdarzenia audytu. Jeśli audyt nie zapisze się, cała mutacja się wycofuje.
4. Współbieżne przydziały kodu korzystają z licznika `UPDATE ... RETURNING`; nie używać pobrania maksimum ani licznika w PHP.
5. Idempotency row blokowany/unikalny przed wykonaniem mutacji. Konflikt unikalności jest obsłużony jako kontrolowany konflikt, a nie sukces-shaped fallback.
6. Operacja produkcyjna zatwierdzająca nowe zużycie oraz blokada/archiwizacja surowca muszą dzielić punkt serializacji lub równoważny protokół blokady, by nie powstał wyścig „block vs consume”. Kontrola odbywa się w krótkiej transakcji finalizującej zużycie; parsing, import i długie przetwarzanie nie trzymają blokady.
7. Należy ustanowić ograniczone `lock_timeout` i `statement_timeout` dla operacji importowych/bulk; limity, retry i maksymalny rozmiar importu pozostają do ustalenia na podstawie pomiarów.
8. Długie eksporty i parsowanie plików nie trzymają transakcji DB; parsują do kwarantanny/stagingu, po czym finalny commit wykonuje krótką transakcję. Job ma jawne stany i błędy.
9. Zmiana stanu, zatwierdzenia rewizji i przestawienie wskaźnika `approved_revision_id` to jedna transakcja. Zmiana krytyczna nie może chwilowo odsłonić części pól nowej rewizji.
10. Każda próba/retry ma jeden `operation_id`; audyt rozróżnia próbę od zaakceptowanej zmiany i nie duplikuje transition przy identycznym retry.

Testy wieloprocesowe PostgreSQL powinny odtworzyć wzorzec istniejących testów `ModuleConfigurationConcurrencyTest`: proces A utrzymuje lock, proces B potwierdza oczekiwanie na ten sam wiersz organizacji, a końcowy stan, liczniki, rewizje i zdarzenia audytu są spójne.

## 10. Import, eksport i operacje zbiorcze

### 10.1 Import atomowy

1. Odbierz tylko CSV/XLSX, limit rozmiaru/wierszy, sprawdzenie rozszerzenia i rzeczywistego MIME, nazwy pliku bez ścieżki, hash oraz malware scan przed parsowaniem.
2. Zapisz plik w prywatnej kwarantannie; parser działa z ograniczeniem CPU/pamięci/czasu. XLSX to ZIP: ograniczyć liczbę/rozmiar elementów i odrzucić macros/embedded objects, external links, formule/linked data nieobsługiwane.
3. Parsuj do staging rows, normalizuj, waliduj typy jednostek, kategorie, wymagane pola, aliasy i potencjalne duplikaty. Pokazuj podgląd z numerem wiersza i błędami po polsku.
4. Wiersze importu nie tworzą aktywnych rekordów. Wszystkie nowe rekordy zaczynają w `DRAFT`.
5. Commit ponownie waliduje cały plik w jednej transakcji i tworzy wszystkie dopuszczone szkice albo nic. Jeśli choć jeden wiersz jest nierozstrzygnięty/błędny, commit nie wykonuje częściowej zmiany.
6. Reimport tego samego pliku/operacji z tym samym idempotency key i hash nie duplikuje rekordów. Dla pliku nowego, ale podobnego, wynik duplikatów wymaga jawnego rozstrzygnięcia; brak cichego „upsert” po nazwie.
7. Import nie może ustawić `ACTIVE`, `approved_revision_id`, ceny, zgodności dostawcy ani zweryfikowanych alergenów bez odrębnego workflow.

### 10.2 Eksport

- Zakres: wybrane ID, zapisany/filtr bieżący lub wszystkie rekordy w zakresie uprawnienia.
- Kolumny są allowlistą; cena, dane dostawcy, dokumenty, audyt i alergeny wymagają odpowiednich permissions.
- Zachować ten sam scope/autoryzację, co widok listy; nigdy nie ufać zapisanym filtrom/ID klienta.
- Formaty CSV (UTF-8; jawny separator/escaping), XLSX (komórki tekstowe, ochrona przed formułami) i PDF (język polski, paginacja, data generacji, zakres danych).
- Neutralizować CSV formula injection dla komórek zaczynających się od `=`, `+`, `-`, `@`, tabulatora; nie wkładać tych wartości jako wykonywalnej formuły do XLSX.
- Wynik generować w workerze queue, prywatnie; download endpoint ponownie sprawdza aktora/organizację/uprawnienie i termin wygaśnięcia. Audytuje utworzenie/pobranie bez rejestrowania zawartości.
- Dla eksportu „wszystkie dozwolone” zdefiniować limit/stronicowanie i snapshot semantykę (np. czas filtracji); nie trzymać długiego snapshotu DB bez potrzeby.

## 11. Audyt i integralność historyczna

Każde zdarzenie co najmniej zawiera: organizację, aktora, lokalizację kontekstu (jeśli ma zastosowanie), `operation_id`, typ/ID przedmiotu, kod akcji, rezultat, czas serwera, kod przyczyny i hash stanu przed/po. Wartości audytowe są minimalne; pełne dane osobowe i zawartość pliku nie powinny trafiać do `metadata`.

Zdarzenia obejmują `CREATED`, `REVISION_CREATED`, `SUBMITTED`, `RETURNED`, `APPROVED`, `BLOCKED`, `UNBLOCKED`, `ARCHIVED`, `RESTORE_SUBMITTED`, `RESTORED`, `PERMISSION_GRANTED`, `PERMISSION_REVOKED`, `PRICE_RECORDED`, `EXCHANGE_RATE_RECORDED`, `SUPPLIER_COMPATIBILITY_REVIEWED`, `DOCUMENT_VERSION_ADDED`, `DOCUMENT_APPROVED`, `IMPORT_PREVIEWED`, `IMPORT_COMMITTED`, `BULK_ITEM_ACCEPTED/REJECTED`, `EXPORT_CREATED/DOWNLOADED`. Snapshot kosztu w produkcji jest audytowany przez domenę Production; surowcowe zdarzenie nie zastępuje jego dziennika.

`security_events` pozostaje dla istniejących zdarzeń bezpieczeństwa i Stage 1E, chyba że platforma jawnie zatwierdzi wspólny schemat. Nie przeciążać constraintu zdarzeń modułowych danymi katalogu. Domena Surowców potrzebuje własnej tabeli append-only, kontroli DB na UPDATE/DELETE, testów niezmienności i backupu.

Historia musi zachować:

- stare i nowe rewizje, autora, osobę zatwierdzającą (w tym self-approval), czasy i powód;
- ceny jako zdarzenia obowiązujące od daty oraz ich źródło;
- delegacje/granty organizacyjne, aktora delegującego, grantobiorcę, zakres, ważność i cofnięcie;
- kurs walutowy, źródło, czas obowiązywania i wartość użyta w konkretnym przeliczeniu PLN;
- przeliczniki i reguły zaokrąglania zastosowane historycznie;
- konkretną wersję dokumentu i powiązanie z rewizją;
- identyfikator/code surowca wraz ze stanem archiwizacji;
- snapshoty receptur/partii używające tych identyfikatorów oraz odtworzone kwoty.

## 12. Bezpieczeństwo dokumentów i API

- Pliki przechowywać na prywatnym disk/object storage; nie pod `public/`, nie wystawiać surowego object key/URL do klienta.
- Używać losowych, nieprzewidywalnych kluczy obiektów; nazwa oryginalna jest metadanymi oczyszczonymi, nie ścieżką.
- Walidować rzeczywisty typ pliku, rozmiar, checksum; skanować antymalware; odrzucać HTML/SVG/aktywną zawartość, makra i archiwa o podejrzanej ekspansji.
- Odczyt pobrania zawsze przechodzi przez backendowy check organizacji, dokumentu, wersji i uprawnienia; linki czasowe tylko krótko ważne i bez wycieku do logów.
- Spójność storage/DB: wgrywać do staging key, weryfikować i przenosić do immutable final key; użyć outbox/reconciliation dla awarii między DB a object storage. Zgłaszać jawny błąd; nie pozostawiać rekordów wskazujących brakujący plik.
- Kontrola anty-CSRF/Sanctum i nagłówki sesyjne pozostają zgodne z istniejącym wzorcem. Throttling dla logowania, uploadu, preview/commit, eksportu i pobierania.
- Ograniczyć walidację `sort`, `columns`, `filters`, sort direction i JSON view do allowlisty. Nigdy nie interpolować nazw kolumn z requestu do SQL.
- Wyciszyć stack trace/SQL exception z odpowiedzi. Stabilne kody `error.code`; tekst polski. Frontend używa bezpiecznego, lokalizowanego fallbacku zamiast renderować dowolny tekst serwera.
- Nie ufać ID organizacji, lokalizacji, actor_id, lifecycle, approver, compatibility state, price visibility ani `critical_change` z payloadu.

## 13. Zagrożenia i kontrolki

| Zagrożenie/przypadek nadużycia | Kontrola / dowód testowy |
|---|---|
| Dostęp między organizacjami przez podmianę UUID | FK złożone, scoped query, centralny policy check; test IDOR dla listy/szczegółu/rewizji/pliku/exportu i mutacji. |
| Lokalizacja redefiniuje wspólny katalog lub sam grant daje operacyjny dostęp przy wyłączonym module | Org-scoped capability do master edits oraz niezależna weryfikacja `RAW_MATERIALS` READY+enabled dla bieżącej lokalizacji; testy ról, grantów i stanu modułu razem. |
| Eskalacja przez role lub ukryty parametr | Uprawnienia backendowe na każdej akcji, allowlist grantów, test roli/uprawnienia/stan modułu; klient nie podaje approver/org/state. |
| Cena referencyjna lub koszt zakupu wycieka przez listę, eksport, log lub cache | Oddzielne permissions referencyjne i zakupowe, selektywne pola, tenant-scoped cache keys, test każdej ścieżki response/filter/export/log. |
| Brak alergenów potraktowany jako „bezpieczne” | Jawny `UNKNOWN`, niepusta walidacja przed aktywacją, test pustej listy i braku atestu. |
| Niezgodny supplier product użyty jako składnik | Wymagany review/dowód dla konkretnej rewizji; `PENDING`/`INCOMPATIBLE` blokuje wybór; zmiana deklaracji unieważnia stary review. |
| Replay/duplikat importu lub operacji | Idempotency key + request hash + unique DB constraints; test równoległego replay i zmienionego payloadu. |
| Częściowy import/bulk | Jedna transakcja, pełna rewalidacja, rollback całości; fault injection w środku operacji, sprawdzenie braku częściowych danych i audytu reject. |
| Race na kodzie, cenie, approve/block | Lock organizacji, sekwencja i unikatowość, serializacja; test wieloprocesowy na realnym PostgreSQL. |
| Cofnięcie kosztu/konwersji przez edycję | Append-only price/revision/conversion, immutable snapshots, triggeri i próba UPDATE/DELETE w testach. |
| CSV/XLSX formula injection / zip bomb / malware | Escaping eksportu, limity archiwum/parsera, MIME signature, skaner, kwarantanna, testy plików granicznych. |
| Wstrzyknięcie SQL przez filtr/sort | Enum allowlist i query builder wiązany parametrami; testy fuzz/negative. |
| Wyłudzenie dostępu przez dokument URL | Autoryzowany stream, losowy klucz, brak publicznego bucketu; test dostępu po zmianie user/org i po wygaśnięciu. |
| Wyścig blokady/archiwizacji z nowym zużyciem lub operacją w toku | Wspólny punkt serializacji przy finalizacji zużycia; operacja w toku ponownie sprawdza status tuż przed commit; safety block odrzuca nowe commity, snapshots historyczne pozostają. |
| Przywrócenie archiwum omija ponowne zatwierdzenie | `ARCHIVED -> VERIFICATION`, osobny `RAW_MATERIALS_RESTORE`, wymóg nowego approval, zachowanie starego kodu i audytu; test niedozwolonego bezpośredniego przejścia do `ACTIVE`. |
| Błędny/przedawniony kurs zmienia PLN historyczne | Append-only exchange-rate event i przypięty snapshot stawki/źródła; test immutability i porównania as-of. |
| Wyciek danych przez saved views | Własność scope organizacja/użytkownik, walidacja kolumn/filtrów, ponowna autoryzacja podczas użycia. |

## 14. Plan testów i kryteria regresji

Testy Stage 2B+ muszą wykonywać się na odizolowanych, jednorazowych PostgreSQL/Redis, nie na bazie aplikacyjnej. PostgreSQL jest konieczny do realnego sprawdzenia composite constraints, triggerów, `FOR UPDATE`, exclusion/index behavior i współbieżności. Nie uruchamiać testów destrukcyjnych na lokalnej bazie wdrożeniowej.

| Warstwa | Minimalne testy |
|---|---|
| Migracje/constraints | Tenant FK, RESTRICT, niezmienny kod, zakres statusów, hierarchia do 3 i blokada poziomu 4/cyklu, precyzja numeric, append-only trigger. |
| RBAC | Macierz każdy permission × rola × organizacja/lokalizacja; odczyt ceny odseparowany; denied requests audytowane; brak grantu odmawia. |
| Lifecycle | Ścieżki i niedozwolone przejścia, self-approval, pierwsza akceptacja, pending critical update pozostawia active snapshot, block natychmiastowy, archive bez delete. |
| Jednostki i ceny | Tylko poprawne wymiarowo przeliczenia; gęstość/sztuki jawne; zmiana konwersji nie przepisuje historycznych kalkulacji; cena netto i daty obowiązywania; waluta. |
| Alergeny | `UNKNOWN` nie przechodzi do `ACTIVE`; direct/cross-contact rozdzielone; supplier pending/incompatible blokowany; nowa rewizja unieważnia review. |
| API/Polish | Paginacja 1..100, sort/filter allowlist, polskie 401/403/404/409/422/500 bez stack trace, stabilne `error.code`, frontendowy fallback po polsku. |
| Import/export | CSV/XLSX pozytywny/negatywny, preview bez zapisu, atomic rollback, dedupe, replay, formula injection, za duży/niepoprawny plik, eksport tylko autoryzowanych kolumn i danych. |
| Dokumenty | Autoryzacja downloadu, checksum, MIME, brak public access, retencja wersji, referencje rewizji, awaria storage i spójność DB. |
| Audyt | Każda mutacja ma dokładny event, brak sukcesu bez audytu, wydarzenie per element bulk, append-only, brak sekretów w metadata. |
| Współbieżność | Wieloprocesowy PostgreSQL: równoległy przydział kodu, approve vs edit, block vs użycie w produkcji, cena vs snapshot, import/bulk replay; brak duplikatów i stanu częściowego. |
| Frontend | Tabela, filtry, sort, wybór/zapis widoku, obsługa stron, klawiatura i dostępność; brak renderowania surowych server errors; pełne polskie UI. |
| Regresja Stage 1A–1F | Uruchomić zatwierdzony backend suite oraz lint/build frontendu na izolowanym środowisku; zachować auth/CSRF/RBAC, audit, readiness NOT_READY i locale `pl|pl|pl`. |

Każdy test dotyczący finalnego wyniku/limitu sprawdza wymaganą wartość dokładnie, a nie tylko status HTTP. Dodatkowo odczyt z testowej bazy potwierdza niezmienność rewizji, cen i audytu.

## 15. Stage 1E readiness i dostępność

Zgodnie z zatwierdzonym B5 `RAW_MATERIALS` jest odrębnym kluczem Stage 1E z gotowością i stanem aktywacji per lokalizacja. Ten dokument nie zmienia [DefinedModuleCatalog](./backend/app/Domain/Modules/DefinedModuleCatalog.php), nie włącza usługi i nie ustawia `ready=true`. Moduł pozostaje niedostępny, dopóki wymagana funkcjonalność nie zostanie zaimplementowana, przetestowana i jawnie uznana za gotową. Niewpisany/NOT_READY moduł pozostaje fail-closed; brak stanu lokalizacji oznacza disabled.

Rozdzielić:

1. **Zakres danych:** jeden katalog master w `organization_id`, niezależny od lokalizacji.
2. **Operacyjne prawo użycia:** aktor musi mieć org-scoped permission oraz membership/operating context dla lokalizacji; lokalizacja musi mieć `RAW_MATERIALS` enabled i katalog musi być READY.
3. **Reguła API:** każda permission raw-material ma jawny mapping do klucza `RAW_MATERIALS` (w tym odczyt, ceny, restore i dokumenty). Ponieważ obecny `ModuleDefinition` przechowuje pojedyncze permission kotwiczące, wpis definiuje kotwicę dla aktywacji, a każde żądanie osobno weryfikuje granularny grant; nie wolno uznać samego anchor permission za grant organizacyjny.
4. **Zależności:** przyszłe Recipes/Inventory/Purchasing/Production sprawdzają własną gotowość i własne uprawnienia, a także zależność Surowców tam, gdzie konsumują katalog. Nie odziedziczają automatycznie stanu z kuchni ani innej lokalizacji.
5. **Ready gate:** definicja `RAW_MATERIALS` może wejść do katalogu wyłącznie jako `NOT_READY`. `ready=true` i aktywacja jakiejkolwiek lokalizacji są zabronione, dopóki cały zatwierdzony zakres nie ma działającego API/UI, organizacyjnego RBAC/delegacji, rewizji/lifecycle, audytu, bezpieczeństwa alergenów/dokumentów, cen/kursów, importu/eksportu, regresji oraz niezależnej akceptacji. Test readiness musi dowieść braku dostępu przy `NOT_READY` i przy braku/wyłączonym stanie lokalizacji. Nie oznaczać Recipes, Purchasing, Inventory, Production ani HACCP jako `READY`, zanim nie spełnią ich własnych kryteriów.

## 16. Magazyn dokumentów i operacyjne recovery

- Preferowany backend storage to prywatny disk/object storage skonfigurowany poza webrootem. Stage 2A nie zakłada konkretnego dostawcy/S3 ani nie dodaje konfiguracji.
- Migracja musi być niezależna od danych plików; w DB przechowuje się metadane/hash/key, nigdy binary payload.
- Backup i restore muszą obejmować spójne metadane DB i obiekty plików oraz umożliwiać sprawdzenie checksum po odtworzeniu.
- Proces porządkowania osieroconych plików powinien być raportujący/bezpieczny, z dry-run i jawnie zatwierdzonym usuwaniem wyłącznie plików nieprzypisanych i poza okresem retencji.
- Wymagane są limity quota, alerty storage, audyt pobrań i plan rotacji/retencji. Szczegóły RPO/RTO i retencji wymagają polityki operacyjnej.

## 17. Strategia migracji

1. **Stage 2B, bez danych biznesowych:** migracje namespaced, niezmienny master/revisions, liczniki, kategorie, jednostki, lifecycle, audyt oraz org-scoped grants/delegation. Permission allowlist i checki są fail-closed; nie bootstrapować szerokich grantów. Dodanie definicji `RAW_MATERIALS` pozostaje `NOT_READY`. Testy migracji na odizolowanym PostgreSQL.
2. **Stage 2C:** supplier/product/offers/reference-price events oraz exchange-rate events jako nowe tabele; przechować ceny zakupowe waluty źródłowej i przeliczenie PLN. Nie zmieniać istniejących schematów zakupowych poza zakresem.
3. **Stage 2D:** słownik alergenów, deklaracje/atesty, immutable compatibility reviews, storage variants oraz wersje dokumentów; dowód dostawcy i prywatny storage przed akceptacją zgodności.
4. **Stage 2E:** staging importu, idempotency/audit/export jobs; limity operacyjne i zależności parserów zatwierdzone; atomowe commit-y sprawdzone pod concurrency.
5. **Stage 2F:** React UI pod stabilne API; saved views i uprawnienia egzekwowane backendowo.
6. **Readiness/deployment:** dodać `RAW_MATERIALS` do definicji Stage 1E jako `NOT_READY`, a następnie — dopiero po wszystkich wymaganych kryteriach, regresji, security review i osobnej zgodzie — oznaczyć READY i aktywować per lokalizacja. Migracje live, wdrożenie i zmiana stanów modułów wymagają oddzielnej autoryzacji operacyjnej.

Wszystkie migracje powinny mieć jasno udokumentowane rollbacki. Dla append-only danych biznesowych preferować forward recovery; rollback, który kasuje historię/ceny/referencje, nie może być automatyczny. Nie uruchamiać `migrate:fresh`, `refresh`, `reset` ani `rollback` na bazie lokalnej aplikacji.

## 18. Roadmapa Stage 2B–2F

### Stage 2B — Katalog, kategorie, jednostki, lifecycle i akceptacje

- **DB:** organizacyjny master, niezmienne kody/licznik, kategorie do 3 poziomów, rewizje, aliasy, słownik/jednostki, lifecycle, audyt i `organization_permission_grants` wraz z historią delegacji. Nie implementować tabel produkcyjnych kosztów ani dostawców/cen w tym pakiecie.
- **API:** lista/szczegóły, kategorie, draft/revision, submit/return/approve, block/unblock/archive/restore; każda trasa wymaga org grant i aktywnego/READY `RAW_MATERIALS` dla kontekstu lokalizacji; żadnych endpointów cen lub kosztów w 2B.
- **RBAC:** jawne granty organizacyjne, kontrolowana delegacja, self-approval dla uprawnionego aktora; role lokalizacyjne oraz ogólny administrator nie omijają grant checks. Odczyt katalogu nie ujawnia żadnych cen.
- **Testy:** migracje/constraints, tenant isolation, delegate privilege boundary, membership × readiness × permission, lifecycle i restore, self-approval, pending revision, audit immutability, idempotencja i wieloprocesowy lock/kod.
- **Akceptacja:** brak duplikatów kodów w równoległych operacjach; brak fizycznego delete/zmiany kodu; odrzucenie nowych użyć materiału zablokowanego/zarchiwizowanego; restore wymaga ponownego approval; brak dostępu, gdy moduł nie jest READY/enabled; API/errors po polsku. Definicja `RAW_MATERIALS`, jeśli dodana w tym pakiecie, pozostaje `NOT_READY`.

### Stage 2C — Dostawcy, opakowania i ceny

- **DB:** dostawcy, produkty opakowaniowe, oferty, historyczne price events, kursy wymiany i jawne grants widoczności/zmiany ceny.
- **API:** CRUD zgodny z lifecycle, append-only oferta/cena, filtrowanie i wyraźne oddzielenie ceny referencyjnej od zakupu.
- **RBAC:** supplier management, `RAW_MATERIALS_REFERENCE_PRICE_VIEW/MANAGE` i odrębne `RAW_MATERIALS_PURCHASING_COSTS_VIEW/MANAGE`.
- **Testy:** append-only cena/kurs, as-of, foreign exchange, precyzja bez przedwczesnego zaokrąglenia, org isolation, nieautoryzowane response/export.
- **Akceptacja:** koszt zakupu zachowuje wartość waluty źródłowej; raport PLN ma przypięty immutable kurs; żaden reader bez właściwego, odrębnego permission nie otrzymuje kwoty.

### Stage 2D — Alergeny, przechowywanie, dokumenty i wersjonowanie

- **DB:** słownik alergenów, deklaracje direct/cross-contact/unknown, compatibility reviews, warianty storage, document/version/effective dates i rewizji.
- **API:** deklaracje i atesty, review zgodności, upload/approve/download wersji dokumentu, zatwierdzone storage variants.
- **RBAC:** jawne uprawnienia zatwierdzania deklaracji/dokumentów i `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`; zgodność zatwierdza autoryzowana osoba na podstawie dokumentu dostawcy.
- **Testy:** brak = unknown, niekompatybilność blokuje użycie, zmiana rev wymaga review, checksum/MIME, file security, prywatny download, niezmienność wersji.
- **Akceptacja:** żaden surowiec nie staje się aktywny przy `UNKNOWN`; dowód zgodności dostawcy jest zatwierdzony przez uprawnionego aktora dla dokładnej rewizji; żaden dokument nie jest publiczny; historyczne powiązania pozostają czytelne.
- **Zależność:** B3 jest zatwierdzona; pełne supplier compatibility wymaga encji produktów Stage 2C i dokumentów Stage 2D. Nie ustawiać zgodności na podstawie samego requestu autora.

### Stage 2E — Import, eksport i atomowe operacje zbiorcze

- **DB:** batches/rows, idempotency, job status, saved query snapshots, export results i audit per item.
- **API:** upload, validate, preview, commit, bulk preview/commit i export job/download; retry semantics.
- **RBAC:** import/export per operation; pola i rekordy filtrowane według uprawnień na backendzie.
- **Testy:** atomic all-or-none, concurrency/replay, duplicate detection, formula injection, malformed/oversized files, parser failure, per-item audit, no unauthorized columns.
- **Akceptacja:** dowolny błąd/konflikt uniemożliwia częściowy zapis; retry nie tworzy duplikatów; polski podgląd i błędy.
- **Zależność:** wymaga stabilnego modelu Stage 2B–2D i zatwierdzonego wyboru parserów/limitu pliku.

### Stage 2F — Polski interfejs i zapisane widoki

- **DB/API:** `raw_material_saved_views`, endpoints CRUD, walidacja schematu filtrów i kolumn; API katalogu powinno być stabilne po wcześniejszych etapach.
- **Frontend:** tabela-first, wyszukiwanie zaawansowane, filtry, sortowanie, wybór kolumn, widoki prywatne/współdzielone, paginacja; formularze workflow i plików; komunikaty po polsku.
- **RBAC:** UI ukrywa niedostępne akcje, lecz backend zawsze ponownie autoryzuje. Widok współdzielony nie przyznaje dostępu do danych.
- **Testy:** komponenty, kontrakty API, dostępność klawiaturą, stany ładowania/błędów, polskie treści, RBAC, build/lint i E2E katalogu.
- **Akceptacja:** kompletne przepływy bez surowych komunikatów serwera, filtrowanie/sortowanie deterministyczne, zapisany widok nie omija uprawnień.
- **Zależność:** API i katalog w Stage 2B–2E; rozpoczęcie makiet/kontraktów może następować równolegle, ale finalna integracja po stabilizacji endpointów.

### Korekty zależności do proponowanej kolejności

Podana kolejność 2B→2F pozostaje poprawna. Jawne zależności:

1. Stage 2B ustala granice rewizji/master oraz org RBAC i feature gate. Rewizja musi od początku pozwalać na immutable allergen/storage/document associations, wdrażane w 2D.
2. Workflow zgodności produktów dostawców w Stage 2D wymaga encji produktów Stage 2C oraz zaakceptowanych dokumentów dostawcy Stage 2D.
3. Production snapshots z B4 należą do przyszłego pakietu Production, nie są częścią Stage 2B ani tabel katalogu. Kontrakt jest ustalony, lecz implementację źródła inventory batch i kosztów wykonuje domena Production/Inventory.
4. Kursy walutowe i snapshot PLN wymagają Stage 2C; wybrane źródło i reguła effective time muszą zostać zapisane w kontrakcie finansowym przed wdrożeniem, bez zmiany B7.

Dokumenty wersjonowane są potrzebne w 2D, ale mechanizm prywatnego storage/upload można wcześniej prototypować bez publicznego dostępu i bez zmiany bazy live. Import/eksport w 2E zależy od stabilnego schematu pól i uprawnień; UI 2F może być prototypowane wcześniej, lecz produkcyjne endpointy muszą zachować finalne kontrakty.

## 19. Warunki odbioru architektury i rekomendacja

**Rekomendacja Stage 2A:** B1–B8 są zatwierdzone i niniejsza architektura przedstawia ich wiążące konsekwencje. **ACCEPT — można rozpocząć Stage 2B po niezależnym przeglądzie architektury i osobnej autoryzacji implementacji.** Zakres 2B jest ograniczony do wspólnego katalogu, revisions, kategorii/jednostek, lifecycle, org RBAC/delegation, append-only audit oraz integracji z fail-closed readiness `RAW_MATERIALS`. Nie obejmuje cen/dostawców (2C), workflow dowodów alergenów/dokumentów (2D), importów/eksportów (2E), UI (2F), tabel kosztów Production, zmian danych live ani oznaczania modułu READY.

Przed wdrożeniem Stage 2C trzeba wybrać autorytatywne źródło kursu oraz dokładną regułę czasu `effective_at`; są to szczegóły wykonawcze, a nie odwrócenie B7. Stage 2D musi wdrożyć zatwierdzoną ocenę zgodności z dokumentacją dla dokładnej rewizji alergenu. Production/Inventory muszą uzgodnić transakcyjny punkt kontroli blokady i realnego kosztu partii. To są jawne zależności integracyjne, nie nierozstrzygnięte decyzje B1–B8.

**Nie wykonano implementacji Stage 2B.** Ten dokument jest specyfikacją do przeglądu; nie zmienia żadnego stanu aplikacji ani Stage 1A–1F.
