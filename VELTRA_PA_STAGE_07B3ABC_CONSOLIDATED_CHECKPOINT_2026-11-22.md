# VELTRA 2.0 — PA-07B.3A–3C — skonsolidowany checkpoint

**Data checkpointu:** 2026-11-22  
**Zakres:** read-only weryfikacja implementacji, Git, lokalnej akceptacji i gotowości  
**Werdykt lokalny:** 3A, 3B i 3C są obecne w zaakceptowanej, liniowej historii lokalnej. Nie są zintegrowane z `main`; integracja i gotowość środowiskowa pozostają odrębnymi decyzjami.

## 1. Podsumowanie wykonawcze

Zweryfikowano rzeczywistą historię Git, commity, status wszystkich czterech worktrees, implementację 3A–3C oraz istniejące raporty. PA-07B.3A i 3B mają owner acceptance; PA-07B.3C ma niezależny końcowy verdict `ACCEPT`. Początkowe blokery 3A/3B zostały opisane w starszych raportach, a następnie objęte korektami, zamknięciem weryfikacji i akceptacją. Nie należy traktować tych historycznych blokad jako bieżących, ale zachować je jako część śladu audytowego.

Commity układają się liniowo: **3A → 3B → 3C**. Aktualny `main` ma osobny commit po wspólnej bazie, więc wdrożenia 3A–3C nie ma jeszcze na gałęzi współdzielonej. Wszystkie trzy worktrees etapów mają czysty tracked tree i indeks; lokalne raporty pozostają nieśledzone. BOSS i RAW pozostają `NOT_READY`. Ten checkpoint nie uruchamiał testów, migracji ani żadnej zmiany środowiska.

## 2. Zweryfikowany Git graph i stan worktrees

| Etap/gałąź | HEAD | Parent | Zakres commitu |
|---|---|---|---|
| 3A — `pa-07b3a-readiness-contract` | `b47085e17810fea5d659b3245d04c542608f07d6` | `09a8cd982f1496a3d6cb80843212a2770540dd70` | 31 plików, w tym capability, migracja 056, API, testy i acceptance |
| 3B — `pa-07b3b-boss-raw-catalog` | `d0d22e65df49fc4e8744ca626ac363126447e36d` | `b47085e17810fea5d659b3245d04c542608f07d6` | 19 plików, w tym read-only BOSS API, testy i acceptance |
| 3C — `pa-07b3c-approved-supplier-snapshots` | `ec8deb8792602721a496292928687dff67a99999` | `d0d22e65df49fc4e8744ca626ac363126447e36d` | dokładnie 7 plików implementacji/testów |

`merge-base(main, 3C)` to `09a8cd982f1496a3d6cb80843212a2770540dd70`. Ancestry 3A→3B, 3B→3C i 3A→3C potwierdzono. `git rev-list main...3C` zwraca `1 3`: `main` ma jeden własny commit, a gałąź 3C ma trzy commity etapów, których nie ma na `main`.

Lokalny `main` i `origin/main` wskazują `0c099bec0f30d3f160827a9eb4ce55a4cf979af5` (`059`). Ten jeden commit zawiera `.gitignore` i osiem plików związanych z PA-07B.2/browser preview. `main` ma czysty status; trzy gałęzie etapów nie mają skonfigurowanego upstreamu. Nie pobierano aktualizacji z remote, więc weryfikacja dotyczy lokalnych refs i nie dowodzi stanu refs, których lokalnie nie ma.

W worktrees 3A, 3B i 3C nie ma staged ani zmodyfikowanych tracked plików. Nieśledzone pliki to raporty/kontrakty Markdown; nie znaleziono nieśledzonych plików aplikacji w tych worktrees. Raporty nie weszły do commitów implementacyjnych. `main` również nie ma niezapisanych zmian. Do 3C dołączony jest raport tego checkpointu jako nowy lokalny artefakt Markdown.

## 3. PA-07B.3A — readiness capability

Commit `b47085e17810fea5d659b3245d04c542608f07d6` wprowadza organizacyjny katalog capability, status oraz administratorowe API jawnej aktywacji/dezaktywacji i historii audytowej, a także addytywną migrację 056 ze stanem i append-only eventami. Capability jest bramką gotowości, nie grantem dostępu do danych.

