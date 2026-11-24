# Stage 6A.1 — przyspieszone przygotowanie wydania

## Decyzja

**Kandydat źródeł i testy są gotowe do przeglądu; wydanie nie ma jeszcze zatwierdzonego commita ani zbudowanych obrazów.** Bieżące kontrole nie wykazały biznesowych rekordów blokujących migracje. **Nie usuwać danych:** nie wskazano żadnego rekordu do usunięcia. Właściciel potwierdził, że syntetyczne dane biznesowe nie mają realnej wartości; nie dotyczy to usunięcia administratora, RBAC, konfiguracji instalacji ani zabezpieczeń.

Nie uruchomiono migracji Stage, nie wdrożono aplikacji, nie zmieniono gotowości ani aktywacji i nie wykonano commita/pusha. Dalsza synchronizacja wymaga osobnej autoryzacji właściciela.

## Kandydat źródeł

- Bazowy commit `main`: `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6`.
- Przeglądalny patch: [VELTRA_STAGE6A1_RELEASE_CANDIDATE.patch](./VELTRA_STAGE6A1_RELEASE_CANDIDATE.patch), SHA-256 `85492cf36ac458cca8c15d52659a2bae536e8054aee8dcc683acf56c91fabc96`.
- Drzewo Git kandydata: `6d2a64d521fe3ee980c4bfa93a4ab835f9a4cdb7`. Utworzono je przy użyciu oddzielnego indeksu z `HEAD` i patcha; indeks bieżącego worktree nie został zmieniony. **To drzewo, nie commit ani zatwierdzona rewizja**; niepowiązany obiekt Git może zostać usunięty przez garbage collection, dlatego trwałym materiałem do odtworzenia jest patch i bazowy commit.
- Patch zawiera wyłącznie funkcjonalny fragment `backend/app/Domain/RawMaterials/RawMaterialService.php`, test `backend/tests/Feature/RawMaterialRevisionDataModelTest.php` oraz dziewięć plików frontendu: `RawMaterialCreateForm.tsx`, `RawMaterialCreateForm.test.tsx`, `RawMaterialEditForm.tsx`, `RawMaterialEditForm.test.tsx`, `RawMaterialPricingFields.tsx`, `api.ts`, `api.test.ts`, `create.ts`, `create.test.ts` w `frontend/src/rawMaterials/`.
- Wykluczono wcześniejsze formatowanie poza zmienioną funkcją backendu oraz niezwiązane zmiany README, Compose, hardeningu PostgreSQL, nieśledzone Dockerfile i raporty. Zmiany te pozostawiono w istniejącym worktree. W działającej konfiguracji zachować już wdrożony hardening PostgreSQL; nie zastępować go konfiguracją z czystego `HEAD`.
- Przegląd kontraktu i szczegóły poprawki: [raport integracyjny RAW](./VELTRA_STAGE6A1_RAW_CONTRACT_INTEGRATION_REPORT.md). Właściciel poprzednio wybrał zachowanie historycznej podstawy ceny bez niejawnego przeliczania. Obecnie Stage ma **0 zdarzeń ceny RAW**; dalsze badanie wyłącznie historycznych syntetycznych cen nie jest wymaganym etapem uruchomienia. Nie osłabiano walidacji.

Po przeglądzie właściciel może osobno zatwierdzić commit: utworzyć osobny czysty worktree na wskazanym commitcie, zastosować patch, sprawdzić wynik `git write-tree` względem powyższego drzewa i przejrzeć diff. Dopiero przy osobnej zgodzie utworzyć commit i zapisać jego ID. Nie wykonywać commita z obecnego zmodyfikowanego worktree ani nie włączać przypadkowo pozostałych zmian.

## Aktualny preflight — odczyt tylko do odczytu

Docker context `default`, daemon `1163050e-c562-4a35-bd94-8c24de688587`; projekt `veltra-stage6a1`; PostgreSQL `79317d539f35ba9186cd3b6518252be9e5eb89d847087cd0bd382ce466c2fccf`, 17.11, `healthy`, wolumen `veltra-stage6a1_postgres-data`; API `/api/health` HTTP 200. Baza `veltra_stage6a1`: **49 unikalnych migracji**, batch maks. 4; dokładna zgodność pierwszych 49 z kandydatem, **12 oczekujących**, brak nieznanych nazw. Zapytania były agregujące, `REPEATABLE READ READ ONLY`; nie odczytywano pojedynczych kont użytkowników ani danych dokumentów. Kontrole wykonano w dwóch transakcjach, bez blokowania bieżącej aplikacji; powtórzyć je po wstrzymaniu zapisów.

