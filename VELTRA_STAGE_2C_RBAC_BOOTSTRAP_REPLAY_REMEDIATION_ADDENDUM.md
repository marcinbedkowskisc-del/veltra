# VELTRA 2.0 — Uzupełnienie remediacji RBAC bootstrap replay

## Podsumowanie

Uzupełniono remediację dwóch ustaleń z niezależnego audytu migracji 017:

1. Rola PostgreSQL używana przez backend była rolą administracyjną z `SUPERUSER`, więc mogła opróżnić rejestr użyć operacji i ponowić cofnięty grant.
2. Backfill migracji 017 zachowywał wcześniejsze aktywne duplikaty grantów, nie oznaczając ich jako incydentu ani nie zatrzymując dalszego upgrade’u.

Dodano nową, forward-only migrację 018 oraz rozdzielono poświadczenia runtime i migracji w Compose. Historyczne migracje 015–017 pozostawiono bez zmian. Nie wykonano migracji w istniejących bazach ani aktualizacji działających kontenerów.

## Zmiany

- `backend/database/migrations/2026_11_03_000018_harden_bootstrap_replay_protection.php`
  - Przed zakończeniem migracji wyszukuje grupy grantów według `(organization_id, bootstrap_operation_id, permission)`.
  - Jeśli grupa zawiera duplikaty i choć jeden grant bez zdarzenia `REVOKED`, zgłasza `SQLSTATE 23514` z komunikatem wymagającym ręcznego przeglądu.
  - Nie zmienia grantów ani historii audytowej. W razie błędu transakcja migracji jest wycofywana, a wersja 018 nie zostaje zapisana jako wykonana.
  - Zachowuje dozwoloną semantykę: jedno użycie identyfikatora na organizację, operację i permission.
- `docker/postgres/10-create-runtime-role.sh`
  - Przy inicjalizacji pustego wolumenu tworzy oddzielną rolę runtime bez `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` i `BYPASSRLS`.
  - Nadaje dostęp do DML i sekwencji, bez `TRUNCATE`, DDL i własności schematu.
  - Konfiguruje uprawnienia domyślne dla obiektów tworzonych przez rolę migracyjną.
- `compose.yaml`
  - Backend i worker używają `APP_DB_USERNAME` / `APP_DB_PASSWORD`.
  - Jawny profil `migrations` używa `POSTGRES_USER` i `POSTGRES_PASSWORD`.
  - Profile testowe uruchamiają migracje i testy na osobnej bazie `veltra_testing`, której właścicielem jest rola runtime.
- `.env.example`, `backend/.env.example`, `scripts/init-env.php` i `README.md`
  - Dokumentują rozdział ról i generują niezależne, losowe hasła.
  - Wyjaśniają, że skrypty inicjalizacyjne PostgreSQL nie wykonują się ponownie na istniejących wolumenach.
- `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php`
  - Dodaje regresję z aktywnym historycznym duplikatem, weryfikującą fail-closed guard migracji 018.

## Weryfikacja niezależna

Testy uruchomiono na oddzielnej sieci Docker `internal=true`. PostgreSQL 17 i Redis 7.4 korzystały z `tmpfs`, bez opublikowanych portów ani trwałych wolumenów. Istniejące kontenery i wolumeny aplikacyjne nie były używane ani zmieniane.

| Sprawdzenie | Wynik |
|---|---|
| Skupiony suite `RawMaterialPermissionBootstrapTest` | **10 testów, 102 asercje — PASS** |
| Pełna regresja backendu (`composer test`) | **176 testów, 1456 asercji — PASS** |
| Świeże migracje 001–018 | **PASS** |
| Upgrade bazy 001–017 do 018 | **PASS** |
| Powtórne `migrate` po migracji 018 | **PASS — `Nothing to migrate`** |
| Aktywny historyczny duplikat przed migracją 018 | **PASS — migracja przerwana, `SQLSTATE 23514`; wersja 018 nie została zapisana, oba aktywne granty pozostały bez zmian** |
| Rola runtime w PostgreSQL | **PASS — `rolsuper`, `rolcreatedb`, `rolcreaterole`, `CREATE` bazy/schematu i `TRUNCATE` = `false`** |
| Próba `TRUNCATE` rejestru jako runtime | **PASS — `permission denied`** |
| Próba wyłączenia triggera append-only jako runtime | **PASS — odrzucona, rola nie jest właścicielem tabeli** |
| Uprawnienia DML runtime | **PASS — SELECT/INSERT/UPDATE/DELETE dostępne; TRUNCATE niedostępne** |
| Compose z profilami `migrations` i `testing` | **PASS — role i bazy przypisane do właściwych usług** |
| `scripts/init-env.php` | **PASS — utworzone dwa różne hasła 64-znakowe w katalogu tymczasowym** |
| PHP syntax, Pint i `git diff --check` | **PASS** |

Próba migracji z duplikatem została wykonana na disposable bazie po zastosowaniu 001–017. Po niepowodzeniu sprawdzono, że migracja 018 nie została odnotowana, funkcja guard została wycofana razem z transakcją, a duplikat pozostał nietknięty. Operator musi rozstrzygnąć taki przypadek ręcznie przed ponowną próbą migracji.

## Ograniczenia i ryzyka operacyjne

- Skrypt `docker-entrypoint-initdb.d` działa tylko przy tworzeniu pustego wolumenu. Istniejące instalacje wymagają osobnego, jawnego przygotowania roli runtime przez administratora; nie wykonano tego dla działającego środowiska.
- Zmiana plików Compose nie zmienia poświadczeń już uruchomionych kontenerów. Przed zastosowaniem nowej konfiguracji należy zaplanować provisioning istniejącej bazy i odrębne wdrożenie.
- Baza zawierająca aktywne historyczne duplikaty pozostanie na 017, dopóki administrator nie przejrzy i nie rozwiąże ich poza tą migracją. Migracja celowo nie cofa ani nie modyfikuje grantów automatycznie.
- Niezależny re-audyt poprawki pozostaje wymagany. Wcześniejszy raport audytowy z werdyktem **REJECT** nie został zmieniony; ten addendum nie stanowi akceptacji Stage 2C.

## Ograniczenia wykonania

Nie zmieniano istniejących baz, kontenerów ani wolumenów. Nie wykonano deploymentu, commitów, pushy, resetów ani rebase’ów. Nie aktywowano `RAW_MATERIALS` i nie rozpoczęto Stage 2D.
