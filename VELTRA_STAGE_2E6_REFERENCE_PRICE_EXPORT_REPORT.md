# VELTRA 2.0 — raport Stage 2E.6: eksport cen referencyjnych i VAT

**Data:** 6 listopada 2026 r.  
**Zakres:** backendowy, tylko do odczytu eksport referencyjnych cen RAW oraz historycznych metadanych VAT/jednostki  
**Środowisko:** DEVELOPMENT; testy wyłącznie na disposable PostgreSQL 17, Redis 7 i ClamAV  
**Werdykt Stage 2E.6:** PASS

## 1. Wynik

Dodano odrębny, prywatny profil eksportu CSV, który generuje snapshot na wskazaną datę biznesową. Cena referencyjna oraz metadane VAT/jednostki są wybierane niezależnie z ich append-only historii. Eksport nie tworzy ani nie zmienia danych katalogowych, zdarzeń cenowych, danych dostawców ani historycznych kosztów produkcji.

Zachowano bez zmian dwa wcześniejsze profile katalogowe: Stage 2E.3 nadal zwraca `ingredient_id;ingredient_name`, a Stage 2E.5 pozostaje osobnym eksportem rozszerzonego katalogu RAW. Nie zmieniano importu katalogu Stage 2E.4 ani sześciokolumnowego importu cen Stage 2E.1–2E.2. Nie dodano migracji.

## 2. Kontrakt endpointu i CSV

Utworzenie eksportu:

```http
POST /api/v1/raw-materials/reference-price-exports
Content-Type: application/json

{"as_of_date":"2026-01-03"}
```

`as_of_date` jest wymagane i musi być prawidłową datą ISO `YYYY-MM-DD`. Odpowiedź zawiera identyfikator eksportu, liczbę wierszy, SHA-256, termin wygaśnięcia, adres API pobrania i datę snapshotu.

Pobranie:

```http
GET /api/v1/raw-materials/reference-price-exports/{export}/download
```

Dokładny nagłówek:

```csv
as_of_date;sku;entity_type;approved_name;category_code;base_unit_code;reference_price_net_pln_per_base_unit;reference_price_effective_from;reference_price_status;vat_rate_percent;vat_input_unit;metadata_effective_from;metadata_status
```

Przykładowe wiersze:

```csv
as_of_date;sku;entity_type;approved_name;category_code;base_unit_code;reference_price_net_pln_per_base_unit;reference_price_effective_from;reference_price_status;vat_rate_percent;vat_input_unit;metadata_effective_from;metadata_status
2026-01-03;RAW-000123;RAW;Mąka pszenna;FLOUR;g;0.004125;2026-01-01;KNOWN;23.00;l;2026-01-03;KNOWN
2026-01-03;SUR-000124;RAW;Historyczny surowiec;GRAINS;kg;1.23456789012345;2026-01-02;KNOWN;;;;UNKNOWN
2026-01-03;RAW-000125;RAW;Bez znanej ceny;GRAINS;g;;;NO_REFERENCE_PRICE;;;;UNKNOWN
```

SKU jest eksportowane dokładnie tak, jak zapisano je w systemie, w tym historyczne `SUR-…`. `entity_type` opisuje domenę RAW, również dla zachowanych rekordów SUR. Nazwa, kategoria i jednostka bazowa pochodzą z tej samej zatwierdzonej rewizji. Nie są eksportowane UUID, oferty dostawców, ceny opakowań, VAT dostawcy ani koszty produkcji.

Format używa UTF-8, separatora `;`, standardowego cytowania CSV i LF. Kwoty dziesiętne są pobierane jako tekst PostgreSQL; nie ma arytmetyki zmiennoprzecinkowej. Brak lub wycofanie ceny daje puste pole kwoty i daty oraz `NO_REFERENCE_PRICE`, nigdy syntetyczne zero. Brak historii VAT/jednostki daje puste pola i `UNKNOWN`. Metadane wejściowe nie zastępują zatwierdzonej jednostki bazowej.

## 3. Semantyka historii

Każdy snapshot powstaje jednym zapytaniem SQL ograniczonym do organizacji aktora, aktywnych materiałów i spójnej zatwierdzonej rewizji. Dzięki pojedynczemu odczytowi dane katalogowe, cena i metadane są pobierane z jednego snapshotu bazy.

- **Cena:** `raw_material_reference_price_events`, tylko zdarzenia z `effective_from <= as_of_date`, sortowanie `effective_from DESC, event_no DESC`. Ostatnie `PRICE_SET` ma status `KNOWN`; ostatnie `PRICE_WITHDRAWN` oznacza `NO_REFERENCE_PRICE`.
- **VAT i jednostka wejściowa:** `raw_material_import_metadata_events`, tylko zdarzenia z `effective_from <= as_of_date`, sortowanie `effective_from DESC, recorded_at DESC, id`. Stan jest wybierany niezależnie od ceny.
- **Granica daty:** zdarzenie z datą równą `as_of_date` jest uwzględniane; zdarzenia późniejsze nie wpływają na wcześniejszy snapshot.
- Zmiana samego VAT/jednostki nie tworzy zdarzenia ceny; zmiana samej ceny nie tworzy zdarzenia VAT/jednostki.
- Eksport nie odtwarza brakującej historii z bieżącego stanu ani z `raw_material_financial_restatements`. Nie zmienia i nie przelicza snapshotów kosztów zamkniętej produkcji.

