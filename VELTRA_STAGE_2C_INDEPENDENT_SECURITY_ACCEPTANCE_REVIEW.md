# VELTRA 2.0 — Stage 2C — Niezależny przegląd akceptacyjny bezpieczeństwa

Data: 7 listopada 2026 r.  
Tryb: READ-ONLY — analiza zachowanych dowodów i źródeł, bez wykonania testów ani SQL.  
Snapshot: HEAD `2f4c1abb932f474d9415a8ea1d73c343d2539929` **wraz z dwiema istniejącymi zmianami testów**, zgodnymi z manifestem końcowej weryfikacji. Sam hash HEAD nie identyfikuje całego badanego worktree.

## 1. Rekomendacja końcowa

**NO-GO — na obecnym stanie rozstrzygnięcia kryteriów akceptacyjnych.**

Wyniki wykonania zostały potwierdzone niezależnym odczytem artefaktów:

- **17/17 metod bezpieczeństwa PASS, 304 asercje**;
- **252/252 przypadki pełnej regresji PASS, 2417 asercji**;
- **0 FAIL, 0 ERROR, 0 skipped**, bez wyłączeń;
- VEL-DEF-007: pierwotne **13/13 odmów**, podstawa do rekomendacji zamknięcia w określonym zakresie runtime.

Nie potwierdzono nowej podatności aplikacyjnej. NO-GO nie oznacza złamania czterech głębszych guardów. Powodem wstrzymania rekomendacji akceptacyjnej jest **nierozstrzygnięta adekwatność dowodów dla czterech pierwotnych kontraktów integralności**: granica podpisu A jest wykazana, samodzielna odmowa głębszych guardów B pozostaje NOT VERIFIED. Nie znaleziono wystarczającej podstawy, aby uznać każde B za bezwzględnie obowiązkowe, ale nie znaleziono również aktualnego, jawnego rozstrzygnięcia, że wykazana A wystarcza zamiast B dla tych konkretnych kontraktów.

Nie wolno zastępować tej decyzji domniemaniem wykonawcy. Nie rekomenduję CONDITIONAL ACCEPT, ponieważ dostępne dowody nie potwierdzają aktualnie zatwierdzonej ścieżki takiej akceptacji dla tego snapshotu i tych czterech zastrzeżeń. Nie rekomenduję też bezwarunkowego ACCEPT z przemilczeniem NOT VERIFIED.

**Nie nadano formalnego Stage 2C ACCEPT, nie zmieniono rejestru ryzyk ani statusu żadnego defektu.**

## 2. Niezależność i ograniczenia przeglądu

Zastosowano odrębny kontekst agenta `security-review`, bez udziału tego kontekstu we wdrożeniu. Pierwszy recenzent samodzielnie odczytał raporty, źródła i surowe artefakty; nie ograniczył się do podsumowania wykonawcy. Ponieważ jego wniosek o bezwzględnej obowiązkowości każdego B wymagał lepszego uzasadnienia, drugi, osobny kontekst wykonał wąskie uzupełnienie: oryginalne kryteria akceptacji, VEL-DEF-007, pełny manifest źródeł i zachowanie asercji pięciu korekt.

To **niezależność kontekstu analitycznego AI**, nie dowód niezależności organizacyjnej, zewnętrzny audyt człowieka ani atestacja pochodzenia infrastruktury. Oba konteksty korzystały z tych samych dostarczonych artefaktów i narzędzi. Wykonawca poprzedniej remediacji zredagował i opublikował dokument; nie przedstawia swojego wcześniejszego self-review jako niezależnego audytu. Rozbieżność recenzentów ujawniono w sekcji 4.

W tym zadaniu:

- nie uruchamiano PHPUnit, skryptów atakujących, środowisk Docker ani zapytań do baz;
- nie zmieniano kodu, testów, konfiguracji, migracji ani rejestru ryzyk;
- wykonano odczyty, parsowanie istniejących XML/JSON/logów, porównania hashy i statyczną analizę;
- jedynym nowym plikiem repozytorium jest niniejszy raport;
- nie wykonano commitów, pushy, deploymentu ani aktywacji RAW_MATERIALS/VSEF.

