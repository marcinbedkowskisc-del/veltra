# VELTRA 2.0 — PA-07B.3C — kontrakt architektoniczny zatwierdzonych dostawców

**Data:** 2026-11-21
**Checkout:** `/home/veltra/projekty/veltra-pa-07b3b`
**HEAD:** `d0d22e65df49fc4e8744ca626ac363126447e36d`
**Status:** REVIEW / PRE-IMPLEMENTATION / STOP

## 1. Podsumowanie wykonawcze

**CONFIRMED CODE FACT:** istniejące `raw_material_supplier_associations` wiążą `organization_id`, `raw_material_id` i `supplier_id`, ale nie wiążą rewizji ani zdarzenia zatwierdzania. `syncSupplierAssociations()` aktualizuje je bezpośrednio podczas tworzenia i edycji working revision: [migration 049](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance.php:178), [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:551).

**CONFIRMED CODE FACT:** nazwy dostawców w zwykłym RAW nie są snapshotem — obecne listing/detail czytają bieżące `organization_suppliers.display_name/legal_name`: [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:529), [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1015).

**CONFIRMED CODE FACT:** approval atomowo tworzy approval event i zmienia `approved_revision_id`; obecny supplier sync odbywa się wcześniej: [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:2131), [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1587), [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1955).

**RECOMMENDATION:** przyjąć addytywny model revision-bound:

1. proposed set związany z dokładną `working_revision_id`;
2. immutable approved snapshot związany z `approved_revision_id`;
3. approval tworzy snapshot i approval event atomowo;
4. legacy associations pozostają `LEGACY_UNVERIFIED` i nie są udostępniane przez 3C;
5. brak danych nie oznacza zatwierdzonego zestawu pustego;
6. w publicznym kontrakcie BOSS rekomendowany jest `supplier_names: string[]|null`, przy czym `[]` oznacza tylko jawnie zatwierdzony pusty zestaw.

**Rekomendacja nazwy:** wariant B — approved supplier identity, bieżąca nazwa słownika. Identity jest immutable, a nazwa może zostać zmieniona przez supplier rename. Snapshot może nie wymuszać starego tekstu nazwy. To jest prostsze i zgodne z obecnym słownikiem dostawców. Jeśli właściciel wymaga historycznie dokładnej nazwy, trzeba osobnego podpisanego snapshot name; to zwiększa zakres i nie może zostać uznane za zatwierdzone retroaktywnie.

**STOP/GO:** **STOP dla implementacji i migracji. GO wyłącznie jako decyzja kontraktowa PO osobnym zatwierdzeniu właściciela 3C.**

## 2. Zweryfikowany baseline repozytorium

**CONFIRMED:**

- branch: `pa-07b3b-boss-raw-catalog`;
- HEAD: `d0d22e65df49fc4e8744ca626ac363126447e36d`;
- worktree tracked files clean; nie ma staged changes;
- nieśledzone raporty istnieją, ale nie są częścią commita;
- nie uruchomiono migracji, bazy, Docker, mutujących testów ani usług.

PA-07B.3A i 3B są lokalnie zaakceptowane:

- 3A tworzy organizacyjny capability state i pozostawia capability NOT_READY: [PA-07B.3A owner acceptance](/home/veltra/projekty/veltra-pa-07b3b/VELTRA_PA_STAGE_07B3A_OWNER_ACCEPTANCE.md:1);
- 3B zatwierdza pięć pól i wyklucza dostawców: [PA-07B.3B owner acceptance](/home/veltra/projekty/veltra-pa-07b3b/VELTRA_PA_STAGE_07B3B_OWNER_ACCEPTANCE.md:1);
- PA-07B.3C nie został wcześniej zatwierdzony do implementacji.

W README istnieje jawna deklaracja, że supplier associations są informacyjne i nie wyznaczają cen: [backend/README.md](/home/veltra/projekty/veltra-pa-07b3b/backend/README.md:59).

Najbardziej aktualny kontrakt 3B znajduje się w osobnym checkoutcie PA-07B.3A i potwierdza, że 3C jest osobnym checkpointem: [VELTRA_PA_STAGE_07B3B_FINAL_CONTRACT_V1.md](/home/veltra/projekty/veltra-pa-07b3a/VELTRA_PA_STAGE_07B3B_FINAL_CONTRACT_V1.md:1).

