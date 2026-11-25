# VELTRA 2.0 — Stage 7A.0.1 — Preflight wdrożenia lokalnego

## Werdykt

**BLOCKED — SPECIFIC REASON**

Nie można zatwierdzić wdrożenia do głównej lokalnej instancji `veltra-primary`: jej PostgreSQL jest zatrzymany, kontener nie startuje z powodu błędu montowania pliku inicjalizacyjnego, a stan schematu i danych na wolumenie primary pozostaje nieznany. Nie da się więc wykonać wymaganych kontroli odczytujących dla bazy docelowej.

Drugi niezależny bloker funkcjonalny: `BOSS_RAW_CATALOG_READ` nadal ma w kodzie `ready: false`. Nie da się go włączyć zmianą rekordu capability w bazie. Przed udostępnieniem trzeba zatwierdzić i przygotować zmianę code-readiness, zbudować nowy backend i wykonać testy tej zmiany. W tym preflight nie zmieniałem kodu ani stanu capability.

## Zakres i stan Git

- Repozytorium: `/home/veltra/projekty/veltra`
- Gałąź: `feature/stage7a-rbac-alignment`
- HEAD: `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`
- Worktree jest celowo nieczysty: zawiera zaakceptowaną, niezacommitowaną implementację Stage 7A.0.1, testy, raporty i nowe migracje. Zmiany zachowano; nie wykonano commita, pusha, merge'a, wdrożenia, migracji ani modyfikacji aplikacji.
- Jedynym plikiem utworzonym w tym zadaniu jest ten raport.

## Rozpoznane lokalne środowiska

Docker context `default` wskazuje na `unix:///var/run/docker.sock`. Odnaleziono kilka odrębnych projektów — ich bazy i obrazy nie są wymienne.

| Projekt | Aplikacja i porty | Baza / obraz | Stan i znaczenie |
|---|---|---|---|
| `veltra-primary` — główna lokalna instancja z README | Backend `veltra-primary-backend:local` na `127.0.0.1:8000` — unhealthy; frontend `veltra-primary-frontend:local` na `127.0.0.1:5173` — healthy; queue działa. `GET /api/health` na porcie 8000 zwraca 503. | Kontener `veltra-primary-postgres-1`, obraz `postgres:17-alpine`, wolumen `veltra-primary-postgres-data`; Redis i Mailpit działają. | PostgreSQL jest `Exited (127)`. Zapisany błąd OCI wskazuje montowanie hostowego pliku `10-create-runtime-role.sh` do `/docker-entrypoint-initdb.d/10-create-runtime-role.sh` z błędem typu pliku/katalogu (`not a directory`). Stan migracji i danych na wolumenie jest **nieznany**. |
| `veltra-stage6a1` — osobny kandydat Stage 6A.1 | Backend `veltra-stage6a1-backend:rc-795356faae84-20261123` na `127.0.0.1:8001` — healthy; frontend `veltra-stage6a1-frontend:rc-22e3320-20261124-unified-raw` na `127.0.0.1:5174` — healthy. | PostgreSQL `veltra-postgres:17-alpine` 17.11, baza `veltra_stage6a1`, wolumen `veltra-stage6a1_postgres-data`; Redis działa. | Działa poprawnie, ale jest odrębną instancją i korzysta ze starszych obrazów. Jego wyniki nie potwierdzają stanu primary ani nie czynią go celem wdrożenia. |
| `veltra-pa07a-preview` | Backend na `127.0.0.1:8002` — unhealthy; frontend na `127.0.0.1:5175` — healthy. | PostgreSQL zatrzymany, osobny wolumen. | Nie jest celem Stage 7A.0.1. Nie używano go do kontroli danych. |

Primary backend i frontend pochodzą z obrazów utworzonych 16 listopada; backend Stage6a1 z obrazu z 23 listopada. Żaden z tych istniejących obrazów nie jest obrazem z aktualnego, niezacommitowanego worktree Stage 7A.0.1. Nie wolno ich traktować jako wdrożenia zaakceptowanej zmiany.

## Odczytowe kontrole migracji i danych

Nie uruchamiałem primary PostgreSQL ani nie otwierałem/zmieniałem jego wolumenu. Kontrole SQL wykonano wyłącznie w osobnej, działającej bazie `veltra_stage6a1`, w transakcji `READ ONLY` z `default_transaction_read_only=on`.

