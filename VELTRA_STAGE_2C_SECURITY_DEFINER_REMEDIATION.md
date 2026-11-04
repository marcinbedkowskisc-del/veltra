# VELTRA 2.0 — raport naprawy podatności SECURITY DEFINER

## Podsumowanie

**Wynik naprawy: migracje 020 i 021 oraz powiązane regresje zaimplementowano i zweryfikowano na zasobach disposable.** Nie jest to niezależna akceptacja bezpieczeństwa Stage 2C. Wymagany pozostaje niezależny re-audyt migracji 021.

Migracja `2026_11_04_000020_revoke_runtime_bootstrap_trigger_execution.php` zamknęła pierwotny wektor wywołania funkcji `raw_materials_reject_bootstrap_grant_replay()` przez trigger tabeli tymczasowej. Późniejsza analiza wykazała odrębne obejście walidacji przez przesłonięcie relacji przez `pg_temp`; naprawia je forward-only migracja `2026_11_04_000021_harden_permission_grant_search_path.php`. Nie zmieniano historycznych migracji.

## Przyczyna źródłowa

Migracja 019 pozostawiała roli runtime `EXECUTE` do funkcji `SECURITY DEFINER`. Ponieważ runtime może tworzyć tabele tymczasowe, mogło podpiąć tę funkcję jako trigger do własnej tabeli i w ten sposób wywołać ją z uprawnieniami jej właściciela. Funkcja dopisywała dane z `NEW` do rejestru użytych operacji bootstrapu, nie ograniczając wcześniej tabeli ani operacji wyzwalającej. Umożliwiało to fałszowanie wpisów w chronionym rejestrze, mimo odebrania runtime bezpośrednich uprawnień DML do tego rejestru.

## Zmiany

- Nowa migracja 020 odtwarza funkcję triggera z kontrolą kontekstu: dopuszcza wyłącznie `INSERT` do `public.organization_permission_grants`; inne tabele lub operacje kończą się błędem `42501`.
- Funkcja zachowuje `SECURITY DEFINER`, ale używa stałego `search_path = pg_catalog, public`, kwalifikowanej nazwy chronionego rejestru oraz statycznego SQL.
- Migracja odbiera `EXECUTE` od `PUBLIC` i skonfigurowanej roli runtime. Nie nadaje runtime żadnego zastępczego prawa do wywołania funkcji.
- Migracja jest forward-only; jej `down()` celowo zgłasza wyjątek, aby rollback nie przywrócił niebezpiecznego prawa.
- Test `RawMaterialPermissionBootstrapTest` sprawdza bezpośredni zapis runtime do rejestru, bezpośrednie wywołanie funkcji i próbę utworzenia triggera na tabeli tymczasowej, a także poprawne operacje bootstrapu, upgrade’u, revokacji i delegacji.
- README doprecyzowuje, że rola runtime nie ma prawa `EXECUTE` do tej funkcji.
- Migracja 021 przypina `search_path = pg_catalog, public, pg_temp` dla walidatorów grantów, kontroli authority, revokacji i replay guardu. Blokuje tabelę grantów na czas kontroli ledgeru, fail-closed wykrywa niezgodną historię, uzupełnia brakujące wpisy ledgeru tylko dla poprawnych grantów oraz weryfikuje ACL funkcji i ledgeru.
- Provisioning runtime i migracji wykonuje nadanie ogólnych praw DML oraz odebranie praw zapisu do ledgeru w jednej transakcji.
- Regresje sprawdzają ustawienia `search_path` oraz próbę fałszowania relacji audytu i grantów przez `pg_temp` przy użyciu rzeczywistych poświadczeń runtime. README opisuje wymagany sposób uruchomienia tego testu.

## Wyniki weryfikacji migracji 020

Pierwotne testy migracji 020 przeprowadzono w sieci Docker `internal=true` i na dedykowanych kontenerach PostgreSQL 17 oraz Redis z danymi w `tmpfs`. Szczegóły poniżej są historycznymi wynikami testów migracji 020, nie testami migracji 021.

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

## Wyniki bieżącej weryfikacji migracji 021

Kontynuację naprawy sprawdzono na odizolowanej sieci Docker `internal=true`, z PostgreSQL 17 i Redis 7.4. Kontenery miały wyłącznie `tmpfs` (`/var/lib/postgresql/data` oraz `/data`), bez podłączonych wolumenów. Istniejące zasoby aplikacji nie były używane ani zmieniane.

| Test | Wynik |
|---|---|
| Atak przez `pg_temp` z rzeczywistymi poświadczeniami runtime po migracji 021 | **PASS — zablokowany**, 1 test / 6 asercji. Próba zapisu do `public.organization_permission_grants` zakończyła się SQLSTATE `23514`; kwalifikowane odczyty potwierdziły brak grantu, ledgeru i audytu. |
| `RawMaterialPermissionBootstrapTest` z rolą migracyjną | **PASS — 14 testów / 133 asercje; 1 pominięty.** Test wymagający runtime uruchomiono osobno. |
| Pełna regresja backendu | **PASS — 180 testów / 1487 asercji; 1 pominięty.** |
| Świeże migracje 001–021 | **PASS** na bazie disposable. |
| Upgrade 001–020 → 021 | **PASS** na bazie disposable. |
| Migracja na bazie z osieroconym wpisem ledgeru | **PASS — oczekiwana odmowa.** Migracja 021 zwróciła SQLSTATE `23514`; baza pozostała na migracji 020, a wpis ledgeru pozostał nietknięty. |
| Uprawnienia runtime i `search_path` po 021 | **PASS.** `veltra_app` nie ma `EXECUTE` do replay guardu ani `INSERT`/`TRUNCATE` ledgeru; funkcje mają `search_path=pg_catalog, public, pg_temp`. |
| PHP lint, Pint, `sh -n`, `docker compose config --quiet`, `git diff --check` | **PASS.** |

Pierwsze uruchomienie skupionego zestawu bez `SANCTUM_STATEFUL_DOMAINS` zakończyło się jednym błędem sesji HTTP 401. Po ustawieniu domen zgodnie z konfiguracją Compose ponowione testy przeszły; błąd konfiguracji nie jest wliczony do wyników końcowych.

## Pozostałe ryzyka i ograniczenia

- Migracja 021 nie została wdrożona do żadnej istniejącej bazy aplikacji. Przed kontrolowanym wdrożeniem należy sprawdzić konfigurację poświadczeń i właścicieli ról oraz wykonać kopię zapasową zgodnie z procedurą wdrożeniową.
- Instalacja z osieroconym ledgerem, duplikatem lub grantem bez zgodnej historii audytowej zatrzyma migrację. Wymaga ręcznej analizy; automatyczne usuwanie danych nie jest częścią naprawy.
- Niezależny re-audyt powinien ponownie sprawdzić funkcje `SECURITY DEFINER`, ich ACL i własność, obejścia przez tymczasowe/alternatywne schematy, bezpośrednie próby SQL oraz współbieżność upgrade’u.
- Nie oceniano ponownie całego zakresu Stage 2C poza powiązanymi kontrolami RBAC/PostgreSQL.

Nie wykonano deploymentu ani migracji na istniejącej bazie. Nie zmieniano istniejących kontenerów, baz ani wolumenów. Nie wykonano commitów ani pushy. RAW_MATERIALS pozostaje nieaktywne/NOT_READY; nie rozpoczęto Stage 2D ani nie aktywowano VSEF.

**Decyzja:** implementacja i testy disposable są zakończone. Stage 2C nie jest uznany za zaakceptowany; przed formalną akceptacją wymagany jest niezależny security re-audyt migracji 021.
