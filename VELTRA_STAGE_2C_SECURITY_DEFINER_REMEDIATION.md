# VELTRA 2.0 — raport naprawy podatności SECURITY DEFINER

## Podsumowanie

**Wynik naprawy: wdrożenie poprawki i weryfikacja na zasobach disposable zakończone.** Nie jest to niezależna akceptacja bezpieczeństwa Stage 2C. Wymagany pozostaje niezależny re-audyt.

W migracji `2026_11_04_000020_revoke_runtime_bootstrap_trigger_execution.php` dodano forward-only zabezpieczenie funkcji `raw_materials_reject_bootstrap_grant_replay()`. Nie zmieniano historycznych migracji. Test regresyjny odtwarza próby wywołania funkcji i podpięcia jej do tabeli tymczasowej przy użyciu poświadczeń roli runtime.

## Przyczyna źródłowa

Migracja 019 pozostawiała roli runtime `EXECUTE` do funkcji `SECURITY DEFINER`. Ponieważ runtime może tworzyć tabele tymczasowe, mogło podpiąć tę funkcję jako trigger do własnej tabeli i w ten sposób wywołać ją z uprawnieniami jej właściciela. Funkcja dopisywała dane z `NEW` do rejestru użytych operacji bootstrapu, nie ograniczając wcześniej tabeli ani operacji wyzwalającej. Umożliwiało to fałszowanie wpisów w chronionym rejestrze, mimo odebrania runtime bezpośrednich uprawnień DML do tego rejestru.

## Zmiany

- Nowa migracja 020 odtwarza funkcję triggera z kontrolą kontekstu: dopuszcza wyłącznie `INSERT` do `public.organization_permission_grants`; inne tabele lub operacje kończą się błędem `42501`.
- Funkcja zachowuje `SECURITY DEFINER`, ale używa stałego `search_path = pg_catalog, public`, kwalifikowanej nazwy chronionego rejestru oraz statycznego SQL.
- Migracja odbiera `EXECUTE` od `PUBLIC` i skonfigurowanej roli runtime. Nie nadaje runtime żadnego zastępczego prawa do wywołania funkcji.
- Migracja jest forward-only; jej `down()` celowo zgłasza wyjątek, aby rollback nie przywrócił niebezpiecznego prawa.
- Test `RawMaterialPermissionBootstrapTest` sprawdza bezpośredni zapis runtime do rejestru, bezpośrednie wywołanie funkcji i próbę utworzenia triggera na tabeli tymczasowej, a także poprawne operacje bootstrapu, upgrade’u, revokacji i delegacji.
- README doprecyzowuje, że rola runtime nie ma prawa `EXECUTE` do tej funkcji.

## Weryfikacja wykonana niezależnie w tej pracy

Testy przeprowadzono w nowej sieci Docker `internal=true` oraz na dedykowanych kontenerach PostgreSQL 17 i Redis z danymi w `tmpfs`. Użyto osobnych baz disposable; istniejące kontenery, bazy i wolumeny aplikacji nie były modyfikowane.

| Zakres | Wynik |
|---|---|
| Świeże migracje 001–020 | PASS — wszystkie 20 migracji zastosowano na pustej bazie disposable. |
| Upgrade 001–019 → 020 | PASS — w bazie disposable zastosowano migracje 001–019, a następnie 020; rejestr zawiera 20 migracji. |
| Uprawnienia funkcji po migracji | PASS — funkcja ma `SECURITY DEFINER`, właścicielem jest `veltra_migrator`, `search_path` to `pg_catalog, public`, a ACL zawiera wyłącznie `veltra_migrator=X`; runtime i `PUBLIC` nie mają `EXECUTE`. |
| Testy `RawMaterialPermissionBootstrapTest` | PASS — 13 testów, 127 asercji. Obejmują m.in. odmowę bezpośredniego zapisu, odmowę bezpośredniego wywołania i odmowę podpięcia triggera przez runtime oraz poprawne ścieżki bootstrapu/upgrade’u i zabezpieczenia revokacji. |
| Adwersarialny test guardu `TG_RELID` | PASS — w disposable bazie tymczasowo nadano runtime `EXECUTE`, po czym wywołano funkcję przez trigger tabeli tymczasowej. Funkcja odrzuciła próbę błędem `42501`; liczba wpisów rejestru przed i po wyniosła 0. Testowe `EXECUTE` następnie odebrano i potwierdzono brak tego prawa. |
| Pełna regresja backendu | PASS — 179 testów, 1481 asercji; 89,79 s. |
| Integracja Redis | PASS — `HealthIntegrationTest`: 2 testy, 7 asercji na disposable PostgreSQL i Redis; test potwierdził połączenie Redis, cache i kolejkę. |
| PHP lint | PASS — migracja 020 i zmieniony test składniowo poprawne. |
| Pint | PASS — oba zmienione pliki przeszły `pint --test`. |
| `git diff --check` | PASS. |

Test tymczasowego triggera celowo sprawdził również obronę w głębi: najpierw podniesiono uprawnienie wyłącznie w bazie disposable, aby przejść kontrolę ACL i wykonać funkcję. Nie zmieniono ACL żadnej istniejącej bazy aplikacji.

## Wynik i pozostałe ryzyka

W badanych przypadkach poprawka blokuje zarówno pierwotną ścieżkę eskalacji opartą o `EXECUTE`, jak i tę samą próbę po przypadkowym przywróceniu `EXECUTE`. Legalne operacje bootstrapu i upgrade’u nadal przechodzą testy. Historia rejestru pozostała niezmieniona w próbie adwersarialnej.

Pozostaje ryzyko operacyjne typowe dla wdrożenia migracji do istniejącej instalacji: migracja 020 musi być wykonana uprawnioną rolą migracyjną będącą właścicielem funkcji; trzeba potwierdzić właściwą konfigurację `APP_DB_USERNAME` dla każdej instancji. Migracja celowo nie może być cofnięta. Wyniki testów disposable nie stanowią dowodu stanu konfiguracji wdrożonych baz.

Nie wykonano deploymentu ani migracji na istniejącej bazie. Nie zmieniano istniejących kontenerów ani wolumenów. Nie wykonano commitów ani pushy. RAW_MATERIALS pozostaje nieaktywne/NOT_READY; nie rozpoczęto Stage 2D ani nie aktywowano VSEF.

Raport wymaga niezależnego audytu bezpieczeństwa przed formalną akceptacją.
