# VELTRA 2.0 — Stage 3E-B.4

## RAW_MATERIALS — ocena architektury usuwania szkiców i integralności

**Tryb:** analiza tylko do odczytu i projekt architektoniczny

**Data:** 2026-11-09

**Status:** decyzja architektoniczna wymagana; brak implementacji

## 1. Podsumowanie decyzji

Bloker z B.2 został potwierdzony w źródłach migracji. To nie jest brak
pojedynczej trasy API: obecny schemat zabrania usuwania mastera, każdej
rewizji i jej niezmiennych dzieci, a liczne FK `ON DELETE RESTRICT` utrwalają
zależności biznesowe i audytowe. Migracja 024 utwardza tę samą ochronę.

**Kontrolowane usunięcie fizyczne jest technicznie możliwe tylko dla bardzo
wąskiego podzbioru rekordów**, po wprowadzeniu jawnego wyjątku w guardach,
zachowaniu niezależnego dowodu usunięcia i zablokowaniu ponownego użycia
tożsamości. Nadal oznaczałoby to wyjątek od aktualnej zasady, że wszystkie
rewizje są niezmienne i że dane historyczne są zachowywane. Nie można go
bezpiecznie zrealizować przez samo sprawdzenie
`approved_revision_id IS NULL`.

**Rekomendacja:** wariant B — nieodwracalny tombstone przy zachowaniu mastera,
rewizji i ich historii. Usunięty szkic znika z normalnego UX i przepływów
biznesowych, lecz pozostaje w bazie oraz w audycie. To najlepiej zachowuje
fundamentalne niezmienniki, nie jest archiwizacją ani formalnym wycofaniem.
Wymaga jednak jawnej decyzji właściciela o odejściu od wcześniej opisanego
hard-delete. Jeśli właściciel potwierdzi, że twarde usunięcie jest nadrzędnym
wymogiem, przed implementacją wariantu A potrzebny będzie jawny wyjątek
architektoniczny oraz zatwierdzenie jego ograniczeń.

Nie wdrożono żadnej z opcji. `RAW_MATERIALS` pozostaje `NOT_READY`.

## 2. Zakres, źródła i ograniczenia weryfikacji

### Zweryfikowane lokalnie

- Przejrzano migracje 001–044 pod kątem RAW DDL, FK, constraintów, triggerów,
  podpisanych mutacji, importów, dokumentów, cen, SKU, udostępnień i audytu.
  Migracje niezwiązane z danymi RAW nie wprowadzają kolejnych FK do RAW.
- Przejrzano bieżące usługi i polityki RAW, w tym
  `RawMaterialService`, `RawMaterialHistoryService`,
  `RawMaterialCommercialService`, obsługę importów, SKU, dokumentów
  i ocen zgodności.
- Przejrzano lokalne raporty B.1, B.2, B.3, B.3.1, zgodności UX/backendu,
  raporty 1D/1E, ADR-001, architekturę RAW 2A, rejestr zmian decyzji RAW,
  raport SUPER ADMIN oraz lokalny audyt kompatybilności z VSEF.
- Migracja 011 i jej aktualizacja w 024 potwierdzają bezwarunkową odmowę
  fizycznego usunięcia mastera. Migracja 011 odrzuca UPDATE/DELETE wszystkich
  rewizji i DELETE/UPDATE danych aliasów oraz jednostek. B.2 potwierdza
  zablokowanie endpointu do czasu uzgodnienia architektury.

### Źródła zdalne

- Wskazane dokumenty Google o 100 decyzjach oraz specyfikacji UX pokazywały
  tytuły i interfejs Google Docs, ale nie udostępniły tekstu treści w tej
  sesji (błędy zasobów 401; edytor bez odczytywalnego tekstu).
- Raporty B.2 i B.3.1 w Drive otwierały się jako podgląd pliku, ale próby
  pobrania treści przez podgląd kończyły się błędami 400/401. Ich lokalne kopie
  repozytoryjne zostały odczytane. Nie deklaruję weryfikacji zgodności kopii
  lokalnych z aktualnymi wersjami na Drive.
- VSEF nie jest zatwierdzonym standardem architektury: lokalny audyt K17
  traktuje go jako projekt i wyraźnie nie nadaje mu mocy autoryzującej.
  Nie znaleziono lokalnej, normatywnej specyfikacji VSEF do zastosowania
  w tej decyzji.

Lokalny raport zgodności opisuje decyzję UX #19 jako usuwanie wyłącznie
szkicu, który nigdy nie został zatwierdzony i nie ma zależności; wymienia
również „twarde usuwanie”. Ponieważ treść dokumentów źródłowych nie była
dostępna, niniejsza analiza nie dopowiada niezweryfikowanych szczegółów UX.
Nie wykonywano połączenia do działającej bazy ani introspekcji jej aktualnego
stanu. Schemat oceniono na podstawie wersjonowanych migracji.
Nie oceniano zewnętrznych obowiązków prawnych ani polityki retencji poza
udokumentowanymi w repozytorium niezmiennikami.

