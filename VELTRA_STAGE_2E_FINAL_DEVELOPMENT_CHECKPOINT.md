# VELTRA 2.0 — Stage 2E.7 — Końcowy checkpoint rozwojowy wymiany CSV

**Data:** 6 listopada 2026 r.  
**Zakres:** zintegrowana weryfikacja backendowych przepływów CSV Stage 2E.1–2E.6  
**Środowisko:** DEVELOPMENT; odizolowana, jednorazowa baza PostgreSQL 17.11  
**Werdykt:** **DEVELOPMENT COMPLETE — zakres wymiany CSV Stage 2E zweryfikowany**  
**Gotowość produkcyjna:** **NO — nie jest potwierdzona**

## 1. Podsumowanie

Przepływy importu i eksportu CSV sprawdzono łącznie, w tym w jednym ciągłym scenariuszu API obejmującym utworzenie katalogu RAW, zatwierdzenie rewizji, import cen i metadanych oraz eksporty według daty historycznej. Scenariusz integracyjny i pełna dozwolona regresja zakończyły się powodzeniem. Nie stwierdzono potwierdzonej usterki Stage 2E wymagającej poprawy logiki aplikacyjnej.

W ramach Stage 2E.7 dodano trwały test integracyjny do `backend/tests/Feature/RawMaterialsTest.php`. Nie dodano funkcji biznesowych, endpointów ani migracji.

## 2. Zakres ukończony według podetapu

| Podetap | Zakres zweryfikowany |
|---|---|
| 2E.1 | Append-only zapis importowanych cen referencyjnych, zdarzeń VAT/jednostki, dokładne SKU, precyzja dziesiętna i historia. |
| 2E.2 | Oddzielny sześciokolumnowy CSV cen: podgląd pełnego pliku, jawny commit, idempotencja, atomowość, audyt i statusy `BEZ ZMIAN`, `ZMIANA DANYCH`, `ZMIANA CENY` oraz konflikt daty. |
| 2E.3 | Zachowany, bajtowo zgodny eksport `ingredient_id;ingredient_name`. |
| SKU | Wspólny alokator i niezależne liczniki RAW/INT/PRD/MEN; zachowanie importowanych identyfikatorów oraz historycznej zgodności SUR. Domeny biznesowe importu nadal obejmują tylko RAW. |
| 2E.4 | Osobny import katalogu RAW v1 z preassigned SKU; szkic, niezatwierdzona rewizja, podgląd, commit, idempotencja i historia. |
| 2E.5 | Osobny, rozszerzony eksport zatwierdzonego katalogu RAW; wyłącznie rekordy aktywne, deterministyczne SKU i ścieżka kategorii. |
| 2E.6 | Osobny eksport cen referencyjnych i metadanych według `as_of_date`, z niezależnym odczytem historii ceny i VAT/jednostki. |

## 3. Zintegrowany scenariusz i wyniki

Scenariusz uruchomiono przez istniejące API i usługi domenowe na nowej, jednorazowej bazie:

1. Utworzono testową organizację, aktora, kontekst i kategorię przy użyciu istniejących mechanizmów testowych/aplikacyjnych.
2. Przez endpoint podglądu i commit zaimportowano trzy SKU: `RAW-000801`, `RAW-000802`, `RAW-000803`. Ponowiony commit z tym samym kluczem idempotencji zwrócił ten sam batch.
3. Zweryfikowano zachowanie SKU, stan `DRAFT` i brak zatwierdzonej rewizji po imporcie.
4. Oba eksporty katalogowe pomijały niezatwierdzone szkice.
5. Dla każdej pozycji wykonano normalną deklarację alergenową/revizję oraz submit i approve przez istniejące ścieżki aplikacyjne/API. Nie zmieniano bezpośrednio stanu zatwierdzenia rekordów.
6. Stary eksport zachował dokładny dwukolumnowy nagłówek i oczekiwane bajty CSV. Rozszerzony eksport zwrócił zatwierdzone atrybuty i numer zatwierdzonej rewizji `2` po zmianie rewizji w procesie deklaracji alergenowej.
7. Przez import cenowy zapisano wsad bazowy dla trzech RAW, a następnie drugi wsad obejmujący zmianę wyłącznie VAT, zmianę wyłącznie ceny i rekord bez zmian. Statusy w podglądzie były odpowiednio `ZMIANA DANYCH`, `ZMIANA CENY`, `BEZ ZMIAN`.
8. Eksporty `2025-12-31`, `2026-01-01` i `2026-01-03` odpowiadały historii: przed ceną zwrócono `NO_REFERENCE_PRICE`; następnie wartości bazowe; na końcu niezależną zmianę VAT i cenę `5.25` dla drugiego SKU. Bez zmian pozostał trzeci SKU.
9. Po importach alokator wygenerował `RAW-000804`; nie wystąpiło nadpisanie ani kolizja importowanych identyfikatorów.

