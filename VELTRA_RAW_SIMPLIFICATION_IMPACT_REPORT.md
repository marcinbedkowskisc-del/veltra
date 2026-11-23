# VELTRA 2.0 — RAW simplification impact report

**Date:** 2026-11-23
**Scope:** Impact analysis only; no application, migration, permission, readiness, or data changes made.

## Executive summary

The supplier-product compatibility workflow is implemented in the backend but has no matching assessment UI in `frontend/src`. It stores append-only `COMPATIBLE`/`INCOMPATIBLE` decisions, calculates a current status with a `PENDING` fallback, requires a special approval permission, and ties decisions to a specific supplier-product revision, approved RAW revision, allergen attestation, and a document whose latest antivirus scan is `CLEAN`.

The status is exposed in supplier-product API responses but is not used as a filter or eligibility predicate by the observed purchasing operations. The independent RAW-to-supplier assignment path already has a direct many-to-many table, `raw_material_supplier_associations`; however, current RAW editing also maintains revision-specific proposals and approval snapshots. BOSS reads the snapshots associated with an approved RAW revision, not the direct association table. This distinction needs to be preserved in any simplification.

RAW material revision approval and its audit/history are separate mechanisms and are **not** supplier-product compatibility approval. Keep them. Optional RAW attachments, their immutable history, authenticated access, checksum validation, file storage protections, and antivirus checks are also separate from the rejected workflow; retain the technical security controls.

## Classification

| Element | Classification | Impact / decision |
|---|---|---|
| Compatibility assessment service, request, API endpoints, and dedicated approval permission | **REMOVE** | No supplier-product qualification is required. Retire assessment reads/writes and the special permission after a forward-only migration and deployed-call-site review. |
| `PENDING`, `COMPATIBLE`, `INCOMPATIBLE` assessment status and its API fields | **REMOVE** | Stop deriving and returning a business status. `PENDING` here is distinct from document antivirus `PENDING`. |
| Compatibility assessment table, status view, and assessment-specific audit/idempotency records | **REVIEW** | Mechanism is unnecessary, but preserve existing rows/history unless the owner explicitly approves an archival/retention strategy. Do not drop populated data or edit old migrations. |
| `raw_material_supplier_associations` | **SIMPLIFY** | Existing direct many-to-many relation matches supplier assignment. Reuse it for direct assign/unassign; retain organization/supplier foreign keys, active-supplier integrity, authorization, and audit. |
| Revision-bound supplier proposals and BOSS supplier snapshots | **REVIEW** | These represent RAW-revision assignment proposals/snapshots, not supplier-product compatibility. Decide explicitly whether BOSS visibility remains bound to approved RAW revisions; do not conflate this with removing supplier-product assessments. |
| `supplier_products`, product revisions, offers, and price events | **KEEP / REVIEW** | These hold separate supplier-specific SKU, unit, offer, and pricing data used by commercial operations. Keep the data/integrity unless a separate purchasing-model decision replaces it. Remove only compatibility-status coupling. |
| Ordinary RAW revision approval, edit history, audit, and historical integrity | **KEEP** | These are RAW material lifecycle controls, not supplier-product qualification. |
| Optional RAW documents and document revision links | **KEEP / SIMPLIFY** | Keep optional attachments and history. Remove compatibility-only document purpose/evidence consumers while retaining existing records and secure document handling. |
| Antivirus scan, file checksums, private storage, authorized preview/download, upload expiry cleanup | **KEEP** | These are technical security/integrity controls, not business prerequisites. A `CLEAN` result may gate file access/claiming; it must not gate RAW/supplier business operations. |

## 1. Compatibility assessment implementation and status logic

### Services, API, authorization, schema

