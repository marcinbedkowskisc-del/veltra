# VELTRA 2.0 — wdrożenie hardeningu PostgreSQL Stage 6A.1

**Data:** 2026-11-23  
**Zakres:** autoryzowany build obrazu i odtworzenie wyłącznie PostgreSQL; bez migracji i wdrożenia aplikacji.

## Wynik

**SUKCES.** PostgreSQL 17.11 działa i jest `healthy`, używając dotychczasowego wolumenu `veltra-stage6a1_postgres-data`. Skrypt inicjalizacyjny jest w obrazie, a jego bind mount nie występuje. PostgreSQL rozpoznał istniejący katalog danych, pominął inicjalizację, wykonał automatyczne WAL recovery i przyjmuje połączenia. API health zwraca HTTP **200**.

Historia bazy zawiera **49 migracji**, do `2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance`; nadal oczekuje 12 migracji. RAW w działającym katalogu aplikacji jest `READY`, a dla jednej aktywnej lokalizacji jest włączony. W repozytoryjnym katalogu `BOSS_RAW_CATALOG_READ` pozostaje `NOT_READY`; obecny starszy obraz backendu nie zawiera klasy tego katalogu, a tabele stanu BOSS nie istnieją, więc bieżącego stanu aktywacji BOSS nie można odczytać.

## Preflight

| Kontrola | Wynik |
|---|---|
| Branch | `main`; istniejące zmiany w worktree zachowane. |
| Docker | `--context default`, `unix:///var/run/docker.sock`; daemon ID `1163050e-c562-4a35-bd94-8c24de688587`, nazwa `docker-desktop`, root `/var/lib/docker`; zgodny z raportem hardeningu. |
| Compose | Projekt `veltra-stage6a1`; kontener przed operacją `veltra-stage6a1-postgres-1`, ID `a3b7753a683d97f28c76c0850822cce341937a268c93e2cd99cc4b79bc4797ec`. |
| Dokładne pliki | Etykiety kontenera potwierdziły `/home/veltra/projekty/veltra/compose.yaml`, `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml` i env-file `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env`. Zmienne środowiskowe PostgreSQL porównano z konfiguracją bez ich wyświetlania. |
| Wolumen | `veltra-stage6a1_postgres-data`, driver `local`, etykiety projektu i wolumenu prawidłowe; `/var/lib/postgresql/data`, `rw`. Jedynym użytkownikiem wolumenu przed operacją był wskazany kontener. |
| Baza obrazu | `postgres:17-alpine`, ID `sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b`, `PG_VERSION=17.11`; ten sam ID miał poprzedni kontener. |
| Konfiguracja | Dockerfile i Compose hardeningu obecne. Programowo porównano wyrenderowane konfiguracje aktualną i `HEAD` z rzeczywistym env-file i override’em: różnią się tylko obrazem/build oraz usuniętym bind mountem skryptu. |
| Dotychczasowe backupy | Wszystkie trzy archiwa z 15.11 istnieją; ich SHA-256 jest zgodny z raportem luki migracyjnej. Są stare i nie przeszły pełnego testu restore. |

Przed samym `up` ponownie sprawdzono ID demona, oryginalnego kontenera i projektu, nazwę oraz wyłącznego użytkownika wolumenu, jego mount oraz ID nowego obrazu — **PASS**.

## Dokładne polecenia zmieniające stan

Zbudowano wyłącznie obraz `postgres`, bez wymuszenia pobrania nowszego obrazu bazowego:

```bash
docker --context default compose -p veltra-stage6a1 \
  --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
  -f /home/veltra/projekty/veltra/compose.yaml \
  -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml \
  build --pull=false postgres
```

Wynik: **PASS**. Log buildu wskazuje dokładny base digest `sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b`. Nowy obraz `veltra-postgres:17-alpine`: ID `sha256:f1e5168e94083519d7b12a8516e02439c6bed43cb48424b5d9b3acc1d55aa99d`; jego warstwy bazowe odpowiadają poprzedniemu obrazowi. Metadane obrazu potwierdzają PostgreSQL `17.11`.

Przed uruchomieniem obraz sprawdzono bez tworzenia testowego kontenera: z `docker image save` odczytano ostatnią warstwę. `/docker-entrypoint-initdb.d/10-create-runtime-role.sh` jest plikiem regularnym `0755`; jego SHA-256 `80e041ac3a4da748245d594744183507894b84de72608f37a7d1cbc162d019f6` i wszystkie bajty odpowiadają plikowi źródłowemu. Wyrenderowany Compose nie zawiera już bind mountu skryptu.

Odtworzono wyłącznie usługę `postgres`:

```bash
docker --context default compose -p veltra-stage6a1 \
  --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
  -f /home/veltra/projekty/veltra/compose.yaml \
  -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml \
  up -d --no-deps --force-recreate --no-build --pull never postgres
```

Wynik Compose: `Recreated`, `Started`. `--no-build --pull never` wymusiły użycie wcześniej sprawdzonego lokalnego obrazu; nie zbudowano ani nie odtworzono innych usług.

## Uruchomienie i integralność zasobów