## 3. Istniejący lifecycle supplier associations

### 3.1 Schemat legacy

Migracja 049 tworzy:

- `raw_material_supplier_associations`;
- composite uniqueness `(organization_id, raw_material_id, supplier_id)`;
- FK do organizacji, materiału, aktywnego dostawcy i aktora;
- trigger wymagający aktywnego dostawcy przy insercie;
- HMAC scope `raw-material.commercial` na insert/delete.

Źródło: [000049 migration](/home/veltra/projekty/veltra-pa-07b3b/backend/database/migrations/2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance.php:178).

**CONFIRMED:** nie ma kolumn `revision_id`, `pending_revision_id`, `approved_revision_id`, `approval_event_id`, `approval_operation_id` ani state.

### 3.2 Odczyt nazw

Ordinary RAW listing/detail łączy associations z `organization_suppliers` i wylicza:

- `display_name`, jeśli niepusta;
- inaczej `legal_name`;
- sortowanie po nazwie.

Źródła: [supplierNamesForMaterial](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:537), [catalog name join](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1015).

### 3.3 Synchronizacja

`syncSupplierAssociations()`:

- zwraca bez zmiany, jeśli `supplier_ids` nie zostało przekazane;
- wymaga `SupplierManage`;
- sprawdza, że wszystkie ID należą do tej organizacji i są ACTIVE;
- usuwa nieobecne association;
- dodaje nowe.

Źródło: [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:551).

**CONFIRMED:** synchronizacja nie zapisuje nazwy, ani rewizji, ani approval evidence.

## 4. Wszystkie ścieżki mutacji i approval

| Ścieżka | Kod | Obecny efekt na associations |
|---|---|---|
| Create | `create()`/`createMaterial()` | po insercie rewizji wywołuje sync: [RawMaterialService.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1466), [1587](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1587) |
| Create+approve | `createApproved()` | najpierw tworzy working revision i sync, potem submit i approve w tej samej DB transaction: [1471](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1471), [1596](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1596) |
| CSV catalog import | `createImported()` | tworzy draft revision i sync: [1607](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1607), [1673](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1673) |
| Revise/edit | `reviseWithPermission()` | tworzy nową working revision, następnie sync: [1881](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1881), [1955](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:1955) |
| Submit | `submit()` | przenosi working revision do pending; nie zmienia association: [2060](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:2060) |
| Return | `returnForChanges()` | zmienia working/pending pointer; nie zmienia lub nie usuwa association: [2088](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:2088) |
| Approve | `approve()` | approval event i approved pointer, ale supplier snapshot nie powstaje: [2131](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:2131) |
| Replace approved revision | revise/submit/approve | nowa rewizja może zmieniać legacy association przed jej zatwierdzeniem |
| Archive | `archive()`/`archiveBulk()` | nie zmienia association: [2249](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:2249) |
| Restore | `restore()` | tworzy verification revision z approved data, ale obecnie nie kopiuje supplier association do proposed set: [2462](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:2462), [2470](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialService.php:2470) |
| Delete draft | `deleteDraft()` | nie ma jawnego cleanup/new proposed set contract; draft dependencies mogą blokować deletion |
| Bulk archive | `archiveBulk()` | nie zmienia supplier snapshotów |
| Supplier update/archive/restore | `SupplierDirectoryService` | zmienia słownik/supplier lifecycle bez tworzenia rewizji RAW: [215](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Suppliers/SupplierDirectoryService.php:215), [299](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Suppliers/SupplierDirectoryService.php:299), [345](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/Suppliers/SupplierDirectoryService.php:345) |
| Import katalogowy | CSV workflow | tworzy imported draft przez `createImported`; aktualna sync musi być objęta nowym proposed-set flow: [RawMaterialCatalogCsvImportWorkflow.php](/home/veltra/projekty/veltra-pa-07b3b/backend/app/Domain/RawMaterials/RawMaterialCatalogCsvImportWorkflow.php:300) |
| System create/approve | `createApproved()` | tworzy i zatwierdza własną propozycję tylko po uprawnieniach Administrator; musi przejść przez ten sam snapshot invariant |

