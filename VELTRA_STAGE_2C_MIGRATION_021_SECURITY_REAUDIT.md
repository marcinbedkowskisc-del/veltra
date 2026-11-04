# VELTRA 2.0 — niezależny re-audyt bezpieczeństwa migracji 021

## Werdykt

# **REJECT**

Migracja 021 zamyka potwierdzone obejście walidacji przez `pg_temp`, synchronizuje backfill ledgeru z zapisami do tabeli grantów i atomizuje zmianę uprawnień provisioningu. Nie zapewnia jednak integralności źródeł dowodowych, na których opiera walidację grantu: rzeczywista rola runtime może dopisać wpis operacji i pasujący event audytowy, a następnie uzyskać trwały grant przez bezpośredni SQL. Ten sam rodzaj bezpośredniego zapisu pozwala nadać dowolnemu użytkownikowi globalną rolę `ADMINISTRATOR`.

W testach rzeczywista rola `veltra_app` utworzyła trwały grant po migracji 021 oraz globalne przypisanie administratora. Ponadto migracja 021 zaakceptowała historię takiego sfałszowanego grantu, gdy była przedstawiona jako historia zastana. Są to potwierdzone **HIGH** w obszarze kontroli autoryzacji PostgreSQL. Stage 2C nie może zostać zaakceptowany na podstawie obecnego zamknięcia migracji 021.

## Zakres i izolacja

Przejrzano poprzedni raport REJECT migracji 020, raport naprawy migracji 021, migracje 017–021, konfigurację ról, skrypt provisioningu i testy RBAC. Osobno wykonano próby adwersarialne na PostgreSQL 17 z rzeczywistymi poświadczeniami `veltra_app`.

Środowisko testowe:

- Docker Client/Server 29.9.2; PostgreSQL 17 i Redis 7.4.
- Nowa sieć Docker `internal=true`; PostgreSQL i Redis działały z katalogami danych wyłącznie w `tmpfs`.
- Kontenery nie miały podłączonych wolumenów; jedyny bind mount PostgreSQL był tylko do odczytu i wskazywał skrypt provisioningu.
- Wykorzystano dedykowane, disposable bazy oraz role; nie łączono się z istniejącymi bazami aplikacji. Istniejące kontenery aplikacji sprawdzono tylko do odczytu i pozostawiono bez zmian.

## Ustalenia

| # | Severity | Confidence | Ustalenie |
|---|---|---:|---|
| 1 | **HIGH** | **10/10** | Rola runtime może sfałszować rekord `raw_material_permission_upgrade_operations` i pasujący event `PERMISSION_UPGRADE` w `raw_material_audit_events`, a następnie wstawić grant, który walidator PostgreSQL akceptuje. Migracja 021 sprawdza wzajemną zgodność tych rekordów, ale nie może odróżnić prawdziwego zatwierdzenia aplikacyjnego od wpisów utworzonych bezpośrednio przez runtime. |
| 2 | **HIGH** | **10/10** | Rola runtime może bezpośrednim `INSERT` dodać globalny `ADMINISTRATOR` do `role_assignments`. Trigger weryfikuje ograniczone przypisania lokalizacyjne, a odroczony constraint gwarantuje zachowanie co najmniej jednego aktywnego administratora; żaden z nich nie wymaga uprawnienia aktora do nadania nowej globalnej roli. |
| 3 | **MEDIUM — zamknięte** | **10/10** | Luka współbieżności backfillu z migracji 017 jest blokowana przez `ACCESS EXCLUSIVE` na `organization_permission_grants` podczas rekonsyliacji w migracji 021. Niezależny test potwierdził oczekiwanie migracji oraz kolejkowanie równoległego dostępu do tabeli. |
| 4 | **MEDIUM — zamknięte** | **10/10** | Okno nadawania runtime DML do ledgeru podczas provisioningu jest objęte jedną transakcją. Powtórne uruchomienie i przerwanie skryptu w trakcie tej transakcji nie pozostawiły runtime praw zapisu do ledgeru. |
| 5 | **MEDIUM — częściowo zamknięte** | **10/10** | Migracja 021 atomowo odrzuca osierocone wpisy ledgeru, duplikaty oraz granty bez zgodnego zaakceptowanego śladu audytowego. Nie weryfikuje autentyczności śladu: sfałszowane przez runtime, ale strukturalnie spójne rekordy przeszły migrację i pozostały aktywne. Szczegóły ujęto w ustaleniu 1. |

