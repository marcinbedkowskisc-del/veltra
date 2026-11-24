# Stage 6A.1 — analiza rozbieżności definicji po restore

## Werdykt

**Wszystkie 53 różnice constraintów i różnica widoku są reprezentacyjne; w badanych definicjach nie stwierdzono różnicy semantycznej.** PostgreSQL reprezentuje rzutowanie tej samej tablicy literałów na `text[]` inaczej przed dumpem i po ponownym sparsowaniu definicji podczas restore.

Test z [raportu backupu](./VELTRA_STAGE6A1_BACKUP_RESTORE_VERIFICATION_REPORT.md) można uznać za **PASS w zakresie logicznego restore schematu i danych według wykonanych kontroli**: restore zakończył się bez błędu, wcześniejsze zestawy i liczby wierszy pasowały, a jedyne 54 różnice definicji zostały wyjaśnione. Jest to uzupełnienie wcześniejszego historycznego werdyktu FAIL, a nie nowe odtworzenie.

**Nie jest to PASS pełnego disaster recovery ani dowód pełnej operacyjnej równoważności bazy.** Nie odtworzono ani nie zweryfikowano globalnych ról, ownership i ACL. Sprawdzono liczby wierszy, nie całkowitą zgodność zawartości wiersz po wierszu ani stan wszystkich sekwencji. Wykazano równoważność analizowanych definicji, nie wszystkich możliwych zachowań aplikacji.

## Potwierdzona tożsamość

- Docker context `default`, daemon `docker-desktop`, ID `1163050e-c562-4a35-bd94-8c24de688587`, root `/var/lib/docker`.
- Źródło: `veltra-stage6a1-postgres-1`, ID `79317d539f35ba9186cd3b6518252be9e5eb89d847087cd0bd382ce466c2fccf`, `running`/`healthy`; baza `veltra_stage6a1`; wolumen `veltra-stage6a1_postgres-data` na `/var/lib/postgresql/data`.
- Restore: `veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c`, ID `75e3fbbadffbb0f5525bf120f6aa6267099c40d81f2b5d347889ffb1df2702c7`, `running`; baza o tej samej nazwie w osobnym klastrze i wolumenie `veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c-data`. Sieć `none`, brak publikowanych portów i brak montowania wolumenu Stage. Etykieta `veltra.restore-test=b01845be954d42199ec77e8909766f7c`.
- Oba serwery PostgreSQL 17.11, UTF8, `en_US.utf8` dla `LC_COLLATE` i `LC_CTYPE`. API Stage: HTTP 200.
- Istniejące archiwum `/home/veltra/backups/stage6a1/veltra-stage6a1-20261123T193945Z-b01845be954d42199ec77e8909766f7c.dump` nadal przechodzi `sha256sum --check`; SHA-256 `1ea1369b02f4014fee09e145998990e8d1b93e861f83ef44c5c54e10d382bf16`.

## Przyczyna i dowody

Poprzedni `restore-inventory.sql` porównywał MD5 **tekstu** z `pg_get_constraintdef(..., false)` i `pg_get_viewdef(..., false)`, a nie znaczenie sparsowanego wyrażenia. Przy takich ustawieniach deparser może ujawnić różne wewnętrzne drzewa wyrażenia mimo zachowania działania.

Przykład `public.locations.locations_type_check`, pełna definicja:

```sql
-- źródło
CHECK (((type)::text = ANY ((ARRAY['CENTRAL_KITCHEN'::character varying, 'RESTAURANT'::character varying])::text[])))
-- restore
CHECK (((type)::text = ANY (ARRAY[('CENTRAL_KITCHEN'::character varying)::text, ('RESTAURANT'::character varying)::text])))
```

W źródle rzutowana jest cała tablica `varchar[]`; po restore rzutowane są jej elementy. W obu przypadkach powstaje ta sama jednowymiarowa tablica `text[]` z tymi samymi wartościami i kolejnością, a `ANY` używa porównania tekstowego. W `pg_cast` obu instancji rzutowanie `character varying` → `text` ma `castmethod=b` (binarnie zgodne), `castcontext=i`, a oba typy mają tę samą domyślną kolację. `text = text` wskazuje na `pg_catalog.texteq`.

