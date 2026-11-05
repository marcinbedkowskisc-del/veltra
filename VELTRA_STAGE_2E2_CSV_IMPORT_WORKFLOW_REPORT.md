# VELTRA 2.0 — raport Stage 2E.2: przepływ importu CSV

**Data:** 5 listopada 2026 r.  
**Zakres:** wyłącznie DEVELOPMENT  
**Werdykt:** implementacja Stage 2E.2 zakończona; pełna dozwolona regresja PASS

## Podsumowanie

Zaimplementowano backendowy przepływ importu CSV zgodny z zatwierdzonym `VELTRA_CSV_DATA_EXCHANGE_CONTRACT.md` i persystencją Stage 2E.1. Przepływ obejmuje ścisłe parsowanie, kontrolę składników i jednostek, bezmutacyjny podgląd, jawne zatwierdzenie oraz prywatny odczyt wyników. Nie dodano frontendu, eksportu katalogu ani równoległego katalogu lub silnika cen.

## Zrealizowany zakres

### Parsowanie i weryfikacja danych

- Parser przyjmuje wyłącznie UTF-8 i dokładny nagłówek `ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date`.
- Obsługuje średnik, standardowe cytowanie CSV i ISO `YYYY-MM-DD`; odrzuca błędne cytowanie, liczbę kolumn, pusty wiersz/pole, niepoprawne wartości, duplikaty kodów SUR i dodatkowe kolumny.
- Limity są konfigurowalne: domyślnie 5 MiB, 5000 wierszy i 30 minut ważności podglądu.
- Składnik jest wyszukiwany wyłącznie po kodzie `SUR-…` w organizacji bieżącego aktora. Wymagany jest aktywny składnik, aktualna zatwierdzona rewizja i dokładna zgodność nazwy.
- Import sprawdza jednostkę i wymiar na podstawie zatwierdzonych wariantów rewizji. Obsługuje `kg`, `l` i mapowanie `szt.` na `pc`; konwersję do jednostki bazowej wykonuje PostgreSQL na wartościach dziesiętnych z `raw_materials_round_half_even_v1()`, bez floatów.

### Podgląd i zapis

- Podgląd kwalifikuje każdy wiersz jako `BEZ ZMIAN`, `BAZA POCZĄTKOWA METADANYCH`, `ZMIANA DANYCH`, `ZMIANA CENY`, `ZMIANA CENY I DANYCH`, `KONFLIKT DATY` albo błąd.
- Pokazuje numer wiersza, szczegół błędu, cenę znormalizowaną, datę efektywną, oznaczenie daty przyszłej/wstecznej i koniec okresu, którego dotyczy wsteczna zmiana.
- Podgląd nie zapisuje zdarzeń ceny ani metadanych. Jest audytowany; walidacja wierszy pozostaje w historii zdarzeń audytowych bez utrwalania zbędnych danych źródłowych.
- Plik pozostaje na prywatnym dysku `raw_material_csv_imports` (`storage/app/private/raw-material-csv-imports`, bez publicznego serwowania). Commit sprawdza jego SHA-256, ponownie wykonuje walidację, autoryzację i porównanie snapshotu pod blokadą organizacji.
- Zatwierdzenie wymaga `confirmed: true` i `Idempotency-Key`. Zapis wykorzystuje usługę Stage 2E.1 i jej podpisaną granicę mutacji, transakcję, audyt oraz append-only historię wierszy/cen/metadanych. Konflikt daty, zmiana stanu od podglądu lub błąd wejścia nie powodują częściowego zapisu.
- Uprawnienia i izolacja organizacji są sprawdzane na ścieżkach uploadu, zatwierdzenia i odczytu wyników. Istniejąca bramka `RAW_MATERIALS` nie jest omijana.

### Minimalne interfejsy API

