# VELTRA 2.0 — diagnoza powracającego błędu bind mountu PostgreSQL

**Data:** 2026-11-23  
**Zakres:** tylko diagnostyka; nie uruchamiano ani nie odtwarzano kontenerów, nie zmieniano konfiguracji i nie dotykano wolumenu.

## Wniosek

**Najbardziej prawdopodobna przyczyna:** po restarcie dystrybucji WSL i demona Docker istniejący kontener został odtworzony z zapisanym hostowym źródłem bind mountu wygenerowanym przez Docker Desktop (`/run/desktop/mnt/host/wsl/...`). To źródło nie jest dostępne w bieżącej przestrzeni nazw WSL/demona, a runc nie może zamontować go jako pliku pod `/docker-entrypoint-initdb.d/10-create-runtime-role.sh`. Błąd kończy się `not a directory` — nie jest błędem PostgreSQL ani dowodem uszkodzenia wolumenu.

Pewność co do **bezpośredniej przyczyny** jest wysoka: `State.Error` wskazuje dokładny mount, a ścieżka źródłowa nie istnieje w bieżącej przestrzeni nazw. Związek awarii z restartem WSL/demona jest mocno poparty zgodnością czasów. Nie udało się natomiast potwierdzić, dlaczego Desktop wygenerował lub zachował nieprawidłowe mapowanie ani czy restartował się sam Windowsowy Docker Desktop.

## Udany start a ponowna awaria — oś czasu

| Czas lokalny (+01) | Ustalenie |
|---|---|
| 23.11, 16:03:06 | Utworzenie bieżącego kontenera `a3b7753a…`; logi pokazują start PostgreSQL 17.11, odzyskanie WAL i `database system is ready to accept connections`. W raporcie odzyskania później potwierdzono `healthy` i HTTP 200 API. |
| 23.11, do 17:17:32 | Zachowane logi kontenera zawierają normalną pracę PostgreSQL oraz późniejsze zapytania aplikacyjne. |
| 23.11, 18:43:06 | Dziennik bieżącej dystrybucji WSL rejestruje `systemd: Shutting down`. |
| 23.11, 18:44:16–18:44:23 | Rozpoczyna się nowy boot dystrybucji; systemd uruchamia `veltra-test-dockerd.service`, daemon ładuje/odtwarza kontenery i kończy inicjalizację. `FinishedAt` kontenera to 18:44:23.160 — ten sam moment, w którym daemon kończy start. |
| Stan obecny | Kontener `exited`, `RestartCount=0`, polityka `unless-stopped`. `State.Error` wskazuje błąd OCI file-vs-directory dla źródła bind mountu. |

`docker events` nie zwróciło zachowanych zdarzeń ani dla nazwy/ID kontenera, ani dla demona; dokładna sekwencja `stop`/`start` nie jest więc odtwarzalna z event streamu. `docker logs` zachowało wcześniejszy udany start, ale nie opisuje błędu runc z próby ponownego utworzenia procesu.

### Ważne ograniczenie diagnostyki Docker Desktop

Bieżący kontekst CLI wskazuje `unix:///var/run/docker.sock`. Odpowiadający mu proces w tym środowisku jest rootless daemonem `veltra-test-dockerd.service`, z etykietą `com.veltra.daemon-role=disposable-test` i katalogiem danych `veltra-test-docker`. `docker desktop status` zwraca, że nie może pobrać stanu i Docker Desktop nie działa. Zatem lokalny dziennik potwierdza restart dystrybucji WSL i **tego** demona, ale nie potwierdza restartu Windowsowego Docker Desktop ani jego logów. Nie należy utożsamiać odpowiedzi `docker info` „Docker Desktop” z dowodem, że to CLI jest połączone z aktywnym silnikiem Desktop.

## Fakty potwierdzone

- Konfiguracja w [compose.yaml](./compose.yaml) używa krótkiej składni file-to-file:
  `./docker/postgres/10-create-runtime-role.sh:/docker-entrypoint-initdb.d/10-create-runtime-role.sh:ro`.
- `docker inspect` pokazuje host source:
  `/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/bddb9986b2324878053b05ed93f9458da73105a723a991fcab36887959f46020`
  i target będący plikiem. W `State.Error` runc raportuje `not a directory` dla tego mountu.