- W katalogowym `conbin` powyższego constraintu i `pg_rewrite.ev_action` widoku źródło zawiera węzeł `ARRAYCOERCEEXPR`, którego brak w restore; oba zawierają `ARRAYEXPR`. Jest to bezpośredni dowód różnicy drzew, nie jedynie formatowania białych znaków.
- We wszystkich 54 różniących się definicjach dokładne teksty porównano po zawężonym przepisaniu wyłącznie literałów `varchar[]` rzutowanych na `text[]` do równoważnego rzutowania każdego literału. Wynik: **54/54 dokładnie zgodne**, łącznie 58 takich rzutowań; żadna inna część tekstu nie wymagała zmiany.
- Niezależnie wykonano w obu instancjach `EXPLAIN (VERBOSE, COSTS OFF, FORMAT JSON)` dla wyrażeń wszystkich 53 constraintów, w transakcjach read-only; porównane wyrażenia wyjściowe planera są **53/53 identyczne**. `EXPLAIN` nie wykonywał skanowania tabel.
- Przy oryginalnym połączeniu obie strony miały `search_path = "$user", public`, efektywnie `pg_catalog, public`, `standard_conforming_strings=on` i `quote_all_identifiers=off`. Wynik potwierdzono też po ujednoliceniu sesji do `search_path=pg_catalog`, z kwalifikacją schematów. Różnice nie wynikają ze zmiany `search_path` ani innego schematu odniesień.
- Zestaw 53 nazw odpowiada dokładnie rozbieżnościom zapisanym wcześniej. Aktualne odciski wszystkich 598 constraintów i dwóch widoków są identyczne z odciskami odpowiednio źródła i restore z pierwotnego porównania, więc diagnoza dotyczy tych samych definicji.
- Porównano wszystkie 231 constraintów `CHECK` i badany widok: nazwy, typy i kolacje wskazanych kolumn, zależności katalogowe i właściwości constraintów są zgodne. Wszystkie 53 wskazane constrainty są `CHECK` i `convalidated=true`; pola `condeferrable`, `condeferred`, `conislocal`, `coninhcount` i `connoinherit` również się zgadzają.

## Zależności i ownership widoku

`public.raw_material_supplier_compatibility_status` ma po obu stronach tę samą listę 41 zależności `pg_rewrite`/`pg_depend`: 35 od kolumn tabel, 5 od kolumn widoku `public.raw_material_approved_compatibility_document_evidence` i jedną wewnętrzną zależność własnej reguły. Zależne tabele to `supplier_products`, `supplier_product_revisions`, `raw_material_supplier_compatibility_assessments`, `raw_materials`, `raw_material_revision_allergen_attestations`, `raw_material_allergen_catalog_versions` i `raw_material_revisions`, wszystkie w `public`.

Po obu stronach zgodne są nazwy, kolejność i typy siedmiu kolumn wynikowych, ich kolacje oraz opcja `security_invoker=true`. Jedyna różnica tekstu definicji dotyczy tablicy statusów atestacji, tego samego rodzaju co dla constraintów.

**Ownership rzeczywiście się różni:** w źródle właściciel widoku nie jest właścicielem bazy ani bieżącym użytkownikiem diagnostycznym; w restore jest nim tymczasowy użytkownik restore, będący właścicielem odtworzonej bazy. To spodziewany skutek `--no-owner`, ale realna różnica metadanych i uprawnień, niezależna od wyjaśnionej różnicy tekstu. `security_invoker=true` jest zachowane; ACL i zachowanie wszystkich ścieżek autoryzacji nie zostały sprawdzone.

## Klasyfikacja wszystkich rozbieżności

Wszystkie poniższe obiekty, schemat `public`, mają klasyfikację **tylko reprezentacja rzutowania, bez wykrytej różnicy semantycznej**. Żaden nie pozostaje nierozstrzygnięty w zakresie definicji.

