# VELTRA 2.0 — raport odzyskania PostgreSQL Stage 6A.1

**Data:** 2026-11-23  
**Zakres:** kontrolowane odtworzenie wyłącznie istniejącego kontenera PostgreSQL projektu `veltra-stage6a1`.

## Wynik

**Kontener PostgreSQL uruchomiony poprawnie.** Docker zaakceptował bind mount skryptu, PostgreSQL wykonał automatyczne odzyskiwanie istniejącego klastra, healthcheck kontenera jest `healthy`, a API `/api/health` zwraca HTTP 200.

Nie odtworzono, nie zresetowano ani nie migrowano bazy. Istniejący wolumen danych został zachowany. Żaden inny kontener nie był celem polecenia odtworzenia.

## Zweryfikowane warunki wstępne

- Istniejący kontener przed naprawą:
  - nazwa: `veltra-stage6a1-postgres-1`
  - ID: `5ac149e0759dd71fee1e6f84e908536714dc4dc7cfae4993dee94bd34e6922fc`
  - projekt Compose: `veltra-stage6a1`
  - usługa Compose: `postgres`
- Etykiety kontenera wskazywały dokładnie pliki użyte w poleceniu:
  - `/home/veltra/projekty/veltra/compose.yaml`
  - `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml`
  - katalog roboczy: `/home/veltra/projekty/veltra`
- Plik środowiskowy z diagnozy istniał; jego wartości nie były odczytywane ani raportowane:
  `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env`
- Obraz odpowiadał diagnozie: `postgres:17-alpine`, ID `sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b`.
- Źródło bind mountu istniało jako plik regularny, tryb `755`:
  `/home/veltra/projekty/veltra/docker/postgres/10-create-runtime-role.sh`.
  Konfiguracja mapuje je do `/docker-entrypoint-initdb.d/10-create-runtime-role.sh` w trybie tylko do odczytu (`ro`).
- Potwierdzony wolumen danych:
  - nazwa: `veltra-stage6a1_postgres-data`
  - sterownik: `local`
  - etykieta projektu: `veltra-stage6a1`
  - etykieta wolumenu Compose: `postgres-data`
- Znaleziono trzy istniejące archiwa PostgreSQL. Każde rozpoznano jako custom database dump i sprawdzono je przez `pg_restore --list` (PASS); żadnego nie odtwarzano:
  - `stage6a1-before-raw-pricing-20261115.dump`
  - `veltra-stage6a1-before-provisioning.dump`
  - `veltra-stage6a1-before-phase1.dump`

## Wykonana komenda

```bash
docker compose -p veltra-stage6a1 \
  --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
  -f /home/veltra/projekty/veltra/compose.yaml \
  -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml \
  up -d --no-deps --force-recreate postgres
```

Wynik Compose: `Recreated` i `Started` dla `veltra-stage6a1-postgres-1`.

## Uruchomienie i kontrole

- Nowy ID kontenera: `a3b7753a683d97f28c76c0850822cce341937a268c93e2cd99cc4b79bc4797ec`.
- Kontener: `running`; healthcheck PostgreSQL: `healthy`.
- Wolumen ponownie podłączony pod `/var/lib/postgresql/data`: **`veltra-stage6a1_postgres-data`**.
- Logi: katalog danych zawierał istniejącą bazę, inicjalizacja została pominięta; PostgreSQL wykonał automatyczne odzyskiwanie po nieczystym zamknięciu, zakończył redo i zgłosił `database system is ready to accept connections`.
- API: `http://127.0.0.1:8001/api/health` — **HTTP 200**.
- Końcowa lista usług wykazała pozostałe pięć kontenerów z tymi samymi ID co przed operacją; nie były odtwarzane ani zatrzymywane w ramach tej komendy.

## Read-only stan aplikacyjny po potwierdzeniu zdrowia DB i API

Zapytania wykonano w transakcji `READ ONLY`, bez odczytywania haseł, adresów e-mail ani identyfikatorów użytkowników.

- Baza aplikacyjna: `veltra_stage6a1`; rola użyta do sprawdzenia: `veltra_app`.
- Warunki bazodanowe lokalnej integracji RAW spełnione; konfiguracja procesu backendu wskazuje `APP_ENV=local`, `APP_URL=http://localhost:8001`, `FRONTEND_URL=http://localhost:5174` i projekt `veltra-stage6a1`.
- Lokalna gotowość `RAW_MATERIALS`: **READY** według konfiguracji Stage 6A.1. Istnieje jedna aktywna lokalizacja i jej stan modułu `RAW_MATERIALS` jest włączony. Nie zmieniano gotowości ani aktywacji.
- Stan administratora, zagregowany: jedno efektywne przypisanie roli administratora na poziomie organizacji i jeden aktywny kontekst członkostwa administratora.
- Aktywne granty organizacyjne RAW: 9 rodzajów (`ALLERGEN_MANAGE`, `APPROVE`, `CATEGORY_MANAGE`, `CREATE`, `EDIT`, `REFERENCE_PRICE_MANAGE`, `REFERENCE_PRICE_VIEW`, `SUBMIT`, `VIEW`). Wyniki nie zawierają tożsamości użytkownika.
- `BOSS_RAW_CATALOG_READ`: gotowość w katalogu kodowym to **NOT_READY**. Ponadto tabele `organization_capability_states` i `organization_capability_events` nie istnieją w tej bazie, więc stan aktywacji tej capability nie jest dostępny do odczytu. Najnowsza zastosowana migracja w bazie to `2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance`; migracja `2026_11_20_000056_create_organization_capability_state` nie została zastosowana.

## Ograniczenia i niewykonane czynności

- **Pozostały blocker:** BOSS pozostaje `NOT_READY`, a stan jego aktywacji nie może być odczytany bez brakujących tabel. Nie uruchomiono migracji, zgodnie z ograniczeniami zadania.
- Nie uruchamiano seederów ani poleceń inicjalizacji/resetowania bazy; nie zmieniano rekordów, ról, uprawnień, lokalizacji, wolumenów ani konfiguracji Compose.
- Nie zmieniano kodu aplikacji; nie wykonywano wdrożenia, commita ani pushowania.
- Archiwa zostały sprawdzone pod kątem możliwości odczytu listy zawartości; nie wykonywano prób odtworzenia.
