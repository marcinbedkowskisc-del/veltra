# VELTRA 2.0 — decyzja architektoniczna bezpieczeństwa Stage 2C

## Decyzja

**Rekomendacja: uprościć i przeprojektować migrację 022. Nie akceptować obecnej architektury ani nie wdrażać jej.**

Docelowym modelem powinny być:

1. Laravel jako jawnie zaufana granica dla uwierzytelniania i decyzji biznesowych: polityki, bramki i serwisy domenowe autoryzują każdą operację na podstawie aktora, organizacji, członkostwa, zakresu i celu.
2. PostgreSQL z oddzielnymi rolami runtime i migracyjną, najmniejszymi potrzebnymi grantami, ograniczeniami integralności i triggerami niezmienników danych.
3. Wąski, jawny mechanizm bazy dla niewielkiej liczby operacji wysokiego ryzyka, jeśli ma chronić je przed użyciem samych poświadczeń DB. Taki mechanizm ma weryfikować krótko żyjący, związany z konkretną operacją dowód autoryzacji; nie może być ogólnym „podpisz dowolny scope” helperem ani udawać ochrony przed kompromitacją Laravel.
4. Audyt z rozdzieleniem praw do dopisywania zdarzeń od ich modyfikowania/usuwania, a dla silnego dowodu przeciw administratorowi bazy — kopia lub łańcuch audytu poza bazą i hostem aplikacji.

Obecny HMAC można zachować jedynie jako **wąskie zabezpieczenie integralności granicy aplikacja–DB dla dostępu SQL**, po przeprojektowaniu. Nie jest i nie powinien być nazywany mechanizmem autoryzacji biznesowej. Obecny szeroki, oparty na `APP_KEY` mechanizm z migracji 022 należy odrzucić jako kandydat do wdrożenia.

## Model zagrożeń i osiągalne gwarancje

| Scenariusz | Osiągalne gwarancje | Czego nie gwarantuje ten model |
|---|---|---|
| **1. Uwierzytelniony użytkownik wywołuje niedozwolone API** | Walidacja wejścia, uwierzytelnianie, Laravel Policies/Gates i serwisy domenowe mogą odrzucać działanie bez właściwej roli, członkostwa, zakresu organizacji, hasła/ponownego potwierdzenia lub stanu zasobu. Ograniczenia i transakcje DB chronią integralność oraz wyścigi. Testy API powinny sprawdzać zarówno odmowę, jak i brak jakichkolwiek skutków ubocznych. | Nie chroni przed błędem autoryzacji w kodzie ani przejęciem sesji/uprawnień użytkownika. Sam PostgreSQL nie zna wiarygodnej tożsamości HTTP, o ile nie dostanie kryptograficznie potwierdzonego kontekstu. |
| **2. SQL injection albo bezpośredni dostęp wyłącznie z poświadczeniami runtime PostgreSQL** | Osobny użytkownik runtime bez `SUPERUSER`, własności obiektów, członkostwa w rolach uprzywilejowanych, DDL, `TRUNCATE` i dostępu do prywatnych schematów ogranicza skutki. Parametryzowane zapytania, wąskie granty, constrainty, append-only i procedury dla operacji krytycznych ograniczają możliwe działania. Oddzielny, niedostępny z DB klucz podpisujący może uniemożliwić samodzielne tworzenie dowodu wymaganej mutacji. | Jeśli runtime ma bezpośredni DML do tabel, SQL injection może ominąć Policies i wykonać dozwolony grantami DML. GUC/session variables i `actor_id` podane przez ten sam runtime nie są dowodem tożsamości. Procedura, która ufa takim parametrom, również nie zapewnia autoryzacji aktora wobec napastnika DB. |
| **3. Kompromitacja procesu Laravel z sekretami i poświadczeniami DB** | Izolacja usług, ograniczenie sieci i uprawnień systemowych, rotacja sekretów, monitoring i szybkie unieważnianie sesji ograniczają zasięg i czas incydentu. Osobny proces/serwis może ograniczyć dostęp do wybranych sekretów, jeśli ma odrębne ACL i niezależnie weryfikuje roszczenia. | Nie można uznać decyzji autoryzacyjnych wykonywanych przez dowolny kod w skompromitowanym procesie za wiarygodne. Napastnik może używać jego sesji, sekretów i dozwolonych interfejsów jak aplikacja. HMAC utworzony przez ten proces nie odróżnia dobrego kodu od kodu napastnika. |
| **4. Kompromitacja roli migracyjnej/administracyjnej PostgreSQL** | Rozdzielenie roli migracyjnej, kontrola dostępu do sekretów i konsoli DB, krótkotrwałe poświadczenia, kopie zapasowe, alerty oraz niezależny eksport audytu mogą ograniczyć i ujawnić skutki. Po incydencie odtworzenie z zaufanej kopii i niezależnego audytu może pozwolić ustalić stan. | Rola właścicielska/migracyjna może zmienić schemat, wyzwalacze, ACL i dane w tej bazie. Żaden trigger ani SECURITY DEFINER w tej samej bazie nie jest wiarygodną ochroną przed jej właścicielem/superuserem. Niezaprzeczalność wymaga kopii dowodów poza tą bazą i domeną administracyjną. |

