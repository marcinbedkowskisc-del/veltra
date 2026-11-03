# VELTRA 2.0 — Przegląd architektury Stage 2C
## Dostawcy, produkty/opakowania, ceny referencyjne i waluty

**Rodzaj:** przegląd dokumentacyjny projektu Stage 2C  
**Werdykt:** **ACCEPT — zatwierdzone decyzje D-01–D-10 zostały zintegrowane jako wiążące niezmienniki; pozostałe ryzyka są techniczne i podlegają weryfikacji implementacyjnej.**
**Zakres zmian:** dokumenty; bez kodu, migracji, testów modyfikujących dane, deploymentu, commitów i aktywacji.
**Stan wymagany:** `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywny.

## 1. Streszczenie

Niniejszy przegląd ocenia [architekturę Stage 2C](./VELTRA_STAGE_2C_ARCHITECTURE.md) względem zatwierdzonych decyzji B1–B8, implementacji Stage 2B, końcowego raportu poprawki RBAC i końcowego niezależnego audytu bezpieczeństwa. Projekt rozdziela: (1) centralny surowiec, (2) dostawcę, (3) konkretny produkt/opakowanie, (4) ofertę zakupu, (5) cenę referencyjną Foodcost, (6) kurs walutowy i (7) koszt rzeczywistej partii Inventory.

**Nie wykryto sprzeczności z zatwierdzonymi B1–B8.** Krytyczne granice są zachowane: osobne granty cen referencyjnych i kosztów zakupowych, organizacyjne RBAC, gotowość per lokalizacja, historia append-only, niezmienne migawki, PLN jako waluta raportowa, brak cichego zaokrąglenia oraz pierwszeństwo rzeczywistego kosztu partii zgodnie z B4.

Przegląd potwierdza integrację zatwierdzonych D-01–D-10. Nie pozostały otwarte decyzje biznesowe w tym zakresie. ACCEPT dotyczy dokumentacji architektury; nie stanowi autoryzacji rozpoczęcia implementacji, migracji, wdrożenia ani aktywacji modułu.

## 2. Potwierdzenie B1–B8

| Decyzja | Ocena integracji w architekturze Stage 2C | Warunek zachowania |
|---|---|---|
| **B1 — osobne permissions cenowe** | Zgodna: `REFERENCE_PRICE_VIEW/MANAGE` i `PURCHASING_COSTS_VIEW/MANAGE` są rozłączne; zwykły katalog nie zwraca cen. | Rozdział musi obejmować list/detail, filtry, sort, agregacje, cache, export, idempotency replay i błędy. Same role lokalizacyjne nie przyznają dostępu. |
| **B2 — blokowanie/archiwizacja** | Zgodna: master i historia dostawcy/produktu/oferty są zachowane; cena/referencje nie odpinają historii. | Endpointy 2C sprawdzają stan master i aktualną zatwierdzoną rewizję; blokada/archiwizacja odrzuca nowe użycie. Otwarte operacje ponownie walidują stan przed commit. |
| **B3 — zgodność dostawcy/alergenów** | Zgodna: Stage 2C może ewidencjonować, ale nie zatwierdza zgodności; review należy do 2D. | `PENDING`, `INCOMPATIBLE` i review dla starej rewizji nie stają się zgodą na wybór/operacyjne użycie. Produkt nie może dostać fałszywego `COMPATIBLE` z payloadu 2C. |
| **B4 — koszt produkcji** | Zgodna: reference price jest źródłem oszacowania, nie actual cost. | Future Production używa kosztu Inventory batch, jeśli jest dostępny; w przeciwnym razie historycznej reference price oznaczonej `is_estimated=true`, z niezmiennym snapshotem. |
| **B5 — RAW_MATERIALS gate** | Zgodna: odrębny klucz Stage 1E i per-location gate pozostają konieczne; 2C nie włącza modułu. | Każdy endpoint nadal weryfikuje `ready=false` jako deny, aktywację lokalizacji, org grant i aktywne membership. Żaden tryb „preview”, administrator ani grant nie omija gate. |
| **B6 — RBAC organizacyjny** | Zgodna: nowe permissiony są jawne i tenant-scoped; role lokalizacyjne nie modyfikują wspólnego katalogu. | Rozszerzenie enum/DB allowlist nie może auto-grantować istniejącym administratorom. Zachować blokadę/transaction i trwałe unieważnianie grantów po zawieszeniu. |
| **B7 — PLN/FX/precyzja** | Zgodna: PLN reporting, NBP, jawne nadpisania, snapshot źródłowy, pełna precyzja pośrednia i HALF-EVEN na wyniku końcowym. | Data operacji `Europe/Warsaw`, ostatni wcześniejszy NBP w dni bez publikacji, brak wymyślonego kursu; księgowe/podatkowe rounding oddzielone. |
| **B8 — restore** | Zgodna: Stage 2C nie kasuje materiałów ani nie przywraca produktu automatycznie. | Restore surowca wciąż przechodzi istniejący audytowany `ARCHIVED -> VERIFICATION -> ACTIVE`; ceny/produkty zachowują historię. |

## 3. Inspekcja stanu faktycznego i skorygowanie nieaktualnych założeń

1. **`RAW_MATERIALS` już jest w katalogu modułów.** `DefinedModuleCatalog` zawiera wpis z permission kotwiczącym `RAW_MATERIALS_VIEW`; `ModuleDefinition.ready` ma domyślne `false`. W starszym raporcie architektury Stage 2A zapisano, że klucza jeszcze nie ma — ten opis był poprawny dla momentu wcześniejszego przeglądu, ale nie jest już aktualny po Stage 2B. W tym zadaniu nie zmieniono definicji, wartości ready ani lokalizacji; Stage 2C utrzymuje obecny fail-closed stan.
2. **Obecne prawa Stage 2B nie obejmują finansów.** `RawMaterialPermission` i DB `CHECK` ograniczają się do rdzenia katalogu/delegowania. Nowe permissions dostawców/cen/FX wymagają skoordynowanej, addytywnej zmiany kodu i constraintu migracyjnego. Nie wolno bezpośrednio dopisywać tych stringów do eventów lub omijać centralnego checku.
3. **Brak istniejących tabel finansowych.** W aktualnych migracjach nie ma produktu dostawcy, oferty, ceny reference, eventu FX ani walutowego snapshotu. Stage 2C powinien wprowadzić nowe tabele, bez migracji lub reinterpretacji danych aplikacyjnych.
4. **Relacja `location_supply_relationships` nie jest Supplier master.** Przechowuje powiązanie kuchnia–restauracja. Użycie jej do ofert surowców myli relację logistyczną z podmiotem prawnym/handlowym; architektura słusznie projektuje osobny tenant-scoped Supplier.
5. **Konwersje Stage 2B są revision-bound.** Przelicznik i jego basis wiążą się z konkretną rewizją materiału. Projekt 2C prawidłowo zachowuje tę rewizję w wersji opakowania i nie przelicza już zapisanych ofert po zmianie konwersji.
6. **Audyt i lock Stage 2B są istotne.** Grantu i historii nie można aktualizować/usunąć; zwieszenie/re-aktywacja jest rozwiązane trwałym odwołaniem. Nowe writes muszą podtrzymać append-only i spójny punkt serializacji. Nie wystarczy dodać application-only validation.

Dokumenty źródłowe obejmowały [Stage 2A architecture](./VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md), [Stage 2A review](./VELTRA_STAGE_2A_FINAL_ARCHITECTURE_REVIEW.md), [Stage 2B implementation](./VELTRA_STAGE_2B_IMPLEMENTATION_REPORT.md), [remediation](./VELTRA_STAGE_2B_SECURITY_REMEDIATION_REPORT.md), [final RBAC fix](./VELTRA_STAGE_2B_FINAL_RBAC_FIX_REPORT.md), [final security audit](./VELTRA_STAGE_2B_FINAL_SECURITY_AUDIT.md), [raw materials migration](./backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php), [unit registry](./backend/app/Domain/RawMaterials/RawMaterialUnitRegistry.php), [permission enum](./backend/app/Domain/RawMaterials/RawMaterialPermission.php), [availability gate](./backend/app/Domain/RawMaterials/RawMaterialAuthorization.php), [module catalog](./backend/app/Domain/Modules/DefinedModuleCatalog.php) i [installation schema](./backend/database/migrations/2026_11_02_000001_create_installation_structure.php).

### Niespójności architektoniczne skorygowane w dokumentach

- Otwarte warianty FX/as-of/rounding/referencyjnej waluty zastąpiono wiążącym NBP, datą operacji w `Europe/Warsaw`, jawnym fallbackiem wyłącznie do wcześniejszej publikacji, `HALF-EVEN`, PLN/base unit i audytowanym restatementem.
- Wielokomponentowy model opakowań uproszczono do jednej jednorodnej zawartości na purchase unit; usunięto hierarchię i mieszane zestawy z zakresu 2C.
- `organization_suppliers` przestał być jedynie kandydatem; jest jednym wspólnym masterem domeny Supplier dla Raw Materials, Purchasing i Inventory.
- Uprawnienia informacji dostawcy, kosztów zakupu, ceny referencyjnej oraz odczytu/zarządzania FX rozdzielono; brak automatycznych grantów i dziedziczenia z lokalnych ról.
- Rozdzielono rejestrację oferty przed review od operacyjnego użycia: zgodność Stage 2D jest bezwzględną bramką dla Purchasing/Production.
- Import NBP został włączony do granic 2C jako kontrolowana synchronizacja; pobranie z sieci nie odbywa się w transakcji zapisu.
- Uzupełniono techniczny invariant rounding: domyślne `round(numeric)`/niejawne przypisanie do `NUMERIC(p,s)` PostgreSQL nie gwarantuje HALF-EVEN i może zaokrąglić przed walidacją. Projekt wymaga unconstrained `NUMERIC`, jawnej funkcji `raw_materials_round_half_even_v1` oraz DB CHECK/trigger weryfikującego końcową wartość.
- Warunkowy werdykt oparty na oczekiwaniu na biznesowe decyzje zastąpiono ACCEPT dokumentacji, pozostawiając osobne bramki implementacji, security review i deploymentu.

## 4. Przegląd modelu danych i niezmienników

### Mocne strony

- Composite FK dla tenant/supplier/material/product revision ograniczają cross-organization i błędne powiązanie rewizji.
- Produkt dostawcy i opakowanie mają własną rewizję i przypięty `raw_material_revision_id`; dokładny przelicznik nie zmienia historycznych cen.
- Oferty, ceny referencyjne, FX i audyt są zdarzeniami append-only. Korekta jest nowym eventem, nie UPDATE.
- Separate logical offer event i one material reference stream odzwierciedlają B1 i zapobiegają kopiowaniu wartości do ogólnego katalogu.
- Price/FX snapshots zawierają kwotę źródłową, walutę, rate, datę efektywną i kwotę PLN; zapobiega to zależności historycznej od przyszłej tabeli „current rate”.
- B4 wprost zabrania traktowania oferty jako actual cost i chroni zamknięte snapshots.

### Inwarianty techniczne wymagane w implementacji

1. **Atomowy porządek finansowy:** szczegółowa kolejność eventów przy tej samej `effective_from` musi być jednoznaczna i deterministyczna. Projekt rekomenduje `event_no`/`revision_no` rosnące pod lockiem. Constraint i query as-of muszą stosować dokładnie ten sam tie-break.
2. **Wycofanie ceny:** zdarzenie `WITHDRAWN` musi mieć semantykę „brak aktualnej ceny od tej chwili”; nie może być mylone z ceną zero. Odpowiedź API powinna jasno odróżniać `NO_PRICE`, `PRICE_WITHDRAWN` i `PRICE_SET`.
3. **Spójność cross-FK:** schema musi wymuszać nie tylko, że `raw_material_revision_id` istnieje, lecz że należy do surowca produktu w tym samym organization. Identyfikator conversion unit również ma wskazywać dokładny wiersz z tej rewizji.
4. **Równoległe rewizje:** nowa rewizja produktu, zmiana oferty i rewizja materiału konkurują o ten sam parent. Mutacje wymagają expected-version/optimistic check pod blokadą, otherwise stary product revision może zostać użyty do nowego eventu.
5. **Append-only audit:** rozszerzenie triggerowanych event codes wymaga sprawdzenia istniejących ograniczeń tabeli. Nie zmieniać triggera w sposób, który umożliwia UPDATE/DELETE istniejącej historii Stage 2B.
6. **Dane wrażliwe:** supplier information, ceny, identyfikatory ofert i source metadata to dane handlowo wrażliwe. Zastosować dedykowane permissions, retencję, maskowanie i eksport; ograniczyć logowanie oraz uniknąć wycieku przez listy/exception/idempotency.
7. **Ograniczenia `numeric`:** skale są wybraną konwencją techniczną; implementacja musi wykazać dopuszczalne zakresy, przepełnienie i dokładne wartości graniczne. Nie zmieniać zatwierdzonego HALF-EVEN ani nie zaokrąglać operandów.
8. **Migracje wsteczne:** usunięcie niezmiennej historii jest niebezpieczne. Każda migracja wymaga planu naprawy przez kolejną migrację; rollback nie może kasować cen/FX/audytu ani istniejących funkcji Stage 2B.

## 5. Ocena API, uprawnień i ochrony danych

API rozdziela katalog, informacje Supplier, koszty zakupu, ceny referencyjne i zarządzanie/odczyt FX odrębnymi capability zgodnie z D-09 i B1. Endpoint cenowy bez właściwego permission zwraca 403, a lista katalogowa nie pobiera ani nie serializuje wrażliwych kolumn. Lista ofert dla osoby z Supplier VIEW może zwrócić samą tożsamość/stan oferty, nigdy kwoty lub currency; endpointy kosztowe autoryzują oddzielnie.

Wymagane dodatkowe zabezpieczenia:

- ponowny check RBAC, readiness, lifecycle, tenant scope i compatibility przed każdym write oraz price download;
- idempotency replay autoryzowany ponownie, aby stary cache nie ujawniał ceny po cofnięciu grantu;
- allowlist sort/filter/columns, bez raw SQL z request;
- responses 404/403 nie ujawniają istnienia cross-tenant obiektu, a polski komunikat nie zawiera SQL, stack trace, kwoty lub tax ID;
- eksport, log, monitoring i background worker stosują te same capability boundaries i minimalne pola;
- nie ufać klientowskiemu `organization_id`, `recorded_by`, `exchange_rate`, `supplier approval`, `raw_material_revision_id` ani obliczonej `base_quantity` bez ponownej weryfikacji.

Nowe permissiony są delegowane według istniejącego bezpiecznego modelu Stage 2B, z jawnie ograniczonym zakresem, terminem i audytem. Dodanie ich do allowlisty nie oznacza grantu ani przypisania do roli. Granty, których authority źródłowe zostanie utracone, pozostają trwale nieskuteczne po reaktywacji; testy z końcowego audytu Stage 2B muszą przejść po rozszerzeniu.

## 6. Precyzja, FX i koszt historyczny

Architektura przechowuje kwoty i kursy dziesiętnie, zachowuje source currency i wprowadza PLN snapshot. Zatwierdzona polityka wyboru rate jest jednoznaczna:

- NBP Table A średni kurs jest techniczną konwencją raportową; `operation_date` wyznacza się w `Europe/Warsaw`, wybierając ostatnią dostępną publikację NBP nie późniejszą niż ta data.
- Czas rejestracji, data operacji i data tabeli NBP są odrębne; w weekendy/święta używa się ostatniej wcześniejszej tabeli. Nie używa się kursu przyszłego, kursu 1, triangulacji ani cichego fallbacku. Ręczne zastąpienie jest jawnym, audytowanym eventem.
- FX correction nie może zmienić kwoty snapshotu; ewentualne restatementy muszą być osobną operacją audytowaną.
- Cena referencyjna jest jedną kanoniczną wartością netto PLN/base unit zgodnie z D-05; dla źródła obcego zachowuje kwotę, walutę i rate snapshot.
- Kurs ma być przypięty do dokładnego price event oraz źródła; bez rate snapshot nie wolno deklarować wartości foreign-currency jako wartości raportowej PLN.
- Kwota zamówienia/oferty to nie koszt rzeczywiście przyjętej lub zużytej partii. Inventory/Production musi zachować koszt partii i użyć szacunku z ceny referencyjnej tylko wtedy, gdy rzeczywisty koszt partii jest niedostępny.
- `HALF-EVEN` stosuje się wyłącznie do końcowego wyniku po pełnej precyzji pośredniej; formatowanie do 2 miejsc nie jest wartością obliczeniową. Reguły księgowe i podatkowe pozostają poza Stage 2C.

## 7. Zależności i bramki

| Zależność | Bramka przed wykorzystaniem |
|---|---|
| Stage 2B grants/audit | Rozszerzyć enum/DB allowlist i przetestować nowe granty; brak automatycznego bootstrapu, zachować permanent revoke przy suspensions i fail-closed chain checks. |
| Stage 2B immutable revisions/units | Wersja opakowania zawsze wskazuje konkretną rewizję i unit record; nie zmieniać starych konwersji ani snapshotów. |
| Stage 2D allergens/documents | Nie istnieje jeszcze completed supplier evidence workflow; żadna oferta/produkt 2C nie jest zatwierdzeniem zgodności. B3 gate blokuje operational use do 2D review. |
| Inventory/Purchasing | Uzgodnić właściciela rzeczywistej ceny batch, receipt data, currency, FX basis oraz rozróżnienie quoted/net invoice/landed cost. Nie implementować ich jako ceny 2C. |
| Production/Foodcost | Wspólny kontrakt „rzeczywisty koszt partii” wobec „szacunku referencyjnego”, historyczna cena na wskazany czas i zamknięta migawka; uzgodnić kolejność blokad dla wyścigu blokady surowca i jego zużycia. |
| Stage 1E readiness | Moduł istnieje w katalogu kodu, ale pozostaje `ready=false`. Brak deploy/activation w tym zadaniu. Pełne READY wymaga kompletnej funkcjonalności i niezależnej akceptacji. |
| FX provider / external dependencies | NBP jest źródłem wiążącym. Testować dostępność, idempotency, integralność payload hash, opóźnione publikacje, dni bez kursu i manual override; awaria nie może tworzyć fikcyjnej wartości. |

## 8. Potwierdzenie zatwierdzonych decyzji D-01–D-10

Wszystkie poniższe decyzje są zamknięte biznesowo i zostały odwzorowane w architekturze. Tabela wskazuje niezmienniki implementacyjne, nie tematy wymagające ponownej akceptacji.

| ID | Potwierdzona decyzja | Weryfikowalny invariant |
|---|---|---|
| D-01 | Oficjalny NBP z kontrolowanym, audytowanym manual override. | Import idempotentny; zachowany identyfikator publikacji/hash; override append-only, uprawniony, uzasadniony i powiązany z NBP. |
| D-02 | Data operacji `Europe/Warsaw`; na weekend/święto ostatni wcześniejszy dostępny NBP; bez wymyślania kursu. | Snapshot zapisuje operation date i rate date; brak kursu blokuje przeliczenie PLN, bez kursu 1/future-rate/cichego fallbacku. |
| D-03 | Korekty historyczne wyłącznie jako oddzielne audytowane restatementy. | Oryginał niezmienny; restatement jawnie wiąże oryginał, podstawę i wynik. |
| D-04 | `HALF-EVEN`, pełna precyzja pośrednia, zaokrąglenie dopiero na wyniku końcowym; księgowość/podatki osobno. | Arytmetyka dziesiętna/`NUMERIC`, brak `float` i zaokrągleń pośrednich; wynik księgowy/podatkowy nie wynika z formatowania Stage 2C. |
| D-05 | Kanoniczna cena netto PLN/base unit; zachować oryginalną kwotę, walutę i kurs. | Jeden referencyjny event stream na materiał; source FX i rate snapshot immutable. |
| D-06 | Prosta jednostka zakupu z obliczoną całkowitą zawartością; bez opakowań mieszanych. | Jedna rewizja produktu wskazuje jeden surowiec, jedną jednostkę i jedną ilość całkowitą; brak hierarchii i mieszanek. |
| D-07 | Wiele ofert/walut/progów/MOQ/okresów ważności, bez automatycznego wyboru. | Każde zdarzenie ma jawny warunek; nakładające się zakresy są odrzucane; brak automatycznego rankingu/wyboru. |
| D-08 | Jeden organizacyjny Supplier master wspólny dla Raw Materials, Purchasing i Inventory. | Jeden tenant-scoped identyfikator/kontrakt i właściciel domenowy Supplier; żaden moduł nie duplikuje mastera. |
| D-09 | Osobne uprawnienia supplier information, purchasing costs i exchange-rate management. | Jawne, nieautomatyczne granty VIEW/MANAGE; rozdzielone od katalogu i wzajemnie nieimplikujące dostępu. |
| D-10 | Można rejestrować produkty/oferty przed zatwierdzeniem zgodności alergenowej; operacyjne zakupy/produkcja czekają na zatwierdzenie Stage 2D. | `PENDING`, brak/nieaktualna weryfikacja blokuje operację; Stage 2C nie ustawia ani nie symuluje zatwierdzenia. |

## 9. Ryzyka i działania ograniczające

| Ryzyko | Waga | Minimalny warunek ograniczenia |
|---|---|---|
| Błędne lub niedostępne dane NBP / błąd daty as-of | Wysokie finansowe | NBP Table A, immutable source/operation-date snapshot, Warsaw as-of tests, audited override, no invented fallback. |
| Wycieki kosztów przez endpointy, agregacje lub odtworzenie żądania | Wysokie poufności | Oddzielne uprawnienia, osobne trasy odczytu, selektywne kolumny, ponowna autoryzacja pamięci podręcznej/odtworzenia/eksportu. |
| Stary przelicznik produktu po zmianie rewizji surowca | Wysokie integralności | Product revision FK do exact raw revision/unit; stale revision rejection and explicit rebase revision. |
| Oferta wzięta za koszt partii | Wysokie rachunkowości | B4 contract, distinct source enum and `is_estimated`; Inventory actual batch precedence. |
| Produkt z nieznanymi alergenami uznany za zakupowo bezpieczny | Wysokie bezpieczeństwa żywności | B3 gate i Stage 2D approved evidence; 2C nie może ustawiać compatibility. |
| Konkurencyjne backdated price/rate events zmieniają wynik as-of | Średnie/wysokie | Per-stream serial lock/event number, deterministic tie break, idempotency and expected revision. |
| Rozszerzenie permission enum otwiera istniejące granty | Wysokie RBAC | Additive, explicit grants only; no broad bootstrap; regression of suspension/revocation/delegation. |
| Pola numeric przepełniają lub różnie zaokrąglają | Wysokie finansowe | Unconstrained `NUMERIC` z constraintami zakresu/skali; wersjonowana funkcja HALF-EVEN i DB CHECK/trigger; testy SQL direct i wartości tie dodatnie/ujemne. |
| Rozbieżne implementacje wspólnego Supplier master | Średnie architektury | Jeden kontrakt domeny Supplier, tenant-scoped FK, brak kopii modułowych i test integracyjny z konsumentami. |
| Migration rollback usuwa finansową historię | Wysokie integralności | Forward recovery by default; backup/recovery tested only in disposable PostgreSQL. |

## 10. Granice implementacji i akceptacja

### Zakres implementacji Stage 2C

- addytywne, tenant-scoped schematy wspólnego Supplier master, rewizji produktu/prostego opakowania, oferty, ceny referencyjnej, NBP/override FX oraz audytowanego restatementu;
- rozdzielone organization permissiony, migracja allowlist oraz API, bez auto-grantu i bez zmiany permissionów Stage 2B;
- import NBP i manual override bez operacji sieciowej w transakcji; append-only historii, normalizacja, FX snapshots, audit/idempotency;
- jawna rejestracja produktów/ofert przed Stage 2D; downstream purchasing/production musi odmówić użycia bez zatwierdzonej, zgodnej rewizji;
- testy DB, izolacji, RBAC, cen/FX/as-of/HALF-EVEN/restatement, progów oferty, concurrency i regression Stage 1A–1F/2B na disposable resources.

### Poza Stage 2C

- wdrożenie, live migrations, activation/READY; finalne UI;
- faktury/PO/receipt, rzeczywiste koszty Inventory, Production cost snapshot implementation i production restatement workflow;
- completed allergens/documents and compatibility approval (Stage 2D);
- zmiana lub konwersja istniejących danych aplikacyjnych bez osobnej migracji i zgody.

### Kontrole przed rozpoczęciem prac (nie są nowymi decyzjami biznesowymi)

1. Właściciele DB/API przygotowują addytywny plan migracji i rozszerzenia permission allowlist; nie auto-grantują nowych uprawnień.
2. Plan weryfikacji pokrywa wszystkie wymagania architektury, w tym PostgreSQL constraints/triggery, rollback atomowy, konkurencję procesową i testy NBP as-of.
3. Walidacja migracji/testów odbywa się wyłącznie na disposable resources; brak live migration/deployment i brak zmiany statusu modułu.
4. Niezależny przegląd bezpieczeństwa i akceptacja wdrożenia pozostają osobnymi bramkami, poza zakresem tego przeglądu.

**Werdykt końcowy: ACCEPT.** D-01–D-10 zostały przyjęte i odzwierciedlone w modelu. Pozostałe ryzyka dotyczą niezawodności integracji NBP, zakresów `NUMERIC`, migracji addytywnej i implementacji DB/API; są mierzalnymi warunkami testów, a nie nierozstrzygniętymi decyzjami biznesowymi. To ACCEPT architektury, **nie** zgoda na Stage 2C implementation, zmianę modułu, migrację, deployment ani aktywację. `RAW_MATERIALS` pozostaje `NOT_READY`.