**CONFIRMED:** każda ścieżka może obejść supplier snapshot, ponieważ snapshot obecnie nie istnieje.

**CONFIRMED:** supplier archive/update nie zmienia RAW revision; istniejący snapshot jest powiązany supplier ID, więc deactivation może nastąpić po approval bez zmiany samej rewizji.

## 5. Minimalna architektura PA-07B.3C

### 5.1 Preferred model

Wprowadzić dwa logiczne stany:

1. **Proposed supplier set** — liczba 0 lub więcej, przypisana do `raw_material_revisions.id`;
2. **Approved supplier snapshot** — liczba 0 lub więcej, przypisana do zatwierdzonej rewizji i approval operation.

Brak recordu w proposed set oznacza `NOT_PROVIDED`; jawny pusty proposal oznacza `PROPOSED_EMPTY`; jeden lub więcej supplier IDs oznacza `PROPOSED_NONEMPTY`.

Approval może utworzyć snapshot tylko w jednym z dwóch stanów:

- `APPROVED_EMPTY`;
- `APPROVED_NONEMPTY`.

Nie tworzyć snapshotu dla:

- `NOT_PROVIDED`;
- `LEGACY_UNVERIFIED`.

### 5.2 Proponowane tabele

**PROPOSAL:**

1. `raw_material_revision_supplier_proposals`
   - `organization_id`;
   - `raw_material_id`;
   - `revision_id`;
   - `state` (`NOT_PROVIDED`, `PROPOSED_EMPTY`, `PROPOSED_NONEMPTY`);
   - `created_by`, `updated_at`;
   - composite FK `(revision_id, raw_material_id, organization_id)` to `raw_material_revisions`;
   - unique `(organization_id, revision_id)`.

2. `raw_material_revision_supplier_proposal_items`
   - `organization_id`;
   - `revision_id`;
   - `supplier_id`;
   - composite FKs do `raw_material_revisions` i `organization_suppliers`;
   - unique `(organization_id, revision_id, supplier_id)`;
   - trigger/constraint zapewniający nieobecność itemów dla `PROPOSED_EMPTY` i co najmniej jeden item dla `PROPOSED_NONEMPTY`.

3. `raw_material_approved_supplier_snapshots`
   - `organization_id`;
   - `raw_material_id`;
   - `revision_id`;
   - `approval_event_id`;
   - `approval_operation_id`;
   - `state` (`APPROVED_EMPTY`, `APPROVED_NONEMPTY`);
   - immutable timestamp;
   - composite FK do approved revision i approval event;
   - unique `(organization_id, revision_id)`.

4. `raw_material_approved_supplier_snapshot_items`
   - `organization_id`;
   - `revision_id`;
   - `supplier_id`;
   - immutable composite FK do snapshot i supplier;
   - unique `(organization_id, revision_id, supplier_id)`;
   - trigger/constraints odzwierciedlające stan snapshotu i organizację dostawcy.

`raw_material_supplier_associations` pozostaje tabelą legacy zwykłego RAW i nie jest backfillowana ani usuwana.

### 5.3 Niezbędne niezmienniki

- Proposed set musi należeć do dokładnej rewizji working/pending.
- Returned revision zachowuje tę samą propozycję do edycji.
- Submit nie tworzy snapshotu.
- Approval weryfikuje każdego proponowanego dostawcę:
  - same organization;
  - lifecycle `ACTIVE`;
  - not deleted/archived;
- Snapshot i approval event są wpisywane w tej samej transakcji.
- `approved_revision_id` zmienia się dopiero po udanym zapisie snapshotu.
- Po rollbacku approval event, snapshot i pointer rewizji pozostają niezmienione.
- Snapshot i jego pozycje są append-only i niemutowalne.
- Legacy associations nigdy nie są interpretowane jako snapshot.
- Zmiana nazwy dostawcy zmienia `display_name` w zwykłym RAW i widoku bieżącej nazwy 3C, ale nie zmienia członkostwa snapshotu.

