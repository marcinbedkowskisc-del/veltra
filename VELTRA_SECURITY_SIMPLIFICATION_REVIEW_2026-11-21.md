# VELTRA 2.0 — przegląd proporcjonalności i uproszczenia bezpieczeństwa

**Data:** 2026-11-21  
**Typ:** niezależna, read-only ocena bezpieczeństwa i złożoności  
**Zakres recenzji:** kod checkoutu PA-07B.3B na SHA `d0d22e65df49fc4e8744ca626ac363126447e36d`

## Podsumowanie wykonawcze — Polish

**Ogólny werdykt:** mechanizmy autoryzacji i integralności są zasadniczo rozleglejsze niż potrzeby typowego systemu biznesowego tego typu, ale nie należą do nich automatycznie mechanizmy, które należy usunąć. Najbardziej nieproporcjonalnym elementem jest ogólna warstwa podpisanych mutacji PostgreSQL: blokuje ona wybrane tabele i wymusza złożony ticket HMAC pochodzący z procesu Laravel. Mechanizm ten nie chroni przed kompromitacją tego samego procesu; przy SQL-only ogranicza przypadki wykonywania mutacji bez ticketu, ale udoskonalenie go lub usunięcie wymaga osobnej decyzji granicy zaufania.

Katalog BOSS `BOSS_RAW_CATALOG_READ` jest obecnie kodowo i organizacyjnie niedostępny: definicja ma `ready: false`, a przepływ autoryzacyjny ponadto wymaga braku/zawężenia stanu aktywacji. `RAW_MATERIALS` pozostaje `NOT_READY` i nie zrealizowano ani nie proponuje się jego aktywacji w tej recenzji.

Blokada `FOR SHARE` organizacji podczas odczytu jest realną, spójną granicą przeciw jednoczesnym zmianom BOSS role, członkostwa, capability i ograniczeń. Nie jest to czysta blokada odczytu dla samego katalogu, lecz blokada serializacji całej transakcji. Prosty ponowny odczyt bez blokady byłby zwykle wystarczający dla mniej zaawansowanych cech dostępności; byłby natomiast słabszy od gwarancji linearizowalnego wyniku względem jednoczesnego cofnięcia uprawnień. Nie rekomenduje się jej usunięcia bez dowodów dotyczących rzeczywistego wyniku biznesowego i bez utrzymania odrębnej granicy praw.

Największa szansa na proporcjonalne uproszczenie bez osłabienia bezpieczeństwa to:

1. zawężenie lub usunięcie ogólnego HMAC/trigger boundary, pozostawiając integralność PostgreSQL i autoryzację Laravel;
2. usunięcie nadmiarowych powtórzeń odczytu tych samych tożsamości, członkostw i capability na poziomie jednego żądania, przy zachowaniu jednoznacznej i wielowarstwowej odmowy;
3. zachowanie prostego mechanizmu walidacji zatwierdzenia, który nie przechodzi na pełne ponowne liczenie hash przy każdym odczycie.

## 1. Dokładna baza Git

| Element | Fakt |
|---|---|
| Checkout główny | `/home/veltra/projekty/veltra` |
| Gałąź główna | `main`, `origin/main` |
| HEAD głównego checkoutu | `0c099bec0f30d3f160827a9eb4ce55a4cf979af5` |
| Status głównego checkoutu | czysty dla plików śledzonych; status z ostatniej nie-artefaktowej inspekcji: `## main...origin/main`, brak zmian śledzonych |
| Checkout PA-07B.3A | `/home/veltra/projekty/veltra-pa-07b3a`, branch `pa-07b3a-readiness-contract`, HEAD `b47085e17810fea5d659b3245d04c542608f07d6` |
| Checkout PA-07B.3B | `/home/veltra/projekty/veltra-pa-07b3b`, branch `pa-07b3b-boss-raw-catalog`, HEAD `d0d22e65df49fc4e8744ca626ac363126447e36d` |
| `git diff --quiet` na checkoutcie PA-07B.3B | brak różnic w plikach śledzonych |

**CONFIRMED FACT:** PA-07B.3A commit `b47085e17810fea5d659b3245d04c542608f07d6` i PA-07B.3B commit `d0d22e65df49fc4e8744ca626ac363126447e36d` istnieją w historii repozytorium i są HEAD odpowiadających osobnych checkoutów.