1. `account_email_deliveries.account_email_deliveries_kind_check`
2. `locations.locations_type_check`
3. `membership_requests.membership_requests_decision_check`
4. `organization_exchange_rate_events.organization_exchange_rate_events_source_code_check`
5. `organization_permission_grants.organization_permission_grants_permission_check`
6. `organization_sku_assignments.organization_sku_assignments_allocation_source_check`
7. `organization_sku_assignments.organization_sku_assignments_entity_type_check`
8. `organization_sku_assignments.organization_sku_assignments_prefix_check`
9. `organization_sku_counters.organization_sku_counters_prefix_check`
10. `organization_suppliers.organization_suppliers_lifecycle_state_check`
11. `permission_grants.permission_grants_permission_check`
12. `raw_material_allergen_catalog_versions.raw_material_allergen_catalog_versions_status_check`
13. `raw_material_audit_events.raw_material_audit_events_outcome_check`
14. `raw_material_catalog_import_rows.raw_material_catalog_import_rows_base_unit_code_check`
15. `raw_material_catalog_import_rows.raw_material_catalog_import_rows_result_code_check`
16. `raw_material_document_lifecycle_events.raw_material_document_lifecycle_events_event_code_check`
17. `raw_material_document_scan_results.raw_material_document_scan_results_result_state_check`
18. `raw_material_document_uploads.raw_material_document_uploads_scan_state_check`
19. `raw_material_document_uploads.raw_material_document_uploads_state_check`
20. `raw_material_document_version_links.raw_material_document_version_links_link_purpose_check`
21. `raw_material_documents.raw_material_documents_document_type_check`
22. `raw_material_import_batches.raw_material_import_batches_batch_status_check`
23. `raw_material_import_metadata_events.raw_material_import_metadata_events_event_type_check`
24. `raw_material_import_metadata_events.raw_material_import_metadata_events_input_unit_check`
25. `raw_material_import_rows.raw_material_import_rows_check`
26. `raw_material_import_rows.raw_material_import_rows_input_unit_check`
27. `raw_material_import_rows.raw_material_import_rows_result_code_check`
28. `raw_material_reference_price_events.raw_material_reference_price_events_check1`
29. `raw_material_reference_price_events.raw_material_reference_price_events_event_type_check`
30. `raw_material_reference_price_events.raw_reference_price_input_check`
31. `raw_material_revision_allergen_attestations.raw_material_revision_allergen_attesta_attestation_status_check`
32. `raw_material_revision_allergen_declarations.raw_material_revision_allergen_declarations_exposure_kind_check`
33. `raw_material_revision_approval_events.raw_material_revision_approval_events_outcome_check`
34. `raw_material_revision_units.raw_material_revision_units_conversion_kind_check`
35. `raw_material_revision_units.raw_material_revision_units_dimension_check`
36. `raw_material_revision_units.raw_material_revision_units_unit_code_check`
37. `raw_material_revisions.raw_material_revisions_allergen_state_check`
38. `raw_material_revisions.raw_material_revisions_base_unit_code_check`
39. `raw_material_revisions.raw_material_revisions_base_unit_dimension_check`
40. `raw_material_revisions.raw_revision_pricing_input_check`
41. `raw_material_supplier_compatibility_assessments.raw_material_supplier_compatibility_assessments_outcome_check`
42. `raw_materials.raw_materials_check4`
43. `raw_materials.raw_materials_lifecycle_state_check`
44. `raw_materials_commercial_audit_events.raw_materials_commercial_audit_events_outcome_check`
45. `role_assignments.role_assignments_check`
46. `role_assignments.role_assignments_role_check`
47. `security_events.security_events_event_kind_check`
48. `security_events.security_events_outcome_check`
49. `supplier_offer_events.supplier_offer_events_event_type_check`
50. `supplier_offer_fx_normalization_events.supplier_offer_fx_normalization_events_source_code_check`
51. `supplier_offers.supplier_offers_lifecycle_state_check`
52. `supplier_product_revisions.supplier_product_revisions_content_unit_code_check`
53. `supplier_products.supplier_products_lifecycle_state_check`
54. Widok `raw_material_supplier_compatibility_status`.

## Metoda i zakres wykonanych operacji

Przeanalizowano istniejący `restore-inventory.sql`, oba zachowane wyniki oraz aktualne definicje i metadane PostgreSQL. Zapytania do obu baz wykonywano przez `docker --context default exec -i ... psql -X -qAt -v ON_ERROR_STOP=1`, z `BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY` i `ROLLBACK`. Do porównania kanonicznego użyto `PGOPTIONS='-c default_transaction_read_only=on -c search_path=pg_catalog'`. Nazwy użytkownika i bazy źródła pobierano wewnątrz jego kontenera ze zmiennych środowiska, bez wypisywania ich wartości.

Zapytania i porównania w tej sesji zapisano pomocniczo w:

- `/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/restore-definition-analysis.sql`
- `/home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/restore-explain-compare.py`
- `source-definitions.jsonl`, `restored-definitions.jsonl`, `source-current-hashes.txt`, `restored-current-hashes.txt` w tym samym katalogu. Zawierają metadane schematu, nie rekordy aplikacyjne.

Nie tworzono backupu, nie odtwarzano ponownie bazy, nie wykonywano DDL, DML, migracji ani seederów. Żadnego kontenera ani wolumenu nie zmieniono, nie uruchomiono ponownie i nie usunięto.

## Ryzyka i następny krok

1. Poprosić właściciela o **osobną autoryzację** na plan pełnego recovery ról globalnych, właścicieli i ACL oraz jego izolowaną weryfikację, jeśli wymagany jest pełny disaster recovery. Nie traktować obecnego `--no-owner --no-acl` jako dowodu tych właściwości.
2. Osobno uzyskać decyzję i autoryzację na bezpieczne usunięcie zachowanego kontenera i wolumenu testowego po ponownym sprawdzeniu identyfikatorów; do tego czasu pozostają nienaruszone.
3. Przed migracjami rozstrzygnąć pozostałe blokery z raportu luki migracyjnej, w tym zgodność obrazu backendu, okno maintenance i osobną zgodę. Ten raport nie autoryzuje migracji, zmian readiness ani uprawnień.
