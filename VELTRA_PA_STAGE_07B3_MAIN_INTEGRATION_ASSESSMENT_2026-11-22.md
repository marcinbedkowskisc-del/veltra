# VELTRA 2.0 — PA-07B.3A–3C — ocena integracji przed merge

**Data:** 2026-11-22  
**Zakres:** read-only analiza kompatybilności lokalnej gałęzi `main` z zaakceptowanym łańcuchem 3A–3C  
**Decyzja techniczna:** **GO dla lokalnej integracji merge commitem**, z zachowaniem zaakceptowanych commitów i przed wykonaniem odrębnego sprawdzenia aktualnego remote. Integracji w tej ocenie nie wykonano.

## 1. Zweryfikowane refs, worktrees i merge-base

| Ref / worktree | HEAD | Stan |
|---|---|---|
| `main` — `/home/veltra/projekty/veltra` | `0c099bec0f30d3f160827a9eb4ce55a4cf979af5` (`059`) | tracked tree i indeks czyste |
| `origin/main` — lokalny remote-tracking ref | `0c099bec0f30d3f160827a9eb4ce55a4cf979af5` | równe lokalnemu `main`; nie wykonywano fetch |
| `pa-07b3a-readiness-contract` — `/home/veltra/projekty/veltra-pa-07b3a` | `b47085e17810fea5d659b3245d04c542608f07d6` | tracked tree i indeks czyste |
| `pa-07b3b-boss-raw-catalog` — `/home/veltra/projekty/veltra-pa-07b3b` | `d0d22e65df49fc4e8744ca626ac363126447e36d` | tracked tree i indeks czyste |
| `pa-07b3c-approved-supplier-snapshots` — `/home/veltra/projekty/veltra-pa-07b3c` | `ec8deb8792602721a496292928687dff67a99999` | tracked tree i indeks czyste; lokalne raporty Markdown są nieśledzone |

Merge-base `main` ↔ 3C: `09a8cd982f1496a3d6cb80843212a2770540dd70`. Relacje rodziców potwierdzają 3A → 3B → 3C, a `git rev-list --left-right --count main...3C` zwraca `1 3`: jeden commit tylko po stronie `main` i trzy commity PA-07B.3 tylko po stronie źródłowej.

Weryfikacja obejmuje lokalne refs. Nie pobierano stanu serwera GitHub i nie zakładam, że `origin/main` jest aktualny. Gałęzie 3A–3C nie mają upstream tracking branch. Istniejące nieśledzone raporty w worktree 3C nie pokrywają się nazwami z żadnym path zmienionym przez 3A–3C; targetowy worktree `main` jest czysty.

**Źródło:** `git worktree list`, `git branch -vv`, `git rev-parse`, `git merge-base`, `git rev-list`, `git diff --name-only` oraz `git diff-tree --name-status`, wykonane read-only.

## 2. Analiza commit 059

Commit `0c099bec0f30d3f160827a9eb4ce55a4cf979af5`, parent `09a8cd982f1496a3d6cb80843212a2770540dd70`, ma subject `059` i zawiera dokładnie dziewięć ścieżek:

| Ścieżka | Zmiana / cel | Związek z 3A–3C |
|---|---|---|
| `.gitignore` | Dodaje wykluczenie `tests/compose/pa07b2-browser-preview.env`; ograniczone do lokalnego pliku środowiska preview. | Brak konfliktu; nie wyklucza plików implementacji ani migracji 056/057. |
| `VELTRA_PA_STAGE_07B2_BROWSER_VERIFICATION_REPORT.md` | Historyczny raport początkowej, zablokowanej weryfikacji browserowej PA-07B.2. | Dokumentacja historyczna; późniejszy raport środowiskowy/owner acceptance opisuje późniejsze wyniki. |
| `VELTRA_PA_STAGE_07B2_ENV_VERIFICATION_REPORT.md` | Zapis izolowanego preview i jego ograniczeń, w tym brak pełnej gwarancji egress isolation. | Nie zmienia kodu. Pozostawia znany warunek dla ponownego użycia preview. |
| `VELTRA_PA_STAGE_07B2_OWNER_ACCEPTANCE.md` | Akceptuje zakres PA-07B.2, wyłączając projekcję RAW i automatyczną zgodę na 3A. Rejestruje otwarte follow-upy PA07B2-FU-01–04. | Historyczny checkpoint; późniejsze addendum/akceptacje 3A–3C są późniejszymi decyzjami. |
| `VELTRA_PA_STAGE_07B3_IMPLEMENTATION_PLAN.md` | Opisuje proposal/checkpointy 3A–3C i wyraźnie oznacza plan jako `REVISED ARCHITECTURE PROPOSAL / BLOCKED`. | **Rozbieżność dokumentacyjna:** status planu jest historyczny i nie odzwierciedla późniejszego owner acceptance 3A/3B ani niezależnego ACCEPT 3C. Nie jest sprzecznością kodową; wymaga czytelnego traktowania planu jako historycznego/superseded. |
| `backend/Dockerfile.browser-preview` | Osobny obraz preview oparty na lokalnym `veltra-test-backend:local`; kopiuje backend i usuwa `.env` z obrazu. | Nie zmienia zwykłego Dockerfile, zależności Composer ani aplikacji. Budowanie zależy od obecności lokalnej bazy obrazu. |
| `backend/database/seeders/BrowserPreviewSeeder.php` | Tworzy wyłącznie syntetyczną organizację, lokalizacje, konta/role BOSS i początkowy RAW grant. Wymaga pustej bazy i odmawia pracy poza dokładnym preview DB/host/rolą; używa osobnego setup połączenia migratora. | Nie tworzy capability activation, supplier proposal, snapshot ani materiału RAW. Po integracji musi nadal pozostawać `BOSS_RAW_CATALOG_READ` NOT_READY/disabled, a RAW NOT_READY. Nie omija 056/057 i nie zmienia ich tabel. |
| `compose.browser-preview.yaml` | Izolowany preview: PostgreSQL 17 z danymi w `tmpfs`, Redis w `tmpfs`, backend, Vite frontend na `127.0.0.1:5197`, osobne migrate/seed jobs. | `migrate` wykona wszystkie obecne migracje, w tym 056/057 po integracji. **Compose nie uzależnia seed job od zakończenia migrate job**: oba zależą wyłącznie od zdrowego PostgreSQL. Test musi uruchamiać migrate i seed sekwencyjnie. `browser-ingress` nie jest siecią `internal`; raport 07B.2 nie uznaje pełnej izolacji egress. |
| `frontend/vite.browser-preview.config.ts` | Proxy `/api` i `/sanctum` do `backend`; nie dodaje komponentów ani tras UI. | Nowe backendowe endpointy mogą być osiągalne przez proxy, ale sam commit 059 nie udostępnia BOSS UI ani nie zmienia autoryzacji. |

W Compose wartości poświadczeń są parametrami wymaganymi z zewnątrz; nie odczytywano pliku poświadczeń ani sekretów. Konfiguracja ma `pull_policy: never`, więc uruchomienie preview wymaga dostępnych, lokalnych obrazów. To ograniczenie testowe, nie blocker integracji kodu.

## 3. Nakładanie ścieżek i konflikty semantyczne

Porównanie nazw path zmienionych względem wspólnej bazy:

- commit 059 / `main`: 9 ścieżek;
- łańcuch 3A–3C: 50 ścieżek;
- przecięcie: **0 ścieżek**.

Oznacza to brak tekstowego konfliktu ścieżek przy scaleniu tych commitów. Nie jest to samo w sobie dowodem zgodności semantycznej; sprawdzono poniższe punkty:

1. **Migracje.** Na `main` ostatnie widoczne pliki to 054/055. Źródłowy łańcuch dodaje 056 (`2026_11_20_000056_create_organization_capability_state`) i następnie 057 (`2026_11_21_000057_add_raw_material_revision_supplier_snapshots`). Nazwy i daty zachowują kolejność Laravel. Migracja 056 tworzy capability state/event i jej własne guardy/granty; nie zmienia tabel RAW grantów, ról ani migracji 054/055. Migracja 057 dodaje revision-bound proposal/snapshot tables. Commit 059 nie zawiera migracji.
2. **Seed i autoryzacja.** Browser seeder zakłada role BOSS oraz osobny RAW grant w pustej, ściśle ograniczonej bazie syntetycznej. Nie dodaje stanu capability. W kodzie 3A `BOSS_RAW_CATALOG_READ` ma `ready: false`; nieobecny stan organizacyjny pozostaje disabled. W kodzie 3A katalog `RAW_MATERIALS` ma domyślne `ready: false`. Dlatego preview nie powinien uzyskać dostępu do BOSS katalogu ani zwykłych endpointów RAW po integracji; jest to zgodne z akceptowanym readiness boundary.
3. **Zapis przez seeder a migracje 056/057.** 056 dotyczy nowych tabel capability; 057 chroni nowe supplier proposal/snapshot tables. Seeder nie zapisuje do tych tabel. Nie znaleziono w 056/057 zmiany grantów, które tworzyłby seeder. Warto jednak potwierdzić tę zgodność w teście migrate→seed na świeżej bazie PostgreSQL 17, zamiast wnioskować wyłącznie z rozłącznych tabel.
4. **BOSS RAW i proxy.** Ścieżka proxy Vite jest ogólna `/api`; endpointy BOSS 3B/3C korzystają z tej samej backendowej przestrzeni, ale nie mają kolidujących tras w commit 059. Commit preview nie zmienia `BusinessVisibility`, RBAC, `OrganizationCapabilityAccess` ani `BossRawMaterialCatalogService`.
5. **RAW lifecycle / legacy suppliers.** Seeder nie tworzy materiałów ani legacy supplier associations. 3C zachowuje legacy association dla zwykłego RAW i BOSS czyta wyłącznie zatwierdzony snapshot. W commit 059 nie ma kodu edycji/approval RAW, więc nie znaleziono logicznej kolizji.
6. **`.gitignore`.** Reguła ma pojedynczy, konkretny path dla lokalnego env preview; nie ignoruje migracji, testów ani raportów PA-07B.3.