**CONFIRMED FACT:** główny `main` nie ma obu commitów jako przodków. Sprawdzenie `merge-base --is-ancestor` dla SHA PA-07B.3A i PA-07B.3B wobec `main` zwróciło `1`. SHA PA-07B.3B występuje w repozytorium głównym jako obiekt, ale nie jako commit osiągalny z `main`.

**CONFIRMED FACT:** recenzowany checkout PA-07B.3B ma HEAD dokładnie równy d0d22e65df49fc4e8744ca626ac363126447e36d i brak niepochodzących od niego zmian w plikach śledzonych. Ostateczny raport źródłowy PA-07B.3B odnotował, że z raportem nie powiązano deploymentu ani activation.

**ARCHITECTURAL CONCERN:** główny branch może zawierać późniejsze/młodsze od podstawy zmiany niezależne od PA-07B.3A/3B. Nie można traktować go jako weryfikacyjnego worktree dla d0; oba drzewo i PA checkouty należy porównywać explicite.

**NOWE/MŁODSZE ZMIANY:** na branchu d0 nie znaleziono potomnych commitów w osobnym worktree. Główny `main` ma HEAD `0c099be`, ale jest rozłączny z PA-07B.3B i nie jest recenzowanym implementacyjnym drzewem. Nie przeprowadzano automatycznej bazy testów ani Docker.

## 2. Zakres i metodologia

Inspekcja była statyczna i read-only:

- sprawdzono status, HEAD, logi i relację commitów;
- przeanalizowano przepływ BOSS catalog read, middleware, RBAC, capability i lokacje;
- przeanalizowano migracje PostgreSQL, role, trigger podpisu, append-only i granice danych RAW;
- przeanalizowano aprobację rewizji, eventy i hash;
- przejrzano testy współbieżności i historyczne raporty;
- nie uruchomiono testów, migracji, skryptów mutujących, Docker, baz danych ani komend zmieniających repozytorium.

Klasyfikacja dowodu:

- **CONFIRMED FACT** — bezpośrednio potwierdzony kodem, migracją lub dokładnym stanem Git;
- **ARCHITECTURAL CONCERN** — wynikający z projektowania, ale bez udowodnionego incydentu;
- **HYPOTHESIS** — możliwy scenariusz, niepotwierdzony dowodem wykonania;
- **NOT VERIFIED** — wymagający bezpiecznego eksperymentu lub pomiaru.

## 3. Stan mechanizmów

### Kody capability i RAW

`BOSS_RAW_CATALOG_READ` jest zdefiniowany jako organizacyjny i niegotowy: [CodeOrganizationCapabilityCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php:9), [CodeOrganizationCapabilityCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php:13), [CodeOrganizationCapabilityCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php:26). Dostęp musi spełnić jednocześnie ready, enabled, operation i type: [OrganizationCapabilityAccess.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/OrganizationCapabilityAccess.php:12).

`RAW_MATERIALS` jest produkcyjnie `NOT_READY` w katalogu: [DefinedModuleCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/DefinedModuleCatalog.php:60), [DefinedModuleCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/DefinedModuleCatalog.php:80).

## 4. Ustalenia

### F-01 — ogólna warstwa HMAC dla mutacji PostgreSQL

1. **Finding ID:** F-01
2. **Affected component:** `AuthorizationMutationContext`, migracje 022/023/024 i trigger `veltra_private.enforce_authorization_mutation`.
3. **Exact file path and locations:**
   - [AuthorizationMutationContext.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/AuthorizationMutationContext.php:35)
   - [2026_11_05_000022_enforce_signed_authorization_mutations.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:277)
   - [2026_11_05_000023_narrow_authorization_mutation_boundary.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000023_narrow_authorization_mutation_boundary.php:14)
