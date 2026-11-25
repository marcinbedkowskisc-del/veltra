# VELTRA 2.0 — Stage 7A.0.1 — Raport wdrożenia lokalnego

## Werdykt

**WDROŻONO KOD I MIGRACJE; WYMAGANE DZIAŁANIE WŁAŚCICIELA**

Nowy backend, queue i frontend działają na `veltra-stage6a1`; migracje 060–063 przeszły. `BOSS_RAW_CATALOG_READ` w uruchomionym backendzie ma `READY`, ale pozostaje `DISABLED` (wersja 0), ponieważ istniejący administrator nie był uwierzytelniony i nie można było bezpiecznie potwierdzić jego hasła. Nie wykonano bezpośredniej zmiany SQL ani nie użyto pominięcia autoryzacji.

## Zweryfikowany cel i konfiguracja

- Repozytorium: `/home/veltra/projekty/veltra`
- Gałąź: `feature/stage7a-rbac-alignment`
- HEAD: `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`
- Docker context: `default`, endpoint `unix:///var/run/docker.sock`
- Zweryfikowany plik środowiska: `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env`, zwykły plik, właściciel `veltra`, tryb `0600`. Bez ujawniania sekretów potwierdzono `POSTGRES_DB=veltra_stage6a1`, `BACKEND_PORT=8001`, `FRONTEND_PORT=5174`, `MAILPIT_PORT=8026`; wartości zgadzają się z runtime.
- Efektywna konfiguracja wskazuje projekt `veltra-stage6a1`, API na `127.0.0.1:8001`, frontend na `127.0.0.1:5174`, bazę `veltra_stage6a1` i zewnętrzny wolumen `veltra-stage6a1_postgres-data`. PostgreSQL nie publikuje portu na hoście.
- `veltra-primary` i `veltra-pa07a-preview` nie były modyfikowane.

Stare etykiety Compose wskazywały nieistniejący frontendowy override sesyjny, a stary release override przypinał nieaktualny frontend. Dodano powtarzalny [`compose.stage6a1.release-7a0.1-20261125-e228f5e-wt1.yaml`](./compose.stage6a1.release-7a0.1-20261125-e228f5e-wt1.yaml). Używa unikalnych tagów i nie zmienia usług PostgreSQL, Redis ani Mailpit.

## Backup, odtworzenie i próba migracji

Backupy zapisano poza repozytorium w `/home/veltra/.copilot/session-state/4842a143-6af6-42a7-9cee-00e540610261/files/stage6a1-deployment-20261125/`. Katalog ma tryb `0700`, dumpy `0600`; backup globals zawiera wrażliwe dane ról. Backupy pozostawiono do ewentualnego odtworzenia.

| Zestaw | Plik | SHA-256 | Rozmiar |
|---|---|---|---:|
| Przed próbą migracji | `pre-rehearsal.dump` | `bc81a253a39bbbce066222c1b9393aef198190e5e500c32a874a43f0f5d03fe1` | 834403 B |
| Przed próbą migracji | `pre-rehearsal-globals.sql` | `00908cc3dc2c7a495a8fe27136855802c897b3d2adf40d62257779c5539dd2de` | 1223 B |
| Końcowy backup przed migracją | `final-pre-migration.dump` | `c8403981f10d470d39504552f62181cc630d36aadb41c37a268dbacda629605b` | 834403 B |
| Końcowy backup przed migracją | `final-pre-migration-globals.sql` | `5a4d7d6760e2429062ebe4689cf323de09e82eb50f9e78746cb22f15e904f0ee` | 1223 B |

- Oba archiwa bazy przeszły `pg_restore --list`; integralność końcowego backupu: **PASS**.
- Backup przed próbą został odtworzony razem z globals do nowego kontenera PostgreSQL na daemonie `disposable-test` (`25b00a87-90c6-4ce2-900b-be9adf2f13eb`). Kontener miał `network=none`, brak portów i dane wyłącznie w `tmpfs`.
- Na odtworzonej kopii nowy backend wykonał migracje 060–063: **PASS**. Weryfikacja po próbie potwierdziła 0 konfliktów ról, 0 naruszeń pojedynczej efektywnej roli i 0 duplikatów central. Zachowano `RAW_MATERIALS` (1 włączony wpis; 1 surowiec, 3 rewizje, 2 kategorie, 10 zdarzeń audytu).
- Kontener odtworzeniowy usunięto po próbie; trwałe usługi testowe PostgreSQL/Redis na daemonie `disposable-test` nie były modyfikowane.

## Stan przed migracją Stage6a1

Po zatrzymaniu backendu, queue i frontendu ponownie sprawdzono cel w transakcji `READ ONLY`: `veltra_stage6a1`, OID `16384`, serwer `127.0.0.1:5432`. Ostatnia migracja to `2026_11_23_000059_retire_raw_material_supplier_compatibility_workflow`, batch 5; 060–063 były pending.

Preconditiony zgodne z migracjami:

- konflikty wielu aktywnych ról: **0**
- aktywne, zweryfikowane konta bez dokładnie jednej efektywnej roli: **0**
- organizacje z wieloma centralami: **0**
- częściowy constraint 060, indeks 061, funkcje 062: **0**
- wcześniejszy stan `BOSS_RAW_CATALOG_READ`: brak wiersza, czyli `DISABLED`, wersja 0

## Migracje i wdrożone obrazy

Wykonano przez usługę `migrate` z nowym release override, bez uruchamiania zależności:

