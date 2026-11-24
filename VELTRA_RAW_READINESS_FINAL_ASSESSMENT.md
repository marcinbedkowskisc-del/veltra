# VELTRA 2.0 — końcowa ocena gotowości RAW

**Data:** 2026-11-23  
**Branch / commit:** `main` / `32e3bff`  
**Decyzja dokumentacyjna:** ADR-001 — jeden klient, jeden serwer, jedna instancja aplikacji i jedna baza danych  
**Status aplikacji:** bez zmian; aktywacja RAW nie wykonana

## 1. Werdykt

**NOT READY — implementacja nie spełnia warunku przejścia do kontrolowanych testów ręcznych na STAGING, ponieważ `RAW_MATERIALS` pozostaje `NOT_READY` i nie jest włączony dla lokalizacji.**

Jednocześnie **na poziomie kodu moduł wygląda jak READY CANDIDATE**: pełna regresja automatyczna przechodzi, kluczowe kontrakty RAW są zaimplementowane i brak potwierdzonych defektów blokujących kod RAW. Wniosek o gotowość nadal wymaga osobnego owner approval, zmianę stanu należy wykonywać dopiero po akceptacji.

RAW nie może obecnie przejść bezpiecznie do kontrolowanej manualnej weryfikacji na STAGING. Można natomiast przygotować osobną kontrolowaną procedurę stagingową bez zmiany gotowości.

## 2. Rozróżnienie stanów

| Warstwa | Bieżący stan | Uzasadnienie |
|---|---|---|
| Gotowość poziomu kodu | **READY CANDIDATE** | Backend i frontend przechodzą pełną regresję; kontrakty CRUD, rewizji, RBAC, dostawców, BOSS i eksportu są obecne. Owner approval i ewentualny mały naprawczy cleanup formatujący nadal wymagają decyzji. |
| Włączenie per-lokalizacja | **NOT VERIFIED / NOT READY** | Kodowo moduł jest zamknięty przez gate; nie odczytano ani nie zmieniano rzeczywistego `location_module_states`. |
| Uprawnienia użytkownika RBAC | **NOT VERIFIED na runtime STAGING** | Testy backendowe potwierdzają granty i odmowy w izolacji, ale bieżące konta, granty i konteksty STAGING nie zostały zweryfikowane. |
| Dostępność staging/deployment | **NOT VERIFIED / niedostępne dla RAW** | Nie wykonano deploymentu ani dostępu do istniejących zasobów STAGING. W stanie kodowym `available=false`. |

## 3. Dokumenty i wymagania

Zweryfikowano, między innymi:

- [VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md](./VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md) — ADR-001.
- [VELTRA_STAGE_1D_IMPLEMENTATION_REPORT.md](./VELTRA_STAGE_1D_IMPLEMENTATION_REPORT.md) i [VELTRA_STAGE_1D_RBAC_AMENDMENT_v1.1.md](./VELTRA_STAGE_1D_RBAC_AMENDMENT_v1.1.md) — RBAC i BOSS restrictions.
- [VELTRA_STAGE_1E_FINAL_VERIFICATION.md](./VELTRA_STAGE_1E_FINAL_VERIFICATION.md) i raporty Stage 1E — readiness, moduły i aktywacja per lokalizacja.
- [VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md](./VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md) — zatwierdzona architektura RAW.
- [VELTRA_RAW_FINAL_VERIFICATION_REPORT.md](./VELTRA_RAW_FINAL_VERIFICATION_REPORT.md), [VELTRA_RAW_FUNCTIONAL_VERIFICATION_REPORT.md](./VELTRA_RAW_FUNCTIONAL_VERIFICATION_REPORT.md), [VELTRA_RAW_PRE_STAGING_READINESS_ASSESSMENT.md](./VELTRA_RAW_PRE_STAGING_READINESS_ASSESSMENT.md) i [VELTRA_RAW_COMPATIBILITY_REMOVAL_REPORT.md](./VELTRA_RAW_COMPATIBILITY_REMOVAL_REPORT.md).

