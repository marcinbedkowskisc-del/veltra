# VELTRA 2.0 — statyczny przegląd bezpieczeństwa migracji 024

**Data:** 4 listopada 2026
**Zakres:** statyczna inspekcja migracji 024, powiązanych definicji PostgreSQL, serwisów i tras Laravel, testów oraz trzech wcześniejszych raportów.
**Tryb:** wyłącznie odczyt. Nie uruchamiano testów, migracji, exploitów ani zapytań adwersarialnych. Nie zmieniano baz, kontenerów, wolumenów, konfiguracji ani kodu źródłowego aplikacji.
**Wniosek:** przegląd statyczny wskazuje na istotne luki w granicy SQL-only, zwłaszcza w przejściu materiału z `BLOCKED` do `ACTIVE` oraz w zgodności produktów/ofert z ich historią. Nie jest to niezależny werdykt akceptacyjny. **Re-audyt runtime pozostaje niezakończony.**

## Metoda i ograniczenia dowodowe

Przejrzano:

- [migrację 024](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php);
- [migrację rdzenia RAW_MATERIALS 011](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php), [migrację domeny dostawców i ofert 013](backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php), [migrację walidacji rewizji produktów 014](backend/database/migrations/2026_11_03_000014_enforce_active_supplier_product_revisions.php) oraz dalsze ACL w migracjach 022–023;
- [RawMaterialAuthorization](backend/app/Domain/RawMaterials/RawMaterialAuthorization.php), [RawMaterialService](backend/app/Domain/RawMaterials/RawMaterialService.php), [RawMaterialCommercialService](backend/app/Domain/RawMaterials/RawMaterialCommercialService.php), [RawMaterialController](backend/app/Http/Controllers/Api/V1/RawMaterialController.php), trasy API i wskazane testy;
- wcześniejszy [niezależny audyt 023](VELTRA_STAGE_2C_MIGRATION_023_INDEPENDENT_SECURITY_AUDIT.md), [raport remediacji 023](VELTRA_STAGE_2C_MIGRATION_023_REMEDIATION_REPORT.md) oraz [decyzję architektoniczną](VELTRA_STAGE_2C_SECURITY_ARCHITECTURE_DECISION.md).

Wyniki wcześniejszych raportów są przytoczone jako **historyczne, nieweryfikowane ponownie**. Statyczna inspekcja nie dowodzi rzeczywistych ACL, aktywności triggerów, transakcyjności w wdrożonej instalacji ani zachowania PostgreSQL 17 w runtime. Nie ustalono, czy migracje 022–024 zastosowano w trwałych środowiskach.

## Podsumowanie ustaleń statycznych

| ID | Obszar | Klasyfikacja | Ważność w zatwierdzonym modelu | Stan dowodów |
|---|---|---|---|---|
| S-1 | Schema resolution migracji 024 | Częściowo poprawne; zakres kontroli ma ograniczenia | Niskie / do weryfikacji | Wniosek ze źródła, bez testu PostgreSQL |
| S-2 | Warunki wstępne i atomowość 024 | Transakcja i kontrole są widoczne w kodzie; pełny fail-closed nie jest statycznie dowiedziony | Średnie wdrożeniowe | Poprzedni raport deklaruje test, niezależnie niewykonany tutaj |
| F2-1 | Bezpośrednie przejście `raw_materials` z `BLOCKED` do `ACTIVE` | **BLOCKER** | Średnie | Dozwolone przez kod triggera; brak niezależnej reprodukcji |
| F2-2 | `supplier_products` bez wymaganej rewizji początkowej | **BLOCKER** dla spójności katalogu | Średnie | Brak warunku kompletności w DDL; poprzedni raport podaje wcześniejszą reprodukcję INSERT |
| F2-3 | `supplier_offers` — stan oferty a ledger zdarzeń | **BLOCKER** dla spójności audytu | Średnie | Brak widocznego sprzężenia triggera zachowania z eventem; brak testu runtime |
| F2-4 | `organization_suppliers` — edycja danych kontrahenta poza ścieżką audytowaną | **ACCEPTABLE RESIDUAL RISK** warunkowo | Niskie–średnie | Zakres mutowalnych kolumn wynika ze źródła; poprzedni raport deklaruje zmianę nazwy bez eventu |
| F2-5 | `raw_material_code_counters` — manipulacja licznikiem | **ACCEPTABLE RESIDUAL RISK** z jawnym wpływem dostępności | Niskie–średnie | DDL nie wiąże licznika z istniejącymi kodami; poprzedni raport deklaruje skuteczny UPDATE |
| F2-6 | `raw_material_categories` — nazwy/kategorie bez śladu audytu | **ACCEPTABLE RESIDUAL RISK** | Niskie | DDL i serwis; poprzedni raport deklaruje bezpośrednią zmianę nazwy |
| F2-7 | `raw_material_revisions` | **ACCEPTABLE RESIDUAL RISK** dla wersji roboczej; hash niepoświadczony przez DB | Średnie | DDL ogranicza strukturę i fazę roboczą; wcześniejszy raport deklaruje INSERT bez Laravel |
| F2-8 | `raw_material_revision_units` | **ACCEPTABLE RESIDUAL RISK** ograniczone do rewizji roboczej | Niskie–średnie | Walidacja strukturalna jest w DDL; wcześniejszy raport deklaruje INSERT |
| F2-9 | `raw_material_aliases` | **ACCEPTABLE RESIDUAL RISK** ograniczone do rewizji roboczej | Niskie–średnie | Unikalność i ograniczenie do rewizji roboczej; wcześniejszy raport deklaruje INSERT |
| A-1 | Autoryzacja wejść Laravel | Centralny serwis autoryzacji jest używany w widocznych ścieżkach API; pełna kompletność wszystkich callerów niepotwierdzona | Brak potwierdzonego obejścia API w przeglądanym zakresie | Statyczna inspekcja tras i serwisów |
| T-1 | Pokrycie testami | Są testy granicy i domeny; ich wykonania tutaj nie było | Luka dowodowa | Wyniki tylko z poprzedniego raportu |

