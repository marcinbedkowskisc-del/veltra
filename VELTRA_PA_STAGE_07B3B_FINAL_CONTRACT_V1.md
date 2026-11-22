# VELTRA 2.0 — PA-07B.3B — finalny kontrakt architektury v1

**Status projektu:** READY FOR IMPLEMENTATION  
**Zakres:** backend API only; projekt i kontrakt, bez implementacji  
**Gotowość kodowa:** `BOSS_RAW_CATALOG_READ = NOT_READY`  
**Aktywacja organizacji:** brak stanu oznacza `DISABLED`; żadna organizacja nie jest włączana tym kontraktem  
**Poza zakresem:** UI, PA-07B.3C, dane/działanie dostawców, migracja, deploy, aktywacja.

## 1. Zatwierdzone decyzje właściciela

Właściciel zatwierdził kierunek architektury i poniższe kontrakty. Są one wiążącym zakresem implementacji 3B:

1. **Osobna projekcja BOSS:** niezależny, organization-scoped odczyt katalogu RAW. BOSS visibility category to `RAW_SUPPLIERS`; ta kategoria nie uprawnia do ujawnienia danych dostawców.
2. **Allowlista odpowiedzi:** dokładnie `id`, `code`, `name`, `category.id`, `category.name`, `base_unit_code`. Żadne dodatkowe biznesowe pola.
3. **Lista:** `search` maks. 120 znaków tylko po widocznych `name`/`code`; opcjonalne `category_id` UUID i `unit` base-unit code; `sort=name|code|category|unit`; `direction=asc|desc`; `page >= 1`; `per_page=25|50|100`. Domyślne: `name`, `asc`, `1`, `25`. Nieznane klucze i wartości są odrzucane. Sortowanie ma stabilny tie-break po ID. Filtry, wyszukiwanie, sortowanie i count działają wyłącznie na autoryzowanej, zatwierdzonej projekcji.
4. **Kategoria:** `category.name` to bieżąca nazwa słownika kategorii w tej samej organizacji, nie zatwierdzony historyczny snapshot. Materiał `ACTIVE` pozostaje widoczny, gdy wskazana kategoria jest zarchiwizowana, jeśli referencja i organizacja są poprawne. Nie zwracać metadanych archiwizacji.
5. **Approval provenance:** wymagany zgodny event `APPROVED` z content hash bieżącej rewizji `approved_revision_id`. Nie wymagać dodatkowo dowodu zatwierdzenia rewizji nr 1 tylko dlatego, że osobna polityka location share ma taki wymóg. Nigdy nie używać working/pending jako fallbacku.
6. **Backend only:** nie dodawać frontendu, nawigacji, klienta, UI ani testów przeglądarkowych. Każde UI wymaga osobnej zgody.
7. **Dostępność:** `503 BOSS_RAW_CATALOG_UNAVAILABLE` dla braku gotowości, braku/wyłączenia capability lub niedostępności wymaganej zależności read. Nie ujawniać konfiguracji innej organizacji. Zachować bezpieczne zachowanie `401/403/404/422/405` oraz `Cache-Control: private, no-store` również przy błędach middleware i exception handlera.

Pozostają wiążące granice: brak zależności od `RAW_MATERIALS`; bez zmian RAW readiness, grantów i zwykłych endpointów; prawdziwa aktywna tożsamość/kontekst/membership, rola BOSS, gotowość kodu, jawna aktywacja organizacji, restrykcje BOSS i zweryfikowane pochodzenie organizacyjne. Nie łączyć źródeł BOSS, Stage 1D i niezależnego RAW. Nie dodawać funkcji 3C.

## 2. Baseline i materiały źródłowe

### Zweryfikowany stan

- Worktree: `/home/veltra/projekty/veltra-pa-07b3a`
- Branch: `pa-07b3a-readiness-contract`
- HEAD: `b47085e17810fea5d659b3245d04c542608f07d6`
- Baseline odpowiada wartościom wymaganym w zleceniu.
- Istniejące nieśledzone raporty, w tym `VELTRA_PA_STAGE_07B3B_ARCHITECTURE_REVIEW.md`, oraz pięć lokalnych raportów PA-07B.3A zachowano bez zmian.
- Oryginalny worktree `/home/veltra/projekty/veltra` nie był modyfikowany. Testów, migracji ani aktywnego połączenia z bazą nie uruchamiano.

### Zweryfikowane źródła

Kontrakt 3B i jego podstawy sprawdzono w:

- `VELTRA_PA_STAGE_07B3B_ARCHITECTURE_REVIEW.md`
- `VELTRA_PA_STAGE_07B3A_SCHEMA_CONTRACT_REVIEW.md`, `VELTRA_PA_STAGE_07B3A_APPROVAL_ADDENDUM.md`, `VELTRA_PA_STAGE_07B3A_OWNER_ACCEPTANCE.md`
- `backend/app/Domain/Authorization/RoleAdministration.php`, `PersonnelAdministration.php`, `SecurityMutation.php`, `AuthorizationMutationContext.php`, `AuthorizationMutationScope.php`, `BusinessAuthorization.php`, `BossLocationRestrictions.php`, `BusinessVisibility.php`
- `backend/app/Domain/Identity/MembershipService.php`, `IdentityService.php`
- `backend/app/Domain/Modules/OrganizationCapabilityService.php`, `CodeOrganizationCapabilityCatalog.php`
- `backend/app/Http/Controllers/Api/V1/SecurityController.php`, `AuthenticationController.php`, `BossLocationController.php`
- `backend/app/Http/Middleware/ValidateIdentityContext.php`, `RequireOperatingContext.php`, `AuditSecurityRejection.php`
- `backend/bootstrap/app.php`, `backend/routes/api.php`
- `backend/database/migrations/2026_11_02_000001_create_installation_structure.php`, `000002_create_user_identity_and_memberships.php`, `000003_create_business_role_scopes.php`, `000004_protect_administrators_and_security_history.php`, `000006_create_permission_grants.php`, `000009_preserve_usable_administrator_context.php`
- Migracje `2026_11_19_000054_create_boss_location_restriction_revisions.php`, `2026_11_19_000055_extend_boss_visibility_policy.php`, `2026_11_20_000056_create_organization_capability_state.php`
- `backend/tests/Feature/OrganizationCapabilityConcurrencyTest.php`, `AdministratorConcurrencyTest.php`, `BossLocationRestrictionsTest.php` oraz `backend/tests/Support/concurrent_organization_capability_configure.php`.

## 3. Zweryfikowana autoryzacja i analiza współbieżności

### Wniosek

**Współdzielona blokada wiersza `organizations` jest wystarczającą granicą serializacji dla obecnych, właściwych ścieżek zmian roli, membershipu, restrykcji BOSS i stanu capability — pod warunkiem, że odczyt BOSS pobiera ją jako pierwszą blokadę i trzyma do materializacji wyniku.** Wynika to z konkretnych ścieżek aplikacji i triggerów DB opisanych niżej, nie tylko z obecności `SecurityMutation`.

To nie jest gwarancja anulowania odpowiedzi, która już przeszła autoryzację przed commitem revocation. Obowiązuje precyzyjna gwarancja linearizacji w sekcji 3.6. Przełączenie wybranego operating context jest stanem sesji, nie transakcją bazy.

### 3.1 BOSS role assignment i revocation

**Aplikacja:**

- `SecurityController::grantRole()` i `revokeRole()` delegują do `RoleAdministration::grant()` / `revoke()`.
- Obie ścieżki zapisują przez `SecurityMutation::run()`. W `SecurityMutation::run()` `AuthorizationMutationContext` otwiera podpisaną transakcję; przed operacją blokowany jest wiersz organizacji `FOR UPDATE`. Autoryzacja jest sprawdzana w callbacku, po blokadzie. Następnie tworzona/odwoływana jest rola, a security event dołączany jest do tej transakcji.
- BOSS jest organizacyjną rolą (`location_id` i `membership_id` NULL); jej assignment nie zastępuje wymogu aktualnego operating membership.
- `RoleAdministration::bootstrapInitial()` blokuje organizację `FOR UPDATE` przed użytkownikiem i utworzeniem roli Administratora. Nie jest ścieżką nadawania BOSS, lecz zachowuje kolejność locków.
- `setAccountActive()` i zmiana email również przechodzą przez `SecurityMutation::run()` i blokują organizację przed aktualizacją użytkownika; wpływają na możliwość autoryzacji aktora.

**PostgreSQL:**

- Migracja `000003` dodaje trigger `roles_serialize` na INSERT/UPDATE/DELETE `role_assignments`.
- Funkcja `serialize_installation_mutation()` z migracji `000001` wykonuje `SELECT ... FROM organizations ... FOR UPDATE`, używając `OLD.organization_id` przy DELETE i `NEW.organization_id` przy pozostałych operacjach.
- Zmiana membershipu automatycznie odwołuje powiązane role w triggerze z migracji `000006`; zmiana membershipu już ma blokadę organizacji.
- Trigger podpisanego scope `role_assignments_ticket_semantics` z migracji `000024` wiąże insert/revocation z aktorem i konkretnym scope.

**Wynik:** grant/revoke BOSS serializuje się na wierszu organizacji zarówno w serwisie, jak i w DB triggerze. `RoleAssignment::effective()` nie blokuje wierszy; nowy read nie powinien dodawać `FOR UPDATE` na rolach.

### 3.2 Zmiany organizacji i operating membership

**Aplikacja:**

- `PersonnelAdministration::assignMembership()`, `removeMembership()` oraz `decide()` przechodzą przez `SecurityMutation::run()` — organizacja jest blokowana przed zmianą.
- `MembershipService::assign()` blokuje organizację, następnie odświeża user/location i weryfikuje aktywność oraz organizację przed insertem.
- `MembershipService::deactivate()` pobiera membership bez locka, następnie blokuje jego organizację, odświeża membership po blokadzie i dokonuje jedynie aktywnej → nieaktywnej zmiany.
- `removeMembership()` odwołuje role związane z membershipem, a potem deaktywuje membership; cała ścieżka pozostaje w zewnętrznej transakcji z blokadą organizacji.
- `requestPersonnel()`/membership approval mogą wywoływać assign i role grant z wnętrza operacji SecurityMutation; transakcja zewnętrzna już posiada ten lock.
- `IdentityService` i `RoleAdministration` blokują organizację przy zmianach stanu konta/identity; migracje dodatkowo serializują INSERT/UPDATE/DELETE `users`.

**PostgreSQL:**

