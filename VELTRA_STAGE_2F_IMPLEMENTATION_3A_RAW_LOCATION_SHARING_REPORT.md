# VELTRA Stage 2F — Implementation 3A

## Zakres i wynik

Zaimplementowano fundament jawnego, odwoływalnego udostępniania materiałów RAW per lokalizacja. Katalog pozostaje organizacyjny i zarządzany z primary central kitchen; rekordy nie są kopiowane między lokalizacjami. `RAW_MATERIALS` pozostał `NOT_READY` i nie został włączony.

## Znalezione problemy i poprawki

- Brakowało trwałego modelu jawnych udziałów. Dodano [migrację 041](./backend/database/migrations/2026_11_08_000041_create_raw_material_location_shares.php), model [RawMaterialLocationShare](./backend/app/Models/RawMaterialLocationShare.php), administrację [RawMaterialLocationShareAdministration](./backend/app/Domain/RawMaterials/RawMaterialLocationShareAdministration.php), politykę [RawMaterialLocationSharePolicy](./backend/app/Domain/RawMaterials/RawMaterialLocationSharePolicy.php) oraz podpisany zakres `raw-material.share` w [AuthorizationMutationScope](./backend/app/Domain/Authorization/AuthorizationMutationScope.php).
- Migracja utrwala organizację, materiał, lokalizację, twórcę, odwołanie i audyt; wymusza aktywny udział bez duplikatów, zachowuje historię i nie przyznaje `DELETE` roli runtime. Utworzenie udziału wymaga aktywnego materiału z zatwierdzoną bieżącą i pierwszą rewizją; odbiorca musi być aktywną lokalizacją tej samej organizacji.
- Polityka odbiorcy wymaga aktywnej tożsamości, membership, znanego primary, zatwierdzonego materiału i dokładnego aktywnego udziału dla kontekstu. Katalog właścicielski primary nie wymaga udziału; pierwsza zatwierdzona rewizja jest warunkiem udostępnienia odbiorcy, nie warunkiem eksportu z primary.
- Wynik importu katalogu CSV nie sprawdzał udziału odbiorcy. Dodano ponowną kontrolę widoczności materiałów w [RawMaterialCatalogCsvImportWorkflow](./backend/app/Domain/RawMaterials/RawMaterialCatalogCsvImportWorkflow.php) i regresję odrzucającą nieudostępniony wynik.
- Kontrole dodano także do katalogu, szczegółów i historii rewizji, eksportów i pobierania eksportów, importów cen, zgodności dostawców oraz dokumentów rewizji. Odwołanie blokuje nowe operacje i wcześniejsze eksporty, nie kasując historii ani odniesień.

## Zmienione obszary

- Migracja, model, share service/policy, podpisany mutation scope.
- RAW authorization, katalog/rewizje, commercial/import/export, compatibility i documents.
- [RawMaterialController](./backend/app/Http/Controllers/Api/V1/RawMaterialController.php), nowe requesty share/revoke, [API routes](./backend/routes/api.php) i polskie komunikaty.
- Regresje w [RawMaterialsTest](./backend/tests/Feature/RawMaterialsTest.php), testach API, rewizji, dokumentów, zgodności i współbieżności oraz workerze [concurrent_raw_material_share](./backend/tests/Support/concurrent_raw_material_share.php).

## Weryfikacja

- Izolacja: projekt Compose `veltra-raw-share-3a`, PostgreSQL `17.11`, osobny wolumen `veltra-raw-share-3a_super-admin-pgdata` i sieć `veltra-raw-share-3a_isolated` z `internal=true`.
- `veltra_app` i `veltra_migrator` są rolami bez `SUPERUSER` i `CREATEDB`. ACL `veltra_app`: `INSERT` udziału dozwolony, `DELETE` zabroniony; próby SQL bez prawidłowego podpisanego kontekstu są objęte regresją.
- Świeża baza i baza upgrade mają po 41 migracji; ostatnia to `2026_11_08_000041_create_raw_material_location_shares`. Zweryfikowano pełne zastosowanie 001–041 na świeżej bazie i upgrade 040→041.
- Pełny PHPUnit: **281 testów, 2741 asercji — PASS**. Skupione testy udziałów/importu: **5 testów, 82 asercje — PASS**; końcowa pełna regresja ponownie objęła te przypadki. Dodatkowy test eksportu primary: **1 test, 69 asercji — PASS**.
- PHP lint: PASS dla `app`, `tests`, `database`, `routes`, `lang`. Pint: **263 pliki — PASS**. `git diff --check`: PASS.
- Wykonane polecenia walidacyjne:

  ```sh
  docker compose -p veltra-raw-share-3a --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml build backend
  docker compose -p veltra-raw-share-3a --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml up -d --no-deps --force-recreate backend
  docker compose -p veltra-raw-share-3a --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend php artisan test
  docker compose -p veltra-raw-share-3a --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend vendor/bin/pint --test
  docker compose -p veltra-raw-share-3a --env-file /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test.env -f /home/veltra/.copilot/session-state/ad0411f3-0f4c-4e25-a9d8-8934695c7289/files/super-admin-test-compose.yaml exec -T backend sh -lc "find app tests database routes lang -name '*.php' -print0 | xargs -0 -n1 php -l >/tmp/php-lint.log && tail -n 1 /tmp/php-lint.log"
  git diff --check
  ```

## Granice i odłożona praca

Brak przepływów produkcyjnych/batchy, snapshotów historycznych rewizji ani aktywacji modułu — pozostają zakresem 3B–3D. Nie zmieniono sześciu ról RBAC ani ochrony primary/SUPER ADMIN. Zasoby instalacyjne, kontenery, wolumeny i konfiguracja instalacji nie były dotykane; nie wykonano deploymentu, commitu ani push.
