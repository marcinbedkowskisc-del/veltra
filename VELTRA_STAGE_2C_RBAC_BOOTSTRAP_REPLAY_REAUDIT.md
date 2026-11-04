# VELTRA 2.0 — niezależny re-audyt RBAC bootstrap replay Stage 2C

**Data:** 3 listopada 2026  
**Zakres:** niezależna weryfikacja poprawki z migracji 017, prób replay direct-SQL, integralności rejestru użyć, kompatybilności upgrade’u 001–016 oraz regresji RBAC  
**Werdykt: REJECT**

## Podsumowanie

Migracja 017 blokuje zwykły ponowny `INSERT` grantu z tym samym `(organization_id, bootstrap_operation_id, permission)`. Próby z tym samym permission, innym permissionem nieobecnym w autoryzowanym evencie oraz zmienionym odbiorcą zostały odrzucone przez PostgreSQL. Rejestr odrzuca UPDATE i DELETE. Poprawnie działa też legalna operacja obejmująca kilka permissionów: bootstrap i jawny upgrade zapisują wiele grantów z jednym operation ID, po jednym wpisie rejestru na permission. Rekurencyjne cofanie multi-hop oraz wspierana idempotentna ścieżka upgrade’u nadal przechodzą testy.

Nie można jednak zaakceptować poprawki:

1. Rejestr nie jest chroniony przed `TRUNCATE`. W Compose konto używane przez backend jest tym samym `POSTGRES_USER`, którego używa kontener PostgreSQL. W niezależnym disposable PostgreSQL rola utworzona tym mechanizmem miała `rolsuper = true`. `TRUNCATE raw_material_permission_grant_operation_uses`, a następnie replay historycznego grantu, został zaakceptowany; odbiorca ponownie uzyskał skuteczne uprawnienie bez nowego audit eventu.
2. Backfill migracji 017 nie neutralizuje replayów, które skutecznie odtworzyły uprawnienie przed wdrożeniem migracji. W syntetycznej bazie 001–016 bezpośredni replay po revocation został zaakceptowany. Po migracji 017 pozostał drugi, aktywny grant; wpis backfillu nie cofnął ani nie oznaczył odziedziczonego grantu.

Oba wyniki naruszają wymagania trwałego revocation, ochrony direct-SQL i zgodności historii audytowej. Wymagają remediacji przed akceptacją. Nie zmieniano kodu aplikacji, migracji, baz ani konfiguracji.

## Ustalenia

| # | Ważność | Ustalenie | Dowód | Pewność |
|---|---|---|---|---:|
| 1 | 🔴 HIGH | **Rejestr można wyczyścić przez TRUNCATE, po czym replay odtwarza cofnięte prawo.** | Po `TRUNCATE` w disposable PostgreSQL ponowny grant z tym samym operation ID został zaakceptowany. Liczba grantów wzrosła z 1 do 2, revocation pierwotnego grantu pozostał, liczba audit events nie wzrosła, a `hasEffectiveGrant()` zwróciło `true`. Compose mapuje `DB_USERNAME` backendu na `POSTGRES_USER`; sprawdzona rola disposable miała `rolsuper=true`. Migracja 017 nie tworzy ochrony `BEFORE TRUNCATE`. | 10/10 |
| 2 | 🟠 MEDIUM | **Migracja 017 nie neutralizuje replayów zrealizowanych przed jej zastosowaniem.** | Na schemacie 001–016 replay po revocation został zaakceptowany. Przed replay: 1 grant, 1 revocation, 2 audit events. Po replay: 2 granty, 1 revocation, nadal 2 audit events, prawo skuteczne. Po migracji 017 oba granty zachowano, 1 nadal był aktywny, a ledger zawierał jeden wpis dla pary operation/permission. | 10/10 |

### Ustalenie 1 — obejście rejestru przez TRUNCATE

Migracja 017:

- backfilluje `raw_material_permission_grant_operation_uses`;
- ustanawia unikalność `(organization_id, bootstrap_operation_id, permission)`;
- dodaje append-only trigger `BEFORE UPDATE OR DELETE`;
- przed INSERT grantu próbuje dodać wpis do rejestru.

Nie ma jednak statement-level triggera `BEFORE TRUNCATE` ani restrykcji uprawnień, które uniemożliwiałyby roli aplikacji wyczyszczenie rejestru. W Compose:

- backend używa `DB_USERNAME: ${POSTGRES_USER:-veltra}`;
- PostgreSQL inicjalizuje `POSTGRES_USER` tą samą wartością.