4. **Current mechanism:** runtime Laravel tworzy HMAC ticket (payload GUC + signature) z 30-sekundowym czasem, scope tekstowym i transakcją. Trigger podpisuje DML na szerokiej liście chronionych tabel. Migracja 023 zastępuje początkowy klucz pochodzący z migracji 022 niezależnym, dedykowanym kluczem konfigurowanym.
5. **Threat addressed:** przypadkowe lub celowe DML z poświadczeniami runtime do chronionych tabel bez ticketu signed.
6. **Realistic likelihood and impact:** niskie do średnie dla zwykłego błędu SQL lub ograniczonego SQL injection; wysokie skutki przy omijaniu RBAC, ale HMAC nie chroni przed kompromitacją procesu, który może wygenerować ticket. 
7. **Complexity, performance and maintenance:** wysoka złożoność scope’ów, rotacji, transakcji, odtwarzania, wyjątków commit/rollback oraz szerokiego zestawu triggerów. Koszt utrzymaniowy jest obecnie większy niż pojedyncza warstwa Laravel.
8. **Evidence:** ticket jest tworzony i podpisany przez ten sam proces, który ma konfigurację klucza i poświadczenia DB. Trigger weryfikuje wyłącznie payload/signature/nonce/txid/scope, bez odrębnego komponentu autoryzującego.
9. **Simpler alternative:** zachować Laravel jako granicę decyzji, najmniejsze granty runtime i wybrane twarde constrainty/trigger niezmienników. HMAC zachować tylko dla jednej lub kilku operacji high-risk, z dokładnym payloadem i jednoznacznym niepowtarzalnym tokenem, albo usunąć po potwierdzeniu braku SQL-only threat.
10. **Preserved guarantees:** least privilege i integralność app–DB dla niektórych mutacji; append-only; atomowe zapisanie danych i zdarzeń.
11. **Reduced guarantees:** nie dotyczy: usunięcie mechanizmu eliminowałoby świadectwo przeciw ticketless DML. Zachowanie tylko wybranych mechanizmów zmniejszałoby scope w sposób proporcjonalny.
12. **Residual risk:** SQL-only przez poświadczenia runtime może ominąć Laravel; wciąż istnieje kompromitacja Laravel/RCE i kompromitacja migratora/superusera; HMAC nie daje pełnej autentyczności.
13. **Recommendation:** **SIMPLIFY**; nie usuwać bez osobnej decyzji granicy zaufania i testów SQL-only.
14. **Priority:** HIGH.
15. **Contract amendment:** tak, jeśli zakres gwarancji zostanie zmieniony. Potrzebna amendacja accepted Stage 2C boundary/architecture contract.
16. **Confidence:** wysoka, że mechanizm jest obecny i ogólny; średnia, że każdy scope jest rzeczywiście potrzebny.

### F-02 — blokada organizacji `FOR SHARE` w odczycie BOSS

1. **Finding ID:** F-02
2. **Affected component:** `BossRawMaterialCatalogService::withAuthorizedRead`.
3. **Exact file path and locations:** [BossRawMaterialCatalogService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php:119), [BossRawMaterialCatalogService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php:226).
4. **Current mechanism:** cały odczyt działa w `DB::transaction`, rozpoczyna od `Organization::sharedLock()`/`FOR SHARE`, następnie ponownie weryfikuje użytkownika, członkostwo, BOSS role, capability i ograniczenia, a potem wykonuje list/detail.
5. **Threat addressed:** BOSS read może zobaczyć wynik z stanu przed jednoczesnym cofnięciem role/członkostwa/capability/restrykcji i zakończyć po zmianie; blokada serializuje z mutacjami biorącymi `FOR UPDATE` na organizacji.
6. **Realistic likelihood and impact:** niski dla pojedynczego operatora, średni wpływ dostępności przy równoległych zmianach; utrata blokady może prowadzić do krótkiego, nieważnego lub niezgodnego odczytu po revokacji.
7. **Complexity, performance and maintenance:** średnia złożoność i potencjalne blokowanie organizacji na czas query count, projection i processing. Wstrzymuje `FOR UPDATE` na organizacji, ale nie blokuje ordinary reads; ogranicza concurrency mutacji tej organizacji.
8. **Evidence:** w testach concurrency writer i reader są wzajemnie serializowane w obu kolejnościach dla role, członkostwa, restriction i capability: [BossRawMaterialCatalogConcurrencyTest.php](/home/veltra/projekty/veltra-pa-07b3b/backend/tests/Feature/BossRawMaterialCatalogConcurrencyTest.php:45).
9. **Simpler alternative:** normalny odczyt biorący listę snapshotów bez sharedLock, jeżeli dopuszczalne jest „usuń lub bądź najnowszym stanem po commitcie”; albo wąska lock only for role/membership/capability state, pozostawiając catalog query poza lockiem. To nie zachowa pełnej linearyzowalności.
10. **Preserved guarantees:** KEEP zachowuje linearizowalność względem mutacji, spójny stan tożsamości i polityki w całej odpowiedzi, fail-closed i brak stanu częściowo odczytanego.
11. **Reduced guarantees:** usunięcie blokady: możliwy stale read jeszcze po cofnięciu; możliwa odpowiedź rozpoczęta przed zmianą i zakończona po commitcie; brak gwarancji serializacji writer–reader.
12. **Residual risk:** przy zachowaniu sharedLock blokada nie chroni przed nieautoryzowanym SQL poza lockiem ani przed dłuższym zaleganiem transakcji. NOT VERIFIED: wpływ na latencję i throughput.
13. **Recommendation:** **KEEP** dla zatwierdzonych readów o wysokiej wiarygodności; dalsze zmniejszenie dopiero po pomiarze i decyzji o dopuszczalnym stale window.
14. **Priority:** MEDIUM.
15. **Contract amendment:** nie, jeżeli zachować obecny kontrakt; tak, jeżeli zmniejszyć do snapshotowego odczytu bez linearizowalności.
16. **Confidence:** wysokie dla mechanizmu i testu; średnie dla realnego wpływu biznesowego i potrzebnego SLA.