### Ustalenie 1 — fałszowanie autoryzacyjnego śladu audytowego

**Dowody z kodu:** skrypt provisioningu nadaje runtime `SELECT`, `INSERT`, `UPDATE` i `DELETE` na wszystkich tabelach `public`, w tym na tabeli zdarzeń audytowych i tabeli operacji upgrade’u. Wyzwalacz append-only na tabeli operacji zabrania `UPDATE`/`DELETE`, ale nie ogranicza `INSERT`. Migracja 021 ocenia zgodność pól grantu z rekordami operacji i audytu; nie sprawdza, czy te rekordy powstały w autoryzowanej ścieżce.

**Reprodukcja po migracji 021:** na disposable PostgreSQL utworzono prawidłową organizację, aktywnego użytkownika-administratora, odbiorcę, aktywne członkostwa oraz rzeczywisty grant bazowy `RAW_MATERIALS_PERMISSION_DELEGATE`. Następnie, łącząc się wyłącznie jako `veltra_app`, wykonano transakcyjnie:

1. `INSERT` do `raw_material_permission_upgrade_operations` z istniejącym, aktywnym grantem authority i wskazaną nową permission;
2. `INSERT` do `raw_material_audit_events` z `event_code='PERMISSION_UPGRADE'`, `outcome='ACCEPTED'` i metadanymi zgodnymi z operacją;
3. bezpośredni `INSERT` do `public.organization_permission_grants` z tym samym identyfikatorem operacji.

Wszystkie trzy inserty zakończyły się powodzeniem; po `COMMIT` każdy z rekordów miał licznik `1`, w tym chroniony grant i wpis ledgeru. Nie wywołano usługi upgradu ani endpointu aplikacyjnego i nie wykonano ponownego uwierzytelnienia hasłem. Warunek wymaganego przez bazę efektywnego authority został spełniony istniejącym grantem użytkownika; fałszywy był sam event i deklaracja operacji.

**Reprodukcja przy migracji:** tę bazę sklonowano do disposable schematu na poziomie migracji 020 z tym samym grantem i sfałszowanymi rekordami. Migracja 021 zakończyła się powodzeniem; po zastosowaniu nadal istniał grant, event oraz wpis operacji. Kontrola wykrywa niespójność składniową/relacyjną, nie dowodzi autentyczności audytu.

**Konsekwencja:** tabela audytowa i rejestr operacji są dowodami autoryzacji dla wyzwalacza, ale runtime może sam tworzyć takie dowody. Append-only chroni historię przed późniejszą zmianą, nie przed dopisaniem fałszywego wpisu.

### Ustalenie 2 — bezpośrednie nadanie globalnej roli administratora

**Dowody z kodu:** [migracja 003](./backend/database/migrations/2026_11_02_000003_create_business_role_scopes.php) definiuje `validate_role_assignment()`, który sprawdza aktywne członkostwo i typ lokalizacji wyłącznie wtedy, gdy `location_id` nie jest `NULL`. Nie weryfikuje uprawnienia do nadania roli `ADMINISTRATOR`. [migracja 004](./backend/database/migrations/2026_11_02_000004_protect_administrators_and_security_history.php) dodaje `require_active_administrator()`, który zapobiega usunięciu ostatniego aktywnego, zweryfikowanego administratora, ale nie ogranicza dodawania kolejnych administratorów.