Weryfikowany kod potwierdził:

- Działający katalog, szczegóły, tworzenie, edycję, rewizje i konflikty optimistic concurrency.
- Graniczne granty organizacyjne RAW, aktywną tożsamość, membership i kontekst lokalizacji.
- Wiele przypisań dostawcy z walidacją duplikatów i constraintem PostgreSQL.
- Eksport CSV katalogu, zaznaczenia i danych rozszerzonych.
- Opcjonalne załączniki informacyjne i bezpieczne pobieranie/preview dokumentów, z odseparowaniem od oceny zgodności dostawców.
- Usunięcie workflow oceny zgodności dostawcy migracją `000059`.
- Snapshoty zatwierdzonych dostawców przypięte do rewizji i eventu zatwierdzenia.
- Oddzielną, tylko-do-odczytu capability i katalog BOSS RAW.
- Separację PA/BOSS/RAW i brak prawa BOSS do zapisu.

Import CSV UI, ocena zgodności dostawców, obowiązkowe certyfikaty/dokumenty, workflow klasyfikacji dokumentów i multi-client SaaS nie są traktowane jako wymagania RAW ani jako blokery tej oceny.

## 4. Pełna regresja

### Laravel backend

Polecenie końcowe:

```bash
./scripts/compose-test run --rm --no-deps backend-test php -d memory_limit=512M vendor/bin/phpunit
```

Środowisko: `PostgreSQL 17`, `Redis 7.4`, `ClamAV 1.4`, dedykowany projekt Compose `veltra-test-application`, baza i Redis w `tmpfs`, sieć `internal`. Migracje i testy wykonano wyłącznie w izolowanym środowisku.

**Wynik: OK — 563 testy, 5530 asercji, 0 błędów, 0 nieudanych testów. Czas 14:49.889; pamięć 103 MB.**

Dwa wcześniejsze próby `php artisan test` nie były miarodajne z powodu limitu pamięci PHP 128 MB albo początkowego uruchomienia migracji bez działających zależnych usług. Nie są to błędy testowanej funkcji i nie są liczone jako failures końcowej regresji.

### React/TypeScript frontend

```bash
npm test -- --reporter=verbose
```

**Wynik: 32 pliki testowe przechodzą, 293 testy przechodzą, 0 failures.**

```bash
npm run build
```

**Wynik: PASS.** Ostrzeżenie: bundle `index-BHYxyE-Q.css/index-BHYxyE...` ma jeden chunk JS o 574,87 kB i przekracza ostrzegawczy próg 500 kB. Nie jest to błąd builda i nie jest regresem RAW.

```bash
npm run lint
```

**Wynik: PASS z 1 ostrzeżeniem.** `src/visualPreview.tsx:39` — Fast Refresh warning `only-export-components`. Nie dotyczy błędu działania ani RAW.

### PHP i zależności

- PHP syntax: `php -l` dla skryptów backendu — **PASS**.
- Composer manifest `composer validate --strict` — **PASS**, manifest valid.
- `./vendor/bin/pint --test` — **FAIL**, 409 plików sprawdzonych, 1 problem formatowania: `app/Domain/RawMaterials/RawMaterialService.php` (`method_chaining_indentation`) w liniach 555 i 710–717. Nie ma to wpływu na wykonanie testów, ale jest aktywnym nieprzechodzącym checkiem formatowania.
- Composer security audit — **NOT VERIFIED**. Online audit został zablokowany przez brak DNS połączenia z `repo.packagist.org`; retry offline nie uzyskał mapy podatności. Nie stwierdzono i nie wykluczono podatności.
- `npm audit --omit=dev` — **PASS, 0 vulnerabilities**.
- `git diff --check` — **PASS**.

## 5. Weryfikacja RAW