### 5.4 Empty vs unknown

| Stan | Model | BOSS result |
|---|---|---|
| Nie podano informacji o dostawcy | header proposalu `NOT_PROVIDED`, brak snapshotu | `supplier_names: null` |
| Informacja o dostawcy istnieje, ale nie jest zweryfikowana | tylko legacy association | `supplier_names: null`, wewnętrznie `LEGACY_UNVERIFIED` |
| Proposed set oczekuje zatwierdzenia | header/pozycje proposalu | `supplier_names: null`, bo snapshot nie został zatwierdzony |
| Jawnie zatwierdzony pusty zestaw | header snapshotu `APPROVED_EMPTY` | `supplier_names: []` |
| Zatwierdzony niepusty zestaw | header/pozycje snapshotu `APPROVED_NONEMPTY` | `supplier_names: [...]` |

Żaden stan brakujący lub nieznany nie może zostać zamieniony na `[]`.

## 6. Semantyka nazwy dostawcy

### Alternatywa A — nazwa snapshotu

Zalety:

- najlepsza historyczna dokładność nazwy;
- stabilny tekst przy późniejszym rename;
- niezależność od zmian słownika dostawców.

Koszt:

- duplikacja nazwy słownika do niemutowalnego snapshotu;
- osobna reguła dla rename/restore i korekty;
- publiczny DTO nadal nie może ujawniać supplier ID;
- zwykłe RAW musi pozostać oddzielnym przepływem.

### Alternatywa B — zatwierdzona identity i bieżąca nazwa ze słownika

Zalety:

- prostszy schemat;
- brak duplikowania tekstu nazwy;
- rename dostawcy jest natychmiast widoczny;
- identity dowodzi, którego dostawcę zatwierdzono;
- zgodność z istniejącym workflow słownika dostawców.

Ograniczenia:

- nie zachowuje historycznej pisowni nazwy;
- stary dostawca może zostać przemianowany lub zarchiwizowany;
- nazwa publiczna zmienia się po zatwierdzeniu.

**RECOMMENDATION:** **wariant B**. Dla PA-07B.3C relacja jest historycznie przypisana do niemutowalnej identity dostawcy. Nazwa jest bieżącym opisem, a nie zatwierdzonym tekstem. To najlepiej dopasowuje się do regularnego słownika i minimalizuje duplikację. Właściciel musi zatwierdzić informację, że rename zmienia nazwę publiczną, natomiast memberstwo dostawcy pozostaje zatwierdzone.

## 7. Polityka legacy

Istniejące `raw_material_supplier_associations` pozostają danymi zwykłego RAW i są:

- `LEGACY_UNVERIFIED`;
- niekopiowane do rewizji;
- niezmieniane przez backfill;
- nieprzedstawione jako approved snapshot;
- nieużywane jako źródło 3C.

Nazwy dostawców i zarządzanie dostawcami w zwykłym RAW pozostają zgodne.

Jeżeli zostaną znalezione wiarygodne dane źródłowe, PA-07B.3C potrzebuje osobnej, zatwierdzonej przez właściciela operacji reconciliation. Musi ona:

- zidentyfikować dostawcę, organizację i rewizję na podstawie udokumentowanego dowodu;
- utworzyć nowy jawny snapshot;
- zapisać niezależny approval event;
- nie przepisywać starego approval hash ani fabrykować historycznego approval event.

Automatyczna migracja nie jest bezpieczna.

## 8. Proponowany kontrakt PostgreSQL

### 8.1 Schema

Cztery nowe tabele opisane w sekcji 5.2. Bez zmian istniejących danych legacy i approval hashy pozostają niezmienione.

### 8.2 Klucze i constraints

- UUID primary keys dla snapshotu i pozycji proposalu tylko, jeśli wymagają tego istniejące konwencje;
- złożone FK organizacja–rewizja i organizacja–dostawca;
- `ON DELETE RESTRICT`;
- `CHECK` spójności stanu;
- unikalny dostawca dla rewizji/zestawu;
- unikalny zestaw dla rewizji;
- rewizja snapshotu musi być obecnie lub historycznie zatwierdzona;
- approved snapshot musi wskazywać zgodny approval event;
- approval event `revision_id`, `organization_id`, `content_hash` musi zgadzać się z rewizją.