**Reprodukcja:** po migracji 021 rola runtime wykonała bezpośredni `INSERT` do `public.role_assignments` z `role='ADMINISTRATOR'`, `location_id=NULL`, `membership_id=NULL`, istniejącym użytkownikiem organizacji i istniejącym `granted_by`. PostgreSQL zaakceptował rekord, a zapytanie potwierdziło aktywne przypisanie. Skuteczny runtime privilege `INSERT` na tej tabeli wynosił `true`.

**Konsekwencja:** pominięcie warstwy aplikacyjnej pozwala bezpośrednio nadać globalne uprawnienia administratora; ograniczenie do zachowania co najmniej jednego administratora nie jest kontrolą autoryzacji operacji.

## Weryfikacja czterech wcześniejszych ustaleń migracji 020 REJECT

| Wcześniejsze ustalenie | Niezależny wynik |
|---|---|
| **HIGH — `pg_temp` przesłania walidatory grantów** | **Naprawione dla badanego wektora.** Test regresyjny z rzeczywistym runtime na bazie migracji 020 nie przeszedł, ponieważ SQL zaakceptował trwały grant (`$accepted=true`). Po zastosowaniu 021 ten sam test przeszedł: 1 test, 6 asercji; insert został odrzucony SQLSTATE `23514`, bez grantu, ledgeru i audytu w `public`. |
| **MEDIUM — wyścig backfillu ledgeru w migracji 017** | **Naprawione w migracji 021.** Niezależnie potwierdzono blokadę tabeli i brak okna na współbieżny zapis. Szczegóły poniżej. |
| **MEDIUM — nieatomowy provisioning uprawnień ledgeru** | **Naprawione w skrypcie provisioningu.** Powtórny i przerwany przebieg nie przywrócił praw DML runtime do ledgeru. |
| **MEDIUM — nieuzgodnione historyczne wpisy z migracji 020** | **Fail-closed dla niespójnych danych, nie dla sfabrykowanego, spójnego śladu.** Osierocony ledger, duplikat i grant bez zaakceptowanego audytu zatrzymywały migrację; syntetyczny, lecz zgodny relacyjnie ślad utworzony przez runtime przeszedł. |

## Dokładne testy wykonane niezależnie

### Migracje i integralność

| Test | Wynik |
|---|---|
| Świeże migracje 001–021 | **PASS** — zastosowano 21 migracji na pustej bazie disposable. |
| Upgrade 001–020 → 021 | **PASS technicznie** — migracja 021 została zastosowana na osobnej disposable bazie z migracjami 001–020; rejestr migracji wskazywał 021. |
| Osierocony ledger | **Oczekiwane odrzucenie** SQLSTATE `23514`; rejestr pozostał na 020, a osierocony wpis zachował się (licznik `1`). |
| Duplikat historycznego grantu | **Oczekiwane odrzucenie** SQLSTATE `23514`; rejestr pozostał na 020, obydwa rekordy nadal istniały (licznik `2`). |
| Grant bez zgodnego audytu | **Oczekiwane odrzucenie** SQLSTATE `23514`; rejestr pozostał na 020, grant i odpowiadający mu wpis ledgeru pozostały nietknięte (liczniki `1`, `1`). |
| Brakujący wpis ledgeru dla poprawnego grantu | **PASS** — migracja 021 zastosowała się, a brakujący wpis został uzupełniony (licznik po migracji `1`). |
| Sfałszowany, ale strukturalnie zgodny grant/upgrade/audyt | **FAIL bezpieczeństwa** — migracja 021 zastosowała się i zachowała grant, audyt oraz operację (każdy licznik `1`). |
| Atomowość odrzuconych migracji | **PASS dla testowanych niespójnych fixture’ów** — po każdym odrzuceniu migracja 021 nie była zarejestrowana, dane kontrolne pozostały niezmienione. |

### Próby SQL na rzeczywistej roli runtime

