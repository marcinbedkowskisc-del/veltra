# VELTRA 2.0 — PA-07B.3B — przegląd architektury i kontraktu implementacyjnego

**Zakres:** projekt i przegląd; bez implementacji  
**Rekomendacja:** można rozpocząć implementację dopiero po rozstrzygnięciu decyzji właściciela z sekcji 7.  
**Poza zakresem:** PA-07B.3C, powiązania dostawców, wdrożenie, migracja, aktywacja capability.

## 1. Zweryfikowany stan repozytorium i źródła

### Baseline i zakres przeglądu

- Worktree: `/home/veltra/projekty/veltra-pa-07b3a`
- Gałąź: `pa-07b3a-readiness-contract`
- HEAD: `b47085e17810fea5d659b3245d04c542608f07d6`
- Weryfikacja przed przeglądem: ścieżka worktree, gałąź i SHA odpowiadają wartościom wymaganym w zleceniu.
- Worktree ma pięć istniejących, nieśledzonych raportów PA-07B.3A: `VELTRA_PA_STAGE_07B3A_COMMIT_REPORT.md`, `VELTRA_PA_STAGE_07B3A_DEFECT_CORRECTION_REPORT.md`, `VELTRA_PA_STAGE_07B3A_FINAL_REVIEW.md`, `VELTRA_PA_STAGE_07B3A_IMPLEMENTATION_REPORT.md` i `VELTRA_PA_STAGE_07B3A_VERIFICATION_CLOSURE.md`. Pozostawiono je nietknięte.
- Oryginalny worktree `/home/veltra/projekty/veltra` nie był modyfikowany. Zrewidowany plan PA-07B.3 został tylko odczytany.
- Zmiany w tym raporcie nie obejmują kodu, testów, migracji ani konfiguracji. Nie uruchamiano testów, aplikacji, migracji ani połączenia z bazą. Ustalenia dotyczą rzeczywistych definicji PostgreSQL w migracjach repozytorium i implementacji lifecycle; nie są wynikiem podglądu działającej bazy.

### Klasyfikacja ustaleń

- **[STAN]** — zachowanie i struktury obecne w zweryfikowanym źródle.
- **[WYMÓG]** — granica wymagana dla PA-07B.3B w tym zleceniu.
- **[PROPOZYCJA]** — kontrakt implementacyjny rekomendowany w tym raporcie; nie jest jeszcze zatwierdzonym zachowaniem aplikacji.
- **[DECYZJA]** — nierozstrzygnięta semantyka lub zakres wymagający akceptacji właściciela.

### Zweryfikowane źródła kodu i schematu

Przegląd objął co najmniej:

- `VELTRA_PA_STAGE_07B1_BOSS_VISIBILITY_ARCHITECTURE.md`
- `VELTRA_PA_STAGE_07B_IMPLEMENTATION_REPORT.md`
- `VELTRA_PA_STAGE_07B2_IMPLEMENTATION_REPORT.md`
- `VELTRA_PA_STAGE_07B3_IMPLEMENTATION_PLAN.md` (z oryginalnego worktree, tylko odczyt)
- `VELTRA_PA_STAGE_07B3A_OWNER_ACCEPTANCE.md`
- `backend/app/Domain/Authorization/BusinessVisibility.php`, `BusinessVisibilitySurface.php`, `BusinessVisibilitySource.php`, `BusinessDataProvenance.php`, `BusinessDataScope.php`, `BossVisibilityCategory.php`, `BossLocationRestrictions.php`, `BusinessAuthorization.php`, `SecurityMutation.php`
- `backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php`, `OrganizationCapabilityDefinition.php`, `OrganizationCapabilityService.php`
- `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php`, `RawMaterialLocationSharePolicy.php`, `RawMaterialService.php`
- `backend/app/Http/Middleware/ValidateIdentityContext.php`, `RequireOperatingContext.php`, `RequireRawMaterialsAvailability.php`
- `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`, `backend/app/Http/Requests/ListRawMaterialsRequest.php`, `backend/routes/api.php`
- Migracje `2026_11_02_000011_create_raw_materials_core.php`, `2026_11_08_000041_create_raw_material_location_shares.php`, `2026_11_09_000045_add_raw_draft_tombstones.php`, `2026_11_17_000050_add_raw_material_category_description_and_archive.php`, `2026_11_17_000051_create_raw_material_unit_dictionary.php` oraz `2026_11_20_000056_create_organization_capability_state.php`.

## 2. Architektura docelowa i granice źródeł autoryzacji

### Stan obecny

