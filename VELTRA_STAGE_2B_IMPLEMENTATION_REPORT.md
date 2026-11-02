# VELTRA 2.0 — raport implementacji Stage 2B

**Data:** 2026-11-02  
**Zakres:** rdzeń katalogu surowców, bez funkcjonalności Stage 2C–2F  
**Werdykt:** **CONDITIONAL ACCEPT** — implementacja i regresja przechodzą; wymagany jest niezależny przegląd bezpieczeństwa. Moduł nie jest gotowy do wdrożenia ani aktywacji.

## Podsumowanie

Zaimplementowano współdzielony katalog surowców w zakresie Stage 2B: kategorie do trzech poziomów, niezmienne kody `SUR-*`, wersje danych, zatwierdzanie, podstawowe jednostki i kontrolowane przeliczniki, stany cyklu życia, przywracanie archiwum, audyt oraz organizacyjne uprawnienia z delegowaniem. Produkcyjny katalog modułów zawiera `RAW_MATERIALS` ze stanem `NOT_READY`; testy używają osobnej fixture gotowości wyłącznie do sprawdzania domeny.

Podczas testów wykryto i naprawiono możliwość cofnięcia przez opiekuna grantu, którego sam nie nadał. Cofnięcie wymaga teraz, aby `granted_by` wskazywało bieżącego aktora. Dodano regresję dla tego ograniczenia.

Nowe surowce pozostają w stanie alergennym `UNKNOWN`; zatwierdzenie i aktywacja są odrzucane do czasu implementacji zatwierdzanej deklaracji alergenów w Stage 2D. To celowe ograniczenie, a nie obejście ani błąd gotowości.

## Izolacja i bezpieczeństwo środowiska testowego

- Testy wykonywano w prywatnej sieci Docker `veltra-stage2b-20261102` z `internal=true`.
- PostgreSQL: `veltra-stage2b-postgres`, baza `veltra_testing`, rola `stage2b_test`. Potwierdzona tożsamość połączenia: `veltra_testing|stage2b_test`.
- Redis: `veltra-stage2b-redis`; `redis-cli ping` zwrócił `PONG`.
- Testowy PostgreSQL nie miał podłączonych wolumenów; Redis używał wyłącznie `tmpfs` (`/data`, 64 MiB). Kontenery testowe nie były podłączone do sieci usług aplikacji i nie publikowały portów.
- Istniejące wolumeny `veltra_postgres-data` i `veltra_redis-data` nie zostały podłączone ani zmienione. Sześć istniejących usług aplikacji nadal działało podczas weryfikacji.
- Runner korzystał z obrazu `veltra-backend:stage1f-polish-localization`, z aktualnym katalogiem `backend/` podmontowanym jako kod źródłowy. Zmienna `REDIS_HOST` wskazywała testowy Redis; konfiguracja PHPUnit wymusza `CACHE_STORE=array`, `SESSION_DRIVER=array` i `QUEUE_CONNECTION=sync`, więc suite nie testuje zachowania aplikacji na Redisie.
- Testy, `migrate`, rollback i ponowne zastosowanie migracji dotyczyły wyłącznie wskazanej bazy testowej. Nie uruchamiano migracji ani testów destrukcyjnych na bazie aplikacyjnej.

### Migracje

Wszystkie migracje `2026_11_02_000001`–`2026_11_02_000011` zakończyły się powodzeniem. Dodatkowo migracja Stage 2B przeszła rollback i ponowne zastosowanie:

```text
php artisan migrate --force
php artisan migrate:rollback --step=1 --force
php artisan migrate --force
```

Wstępne `migrate:fresh` na jednorazowej bazie nie zakończyło się poprawnie: najpierw runner nie mógł zapisać do pliku logu, a po przełączeniu logowania na `stderr` pozostały funkcje PostgreSQL tworzone przez wcześniejsze migracje. Wyczyściłem schemat wyłącznie tej odizolowanej bazy i odtworzyłem go; pełny łańcuch migracji oraz rollback/reapply `000011` przeszły. Jest to ograniczenie ponownego użycia `migrate:fresh` przy pozostawionych procedurach PostgreSQL, nie zmiana ani operacja na środowisku aplikacyjnym.

## Zmienione i dodane pliki

**Katalog, dostęp i API**

