# VELTRA 2.0 — diagnoza awarii PostgreSQL Stage 6A.1

**Data:** 2026-11-23

**Zakres:** tylko odczyt istniejącego stosu `veltra-stage6a1`.

**Wynik:** przyczyna bezpośrednia potwierdzona; nie wykonano naprawy ani startu usług.

## Wniosek

Kontener PostgreSQL nie dochodzi do uruchomienia procesu `postgres`. Docker/runc odrzuca jego konfigurację mountów podczas inicjalizacji kontenera: bind mount skryptu `10-create-runtime-role.sh` kończy się błędem niezgodności typu „not a directory” (plik kontra katalog). Kontener ma `ExitCode=127`, lecz zarejestrowany błąd jest błędem OCI przy tworzeniu procesu, a nie błędem SQL ani udokumentowanym wyjściem serwera PostgreSQL z kodem 127.

Plik źródłowy w repozytorium jest zwykłym plikiem. Nie ustalono, dlaczego jego ścieżka została odwzorowana przez Docker Desktop/WSL na źródło mountu, którego runc nie może zamontować jako pliku. Jest to niewiadoma na poziomie mechanizmu mapowania Docker Desktop; nie zmienia potwierdzonego bezpośredniego punktu awarii.

Nie ma dowodu, że obecna awaria wynika z uszkodzenia wolumenu danych. Ten konkretny błąd następuje przy mountowaniu skryptu inicjalizacyjnego i przed startem procesu PostgreSQL. Historyczne logi potwierdzają, że istniejący klaster wcześniej przeszedł automatyczne odzyskiwanie i przyjmował połączenia. Nie da się wykluczyć odrębnego, latentnego problemu danych bez uruchomienia PostgreSQL; zawartości wolumenu nie sprawdzano.

## Konfiguracja rzeczywistego kontenera

- **Kontener:** `veltra-stage6a1-postgres-1`
- **ID:** `5ac149e0759dd71fee1e6f84e908536714dc4dc7cfae4993dee94bd34e6922fc`
- **Projekt Compose:** `veltra-stage6a1`
Stan: `exited`, exit code `127`; `StartedAt=2026-11-16T14:15:41Z`, `FinishedAt=2026-11-16T19:52:54Z`.

Inspekcja etykiet kontenera wskazuje rzeczywiste pliki Compose:

- `/home/veltra/projekty/veltra/compose.yaml`
- `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml`

Używany plik środowiskowy to `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env`; sprawdzono wyłącznie jego istnienie i metadane, nie odczytano wartości. Zawartość override’u Stage 6A.1 zmienia obrazy usług aplikacyjnych, nie konfigurację usługi PostgreSQL. Kontener nie ma w etykietach `compose.stage6a1.yaml` z katalogu repozytorium jako źródła konfiguracji.

Konfiguracja PostgreSQL pochodzi z usługi `postgres` w [compose.yaml](./compose.yaml):

| Pole | Wartość zweryfikowana |
|---|---|
| Skonfigurowany obraz | `postgres:17-alpine` |
| Obraz lokalny / digest | `sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b` |
| Entrypoint | `docker-entrypoint.sh` |
| Command | `postgres` |
| Ścieżka procesu / argumenty | `docker-entrypoint.sh` / `["postgres"]` |
| Katalog danych | wolumen `veltra-stage6a1_postgres-data` → `/var/lib/postgresql/data` (`rw`) |
| Skrypt inicjalizacyjny | bind `./docker/postgres/10-create-runtime-role.sh` → `/docker-entrypoint-initdb.d/10-create-runtime-role.sh` (`ro`) |
| Port hosta | brak mapowania portu PostgreSQL na hosta |
| Restart policy | `unless-stopped` |

Sam skrypt jest zwykłym plikiem hosta (`mode 755`). Wolumen `veltra-stage6a1_postgres-data` istnieje, ma sterownik `local` i etykietę projektu Compose `veltra-stage6a1`. Oddzielny istniejący wolumen Redis to `veltra-stage6a1_redis-data`.

## Dowód przyczyny

`docker inspect` zwraca w `State.Error`:

```text
failed to create task for container: failed to create shim task:
OCI runtime create failed: runc create failed: unable to start container process:
error during container init: error mounting
"/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/<mapped-source>"
to rootfs at "/docker-entrypoint-initdb.d/10-create-runtime-role.sh":
... not a directory: Are you trying to mount a directory onto a file
(or vice-versa)?
```

