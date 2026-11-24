# VELTRA 2.0 — hardening obrazu PostgreSQL

**Data:** 2026-11-23  
**Zakres:** zmiany repozytorium i walidacja bez uruchamiania usług, budowania obrazu ani zmian wolumenów i baz.

## Wynik

PostgreSQL korzysta teraz z definicji obrazu `veltra-postgres:17-alpine`, budowanego z `postgres:17-alpine`. Skrypt inicjalizacyjny jest kopiowany do obrazu z prawami `0755`; jego treść nie została zmieniona. Konfiguracje Compose nie montują już tego pliku z hosta w runtime.

**Zmiana nie została wdrożona ani zbudowana.** Przed późniejszym wdrożeniem wymagane są osobna autoryzacja, potwierdzenie właściwego demona i akceptacja ryzyka dla danych. Istniejący kontener Stage 6A.1 nadal jest zatrzymany.

## Zmienione pliki

- `docker/postgres/Dockerfile` — nowy obraz bazowy PostgreSQL 17 Alpine i `COPY --chmod=755` skryptu.
- `docker/postgres/.dockerignore` — nowy, mały kontekst; dopuszcza Dockerfile i skrypt, wyklucza `.env` i `.env.*`. Jest wymagany także przez istniejący walidator bezpieczeństwa środowisk testowych.
- `compose.yaml` — obraz i build PostgreSQL, usunięty wyłącznie bind mount skryptu; named volume bez zmian.
- `compose.testing.yaml`, `compose.stage2c-security-test.yaml`, `compose.stage3e-b1-test.yaml`, `compose.browser-preview.yaml` — ta sama zmiana dla niezależnych definicji PostgreSQL; istniejące `tmpfs`, sieci i pozostałe ustawienia pozostają bez zmian. `compose.stage3e-b2-test.yaml`, `compose.stage3e-b3-test.yaml` i `compose.stage3e-b41-test.yaml` dziedziczą usługę z B1.
- `README.md` — dodano budowanie obrazu PostgreSQL do przykładów Primary i izolowanych testów oraz opis wymagania budowy dla preview.
- `VELTRA_POSTGRES_DOCKER_HARDENING_REPORT.md` — niniejszy raport.

Pozostałe wcześniejsze zmiany w worktree, w tym `backend/app/Domain/RawMaterials/RawMaterialService.php`, nie były modyfikowane ani cofane.

## Weryfikacja

| Kontrola | Wynik |
|---|---|
| `docker compose config --format json`, bez daemona, wyłącznie syntetyczne zmienne | **PASS** dla bazowego Compose, Primary, Stage 6A.1, rzeczywistego historycznego override’u obrazów, `compose.testing.yaml`, Stage 2C, Stage 3E B1 i browser preview. |
| Porównanie wyrenderowanej konfiguracji z `HEAD` | **PASS**: poza zmianą obrazu/build i usunięciem bind mountu skryptu, konfiguracje są identyczne, w tym środowisko, healthcheck, polityki restartu, sieci, porty, tmpfs i pozostałe mounty. |
| Walidator `validate_compose_config` | **PASS** dla wszystkich 6 izolowanych konfiguracji testowych, w tym B2/B3/B41 przez `extends` oraz profili Stage 2C. |
| `python3 -m unittest discover -s scripts/tests -p 'test_compose_test_cleanup.py'` | **PASS**, 16 testów. |
| `git diff --check`, `sh -n docker/postgres/10-create-runtime-role.sh`, źródło zwykły plik wykonywalny | **PASS**. |
| Dockerfile i kontekst | Sprawdzono instrukcje `FROM`, `COPY --chmod=755`, obecność pliku źródłowego i `.dockerignore`. **Nie wykonano rzeczywistego builda ani walidacji Dockerfile przez builder**; działanie `COPY` w docelowym builderze nadal wymaga potwierdzenia po autoryzacji. |
| Nazwy i cele wolumenów | **PASS**: porównanie konfiguracji oraz montowania PostgreSQL. `postgres-data` nadal montuje `/var/lib/postgresql/data`; Stage 6A.1 nadal wskazuje `veltra-stage6a1_postgres-data`. Primary nadal używa swojego zewnętrznego wolumenu. |
| `compose.stage6a1.yaml` oraz historyczny `compose.stage6a1-images.yaml` | **PASS** dla obu osobno z bazowym Compose: nie nadpisują obrazu ani mountów PostgreSQL. Historyczny override innych usług zachowano bez zmian. |

Komendy renderujące nie wyświetlały rzeczywistych wartości środowiska; konfiguracje porównano programowo. Nie uruchomiono kontenerów, migracji, backupu ani restore.

### Istniejący katalog danych i inicjalizacja

Odczytano `docker-entrypoint.sh` z istniejącego zatrzymanego kontenera Stage 6A.1 bez jego startu. Skrypt obrazu sprawdza niepusty `$PGDATA/PG_VERSION`; gdy istnieje, ustawia `DATABASE_ALREADY_EXISTS`, pomija `docker_process_init_files` i zgłasza `Skipping initialization`. Zachowane logi tego kontenera potwierdzają, że przy ostatnim udanym starcie istniejąca baza przeszła właśnie tę gałąź.

Nasz Dockerfile nie zmienia `ENTRYPOINT`, `CMD` ani `PGDATA`. Przy tej samej wersji obrazu bazowego i poprawnie dołączonym istniejącym katalogu danych zachowanie powinno pozostać takie samo. Nie był wykonywany nowy test uruchomienia ani odczyt zawartości wolumenu; ponowne potwierdzenie po zbudowaniu i uruchomieniu wymaga osobnej zgody.