## 3. Podstawa źródłowa i integralność dowodów

### 3.1. Raporty i decyzje

Przejrzano:

1. [Końcową weryfikację kontraktu testów](VELTRA_STAGE_2C_FINAL_SECURITY_TEST_CONTRACT_VERIFICATION.md).
2. [Poprzednią remediację i rerun](VELTRA_STAGE_2C_SECURITY_TEST_REMEDIATION_AND_RERUN_REPORT.md).
3. [Triage nieudanych testów](VELTRA_STAGE_2C_SECURITY_TEST_FAILURE_TRIAGE.md).
4. [Pierwotną autoryzowaną weryfikację](VELTRA_STAGE_2C_AUTHORIZED_SECURITY_VERIFICATION_REPORT.md).
5. [Plan ponownego otwarcia akceptacji](VELTRA_STAGE_2C_SECURITY_ACCEPTANCE_REOPENING_PLAN.md).
6. [Decyzję architektoniczną](VELTRA_STAGE_2C_SECURITY_ARCHITECTURE_DECISION.md), zwłaszcza linie 7–11 i 29–45.
7. [Checkpoint gotowości](VELTRA_STAGE_2C_FINAL_READINESS_CHECKPOINT.md), zwłaszcza linie 47–56.
8. [Starszą formalną decyzję zamknięcia](VELTRA_STAGE_2C_FORMAL_CLOSURE_DECISION.md), zwłaszcza linie 3–14 i 41–43.
9. Dostępne decyzje właściciela z historii: autoryzację pierwotnych 17 metod i VEL-DEF-007, zakres T-01–T-04 oraz końcową autoryzację pięciu korekt z obowiązkowym rozróżnieniem A/B.

Nie utożsamiano streszczeń checkpointów z nową, formalną zgodą właściciela.

### 3.2. Co zweryfikowano niezależnie

Katalog bazowy zachowanych dowodów:

```text
/home/veltra/.copilot/session-state/5f8720b2-0de3-4a1d-855a-82a5d95482c2/files/
```

| Kontrola | Wynik niezależnego odczytu |
|---|---|
| Pierwotny manifest dowodów | **99/99 zgodnych pozycji** |
| Manifest poprzedniej remediacji | **154/154 zgodnych pozycji**, zachowane wcześniejsze FAIL/ERROR |
| Końcowy manifest dowodów | **169/169 zgodnych pozycji** |
| Pełny końcowy manifest tracked | **400/400 plików zgodnych z bieżącym worktree**, bez braków i rozbieżności |
| Osobny manifest źródeł | **66/66 plików zgodnych**, w tym wszystkie **36 migracji** |
| Baseline sprzed końcowej remediacji | 398 zgodnych pozycji, dwa oczekiwane odchylenia: autoryzowane testy |
| Hash raportu końcowego | zgodny z manifestem |
| Indywidualne JUnit | 17 dokładnych nazw metod, 304 asercje, brak failure/error/skipped |
| Pełny JUnit | 252 przypadki, 2417 asercji, brak failure/error/skipped |
| Inwentaryzacja pełnej suite | 252/252; każda z 17 autoryzowanych metod dokładnie raz |
| Pierwsza piątka | 5 PASS, 184 asercje, zachowana osobno od kolejnych wykonań |

Źródła: `stage2c-authorized-evidence/evidence-sha256.json`, `stage2c-rerun/evidence-sha256.json`, `stage2c-final/evidence-sha256.json` oraz podkatalog `stage2c-final/stage2c-authorized-evidence/`: `tracked-hashes.json`, `source-hashes.json`, `corrected-five/`, `authorized-seventeen/`, `regression.xml`, `regression.txt`, `regression-list-tests.txt` i `regression-plan.json`.

Zgodność manifestów zabezpiecza przed niezauważoną zmianą względem zachowanych hashy, ale **nie jest kryptograficznym podpisem niezależnego świadka ani dowodem, że wykonawca nie mógł stworzyć artefaktów**. Pochodzenie dowodów oceniano z ich wzajemnej spójności, nie z zewnętrznej atestacji.

