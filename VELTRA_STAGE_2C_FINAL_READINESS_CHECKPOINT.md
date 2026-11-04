# VELTRA 2.0 — końcowy checkpoint gotowości Stage 2C

**Stan na:** 4 listopada 2026  
**Cel:** konsolidacja bieżącego kodu, migracji i istniejących raportów.  
**Tryb:** wyłącznie odczyt i ocena dokumentacyjna. Nie uruchamiano testów, migracji, zapytań adwersarialnych ani Docker; nie zmieniano kodu, baz, kontenerów, wolumenów ani konfiguracji.

## Konkluzja i rekomendacja

**NO-GO dla końcowego ACCEPT Stage 2C oraz dla wdrożenia lub aktywacji RAW_MATERIALS.** To nie jest nowy audyt bezpieczeństwa ani werdykt ACCEPT/REJECT: dostępne dowody są mieszane, a aktualny, niezależny re-audyt runtime po migracji 025 nie został wykonany.

Ostatnia weryfikacja funkcjonalna wykazała poprawne wyniki w uruchomionym zakresie i zgodność migracji na bazach syntetycznych. Nie obejmowała 17 testów bezpieczeństwa/adwersarialnych. Sześć pozostałych tabel F-2 nadal ma runtime DML, a ryzyk nie zatwierdził jawnie właściciel projektu. Nie znamy też historii ani stanu żadnej trwałej instalacji. Brak dowodu błędu w dozwolonych testach nie zamyka tych warunków.

## Stan implementacji

