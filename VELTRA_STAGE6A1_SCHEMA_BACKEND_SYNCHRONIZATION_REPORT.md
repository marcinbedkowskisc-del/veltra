# Stage 6A.1 — synchronizacja schematu i backendu

## Wynik: BLOCKED — zatrzymano przed zmianami bazy

Działający frontend Stage 6A.1 nie jest w pełni zgodny z kontraktem backendu z rozważanej rewizji. Aktualizacja frontendu nie jest objęta udzieloną autoryzacją. Dodatkowo wymaganego dokumentu VSEF nie znaleziono w dostępnych materiałach, więc nie można potwierdzić jego wymagań.

**Nie uruchomiono migracji, nie zbudowano ani nie wdrożono obrazów i nie zatrzymano żadnej usługi.** Nie zmieniono bazy, ról, uprawnień, gotowości ani aktywacji. Nie wykonano commita ani pusha.

## Blokada zgodności frontend–backend

- Działający frontend: kontener `veltra-stage6a1-frontend-1`, ID `b1930850c0c0af5cfcc78b44fc5edd7724eed8e5ff9de93835801aa23a8f7666`, obraz `sha256:fb824ac54aa38e5c62916aa0ecaf1c36e74886592f8fe5357147a3fa775b0c02`. Jego kod `/app/src/rawMaterials/api.ts` w parserze odpowiedzi edytora RAW dopuszcza przy `NOMINAL_UNIT` tylko `kg`, `l`, `pc`; inne wartości, np. `g` lub `mg`, odrzuca jako błędną odpowiedź. Ogranicza też jednostki bazowe i przeliczeniowe do sześciu stałych kodów.
- Backend z `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6` dopuszcza szersze kody jednostek: `RawMaterialDataRequest` waliduje kod przez regex, `RawMaterialPricingService::assertInputUnit` akceptuje jednostkę `BASE`/`METRIC` o zgodnym wymiarze, a `RawMaterialService::pricingInputForEditor` zwraca zapisany `unit_code`. Migracja `000051` zastępuje wcześniejszy check `kg`/`l`/`pc` słownikiem jednostek, który zawiera m.in. `g` i `mg` oraz dopuszcza nowe kody.
- Oznacza to konkretną niezgodność kontraktu: prawidłowy dla nowego backendu stan `NOMINAL_UNIT` z `unit_code=g` może być odrzucony działającym edytorem. Nie twierdzimy, że taki rekord obecnie istnieje ani że bieżący frontend już zgłasza błąd: problem dotyczy dozwolonych przez nowy kontrakt danych po synchronizacji.
- Frontend w rozważanym `HEAD` ma odpowiednio rozszerzony parser; jego wdrożenie wymaga jednak osobnej decyzji i autoryzacji właściciela. Innego w pełni zgodnego obrazu backendu i queue dla tego działającego frontendu nie zweryfikowano. Nie wolno rozstrzygać zgodności na podstawie samego HTTP 200 ani przemilczeć ograniczenia.

## Wymagane materiały

Przeczytano raporty luki migracyjnej, backupu, wyjaśnienia różnic restore i wdrożenia hardeningu PostgreSQL. Przeczytano również `VELTRA_STAGE_1D_RBAC_AMENDMENT_v1.1.md`, `VELTRA_STAGE_1E_FINAL_VERIFICATION.md`, `VELTRA_STAGE_4B2A_CAPABILITIES_CONTRACT_PROPOSAL.md` i raporty gotowości RAW oraz usunięcia workflow.

Poszukiwanie lokalnego dokumentu „VSEF — Standard bezpiecznego wytwarzania” nie pozwoliło go odnaleźć. `VELTRA_STAGE_7A_INT_PRD_ARCHITECTURE_V1.1.md` wymienia go jedynie jako materiał źródłowy, a `VELTRA_STAGE_2C_SECURITY_ACCEPTANCE_REOPENING_PLAN.md` wskazuje brak osobnego autorytatywnego dokumentu w zbadanych materiałach. Nie utożsamiono wzmianek z odczytem standardu.

## Wyniki preflight