Sprawdzono inwentaryzację i zgodność migracji 001–036 oraz statycznie istotne mechanizmy autoryzacji/integralności, zwłaszcza migracje 012, 015–018, 022–023, 025–026 i 035. Nie należy interpretować tego jako nowego pełnego audytu każdej linii wszystkich 36 migracji.

### 3.3. Zachowanie asercji

Porównanie kopii baseline z końcowymi dwoma testami potwierdza:

- zachowanie danych i negatywnych operacji metod 7, 11, 12 i 13;
- brak przeniesienia negatywnego runtime SQL do migratora;
- zachowanie dalszych asercji cofnięcia, efektywnej authority, reaktywacji, członkostw i audytu;
- ścisłe `42501`, komunikat podpisanej granicy, rzeczywiste role oraz pełne snapshoty zamiast poprzednich nieosiągniętych oczekiwań `23514`;
- brak poszerzenia dopuszczanych SQLSTATE w tych czterech przypadkach;
- zachowanie prób oferty i produktu w metodzie 16; odroczony constraint wymuszony wewnątrz savepointu, wymagane `23514` i kontekst `supplier_product_initial_revision_validate`;
- flaga ustawiana przed `SET CONSTRAINTS` uniemożliwia uznanie wcześniejszego wyjątku za osiągnięcie odroczonej granicy;
- po rollbacku brak produktu i jego rewizji, równe snapshoty; późniejsze pierwotne przypadki oraz końcowe `SET CONSTRAINTS ALL IMMEDIATE` pozostały.

Istotne ograniczenie: w metodzie 11 pierwszy unsigned INSERT operacji jest odrzucony; dwa dalsze INSERT-y audytu/grantu pozostają w źródle, ale **nie zostały wykonane** w tej ścieżce. Kompletność uruchomienia metod nie oznacza wykonania każdej instrukcji za wyjątkiem.

### 3.4. Role, izolacja, sprzątnięcie

Źródła: `isolation-before-migrations.json`, `connection-identities.json`, `roles-and-triggers-before-tests.txt`, `scanner-isolation.json`, `post-regression-state.txt`, `cleanup.json` w pakiecie końcowym; analogiczne dowody pierwotnego środowiska dla VEL-DEF-007.

| Właściwość | Ocena |
|---|---|
| PostgreSQL | nowy disposable PostgreSQL 17.11, migracje 001–036 |
| Sieć | dedykowana `Internal=true`, kontenery wyłącznie w niej, brak połączenia z siecią aplikacji |
| Storage i porty | brak istniejących bindów/wolumenów, nowe tmpfs, brak publikacji portów |
| Usługi | oddzielne nowe PostgreSQL, Redis, runner i ClamAV |
| Runtime | current_user i session_user = `veltra_app`, baza `veltra_testing` |
| Setup/migrator | current_user i session_user = `veltra_migrator`; odrębny właściciel bazy `veltra_test_admin` |
| Uprawnienia runtime | brak superuser/createdb/createrole/bypassrls, CREATE public, dostępu do private i bezpośredniego INSERT ledgeru |
| Uprawnienia migratora | właściciel tabel, nie właściciel bazy i nie superuser; zaufany fixture/migracje, nie negatywne próby runtime |
| Triggery | przed testami aktywne; po regresji zero nieaktywnych niesystemowych triggerów |
| Sprzątnięcie | usunięto cztery własne kontenery i własną sieć; brak zasobów z etykietą zadania |
| Istniejąca infrastruktura | sześć kontenerów zachowało ID i sieci; brak dowodów ingerencji w ich dane |

Odczytane dowody są spójne z wymaganą izolacją. Nie przeprowadzano teraz inspekcji żywej bazy ani infrastruktury. Niezmienione ID kontenerów samo nie dowodzi nienaruszenia każdej strony danych; wniosek opiera się również na izolowanych celach połączeń, mountach, poleceniach i przebiegu wykonania.

## 4. Model zagrożeń i znaczenie NOT VERIFIED

Decyzja architektoniczna określa Laravel jako zaufaną granicę autoryzacji biznesowej, a PostgreSQL jako warstwę integralności i least privilege. Podpisana granica ma odrzucać niepodpisane mutacje posiadacza poświadczeń runtime. Nie obiecuje ochrony przed przejęciem zaufanego procesu Laravel z jego materiałem podpisującym, migratora ani właściciela bazy.