- [`backend/app/Domain/RawMaterials/RawMaterialCompatibilityAssessmentService.php`](backend/app/Domain/RawMaterials/RawMaterialCompatibilityAssessmentService.php) reads history/current status and creates decisions. Assessments are append-only, require a reason and idempotency key, and write an assessment-specific entry to the shared commercial audit and idempotency tables.
- [`backend/app/Http/Requests/RawMaterialCompatibilityAssessmentRequest.php`](backend/app/Http/Requests/RawMaterialCompatibilityAssessmentRequest.php) accepts only `COMPATIBLE` or `INCOMPATIBLE`.
- [`backend/app/Http/Controllers/Api/V1/RawMaterialController.php`](backend/app/Http/Controllers/Api/V1/RawMaterialController.php) exposes the history and assessment handlers.
- [`backend/routes/api.php`](backend/routes/api.php) exposes:
  - `GET /v1/raw-materials/supplier-products/{product}/compatibility-assessments`
  - `POST /v1/raw-materials/supplier-products/{product}/compatibility-assessments`
- [`backend/app/Domain/RawMaterials/RawMaterialPermission.php`](backend/app/Domain/RawMaterials/RawMaterialPermission.php) defines `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`.
- [`backend/database/migrations/2026_11_06_000032_create_supplier_compatibility_assessments.php`](backend/database/migrations/2026_11_06_000032_create_supplier_compatibility_assessments.php) adds the special permission to the grant constraint, creates the assessment table and current-status view, adds a validating/immutability trigger, and grants the runtime role access.

### Status meaning and invalidation

- `COMPATIBLE` and `INCOMPATIBLE` are stored as assessment outcomes. The database view `raw_material_supplier_compatibility_status` emits the latest applicable assessment outcome.
- The view emits `PENDING` when no assessment is applicable to the latest supplier-product revision and currently approved RAW revision. A newer product or RAW revision, a RAW revision in working/pending state, or an ineligible attestation/evidence means the prior assessment is not current.
- `RawMaterialCompatibilityAssessmentService::history()` also defaults missing current status to `PENDING`; its `assess()` path requires the dedicated permission and rejects stale/incomplete revision context.
- [`backend/app/Domain/RawMaterials/RawMaterialCommercialService.php`](backend/app/Domain/RawMaterials/RawMaterialCommercialService.php) joins that view in product, product-revision, and product-detail responses, and returns `compatibility_status`. These are output fields; the service does not use compatibility status in its query filters or offer/price eligibility predicates.
- No supplier-product compatibility assessment screen or compatibility-status consumer was found under `frontend/src`. RAW supplier selection and RAW document UI are present, but not a COMPATIBLE/INCOMPATIBLE decision UI.

### Assessment tests

- [`backend/tests/Feature/RawMaterialCompatibilityAssessmentTest.php`](backend/tests/Feature/RawMaterialCompatibilityAssessmentTest.php) covers decisions, status/history behavior, authorization, idempotency, exact revision binding, invalidation, and audit atomicity.
- [`backend/tests/Feature/RawMaterialDocumentStorageTest.php`](backend/tests/Feature/RawMaterialDocumentStorageTest.php) includes cases asserting that only a latest clean exact-checksum document can be used as *compatibility evidence*. The tests around private storage, scanning, and secure access remain relevant even when that evidence-specific behavior is removed.
- [`backend/tests/Feature/RawMaterialsTest.php`](backend/tests/Feature/RawMaterialsTest.php) covers supplier products, revisions, offers, and their independent integrity/eligibility behavior; retain the non-compatibility cases.

## 2. Supplier relationships and many-to-many capability

### Direct association

[`backend/database/migrations/2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance.php`](backend/database/migrations/2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance.php) creates `raw_material_supplier_associations` with:

- one row per `(organization_id, raw_material_id, supplier_id)` (unique constraint);
- organization-scoped foreign keys to both `raw_materials` and `organization_suppliers`;
- a validation trigger requiring an active supplier on insert;
- signed-mutation authorization scopes for insert/delete.

This is a direct many-to-many relation: one RAW material may have multiple suppliers, and one supplier may be associated with multiple RAW materials. It is not a one-supplier-per-material structure.

