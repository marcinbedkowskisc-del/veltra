# Stage 6A.1 — build obrazów kandydata RAW

## Wynik

Obrazy lokalnego kandydata zbudowano i sprawdzono; **nie wdrożono ich i nie wykonano migracji**. Nie jest to autoryzacja synchronizacji Stage. Zatwierdzony worktree pozostał czysty.

- Gałąź: `release/stage6a1-raw-candidate`
- Commit: `795356faae84bd2185d615dc3764c97811d07184`
- Drzewo: `6d2a64d521fe3ee980c4bfa93a4ab835f9a4cdb7`
- Platforma zbudowanych obrazów: `linux/amd64`

| Zastosowanie | Lokalny tag | ID z `docker image inspect` |
|---|---|---|
| Frontend | `veltra-stage6a1-frontend:rc-795356faae84-20261123-complete` | `sha256:85a24393a503d83075a3efc0c88f06b59c58414ccd4ebe1b793e58852ae5c4fe` |
| Backend, migrator i queue | `veltra-stage6a1-backend:rc-795356faae84-20261123` | `sha256:63e14f50f0dffe93b07b7fe64d23d021a49ee71956326a48f84fb76ca55456d6` |

Tagi były wolne przed budową; nie nadpisano istniejących tagów Stage. Wstępny obraz frontendu `veltra-stage6a1-frontend:rc-795356faae84-20261123`, ID `sha256:389fc9636e4a780d2643faf0131b368736827fe7981efd26e99eadf7b91d3121`, pozostawiono bez usuwania, ale **nie jest wskazany w override ani zalecany do wydania**: brakowało w nim danych podglądu opisanych niżej.

## Źródło i build

Konteksty `frontend` i `backend` uzyskano wyłącznie przez `git archive` wskazanego commita, nie z zawartości worktree ani z istniejących `vendor`/`node_modules`. Użyto Docker context `default`, daemon `1163050e-c562-4a35-bd94-8c24de688587`. Build backendu wykonał `composer install` z lockfile (111 pakietów, w tym zależności developerskie); build frontendu wykonał `npm ci` z lockfile (93 pakiety, audyt npm zgłosił 0 podatności). `composer check-platform-reqs` w obrazie backendu: PASS; PHP 8.4.28, Laravel 13.40.0, wymagane rozszerzenia obecne.

Źródłowy Dockerfile frontendu kopiuje tylko katalog `frontend`. Pierwszy pełny test obrazu ujawnił, że test podglądu alergenów importuje plik JSON z `backend/database/data` i nie może go odnaleźć: 31 pakietów testów i 308 testów przeszło, jeden pakiet nie wystartował. Dlatego przygotowano osobny [Dockerfile pakowania frontendu](./Dockerfile.stage6a1-frontend-release): zachowuje polecenia zatwierdzonego Dockerfile, dodając jedynie kopiowanie tego pliku z archiwum tego samego commita do `/backend/database/data`. Kod ani zatwierdzony commit nie zostały zmienione. Suma dodatkowego Dockerfile: `79ea316861375eb8d2d798f3a20292a4e568e9c7b7c58eec4d8b41252359c466`; pliku danych: `8e6ebe85f49fe4663ead4d9d85962f39f47d24e33a9297b9e2774c41e17bd19e`.

Dokładny schemat wykonanych buildów, po rozpakowaniu `git archive 795356faae84bd2185d615dc3764c97811d07184` do nowego pustego katalogu `$SOURCE`:

```sh
docker --context default build --pull=false --progress=plain \
  --tag veltra-stage6a1-backend:rc-795356faae84-20261123 "$SOURCE/backend"
docker --context default build --pull=false --progress=plain \
  --build-context backend-data="$SOURCE/backend/database/data" \
  -f /home/veltra/projekty/veltra/Dockerfile.stage6a1-frontend-release \
  --tag veltra-stage6a1-frontend:rc-795356faae84-20261123-complete "$SOURCE/frontend"
```

Lockfile SHA-256: frontend `6f16405dfdbe7c919cf33c309542d25573058b14da807261e09fd546a55d2971`, backend `fa955ce6b4ae4bdfd30f3dfac281419c0fe9238136123cb54d2d8fd28ecec892`. BuildKit wskazał bazy `node:24-alpine@sha256:8a32c8cf649a49915a66e81e7a609223c708d0adabf288ee3f4077d765542f85`, `php:8.4-cli-alpine@sha256:d64c04fbee7a93c48226fe40380b5e121856d90739dadb7c74574f9995338700` i `composer:2@sha256:4ed71c38c6d788eb4e4e3747b5332f5b40d5b5a6fcadd5062a1a70986a118e0a`. Warstwa instalacji systemowej backendu pochodziła z cache BuildKit; Composer pobrał zależności w trakcie builda, a końcowy build frontendu użył cache wcześniejszego udanego `npm ci`.

**Ograniczenie odtwarzalności:** Dockerfile używają zmiennych tagów baz i nie przypinają wersji pakietów Alpine. Nie wykonano niezależnego builda bez cache ani nie wykazano identyczności bitowej. Lockfile, podane digesty i utrwalenie pomocniczego Dockerfile umożliwiają identyfikację wejść, ale same nie gwarantują powtórzenia ID obrazu. Obrazy znajdują się lokalnie, nie opublikowano ich. Frontend nadal działa jako serwer Vite w trybie developerskim, zgodnie z istniejącym Dockerfile; build produkcyjny jest kontrolą, nie serwowanym artefaktem. To rozwiązanie wyłącznie do lokalnego Stage, nie dowód gotowości produkcyjnej.

