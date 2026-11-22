# VELTRA PA-07B.3 — lokalny merge do `main`

## 1. Wynik

**LOCAL INTEGRATION COMPLETE.** Zaakceptowany łańcuch PA-07B.3A–3C został scalony do lokalnego `main` jednym merge commitem. Obowiązkowe migracje i ukierunkowany zestaw regresyjny przeszły na nowych, jednorazowych projektach PostgreSQL 17.

Nie wykonano pushu, fetchu, deploymentu, migracji istniejącej bazy ani aktywacji możliwości lub modułu. Podglądu przeglądarkowego PA-07B.2 nie uruchamiano.

## 2. Zweryfikowany baseline Git

| Element | Wartość |
|---|---|
| Repozytorium docelowe | `/home/veltra/projekty/veltra` |
| Branch docelowy | `main` |
| `main` przed merge | `0c099bec0f30d3f160827a9eb4ce55a4cf979af5` |
| Branch źródłowy | `pa-07b3c-approved-supplier-snapshots` |
| HEAD źródłowy | `ec8deb8792602721a496292928687dff67a99999` |
| Merge-base | `09a8cd982f1496a3d6cb80843212a2770540dd70` |
| PA-07B.3A | `b47085e17810fea5d659b3245d04c542608f07d6` |
| PA-07B.3B | `d0d22e65df49fc4e8744ca626ac363126447e36d` |
| PA-07B.3C | `ec8deb8792602721a496292928687dff67a99999` |

Przed merge target miał czysty tracked/staged state, nie było kolizji z untracked files ani operacji Git w toku. Historia 3A → 3B → 3C jest liniowa; wszystkie trzy zaakceptowane commity są przodkami źródłowego HEAD i nowego `main`.

## 3. Strategia i operacje Git

Użyto zwykłego merge bez fast-forward, zachowującego oryginalną tożsamość zaakceptowanych commitów. Najpierw przygotowano merge bez commitu; automatyczne scalenie nie zgłosiło konfliktów. Przed commitem staged tree zawierało 50 ścieżek, `git ls-files -u` było puste, a `git diff --cached --check` zakończył się powodzeniem.

Commit:

`677e83bbc5f5939f562485ad39167f6083cce124`

Rodzice:

1. `0c099bec0f30d3f160827a9eb4ce55a4cf979af5`
2. `ec8deb8792602721a496292928687dff67a99999`

Commit zawiera 50 ścieżek (7055 insertions, 36 deletions). W zintegrowanym drzewie są zmiany z commitu 059, migracje 056 i 057 oraz implementacje 3A, 3B i 3C. Branch źródłowy pozostał na `ec8deb8792602721a496292928687dff67a99999`.

## 4. Migracje i zgodność danych

Weryfikacje wykonano wyłącznie w dedykowanym kontekście `veltra-test-isolated`, na osobnych projektach Compose z PostgreSQL 17, wewnętrzną siecią i `tmpfs`.

### Clean migration

- Czysta migracja przez 057: **PASS**.
- Migracje `2026_11_20_000056_create_organization_capability_state` i `2026_11_21_000057_add_raw_material_revision_supplier_snapshots`: obie zakończyły się `DONE`.

### Forward upgrade

Na osobnej bazie zastosowano migracje 001–055, przygotowano syntetyczne, poprawne referencyjnie dane historyczne RAW: jeden zatwierdzony materiał, rewizję, zdarzenie approval i legacy supplier association. Następnie zastosowano 056 i 057.

Po migracji potwierdzono:

- materiał `SUR-900001` i jego zatwierdzona rewizja nadal istnieją;
- zdarzenie approval nadal wskazuje zgodny hash rewizji;
- legacy supplier association nadal wskazuje tego samego dostawcę;
- brak wstecznie utworzonych wierszy w supplier proposals i approved supplier snapshots;
- brak wierszy stanu capability oraz aktywacji modułu RAW.

Do utworzenia syntetycznych historycznych rekordów użyto dedykowanej roli setup w tej jednorazowej bazie; na czas seedowania wyłączono i ponownie włączono user triggers, pozostawiając ograniczenia FK/CHECK aktywne i wymuszając ich walidację przed commitem fixture. Nie dotykano istniejącej bazy.