**NOT VERIFIED — minimalny bezpieczny eksperyment:** isolated PostgreSQL, utworzyć 100/1000 równoległych odczytów i mutacji, zmierzyć waitów i latency bez wykonywania migracji; użyć rollback po eksperymencie i nie dotykać danych produkcyjnych.

### F-03 — powtórzona walidacja tego samego kontekstu i członkostwa

1. **Finding ID:** F-03
2. **Affected component:** middleware, `BossRawMaterialCatalogService`, `BusinessAuthorization`, `BusinessVisibility`.
3. **Exact file path and locations:** [ValidateIdentityContext.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Http/Middleware/ValidateIdentityContext.php:13), [BossRawMaterialCatalogService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php:119), [BusinessAuthorization.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/BusinessAuthorization.php:14), [BusinessVisibility.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/BusinessVisibility.php:85).
4. **Current mechanism:** middleware weryfikuje identity context i membership; serwis ponownie pobiera organization, fresh user, membership i context; `eligible()` jeszcze raz robi fresh() i membership query; potem osobne DB queries dla role i capability.
5. **Threat addressed:** stale session, nieaktywna organizacja, zmiana auth_version, usunięte/revoke membership, podmiana kontekstu.
6. **Realistic likelihood and impact:** powtarzanie jest regularne dla każdego list/detail i zwiększa czas; błąd w kolejnej warstwie może nadal prowadzić do odmowy, zwykle fail-closed.
7. **Complexity, performance and maintenance:** średnia; kilka query na request i wiele obiektów obudowujących ten sam kontekst. Ryzyko niespójnej interpretacji między warstwami.
8. **Evidence:** w kodzie JWT/session jest osobny control, potem service fresh database checks; `eligible()` też freshuje organizację/target i sprawdza membership.
9. **Simpler alternative:** jeden immutable per-request authorization context zawierający świeżą organizację, aktywnego użytkownika, membership, location i effective role; przekazać go do serwisu i `BusinessVisibility`. Middleware powinien robić walidację oryginalnego requestu, a serwis wykonywać tylko brakującą odrębną decyzję capability/restriction.
10. **Preserved guarantees:** zachowuje identity check, organization isolation, membership, BOSS role i capability.
11. **Reduced guarantees:** uproszczenie może dodać stale window, jeżeli serwis nie pobiera świeżych danych pod lockiem.
12. **Residual risk:** centralizacja może utrwalić ten sam bug; cache request-scoped nie może być cache z persystencji.
13. **Recommendation:** **SIMPLIFY** z zachowaniem sharedLock i świadomą granicą jedno-jednego fresh contextu.
14. **Priority:** MEDIUM.
15. **Contract amendment:** nie, o ile semantyka i status HTTP pozostają bez zmian.
16. **Confidence:** wysokie dla duplikacji; średnie dla możliwego benefit/performance.

### F-04 — gotowość capability i bramka RAW jako osobne warstwy

