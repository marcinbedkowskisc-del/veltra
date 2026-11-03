# VELTRA 2.0 — końcowy audyt remediacji Stage 2C

**Data audytu:** 3 listopada 2026

**Zakres:** dwie ostatnie poprawki LOW — retry synchronizacji NBP oraz powtarzalność migracji PostgreSQL.

**Tryb:** niezależny przegląd bezpieczeństwa i regresji; testy wyłącznie na disposable PostgreSQL/Redis.
**Werdykt:** **ACCEPT** dla obu zmian objętych tym audytem. Werdykt nie aktywuje `RAW_MATERIALS`, nie rozpoczyna Stage 2D i nie zastępuje odrębnych decyzji wdrożeniowych.

## Podsumowanie

Nie stwierdzono nierozwiązanych podatności w zakresie dwóch poprawek. Ukończony retry NBP odtwarza poprzednią odpowiedź bez połączenia z NBP; ten sam klucz z inną walutą kończy się konfliktem HTTP 409, również bez połączenia z upstream. Zmiany w 11 wcześniejszych migracjach ograniczają się do zastąpienia 43 deklaracji `CREATE FUNCTION` przez `CREATE OR REPLACE FUNCTION`; ich sygnatury i treści funkcji pozostały bez zmian.

Testy skupione Stage 2B/2C i pełny backend przeszły. Powtórne `migrate:fresh` zbudowało wszystkie 14 migracji dwukrotnie. Dodatkowo odtworzono stan bazy przed migracją 014 (migracje 001–013 zastosowane, plik 014 tymczasowo pominięty w runnerze disposable) i potwierdzono, że zwykłe `migrate` zastosowało wyłącznie oczekującą migrację 014.

## 1. Synchronizacja NBP i idempotencja

### Dowody z implementacji