W modelu wdrożenia „jeden klient — jeden serwer” Laravel i PostgreSQL są zwykle częścią tej samej domeny administracyjnej. Izolacja kontenerów i ról nadal zmniejsza ryzyko, lecz nie należy przedstawiać jej jako odporności na administratora hosta ani jako pełnej separacji od RCE w backendzie.

## Rozważone architektury

### A. Laravel jako granica autoryzacji, PostgreSQL jako warstwa integralności

- Wszystkie decyzje o prawach pozostają w Laravel Policies/Gates i serwisach domenowych. Kontrolery nie implementują reguł samodzielnie; każda mutacja przechodzi przez jeden serwis, a krytyczne zmiany są atomowe.
- Runtime ma wyłącznie potrzebne prawa do konkretnych tabel/kolumn i sekwencji; migrator jest oddzielny i używany tylko przez kontrolowany proces. Role nie są właścicielami tabel ani członkami ról nadrzędnych.
- DB wymusza niezmienniki, których nie powinno dać się złamać również przez aplikacyjny błąd: FK, CHECK, unikalność, nieodwracalną revocation, spójność organizacji oraz wybrane blokady/wyzwalacze. Operacje krytyczne mogą mieć wąskie funkcje DB z pełnymi ACL i stałym `search_path`, ale tylko tam, gdzie warunek jest lokalny i nie udaje weryfikacji HTTP aktora.
- Zdarzenia audytowe są dopisywane przez ścieżki domenowe; runtime nie ma `UPDATE`/`DELETE` do historii. Kopia audytu może być eksportowana do niezależnego systemu.

**Zalety:** proste dla zespołu Laravel, zgodne z monolitem i jednym serwerem, mało duplikacji logiki, łatwe lokalne testy.  
**Koszt i ograniczenia:** ochrona przed SQL injection zależy od grantów i ograniczeń; runtime z szerokim DML może wykonać operacje z pominięciem autoryzacji HTTP. RCE w Laravel pozostaje przejęciem zaufanej granicy. Append-only nie dowodzi, że dopisany event jest prawdziwy.

### B. Hybryda z wąskim DB API dla mutacji wysokiego ryzyka