## 3. Zweryfikowany bloker i aktualne niezmienniki

### Migracja 011 — model podstawowy

1. `raw_materials_preserve_master()` bezwarunkowo odrzuca DELETE mastera.
   Trigger `raw_materials_preserve_master` działa przed UPDATE/DELETE.
   Funkcja zabrania również zmiany tożsamości, organizacji, kodu i autora.
2. `raw_materials_revision_immutable()` zawsze rzuca wyjątek; trigger
   `raw_material_revisions_immutable` odrzuca UPDATE i DELETE każdej rewizji,
   nie tylko zatwierdzonej.
3. `raw_materials_child_immutable()` odrzuca UPDATE/DELETE
   `raw_material_aliases` i `raw_material_revision_units`.
4. `raw_material_revisions.raw_material_id` ma FK do mastera z
   `ON DELETE RESTRICT`. Rewizja wskazuje również kategorię i autora.
5. Master wskazuje zatwierdzoną, oczekującą i roboczą rewizję trzema
   kompozytowymi FK do rewizji; są `DEFERRABLE INITIALLY DEFERRED`.
   Constraint DRAFT wymaga niepustej `working_revision_id`; wymaga też
   `approved_revision_id IS NULL` i `pending_revision_id IS NULL`.
6. `raw_material_revision_approval_events` ma FK do rewizji `RESTRICT` oraz
   trigger append-only. Obejmuje zarówno `APPROVED`, jak i `RETURNED`.
7. `raw_material_audit_events` jest append-only, ale `subject_id` nie ma FK
   do mastera. Jest zatem niezależnym od rekordu materiału źródłem audytu.
   Zawiera jednak dowody zdarzeń i hash’e, a nie pełny snapshot treści rewizji.
8. Trigger `raw_materials_serialize` serializuje mutacje przez blokadę
   organizacji. Nie stanowi samodzielnie dowodu, że każda nowa ścieżka
   zależności blokuje master w wymaganej kolejności.

### Późniejsze migracje i ochrona

- **024:** zastępuje `raw_materials_preserve_master()` utwardzoną wersją
  z `search_path`, nadal bezwarunkowo odrzucającą DELETE.
- **025:** dodaje `transaction_id`, indeks audytu oraz signed-ticket guardy
  dla wąskich operacji biznesowych, w tym odblokowania. Nie ustanawia
  autoryzacji usunięcia szkicu.
- **026:** utrwala kod RAW i monotoniczny licznik kodów; kasowanie licznika
  jest odrzucane.
- **028, 033:** dodają rewizyjne dane alergenów i wariantów przechowywania
  oraz signed-mutation guards. Rewizja i jej children pozostają chronione.
- **029, 031, 044:** dodają niezmienne powiązania wersji dokumentów, wyniki
  skanów i staging uploadów. Zestaw dokumentów rewizji ma FK `RESTRICT`.
- **032:** oceny zgodności dostawcy odwołują się do RAW, rewizji,
  rewizji produktu i snapshotu wersji katalogu przez FK.
- **034, 036:** zapisują historię importów cen/metadata oraz importów
  katalogu. Wiersze importu mogą bezpośrednio wskazywać RAW przez FK
  `RESTRICT`; historia jest append-only.
- **035, 042:** utrwalają assignmenty SKU i rezerwacje. Usuwanie rezerwacji
  jest odrzucane, a assignmentów nie dopuszcza runtime scope. Numer SKU
  nie jest zwalniany po porzuceniu, wygaśnięciu ani skonsumowaniu rezerwacji.
- **041:** rekordy share mają FK do mastera `RESTRICT` i trigger zachowania
  historii, obejmujący również cofnięte udostępnienia.
- **043:** `raw_materials_zz_advance_edit_version` nadaje nową wersję przy
  każdym UPDATE mastera. Odczytany token jest warunkiem optymistycznej
  współbieżności.