Rozbieżność dokumentacyjna planu 07B.3 jest jedynym stwierdzonym konfliktem znaczenia: plik w commit 059 mówi „BLOCKED / niezatwierdzone”, podczas gdy późniejsze, osobne acceptance records potwierdzają etapy. Jest to stan historycznego dokumentu, nie blokada kodu. Przy integracji nie należy przedstawiać go jako aktualnego statusu bez wskazania późniejszych decyzji.

## 4. Rekomendowana strategia integracji

**Preferowana ścieżka przy procedurze współdzielonej:** PR z `pa-07b3c-approved-supplier-snapshots` do `main`, zakończony **merge commitem** (bez squash i bez rebase), po uprzednim potwierdzeniu bieżącego remote. To daje review/audit trail i zachowuje SHA oraz ancestry 3A→3B→3C.

**Jeśli zakres ma być wyłącznie lokalnym scaleniem:** zwykły `git merge --no-ff` w czystym worktree `main`, target `main` od `0c099bec0f30d3f160827a9eb4ce55a4cf979af5`, source `pa-07b3c-approved-supplier-snapshots` od `ec8deb8792602721a496292928687dff67a99999`. Powstanie merge commit z rodzicami `main` i 3C; wszystkie trzy zaakceptowane commity i ich pełne ancestry pozostaną bez zmian.

Fast-forward nie jest możliwy bez zmiany historii, bo `main` ma niezależny commit 059 po wspólnej bazie. Cherry-pick, rebase i squash są niewskazane, ponieważ zmieniłyby lub zgubiły zaakceptowane identyfikatory/relacje commitów. Nie sprawdzano zdalnych refs; przed PR/push należy osobno zweryfikować remote oraz uwzględnić otwarty PA07B2-FU-01 dotyczący atrybucji historycznego `update by push`. To nie blokuje lokalnego merge commitu, ale nie pozwala uznać lokalnego `origin/main` za dowód aktualności remote.

## 5. Potencjalne blokery i warunki

| Pozycja | Ocena |
|---|---|
| Tekstowy konflikt plików | Brak; przecięcie zmienionych paths wynosi zero. |
| Konflikt migracji 054/055 z 056/057 | Nie stwierdzono; nowa sekwencja jest addytywna i uporządkowana. Wymaga potwierdzenia przez test migracji po merge. |
| Capability / RAW readiness | Bez konfliktu; oba pozostają fail-closed/NOT_READY, jeśli nie zmieni się ich definicji. Nie wolno ich aktywować. |
| Stary status w `VELTRA_PA_STAGE_07B3_IMPLEMENTATION_PLAN.md` | Dokumentacyjna rozbieżność, nie blokada lokalnego merge. Przy publikacji wspólnego checkpointu odsyłać do późniejszych acceptance; ewentualna korekta dokumentu powinna być osobnym, jawnie zatwierdzonym commitem. |
| Kolejność migrate/seed w Compose 059 | Ograniczenie testowego orkiestratora; sekwencyjnie uruchomić migrate, potem seed. Nie uruchamiać obu one-shot jobs równolegle. |
| Egress preview | PA-07B2-FU-02 pozostaje istotne przy uruchamianiu browser preview; `browser-ingress` nie jest internal. Nie traktować tego stacka jako w pełni egress-isolated bez osobnej decyzji. Nie blokuje scalania samych źródeł. |
| Remote aktualność | Niezweryfikowana; przed zdalnym PR/push konieczny osobny fetch/porównanie i uprawnienie. Nie blokuje lokalnej integracji od zweryfikowanego HEAD. |