| Badany obszar | Wynik |
|---|---|
| RAW | 0 materiałów, rewizji, jednostek rewizji, cen, wierszy importów i przypisań dostawców; 2 kategorie. |
| Dostawcy / dokumenty | 0 supplierów, produktów i rewizji produktów; 0 dokumentów, wersji, linków i uploadów. Brak ciphertextów i legal-name backfillu supplierów. |
| Alergeny | 0 dotychczasowych wersji katalogu, definicji, deklaracji i attestations. |
| Wycofywany workflow | 0 ocen kompatybilności, 0 starych grantów compatibility, 0 niezgodnych linków dokumentów; oba widoki wymagane przez `000059` istnieją. `NOT VALID` w `000059` nie waliduje starych wierszy, ale ogranicza nowe i aktualizowane. |
| Częściowe obiekty | 0 z 15 sprawdzonych nowych tabel; 0 z 14 sprawdzonych nowych kolumn. Kontrola nie obejmuje wszystkich funkcji, triggerów i constraintów. |
| Administrator | 1 aktywny użytkownik z nieodwołaną rolą `ADMINISTRATOR`; to nie jest pełna weryfikacja logowania, membership ani uprawnień użytkowych. |
| Uprawnienia migratora | `veltra_migrator` ma wymagane atrybuty ograniczonej roli, CREATE bazy oraz schematów `public` i `veltra_private`; może użyć roli właściciela wszystkich 14 sprawdzonych istniejących tabel/widoków. Nie jest to dowód wszystkich uprawnień wymaganych przez każdą migrację. |

Brak RAW, supplierów i importów eliminuje aktualnie sprawdzone konflikty danych dla FK/checków `000051`; brak supplierów eliminuje obecny backfill i potrzebę odszyfrowywania ich identyfikatorów w `000052`. To nie dowód braku innych usterek środowiska lub przyszłych równoległych zapisów. Zmiana `000057` nie backfilluje historycznych przypisań, ale bieżąca baza nie ma takich przypisań. Nie zaproponowano listy do usuwania: żaden konflikt nie został potwierdzony.

Archiwum `/home/veltra/backups/stage6a1/veltra-stage6a1-20261123T193945Z-b01845be954d42199ec77e8909766f7c.dump` istnieje, tryb `600`, 677466 B; SHA-256 ponownie wynosi `1ea1369b02f4014fee09e145998990e8d1b93e861f83ef44c5c54e10d382bf16`. Wcześniejszy izolowany restore przeszedł kontrole logiczne po wyjaśnieniu różnic definicji, ale nie odtwarzał globalnych ról, ownership ani ACL. Sama suma nie dowodzi aktualności kopii względem późniejszych zapisów ani pełnej możliwości odzyskania dostępu administratora. Przed migracją sprawdzić aktualność i realny recovery; jeśli kopia jest niewystarczająca, najpierw uzyskać autoryzację na aktualny backup i test, bez usuwania starszych.

## Sprawdzenie kandydata

| Kontrola | Wynik |
|---|---|
| Frontend z dokładnego drzewa, lokalny Node 26.13.0 | `npm ls --depth=0`, `npm run lint`, `npm run build` (`tsc -b` + Vite), `npm test`: **PASS, 32 pliki, 320 testów**. Istnieją ostrzeżenia lint `visualPreview.tsx` i duży chunk Vite. |
| Backend z dokładnego drzewa, PostgreSQL 17.11 | `RawMaterialRevisionDataModelTest.php` i `RawMaterialCapabilitiesApiTest.php`: **PASS, 43 testy, 384 asercje**. |
| Migracje na pustej bazie wyłącznie testowej | Wszystkie 61 migracji **PASS**. |
| Symulacja aktualizacji na osobnej pustej bazie testowej | Najpierw 49 migracji, potem dokładnie oczekujące 12; **61 unikalnych wpisów, 12 w drugim batchu, PASS**. Nie była to kopia ani fixture Stage; nie dowodzi zachowania konkretnych kont Stage. |
| Odesłania backendu do workflow `000059` | W `backend/app` i `backend/routes` drzewa kandydata nie znaleziono nazw dwóch wycofywanych widoków ani tabeli assessment. To analiza statyczna, nie test uruchomionego obrazu. |
| Formatowanie | `git diff --check` i patch w oddzielnym indeksie **PASS**. Na bazowym HEAD Pint zgłasza istniejący problem formatowania tego samego pliku PHP poza funkcjonalnym fragmentem; wcześniejszy Pint całego zmienionego worktree przechodził. Ta niezwiązana zmiana nie jest w patchu. |

