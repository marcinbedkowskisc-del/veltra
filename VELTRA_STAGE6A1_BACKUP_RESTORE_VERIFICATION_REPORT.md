# Stage 6A.1 — backup i test odtworzenia

## Werdykt: FAIL

Archiwum zostało utworzone, a `pg_restore --exit-on-error` zakończył się kodem 0. **Nie potwierdzono jednak kompletnej zgodności odtworzenia:** przy porównaniu definicji wykryto różnice w 53 constraintach i jednym widoku. Przyczyna nie została ustalona; mogą to być różnice reprezentacji lub rzeczywiste różnice definicji. Nie wolno uznać testu za zaliczony ani używać go jako podstawy do migracji. Po wykryciu rozbieżności nie wykonano dalszych zapytań do baz ani czyszczenia zasobów testowych.

## Środowisko źródłowe i preflight

- Kontekst `default`, `unix:///var/run/docker.sock`; daemon `docker-desktop`, ID `1163050e-c562-4a35-bd94-8c24de688587`, root `/var/lib/docker`.
- Projekt `veltra-stage6a1`; kontener `veltra-stage6a1-postgres-1`, ID `79317d539f35ba9186cd3b6518252be9e5eb89d847087cd0bd382ce466c2fccf`.
- Baza `veltra_stage6a1`, PostgreSQL i narzędzia 17.11, 13 260 467 B, 49 unikalnych migracji, max batch 4. Źródłowy obraz: `sha256:f1e5168e94083519d7b12a8516e02439c6bed43cb48424b5d9b3acc1d55aa99d`.
- Wolumen `veltra-stage6a1_postgres-data` był zamontowany w `/var/lib/postgresql/data`. PostgreSQL był `healthy`, API pod `http://127.0.0.1:8001/api/health` zwróciło HTTP 200.
- Dostępne miejsce: około 1 003 756 740 608 B na filesystemie docelowym i 971 423 276 KiB po stronie Dockera; ponad wymagane 3 × rozmiar bazy + 2 GiB.
- Jedyny constraint `NOT VALID`, `public.raw_revision_usable_not_above_nominal_check`, jest celowo tak utworzony przez migrację `000049`; w read-only preflight wykryto 0 naruszeń. Nie stwierdzono polityk przypisanych konkretnym rolom ani foreign tables. Test źródłowych FK przed dumpem wykazał 0 naruszeń.

## Backup

- Katalog: `/home/veltra/backups/stage6a1`, poza repozytorium i `.copilot/session-state`; utworzony wraz z katalogiem nadrzędnym z trybem `0700`. Zweryfikowano właściciela, brak symlinków i ścieżkę rzeczywistą.
- Archiwum: `/home/veltra/backups/stage6a1/veltra-stage6a1-20261123T193945Z-b01845be954d42199ec77e8909766f7c.dump`.
- Czas UTC: `2026-11-23 19:39:45`; rozmiar 677 466 B; tryb `0600`.
- SHA-256: `1ea1369b02f4014fee09e145998990e8d1b93e861f83ef44c5c54e10d382bf16`.
- Suma zapisana w pliku o tej samej nazwie z rozszerzeniem `.sha256`, tryb `0600`; późniejsze `sha256sum --check` zwróciło `OK`.
- `pg_dump --format=custom` zakończył się kodem 0; `pg_restore --list` zakończył się kodem 0. Pliki utworzono przez `O_CREAT|O_EXCL|O_NOFOLLOW`, bez nadpisywania.
- Zestawienie źródła i dump korzystały z tego samego snapshotu wyeksportowanego przez transakcję `REPEATABLE READ READ ONLY`, utrzymywaną otwartą do końca dumpu. Dzięki temu współbieżne zapisy nie powinny powodować różnic liczby wierszy w porównaniu.

## Izolowane odtworzenie