Nie znaleziono uzasadnionego technicznego powodu do rebase, cherry-pick ani zmiany treści zaakceptowanych commitów.

## 6. Minimalny plan regresji po merge

Wszystkie testy bazodanowe poniżej uruchomić **wyłącznie na disposable PostgreSQL 17** z syntetycznymi danymi; żadnych istniejących baz ani persistent volumes.

1. **Migracje:** na świeżej bazie wykonać sekwencję od początku przez 057; potwierdzić, że 056 poprzedza 057. Na oddzielnej, nowej disposable bazie odtworzyć stan po 055 z syntetycznymi RAW/approval/legacy association, następnie zastosować 056 i 057. Potwierdzić brak fałszywego backfillu, zachowanie istniejącego approval pointer/hash i poprawne install/grants nowych tabel.
2. **Commit 059 preview integration:** w unikatowym disposable Compose project (PostgreSQL 17 `tmpfs`, syntetyczne zmienne wyłącznie poza repo) uruchomić `migrate` i dopiero po jego sukcesie `seed`; potwierdzić, że guard seeder akceptuje tylko oczekiwaną bazę/role, tworzy role/grants, nie aktywuje capability i nie tworzy approved supplier snapshot. Następnie sprawdzić, że BOSS bez gotowej capability oraz ordinary RAW pozostają niedostępne (`BOSS` unavailable/503, RAW `NOT_READY`/503). Compose nie wymusza zależności seed→migrate; kolejność musi być kontrolowana przez runner.
3. **Capability/readiness:** uruchomić `OrganizationCapabilityTest`, `OrganizationCapabilityDatabaseIntegrityTest`, `OrganizationCapabilityAuditFailureTest`, `OrganizationCapabilityConcurrencyTest` i `RawMaterialCapabilitiesApiTest`. Sprawdzić domyślne NOT_READY/disabled, audyt oraz brak wpływu capability na ordinary RAW. Test-only READY/ENABLED tylko w odizolowanych fixtures.
4. **BOSS API/autoryzacja:** uruchomić `BossRawMaterialCatalogApiTest`, `BossRawMaterialCatalogAuthorizationTest`, `BossRawMaterialCatalogProvenanceTest` i `BossRawMaterialCatalogConcurrencyTest`; zachować denied RAW-only/Stage 1D-only, org isolation, restrictions, approved revision/hash i `supplier_names` (`null`/`[]`/lista).
5. **RAW/supplier lifecycle:** uruchomić właściwy `RawMaterialsTest` oraz testy approval/revision, supplier proposal, returned revision, snapshot immutability, draft deletion i legacy association. Wykonać te suites sekwencyjnie przy współdzielonych fixtures.
6. **Kontrola końcowa:** `git diff --check`, migracja status/schema, staged/working-tree review oraz sprawdzenie, że po merge kod nadal deklaruje `BOSS_RAW_CATALOG_READ = NOT_READY` i `RAW_MATERIALS = NOT_READY`. Nie wykonywać aktywacji ani deploymentu.

Browser UI/e2e nie jest warunkiem integracji backendu 3A–3C. Jeśli ma być uruchomione preview z frontendem, najpierw trzeba osobno rozstrzygnąć egress limitation PA07B2-FU-02. Nie odblokowywać readiness w konfiguracji preview jako substytutu test fixture.

## 7. Rekomendacja końcowa

### **GO — lokalna integracja**

Od strony ancestry, braku path overlap, kolejności migracji oraz obserwowanej logiki kodu 059 nie stwierdzono blokera lokalnego merge. Zalecany jest **merge commit** z zachowaniem oryginalnych commitów; PR z merge commit jest preferowany dla ścieżki współdzielonej, jeśli owner osobno upoważni publikację i po sprawdzeniu aktualnego remote. Historyczna rozbieżność dokumentacyjna i kolejność migrate/seed wymagają jawnego obejścia/oznaczenia, ale nie uzasadniają odrzucenia integracji.

`GO` dotyczy wyłącznie technicznej możliwości lokalnego scalenia zaakceptowanej historii. Nie jest zgodą na push, deploy, migrację na istniejącej bazie ani aktywację. `BOSS_RAW_CATALOG_READ` i `RAW_MATERIALS` mają pozostać `NOT_READY`.

Ta ocena nie wykonała merge, commit, rebase, cherry-pick, push, migracji, testów, uruchomienia Compose ani zmian środowiskowych.