- Pozostałe migracje RAW w zakresie 012–044 wzmacniają autoryzację, podpisane
  mutacje, izolację organizacji, PRIMARY CENTRAL KITCHEN i ochronę SUPER ADMIN;
  nie wprowadzają wyjątku dla usuwania draftów.

  ### Powiązane usługi i brakujący endpoint

  - `RawMaterialService::list`, `show`, `editor`, `revisions` i prywatne
    `material` są głównymi odczytami katalogu/edytora; powinny ukrywać
    tombstone w wariancie B.
  - `RawMaterialService::create`, `createImported`, `revise`,
    `reviseAllergenDeclaration`, `reviseStorageVariants`, `submit`,
    `returnForChanges`, `approve`, `block`, `unblock`, `archive`,
    `archiveBulk` i `restore` są mutacjami albo wejściami tworzącymi nową
    historię. Każde musi odmówić działania na tombstone.
  - `RawMaterialHistoryService::history` jest odrębnym, chronionym odczytem
    audytu. Nie może ujawniać payloadu szkicu bez dotychczasowego
    `AUDIT_VIEW` i redakcji.
  - `RawMaterialCommercialService::createProduct`, `reviseProduct`,
    `archiveProduct`, `createOffer`, `createRestatement` i jego prywatny
    `material` obsługują zależności handlowe/cenowe; zapisy muszą być
    serializowane względem tombstone.
  - `RawMaterialCompatibilityAssessmentService` zapisuje oceny zależne od
    produktów i rewizji; sprawdzenie musi być ponawiane przy zapisie.
  - Workflows `RawMaterialCsvImportWorkflow`,
    `RawMaterialCatalogCsvImportWorkflow` i
    `RawMaterialCsvImportPersistenceService` muszą pomijać tombstone w
    wyszukiwaniu oraz odrzucać import próbujący dodać do niego dane.
  - `RawMaterialSkuReservationService` oraz
    `Documents\RawMaterialUploadService`, `Documents\RawMaterialDocumentService`
    i `Documents\RawMaterialDocumentSetService` nie mogą zwalniać assignmentu
    ani usuwać pliku przy tombstone; upload staging pozostaje odrębnym lifecycle.
  - `RawMaterialController` ma obecne trasy lifecycle, rewizji, dokumentów
    i bulk archive, ale nie ma trasy DELETE draftu. Wariant B wymaga nowej
    akcji kontrolera i Request dla kontraktu version/authorization.

  ## 4. Tabele i zależności, które wpływają na usunięcie

| Obszar | Tabele / powiązanie | Wpływ |
|---|---|---|
| Master i rewizje | `raw_materials`, `raw_material_revisions`; FK master↔bieżące rewizje, FK rewizji do mastera | Cykliczne, częściowo odroczone zależności oraz bezwarunkowe triggery blokują obecnie usunięcie. |
| Dane rewizji | `raw_material_aliases`, `raw_material_revision_units`, `raw_material_revision_allergen_attestations`, `raw_material_revision_allergen_declarations`, `raw_material_revision_storage_variants` | Dzieci wskazują rewizję; triggery odrzucają ich zmianę/usunięcie. |
| Zatwierdzanie | `raw_material_revision_approval_events` | FK RESTRICT i append-only; także zwrot z review zostawia trwałe zdarzenie. |
| Dostawcy | `supplier_products`, `supplier_product_revisions` | Bezpośrednie FK do mastera oraz do RAW revision/unit; niezmienna rewizja produktu utrwala snapshot. |
| Ceny i finanse | `raw_material_reference_price_events`, `raw_material_financial_restatements` | Bezpośrednie FK do mastera i poprzednich zdarzeń; historia append-only. |
| Importy | `raw_material_import_metadata_events`, `raw_material_import_rows`, `raw_material_catalog_import_rows` | Wiersze i metadane mogą wskazywać master przez FK RESTRICT; pozostają historią importu. |
| Udostępnienia | `raw_material_location_shares` | FK RESTRICT; cofnięcie share zachowuje historyczny wiersz. |
| Zgodność | `raw_material_supplier_compatibility_assessments` | FK do RAW, rewizji, produktu dostawcy i wersji katalogu. |
| Dokumenty trwałe | `raw_material_document_version_links`, `raw_material_revision_document_sets`, `raw_material_document_lifecycle_events` | Linki i zestawy wskazują rewizję lub dokument; wersje, linki i zdarzenia są chronione. |
| Upload tymczasowy | `raw_material_document_uploads` | Upload staging nie wskazuje mastera. Po CLAIMED wskazuje wersję dokumentu; guard zabrania DELETE, a pliki mają własną retencję. |
| Audyt | `raw_material_audit_events` | `subject_id` bez FK pozwala zachować audyt po hard-delete; hash i metadane nie zachowują pełnej treści rewizji. |
| SKU | `organization_sku_assignments`, `organization_sku_counters`, `raw_material_sku_reservations` | Assignment nie jest zależny FK od mastera, lecz jest trwały i nie może być odzyskany. Rezerwacja i jej wynik są również chronione. |

### Co należy traktować jako zależność blokującą

Przy wariancie A fizyczne usunięcie jest niedopuszczalne, jeśli istnieje
jakikolwiek rekord odnoszący się do mastera lub rewizji, którego usunięcie
naruszyłoby FK albo historię. W szczególności:

- produkt dostawcy, oferta/zdarzenie handlowe, cena referencyjna lub
  restatement;
- wiersz importu cen/katalogu albo metadata event związany z materiałem;
- share aktywny lub cofnięty;
- ocena zgodności dostawcy;
- trwały link dokumentu, zestaw dokumentów rewizji lub inny zapis
  dokumentowy związany z rewizją;
- zdarzenie zatwierdzenia lub zwrotu, ponieważ ma FK do rewizji i jest
  append-only;
- dowolna inna FK z tabeli, która powstanie w przyszłości.

Rekomenduję, aby każda z tych zależności również blokowała operację w
wariancie B, zgodnie z deklarowanym warunkiem UX „bez zależności”, mimo że
tombstone mógłby technicznie zachować referencje. W przeciwnym razie obiekt
zniknąłby użytkownikowi mimo istniejących danych handlowych, importowych lub
udostępnień. Zakres „zależności” musi zatwierdzić właściciel. W szczególności
nie wiadomo, czy sam `RETURNED` lub istniejący staging upload ma blokować
operację.

