# VELTRA 2.0 — Stage 2A: Końcowy przegląd architektury surowców

**Status:** przegląd po zatwierdzeniu decyzji biznesowych B1–B8
**Rekomendacja:** **ACCEPT — architektura dopuszcza rozpoczęcie ograniczonego Stage 2B po niezależnym przeglądzie i osobnym upoważnieniu implementacji**
**Zakres wykonania:** wyłącznie dokumentacja; bez zmian kodu, migracji, bazy, kontenerów, commitów i wdrożeń

## 1. Podsumowanie

Zaktualizowana [specyfikacja Stage 2A](./VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md) uwzględnia wszystkie zatwierdzone decyzje B1–B8. Przegląd skorygował sprzeczności między niezmiennością rewizji/dokumentów/audytu a wcześniej proponowanym zapisem pól zatwierdzenia, doprecyzował rozdział grantów organizacyjnych od aktywacji modułu na lokalizacji oraz dodał brakujące kontrakty dla kursów walut i snapshotów kosztu produkcji.

Nie znaleziono architektonicznego blokeru dla **Stage 2B w jego wyraźnie ograniczonym zakresie**. Kluczowe warunki bezpieczeństwa są fail-closed: brak jawnego grantu organizacyjnego lub brak gotowości/aktywacji `RAW_MATERIALS` blokuje request; status `NOT_READY` nie może być obchodzony przez rolę ani grant. Stage 2B nie może ustawić modułu `READY` ani udostępnić cen, zakupów, niekompletnego workflow alergenów lub produkcji.

## 2. Potwierdzenie integracji zatwierdzonych decyzji

| Decyzja | Potwierdzenie w specyfikacji | Weryfikacja architektoniczna |
|---|---|---|
| **B1 — osobne uprawnienia cenowe** | `RAW_MATERIALS_REFERENCE_PRICE_VIEW/MANAGE` i `RAW_MATERIALS_PURCHASING_COSTS_VIEW/MANAGE`; katalog nie zwraca żadnej ceny. | Zgodne. Odczyt, filtrowanie, agregacja, cache i eksport muszą wykonywać niezależny check uprawnienia. Istniejące `PURCHASE_PRICES_VIEW` nie jest automatycznie mapowane. |
| **B2 — blokowanie/archiwizacja** | Zachowanie zatwierdzonych receptur i historycznych powiązań; brak nowych użyć; ponowna walidacja operacji w toku; natychmiastowy safety block. | Zgodne. Status master i historyczna rewizja są oddzielne; produkcyjne zużycie oraz blokada wymagają wspólnego punktu serializacji przed commit. |
| **B3 — zgodność alergenowa dostawcy** | Wymagany dokument dostawcy, osoba z `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`, immutable wynik i dokładna rewizja deklaracji. | Zgodne. Brak lub nieaktualność review oznacza `PENDING`; `INCOMPATIBLE`/`PENDING` nie jest dopuszczane jako zatwierdzony produkt. |
| **B4 — koszt produkcji** | Koszt rzeczywiście skonsumowanej partii inventory, jeśli jest dostępny; w przeciwnym razie historyczna cena referencyjna oznaczona jako szacowana. | Zgodne. Snapshot domeny Production zachowuje materiał/rewizję, źródło kosztu, ilość/jednostkę, konwersję, walutę, kurs i wynik raportowy PLN; po zamknięciu jest niezmienny. |
| **B5 — moduł Stage 1E** | Osobny klucz `RAW_MATERIALS`; aktywacja i gotowość per lokalizacja; jeden wspólny katalog organizacji; brak `READY` przed pełnym wdrożeniem i weryfikacją. | Zgodne. Każde żądanie wymaga zarówno gotowości i aktywacji w bieżącym kontekście, jak i granularnego org-scoped permission. |
| **B6 — organizacyjny RBAC** | Jawne granty organizacyjne z kontrolowaną delegacją; sama rola lokalizacyjna nie daje prawa edycji katalogu. | Zgodne. Nie wolno odziedziczyć raw-material permissions przez skrót administratora/managera w istniejącym `BusinessAuthorization`; bootstrap pierwszych grantów nie może być automatyczny ani samonadawany. |
| **B7 — PLN i precyzja** | PLN jako waluta raportowa; zakupy foreign-currency z zapisem kursu; brak przedwczesnego zaokrąglania; prezentacja zwykle do 2 miejsc. | Zgodne. Kwota źródłowa i zapisany kurs są zachowane; `NUMERIC` przechowuje wartości obliczeniowe, a formatowanie do 2 miejsc jest prezentacyjne. |
| **B8 — restore** | Restore wyłącznie jawnie uprawnionym, audytowanym workflow z ponowną walidacją i zatwierdzeniem; zachować kod/dokumenty/historię/audyt. | Zgodne. Przejście `ARCHIVED -> VERIFICATION -> ACTIVE`, nowa rewizja, to samo ID/kod, bez fizycznego usunięcia. |