1. **Finding ID:** F-04
2. **Affected component:** catalog capability, capability state, module readiness, route middleware.
3. **Exact file and locations:** [CodeOrganizationCapabilityCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php:18), [OrganizationCapabilityAccess.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/OrganizationCapabilityAccess.php:12), [DefinedModuleCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/DefinedModuleCatalog.php:80), [api.php](/home/veltra/projekty/veltra-pa-07b3b/backend/routes/api.php:131).
4. **Current mechanism:** global ready false + organizational enabled false/true + route session/membership auth + capability access. RAW gate pozostaje osobno.
5. **Threat addressed:** przypadkowe aktywowanie nowego API przed produkcyjnym świadectwem; aktywacja niezapowiedziana organizacji.
6. **Realistic likelihood and impact:** low for accidental activation; high business impact if opening business data prematurely.
7. **Complexity:** medium-to-high due to code, database state and audit, but acceptable for explicit owner activation.
8. **Evidence:** production ready false and no activated state at acceptance.
9. **Simpler alternative:** nie usuwać readiness; po zaakceptowaniu gotowości zastąpić global/configured ready z prostym feature gate tylko wtedy, gdy nie wymaga się granularnej org activation.
10. **Preserved guarantees:** BOSS API remains fail-closed until ready and enabled.
11. **Reduced guarantees:** removing capability state would reduce explicit owner control and audit transitions.
12. **Residual risk:** stale state and readiness drift; capability state is not a business grant.
13. **Recommendation:** **KEEP** for owner-controlled activation, provided state remains part of accepted contract.
14. **Priority:** MEDIUM.
15. **Contract amendment:** no for current readiness; amendment required if activation semantics are simplified.
16. **Confidence:** high.

### F-05 — rewalidacja hash podczas zwykłego odczytu katalogu

1. **Finding ID:** F-05
2. **Affected component:** `BossRawMaterialCatalogService::approvedMaterials`, revision approval, working/pending revision.
3. **Exact file and locations:** [BossRawMaterialCatalogService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php:236), [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1298), [2026_11_02_000011_create_raw_materials_core.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php:313).
4. **Current mechanism:** approved catalog joins `approved_revision_id`, active state, approval event with matching `content_hash`, but does not recompute every field on ordinary read. Recompute at approval is in `approvalProblems()` and `hashFromDatabase()`.
5. **Threat addressed:** catalog could expose draft/pending or mismatching revision; approval event could disagree with stored hash.
6. **Realistic likelihood and impact:** low after constraints and review; high impact if approved data changed without approval.
7. **Complexity:** low read complexity, high mutation complexity concentrated at approval.
8. **Evidence:** read uses only projection from approved revision and matching approval event. Approval rechecks hash and required allergen/storage attestations; DB trigger verifies approved revision is pending and approved event matches revision hash.
9. **Simpler alternative:** enforce hash and approved/pending invariant completely at approval and revision mutation, then ordinary read need not recompute. That is effectively already the architecture.
10. **Preserved guarantees:** only ACTIVE + approved revision + matching approved event is projected.
11. **Reduced guarantees:** none for this finding; adding recompute would protect against some direct tampering but also become repetitive read cost.
12. **Residual risk:** direct SQL access as migration role or privileged owner can bypass app invariant; ordinary runtime has narrow triggers/grants. Historical tampering requires independent audit or database snapshot.
13. **Recommendation:** **KEEP** the current read projection; no new repeated recomputation at ordinary read.
14. **Priority:** MEDIUM.
15. **Contract amendment:** no.
16. **Confidence:** high for static behavior; NOT VERIFIED for runtime direct SQL and migration role behavior.

### F-06 — append-only i signed audit jako jednostki integralności

1. **Finding ID:** F-06
2. **Affected component:** security_events, approval/audit tables, DB triggers.
3. **Exact file and locations:** [2026_11_02_000011_create_raw_materials_core.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_02_000011/create_raw_materials_core.php:461), [2026_11_05_000022_enforce_signed_authorization_mutations.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:277).
4. **Current mechanism:** trigger prevents update/delete of approved/other history; signed mutating context writes authorization and audit in controlled scope.
5. **Threat addressed:** accidental and partial audit edits; direct runtime could alter protected tables.
6. **Realistic likelihood and impact:** low to medium accidental modification; high impact for audit integrity.
7. **Complexity:** medium maintenance and migration risk.
8. **Evidence:** append-only triggers are in schema; signed ticket is created in Laravel and can be used by compromised process.
9. **Simpler alternative:** keep append-only and runtime revoke UPDATE/DELETE, export critical audit independently; signed mechanism only for selected operations. Do not claim audit authenticity against RCE/db superuser.
10. **Preserved guarantees:** history cannot be updated/deleted by ordinary runtime; audit and mutation can be transactional.
11. **Reduced guarantees:** none if simply preserved; removing signed ticket loses extra SQL-only protection.
12. **Residual risk:** superuser/migrator, compromised Laravel, copied/altered database, missing independent copy.
13. **Recommendation:** **KEEP** append-only and narrow mutation scope; remove generic HMAC claims and separately assess external audit export.
14. **Priority:** HIGH.
15. **Contract amendment:** amend architecture contract if external audit or independent authenticity is required.
16. **Confidence:** high for append-only; high that HMAC does not prove against a compromised Laravel.