- Migracja `000004` dodaje `memberships_serialize` i `users_serialize`; oba używają `serialize_installation_mutation()` i blokują organizację `FOR UPDATE`.
- Trigger `membership_authorization_revocation` z migracji `000006` odwołuje powiązane role i uprawnienia przy deaktywacji; zachodzi w transakcji aktualizującej membership.
- Migracja `000002` zabrania usuwania historii i reaktywacji wiersza membershipu. Nowe membershipy otrzymują nowe ID.

**Operating-context selection:**

- `AuthenticationController::selectLocation()` waliduje membership przez `MembershipService::available()` i zapisuje `operating_membership_id` w sesji.
- `ValidateIdentityContext` odczytuje ID z sesji i przy każdym żądaniu ponownie sprawdza membership oraz lokalizację; nieważny/revoked kontekst odrzuca. Middleware przekazuje operating location jako request attribute.
- Wybór kontekstu jest aktualizacją sesji, nie wiersza organizacji i nie jest blokowany `FOR UPDATE`. Jest wejściem pojedynczego żądania, a nie globalnym uprawnieniem.

**Wniosek:** rewalidacja w serwisie BOSS po uzyskaniu locka musi odtworzyć dokładnie membership wskazany przez bieżące żądanie/sesję, a nie tylko znaleźć „jakikolwiek aktywny membership” tej lokalizacji. W przeciwnym razie regrant membershipu mógłby nieprawidłowo ożywić starą sesyjną referencję.

### 3.3 BOSS category/location restriction updates

- `BossLocationController::updateRestrictions()` deleguje do `BossLocationRestrictions::replace()`.
- `replace()` używa `SecurityMutation::run(..., 'boss.location_restrictions', ...)`, więc aktualna ścieżka aplikacji blokuje organizację `FOR UPDATE` przed rewalidacją administratora, odczytem wersji i insertem nowej rewizji.
- Tabela `boss_location_restriction_revisions` jest append-only i dostęp runtime ograniczony do SELECT/INSERT. Triggery `000054/000055` sprawdzają podpisany scope/aktora, schemat, poprzednią rewizję, numer wersji i integralność wartości.
- **Ważne ograniczenie DB:** `validate_boss_visibility_revision()` nie pobiera samodzielnie blokady organizacji. Aktualna serializacja tej tabeli wynika z jedynej ścieżki zapisu aplikacyjnego przez `SecurityMutation::run()`, a nie z bezwarunkowej blokady w triggerze restrykcji. Wyszukiwanie call sites wykazało zapis produkcyjny w `BossLocationRestrictions::replace()`; pozostałe wystąpienia to migracje/testy.

**Ocena kompatybilności:** dla obecnego kontraktu aplikacji blokada jest wystarczająca. Przyszły zapis restrykcji nie może wywoływać DML z podpisanym ticketem bez wcześniejszego `FOR UPDATE` na organizacji. Jeśli pojawi się niezależna ścieżka zapisu, najmniejszym dodatkowym hardeningiem jest BEFORE INSERT trigger na revisions, który blokuje wiersz `organizations` dla `NEW.organization_id FOR UPDATE`; nie jest to wymagane w 3B i nie uzasadnia migracji w tym kontrakcie.

### 3.4 Capability enable/disable

- `OrganizationCapabilityService::configure()` uruchamia podpisaną transakcję, a w transakcji organizacji pobiera `organizations ... FOR UPDATE` jako pierwszy lock, świeżo weryfikuje aktora/kontekst, blokuje stan capability i dopiero zapisuje audyt oraz stan.
- Trigger `guard_organization_capability_state()` z migracji `000056` sam wykonuje `SELECT ... FOR UPDATE` na organizacji. Stan i event są ograniczone podpisanym scope i audytem append-only.
- Administratorowe `status()`/`history()` są read-only; nowy endpoint nie może używać administratorowego `status()` jako autoryzacji danych.

**Wynik:** deaktywacja capability serializuje się z nowym readem na tym samym wierszu organizacji. Brak wiersza pozostaje DISABLED.

### 3.5 Czy `FOR SHARE` wystarcza? Izolacja, kolejność i ryzyko deadlocku

**Zalecana granica nowego readu:**

1. Po middleware, rozpocząć krótką transakcję PostgreSQL `READ COMMITTED`.
2. Pierwszą operacją DB wewnątrz niej pobrać świeżą organizację aktora z `SELECT ... FOR SHARE`. Nie odczytywać uprzednio roli/capability/restrykcji w transakcji i nie używać cache decyzji.
3. Po uzyskaniu locka odświeżyć użytkownika, auth/identity version, aktywną organizację i dokładny `operating_membership_id` bieżącego żądania; zweryfikować aktywny membership, location, organization i dozwolony typ kontekstu.
4. Następnie odczytać efektywną rolę BOSS, code readiness i organization capability state, aktualną rewizję restrykcji, a następnie wykonać query/count i zmaterializować DTO. Nie używać `FOR UPDATE` na wierszach roli/membershipu/materiału.
5. Zatwierdzić transakcję po materializacji wyników, nie trzymać locka podczas JSON/network response.