## 3. Niespójności wykryte i poprawione

1. **B1–B8 nie były jeszcze zintegrowane.** Pierwotna wersja przedstawiała je jako otwarte pytania i uzależniała od nich decyzję o Stage 2B. Zastąpiono pytania wiążącą tabelą B1–B8, opisami kontraktów oraz etapami zależności.
2. **Approval kontra immutable revision.** Pierwotny schemat umieszczał `approved_by/approved_at` w wierszu rewizji opisywanym jako niezmienny. Zastąpiono to tabelą append-only `raw_material_revision_approval_events`; master pointer i approval event zmieniają się w jednej transakcji, a zatwierdzany `content_hash` obejmuje cały snapshot wraz z tabelami podrzędnymi.
3. **Wersja dokumentu kontra mutowalne daty.** Pierwotny `effective_until` w immutable document row wymagał późniejszej aktualizacji. Przeniesiono cykl zatwierdzenia/skuteczności do append-only `raw_material_document_version_events`.
4. **Odwołanie grantu kontra append-only historia.** Pierwotne `revoked_at` w wierszu grantu kolidowało z deklarowaną niezmiennością. Cofnięcie jest teraz osobnym eventem; efektywność grantu jest wyliczana bez UPDATE/DELETE jego rekordu.
5. **Konwersja kontra zaokrąglenie pośrednie.** Usunięto `rounding_scale` z wiersza konwersji, by nie sugerować zaokrąglania każdej konwersji. Ilości i pieniądze zachowują precyzję; rounding pozostaje na jawnej granicy końcowej/prezentacyjnej.
6. **Status kompatybilności kontra historia ocen.** Usunięto niezależny `compatibility_state` i `verified_against_revision_id`, które mogły przeczyć review. Produkt wskazuje immutable review; wynik jest ważny tylko dla dokładnej rewizji surowca i wersji dokumentu.
7. **Moduł kontra pojedyncze permission kotwiczące.** Istniejący `ModuleDefinition` ma jedno permission kotwiczące, podczas gdy Surowce wymaga wielu granularnych uprawnień. Specyfikacja rozdziela anchor modułu `RAW_MATERIALS` od osobnych checks każdego org-scoped grantu.
8. **Tenant/revision FK.** Doprecyzowano złożone FK tak, by approved/pending revision oraz compatibility review musiały wskazywać rewizję tego samego surowca i organizacji. Wskazano potrzebę constraintów `DEFERRABLE` dla cyklicznej relacji master–rewizja.
9. **Pieniężna historia produkcji.** Zdefiniowano kontrakt snapshotu kosztu i jego pola; jego właścicielem jest Production, nie katalog Surowców. Oddzielono actual inventory batch cost od szacowanej historycznej ceny referencyjnej.

## 4. Ocena wymaganych obszarów

### 4.1 Baza, rewizje i integralność

Proponowane UUID, FK złożone z `organization_id`, `ON DELETE RESTRICT`, unikalny kod/licznik, niezmienne rewizje, audyt append-only oraz precyzja `NUMERIC` są spójne z obecnym PostgreSQL i wzorcami instalacyjnymi. Zatwierdzenie musi porównać hash całego payloadu rewizji pod lockiem; zapis children po submit/approve jest niedozwolony. Referencje snapshotów użytych w recepturach i produkcji nigdy nie są kaskadowo usuwane.

Migracje muszą respektować kolejność cyklicznych FK i odwołań dokumentów, definiować funkcje/constrainty przed triggerami oraz testować je na rzeczywistym, odizolowanym PostgreSQL. Brak zgodności tenant/material/revision musi być odrzucany przez bazę, nie tylko walidator HTTP.

### 4.2 Granty organizacyjne i moduł per lokalizacja

Inspekcja [Permission.php](./backend/app/Domain/Authorization/Permission.php), [BusinessAuthorization.php](./backend/app/Domain/Authorization/BusinessAuthorization.php), [migration 000006](./backend/database/migrations/2026_11_02_000006_create_permission_grants.php), [DefinedModuleCatalog.php](./backend/app/Domain/Modules/DefinedModuleCatalog.php), [ModuleDefinition.php](./backend/app/Domain/Modules/ModuleDefinition.php) i [ModuleAvailability.php](./backend/app/Domain/Authorization/ModuleAvailability.php) potwierdza, że obecne granty permission są lokalizacyjne, a moduły mają kodową definicję oraz stan per lokalizacja. Istniejące role i `allows()` nie mogą bezpośrednio pełnić funkcji grantów do centralnego katalogu: administrator/manager ma skróty autoryzacyjne, a `permission_grants` wiąże grant z membership i lokalizacją.