- `backend/app/Domain/Modules/DefinedModuleCatalog.php`
- `backend/app/Domain/RawMaterials/OrganizationRawMaterialPermissionAdministration.php`
- `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php`
- `backend/app/Domain/RawMaterials/RawMaterialException.php`
- `backend/app/Domain/RawMaterials/RawMaterialPermission.php`
- `backend/app/Domain/RawMaterials/RawMaterialPermissionBootstrap.php`
- `backend/app/Domain/RawMaterials/RawMaterialService.php`
- `backend/app/Domain/RawMaterials/RawMaterialUnitRegistry.php`
- `backend/app/Console/Commands/BootstrapRawMaterialPermissions.php`
- `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`
- `backend/app/Http/Middleware/RequireRawMaterialsAvailability.php`
- `backend/app/Http/Requests/ListRawMaterialsRequest.php`
- `backend/app/Http/Requests/RawMaterialApprovalRequest.php`
- `backend/app/Http/Requests/RawMaterialCategoryRenameRequest.php`
- `backend/app/Http/Requests/RawMaterialCategoryRequest.php`
- `backend/app/Http/Requests/RawMaterialDataRequest.php`
- `backend/app/Http/Requests/RawMaterialGrantRequest.php`
- `backend/app/Http/Requests/RawMaterialReasonRequest.php`
- `backend/bootstrap/app.php`
- `backend/lang/pl/messages.php`
- `backend/routes/api.php`
- `backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php`

**Testy i fixture**

- `backend/tests/Feature/RawMaterialsTest.php`
- `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php`
- `backend/tests/Feature/RawMaterialConcurrencyTest.php`
- `backend/tests/Support/concurrent_raw_material_create.php`
- `backend/tests/Support/ModuleCatalogFixtures.php`
- `backend/tests/Feature/ModuleConfigurationTest.php`
- `backend/tests/Unit/ModuleCatalogTest.php`

Nie zmieniano plików frontendowych. Dokumenty architektury Stage 2A pozostawiono bez zmian.

## Model danych i integralność

Migracja tworzy tabele:

- `raw_material_categories`
- `raw_materials`
- `raw_material_code_counters`
- `raw_material_revisions`
- `raw_material_aliases`
- `raw_material_revision_units`
- `raw_material_revision_approval_events`
- `raw_material_audit_events`
- `organization_permission_grants`
- `organization_permission_grant_events`

Integralność opiera się m.in. na kluczach złożonych z `organization_id`, ograniczeniach unikalności i `CHECK`, kluczach obcych z `ON DELETE RESTRICT`, odraczanych FK do rewizji, triggerach PostgreSQL oraz blokadzie wiersza organizacji. Baza wymusza niezmienność kodu i rewizji, spójność stanów i przejść, głębokość kategorii, walidację przeliczeń i zgodność zdarzenia zatwierdzenia z oczekującą rewizją. Zdarzenia audytowe, zatwierdzeń i grantów są append-only.

Licznik kodów jest blokowany w transakcji organizacji; kod nie jest ponownie wykorzystywany. Przeliczniki metryczne oblicza backend, a nie klient. Dla podstawowych jednostek obsługiwane są `mg`, `g`, `kg`, `ml`, `l`, `pc`; współczynniki są przechowywane jako wartości dziesiętne, bez obliczeń zmiennoprzecinkowych.

## API i zabezpieczenia

Trasy są wersjonowane pod `/api/v1/raw-materials` i objęte sesją SPA, uwierzytelnieniem, walidacją tożsamości i kontekstu lokalizacji, ograniczeniem częstotliwości oraz sprawdzeniem dostępności modułu. Operacje domenowe dodatkowo wymagają odpowiedniego grantu organizacyjnego.

- `GET/POST /api/v1/raw-materials` — lista z filtrami i paginacją / utworzenie szkicu.
- `GET /categories`, `POST /categories`, `PATCH /categories/{category}` — kategorie.
- `GET /{material}`, `GET /{material}/revisions`, `PATCH /{material}/draft` — odczyt i rewizje.
- `POST /{material}/submit`, `/approve`, `/return`, `/block`, `/unblock`, `/archive`, `/restore` — zatwierdzanie i cykl życia.
- `POST /permissions/{user}`, `DELETE /permissions/{grant}` — kontrolowane nadanie i cofnięcie uprawnień.

Lista obsługuje `search`, `category_id`, `lifecycle_state`, `sort`, `direction`, `page` i `per_page` (maksymalnie 100). Walidacja i komunikaty API są po polsku; błędy domenowe zachowują stabilny `error.code`.