1. `2026_11_24_000060_enforce_single_business_role` — DONE
2. `2026_11_24_000061_limit_central_kitchens` — DONE
3. `2026_11_24_000062_require_effective_business_role` — DONE
4. `2026_11_24_000063_authorize_identity_disable_token_cleanup` — DONE

`php artisan migrate:status` przez nowy obraz wykazał wszystkie cztery jako `Ran`, batch 6. Ponowny odczyt potwierdził 0 konfliktów ról, centrali i efektywnych ról; dwa oczekiwane zakresy `identity.disable` są obecne.

| Usługa | Tag wdrożony | Image ID |
|---|---|---|
| Backend / migrate / queue | `veltra-stage6a1-backend:stage7a0.1-20261125-e228f5e-wt1` | `sha256:d650e85e03d8aa9f8acc2a4d1a94af89c7933f3e9f0fc7f96f62ecab9bb09e60` |
| Frontend | `veltra-stage6a1-frontend:stage7a0.1-20261125-e228f5e-wt1` | `sha256:bcb784985d2e3c43c2567917ec5e179ab36cc583fb88c2bcbeed3b8743b2a612` |

Poprzednie obrazy backendu `sha256:63e14f50…` i frontend `sha256:412fc5bf…` zachowano. Obraz PostgreSQL hardened `veltra-postgres:17-alpine` (`sha256:f1e5168e…`), Redis (`sha256:8da19c73…`), Mailpit, wolumen i dane pozostały bez zmian. Compose zgłosił istniejący, zatrzymany orphan `veltra-stage6a1-migrate-795356faae84-20261123-deployment`; zachowano go i nie użyto `--remove-orphans`.

## Health i acceptance

- Backend: running, **healthy**, `127.0.0.1:8001`.
- Frontend: running, **healthy**, `127.0.0.1:5174`.
- Queue: running na nowym obrazie backendu.
- PostgreSQL, Redis i Mailpit: running, **healthy**, na poprzednich obrazach.
- `GET /api/health`: **200**; frontend `/`: **200**; health przez frontend proxy: **200**.
- Otwarta przeglądarka pokazała nowy frontend VELTRA i pozycję RAW. Była niezalogowana; to nie jest uwierzytelniony test BOSS.
- RAW po wdrożeniu pozostał `READY` i `ENABLED`; usługa aplikacji zgłosiła `raw_readiness=true`, `raw_enabled=true`. Dane nie uległy zmianie: 1 surowiec, 3 rewizje, 2 kategorie, 10 zdarzeń audytu.
- Bezpośredni, nieuwierzytelniony odczyt endpointów capability, katalogu modułów i RAW zwraca **401** — oczekiwana granica autoryzacji.
- Odczyt przez `OrganizationCapabilityService::status` w nowym backendzie, z istniejącym kontekstem administratora, zwrócił: `READY`, `DISABLED`, `available=false`, `state_version=0`, operacje `list`/`detail`.
- Zdarzenia audytu capability: **0**, ponieważ aktywacja nie została wykonana.
- Baza ma jednego użytkownika i jedną aktywną rolę `ADMINISTRATOR`; nie ma użytkownika BOSS. Nie utworzono ani nie zmieniono kont, ról, grantów czy ograniczeń finansowych. Wcześniejsze testy celowane na izolowanej bazie zakończyły się wynikiem **65 testów / 761 asercji**, obejmując autoryzację BOSS i macierz finansową; pełnej regresji nie powtarzano.

## Wymagane działanie właściciela

Aktywacja jest API-only; frontend nie zawiera ekranu zarządzania capability. Przeglądarka pokazała formularz logowania, a nieuwierzytelnione API zwróciło 401. Nie odczytywano ani nie proszono o hasło, cookie czy token.

Właściciel musi lokalnie uwierzytelnić się jako istniejący administrator i wykonać istniejący workflow:

- `PUT http://127.0.0.1:8001/api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ`
- body: `{"enabled":true,"expected_state_version":0,"current_password":"<wprowadzić lokalnie>"}`

Żądanie musi zawierać bieżącą sesję/CSRF właściwą dla uwierzytelnionego administratora. Nie przekazywać hasła ani tokenów w czacie. Po udanej odpowiedzi sprawdzić `ENABLED`, `available=true`, wersję 1 oraz zdarzenia aktywacji w historii. Nie zmieniać `RAW_MATERIALS`.

Nie można wykonać uwierzytelnionego odczytu katalogu BOSS w Stage6a1, ponieważ baza nie zawiera konta BOSS. Nie utworzono konta testowego ani nie zmieniono roli. Dostęp BOSS pozostaje pokryty wcześniejszymi testami izolowanymi; sprawdzenie na istniejącym koncie produkcyjnych danych wymaga osobnego, autoryzowanego konta BOSS.

## Końcowy stan Git i ograniczenia

- Gałąź i HEAD pozostały bez zmian: `feature/stage7a-rbac-alignment`, `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`.
- Zachowano wszystkie wcześniejsze niezacommitowane pliki. W tym wdrożeniu dodano wyłącznie release override Compose i ten raport.
- Brak commit, push, merge, VPS deployment lub Stage 7A.1.
- Migracji nie wycofywano; rollback wymaga odtworzenia chronionego backupu do kontrolowanej instancji, nie `migrate:rollback`.

**Końcowa ocena:** aplikacja i migracje wdrożone poprawnie; operacyjny BOSS read pozostaje wyłączony do czasu wymaganej autoryzowanej aktywacji przez właściciela. Nie deklaruję pełnej akceptacji funkcjonalnej przed tą czynnością.