### Dokumenty i staging

- Nie kasować z storage ani tabel dokumentów w ramach usunięcia szkicu.
- Trwałe dokumenty związane z rewizją są dependency; dla hard-delete należy
  odmówić zamiast obchodzić FK lub kasować dokument.
- Niezwiązane, nieprzypisane uploady stagingowe nie są zależnością RAW:
  pozostawić je w dotychczasowym lifecycle TTL/EXPIRED. Nie usuwać ich
  rekordów ani plików podczas operacji szkicu.
- Upload CLAIMED i dokument z linkiem do rewizji muszą pozostać. Należy
  traktować link jako dependency i nie próbować „sprzątać” go w tle.

## 5. Audyt, historia i SKU

### Czy hard-delete może zachować append-only audit?

Tak, częściowo: nowy event `DRAFT_DELETED` może zostać zapisany w tej samej
transakcji do `raw_material_audit_events`; `subject_id` nie ma FK do mastera,
więc istniejące `CREATED`/inne eventy oraz event usunięcia mogą przetrwać
usunięcie wiersza. Tabela audytu sama jest append-only.

Nie zachowa to jednak pełnej historii treści. Po usunięciu rewizji jej opis,
alergeny, jednostki i metadane znikałyby; audit zawiera hash i wybrane
metadane, nie kopię całej rewizji. Dodatkowo `raw_material_revision_approval_events`
ma FK `RESTRICT`, więc nie wolno usunąć rewizji z eventem `APPROVED` ani
`RETURNED` bez osłabienia append-only / referencyjnej integralności. Nie wolno
usuwać ani przepinać tych eventów.

Wariant A wymagałby zatem jednocześnie:

1. usunięcia tylko rewizji bez jakichkolwiek approval/return events;
2. osobnego eventu audytowego z trwałym `organization_id`, UUID mastera, kodem,
   SKU, rewizją i content hash, aktorem/lokalizacją, wersją oczekiwaną,
   `operation_id` i czasem;
3. trwałego rejestru skasowanych UUID (lub równoważnego constraintu),
   aby nie pozwolić ponownie utworzyć innego RAW pod tym samym ID.

### SKU i tożsamość

Migracje 035 i 042 wykazują, że `organization_sku_assignments` jest odrębnym,
trwałym rejestrem: ma unikalność `(organization_id, sku)` oraz
`(organization_id, entity_type, entity_id)`. Rezerwacje wiążą SKU z ID
materiału i nie dają prawa do recyklingu numeru. Usunięcie mastera nie może
usuwać, zmieniać ani zwalniać assignmentu/licznika/rezerwacji.

W B tombstone zachowuje ID mastera oraz assignment bez dodatkowego ledgeru.
W A UUID mógłby stać się ponownie dostępny po usunięciu globalnego PK; nowy
ledger albo równoważny globalny zakaz ponownego użycia ID jest konieczny.
SKU nadal musi pozostać zajęte w tabeli assignmentów. Nie wolno uznać
usunięcia materiału za cofnięcie alokacji.

## 6. Współbieżność i B.2 expected-version

Każda mutacja musi sprawdzić `If-Match` przed zmianą stanu, w transakcji,
po ponownej autoryzacji i pod blokadami. Istniejący
`RawMaterialVersion::assertCurrent()` określa kontrakt:

- brak `If-Match`: **428**;
- nieprawidłowy UUID: **422**;
- nieaktualny `edit_version`: **409 RAW_MATERIAL_VERSION_CONFLICT**.

Operacja powinna zachować kolejność znaną z RAW: podpisany scope → transakcja
→ blokada organizacji → ponowna autoryzacja → blokada mastera `FOR UPDATE`
→ sprawdzenie wersji i kwalifikacji → zapis audytu i tombstone/usunięcie
→ commit. Nie zwracać aktualnego tokena ani danych materiału w konflikcie.

Przy A FK chronią przed nowymi wpisami referencyjnymi konkurującymi z
usunięciem; nadal wymagane jest pobranie blokad oraz ponowienie kontroli
zależności przed usunięciem. FK bezpośrednie i FK do rewizji muszą być
uwzględnione, bez cascade.

Przy B sam FK nie wystarczy: tombstone nie usuwa referencjonowanego wiersza.
Każda ścieżka tworząca zależność musi blokować organizację i master w tej
samej kolejności, a trigger/guard musi odmówić dodania zależności do
`deleted_at IS NOT NULL`. Dotyczy to również importów, share, cen, produktów,
ocen zgodności i linków dokumentów. Jeśli którakolwiek ścieżka może ominąć
tę blokadę, operacja nie jest gotowa do wdrożenia.

## 7. Porównanie opcji

### Opcja A — kontrolowane usunięcie fizyczne

