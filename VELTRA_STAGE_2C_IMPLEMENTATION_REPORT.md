# VELTRA 2.0 — raport implementacji Stage 2C

## Podsumowanie

Stage 2C dodaje wspólny dla organizacji master dostawców, produkty i rewizje opakowań, oferty zakupowe, kursy FX oraz odrębną historię cen referencyjnych. Zachowano rozdział między ceną oferty, szacowanym foodcostem i przyszłym rzeczywistym kosztem produkcji z partii magazynowej.

**Rekomendacja: CONDITIONAL ACCEPT — implementacja jest gotowa do niezależnego audytu bezpieczeństwa; nie jest to zgoda na aktywację modułu, wdrożenie ani rozpoczęcie Stage 2D.**

`RAW_MATERIALS` nadal ma `ready=false`, a dostęp do każdego endpointu pozostaje za istniejącym middleware dostępności modułu. Nie wykonano migracji w środowisku aplikacyjnym ani zmian konfiguracji, danych lub wolumenów aplikacji.

## Zrealizowane funkcje

- Jeden `organization_suppliers` dla organizacji; dane identyfikacyjne są filtrowane po organizacji, identyfikator podatkowy jest szyfrowany i nie jest zwracany w odpowiedziach API.
- Produkty dostawców i ich niezmienne rewizje zapisują jednostkę zakupu, zawartość opakowania oraz migawkę konwersji do zatwierdzonej jednostki bazowej surowca.
- Wiele ofert i zdarzeń cenowych z walutą, progami ilościowymi, MOQ, okresem ważności i jawnym wycofaniem. Nakładające się okresy/progi są odrzucane; system nie wybiera automatycznie dostawcy.
- Osobna, kanoniczna cena referencyjna netto w PLN na jednostkę bazową. Opcjonalne źródło oferty zachowuje kwotę źródłową, rewizję produktu i migawkę kursu. Korekta historyczna tworzy osobny, audytowany restatement; oryginalne zdarzenie pozostaje niezmienne.
- Kursy NBP Table A, kurs tożsamościowy PLN oraz audytowane, ręczne nadpisania. Wybór kursu stosuje datę operacji i najnowszy wcześniejszy kurs; brak kursu jest błędem, a nie domyślną wartością. Odpowiedź NBP jest parsowana bez pośredniego przeliczenia kwoty `mid` przez `float`.
- PostgreSQL wykonuje obliczenia dziesiętne i końcowe zaokrąglenie HALF-EVEN. Trigger porównuje migawki normalizacji i cen referencyjnych z ponownie obliczonym wynikiem.
- Produkty/oferty nie otrzymują zatwierdzenia zgodności alergenowej; odpowiedzi wskazują `PENDING`. Nie dodano workflow Stage 2D.

## API

Nowe trasy są wersjonowane (`/api/v1/raw-materials`) i używają istniejących middleware sesji, tożsamości, kontekstu operacyjnego, audytu odrzuceń i dostępności `RAW_MATERIALS`.

- `GET/POST /suppliers`, `POST /suppliers/{supplier}/archive`
- `GET/POST /{material}/supplier-products`
- `GET/POST /supplier-products/{product}/revisions`, `POST /supplier-products/{product}/archive`
- `GET/POST /supplier-products/{product}/offers`
- `GET/POST /supplier-offers/{offer}/price-events`
- `GET /exchange-rates`, `POST /exchange-rates/nbp-sync`, `POST /exchange-rates/{rate}/overrides`
- `GET/POST /{material}/reference-prices`
- `GET/POST /{material}/reference-prices/{event}/restatements`

Wszystkie mutacje komercyjne wymagają poprawnego `Idempotency-Key`. Ponowne użycie klucza z innym payloadem zwraca konflikt. Utworzenie oferty wymaga jednocześnie `SUPPLIER_MANAGE` i `PURCHASING_COSTS_MANAGE`.

## Zabezpieczenia i integralność

- Dodano oddzielne permissiony VIEW/MANAGE dla danych dostawców, kosztów zakupowych, cen referencyjnych i FX; żadne nowe uprawnienie nie jest automatycznie przyznawane.
- Odczyt ofert bez `PURCHASING_COSTS_VIEW` nie zwraca kwot ani walut; odczyt historii cen zwraca 403. Test obejmuje również żądania z nieznanymi identyfikatorami.
- Zapytania i złożone klucze obce wiążą rekordy z organizacją, dostawcą, surowcem i właściwą rewizją. Mutacje są ponownie autoryzowane w transakcji.
- Transakcje, blokady wiersza organizacji/oferty, wersje oczekiwane, klucze idempotencji i indeksy unikalne chronią numerację oraz spójność zdarzeń.
- Rewizje, ceny, kursy, migawki, restatements, audyt i wyniki idempotencji są chronione przed UPDATE/DELETE przez triggery append-only.
- Błędy PostgreSQL dla tabel domeny surowców są raportowane wyłącznie z SQLSTATE, bez SQL i bindings zawierających kwoty lub dane dostawcy.
- Błędy konfliktu ograniczeń, w tym nakładające się oferty (`23P01`), są mapowane na bezpieczną odpowiedź 409.

