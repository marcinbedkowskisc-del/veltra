# VELTRA 2.0 — Stage 1D RBAC Amendment v1.1

**Date:** 2026-11-19\
**Status:** Owner-approved policy amendment; implementation is not yet verified.\
**Supersedes:** Stage 1D v1 only where this amendment explicitly changes BOSS visibility.\
**History:** This amendment supplements, and does not replace or erase, the approved Stage 1D v1 decision.

## 1. Purpose and precedence

The owner-approved PA-07 decision changes the BOSS business-data visibility policy. All Stage 1D v1 rules for the other five roles, account security, role assignment, delegations, location memberships, financial permission separation, and audit remain authoritative.

If an existing data surface cannot apply this amendment's restrictions consistently at authorization and data-projection/query layers, that surface remains unavailable to BOSS until it can do so. UI hiding is not enforcement.

## 2. BOSS default access

BOSS is a global, read-only business oversight role. Subject to individual restrictions, BOSS may read available business data across the installation, including central kitchens and restaurants, and may filter, sort, search, compare, analyze, report, export, download, or print authorized information.

This scope applies only to implemented data surfaces whose modules are available under the approved Stage 1E contract. BOSS visibility does not make a `NOT_READY` module ready, enable a disabled location module, or authorize an unavailable operation.

## 3. Read-only boundary

BOSS does not obtain permission to create, edit, archive, delete, approve, reject, or otherwise persistently mutate business data. BOSS also does not obtain permission to administer users, roles, permissions, restrictions, suppliers, installation settings, module readiness, or privileged security APIs.

Read access never bypasses identity, session, location, module-readiness, RBAC, or data-query security checks. Passwords, tokens, secrets, and privileged security configuration remain outside BOSS access.

## 4. Individual restrictions

Only an authorized `ADMINISTRATOR` may add, change, or remove restrictions for a BOSS account. Restrictions are account-specific and can only reduce the BOSS-derived read scope. A BOSS user cannot change their own restrictions.

Restrictions may deny:

- Information categories, including purchase prices, foodcost, recipes/products, production, inventory/warehouses, and reports/analytics.
- Individual central kitchens or restaurants.

The default is unrestricted BOSS read access to otherwise available business data. A restriction is a deny, never a grant or an edit privilege. Its effective result must be visible to administrators and changes must be durably audited.

### 4.1 Purchase-price restriction

The `Ceny zakupu` restriction denies purchase costs and prices, reference purchase prices, currency exchange rates used in purchase-price calculations, historical purchase-price information, and any derived value, report, export, or statistic that directly or indirectly reveals restricted purchase-price information.

Purchase-price visibility and foodcost visibility remain independent. Foodcost is withheld only when its output would disclose restricted purchase-price information or when the independent foodcost restriction applies. The restriction must not suppress unrelated raw-material information, including material names, supplier everyday display names, units, categories, or other ordinary business data.

### 4.2 Location restriction

An excluded location and its underlying records must be omitted from direct results and from every related detail, search, aggregation, report, export, print output, comparison, and derived statistic. No aggregate exception is implied. If a data projection cannot safely exclude a restricted location's contribution, withhold that result.

## 5. Multi-role precedence

Evaluate each role and permission within its existing authorized scope; do not merge scopes into a broader grant. A BOSS restriction must clamp access derived from BOSS and must not be bypassed by combining a global BOSS read with a permission from another scope.

An independent permission under another role remains effective only where the existing authorization model independently authorizes that operation at that role's existing scope. It must not be extended to other locations or data by BOSS. A permission source without a location scope cannot be treated as a location-scoped exception to a BOSS restriction.

## 6. Reports, exports, and non-bypass guarantees

Every report, export, print view, search, aggregate, comparison, and derived statistic must be built from data already constrained by the effective category and location restrictions. Report authorization alone is insufficient if the data projection can include restricted records or values.

If restricted and unrestricted values cannot be separated safely, omit the restricted value or withhold the result. Alternate endpoints, historical reports, caches, or different output formats must not restore restricted information.

## 7. Stage 1E compatibility

The Stage 1E availability contract is unchanged: effective access requires code readiness, explicit per-location enablement, active and verified identity, valid operating context and scope, and the required RBAC permission. Missing or false conditions deny access. No module is made `READY` or enabled by this amendment.

## 8. Implementation status

This amendment records the approved policy only. The implementation report [`VELTRA_PA_STAGE_07_IMPLEMENTATION_REPORT.md`](./VELTRA_PA_STAGE_07_IMPLEMENTATION_REPORT.md) identifies current backend surfaces that cannot yet enforce it end-to-end. No BOSS authorization behavior was changed as part of this amendment.