### F-07 — redundantne i zbyt szerokie testy regresji

1. **Finding ID:** F-07
2. **Affected component:** backend test suite, PA-07B.3B authorization/concurrency/provenance/API test sets.
3. **Exact file and locations:** test files in [backend/tests/Feature/](/home/veltra/projekty/veltra-pa-07b3b/backend/tests/Feature), especially `BossRawMaterialCatalogAuthorizationTest.php`, `BossRawMaterialCatalogConcurrencyTest.php`, `BossRawMaterialCatalogProvenanceTest.php`, `BossRawMaterialCatalogApiTest.php`.
4. **Current mechanism:** broad 542-test acceptance reports, plus dedicated authorization/concurrency/provenance/API suites and full regression.
5. **Threat addressed:** missing access isolation, stale locks, capability/revocation races, wrong approval projection.
6. **Realistic likelihood and impact:** low for documentation-only change; high for authorization/approval change. Regression cost can delay delivery.
7. **Complexity:** full suite is expensive, but focused suites add precise evidence and avoid broad rerun.
8. **Evidence:** PA-07B.3B acceptance reports post-correction 233 tests/2730 assertions for relevant regression and 10 focused tests/47 assertions. Historical full suite 542/5410 assertions is evidence of prior run, not current execution.
9. **Simpler alternative:** risk-based matrix with low/medium/high/critical levels.
10. **Preserved guarantees:** high/critical changes retain concurrency, integrity and independent verification.
11. **Reduced guarantees:** cosmetic-only full suite unnecessary; medium changes can omit privileged DB boundary and full migration if effects are isolated.
12. **Residual risk:** reduced suite might miss cross-module side effects; use smoke suite and targeted cross-module suite.
13. **Recommendation:** **SIMPLIFY** test orchestration, not meaningful authorization test coverage.
14. **Priority:** MEDIUM.
15. **Contract amendment:** no if policy is release process only.
16. **Confidence:** high for overhead classification; NOT VERIFIED for current suite runtime.

### F-08 — historyczny stan migracji 022 i realne dane instalacji

1. **Finding ID:** F-08
2. **Affected component:** migration 022→023 upgrade, role, signing key and existing history.
3. **Exact file and locations:** [2026_11_05_000022_enforce_signed_authorization_mutations.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:111), [2026_11_05_000023_narrow_authorization_mutation_boundary.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000023_narrow_authorization_mutation_boundary.php:48).
4. **Current mechanism:** current migration tree narrows and replaces key, but a fresh/upgrade database was not inspected in this session.
5. **Threat addressed:** preserving signed mutation context during upgrade and avoiding legacy data bypass.
6. **Realistic likelihood and impact:** low unknown for reviewer; potentially critical if migration 022 was applied to a real installation.
7. **Complexity:** migrations and atestacja have high maintenance cost.
8. **Evidence:** code has explicit migration attestation and 023 narrows triggers. No direct database status read was authorized/performed.
9. **Simpler alternative:** if no installation has migrated 022, remove prototype migrations from future clean schema or replace with a clean v3 migration; never alter applied migrations. If applied, forward-only migration with independent review.
10. **Preserved guarantees:** no regression in new environment until deployment.
11. **Reduced guarantees:** not applicable: no runtime state change made.
12. **Residual risk:** unknown legacy data and existing secret/role state.
13. **Recommendation:** **FURTHER EVIDENCE**; no implementation until deployment inventory and independent migration review.
14. **Priority:** HIGH.
15. **Contract amendment:** likely if migration 022 is considered accepted/deployed.
16. **Confidence:** low for installed state; high for absence of runtime mutation in this task.

## 5. Mechanizmy, które powinny pozostać bez zmian

- **Najmniej przywilejowane przepływy RBAC i izolacja organizacji** — zachować `organization_id`, membership, active status i effective role checks.
- **BOSS location/category restrictions** — są filtrem pola widoczności, nie pomocą UI: [BossLocationRestrictions.php](/home/veltra/projekty/veltra-pa_07b3b/backend/app/Domain/Authorization/BossLocationRestrictions.php:1) (ścieżka referencyjna: nazwa klasy i testy concurrency).
- **Org capability READY+ENABLED** — explicit owner activation is not reduced while feature remains unready.
- **Approval invariants** — approved revision requires matching approval event, hash and pending state; [2026_11_02_000011_create_raw_materials_core.php](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php:313).
- **Append-only history** — update/delete protection remains meaningful against ordinary runtime role.
- **PostgreSQL constraints/foreign keys and organization locks** — preserve atomic races and no orphaned data.
- **Session identity auth_version check and membership validation** — no simplification that removes fail-closed check.
- **Strict projected catalog fields** — BOSS five DTO fields are separate read contract and should not accidentally include description/price/cost/supplier/work revisions.