W tym raporcie:

- **A** = skuteczna odmowa unsigned runtime SQL na signed mutation boundary;
- **B** = niezależne wykazanie konkretnego głębszego business/integrity guarda po dojściu do niego.

Te oznaczenia nie są wariantami architektonicznymi A/B z wcześniejszej decyzji architektonicznej.

Pierwszy recenzent uznał wszystkie B za obligatoryjne i zalecił NO-GO z tego powodu. Uzupełniający przegląd źródłowy **nie znalazł podstawy do tak kategorycznego twierdzenia**:

1. Decyzja architektoniczna nie ustanawia powszechnego obowiązku niezależnego testu B dla każdej mutacji.
2. Końcowa autoryzacja właściciela dopuszcza dokładne `42501` dla gwarancji A; wymaga zachować B jako NOT VERIFIED, jeżeli pierwotny test ma ją niezależnie dowodzić. Nie stanowi ani automatycznego odstąpienia od B, ani nakazu, że każde B blokuje akceptację.
3. Starsza formalna decyzja z 6 listopada odrzucała ACCEPT/CONDITIONAL ACCEPT dla wcześniejszego stanu obejmującego migracje do 027. Nie można jej bez uzasadnienia traktować jako niezmiennego zakazu dla obecnych nowych dowodów 001–036.
4. Nowsze autoryzacje wykonania i korekt testów nie są same w sobie decyzją o akceptacji czterech braków.

**Wniosek wspólny dla bezpiecznej decyzji:** A jest prawidłowo wykazana; B nie jest dynamicznie wykazana. Brak jednoznacznego, aktualnego rozstrzygnięcia statusu B w kryteriach akceptacji jest blokadą decyzyjno-dowodową. Nie udowodniono, że cztery dodatkowe testy są jedyną dopuszczalną drogą zamknięcia tej blokady.

Akceptacja architektury z wystarczającą A w zatwierdzonym modelu byłaby legalną decyzją architektoniczną — o ile jest jawna, zachowuje NOT VERIFIED dla B i nie przemianowuje nieprzeprowadzonych prób na PASS. Jeśli natomiast wymagane jest niezależne B, potrzebny jest odrębny, autoryzowany dowód, nie samo stwierdzenie „17/17”.

## 5. Macierz gwarancji bezpieczeństwa

| Gwarancja | Dowód i wynik | Ograniczenie akceptacyjne |
|---|---|---|
| Oddzielne role migratora, runtime i właściciela | role/ACL/identity + metody 1–2: PASS | zaufany migrator poza modelem nieuprzywilejowanego runtime |
| Ledger: runtime bez bezpośredniej mutacji | metoda 2: PASS, ledger bez zmian | nie jest próbą przeciwko uprzywilejowanemu właścicielowi |
| Security-definer i shadowing obiektów tymczasowych | metody 3–5: PASS | dotyczy istniejących autoryzowanych scenariuszy |
| Jawny, audytowany bootstrap, brak self-grantu | metody 6, 9: PASS | nie zastępuje niezależnego testu głębszego replay guarda |
| Historyczny aktywny duplikat | metoda 8: PASS | specyficzny migracyjny guard, nie dowód wszystkich B |
| Upgrade, idempotencja, trwała revokacja | metody 10–11, 14: PASS w legalnych ścieżkach | direct SQL B z metody 11 nierozstrzygnięte |
| Brak eskalacji administratora bez delegate | metoda 12: odmowa usługi i A PASS | głębszy SQL guard B NOT VERIFIED |
| Delegacja, dezaktywacja, reaktywacja i audyt | metody 13–14: PASS | bezpośredni przypadek zawieszonego grantora B NOT VERIFIED |
| Wygaśnięcie uprawnień i zależności | metoda 15: PASS | nieprzeklasyfikowane na podstawie samego signed boundary |
| Podpisane legalne przepływy i odmowy runtime | metoda 16: PASS | znane, odroczone wyjątki 001–006 pozostają |
| ACTIVE supplier product wymaga rewizji początkowej | metoda 16: rzeczywisty deferred `23514`, rollback i brak produktu/revizji: PASS | nie usuwa odroczeń dotyczących innych danych |
| Ostatni administrator | metoda 17: PASS | zachowane wymagane fixture i negatywne operacje |
| Licznik i przypisania SKU, zakres VEL-DEF-007 | oryginalne 13/13 odmów, snapshoty identyczne | rekomendacja CLOSED tylko w opisanym modelu runtime |