Bootstrap pierwszych grantów jest odrębnym poleceniem interaktywnym `veltra:bootstrap-raw-material-permissions`. Wymaga aktywnego, zweryfikowanego administratora organizacji z rolą globalną, potwierdzenia jego hasła, jawnej listy uprawnień obejmującej `RAW_MATERIALS_PERMISSION_DELEGATE`, innego aktywnego i zweryfikowanego odbiorcy z członkostwem oraz braku wcześniejszego bootstrapu. Operacja jest jednorazowa, transakcyjna i audytowana. Role lokalizacyjne same nie nadają dostępu do katalogu.

Delegowanie wymaga efektywnego grantu dla delegowanej permission oraz uprawnienia do delegowania; samo `RAW_MATERIALS_PERMISSION_DELEGATE` nie jest delegowalne. Grant nie może wykraczać poza okres obowiązywania uprawnienia źródłowego. Cofnięcie jest append-only i ograniczone do grantu nadanego przez aktora.

## Wykonane testy

Wszystkie polecenia PHPUnit uruchomiono w opisanym wyżej kontenerze testowym, z połączeniem `DB_HOST=veltra-stage2b-postgres`, `DB_DATABASE=veltra_testing` oraz `REDIS_HOST=veltra-stage2b-redis`.

```text
vendor/bin/phpunit --do-not-cache-result \
  tests/Feature/RawMaterialsTest.php \
  tests/Feature/RawMaterialPermissionBootstrapTest.php \
  tests/Feature/RawMaterialConcurrencyTest.php
OK (12 tests, 80 assertions)

vendor/bin/phpunit --do-not-cache-result
OK (157 tests, 1195 assertions)
```

Testy Stage 2B obejmują:

- niezmienny kod i audyt tworzenia, duplikaty oraz walidację jednostek;
- limit trzech poziomów kategorii i bezpośrednie próby naruszenia triggerów/niezmienności SQL;
- odrzucenie zatwierdzenia przy stanie alergennym `UNKNOWN`;
- blokowanie, odblokowanie, archiwizację, przywracanie i ponowne zatwierdzenie z zachowaniem historii;
- fail-closed dla produkcyjnego `NOT_READY`, brak obejścia przez rolę lokalizacyjną, zakaz samonadania, bootstrap, delegowanie i cofanie grantów;
- dwa procesy równolegle tworzące surowce, oczekiwanie na blokadę PostgreSQL, różne kody `SUR-000001`/`SUR-000002` i dwa zdarzenia audytu.

Pozostałe kontrole:

```text
php artisan migrate --force
php artisan migrate:rollback --step=1 --force
php artisan migrate --force
vendor/bin/pint --test <26 zmienionych plików PHP>
php -l <każdy zmieniony plik PHP>
git diff --check
```

Wyniki: wszystkie migracje oraz rollback/reapply `000011` — PASS; Pint — PASS, 26 plików; PHP syntax — PASS dla wszystkich zmienionych plików; `git diff --check` — PASS; Redis `PING` — `PONG`. Frontend lint/build nie uruchamiano, ponieważ frontend i współdzielone kontrakty TypeScript nie były zmieniane, a UI należy do wyłączonego zakresu Stage 2F.

## Ograniczenia i pozostałe ryzyka

1. **Moduł pozostaje `NOT_READY`** i nie jest aktywowany w żadnej lokalizacji. Fixture gotowości istnieje wyłącznie w testach.
2. **Aktywacja surowca nie jest możliwa w Stage 2B:** `UNKNOWN` nie może zostać zatwierdzone. Edycja zatwierdzanej deklaracji alergenów należy do Stage 2D.
3. Brak dostawców, ofert, cen, dokumentów, ukończonego workflow alergenów, importów/eksportów, produkcyjnego kalkulowania kosztów i UI jest zgodny z wyłączeniami Stage 2C–2F, nie jest błędem tej fazy.
4. Redis był osiągalny, ale pełna regresja korzystała z pamięciowego cache/session i synchronicznej kolejki zgodnie z konfiguracją testową. Integracja Stage 2B z Redis nie jest wymagana przez obecny rdzeń i nie była testowana.
5. Niezależny przegląd bezpieczeństwa nie został wykonany w ramach tej implementacji; pozostaje warunkiem przed akceptacją do dalszego użycia.

## Rekomendacja

**CONDITIONAL ACCEPT dla implementacji Stage 2B w zadeklarowanym zakresie.** Testy i kontrole przechodzą, a nie wykryto nierozwiązanych defektów w zweryfikowanym zakresie. Przed wykorzystaniem implementacji wymagany jest niezależny przegląd bezpieczeństwa. **Brak zgody na wdrożenie lub aktywację `RAW_MATERIALS`**; moduł musi pozostać `NOT_READY` do ukończenia i weryfikacji wymaganych etapów.