- `/run/desktop` oraz wskazany wygenerowany source są **nieobecne w bieżącej przestrzeni nazw WSL**. To potwierdza niedostępność ścieżki dla aktualnie sprawdzanego środowiska; samo w sobie nie opisuje widoku plików wewnątrz nieuruchomionej maszyny Desktop.
- Jawne źródło repozytorium nadal jest plikiem regularnym `0755`; `realpath` rozwiązuje je prawidłowo. `mtime` pliku: 4.11.2026; katalogu `docker/postgres`: 3.11.2026. `git status` nie wykazał zmian w Compose ani w skrypcie; tryb pliku w HEAD to `100755`. Nie ma dowodu zmiany hostowego źródła po odzyskaniu.
- Wolumen pozostaje odrębnym mountem: `veltra-stage6a1_postgres-data` → `/var/lib/postgresql/data`. Błąd dotyczy skryptu inicjalizacyjnego, nie ścieżki danych.
- Bieżące `docker desktop status` i brak dostępnych zdarzeń ograniczają możliwość potwierdzenia stanu/diagnozy właściwego Desktop engine.

## Fakty a hipotezy

**Potwierdzone:** PostgreSQL uruchomił się po wcześniejszym odzyskaniu; później dystrybucja WSL przeszła zamknięcie i nowy boot; rootless daemon wystartował ponownie; obecny kontener ma błąd mountu źródła pliku; źródłowy plik repozytorium nie wykazuje zmian.

**Najbardziej prawdopodobna interpretacja:** zapisany mount zawiera Desktop-owy, wewnętrzny path zamiast stabilnego źródła hosta dostępnego po restarcie. Przy odtwarzaniu kontenera po restarcie demona mapowanie nie zostało poprawnie odtworzone albo kontener jest obecnie obsługiwany przez inny daemon/kontekst niż ten, który utworzył mapowanie. Zbieżność `FinishedAt` z uruchomieniem demona i nieobecność `/run/desktop` wspierają tę hipotezę.

**Niepotwierdzone:** czy przyczyną źródłową jest błąd/wyścig Docker Desktop WSL integration, przełączenie kontekstu lub import metadanych kontenera do disposable-test daemonu; czy maszyna Windows/WSL VM również restartowała się; czy runc widział dokładnie ten sam stan ścieżki w silniku Desktop. Dostępne logi nie pozwalają rozstrzygnąć tych wariantów. Nie ma podstaw, by przypisać awarię zmianie uprawnień skryptu, jego zawartości ani danych PostgreSQL.

## Trwała, minimalna rekomendacja

Usunąć zależność startu PostgreSQL od runtime bind mountu pojedynczego pliku. Zamiast mapować skrypt z hosta, **wbudować go w obraz PostgreSQL**:

1. Dodać mały `docker/postgres/Dockerfile`, bazujący na zweryfikowanym obrazie PostgreSQL i kopiujący `10-create-runtime-role.sh` do `/docker-entrypoint-initdb.d/` z trybem wykonywalnym.
2. Zmienić definicję usługi `postgres` w Compose tak, aby budowała/używała tego obrazu, i usunąć tylko bind mount skryptu.
3. Zachować istniejący named volume `veltra-stage6a1_postgres-data` bez zmian. Nie wykonywać `down -v`, odtwarzania danych ani migracji.
4. Przed kolejną operacją upewnić się, że Docker CLI wskazuje na właściwy, zatwierdzony daemon/Compose project, a nie `disposable-test` daemon.

To wymaga **zmiany Compose i dodania Dockerfile oraz przebudowania obrazu**. Nie wymaga zmiany ustawień Docker Desktop ani modyfikacji hostowego systemu plików/skryptu. Powoduje, że start kontenera nie potrzebuje mapowania `/run/desktop/...`. Istniejący klaster ma już katalog danych, więc entrypoint PostgreSQL pominie ponowne wykonanie skryptów inicjalizacyjnych; dodanie skryptu do obrazu nie uruchamia go ponownie ani nie zmienia ról w istniejącej bazie.

Samo przejście na bind całego katalogu lub ustawienie `bind.create_host_path: false` może ograniczyć pomyłki file-vs-directory, lecz nadal zależy od hostowego mapowania Docker Desktop i nie usuwa prawdopodobnego mechanizmu nawrotu. Restart Desktop/WSL może odświeżyć mapowania doraźnie, ale nie jest trwałą naprawą.

## Zakres niewykonanych działań

Nie uruchamiano, nie restartowano ani nie odtwarzano kontenerów; nie zmieniano Compose, hostowego pliku ani wolumenu; nie wykonywano backupu, restore, migracji ani zmian aplikacyjnych. Nie zmieniano readiness, uprawnień ani poświadczeń.
