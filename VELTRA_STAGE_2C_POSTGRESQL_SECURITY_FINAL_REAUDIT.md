# VELTRA 2.0 — Końcowy niezależny re-audyt bezpieczeństwa PostgreSQL Stage 2C

**Data:** 4 listopada 2026  
**Zakres:** rozdzielenie ról PostgreSQL, migracje 017–019, rejestr użyć operacji uprawnień, funkcje triggerów oraz powiązane zabezpieczenia RBAC  
**Werdykt:** **REJECT**

## Podsumowanie

Ponowna, niezależna weryfikacja potwierdziła, że poprzednie ustalenie o roli migracyjnej jako superuserze zostało naprawione: `veltra_migrator` nie ma `SUPERUSER`, `CREATEDB` ani `CREATEROLE`, nie jest właścicielem bazy i nie należy do żadnej roli. Rola runtime nie ma bezpośrednich praw zapisu do ledger, nie może zmieniać schematu `public`, wyłączać chronionych triggerów ani modyfikować wpisów ledger bezpośrednim SQL. Świeże migracje i upgrade 018 → 019 przechodzą.

Pozostaje jednak **jedno ustalenie MEDIUM**. Rola runtime ma `EXECUTE` na funkcji triggera `SECURITY DEFINER`, a prawo `TEMP` odziedziczone przez `PUBLIC`. Może więc utworzyć tabelę tymczasową, podpiąć do niej tę funkcję i użyć jej jako zastępczego, niekontrolowanego zapisu do chronionego ledger. Taki wpis został niezależnie wstawiony i odczytany w disposable PostgreSQL 17. Nie przywraca on samodzielnie cofniętego grantu, ale fałszuje append-only historię i narusza wymaganą integralność rejestru.

W konsekwencji zabezpieczenie bezpośrednich operacji na tabeli nie wystarcza do akceptacji. W zakresie RBAC/bazy danych nie znaleziono innego potwierdzonego ustalenia HIGH. Z uwagi na otwarte MEDIUM werdykt całościowy pozostaje **REJECT**.

## Ustalenie

### M019-01 — Funkcja `SECURITY DEFINER` pozwala runtime dopisywać fałszywe wpisy do ledger przez trigger na tabeli tymczasowej

**Severity:** 🟡 MEDIUM  
**Confidence:** 10/10  
**Status:** otwarte — wymaga poprawki oraz niezależnego re-audytu

