# VELTRA 2.0 — Przegląd architektury Stage 2C
## Dostawcy, produkty/opakowania, ceny referencyjne i waluty

**Rodzaj:** przegląd dokumentacyjny projektu Stage 2C  
**Werdykt:** **CONDITIONAL ACCEPT — architektura jest spójna i może wejść w biznesowy przegląd; implementacja nie powinna ruszyć przed zatwierdzeniem wskazanych polityk finansowych i integracyjnych.**  
**Zakres zmian:** dokumenty; bez kodu, migracji, testów modyfikujących dane, deploymentu, commitów i aktywacji.  
**Stan wymagany:** `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywny.

## 1. Streszczenie

Niniejszy przegląd ocenia [architekturę Stage 2C](./VELTRA_STAGE_2C_ARCHITECTURE.md) względem zatwierdzonych decyzji B1–B8, implementacji Stage 2B, końcowego raportu poprawki RBAC i końcowego niezależnego audytu bezpieczeństwa. Projekt rozdziela: (1) centralny surowiec, (2) dostawcę, (3) konkretny produkt/opakowanie, (4) ofertę zakupu, (5) cenę referencyjną Foodcost, (6) kurs walutowy i (7) koszt rzeczywistej partii Inventory.

**Nie wykryto sprzeczności z zatwierdzonymi B1–B8.** Krytyczne granice są zachowane: osobne granty cen referencyjnych i kosztów zakupowych, organizacyjne RBAC, gotowość per lokalizacja, historia append-only, niezmienne migawki, PLN jako waluta raportowa, brak cichego zaokrąglenia oraz pierwszeństwo rzeczywistego kosztu partii zgodnie z B4.

Warunkowe przyjęcie wynika z nierozstrzygniętych polityk finansowych i zakresu danych: źródła/effective-time FX, denominacji ceny referencyjnej, trybu i punktu rounding, korekt wstecznych, granic opakowania, master dostawcy i uprawnień do danych handlowych. Nie są to powody do rozszerzania B1–B8; należy uzyskać decyzje biznesowe przed implementacją właściwych ścieżek.

## 2. Potwierdzenie B1–B8

| Decyzja | Ocena integracji w architekturze Stage 2C | Warunek zachowania |
|---|---|---|
| **B1 — osobne permissions cenowe** | Zgodna: `REFERENCE_PRICE_VIEW/MANAGE` i `PURCHASING_COSTS_VIEW/MANAGE` są rozłączne; zwykły katalog nie zwraca cen. | Rozdział musi obejmować list/detail, filtry, sort, agregacje, cache, export, idempotency replay i błędy. Same role lokalizacyjne nie przyznają dostępu. |
| **B2 — blokowanie/archiwizacja** | Zgodna: master i historia dostawcy/produktu/oferty są zachowane; cena/referencje nie odpinają historii. | Endpointy 2C sprawdzają stan master i aktualną zatwierdzoną rewizję; blokada/archiwizacja odrzuca nowe użycie. Otwarte operacje ponownie walidują stan przed commit. |
| **B3 — zgodność dostawcy/alergenów** | Zgodna: Stage 2C może ewidencjonować, ale nie zatwierdza zgodności; review należy do 2D. | `PENDING`, `INCOMPATIBLE` i review dla starej rewizji nie stają się zgodą na wybór/operacyjne użycie. Produkt nie może dostać fałszywego `COMPATIBLE` z payloadu 2C. |
| **B4 — koszt produkcji** | Zgodna: reference price jest źródłem oszacowania, nie actual cost. | Future Production używa kosztu Inventory batch, jeśli jest dostępny; w przeciwnym razie historycznej reference price oznaczonej `is_estimated=true`, z niezmiennym snapshotem. |
| **B5 — RAW_MATERIALS gate** | Zgodna: odrębny klucz Stage 1E i per-location gate pozostają konieczne; 2C nie włącza modułu. | Każdy endpoint nadal weryfikuje `ready=false` jako deny, aktywację lokalizacji, org grant i aktywne membership. Żaden tryb „preview”, administrator ani grant nie omija gate. |
| **B6 — RBAC organizacyjny** | Zgodna: nowe permissiony są jawne i tenant-scoped; role lokalizacyjne nie modyfikują wspólnego katalogu. | Rozszerzenie enum/DB allowlist nie może auto-grantować istniejącym administratorom. Zachować blokadę/transaction i trwałe unieważnianie grantów po zawieszeniu. |
| **B7 — PLN/FX/precyzja** | Zgodna: kwota źródłowa i PLN snapshot zachowane, FX append-only, bez float i przedwczesnego zaokrąglenia. | Źródło, czas, rate direction, kurs i rounding policy muszą być zatwierdzone; cena historyczna nie może używać „bieżącego” kursu bez snapshotu. |
| **B8 — restore** | Zgodna: Stage 2C nie kasuje materiałów ani nie przywraca produktu automatycznie. | Restore surowca wciąż przechodzi istniejący audytowany `ARCHIVED -> VERIFICATION -> ACTIVE`; ceny/produkty zachowują historię. |

## 3. Inspekcja stanu faktycznego i skorygowanie nieaktualnych założeń

1. **`RAW_MATERIALS` już jest w katalogu modułów.** `DefinedModuleCatalog` zawiera wpis z permission kotwiczącym `RAW_MATERIALS_VIEW`; `ModuleDefinition.ready` ma domyślne `false`. W starszym raporcie architektury Stage 2A zapisano, że klucza jeszcze nie ma — ten opis był poprawny dla momentu wcześniejszego przeglądu, ale nie jest już aktualny po Stage 2B. W tym zadaniu nie zmieniono definicji, wartości ready ani lokalizacji; Stage 2C utrzymuje obecny fail-closed stan.
2. **Obecne prawa Stage 2B nie obejmują finansów.** `RawMaterialPermission` i DB `CHECK` ograniczają się do rdzenia katalogu/delegowania. Nowe permissions dostawców/cen/FX wymagają skoordynowanej, addytywnej zmiany kodu i constraintu migracyjnego. Nie wolno bezpośrednio dopisywać tych stringów do eventów lub omijać centralnego checku.
3. **Brak istniejących tabel finansowych.** W aktualnych migracjach nie ma produktu dostawcy, oferty, ceny reference, eventu FX ani walutowego snapshotu. Stage 2C powinien wprowadzić nowe tabele, bez migracji lub reinterpretacji danych aplikacyjnych.
4. **Relacja `location_supply_relationships` nie jest Supplier master.** Przechowuje powiązanie kuchnia–restauracja. Użycie jej do ofert surowców myli relację logistyczną z podmiotem prawnym/handlowym; architektura słusznie projektuje osobny tenant-scoped Supplier.
5. **Konwersje Stage 2B są revision-bound.** Przelicznik i jego basis wiążą się z konkretną rewizją materiału. Projekt 2C prawidłowo zachowuje tę rewizję w wersji opakowania i nie przelicza już zapisanych ofert po zmianie konwersji.
6. **Audyt i lock Stage 2B są istotne.** Grantu i historii nie można aktualizować/usunąć; zwieszenie/re-aktywacja jest rozwiązane trwałym odwołaniem. Nowe writes muszą podtrzymać append-only i spójny punkt serializacji. Nie wystarczy dodać application-only validation.

Dokumenty źródłowe obejmowały [Stage 2A architecture](./VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md), [Stage 2A review](./VELTRA_STAGE_2A_FINAL_ARCHITECTURE_REVIEW.md), [Stage 2B implementation](./VELTRA_STAGE_2B_IMPLEMENTATION_REPORT.md), [remediation](./VELTRA_STAGE_2B_SECURITY_REMEDIATION_REPORT.md), [final RBAC fix](./VELTRA_STAGE_2B_FINAL_RBAC_FIX_REPORT.md), [final security audit](./VELTRA_STAGE_2B_FINAL_SECURITY_AUDIT.md), [raw materials migration](./backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php), [unit registry](./backend/app/Domain/RawMaterials/RawMaterialUnitRegistry.php), [permission enum](./backend/app/Domain/RawMaterials/RawMaterialPermission.php), [availability gate](./backend/app/Domain/RawMaterials/RawMaterialAuthorization.php), [module catalog](./backend/app/Domain/Modules/DefinedModuleCatalog.php) i [installation schema](./backend/database/migrations/2026_11_02_000001_create_installation_structure.php).

## 4. Przegląd modelu danych i niezmienników

### Mocne strony

- Composite FK dla tenant/supplier/material/product revision ograniczają cross-organization i błędne powiązanie rewizji.
- Produkt dostawcy i opakowanie mają własną rewizję i przypięty `raw_material_revision_id`; dokładny przelicznik nie zmienia historycznych cen.
- Oferty, ceny referencyjne, FX i audyt są zdarzeniami append-only. Korekta jest nowym eventem, nie UPDATE.
- Separate logical offer event i one material reference stream odzwierciedlają B1 i zapobiegają kopiowaniu wartości do ogólnego katalogu.
- Price/FX snapshots zawierają kwotę źródłową, walutę, rate, datę efektywną i kwotę PLN; zapobiega to zależności historycznej od przyszłej tabeli „current rate”.
- B4 wprost zabrania traktowania oferty jako actual cost i chroni zamknięte snapshots.

### Warunki wymagane przed implementacją

1. **Atomowy porządek finansowy:** szczegółowa kolejność eventów przy tej samej `effective_from` musi być jednoznaczna i deterministyczna. Projekt rekomenduje `event_no`/`revision_no` rosnące pod lockiem. Constraint i query as-of muszą stosować dokładnie ten sam tie-break.
2. **Wycofanie ceny:** zdarzenie `WITHDRAWN` musi mieć semantykę „brak aktualnej ceny od tej chwili”; nie może być mylone z ceną zero. Odpowiedź API powinna jasno odróżniać `NO_PRICE`, `PRICE_WITHDRAWN` i `PRICE_SET`.
3. **Spójność cross-FK:** schema musi wymuszać nie tylko, że `raw_material_revision_id` istnieje, lecz że należy do surowca produktu w tym samym organization. Identyfikator conversion unit również ma wskazywać dokładny wiersz z tej rewizji.
4. **Równoległe rewizje:** nowa rewizja produktu, zmiana oferty i rewizja materiału konkurują o ten sam parent. Mutacje wymagają expected-version/optimistic check pod blokadą, otherwise stary product revision może zostać użyty do nowego eventu.
5. **Append-only audit:** rozszerzenie triggerowanych event codes wymaga sprawdzenia istniejących ograniczeń tabeli. Nie zmieniać triggera w sposób, który umożliwia UPDATE/DELETE istniejącej historii Stage 2B.
6. **Dane wrażliwe:** `tax_identifier`, ceny, identyfikatory ofert i source metadata to dane handlowo wrażliwe. Należy określić retencję, maskowanie i eksport, ograniczyć logowanie oraz uniknąć wycieku przez tabele list/exception/idempotency.
7. **Ograniczenia `numeric`:** skale podane w dokumencie są propozycją, wymagają obliczenia maksimum i przepływu overflow dla ceny/ilości. Testy muszą mierzyć expected numeric values dokładnie na granicznych wejściach.
8. **Migracje wsteczne:** usunięcie niezmiennej historii jest niebezpieczne. Każda migracja wymaga planu naprawy przez kolejną migrację; rollback nie może kasować cen/FX/audytu ani istniejących funkcji Stage 2B.

## 5. Ocena API, uprawnień i ochrony danych

API trzyma oddzielne trasy i uprawnienia dla katalogu, supplier details, zakupowych ofert, reference prices i rate administration. To jest konieczne, bo maskowanie tylko w UI nie spełnia B1. Kontrakt powinien wybrać jednoznacznie, czy GET endpoint bez permission zwraca 403, czy publiczną, zredagowaną projekcję — nie wolno, by zależało to od join/serializera. Rekomendacja: odrębne endpointy dla danych cenowych oraz 403 przy bezpośrednim odczycie kwoty bez price grantu; lista katalogowa nie zawiera wrażliwych kolumn w zapytaniu.

Wymagane dodatkowe zabezpieczenia:

- ponowny check RBAC, readiness, lifecycle, tenant scope i compatibility przed każdym write oraz price download;
- idempotency replay autoryzowany ponownie, aby stary cache nie ujawniał ceny po cofnięciu grantu;
- allowlist sort/filter/columns, bez raw SQL z request;
- responses 404/403 nie ujawniają istnienia cross-tenant obiektu, a polski komunikat nie zawiera SQL, stack trace, kwoty lub tax ID;
- eksport, log, monitoring i background worker stosują te same capability boundaries i minimalne pola;
- nie ufać klientowskiemu `organization_id`, `recorded_by`, `exchange_rate`, `supplier approval`, `raw_material_revision_id` ani obliczonej `base_quantity` bez ponownej weryfikacji.

Nowe permissiony są delegowane według istniejącego bezpiecznego modelu Stage 2B, z jawnie ograniczonym zakresem, terminem i audytem. Dodanie ich do allowlisty nie oznacza grantu ani przypisania do roli. Granty, których authority źródłowe zostanie utracone, pozostają trwale nieskuteczne po reaktywacji; testy z końcowego audytu Stage 2B muszą przejść po rozszerzeniu.

## 6. Precyzja, FX i koszt historyczny

Architektura prawidłowo przechowuje kwoty i kurs jako wartości dziesiętne, zachowuje source currency i wprowadza PLN snapshot. Krytycznym ryzykiem jest polityka wyboru rate, a nie sama arytmetyka:

- `effective_at` i `recorded_at` muszą być odrębne; reguła as-of potrzebuje jawnego momentu, strefy, source-date i zachowania w weekendy/święta.
- Nie można po cichu użyć najnowszego kursu, kursu 1, ostatniego znanego ani drugiej waluty jako fallback.
- FX correction nie może zmienić kwoty snapshotu; ewentualne restatementy muszą być osobną operacją audytowaną.
- Wartość referencyjna w PLN jest rekomendacją architekta, nie zatwierdzonym już wymogiem biznesowym. B1 dopuszcza centralną cenę netto; B7 określa PLN raportowo. D-05 wymaga jednoznacznego zatwierdzenia, czy wartość canonical foodcost ma być PLN/base unit.
- Kurs ma być przypięty do dokładnego price event oraz źródła; bez rate snapshot nie wolno deklarować wartości foreign-currency jako wartości raportowej PLN.
- Kwota zamówienia/oferty to nie koszt rzeczywiście przyjętej lub zużytej partii. Inventory/Production musi zachować koszt partii i użyć szacunku z ceny referencyjnej tylko wtedy, gdy rzeczywisty koszt partii jest niedostępny.
- Niezaokrąglanie intermediate i zwykle 2-miejscowa prezentacja odpowiada B7; końcowy rounding księgowy, scale source currency i punkt agregacji pozostają do formalnej decyzji Finance.

## 7. Zależności i bramki

| Zależność | Bramka przed wykorzystaniem |
|---|---|
| Stage 2B grants/audit | Rozszerzyć enum/DB allowlist i przetestować nowe granty; brak automatycznego bootstrapu, zachować permanent revoke przy suspensions i fail-closed chain checks. |
| Stage 2B immutable revisions/units | Wersja opakowania zawsze wskazuje konkretną rewizję i unit record; nie zmieniać starych konwersji ani snapshotów. |
| Stage 2D allergens/documents | Nie istnieje jeszcze completed supplier evidence workflow; żadna oferta/produkt 2C nie jest zatwierdzeniem zgodności. B3 gate blokuje operational use do 2D review. |
| Inventory/Purchasing | Uzgodnić właściciela rzeczywistej ceny batch, receipt data, currency, FX basis oraz rozróżnienie quoted/net invoice/landed cost. Nie implementować ich jako ceny 2C. |
| Production/Foodcost | Wspólny kontrakt „rzeczywisty koszt partii” wobec „szacunku referencyjnego”, historyczna cena na wskazany czas i zamknięta migawka; uzgodnić kolejność blokad dla wyścigu blokady surowca i jego zużycia. |
| Stage 1E readiness | Moduł istnieje w katalogu kodu, ale pozostaje `ready=false`. Brak deploy/activation w tym zadaniu. Pełne READY wymaga kompletnej funkcjonalności i niezależnej akceptacji. |
| FX provider / external dependencies | D-01/D-02 zatwierdzone, polityka awarii/wersji źródła i testy as-of przed wprowadzeniem importu. |

## 8. Nierozstrzygnięte decyzje biznesowe i rekomendacje

Poniższe elementy muszą mieć jawny owner/approval. Rekomendacja nie jest automatycznym zatwierdzeniem.

| ID | Nierozstrzygnięte pytanie | Opcje | Zalecenie | Konsekwencja pozostawienia otwartego |
|---|---|---|---|---|
| D-01 | Autorytatywne źródło kursu i sposób importu? | Ręczny, NBP, źródło księgowe/umowne, zatwierdzany automatyczny import. | Centralnie zatwierdzone źródło z audytowanym ręcznym zastępstwem; wybór dopiero po akceptacji działu finansowego. | Brak automatycznej normalizacji cen obcych walut do PLN; nie można domyślać wartości. |
| D-02 | Który czas wybiera kurs: data tabeli, faktury, operacji czy recorded-time; jak traktować dni bez publikacji? | Każdy z powyższych, z jawnie wybraną strefą/regułą weekend/święto. | Zachować źródłowy effective time i recorded time oddzielnie; brak rate powoduje widoczny błąd. | Nie można bezpiecznie ustalić as-of i raportu historycznego. |
| D-03 | Czy korekty wsteczne mają restate otwarte raporty? | tylko przyszłe; odczyt as-of; osobny restatement. | Tylko nowy, audytowany restatement; zamknięte snapshots niezmienne. | Ryzyko zmiany historycznych wartości w raporcie mimo niezmiennego batch. |
| D-04 | Skala, tryb zaokrąglania i księgowy punkt zaokrąglenia? | Połowa w górę, połowa do parzystej, reguła systemu finansowego; pozycja/suma/księga. | Bez zaokrągleń pośrednich; końcowe zgodne z księgowością, prezentacja do 2 miejsc. | Rozbieżności końcowych sum/uzgodnień i trudne do odtworzenia testy. |
| D-05 | Kanoniczna jednostka/waluta reference price? | PLN/base unit; source currency plus PLN; wiele walut równoległych. | Jedna PLN/base-unit value z immutable source provenance. | Foodcost i porównanie cen nie będą jednoznaczne; wielowalutowa referencja może kolidować z „jedną ceną”. |
| D-06 | Jak szeroko modelować wielopoziomowe opakowania? | Sumaryczna ilość na purchase unit; pełne case/pack hierarchy; dowolne komponenty. | Prosty, agregowany purchase unit w pierwszej wersji. | Złożone assortment packs i różne ścieżki konwersji trzeba wykluczyć do późniejszego rozszerzenia. |
| D-07 | Oferty ilościowe/walutowe i okres obowiązywania? | Jeden price series; wiele ofert/tiers/moq/expiry. | Wiele logicznych ofert, event ma jedno currency+unit+MOQ; bez automatycznego rankingu. | Nie można obiecać automatycznego wyboru najtańszego dostawcy. |
| D-08 | Czy Supplier jest wspólnym masterem dla Purchasing? | Surowce owner; wspólny organizacyjny Supplier; migracja później. | Uzgodnić shared `organization_suppliers` z właścicielem domenowym przed 2C. | Ryzyko zduplikowania kontrahenta przy późniejszym Purchasing. |
| D-09 | Kto czyta supplier data i FX source details? | Katalogowe VIEW; osobny Supplier VIEW; cena-scoped FX. | Osobny `SUPPLIER_VIEW`; FX w kontekście permission cenowego; odrębne FX manage. | Możliwy nadmierny wyciek danych handlowych albo niepraktyczny model grantów. |
| D-10 | Czy produkt/oferta może zostać użyta do PO przed 2D? | Ewidencja tylko; albo operacyjne użycie przed compatibility review. | Ewidencja możliwa, operacyjne użycie wymaga B3 review. | Jeśli nie zostanie rozstrzygnięte, 2C nie może wskazywać „approved for purchasing”. |

## 9. Ryzyka i działania ograniczające

| Ryzyko | Waga | Minimalny warunek ograniczenia |
|---|---|---|
| Zły kurs/źródło effective-time zmienia PLN | Wysokie finansowe | Zatwierdzone D-01/D-02, immutable rate snapshot, testy as-of/korekty, brak fallbacku. |
| Wycieki kosztów przez endpointy, agregacje lub odtworzenie żądania | Wysokie poufności | Oddzielne uprawnienia, osobne trasy odczytu, selektywne kolumny, ponowna autoryzacja pamięci podręcznej/odtworzenia/eksportu. |
| Stary przelicznik produktu po zmianie rewizji surowca | Wysokie integralności | Product revision FK do exact raw revision/unit; stale revision rejection and explicit rebase revision. |
| Oferta wzięta za koszt partii | Wysokie rachunkowości | B4 contract, distinct source enum and `is_estimated`; Inventory actual batch precedence. |
| Produkt z nieznanymi alergenami uznany za zakupowo bezpieczny | Wysokie bezpieczeństwa żywności | B3 gate i Stage 2D approved evidence; 2C nie może ustawiać compatibility. |
| Konkurencyjne backdated price/rate events zmieniają wynik as-of | Średnie/wysokie | Per-stream serial lock/event number, deterministic tie break, idempotency and expected revision. |
| Rozszerzenie permission enum otwiera istniejące granty | Wysokie RBAC | Additive, explicit grants only; no broad bootstrap; regression of suspension/revocation/delegation. |
| Pola numeric obcinają lub różnie zaokrąglają | Średnie finansowe | Dowieść zakresu i skali, używać arytmetyki dziesiętnej, sprawdzać dokładne wartości graniczne i wersjonować politykę zaokrągleń. |
| Dwie tabele Supplier w Surowcach i Purchasing | Średnie architektury | D-08 owner decision before schema freeze. |
| Migration rollback usuwa finansową historię | Wysokie integralności | Forward recovery by default; backup/recovery tested only in disposable PostgreSQL. |

## 10. Granice implementacji i akceptacja

### Dozwolony zakres Stage 2C po zatwierdzeniu

- addytywne, tenant-scoped schematy Supplier, product revision/packaging, offer, reference-price and FX event;
- rozdzielone organization permissiony, migracja allowlist oraz API, bez auto-grantu i bez zmiany permissionów Stage 2B;
- zapis i odczyt append-only historii, normalizacja według zatwierdzonych conversions, FX snapshots, audit/idempotency;
- testy DB, izolacji, RBAC, cen/FX/as-of/rounding, concurrency, regression Stage 1A–1F/2B na disposable resources.

### Poza Stage 2C

- wdrożenie, live migrations, activation/READY; finalne UI;
- importer kursów/plików, faktury/PO/receipt, rzeczywiste koszty Inventory, Production cost snapshot implementation;
- completed allergens/documents and compatibility approval (Stage 2D);
- zmiana lub konwersja istniejących danych aplikacyjnych bez osobnej migracji i zgody.

### Warunki przed rozpoczęciem implementacji

1. Sponsor biznesowy/Finance zatwierdza D-01, D-02, D-04 i D-05.
2. Product/Inventory/Purchasing owners zatwierdzają D-06–D-10 oraz kontrakt B4.
3. DB/API właściciel zatwierdza allowlistę nowych permissions i ownera wspólnego Supplier master.
4. Implementacja przedstawia migracyjny plan addytywny, bez zmian danych live i bez rollbacku niszczącego historię.
5. Test plan zawiera każdy warunek z rozdziału 13 architektury, w tym direct PostgreSQL writes/triggeri oraz procesową konkurencję.

**Werdykt końcowy: CONDITIONAL ACCEPT.** Model danych i granice bezpieczeństwa są odpowiednią podstawą do biznesowego przeglądu Stage 2C. **Nie jest to zgoda na implementację Stage 2C, zmianę modułu, migrację, deployment ani aktywację.** Po zamknięciu decyzji D-01–D-10 i niezależnej akceptacji architektury można osobno autoryzować Stage 2C. Do tego czasu `RAW_MATERIALS` pozostaje `NOT_READY`.
