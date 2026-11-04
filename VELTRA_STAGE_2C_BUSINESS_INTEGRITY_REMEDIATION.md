# VELTRA 2.0 — Remediacja integralności biznesowej Stage 2C

## Podsumowanie

Wprowadzono wąskie poprawki do trzech blockerów F-2 ze statycznego przeglądu migracji 024:

1. odblokowanie surowca wymaga zgodnego, podpisanego kontekstu i niezmiennego zdarzenia audytowego w tej samej transakcji;
2. aktywny produkt dostawcy wymaga rewizji początkowej nr 1 przy zatwierdzeniu transakcji;
3. stan oferty nie może być niezależnie zmieniany na nieobsługiwany stan cyklu życia; wycofanie pojedynczego zdarzenia cenowego pozostaje zdarzeniem ledgera, a oferta pozostaje aktywna.

Dodano forward-only migrację 025. Nie modyfikowano wcześniejszych migracji. Nie wykonano commitów, wdrożeń ani zmian istniejących baz, kontenerów i wolumenów. `RAW_MATERIALS` pozostaje `NOT_READY`; nie rozpoczęto Stage 2D ani nie aktywowano VSEF.

**Wynik:** poprawki są wdrożone w kodzie i migracji oraz przeszły wybrane testy funkcjonalne, ale dowody są ograniczone. Pełna regresja i niezależna weryfikacja runtime pozostają niezakończone. Raport nie jest niezależnym werdyktem bezpieczeństwa ani akceptacją Stage 2C.

## Zmiany

### 1. Odblokowanie surowca i historia audytowa

W [RawMaterialService.php](backend/app/Domain/RawMaterials/RawMaterialService.php) metoda `unblock()`:

- ponownie sprawdza uprawnienia `Unblock` i `Approve` wewnątrz transakcji oraz blokuje wiersz surowca;
- wystawia ticket dla zakresu `raw-material.unblock`, powiązany z UUID konkretnego surowca;
- najpierw zapisuje zdarzenie `UNBLOCKED` z aktorem, wynikiem `ACCEPTED` i haszami stanu przed/po, a następnie zmienia stan;
- zachowuje atomowość operacji: błąd lub rollback wycofuje zarówno zmianę stanu, jak i zdarzenie.

W [AuthorizationMutationContext.php](backend/app/Domain/Authorization/AuthorizationMutationContext.php) dodano `runForSubject()`, a w [AuthorizationMutationScope.php](backend/app/Domain/Authorization/AuthorizationMutationScope.php) wąski zakres `raw-material.unblock`.

Migracja 025 wiąże ticket, aktora, obiekt, zdarzenie `UNBLOCKED` i identyfikator transakcji. Zabrania przy tym zmiany rewizji lub innych pól surowca w ramach przejścia `BLOCKED → ACTIVE`. Brak ticketu nie jest interpretowany jako poprawny JSON: pusty GUC jest normalizowany przez `nullif(..., '')`, po czym walidator przejścia odmawia operacji bez wymaganych claimów. Sam dowolny wpis audytowy nie wystarcza.

### 2. Kompletność produktu dostawcy

Migracja 025 dodaje odroczony constraint trigger dla `supplier_products`. Przy końcu transakcji blokuje wiersz produktu i wymaga rewizji nr 1 o zgodnej organizacji. Pozwala to istniejącemu, transakcyjnemu procesowi utworzyć produkt i rewizję w jednej transakcji, a odrzuca produkt aktywny bez rewizji. Kontrola uwzględnia rollback i ponowną ocenę stanu transakcji.

Przed instalacją migracja przerywa się, jeśli znajdzie istniejący aktywny produkt bez wymaganej rewizji; nie dopisuje ani nie przekształca danych historycznych.

### 3. Spójność stanu ofert

Migracja 025 dodaje i waliduje ograniczenie `supplier_offers.lifecycle_state = 'ACTIVE'`. Bieżący model nie zapewnia odrębnego, spójnego zdarzenia wycofania całej oferty. Wycofanie pojedynczej ceny nadal jest zdarzeniem `WITHDRAWN` w `supplier_offer_events`; późniejsze zdarzenie cenowe pozostaje dozwolone. Nie dodano drugiego źródła prawdy ani nowej semantyki wycofania całej oferty.

Jeśli istnieją dane z ofertą oznaczoną jako `WITHDRAWN`, migracja odmawia instalacji i wymaga decyzji właściciela danych. Nie poprawia ani nie usuwa historii automatycznie.

## Migracja i dane

Nowy plik: [2026_11_05_000025_enforce_raw_material_business_integrity.php](backend/database/migrations/2026_11_05_000025_enforce_raw_material_business_integrity.php).

- Weryfikuje zastosowanie migracji 022–024, wymagane relacje i walidator podpisanych mutacji.
- Sprawdza dane historyczne dla aktywnych produktów bez rewizji początkowej oraz ofert w stanie `WITHDRAWN`.
- Wykonuje precondition checks, DDL i kontrolę zainstalowanych niezmienników w transakcji PostgreSQL.
- Sprawdza obecność wymaganych triggerów i ich odroczenie dla produktów.
- Jest nieodwracalna przez `down()`; dalsze zmiany wymagają kolejnej migracji naprawczej do przodu.
- Nie przepisuje migracji 001–024 i nie naprawia historycznych danych.