- **Wykonalność:** możliwe wyłącznie dla wąskiej, zweryfikowanej selekcji:
  nigdy niezatwierdzony materiał w dopuszczonym stanie, bez zależności,
  bez eventu approval/return i z jedną, możliwą do bezpiecznego usunięcia
  bieżącą rewizją. Sam brak approved pointera jest niewystarczający.
- **Zmiany DB:** nowa migracja forward-only musiałaby ograniczyć wyjątek
  w `raw_materials_preserve_master`, `raw_materials_revision_immutable`
  i `raw_materials_child_immutable` do podpisanego scope dedykowanego
  usuwaniu draftów. Należałoby dodać append-only receipt/ledger tożsamości,
  uniknąć ON DELETE CASCADE oraz zachować wszystkie assignmenty SKU.
  Usunąć w transakcji dzieci rewizji, rewizję i master w bezpiecznej
  kolejności; odroczone FK master→working revision muszą być rozliczone
  przed commit.
- **Ryzyko integralności:** najwyższe. Wyjątek narusza obecny invariant
  niezmienności każdej rewizji i trwałości mastera; nowe FK/tabele mogą
  unieważnić listę zależności. Złożony FK `RESTRICT` nie może być obchodzony.
- **Audyt:** niezależny audit event może przetrwać, ale pełna treść wersji
  zostaje utracona. Ledger musi chronić ID i poświadczać usunięcie. Approval
  i return events pozostają nietykalne, więc ich istnienie blokuje operację.
- **SKU:** assignment, licznik i reservation pozostają bez zmian; SKU nigdy
  nie jest ponownie przydzielany. Potrzebny zakaz ponownego użycia UUID.
- **RBAC:** osobne prawo `RAW_MATERIALS_DELETE_DRAFT`, primary central kitchen,
  aktywna organizacja/lokalizacja/membership, bramka modułu i signed scope.
  Bez obejścia przez ADMINISTRATOR, SUPER ADMIN, `ARCHIVE` ani bypass SQL.
  Nie dodawać siódmej roli; delegowalność nowego grantu wymaga decyzji.
- **Kompatybilność rewizji:** niezmienione rewizje zatwierdzone pozostają
  chronione; wszystkie rewizje kandydata, nawet niezatwierdzone, wymagają
  kontrolowanego wyjątku. Rekord z approval/return event nie kwalifikuje się.
- **Efekt dla użytkownika:** zgodny z hard-delete, ale ID/assignment i receipt
  nadal istnieją poza tabelą mastera. Nie oznacza zatarcia audytu.
- **Migracja/testy:** wysoka złożoność; zmiany triggerów, scope, ledgeru,
  kontroli wszystkich FK, testów migracji i współbieżności. Dotychczasowe
  negatywne testy DELETE muszą pozostać dla wszystkich niekwalifikujących się
  przypadków; dopisać pojedynczy wąski sukces oraz rollback.
- **Decyzja właściciela:** wymagana jawna zgoda na wyjątek od append-only
  treści rewizji oraz na utratę jej payloadu; trzeba zatwierdzić dokładną
  definicję kwalifikacji i ledger.

### Opcja B — logiczne usunięcie / tombstone

- **Wykonalność:** wykonalne bez usuwania wierszy i bez zmiany FK. Master,
  rewizje, dzieci, dokumenty, ceny i eventy pozostają w obecnej relacji.
- **Zmiany DB:** forward-only migracja dodaje pola oznaczenia usunięcia
  (`deleted_at`, `deleted_by`, `deleted_operation_id`) oraz zgodność
  null/non-null i FK aktora `(deleted_by, organization_id)`. Trigger mastera
  pozwala tylko na jednorazowe, nieodwracalne ustawienie tombstone po
  walidacji stanu, braku APPROVED i braku zatwierdzonych jako blokujące
  zależności. Nie zmienia lifecycle na ARCHIVED i nie zmienia guardów
  niezmienności rewizji. Audyt `DRAFT_DELETED` zapisuje się atomowo.
- **Ryzyko integralności:** umiarkowane i kontrolowalne, ale wysoki koszt
  kompletności. Wszystkie odczyty i operacje zależne muszą ukrywać tombstone;
  wszystkie ścieżki tworzenia zależności muszą odrzucać go pod wspólną
  blokadą. Sama kolumna nie wystarczy.
- **Audyt:** pełna treść i wszystkie append-only źródła pozostają. Event
  tombstone wiąże operację z aktorem, wersją i powodem/kodem zdarzenia.
  Dostęp do historii nadal musi stosować dotychczasową redakcję i RBAC.
- **SKU:** zachowuje ID, code/SKU, assignment, reservation i licznik;
  nie ma recyklingu ani konieczności nowego globalnego registry ID.
- **RBAC:** osobny grant do nieodwracalnego usunięcia draftu, bez dodawania
  roli. Wymaga PRIMARY CENTRAL KITCHEN, skutecznego membership/grantu,
  autoryzacji po stronie serwisu i DB oraz fail-closed `RAW_MATERIALS`.
  Pierwotna tożsamość SUPER ADMIN nie omija reguł.