1. **Capability PA-07B.3A:** `CodeOrganizationCapabilityCatalog` definiuje `BOSS_RAW_CATALOG_READ` jako capability o zakresie `ORGANIZATION`, operacjach `list`/`detail`, kontekstach `CENTRAL_KITCHEN` i `RESTAURANT`, lecz `ready: false`. `OrganizationCapabilityService` traktuje brak wiersza stanu organizacji jako `DISABLED`; aktywacja kodowo niegotowej capability jest odrzucana. Stan nie jest sam w sobie uprawnieniem do danych. Nie istnieje jeszcze endpoint katalogu BOSS.
2. **Zwykły RAW:** wszystkie bieżące `/api/v1/raw-materials` są w osobnej grupie z `RequireRawMaterialsAvailability`. Middleware sprawdza `RAW_MATERIALS` dla rzeczywistego kontekstu i zwraca `503 RAW_MATERIALS_MODULE_UNAVAILABLE` przed kontrolerem. `RawMaterialAuthorization` dodatkowo wymaga aktualnego użytkownika, organizacji, membershipu, readiness i efektywnego RAW grantu.
3. **Widoczność BOSS:** `BusinessVisibility` rozdziela źródła `BOSS_DERIVED`, `STAGE_1D_INDEPENDENT` i `RAW_ORGANIZATION_INDEPENDENT`. Istniejący kontrakt BOSS obsługuje lokalizacje i raporty, nie organizacyjny katalog RAW. `BusinessVisibilitySurface` wymaga obecnie `Permission|RawMaterialPermission`; żadne z istniejących uprawnień nie może zostać użyte jako pozorny grant do katalogu BOSS.
4. **Ryzyko fallbacku:** `BusinessAuthorization::allows()` może po odmowie ścieżki BOSS sprawdzić niezależne uprawnienie Stage 1D. To jest celowe dla istniejących powierzchni, ale nowy endpoint RAW BOSS nie może wołać tej metody ani scalać jej wyniku z decyzją BOSS.
5. **Ograniczenia BOSS:** `BossLocationRestrictions::denies()` odrzuca globalnie wykluczoną kategorię; dla pochodzenia `ORGANIZATION` zwraca brak zastosowania wykluczeń lokalizacji i par lokalizacja/kategoria. Pochodzenie `UNKNOWN` jest odrzucane. To odpowiada naturze wspólnej definicji organizacyjnej, a nie lokalnego faktu.
6. **Kontekst:** istniejące middleware sprawdzają tożsamość sesji, aktywność/zweryfikowanie użytkownika i organizacji oraz bieżący, aktywny membership kontekstu. Endpoint BOSS ma wymagać tego prawdziwego kontekstu również wtedy, gdy dane są organizacyjne; nie może symulować członkostwa w centrali.

### Proponowany przebieg żądania

**[PROPOZYCJA]** Nowy, izolowany przepływ odczytu:

1. Sesja SPA i uwierzytelnienie Sanctum; kontrola wersji tożsamości; aktywna organizacja, użytkownik i bieżący aktywny membership operating context.
2. Walidacja, że kontekst jest jednym z typów dopuszczonych dla tej capability i należy do organizacji aktora.
3. Efektywna rola BOSS w organizacji; bez wnioskowania tej roli z RAW grantu i bez zastępowania jej rolą Stage 1D.
4. Kodowa definicja `BOSS_RAW_CATALOG_READ` istnieje, wskazuje `READY` oraz dopuszcza konkretną operację `list` albo `detail`; stan tej samej organizacji jest jawnie `ENABLED`.
5. `BusinessVisibility` rozstrzyga osobny BOSS intent dla operacji i kategorii `RAW_SUPPLIERS`, z serwerowym pochodzeniem `ORGANIZATION`; odmowa globalnej kategorii blokuje projekcję.
6. Osobny serwis wykonuje ograniczone zapytanie do zatwierdzonego katalogu i buduje jawny DTO. Lista, licznik, filtry, sortowanie, paginacja i detal używają tych samych warunków oraz allowlisty.

**[WYMÓG]** `RAW_MATERIALS` readiness, zwykłe `RAW_MATERIALS_VIEW` ani dowolny inny RAW grant nie uczestniczą w decyzji o nowej projekcji. Grant RAW nie aktywuje capability i nie rozszerza jej pól, rekordów, filtrów ani dostępu BOSS. BOSS nie otrzymuje przez ten endpoint zwykłego RAW permission. Stage 1D oraz zwykłe RAW zachowują własne, niezależne ścieżki i nie są fallbackiem.

### Minimalne rozszerzenie `BusinessVisibility`

**[PROPOZYCJA]**

- Dodać zarejestrowany, typowany intent BOSS dla `raw-materials.list` oraz osobny intent `raw-materials.detail`. Intenty są akceptowane wyłącznie w źródle `Boss`, wyłącznie dla capability `BOSS_RAW_CATALOG_READ`, operacji `list`/`detail`, kategorii `[RAW_SUPPLIERS]`, pochodzenia `ORGANIZATION` i bez lokalizacji docelowej.
- Rozszerzyć `BusinessVisibilitySurface` minimalnie i typowo (dedykowany enum intentu BOSS, bez sztucznego `Permission`/`RawMaterialPermission`). Nie zmieniać semantyki już istniejących powierzchni i nie kierować BOSS do `decideRaw()`.
- Dla nowych intentów decyzja BOSS sprawdza efektywną rolę, capability READY+ENABLED, operację, kontekst członkostwa, kategorię i znane pochodzenie. Utrzymać odmienne `list`/`detail` także w kluczach audytowalnych decyzji i testach.
- Nie uruchamiać lokalizacyjnego `ModuleAvailability` dla globalnego katalogu: nie ma źródłowej ani docelowej lokalizacji tego faktu. W szczególności nie sprawdzać `RAW_MATERIALS` w operating context ani w fikcyjnej centrali.
- Pozostawić istniejące trzy źródła `BusinessVisibilitySource` rozłączne. Nie zmieniać `BusinessAuthorization::allows()` ani zwykłej ścieżki RAW, poza niezbędnym zachowaniem zgodności typów kontraktu.

**[DECYZJA]** Szczegółowy kształt API typów (`BusinessVisibilityIntent` jako osobne pole albo typowany wariant istniejącej powierzchni) może wybrać implementacja, o ile nie ma wartości domyślnej, nie dopuszcza fałszywego RAW permission i testy dowodzą, że tylko dwa jawne intent-y są akceptowane dla nowej capability.