W PostgreSQL `FOR SHARE` jest konfliktem dla istniejących writer locków `FOR UPDATE`, ale współdzieli się z innymi readerami `FOR SHARE`. Gdy write zdążył pierwszy, reader czeka, a następne READ COMMITTED statements widzą commit. Gdy reader zdobędzie blokadę jako pierwszy, aktualizacje autoryzacyjne czekają do zakończenia read transaction. Lock ten obejmuje jednocześnie role, membership/aktywny kontekst, capability oraz aktualny serwis restrykcji. Aktualne ścieżki RAW/category mutate też blokują organizację, więc count/page/read danych nie zmieni się przez te serwisy w trakcie tej transakcji.

**Dlaczego nie wystarczy sam `REPEATABLE READ`:** stały snapshot gwarantuje spójność zapytań, ale sam nie czeka na revocation i może zwrócić sukces z autoryzacji sprzed commitu revocation, gdy ten commit nastąpi przed końcem odpowiedzi. Sam `READ COMMITTED` bez locka pozwala natomiast na różne wersje polityki między zapytaniami. `FOR SHARE` jako pierwszy lock daje serializację względem obecnych writerów; READ COMMITTED wystarcza pod warunkiem braku writerów omijających wskazaną granicę.

**Deadlock/lock order:**

- Standardowe app write paths biorą lock organizacji przed walidacją i zmianą roli/membershipu/polityki/capability, potem operują na wierszach podrzędnych. Reader pobiera wyłącznie lock organizacji, wykonuje nieblokujące SELECT-y i nie bierze niższych row locks. Nie tworzy cyklu locka z tymi ścieżkami.
- DB serialization triggers ról/membershipów mogą otrzymać row tuple lock przed próbą triggerowego locka organizacji przy bezpośrednim DML. Reader nie blokuje tych tuple’ów, więc może odczytać poprzednią zatwierdzoną wersję przez MVCC i nie tworzy odwrotnego wait cycle. Rekomendowany kod aplikacji nadal zawsze bierze lock organizacji pierwszy.
- Nie wykonywać w read transaction DML, audytowego `SecurityMutation`, row locking ani wywołań usług, które biorą dodatkowe locki. Obsłużyć deadlock/lock timeout jako 503 `BOSS_RAW_CATALOG_UNAVAILABLE`, logując wyłącznie bezpieczny SQLSTATE/operation context bez treści danych.
- Organizacyjny lock obejmuje wszystkie read requests współdzielonym lockiem, lecz blokuje write flows tej organizacji do końca count/query. Ograniczyć transakcję do autoryzacji i materializacji, mieć limit strony 100, indeksować/analizować plan zapytania i użyć skonfigurowanych limitów czasu DB.

### 3.6 Dokładna gwarancja linearizacji

**Gwarancja:** autoryzacja BOSS i wynik query są jednym transakcyjnym odczytem uporządkowanym względem każdej wymienionej zmiany, która stosuje organization `FOR UPDATE`. Jeżeli reader zdobędzie `FOR SHARE` pierwszy, jego decyzja/query linearyzuje się przed commitem późniejszego revocation/change; writer czeka. Jeżeli writer zdobędzie `FOR UPDATE` i commitnie pierwszy, reader czeka, odczytuje nowy stan i deny’uje po revocation/disabled/global-category restriction. Nowe żądanie po commicie zmiany nie używa starej decyzji.

**Nie gwarantuje:** cofnięcia lub niewysłania odpowiedzi już autoryzowanej przed commitem revocation, jeżeli serializacja HTTP trwa dłużej; wycofania danych już dostarczonych; atomowości z aktualizacją `operating_membership_id` w sesji. Kontekst żądania jest membership ID załadowanym i zweryfikowanym na początku żądania, a jego aktywność jest ponownie sprawdzana po locku. Zmiana wyboru kontekstu w równoległym żądaniu obowiązuje dla kolejnych żądań, nie retroaktywnie dla żądania już rozpoczętego. Zmiana aktywności/revocation membershipu jest DB write i serializuje się.

Nie nazywać tego „immediate revocation” ani pełną serializowalnością; jest to serializacja autoryzacji/read z commitami na bieżących writer paths.

### 3.7 Deterministyczne testy współbieżności

Wykorzystać wzorzec z `OrganizationCapabilityConcurrencyTest` i `AdministratorConcurrencyTest`: osobne procesy/połączenia PostgreSQL, oznaczenie `application_name`, bariera przez `pg_stat_activity.wait_event_type='Lock'`, jawny commit/rollback i kontrola odpowiedzi po zwolnieniu locka.

Wymagane dwa porządki:

1. **Reader pierwszy:** proces testowy zaczyna transakcję read, pobiera `FOR SHARE`, sygnalizuje barierę i utrzymuje lock do materializacji. Writer przez rzeczywistą usługę/endpoint próbuje kolejno revoke BOSS, usunąć operating membership, dodać globalne `RAW_SUPPLIERS` restriction albo wyłączyć capability. Potwierdzić, że writer czeka; reader kończy dozwolony wynik, writer następnie commitnie, a następne żądanie dostaje 403 lub 503 zgodnie ze zmianą.
2. **Writer pierwszy:** proces writer rozpoczyna operację przez rzeczywistą usługę, uzyskuje `FOR UPDATE`, dokonuje zmiany i zatrzymuje się przed commitem. Reader żąda katalogu i musi czekać. Po commit reader musi otrzymać deny, nigdy odpowiedź autoryzowaną ze starej roli/polityki/stanu. W wariancie rollback reader po zwolnieniu locka może uzyskać wynik zgodny ze stanem sprzed write.