- Nowy kontener PostgreSQL: `79317d539f35ba9186cd3b6518252be9e5eb89d847087cd0bd382ce466c2fccf`, `running`, **`healthy`**; obraz odpowiada nowemu ID.
- Jedyny mount: ten sam wolumen `veltra-stage6a1_postgres-data` → `/var/lib/postgresql/data` (`rw`). Bind mount skryptu nie istnieje; nie utworzono nowego wolumenu.
- W działającym kontenerze `postgres --version` zwraca `postgres (PostgreSQL) 17.11`; skrypt nadal jest plikiem regularnym `0755` z powyższą sumą.
- Log o `2026-11-23 19:25:53 UTC`: `PostgreSQL Database directory appears to contain a database; Skipping initialization`.
- Po wcześniejszym nieczystym zamknięciu PostgreSQL wykonał `automatic recovery`, `redo` i checkpoint, po czym o `19:25:54 UTC` zgłosił `database system is ready to accept connections`.
- `curl --silent --show-error --max-time 10 --output /dev/null --write-out 'API_health_HTTP=%{http_code}\\n' http://127.0.0.1:8001/api/health` → **HTTP 200**.
- Pozostałe kontenery zachowały IDs: backend `7e3e4949cb4f`, queue `6d8822e78986`, frontend `b1930850c0c0`, Redis `07b7977bcd04`, Mailpit `8ddcb4ac30e5`. Backend sam przeszedł z `unhealthy` na `healthy`; nie był odtwarzany.

Kontrole wykonano przez `docker --context default inspect`, `volume inspect`, `image inspect`, `logs`, `ps -a` oraz `docker exec` do odczytu pliku i wersji. Nie wywoływano skryptu inicjalizacyjnego.

## Read-only: baza i readiness

Po potwierdzeniu zdrowia bazy i API wykonano:

```bash
docker --context default exec -i veltra-stage6a1-postgres-1 \
  sh -c 'exec psql -X -v ON_ERROR_STOP=1 -U "$APP_DB_MIGRATION_USERNAME" -d "$POSTGRES_DB" -At' <<'SQL'
BEGIN TRANSACTION READ ONLY;
SELECT 'database=' || current_database() || ';version=' || current_setting('server_version');
SELECT 'migrations=' || count(*) || ';distinct=' || count(DISTINCT migration) || ';max_batch=' || max(batch) || ';latest=' || max(migration) FROM public.migrations;
SELECT 'raw_location_states=' || count(*) || ';enabled=' || count(*) FILTER (WHERE enabled) || ';enabled_active_locations=' || count(*) FILTER (WHERE s.enabled AND l.is_active) FROM public.location_module_states s JOIN public.locations l ON l.id=s.location_id AND l.organization_id=s.organization_id WHERE s.module_key='RAW_MATERIALS';
SELECT 'boss_capability_states_table=' || (to_regclass('public.organization_capability_states') IS NOT NULL)::text || ';boss_capability_events_table=' || (to_regclass('public.organization_capability_events') IS NOT NULL)::text;
ROLLBACK;
SQL
```

Wynik: baza `veltra_stage6a1`, wersja `17.11`, 49 różnych migracji, max batch 4, ostatnia `000049`; 1 stan RAW włączony dla 1 aktywnej lokalizacji; obie tabele capability BOSS nie istnieją. Pierwsze wywołanie tej kontroli bez `docker exec -i` nie przekazało SQL i nie dało wyniku; poprawne wywołanie powyżej zakończyło się `ROLLBACK`.

Gotowość RAW sprawdzono w rzeczywistym backendzie, odczytując katalog aplikacji w transakcji `READ ONLY`:

```bash
docker --context default exec -w /var/www/html veltra-stage6a1-backend-1 php -r \
  'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap(); $db = Illuminate\Support\Facades\DB::connection(); $db->beginTransaction(); $db->statement("SET TRANSACTION READ ONLY"); $catalog = $app->make(App\Domain\Modules\ModuleCatalog::class); $raw = $catalog->find("RAW_MATERIALS"); if ($raw === null) {fwrite(STDERR, "RAW_MATERIALS missing from deployed catalog\n"); exit(1);} echo "RAW_MATERIALS_readiness=".($raw->ready ? "READY" : "NOT_READY").PHP_EOL; $db->rollBack();'
```

Wynik `RAW_MATERIALS_readiness=READY`. Pierwsza próba PHP bez `vendor/autoload.php` zakończyła się błędem ładowania klasy przed uruchomieniem zapytań; powyższa poprawiona próba zakończyła się powodzeniem.

`BOSS_RAW_CATALOG_READ` jest `ready: false` w aktualnym kodzie repozytorium. Obraz działającego backendu nie ma `CodeOrganizationCapabilityCatalog.php`; nie można więc przedstawiać tego jako bezpośrednio odczytanej gotowości bieżącego procesu. Brak tabel uniemożliwia odczyt bazy stanu aktywacji.

## Pozostałe blokery i ograniczenia

- Brak świeżego, przetestowanego backupu; wymagane są osobne autoryzacje backupu i izolowanego testu odtworzenia.
- 12 migracji pozostaje niewykonanych; stary backend odwołuje się do widoków usuwanych przez `000059`, więc ich przyszłe zastosowanie wymaga osobnego planu i zgodnego obrazu aplikacji.
- Nie uruchomiono migracji, seederów, restore ani ręcznego provisioningu; nie zmieniono ról, uprawnień, readiness ani aktywacji. Nie wykonano commita, pusha ani wdrożenia aplikacji.
- Przywrócono z `HEAD` wyłącznie śledzony `scripts/__pycache__/compose_test_cleanup.cpython-314.pyc`, wcześniej omyłkowo usunięty podczas sprzątania poprzedniej walidacji; nie pozostaje on zmieniony.