Owner acceptance potwierdza zakres i jawne wyłączenia. Weryfikacja początkowo wykazała błąd deferred-commit audit path; późniejszy [raport korekty](../veltra-pa-07b3a/VELTRA_PA_STAGE_07B3A_DEFECT_CORRECTION_REPORT.md) dokumentuje rozróżnienie potwierdzonego rollbacku od niepewnego wyniku COMMIT i naprawę testów. Owner acceptance zatwierdza również `CAPABILITY_COMMIT_OUTCOME_UNKNOWN` jako HTTP 503. Późniejsze 521 testów / 5 188 asercji przeszło w odizolowanym środowisku PostgreSQL 17.

Akceptacja nie oznacza aktywacji: kodowa definicja `BOSS_RAW_CATALOG_READ` nadal ma `ready: false`; nie ma domyślnego włączenia dla organizacji.

Źródła: [owner acceptance](../veltra-pa-07b3a/VELTRA_PA_STAGE_07B3A_OWNER_ACCEPTANCE.md), [approval addendum](../veltra-pa-07b3a/VELTRA_PA_STAGE_07B3A_APPROVAL_ADDENDUM.md), [defect correction](../veltra-pa-07b3a/VELTRA_PA_STAGE_07B3A_DEFECT_CORRECTION_REPORT.md), [commit report](../veltra-pa-07b3a/VELTRA_PA_STAGE_07B3A_COMMIT_REPORT.md).

## 4. PA-07B.3B — BOSS RAW read-only catalog

Commit `d0d22e65df49fc4e8744ca626ac363126447e36d` dodaje osobne list/detail BOSS API i projekcję zatwierdzonych surowców. Kod ponownie waliduje tożsamość oraz dokładny membership/kontekst żądania, ocenia ścieżkę BOSS i capability, a katalog ogranicza do organizacji oraz aktualnej zatwierdzonej rewizji z pasującym approval event/content hash. Zapytanie BOSS korzysta z organizacyjnego shared lock; nie deleguje do zwykłego RAW endpointu.

Kontrakt 3B ogranicza odpowiedź do `id`, `code`, `name`, `category.id`, `category.name`, `base_unit_code`. Owner acceptance potwierdza dodane testy odmowy dla aktora z samym RAW lub Stage 1D, bez roli BOSS. Starszy security review został zamknięty korektą testów i późniejszą akceptacją. Raporty podają pełną regresję 542/5 410, a następnie istotną regresję po korekcie 233/2 730 oraz test autoryzacji 10/47.

Źródła: [kontrakt 3B](../veltra-pa-07b3a/VELTRA_PA_STAGE_07B3B_FINAL_CONTRACT_V1.md), [owner acceptance](../veltra-pa-07b3b/VELTRA_PA_STAGE_07B3B_OWNER_ACCEPTANCE.md), [review closure](../veltra-pa-07b3b/VELTRA_PA_STAGE_07B3B_REVIEW_CLOSURE.md), [implementation report](../veltra-pa-07b3b/VELTRA_PA_STAGE_07B3B_IMPLEMENTATION_REPORT.md), [commit report](../veltra-pa-07b3b/VELTRA_PA_STAGE_07B3B_COMMIT_REPORT.md).

## 5. PA-07B.3C — revision-bound supplier snapshots

Commit `ec8deb8792602721a496292928687dff67a99999`, parent `d0d22e65df49fc4e8744ca626ac363126447e36d`, obejmuje dokładnie:

1. `backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`
2. `backend/app/Domain/RawMaterials/RawMaterialService.php`
3. `backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php`
4. `backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php`
5. `backend/app/Http/Requests/RawMaterialDataRequest.php`
6. `backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php`
7. `backend/tests/Feature/BossRawMaterialCatalogApiTest.php`

W kodzie proposal jest związany z dokładną rewizją; approval event, snapshot i aktualizacja wskaźnika zatwierdzonej rewizji są w jednej transakcji. Guardy migracji chronią lifecycle i immutability snapshotów, a korekta identity uniemożliwia UPDATE przenoszący proposal header/item między rewizjami. BOSS dołącza tylko snapshot przypisany do approved revision, a nie legacy `raw_material_supplier_associations`.