### Restrykcje i współbieżność polityki

- Globalne wykluczenie `RAW_SUPPLIERS` blokuje listę i detal niezależnie od share’ów. Globalne wykluczenie innej kategorii samo nie blokuje tej kategorii danych i nie przyznaje żadnych dodatkowych pól.
- Dla dowiedzionego `ORGANIZATION` wykluczenie lokalizacji lub para `(RAW_SUPPLIERS, location_id)` nie ukrywa wspólnej definicji i nie może być interpretowane jako filtr po share. Nie należy rozstrzygać dostępności rekordu przez `raw_material_location_shares`.
- Nieznane/uszkodzone pochodzenie jest fail-closed. Nie wolno zamienić go w `ORGANIZATION` przez brak `location_id`.
- **[PROPOZYCJA]** Całe odczytanie autoryzacji, capability, migawki restrykcji, count i danych wykonać w krótkiej transakcji z blokadą współdzieloną wiersza organizacji. Zapis restrykcji BOSS przechodzi przez `SecurityMutation::run()`, które blokuje ten sam wiersz organizacji `FOR UPDATE`; zmiana polityki nie może więc nastąpić między decyzją a projekcją. Capability configure również serializuje się na wierszu organizacji. Nie używać cache współdzielonego między użytkownikami ani żądaniami. Zmiany membershipu/roli i ich blokadę na tej samej granicy trzeba potwierdzić testem współbieżności przed uznaniem kontraktu za gotowy.
- Jeśli polityki nie da się wiarygodnie odczytać lub jej struktura jest uszkodzona, przerwać żądanie bez odpowiedzi sukcesu. Nie zwracać pustej listy jako zastępstwa awarii.

## 3. Dokładny kontrakt API i DTO

### Trasy i middleware

**[PROPOZYCJA — nowe trasy, obecnie nie istnieją]**

```text
GET /api/v1/boss/raw-materials
GET /api/v1/boss/raw-materials/{material}   (UUID)
```

Umieścić je w dedykowanej grupie BOSS, nie w `/v1/raw-materials` ani `/v1/security`. Stosować `RequireSpaSession`, `auth:sanctum`, `ValidateIdentityContext`, `AuditSecurityRejection`, `RequireOperatingContext` i limit odczytu zgodny z konwencją API. Dodać UUID constraint do `{material}`. Nie podłączać `RequireRawMaterialsAvailability`, istniejącego `RawMaterialController`, `ListRawMaterialsRequest`, `RawMaterialService::buildCatalogQuery` ani serializatorów RAW.

Nowy kontroler ma pobierać aktora i rzeczywisty `operating_location` z request attributes, a nie z parametrów wejściowych. Request listy waliduje wszystkie klucze i wartości; nieznane parametry odrzuca jako `422`. Detail nie przyjmuje filtrów.

### Odpowiedź — dokładna allowlista danych

Lista:

```json
{
  "data": [
    {
      "id": "uuid",
      "code": "SUR-000001",
      "name": "Nazwa z zatwierdzonej rewizji",
      "category": {
        "id": "uuid",
        "name": "Aktualna nazwa słownika"
      },
      "base_unit_code": "kg"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 1,
    "last_page": 1
  }
}
```

Detail zwraca ten sam obiekt pod `data`, bez paginacji. Pola rekordu to wyłącznie `id`, `code`, `name`, `category.id`, `category.name`, `base_unit_code`. `meta` jest wyłącznie metadanymi paginacji, nie dodatkowymi danymi materiału.

DTO tworzyć jawnie, z wybranych kolumn; nie zwracać modelu, rewizji, relacji ani `toArray()`/arbitralnej serializacji. Nie dołączać supplier IDs/names, opisu, SKU, aliasów, lifecycle, rewizji, statusu kategorii, jednostek przeliczeniowych, ilości, cen, kosztów, share’ów, plików/dokumentów, alergenów, historii, audytu, flag administracyjnych ani danych z innych źródeł. Nie używać ich w wyszukiwaniu, filtrach, sortowaniu, liczniku ani błędach.

### Parametry listy

**[PROPOZYCJA]** Minimalny jawny allowlist zgodny ze zrewidowanym planem:

| Parametr | Reguła |
|---|---|
| `search` | Opcjonalny tekst, maks. 120 znaków; dopasowanie tylko do widocznych `name` i `code`. Escape `%`, `_` i `\\`, by wyszukiwanie było dosłowne. Bez aliasów/opisów/dostawców. |
| `category_id` | Opcjonalny UUID. Filtr stosowany wyłącznie do joined category tego samego materiału i organizacji; nie ujawnia, czy obca kategoria istnieje. |
| `unit` | Opcjonalny kod `base_unit_code` zgodny z formatem słownika jednostek. |
| `sort` | Tylko `name`, `code`, `category`, `unit`; domyślnie `name`. |
| `direction` | Tylko `asc`/`desc`; domyślnie `asc`. |
| `page` | Liczba całkowita >= 1; domyślnie 1. |
| `per_page` | Tylko 25, 50 lub 100; domyślnie 25. |

Każda lista sortowania kończy się stabilnym `id` jako tie-breaker. Dla kategorii sortować według aktualnej nazwy kategorii, a potem `id`. Jawnie ustalić kolację w używanym środowisku i testować równe nazwy/kody. `total` liczyć po wszystkich warunkach autoryzacji, provenance i filtrach, przed limitem strony. Nie obiecywać niezmiennego snapshotu między odrębnymi żądaniami stron.