| Próba | Wynik |
|---|---|
| Przesłonięcie `organization_permission_grants` i `raw_material_audit_events` przez `pg_temp`, następnie insert do `public.organization_permission_grants`, przed migracją 021 | **Odtworzono podatność.** Test regresyjny nie przeszedł na asercji oczekującej odmowy; zmienna `$accepted` miała wartość `true`. |
| Ta sama próba po migracji 021, z manipulowanym `search_path` | **PASS odmowy** — 1 test, 6 asercji, SQLSTATE `23514`; brak trwałego wpisu w tabelach `public`. |
| Bezpośrednie nadanie grantu bez rekordu operacji/audytu po 021 | **PASS odmowy** SQLSTATE `23514`; licznik badanego grantu pozostał `0`. |
| Fałszowanie `raw_material_permission_upgrade_operations` + `raw_material_audit_events` + grantu po 021 | **Odtworzono obejście HIGH.** Rola `veltra_app` dopisała wszystkie rekordy, a grant został zaakceptowany i zarejestrowany w ledgerze. |
| Bezpośrednie `INSERT` do globalnej roli administratora po 021 | **Odtworzono obejście HIGH.** Rola `veltra_app` utworzyła aktywne przypisanie `ADMINISTRATOR`. |
| Wywołanie funkcji `SECURITY DEFINER` bezpośrednio i przez trigger tabeli tymczasowej | **PASS odmowy** — 1 test, 5 asercji, rzeczywiste poświadczenia runtime. |

### Współbieżność i provisioning

- **Backfill/migracja:** na disposable bazie transakcja trzymała `ROW EXCLUSIVE` na `organization_permission_grants`. Migracja 021 oczekiwała na `ACCESS EXCLUSIVE`; równoległe zapytanie runtime do tej tabeli również oczekiwało w kolejce blokad. Po zwolnieniu transakcji migracja zakończyła się, a brakujący wpis ledgeru pojawił się. Potwierdzono `ACCESS EXCLUSIVE` jako nieprzyznaną blokadę migracji w trakcie oczekiwania oraz przyznaną blokadę writer’a.
- **Ponowne provisionowanie:** skrypt uruchomiono ponownie na bazie disposable po migracjach. Skuteczne `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE` runtime do ledgeru pozostały `false`.
- **Przerwane provisionowanie:** wyłącznie w disposable środowisku testowym użyto kopii skryptu z opóźnieniem przed poleceniem `REVOKE`, ale wewnątrz tego samego `BEGIN`/`COMMIT`. Zakończono połączenie PostgreSQL w stanie `idle in transaction`, po nadaniu w transakcji praw do zwykłych tabel. Zmiany ACL z transakcji wycofały się; runtime nadal nie miał żadnego z czterech praw DML do ledgeru. Oryginalny skrypt źródłowy nie był zmieniany.

### Testy aplikacyjne i kontrola konfiguracji

| Test | Wynik |
|---|---|
| `RawMaterialPermissionBootstrapTest` | **14 zaliczonych, 1 pominięty, 133 asercje** na bazie disposable po 021. Obejmuje bootstrap, upgrade/idempotencję, cofanie uprawnień, delegację wielopoziomową i walidację PostgreSQL. |
| Pełna regresja backendu | **180 zaliczonych, 1 pominięty, 1487 asercji** na disposable PostgreSQL/Redis po 021. |
| Test przesłaniania `pg_temp` z runtime po 021 | **1 zaliczony, 6 asercji.** |
| Test wywołania funkcji `SECURITY DEFINER` z runtime po 021 | **1 zaliczony, 5 asercji.** |
| Konfiguracja roli migracyjnej | Test skupiony potwierdził brak `SUPERUSER`, `CREATEDB`, `CREATEROLE` i własności bazy. |
| `SECURITY DEFINER` i ACL | W katalogu PostgreSQL znaleziono jedną taką funkcję: `public.raw_materials_reject_bootstrap_grant_replay()`, właściciel `veltra_migrator`, `search_path=pg_catalog, public, pg_temp`, ACL tylko `veltra_migrator=X`. Runtime nie może wykonać funkcji bezpośrednio ani podpiąć jej do tabeli tymczasowej. |
| PHP lint, Pint, `sh -n`, `docker compose config --quiet`, `git diff --check` | **PASS.** |