[`backend/app/Domain/RawMaterials/RawMaterialService.php`](backend/app/Domain/RawMaterials/RawMaterialService.php) has `syncSupplierAssociations()` and reads this relation for material supplier names. RAW create/update flows call it. [`backend/app/Http/Requests/RawMaterialDataRequest.php`](backend/app/Http/Requests/RawMaterialDataRequest.php), [`frontend/src/rawMaterials/api.ts`](frontend/src/rawMaterials/api.ts), [`frontend/src/rawMaterials/create.ts`](frontend/src/rawMaterials/create.ts), [`frontend/src/rawMaterials/RawMaterialCreateForm.tsx`](frontend/src/rawMaterials/RawMaterialCreateForm.tsx), and [`frontend/src/rawMaterials/RawMaterialEditForm.tsx`](frontend/src/rawMaterials/RawMaterialEditForm.tsx) carry the existing `supplier_ids` assignment input. [`backend/app/Domain/Suppliers/SupplierDirectoryService.php`](backend/app/Domain/Suppliers/SupplierDirectoryService.php) includes these associations when counting RAW materials for a supplier.

### Separate revision-bound assignment representation

[`backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`](backend/database/migrations/2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php) creates:

- `raw_material_revision_supplier_proposals`
- `raw_material_revision_supplier_proposal_items`
- `raw_material_approved_supplier_snapshots`
- `raw_material_approved_supplier_snapshot_items`

`RawMaterialService` synchronizes a proposal against a working RAW revision and creates an immutable supplier snapshot when that RAW revision is approved. A proposal can distinguish `NOT_PROVIDED`, explicitly empty, and non-empty. The direct association table is not equivalent to this historical, revision-bound record.

### BOSS visibility dependency

- [`backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php`](backend/app/Domain/RawMaterials/BossRawMaterialCatalogService.php) selects approved, active RAW revisions and projects supplier names from `raw_material_approved_supplier_snapshots` and its items.
- [`backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php`](backend/app/Domain/RawMaterials/BossRawMaterialCatalogItem.php) maps approved-empty and approved-nonempty snapshot states to BOSS supplier names.
- [`backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php`](backend/tests/Feature/BossRawMaterialCatalogProvenanceTest.php) explicitly verifies that a legacy `raw_material_supplier_associations` row is not projected to BOSS and that BOSS uses revision-bound approved supplier snapshots.
- [`frontend/src/bossRawMaterials/BossRawMaterialsCatalogPage.tsx`](frontend/src/bossRawMaterials/BossRawMaterialsCatalogPage.tsx) and its tests render the BOSS supplier names projection.

**Decision required:** The approved business decision rejects compatibility assessment, not ordinary RAW revision approval. Before changing BOSS supplier visibility, confirm whether assignments should become visible immediately when added/removed or remain visible only through the approved RAW-revision snapshot. Preserve the current snapshot/history behavior until that decision is made. Do not remove RAW approval, readiness, or history to simplify supplier qualification.

## 3. Supplier-product data and purchasing dependencies

[`backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php`](backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php) defines:

- `organization_suppliers`
- `supplier_products` (supplier + RAW-material commercial product)
- `supplier_product_revisions` (supplier SKU/GTIN/name, purchase/content units and quantity snapshots, linked to a RAW revision)
- `supplier_offers`, with offer/price event tables in the same commercial domain.

These are not the compatibility status table. Keep the commercial records and their revision, pricing, supplier-active, and organization-integrity rules unless separately redesigned.

Observed purchasing paths in `RawMaterialCommercialService` check such conditions as active supplier/product/material, existence of a latest product revision, and that the supplier-product revision refers to the current approved RAW revision. Offer creation and price-setting do **not** test `compatibility_status` or require `COMPATIBLE`. Supplier filtering in `RawMaterialService` similarly checks active/current supplier products, not compatibility. Remove the status joins/output, but do not inadvertently remove those distinct purchasing and RAW-revision checks.

## 4. Documents: business coupling versus security

### Compatibility-only document coupling