Nie przyjmować `supplier_id`, `lifecycle_state`, `revision_id`, filtrów share, aliasu, pól finansowych, `include_*`, pól projekcji ani nieznanych sortów. Nieznany klucz i nieprawidłowa wartość dają walidację 422, nie ignorowanie parametru.

### Statusy i błędy

Zachować konwencję JSON `{ "error": { "code": "...", "message": "..." } }`; komunikat jest bezpieczny, bez SQL, identyfikatorów obcych danych ani przyczyny wewnętrznej.

| Status | Przypadek |
|---|---|
| `401` / istniejące `419` | Brak/niepoprawna sesja, nieważna tożsamość lub wygasła sesja/CSRF zgodnie z konwencją SPA. |
| `403` | Brak wymaganego operating context albo aktywnego membershipu, brak efektywnej roli BOSS, niedozwolony typ kontekstu, globalna restrykcja `RAW_SUPPLIERS`. Nie podawać, czy żądany UUID istnieje. |
| `404` | Detail: materiał nieistniejący, obcy, soft-deleted, nieaktywny, bez prawidłowej rewizji/approval/category lub ukryty przez warunki projekcji — ten sam status, kod i bezpieczny komunikat. |
| `422` | Nieznane query key, błędny format, nieobsługiwana wartość parametru lub niepoprawne UUID w query. |
| `503` | Capability NOT_READY, jawnie DISABLED/brak stanu organizacji, wymagany stan autoryzacji/provenance niedostępny albo błąd bazy/polityki. Jeden ogólny kod, np. `BOSS_RAW_CATALOG_UNAVAILABLE`; bez success-shaped fallbacku. |
| `405` | Metoda nieobsługiwana na istniejącej trasie. Brak tras zapisu/eksportu/dokumentów w grupie BOSS. |

Wymagana separacja: globalna odmowa dostępu do powierzchni to 403; niedostępność capability to 503; obiekt ukryty/obcy/nieistniejący to nierozróżnialne 404. Na detail autoryzować rolę, readiness i kategorię przed wyszukaniem ID. W obrębie dozwolonej powierzchni identyfikator obcy nie może ujawniać organizacji ani statusu rekordu.

Ustawić `Cache-Control: private, no-store` na sukcesach i błędach, w tym odpowiedziach generowanych przez wyjątki/middleware (nie tylko w kontrolerze); zweryfikować to przez testy odpowiedzi 401/403/404/422/503. Nie stosować CDN/public cache, współdzielonego cache aplikacji, stale response, ETag/304 ani klucza cache bez pełnego kontekstu użytkownika/organizacji/polityki. Najbezpieczniejszy kontrakt 3B to brak cache.

## 4. Macierz autoryzacji i readiness

| Warunek | Wynik | Źródło / uwaga |
|---|---|---|
| Sesja, aktywna/zweryfikowana tożsamość, aktywna organizacja i prawdziwy membership aktywnego kontekstu | Wymagane przed serwisem; 401 dla tożsamości, 403 dla brakującego/nieważnego kontekstu | Istniejące `ValidateIdentityContext`, `RequireOperatingContext`, membership service; nie przyjmować organizacji/kontekstu z query. |
| Kontekst typu `CENTRAL_KITCHEN` lub `RESTAURANT` | Wymagane, jeśli utrzymane typy capability 3A; aktywny membership tej organizacji | Nie tworzyć membershipu ani nie podszywać się pod primary central kitchen. |
| Efektywna rola BOSS | Wymagana dla obu intentów; w przeciwnym razie 403 | BOSS w organizacji, ponowna walidacja aktualnego przypisania. |
| Capability istnieje w zamkniętym katalogu i jest kodowo `READY` | Wymagane; NOT_READY/nieznana definicja nie może dopuścić read | Zmiana readiness wyłącznie przez zatwierdzone wydanie kodu po ukończeniu gate’u z sekcji 8. |
| Stan organizacji `BOSS_RAW_CATALOG_READ` jest jawnie ENABLED | Wymagane; brak wiersza = DISABLED | Nie dziedziczyć ze stanu RAW, z lokalizacji ani od innej organizacji. |
| Intent `list` albo `detail`, kategoria `RAW_SUPPLIERS`, provenance `ORGANIZATION` | Wymagane dokładne dopasowanie | Każdy inny intent/źródło/kategoria/provenance — deny. |
| Globalna kategoria `RAW_SUPPLIERS` wykluczona | 403 dla całej projekcji, listy i detalu | Nie zamieniać na pustą listę; nie używać niezależnego grantu jako obejścia. |
| Lokalizacja lub para location/category wykluczona, a źródło potwierdzone `ORGANIZATION` | Bez wpływu na wspólną definicję | Nie stosować lokalnego wykluczenia bez lokalnego pochodzenia. |
| Niezależny grant RAW obecny/nieobecny | Bez wpływu na BOSS projekcję | Nie aktywuje capability i nie poszerza DTO. Zwykła trasa nadal zachowuje własną readiness/grant ścieżkę. |
| `RAW_MATERIALS` NOT_READY/DISABLED | Bez wpływu na nową projekcję; nie jest warunkiem BOSS | Nie zmieniać readiness/grantów/endpointów RAW; nie podpinać RAW middleware. |
| Stage 1D authorization | Niezależna od BOSS read | Nie łączyć źródeł ani nie przełączać źródła po odmowie BOSS. |
| Brak/mismatch approval, category join, organization scoping | Wiersz wyłączony; detail 404 | Brak fallbacku do working/pending rewizji. |
| Awaria odczytu capability/restrykcji/bazy lub nieznana polityka | Fail closed, ogólny 503 | Nigdy nie mapować awarii na `[]`/brak rekordu success. |
| Zmiana roli/kontekstu/restrykcji współbieżnie | Żądanie musi mieć zdefiniowany punkt serializacji; konflikt/awaria deny | Lock polityki oraz testy; bez zapamiętywania autoryzacji pomiędzy żądaniami. |