- Bieżące źródło zawiera migracje **001–025**. Migracje 022–025 pozostają w repozytorium: 022 wprowadza mechanizm podpisanych mutacji; 023 zawęża jego granicę; 024 utwardza rozwiązywanie schematów i preconditions; 025 wprowadza poprawki trzech blockerów integralności biznesowej. Migracje 024 i 025 są forward-only; wcześniejszej historii migracji nie należy przepisywać dla już zastosowanych środowisk.
- Zmiany 025: przejście `BLOCKED → ACTIVE` wymaga powiązanego ticketu, aktora i zdarzenia `UNBLOCKED` w tej samej transakcji; aktywny produkt dostawcy wymaga rewizji początkowej nr 1 przy zatwierdzeniu transakcji; cykl życia oferty jest ograniczony do `ACTIVE`, a wycofanie pojedynczego zdarzenia ceny pozostaje osobnym zdarzeniem.
- Zatwierdzony model pozostawia decyzje biznesowe w Laravel. PostgreSQL odpowiada za najmniejsze uprawnienia, integralność, niezmienniki RBAC/audytu i wybrane zabezpieczenia SQL-only. Model **nie** deklaruje ochrony przed dowolnym kodem w skompromitowanym procesie Laravel ani przed administratorem bazy.
- `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Stage 2D nie rozpoczęto; VSEF nie aktywowano.
- Raport remediacji audytu migracji 023 zawiera datę **6 listopada 2026**, późniejszą niż data checkpointu. Jego twierdzenia oznaczam jako historyczne i nie wykorzystuję tej daty jako dowodu kolejności lub aktualności.

## Skonsolidowany rejestr ustaleń Stage 2C

Status „CLOSED” dotyczy wskazanego ustalenia w granicach dowodów wymienionych w wierszu; nie oznacza akceptacji całej Stage 2C. **Żadne ryzyko nie jest oznaczone jako ACCEPTED RISK bez jawnej zgody właściciela projektu.**

| Ustalenie i pierwotna ważność | Remediacja / bieżące dowody | Status | Warunek pozostający |
|---|---|---|---|
| Ujawnienie kosztu zakupu użytkownikowi bez `PURCHASING_COSTS_VIEW` — **MEDIUM** | Poprawiono prezentację źródła ceny referencyjnej i kontrolę prawa. Końcowy audyt bezpieczeństwa Stage 2C raportuje test niezależny; test regresyjny istnieje w `RawMaterialsTest`. | **CLOSED** w zakresie naprawionej ścieżki API | Zachować regresję uprawnień w przyszłych zmianach; nie jest to dowód audytu migracji 025. |
| Zapis ofert/cen dla zablokowanych lub niekwalifikujących się encji — **MEDIUM** | Kontrole lifecycle w serwisach i triggerach zostały poprawione; raport końcowego audytu i testy API/DB wskazują naprawę. | **CLOSED** dla pierwotnych scenariuszy | Niezależny audyt 025 powinien potwierdzić brak regresji poza przetestowanym podzbiorem. |
| Dodanie rewizji produktu po archiwizacji produktu/dostawcy — **LOW** | Migracja 014 i serwis sprawdzają aktywność. Końcowy audyt bezpieczeństwa raportuje testy API i PostgreSQL. | **CLOSED** w badanym zakresie | Procedura migracji i rollback dla istniejących instalacji pozostaje warunkiem operacyjnym. |
| Retry NBP zależny od dostępności upstream — **LOW** | Preflight idempotencji przed HTTP; audyt remediacji raportuje replay bez żądania do NBP i konflikt 409 przy innym zamiarze. | **CLOSED** w badanym zakresie | Brak nowej weryfikacji w teście migracji 025; nie blokuje sam w sobie akceptacji bezpieczeństwa. |
| Precyzja HALF-EVEN dla dużych wartości — **LOW** | W źródle funkcja buduje wynik tekstowo, a test w `RawMaterialsTest` asertywnie sprawdza duże wartości dodatnie/ujemne oraz remisy. Raport funkcjonalny podaje 169 testów bez błędów; wskazany test nie należy do 17 wyłączonych klas/przypadków. | **CLOSED funkcjonalnie** na podstawie istniejącego przebiegu | Wynik testu jest wliczony w raport zbiorczy, nie wyodrębniony jako osobny licznik. |
| `migrate:fresh` i pozostawione funkcje PL/pgSQL — **LOW / operacyjne** | Raport naprawy migracji wykazuje `CREATE OR REPLACE FUNCTION` oraz dwa poprawne odtworzenia na disposable PostgreSQL 17. To narzędzie nadal destrukcyjnie usuwa dane. | **CLOSED** dla powtarzalności raportowanej w tamtym zakresie | Nigdy nie używać `migrate:fresh` na danych zachowywanych; przy zwykłym wdrożeniu stosować migracje naprzód. |
| Brak bezpiecznej ścieżki dodania nowych permissionów do już zainicjalizowanej organizacji Stage 2B — **wdrożeniowe** | Raport zamknięcia Stage 2C nie znajduje wspieranej ścieżki addytywnego bootstrapu ani dowodu rozwiązania. | **OPEN** | Przed migracją istniejącej instalacji określić i przetestować jawny, audytowany proces aktualizacji permissionów. Bez automatycznego grantu i bez SQL omijającego reguły Laravel. |
| Bezpośrednie fałszowanie grantów, zdarzeń `PERMISSION_UPGRADE` i dowodów audytowych; globalny `ADMINISTRATOR` — pierwotnie **HIGH** | Audyty migracji 021/022 potwierdziły nadużycia dawnych wersji. 023 ogranicza zakresy/ledger, a integracja przenosi autoryzację biznesową do Laravel. Funkcjonalne testy API przeszły, lecz najnowsza regresja wyłączyła klasę `AuthorizationMutationBoundaryTest` i test SQL w `RoleAdministrationTest`. | **NOT VERIFIED** dla bieżącego 025 | Niezależny re-audyt na aktualnym kodzie i rzeczywistych poświadczeniach runtime musi ponownie potwierdzić ochronę grantów, dowodów, administratora oraz brak skutków po odmowie. |
| Zbyt ogólny ticket z migracji 022, niezwiązany z dokładnym payloadem mutacji — **HIGH** dla poprzedniego projektu; w zatwierdzonym modelu nie jest gwarancją przeciw RCE Laravel | 023 zawęża katalog scope’ów i Laravel pozostaje zaufaną granicą autoryzacji. Ticket nadal nie dowodzi samodzielnie autoryzacji aktora ani nie jest podpisem biznesowym; threat model wyłącza dowolne RCE w procesie Laravel. | **OPEN — warunek zakresu gwarancji** | Re-audyt ma potwierdzić, że kod nie przedstawia ticketu jako autoryzacji biznesowej, że call sites sprawdzają polityki domenowe i że wybrane zabezpieczenia DB nie przekraczają deklarowanego threat modelu. |
| Obejście walidacji zatwierdzenia przez `pg_temp`/`search_path` po migracji 023 — **MEDIUM**, potwierdzone historycznie | 024 kwalifikuje odwołania i ustala `search_path`; raport remediacji 023 podaje pozytywny test po 024. Statyczny przegląd potwierdza zmiany w źródle. | **NOT VERIFIED** jako niezależna kontrola aktualnej wersji 025 | Niezależny audyt ma potwierdzić na bieżącym schemacie rezultat i brak zmiany stanu przy odmowie. Nie należy ponawiać operacji zablokowanych przez usługę; stosować wyłącznie osobno zatwierdzony, dozwolony proces. |
| Migracja 023 przepuszczająca brak prywatnych prerekwizytów — **LOW / wdrożeniowe** | 024 ma preconditions w transakcji; raport remediacji podaje fail-closed. Świeże migracje i upgrade 024→025 przeszły według raportu funkcjonalnego. | **CLOSED** dla standardowego toru migracji; **NOT VERIFIED** dla wszystkich błędnych stanów | Re-audyt/plan wdrożenia powinien potwierdzić rzeczywiste ACL, właścicielstwo, domyślne granty i atomowość w reprezentatywnych stanach. |
| `SQLSTATE` regresji oczekiwał innego kodu niż zaobserwowany; poprzednia odmowa nie miała pełnej asercji stanu — **luka testowa** | Raporty architektoniczne wymagają weryfikacji semantyki błędu i niezmienionego stanu DB. Obecny test granicy SQL był wyłączony z przebiegu 169 testów. | **NOT VERIFIED** | Skorygować test zgodnie z rzeczywistą semantyką, asertywnie sprawdzić brak skutków ubocznych i nie osłabiać asercji tylko dla PASS. |
| Historia audytowa może być niespójna lub semantycznie fałszywa; tekst atestacji nie dowodzi prawdziwości — **wdrożeniowe** | Decyzja architektoniczna wymaga przeglądu historycznych grantów i dowodów poza bazą. Raporty wyraźnie odróżniają syntetyczne fixtures od rzeczywistych instalacji. | **OPEN** | Dla każdej instalacji: uzgodnić konta, role, granty, delegacje, odwołania, aktorów, audyt i duplikaty; zachować zapytania/raport poza bazą i uzyskać niezależne zatwierdzenie. Sam ciąg atestacji nie wystarcza. |
| Niezależność, rotacja i ekspozycja klucza podpisującego — pierwotnie **MEDIUM** w projekcie 022 | Implementacja raportuje klucz niezależny od `APP_KEY` oraz test rotacji na disposable zasobach; funkcjonalna weryfikacja użyła syntetycznych kluczy. | **CLOSED** w kodzie / **NOT VERIFIED** w instalacji | Przed wdrożeniem sprawdzić dostarczanie sekretu, uprawnienia, procedurę rotacji z zatrzymanymi workerami/procesami, odzyskiwanie po błędzie oraz brak sekretu w logach. |
| Zmiana `BLOCKED → ACTIVE` bez powiązanego audytu, aktywny produkt bez rewizji nr 1, niespójny lifecycle oferty — **MEDIUM / F-2 BLOCKER** | Migracja 025 dodaje ticket i zdarzenie w tej samej transakcji, odroczony trigger rewizji i CHECK lifecycle oferty. Raport funkcjonalny podaje legalne przepływy/rollback, świeże 001–025 oraz syntetyczny upgrade 024→025; przypadek produktu bez rewizji został odrzucony atomowo. | **CLOSED funkcjonalnie**; **NOT VERIFIED** niezależnie | Niezależny re-audyt po 025; dodatkowo w raporcie funkcjonalnym nie przetestowano osobnego upgrade’u dla historycznej oferty `WITHDRAWN`. |
| Sześć niepodpisanych tabel F-2 — pierwotnie **MEDIUM jako ekspozycja**, nie automatycznie podatność | Static review i wcześniejsza macierz DML wykazały dozwolone bezpośrednie modyfikacje o różnym wpływie. Remediacja 025 celowo nie rozszerza HMAC na te tabele. | **OPEN — niezaakceptowane ryzyko** | Wymagana indywidualna decyzja właściciela dla każdej tabeli; niezależna ocena skutków i weryfikacja, że zakres Laravel/DB spełnia przyjęty model. Szczegóły poniżej. |
| Ograniczony dowód izolacji między organizacjami w modelu jednej organizacji na instalację | Architektura i raporty opisują jeden klient/serwer/instancję; testu dwóch instalacyjnych tenantów nie da się wykonać w tym modelu. | **ACCEPTED RISK — nie**; **ograniczenie dowodu** | Nie osłabiać constraintu jednej instalacji dla testu. Zachować testy zakresu organizacji/lokalizacji i potwierdzić odrębne wdrożenia klientów operacyjnie. Właściciel nie zatwierdził tu żadnego ryzyka. |

## Sześć pozostałych tabel F-2

Wcześniejszy przegląd statyczny rekomenduje je jako **warunkowe ryzyka rezydualne**, nie jako automatyczne blokery tylko z powodu braku HMAC. Raport remediacji 023 zawiera wcześniejsze obserwacje bezpośredniego DML dla części tabel; nie wykonano tych prób w ramach obecnego checkpointu. Poniżej „akceptowalne warunkowo” jest rekomendacją do decyzji właściciela, **nie akceptacją ryzyka**.

| Tabela | Co może obejść runtime SQL / ograniczenia DB opisane w raportach | Ocena względem threat modelu | Rekomendacja i warunek |
|---|---|---|---|
| `organization_suppliers` | Zmienić dozwolone pola nazwy/danych prawnych lub zaszyfrowanego identyfikatora oraz archiwizować bez autoryzacji Laravel; ograniczenia chronią tożsamość/kod i inne niezmienniki, nie każdą zmianę danych. Raport 023 odnotowuje zmianę nazwy bez nowego audytu. | **Akceptowalne warunkowo**, jeśli te pola nie są samodzielnym dowodem finansowym/zgodności, a SQL-only nie jest gwarancją kompletnej autoryzacji każdej kolumny. Ciphertext wymaga ostrożniejszej oceny. | Właściciel domeny ma zatwierdzić ryzyko braku śladu dla zmian. Jeśli dane są regulacyjne/rozliczeniowe, wymagać audytowanej wąskiej ścieżki aktualizacji lub odebrać DML na odpowiednich kolumnach. |
| `raw_material_code_counters` | Przesunąć/cofnąć licznik; unikalność kodu ogranicza duplikaty, lecz konflikt lub wartość graniczna może zablokować tworzenie nowych rekordów. | **Akceptowalne warunkowo** jako ograniczony wpływ dostępności/operacyjny, nie jako dowód możliwości obejścia RBAC. | Właściciel ma zaakceptować ryzyko DoS/naprawy ręcznej. Rozważyć monotoniczność/zgodność z kodami lub odebranie bezpośredniego DML, jeśli dostępność tego przepływu jest krytyczna. |
| `raw_material_categories` | Zmienić nazwę lub dodać kategorię bez śladu aplikacyjnego; DDL/trigger kontroluje strukturę, hierarchię i tożsamość. | **Akceptowalne warunkowo** dla taksonomii organizacyjnej, jeżeli etykiety nie są regulowanym dowodem i autoryzacja zwykłych użytkowników nadal przechodzi przez Laravel. | Właściciel ma jawnie zaakceptować ograniczoną utratę audytowalności; w przeciwnym razie dodać wąski audyt/niezmiennik, nie ogólny signer. |
| `raw_material_revisions` | Dodać rewizję roboczą z treścią/hash-formatem bez autoryzacji Laravel; sam format `content_hash` nie dowodzi, że hash odpowiada treści. Triggery ograniczają insert do wskazanej rewizji roboczej i blokują późniejsze zmiany. | **Akceptowalne warunkowo** tylko dla draftu, jeśli niezależna weryfikacja potwierdzi, że bez autoryzowanej ścieżki nie da się zatwierdzić/wykorzystać fałszywej treści. | Przed akceptacją sprawdzić powiązanie z procesem zatwierdzania; traktować hash jako identyfikator Laravel, nie dowód DB. Właściciel ma zaakceptować możliwość manipulacji draftem. |
| `raw_material_revision_units` | Dodać jednostki do rewizji roboczej; constrainty sprawdzają typy, współczynniki i relacje, a UPDATE/DELETE istniejących zatwierdzonych danych są ograniczone. | **Akceptowalne warunkowo** jako składniki draftu, pod tym samym warunkiem braku możliwości cichego zatwierdzenia. | Niezależnie potwierdzić granicę draft/approval; właściciel ma zaakceptować bezpośredni INSERT do draftu. Nie dodawać HMAC bez wykazanego zysku. |
| `raw_material_aliases` | Dodać alias do rewizji roboczej; unikalność i relacja z rewizją ograniczają strukturę, nie dowodzą uprawnienia aktora. | **Akceptowalne warunkowo** dla niezatwierdzonego draftu, o ile alias nie zmienia samodzielnie interpretacji zatwierdzonego surowca. | Potwierdzić, że alias staje się skuteczny dopiero po zatwierdzeniu właściwej rewizji; właściciel ma zaakceptować możliwość modyfikacji draftu. |

## Dokładny dowód funkcjonalny istniejący w raporcie

Wyniki poniżej pochodzą z `VELTRA_STAGE_2C_MIGRATION_025_FUNCTIONAL_VERIFICATION.md`; nie zostały ponownie uruchomione podczas checkpointu:

- **169 wykonanych testów — PASS**.
- **1381 asercji**.
- **0 failed/errors, 0 warning, 0 PHPUnit skipped** w uruchomionym zakresie.
- **17 testów bezpieczeństwa/adwersarialnych nie uruchomiono** z ograniczenia zakresu. Wyłączono `AuthorizationMutationBoundaryTest`, `RawMaterialPermissionBootstrapTest` i jeden test ochrony ostatniego administratora; te 17 nie są skipami PHPUnit.
- **Świeże migracje PostgreSQL 17: 001–025 — PASS.**
- **Syntetyczny upgrade 024→025 — PASS.** Osobny przypadek aktywnego produktu bez rewizji początkowej został odrzucony; raport stwierdza brak rejestracji 025 i częściowego DDL oraz zachowanie istniejącego wiersza.
- PHP lint, Pint, `sh -n`, Compose config i `git diff --check` — **PASS** według tego raportu.

To nie jest pełna suita (17 testów poza zakresem), niezależny security re-audit ani test rzeczywistej historii instalacji. Wcześniejsze wyniki liczbowe w raportach 021–024 dotyczą innych wersji i przebiegów; nie należy ich sumować ani zastępować nimi wyniku 169/1381.

## Rozdzielenie blokad i ograniczeń

### Blokady akceptacji kodu

1. Niezależny re-audyt bezpieczeństwa aktualnej implementacji po 025 nie jest dostępny. Wcześniejsze audyty zakończyły się REJECT dla starszych wersji; raport 024 jest tylko przeglądem statycznym.
2. Sześć tabel F-2 pozostaje bez jawnej zgody właściciela na konkretne ryzyko. Szczególnej weryfikacji wymaga możliwość zatwierdzenia zmanipulowanej rewizji roboczej oraz wiarygodność danych dostawców.
3. 17 testów nie uruchomiono; w tym nie wykonano ponownie SQL-owych regresji granicy autoryzacji i bootstrapu. Nierozstrzygnięte oczekiwanie SQLSTATE musi testować zarówno semantykę odmowy, jak i niezmieniony stan.
4. Bieżący wynik funkcjonalny nie dowodzi aktualnej odporności na replay, podstawienie aktora/scope, wykorzystanie ticketu w transakcji, delegację wielohopową, trwałe odwołanie ani bezpośrednie fałszowanie ledgerów po 025.

### Wymagania niezależnej weryfikacji

- Niezależny audyt kodu, migracji i Laravel call sites na bieżącym drzewie 025.
- Oddzielne wyniki dla SQL-only przy rzeczywistej roli runtime, API uprawnionych/nieuprawnionych, RBAC, współbieżności, replay/rollback i wszystkich sześciu tabel F-2.
- Sprawdzenie rzeczywistych ACL, default privileges, właścicieli, triggerów, `search_path`, roli runtime/migratora i wydruków/wyjątków z konfiguracją sekretów.
- Jeżeli jakąkolwiek część testów blokuje kontrola bezpieczeństwa usługi, zachować ten brak jako NOT VERIFIED i zatrzymać tę część. Nie obchodzić ani nie ponawiać blokady w sposób niedozwolony.

### Wymagania wyłącznie wdrożeniowe

- Nieznane pozostaje, czy i gdzie 022–025 lub wcześniejsze migracje zostały zastosowane do trwałych baz; w tym checkpointcie nie sprawdzano tego przez połączenie z istniejącą bazą.
- Dla każdej instalacji uzgodnić rzeczywiste historyczne rekordy i niezależnie zatwierdzić raport. Tekst atestacji migracji nie jest dowodem poprawności danych.
- Przed 025 trzeba rozstrzygnąć dane niezgodne z preconditions, w tym aktywny produkt bez rewizji nr 1 oraz ofertę w stanie `WITHDRAWN`. Migracja celowo nie wybiera ani nie stosuje automatycznej korekty.
- Potwierdzić rozdział runtime/migrator, konfigurację niezależnego klucza, rotację/odtworzenie i brak sekretów w logach. Plan dla forward-only migracji musi opierać się na migracji naprzód lub odtworzeniu kopii.
- Rozstrzygnąć ścieżkę nadania nowych permissionów na istniejącej organizacji Stage 2B przed aktualizacją takiej instalacji.

### Akceptowane ograniczenia architektoniczne — nie oznaczają zgody właściciela

- Laravel jest zaufaną granicą decyzji biznesowych; PostgreSQL nie odtwarza samodzielnie pełnej autoryzacji HTTP.
- Arbitralne RCE w Laravel, dostęp do sekretów tego procesu oraz kompromitacja administratora/właściciela PostgreSQL są poza deklarowaną gwarancją.
- Ticket nie jest podpisem biznesowym ani ochroną przed dowolnym kodem w skompromitowanym procesie. Ma służyć wyłącznie wąskim, wybranym zabezpieczeniom DB zgodnym z zatwierdzonym modelem.
- Jeden klient na instalację ogranicza dowód dynamicznego testu wielu tenantów w pojedynczej instancji; nie jest podstawą do usunięcia tenant-scope checks ani do osłabienia constraintu wdrożeniowego.

## Dokładne czynności do osiągnięcia Stage 2C ACCEPT

1. Uzyskać osobną, uprawnioną niezależną re-audytację aktualnej implementacji 025, bez przekraczania kontroli bezpieczeństwa usługi.
2. Zamknąć każde ustalenie kodowe z audytu. Gdy brak wykrycia nie jest dowodem z powodu blokady, pozostawić je otwarte.
3. Uzyskać jawne decyzje właściciela projektu dla każdej z sześciu tabel F-2 albo wprowadzić tylko te precyzyjne, uzasadnione kontrolki, których wymaga wynik oceny.
4. Uzupełnić/naprawić SQLSTATE regression test tak, by sprawdzał kod/semantykę błędu oraz stan przed/po; przywrócić pominięte pokrycie bezpieczeństwa w osobno dozwolonym przebiegu.
5. Zatwierdzić procedurę przeglądu danych historycznych, nowego bootstrapu permissionów dla istniejącej instalacji, provisioningu i rotacji klucza.
6. Wykonać osobną bramkę wdrożeniową per instalacja na podstawie udokumentowanej historii i stanu danych; ten krok nie może być zastąpiony syntetycznym upgrade’em.
7. Po spełnieniu powyższego opublikować nowy, niezależny werdykt; do tego czasu utrzymać `RAW_MATERIALS` jako `NOT_READY` i nie rozpoczynać Stage 2D ani aktywacji VSEF.

## Ostateczna rekomendacja

**NO-GO. Nie deklarować Stage 2C ACCEPT.** Istniejące dowody potwierdzają ważne poprawki i pozytywną funkcjonalną regresję 169/1381 w ograniczonym zakresie, ale nie wystarczają do zaakceptowania bezpieczeństwa całej Stage 2C ani wdrożenia na istniejącej instalacji. Otwarte wymagania obejmują niezależny re-audyt po migracji 025, 17 niewykonanych testów bezpieczeństwa, niezatwierdzone ryzyka sześciu tabel F-2 oraz przegląd rzeczywistej historii i warunków konkretnej instalacji.

**Zakres checkpointu:** nie zmieniano kodu ani konfiguracji, nie uruchamiano testów ani operacji bazodanowych, nie wdrażano migracji, nie zmieniano istniejących baz/kontenerów/wolumenów, nie wykonywano commitów ani pushy.