- **Kompatybilność rewizji:** wszystkie wersje i obecne FK pozostają
  nienaruszone; B.2 `edit_version` aktualizuje się przy oznaczeniu. B.3
  dokumenty pozostają chronione. B.3.1 archive nadal jest osobnym lifecycle.
- **Efekt dla użytkownika:** z normalnych list, wyszukiwania, eksportu
  i operacji materiał znika; wiersz jest dostępny wyłącznie przez ściśle
  autoryzowany audyt/retencję, nie jest możliwy restore ani ponowne użycie
  tożsamości. To spełnia efekt „usuń z mojego katalogu”, lecz nie semantykę
  fizycznego usunięcia.
- **Migracja/testy:** średnia do wysokiej złożoność aplikacyjna, bez usuwania
  istniejących FK/guardów. Trzeba zmienić filtry wszystkich endpointów,
  eksportów/importów, usług komercyjnych, dokumentów, share i historii oraz
  zablokować konkurencyjne nowe zależności. Zachować obecne testy odmowy SQL
  DELETE i dodać testy tombstone.
- **Decyzja właściciela:** konieczna zgoda na różnicę semantyczną wobec
  wcześniej zatwierdzonego hard-delete oraz definicja zależności/stanu.

### Opcja C — osobny lifecycle „discarded”

- **Wykonalność:** technicznie wykonalne i zachowuje wszystkie wiersze,
  podobnie jak B.
- **Zmiany DB:** rozszerzenie constraintu `lifecycle_state` o np. `DISCARDED`,
  trigger przejścia tylko z uzgodnionego stanu, nowe pola audytu oraz
  zablokowanie wszystkich zwykłych akcji. Również wymaga filtrów i guardów
  zależności jak B.
- **Ryzyko integralności:** historia pozostaje, ale nowy stan przecina się
  z `DRAFT`, `VERIFICATION`, `ARCHIVED`; trzeba rozstrzygnąć jego relację do
  archive/restore i dozwolonych transitionów. Może przypadkowo zostać
  uznany za archiwizację lub formalne wycofanie.
- **Audyt:** dobrze widoczny stan zdarzenia, ale można go równie dobrze
  zapisać jako tombstone event/kolumnę bez dodawania stanu biznesowego.
- **SKU:** assignment pozostaje; nie może być zwolniony ani użyty ponownie.
- **RBAC:** nowa, odrębna akcja/grant nadal potrzebny; nie wolno użyć
  `ARCHIVE` lub formalnego SUPER ADMIN retirement.
- **Kompatybilność rewizji:** treść pozostaje; wszystkie check constraints,
  lifecycle transitiony, UI, raporty i akcje muszą poznać nowy stan.
  `ARCHIVED`/restore i B.3.1 pozostają odrębne.
- **Efekt dla użytkownika:** praktycznie taki sam jak B, gdy stan jest
  domyślnie ukryty; jawna etykieta „odrzucony/porzucony” może różnić się od
  UX „usunięty”.
- **Migracja/testy:** wysoka, bo wymaga zmiany constraintów i wielu
  switch/mapowania lifecycle oraz regresji archiwizacji, restore,
  zatwierdzania, share i eksportu.
- **Decyzja właściciela:** potrzebna zgoda na nowy stan terminalny i jego
  znaczenie. Wobec równoważnego efektu B jest to dodatkowa komplikacja bez
  wykazanego zysku biznesowego.

## 8. Rekomendowany projekt B — konkretne zmiany do zatwierdzenia

Poniższy projekt jest propozycją, nie wdrożeniem.

### Migracja

Nowa migracja forward-only **045** powinna:

1. Dodać do `raw_materials`: `deleted_at timestamptz NULL`,
   `deleted_by uuid NULL`, `deleted_operation_id uuid NULL`; dodać
   constraint wymagający wszystkich trzech wartości NULL albo wszystkich
   ustawionych oraz composite FK `(deleted_by, organization_id)` do `users`.
   Nie zmieniać `lifecycle_state`, approved/pending/working pointerów ani
   istniejących FK.
2. Zastąpić bieżącą wersję `raw_materials_preserve_master()` w sposób
   zachowujący wszystkie istniejące reguły tożsamości i lifecycle; dopuścić
   tylko jednorazowe przejście `deleted_at NULL → NOT NULL` w
   zweryfikowanym signed scope. Nie dopuścić do zmiany/wyzerowania
   tombstone, ponownego użycia rekordu ani modyfikacji approved revisions.
3. Dodać bazodanową kontrolę, że kwalifikacja sprawdza **całą historię**,
   a nie tylko bieżący pointer: brak eventu `APPROVED`, dozwolony lifecycle,
   brak pending akcji oraz brak każdej zależności uznanej za blokującą.
   Wymaga to listy zależności uzgodnionej przez właściciela i wspólnej
   blokady organizacja→master.
4. Rozszerzyć autoryzowaną tabelę grantów o
   `RAW_MATERIALS_DELETE_DRAFT`; aktualizować aktywny permission CHECK
   wyłącznie migracją forward-only. Nie zmieniać liczby ról biznesowych.
