# VELTRA 2.0 — Stage 2F — Implementation 3D.1
## Raport: atomowe zbiorcze udostępnianie RAW i zmienione decyzje SUPER ADMIN

**Wynik:** **PASS** dla zatwierdzonego zakresu bulk sharing i rejestracji decyzji.  
**Formalne wykonanie wycofania RAW:** **zablokowane** do czasu uzupełnienia kontraktu właściciela; endpointu celowo nie utworzono.  
**RAW_MATERIALS:** **NOT_READY**; nie włączono i nie wdrożono.

## 1. Zmiany decyzji

Utworzono [rejestr zmian decyzji RAW_MATERIALS](./VELTRA_STAGE_2F_RAW_DECISION_AMENDMENTS_2026-11-09.md) na podstawie jawnej decyzji właściciela przekazanej 2026-11-09. Zmiany zastępują wyłącznie sprzeczne fragmenty K3.9–K3.10 i K3.18–K3.20; historycznych zatwierdzonych dokumentów źródłowych nie nadpisano.

- **K3.9–K3.10:** wszystkie cele bulk muszą przejść walidację, albo cała operacja nie zmienia danych. Częściowy sukces jest zabroniony.
- **K3.18–K3.20:** formalnym wycofaniem może zarządzać wyłącznie pierwotna tożsamość SUPER ADMIN. ADMINISTRATOR nie inicjuje, nie zatwierdza ani nie wykonuje wycofania. Wymóg drugiego administratora został wycofany. Wymagane są świeża ponowna autentykacja hasłem i kompletny audyt; SUPER ADMIN nie omija integralności referencyjnej, safety blocks ani kontroli zależności.
- SUPER ADMIN pozostaje atrybutem chronionej tożsamości, nie nową rolą RBAC. Nie dodano ani nie zmieniono ról.
- Aneks dopisano również do lokalnych materiałów planistycznych: [analizy wpływu K3](./VELTRA_STAGE_2F_RAW_K3_TECHNICAL_IMPACT.md) i [przeglądu gotowości K2/K3](./VELTRA_STAGE_2F_K2_K3_READINESS_REVIEW.md).

## 2. Wdrożone API i semantyka

Obie ścieżki korzystają z jednego `RawMaterialLocationShareAdministration` i istniejącej polityki, podpisanego zakresu mutacji oraz audytu:

1. `POST /api/v1/raw-materials/{material}/shares/bulk` — jeden materiał do wielu lokalizacji; ciało: `location_ids: UUID[]`.
2. `POST /api/v1/raw-materials/bulk-shares/by-location` — wiele materiałów do jednej lokalizacji; ciało: `location_id: UUID`, `material_ids: UUID[]`.

Każda lista przyjmuje 1–100 UUID. Odpowiedź `200` zawiera `outcome=ACCEPTED`, `created`, `already_active`, `duplicates` i `invalid_targets`. Aktywne udostępnienie jest idempotentnym brakiem zmiany; powtórzone identyfikatory są deterministycznie redukowane i wykazywane w `duplicates`. Odpowiedź `422` zawiera `outcome=REJECTED` i uporządkowaną listę nieprawidłowych par z kodami przyczyn.

Przed odczytem szczegółów celów wymuszana jest istniejąca autoryzacja ADMINISTRATOR, aktywnej tożsamości/organizacji, członkostwa i kontekstu. Materiały i lokalizacje są wyszukiwane wyłącznie w organizacji aktora; brak celu w tej organizacji daje ogólną odmowę bez ujawniania UUID ani danych innej organizacji. Dla celów wewnątrz organizacji odpowiedź rozróżnia m.in. nieaktywną lokalizację, nieaktywny materiał, brak zatwierdzonej rewizji, brak zatwierdzenia bieżącej rewizji i brak zatwierdzonej pierwszej rewizji.

### Atomowość, współbieżność i audyt

- Mutacja wykonuje się w istniejącym podpisanym zakresie `raw-material.share`.
- W transakcji blokowana jest organizacja, następnie uporządkowane identyfikatory materiałów i lokalizacji. Uprawnienie i bieżący stan są ponownie weryfikowane pod blokadami.
- Pełna walidacja wszystkich par następuje przed pierwszym zapisem. Nieprawidłowy cel oznacza zero nowych share; awaria dowolnego zapisu cofa cały batch.
- Dla każdej nowej relacji zapisuje się standardowe `LOCATION_SHARE_CREATED`; końcowy sukces bulk otrzymuje `LOCATION_SHARE_BULK_COMPLETED` w tej samej transakcji. Aktywne już relacje nie otrzymują fikcyjnych zdarzeń utworzenia.
- Nieprawidłowe cele w dozwolonym zakresie otrzymują `LOCATION_SHARE_BULK_REJECTED` poza transakcją biznesową. Po błędzie zapisu rollback jest zakończony przed zapisem audytu odrzucenia. Dla celu poza zakresem audyt zawiera wyłącznie ogólny powód i liczbę celów, bez obcych identyfikatorów.
- Istniejące blokady organizacji serializują bulk z nadaniem/cofnięciem share, archiwizacją materiału i dezaktywacją lokalizacji. Historia udziałów nadal jest append-only; nie dodano usuwania.
- Walidacja zatwierdzonego materiału jest wspólna dla operacji pojedynczej i bulk.

## 3. Zmienione pliki