## 6. Cztery głębsze gwarancje — osobne ustalenia

### 6.1. Metoda 7 — replay historycznego bootstrapu

**Dokładna gwarancja B:** ponowne użycie historycznego `bootstrap_operation_id` nie może odtworzyć cofniętego prawa; ledger i guard replayu mają odrzucić taki przypadek niezależnie od wcześniejszej bariery unsigned SQL.

**Aktualny dowód:** runtime `veltra_app`, dokładne `42501` i komunikat wymagający podpisanego biletu; stan grantów, ledgeru i audytu identyczny. Historyczna revokacja pozostaje skuteczna. Statycznie istnieją ledger i replay guard migracji 017, zwłaszcza linie 32–64, oraz późniejsze utwardzenia. Nie wykonano dynamicznego przejścia przez tę wewnętrzną odmowę.

**Czy A wystarcza w modelu?** Tak dla atakującego posiadającego wyłącznie rolę runtime bez biletu. Nie jest to dowód B. Zaufany podpisujący proces nie należy do tej samej klasy atakującego.

**Czy osobny dowód B obowiązkowy?** Nie wykazano jednoznacznego wymogu w aktualnej decyzji akceptacyjnej; rozstrzygnięcie właściciela jest wymagane, zanim uznamy kontrakt za kompletny.

**Ryzyko resztkowe:** latentny błąd wewnętrznego guarda mógłby pozostać niewykryty, gdy prawidłowo podpisana lub zaufana ścieżka dostarczyłaby niepoprawne historyczne dane. To scenariusz warunkowy, nie potwierdzony exploit.

**Minimalny dalszy dowód:** albo zatwierdzone mapowanie tego kryterium na wykazaną A w modelu zaufanego podpisującego procesu, albo odrębnie autoryzowany dowód dochodzący do replay guarda, wymagający właściwej odmowy, identycznych snapshotów i dalszej skuteczności revoke. Bez fałszowania biletów, wyłączania signed protection czy zamiany negatywnego runtime na migratora.

### 6.2. Metoda 11 — zagnieżdżony upgrade po cofnięciu źródła

**Dokładna gwarancja B:** wewnętrzna walidacja PostgreSQL nie pozwala stworzyć kolejnego upgrade/grantu opartego na cofniętej, rekurencyjnej authority.

**Aktualny dowód:** legalny łańcuch upgrade’ów, revokacja źródła, brak efektywnych praw, odmowa usługi i wynik PostgreSQL `false` dla istniejącej authority są wykazane. Unsigned INSERT nowej operacji otrzymuje `42501`; późniejsze INSERT-y audytu i grantu nie są osiągane. Migracja 016 statycznie implementuje rekurencyjną ocenę authority.

**Czy A wystarcza w modelu?** Tak dla unsigned runtime forgery; dodatkowo istnieje odrębny dowód propagacji cofnięcia przy odczycie authority. Nadal nie dowodzi odmowy wewnętrznego INSERT guarda.

**Obowiązkowość B:** wymaga aktualnego mapowania kryteriów; nie należy ignorować istniejącego wymogu propagacji, ale nie należy też utożsamiać go automatycznie z koniecznością obejścia signed boundary.

**Ryzyko resztkowe:** możliwy niewykryty błąd walidacji nowej, zaufanie podpisanej mutacji po revokacji. Brak dowodu, że obecne legalne usługi potrafią taki przypadek wywołać.

**Minimalny dalszy dowód:** rozstrzygnięcie, czy wystarczą istniejące A + usługa + funkcja PostgreSQL; jeśli nie, osobno autoryzowana ścieżka wywołująca właściwy guard nowej operacji/grantu po revoke, wraz z dowodem braku operacji, audytu i grantu. Nie przypisywać wyników nieosiągniętym INSERT-om.