## 5. Pochodzenie i reguły danych PostgreSQL

### Fakty zweryfikowane w migracjach i lifecycle

- `raw_materials` jest organizacyjne: `organization_id`, unikalny kod w organizacji i indeks po organizacji/stanie/kodzie. Composite FK wiąże `approved_revision_id` z rewizją przez `(id, raw_material_id, organization_id)`.
- `raw_material_revisions` przechowuje nazwę, `category_id`, `base_unit_code` i `content_hash`; ma FK do materiału i kategorii w tej samej organizacji. `raw_material_revision_approval_events` zawiera organizację, rewizję, `outcome`, `content_hash` i aktora.
- Lifecycle dopuszcza `DRAFT`, `VERIFICATION`, `ACTIVE`, `BLOCKED`, `ARCHIVED`. Migracja tombstone dodaje `deleted_at` wraz z aktorem/operacją i ograniczeniem spójności oraz indeks częściowy dla `deleted_at IS NULL`.
- Trigger ochronny odrzuca zmianę `approved_revision_id`, jeśli nie wskazuje nowego `pending_revision_id` lub brakuje zgodnego zdarzenia `APPROVED` z hashem rewizji. `RawMaterialService::approve()` zapisuje zdarzenie APPROVED i przesuwa pointer w jednej transakcji.
- Edycja aktywnego materiału tworzy working/pending rewizję, ale wskaźnik `approved_revision_id` pozostaje odrębny. Zatem aktywny materiał z nowszą roboczą lub oczekującą rewizją powinien pokazywać dotychczas zatwierdzony pointer — nigdy `COALESCE(working, pending, approved)`.
- `RawMaterialLocationSharePolicy` wymaga zatwierdzenia aktualnej rewizji przez event/hash, a także dowodu zatwierdzenia rewizji nr 1 dla udostępniania lokalizacyjnego. Jej warunek primary-kitchen i share nie jest właściwą bramką dla BOSS katalogu organizacyjnego.
- `category_id` rewizji jest FK do kategorii tej samej organizacji. `category.name` nie jest snapshotem rewizji: kategoria może być przemianowana osobno, a schemat jej archiwizacji dodaje `archived_at`/`archived_by`. Kod archiwizacji kategorii kontroluje potomków, nie wymusza usunięcia ani przepisania zatwierdzonych rewizji.
- `base_unit_code` jest kodem słownika. Migracja jednostek dodaje FK oraz chroni znaczenie kodów referencjonowanych przez historię. DTO nie potrzebuje nazwy jednostki ani konwersji.

### Wymagany predykat odczytu

**[PROPOZYCJA]** Lista i detail powinny bazować na tym samym serwerowym query scope, z jawnymi kolumnami i joinami w tej samej organizacji:

```sql
SELECT
    m.id,
    m.code,
    r.name,
    c.id AS category_id,
    c.name AS category_name,
    r.base_unit_code
FROM raw_materials AS m
JOIN raw_material_revisions AS r
  ON r.id = m.approved_revision_id
 AND r.raw_material_id = m.id
 AND r.organization_id = m.organization_id
JOIN raw_material_categories AS c
  ON c.id = r.category_id
 AND c.organization_id = m.organization_id
WHERE m.organization_id = :authenticated_organization_id
  AND m.lifecycle_state = 'ACTIVE'
  AND m.deleted_at IS NULL
  AND m.approved_revision_id IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM raw_material_revision_approval_events AS e
      WHERE e.organization_id = m.organization_id
        AND e.revision_id = r.id
        AND e.outcome = 'APPROVED'
        AND e.content_hash = r.content_hash
  )
```

`EXISTS` nie mnoży materiałów przy wielu wydarzeniach. Kwerenda rzeczywista może użyć query buildera, ale zachowuje jawny select, wskazane joiny i identyczny warunek dla count/list/detail. Rekord bez pointera, rewizji, kategorii w tej samej organizacji lub zgodnego event/hash jest niewidoczny. Nie próbować „naprawiać” go przez wybór innej rewizji lub dostawcy.

**[WYMÓG]** `ACTIVE` i `deleted_at IS NULL` są obowiązkowe. `BLOCKED`, `ARCHIVED`, `DRAFT`, `VERIFICATION` oraz tombstone są wykluczone. Materiał `ACTIVE` z nowym `working_revision_id` lub `pending_revision_id` nadal prezentuje jedynie `approved_revision_id`. `category_id` zwracany jest z zatwierdzonej rewizji, a nie z filtra/working revision. Nigdy nie odczytywać ani nie sprawdzać `raw_material_supplier_associations` lub supplier tables dla odpowiedzi 3B.

**[DECYZJA — dodatkowy dowód historyczny]** Obecna polityka share wymaga również poprawnego approval event/hash dla rewizji nr 1. Warunek ten należy formalnie potwierdzić jako integralność katalogu organizacyjnego albo ograniczyć projekcję do wymaganego, zgodnego event/hash bieżącej zatwierdzonej rewizji. Nie kopiować bez uzasadnienia wymogu share/primary-kitchen; przed READY muszą być testy obu wariantów i decyzja o tej semantyce.