Zależności `vendor` i `node_modules` skopiowano z istniejących instalacji, bez instalowania pakietów. Test nie jest potwierdzeniem odtwarzalności lockfile ani produkcyjnych obrazów. W symulacji pierwsza, oddzielna baza testowa miała błędnie ustawionego właściciela i zatrzymała się na migracji `000022` wskutek poprawnie działającej kontroli least privilege. Nie obchodzono jej: utworzono drugą bazę **w tym samym wyłącznie tymczasowym PostgreSQL**, z oddzielnym właścicielem i uprawnieniami migratora, i rozpoczęto symulację od początku. Wynik PASS dotyczy tej drugiej bazy.

Projekt testowy `veltra-accelerated-b7a2817b` korzystał z wewnętrznej sieci, bez publikowanych portów i bez wolumenów Stage; PostgreSQL i Redis miały dane w `tmpfs`. Po weryfikacji ID i członków sieci usunięto tylko dwa utworzone kontenery i ich sieć; kopię kandydata i syntetyczne poświadczenia usunięto. Żaden kontener ani wolumen Stage nie był zmieniany. PostgreSQL Stage po sprzątaniu jest `healthy`, API HTTP 200.

## Minimalna sekwencja wdrożenia — NIE WYKONANO

1. Właściciel zatwierdza pełny diff i **osobno** autoryzuje commit; z tego jednego czystego commita zbudować frontend oraz wspólny obraz backend/migrator/queue. Zanotować commit, lockfile, bazy i niezmienne ID/digests obrazów. Przed buildem potwierdzić dostępność podstawowych obrazów i zależności; nie włączać przypadkowo bieżącego worktree. Dockerfile frontendu uruchamia serwer Vite, zatem produkcyjny build testowy nie dowodzi sam w sobie działania wdrażanego kontenera.
2. Uzyskać osobną zgodę na **lokalne** wdrożenie i migracje. Przed oknem serwisowym ponownie potwierdzić daemon/projekt, PostgreSQL, wolumen, health, backup/recovery, 49/12 i komplet konfliktów po zatrzymaniu zapisów; sprawdzić uprawnienia migratora i runtime, działanie istniejącego administratora oraz dostępność `APP_KEY`, klucza podpisu mutacji i pozostałych sekretów bez wypisywania wartości. Potwierdzić zgodność renderowanej konfiguracji z obecnym Stage. Udokumentowany env-file `/home/veltra/.copilot/session-state/adef6d0b-b0cb-4369-82e9-5a18fc6e1bf9/veltra-stage6a1.env` jest obecnie dostępny, ale znajduje się w przestrzeni sesji: nie zakładać jego trwałości. Zweryfikować również Redis, Mailpit, porty, sieć i ograniczenia lokalnego profilu.
3. Użyć istniejących plików Stage `/home/veltra/projekty/veltra/compose.yaml` i `/home/veltra/projekty/veltra/compose.stage6a1.yaml` oraz nowego, jawnie przejrzanego override pliku wskazującego **nowe, unikalne lokalne tagi** obrazów dla `backend`, `migrate`, `queue` i `frontend`. Nie nadpisywać starego tagu obrazu. Przykładowy kształt override (tagi zastąpić zweryfikowanymi przed użyciem):

   ```yaml
   services:
     backend: { image: "veltra-stage6a1-backend:release-REVIEWED" }
     migrate: { image: "veltra-stage6a1-backend:release-REVIEWED" }
     queue: { image: "veltra-stage6a1-backend:release-REVIEWED" }
     frontend: { image: "veltra-stage6a1-frontend:release-REVIEWED" }
   ```

   Sprawdzić `docker compose config` bez ujawniania sekretów: ta sama baza i sieć, zewnętrzne wolumeny, poprawne zmienne, lokalna konfiguracja RAW, identyczny obraz backend/migrate/queue, obraz frontend oraz brak niezamierzonych zmian pozostałych usług. Nie uruchamiać `up` dla PostgreSQL, Redis ani Mailpit.