5. Rozszerzyć signed mutation scopes/ticket validation tylko dla tabeli
   `raw_materials` i INSERT `raw_material_audit_events` w wąskim
   `raw-material.delete-draft` scope. Nie udzielać runtime DELETE, nie
   zmieniać `raw_materials_append_only()` i nie dodawać bypassu ogólnego.
6. Dodać kontrolę na INSERT do każdej tabeli tworzącej zależność, aby
   blokowała tombstone po lockowaniu mastera; zachować kompozytowe FK,
   tenant scope, `ON DELETE RESTRICT`, podpisy i dotychczasowe
   authorization tickets.
7. Opcjonalnie dodać indeks częściowy dla aktywnego katalogu,
   np. `(organization_id, lifecycle_state, code) WHERE deleted_at IS NULL`,
   po sprawdzeniu planów istniejących zapytań. Nie zmieniać unikalności SKU.

### API i usługa

- Dodać `DELETE /api/v1/raw-materials/{material}/draft`. To jest logical
  delete: serwis ustawia tombstone; nie wykonuje SQL DELETE. Wymaga
  `If-Match` z aktualnym B.2 `edit_version`, primary central kitchen,
  nowego grantu, świeżej autoryzacji i dostępnego modułu.
- W jednej transakcji: signed scope → lock organizacji → ponowna autoryzacja
  → lock mastera → `RawMaterialVersion::assertCurrent` → kompletna walidacja
  stanu i zależności → update tombstone → `DRAFT_DELETED` audit event
  z operation ID i minimalnym snapshotem identyfikacyjnym → commit.
- Sukces zwraca **204**; brak/stary token zachowuje odpowiednio **428/409**,
  błędny format **422**, niedostępny materiał **404**, brak grantu/kontekstu
  **403**, moduł niedostępny pozostaje fail-closed. Konflikt nie ujawnia
  danych ani bieżącej wersji.
- Wszystkie zwykłe odczyty i działania mają filtrować tombstone: `list`,
  `show`, `editor`, completion, revisions, prices, supplier links,
  compatibility, documents, imports, shares, CSV export i mutacje. Poza
  dedykowanym audytem rekord ma zwracać bezpieczne 404. Historia audytowa
  zachowuje dotychczasowe reguły dostępu i redakcji.
- Nie oferować restore/undo. Nie traktować operacji jako `ARCHIVED`,
  formalnego retirement ani fizycznego wymazania danych/plików.

Nazwę endpointu, semantykę 204 i to, czy należy zbierać dodatkowy powód od
użytkownika, trzeba zatwierdzić z właścicielem UX; wskazane dokumenty
zdalne nie pozwoliły tych detali zweryfikować.

## 9. RBAC, izolacja i pozostałe wymagania bezpieczeństwa

Każda opcja musi zachować organizację i lokalizację, primary central kitchen,
aktywne membership, skuteczne granty i istniejące signed mutation boundary.
Zmiana nie może dodawać roli — bieżące sześć ról pozostaje bez zmian — ani
przyznawać uprawnienia na podstawie samego ADMINISTRATOR/SUPER ADMIN.

Jeśli powstanie nowe prawo `RAW_MATERIALS_DELETE_DRAFT`, wymaga jawnego
nadania/delegacji zgodnie z istniejącą administracją grantów. Trzeba
zatwierdzić, czy grant może być delegowany i które role mogą go otrzymać.
`RAW_MATERIALS_ARCHIVE` nie jest właściwym zamiennikiem: archiwizacja jest
odwracalnym lifecycle dla zatwierdzonych/aktywnych materiałów, nie
usunięciem szkicu. Bramka `RAW_MATERIALS` nadal blokuje operację przy
`NOT_READY`; nie wolno zmieniać gotowości w ramach tej pracy.

Pierwotna tożsamość SUPER ADMIN i sześć ról pozostają pod istniejącą
ochroną; żaden wariant nie może omijać ich guardów, chronić przed audytem
lub dawać specjalnego bypassu. VSEF nie jest źródłem upoważnienia ani
zatwierdzonym standardem.

## 10. Wymagane testy przed ewentualną implementacją

### Kwalifikacja / odmowa

- DRAFT bez dependencies: dokładnie jeden autoryzowany tombstone (B) albo
  kontrolowany hard-delete (A) i jeden event audytowy.
- Ever-approved, pointer approved, `VERIFICATION`, niekwalifikujący się
  lifecycle, aktywny/pending workflow, share active/revoked, supplier
  product, reference price/restatement, import row, compatibility
  assessment, dokument/link/set lub inne dependency: odmowa bez częściowych
  zmian.
- DRAFT po `RETURNED`: zgodność z zatwierdzoną definicją; nie kasować
  `raw_material_revision_approval_events`.
- Błędna/nieobecna wersja, brak prawa, read-only share, obca organizacja,
  kontekst nie-primary, nieaktywny membership i `RAW_MATERIALS=NOT_READY`.
- Bezpieczne 404 bez ujawniania istnienia lub szczegółów zależności spoza
  zakresu aktora.