| Metoda i ścieżka | Działanie |
|---|---|
| `POST /api/v1/raw-materials/imports` | Upload, walidacja i podgląd; `201` |
| `POST /api/v1/raw-materials/imports/{preview}/commit` | Jawny, idempotentny commit |
| `GET /api/v1/raw-materials/imports/results/{batch}` | Odczyt wyniku batcha i statusów wierszy |

Wszystkie trasy działają pod istniejącym uwierzytelnieniem, kontekstem organizacji, RBAC i middleware `RequireRawMaterialsAvailability`. Przy `RAW_MATERIALS NOT_READY` test rzeczywistego żądania HTTP otrzymuje `503`.

## Migracje i zgodność zakresu

Stage 2E.2 nie wymagał nowej migracji. Przepływ korzysta z istniejących tabel i kontraktu migracji 034 wdrożonej w Stage 2E.1: batchy, wierszy, metadanych, idempotencji, zdarzeń cen referencyjnych i audytu. Nie zmieniono istniejących historycznych snapshotów kosztów ani strumieni cen ofert dostawców.

Nie zaimplementowano eksportu katalogu, frontendu, OCR, faktur, KSeF, księgowości, cen dostawców ani magazynu.

## Weryfikacja

Wszystkie testy wykonano na odizolowanym środowisku testowym:

- runner `veltra-stage2c-sec-runner`, sieć `veltra-stage2c-security-test-internal`;
- PostgreSQL `17.11`, baza `veltra_testing`;
- Redis `7.4.12`;
- PHP `8.4.27`.

Runner, PostgreSQL i Redis były w oddzielnej sieci testowej; runner i Redis nie miały podłączonych wolumenów, a testowy PostgreSQL nie współdzielił wolumenu aplikacyjnego. Kontenery aplikacyjne PostgreSQL/Redis działały w innej sieci i nie były podłączone do runnera. Nie modyfikowano ich baz, kontenerów ani wolumenów.

| Sprawdzenie | Wynik |
|---|---|
| Testy ukierunkowane CSV | **9 testów, 119 asercji — PASS** |
| Pełna dozwolona regresja | **210 testów, 1766 asercji — PASS** |
| Testy ograniczone Stage 2C | **17 wykluczonych, 0 uruchomionych** |
| PHP lint zmienionych plików PHP | **PASS** |
| Pint — 10 zmienionych plików | **PASS** |
| `git diff --check` | **PASS** |
| HTTP przy `RAW_MATERIALS NOT_READY` | **503 — PASS** |

Regresję filtrowano, wykluczając wszystkie 15 przypadków `RawMaterialPermissionBootstrapTest` oraz:

- `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`
- `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`

Żaden z tych 17 testów nie został wykonany ani odtworzony. Pełnej sekwencji migracji 001–034 nie powtarzano w Stage 2E.2; w tej fazie nie dodano migracji, a weryfikacja migracji 034 i sekwencji świeżej bazy została udokumentowana w raporcie Stage 2E.1.

## Defekty i ograniczenia

W trakcie implementacji i testów skorygowano tolerowanie cudzysłowu w niecytowanym polu, prezentację dat wstecznych, jawne obsłużenie niepowodzeń prywatnego storage/cache oraz fixture’y testowe dla jednostek innych niż masa. Po końcowej regresji nie stwierdzono nierozwiązanych defektów Stage 2E.2.

Weryfikacja sukcesu biznesowego używała wyłącznie jawnych fixture’ów testowych z gotowością modułu. W normalnej konfiguracji `RAW_MATERIALS` nadal pozostaje `NOT_READY`; nie aktywowano modułu. Raport nie stwierdza gotowości produkcyjnej ani akceptacji bezpieczeństwa.

## Statusy poza zakresem

- Stage 2C: **OPEN / NOT VERIFIED**; VEL-DEF-007 pozostaje **NOT VERIFIED**.
- 17 ograniczonych testów Stage 2C: **niewykonane**.
- `RAW_MATERIALS`: **NOT_READY**.
- `VSEF`: **nieaktywne**.
- Nie wykonano wdrożenia, commitów ani pushy.