Kontrakt pola to `supplier_names: list<string>|null`: `null` oznacza brak zatwierdzonego snapshotu/`NOT_PROVIDED`, `[]` jawnie zatwierdzony pusty zbiór, a lista — zatwierdzony zbiór. Snapshot zachowuje tożsamość dostawców; prezentowana nazwa jest rozwiązywana z bieżącego słownika. Legacy associations pozostają poza BOSS i nie są backfillowane.

Niezależna weryfikacja końcowa podała `ACCEPT`, `3C-AUD-001` i `3C-AUD-002` jako `VERIFIED FIXED`; signed cross-revision UPDATE został odrzucony z SQLSTATE `23514`, a próby ticketless z `42501`. W disposable PostgreSQL 17 clean migration 001–057 i syntetyczny upgrade 056→057 przeszły. Raport końcowy opisuje stan przed commitem i zawiera bazowy HEAD `d0d22e6`; aktualnym zweryfikowanym HEAD jest `ec8deb8`, a lista commitowanych plików jest zgodna z zaakceptowanym zestawem.

Źródła: [final targeted verification](VELTRA_PA_STAGE_07B3C_FINAL_TARGETED_VERIFICATION_2026-11-22.md), [implementation report](VELTRA_PA_STAGE_07B3C_IMPLEMENTATION_REPORT.md), [migracja 057](backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php), [serwis BOSS](backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php), [DTO](backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php).

## 6. Spójność architektury i migracji

- **Izolacja organizacji:** serwis BOSS pobiera organizację z aktualnego aktora i ogranicza zapytanie do jej ID; tabele proposals/snapshots i ich relacje mają klucze złożone/FK obejmujące organizację.
- **Granice RBAC/BOSS:** BOSS jest odrębną ścieżką widoczności. Capability nie zastępuje membershipu ani roli; RAW-only i Stage 1D-only nie przyznają dostępu do BOSS.
- **Pochodzenie approval:** projekcja wymaga zatwierdzonej rewizji oraz zgodnego eventu `APPROVED` z tym samym content hash.
- **Proposals i snapshots:** propozycje są rewizjo-związane; approved snapshot jest powiązany z eventem i rewizją oraz chroniony przed UPDATE/DELETE. Zmiana dostawców wymaga nowej rewizji i ponownej aprobaty.
- **Legacy:** zwykłe asocjacje RAW zachowują dotychczasowe znaczenie; nie są traktowane jako zatwierdzony snapshot BOSS.
- **HMAC/DB security:** 3A wprowadza scope’y dla capability, a 3C scope’y potrzebne dla nowych tabel; zakresy są addytywne. Nie znaleziono w zweryfikowanych commitach redesignu architektury HMAC.
- **Migracje:** 056 i 057 są kolejnymi migracjami w zaakceptowanej historii. Dowody migracji 057 pochodzą z disposable PG17; brak dowodu zastosowania ich na istniejącej bazie i nie wykonano takiej operacji w checkpointcie.

## 7. Testy i dowody akceptacji

Poniższe wyniki pochodzą z istniejących raportów — **nie uruchamiano ich ponownie w ramach tego read-only checkpointu**.

| Etap | Zachowane wyniki |
|---|---|
| 3A | 521 testów / 5 188 asercji — pełna regresja na świeżym, izolowanym PostgreSQL 17; 56/551 testów capability + RAW; Pint 389 plików; `git diff --check` PASS |
| 3B | 542/5 410 pełna regresja; po poprawce testy istotne 233/2 730; autoryzacja 10/47; Pint 401 plików; `git diff --check` PASS |
| 3C | provenance 15/73; API 5/76; authorization 10/47; concurrency 4/89; `RawMaterialsTest` 161/1 934; clean migration 001–057 i forward upgrade 056→057 PASS; wcześniejszy deterministyczny concurrency matrix 10 przeplotów / 43 asercje PASS |

Weryfikacja nie uruchamiała testów, migracji, lintów ani narzędzi modyfikujących stan. Pełna regresja na wspólnej gałęzi po integracji pozostaje do wykonania.