## Weryfikacja

Testy uruchamiano wyłącznie w efemerycznych kontenerach PostgreSQL 17 i Redis, z wewnętrzną siecią i tymczasowym magazynem `tmpfs`. Kontenery, sieć, tymczasowe hasła i pliki środowiskowe utworzone na potrzeby tych przebiegów były sprzątane. Istniejące kontenery aplikacyjne i trwałe wolumeny nie były modyfikowane.

### Wyniki końcowego poprawnego przebiegu wybranych testów

Uruchomiono przez `php artisan test --do-not-cache-result` cztery przypadki:

- `test_block_archive_restore_and_reapproval_preserve_identity_and_history`
- `test_unblock_state_and_immutable_audit_record_roll_back_together`
- `test_supplier_product_and_initial_revision_roll_back_together`
- `test_supplier_products_offer_prices_and_cost_permissions_are_isolated`

Wynik: **4 testy, 69 asercji, 0 niepowodzeń i 0 błędów; wszystkie 4 oznaczone jako WARN**. Ostrzeżenia wynikały z braku pliku `/var/www/html/.env` w kontenerze opartym o montowanie tylko do odczytu, a nie z błędów asercji. Po ustawieniu domen SPA zestaw zakończył się poprawnie. Próba ponownego uruchomienia na kopii roboczej z efemerycznym `.env` nie doszła do testów: kontener PostgreSQL nie zgłosił gotowości (`pg_isready: no response`). Zasoby tej nieudanej próby zostały posprzątane.

Wcześniejszy przebieg po migracji ujawnił błąd walidatora: pusty kontekst GUC był rzutowany na JSON, przez co zwykły wpis bootstrapu uprawnień kończył się `SQLSTATE 22P02`. Poprawiono obsługę pustej wartości w obu funkcjach migracji 025. Następny przebieg z niepełną konfiguracją runnera zwrócił 401 przy wyborze kontekstu; właściwe ustawienie `SANCTUM_STATEFUL_DOMAINS` dla testów SPA usunęło ten problem środowiskowy.

### Pozostałe kontrole wykonane

- Świeża baza PostgreSQL 17 przeszła migracje 001–025 w przebiegach testowych.
- Redis odpowiadał `PONG` w przebiegach, w których uruchomiono testy.
- Pint `--test` dla pięciu zmienionych plików: **PASS**.
- PHP lint dla [AuthorizationMutationContext.php](backend/app/Domain/Authorization/AuthorizationMutationContext.php), [AuthorizationMutationScope.php](backend/app/Domain/Authorization/AuthorizationMutationScope.php), [RawMaterialService.php](backend/app/Domain/RawMaterials/RawMaterialService.php), migracji 025 i [RawMaterialsTest.php](backend/tests/Feature/RawMaterialsTest.php): **PASS**.
- `git diff --check`: **PASS**.

### Nie wykonano

- Pełnej regresji backendu ani kompletnego zestawu workflow RAW_MATERIALS.
- Niezależnych scenariuszy SQL/adwersarialnych, testów ataku ani ponowienia operacji objętych wcześniejszą blokadą bezpieczeństwa HTTP 422.
- Niezależnej weryfikacji rzeczywistych instalacji, ich danych historycznych, migracji już wdrożonych lub uprawnień runtime.

## Pozostałe ryzyka i ograniczenia

- Sześć pozostałych tabel z F-2 — `organization_suppliers`, `raw_material_code_counters`, `raw_material_categories`, `raw_material_revisions`, `raw_material_revision_units` i `raw_material_aliases` — nie było częścią tej wąskiej remediacji. Ocena wcześniejszego przeglądu statycznego klasyfikowała je jako warunkowe ryzyko rezydualne, ale wymaga ono niezależnej weryfikacji runtime; brak HMAC sam w sobie nie jest tu podstawą do nowego werdyktu.
- Wdrożenie 025 na istniejącej bazie może zostać zablokowane przez aktywny produkt bez rewizji nr 1 albo ofertę `WITHDRAWN`. Dane należy wtedy przejrzeć z właścicielem domeny; migracja celowo nie wybiera za niego korekty historycznej.
- Wybrane testy potwierdzają wskazane przepływy i rollback, ale nie dowodzą odporności wobec SQL-only ani poprawności wszystkich ścieżek aplikacji.
- Niezależny audyt bezpieczeństwa Stage 2C oraz niezależna weryfikacja runtime pozostają wymagane. Nie wydano werdyktu `ACCEPT`.

## Stan końcowy

Wprowadzono tylko poprawki powiązane z trzema wskazanymi blockerami i dodano forward-only migrację. Wybrane testy przeszły z ostrzeżeniami środowiskowymi; pełna regresja i niezależne potwierdzenie bezpieczeństwa pozostają otwarte. Nie wdrażano migracji w istniejących środowiskach i nie wprowadzano automatycznych korekt danych historycznych.