4. W uzgodnionym oknie zatrzymać frontend, queue i backend, nie zatrzymując PostgreSQL ani nie zmieniając wolumenów. Przyjmując `ENV_FILE` i `RELEASE_OVERRIDE` jako wcześniej sprawdzone bezpieczne ścieżki:

   ```sh
   docker --context default compose --project-directory /home/veltra/projekty/veltra -p veltra-stage6a1 \
     --env-file "$ENV_FILE" -f /home/veltra/projekty/veltra/compose.yaml \
     -f /home/veltra/projekty/veltra/compose.stage6a1.yaml -f "$RELEASE_OVERRIDE" \
     stop frontend queue backend
   ```

   Potwierdzić zatrzymanie procesów i brak innych źródeł zapisu; ponowić kontrolę danych, historii i backupu. Nie rozpoczynać migracji przy niepewnej tożsamości albo niewystarczającym recovery.
5. Uruchomić migrator z obrazu kandydata, bez zależności i pobierania obrazu, zachowując kontener oraz logi. Nazwa poniżej musi być unikalna i wcześniej sprawdzona:

   ```sh
   docker --context default compose --project-directory /home/veltra/projekty/veltra -p veltra-stage6a1 \
     --env-file "$ENV_FILE" -f /home/veltra/projekty/veltra/compose.yaml \
     -f /home/veltra/projekty/veltra/compose.stage6a1.yaml -f "$RELEASE_OVERRIDE" \
     run -T --no-deps --pull never --name veltra-stage6a1-migrate-REVIEWED \
     migrate php artisan migrate --force
   ```

   Komenda ma wykonać tylko 12 poniższych migracji; Laravel zatrzymuje się na pierwszym błędzie. Migracja alergenów `000052` sama uruchamia inicjalizację referencyjną; nie uruchamiać osobnych seederów. Po błędzie zachować stan i logi, **nie wykonywać automatycznego retry, rollbacku, resetu ani restore**, nie uruchamiać starego backendu; uzyskać osobną decyzję. Odtworzenie na źródłowy wolumen wymaga osobnej zgody.
6. Po sukcesie potwierdzić dokładnie 61 nazw, obiekty i status integralności, zachowane konta/role/granty/konfigurację oraz brak niezamierzonej aktywacji. Dopiero następnie uruchomić nowy backend, sprawdzić health i logi, potem queue oraz frontend:

   ```sh
   docker --context default compose --project-directory /home/veltra/projekty/veltra -p veltra-stage6a1 \
     --env-file "$ENV_FILE" -f /home/veltra/projekty/veltra/compose.yaml \
     -f /home/veltra/projekty/veltra/compose.stage6a1.yaml -f "$RELEASE_OVERRIDE" \
     up -d --no-deps --no-build --pull never backend
   # Po potwierdzeniu zdrowego backendu:
   docker --context default compose --project-directory /home/veltra/projekty/veltra -p veltra-stage6a1 \
     --env-file "$ENV_FILE" -f /home/veltra/projekty/veltra/compose.yaml \
     -f /home/veltra/projekty/veltra/compose.stage6a1.yaml -f "$RELEASE_OVERRIDE" \
     up -d --no-deps --no-build --pull never queue frontend
   ```

   Zweryfikować rzeczywiste ID obrazów, API i frontend, logowanie i istniejące uprawnienia administratora bez samodzielnego nadawania grantów, logi queue, kontrakt RAW i dostawców oraz zgodność frontend/API. Ręczne testy biznesowe prowadzić dopiero po tych kontrolach. RAW readiness, włączenie lokalizacji i BOSS readiness/aktywacja są odrębne od migracji; niczego nie aktywować automatycznie.

### Oczekująca kolejność

1. `2026_11_17_000050_add_raw_material_category_description_and_archive`
2. `2026_11_17_000051_create_raw_material_unit_dictionary`
3. `2026_11_17_000052_create_international_allergen_dictionaries`
4. `2026_11_18_000052_create_supplier_directory`
5. `2026_11_18_000053_create_installation_precautionary_allergens`
6. `2026_11_19_000053_preserve_actor_when_revoking_membership_permissions`
7. `2026_11_19_000054_create_boss_location_restriction_revisions`
8. `2026_11_19_000055_extend_boss_visibility_policy`
9. `2026_11_20_000056_create_organization_capability_state`
10. `2026_11_21_000057_add_raw_material_revision_supplier_snapshots`
11. `2026_11_22_000058_add_user_avatar_state`
12. `2026_11_23_000059_retire_raw_material_supplier_compatibility_workflow`

Wiele migracji jest forward-only. Stary backend używa widoków usuwanych przez ostatnią migrację i nie może bezpiecznie działać po jej wykonaniu. Autorytatywny standard VSEF pozostaje niedostępny; nie deklaruje się zgodności z nim. Szczegóły skutków i dalszych zabezpieczeń: [analiza luki migracyjnej](./VELTRA_STAGE6A1_MIGRATION_GAP_REPORT.md).