## 8. Ryzyka i pozostałe ograniczenia

1. **Brak integracji:** 3A–3C nie są w `main`. Przed integracją trzeba przejrzeć commit `059` po stronie `main` i uzgodnić strategię scalenia; ten commit jest poza łańcuchem 3A–3C.
2. **Brak testów po integracji:** historyczne PASS-y są per etap/izolowane worktrees; nie dowodzą, że cały zestaw przechodzi po integracji z `main`.
3. **Brak gotowości środowiskowej:** nie zweryfikowano staging/produkcji ani nie wykonano tam migracji.
4. **Granica legacy:** stare association nie mają historycznego dowodu rewizji/approval i nie są konwertowane na snapshot. To zamierzone ograniczenie, nie luka do uzupełnienia bez osobnej decyzji i dowodów.
5. **Raporty historyczne:** raporty 3A/3B zawierają wczesne wyniki BLOCKED/REJECT sprzed korekt. Końcowy status wynika z późniejszych correction/review-closure, owner acceptance oraz obecnych commitów, a nie z pierwszego raportu.
6. **Pozostałe niezweryfikowane obszary:** production readiness, live database migration state, deployment, frontend/UI BOSS oraz aktywacja capability/modułu. Są poza accepted scope lub nie zostały wykonane.

Nie znaleziono nowego, konkretnego defektu wymagającego ponownego szerokiego audytu. Niezależny targeted review 3C jest już wykonany i zaakceptowany.

## 9. Macierz gotowości

| Obszar | Status | Uzasadnienie |
|---|---|---|
| Lokalna kompletność implementacji 3A–3C | **COMPLETE** | Wszystkie trzy zaakceptowane commity są w lokalnej, liniowej historii. |
| Lokalna akceptacja Git | **ACCEPTED** | 3A/3B mają owner acceptance, 3C niezależny verdict ACCEPT; worktrees tracked/index clean. |
| Integracja ze wspólną gałęzią | **NOT INTEGRATED / WARUNKOWA** | `main` ma osobny commit `059`; wymagane uzgodnienie i przegląd integracyjny. |
| STAGING readiness | **NOT READY** | Brak deployu i weryfikacji środowiska; wymagane odrębne upoważnienie. |
| Production readiness | **NOT READY** | Lokalny ACCEPT nie jest zgodą na wdrożenie ani dowodem produkcyjnego stanu migracji. |
| Capability `BOSS_RAW_CATALOG_READ` | **NOT_READY / DISABLED** | Definicja kodowa ma `ready: false`; nie aktywowano organizacji. |
| Moduł `RAW_MATERIALS` | **NOT_READY** | Produkcyjna definicja pozostaje `ready: false`. |

## 10. Rekomendowany następny krok

W dokumentacji zweryfikowanej w tym zadaniu nie znaleziono zatwierdzonego zakresu PA-07B.4 ani kolejnego etapu implementacyjnego po 3C. Nie należy go dopowiadać.

**Najmniejszy sensowny następny krok to planowanie integracji, nie nowa funkcja:** uzyskać decyzję właściciela o włączeniu liniowego łańcucha 3A→3B→3C do wspólnej gałęzi, przejrzeć różnicę względem jedynego commitu `main` po bazie (`059`), ustalić merge/PR i dopiero po integracji wykonać ukierunkowaną regresję na scalonym stanie w disposable PostgreSQL 17. Dalszy rozwój funkcjonalny powinien zaczekać na tę integrację, żeby uniknąć pracy na odseparowanym łańcuchu.

Zależności pozostają ścisłe: 3B opiera się na capability i kontrakcie 3A; 3C rozszerza zatwierdzoną projekcję 3B. Nie rekomenduje się ponownego szerokiego audytu HMAC ani powtarzania zaakceptowanego targeted review 3C bez nowego dowodu lub zmian w integracji. Po scaleniu wystarczy adekwatna regresja integracyjna; nowy review jest potrzebny tylko wtedy, gdy integracja zmieni istotny kod lub ujawni konkretny konflikt.

Nie wykonano integracji, merge, commit, push, rebase, deployu, migracji, modyfikacji bazy ani aktywacji capability/modułu. Nie rozpoczęto PA-07B.4.