Testy muszą obserwować rzeczywiste worker transactions, a nie tylko ręcznie założone locki w jednym procesie. Powtórzyć dla BOSS role revoke, exact context membership deactivate, category restriction i capability disable. Dla selection context testować, że session ID jest wejściem requestu i że revocation starego membership ID nie jest ożywiane przez nowy membership do tej samej location.

## 4. Finalny API i data contract

### 4.1 Dokładnie dwie trasy

```text
GET /api/v1/boss/raw-materials
GET /api/v1/boss/raw-materials/{material}   // UUID constraint
```

Żadnych POST/PATCH/PUT/DELETE, exportów, dokumentów, supplier endpoints ani wspólnego routera do zwykłego RAW.

### 4.2 Middleware i kolejność autoryzacji

Użyć istniejącej sesji i bezpieczeństwa API: `RequireSpaSession`, `auth:sanctum`, `ValidateIdentityContext`, `AuditSecurityRejection`, `RequireOperatingContext` oraz limitu GET. Dodać no-store middleware dla odpowiedzi sukcesu. **Nie** podłączać `RequireRawMaterialsAvailability`.

Wewnątrz transakcji realizować kolejno:

1. Organizacyjny `FOR SHARE` jako pierwsza operacja DB w transakcji.
2. Rewalidacja aktywnej, zweryfikowanej tożsamości, auth version, aktywnej organizacji, rzeczywistego kontekstu i dokładnego session membership ID.
3. Efektywna organizacyjna rola BOSS; brak roli/kontekstu to 403, bez odczytywania ID materiału.
4. Kodowa definicja zamkniętego `BOSS_RAW_CATALOG_READ` istnieje, `ready === true`, operacja jest dozwolona, a wiersz stanu tej organizacji jest `enabled`. Brak/false/awaria odczytu: 503 `BOSS_RAW_CATALOG_UNAVAILABLE`.
5. Wykonanie dedykowanego, typowanego `BusinessVisibility` BOSS intentu dla listy lub detalu z kategorią `[RAW_SUPPLIERS]`, provenance `ORGANIZATION`, bez location target.
6. Wspólny query scope i DTO; commit po materializacji.

Nie odczytywać readiness `RAW_MATERIALS`, RAW grants, Stage 1D grants ani nie używać `BusinessAuthorization::allows()` jako fallbacku. Jeżeli `BusinessVisibility` nie ma dokładnego intentu BOSS, odmowa; nie wywnioskować prawa z `Permission::LocationView`, `RawMaterialPermission::View` ani innego istniejącego permission.

### 4.3 Typowane intent-y widoczności

Dodać osobny enum/typ `BossRawCatalogIntent` z dokładnie dwoma wartościami: `List` i `Detail`. `BusinessVisibilitySurface` ma przekazywać ten typ, nie pseudo-permission; `BusinessVisibility` przyjmuje go tylko w źródle `Boss`. Zmapować list/detail do capability operation `list`/`detail`, kategorii `RAW_SUPPLIERS` oraz provenance `ORGANIZATION`.

Odrzucić kombinacje niezgodne: `Stage1d`/`RawOrganization`, nieznany intent, brak capability, dodatkowa kategoria, `Unknown`/location/derived provenance, location target lub zły operation. Zachować istniejące `BusinessVisibilitySource` i zachowanie wszystkich dotychczasowych surface.

Globalne wykluczenie `RAW_SUPPLIERS` daje 403. Wykluczenie lokalizacji/pary location-category nie ukrywa faktu `ORGANIZATION` i nie służy do filtrowania lokalnych share. Pozostałe źródła authorization nie są łączone.

### 4.4 Wspólny approved-data query scope

Lista, count i detail używają tej samej bazowej query/projection. Minimalny kształt relacji:

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
WHERE m.organization_id = :fresh_authenticated_organization_id
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

Join musi wiązać materiał, rewizję i kategorię po ID **oraz** organization ID; approved revision również musi należeć do materiału. `EXISTS` approval nie mnoży rekordów. `category.name` pobiera aktualny słownik w tej samej organizacji i nie raportuje archived state. Nie wymagać pierwszej rewizji approval. Brak/mismatch pointer, revision, organization, category FK/join lub bieżącego approval/hash wyklucza rekord. Nigdy nie fallbackować do working/pending; nie czytać supplier association.

Materiał `ACTIVE` z nowym working/pending revision nadal zwraca zatwierdzone `approved_revision_id`. `DRAFT`, `VERIFICATION`, `BLOCKED`, `ARCHIVED` i `deleted_at IS NOT NULL` są wykluczone. Soft-deleted, obcy, ukryty i nieistniejący detail identyfikator daje tę samą odpowiedź 404.

Jawny select kolumn; bez `SELECT *`, bez istniejących RAW query/DTO i bez model serialization. Filtry, search, count, sorting i pagination nakładają się dopiero na ten approved-data scope.

### 4.5 DTO i odpowiedzi

Każdy rekord ma dokładnie następujący kształt:

```json
{
  "id": "uuid",
  "code": "SUR-000001",
  "name": "Nazwa bieżącej zatwierdzonej rewizji",
  "category": {
    "id": "uuid",
    "name": "Aktualna nazwa słownika organizacji"
  },
  "base_unit_code": "kg"
}
```