| Warunek | Wynik |
|---|---|
| Docker | `default`; daemon `docker-desktop`, ID `1163050e-c562-4a35-bd94-8c24de688587`, root `/var/lib/docker` — zgodny z dokumentacją. |
| Projekt i PostgreSQL | Projekt `veltra-stage6a1`; kontener `veltra-stage6a1-postgres-1`, ID `79317d539f35ba9186cd3b6518252be9e5eb89d847087cd0bd382ce466c2fccf`, obraz `sha256:f1e5168e94083519d7b12a8516e02439c6bed43cb48424b5d9b3acc1d55aa99d`, `running`/`healthy`; PostgreSQL 17.11, baza `veltra_stage6a1`. |
| Wolumen | Oryginalny `veltra-stage6a1_postgres-data` na `/var/lib/postgresql/data`, etykiety projektu i `postgres-data` poprawne. |
| API przed maintenance | `http://127.0.0.1:8001/api/health` — HTTP 200. Frontend `/` na porcie 5174 — HTTP 200; nie dowodzi zgodności kontraktów. |
| Historia migracji | Read-only: 49 rekordów, 49 nazw unikalnych, max batch 4. Porównanie z plikami Git `HEAD`: 61 plików, dokładnie 12 oczekujących, 0 rekordów bez pliku. |
| Backup | `/home/veltra/backups/stage6a1/veltra-stage6a1-20261123T193945Z-b01845be954d42199ec77e8909766f7c.dump`; `sha256sum --check` — OK, oczekiwany SHA-256 `1ea1369b02f4014fee09e145998990e8d1b93e861f83ef44c5c54e10d382bf16`. Archiwum i checksum pozostawiono bez zmian. |
| Test restore | PASS **w zakresie logicznego restore i wykonanych kontroli**, po wyjaśnieniu 53 constraintów i widoku jako różnic reprezentacji. Globalne role, ownership i ACL nie zostały odtworzone ani zweryfikowane; pozostają ryzykiem pełnego recovery. |
| Izolowany restore | Zachowany kontener `veltra-stage6a1-restore-b01845be954d42199ec77e8909766f7c`, ID `75e3fbbadffbb0f5525bf120f6aa6267099c40d81f2b5d347889ffb1df2702c7`, `network=none`, bez portów; odrębny wolumen testowy, brak montowania wolumenu Stage. Nie usunięto zasobów. |
| Źródło | `main`, `HEAD=3006659240b3a6bdba5b6c0b7b2583b99d21fbe6`; 61 śledzonych migracji. Sprawdzono czyste obiekty Git, nie wykonano builda z worktree. W kodzie backendu i routes `HEAD` nie znaleziono odwołań do dwóch usuwanych widoków ani do tabeli wycofywanego workflow. |
| Worktree | Istnieją niezatwierdzone zmiany hardeningu PostgreSQL, README, plików Compose oraz formatowania `backend/app/Domain/RawMaterials/RawMaterialService.php`; zachowane, nie były użyte do builda ani wdrożenia w bieżącym zadaniu. Hardening PostgreSQL wdrożono wcześniej, zgodnie z osobnym raportem. |
| Stary backend/queue | Oba używają `sha256:a2ba484f1e44442bb2ee55ad5553490e3d9aafca7820d6b2ace0ae1ec1043356` (`veltra-stage6a1-backend:local`), obraz z 2026-11-16; backend `healthy`, queue `running`. Obraz jest niezgodny z migracją `000059` usuwającą używane przez niego widoki. |
| Nowy obraz / zależności | **NIE WYKONANO:** nie zbudowano obrazu backendu/queue i nie zweryfikowano jego ID ani kompletności zależności. Lokalna obecność części obrazów bazowych nie stanowi dowodu, że build zakończyłby się powodzeniem. |
| Role i ownership | Migrator ma LOGIN, `CREATE` bazy, `CREATE` w `public` i `veltra_private`; nie jest superuserem ani `BYPASSRLS`. `pg_has_role(..., 'USAGE')` potwierdza użycie roli właściciela 73/73 badanych relacji i 8/8 funkcji `veltra_private`, w tym obu widoków do usunięcia; nie jest to dowód bezpośredniego ownership. Rola runtime istnieje i ma `USAGE` w `public` oraz `SELECT` na obecnych RAW i supplierach. **Nie potwierdzono kompletu uprawnień wymaganych przez wszystkie 12 migracji i nową aplikację**; nie przyznawano grantów. |
| Klucze runtime | W działającym backendzie `APP_KEY` i `APP_DB_MUTATION_SIGNING_KEY` są obecne; wartości nie były ujawniane. Dostępność wszystkich sekretów w nowym runnerze nie została zweryfikowana. |
| RAW / BOSS | Bieżący backend read-only raportuje `RAW_MATERIALS=READY`; ostatni zapisany stan miał jedną włączoną aktywną lokalizację. Kod `HEAD` ma lokalny warunkowy katalog RAW, a `BOSS_RAW_CATALOG_READ` ma `ready: false`. Stan nowego obrazu, dostępność RAW po deploymencie i bieżące granty użytkowników **nie zostały zweryfikowane**. |
| Dane i reszta preflight | Ponowne pełne kontrole konfliktów danych, pełnego zestawu uprawnień, zgodności frontend–backend i recovery po częściowej migracji nie zostały zakończone. Historyczny preflight z raportu luki jest migawką, nie zastępuje nowej kontroli przed migracją. |