Dlatego nowy, jawny org-scoped grant/policy jest wymaganym elementem 2B, nie kosmetycznym refaktorem. Wymaga osobnego permission delegacji, grantów z terminem i historią cofnięć, ochrony przed samograntem i niedozwoloną subdelegacją, oraz deny-by-default. Sam grant nie omija weryfikacji aktywnego użytkownika, membership/kontekstu ani `RAW_MATERIALS` READY+enabled dla danej lokalizacji. Brak rekordu aktywacji nie oznacza aktywacji; status kuchni nie przenosi się na restaurację.

### 4.3 Alergeny i dostawcy

Ocena zgodności jest świadomą decyzją osoby uprawnionej, nie automatycznym wynikiem z pustego zestawu alergenów. Zdarzenie review musi wskazywać konkretny supplier product, `raw_material_id`, `raw_material_revision_id`, wersję dowodu, aktora, czas i decyzję. Nowa zatwierdzona rewizja deklaracji unieważnia użyteczność starego review bez kasowania jego historii. Document download ponownie autoryzuje użytkownika i organizację.

### 4.4 Koszt historyczny i waluty

Kontrakt B4 zachowuje rozróżnienie rzeczywistego kosztu skonsumowanej inventory batch i kosztu szacowanego z ceny referencyjnej. Utrwalony snapshot wskazuje, z jakiej rewizji, jednostki, konwersji, eventu ceny i eventu kursu pochodzi wynik PLN. Zamkniętych partii nie wolno przeliczać ponownie przy zmianie master, ceny, kursu lub współczynnika. Kwoty dostawcy w walucie źródłowej nie są zastępowane raportową kwotą PLN.

### 4.5 Cykl życia i operacje w toku

`BLOCKED` natychmiast odrzuca nowe użycie. `ARCHIVED` również blokuje nowe użycie, nie przepisując receptur. Każde rozpoczęte, ale niezatwierdzone zużycie ponownie sprawdza aktualny stan tuż przed commitem; szczególnie safety block ma pierwszeństwo. Restore zachowuje ID/kod, tworzy podlegającą ponownej walidacji rewizję i nie przechodzi automatycznie do `ACTIVE`.

### 4.6 Transakcje, concurrency i audyt

Wzorzec blokady organizacji oraz transakcyjnego audytu jest zgodny z [SecurityMutation.php](./backend/app/Domain/Authorization/SecurityMutation.php) i testami concurrency Stage 1E. Operacje katalogu/grantów/restore mają jeden `operation_id`; dane i wymagany audyt commitują atomowo. Importy/parsowanie/eksport nie trzymają locka. Finalizacja produkcyjnego zużycia musi uczestniczyć w tym samym serializującym protokole co blokada surowca.

Ryzyko wydajnościowe: jedna blokada wiersza organizacji może serializować wiele zmian i finalizacji operacyjnych. To jest akceptowalny punkt wyjścia dla poprawności, ale Stage 2B i przyszłe integracje muszą mierzyć czas oczekiwania i nie trzymać locka podczas pracy z plikami/HTTP. Ewentualna optymalizacja nie może osłabić gwarancji block-vs-consume.

### 4.7 API i polski interfejs

Wersjonowane `/api/v1` endpointy, walidacja requestów, pagination `page`/`per_page`, allowlist filtrów/sortowania i stabilne angielskie kody błędów są zgodne z istniejącym stylem API. Każde `message`/`errors` i wszystkie UI copy pozostają po polsku; błędy nie pokazują stack trace, SQL ani swobodnego tekstu upstream. Ceny i dokumenty nie mogą być ujawniane przez list/detail/filters, saved views, idempotency response, eksport lub cache bez osobnego permission. Frontend nie jest granicą autoryzacji.

## 5. Pozostałe ryzyka i zależności (bez zmiany zatwierdzonych B1–B8)