### 6.3. Metoda 12 — nielegalny root grant bez authority/audytu

**Dokładna gwarancja B:** root grant bez prawidłowego bootstrapu/upgrade’u, właściwej authority i powiązanego audytu jest odrzucany przez business validator PostgreSQL, nie tylko przez wymóg podpisu.

**Aktualny dowód:** administrator bez prawa delegowania jest odrzucony przez usługę; direct unsigned INSERT otrzymuje `42501`, brak grantu/operacji/efektywnego SupplierManage, snapshoty identyczne. Migracja 015 zawiera dalszą walidację grantu i authority.

**Czy A wystarcza w modelu?** Tak dla ręcznego unsigned SQL posiadacza runtime. Nie stanowi dowodu, że każdy strukturalnie niepoprawny, lecz przepuszczony przez A grant zostałby odrzucony przez B.

**Obowiązkowość B:** nierozstrzygnięta dla aktualnej akceptacji; nie wykazano uniwersalnego wymogu B ani jawnego odstąpienia dla tego kontraktu.

**Ryzyko resztkowe:** ukryty błąd spójności root grantu mógłby ujawnić się w zaufanej ścieżce, której nie obejmuje demonstracja unsigned SQL.

**Minimalny dalszy dowód:** jawne zatwierdzenie, że A wraz z odmową usługi pokrywa mandatory guarantee w modelu; alternatywnie odrębny autoryzowany dowód odmowy właściwego walidatora z niezmienionym stanem grant/operation/audit.

### 6.4. Metoda 13 — zawieszony grantor i nieefektywne źródło delegacji

**Dokładna gwarancja B:** dalszy walidator nie dopuszcza delegowanego grantu od zawieszonego grantora lub z cofniętego/nieefektywnego źródła.

**Aktualny dowód:** dezaktywacja trwale cofa prawa; zdarzenia i audyt istnieją, HTTP przechodzi z dostępu do odmowy, reaktywacje i członkostwa nie przywracają grantów. Unsigned SQL jest odrzucony `42501`, snapshoty identyczne. Migracje 012 i 015 zawierają odpowiednie mechanizmy revokacji i walidacji.

**Czy A wystarcza w modelu?** Tak dla unsigned delegacji runtime; nie jest testem głębszego walidatora grantora.

**Obowiązkowość B:** wymaga odrębnego rozstrzygnięcia aktualnych kryteriów. Zachowane zachowania usług nie mogą być przemianowane na dynamiczny dowód głębszego SQL guarda.

**Ryzyko resztkowe:** ewentualny defekt walidacji tożsamości/źródła w zaufanej mutacji mógłby pozostać niewykryty. Nie potwierdzono możliwości wykorzystania.

**Minimalny dalszy dowód:** jawna decyzja o wystarczalności A i istniejących przepływów w modelu albo odrębnie autoryzowany dowód odmowy właściwego guarda dla zawieszonego grantora, z zachowaniem wszystkich revokacji i niezmienionym audytem.

## 7. VEL-DEF-007 — niezależna ocena zamknięcia

Źródła: pierwotne `stage2c-authorized-evidence/vel-def-007.jsonl`, skrypt `stage2c-vel-def-007.php` (identity: linie 17–22, kontrakt odmowy: linie 86–100), migracja [035](backend/database/migrations/2026_11_06_000035_standardize_business_sku_allocation.php), zwłaszcza linie 107–155 i 236–280.

**Faktyczna rola:** `current_user=session_user=veltra_app`, baza `veltra_testing`; brak wykonania negatywnych prób jako migrator.

**Oczekiwanie oryginalnego skryptu:** dla każdej próby odmowa ze zbioru `{42501, 23514}` oraz identyczne snapshoty. Skrypt nie miał oddzielnego oczekiwanego SQLSTATE dla każdej pozycji. Nie wolno przedstawiać poniższych obserwacji jako 13 ścisłych, indywidualnie zakodowanych oczekiwań.