Lista:

```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 0,
    "last_page": 1
  }
}
```

Detail zwraca ten sam obiekt pod `data`, bez `meta`. `meta` nie jest polem biznesowym rekordu. W DTO nie ma innych kluczy.

Zakazane wszędzie w 3B, także w filtrach, sortach, meta, błędach i obliczeniach: supplier IDs/names/associations, ceny/koszty/finanse, shares/lokalizacje/liczniki udziałów, opisy, aliasy, SKU, dokumenty/pliki, alergeny, historia/audyt, rewizje/statusy/wersjonowanie, ilości/konwersje, flagi archiwizacji i arbitralne dane modelu.

### 4.6 Filtry, search, sort i paginacja

| Parametr | Wartości |
|---|---|
| `search` | 0–120 znaków; tylko `name` i `code`; uciec `%`, `_`, `\\` jako literalne znaki. |
| `category_id` | Opcjonalny UUID; filtr po dołączonej kategorii z tego samego organization scope. |
| `unit` | Opcjonalny kod `base_unit_code`; filtr wyłącznie na tym polu. |
| `sort` | `name`, `code`, `category`, `unit`; domyślnie `name`. |
| `direction` | `asc`, `desc`; domyślnie `asc`. |
| `page` | Integer >= 1; domyślnie 1. |
| `per_page` | 25, 50, 100; domyślnie 25. |

Odrzucać nieznane klucze, niedozwolone sorty i wartości jako 422. `supplier_id`, `lifecycle_state`, `revision_id`, aliasy, status category, `include_*` i pola finansowe nie istnieją w kontrakcie.

Stable order to wybrane pole/kierunek plus zawsze `id ASC` jako tie-breaker. Sort `category` stosuje bieżącą nazwę kategorii. Ustalić używaną kolację DB i testować deterministyczność przy równych kluczach. `total` jest liczbą tylko autoryzowanych approved projection rows po filtrach. Count i page wykonują się wewnątrz transakcji z tym samym organization lock/scope. Nie obiecywać snapshotu pomiędzy osobnymi stronami/żądaniami.

### 4.7 Statusy i bezpieczny error envelope

Wszystkie błędy nowych tras mają ten sam zewnętrzny kształt:

```json
{ "error": { "code": "SAFE_CODE", "message": "Bezpieczny komunikat" } }
```

Nie zwracać SQL, nazw tabel, hashy, wewnętrznych ID ani informacji o konfiguracji innej organizacji.

| Status | Kontrakt |
|---|---|
| 401 | Brak/nieważna sesja lub nieprawidłowa tożsamość. |
| 403 | Brak operating context/aktywnego exact membershipu, nieobsługiwany typ kontekstu, brak BOSS lub globalne `RAW_SUPPLIERS` restriction. |
| 404 | Detail ID nieistniejący, obcy, ukryty, tombstoned, nie-ACTIVE lub bez prawidłowej approval/category evidence; jeden status/kod/komunikat dla wszystkich. |
| 422 | Nieznany query key, błędny format lub niedozwolona wartość. |
| 503 | Kodowo NOT_READY, capability state missing/DISABLED, niedostępny/nieznany wymagany read dependency, lock timeout/deadlock lub awaria query. Zawsze `BOSS_RAW_CATALOG_UNAVAILABLE`, bez rozróżnienia disabled vs outage. |
| 405 | Metoda nieobsługiwana; endpointy write/export nie są rejestrowane. |
| 419 | Zachować konwencję wygasłej sesji/CSRF, z bezpiecznym envelope dla nowych tras. |

**No-store:** middleware grupy nowych tras ustawia `Cache-Control: private, no-store` dla odpowiedzi sukcesu i odpowiedzi już obsłużonych. Dodać scoped `bootstrap/app.php` exception-response handling dla prefiksu obu tras, który normalizuje error envelope i dodaje ten sam nagłówek do 401/403/404/405/419/422/503 generowanych przez framework, routing i exception handlers. Testować również błędy przed uruchomieniem kontrolera. Nie używać ETag/304, CDN ani cache współdzielonego.

## 5. Dokładne wymagane zmiany i pliki

### Kod aplikacji

1. **Nowy `backend/app/Domain/Authorization/BossRawCatalogIntent.php`** — typowane intent-y list/detail, bez innych operacji.
2. **`BusinessVisibilitySurface.php`** — dodać wariant intentu bez fałszywego permission; zachować walidację stabilnego klucza, operation i kategorii.
3. **`BusinessVisibility.php`** — dodać osobną gałąź BOSS dla dwóch intentów, z capability access, kategorią i organization provenance; nie zmieniać legacy branches.
4. **Nowy `backend/app/Domain/Modules/OrganizationCapabilityAccess.php`** (nazwa rekomendowana) — read-only ocena code catalog + aktywnego state dla konkretnego operation; brak wiersza/NOT_READY/disabled zamyka dostęp. Nie korzystać z administratorowego `OrganizationCapabilityService::status()` jako prawa dostępu.
5. **Nowy `backend/app/Http/Requests/ListBossRawMaterialsRequest.php`** — dokładny query allowlist, walidacja i wartości domyślne.
6. **Nowy `backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php`** — transakcja/lock orchestration, rewalidacja context/membership, wspólne query builder predicates, list/detail/count.
7. **Nowy DTO `backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php`** — jawna projekcja 6 dozwolonych wartości, bez Eloquent/model serialization.
8. **Nowy `backend/app/Http/Controllers/Api/V1/BossRawMaterialCatalogController.php`** — cienki kontroler list/detail; przekazuje aktora, context i request-snapshot membership ID.
9. **Nowy `backend/app/Http/Middleware/BossRawCatalogNoStore.php`** — dekoruje wszystkie normalne odpowiedzi tras `private, no-store`.
10. **`backend/routes/api.php`** — dokładnie dwie GET trasy w osobnej grupie middleware, z UUID constraint; nie dodać RAW availability middleware.
11. **`backend/bootstrap/app.php`** — scoped exception handling dla nowego path prefixu: bezpieczne error codes/envelope oraz no-store na błędach, w tym auth, validation, routing i query errors.
12. **`backend/lang/pl/messages.php`** — bezpieczne, nieujawniające komunikaty dla nowych error codes.

