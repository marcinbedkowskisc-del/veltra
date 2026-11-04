# VELTRA 2.0 — Niezależny audyt bezpieczeństwa Stage 2C (migracja 023)

**Data audytu:** 4 listopada 2026  
**Zakres:** migracja 023, migracje 011 i 022, integracja Laravel, uprawnienia roli runtime PostgreSQL, integralność RBAC i RAW_MATERIALS.  
**Tryb:** audyt wyłącznie na disposable PostgreSQL 17 i Redis oraz inspekcja kodu i raportów. Nie modyfikowano aplikacji, istniejących konfiguracji, baz, kontenerów ani wolumenów.  
**Werdykt: REJECT**

Odrzucenie dotyczy bieżącej implementacji architektury Stage 2C, a nie tylko wdrożenia w istniejącej bazie. Niezależnie od historii danych potwierdzono, że po migracji 023 posiadacz samych poświadczeń runtime PostgreSQL może ominąć walidację zatwierdzenia materiału. Jest to naruszenie deklarowanej ochrony integralności przed atakiem SQL-only. Nie jest to zarzut dotyczący arbitralnego wykonania kodu w procesie Laravel ani przejęcia roli administracyjnej PostgreSQL — te scenariusze są poza zatwierdzonym modelem zagrożeń.

## Podsumowanie ustaleń

| ID | Rodzaj | Ważność | Pewność | Status |
|---|---|---:|---:|---|
| F-1 | Obejście walidacji zatwierdzenia `raw_materials` przez `pg_temp` i niezabezpieczony `search_path` | MEDIUM | 10/10 | Potwierdzone wykonaniem na PostgreSQL 17 z rzeczywistymi poświadczeniami `veltra_app` |
| F-2 | Dziewięć tabel pozostaje bez podpisanego triggera; poprawny bezpośredni DML nie jest ograniczony do autoryzacji biznesowej Laravel | MEDIUM — ekspozycja architektoniczna; dla wielu mutacji brak osobnej reprodukcji | 8/10 | Granty i reguły sprawdzone; nie wykonano pełnej macierzy bezpośrednich prób SQL per tabela |
| R-1 | Migracja 023 oznacza się jako wykonaną mimo braku prywatnej infrastruktury wymaganej przez 022 | LOW / ryzyko wdrożeniowe | 9/10 | Zaobserwowane na niespójnej, disposable bazie; nie jest to ścieżka standardowej migracji sekwencyjnej |
| G-1 | Niewystarczający niezależny dowód dla pełnej macierzy ticket/replay, protected ledgers i dziewięciu tabel | Luka pokrycia audytu | 10/10 | Raport implementacyjny podaje agregaty; nie dowodzi wykonania każdego wymaganego scenariusza |

## F-1 — Obejście walidacji zatwierdzenia materiału przez `pg_temp`

**Pliki i miejsca:** [2026_11_05_000023_narrow_authorization_mutation_boundary.php](/home/veltra/projekty/veltra/backend/database/migrations/2026_11_05_000023_narrow_authorization_mutation_boundary.php:8), [2026_11_02_000011_create_raw_materials_core.php](/home/veltra/projekty/veltra/backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php:303), [2026_11_02_000022_enforce_signed_authorization_mutations.php](/home/veltra/projekty/veltra/backend/database/migrations/2026_11_02_000022_enforce_signed_authorization_mutations.php:243).

Migracja 023 ogranicza listę tabel chronionych triggerem podpisu i usuwa trigger `enforce_authorization_mutation` z `raw_materials`. Funkcja triggera integralności z migracji 011 sprawdza zatwierdzenie przez niekwalifikowane odwołania do tabel i nie ustala bezpiecznego `search_path`. Rola runtime ma uprawnienie `TEMP`, więc może ustawić `search_path` tak, by nazwy w funkcji rozwiązały się do tymczasowych tabel kontrolowanych przez sesję.

**Niezależna reprodukcja:** na disposable PostgreSQL 17.11, z faktyczną rolą `veltra_app`:

1. Potwierdzono, że `raw_materials` nie ma już triggera `enforce_authorization_mutation`, a rola ma `UPDATE` i `TEMP`.
2. Ustawiono `search_path=pg_temp,public` i utworzono tymczasowe tabele o nazwach `raw_material_revision_approval_events` oraz `raw_material_revisions`, zawierające syntetyczne dane zgodne z walidacją triggera.
3. Wykonano przejście syntetycznego materiału `DRAFT → VERIFICATION → ACTIVE`.
4. Operacja zakończyła się powodzeniem, mimo że w `public.raw_material_revision_approval_events` nie istniało odpowiadające zdarzenie zatwierdzenia.
5. Kontrolna próba bez cieniowania tabel tymczasowych została odrzucona błędem `An approved revision requires a matching approval event`.

Nie użyto biletu ani klucza podpisującego. Jest to potwierdzone obejście wyłącznie przy poświadczeniach runtime, więc mieści się w granicy ochrony deklarowanej przez architekturę.

**Wymagana korekta przed akceptacją kodu:** zabezpieczyć funkcje i triggery integralności przed obiektami kontrolowanymi przez sesję, stosując wąski, zaufany `search_path` lub w pełni kwalifikowane nazwy obiektów. Ochrony na `raw_materials` nie należy usuwać, dopóki walidator nie jest odporny na `pg_temp`. Rozważyć odebranie `TEMP` roli runtime, jeśli potwierdzono, że aplikacja go nie potrzebuje.

## F-2 — Ocena dziewięciu tabel bez podpisanych triggerów

Migracja 022 nadaje roli runtime `SELECT, INSERT, UPDATE, DELETE` na niżej wymienionych tabelach i odbiera `TRUNCATE`, `CREATE` w `public` oraz dostęp do schematu prywatnego. Migracja 023 nie odbiera tych grantów. Brak podpisu sam w sobie nie jest tu traktowany jako podatność. Znaczenie ma to, że poprawny bezpośredni DML jest możliwy bez autoryzacji biznesowej Laravel; wpływ zależy od tabeli i jej pozostałych ograniczeń.

| Tabela | Efektywne uprawnienia runtime | Ograniczenia z bazy (wg migracji) | Skutek bezpośredniego DML i status |
|---|---|---|---|
| `organization_suppliers` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | Klucze obce i zgodność organizacji, unikalny kod w organizacji, walidacja kodu/nazw/stanu; triggery chronią usunięcie, niezmienną tożsamość/kod/organizację i cofnięcie archiwizacji. | Możliwy zapis/zmiana nazwy, nazwy prawnej lub zaszyfrowanego identyfikatora podatkowego dostawcy oraz archiwizacja bez sprawdzenia aktora przez Laravel. Usunięcie i zmiana chronionych pól są ograniczone. Bezpośredniego poprawnego DML nie testowano. |
| `supplier_products` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | Złożone FK wiążą dostawcę, surowiec i użytkownika z organizacją; CHECK stanu; triggery chronią tożsamość, usunięcie i cofnięcie archiwizacji. | Możliwe dodanie produktu dla istniejących referencji i archiwizacja bez kontroli aktora. Zmiana referencji/organizacji i usunięcie są ograniczone. Bezpośredniego poprawnego DML nie testowano. |
| `supplier_offers` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | FK do produktu/użytkownika w tej samej organizacji; triggery zabraniają usunięcia, zmiany tożsamości/produktu i cofnięcia wycofania; walidacja INSERT wymaga aktywnego dostawcy/produktu i zatwierdzonej rewizji. | Możliwe utworzenie zgodnej oferty, zmiana pól dozwolonych przez triggery lub jej wycofanie bez autoryzacji biznesowej. Ceny są w osobnej chronionej tabeli `supplier_offer_events`, więc zmiana samej oferty nie dowodzi manipulacji ceną. Brak bezpośredniego DML testu. |
| `raw_material_categories` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | CHECK kodu/nazwy/głębokości, unikalny kod w organizacji, FK; trigger weryfikuje głębokość/rodzica, chroni tożsamość i usuwań; trigger serializuje mutacje. | Możliwa nieautoryzowana aplikacyjnie zmiana nazwy lub utworzenie kategorii; integralność hierarchii i niezmienność są kontrolowane w bazie. Brak testu poprawnego bezpośredniego DML. |
| `raw_material_code_counters` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | PK organizacja/prefix, FK, prefix `SUR`, zakres licznika 0–999999999; brak triggera tej tabeli. | Możliwe przesunięcie lub cofnięcie licznika, co wpływa na numerację; unikalność kodu nadal ogranicza duplikaty. Bezpośredniej manipulacji licznikiem nie testowano. |
| `raw_materials` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | CHECK stanu i flag, FK; triggery chronią usunięcie/tożsamość/kod, przejścia stanów i zgodność zatwierdzenia z eventem oraz serializują mutacje. Podpisany trigger został usunięty w 023. | Możliwa zmiana dozwolonych pól i przejść bez sprawdzenia uprawnienia aktora. Ponadto F-1 potwierdza obejście warunku zatwierdzenia przez `pg_temp`. |
| `raw_material_revisions` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | Unikalność rewizji, FK do surowca/kategorii/twórcy w organizacji i CHECK; UPDATE/DELETE zablokowane; INSERT tylko dla aktualnej rewizji roboczej. | Możliwe dodanie rewizji do otwartej rewizji roboczej bez kontroli aktora. Istniejące rekordy są niezmienne. Test bezpośredniego poprawnego INSERT nie został wykonany. |
| `raw_material_revision_units` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | Unikalność jednostki, FK, dozwolone rodzaje/wymiary i dodatni współczynnik; trigger dopuszcza INSERT tylko w aktualnej rewizji roboczej i blokuje UPDATE/DELETE. | Możliwe dodanie poprawnej jednostki/przelicznika do otwartej rewizji roboczej bez autoryzacji biznesowej. Test bezpośredniego poprawnego INSERT nie został wykonany. |
| `raw_material_aliases` | `SELECT, INSERT, UPDATE, DELETE`; bez `TRUNCATE` | FK rewizja–surowiec–organizacja, niepusty alias; trigger blokuje UPDATE/DELETE i pozwala INSERT tylko do aktualnej rewizji roboczej; unikalność znormalizowanego aliasu w organizacji. | Możliwe wprowadzenie aliasu wpływającego na wyszukiwanie/dopasowanie w otwartej rewizji roboczej bez kontroli aktora. Bezpośredniego poprawnego DML nie testowano. |