Migracja 019 tworzy `public.raw_materials_reject_bootstrap_grant_replay()` jako `SECURITY DEFINER`, ustawia jej `search_path` na `pg_catalog, public` i jawnie odwołuje `EXECUTE` od `PUBLIC`. Następnie przyznaje `EXECUTE` roli z `APP_DB_USERNAME`. Funkcja wstawia wartości `NEW.organization_id`, `NEW.bootstrap_operation_id` i `NEW.permission` do chronionego ledger, ale nie sprawdza `TG_RELID`, schematu ani nazwy tabeli, z której została wywołana. Zob. [migrację 019](./backend/database/migrations/2026_11_04_000019_restrict_permission_operation_ledger_writes.php#L21).

Na disposable PostgreSQL 17 odczyt uprawnień poświadczeniami `veltra_app` wykazał:

- `has_database_privilege(..., 'TEMP') = true` — runtime dziedziczy prawo tworzenia tabel tymczasowych przez `PUBLIC`;
- `has_schema_privilege(..., 'public', 'CREATE') = false`;
- `has_function_privilege(..., 'raw_materials_reject_bootstrap_grant_replay()', 'EXECUTE') = true`.

#### Niezależne odtworzenie

1. Administrator utworzył wyłącznie w disposable bazie pojedynczą organizację testową o ID `8a6212af-1d1b-4555-b97d-019f5d274197`.
2. Połączenie z rzeczywistymi poświadczeniami disposable roli runtime wykonało:

   ```sql
   CREATE TEMP TABLE m019_forgery (
       organization_id uuid,
       bootstrap_operation_id uuid,
       permission varchar(80)
   );
   CREATE TRIGGER m019_forgery_trigger
       BEFORE INSERT ON m019_forgery
       FOR EACH ROW
       EXECUTE FUNCTION public.raw_materials_reject_bootstrap_grant_replay();
   INSERT INTO m019_forgery VALUES (
       '8a6212af-1d1b-4555-b97d-019f5d274197',
       'bc047bf7-c653-46b7-84dc-673a26a3600d',
       'FORGED_LEDGER_ENTRY'
   );
   ```

3. Wszystkie trzy polecenia zakończyły się powodzeniem. Odczyt administracyjny ledger potwierdził wpis z podanym UUID operacji i `permission = FORGED_LEDGER_ENTRY`.

Wstawiony rekord nie był powiązany z grantem ani zdarzeniem audytowym. Nie wykazano, by samo dopisanie rekordu przywracało cofnięte uprawnienie. Potwierdza ono jednak możliwość fałszowania append-only rejestru i może zanieczyścić jego historię lub blokować późniejsze operacje o pasującym kluczu unikalnym.

#### Kontrola ograniczeń bezpośrednich

Używając tych samych poświadczeń runtime, niezależnie próbowano bezpośredniego `INSERT`, `UPDATE`, `DELETE` i `TRUNCATE` na ledger. Każda próba została odrzucona przez PostgreSQL jako `permission denied` (`SQLSTATE 42501`). Próba `CREATE TABLE` w schemacie `public` również zwróciła `42501`; próba wyłączenia triggera zakończyła się odmową z powodu braku własności tabeli.

Te kontrole potwierdzają naprawę bezpośrednich praw DML, ale nie zamykają wektora przez `SECURITY DEFINER`.

## Weryfikacja funkcji i ról

- Jedyną funkcją `SECURITY DEFINER` znalezioną w kodzie objętym sprawdzeniem jest `raw_materials_reject_bootstrap_grant_replay()`.
- W katalogu PostgreSQL funkcja ma właściciela `veltra_migrator`, `prosecdef = true` i `search_path=pg_catalog, public`.
- `PUBLIC` nie ma `EXECUTE`; ACL funkcji zawiera `EXECUTE` właściciela migracyjnego oraz `veltra_app`.
- Funkcja nie używa dynamicznego SQL, a tabela ledger jest kwalifikowana nazwą `public`. Nie stwierdzono klasycznego przejęcia `search_path`; błąd polega na braku ograniczenia, z jakiej tabeli może zostać wywołana funkcja.
- `veltra_app`: `rolsuper=false`, `rolcreatedb=false`, `rolcreaterole=false`, `rolreplication=false`, `rolbypassrls=false`; bez członkostwa w rolach.
- `veltra_migrator`: te same flagi uprzywilejowane są `false`; nie jest właścicielem bazy `veltra` (właścicielem pozostaje `veltra_admin`) i nie ma członkostwa w rolach. Ma `CONNECT`/`CREATE` na bazie oraz jest właścicielem schematu `public` i obiektów aplikacji, co umożliwia kontrolowane migracje i oznacza, że jego poświadczenia pozostają uprzywilejowane w obrębie tej bazy.
- Default privileges migratora nadają runtime standardowe DML na tabele oraz wymagane prawa do sekwencji. Na chronionym ledger provisioning i migracja 019 odbierają runtime `INSERT`, `UPDATE`, `DELETE` i `TRUNCATE`; runtime zachowuje `SELECT`.
- Provisioning odwołuje członkostwa runtime i migratora w rolach. Nie odbiera `TEMP` publicznie ani bezpośrednio roli runtime.

## RBAC, historia i prawidłowe operacje

Weryfikacja kodu `RawMaterialAuthorization`, migracji 015–018 i usług bootstrap/upgrade potwierdza zachowanie rekurencyjnego sprawdzania authority, zależności grantów, permanentnego cofania i ochrony przed ponownym użyciem historycznego operation ID. Niezależna suita poniżej przeszła, a prawidłowe bootstrapy i upgrade’y wykonane przez runtime działały z aktualnym grantem `EXECUTE`.

**Kontrola ograniczająca zakres ryzyka:** wyłącznie w disposable bazie testowej odebrano `EXECUTE` roli runtime. Następnie:

- próba podpięcia funkcji do nowej tabeli tymczasowej została odrzucona jako `permission denied for function`;
- prawidłowy bootstrap przez istniejący trigger na `organization_permission_grants`, wykonany przez usługę aplikacji połączoną jako `veltra_app`, nadal zakończył się powodzeniem: **1 test, 6 asercji — PASS**.

Była to eksperymentalna zmiana ACL wyłącznie w bazie disposable, nie zmiana kodu ani istniejących zasobów. Wskazuje, że odebranie runtime prawa `EXECUTE` jest warte rozważenia jako naprawa; przed wdrożeniem należy sprawdzić pełny zestaw ścieżek aplikacyjnych i wprowadzić zmianę zatwierdzonym forward-only mechanizmem.

## Testy wykonane niezależnie w tym audycie

Wszystkie zasoby testowe działały w osobnej sieci Docker `internal=true`, bez opublikowanych portów i bez trwałych wolumenów; katalogi danych PostgreSQL i Redis były na `tmpfs`. Użyto PostgreSQL 17.11, Redis 7.4.12 i nowego obrazu backendu zbudowanego do testów. Istniejące kontenery, bazy i wolumeny VELTRA nie były używane ani modyfikowane.

| Test | Wynik |
|---|---:|
| Skupione testy bootstrapu, upgrade’u, cofania, delegacji wielostopniowej i reaktywacji, uruchomione jako rzeczywista rola runtime `veltra_app` | **9 testów, 99 asercji — PASS** |
| Pełna regresja backendu (`composer test`) z bazą disposable i osobnym runtime loginem w testach | **178 testów, 1475 asercji — PASS** |
| Bezpośredni ledger SQL: `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE` jako runtime | **4/4 odrzucone (`42501`)** |
| Próba trwałego DDL w `public` jako runtime | **Odrzucona (`42501`)** |
| Próba wyłączenia chronionego triggera jako runtime | **Odrzucona (brak własności tabeli)** |
| Podpięcie `SECURITY DEFINER` do tabeli tymczasowej i wpis fałszywego rekordu | **PASS — podatność odtworzona** |
| Po odebraniu `EXECUTE` w disposable DB: ten sam temp-trigger bypass | **Odrzucony (`permission denied for function`)** |
| Po odebraniu `EXECUTE`: prawidłowy bootstrap przez aplikację jako runtime | **1 test, 6 asercji — PASS** |
| Świeże migracje 001–019 jako `veltra_migrator` | **PASS** |
| Upgrade bazy po migracjach 001–018 do 019 jako `veltra_migrator` | **PASS**, 19 wersji migracji zapisanych |
| Ponowne `migrate` po 019 | **PASS**, `Nothing to migrate` |
| Redis disposable | **PONG** |
| PHP syntax migracji 017–019, testów RBAC i `scripts/init-env.php` | **PASS** |
| Laravel Pint dla migracji 017–019 i testu RBAC | **PASS** |
| `docker compose config --quiet`, `sh -n docker/postgres/10-create-runtime-role.sh`, `git diff --check` | **PASS** |

Wyniki 176 testów/1456 asercji z poprzedniego raportu migracji 018 są historyczne i nie są wliczone do powyższych wyników.

## Ryzyka dla istniejących instalacji

1. Skrypty `docker-entrypoint-initdb.d` nie są ponownie wykonywane po inicjalizacji wolumenu. Istniejąca instalacja wymaga jawnego uzupełnienia `.env` o dane migratora i zatwierdzonego, kontrolowanego provisioningu.
2. Provisioning zmienia hasła ról, przenosi własność obiektów w schemacie `public` na migratora i nadaje tej roli `CONNECT`/`CREATE` w bazie. Operacja wymaga kopii zapasowej, weryfikacji docelowej bazy i okna serwisowego; nie została wykonana na żadnej istniejącej instalacji.
3. `veltra_testing` wymaga osobnego provisioningu grantów i default privileges. Utworzenie loginu nie konfiguruje automatycznie praw dla każdej bazy.
4. Do czasu poprawki runtime może tworzyć wpisy ledger za pośrednictwem triggera na obiekcie tymczasowym mimo odmowy bezpośredniego DML.

## Werdykt

# **REJECT**

Poprzednie ustalenie dotyczące superusera migracyjnego jest zamknięte. Bezpośrednie prawa zapisu runtime do ledger również są odebrane, lecz pozostaje ich obejście przez wywołanie funkcji `SECURITY DEFINER` z tabeli tymczasowej. Dopóki runtime zachowuje `EXECUTE` na tej funkcji bez weryfikacji relacji wywołującej, integralność append-only ledger nie jest zapewniona. Nie znaleziono potwierdzonego ustalenia HIGH; powyższe otwarte MEDIUM wystarcza do odrzucenia akceptacji.

Audyt był read-only względem kodu aplikacji i istniejącej konfiguracji. Utworzono wyłącznie ten raport. Nie zmieniano kodu, istniejących kontenerów, baz ani wolumenów; nie wykonywano deploymentu ani commitów. Zmiany ACL do testu kontrolnego dotyczyły wyłącznie disposable bazy. `RAW_MATERIALS` nie zostało aktywowane w istniejącym środowisku; Stage 2D nie rozpoczęto.