| # | Operacja z zachowanego skryptu | Zaobserwowany SQLSTATE | Ocena mechanizmu |
|---:|---|---|---|
| 1 | unsigned cofnięcie licznika | 23514 | monotoniczność |
| 2 | unsigned zwiększenie licznika | 42501 | wymagana podpisana mutacja |
| 3 | unsigned zmiana prefiksu licznika | 23514 | niezmienny prefiks |
| 4 | unsigned DELETE licznika | 42501 | ACL runtime |
| 5 | unsigned TRUNCATE licznika | 42501 | ACL runtime |
| 6 | unsigned UPDATE przypisania | 42501 | ACL runtime |
| 7 | unsigned DELETE przypisania | 42501 | ACL runtime |
| 8 | unsigned TRUNCATE przypisań | 42501 | ACL runtime |
| 9 | unsigned INSERT przypisania | 42501 | wymagana podpisana mutacja |
| 10 | podpisane cofnięcie licznika | 23514 | monotoniczność obowiązuje również w podpisanej ścieżce |
| 11 | podpisana zmiana licznika bez przypisania | 23514 | odroczona spójność licznika i przypisań |
| 12 | podpisany UPDATE przypisania | 42501 | ACL runtime; trigger append-only nieosiągnięty |
| 13 | podpisany DELETE przypisania | 42501 | ACL runtime; trigger append-only nieosiągnięty |

„Podpisane” oznacza istniejącą, pierwotnie autoryzowaną próbę z zachowanego skryptu, nie nową próbę lub fałszowanie biletu w tym przeglądzie. Mechanizm przypisano z analizy ACL i migracji w zestawieniu z logiem; nie opiera się na odrębnej asercji nazwy triggera w każdej próbie.

**Snapshoty:** jeden syntetyczny tenant, prefiks RAW, licznik `51`, przypisania `RAW-000050` i `RAW-000051`. Przed i po trzynastu próbach wartości i zapisane przypisania są identyczne. Skrypt porównuje tablice danych, nie generuje osobnych hashy snapshotów; manifest SHA-256 wiąże plik JSONL, a nie bezpośrednio żywą bazę.

**Rekomendacja: CLOSED RECOMMENDED, wyłącznie w zakresie defektu runtime licznika/przypisań SKU na zweryfikowanym schemacie PostgreSQL 17 po migracji 035.** Oryginalne 13/13 wraz z analizą wspólnych mechanizmów jest wystarczające do takiej rekomendacji. Brak indywidualnych ścisłych oczekiwań SQLSTATE jest ograniczeniem jakości kontraktu dowodowego, ale nie unieważnia zaobserwowanych odmów i niezmienności danych.

Ograniczenia:

- dynamicznie zademonstrowano jeden tenant i prefiks RAW, nie całą macierz tenantów/prefiksów;
- ACL dla UPDATE/DELETE przypisań wystarcza wobec runtime, ale nie dowodzi samodzielnego triggera append-only za tą barierą;
- nie obejmuje kompromitacji migratora, właściciela bazy, zaufanego Laravel/RCE ani sekretu podpisującego;
- nie jest atestacją konfiguracji rzeczywistego deploymentu ani pełnym dowodem odporności na wszystkie scenariusze współbieżności;
- nie wykonano nowych prób ani nie zmieniono statusu rejestru. Formalne zamknięcie wymaga decyzji właściciela.

## 8. VEL-DEF-001–006 i nowe ryzyko

**Zachować wszystkie dotychczasowe odroczenia właściciela bez zmian.** Nie znaleziono nowego, konkretnego dowodu materialnego ryzyka, który wymagałby ich ponownego otwarcia.

Istniejąca metoda 16 zawiera znane, jawnie odroczone zachowania mutacji poza zawężoną granicą podpisu. Ich powodzenie nie jest nowo odkrytym skutecznym atakiem i nie wolno go przedstawiać jako nowego zamknięcia tych ryzyk. PASS regresji nie usuwa przyjętych wcześniej ograniczeń.

Cztery NOT VERIFIED są ograniczeniem dowodu/kontraktu, a nie dowodem nowej skutecznej eskalacji. Nie sklasyfikowano ich jako potwierdzonych podatności Broken Access Control ani jako powodów automatycznego reopening odroczeń.

## 9. Ustalenia i blokery — nie potwierdzone exploity

