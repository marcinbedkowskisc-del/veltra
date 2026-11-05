# VELTRA 2.0 — Stage 2D.2 — Raport implementacji prywatnego storage dokumentów

## Status

Implementacja Stage 2D.2 została wykonana i zweryfikowana wyłącznie w trybie DEVELOPMENT ONLY, na odizolowanym środowisku disposable PostgreSQL 17 / Redis. Nie dodano endpointów uploadu ani pobierania. RAW_MATERIALS pozostaje nieaktywowany, VSEF nieaktywny, a status bezpieczeństwa Stage 2C nie został zmieniony.

## Zmiany

- Migracja forward-only `029` tworzy:
  - organizacyjne rekordy logiczne dokumentów;
  - niezmienne wersje z unikalnym numerem, SHA-256, rozmiarem, metadanymi pliku, prywatnym kluczem obiektu i stanem `PENDING_SCAN`;
  - niezmienne, dokładne powiązania wersji dokumentu z rewizją surowca oraz opcjonalnie z rewizją produktu dostawcy;
  - niezmienny dziennik zdarzeń cyklu życia.
- Klucze obce złożone z `organization_id`, ograniczenia i walidacja w bazie blokują niespójne referencje między organizacjami. Baza dopuszcza rolę `veltra_app` wyłącznie do `SELECT` i `INSERT`; podpisany trigger chroni mutacje.
- Dodano interfejs storage i adapter prywatnego lokalnego dysku. Klucz obiektu jest generowany po stronie serwera; nazwa klienta jest wyłącznie metadanymi i nie może wskazywać ścieżki.
- Dysk `raw_material_documents` zapisuje poza publicznym webrootem, ma `serve=false`, prywatną widoczność i nie definiuje publicznego URL.
- Usługa zapisuje i ponownie odczytuje treść, weryfikując rozmiar i SHA-256 przed zatwierdzeniem metadanych. Przy błędzie transakcji usuwa obiekt tylko po potwierdzeniu, że nie ma referencji; gdy nie można tego potwierdzić, pozostawia obiekt i zapisuje ostrzeżenie zamiast ryzykować usunięcie zatwierdzonego pliku.
- Treść dokumentu nie trafia do PostgreSQL. Historyczne wersje oraz powiązania pozostają zachowane.

## Pliki

- `backend/database/migrations/2026_11_06_000029_create_raw_material_versioned_private_documents.php`
- `backend/app/Domain/RawMaterials/Documents/` — interfejs i adapter storage, serwis oraz wyjątki domenowe/infrastrukturalne
- `backend/app/Providers/AppServiceProvider.php`
- `backend/config/filesystems.php`
- `backend/tests/Feature/RawMaterialDocumentStorageTest.php`

## Migracje i testy

- PostgreSQL 17 i Redis środowiska disposable były zdrowe; istniejące sześć kontenerów VELTRA pozostało uruchomionych.
- Migracja 029 została zastosowana do bazy testowej po migracjach 001–028. `migrate:status` potwierdził migracje 001–029 jako zastosowane; migracja 029 jest forward-only.
- Testy Stage 2D.2: **7 PASS, 45 asercji, 0 FAIL, 0 błędów, 0 ostrzeżeń, 0 pominiętych**.
- Pełna dozwolona regresja (w tym Stage 2D.1 i Stage 2D.2): **183 PASS, 1471 asercji, 0 FAIL, 0 błędów, 0 ostrzeżeń, 0 pominiętych**.
- Zgodnie z ograniczeniami nie uruchomiono 17 testów bezpieczeństwa Stage 2C: 15 testów `RawMaterialPermissionBootstrapTest` oraz po jednym wskazanym teście `AuthorizationMutationBoundaryTest` i `RoleAdministrationTest`.
- Laravel Pint: 9 plików PASS. PHP lint: wszystkie pliki PHP Stage 2D.2 PASS. Konfiguracja Compose i `git diff --check`: PASS.
- W toku testów naprawiono brak uprawnienia runtime wynikający z blokady `SELECT FOR UPDATE` (zastąpiono ją transakcyjną blokadą advisory) oraz uzupełniono odczyt pola `stored_at`. Końcowa pełna regresja przeszła bez błędów.

## Ograniczenia i decyzje przed produkcyjnym przepływem

- Stan `PENDING_SCAN` pozostaje obowiązkowy. Nie ma integracji antywirusowej ani ścieżki pobierania; produkcyjny upload i udostępnianie plików muszą pozostać wyłączone do zatwierdzenia polityki skanowania i wdrożenia integracji.
- Prywatny dysk lokalny jest adapterem deweloperskim. Przed produkcją należy zatwierdzić docelowy prywatny backend, retencję, kopie zapasowe/odtwarzanie oraz procedurę uzgadniania ewentualnych osieroconych obiektów po niejednoznacznej awarii bazy.
- Nie potwierdzono żadnego nierozwiązanego defektu w zakresie Stage 2D.2. Testy ograniczone Stage 2C pozostają poza zakresem i nie stanowią dowodu bezpieczeństwa.