**Wniosek:** pozostałe więzy ograniczają integralność strukturalną, ale w większości nie są dowodem uprawnienia użytkownika do działania. Samych brakujących podpisów nie uznaję za dziewięć niezależnych podatności; F-2 jest ekspozycją wspólną, której skutki wymagają dalszych ukierunkowanych prób i oceny zgodności z zaakceptowaną granicą SQL-only. Szczególnie istotne są mutowalne dane dostawców/produktów/ofert, liczniki oraz INSERT-y do otwartych rewizji.

Źródła macierzy: migracje 011 (`raw_materials`, kategorie i rewizje) oraz 013 (dostawcy/produkty/oferty), a granty runtime w migracji 022. Testy aplikacyjne istnieją dla części ograniczeń i przepływów, lecz nie stanowią dowodu, że poprawny bezpośredni DML runtime jest odrzucany. Raport implementacyjny 023 sam wskazuje tę lukę jako ryzyko do rozstrzygnięcia.

## RBAC, tickety i integralność audytu — zakres dowodów

Wcześniejszy niezależny raport dotyczył migracji 022, nie migracji 023. Poniższe rozróżnienie jest istotne: nie przypisuję zbiorczym wynikom regresji znaczenia, którego nie mają.

| Scenariusz | Niezależny dowód dostępny dla tego audytu |
|---|---|
| `pg_temp` / `search_path` przy walidacji zatwierdzenia materiału | Wykonany i skuteczny exploit F-1 z runtime credentials. |
| Podpisany bilet dostępny podczas dodatkowych zapytań lub kontroli Laravel | Nie wykonano osobnej próby wykorzystania okna dostępności biletu. |
| Ponowne użycie biletu w tej samej transakcji | Brak kompletnej, niezależnej macierzy wykonanych prób. |
| Replay między transakcjami, tabelami lub operacjami | Brak kompletnej, niezależnej macierzy replay. |
| Podmiana aktora lub scope’u w bilecie/GUC | Historyczny raport 022 opisywał możliwość, by kod Laravel posiadający klucz podpisał nieautoryzowany scope; nie jest to odrębna próba podmiany biletu w tym audycie. |
| GUC i `search_path` dla walidatora biletu oraz pośrednich wywołań | Nie potwierdzono niezależnym wynikiem wszystkich wariantów. Test F-1 dotyczy triggera zatwierdzenia RAW, a nie pełnej macierzy walidacji biletu. |
| Bezpośrednie fałszowanie wszystkich chronionych ledgerów RBAC/audytu | Raport 022 podał wybrane kontrole ACL i triggerów, ale przyznał brak kompletnej macierzy prób fałszowania eventów i grantów. Dla 023 brak nowej, niezależnej pełnej macierzy. |
| Globalny administrator, granty, upgrade evidence, trwałe cofnięcie i wielopoziomowa delegacja | Raport implementacyjny deklaruje testy zbiorcze; nie wykonano w tym niezależnym przebiegu nowej kompletnej macierzy SQL i API dla tych scenariuszy. |