## 4. Uprawnienia i ochrona

- Utworzenie i pobranie wymagają `RAW_MATERIALS_REFERENCE_PRICE_VIEW`; samo uprawnienie do odczytu katalogu nie wystarcza.
- Trasy pozostają pod `RequireRawMaterialsAvailability`; stan `RAW_MATERIALS NOT_READY` zwraca `503`.
- Zachowano członkostwo, kontekst organizacji/lokalizacji, tenant scope, prywatny storage, manifest, SHA-256, limity, TTL, audyt oraz ponowną autoryzację przy pobraniu.
- Publiczny URL nie jest tworzony. Wygasły lub brakujący manifest blokuje pobranie.
- Ryzyko formuły arkusza powoduje jawne odrzucenie eksportu z informacją o SKU i polach. Zatwierdzona nazwa/SKU nie jest po cichu modyfikowana.
- Odpowiedź pobrania ma `text/csv; charset=UTF-8`, `Cache-Control: no-store` i `X-Content-Type-Options: nosniff`.

## 5. Zmiany plików

- [`RawMaterialCsvCatalogExportWorkflow.php`](./backend/app/Domain/RawMaterials/RawMaterialCsvCatalogExportWorkflow.php) — osobny profil cenowy w istniejącym mechanizmie eksportów, selekcja historii as-of, pola CSV, manifest, audyt i autoryzacja pobrania.
- [`RawMaterialCsvCatalogExportController.php`](./backend/app/Http/Controllers/Api/V1/RawMaterialCsvCatalogExportController.php) — akcje utworzenia i pobrania; walidacja daty ISO.
- [`api.php`](./backend/routes/api.php) — dwie osobne trasy profilu cenowego w istniejącej grupie RAW_MATERIALS.
- [`RawMaterialsTest.php`](./backend/tests/Feature/RawMaterialsTest.php) — regresje semantyki as-of, niezależnych historii, wycofania ceny, nieznanego VAT, precyzji dziesiętnej, SUR, dwóch organizacji, RBAC, odwołania uprawnienia, formula injection, TTL i readiness.
- [`VELTRA_STAGE_2E_EXTENDED_CSV_EXCHANGE_DESIGN.md`](./VELTRA_STAGE_2E_EXTENDED_CSV_EXCHANGE_DESIGN.md) — aktualizacja statusu, nagłówka i kontraktu eksportu.

Nie dodano migracji, API pomocniczego, frontendowego UI ani nowego mechanizmu storage.

## 6. Weryfikacja

Testy wykonano na odizolowanej sieci Docker `veltra-stage2e6-reference-export`, z dedykowaną bazą `veltra_testing` PostgreSQL 17.11. PostgreSQL i Redis korzystały wyłącznie z tmpfs; kontenery testowe nie miały portów hosta ani podłączonych wolumenów. Runner nie miał mountów. ClamAV był osobną usługą testową. Istniejące kontenery aplikacyjne, ich bazy i wolumeny nie były używane ani modyfikowane.

| Sprawdzenie | Wynik |
|---|---:|
| Testy profili CSV eksportu, w tym zgodność wcześniejszych eksportów | 13 testów, 174 asercje — PASS |
| Pełna dozwolona regresja backendu | 234 testy, 2044 asercje — PASS |
| Ograniczone testy Stage 2C | Dokładnie 17 pominięto |
| PHP lint | 4 zmienione pliki PHP — PASS |
| Pint `--test` | 4 zmienione pliki PHP — PASS |
| `git diff --check` | PASS |

Przed wykonaniem pełnej regresji sprawdzono listę testów: 251 łącznie, 234 dozwolone. Wyłączono wszystkie 15 testów `RawMaterialPermissionBootstrapTest` oraz dokładnie:

- `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`
- `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`

Testy zawierały jawny fixture dwóch organizacji z aktywnym rekordem i zdarzeniem ceny w obcej organizacji; eksport nie ujawnił jej SKU, nazwy ani kwoty.

## 7. Pozostałe ograniczenia

- Eksport obejmuje wyłącznie aktywne RAW i historyczne SUR zgodne z tym modelem; INT/PRD/MEN nie są zaimplementowane.
- Nie wdrożono eksportu cen ofert dostawców, cen opakowań ani kosztów produkcji.
- Nie uruchomiono frontendowego UI ani wdrożenia produkcyjnego.
- Nie aktywowano `RAW_MATERIALS` ani `VSEF`; nie przeprowadzono Stage 2C security acceptance.
- Nie wykonano migracji, commitów ani pushy.

## 8. Odniesienia

- [Projekt rozszerzonego kontraktu wymiany CSV](./VELTRA_STAGE_2E_EXTENDED_CSV_EXCHANGE_DESIGN.md)
- [Raport Stage 2E.5 — rozszerzony eksport katalogu RAW](./VELTRA_STAGE_2E5_EXTENDED_CATALOG_EXPORT_REPORT.md)
- [Kontrakt importu cenowego CSV](./VELTRA_CSV_DATA_EXCHANGE_CONTRACT.md)