1. **Źródło i effective-time kursu (przed Stage 2C):** B7 ustala PLN, zagraniczne waluty i zapis kursu; nie wskazuje instytucjonalnego źródła ani precyzyjnej reguły daty obowiązywania. Implementacja 2C musi zapisać ten wybór oraz rate/source/effective time, nie może nadpisać historycznych kursów.
2. **Początkowe granty (Stage 2B):** obecna baza nie ma modelu org-scoped grantów surowcowych. Trzeba określić kontrolowaną, audytowaną procedurę nadania pierwszego permission delegacji; seed/migracja nie może automatycznie nadać wszystkim administratorom uprawnień do katalogu/cen ani pozwolić grantorowi nadać sobie prawa.
3. **Katalog gotowości (Stage 2B+):** `RAW_MATERIALS` obecnie nie występuje w `DefinedModuleCatalog`. Dodanie wpisu jest zmianą kodu poza tym zadaniem i musi pozostać `ready=false`; aktywacja/READY wymaga kryteriów pełnego modułu oraz niezależnej akceptacji. Nie uruchamiać teraz żadnej operacji Stage 1E.
4. **Shared lock w Production/Inventory:** kontrakt integracji wymaga wspólnej serializacji, ale moduły Production/Inventory nie należą do obecnego Stage 2A. Ich przyszła implementacja ma wykazać testem współbieżności, że safety block nie może ścigać się z zatwierdzanym zużyciem.
5. **Biblioteki i magazyn plików (Stage 2D/2E):** aktualny [composer.json](./backend/composer.json) nie deklaruje parsera XLSX, engine PDF ani docelowego object store. Dobór zależności, licencje, limity, antymalware, recovery i retencja wymagają przeglądu przed implementacją tych etapów.
6. **Append-only maintenance:** retencja i legal hold nie mogą skutkować automatycznym kasowaniem historii audit/revisions/documents/cost snapshots. Backup musi obejmować spójne dane DB i obiekty storage; polityka operacyjna ma pozostać osobna od codziennego lifecycle.

Powyższe kwestie są wykonawczymi lub przyszłymi integracyjnymi ryzykami. Nie odwracają zatwierdzonych decyzji biznesowych i nie blokują ograniczonego modelowania/implementacji Stage 2B; punkty 1, 4 i 5 są bramkami przed odpowiednimi późniejszymi etapami.

## 6. Granice Stage 2B

Stage 2B obejmuje wyłącznie:

- organizacyjny, wspólny katalog i niezmienny automatyczny kod;
- kategorie maksymalnie trójpoziomowe, aliasy/wykrywanie duplikatów;
- immutable revision, jednostkę bazową i kontrolowane przeliczniki;
- lifecycle, pending/approved revision, approval events, blokadę/archiwizację/restore;
- append-only audit oraz nowe organization-scoped grants i kontrolowane delegowanie;
- backendowe checks: identity/membership + organization grant + `RAW_MATERIALS` readiness/enabled dla aktualnej lokalizacji;
- polskie API i błędy, izolowane testy PostgreSQL, testy constraints oraz wieloprocesową concurrency.

Stage 2B nie obejmuje cen/kosztów dostawców i kursów (2C), kompletnego workflow dokumentów/alergenów/zgodności dostawców (2D), importu/eksportu i bulk (2E), produkcyjnego interfejsu i saved views (2F), snapshotów kosztów tabeli Production, migracji live ani aktywacji `READY`. Jeżeli endpoint approval istnieje w kodzie 2B, moduł nadal musi pozostać `NOT_READY` do ukończenia i weryfikacji wymaganych funkcji całego zakresu; nie wolno aktywować częściowo bez osobnej zaakceptowanej architektury readiness.

Kryteria końcowe 2B: tenant isolation i złożone FK dowiedzione na PostgreSQL; równoległy przydział kodu bez duplikatów; bezpośrednia mutacja approved child/master odrzucona; grant/rola/moduł sprawdzone niezależnie; no self-grant/escalation; brak operacji gdy `NOT_READY`/disabled; restore nie omija reapproval; audit commituje atomowo; polskie komunikaty bez ujawniania danych/cen; brak zmian w istniejących danych lub stanach modułów.

## 7. Werdykt

**ACCEPT — architektura jest spójna z zatwierdzonymi B1–B8 i wystarczająca do rozpoczęcia zakresu Stage 2B opisanego powyżej, po niezależnym przeglądzie dokumentów i uzyskaniu osobnej autoryzacji implementacji.** Przyjęcie architektury nie oznacza wdrożenia, nie zmienia `RAW_MATERIALS` na `READY` i nie autoryzuje Stage 2C–2F.

Przegląd wykonano na podstawie [specyfikacji Stage 2A](./VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md), aktualnych modeli/autoryzacji/migracji Stage 1E oraz dostępnych raportów Stage 1E–1F. Ponieważ zadanie jest dokumentacyjne, nie wykonywano testów aplikacji ani migracji; implementacyjne kryteria walidacji są określone w specyfikacji.
