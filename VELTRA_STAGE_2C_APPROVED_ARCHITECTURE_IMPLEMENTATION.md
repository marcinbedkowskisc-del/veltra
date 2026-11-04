# VELTRA 2.0 — Stage 2C: wdrożenie zatwierdzonej architektury autoryzacji

## Status i zakres

Implementacja w bieżącym worktree jest zakończona i zweryfikowana na zasobach
testowych. Nie wykonano commitów, pushy, wdrożeń ani migracji na istniejących
bazach. Nie modyfikowano istniejących kontenerów ani ich wolumenów. Migracja
022 pozostała niezmieniona.

**Stage 2C nie jest zaakceptowany.** Wymagany jest niezależny re-audyt zmian
oraz rozstrzygnięcie ryzyk opisanych poniżej przed wdrożeniem. Nie rozpoczęto
Stage 2D ani VSEF. RAW_MATERIALS pozostaje `NOT_READY` i nieaktywne.

## Decyzje i zmiany

- Laravel pozostaje źródłem decyzji biznesowych. Istniejące serwisy domenowe
  (`RoleAdministration`, `PersonnelAdministration`, `PermissionAdministration`,
  serwisy instalacji i RAW_MATERIALS) nadal wykonują kontrole aktora, zakresu,
  hierarchii, celu i wymaganych potwierdzeń; PostgreSQL nie jest przedstawiany
  jako zamiennik tych kontroli.
- Wprowadzono `AuthorizationMutationScope` jako zamknięty, typowany katalog
  scope’ów. `AuthorizationMutationContext` nie przyjmuje już dowolnego tekstu.
  Akcje API struktury są mapowane na istniejące scope’y instalacyjne.
- Klucz podpisujący jest niezależny od `APP_KEY`, ma format 32 losowych bajtów
  zapisanych jako 64 znaki hex i jest konfigurowany przez
  `APP_DB_MUTATION_SIGNING_KEY`. Migracja 023, jako migracja wyłącznie do
  przodu, ponownie ustawia klucz w bazie oraz pozostawia triggery podpisu tylko
  na wskazanych tabelach RBAC i audytu. Migracja 022 nie została przepisana.
- Dodano polecenie `veltra:rotate-database-mutation-signing-key`. Wymaga
  połączenia migracyjnego, nie wypisuje sekretu i aktualizuje jeden rekord
  klucza transakcyjnie.
- Opisano macierz uprawnień, zagrożenia, przegląd danych historycznych oraz
  rotację w `README.md`.
- Dodano regresje dla zamkniętego katalogu scope’ów, globalnego
  `ADMINISTRATOR`, bezpośrednich prób INSERT/UPDATE/DELETE/TRUNCATE z
  poświadczeniami runtime, triggera z tabeli tymczasowej oraz dozwolonych
  przepływów aplikacyjnych.

### Macierz PostgreSQL

Na świeżej bazie PostgreSQL 17 sprawdzono rzeczywiste ACL przy użyciu roli
runtime. Rola nie jest właścicielem bazy, nie ma `SUPERUSER`, `CREATEDB`,
`CREATEROLE`, członkostwa w rolach, `CREATE` w `public`, dostępu do
`veltra_private` ani prawa `TRUNCATE`. Rola migracyjna również nie jest
superuserem, właścicielem bazy ani członkiem innej roli; ma `CREATE` w bazie
potrzebne do migracji. Nie znaleziono domyślnych ACL przyznających runtime
automatyczne prawa do nowych obiektów.