W disposable środowisku testowym potwierdzono `rolsuper=true` dla analogicznie skonfigurowanej roli. Bezpośrednia sekwencja `TRUNCATE raw_material_permission_grant_operation_uses` + `INSERT` z historycznym bootstrap operation ID zakończyła się powodzeniem. Stan po próbie: dwa granty dla tej samej operacji i permissionu, stary grant nadal ma event `REVOKED`, nowy jest skuteczny, a liczba audit events pozostała niezmieniona.

Append-only trigger skutecznie odrzucił UPDATE i DELETE (`SQLSTATE 23514`), ale nie obejmuje TRUNCATE. Przy roli superuser sama ochrona triggerami nie stanowi granicy bezpieczeństwa: taka rola może również zmieniać ustawienia triggerów lub schemat. Jest to realne weryfikowalne ograniczenie obecnego mapowania roli aplikacyjnej; test nie zmieniał konfiguracji żadnej istniejącej instancji.

### Ustalenie 2 — aktywne granty odziedziczone sprzed migracji 017

Backfill agreguje istniejące granty po organizacji, operation ID i permission oraz tworzy jeden wpis rejestru. Nie wykrywa duplikatów, nie sprawdza czy wśród nich znajduje się wcześniejszy grant cofnięty i późniejszy replay, ani nie neutralizuje aktywnego duplikatu.

Niezależnie odtworzono sekwencję na disposable schemacie 001–016: utworzono legalny bootstrap z dwoma permissionami, cofnięto `RAW_MATERIALS_VIEW`, po czym bezpośredni SQL użył historycznego operation ID do utworzenia kolejnego grantu tego permissionu. Przed migracją 017 nowy grant stał się skuteczny bez nowego eventu audytowego. Po zastosowaniu migracji 017 zapytanie wykazało dwa wiersze `RAW_MATERIALS_VIEW`, z których jeden nie miał eventu `REVOKED`, oraz jeden wpis rejestru dla tej operacji. Migration nie cofnęła istniejącego stanu.

Wykryte historyczne aktywne duplikaty należy traktować jako potencjalne przywrócone uprawnienia, a nie tylko jako nieszkodliwą duplikację ledgeru. W tym audycie nie odczytywano żadnej istniejącej bazy klienta; nie można więc stwierdzić, czy takie rekordy występują w aktualnych instalacjach.

## Ocena semantyki operation ID

Operation ID nie powinno być globalnie jednorazowe dla jednego permissionu, ponieważ legalne operacje grupowe obejmują listę permissionów i tworzą dla każdego osobny grant:

- `RawMaterialPermissionBootstrap::grantInitial()` emituje jeden `PERMISSION_BOOTSTRAP` event z listą permissionów, a następnie tworzy osobny grant dla każdego;
- `RawMaterialPermissionUpgrade::grant()` analogicznie wiąże wiele grantów z jednym `PERMISSION_UPGRADE` operation ID;
- niezależne testy bootstrapu i upgrade’u potwierdziły poprawny przypadek wielu permissionów z jedną operacją.

Właściwą semantyką jest więc **najwyżej jedno użycie pary `(organization_id, operation_id, permission)`**, a nie jedno użycie operation ID na całą organizację. Nowy permission, którego nie ma w historycznym evencie, został odrzucony (`SQLSTATE 23514`); użycie innego odbiorcy także zostało odrzucone (`SQLSTATE 23514`). Scoping ledgeru po organizacji chroni kontekst tenantowy; nie testowano wielu tenantów, ponieważ zatwierdzona architektura przewiduje jedną organizację na instalację.

## Pozostałe kontrole

| Kontrola | Wynik audytu |
|---|---|
| Zwykły replay tej samej organizacji, operation ID i permission po 017 | **Odrzucony** — `SQLSTATE 23514`, grant pozostaje cofnięty, skuteczność `false`, audit count bez zmian. |
| Ten sam operation ID i permission spoza historycznej listy | **Odrzucony** — `SQLSTATE 23514`; nieudany zapis ledgeru został wycofany razem z transakcją. |
| Zmieniony odbiorca w kontekście historycznego operation ID | **Odrzucony** — `SQLSTATE 23514`. |
| Zmiana / usunięcie wpisu rejestru | **Odrzucone** — `SQLSTATE 23514`. |
| TRUNCATE rejestru | **Zaakceptowany**; umożliwił replay i ponowne uzyskanie skutecznego prawa. |
| Recursywne multi-hop revocation po 016 | Testy focused przechodzą; próba SQL oparta na cofniętym upstream authority jest odrzucana. |
| Automatyczne przywrócenie po reaktywacji / retry | Nie stwierdzono w testowanej ścieżce; ponowienie istniejącego upgrade’u pozostaje idempotentne. Nie niweluje to ustaleń 1–2. |
| Audit event po replay | Brak nowego eventu w obu reprodukcjach replay; liczba eventów pozostała 2. |
| Konkurencyjny insert tej samej pary w ledgerze | Jeden proces wstawił wiersz; konkurencyjna transakcja czekała na unikalnym indeksie i wstawiła 0 wierszy. Potwierdza to serializację unikalnego klucza, ale nie chroni przed TRUNCATE. |

