# VELTRA 2.0 — raport poprawki replay bootstrapu RBAC Stage 2C

**Data:** 3 listopada 2026  
**Status:** implementacja i weryfikacja zakończone; wymagana niezależna ponowna audytowa weryfikacja  
**Zakres:** blokada ponownego użycia historycznego `PERMISSION_BOOTSTRAP` operation ID do odtworzenia cofniętego uprawnienia

## Podsumowanie

Przyczyną podatności był brak trwałego, unikalnego śladu wykorzystania pary operation ID i permissionu. Migracja 016 traktowała operation ID bez rekordu upgrade jako bootstrap, a trigger migracji 015 uznawał historyczny, niezmienny `PERMISSION_BOOTSTRAP` event za wystarczające uzasadnienie kolejnego grantu. Po cofnięciu grantu ten sam operation ID mógł więc zostać użyty ponownie przez bezpośredni SQL. Nowy grant stawał się skuteczny bez nowego zdarzenia audytowego.

Dodano forward-only migrację 017. Tworzy ona append-only tabelę wykorzystań operation ID, z unikalnością `(organization_id, bootstrap_operation_id, permission)`, zapełnia ją na podstawie istniejących grantów i instaluje PostgreSQL trigger rejestrujący każde nowe wykorzystanie przed wstawieniem grantu. Powtórzenie tej samej pary jest odrzucane przez unikalne ograniczenie i trigger (`SQLSTATE 23514`), również gdy dwa zapisy konkurują. Istniejące granty ani zdarzenia audytowe nie są usuwane ani modyfikowane.

Istniejąca ścieżka jawnego upgrade’u administracyjnego pozostaje obsługiwana: dla nowej operacji każdy wybrany permission jest używany raz, a ponowienie z tym samym kluczem idempotencji zwraca poprzedni wynik. Poprawka nie przywraca grantów automatycznie i nie zmienia rekurencyjnego sprawdzania authority z migracji 016.

## Zmienione pliki

- `backend/database/migrations/2026_11_03_000017_prevent_permission_bootstrap_replay.php` — nowy forward-only ledger użyć, backfill, append-only trigger i blokada ponownego użycia operation ID z permissionem.
- `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php` — regresja cofnięcia uprawnienia i bezpośredniej próby SQL z historycznym bootstrap operation ID.

Nie zmieniano historycznych migracji ani serwisów aplikacji. `RawMaterialAuthorization.php` zachowuje dotychczasowe sprawdzanie skuteczności grantów i rekurencyjne cofanie łańcuchów authority.

## Zabezpieczenia i integralność

- PostgreSQL zapisuje użycie każdej kombinacji organizacja/operation ID/permission w tabeli z unikalnym ograniczeniem.
- Tabela wykorzystań jest append-only; odrzuca UPDATE i DELETE przez istniejący mechanizm `raw_materials_append_only()`.
- Trigger grantów rejestruje użycie w tej samej transakcji co INSERT grantu. Odrzucenie INSERT lub rollback wycofuje również wpis ledger.
- Migracja backfilluje istniejące kombinacje przez `GROUP BY`, zachowując istniejące wiersze grantów i ich historię. Istniejące historyczne duplikaty nie są automatycznie usuwane.
- Ponowne wywołanie wspieranego upgrade’u pozostaje idempotentne; ten sam klucz z innymi parametrami nadal kończy się konfliktem.
- Migracja 016 nadal blokuje zależne upgrade’y po cofnięciu upstream authority.

## Weryfikacja niezależna dla tej poprawki

Wszystkie operacje wykonano na oddzielnej, wewnętrznej sieci Docker `internal=true`. Disposable PostgreSQL 17 i Redis 7.4 używały wyłącznie `tmpfs`, bez trwałych mountów i opublikowanych portów. Testowy backend działał z tymczasowej kopii. Kontenery, bazy i wolumeny aplikacyjne nie były używane ani zmieniane.

| Weryfikacja | Wynik |
|---|---:|
| Świeże migracje 001–017 na PostgreSQL 17 | **PASS** |
| Upgrade bazy po migracjach 001–016, zawierającej 2 istniejące granty i ich bootstrap event | **PASS** — po migracji zachowano 2/2 granty, utworzono 2/2 wpisy ledger |
| Powtórne `migrate` po migracji 017 | **PASS** — `Nothing to migrate` |
| Replay cofniętego bootstrap permissionu przez bezpośredni SQL | **PASS** — odrzucony; grant pozostał cofnięty, nie powstał nowy grant ani audit event |
| Zachowanie jawnego upgrade’u administracyjnego | **PASS** — nowy permission przyznany, replay tego samego żądania idempotentny |
| Konflikt klucza idempotencji przy innych parametrach | **PASS** — domain conflict zgodny z istniejącą regresją |
| Multi-hop revocation i bezpośrednia próba SQL z cofniętym upstream authority | **PASS** |
| Skupione testy RBAC, revocation i concurrency | **14 testów, 146 asercji — PASS** |
| Pełna regresja backendu | **175 testów, 1453 asercje — PASS** |
| PHP syntax dla migracji i testu | **PASS** |
| Laravel Pint dla zmienionych plików | **PASS** |
| `git diff --check` | **PASS** |

Testy wymagające sesji przeglądarki uruchomiono z domenami Stateful odpowiadającymi testowemu originowi (`localhost:5173`, `127.0.0.1:5173`). Po zastosowaniu tej konfiguracji pełna regresja przeszła bez błędów i ostrzeżeń.

## Ograniczenia i pozostałe ryzyka

- Zmiana wymaga zastosowania migracji 017 przed ponownym uznaniem poprawki za niezależnie zaakceptowaną.
- Migration backfill zachowuje wcześniejsze granty, w tym ewentualne historyczne duplikaty. Tworzy jeden wpis ledger dla każdej różnej kombinacji organizacja/operation ID/permission; nie próbuje reinterpretować ani usuwać historii.
- Nie wykonano niezależnego audytu po remediacji. Akceptacja Stage 2C pozostaje poza zakresem tego raportu.
- Nie zmieniano stanu `RAW_MATERIALS`; pozostaje `NOT_READY` i nieaktywne. Nie rozpoczęto Stage 2D.

## Wynik

**Poprawka zaimplementowana i przeszła wymagane lokalne testy regresyjne.** Potwierdzenie zamknięcia podatności wymaga niezależnego re-audytu migracji 017 i prób bezpośredniego SQL. Nie wykonywano commitów, pushy, wdrożeń ani migracji w istniejącej bazie aplikacyjnej.