- [`backend/database/migrations/2026_11_06_000029_create_raw_material_versioned_private_documents.php`](backend/database/migrations/2026_11_06_000029_create_raw_material_versioned_private_documents.php) creates `raw_material_document_version_links` with `link_purpose` values including `SUPPLIER_COMPATIBILITY_EVIDENCE`; that purpose requires a `supplier_product_revision_id`.
- [`backend/database/migrations/2026_11_06_000031_create_raw_material_document_scan_results.php`](backend/database/migrations/2026_11_06_000031_create_raw_material_document_scan_results.php) creates `raw_material_approved_compatibility_document_evidence`, which exposes only the latest `CLEAN` scan for an exact document checksum and compatibility-purpose link.
- [`backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php`](backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php) accepts that purpose and checks the product-revision link.
- [`backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentScanService.php`](backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentScanService.php) has a compatibility-evidence-specific clean scan check.
- Migration 032 requires that exact evidence and a published allergen attestation to make a supplier-product assessment. This makes document presence, association, and current scan status prerequisites for the *rejected assessment workflow* only.

Remove this business-evidence dependency for future operations. Preserve historical document versions, links, hashes, scan results, and any assessment references; the dedicated purpose value and evidence view need a forward-only retirement plan rather than destructive edits to migrations 029/031/032.

### Document behavior to retain

The ordinary RAW attachment path uses `raw_material_documents`, `raw_material_document_versions`, `raw_material_document_version_links`, `raw_material_document_lifecycle_events`, `raw_material_document_scan_results`, `raw_material_document_uploads`, and `raw_material_revision_document_sets`.

- [`backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentSetService.php`](backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentSetService.php) saves optional revision attachment sets, lists documents, and permits preview/download only after a latest matching-checksum `CLEAN` scan.
- [`backend/app/Domain/RawMaterials/Documents/RawMaterialUploadService.php`](backend/app/Domain/RawMaterials/Documents/RawMaterialUploadService.php), [`backend/app/Domain/RawMaterials/Documents/PrivateDocumentStorage.php`](backend/app/Domain/RawMaterials/Documents/PrivateDocumentStorage.php), [`backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentScanService.php`](backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentScanService.php), and [`backend/app/Jobs/ScanRawMaterialDocumentVersion.php`](backend/app/Jobs/ScanRawMaterialDocumentVersion.php) handle staging, storage, scanning, and scan-result recording.
- [`backend/app/Http/Controllers/Api/V1/RawMaterialDocumentController.php`](backend/app/Http/Controllers/Api/V1/RawMaterialDocumentController.php) serves authorized listing, preview, and download routes. The routes remain behind the existing RAW session, identity, operating-context, throttle, and readiness middleware.
- [`frontend/src/rawMaterials/RawMaterialDocumentsSection.tsx`](frontend/src/rawMaterials/RawMaterialDocumentsSection.tsx), [`frontend/src/rawMaterials/api.ts`](frontend/src/rawMaterials/api.ts), and the RAW create/edit forms provide optional upload, status display, preview, and download.
- Upload `expires_at` in migration 044 applies to unclaimed temporary uploads and cleanup; it is not certificate/document expiry or supplier eligibility. The document schema and reviewed implementation expose no certificate-expiration business rule.

The document UI disables the material submit button while an upload is in progress, and the upload-claim flow requires a safe scanned file. These are transient/technical safeguards, not requirements that a RAW material have an attachment. Keep them scoped to upload completion and file safety; absence of attachments and document content/status must not change RAW creation, editing, approval, supplier assignment, purchasing, or business status.

Relevant tests include [`backend/tests/Feature/RawMaterialDocumentStorageTest.php`](backend/tests/Feature/RawMaterialDocumentStorageTest.php), [`backend/tests/Concerns/RawDocumentsStage3eB3Tests.php`](backend/tests/Concerns/RawDocumentsStage3eB3Tests.php), [`frontend/src/rawMaterials/RawMaterialDocumentsSection.test.tsx`](frontend/src/rawMaterials/RawMaterialDocumentsSection.test.tsx), and [`frontend/src/rawMaterials/RawMaterialDocumentsApi.test.ts`](frontend/src/rawMaterials/RawMaterialDocumentsApi.test.ts).

## 5. Migration, history, and authorization dependencies

### Compatibility and business audit records

`raw_material_supplier_compatibility_assessments` is append-only and references exact supplier-product/RAW revisions, attestation, document version/hash, and actor. Migration 032 adds its runtime allowlist and mutation scope. Assessment writes also create rows in shared `raw_materials_commercial_audit_events` (`subject_type = SUPPLIER_COMPATIBILITY_ASSESSMENT`, `event_code = SUPPLIER_COMPATIBILITY_ASSESSED`) and `raw_materials_commercial_idempotency` (`operation_code = supplier-compatibility.assess`).