## Testy wykonane niezależnie w tym re-audycie

Testy uruchomiono na disposable PostgreSQL 17 i Redis 7.4 w sieci Docker `internal=true`. Oba kontenery miały wyłącznie `tmpfs`, bez trwałych mountów i opublikowanych portów. Backend uruchomiono z tymczasowej kopii. Nie używano ani nie modyfikowano istniejących baz, wolumenów lub kontenerów aplikacyjnych.

| Test | Wynik niezależny |
|---|---:|
| Świeże migracje 001–017 | **PASS** |
| Upgrade bazy 001–016 z dwoma istniejącymi grantami i eventem bootstrap | **PASS dla wykonania migracji** — zachowano 2/2 granty i 1 event; backfill utworzył 2/2 wpisy ledger. Nie dowodzi to bezpieczeństwa wcześniej odtworzonych aktywnych duplikatów. |
| Powtórne `migrate` po migracji 017 | **PASS** — `Nothing to migrate` |
| Legacy replay po revocation na 001–016 | **EXPLOIT POTWIERDZONY** — prawo skuteczne bez nowego audit eventu. |
| Stan legacy replay po zastosowaniu 017 | **FINDING POTWIERDZONY** — 2 granty, 1 aktywny, 1 wpis ledger. |
| Replay na 017, bez TRUNCATE | **Odrzucony** (`23514`), prawo pozostało nieskuteczne. |
| Replay po `TRUNCATE` ledgeru | **EXPLOIT POTWIERDZONY** — 2 granty, oryginalny revoke bez zmian, audit count bez wzrostu, prawo skuteczne. |
| Zmiana odbiorcy oraz nieautoryzowany permission | **Odrzucone** (`23514`); nieudane operacje nie pozostawiły wpisów ledger. |
| UPDATE / DELETE rejestru | **Odrzucone** (`23514`). |
| Wyścig dwóch procesów o ten sam unikalny wpis ledger | **PASS** — jeden insert, drugi `0 rows`. |
| Skupione testy `RawMaterialPermissionBootstrapTest`, `RawMaterialConcurrencyTest`, `PermissionDelegationTest` | **14 testów, 146 asercji — PASS** |
| Pełna regresja backendu | **175 testów, 1453 asercje — PASS** |
| `git diff --check` | **PASS** |

Pozytywne wyniki testów aplikacyjnych nie obalają reprodukcji direct-SQL. Testy PASS potwierdzają zachowanie przewidzianych ścieżek aplikacyjnych; oba replay exploity zostały osobno odtworzone na PostgreSQL.

## Wyniki historyczne — oddzielone

Raport implementacyjny `VELTRA_STAGE_2C_RBAC_BOOTSTRAP_REPLAY_FIX_REPORT.md` deklarował te same liczby dla focused tests i full backend regression oraz upgrade 001–016 → 017. Nie traktuję tych wcześniejszych wyników jako dowodu w niniejszym audycie. Powyższe liczby i próby direct-SQL zostały ponownie wykonane niezależnie na disposable zasobach.

## Wniosek

**REJECT — migracja 017 nie zamyka ryzyka bootstrap replay.** Zwykłe duplikowanie tej samej pary jest blokowane, a model wielouprawnieniowej operacji jest poprawnie zachowany. Jednak rejestr można opróżnić w skonfigurowanym modelu uprawnień bazy, a wcześniej odtworzone aktywne granty pozostają skuteczne po backfillu.

Przed ponowną akceptacją konieczna jest niezależnie zweryfikowana poprawka obejmująca co najmniej:

1. ograniczenie uprawnień runtime DB do najmniejszych wymaganych praw, bez superuser/owner możliwości manipulowania tabelą rejestru; migracje powinny działać oddzielną uprzywilejowaną rolą;
2. audyt i deterministyczne, audytowalne unieważnienie lub ręczne rozstrzygnięcie wcześniej zdublowanych grantów dla tych samych organization/operation/permission, z zachowaniem immutability historii;
3. bezpośrednie testy PostgreSQL dla `TRUNCATE`, legacy duplicate backfill, rollback i konkurencyjnych replayów.

Nie wykonano poprawek, commitów, pushy, deploymentu ani migracji live. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Stage 2D nie został rozpoczęty.