| Kontrola w `veltra_stage6a1` | Wynik |
|---|---:|
| Ostatnia zarejestrowana migracja | `2026_11_23_000059_retire_raw_material_supplier_compatibility_workflow`, batch 5; 61 wpisów w tabeli `migrations`. |
| Migracje 060, 061, 062, 063 | Żadna nie jest zarejestrowana jako wykonana. |
| Użytkownicy z wieloma różnymi aktywnymi rolami (`revoked_at IS NULL`) | 0 |
| Aktywne, zweryfikowane konta bez dokładnie jednej efektywnej roli według logiki migracji 062 | 0 |
| Organizacje z więcej niż jedną lokalizacją `CENTRAL_KITCHEN` | 0 |
| `btree_gist` wymagany przez indeks exclusion migracji 060 | Obecny, wersja 1.7; PostgreSQL 17.11. |
| Istniejące obiekty częściowo odpowiadające migracjom 060–062 | Nie znaleziono constraintu 060, indeksu 061, funkcji ani triggerów 062. |
| Wiersze zakresów `identity.disable` dodawanych przez migrację 063 | 0. |
| Stan `BOSS_RAW_CATALOG_READ` w tabeli capability | Brak wiersza; konfiguracja domyślna to wyłączona, wersja stanu 0. |

To potwierdza, że baza Stage6a1 nie wykazuje konfliktów dla sprawdzonych preconditionów i wymagałaby migracji 060–063. **Nie potwierdza zgodności primary.** Dla primary wszystkie powyższe kontrole danych i stan migracji pozostają niewykonane, ponieważ baza nie jest dostępna.

## Zakres i kolejność migracji

Laravel zastosuje je w kolejności timestampów; przed każdym uruchomieniem należy potwierdzić na bazie docelowej, że poprzednie są już oznaczone jako wykonane albo że wszystkie cztery są pending:

1. [`2026_11_24_000060_enforce_single_business_role.php`](./backend/database/migrations/2026_11_24_000060_enforce_single_business_role.php) — odmawia, gdy aktywne przypisania obejmują różne role, następnie dodaje exclusion constraint; przypisania tej samej roli w różnych lokalizacjach pozostają możliwe.
2. [`2026_11_24_000061_limit_central_kitchens.php`](./backend/database/migrations/2026_11_24_000061_limit_central_kitchens.php) — odmawia przy więcej niż jednej centrali na organizację, następnie dodaje unikalny indeks częściowy.
3. [`2026_11_24_000062_require_effective_business_role.php`](./backend/database/migrations/2026_11_24_000062_require_effective_business_role.php) — odmawia, jeśli aktywny zweryfikowany użytkownik nie ma dokładnie jednej efektywnej roli; dodaje funkcje oraz odroczone constraint triggery dla kont, ról, członkostw i lokalizacji.
4. [`2026_11_24_000063_authorize_identity_disable_token_cleanup.php`](./backend/database/migrations/2026_11_24_000063_authorize_identity_disable_token_cleanup.php) — dodaje wyłącznie podpisane zakresy `DELETE` dla `activation_tokens` i `password_reset_tokens` w kontekście `identity.disable`.

Migracje 060–062 blokują zapisy na odpowiednich tabelach przez `ACCESS EXCLUSIVE`; okno wdrożeniowe musi uwzględnić blokady i czas kontroli. Każda migracja ma nieobsługiwane wycofanie w `down()` — nie planować `migrate:rollback`.

## Gotowość i aktywacja `BOSS_RAW_CATALOG_READ`