- Obraz bazowy lokalny `sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b`, `PG_VERSION=17.11`; `--pull never`. Nie użyto hardened image zawierającego skrypt aplikacyjny.
- Kontener `veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c`, ID `75e3fbbadffbb0f5525bf120f6aa6267099c40d81f2b5d347889ffb1df2702c7`; osobny wolumen `veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c-data`, etykieta `veltra.restore-test=b01845be954d42199ec77e8909766f7c`.
- `--network none`, bez opublikowanych portów, limit 2 CPU, 2 GiB RAM i 256 procesów. Wolumen Stage nie był podłączony. Archiwum było podmontowane tylko do odczytu; SHA-256 pliku w kontenerze odpowiadał plikowi hosta. Docker Desktop przemapował źródło bind mountu na `/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/8dfa0a4f803334fd8d50348d53dce75b14d19a232bbfd5194c8ee82283f17484`.
- PostgreSQL testowy przyjął połączenia; `pg_restore --exit-on-error --no-owner --no-acl --create` wykonał się bez błędu i odtworzył bazę w oddzielnym klastrze. Nie pomijano błędów restore.
- `--no-owner --no-acl` świadomie pomija odtwarzanie właścicieli i ACL. `pg_dump` jednej bazy nie obejmuje globalnych ról klastra. **Role, ownership i uprawnienia nie zostały odtworzone ani zweryfikowane**; do faktycznego recovery potrzebny jest odrębny plan i weryfikacja tych elementów.

## Porównanie integralności

Porównano uporządkowane zestawy nazw i odcisków definicji oraz agregaty bez odczytywania pojedynczych rekordów:

| Kontrola | Źródło / restore | Wynik |
|---|---:|---|
| Schematy | 2 / 2 | Zestawy zgodne |
| Relacje, w tym tabele i widoki | 73 / 73 | Nazwy i rodzaje zgodne |
| Widoki i widoki materializowane | 2 / 2 | **1 różny odcisk definicji**: `public.raw_material_supplier_compatibility_status` |
| Constrainty | 598 / 598 | **53 różne odciski definicji**, liczone jako 53 po każdej stronie |
| Indeksy | 219 / 219 | Nazwy i odciski definicji zgodne |
| Triggery niebędące wewnętrznymi | 226 / 226 | Nazwy, stan i odciski definicji zgodne |
| Funkcje i procedury | 318 / 318 | Sygnatury i odciski definicji zgodne |
| Migracje | 49 / 49 | Nazwy i batche zgodne; oba odciski `04c9fd26b9793d6fe5a02423dc760eed` |
| Liczby wierszy tabel | 70 / 70 | Wszystkie zgodne, także tabele RAW, supplierów, organizacji, lokalizacji, członkostw i uprawnień |
| Kontrole kluczy obcych | 199 / 199 | 0 naruszeń po obu stronach |
| Istniejący constraint RAW `NOT VALID` | 1 / 1 | 0 naruszeń po obu stronach |

Odciski definicji wyliczono z `pg_get_viewdef` i `pg_get_constraintdef`. Sam fakt różnego odcisku **nie rozstrzyga**, czy różni się semantyka, czy wyłącznie tekst zwracany przez PostgreSQL. Porównanie liczby wierszy nie jest porównaniem całej zawartości wierszy. Triggery wewnętrzne nie były osobno porównywane; relacje FK sprawdzono oddzielnie. Powyższy odcisk migracji używa nazw i batchy, więc nie jest tym samym algorytmem co odcisk z wcześniejszego raportu.

## Stan po zatrzymaniu

- Zgodnie z ograniczeniem czyszczenia wyłącznie po udanej weryfikacji **nie usunięto** kontenera ani wolumenu testowego. Kontener testowy pozostaje uruchomiony bez sieci; wolumen ma wskazaną wyżej etykietę. Wymagają późniejszej decyzji właściciela.
- Źródłowy kontener PostgreSQL nadal `running` i `healthy`, z oryginalnym wolumenem; udokumentowany endpoint API na porcie 8001 zwrócił HTTP 200.
- Kontrola pod omyłkowo użytym portem 8000 zwróciła HTTP 503; nie jest to udokumentowany endpoint Stage 6A.1. Następnie sprawdzono właściwy port 8001.
- Nie uruchomiono migracji ani seederów aplikacji, nie zmieniono danych źródłowych, ról, uprawnień, RAW/BOSS, istniejących kontenerów ani wolumenów. Nie wykonano deploymentu, commitu ani pusha.

## Główne wykonane polecenia

Wszystkie operacje Docker używały `--context default`. Wykonane pomocnicze skrypty i pełne zapytania porównawcze znajdują się w bieżącym katalogu sesji:

- `/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/stage6a1-snapshot-backup.py`
- `/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/restore-inventory.sql`
- Wyniki lokalne: `source-inventory.txt`, `restored-inventory.txt` i `archive-list.txt` w tym samym katalogu.

Polecenie uruchomienia skryptu:

```sh
python3 /home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/stage6a1-snapshot-backup.py
```

Wewnątrz niego użyto `docker --context default exec -i veltra-stage6a1-postgres-1` z `psql -X -qAt -v ON_ERROR_STOP=1`, wyeksportowano snapshot, wykonano `pg_dump --no-password --format=custom --snapshot="$1" -U "$POSTGRES_USER" -d "$POSTGRES_DB"` i sprawdzono archiwum przez `docker --context default exec -i veltra-stage6a1-postgres-1 pg_restore --list`. Hasła nie były wypisywane.

```sh
docker --context default volume create --label veltra.restore-test=b01845be954d42199ec77e8909766f7c veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c-data
docker --context default run --detach --pull never --name veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c --label veltra.restore-test=b01845be954d42199ec77e8909766f7c --network none --cpus 2 --memory 2g --pids-limit 256 --mount 'type=volume,source=veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c-data,target=/var/lib/postgresql/data' --mount 'type=bind,source=/home/veltra/backups/stage6a1/veltra-stage6a1-20261123T193945Z-b01845be954d42199ec77e8909766f7c.dump,target=/restore/source.dump,readonly' -e POSTGRES_USER=restore_check -e POSTGRES_DB=restore_check -e POSTGRES_HOST_AUTH_METHOD=trust sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b
docker --context default exec veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c pg_isready -U restore_check -d restore_check
docker --context default exec --user root veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c sha256sum /restore/source.dump
docker --context default exec --user root veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c pg_restore --exit-on-error --no-owner --no-acl --create -U restore_check -d restore_check /restore/source.dump
```

Inwentaryzację restore przeprowadzono w transakcji read-only za pomocą:

```sh
work=/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files
{ printf '%s\n' 'BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;'; cat "$work/restore-inventory.sql"; printf '%s\n' 'ROLLBACK;'; } | docker --context default exec -i veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c psql -X -qAt -v ON_ERROR_STOP=1 -U restore_check -d veltra_stage6a1 > "$work/restored-inventory.txt"
```

Porównanie `Counter` z plików inwentaryzacji zakończyło się kodem 1 z powodu wskazanych 54 rozbieżności definicji. Źródłowy odcisk całej inwentaryzacji SHA-256: `63722f484cfbdf08e2f70c2151b9b6785f6cdde66938a0a0a50a07a04da02058`; odtworzony: `658c2e9680a7f748cd73b2b4aa73c5109902e1f182acdcc7ffec01fdc4342ccc`.

Przed backupem jedno zapytanie diagnostyczne do `pg_constraint` zakończyło się błędem niejednoznacznego operatora `text || "char"`; poprawiono wyłącznie rzutowanie w kolejnym zapytaniu read-only. Po uruchomieniu kontenera pierwszy szablon `docker inspect` zakończył się błędem, ponieważ bind mount nie ma pola `Name`. Kolejny automatyczny test błędnie oczekiwał w `inspect` ścieżki hosta zamiast ścieżki przemapowanej przez Docker Desktop; rzeczywistą zawartość mountu zweryfikowano następnie sumą SHA-256. Żaden z tych błędów nie był pominiętym błędem `pg_restore`.

## Blokery przed 12 migracjami

1. Wyjaśnić 53 rozbieżności constraintów i jedną widoku w kontrolowanej analizie read-only; ustalić, czy są semantyczne, czy wynikają z kontekstu dekompilacji definicji.
2. Uzyskać pełny pozytywny wynik porównania i ustalić sposób weryfikacji ról, właścicieli oraz ACL dla rzeczywistego odtworzenia.
3. Rozstrzygnąć dalsze postępowanie z pozostawionymi zasobami testowymi. Nie usuwać ich bez potwierdzenia tożsamości i odpowiedniej autoryzacji.
4. Osobno uzyskać autoryzację na migracje, ich preflight i zgodny obraz aplikacji. Bieżące zadanie nie autoryzuje migracji.