Ścieżka `<mapped-source>` jest wewnętrznym, wygenerowanym przez Docker Desktop/WSL źródłem bind mountu; z hosta nie można jej `stat`-ować. Jawny hostowy plik `docker/postgres/10-create-runtime-role.sh` istnieje i jest plikiem regularnym. Nie stwierdzono błędu montowania ścieżki `/var/lib/postgresql/data`.

Logi kontenera zawierają wcześniejsze, udane starty obrazu PostgreSQL `17.11`:

- komunikat `Database directory appears to contain a database; Skipping initialization`;
- `database system was not properly shut down; automatic recovery in progress`;
- zakończenie redo i checkpointu oraz `database system is ready to accept connections`;
- dalsze checkpointy, ostatnie widoczne o `2026-11-16T19:05:46Z`.

W logach nie ma procesu PostgreSQL uruchomionego po tym czasie ani błędu procesu odpowiadającego obecnemu `State.Error`. Bieżące niepowodzenie występuje przed uruchomieniem entrypointu, więc nie wskazuje na uszkodzony obraz czy błąd konfiguracji serwera. Poprzednie udane odzyskanie wspiera rozróżnienie od problemu wolumenu, ale nie jest pełnym testem jego obecnego stanu.

## Zakres zasobów i proponowana minimalna naprawa

Aktualny projekt zawiera sześć kontenerów: backend (unhealthy), queue, frontend (healthy), PostgreSQL (exited), Redis (healthy) i Mailpit (healthy). Istnieją dwa nazwane wolumeny wymienione powyżej.

Po **osobnej zgodzie na naprawę**, zachowując istniejący wolumen i używając dokładnych plików Compose z etykiet kontenera, najmniejszy proponowany krok to odtworzenie wyłącznie kontenera PostgreSQL, aby Docker Desktop ponownie rozwiązał bind mount pliku:

```bash
docker compose -p veltra-stage6a1 \
  --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
  -f /home/veltra/projekty/veltra/compose.yaml \
  -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml \
  up -d --no-deps --force-recreate postgres
```

To jest **propozycja, nie wykonana komenda**. Przed nią administrator powinien potwierdzić, że hostowy plik bind mountu jest dostępny jako plik dla Docker Desktop oraz — jeśli wymagane dla polityki ochrony danych — wykonać zatwierdzony, spójny backup/snapshot istniejącego wolumenu.

### Dlaczego ta komenda zachowuje pozostałe zasoby

- Cel wskazuje wyłącznie usługę `postgres`; `--no-deps` nie uruchamia ani nie odtwarza backendu, queue, frontendu, Redis ani Mailpit.
- Compose używa istniejącego, nazwowego wolumenu `veltra-stage6a1_postgres-data`, który został zweryfikowany jako obecny. Komenda nie zawiera `down`, `-v`, `--renew-anon-volumes` ani polecenia usunięcia wolumenu. Odtworzenie kontenera odłącza i ponownie podłącza ten sam wolumen, a nie tworzy pustego wolumenu anonimowego.
- Pozostałe kontenery i wolumen `veltra-stage6a1_redis-data` nie są celami tej operacji.

### Ryzyka i warunki przerwania

- Odtworzenie kontenera samo w sobie nie usuwa wolumenu, ale start PostgreSQL zapisuje do niego: przy nieoczekiwanym zatrzymaniu serwer może wykonać zwykłe odzyskiwanie WAL i checkpoint. Dlatego backup/snapshot przed naprawą jest zalecany, jeśli wymagana jest możliwość powrotu do dokładnego stanu plików.
- Jeśli bind mount nadal kończy się błędem, **nie** usuwać ani nie omijać skryptu w ramach tej procedury. Przerwać i osobno zdiagnozować mapowanie Docker Desktop/WSL lub uzyskać zatwierdzenie zmiany konfiguracji.
- Po udanym starcie sprawdzić, że mount danych nadal wskazuje na `veltra-stage6a1_postgres-data`, log zawiera `database system is ready to accept connections`, a API health wraca do oczekiwanego stanu. Nie uruchamiać migracji ani provisioningu w ramach tej naprawy.

## Czynności niewykonane

Nie uruchamiano, restartowano, odtwarzano ani usuwano kontenerów; nie zmieniano wolumenów ani plików bazy; nie uruchamiano migracji; nie odczytywano sekretów; nie zmieniano danych logowania, uprawnień, readiness ani aktywacji; nie wdrażano, nie commitowano i nie pushowano zmian.