## 5. Testy integracyjne

Uruchomiono wskazany zestaw testów capability/readiness, BOSS RAW, RAW i supplier:

- `OrganizationCapabilityTest`
- `OrganizationCapabilityAuditFailureTest`
- `OrganizationCapabilityConcurrencyTest`
- `OrganizationCapabilityDatabaseIntegrityTest`
- `ModuleConfigurationTest`
- `ModuleConfigurationConcurrencyTest`
- `RawMaterialCapabilitiesApiTest`
- `BossRawMaterialCatalogApiTest`
- `BossRawMaterialCatalogAuthorizationTest`
- `BossRawMaterialCatalogConcurrencyTest`
- `BossRawMaterialCatalogProvenanceTest`
- `RawMaterialsTest`
- `RawMaterialRevisionDataModelTest`
- `RawMaterialConcurrencyTest`

**Wynik: 292 testy zaliczone, 3185 asercji, 0 błędów; czas 463,92 s.**

W tym przebiegu sprawdzono regresję API BOSS, autoryzację i ograniczenia kategorii, provenance zatwierdzonych rewizji, projekcję supplier snapshots, uprawnienia, cykl życia RAW, concurrency i konfigurację capability/modułów.

Syntax check PHP: **375 plików poprawnych** (`app`, `database/migrations`, `tests`). `git diff --cached --check` przed commitem: **PASS**.

## 6. Capability i gotowość

- Definicja `BOSS_RAW_CATALOG_READ` nadal ma `ready: false`.
- Definicja produkcyjna modułu `RAW_MATERIALS` korzysta z domyślnego `ready: false`.
- Po zakończeniu testów disposable DB nie zawierała żadnego wiersza `organization_capability_states` ani `location_module_states` dla `RAW_MATERIALS`.
- Testowe scenariusze mogły wykonywać przejścia stanu wyłącznie w odizolowanej bazie; żadna rzeczywista konfiguracja ani capability nie została aktywowana.

## 7. Commit 059 i browser preview

Zweryfikowano commit `0c099bec0f30d3f160827a9eb4ce55a4cf979af5` („059”): modyfikuje `.gitignore`, dodaje raporty/plany PA-07B.2/3B, `backend/Dockerfile.browser-preview`, `backend/database/seeders/BrowserPreviewSeeder.php`, `compose.browser-preview.yaml` i konfigurację Vite podglądu.

Zmiany 3A–3C nie nadpisują tych ścieżek. Seeder przygotowuje syntetyczną organizację, użytkowników, role, członkostwa i granty RAW; nie tworzy zatwierdzonych materiałów ani supplier snapshots oraz nie aktywuje `BOSS_RAW_CATALOG_READ` lub modułu `RAW_MATERIALS`.

**Browser-preview Compose nie został uruchomiony.** Izolacja egress tego środowiska nie została niezależnie potwierdzona, więc wykonanie preview oznaczono jako **NOT VERIFIED**; nie osłabiano tej granicy.

## 8. Ograniczenia i stan końcowy

- Preview PA-07B.2 pozostaje nieweryfikowany runtime’owo.
- Testy forward-upgrade potwierdzają zachowanie syntetycznych rekordów i brak automatycznego backfillu; nie zastępują próby na osobnej, rzeczywistej kopii danych przed przyszłym wdrożeniem.
- Nie wykonywano deploymentu ani testów przeciw STAGING/produkcji.
- Dwa jednorazowe projekty testowe zostały zatrzymane i usunięte; ich dane znajdowały się w `tmpfs`.
- Po commicie tracked/staged state docelowego worktree był czysty. Ten raport jest jedynym nowym untracked plikiem utworzonym w docelowym worktree. Istniejące raporty w worktree źródłowym pozostały zachowane i niezmienione.

**Werdykt: LOCAL INTEGRATION COMPLETE.** Merge jest lokalny; gotowość do integracji nie oznacza autoryzacji wdrożenia ani aktywacji.