## Weryfikacja obrazów i kontraktu

| Kontrola | Wynik |
|---|---|
| Zawartość | Sumy wszystkich plików `backend/app`, `backend/routes`, `backend/database/migrations` oraz `frontend/src/rawMaterials` w obrazach odpowiadają archiwum commita. Dołączony JSON jest bajtowo zgodny przez SHA-256. |
| Migracje | Obraz backendu zawiera 61 plików migracji, identycznych ze źródłem; migracji nie uruchamiano. |
| Wycofany workflow | W `backend/app` i `backend/routes` obrazu brak nazw dwóch widoków i tabeli assessment wycofywanych przez `000059`. To kontrola statyczna tych ścieżek, nie dowód dla każdego możliwego klienta. |
| Frontend | W docelowym obrazie bez sieci: `npm ls --depth=0`, lint i `npm run build` (TypeScript i Vite): PASS; istniejące ostrzeżenia lint i rozmiaru chunka. Pełny test `npm test -- --maxWorkers=2 --reporter=dot`: **32 pliki, 320 testów PASS**. |
| Powtórzenie testów | Pierwszy pełny przebieg docelowego obrazu przy domyślnej współbieżności: 319 PASS, jeden błąd testu przywracania fokusu w `AdministrationUsersPage`; następny pełny przebieg z dwoma workerami przeszedł. Ten sam sporadyczny problem był wcześniej odnotowany; nie należy ukrywać niestabilności. |
| Vite | W osobnym krótkotrwałym kontenerze `--network none`: HTTP dla strony głównej, modułu podglądu i pliku JSON przez Vite: PASS. Początkowe odmowy połączenia wystąpiły przed zakończeniem startu serwera; próby ponowiono. |
| RAW | Testy frontendu obejmują `PACKAGE`, `NOMINAL_UNIT`, jednostki słownika, niepoprawne wymiary, `LEGACY_BASE_UNIT` i cenę z wcześniejszej rewizji. Backend i frontend w obrazach mają dokładnie zweryfikowany kod kandydata. Wcześniejsze 43 testy backendu na izolowanym PostgreSQL oraz symulacja 12 migracji przeszły dla tego samego drzewa; **nie powtórzono ich w tym zadaniu ani w nowych obrazach**. Pełnego end-to-end nowego stosu jeszcze nie wykazano. |

Szczegóły zakresu kontraktu i wcześniejszych testów: [raport integracyjny RAW](./VELTRA_STAGE6A1_RAW_CONTRACT_INTEGRATION_REPORT.md) oraz [plan wydania](./VELTRA_STAGE6A1_ACCELERATED_RELEASE_PLAN.md).

## Compose

Przygotowano osobny [override obrazów](./compose.stage6a1.release-795356faae84.yaml), SHA-256 `94079f59840b86077d1710c7260247a9ed8e709ea445c4521c74261190a200ab`. Ustawia powyższe tagi dla czterech usług i usuwa ich sekcje `build` przez `!reset null`, aby przypadkowo nie zbudować ich z modyfikowanego worktree `main`.

Bez uruchamiania usług porównano w pamięci wynik `docker compose config --format json`, z profilem `migrations` i istniejącym plikiem środowiska, dla:

1. `/home/veltra/projekty/veltra/compose.yaml` + `/home/veltra/projekty/veltra/compose.stage6a1.yaml`;
2. tych samych plików z dołączonym override wydania.

**PASS:** jedyne różnice to cztery wskazane obrazy i usunięcie ich sekcji `build`. Pozostała konfiguracja, w tym środowisko usług, sieć, porty, healthchecki i wolumeny, jest identyczna; wartości sekretów nie były wypisywane. PostgreSQL wskazuje hardened `veltra-postgres:17-alpine`, nie ma bind mountu skryptu inicjalizacyjnego, a `veltra-stage6a1_postgres-data` i `veltra-stage6a1_redis-data` pozostają zewnętrzne. Pierwsze porównanie bez profilu `migrations` nie obejmowało usługi `migrate` i zakończyło się błędem kontroli; powtórzono je z profilem.

## Stage i warunki dalszego wdrożenia

Końcowy odczyt: istniejące ID kontenerów Stage bez zmian; PostgreSQL, backend i frontend `healthy`, queue `running`, API `/api/health` HTTP 200. PostgreSQL nadal używa obrazu `sha256:f1e5168e94083519d7b12a8516e02439c6bed43cb48424b5d9b3acc1d55aa99d` i wolumenu `veltra-stage6a1_postgres-data`. Nie zmieniano Stage, PostgreSQL, wolumenów, danych, sekretów, RBAC ani readiness; nie wykonywano migracji, deploymentu, pusha ani merge.

Przed jakąkolwiek synchronizacją potrzebne są: **osobna autoryzacja właściciela**, ponowny preflight bieżących 49/12 migracji, konfliktów, tożsamości i integralności obrazów, uprawnień migratora/runtime, dostępności sekretów i administratora; sprawdzenie aktualności backupu oraz recovery (role globalne, ownership i ACL pozostają ograniczeniem wcześniejszego testu). Plik środowiska znajduje się w przestrzeni sesji i nie należy zakładać jego trwałości. Należy zachować izolowane zasoby restore. Stary backend zależy od widoków usuwanych przez `000059`; wymagane jest skoordynowane wdrożenie wraz z migracjami zgodnie z planem, bez automatycznego rollbacku lub restore. Autorytatywny standard VSEF pozostaje niedostępny; zgodności nie potwierdzono.