Retire the compatibility-specific endpoint and write path, but do not delete the shared audit/idempotency tables or ordinary RAW events. Existing compatibility rows and their associated audit records are historical business records. Count and review deployed data before considering any archival; no data-presence assumption was made in this analysis.

Migration [`backend/database/migrations/2026_11_09_000045_add_raw_draft_tombstones.php`](backend/database/migrations/2026_11_09_000045_add_raw_draft_tombstones.php) also checks compatibility assessments in the draft-dependency predicate and adds the special permission to its permission constraint. If compatibility tables remain as history, the reference can remain protective. If schema retirement is later approved, update these dependencies in a new migration without weakening tombstone/data-retention safeguards.

### RAW revision history is not supplier-product revision history

`supplier_product_revisions` is commercial product/SKU history linked to a RAW revision. It is separate from `raw_material_revisions`, RAW approval events, RAW edit history, and RAW audit events. The compatibility workflow couples the two revision streams, but removing that workflow does not justify deleting either stream or relaxing their integrity constraints. Keep ordinary RAW approval/review, current-vs-working revision behavior, historical audit, and tombstone protection.

### Authorization and readiness

The assessment routes inherit the existing RAW middleware/readiness gate and the assessment service performs an explicit permission check. Direct association mutations use the existing supplier-management authorization and signed-mutation boundary. Document access and scans use separate authorization and scanner scopes.

This report makes no permission, role/grant, security-control, or RAW/BOSS readiness change. A future forward migration must account for the permission constraint and any previously granted compatibility permission; do not broaden access or remove unrelated supplier/document permissions.

## 6. Minimal safe removal/simplification plan

1. **Leave this report as the only current change.** Do not edit application code, readiness, permissions, migrations, data, or deployment state as part of this impact task.
2. In a separately approved implementation, remove assessment routes/handlers/request/service and compatibility status joins/response fields. Keep normal supplier-management permission checks for direct association changes.
3. Use the existing direct `raw_material_supplier_associations` N:M table for add/remove association semantics; do not create an assessment, qualification, or replacement approval workflow.
4. Resolve the BOSS decision first. Preserve revision-bound supplier proposal/snapshot records and ordinary RAW approval until the desired visibility timing is explicit. Do not use the legacy direct-association table as a BOSS data source without deciding how it interacts with approved revision provenance.
5. Keep `supplier_products`, product revisions, offers, prices, and their non-compatibility eligibility/integrity checks pending a separate commercial-model decision.
6. Preserve document storage, scan records, private access, and optional RAW attachment behavior. Retire only compatibility-specific evidence validation and new compatibility-purpose usage; retain old link/evidence/assessment data.
7. Use new forward-only migrations for any schema/constraint retirement; do not alter or delete historical migrations. Preserve audit/history, signed mutation protections, authorization boundaries, tenant foreign keys, and tombstone guards.
8. Before rollout, inventory compatibility rows, evidence/link references, audit/idempotency rows, existing direct associations, proposals, snapshots, and any supplier grants. Add focused regression coverage proving that compatibility state and optional document presence/scan status do not affect RAW/supplier/purchasing operations, while clean-file access controls and BOSS provenance remain intact.

## 7. Data migration risks