## Test pominięty — interpretacja

Jedyny pominięty test to `test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` w [teście RBAC](./backend/tests/Feature/RawMaterialPermissionBootstrapTest.php#L211). Test celowo pomija się, gdy połączenie Laravel nie używa nazwy roli `APP_DB_USERNAME`; standardowe uruchomienie zestawu korzysta z osobnej roli migracyjnej. To uzasadnione ograniczenie konfiguracji głównej suity, ale nie może być uznane za pokrycie runtime.

W tym audycie uruchomiono test jawnie z `DB_USERNAME=veltra_app`: przed 021 ujawnił zaakceptowany grant, po 021 przeszedł z odmową. Osobno uruchomiono test wywołania funkcji `SECURITY DEFINER` z runtime. Pozostaje jednak brak regresji dla fałszowania eventu/operacji i bezpośredniego nadania globalnej roli — obydwa wektory nie są objęte istniejącymi asercjami i zostały odtworzone w tym audycie.

## Inne walidatory i `SECURITY DEFINER`

- Kontrole `search_path` w migracji 021 dla `raw_materials_serialize_grant()`, `raw_materials_permission_upgrade_authority_is_effective(uuid, uuid)`, `raw_materials_revoke_grants_on_suspension()` i replay guardu skutecznie rozdzielają `pg_catalog`, `public` i `pg_temp`. Konkretny wektor przesłaniania walidatora został odrzucony po 021.
- Nie wykazano drugiej funkcji `SECURITY DEFINER` w badanym schemacie. Jedyna funkcja ma przypisanego właściciela migracyjnego, stały `search_path` i brak `EXECUTE` dla runtime/`PUBLIC`; próby bezpośredniego oraz pośredniego wywołania przez runtime zostały odrzucone.
- Pozostają analogiczne problemy granicy zaufania poza samym `search_path`: walidator grantu ufa wpisom, które runtime może sam dopisać do audytu i rejestru upgrade’u; walidator ról nie autoryzuje bezpośredniego dodania globalnego administratora. Są to niezależne ścieżki, których sama migracja 021 nie naprawia.

## Blokery i pozostałe ryzyka

1. **Bloker wdrożenia:** runtime może sfabrykować poświadczenia autoryzacyjne i utworzyć grant bez zatwierdzonej operacji aplikacyjnej. Migracja 021 uznaje je również za wiarygodną historię.
2. **Bloker wdrożenia:** runtime może nadać globalną rolę administratora bez przejścia przez operację autoryzacyjną.
3. Migracja 021 jest forward-only. Niezgodne historyczne dane zatrzymają upgrade i wymagają ręcznego przeglądu, ale poprawnie sfałszowane rekordy nie są rozpoznawane jako takie.
4. Przejście testów aplikacyjnych nie wykrywa dwóch odtworzonych wektorów runtime; konieczne są osobne regresje SQL z rzeczywistymi ograniczonymi poświadczeniami.

## Ograniczenia i zakończenie

Testy i fixture’y modyfikowały wyłącznie nazwane, disposable bazy PostgreSQL w sieci wewnętrznej. Nie zmieniano kodu aplikacji ani istniejącej konfiguracji, istniejących kontenerów, baz i wolumenów. Nie wykonano live migracji, wdrożenia, commitów, pushy, resetów ani rebase’ów. RAW_MATERIALS pozostaje NOT_READY i nieaktywne. Nie rozpoczęto Stage 2D ani nie aktywowano VSEF.

**Decyzja końcowa: REJECT.** Migracja 021 poprawia trzy z czterech wcześniejszych ustaleń, ale nie zamyka autoryzacji opartej na dowodach zapisywalnych przez runtime, a bezpośrednie nadanie globalnej roli administratora jest odrębnym, potwierdzonym HIGH. Wymagana jest osobna naprawa i niezależny re-audyt przed akceptacją.