- [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php#L566) — `syncNbpRate()` weryfikuje uprawnienie, walutę i datę, a następnie sprawdza istniejący wynik idempotencji przed wywołaniem `fetchNbpTables()`. Gdy odpowiedź dla zgodnego żądania istnieje, zwraca ją bez wywołania HTTP.
- [RawMaterialCommercialService.php](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php#L891) — mutacja ponownie sprawdza idempotencję w granicach właściwej transakcji po pobraniu upstream, co zachowuje kontrolę przy współbieżnym pierwszym żądaniu.
- [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php#L495) — test `test_nbp_as_of_overrides_half_even_restatements_and_idempotency` najpierw zapisuje kurs z kontrolowanej odpowiedzi HTTP, następnie zmienia fake NBP na HTTP 503 i powtarza to samo żądanie.
- W tym samym teście `Http::assertNothingSent()` potwierdza brak żądania przy replay; analogiczna asercja jest wykonywana dla starszego formatu wpisu idempotencji i dla konfliktu tego samego klucza z walutą `EUR`. Dla konfliktu test sprawdza `RAW_MATERIAL_IDEMPOTENCY_CONFLICT` oraz status 409.

### Wynik

Potwierdzono zgodny replay nowego i starszego formatu wpisu bez kontaktu z upstream. Zmiana parametrów przy ponownym użyciu klucza nie zwraca starego wyniku ani nie pobiera kursu; zwraca jawny konflikt.

## 2. Audyt 11 zmienionych migracji historycznych

Sprawdzono diffy plików:

1. `2026_11_02_000001_create_installation_structure.php`
2. `2026_11_02_000002_create_user_identity_and_memberships.php`
3. `2026_11_02_000003_create_business_role_scopes.php`
4. `2026_11_02_000004_protect_administrators_and_security_history.php`
5. `2026_11_02_000005_create_membership_approval_requests.php`
6. `2026_11_02_000006_create_permission_grants.php`
7. `2026_11_02_000007_create_account_security_links.php`
8. `2026_11_02_000010_create_location_module_configuration.php`
9. `2026_11_02_000011_create_raw_materials_core.php`
10. `2026_11_03_000012_permanently_revoke_raw_material_grants_on_suspension.php`
11. `2026_11_03_000013_create_raw_materials_commercial_domain.php`

Wynik przeglądu diffu: **43** usunięte deklaracje `CREATE FUNCTION` i **43** odpowiadające im deklaracje `CREATE OR REPLACE FUNCTION`; poza tymi deklaracjami diff nie zawiera innych zmian. Sygnatury, typy zwracane, język, treści funkcji, definicje triggerów, ograniczenia i dane nie zostały zmienione. PostgreSQL zastępuje funkcję o tej samej sygnaturze; w tym diffie nie zmieniono parametrów ani typu zwrotnego, które mogłyby uniemożliwić zastąpienie.

### Kompatybilność istniejących baz

Laravel nie wykonuje ponownie migracji oznaczonych jako zastosowane. Baza, która już zastosowała jedną z tych 11 migracji, nie uruchomi zmienionej deklaracji tylko dlatego, że zmienił się plik źródłowy. Pozostawiona definicja funkcji jest dotychczasową definicją; `CREATE OR REPLACE` nie dokonuje żadnej zmiany tej bazy podczas zwykłego `migrate`. Bazy z migracjami 001–013 otrzymują wyłącznie nieuruchomioną wcześniej, nową migrację 014.

Potwierdzenie na disposable PostgreSQL 17:

- `migrate:fresh --force` przebiegło dwa razy z wynikiem 14/14 migracji.
- Przygotowano bazę z migracjami 001–013 zastosowanymi; migracja 014 nie była obecna w tym przebiegu.
- Następne zwykłe `migrate --force` wykonało wyłącznie `2026_11_03_000014_enforce_active_supplier_product_revisions`; końcowy status: wszystkie 14 migracji `Ran`.
- Dodatkowy test bezpośrednio sprawdza, że walidacja w migracji 014 blokuje rewizje produktów po archiwizacji dostawcy lub produktu: `test_supplier_product_revisions_require_active_supplier_and_product_in_api_and_postgresql` w [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php#L785).

## 3. Bezpieczeństwo Stage 2C i regresja

Niezależny przegląd zmian nie zgłosił podatności. W ramach testów sprawdzono istniejące zabezpieczenia bez ich modyfikowania:

- RBAC dostawców, cen zakupu, cen referencyjnych i FX; brak ujawnienia poufnych cen przez endpointy katalogowe.
- niezmienność rewizji, zdarzeń cenowych i audytu oraz rollback komercyjnej mutacji, gdy zapis audytu zawodzi;
- blokady lifecycle dla archiwizowanych dostawców i produktów, w API i PostgreSQL;
- trwałe cofanie uprawnień, delegowanie i ponowne aktywowanie użytkowników/członkostw bez przywracania cofniętych grantów;
- blokadę modułu produkcyjnego. Testy API korzystają z jawnej testowej konfiguracji gotowego modułu; produkcyjny katalog modułów i stan `RAW_MATERIALS` nie były zmieniane.

### Testy wykonane podczas tego audytu

Wszystkie destrukcyjne testy i migracje korzystały z `veltra-stage2c-remediate-pg`, `veltra-stage2c-remediate-redis` oraz runnera na sieci Docker `internal=true`. PostgreSQL używał `tmpfs`; kontenery nie miały trwałych mountów ani opublikowanych portów. Runner nie miał mountów, a jego hashe plików serwisu, testu i migracji były zgodne z audytowanym worktree. Istniejące kontenery i wolumeny aplikacji nie były używane do testów destrukcyjnych.

| Test | Wynik |
|---|---:|
| Skupione testy `RawMaterialsTest`, `RawMaterialPermissionBootstrapTest`, `RawMaterialConcurrencyTest` | **25 testów, 284 asercje — PASS** |
| Pełna regresja backendu (`php artisan test`) | **170 testów, 1399 asercji — PASS** |
| `migrate` oraz dwa kolejne `migrate:fresh` | **PASS; za każdym razem 14/14 migracji** |
| Upgrade-test istniejącej bazy z migracjami 001–013 | **PASS; zastosowano wyłącznie migrację 014** |
| `git diff --check` | **PASS** |

Pierwsze wywołanie testów skupionych zakończyło się 13 błędami `401` / „Wymagana jest aktywna sesja przeglądarki”. Był to brak testowej konfiguracji `SANCTUM_STATEFUL_DOMAINS=localhost:5173`, odpowiadającej nagłówkowi `Origin` ustawianemu przez `IdentityTestCase`; nie był to błąd produktu. Po ustawieniu tej zmiennej w procesie testowym testy skupione przeszły w całości. Pełny backend uruchomiono z tą samą konfiguracją i również przeszedł.

## 4. Pozostałe ryzyka i ograniczenia

- Edytowanie plików wcześniejszych migracji jest odstępstwem od praktyki niezmieniania historii migracji. Zmiana naprawia czyste i powtarzane odtwarzanie schematu; nie aktualizuje funkcji w istniejących bazach, które już oznaczyły te migracje jako wykonane. W przyszłości zmiany zachowania funkcji w utrwalonej bazie powinny być wprowadzane nową migracją.
- `migrate:fresh` jest poleceniem destrukcyjnym i pozostaje zabronione dla danych, które mają zostać zachowane.
- Migracja 014 jest celowo forward-only; plan odzyskiwania musi opierać się na migracji naprzód lub odtworzeniu kopii.
- Audyt nie upoważnia do wdrożenia ani migracji live. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Nie rozpoczęto Stage 2D.

## Werdykt końcowy

**ACCEPT — dla dwóch poprawek objętych tym raportem.** Retry NBP jest idempotentny i nie obciąża upstream dla zakończonych żądań; konflikt parametrów jest jawny. Zamiana deklaracji funkcji w 11 migracjach nie zmienia ich logiki ani ograniczeń, przechodzi powtórne odtwarzanie schematu i nie powoduje ponownego wykonywania zapisanych migracji w istniejącej bazie. Nie stwierdzono pozostałego błędu wymagającego odrzucenia tych poprawek.