### Historia, dokumenty, SKU i kompatybilność

- Audyt `DRAFT_DELETED`, stare eventy i hash pozostają append-only;
  B zachowuje wszystkie wiersze i payload rewizji.
- `organization_sku_assignments`, liczniki, rezerwacje i status CONSUMED/
  EXPIRED pozostają bez zmian; próba ponownego użycia numeru/ID jest
  odrzucona.
- Dokumenty trwałe/linki i eventy nie są kasowane; upload staging pozostaje
  pod dotychczasową retencją.
- Normalny katalog, eksport, import, editor, ceny, dokumenty, share i
  mutacje nie mogą ujawnić ani ponownie modyfikować tombstone.
- Dotychczasowe testy zakazujące bezpośredniego DELETE mastera, rewizji,
  dzieci, auditów i assignmentów pozostają zielone dla B. Dla A wyjątek
  nie może osłabić negatywnych testów innych kategorii.

### Współbieżność / rollback

- Dwa równoległe delete z tym samym `If-Match`: co najwyżej jeden sukces;
  drugie żądanie konfliktuje albo dostaje bezpieczny stan terminalny bez
  duplikacji audit eventu.
- Delete równolegle z edycją, submit/return/approve, archive/restore,
  tworzeniem produktu/ceny/importu/share i linkowaniem dokumentu.
  Każdy przebieg musi zakończyć się jednym z dwóch spójnych porządków:
  zależność pierwsza i delete odmówiony, albo delete pierwszy i nowa
  zależność odmówiona.
- Błąd po zapisie eventu lub po zmianie tombstone cofa oba zapisy w tej
  samej transakcji; brak osieroconego eventu lub statusu.
- Testy na PostgreSQL z niezależnymi połączeniami; mocki nie dowodzą
  zachowania FK, triggerów ani locków.

## 11. Ryzyka i kompatybilność

- **A:** twarde usunięcie spełnia literalną semantykę, ale usuwa payload
  rewizji i wprowadza wyjątek do najniższej warstwy integralności.
  Każda nowa tabela może stać się pominiętą zależnością; testy katalogu
  dependency muszą być aktualizowane razem z migracjami.
- **B:** zachowuje historię i FK, ale tombstone pozostaje w bazie. Błędny
  filtr może ujawnić rekord; błędna blokada może dopuścić nową zależność
  po „usunięciu”. Trzeba objąć wszystkie odczyty oraz inserty, nie tylko
  ekran katalogu.
- **C:** zachowuje historię, lecz wprowadza kolejny lifecycle terminalny,
  który może kolidować z ARCHIVED/restore i formal retirement.
- W każdej opcji front-end nie jest częścią tego raportu. Nie wolno
  zmieniać migracji ani wdrażać bez akceptacji designu i pokrycia regresją.
- Trzeba rozstrzygnąć, jak backup/retencja, export, audit history oraz
  ewentualny compliance/data-erasure proces odnoszą się do tombstone.

## 12. Decyzje wymagające jawnej zgody właściciela

1. Czy nadrzędne jest zachowanie wcześniej zatwierdzonego hard-delete, czy
   integralność niezmiennej historii (rekomendowane B)?
2. Jeśli B: czy użytkownik akceptuje rekord tombstone w bazie, ukryty
   w normalnym UX i nieodwracalny, zamiast fizycznego usunięcia?
3. Czy kwalifikuje się tylko `DRAFT`, czy także `VERIFICATION`; co z
   wcześniejszym `RETURNED` i wieloma niezatwierdzonymi rewizjami?
4. Które dokładnie zależności blokują: dokumenty/staging, supplier links,
   ceny, importy, compatibility, share (także cofnięty) i historyczne
   eventy? Propozycja raportu: blokują wszystkie wymienione trwałe
   zależności; staging bez związku z rewizją pozostaje poza operacją.
5. Kto może otrzymać osobny grant DELETE_DRAFT, czy grant jest delegowalny
   i czy primary central kitchen jest obowiązkowe? Rekomendacja: osobny
   grant, primary wymagane, bez nowej roli i bez SUPER ADMIN bypassu.
6. Czy operacja jest nieodwracalna i jaki minimalny powód/tekst potwierdzenia
   ma być zapisany? Rekomendacja: brak restore; dokładny UX copy do ustalenia.
7. Czy akceptuje się zakres retencji danych tombstone i rewizji w backupach,
   eksporcie audytu oraz politykach zgodności?

## 13. Potwierdzenie zakresu wykonania

W tej fazie **nie**:

- zmieniano kod aplikacji, testów, dokumentacji produktu ani migracji;
- wykonywano SQL, migracji, operacji destrukcyjnych ani introspekcji
  działającej bazy;
- tworzono zasobów Docker lub uruchamiano testów;
- implementowano API, frontend, tombstone ani hard-delete;
- wykonywano deploymentu, commit/push ani zmiany gotowości modułu.

Utworzono wyłącznie ten raport. `RAW_MATERIALS` pozostaje `NOT_READY`.
Następny krok należy do właściciela produktu/architektury.