| Obszar | Wynik |
|---|---|
| Create / read / edit | **ZWERYFIKOWANO — PASS** |
| Rewizje i konflikt edycji | **ZWERYFIKOWANO — PASS** |
| RBAC i granice organizacyjne/lokalizacyjne | **ZWERYFIKOWANO w izolacji — PASS** |
| Wiele dostawców | **ZWERYFIKOWANO — PASS** |
| Eksport CSV | **ZWERYFIKOWANO — PASS** |
| Informacyjne załączniki i security plików | **ZWERYFIKOWANO — PASS** |
| Usunięcie ocen zgodności | **ZWERYFIKOWANO migracją i testami — PASS** |
| Snapshot dostawców BOSS | **ZWERYFIKOWANO kodem i testami — PASS** |
| Gate modułu i stan per-lokalizacja | **CODE PASS, runtime NOT VERIFIED** |
| Integracja PA i BOSS | **ZWERYFIKOWANO częściowo i testami — PASS na poziomie kodu** |

## 6. Blockery i ograniczenia

### BLOCKER

1. **Bieżący stan readiness/deployment**
   - Dowód: produkcyjny katalog definiuje `RAW_MATERIALS` jako `NOT_READY`; `ConfiguredModuleAvailability` odmawia bez gotowości i jawnego `enabled=true`; trasy RAW są blokowane przez `RequireRawMaterialsAvailability`.
   - Skutek: nie można przeprowadzić kontrolowanych manualnych testów RAW na rzeczywistym STAGING bez zmiany approved readiness i activation, co nie jest obecnie upoważnione.
   - Najmniejsza korekta: osobna owner approval dla readiness; późniejsza odrębna weryfikacja i konfiguracja wybranej lokalizacji. Nie obchodzić gate'u.

### NON-BLOCKER

1. **Pint: 1 problem indentacji**
   - Dowód: `app/Domain/RawMaterials/RawMaterialService.php`, `method_chaining_indentation`.
   - Skutek: nie przechodzi formatting check; pełne 563 testy backendowe przechodzą.
   - Najmniejsza korekta: dodać brakujący odstęp i poprawić indentację łańcucha metod; bez zmiany logiki.

2. **Warning chunk size frontendu**
   - Nieblokujące ostrzeżenie produkcyjnego builda; nie jest defektem RAW.

3. **Fast Refresh lint warning**
   - Ostrzeżenie frontendu w `visualPreview.tsx:39`; lint kończy się kodem 0.

### NOT VERIFIED

1. Stan baz, kont, grantów i `location_module_states` bieżącego STAGING.
2. Rzeczywista dostępność deploymentu i zdrowie STAGING.
3. Aktualne uprawnienia użytkowników na STAGING.
4. Własny Composer security audit z powodu niedostępnego registry DNS.

## 7. Rekomendacja i następna akcja

**Rekomendacja: NOT READY w bieżącym stanie, z kodem ocenianym jako READY CANDIDATE.**

Najmniejsza bezpieczna następna akcja: pozostawić `RAW_MATERIALS = NOT_READY`, nie włączać żadnej lokalizacji i nie wdrażać. Po owner approval wykonać osobny read-only preflight STAGING, sprawdzić przygotowane konto/granty i stan modułu, poprawić formatowanie PHP, powtórzyć Composer audit po przywróconym DNS i dopiero potem uruchomić osobny, zatwierdzony proces zmiany readiness i aktywacji. To nie zmieniło żadnego stanu.

## 8. Stan końcowy i cleanup

- Worktree przed i po ocenie: czysty.
- Nie wykonano zmian aplikacji, migracji, commitów, pushy, deploymentu ani aktywacji.
- Nie odczytano ani nie zmodyfikowano istniejących baz danych STAGING/primary.
- Został utworzony wyłącznie ten raport.
- Izolowane kontenery i sieć `veltra-test-application` zostały usunięte; wolumeny testowe nie istniały i nie były usuwane.