## 6. Kandydaci uproszczeń uszeregowani według wartości biznesowej

| Rank | Candidate | Business value | Risk of simplification | Required evidence |
|---:|---|---|---|---|
| 1 | Zawęzić HMAC do kilku mutacji high-risk lub usunąć dla niechronionych tabel | wysokie — mniej scopes, migracji i testów | wysokie — SQL-only/integralność | threat model, role grant audit, adversarial SQL-only test |
| 2 | Request-scoped authorization context zamiast powtarzanych fresh queries | średnie — latency i maintenance | średni — stale context | latency/lock test, exact HTTP contract |
| 3 | Retain sharedLock but test lock duration and transaction scope | średnie — availability vs guarantee | średni — stale auth window | NOT VERIFIED concurrency/latency experiment |
| 4 | Unified test matrix instead of unconditional full suite | średnie — release speed | niski–średni — missed cross-module effects | mapping change→suite |
| 5 | Remove only future prototype migration022 on clean installations | średnie — less upgrade complexity | wysokie dla existing DB | inventory of deployed migrations and independent rollback/recovery |

## 7. Review A — odpowiedzi na pytania o locking

1. **Po co każdy lock jest konieczny:** `FOR SHARE` serializuje katalog z mutacjami używającymi `FOR UPDATE` organization. Stanowi dwie strony kontraktu atomicznego: odczyt i mutacja organizacji.
2. **Race prevented:** role revocation, membership revocation, restriction replacement i capability disable mogą być współbieżne z list/detail. Shared lock zapobiega odczytowi kończonemu poza zatwierdzoną zmianą.
3. **Czy read blocks org writes:** tak, read-only transaction acquires organization shared lock and blocks conflicting update/delete and any `FOR UPDATE` on organization until transaction end.
4. **Czy consistency stronger than business need:** nie potwierdzono. Kontrakt oczekuje atomicznego view and deny; however business may accept newer snapshot. The stronger guarantee is justified for authorization but overstrong if stale read window is accepted.
5. **Per-request authorization model:** może dać wystarczającą ochronę dla kolejnego requestu, ale nie zapewni linearizowalnej serializacji. Dla zwykłego odczytu może być wystarczające.
6. **What lost:** stale/linearization guarantee, simultaneous revocation/capability test guarantee and guaranteed writer waits.
7. **Other modules:** Security mutations, membership, role administration, capability configure, module configuration and ordinary RAW operations share organization `FOR UPDATE`, although not every operation is the same transaction contract.

## 8. Review B — complexity authorization

Potwierdzono wiele warstw: middleware, service fresh identity, membership, BOSS role, capability, restriction. Część jest defence-in-depth. Powtórzenia `fresh()` i membership queries są accidental-looking duplication; centralizacja request context is appropriate, but no removal while lock/race semantics are not contractually defined.

## 9. Review C — approval and integrity

Approved revision selection is strongest at approval and DB constraints. Ordinary catalog read does not recompute full content hash. This is proportional. Repeated recomputation should remain limited to approval or integrity audit. Direct SQL as migration/superuser remains outside app guarantees.

## 10. Review D — database security

Actual implementation has separate runtime/migration roles and dedicated mutation signing key after migration 023; generic trigger was narrowed in migration 023. Migration 022 behavior is not treated as current. Runtime HMAC key is available to Laravel. It is not a boundary against compromised Laravel/RCE.

## 11. Review E — proposed risk-based testing policy

| Level | Examples | Required validation |
|---|---|---|
| LOW | kosmetyka, copy, design non-sensitive | focused UI/lint/build and smoke test |
| MEDIUM | ordinary business workflow, read-only API, no privilege/financial effect | focused backend tests + relevant cross-module smoke |
| HIGH | authorization, roles, revocation, financial data, approvals, business-critical mutations | targeted unit/feature/concurrency tests + rollback/commit outcome + relevant regression |
| CRITICAL | irreversible migrations, credentials, audit integrity, privileged DB boundary | disposable migration/upgrade test, adversarial runtime test, independent review, audit integrity, full relevant regression |