### 8.3 Snapshot immutability

Trigger PostgreSQL:

- odrzuca UPDATE/DELETE na headerze i pozycjach snapshotu;
- odrzuca niezgodność pozycji i dostawcy;
- odrzuca zastąpienie snapshotu dla istniejącej rewizji;
- odrzuca stan snapshotu bez zgodnej liczby pozycji.

### 8.4 Existing HMAC

Bieżąca granica HMAC obejmująca 65 tabel pozostaje bez zmiany. PA-07B.3C może używać istniejących scope’ów `raw-material.mutate` lub `raw-material.commercial` dla zwykłych zapisów proposed set. Approved snapshot jest ledgerem zatwierdzenia wysokiego ryzyka i powinien pozostać w istniejącej granicy transakcji approval, bez bezpośredniego runtime write access, dopóki osobny przegląd migracji nie zatwierdzi scope HMAC:

- proposed set: HMAC nie jest zakładany jako nowa warstwa poza istniejącymi zwykłymi scope’ami RAW mutacji;
- snapshot header/items: insert snapshotu musi odbyć się wewnątrz już podpisanego HMAC callbacku `rawMaterialMutate`; bezpośrednie runtime INSERT/UPDATE/DELETE powinny być odebrane lub niezależnie chronione;
- brak redesignu HMAC w ramach 3C.

**NOT VERIFIED:** live granty i stan triggerów.

### 8.5 Indeksy

- proposal lookup `(organization_id, revision_id)`;
- snapshot lookup `(organization_id, revision_id)`;
- snapshot item lookup `(organization_id, revision_id, supplier_id)`;
- supplier impact lookup `(organization_id, supplier_id, revision_id)` do sprawdzania rename/archive/references.

### 8.6 Migration

Forward-only migracja addytywna:

- utworzyć proposal i snapshot tables;
- dodać constraints/triggers;
- zachować legacy table;
- bez migracji danych;
- bez historycznych approval events;
- bez aktualizacji hashy;
- bez aktywacji capability.

Rollback jest forward-only; jeśli migracja nie powiedzie się, transakcja nie zostawia częściowego schematu.

## 9. Kontrakt API BOSS

### 9.1 Istniejące trasy

3C powinno rozszerzać wyłącznie:

- `GET /api/v1/boss/raw-materials`;
- `GET /api/v1/boss/raw-materials/{material}`;

Brak dedykowanych supplier endpoints.

### 9.2 Pole dostawcy

**PROPOSAL:** dodać `supplier_names`:

```json
{
  "supplier_names": ["Supplier A", "Supplier B"]
}
```

Stan:

- approved non-empty: posortowane, unikalne nazwy;
- approved explicit-empty: `[]`;
- brak snapshotu/legacy/unproposed: `null`;
- brak supplier IDs, contacts, tax identifiers i financial fields.

Publiczny DTO powinien zawierać tylko nazwy. ID pozostają wewnętrzne.

`supplier_names` musi pozostać wyłączone z 3B, dopóki nie zostanie osobno zatwierdzone.

### 9.3 Filtrowanie i sortowanie

Bez początkowego filtrowania ani sortowania po `supplier_id`, dopóki właściciel tego nie zaakceptuje. Zachować istniejące 3B search/sort:

- wyszukiwanie tylko po name/code;
- filtry category/unit pozostają;
- brak supplier filter;
- brak supplier sort;
- stabilna paginacja pozostaje.

### 9.4 Autoryzacja

Wymagać tych samych warunków co PA-07B.3B:

- aktywna identity i organization;
- aktywny exact membership;
- organizacyjna rola BOSS;
- capability ready/enabled;
- restrykcje kategorii BOSS RAW_SUPPLIERS;
- approved snapshot source.

Zwykłe RAW granty `SupplierManage`/`SupplierView` nie mogą przyznawać BOSS catalog `supplier_names`.

### 9.5 Zachowanie odczytu

BOSS musi odczytywać wyłącznie snapshot dla `materials.approved_revision_id`.

Nie pokazywać:

- working/pending proposal;
- legacy association;
- niezatwierdzony stan pusty;
- zarchiwizowany lub usunięty dostawca? W kontrakcie bieżącej nazwy, jeśli dostawca istnieje i członkostwo jest zatwierdzone, zarchiwizowany dostawca może nadal zwracać nazwę ze słownika. Właściciel musi rozstrzygnąć, czy archiwizacja ukrywa nazwę.

Zachowanie błędów:

- unknown/dependency failure: bezpieczne 503 tylko, gdy niedostępny jest kontrakt capability/dependency;
- brak snapshotu: null, nie pusty;
- niezweryfikowany legacy: null;
- niespójność danych: odrzucić rekord lub zwrócić bezpieczne 503; nie zgadywać.

## 10. Implikacje bezpieczeństwa

### 10.1 Zachować

- izolację organizacyjną;
- rewizję approval i content hash;
- append-only history;
- bieżącą granicę mutacji HMAC;
- kontrole roli/capability/restriction BOSS;
- brak fallbacku zwykłego RAW grant.

### 10.2 Nowe ryzyka

- niezgodność approval snapshot;
- proposed set zapisany poza transakcją rewizji;
- dostawca dezaktywowany między proposal a approval;
- supplier ID z obcej organizacji;
- rename dostawcy po approval;
- przypadkowy backfill lub ujawnienie legacy association;
- aktywny snapshot przetrwający archive/restore niepoprawnie;
- `[]` błędnie uznane za unverified lub unknown.

### 10.3 Ryzyka rezydualne

- bezpośrednie uprzywilejowane mutacje DB pozostają poza dowodem aplikacyjnym;
- archiwizacja i rename dostawcy są niezależne od snapshotu i wymagają osobnej polityki;
- publiczna semantyka bieżącej nazwy nie jest dowodem historycznej nazwy;
- niemutowalność snapshotu chroni relację, ale nie dowodzi prawdziwości zewnętrznej informacji o dostawcy.

## 11. Ukierunkowana macierz testów

| Risk | Test |
|---|---|
| HIGH | tworzenie proposed set working revision zapisuje dokładnie tę rewizję |
| HIGH | revise tworzy nowy proposed set i nie zmienia approved snapshot |
| HIGH | submit przenosi propozycję do pending, ale nie zatwierdza jej |
| HIGH | approval tworzy snapshot atomowo wraz z approval event |
| HIGH | rollback approval przywraca pointer materiału i usuwa snapshot/event |
| HIGH | dezaktywacja dostawcy przed approval odrzuca mutację |
| HIGH | dostawca z obcej organizacji zostaje odrzucony |
| HIGH | dostawca zarchiwizowany podczas pending approval zostaje odrzucony |
| HIGH | współbieżne revise/submit/approve zatwierdza tylko jeden wygrywający zestaw |
| HIGH | trigger PostgreSQL odrzuca UPDATE/DELETE snapshotu |
| MEDIUM | jawnie zatwierdzony pusty zestaw zwraca `[]` |
| MEDIUM | brak/stan unknown proponowanego zestawu zwraca `null`, nigdy `[]` |
| MEDIUM | legacy association nie jest nigdzie eksponowana w 3C |
| MEDIUM | rename dostawcy zmienia bieżącą nazwę, nie membership snapshotu |
| MEDIUM | restore/archive dostawcy nie zmienia membership snapshotu |
| MEDIUM | restrykcje BOSS i capability odmawiają `supplier_names` |
| MEDIUM | zwykły RAW grant nie przyznaje BOSS catalog `supplier_names` |
| MEDIUM | brak wycieku kontaktów, podatków, cen i supplier ID |
| MEDIUM | pola list/detail 3B pozostają dokładne, gdy pole 3C nie jest zaakceptowane |
| MEDIUM | archive/restore nie kopiuje legacy association do snapshotu |
| LOW | sortowanie/deduplikacja nazw i stabilne dane listy |

Nie jest potrzebna nowa szeroka warstwa HMAC ani pełna regresja przeglądarkowa/modułowa, dopóki implementacja nie zmienia tych granic.

## 12. Otwarte decyzje właściciela