### Testy

- Nowy `backend/tests/Feature/BossRawMaterialCatalogApiTest.php`: route, DTO exact keys, param allowlist, sorting, pagination, status/envelope/no-store, brak tras write.
- Nowy `backend/tests/Feature/BossRawMaterialCatalogAuthorizationTest.php`: aktywny identity/org, exact operating membership, context types, effective BOSS, capability READY+ENABLED, restrictions; rozdzielenie Stage 1D i RAW.
- Nowy `backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php`: organization joins, ACTIVE/soft-delete, exact approved revision event/hash, pending/working no-fallback, category rename/archive/current name, corrupt/missing evidence.
- Nowy test concurrency z process harness (np. `BossRawMaterialCatalogConcurrencyTest.php` i `tests/Support/concurrent_boss_raw_catalog_read.php`): reader-first/writer-first dla revoke BOSS, membership revoke, category restriction i capability disable.
- Nie modyfikować testów/migracji RAW poza koniecznym regression harness; każda poprawka innego obszaru wymaga osobnej zgody.

### Migracja i readiness

**Brak migracji jest wymaganiem domyślnym i jest uzasadniony zweryfikowanym schematem.** Istnieją composite FK, approved revision pointer, event/hash, category dictionary, tombstone i unit dictionary. Istnieją capability state/event tables 3A i append-only BOSS restriction history. Żadne nowe pole ani tabela nie jest potrzebna dla projekcji 3B.

Nie dodawać indeksu bez `EXPLAIN (ANALYZE, BUFFERS)` na syntetycznej, izolowanej bazie i odrębnego uzasadnienia wydajności. Nie dodawać DB lock trigger do restrykcji w tym zakresie: aktualna writer path serializuje się przez `SecurityMutation`, a testy powinny chronić tę zależność. Jeśli w przyszłości powstanie inny writer, rozważyć append-only BEFORE INSERT trigger z organization `FOR UPDATE`; nie zmieniać migracji 000054/000055.

`ready: false` pozostaje w kodowym katalogu i aktywacja organizacji pozostaje disabled domyślnie. Żadna fixture/test provider nie może modyfikować kodu produkcyjnego ani utrwalać stanu testowego poza izolowaną bazą testową.

## 6. Bezpieczeństwo i negatywne kryteria akceptacji

Implementacja nie jest gotowa do odbioru, jeśli którekolwiek z poniższych nie przechodzi:

### Autoryzacja i źródła

- Invalid/inactive/unverified user, inactive org, auth version mismatch, missing/invalid operating membership: 401/403 zgodnie z kontraktem.
- BOSS role revoke wygrywa przed lockiem: read po commit zwraca 403; sam RAW grant ani Stage 1D membership nie zastępuje BOSS.
- BOSS bez RAW grant przy READY+ENABLED uzyskuje tylko nową projekcję; BOSS+RAW grant ma identyczną projekcję. Zmiana ordinary RAW readiness/grant/endpoints nie jest częścią tej ścieżki.
- `RAW_MATERIALS` pozostaje NOT_READY; zwykłe RAW zachowuje dotychczasowy 503/authorization result, niezależnie od BOSS state.
- Capability brak/disabled/NOT_READY: 503 `BOSS_RAW_CATALOG_UNAVAILABLE`; nie pusty 200.
- Globalne `RAW_SUPPLIERS` exclusion: 403 dla obu tras. Exclusion lokalizacji/pary z organization provenance nie filtruje wspólnej definicji. Brak/nieznane provenance fail-closed.
- Żaden `BusinessAuthorization::allows()` fallback, grant union, fake central-kitchen membership ani ordinary RAW endpoint reuse.

### Tenant i ID privacy

- Dwie organizacje z równoległymi fixture’ami: żaden rekord, count, kategoria, search result ani error nie ujawnia obcej organizacji.
- W ramach poprawnie dozwolonej powierzchni: nonexistent, foreign, hidden, deleted, non-ACTIVE, missing approval/category i mismatch approval/hash zwracają identyczny 404 envelope na detail.
- Globalne role/capability/category deny ocenia się przed lookup ID, bez potwierdzania istnienia rekordu.

### Data leakage i provenance