**Wynik testu integracyjnego:** **1 test, 69 asercji — PASS**.

Testy regresyjne w tej samej dozwolonej suite pokryły też konflikty i duplikaty, atomowy rollback, zmianę danych między podglądem a commit, retry idempotency, co najmniej 375 rekordów, izolację tenantów, zgodność SUR, readiness 503, ograniczenia RBAC, współbieżną rezerwację SKU i historię cen.

## 4. Regresja i kontrole

| Kontrola | Wynik |
|---|---:|
| Pełna dozwolona regresja backendu, łącznie z nowym testem integracyjnym | **235 testów, 2113 asercji — PASS** |
| Test integracyjny ciągłego przepływu CSV | **1 test, 69 asercji — PASS** |
| PHP lint wszystkich zmienionych plików PHP | **15 plików — PASS** |
| Pint `--test` | **15 plików — PASS** |
| `git diff --check` | **PASS** |
| Testy zastrzeżone Stage 2C | **Dokładnie 17 pominięto; nie uruchomiono** |

Przed regresją wyliczono testy: 252 łącznie, 17 zastrzeżonych i 235 dozwolonych. Wyłączono wszystkie 15 testów `RawMaterialPermissionBootstrapTest` oraz dokładnie:

- `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`
- `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`

Wstępny przebieg suite z niepełną konfiguracją runnera nie jest zaliczany do wyniku: brak `SANCTUM_STATEFUL_DOMAINS` powodował odrzucenie testowych żądań jako wymagających aktywnej sesji przeglądarki. Po ustawieniu kompletnej konfiguracji testowej testy uwierzytelniania oraz finalna regresja przeszły. Nie był to defekt aplikacji.

## 5. Izolacja i stan schematu

- Docker: PostgreSQL `17.11`, Redis `7.4` i ClamAV `1.4` w nowej sieci `internal=true`.
- PostgreSQL i Redis korzystały wyłącznie z oddzielnego tmpfs; usługi nie miały opublikowanych portów hosta ani istniejących wolumenów. Runner nie miał mountów. Istniejące kontenery, bazy i wolumeny aplikacyjne nie były używane ani modyfikowane.
- W jednorazowej bazie zastosowano osobne role `veltra_migrator` i `veltra_app` zgodnie z wymogami migracji least-privilege. Wszystkie **36 migracji** zakończyły się statusem `Ran`.
- Nie dodano migracji w Stage 2E.7. Migracja `2026_11_06_000036_create_raw_material_catalog_csv_import_history` należy do Stage 2E.4; została zastosowana wyłącznie w bazie disposable.
- Niezależny test upgrade 034→035 z historycznymi rekordami SUR, licznikami i historią cen został wcześniej zakończony wynikiem PASS i opisany w raporcie Stage 2E.4. Nie wykonano migracji na bazie aplikacyjnej w tym zadaniu.
- Bramka `RAW_MATERIALS NOT_READY` zwróciła HTTP 503 w testach. Nie zmieniono gotowości ani konfiguracji istniejących aplikacyjnych kontenerów. `RAW_MATERIALS` i `VSEF` nie zostały aktywowane.

## 6. Kontrakty CSV i istniejące endpointy

Ścieżki poniżej mają prefiks `/api/v1/raw-materials`.

### Import katalogu RAW v1

Nagłówek:

```csv
sku;entity_type;name;description;base_unit_code;category_code
RAW-000801;RAW;Mąka integracyjna;Opis mąki;kg;DRY_GOODS
```

- `POST /catalog-imports` — pełny podgląd.
- `POST /catalog-imports/{preview}/commit` — jawny, autoryzowany commit.
- `GET /catalog-imports/results/{batch}` — wynik i historia wsadu.

### Import cen referencyjnych

Zachowany sześciokolumnowy nagłówek:

```csv
ingredient_id;ingredient_name;price_net;vat_rate;unit;effective_date
```

- `POST /imports`
- `POST /imports/{preview}/commit`
- `GET /imports/results/{batch}`

### Eksporty

- Stage 2E.3: `POST /catalog-exports`, pobranie `GET /catalog-exports/{export}/download`; nagłówek `ingredient_id;ingredient_name`.
- Stage 2E.5: `POST /catalog-exports/extended`, pobranie `GET /catalog-exports/extended/{export}/download`; nagłówek `sku;entity_type;name;description;category_code;category_path_codes;base_unit_code;approved_revision_no`.
- Stage 2E.6: `POST /reference-price-exports` z `as_of_date` w formacie `YYYY-MM-DD`; pobranie `GET /reference-price-exports/{export}/download`. Nagłówek: `as_of_date;sku;entity_type;approved_name;category_code;base_unit_code;reference_price_net_pln_per_base_unit;reference_price_effective_from;reference_price_status;vat_rate_percent;vat_input_unit;metadata_effective_from;metadata_status`.

Profile pozostają odrębne. Eksport cenowy nie miesza ceny referencyjnej z ceną oferty dostawcy, ceną opakowania ani kosztem produkcji.

## 7. Defekty i zmiany

Nie potwierdzono defektu implementacji Stage 2E i nie wprowadzono poprawki logiki biznesowej. Dostosowano konfigurację jednorazowego runnera oraz asercje testu do rzeczywistego quoting CSV, skali VAT `23.00` i numeru rewizji po normalnej deklaracji alergenowej; nie zmieniało to zachowania aplikacji.

Poza nowym testem integracyjnym w `backend/tests/Feature/RawMaterialsTest.php` nie wprowadzono w Stage 2E.7 zmian źródłowych ani schematu.

## 8. Bezpieczeństwo i ograniczenia

- Stage 2C security acceptance **nie została wykonana ani zaliczona**. Wymienionych 17 testów nie uruchamiano zgodnie z ograniczeniem.
- Wynik PASS dotyczy rozwojowego zakresu wymiany CSV i dozwolonej regresji; nie jest akceptacją bezpieczeństwa ani gotowością produkcyjną.
- API nadal wymaga istniejącego kontekstu organizacji/lokalizacji, uprawnień, audytu, podpisanych granic mutacji i bramki gotowości.
- Nie uruchamiano ani nie modyfikowano rzeczywistego procesu wdrożeniowego, aplikacyjnych baz danych, oryginalnych wolumenów, konfiguracji produkcyjnej ani statusu modułów.

## 9. Pozostały zakres rozwojowy i rekomendowany następny etap

Poza ukończonym zakresem CSV pozostają:

- katalogowe importy INT/PRD/MEN oraz ich procesy biznesowe — alokator SKU sam w sobie ich nie implementuje;
- wymiana opakowań, produktów dostawców i ofert handlowych, odrębna od cen referencyjnych;
- uzgodniony proces operacyjny i bezpieczeństwa przed wdrożeniem produkcyjnym;
- niezależna decyzja i wykonanie Stage 2C security acceptance.

**Rekomendacja:** następny etap architektoniczny po decyzji właściciela powinien osobno zaprojektować wymianę danych handlowych dostawców i opakowań, z jawnym rozdzieleniem ceny opakowania/oferty od ceny referencyjnej. Ewentualna praca nad tym zakresem nie powinna aktywować `RAW_MATERIALS` ani zastępować niezależnej akceptacji Stage 2C.

**Końcowy werdykt:** **DEVELOPMENT COMPLETE dla zakresu CSV Stage 2E; PRODUCTION READY — NO.**