Brak osobnej reprodukcji nie oznacza potwierdzonej podatności, ale oznacza, że tych gwarancji nie można uznać za niezależnie zweryfikowane.

## Migracje, provisioning i klucze

Raport implementacyjny 023 podaje świeże migracje 001–023 oraz upgrade 001–021 przez 022–023 jako zakończone powodzeniem. Niezależnie odtworzono te ścieżki na disposable PostgreSQL 17.11:

- Fresh migrations 001–023: **PASS, 23 migracje**.
- Upgrade 001–021 → 022 → 023: **PASS** dla syntetycznego rekordu; nie jest to dowód poprawności historii rzeczywistej instalacji.
- Migracja 023 uruchomiona w celowo niespójnej bazie bez `veltra_private.mutation_signing_keys` zakończyła się bez błędu i mogła zostać oznaczona jako wykonana. To zachowanie nie jest ścieżką standardową, ale jest niefail-closed dla brakującej infrastruktury. Zalecana walidacja stanu wejściowego zamiast cichego pominięcia.
- Nie wykazano, że zmieniono historyczne migracje wdrożone w trwałej instalacji; istniejących instalacji nie sprawdzano.
- Raport implementacyjny deklaruje rozdzielenie klucza podpisującego od `APP_KEY` i rotację po zatrzymaniu backendów/workerów. Rotacja nie była niezależnie powtarzana w tym audycie.
- W disposable ustawiono `log_statement=none`; nie badałem konfiguracji logów rzeczywistych instalacji ani wszystkich możliwych śladów wyjątków. Brak wycieku w tych warunkach nie jest dowodem dla produkcji.
- Nie weryfikowano provisioning interruption/retry w pełnej macierzy ani dostępu do realnego sekretu. Nie zmieniano istniejących kontenerów.

### Atestacja danych historycznych

Test upgrade użył syntetycznej wartości atestacji `REVIEWED:veltra_upgrade:2026_11_05_000022`. Potwierdza to zachowanie migracji dla testowej wartości i testowych danych, nie autentyczność ani kompletność danych z instalacji klienta. Rzeczywista historia migracji, rekordy RBAC, ledger i ewentualne niespójności nie mogą być ocenione bez bezpiecznego przeglądu konkretnej instalacji. Ręcznie podany tekst atestacji nie stanowi dowodu, że dane zostały rzetelnie zweryfikowane.

## Testy i ograniczenia

Testy uruchomiono na disposable PostgreSQL 17.11 i Redis 7.4 w prywatnej sieci Docker `internal=true`, bez publikowanych portów i bez wolumenów. Nie używano istniejących kontenerów, baz ani wolumenów. Tymczasowe zasoby testowe zostały usunięte po audycie.

| Sprawdzenie | Wynik niezależnego audytu |
|---|---|
| Migracje 001–023 | PASS — 23 migracje |
| Upgrade 001–021 → 022 → 023 | PASS — syntetyczny istniejący rekord zachowany; atestacja testowa |
| Pełny backend po konfiguracji testowej zgodnej z Compose | PASS — 184 testy, 1585 asercji, 0 niepowodzeń, 0 pominiętych |
| Pełny backend — pierwszy przebieg bez `SANCTUM_STATEFUL_DOMAINS` | FAIL — 56 niepowodzeń, 128 przejść, 1133 asercje; błędy 401 w testach API. Po dodaniu ustawienia zgodnego z Compose przebieg końcowy PASS. |
| `SecurityApiTest` | PASS — 5 testów, 58 asercji |
| PHP syntax / Pint | PASS — 185 plików bez błędów składni; Pint dla 197 plików |
| `sh -n` provisioningu / Compose config | PASS — Compose z syntetycznymi sekretami |
| Rotacja klucza | PASS w warunkach raportu implementacyjnego; zatrzymane backendy/workery. Nie została niezależnie powtórzona w tym przebiegu. |
| Testy bezpośredniego poprawnego DML dla każdej z dziewięciu tabel | Nie wykonano |
| Pełna macierz ticket window / same transaction / replay / actor substitution | Nie wykonano |
| Pełna macierz DML wszystkich ledgerów RBAC/audytu | Nie wykonano |
| `git show --check` | FAIL — dwie końcowe spacje w `VELTRA_STAGE_2C_SECURITY_ARCHITECTURE_DECISION.md` (linie 36 i 46); nie wpływa na wykonanie, ale pozostaje wynik walidacji negatywny. |