### Znaczenie nazwy kategorii

**[PROPOZYCJA]** `category.id` bierze się z `r.category_id`, a `category.name` z bieżącego `raw_material_categories.name` w tej samej organizacji. To aktualny label słownika, nie historyczna nazwa zatwierdzona wraz z rewizją; zmiana nazwy kategorii może więc zmienić odpowiedź bez nowej akceptacji materiału. `archived_at` nie jest zwracane.

**[DECYZJA]** Właściciel powinien zatwierdzić, czy aktywny materiał może być pokazany z aktualną nazwą kategorii zarchiwizowanej. Rekomendacja: tak, jeśli FK i organizacja są poprawne, bo lifecycle materiału jest niezależnym kryterium, a archiwizacja kategorii nie jest odwołaniem jej historii; nie ujawniać flagi archiwizacji. Jeśli oczekiwane jest wyłącznie aktywne słownictwo, należy formalnie przyjąć pomijanie rekordów z kategorią archived i przetestować skutek dla materiału ACTIVE.

### Wydajność bez osłabiania kontroli

- Używać indeksu `raw_materials(organization_id, lifecycle_state, code)` i indeksu częściowego tombstone; istnieją indeksy/unikalności rewizji, kategorii i approval history.
- Najpierw nakładać scope organizacji, ACTIVE, soft-delete, pointer i dowód APPROVED; dopiero potem filtrować, liczyć, sortować i stronicować.
- Nie dociągać tabel przez N+1; joiny kategorii i rewizji są ograniczone kluczami organizacji. Approval proof używa `EXISTS`.
- Sprawdzić `EXPLAIN (ANALYZE, BUFFERS)` na syntetycznym, izolowanym PostgreSQL przed dodaniem indeksu. Nowy indeks/migracja nie jest obecnie wymagana przez kontrakt; dodać wyłącznie z wykazanym planem i osobnym przeglądem.
- Count i stronę odczytać przy tej samej restrykcji i tej samej granicy spójności; count nie może ujawniać rekordów poza projekcją.

## 6. Ryzyka i wymagane zabezpieczenia

| Ryzyko | Zabezpieczenie / kryterium odbioru |
|---|---|
| Zmiana istniejącego RAW readiness lub przypadkowe odblokowanie szerokich tras | Nowa grupa i serwis; bez RAW middleware, grantów i modyfikacji readiness. Regresja zwykłego RAW wykazuje niezmienione wyniki przed/po BOSS capability. |
| Użycie istniejącego serializatora lub zapytania RAW | Nowy DTO i query; asercja dokładnych kluczy listy i detalu. Negatywne dane-testowe umieszczają sekretne/wyłączone wartości w modelu i sprawdzają brak ich we wszystkich odpowiedziach oraz błędach. |
| Błędne uznanie working/pending lub powiązań dostawców za zatwierdzone | Zawsze join przez `approved_revision_id`; test aktywnego materiału z pending/working; supplier tables poza SELECT/EXISTS/filtrami/DTO. |
| Cross-organization leak przez join albo `category_id` query | Każdy join uwzględnia `organization_id`; dwa syntetyczne tenanty, obcy UUID w detail i category filter, porównanie 404 i count. |
| Wyliczenie istnienia rekordu przez detail | Autoryzacja powierzchni przed ID; ten sam 404/kod/treść dla foreign/hidden/nonexistent, także soft-deleted i brakujących approval evidence. |
| Ukryte side-channel w search, sort, meta lub błędzie | Wyłącznie pola DTO w filtrach; count po ograniczeniu; brak supplier/finansowych filtrów; walidacja nieznanych kluczy. |
| Globalna restrykcja pominięta albo local exclusion nadmiernie zastosowana | Test globalnego `RAW_SUPPLIERS` deny; organizacyjny provenance z wykluczoną lokalizacją/parą pozostaje widoczny; wprowadzane dane nie zawierają faktów lokalnych. |
| Grant RAW albo Stage 1D staje się fallbackiem | Endpoint sprawdza wyłącznie intent BOSS; testy z BOSS bez RAW grantu, BOSS+RAW grantem, samym RAW grantem oraz Stage 1D. Żadna kombinacja nie poszerza projekcji. |
| Capability disabled/readiness awarii udaje pusty sukces | Brak stanu/NOT_READY/disabled/awaria autoryzacji zwraca 503; nie `200 []`. Brak capability nigdy nie jest aktywowany automatycznie. |
| Wyścig z wycofaniem roli/restrykcji/context | Jawna blokada/snapshot zgodna z używanym mechanizmem organizacji; concurrency test dla restrykcji, membershipu i roli; każda kolejna strona ponownie autoryzuje. |
| Cache zwraca dane poprzedniego BOSS/tenant/policy | `private, no-store`; brak cache odpowiedzi i capability/polityki w 3B. |
| Błędny display category albo niezatwierdzona semantyka archiwizacji | Potwierdzić znaczenie bieżącej nazwy i archived category; test rename/archive obok aktywnego materiału. |

## 7. Decyzje właściciela przed implementacją