- Dokładny zbiór kluczy list/detail testowany równością; nie wystarcza sprawdzenie obecności sześciu pól.
- Fixtures z niebezpiecznymi opisami/aliasami, supplier IDs/names/associations, ceną, kosztem, shares, dokumentami, alergenami, historią/audytem nie ujawniają ich w body/meta/errors, ani w search/sort/filters/count.
- Lista i detail wybierają tylko `approved_revision_id`; aktywny materiał z in-progress revision pokazuje zatwierdzone wartości; nowa wartość pojawia się dopiero po nowym, poprawnym APPROVED event/hash.
- Material-revision-category organization joins oraz approval `EXISTS` są wymagane. Brak/mismatch dowodu nie powoduje fallbacku.
- Archived category przy ACTIVE materiale pozostaje widoczna z current `name`; jej archive metadata nie wraca.

### API/caching/concurrency

- Nieznane query key i niedozwolone sort/filter wartości: 422; query escape jest dosłowny.
- Stable tie-break, poprawne count po pełnym scope i paginacja na 25/50/100.
- Error envelope oraz `private, no-store` sprawdzone na success i błędach wygenerowanych przez middleware/exception handler, w tym 401/403/404/405/419/422/503.
- Dwa porządki locków z sekcji 3.7 wykazują, że po writer commit żaden nowy read nie otrzymuje decyzji ze starej roli/membership/policy/state. Lock timeout/deadlock fail-closed jako 503.
- Regresja `BossLocationRestrictionsTest`, role/security API, `OrganizationCapabilityConcurrencyTest`, `AdministratorConcurrencyTest`, RAW availability/auth/revision testów i pełnych backend testów. Testy nie zmieniają utrwalonej gotowości/aktywacji.

## 7. Rollback i kompatybilność

- Addytywne trasy i klasy; nie zmieniać semantyki już istniejących `BusinessVisibility` sources/intents, Stage 1D, ordinary RAW grants, route group, middleware ani RAW readiness.
- Brak migracji: rollback kodu usuwa/wycofuje wyłącznie dwie trasy i nowe klasy; PA-07B.3A state/events i restrykcje pozostają nietknięte.
- `ready: true` nie trafia do wdrożenia w ramach samego implementowania endpoints. Najpierw kod/testy, acceptance checkpoint i osobna zatwierdzona zmiana code catalog readiness.
- Nawet po późniejszym ustawieniu `ready: true` organizacyjny state nie jest auto-enabled; brak state row pozostaje DISABLED. Rollback code readiness do false powoduje niedostępność BOSS read; nie kasować stanów/audytu i nie zmieniać RAW.
- Brak fallbacku do starego endpointu w razie rollbacku, 403, 404 lub 503. Frontend nie wchodzi w 3B.

## 8. Readiness gate, blokery i rekomendacja

### Warunki code-level READY

`BOSS_RAW_CATALOG_READ` może być kodowo READY wyłącznie po:

1. dostarczeniu obu oddzielnie autoryzowanych intentów i nowej grupy GET bez zwykłego RAW middleware;
2. dostarczeniu capability/context/role/restriction checks pod jednym, potwierdzonym snapshotem organizacyjnym;
3. wspólnym organization-scoped query z ACTIVE, soft-delete, approved pointer, revision/material/category integrity i current approval event/hash;
4. dokładnym DTO i zatwierdzonych filtrach/sort/page/count, error contract i no-store na sukcesach/błędach;
5. przejściu wszystkich negatywnych, cross-organization, data leakage i deterministycznych writer-first/reader-first testów;
6. pełnej regresji wykazującej, że ordinary RAW readiness/grants/endpoints, Stage 1D i PA-07B.2 restrictions są niezmienione;
7. osobnym review/akceptacji implementacji PA-07B.3B.

Po tej bramce aktywacja każdej organizacji nadal jest oddzielną, jawną, administratorową decyzją; aktywacja domyślna pozostaje DISABLED.

### Pozostałe blokery

Nie ma nierozstrzygniętych decyzji właściciela: siedem decyzji z tego kontraktu jest zatwierdzonych. Pozostają techniczne acceptance criteria, nie blokery architektury:

- testy muszą potwierdzić serializację i request-start context snapshot w runtime;
- implementacja musi zachować organizacyjny lock dla wszystkich zmian policy/auth;
- query plan musi być sprawdzony w izolowanej bazie przed rozważeniem jakiegokolwiek indeksu.

W razie wykrycia nowej ścieżki zapisu BOSS restrictions, która omija `SecurityMutation::run()`, kontrakt staje się **BLOCKED** do czasu wdrożenia i weryfikacji DB-level organization lock dla tej tabeli. Nie jest to stan obecnego source path.

### Rekomendacja

**READY FOR IMPLEMENTATION.** Baseline jest zgodny, decyzje właściciela rozstrzygają zakres DTO/API/kategorie/provenance/UI, a istniejące role, membership, capability oraz bieżący serwis restrykcji serializują się na wierszu organizacji. Implementacja ma obowiązek pobrać `FOR SHARE` przed odczytem autoryzacji, ponownie sprawdzić exact request membership po locku i przejść deterministyczne testy współbieżności. Ta rekomendacja nie oznacza wdrożenia, code-level READY ani aktywacji; do odbioru PA-07B.3B `BOSS_RAW_CATALOG_READ` pozostaje NOT_READY, a organizacje DISABLED domyślnie.