- Dropping the assessment table/view or document-evidence view can be blocked by foreign keys and can destroy the only structured record of historical decisions. The corresponding migrations are forward-only (`down()` throws); rollback cannot be assumed.
- Assessment history references particular supplier-product and RAW revisions, allergen catalog version, document version/checksum, and actor. Preserve those references or export/retain equivalent immutable history before any table retirement.
- Audit and idempotency rows are in shared tables. Deleting by table or broad operation cleanup risks deleting ordinary commercial/RAW audit history; scope any future retention change only to explicitly reviewed compatibility records.
- Direct associations, per-revision proposals, and approved snapshots can differ. `supplierIdsForEditor()` prefers proposal/snapshot data and falls back to direct associations for older revisions; BOSS intentionally projects only approved snapshots. Naively copying one table over another can overwrite explicit empty sets, change legacy behavior, or expose unapproved draft supplier assignments.
- Supplier names in BOSS are revision-provenance snapshots but are resolved against current supplier names at read time. Changing the source or snapshot rules can change BOSS visibility and historical presentation.
- Compatibility status is currently included in supplier-product response shapes. Removing it may affect external/API consumers even though no frontend consumer was found in this repository; confirm integrations before removing the fields.
- Document expiry in staging is cleanup-only, while scan state is security-sensitive. Conflating either with business eligibility or removing scan guards could expose unsafe files or break upload integrity.
- All future schema work must preserve tenant-scoped foreign keys, immutable history, active-supplier constraints, signed mutation authorization, RAW audit/tombstone integrity, and existing readiness behavior.

## Files and tables at a glance

**Compatibility implementation:** `RawMaterialCompatibilityAssessmentService.php`, `RawMaterialCompatibilityAssessmentRequest.php`, `RawMaterialController.php`, `routes/api.php`, `RawMaterialCommercialService.php`, `RawMaterialPermission.php`, migration `2026_11_06_000032_create_supplier_compatibility_assessments.php`, and tests `RawMaterialCompatibilityAssessmentTest.php` / `RawMaterialDocumentStorageTest.php`.

**Supplier relationship and BOSS provenance:** `RawMaterialService.php`, `RawMaterialDataRequest.php`, `SupplierDirectoryService.php`, frontend `api.ts`, `create.ts`, `RawMaterialCreateForm.tsx`, and `RawMaterialEditForm.tsx`, `BossRawMaterialCatalogService.php`, `BossRawMaterialCatalogItem.php`, `BossRawMaterialCatalogController.php`, migration `2026_11_12_000049_add_raw_pricing_basis_and_quantity_provenance.php`, migration `2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php`, `BossRawMaterialCatalogProvenanceTest.php`, and `RawMaterialRevisionDataModelTest.php`.

**Document-specific coupling/security:** migrations `2026_11_06_000029_create_raw_material_versioned_private_documents.php`, `2026_11_06_000031_create_raw_material_document_scan_results.php`, `2026_11_09_000044_create_raw_document_uploads_and_revision_sets.php`; `RawMaterialDocumentService.php`, `RawMaterialDocumentSetService.php`, `RawMaterialDocumentScanService.php`, `RawMaterialUploadService.php`, `PrivateDocumentStorage.php`, `RawMaterialDocumentController.php`, `ScanRawMaterialDocumentVersion.php`; frontend `RawMaterialDocumentsSection.tsx`, `RawMaterialDocumentsSection.test.tsx`, `RawMaterialDocumentsApi.test.ts`, `RawMaterialCreateForm.tsx`, and `RawMaterialEditForm.tsx`.

**Database tables/views:** `raw_material_supplier_compatibility_assessments`, `raw_material_supplier_compatibility_status` (view), `raw_material_approved_compatibility_document_evidence` (view), `raw_material_supplier_associations`, `raw_material_revision_supplier_proposals`, `raw_material_revision_supplier_proposal_items`, `raw_material_approved_supplier_snapshots`, `raw_material_approved_supplier_snapshot_items`, `organization_suppliers`, `supplier_products`, `supplier_product_revisions`, `supplier_offers`, `supplier_offer_events`, `raw_materials`, `raw_material_revisions`, `raw_material_revision_approval_events`, `raw_material_revision_allergen_attestations`, `raw_material_allergen_catalog_versions`, `raw_material_revision_allergen_declarations`, `raw_materials_commercial_audit_events`, `raw_materials_commercial_idempotency`, `organization_permission_grants`, `raw_material_documents`, `raw_material_document_versions`, `raw_material_document_version_links`, `raw_material_document_lifecycle_events`, `raw_material_document_scan_results`, `raw_material_document_uploads`, and `raw_material_revision_document_sets`. Migration 032 also registers the assessment table in `veltra_private.runtime_table_allowlist` and `veltra_private.authorization_mutation_scopes`.