Severity w tabeli oznacza **priorytet luki akceptacyjno-dowodowej**, nie ocenę CVSS ani potwierdzoną dotkliwość exploitu. Confidence dotyczy faktu nieosiągnięcia głębszej odmowy, nie prawdopodobieństwa podatności i nie obowiązkowości dodatkowego testu.

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | 🟠 HIGH | `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php` | 385–451 | Luka dowodowa: B replay bootstrapu NOT VERIFIED; nie potwierdzona podatność | 10/10 |
| 2 | 🟠 HIGH | `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php` | 658–818 | Luka dowodowa: B nowej mutacji po revoke authority NOT VERIFIED | 10/10 |
| 3 | 🟠 HIGH | `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php` | 820–859 | Luka dowodowa: B spreparowanego root grantu NOT VERIFIED | 10/10 |
| 4 | 🟠 HIGH | `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php` | 860–954 | Luka dowodowa: B grantu zawieszonego grantora NOT VERIFIED | 10/10 |

Zakresy wskazują odpowiednie metody/ich istotne części w badanym snapshocie, nie nowe linie produkcyjnego defektu. Signed boundary: migracja 022, linie 277–344. Nie zidentyfikowano potwierdzonej podatności wymagającej autoryzowanej zmiany aplikacji.

**Dokładny wspólny blocker:** brak zatwierdzonego, aktualnego mapowania tych czterech pierwotnych kontraktów na wystarczającą A albo na wymagającą dodatkowego dowodu B. Niekompletność dotyczy podstawy uznania mandatory guarantees za dostatecznie wykazane. Nie ustanawia się samodzielnie nowego wymagania testowego.

## 10. Minimalne dalsze decyzje właściciela

1. **Rozstrzygnąć osobno dla #7, #11, #12 i #13**, czy wykazana A wraz z istniejącymi legalnymi przepływami jest wystarczającym spełnieniem obowiązkowej gwarancji w modelu zaufanego Laravel, a B pozostaje nieobowiązkowym defense-in-depth; czy B jest obowiązkowym elementem dowodu.
2. Jeśli A wystarcza: zapisać uzasadnioną decyzję architektoniczną/akceptację resztkowego ograniczenia i podstawę procesową, zachowując uczciwe NOT VERIFIED dla B. Następnie uzyskać uzupełnioną rekomendację niezależnego przeglądu. Sama decyzja „wszystkie testy PASS” nie zastępuje tego mapowania.
3. Jeśli B jest obowiązkowe: odrębnie autoryzować minimalny projekt dowodu i dopuszczalną ścieżkę wykonania. Do tego czasu nie wykonywać nowych testów ani nie obchodzić signed boundary. Jeśli przy zakazie obejść nie da się zaprojektować właściwego dowodu, zgłosić konflikt projektu zamiast osłabiać zabezpieczenia.
4. **VEL-DEF-007:** zdecydować o formalnym CLOSED w wyżej określonym zakresie lub pozostawić otwarte z podaną dodatkową potrzebą dowodową. Ten raport nie aktualizuje rejestru.
5. Pozostawić VEL-DEF-001–006 i statusy RAW_MATERIALS/VSEF bez zmian.

Niezależna rekomendacja pozostaje **NO-GO do rozstrzygnięcia punktu 1 i udokumentowania odpowiedniej ścieżki z punktu 2 albo 3**. Nie wynika z niej nakaz zmiany produkcyjnego kodu ani automatyczny obowiązek czterech nowych symulacji. Właściciel zachowuje wyłączne uprawnienie do formalnej decyzji Stage 2C.

## 11. Publikacja i granica zakończenia

Raport przeznaczony do istniejącego folderu [RAPORTY_COPILOTA](https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t). Przesłaną kopię należy otworzyć i sprawdzić, w szczególności NO-GO, rozróżnienie A/B, 17/17 i 252/252, ograniczony CLOSED RECOMMENDED dla VEL-DEF-007 oraz niezmienione odroczenia.

Po publikacji zakończyć zadanie. Brak zgody na testy, SQL, kod, rejestr ryzyk, commity, pushe, deployment i aktywację modułów.