## Status poszczególnych migracji

Wszystkie poniższe: **PENDING — nie wykonano**; kolejność porównana z nazwami plików w `HEAD`:

1. `2026_11_17_000050_add_raw_material_category_description_and_archive.php`
2. `2026_11_17_000051_create_raw_material_unit_dictionary.php`
3. `2026_11_17_000052_create_international_allergen_dictionaries.php`
4. `2026_11_18_000052_create_supplier_directory.php`
5. `2026_11_18_000053_create_installation_precautionary_allergens.php`
6. `2026_11_19_000053_preserve_actor_when_revoking_membership_permissions.php`
7. `2026_11_19_000054_create_boss_location_restriction_revisions.php`
8. `2026_11_19_000055_extend_boss_visibility_policy.php`
9. `2026_11_20_000056_create_organization_capability_state.php`
10. `2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`
11. `2026_11_22_000058_add_user_avatar_state.php`
12. `2026_11_23_000059_retire_raw_material_supplier_compatibility_workflow.php`

Żaden seeder nie został uruchomiony; w szczególności nie uruchomiono osobno seedera osadzonego w migracji `000052`.

## Wykonane operacje

Wyłącznie odczyt raportów, kodu i plików Git; `git status`, `git log`, `git ls-tree`, `git grep`, porównanie kodu działającego frontendu z `git show HEAD:...`; `docker --context default info`, `ps -a`, `inspect`, `volume inspect`, `image ls`, `exec` do odczytu plików i gotowości; `curl` do health endpointów; `sha256sum --check` istniejącej kopii. Zapytania do PostgreSQL wykonywano przez `docker --context default exec -i veltra-stage6a1-postgres-1` i `psql -X -v ON_ERROR_STOP=1`, z `BEGIN TRANSACTION ... READ ONLY` i `ROLLBACK`. Nie wypisano haseł, kluczy ani indywidualnych danych użytkowników.

Porównanie nazw migracji wykonało `git ls-tree -r --name-only HEAD backend/database/migrations/` oraz read-only `SELECT migration FROM public.migrations ORDER BY migration`; różnica zbiorów wyniosła dokładnie 12, bez nieznanych rekordów. Próba wyszukania frontendowych tras przy użyciu opcji `grep --include` zakończyła się błędem nieobsługiwanej opcji BusyBox; powtórzono odczyt kompatybilnym poleceniem. Próba odczytu metadanych obrazu frontendowego przez `docker image inspect` nie odnalazła wskazanego ID; ID odczytano z istniejącego kontenera `docker inspect`, a kod bezpośrednio z kontenera. Nie było to budowanie ani wdrożenie.

## Dalszy krok

1. Właściciel powinien wskazać wymagany dokument VSEF albo wyjaśnić jego zastosowanie.
2. Uzyskać osobną autoryzację na przygotowanie i wdrożenie zgodnego frontendu w tym samym lokalnym Stage 6A.1 **albo** wskazać i zweryfikować zgodną alternatywną, czystą rewizję backendu/queue. W obu wariantach wymagane są testy kontraktu aktualnego frontendu i nowego backendu przed zmianą schematu.
3. Po usunięciu blokad ponowić cały preflight, w tym aktualność kopii względem zapisów, konflikty danych, uprawnienia dla każdej migracji, dostępność sekretów, tożsamość obrazów i plan zachowania bazy po częściowym błędzie. Dopiero po spełnieniu warunków i potwierdzeniu zakresu wykonać autoryzowaną synchronizację.
4. Ręczne testy RAW/BOSS pozostają zablokowane brakiem synchronizacji i wymagają osobnego sprawdzenia gotowości, aktywacji lokalizacji i uprawnień użytkownika. Nie aktywować BOSS.
