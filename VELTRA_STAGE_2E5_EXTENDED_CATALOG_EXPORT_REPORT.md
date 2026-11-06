# VELTRA 2.0 — raport Stage 2E.5: rozszerzony eksport katalogu RAW

**Data:** 6 listopada 2026 r.  
**Zakres:** backendowy, tylko do odczytu eksport rozszerzonego katalogu RAW  
**Środowisko:** DEVELOPMENT; testy wyłącznie na disposable PostgreSQL 17, Redis 7 i ClamAV  
**Werdykt Stage 2E.5:** PASS

## 1. Wynik

Dodano osobny profil rozszerzonego eksportu katalogu RAW, bez zmiany formatu ani adresów Stage 2E.3. Eksport zwraca wyłącznie aktywne surowce z dokładnie wskazaną, zatwierdzoną rewizją; dane rewizji i tenantowo ograniczonej hierarchii kategorii są odczytywane jednym zapytaniem SQL.

Nie dodano migracji ani tabel. Nie zmieniano importu katalogu Stage 2E.4, importu cen Stage 2E.1–2E.2, generatora SKU ani cyklu zatwierdzania. `RAW_MATERIALS` i `VSEF` pozostają nieaktywowane; nie przeprowadzono akceptacji bezpieczeństwa Stage 2C.

## 2. Kontrakt CSV

Dokładny nagłówek:

```csv
sku;entity_type;name;description;category_code;category_path_codes;base_unit_code;approved_revision_no
```

Przykładowy wiersz:

```csv
RAW-000123;RAW;"Mąka; ""biała""";"Opis; dokładny ""cytat""";FLOUR;DRY_GOODS/GRAINS/FLOUR;g;1
```

Plik jest kodowany w UTF-8, ma separator `;`, standardowe quoting CSV i zakończenia wierszy LF. Wartości nazwy, opisu i SKU pochodzą bez zmian z danych zapisanych/zatwierdzonej rewizji. Kody ścieżki kategorii są zwracane od korzenia do liścia, rozdzielone `/`. Dla historycznego `SUR-…` eksport zachowuje to SKU, a `entity_type` ma wartość `RAW`.

Eksport:

- sortuje po dokładnym SKU;
- pomija szkice, rewizje oczekujące i rekordy bez spójnej zatwierdzonej rewizji;
- nie zawiera UUID, cen, VAT, dostawców, ofert, opakowań ani danych zakupowych;
- odrzuca eksport, gdy SKU/nazwa/opis mogłyby zostać wykonane jako formuła arkusza; nie modyfikuje zatwierdzonych danych i raportuje SKU oraz ryzykowne pola;
- korzysta ze wspólnego prywatnego storage, manifestu, SHA-256, limitów, TTL i audytu;
- ponownie sprawdza uprawnienie i kontekst organizacji przy pobraniu; nie udostępnia publicznego URL.

## 3. Endpointy i zgodność

- `POST /api/v1/raw-materials/catalog-exports/extended` — utworzenie eksportu.
- `GET /api/v1/raw-materials/catalog-exports/extended/{export}/download` — autoryzowane pobranie.

Oba endpointy używają istniejącej bramki `RAW_MATERIALS` i wymagają dotychczasowego dostępu do odczytu katalogu oraz kontekstu członkostwa/lokalizacji.

Stage 2E.3 pozostał bez zmian: jego CSV nadal zawiera dokładnie:

```csv
ingredient_id;ingredient_name
```

Test porównuje wynik tego profilu bajt w bajt z zatwierdzonym formatem. Import cenowy nadal zachowuje sześciokolumnowy kontrakt i nie jest częścią eksportu.

## 4. Zmiany plików

- [`RawMaterialCsvCatalogExportWorkflow.php`](./backend/app/Domain/RawMaterials/RawMaterialCsvCatalogExportWorkflow.php) — wspólny mechanizm dwóch profili; pojedynczy snapshot SQL rozszerzonego eksportu, ścieżki kategorii, walidacja ryzyka formuł i profil manifestu/pobrania.
- [`RawMaterialCsvCatalogExportController.php`](./backend/app/Http/Controllers/Api/V1/RawMaterialCsvCatalogExportController.php) — osobne akcje tworzenia i pobrania rozszerzonego profilu.
- [`api.php`](./backend/routes/api.php) — nowe trasy rozszerzonego eksportu; dotychczasowe trasy Stage 2E.3 bez zmian.
- [`RawMaterialsTest.php`](./backend/tests/Feature/RawMaterialsTest.php) — testy kontraktu, zatwierdzonego snapshotu, kategorii, historycznego SKU, formuł, reautoryzacji, readiness oraz zgodności bajtowej starego eksportu.
- [`VELTRA_STAGE_2E_EXTENDED_CSV_EXCHANGE_DESIGN.md`](./VELTRA_STAGE_2E_EXTENDED_CSV_EXCHANGE_DESIGN.md) — aktualizacja statusu wdrożenia i rzeczywistego formatu ścieżki kategorii.

Nie dodano schematu ani nowych zależności.

## 5. Weryfikacja

Wszystkie testy używały nowej sieci Docker `internal=true` i dedykowanych, jednorazowych usług bez portów hosta ani istniejących danych aplikacyjnych. PostgreSQL był w disposable storage, Redis miał osobny efemeryczny wolumen, a runner nie miał podłączonych wolumenów. Istniejące kontenery aplikacyjne, ich bazy i wolumeny nie były używane do testów ani modyfikowane.

| Sprawdzenie | Wynik |
|---|---:|
| Testy obu profili eksportu, w tym dokładna bajtowa zgodność Stage 2E.3 | 7 testów, 95 asercji — PASS |
| Test skanera ClamAV disposable | 1 test, 6 asercji — PASS |
| Dozwolona regresja całego backendu | 229 testów, 1972 asercje — PASS |
| Ograniczone testy Stage 2C | Dokładnie 17 pominięto |
| PHP lint | 241 plików — PASS |
| Pint `--test` | 15 zmienionych plików PHP — PASS |
| `git diff --check` | PASS |

Wyłączono wszystkie 15 testów `RawMaterialPermissionBootstrapTest` oraz dokładnie:

- `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`
- `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`

Stage 2C nie został zaakceptowany.

## 6. Pozostałe ograniczenia

- Eksport obejmuje wyłącznie domenę RAW; nie wdrożono INT, PRD ani MEN.
- Eksport cen referencyjnych, VAT/metadanych, ofert dostawców i opakowań pozostaje poza zakresem.
- Nie uruchomiono frontendowego UI; eksport jest dostępny przez API.
- Bieżące działanie bramki `RAW_MATERIALS NOT_READY` pozostaje obowiązkowe; w teście rozszerzony endpoint zwracał `503`.
- Nie wykonano wdrożenia, aktywacji modułów, commitów ani pushy.

## 7. Odniesienia

- [Projekt rozszerzonego kontraktu wymiany CSV](./VELTRA_STAGE_2E_EXTENDED_CSV_EXCHANGE_DESIGN.md)
- [Raport Stage 2E.4 — import katalogu RAW](./VELTRA_STAGE_2E4_RAW_CATALOG_IMPORT_REPORT.md)
- [Zatwierdzony kontrakt CSV cen referencyjnych](./VELTRA_CSV_DATA_EXCHANGE_CONTRACT.md)