## Zmienione pliki

- `backend/app/Domain/RawMaterials/RawMaterialCommercialService.php` — nowa logika domenowa dostawców, ofert, FX, cen referencyjnych i restatements.
- `backend/app/Domain/RawMaterials/RawMaterialPermission.php` — nowe capability.
- `backend/app/Domain/RawMaterials/RawMaterialException.php` — szczegóły kontrolowanych błędów domenowych.
- `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`, `backend/routes/api.php` — endpointy i routing.
- `backend/app/Http/Requests/InstallationRequest.php` oraz nowe requesty `RawMaterial*` dla dostawców, produktów, ofert, cen, FX i korekt.
- `backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php` — tabele, FK, indeksy, walidacje, funkcja HALF-EVEN i triggery.
- `backend/bootstrap/app.php` — bezpieczne mapowanie błędów integralności i ograniczenie danych w logach.
- `backend/lang/pl/messages.php` — polskie komunikaty API.
- `backend/tests/Feature/RawMaterialsTest.php` — testy regresji Stage 2C.

Nie zmieniano frontendowych plików ani konfiguracji środowiska.

## Weryfikacja

Testy wykonano na odizolowanych, jednorazowych usługach: PostgreSQL 17.11 (`postgres:17-alpine`) i Redis 7.4 (`redis:7.4-alpine`). Kontenery nie miały mountów ani opublikowanych portów i były podłączone wyłącznie do dedykowanej sieci `veltra-stage2c-disposable-5f8720b2`; runner montował kod tylko do odczytu, a katalogi runtime korzystały z `tmpfs`. Początkowa baza nie zawierała tabel publicznych, a Redis miał 0 kluczy. Istniejące kontenery i wolumeny aplikacyjne nie były używane do testów destrukcyjnych.

- Pełne migracje na czystym, jednorazowym PostgreSQL 17: **zakończone powodzeniem**.
- `RawMaterialsTest.php`: **14 testów, 161 asercji — PASS**.
- Pełny backend PHPUnit: **166 testów, 1356 asercji — PASS**.
- PHP syntax check: zmienione pliki PHP — **PASS**.
- Pint: **23 pliki — PASS**.
- `git diff --check`: **PASS**.
- Testy obejmują m.in. uprawnienia i brak wycieku kosztów, wymagany klucz idempotencji, NBP/as-of i weekend, ręczny override, HALF-EVEN dla dodatnich i ujemnych remisów, wycofanie i zastąpienie oferty, konwersję opakowania, restatement, rollback po awarii audytu, niezmienność rewizji/cen oraz odrzucenie nakładających się ofert.
- Procesowa próba PostgreSQL: **6 równoległych procesów, 6 poprawnie zserializowanych numerów zdarzeń 1–6, 0 błędów i 0 duplikatów**. Próba odtwarza wzorzec blokady wiersza i numeracji używany przez usługę; nie jest pełnym, równoległym testem HTTP całej aplikacji.

Podczas ponownego `migrate:fresh` na już użytej bazie testowej PostgreSQL zgłosił pozostawioną funkcję `serialize_installation_mutation` (`42723`) z wcześniejszej migracji instalacyjnej. Odtworzenie wyłącznie jednorazowego kontenera PostgreSQL i jednokrotne migracje na czystej bazie zakończyły się powodzeniem. Nie zmieniano wcześniejszych migracji ani żadnej bazy/wolumenu aplikacyjnego.

## Pozostałe ryzyka i ograniczenia

1. Wymagany jest odrębny, niezależny audyt bezpieczeństwa Stage 2C przed akceptacją. Niniejszy raport nie zastępuje tego audytu.
2. Procesowa próba weryfikuje zachowanie blokady PostgreSQL i sekwencji, ale nie obciążenie równoległymi żądaniami produkcyjnej ścieżki API.
3. Obecny schemat instalacyjny dopuszcza jedną organizację instalacyjną (`organizations_single_installation_unique`), więc test nie mógł utworzyć drugiej rzeczywistej organizacji. Sprawdzono filtrowanie po organizacji i zachowanie dla nieznanych UUID; izolację między dwoma rzeczywistymi tenantami należy ponownie zweryfikować w środowisku obsługującym wiele organizacji.
4. Testy NBP korzystają z kontrolowanych odpowiedzi HTTP; nie wykonano żądania do zewnętrznego serwisu NBP.
5. Migracji nie zastosowano w istniejącym środowisku aplikacyjnym, a modułu nie aktywowano.

## Werdykt

**CONDITIONAL ACCEPT** — zakres Stage 2C jest zaimplementowany i zweryfikowany w bezpiecznym środowisku testowym. Warunkiem akceptacji jest niezależny audyt, w szczególności przegląd uprawnień finansowych, migracji/triggerów i równoległych operacji. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne; nie rozpoczynać Stage 2D na podstawie tego raportu.