## Docker context i sprostowanie wcześniejszej diagnozy

Odczyt w tym zadaniu wykazał **dwa różne demony**:

| Połączenie | ID | Root / identyfikacja |
|---|---|---|
| `docker --context default`, `unix:///var/run/docker.sock` | `1163050e-c562-4a35-bd94-8c24de688587` | `docker-desktop`, `/var/lib/docker`; to on obecnie pokazuje kontener `a3b7753a…` i wolumen `veltra-stage6a1_postgres-data`. |
| `unix:///run/user/1000/veltra-test-docker/docker.sock` | `25b00a87-90c6-4ce2-900b-be9adf2f13eb` | rootless, `/home/veltra/.local/share/veltra-test-docker`, etykieta `com.veltra.daemon-role=disposable-test`; brak tego kontenera i wolumenu Stage. |

Wcześniejszy raport błędnie utożsamił daemon obsługujący `default` z rootless `disposable-test`: są odrębne. Wskazany tam restart rootless demona nie dowodzi restartu demona Stage ani dokładnej przyczyny jego awarii. `docker desktop status` nadal nie potrafi pobrać stanu Desktop, mimo że `docker --context default info` odpowiada. Nie rozstrzygnięto tego rozdźwięku.

**Kandydat dla późniejszej operacji Stage:** `docker --context default` z powyższą tożsamością, kontenerem, projektem i wolumenem, zgodnie z istniejącą procedurą Stage. Właściciel musi przed operacją potwierdzić, że to właściwy i zatwierdzony daemon; samo dopasowanie etykiet nie jest autoryzacją. Nie używać daemona `disposable-test` do Stage.

## Pozostałe ryzyka i zgodność

- `postgres:17-alpine` jest tagiem zmiennym. Przed buildem należy potwierdzić bazowy image ID i wersję względem obecnego Stage; w przeciwnym razie można przypadkowo wprowadzić aktualizację PostgreSQL. Nie wykonywano pull ani buildu.
- `veltra-postgres:17-alpine` jest wspólnym tagiem lokalnym. Obraz musi zostać zbudowany w **każdym właściwym daemonie**; po zmianie skryptu należy go przebudować. W browser preview istnieje `pull_policy: never`, więc wymaga jawnego wcześniejszego buildu; ten sam tag nie udostępnia obrazu między osobnymi daemonami.
- Historyczny Stage override znajduje się w katalogu sesji i jest nieśledzony; jego dostępność i dokładna zawartość muszą być sprawdzone przed użyciem. `compose.stage6a1.yaml` jawnie wymaga zewnętrznego wolumenu; historyczny override korzysta z istniejącej nazwy projektowej. Nie zastępować jednego drugim bez weryfikacji.
- `scripts/deploy_stage6a1.py` jest narzędziem aktualizacji aplikacji, wymaga już zdrowego PostgreSQL i nie buduje nowego obrazu PostgreSQL; nie jest procedurą naprawy tego zatrzymanego kontenera.
- Nie przetestowano budowania obrazu, pierwszej inicjalizacji pustego klastra ani restartu po zmianie. Inicjalizacja istniejącej bazy nie może być przedmiotem prób resetu.
- Świeży i sprawdzony backup bazy nadal nie jest dostępny; jego wykonanie wymaga działającego PostgreSQL oraz odrębnej autoryzacji. Przed jakimkolwiek startem właściciel musi zaakceptować ryzyko zapisu do istniejącego klastra i możliwego automatycznego WAL recovery.

## Minimalna procedura po osobnej autoryzacji — nie wykonano

1. Właściciel zatwierdza zakres: build obrazu i odtworzenie **wyłącznie** kontenera `postgres`, ryzyko oraz właściwy daemon. Przed startem ponownie potwierdzić `default`, ID demona, projekt `veltra-stage6a1`, aktualny kontener i jego mount `veltra-stage6a1_postgres-data`, obraz bazowy, plik środowiskowy oraz oba dokładne pliki Compose. Nie ujawniać sekretów.
2. Na zatwierdzonym daemonie zbudować wyłącznie PostgreSQL. Przy użyciu dokładnej historycznej konfiguracji:

   ```bash
   docker --context default compose -p veltra-stage6a1 \
     --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
     -f /home/veltra/projekty/veltra/compose.yaml \
     -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml \
     build postgres
   ```

3. Sprawdzić wynik buildu i obraz, po czym — wyłącznie po potwierdzeniu tożsamości i mountu — odtworzyć sam kontener:

   ```bash
   docker --context default compose -p veltra-stage6a1 \
     --env-file /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env \
     -f /home/veltra/projekty/veltra/compose.yaml \
     -f /home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/compose.stage6a1-images.yaml \
     up -d --no-deps --force-recreate postgres
   ```

4. Potwierdzić `healthy`, ten sam named volume i target, brak bind mountu skryptu, log `Skipping initialization`, gotowość PostgreSQL oraz API health. Nie uruchamiać provisioningu, migracji ani seederów. Po odzyskaniu dostępności osobno uzyskać autoryzację świeżego backupu i izolowanego testu restore.
5. Jeśli tożsamość demona/projektu/wolumenu nie zgadza się, base image jest inny, build nie przechodzi, skrypt nie jest poprawny, log nie potwierdza pominięcia inicjalizacji lub PostgreSQL nie jest healthy — **stop**, bez obchodzenia skryptu, ponownych prób resetu, `down -v`, usuwania wolumenu lub zmiany innych usług.