- [`CodeOrganizationCapabilityCatalog.php`](./backend/app/Domain/Modules/CodeOrganizationCapabilityCatalog.php) deklaruje capability `ready: false`, zakres `ORGANIZATION`, konteksty kuchni centralnej/restauracji i operacje `list`, `detail`. Obecnie endpoint aktywacji powinien odrzucić próbę `enabled=true` jako `CAPABILITY_NOT_READY`.
- [`DefinedModuleCatalog.php`](./backend/app/Domain/Modules/DefinedModuleCatalog.php) także deklaruje `RAW_MATERIALS` jako `ready: false`. To osobna bramka modułu. Test `test_raw_material_module_is_not_a_boss_catalog_readiness_dependency` potwierdza, że gotowość modułu RAW nie jest zależnością dla samego katalogu BOSS; nie należy mylić ani automatycznie aktywować tych dwóch stanów.
- Brak wiersza `organization_capability_states` oznacza `DISABLED`, wersję 0; readiness jest metadanymi kodu, nie ustawieniem, które można bezpiecznie wymusić SQL-em.
- Istnieją endpointy statusu/konfiguracji/historii: `GET` i `PUT /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ`, historia pod `/history`. Nie znaleziono odpowiedniego interfejsu frontendowego; aktywacja jest dostępna przez API.
- Po osobnym zatwierdzeniu gotowości trzeba zmienić code-readiness capability na `READY`, dodać/uruchomić testy tej zmiany i zbudować nowy backend. Dopiero wtedy uprawniony administrator organizacji może włączyć stan przez istniejący endpoint `PUT`, z bieżącym hasłem i wersją stanu, np. `{"enabled":true,"expected_state_version":0,"current_password":"<hasło administratora>"}`. Operacja jest audytowana; nie modyfikować tabel capability bezpośrednio.
- Po zmianie kodu należy najpierw sprawdzić status `READY` / `DISABLED`, a następnie po jawnej autoryzacji wykonać aktywację. BOSS otrzymuje tylko zdefiniowane operacje odczytu. Nie rozszerzać tego na zapis ani nie zmieniać gotowości modułu RAW przy okazji.

## Wymagane obrazy i procedura lokalnego wdrożenia

Aktualny primary Compose definiuje obrazy [`veltra-primary-backend:local`](./compose.primary.yaml), `veltra-primary-frontend:local` i `veltra-postgres:17-alpine` (obraz PostgreSQL z [`docker/postgres/Dockerfile`](./docker/postgres/Dockerfile)). Usługi `migrate` i `queue` używają tego samego obrazu backendu. Obecny primary PostgreSQL jest jednak starym obrazem `postgres:17-alpine` i nie uruchamia się; konieczne będzie najpierw wyjaśnienie błędu montowania, a potem kontrolowane uruchomienie na **istniejącym** wolumenie.

Po usunięciu blockerów i dopiero po osobnej autoryzacji wdrożenia:

1. Zachować stan worktree i identyfikatory bieżących obrazów. Zbudować obrazy backendu, frontendu i PostgreSQL z dokładnie zweryfikowanego worktree; nie zakładać, że sam HEAD `e228f5e` zawiera niezacommitowane zmiany.
2. Bez `down -v`, usuwania ani inicjalizacji nowego wolumenu rozwiązać awarię kontenera PostgreSQL i potwierdzić, że `veltra-primary-postgres-data` zawiera właściwą bazę. Zweryfikować gotowość DB, połączenie aplikacji migracyjnej i rolę migratora.
3. Zatrzymać zapisy z backendu i kolejki w uzgodnionym oknie. Utworzyć zabezpieczony backup bazy przed migracją oraz backup globals/rol PostgreSQL; ograniczyć prawa plików do właściciela, zweryfikować sumę i możliwość odczytu archiwum. Przykładowy schemat backupu (nie wykonywano):

   ```bash
   umask 077
   docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml \
     exec -T postgres sh -lc 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' \
     > /secure/backup/veltra-primary-before-stage7a.dump
   docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml \
     exec -T postgres sh -lc 'pg_dumpall -U "$POSTGRES_USER" --globals-only' \
     > /secure/backup/veltra-primary-globals-before-stage7a.sql
   sha256sum /secure/backup/veltra-primary-before-stage7a.dump \
     /secure/backup/veltra-primary-globals-before-stage7a.sql
   ```

   Dump globals zawiera wrażliwe dane uwierzytelniające ról — przechowywać go jak sekret i nie publikować.