Testy concurrency są obowiązkowe dla zmian locków, revocation i capability. Testy pełnego backendu wymagane dla HIGH/CRITICAL cross-module, nie dla LOW. Independent audit should be proportionate; documentation defects cannot be called production vulnerabilities merely because report text is stale or inconsistent.

## 12. Dependencies and consequences

Any change to F-01 requires migration, role grant, audit and rollback tests. F-02 removal requires contract and owner approval; ordinary RAW operations can remain unaffected. F-03 centralization requires exact status semantics and no cross-request cache. F-04 activation change requires owner readiness amendment. F-05 no implementation due to existing invariant. F-06 external audit export is a new infrastructure dependency. F-07 policy change is process-only.

## 13. Unresolved questions

- Is linearizable BOSS read guaranteed by business contract, or is snapshot read acceptable?
- What is the measurable p95 latency and lock wait during organization mutations?
- Which migration 022/023/024 is actually applied in production/staging?
- Does runtime DB role have any direct UPDATE/DELETE beyond triggers/grants?
- Is APP_DB_MUTATION_SIGNING_KEY independent and not exposed to application logs/process dump?
- Is external audit export required or only append-only local integrity?
- Is direct SQL by migration role part of realistic threat model?
- How much business risk exists in stale catalog after role/capability revocation?
- VSEF proposal: no explicit approval evidence was found in the recenzowany implementation; it is not treated as implemented/binding.

## 14. Recommended minimal implementation sequence — future owner approval only

1. Measure read/organization lock wait in disposable PostgreSQL environment; no production data.
2. Inventory applied migrations and role/grant state on a snapshot or independent report.
3. Decide whether generic HMAC remains for SQL-only protection and whether current scope is a real threat.
4. If HMAC remains, narrow scope and payload to named operations; if not, remove generic ticket with forward migration.
5. Consolidate request-scoped authorization context without changing lock semantics.
6. Add LOW/MEDIUM/HIGH/CRITICAL suite policy and CI selection.
7. Accept a Stage 2C/PA contract amendment before implementation.
8. No implementation, activation or PA-07B.3C is authorized by this report.

## 15. Source references

- [BossRawMaterialCatalogService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php)
- [BossRawMaterialCatalogController.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Http/Controllers/Api/V1/BossRawMaterialCatalogController.php)
- [BusinessVisibility.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/BusinessVisibility.php)
- [BusinessAuthorization.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/BusinessAuthorization.php)
- [ValidateIdentityContext.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Http/Middleware/ValidateIdentityContext.php)
- [OrganizationCapabilityAccess.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/OrganizationCapabilityAccess.php)
- [CodeOrganizationCapabilityCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php)
- [DefinedModuleCatalog.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Modules/DefinedModuleCatalog.php)
- [AuthorizationMutationContext.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Authorization/AuthorizationMutationContext.php)
- [migration 022](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php)
- [migration 023](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_05_000023_narrow_authorization_mutation_boundary.php)
- [migration capability state](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_20_000056_create_organization_capability_state.php)
- [BossRawMaterialCatalogConcurrencyTest.php](/home/veltra/projekty/veltra-pa-07b3b/backend/tests/Feature/BossRawMaterialCatalogConcurrencyTest.php)
- [BossRawMaterialCatalogProvenanceTest.php](/home/veltra/projekty/veltra-pa-07b3b/backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php)
- [PA-07B.3A Owner Acceptance](/home/veltra/projekty/veltra-pa-07b3b/VELTRA_PA_STAGE_07B3A_OWNER_ACCEPTANCE.md)
- [PA-07B.3B Owner Acceptance](/home/veltra/projekty/veltra-pa-07b3b/VELTRA_PA_STAGE_07B3B_OWNER_ACCEPTANCE.md)
- [Stage 2C security architecture decision](/home/veltra/projekty/veltra-pa-07b3b/VELTRA_STAGE_2C_SECURITY_ARCHITECTURE_DECISION.md)

## 16. Zakończenie

**CONFIRMED FACT:** raport został przygotowany bez zmiany application code, konfiguracji, testów, migracji, danych, Docker, Git history, capability readiness, modułów ani deploymentu.

**CONFIRMED FACT:** nie uruchomiono testów ani skryptów mogących zmienić środowisko. Jedynym nowym artefaktem jest niniejszy raport.

**Wait for explicit owner authorization:** żadna z rekomendowanych korekt nie może być wdrożona bez osobnej zgody właściciela. PA-07B.3C nie został rozpoczęty.
