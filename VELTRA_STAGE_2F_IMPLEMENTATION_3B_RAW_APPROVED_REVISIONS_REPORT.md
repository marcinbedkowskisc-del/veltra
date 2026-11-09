# VELTRA 2.0 — Stage 2F — Implementation 3B
## Zatwierdzone rewizje RAW i kontrolowana propagacja

### Wynik

Implementacja zakończona i zweryfikowana. Odbiorcy z aktywnym udostępnieniem rozwiązują jedną bieżącą rewizję przez `raw_materials.approved_revision_id`; rewizje robocze, oczekujące i wcześniejsze nie są widoczne w widokach odbiorcy. Katalog pozostaje pojedynczy i organizacyjny, bez kopii materiałów dla lokalizacji. `RAW_MATERIALS` pozostaje `NOT_READY`; nie uruchomiono Implementation 3C.

### Znalezione problemy i zmiany

- Historia rewizji odbiorcy zwracała wszystkie rewizje, a endpoint Stage 2D akceptował bez ograniczenia identyfikator rewizji. Ograniczono oba zapytania do bieżącego zatwierdzonego wskaźnika. Primary nadal ma dostęp do pełnej historii, w tym szkiców i rewizji oczekujących.
- Odczyt wersji dokumentu sprawdzał share materiału, lecz nie to, czy dokument jest powiązany z aktualną zatwierdzoną rewizją. Dodano sprawdzenie bieżącego wskaźnika i aktywnego lifecycle w tym samym zapytaniu.
- Projekcje produktów dostawców, ich rewizji, ofert i cen mogły zwrócić dane powiązane z historyczną rewizją materiału. Dla odbiorców zawężono je do produktów powiązanych z bieżącą zatwierdzoną rewizją. Widok właścicielski primary oraz odrębne kontrole uprawnień finansowych zachowano.
- Historia ocen zgodności mogła ujawniać odbiorcy ocenę powiązaną z zastąpioną rewizją RAW. Odbiorcy widzą teraz tylko oceny dla bieżącego zatwierdzonego wskaźnika; gdy kontekst produktu wskazuje rewizję nieaktualną, szczegóły tego kontekstu są pomijane. Primary zachowuje pełną historię.
- Nie zmieniano mechanizmu zatwierdzania: już wykonywał walidację, zapis zdarzenia audytowego i atomową zmianę wskaźnika w transakcji z blokadą organizacji. Potwierdzono go testami współbieżności.

Zmodyfikowano [RawMaterialService](./backend/app/Domain/RawMaterials/RawMaterialService.php), [RawMaterialDocumentService](./backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php), [RawMaterialCommercialService](./backend/app/Domain/RawMaterials/RawMaterialCommercialService.php) i [RawMaterialCompatibilityAssessmentService](./backend/app/Domain/RawMaterials/RawMaterialCompatibilityAssessmentService.php). Regresje dodano w [RawMaterialsTest](./backend/tests/Feature/RawMaterialsTest.php) i [RawMaterialCompatibilityAssessmentTest](./backend/tests/Feature/RawMaterialCompatibilityAssessmentTest.php); proces testowy znajduje się w [concurrent_raw_material_revision.php](./backend/tests/Support/concurrent_raw_material_revision.php).

### Weryfikacja

- Celowane testy końcowe plików RAW: **72 testy, 980 asercji — PASS**; nowa regresja historii zgodności została ponadto uruchomiona osobno: **1 test, 5 asercji — PASS** (ten test jest również wliczony w 72). Obejmują szkic, submit, zwrot do korekty, odrzuconą próbę ponownego zatwierdzenia, propagację V1→V2 do odbiorców, endpointy rewizji/Stage 2D, produkty dostawców, dokumenty i zgodność.
- Pełny backend PHPUnit po wszystkich zmianach, w tym testy RBAC, migracji, 3A, primary i SUPER ADMIN: **284 testy, 2823 asercje — PASS**.
- Migracje **001–041** wykonano od zera; konto wykonujące migracje: `veltra_migrator`. Nowa migracja nie była potrzebna, więc upgrade z 041 do kolejnej migracji nie dotyczy tej implementacji.
- Dwa niezależne procesy próbujące zatwierdzić tę samą rewizję: jeden zatwierdził, drugi otrzymał `RAW_MATERIAL_APPROVAL_INVALID`; wskaźnik i audyt pozostały spójne. Odczyt równoległy z zatwierdzeniem zwrócił kompletną starą albo nową rewizję, nigdy mieszane dane. Próba odczytu po zatwierdzonym cofnięciu share została odrzucona; żądanie rozpoczęte przed cofnięciem mogło odczytać bieżącą rewizję.
- Izolacja: świeży projekt `veltra-raw-revisions-3b`, PostgreSQL **17.11**, wewnętrzna sieć (`internal=true`, cztery kontenery projektu), odrębny wolumen, bez opublikowanego portu PostgreSQL. `veltra_app` i `veltra_migrator` zweryfikowano jako role bez `SUPERUSER`; połączenie aplikacji działało jako `veltra_app`.
- PHP lint: PASS dla `app`, `tests`, `database`, `routes`, `lang`; Pint: PASS dla **264 plików**; `git diff --check`: PASS.
- Wykonano m.in.:

  ```sh
  docker compose -p veltra-raw-revisions-3b --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml build backend
  docker compose -p veltra-raw-revisions-3b --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml up -d --no-deps --force-recreate backend
  docker exec veltra-raw-revisions-3b-backend-1 php artisan test --do-not-cache-result --display-warnings tests/Feature/RawMaterialsTest.php tests/Feature/RawMaterialCompatibilityAssessmentTest.php
  docker exec veltra-raw-revisions-3b-backend-1 php artisan test --do-not-cache-result --display-warnings
  docker exec veltra-raw-revisions-3b-backend-1 sh -lc 'vendor/bin/pint --test && find app tests database routes lang -name "*.php" -print0 | xargs -0 -n1 php -l'
  git diff --check
  ```

### Zakres i ograniczenia

Nie dodano tabel ani migracji; nie zmieniono sześciu ról RBAC, modelu SUPER ADMIN, ochrony primary ani finansowych uprawnień `FOODCOST_VIEW` / `PURCHASE_PRICES_VIEW`. Nie dodano kopii rewizji, kolejki, snapshotów produkcyjnych ani nowych workflow batchy. Testy używały syntetycznych danych i izolowanej bazy; zasoby instalacyjne, kontenery, wolumeny i konfiguracja nie były celem migracji ani testów.

Implementation 3C/3D, historyczne snapshoty produkcyjne i uruchomienie modułu pozostają poza zakresem. Nie udało się bezpośrednio pobrać części źródłowych dokumentów Google; wykorzystano lokalne zatwierdzone specyfikacje i raporty dostępne dla implementacji. Raport przesłano do folderu `RAPORTY_COPILOTA` jako Dokument Google pod tą samą nazwą bazową; potwierdzono go na liście zawartości folderu (Google Docs, 4 KB). Oryginalny lokalny plik Markdown pozostaje w repozytorium.