1. **Zatwierdzić 3B dokładnie jako organizacyjny katalog pięciu dozwolonych wartości** i potwierdzić, że `RAW_SUPPLIERS` jest właściwą globalną kategorią dla nazwy/kodu/kategorii/jednostki, mimo że żadne dane dostawcy nie są ujawniane.
2. **Zatwierdzić filtry i limity** (`search` code/name do 120 znaków, `category_id`, `unit`, sorty `name|code|category|unit`, strony 25/50/100) albo wskazać inny allowlist. Wszystkie pola spoza DTO pozostają niedopuszczalne.
3. **Zatwierdzić semantykę kategorii:** nazwa jest aktualnym słownikiem, nie snapshotem zatwierdzenia; rozstrzygnąć widoczność aktywnego materiału, gdy jego kategoria została zarchiwizowana.
4. **Rozstrzygnąć wymóg rewizji nr 1:** czy pozytywna projekcja wymaga tylko zgodnego approval event/hash bieżącej rewizji, czy również dowodu zatwierdzenia pierwszej rewizji, jak w lokalnym share policy.
5. **Potwierdzić operacyjny kontekst** `CENTRAL_KITCHEN` i `RESTAURANT`, zgodny z definicją 3A, oraz akceptację sekwencjonowania read względem zmian ról/membershipów/restrykcji.
6. **Potwierdzić czy checkpoint 3B obejmuje UI**. Zrewidowany plan proponuje oddzielny widok BOSS; UI nie może używać zwykłego klienta RAW, nie może fallbackować po 403/503 i powinien usuwać dane po zmianie użytkownika/kontekstu. UI nie zmienia kontraktu backendu ani readiness; jeśli wchodzi do odbioru, wymaga osobnego zakresu i testów przeglądarkowych.
7. Potwierdzić dokładne mapowanie błędu capability niedostępnej/wyłączonej na ogólny 503 oraz bezpieczne kody API, zgodnie z nieujawnianiem różnic tenant/rejestru.

Do czasu tych decyzji wszystkie szczegóły oznaczone jako propozycja pozostają propozycją, a `BOSS_RAW_CATALOG_READ` pozostaje NOT_READY/DISABLED.

## 8. Plan implementacji i weryfikacji

### Proponowane pliki i odpowiedzialności

**Minimalne zmiany w istniejących komponentach**

- `backend/app/Domain/Authorization/BusinessVisibilitySurface.php`: dodać typowany BOSS intent dla list i detail; nie reużywać RAW permission.
- `backend/app/Domain/Authorization/BusinessVisibility.php`: autoryzować nowe intenty w ścieżce BOSS z aktualną rolą, readiness/activation, kategorią i provenance organizacyjnym; zachować istniejące ścieżki BOSS/Stage 1D/RAW.
- `backend/app/Domain/Authorization/BossLocationRestrictions.php`: preferować istniejące `denies()` dla kategorii/provenance organization; nie dodawać lokalizacyjnego filtra do definicji globalnej.
- `backend/app/Domain/Modules/OrganizationCapabilityService.php` lub nowy wąski reader `OrganizationCapabilityAccess`: dostarczyć sprawdzenie read-only dla bieżącego aktora/organizacji/operacji; nie używać administratorowego `status()` jako autoryzacji odczytu.
- `backend/routes/api.php`: dodać wyłącznie dwie GET trasy do osobnej grupy BOSS, bez `RequireRawMaterialsAvailability`.

**Nowe elementy backendu**

- `backend/app/Http/Requests/ListBossRawMaterialsRequest.php`: ścisły allowlist, walidacja i normalizacja parametrów listy.
- `backend/app/Http/Controllers/Api/V1/BossRawMaterialCatalogController.php`: cienki kontroler list/detail, rzeczywisty aktor/kontekst z middleware, no-store.
- `backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php` (nazwa do ustalenia): wspólny, ograniczony query scope dla list/detail/count.
- Jawny `BossRawMaterialCatalogItem` DTO/projection: buduje dokładnie sześć wartości dozwolonych (w tym dwa zagnieżdżone elementy kategorii), bez model serialization.
- Nowy, wąski testowany predykat provenance/approval tylko jeśli wydzielenie z `RawMaterialLocationSharePolicy` okaże się uzasadnione. Nie przenosić wymogów share/primary-kitchen bez jawnej decyzji.

**Testy**

- `backend/tests/Feature/BossRawMaterialCatalogApiTest.php`: kontrakt odpowiedzi, trasy/middleware, statusy, filtr/list/detail, no-store, brak tras mutacji.
- `backend/tests/Feature/BossRawMaterialCatalogAuthorizationTest.php`: identity, organization/context membership, BOSS role, disabled/readiness, category restriction, global/category-location exclusions, osobne źródła uprawnień.
- `backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php`: ACTIVE/soft-delete, zgodność pointer/revision/material/organization/category, approval event/hash, pending i working rewizje, uszkodzone/brakujące dowody.
- Testy powinny korzystać z istniejącego test harnessu PostgreSQL i syntetycznych organizacji. Wstrzyknięcie READY/ENABLED wyłącznie przez testowy provider/fixtures; bez zmiany kodowej readiness w produkcji lub aktywacji organizacji.

### Baza danych i brak migracji

**[PROPOZYCJA]** Dla pięciu pól 3B nie jest potrzebna migracja: istnieją materiały, zatwierdzone rewizje, approval events, kategorie i jednostki. Nie zmieniać ani nie dopisywać danych w migracjach RAW. Nowy indeks rozważyć dopiero po analizie planu query w izolowanym środowisku. Supplier associations, supplier revisions i ich approval snapshots są poza 3B; nie dodawać PA-07B.3C.

### Izolowana strategia testowa i regresja