| Tabele | Uprawnienia runtime | Wymuszanie |
|---|---|---|
| `organizations`, `locations`, `location_management_assignments`, `location_supply_relationships`, `users`, `location_memberships`, `role_assignments`, `security_events`, `membership_requests`, `permission_grants`, `report_shares`, `activation_tokens`, `password_reset_tokens`, `account_email_deliveries`, `location_module_states`, `raw_material_revision_approval_events`, `raw_material_audit_events`, `supplier_product_revisions`, `supplier_offer_events`, `supplier_offer_fx_normalization_events`, `organization_exchange_rate_events`, `raw_material_reference_price_events`, `raw_material_financial_restatements`, `raw_materials_commercial_idempotency`, `raw_materials_commercial_audit_events`, `organization_permission_grants`, `organization_permission_grant_events`, `raw_material_permission_upgrade_operations` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` | Trigger wymaga poprawnego, krótkotrwałego podpisu w zamkniętym scope. Obejmuje także append-only historię. |
| `organization_suppliers`, `supplier_products`, `supplier_offers`, `raw_material_categories`, `raw_material_code_counters`, `raw_materials`, `raw_material_revisions`, `raw_material_revision_units`, `raw_material_aliases` | `SELECT`, `INSERT`, `UPDATE`, `DELETE` | Bez triggera podpisu; obowiązują kontrole domenowe Laravel i ograniczenia danych PostgreSQL. |
| `raw_material_permission_grant_operation_uses`, `raw_materials_currency_codes` | `SELECT` | Brak praw zapisu; pierwsza tabela ma także trigger ochronny. |
| `migrations` | Brak | Dostęp migracyjny wyłącznie rolą migracyjną. |

`TRUNCATE` jest odebrane dla wszystkich tabel. Rola runtime ma prawa do
dozwolonych sekwencji, z wyłączeniem sekwencji tabeli `migrations`.

## Model zagrożeń i gwarancje

| Scenariusz | Gwarancja i ograniczenie |
|---|---|
| Zwykły uwierzytelniony użytkownik wywołuje API | Decyzje podejmują serwisy domenowe Laravel. Testy potwierdzają odmowę nadania globalnego administratora przez lokalnego menedżera oraz poprawne ścieżki administracyjne. |
| Napastnik ma wyłącznie poświadczenia runtime PostgreSQL | Nie może tworzyć obiektów w `public`, użyć `veltra_private`, zmienić triggerów, nadać sobie roli migracyjnej ani pisać do chronionego ledgeru operacji uprawnień. Mutacje wskazanych tabel RBAC/audytu wymagają podpisu. Niechronione tabele z macierzy pozostają bezpośrednio zapisywalne. |
| Przejęty proces Laravel | Nie ma gwarancji ochrony: proces ma klucz podpisujący oraz poświadczenia runtime i może wywoływać dozwolone scope’y. HMAC potwierdza posiadanie sekretu aplikacji, nie biznesowe uprawnienie aktora. |
| Przejęta rola migracyjna lub administracyjna DB | Nie ma gwarancji niezmienności wobec właściciela schematu/bazy. Może on zmienić kod, triggery, klucze i dane. Odporność audytu na administratora wymaga zewnętrznego, kontrolowanego eksportu. |

Ticket wiąże podpis z transakcją, czasem ważności i nazwanym scope’em, ale nie
z konkretnym identyfikatorem wiersza ani payloadem; nie należy traktować go jako
podpisu biznesowego ani zabezpieczenia przed SQL injection wykonywanym w
kontekście aktywnego scope’u. To ograniczenie musi zostać niezależnie ocenione.

## Wyniki weryfikacji

Wszystkie zasoby były osobnymi kontenerami PostgreSQL 17 i Redis, w prywatnej
sieci Dockera, bez mapowania portów, z wyłącznie tymczasowym `tmpfs` jako
magazynem. Żadne istniejące dane ani wolumeny aplikacyjne nie były używane.

| Sprawdzenie | Wynik |
|---|---|
| Świeża instalacja, migracje 001–023 | PASS; powtórzono na czystej bazie z końcowym zakresem tabel. |
| Upgrade z migracji 001–021, z syntetycznym istniejącym rekordem, przez 022–023 | PASS; rekord organizacji zachowany, migracje 022 i 023 zapisane. |
| Przegląd ról, ACL, schematów i domyślnych uprawnień | PASS; zgodnie z powyższą macierzą. |
| Testy ukierunkowane: scope’y, granica runtime, bootstrap/replay/delegacja, role | PASS — 21 testów, 253 asercje. |
| Pełna regresja backendu | PASS — 184 testy, 1585 asercji, 0 pominiętych testów. |
| Rotacja klucza: runtime odmówiono; migracja zaakceptowana; mutacje podpisane po rotacji działają | PASS — odmowa runtime, sukces rolą migracyjną, następnie 1 test / 55 asercji. |
| PHP syntax | PASS dla wszystkich zmienionych plików PHP. |
| Pint | PASS po automatycznym formatowaniu jednego zmienionego testu. |
| `sh -n docker/postgres/10-create-runtime-role.sh` | PASS. |
| `docker compose ... config --quiet` z syntetycznymi wartościami | PASS. |
| `git diff --check` | PASS. |

Test upgrade z istniejącymi danymi użył syntetycznego rekordu organizacji oraz
wartości `REVIEWED:veltra_upgrade022:2026_11_05_000022`. Potwierdza to ścieżkę
migracji i zachowanie wiersza; **nie** dowodzi prawdziwości historii ani tego,
że ktoś faktycznie przeprowadził przegląd. Migracja nadal weryfikuje jedynie
oczekiwany tekst i zapisuje go jako atestację.

## Znalezione i usunięte błędy implementacyjne

- Pierwsza walidacja migracji 023 opierała się na nazwach triggerów, które
  PostgreSQL może skracać do 63 bajtów. Zmieniono wykrywanie na funkcję triggera
  i relację docelową; świeże i upgrade’owe migracje przeszły.
- Przekazanie binarnego klucza `bytea` jako tekstowego parametru powodowało błąd
  kodowania UTF-8. Zapis używa teraz parametryzowanego hex i `decode(..., 'hex')`;
  świeża migracja i rotacja klucza przeszły.
- Zamknięty katalog scope’ów nie obejmował trzech istniejących akcji struktury.
  Dodano mapowanie na scope’y instalacyjne; testy API i pełna regresja przeszły.
- Test boundary oczekiwał odrzucenia autoryzacyjnego dla zwykłej tabeli, a
  otrzymywał błąd constraintu `23502`. Usunięto tę błędną przesłankę z testu
  granicy; dla chronionych mutacji sprawdzane są semantyczne SQLSTATE
  (`42501`, `22023` lub `23514`) **i niezmienione liczniki chronionych tabel**.

## Ryzyka i wymagania przed wdrożeniem

1. **Bezpośredni zapis do 9 tabel bez triggera podpisu** jest dozwolony dla
   poświadczeń runtime. Obejmuje m.in. `raw_materials`, `raw_material_revisions`,
   `supplier_offers` i `organization_suppliers`. Polityki aplikacyjne nie
   chronią tych tabel przed bezpośrednim SQL. To jawne ryzyko modelu, które
   niezależny audyt musi zaakceptować albo wskazać do ograniczenia.
2. **Atestacja danych historycznych nie jest dowodem.** Przed upgrade’em
   produkcyjnej bazy wymagane są migawka tylko do odczytu, zapytania uzgodnienia
   tabel/grantów/ledgerów, zapis rozbieżności i ich rozstrzygnięcia oraz
   zatwierdzenie drugiej osoby poza migrowaną bazą. Rzeczywisty wynik należy
   przechować poza DB; tekst zmiennej środowiskowej jest wyłącznie bramką
   migracji.
3. **Nie potwierdzono historii wdrożeń poza tym hostem.** Wcześniejszy,
   tylko do odczytu przegląd lokalnych baz wykazał maksymalnie migrację 010
   (`veltra`) i 009 (`veltra_testing`); nie dowodzi to stanu innych instalacji.
   Przed wydaniem należy zinwentaryzować wszystkie współdzielone/produkcyjne
   bazy tylko do odczytu. Jeśli 022 była zastosowana, musi pozostać
   niezmieniona, a ochronę zawęża dopiero forward-only migration 023.
4. Klucz należy przechowywać niezależnie od `APP_KEY`. Rotacja wymaga wstrzymania
   backendu i kolejek, ustawienia nowego sekretu we wszystkich procesach,
   wykonania polecenia rolą migracyjną i testu chronionego zapisu przed
   wznowieniem ruchu. Historyczna wersja 022 przejściowo używała `APP_KEY`
   podczas swojej instalacji; 023 zastępuje zapisany klucz niezależnym.
5. Przed akceptacją wymagany jest niezależny re-audyt obejmujący szczególnie
   ambientny ticket podczas walidacji domenowej, możliwość ponownego użycia w
   tej samej transakcji, dokładny zakres 9 tabel bez podpisu i operacyjną
   procedurę przeglądu każdej istniejącej historii.

**Końcowe stwierdzenie:** implementacja i testy w środowisku disposable są
zakończone. Stage 2C pozostaje niezaakceptowany do czasu niezależnego audytu i
rozstrzygnięcia powyższych ryzyk. Raport nie jest zgodą na wdrożenie.