- Zasady dostępu HTTP nadal są sprawdzane przez Laravel. Dla małej, zamkniętej grupy mutacji (np. globalny administrator, nadanie/uprawnienie, trwałe odwołanie) runtime traci bezpośredni DML do chronionych tabel.
- Mutacja przechodzi przez osobną procedurę/funkcję z `SECURITY DEFINER`, należącą do roli bez logowania, z ustalonym bezpiecznym `search_path`, minimalnym SQL, walidacją wszystkich pól i `EXECUTE` wyłącznie dla runtime. Funkcja wymusza integralność, idempotencję, niezmienniki oraz zapis zdarzenia w tej samej transakcji.
- Jeśli ochrona ma obejmować SQL injection z poświadczeniami DB, procedura wymaga dowodu podpisanego kluczem niedostępnym runtime DB. Dowód powinien być wydawany dopiero po autoryzacji przez zaufany komponent, być krótko ważny, jednorazowy i związany z aktorem, organizacją, typem operacji, identyfikatorami celu, treścią mutacji i transakcją. Sam GUC, role session-level ani parametr `actor_id` nie wystarczą.
- Taki komponent może ograniczyć SQL-only ataki, lecz jeśli sekret i wystawianie dowodów pozostają w procesie Laravel, nie chroni przed kompromitacją tego procesu. Osobny broker może podnieść granicę tylko wtedy, gdy samodzielnie weryfikuje zaufane uwierzytelnienie i reguły, ma osobny sekret/poświadczenia i nie ufa żądaniom Laravel jako decyzji autoryzacyjnej.

**Zalety:** mocniejsza ochrona wybranych tabel przy kradzieży samych danych runtime DB; bezpośredni DML i bypass triggerów można zamknąć grantami.  
**Koszt i ograniczenia:** logika autoryzacji lub jej dowody mogą zostać zdublowane; procedury są trudniejsze do testowania, wersjonowania, debugowania i migrowania. Nieprawidłowe `SECURITY DEFINER`, `search_path`, EXECUTE ACL lub role właścicielskie tworzą ryzyko eskalacji. Każda operacja wymaga testów rollbacku, współbieżności, idempotencji i zgodności migracji. Nadal nie chroni przed administratorem DB ani automatycznie przed skompromitowanym backendem.

## Rekomendowany model dla VELTRA

Wybrać **architekturę A jako podstawę**, z **ograniczonymi elementami B tylko dla precyzyjnie wybranych tabel i operacji wysokiego ryzyka**. Nie budować generycznego podpisywania wszystkich mutacji ani drugiej, rozproszonej kopii całego RBAC w PL/pgSQL.

Uzasadnienie: model jednego klienta na serwerze nie uzasadnia kosztu pełnego, niezależnego silnika autoryzacji DB dla każdej tabeli. Dla zwykłych operacji wiarygodną granicą jest Laravel; gwarancje dla SQL-only należy uzyskać przez najmniejsze granty, ograniczenie bezpośredniego DML na krytycznych tabelach i wąskie procedury. Przeciw RCE w Laravel trzeba jawnie przyjąć, że autoryzacja w tym samym procesie może zostać ominięta. Jeżeli biznes wymaga ochrony także przed RCE, trzeba utworzyć rzeczywiście niezależną granicę administracyjną/uwierzytelniającą, a nie dodawać kolejny HMAC do tego samego procesu.

### Zalecenia dotyczące obecnego HMAC i migracji 022

- Nie zachowywać obecnego `AuthorizationMutationContext::run(scope, actor, closure)` jako publicznego mechanizmu autoryzacji. Jest zbyt ogólny; sam wywołujący wybiera scope i aktora, a ticket nie wiąże podpisu z konkretnym DML ani wartościami rekordu.
- Jeżeli HMAC zostaje dla ochrony DB przed SQL-only, przekształcić go w szczegółowy capability dla jawnie nazwanych operacji, nie w bilet z dowolnym tekstowym scope. Wydać capability dopiero po autoryzacji domenowej, związać go z dokładnym payloadem i kontekstem, wymusić jednorazowość oraz nie udostępniać ogólnego callbacku. Nie przedstawiać go jako ochrony przed RCE Laravel.
- Nie używać `APP_KEY` jako sekretu DB. Użyć niezależnego, losowego klucza z kontrolowaną rotacją i ograniczonym dostępem; nigdy nie umieszczać sekretu w logowanych poleceniach/SQL. Jeśli klucz jest dostępny temu samemu runtime, nadal nie daje odporności na kompromitację runtime.
- Ograniczyć użycie mechanizmu do chronionych zmian RBAC/audytu, jeśli uzasadnia to konkretny model SQL injection. Dla pozostałych tabel preferować polityki Laravel, granty i constrainty. Nie dodawać szerokich triggerów, które tworzą fałszywe poczucie ochrony każdej mutacji.
- **Stan gałęzi rozwojowej:** pozostawić migrację 022 chwilowo wyłącznie jako odrzucony prototyp i materiał audytowy; oznaczyć ją w procesie prac jako „nie wdrażać / Stage 2C niezaakceptowane”. Nie traktować jej jako zaakceptowanego rozwiązania ani podstawy wydania. Ponieważ nie wdrożono jej do istniejącej bazy w ramach tego zadania, można przed wydaniem zastąpić ją poprawnym projektem w gałęzi. Jeśli jakiekolwiek współdzielone/produkcyjne środowisko już ją zastosowało, nie edytować zastosowanej migracji — przygotować przetestowaną migrację wyłącznie do przodu i plan przejścia.

