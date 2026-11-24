# VELTRA 2.0 — plan backupu i testu odtworzenia Stage 6A.1

**Data weryfikacji:** 2026-11-23 18:46 +01  
**Zakres:** przygotowanie i odczyt; backup ani odtworzenie nie zostały wykonane.

## Decyzja i bieżący blocker

**Nie wykonywać teraz backupu ani restore.** Weryfikacja wykazała, że stan środowiska różni się od wcześniejszych raportów:

- PostgreSQL `veltra-stage6a1-postgres-1` jest `exited` (exit code `127`); Docker nie uruchomił procesu PostgreSQL z powodu błędu bind mountu skryptu `10-create-runtime-role.sh`.
- Backend jest `unhealthy`; `http://127.0.0.1:8001/api/health` nie odpowiedział w ciągu 4 sekund (HTTP `000`).
- Ponieważ PostgreSQL nie działa, nie można teraz potwierdzić rozmiaru bazy, jej bieżącej historii migracji ani wykonać logicznego zrzutu. **Nie podejmowano próby uruchomienia ani naprawy usług.**

Najpierw potrzebna jest osobna autoryzacja i rozwiązanie blokera uruchomienia PostgreSQL. Dopiero po potwierdzeniu zdrowia bazy można uzyskać odrębną zgodę na utworzenie backupu i test restore. Niniejszy plan nie stanowi takiej zgody.

## 1. Tożsamość i stan zasobów

Odczytane bez ujawniania zmiennych środowiskowych i poświadczeń:

| Zasób | Bieżące ustalenie |
|---|---|
| Compose project / usługa | `veltra-stage6a1` / `postgres` |
| Kontener | `veltra-stage6a1-postgres-1`, ID `a3b7753a683d97f28c76c0850822cce341937a268c93e2cd99cc4b79bc4797ec`, obecnie zatrzymany |
| Obraz | `postgres:17-alpine`, image ID `sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b` |
| Baza oczekiwana z wcześniejszych raportów | `veltra_stage6a1`; nie można teraz otworzyć połączenia i ponownie potwierdzić |
| Wolumen danych | **`veltra-stage6a1_postgres-data`**, driver `local`, etykiety Compose wskazują projekt `veltra-stage6a1`, etykieta wolumenu `postgres-data` |
| Montowanie danych | ten wolumen → `/var/lib/postgresql/data` (`rw`) |
| Pliki Compose z etykiet kontenera | `/home/veltra/projekty/veltra/compose.yaml` oraz `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml` |

Identyfikacja wolumenu jest zgodna z diagnozą i raportem odzyskania. Nie wykonywano `docker compose`, `start`, `restart`, `up`, `down` ani operacji na wolumenie.

## 2. Narzędzia, miejsce i istniejące kopie

- Na hoście są dostępne `docker`, `gzip`, `sha256sum` i `df`. Hostowe `pg_dump` oraz `pg_restore` nie są zainstalowane.
- Kontener nie działa, więc nie dało się sprawdzić w nim wersji `pg_dump`/`pg_restore`. Obraz `postgres:17-alpine` ma być użyty do eksportu i testu dopiero po osobnej zgodzie oraz sprawdzeniu binariów. Przywracanie powinno użyć dokładnego, lokalnego image ID powyżej, a nie zmiennego tagu.
- Wolne miejsce hostowego systemu plików: około **935 GB**. Docker zgłosił 578.2 MB zajętości wolumenów łącznie, ale jest to agregat, nie rozmiar tego wolumenu ani wolne miejsce. Docker root to `/var/lib/docker`; rzeczywiste wolne miejsce po stronie magazynu Docker Desktop/daemonu nie zostało potwierdzone. Przed backupem trzeba je potwierdzić.
- Kandydat na chroniony katalog docelowy:  
  `/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/`  
  Katalog istnieje, należy do `veltra`, ma tryb `0700`; leży na systemie plików z około 935 GB wolnego miejsca. To propozycja lokalizacji, nie potwierdzenie, że jest zatwierdzonym, długoterminowym repozytorium backupów. Pliku kopii nie umieszczać w repozytorium.
- Znalezione starsze archiwa (15.11.2026) mają od 637 311 do 659 013 bajtów; każde przechodziło `pg_restore --list`. Są starsze od późniejszej aktywności bazy i nie potwierdzają pełnej odtwarzalności, więc nie zastępują świeżego backupu.

Rozmiar bazy jest nieznany. Po przywróceniu zdrowego połączenia należy odczytać `pg_database_size()` i wolne miejsce magazynu Docker. Warunek planu: dostępne miejsce w magazynie, na którym powstanie archiwum i wolumen testowy, musi wynosić co najmniej **3 × rozmiar bazy + 2 GiB**. Jeśli warunek nie jest spełniony albo rozmiaru/limitu nie da się wiarygodnie ustalić — stop.