4. Wykonać ponownie powyższe agregatowe kontrole zgodności na **primary**. Przerwać bez migracji, jeśli występuje konflikt ról, aktywne zweryfikowane konto bez jednej efektywnej roli, wiele central albo brak wymaganej extension. Konflikty rozstrzygać jawnie; nie przypisywać ról automatycznie.
5. Zbudować obrazy według istniejącej procedury [README.md](./README.md): `docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml build backend frontend postgres`. Następnie, przy potwierdzonym zachowaniu istniejącego wolumenu, uruchomić zależności i zastosować migracje przez usługę migracyjną: `docker --context default compose -p veltra-primary -f compose.yaml -f compose.primary.yaml run --rm --no-deps migrate`. Odczytać `migrate:status` i potwierdzić `Ran` dla 060–063, następnie uruchomić stack zgodnie z README: `up -d --wait`.
6. Zweryfikować health API, frontendu/proxy, backendu, kolejki i bazy. Zweryfikować status capability. Nie aktywować jej, dopóki code-readiness nie jest `READY`.
7. Po osobnej autoryzacji przygotowania capability wdrożyć backend zawierający code-readiness `READY`, zweryfikować status, aktywować ją przez endpoint jako uprawniony administrator i sprawdzić audyt oraz status `ENABLED`.

## Automatyczne testy akceptacyjne po wdrożeniu

Testy zapisujące uruchamiać wyłącznie na izolowanej kopii/test DB, nigdy na bazie primary:

- uruchomić suite backend/frontend zbudowanych obrazów w odizolowanym środowisku testowym; nie wykonywać testów PHPUnit/E2E zapisujących rekordy na primary;
- automatyczny test smoke: migracje 060–063 `Ran`, API health 200, frontend i proxy API 200, brak błędów backendu/kolejki;
- capability status: przed aktywacją `READY` i `DISABLED`, po zatwierdzonej aktywacji `READY`, `ENABLED`, `available=true`, wersja stanu zwiększona o jeden; historia zawiera zdarzenia aktywacji;
- w uwierzytelnionym, syntetycznym środowisku sprawdzić BOSS `list`/`detail`, odmowę mutacji, ograniczenia lokalizacji i brak niedozwolonych pól finansowych; sprawdzić niezależne kombinacje FOODCOST/PURCHASE_PRICES;
- na primary ograniczyć weryfikację do autoryzowanych, niedestrukcyjnych żądań odczytu istniejącymi kontami. Nie tworzyć kont testowych ani nie modyfikować grants/configuration bez odrębnej zgody.

Wcześniejsza pełna regresja Stage 7A.0.1 została zaakceptowana i nie była powtarzana w tym preflight.

## Rollback

Migracje 060–063 są tylko do przodu. Nie używać `migrate:rollback` ani nie usuwać constraintów ręcznie. Przy błędzie:

1. Nie aktywować capability; zatrzymać ruch aplikacyjny i kolejkę.
2. Zachować wolumen po nieudanej próbie do diagnostyki.
3. Przywrócić zweryfikowany backup bazy i globals do oddzielnego, kontrolowanego wolumenu/instancji; potwierdzić integralność i stan przedmigracyjny, zanim stanie się aktywnym DB.
4. Dopiero po potwierdzonym odtworzeniu przełączyć backend, kolejkę i frontend na zapisane poprzednie obrazy. Nie kierować starego backendu na bazę po migracjach bez pełnego odtworzenia backupu.
5. Po rollbacku sprawdzić health i read-only smoke tests, zachowując oryginalny wolumen oraz logi.

## Konkretne blokery do usunięcia

1. **Primary DB niedostępna:** `veltra-primary-postgres-1` kończy się błędem 127 przy starcie z błędem montowania `10-create-runtime-role.sh`; backend jest unhealthy, a `/api/health` zwraca 503. Nieznane są migracje i dane primary. Przed wdrożeniem wymagane kontrolowane rozwiązanie problemu bez usunięcia lub nadpisania wolumenu.
2. **Code-readiness capability wyłączona:** `BOSS_RAW_CATALOG_READ` jest `NOT_READY`; sama zmiana capability state nie wystarczy. Wymaga zatwierdzonej zmiany metadanych kodu, testów i obrazu backendu. Nie wykonano jej w preflight.
3. **Obrazy primary są przestarzałe i różnią się od konfiguracji oczekiwanej z bieżącego worktree:** backend/frontend są z 16 listopada, a PostgreSQL działał jako `postgres:17-alpine`, zamiast oczekiwanego obrazu hardened `veltra-postgres:17-alpine`. Konieczny kontrolowany build i restart po uzgodnieniu bazy.

**Nie zmieniono bazy, kont, grantów, konfiguracji capability/modułów ani kontenerów. Nie wykonano migracji, budowania obrazów, commitów ani wdrożenia.**