## Ochrona historii i audytu

- Oddzielić niezmienność (runtime nie może aktualizować/usuwać historii) od autentyczności (czy zdarzenie rzeczywiście wynikało z autoryzowanej operacji). Trigger append-only zapewnia tylko pierwszą właściwość.
- Tam, gdzie event jest dowodem dla następnej decyzji autoryzacyjnej, nie dopuścić, by runtime mógł niezależnie wstawić event i rekord operacji uznawane za wiarygodny dowód. Użyć jednej atomowej, ograniczonej ścieżki zapisu albo zewnętrznego dowodu podpisanego przez niezależny komponent.
- Dla wykrywalności manipulacji przez migratora/superusera, wysyłać audyt poza bazę (inna domena uprawnień/host albo usługa zdalna), z kontrolą braków i kolejności. Audyt w tej samej bazie nie jest odporny na właściciela tej bazy.

## Atestacja historii, rotacja kluczy i wyniki testów

### Historyczna atestacja

Wartość `REVIEWED:<nazwa_bazy>:2026_11_05_000022` wykrywa brak wymaganego ręcznego kroku, ale nie jest niezależnym dowodem przeglądu ani poprawności dawnych nadań. Przed migracją na istniejącej instalacji należy:

1. uzgodnić konta, role, aktywne i odwołane granty, historię operacji oraz zdarzenia audytu;
2. sprawdzić relacje aktor–cel–organizacja i wykryć duplikaty/osierocone rekordy;
3. zachować raport z migawki źródłowej, użyte zapytania, wynik i osobę zatwierdzającą poza bazą migrowaną;
4. wymagać niezależnej akceptacji dla niejednoznacznych danych; przy rozbieżności migracja ma kończyć się bez zmian.

Nie akceptować samego syntaktycznie prawidłowego ciągu jako dowodu czystości historii.

### Rotacja klucza

Nie wykazano bezpiecznej, zgodnej z istniejącymi instalacjami procedury rotacji. Przed przyjęciem jakiegokolwiek biletu należy opisać i przetestować: identyfikator wersji klucza, rotację z ograniczonym okresem nakładania, atomową synchronizację weryfikatora DB i sygnatariusza, zachowanie po awarii/rollback, unieważnienie starego klucza, kopie zapasowe oraz bezpieczne odtworzenie. Klucz nie może pochodzić z `APP_KEY` ani być emitowany w logach migracji, PostgreSQL, APM lub wyjątkach. W razie braku potrzeby podpisów lepiej usunąć ten mechanizm niż utrzymywać kruchą zależność rotacyjną.

### Nieudany SQLSTATE i pominięty test