- `backend/app/Domain/RawMaterials/RawMaterialLocationShareAdministration.php`
- `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`
- `backend/app/Http/Requests/RawMaterialBulkShareLocationsRequest.php`
- `backend/app/Http/Requests/RawMaterialBulkShareMaterialsRequest.php`
- `backend/routes/api.php`
- `backend/tests/Feature/RawMaterialsTest.php`
- `backend/tests/Support/concurrent_raw_material_share.php`
- `VELTRA_STAGE_2F_RAW_DECISION_AMENDMENTS_2026-11-09.md`
- `VELTRA_STAGE_2F_RAW_K3_TECHNICAL_IMPACT.md`
- `VELTRA_STAGE_2F_K2_K3_READINESS_REVIEW.md`

Nie zmieniono UI, finansowych uprawnień, sześciu ról, SUPER ADMIN invariants, bramki readiness ani workflow produkcyjnych.

## 4. Weryfikacja

Wyniki poniżej pochodzą z dedykowanego projektu Compose `veltra-stage2f-implementation3d1-test`, z siecią `internal=true` i własnymi wolumenami. Baza miała identyfikator `veltra_testing` i PostgreSQL **17.11**; instalacyjne usługi nie były dołączone do projektu.

| Test/weryfikacja | Wynik |
|---|---|
| Skupione testy bulk sharing | **PASS — 6 testów, 66 asercji** |
| Pełny `RawMaterialsTest` | **PASS — 71 testów, 970 asercji** |
| Pełna regresja backendu po końcowej zmianie walidatora | **PASS — 290 testów, 2 889 asercji** |
| Świeże migracje na odizolowanej bazie | **PASS — 41/41, ostatnia migracja 041** |
| Upgrade z 041 | **N/D — brak nowej migracji** |
| PHP lint (`app`, `tests`, `database`, `routes`, `lang`) | **PASS — 249 plików PHP** |
| Pint | **PASS — 266 plików** |
| `git diff --check` | **PASS** |
| Tożsamość DB/role | **PASS** — `veltra_app` i `veltra_migrator` bez `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `BYPASSRLS`; właścicielem `raw_material_location_shares` jest `veltra_migrator` |
| Bezpośredni SQL runtime bez biletu | **PASS — `veltra_app` odrzuł testowy INSERT udziału kodem SQLSTATE `42501`** |

Polecenia testowe:

```sh
docker compose --env-file .env.3d1-test -p veltra-stage2f-implementation3d1-test -f compose.stage2f-implementation3d1-test.yaml run --rm migrate
docker compose --env-file .env.3d1-test -p veltra-stage2f-implementation3d1-test -f compose.stage2f-implementation3d1-test.yaml exec -T test-runner php artisan test tests/Feature/RawMaterialsTest.php --filter='bulk_share'
docker compose --env-file .env.3d1-test -p veltra-stage2f-implementation3d1-test -f compose.stage2f-implementation3d1-test.yaml exec -T test-runner php artisan test tests/Feature/RawMaterialsTest.php
docker compose --env-file .env.3d1-test -p veltra-stage2f-implementation3d1-test -f compose.stage2f-implementation3d1-test.yaml exec -T test-runner php artisan test
docker compose --env-file .env.3d1-test -p veltra-stage2f-implementation3d1-test -f compose.stage2f-implementation3d1-test.yaml exec -T test-runner sh -lc 'find app tests database routes lang -name "*.php" -print0 | xargs -0 -n1 php -l'
docker compose --env-file .env.3d1-test -p veltra-stage2f-implementation3d1-test -f compose.stage2f-implementation3d1-test.yaml exec -T test-runner vendor/bin/pint --test
git diff --check
```

Testy współbieżności używały oddzielnych procesów PHP względem PostgreSQL. Potwierdzono serializację dwóch równoległych operacji bulk oraz bulk oczekującego na commit cofnięcia share, archiwizacji materiału i dezaktywacji lokalizacji. Testy SQL/API potwierdziły brak częściowego zapisu, zachowanie audytu po rollbacku, brak ujawnienia obcej lokalizacji i odmowę runtime SQL bez podpisanego biletu. Pełny backend obejmuje również istniejące regresje 3A/3B, separację uprawnień finansowych, ochronę primary/SUPER ADMIN i `NOT_READY`.

## 5. Formalne wycofanie: nadal poza implementacją

Nie dodano endpointu, tabel ani logiki wykonania wycofania. Nadal brakuje zatwierdzonych: stanów i przejść, pełnego katalogu zależności i ich źródeł, skutków dla dostępu i procesów w toku, semantyki recovery/anulowania oraz dokładnego kontraktu świeżej reautentykacji hasłem. Właściciel musi także ustalić fail-closed zachowanie dla nieznanego stanu safety block i wymogi audytu finalizacji. Wyłączenie modułu nie jest formalnym wycofaniem.

## 6. Status bezpieczeństwa i środowiska

- **RAW_MATERIALS = NOT_READY**; brak aktywacji i wdrożenia.
- **Brak migracji** po 041; brak produkcyjnych batchy, zużycia i kontynuacji.
- Nie wykonano commit/push.
- Baza, kontenery, wolumeny i konfiguracja instalacji nie były dotykane. Użyto wyłącznie osobnego projektu testowego z własną siecią i wolumenami; jego zasoby tymczasowe należy usunąć po zakończeniu uploadu raportu.
- Raport zapisano lokalnie jako [VELTRA_STAGE_2F_IMPLEMENTATION_3D1_RAW_BULK_SHARING_REPORT.md](./VELTRA_STAGE_2F_IMPLEMENTATION_3D1_RAW_BULK_SHARING_REPORT.md). Wysłanie do `RAPORTY_COPILOTA` nie powiodło się: istniejąca karta przeglądarki Dysku nie odpowiedziała w limicie 30 sekund. Nie ponawiano prób; zawartość chmury nie została zweryfikowana.