## S-1 — schema resolution w migracji 024

### Obserwacje ze źródła

- Funkcja `public.raw_materials_preserve_master()` ma jawne `SET search_path = pg_catalog, public, pg_temp` i używa kwalifikowanych nazw `public.raw_material_revision_approval_events` oraz `public.raw_material_revisions` przy sprawdzaniu zatwierdzenia ([migracja 024, linie 124–128 i dalej](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php#L124)).
- Wartość `pg_temp` jest umieszczona na końcu listy; `pg_catalog` jest jawnie pierwszy. To jest zamierzone utwardzenie wobec wcześniejszej walidacji opartej na niekwalifikowanych nazwach, opisanej w raporcie audytu 023.
- `veltra_private.validate_role_assignment_ticket()` ma `SET search_path = pg_catalog, public, veltra_private, pg_temp` ([migracja 024, linia 77](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php#L77)). Funkcja jawnie kwalifikuje odczyt tabeli kluczy jako `veltra_private.mutation_signing_keys` i chroni wbudowane katalogi pierwszeństwem `pg_catalog`.
- Migracja wyszukuje funkcje triggerów w schemacie `public`, przypisane do jawnej listy tabel integralności, ustawia im stały `search_path`, a następnie sprawdza ich `proconfig` ([migracja 024, linie 182–232](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php#L182)).

### Ocena i brakujące dowody

Kod migracji 024 poprawia rozpoznawanie obiektów w walidatorze materiałów i ustawia kolejność schematów dla wykrytych funkcji triggerów. Bezpieczne użycie `public` i `veltra_private` zależy jednak od rzeczywistych ACL tych schematów; migracja 024 sama nie sprawdza, czy rola runtime nie ma `CREATE` w `public`, czy prywatny schemat nie jest zapisywalny ani czy właściciele funkcji/tabel są zgodni z założeniami. Te kontrole były opisane w migracji 022 i wcześniejszej macierzy uprawnień, ale nie zostały niezależnie odczytane z runtime w tym przeglądzie.

Kontrola `unsecuredFunctionCount` obejmuje funkcje `public` podpięte triggerami do nazw z `INTEGRITY_TABLES`. Nie jest to ogólny audyt wszystkich funkcji triggerów we wszystkich schematach ani wszystkich funkcji `SECURITY DEFINER`. Zakres migracji wydaje się zgodny z intencją dla istniejących tabel, ale należy go traktować jako listę kontrolowaną, a nie uniwersalną gwarancję.

**Brakująca weryfikacja:** faktyczne ACL, właścicielstwo, aktywność triggerów, `search_path` wszystkich funkcji triggerów i rozstrzyganie nazw w działającym PostgreSQL. Historyczny raport remediacji podaje wynik testu `pg_temp` po 024, lecz nie jest to niezależne wykonanie w tym zadaniu.

## S-2 — preconditions, błędy i atomowość migracji

### Obserwacje ze źródła

- `up()` wykonuje `assertPrerequisites()`, zmianę scope’ów, definicje funkcji i kontrole końcowe wewnątrz `DB::transaction()` ([migracja 024, linie 66–71](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php#L66)).
- Precondition sprawdza obecność wymaganych relacji prywatnych/publicznych, wpisy migracji 022–023, dokładnie jeden 32-bajtowy klucz oraz liczbę triggerów dla chronionych tabel ([migracja 024, linie 276–325](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php#L276)).
- Następnie sprawdzane są nieoczekiwane triggery oraz istnienie relacji publicznych dla tabel z listy ([migracja 024, linie 327–365](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php#L327)).
- Scope’y dla `role_assignments` są usuwane i ponownie wstawiane w ramach tej samej otaczającej transakcji ([migracja 024, linie 237–269](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php#L237)).

W PostgreSQL DDL obsługiwany transakcyjnie oraz opakowanie zmian w transakcję stanowią dobry statyczny argument za atomowością. Jest to jednak wniosek warunkowy: zależy od połączenia migracyjnego, silnika, konfiguracji runnera migracji i od tego, że zapis wersji migracji następuje dopiero po powodzeniu `up()`.

**Nie wykazano statycznie**, że wszystkie błędne warianty konfiguracji są objęte kontrolami: np. nieprawidłowy właściciel funkcji, ACL schematów, nieoczekiwany domyślny privilege grant, brakujące kolumny istniejącej relacji lub częściowo zmieniony zestaw triggerów. Poprzedni raport remediacji podaje, że brak prywatnej infrastruktury został odrzucony bez częściowych zmian; wynik nie był powtarzany.

## F-2 — decyzja per tabela

Ocena odnosi się do zatwierdzonego modelu: Laravel jest zaufaną granicą decyzji biznesowych; PostgreSQL ma zapewniać najmniejsze uprawnienia, integralność danych i wybrane zabezpieczenia SQL-only. Sam brak HMAC nie jest podstawą do uznania tabeli za podatną.

### 1. `organization_suppliers` — ACCEPTABLE RESIDUAL RISK, z warunkami

- **Źródło:** DDL w [migracji 013, linie 105–124](backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php#L105) oraz trigger zachowania w [liniach 922–960](backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php#L922). Trigger utrwala identyfikator, organizację, kod i autora, blokuje usunięcie oraz cofnięcie archiwizacji; nie blokuje zmian `display_name`, `legal_name` ani `tax_identifier_ciphertext`.
- **Laravel:** `createSupplier()` i `archiveSupplier()` są w serwisie handlowym i przechodzą wspólny helper `mutate()`, który sprawdza `SupplierManage` oraz zapisuje idempotencję/audyt ([serwis, linie 74–145 i 896–956](backend/app/Domain/RawMaterials/RawMaterialCommercialService.php#L74)). Nie widać publicznej trasy edycji danych dostawcy.
- **Ryzyko:** bezpośredni runtime SQL może zmienić nazwę/identyfikator bez eventu audytowego. Wcześniejszy raport remediacji deklaruje reprodukcję zmiany `display_name` bez nowego eventu, ale nie testował zmiany zaszyfrowanego identyfikatora.
- **Klasyfikacja:** **ACCEPTABLE RESIDUAL RISK** tylko dla przyjętego modelu, w którym SQL-only może modyfikować część niechronionych danych domenowych i nie ma twierdzenia o pełnej ochronie przed dowolną iniekcją SQL. Ryzyko dla identyfikatora podatkowego jest istotniejsze niż dla nazwy.
- **Brakująca weryfikacja:** potwierdzenie, czy `tax_identifier_ciphertext` może być użyty przez funkcje finansowe/raportowe i jak aplikacja obsługuje uszkodzony ciphertext; ocena, czy zmiana dostawcy powinna mieć niezależny ślad.
- **Zalecenie:** jeśli dane te są źródłem rozliczeń lub zgodności, odbierać bezpośredni UPDATE nieużywanych kolumn albo wymagać wąskiej ścieżki aktualizacji z niezmiennym eventem audytu. Bez takiej potrzeby nie rozszerzać ogólnego mechanizmu podpisów.

### 2. `supplier_products` — BLOCKER dla kompletności produktu

- **Źródło:** tabela w [migracji 013, linie 126–153](backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php#L126) ma stan domyślny `ACTIVE`, klucze obce organizacji oraz trigger zachowania, ale nie wymaga istnienia rewizji produktu. Rewizje są przechowywane oddzielnie w `supplier_product_revisions` i tworzone przez aplikację.
- **Laravel:** `createProduct()` tworzy w transakcji wiersz produktu, następnie rewizję i event audytu ([serwis, linie 199–245](backend/app/Domain/RawMaterials/RawMaterialCommercialService.php#L199)). To pokazuje, że poprawna operacja biznesowa zakłada kompletność pary produkt–rewizja.
- **Ryzyko:** INSERT runtime do `supplier_products` może pozostawić aktywny produkt bez żadnej rewizji i bez zdarzenia audytowego. Trigger tworzenia oferty wymaga najnowszej rewizji produktu oraz zgodności z zatwierdzonym materiałem ([migracja 013, linie 555–587](backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php#L555)), co ogranicza dalsze użycie takiego rekordu, ale nie przywraca kompletności ani audytu produktu.
- **Istniejące dowody:** raport remediacji 023 podaje wcześniejszy bezpośredni INSERT produktu, który zakończył się stanem `ACTIVE`; jest to dowód historyczny, nie niezależnie powtórzony tutaj.
- **Klasyfikacja:** **BLOCKER** dla integralności domeny: baza nie wymusza, aby zatwierdzona transakcja tworzenia produktu zakończyła się z wymaganym pierwszym snapshotem rewizji.
- **Zalecenie:** dodać wąskie wymuszenie kompletności na granicy transakcji (np. odroczony constraint trigger, zgodny z sekwencją insertu produktu i rewizji) lub zmienić model tak, by nowy produkt nie był aktywny przed utworzeniem rewizji. Następnie testować rollback, współbieżność i legalny bootstrap.

### 3. `supplier_offers` — BLOCKER dla spójności stanu i historii

- **Źródło:** tabela w [migracji 013, linie 200–216](backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php#L200) ma `lifecycle_state` oraz `offer_reference`. Trigger `supplier_offers_preserve` blokuje zmianę tożsamości i cofnięcie stanu `WITHDRAWN`, lecz nie wymaga powiązanego zdarzenia ceny/wycofania ([linie 937–976](backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php#L937)). Walidator INSERT sprawdza aktywnego dostawcę, produktu i zatwierdzonego surowca, a eventy cenowe są osobną append-only historią.
- **Laravel:** tworzenie oferty i zapis/wycofanie ceny przechodzą `RawMaterialCommercialService::mutate()` oraz tworzą zdarzenia domenowe; ceny nie są trzymane jako edytowalne pola w rekordzie oferty.
- **Ryzyko:** bezpośredni UPDATE może zmienić `offer_reference` albo przełączyć ofertę na `WITHDRAWN` bez odpowiedniego eventu i audytu. Daje to rozbieżność między bieżącym stanem rekordu a niezmiennym ledgerem zdarzeń; może też sprawić, że dalsze eventy zostaną odrzucone przez walidator oczekujący aktywnej oferty.
- **Istniejące dowody:** wcześniejszy raport remediacji nie wykonał poprawnego INSERT oferty ani manipulacji stanem/ceną; dlatego ten wpływ jest wnioskiem statycznym.
- **Klasyfikacja:** **BLOCKER** dla spójności historii handlowej, jeśli `lifecycle_state` jest autorytatywnym stanem oferty.
- **Zalecenie:** uczynić stan wycofania pochodnym od eventów albo dodać DB niezmiennik, który wymaga zgodnego eventu w tej samej transakcji. Nie przenosić zapisu ceny do ogólnego ticketu.

### 4. `raw_material_categories` — ACCEPTABLE RESIDUAL RISK

- **Źródło:** DDL i constrainty kodu, nazwy, głębokości, organizacji/rodzica znajdują się w [migracji 011, linie 11–29](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php#L11); triggery walidują hierarchię i chronią tożsamość. Nazwa pozostaje mutowalna.
- **Laravel:** `createCategory()` i `renameCategory()` sprawdzają odpowiednio `CategoryManage`, blokują wiersz/organizację i dopisują audyt ([serwis, linie 29–88](backend/app/Domain/RawMaterials/RawMaterialService.php#L29)).
- **Ryzyko i dowód:** UPDATE nazwy bez audytu jest zgodny z DDL; poprzedni raport remediacji deklaruje, że taka próba powiodła się.
- **Klasyfikacja:** **ACCEPTABLE RESIDUAL RISK** dla etykiet i organizacyjnej taksonomii, pod warunkiem zaakceptowania, że runtime SQL może obejść autoryzację Laravel. To nie jest naruszenie strukturalnej hierarchii, ale pozostaje utrata audytowalności.
- **Zalecenie:** jeśli nazwy kategorii mają znaczenie regulacyjne lub audytowe, wymagać eventu stanu; w przeciwnym razie udokumentować świadomą akceptację.

### 5. `raw_material_code_counters` — ACCEPTABLE RESIDUAL RISK z wpływem DoS

- **Źródło:** [migracja 011, linie 72–78](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php#L72) ogranicza prefix i zakres licznika, lecz nie wiąże `last_value` z maksymalnym kodem istniejących materiałów ani nie ustanawia monotoniczności.
- **Laravel:** tworzenie materiału bierze blokadę licznika, zwiększa wartość i generuje `SUR-...` ([serwis, linie 202–235](backend/app/Domain/RawMaterials/RawMaterialService.php#L202)).
- **Ryzyko i dowód:** bezpośredni UPDATE może przesunąć licznik, wywołać kolizję z unikalnym kodem albo ustawić wartość graniczną, po której tworzenie kończy się błędem pojemności. Poprzedni raport remediacji deklaruje, że bezpośrednia zmiana licznika powiodła się.
- **Klasyfikacja:** **ACCEPTABLE RESIDUAL RISK** w obecnym modelu, z jawnym ryzykiem dostępności i możliwą ręczną naprawą licznika. Nie wykazano możliwości utworzenia duplikatu kodu, bo ogranicza ją unikalność organizacja/kod; jednak konflikt może blokować kolejne tworzenie.
- **Zalecenie:** rozważyć trigger monotoniczności i zgodności z istniejącymi kodami lub odebranie bezpośredniego DML na liczniku, jeżeli odporność na SQL-only ma obejmować odmowę DoS tej funkcji.

### 6. `raw_materials` — BLOCKER dla przejścia odblokowania

- **Źródło:** DDL wymusza stany, FK i podstawowe pola blokady w [migracji 011, linie 31–69](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php#L31). Trigger `raw_materials_preserve_master()` zakazuje określonych przejść, ale dopuszcza `BLOCKED → ACTIVE` ([migracja 024, linie 127–176](backend/database/migrations/2026_11_05_000024_harden_raw_material_integrity_search_path.php#L127)). Warunek eventu zatwierdzenia jest uruchamiany, gdy zmienia się `approved_revision_id`, a nie przy samym przejściu ze stanu `BLOCKED` do `ACTIVE`.
- **Laravel:** operacja `unblock()` w `RawMaterialService` wymaga `RawMaterialPermission::Unblock`, przywraca stan i zapisuje event audytu; inne mutacje również są opakowane w autoryzację domenową.
- **Ryzyko:** runtime ma DML do `raw_materials`. Przy istniejącym zatwierdzonym materiale z `BLOCKED` może zmienić stan z powrotem na `ACTIVE` i wyczyścić pola blokady bez autoryzacji Laravel, bez zmiany zatwierdzonej rewizji i bez eventu audytu. Statyczny kod walidatora nie wymaga osobnego zdarzenia odblokowania.
- **Istniejące dowody:** poprzedni raport potwierdza, że walidacja fałszywego zatwierdzenia przez `pg_temp` po 024 była odrzucana. Nie testował jednak bezpośredniego odblokowania materiału z istniejącą rewizją zatwierdzoną. To odrębna ścieżka.
- **Klasyfikacja:** **BLOCKER** względem gwarancji integralności dla SQL-only. Odblokowanie jest operacją uprzywilejowaną i ma osobną autoryzację/event w aplikacji, lecz nie ma odpowiadającego niezmiennika DB.
- **Zalecenie:** wymusić na przejściu `BLOCKED → ACTIVE` odpowiednie niezmienne zdarzenie/warunek DB albo odebrać bezpośredni UPDATE tej kolumny i wystawić wąski interfejs. Warunek musi zachować legalny przepływ `unblock()` i jego semantykę transakcyjną.

### 7. `raw_material_revisions` — ACCEPTABLE RESIDUAL RISK dla draftów; hash wymaga jawnego traktowania

- **Źródło:** tabela w [migracji 011, linie 80–120](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php#L80) wymusza FK organizacji, unikalną kolejność i format 64-znakowego `content_hash`. Trigger blokuje UPDATE/DELETE i pozwala INSERT tylko do rewizji wskazanej jako bieżąca robocza ([linie 360–385](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php#L360)).
- **Laravel:** tworzenie i rewizja materiału sprawdzają `Create`/`Edit`, walidują kategorie, jednostki, aliasy, generują hash i dopisują audit ([serwis, linie 202–325](backend/app/Domain/RawMaterials/RawMaterialService.php#L202)).
- **Ryzyko:** format `content_hash` jest kontrolowany, ale DDL nie dowodzi, że hash odpowiada zawartości rekordu. INSERT poprawnie powiązanej rewizji roboczej może więc wprowadzić treść/hash bez kontroli aktora i bez aplikacyjnego eventu. Ochrona zatwierdzenia wymaga osobnego eventu zatwierdzenia i zgodności hasha; zapobiega to bezpośredniemu opublikowaniu dowolnej wersji według widocznego triggera.
- **Istniejące dowody:** raport remediacji 023 deklaruje wcześniejszy INSERT rewizji roboczej z bezpośrednim SQL i `content_hash` spełniającym jedynie format.
- **Klasyfikacja:** **ACCEPTABLE RESIDUAL RISK** dla modyfikacji wersji roboczej, jeśli wyraźnie przyjmuje się, że SQL-only może naruszyć zawartość draftu, lecz nie może pominąć ścieżki zatwierdzenia. Uczciwość hash nie jest gwarantowana przez sam CHECK.
- **Zalecenie:** dokumentować, że hash jest identyfikatorem obliczonym przez Laravel, nie dowodem kryptograficznym walidowanym przez DB. Jeśli hash ma stanowić dowód audytowy, wyliczać/weryfikować go w wąskiej funkcji lub przed akceptacją niezależnie.

### 8. `raw_material_revision_units` — ACCEPTABLE RESIDUAL RISK ograniczone do draftu

- **Źródło:** typ, dodatnie współczynniki, jednostki i zgodność dimension są sprawdzane przez DDL/trigger w [migracji 011, linie 137–170 i 389–435](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php#L137). UPDATE/DELETE są blokowane, a INSERT jest dopuszczany dla aktualnej rewizji roboczej.
- **Laravel:** `RawMaterialUnitRegistry::resolve()` waliduje jednostki i przeliczenia przed zapisem przez `RawMaterialService`.
- **Ryzyko:** SQL-only może dodać strukturalnie poprawną jednostkę/przelicznik do draftu bez uprawnienia `Edit` i bez audit eventu. Nie wynika z DDL możliwość zmiany istniejącej jednostki ani dopięcia jej do zatwierdzonej rewizji.
- **Istniejące dowody:** wcześniejszy raport remediacji deklaruje bezpośredni INSERT jednostki roboczej oraz odmowę UPDATE.
- **Klasyfikacja:** **ACCEPTABLE RESIDUAL RISK** ograniczone do niezakończonej rewizji, z zastrzeżeniem, że późniejsza akceptacja musi być świadoma.
- **Zalecenie:** utrzymywać niezmienność po zamknięciu rewizji i wymagać kontroli diffu przez zatwierdzającego; dodatkowe DB API tylko jeśli SQL-only ma nie móc zmieniać draftu.

### 9. `raw_material_aliases` — ACCEPTABLE RESIDUAL RISK ograniczone do draftu

- **Źródło:** DDL wiąże alias z rewizją, materiałem i organizacją; indeks/trigger zapewniają unikalność aliasu znormalizowanego. Trigger dziecka blokuje UPDATE/DELETE i wymaga, aby rewizja była aktualnie robocza ([migracja 011, linie 122–135, 389–452](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php#L122)).
- **Laravel:** aliasy są normalizowane oraz sprawdzane pod kątem duplikatów w `RawMaterialService` przed utworzeniem/rewizją materiału.
- **Ryzyko:** wstawienie aliasu do rewizji roboczej może wpłynąć na późniejsze dopasowanie i wyszukiwanie bez autoryzacji `Edit` i eventu. Ograniczenia wiążą go z właściwą organizacją i nie dopuszczają modyfikacji zamkniętej rewizji.
- **Istniejące dowody:** poprzedni raport remediacji deklaruje bezpośredni INSERT aliasu w rewizji roboczej oraz odmowę UPDATE.
- **Klasyfikacja:** **ACCEPTABLE RESIDUAL RISK** dla otwartego draftu; wymaga wyraźnego rozumienia, że semantyczna zawartość draftu może zostać zmieniona przez SQL-only.
- **Zalecenie:** sprawdzać diff aliasów przed zatwierdzeniem; nie rozszerzać HMAC jako ogólnej ochrony.

## A-1 — autoryzacja w obsługiwanych wejściach Laravel

- Trasy `/v1/raw-materials` są grupowane pod `RequireSpaSession`, `auth:sanctum`, `ValidateIdentityContext`, `RequireOperatingContext`, ograniczenie szybkości i `RequireRawMaterialsAvailability` ([trasy API, linie 55–111](backend/routes/api.php#L55)).
- Kontroler przekazuje uwierzytelnionego użytkownika i operating location do serwisów, nie wykonuje samodzielnie zapytań mutujących ([RawMaterialController, linie 45–89 oraz 300–465](backend/app/Http/Controllers/Api/V1/RawMaterialController.php#L45)).
- `RawMaterialAuthorization::assertAllowed()` odświeża aktora i lokalizację, sprawdza aktywność, weryfikację email, wspólną organizację, członkostwo, dostępność modułu i efektywne uprawnienie ([RawMaterialAuthorization, linie 14–42](backend/app/Domain/RawMaterials/RawMaterialAuthorization.php#L14)).
- Serwisy `RawMaterialService` i `RawMaterialCommercialService` wywołują sprawdzenie uprawnienia przed operacją; komercyjny helper `mutate()` powtarza kontrolę wewnątrz transakcji po blokadzie organizacji ([RawMaterialCommercialService, linie 896–956](backend/app/Domain/RawMaterials/RawMaterialCommercialService.php#L896)).

W widocznych ścieżkach HTTP brak oczywistego endpointu mutacji omijającego serwisy domenowe. Nie znaleziono klas Laravel Policy/Gate; autoryzacja jest scentralizowana w domenowym `RawMaterialAuthorization`, co jest zgodne z zatwierdzoną alternatywą. Nie prześledzono wyczerpująco wszystkich jobów, komend, event listenerów i innych wewnętrznych callerów serwisów. Zatem nie stwierdza się, że każdy możliwy caller jest poprawny; stwierdza się jedynie, że analizowane trasy API przekazują kontrolę do usług z widocznymi kontrolami.

## Testy i wcześniejsze dowody

### Pokrycie widoczne w kodzie

- [AuthorizationMutationBoundaryTest](backend/tests/Feature/AuthorizationMutationBoundaryTest.php#L67) zawiera test roli runtime, odmowy na chronionych RBAC/audit tabelach, próbę `pg_temp`, fałszywych GUC oraz legalnych przepływów aplikacyjnych.
- W [tej samej klasie, linie 365–459](backend/tests/Feature/AuthorizationMutationBoundaryTest.php#L365) znajduje się macierz dziewięciu tabel. `setUp()` pomija test, jeśli poświadczenia runtime nie są ustawione ([linie 29–38](backend/tests/Feature/AuthorizationMutationBoundaryTest.php#L29)).
- Istnieją [testy domeny RAW_MATERIALS](backend/tests/Feature/RawMaterialsTest.php) i [testy współbieżności](backend/tests/Feature/RawMaterialConcurrencyTest.php). Sam fakt ich istnienia nie dowodzi, że obejmują nową ścieżkę odblokowania `BLOCKED → ACTIVE`, zgodność wpisów `supplier_products` z rewizjami ani spójność stanu oferty z eventem.

### Historyczne wyniki, niepowtarzane tutaj

Poprzedni raport remediacji 023 podaje: 184 testy / 1619 asercji, 0 pominięć, fresh migracje 001–024, upgrade i fail-closed oraz test granicy z 101 asercjami. Są to deklaracje poprzedniej sesji. Zgodnie z ograniczeniem bieżącego zadania nie uruchomiono ponownie żadnego testu i nie traktuje się tych agregatów jako nowego niezależnego dowodu.

Test z runtime może zostać pominięty przy braku `APP_DB_USERNAME` lub `APP_DB_PASSWORD`; aby wynik wcześniejszej sesji był miarodajny dla bieżącego kodu, trzeba osobno sprawdzić konfigurację runnera i raport PHPUnit. Nie wykonano tej weryfikacji.

## Czy wcześniejsze raporty wspierają ocenę architektury?

Tak — wspierają jedynie **prowizoryczną ocenę architektoniczną**, nie niezależną akceptację:

1. Decyzja architektoniczna poprawnie rozróżnia autoryzację Laravel od gwarancji PostgreSQL i jawnie uznaje ryzyko szerokiego DML runtime.
2. Niezależny audyt 023 historycznie potwierdził exploita `pg_temp` sprzed migracji 024 oraz stwierdził lukę dla dziewięciu tabel.
3. Raport remediacji 023 deklaruje, że migracja 024 zablokowała wskazaną ścieżkę `pg_temp` i fail-closed przy brakujących obiektach, a także opisuje bezpośrednie DML części tabel.
4. Raport remediacji wprost pozostawia F-2 jako otwarte ryzyko i nie uznaje Stage 2C za zaakceptowany.

Statyczny przegląd ujawnia dodatkowe semantyczne przypadki, których historyczne testy opisywane w raporcie nie pokrywają: odblokowanie `raw_materials`, produkt bez rewizji oraz stan `supplier_offers` niezwiązany z ledgerem zdarzeń. Wniosek nie jest dowodem, że exploity zostały wykonane w runtime; jest wskazaniem, że same raporty nie zamykają tych kwestii.

## Zalecane działania przed zamknięciem Stage 2C

### Blokery kodu wskazane statycznie

1. Dla `raw_materials` zdefiniować i wymusić niezmiennik odblokowania `BLOCKED → ACTIVE`, tak aby nie dało się ominąć wymaganej operacji/eventu.
2. Wymusić atomową kompletność produktu z co najmniej jedną prawidłową rewizją przed pozostawieniem produktu w stanie aktywnym.
3. Uzgodnić źródło prawdy dla stanu `supplier_offers`; stan i append-only eventy muszą pozostać spójne także przy DML runtime.
4. Dla tych trzech zmian dodać ukierunkowane testy bezpośredniego DML i pozytywnych legalnych przepływów. To są rekomendacje; w tym zadaniu nie wprowadzono implementacji ani testów.

### Warunki wdrożenia, a nie dowiedzione blokery kodu

- Sprawdzić historię migracji każdej instalacji bez jej zmieniania; migracji już wdrożonych nie przepisywać.
- Przed zastosowaniem 024/nowych migracji uzgodnić dane historyczne z ledgerami i źródłami audytowymi. Tekstowa atestacja w bazie nie dowodzi rzetelności przeglądu.
- Z rzeczywistymi, rozdzielonymi rolami potwierdzić ACL `public`, `veltra_private`, ownership, `EXECUTE`, default privileges, aktywność triggerów i stabilność klucza mutacji. Rotacja i zachowanie istniejących workerów pozostają poza tym przeglądem.
- Uruchomić niezależny re-audyt runtime w dozwolonym środowisku, z zapisem dokładnych wyników i bez użycia istniejących baz/wolumenów.

## Konkluzja

Ten dokument jest **statycznym przeglądem kodu i dokumentacji**, a nie testem penetracyjnym ani niezależnym audytem runtime. Nie wydaje werdyktu `ACCEPT` dla Stage 2C ani nie zastępuje niezależnego re-audytu.

**Re-audyt runtime pozostaje outstanding / niezakończony.** Z uwagi na wcześniejsze przerwanie re-audytu 024 przez kontrolę bezpieczeństwa usługi, nie uruchamiano żadnych zablokowanych operacji ani nie próbowano obchodzić ograniczenia. Do niezależnego zamknięcia pozostają runtime weryfikacja `pg_temp`, atomiczności migracji, scope/actor substitution, chronionych ledgerów, revocation/delegation/replay, pełnej macierzy F-2, ACL, migracji fresh/upgrade i regresji.

Nie zmieniono kodu, testów, migracji, konfiguracji, baz, kontenerów ani wolumenów. Nie wykonano commitów, pushy, wdrożeń ani migracji. `RAW_MATERIALS` pozostaje `NOT_READY`; Stage 2D i VSEF nie zostały rozpoczęte/aktywowane.