- Niezależny pełny backend miał **182 testy, 1508 asercji, 1 błąd i 1 pominięty**. Błąd w `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available` oczekiwał `42501`, otrzymał `22023`. Operacja została odrzucona, więc wynik nie potwierdza udanego obejścia; jednocześnie test nie zaliczył się i nie wykonał dalszych asercji tej metody. Nie należy po prostu poluzować asercji. Należy ustalić, czy `22023` jest prawidłową odmową dla danego wejścia, a następnie asertywnie testować stan DB bez skutków ubocznych oraz osobno pokryć oczekiwane odmowy privilege i invalid ticket.
- `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` pozostał pominięty, bo fixture wykonuje bootstrap bez podpisanego kontekstu przy głównym połączeniu runtime. To brak pokrycia automatycznego, a nie potwierdzona podatność. Przed akceptacją należy przebudować test na fixture zgodny z rzeczywistą rolą runtime albo wykonać osobny test adwersarialny z poświadczeniami runtime, tymczasowymi obiektami i manipulacją `search_path`; test musi sprawdzać brak trwałej zmiany oraz właściwy błąd.
- Niezależne testy migracji 001–022 oraz upgrade 001–021 → 022 przeszły na disposable bazie. To nie unieważnia błędu regresji ani pominiętej ścieżki, i nie dowodzi poprawności historycznych danych konkretnej instalacji.

## Ograniczony plan przed akceptacją Stage 2C

1. **Zamknąć decyzję o granicy zaufania:** zaakceptować, że Laravel jest zaufany dla autoryzacji użytkownika, albo sfinansować niezależny komponent z własnym uwierzytelnianiem i odrębnymi sekretami. Nie deklarować ochrony RCE bez takiej separacji.
2. **Zawęzić projekt DB:** zinwentaryzować wszystkie tabele i operacje krytyczne; dla każdej ustalić, czy wymaga bezpośredniego DML, triggera niezmiennika, czy ograniczonej funkcji. Usunąć zbędne szerokie scope’y.
3. **Zamknąć ustalenie migracji 022:** usunąć możliwość wystawiania dowolnego ticketu; jeśli podpis jest uzasadniony dla SQL-only, projektować bilety związane z konkretną mutacją i osobnym kluczem. Sprawdzić ACL każdej funkcji `SECURITY DEFINER`, właściciela, `search_path` i wszystkie ścieżki wywołania.
4. **Dokończyć integralność RBAC/audytu:** udowodnić replay, permanent revocation, wielohopowe delegacje, atomicity, rollback i concurrency na rzeczywistych rolach; runtime nie może fabrykować dowodów, na podstawie których DB udziela następnych uprawnień.
5. **Ustalić procedurę dla istniejących danych i sekretów:** udokumentować ręczne uzgodnienie historii, blokadę wdrożenia przy niespójności oraz rotację/odtworzenie sekretów. Przetestować czystą instalację i upgrade na disposable danych reprezentatywnych dla istniejących instalacji.
6. **Naprawić pokrycie:** rozstrzygnąć SQLSTATE testu według zachowania i stanu danych, odtworzyć pominięty test na runtime oraz uruchomić skoncentrowane RBAC, pełną regresję, PHP syntax/Pint, migracje i `git diff --check`. Wyniki niezależnej reaudytacji mają być osobne od wyników implementacji.
7. **Niezależna akceptacja:** powtórzyć testy adwersarialne przed oznaczeniem Stage 2C jako zaakceptowanego. Brak krytycznego wykrycia nie oznacza ochrony przed kompromitacją zaufanej granicy.

## Konkluzja

**Obecna migracja 022: odrzucona do wdrożenia.** Należy ją traktować jako prototyp ochrony przed SQL-only, nie jako źródło autoryzacji. Zalecana jest autoryzacja Laravel jako główna granica, minimalne uprawnienia PostgreSQL, twarde niezmienniki DB, ograniczone interfejsy DB tylko dla kilku krytycznych przejść oraz eksport audytu poza bazę, gdy wymagana jest odporność na administratora DB. Po akceptacji zakresu gwarancji, poprawieniu podpisywania albo jego usunięciu, naprawieniu testów i zamknięciu atestacji/rotacji Stage 2C nadal wymaga niezależnego re-audytu.

**Stan ograniczeń:** raport nie wprowadza implementacji ani zmian w migracjach, konfiguracji, bazach, testach lub kontenerach. Nie wykonano wdrożenia, commitów, pushy, resetów ani rebase. RAW_MATERIALS pozostaje `NOT_READY`; Stage 2D i VSEF nie zostały rozpoczęte.