1. **Zatwierdzić zakres PA-07B.3C:** dopiero po akceptacji gotowości 3B.
2. **Kształt publicznego pola dostawcy:** `supplier_names: string[]|null` albo oddzielny obiekt.
3. **Semantyka nazwy dostawcy:** rekomendowana nazwa bieżąca ze słownika (B); właściciel musi zaakceptować brak historycznej gwarancji nazwy.
4. **Nazwa zarchiwizowanego dostawcy:** pokazać bieżącą nazwę, ukryć ją, czy zwrócić relację bez nazwy?
5. **Jawnie pusty zestaw:** wymagać jawnego potwierdzenia UI/domeny czy wnioskować z pustego requestu?
6. **Brak danych dostawcy:** publiczny stan `null`, bez dodatkowego status code.
7. **Granularność tabel proposed set:** header/items albo prostsza tabela revisjonowanej wersji.
8. **Ochrona HMAC:** bezpośredni zapis snapshotu powinien być jawnie chroniony, ale nie generalizowany.
9. **Reconciliation legacy:** bez backfillu; tylko osobny, zatwierdzony przez właściciela proces.
10. **Create/approval CSV i systemowe:** które workflows mogą auto-submit/approve i muszą mieć tę samą walidację snapshotu.

## 13. Ryzyka implementacyjne

- Istniejące `syncSupplierAssociations()` działa na poziomie materiału i może mutować legacy associations, nawet gdy proposed flow jest niekompletny.
- `createApproved()` wykonuje nested submit i approve w zewnętrznej transakcji; supplier snapshot musi zostać zapisany w głębokim approval point.
- Restore odtwarza pola rewizji, ale pomija supplier proposals.
- Supplier approval może współbiegać z archive/rename.
- Odczyty zwykłego RAW nadal używają legacy/physical associations i mogą rozbiegać się ze snapshotem BOSS.
- Legacy data może pojawić się w zwykłym eksporcie, ale nie może pojawić się w BOSS.
- Scope HMAC i runtime DML nie dowodzą autoryzacji biznesowej.
- Publiczne `[]` jest niebezpiecznym sentinelm, jeśli stan unknown nie jest rozdzielony wewnętrznie.

## 14. Rekomendowana sekwencja implementacji

1. Zatwierdzić tę architekturę i otwarte decyzje.
2. Dodać kontrakty proposed i snapshot tables wyłącznie przez forward-only migration.
3. Zastąpić zachowanie `syncSupplierAssociations()` na poziomie materiału zapisami proposed set związanymi z rewizją.
4. Zaktualizować create, revise, restore i CSV create.
5. Dodać proposal validation przy submit.
6. Dodać ponowną weryfikację dostawcy i atomowe tworzenie snapshotu w `approve()`.
7. Zachować legacy table i zgodność zwykłego RAW.
8. Rozszerzyć projekcję BOSS o `supplier_names` dopiero po osobnej akceptacji kontraktu.
9. Dodać ukierunkowane testy rollbacku approval, współbieżności, empty/unknown i legacy.
10. Uruchomić niezależny przegląd migracji i security review przed aktywacją.

## 15. Decyzja STOP/GO

**STOP:** bez implementacji, migracji, zmiany HMAC i aktywacji.

**GO wymaga:**

- jawnej akceptacji właściciela zakresu PA-07B.3C;
- akceptacji semantyki nazwy dostawcy;
- akceptacji publicznego kontraktu `null/[]/non-empty`;
- akceptacji migracji snapshotu i polityki legacy;
- zatwierdzony zachowania archive/rename dostawcy;
- izolowanego środowiska testowego i niezależnego przeglądu migracji.

## 16. Wykonana weryfikacja

**CONFIRMED FROM CODE:** analiza lifecycle, schema i mutacji powyżej.

**PROPOSALS:** nowy schemat, pole API i semantyka snapshotu wymagają akceptacji właściciela.

**NOT VERIFIED IN DATABASE:** istniejące wiersze, stan migracji, live granty, rzeczywisty stan scope HMAC i dane historyczne.

Raport został utworzony w trybie read-only i nie dokonano żadnych zmian aplikacji ani środowiska.