Wyniki 184/1585 i 21/253 występowały również w raporcie implementacyjnym. Powyżej podano te wyniki jako wykonane w przebiegu testowym poprzedzającym niezależną reprodukcję, nie jako dowód ukończenia każdej wymaganej próby adversarialnej. W szczególności pełny test aplikacyjny nie obalił exploitu F-1.

Nie udało się wiarygodnie wykonać testu cross-organization: model instalacji wiąże zasoby z pojedynczą organizacją, więc nie tworzono sztucznego wieloorganizacyjnego środowiska. Ograniczenia złożonych FK przejrzano źródłowo; nie należy traktować tego jako wykonanej próby izolacji organizacyjnej.

## Pozostałe ryzyka i granice

**Poza zatwierdzoną gwarancją, zaakceptowane residual risks:**

- Arbitrary code execution w procesie Laravel, który ma dostęp do sekretów i poświadczeń DB, może działać z uprawnieniami tego procesu. Autoryzacja Laravel nie jest niezależną barierą wobec w pełni przejętego procesu.
- Przejęcie roli administracyjnej lub migracyjnej PostgreSQL nie jest powstrzymywane przez triggery w tej samej bazie.

**Niezaakceptowane findings / blokery:**

1. F-1 narusza SQL-only gwarancję integralności zatwierdzeń RAW_MATERIALS.
2. F-2 oraz G-1 pozostawiają niezweryfikowane konsekwencje bezpośredniego DML na dziewięciu tabelach i niepełny dowód dla RBAC/audit ticket scenarios. Przed akceptacją należy ustalić granicę ryzyka dla tych tabel i wykonać testy z runtime credentials; nie należy dodawać ogólnego HMAC bez uzasadnienia.
3. R-1: niespójna migracja bez wymaganej tabeli kluczy jest pomijana zamiast jawnie kończyć się błędem.
4. Wdrożenie na istniejącej bazie wymaga osobnego, kontrolowanego przeglądu rzeczywistych danych historycznych, historii migracji, konfiguracji ról i sekretów. Tego nie można potwierdzić z repozytorium ani syntetycznego upgrade testu.

## Zalecane warunki ponownego audytu

- Naprawić odporność triggera zatwierdzenia na `pg_temp`/`search_path` i zachować fail-closed walidację.
- Dla każdej z dziewięciu tabel wykonać bezpośrednie próby dozwolonego/odrzuconego DML na `veltra_app`, sprawdzając skutki dla organizacji, stanu zatwierdzenia, cen i chronionych ledgerów. Wprowadzać tylko te ograniczenia, których wymagają wykazane gwarancje.
- Niezależnie sprawdzić użycie podpisanego biletu w czasie innych kontroli Laravel, reuse w tej samej i kolejnych transakcjach, aktora/scope, GUC oraz pośrednie wywołania.
- Zweryfikować bezpośrednie próby manipulacji chronionymi ledgerami oraz trwałe cofnięcie i wielopoziomową delegację.
- Zmienić migrację 023 tak, aby stan niezgodny z wymaganym schematem/kluczami powodował jednoznaczne przerwanie migracji.
- Dla istniejącej instalacji przygotować przegląd historycznych rekordów oparty na eksporcie, uzgodnieniu ledgerów i udokumentowanym zatwierdzeniu operatora; tekst atestacji nie może zastępować tych dowodów.

**Konkluzja:** obecna architektura/implementacja Stage 2C otrzymuje **REJECT** ze względu na niezależnie odtworzone obejście SQL-only. Nie jest to wyłącznie warunek wdrożenia na istniejącej bazie. Nawet po usunięciu F-1 wdrożenie istniejącej instalacji pozostaje osobno zablokowane do czasu rzeczywistej inspekcji historii danych. RAW_MATERIALS pozostaje `NOT_READY`; nie rozpoczęto Stage 2D ani nie aktywowano VSEF.