1. **Po zgodzie na implementację:** migracje/testy tylko w dedykowanym, izolowanym PostgreSQL; syntetyczne organizacje i aktorzy. Nie używać primary lokalnej bazy, STAGING ani produkcji. Nie zmieniać trwałego stanu capability poza fixture testowym.
2. **Pozytywne przypadki:** BOSS z rzeczywistym aktywnym membershipem, capability gotową i jawnie włączoną, `RAW_MATERIALS` nadal NOT_READY, materiał ACTIVE z approval evidence; odpowiedź zawiera dokładnie pola kontraktu.
3. **Odmowy:** brak/nieaktywna tożsamość, organizacja/kontekst/membership, BOSS, capability, state row; RAW-only grant; Stage 1D-only; globalna restrykcja; nieznane provenance. Brak stanu/NOT_READY nie może zwracać pustego 200.
4. **Izolacja tenantów i detail:** foreign, hidden, nieistniejący, deleted, nieaktywny, brak pointera, obca/niezgodna rewizja/kategoria oraz brak/mismatch approval event/hash dają identyczne 404 dla detail. Potwierdzić, że żaden join ani count nie obejmuje drugiej organizacji.
5. **Lifecycle:** testować `DRAFT`, `VERIFICATION`, `BLOCKED`, `ARCHIVED`, tombstone jako niewidoczne; aktywny materiał z working/pending nadal pokazuje poprzednio zatwierdzoną rewizję, a po prawidłowym zatwierdzeniu nową.
6. **Data leakage:** dokładne asercje allowlisty dla list i detail; niebezpieczne wartości w opisie, aliasie, alergennym, supplier association, cenie, dokumencie i audycie nie występują w body/meta/błędach ani wpływie wyszukiwania/sortowania/count.
7. **Filtrowanie/paginacja:** escape znaków specjalnych, sort rosnący/malejący, tie-break ID, filtry category/unit, nieznane parametry 422, granice `per_page`, count po filtrach i stabilny wynik pod równymi sort key.
8. **Współbieżność:** w trakcie list/detail spróbować wycofać rolę, zmienić membership, restrykcję i capability. Potwierdzić wybrany punkt serializacji i brak odpowiedzi, która używa starej polityki po jej zatwierdzonej zmianie.
9. **Regresja:** uruchomić testy restrykcji BOSS/role removal-regrant, Stage 1D, `RawMaterialCapabilitiesApiTest`, zwykłe RAW API/auth/readiness, lifecycle/revisions, a następnie pełne backend test suite i lint/Pint. Jeśli UI zatwierdzone: oddzielne klient/component tests, build/lint i browser na izolowanym preview.

### Checkpointy

1. Właściciel zatwierdza decyzje z sekcji 7 i wyznacza zakres API-only lub API+UI.
2. Implementacja authorization intent/readiness i isolated unit/feature tests; żadnego BOSS DTO, dopóki gate autoryzacji nie przechodzi negatywnych przypadków.
3. Implementacja wspólnego query/provenance i jawnego DTO; migracja tylko po wykazaniu konieczności.
4. Dodanie dwóch GET tras, błędów, no-store i tests cross-tenant/foreign-hidden-nonexistent.
5. Pełna regresja Stage 1D, zwykłego RAW i BOSS visibility; sprawdzenie planu query.
6. Osobny przegląd akceptacyjny PA-07B.3B. Dopiero po odbiorze wszystkich warunków może powstać zmiana kodowa `ready: true`; aktywacja organizacji pozostaje osobną, jawnie administratorową decyzją i DISABLED domyślnie.

## 9. Readiness decision i rekomendacja końcowa

**PA-07B.3A pozostaje niezmienione.** W aktualnym repozytorium capability jest kodowo `NOT_READY`; brak stanu organizacji oznacza `DISABLED`. Tego raportu nie należy interpretować jako zmiany gotowości ani aktywacji.

Przed `READY` kod musi zawierać i przejść odbiór:

1. dedykowane, rozdzielone list/detail BOSS intents i brak autoryzacyjnego fallbacku;
2. wszystkie middleware, membership, efektywną rolę BOSS, organization-scoped capability READY+ENABLED oraz globalną kategorię restrykcji;
3. źródłowo zweryfikowaną projekcję `ORGANIZATION`, aktywny lifecycle, soft-delete, zatwierdzony pointer, spójność org/material/revision/category i matching approval event/hash;
4. ścisły, wspólny DTO list/detail, allowlist parametrów, deterministyczne sortowanie/paginację, prywatne no-store i rozdzielenie 403/404/503;
5. negatywne testy identity, membership, role revocation, cross-organization, disabled/not-ready, unknown provenance, hidden IDs, approval mismatch, dane niepożądane oraz współbieżną zmianę polityki;
6. dowody regresji, że ordinary RAW readiness/grants/endpoints, Stage 1D oraz PA-07B.2 restrykcje zachowują dotychczasowe zachowanie; BOSS capability nie zależy od `RAW_MATERIALS` i independent RAW grant jej nie rozszerza.

**Werdykt:** rekomenduję osobną, organization-scoped projekcję BOSS zgodną z powyższym kontraktem, bez migracji i bez zależności od zwykłego RAW, pod warunkiem decyzji właściciela z sekcji 7. Nie wdrażać ani nie oznaczać capability READY przed implementacją i weryfikacją całego read path. Jawna aktywacja każdej organizacji pozostaje wyłączona domyślnie; BOSS, Stage 1D i niezależne RAW pozostają odrębnymi źródłami autoryzacji. PA-07B.3C nie jest częścią tej rekomendacji ani planu 3B.