## 3. Proponowany backup logiczny — komenda niewykonana

Po osobnej autoryzacji backupu, rozwiązaniu problemu startu oraz potwierdzeniu:

1. kontener jest `healthy`, baza to `veltra_stage6a1`, podpięty jest istniejący wolumen `veltra-stage6a1_postgres-data`;
2. PostgreSQL przyjmuje połączenia, `pg_dump` działa, a zatwierdzony administrator bazy może wykonać kompletny zrzut bez wyświetlania ani umieszczania hasła w poleceniu;
3. dostępne miejsce spełnia próg powyżej, a wskazany katalog docelowy nadal ma tryb `0700`;
4. docelowy plik i plik tymczasowy jeszcze nie istnieją.

proponowane archiwum:

`/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/veltra-stage6a1-pre-schema-20261123T1846+0100.dump`

Komenda tworzy PostgreSQL custom-format dump bez wyłączania działającego serwera. Ścieżki są jawne; dane nie są wypisywane na terminal:

```bash
set -euo pipefail
umask 077
target=/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/veltra-stage6a1-pre-schema-20261123T1846+0100.dump
tmp="${target}.partial"
test ! -e "$target" && test ! -e "$tmp"
test ! -e "${target}.sha256"
docker exec veltra-stage6a1-postgres-1 \
  pg_dump -U postgres -d veltra_stage6a1 --format=custom \
  > "$tmp"
test -s "$tmp"
docker run --rm --network none \
  --mount type=bind,src=/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files,dst=/backup,readonly \
  sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b \
  pg_restore --list /backup/veltra-stage6a1-pre-schema-20261123T1846+0100.dump.partial \
  > /dev/null
mv -n "$tmp" "$target"
test -s "$target" && test ! -e "$tmp"
sha256sum "$target" > "${target}.sha256"
```

Komenda nie odczytuje ani nie zmienia danych przez SQL poza odczytem wykonywanym przez `pg_dump`; zapisuje wyłącznie nowy plik na hoście. `pg_dump` obejmuje schemat i dane tej bazy, w tym jej definicje ACL/ownership. Nie obejmuje globalnych obiektów klastra, np. definicji ról. Nie wolno uznać samego poprawnego exit code lub listy TOC za test pełnego restore. Jeśli rola `postgres` nie jest dostępna albo nie ma wymaganych praw, przerwać; nie próbować odgadywać ani odczytywać sekretów.

Stała nazwa zawiera czas sporządzenia planu. Jeśli wykonanie nastąpi później lub plik już istnieje, nie nadpisywać go: uzgodnić unikalną nazwę i ponownie potwierdzić miejsce docelowe.

## 4. Izolowany test odtworzenia — komendy niewykonane

Test ma użyć nowego kontenera PostgreSQL i anonimowego wolumenu, nie wolumenu Stage 6A.1. `--network none`, brak opublikowanych portów oraz wyłącznie `docker exec` izolują go od sieci i źródłowej bazy. Archiwum jest montowane tylko do odczytu. Limity proponowane dla testowego kontenera: **2 CPU, 2 GiB RAM, 256 procesów**; jeżeli restore wymaga więcej, zatrzymać test i uzyskać zgodę na nowe limity.

Po zweryfikowaniu pliku, sumy SHA-256, wolnego miejsca oraz braku kontenera o nazwie `veltra-stage6a1-restore-check`:

```bash
docker run -d --rm \
  --name veltra-stage6a1-restore-check \
  --network none \
  --cpus=2 --memory=2g --pids-limit=256 \
  --mount type=volume,dst=/var/lib/postgresql/data \
  --mount type=bind,src=/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files,dst=/backup,readonly \
  -e POSTGRES_USER=restore_check \
  -e POSTGRES_HOST_AUTH_METHOD=trust \
  sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b
```

Ustawienie `trust` dotyczy wyłącznie jednorazowego serwera bez interfejsu sieciowego i bez portów; połączenia do niego wykonuje lokalnie `docker exec`. Nie używać tej konfiguracji dla żadnej usługi trwałej ani dostępnej z sieci. Dalej:

```bash
docker exec veltra-stage6a1-restore-check \
  pg_restore --exit-on-error --create --no-owner --no-acl \
  -U restore_check -d postgres \
  /backup/veltra-stage6a1-pre-schema-20261123T1846+0100.dump
```

Weryfikacja ma się zakończyć kodem `0`, bez błędów w logu restore. Następnie w transakcji `READ ONLY` uruchomić na źródle i odtworzonej bazie te same agregaty:

```sql
BEGIN READ ONLY;
SELECT current_database(), pg_database_size(current_database());
SELECT count(*) AS migration_rows,
       count(DISTINCT migration) AS distinct_migrations,
       min(migration) AS first_migration,
       max(migration) AS last_migration,
       max(batch) AS max_batch,
       md5(string_agg(migration || ':' || batch::text, E'\n' ORDER BY migration)) AS history_fingerprint
FROM public.migrations;
SELECT c.relkind, count(*)
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND n.nspname NOT LIKE 'pg_toast%'
  AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')
GROUP BY c.relkind
ORDER BY c.relkind;
SELECT count(*) AS noninternal_triggers
FROM pg_trigger t
JOIN pg_class c ON c.oid = t.tgrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE NOT t.tgisinternal
  AND n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND n.nspname NOT LIKE 'pg_toast%';
SELECT count(*) AS constraints
FROM pg_constraint c
JOIN pg_namespace n ON n.oid = c.connamespace
WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND n.nspname NOT LIKE 'pg_toast%';
ROLLBACK;
```

Porównać: nazwę bazy, fingerprints migracji, liczbę migracji i batch oraz liczniki relacji, niewewnętrznych triggerów i constraintów. Oczekiwany stan historii zgodnie z raportem migracyjnym: **49 migracji**, maksimum `2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance`, batch `4`. Jeśli bieżący odczyt źródła się różni, nie zakładać, że stary raport nadal opisuje tę bazę — przerwać i wyjaśnić zmianę. Sama równość agregatów potwierdza testowalny zestaw, nie jest dowodem bitowej identyczności wszystkich danych.

Po zachowaniu wyników kontrolnych zatrzymać wyłącznie kontener testowy:

```bash
docker stop veltra-stage6a1-restore-check
```

`--rm` usuwa kontener i jego anonimowy wolumen po zatrzymaniu. Nigdy nie podpinać `veltra-stage6a1_postgres-data` do testu ani nie zmieniać celu restore na bazę źródłową. Nie odtwarzać archiwum na żywy Stage 6A.1.

## 5. Kryteria zaliczenia, ryzyka i stop conditions

**Backup/test zaliczony dopiero, gdy:**

- przed zrzutem tożsamość projektu, kontenera, bazy i wolumenu jest ponownie zgodna z tabelą wyżej, a PostgreSQL jest zdrowy;
- `pg_dump` zakończy się kodem `0`, archiwum będzie niepuste, `pg_restore --list` zakończy się kodem `0`, suma SHA-256 zostanie zapisana poza samym archiwum;
- pełny `pg_restore --exit-on-error` zakończy się kodem `0` w odizolowanym środowisku;
- history fingerprint i liczniki obiektów na źródle i kopii będą zgodne, bez wypisywania rekordów użytkowników, haseł, e-maili ani treści dokumentów;
- plik końcowy pozostanie w katalogu `0700`; kopia nie trafi do repozytorium ani do niezatwierdzonej lokalizacji.

**Natychmiast przerwać, bez retry lub zmian konfiguracji, jeśli:**

- PostgreSQL jest zatrzymany/unhealthy, API pozostaje niezdrowe, albo występuje bind-mount error;
- zmieni się projekt, image ID, baza, Compose identity lub nazwa/etykieta wolumenu;
- `pg_dump`/`pg_restore` jest nieobecny, zwraca błąd, nie może odczytać wymaganych obiektów albo wymaga odczytu sekretu;
- brak miejsca, przekroczony limit, kontener testowy ma konflikt nazwy, nie da się zapewnić `--network none`, albo archiwum nie przechodzi pełnego restore;
- zmieni się fingerprint historii lub liczniki obiektów, bądź pojawią się nieoczekiwane błędy walidacji.

Istotne ryzyka: wcześniejszy backup nie jest świeży; rozmiar aktualnej bazy i stan migracji nie są obecnie dostępne; dump nie zawiera globalnych definicji ról; test restore z `--no-owner --no-acl` sprawdza odzyskiwalność schematu i danych, nie odtwarza ani nie weryfikuje zewnętrznych ról/ACL klastra. Backup zawiera dane aplikacyjne i musi być traktowany jako poufny. Nie wolno uruchamiać migracji jako części tej procedury.

## Czynności niewykonane

Nie utworzono backupu, nie odtworzono bazy, nie uruchomiono zapytań do niedziałającej bazy, nie uruchamiano ani nie zatrzymywano usług, nie dotykano istniejącego wolumenu, danych, uprawnień, readiness ani aktywacji. Nie wykonano migracji, wdrożenia, commita ani pushowania.
