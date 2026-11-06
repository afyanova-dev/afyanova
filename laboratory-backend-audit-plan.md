# Laboratory Backend Audit and Implementation Plan

**Status:** Audit complete; Phase 0 decisions agreed; Phases 1–4 implemented
**Reviewed:** 2026-11-05
**Scope:** Backend support for clinician lab ordering, lab test configuration,
and the tests, equipment, reagents, and other materials required to perform
laboratory work.

## Executive summary

The backend already separates the clinician's orderable laboratory services
from the physical materials used to perform them:

- `lab_test_definitions` stores test catalog entries.
- `lab_panels` and `lab_panel_members` group tests for ordering.
- `lab_orders` and `lab_order_items` record the clinician's requested work.
- `inv_items` is the tenant's stock-item catalog and already supports
  diagnostic reagents, consumables, and equipment classifications.

Phases 1–4 provide governed test and panel management, method/material
configuration, a facility instrument registry, and a laboratory execution
workflow that records actual Inventory consumption. Inventory remains the
authority for stock and movement history. QC control material remains a
descriptive reference and is not yet linked to Inventory lots.

**Design direction:** keep the clinician workspace picker limited to tests and
panels. Laboratory methods reference Inventory items, and execution records
snapshot configured requirements and send actual usage to Inventory with a
stable retry key. Do not treat ordering a test as stock consumption.

## Audit findings

| Area | Current backend state | Gap / implication |
|---|---|---|
| Test catalog | `lab_test_definitions` has code, name, category, specimen/container, parameters, availability, capability, and version fields. Definitions are tenant-scoped. Phase 1 adds a staff-facing catalog workspace and validates structured parameter definitions. | Result capture and result validation during laboratory execution are still outside Phase 1. |
| Panels | `lab_panels` and `lab_panel_members` define bundles of tests. | Panel membership only groups orderable tests; it does not define a method or its materials. |
| Clinician orders | The clinician endpoint accepts test and panel IDs. Panel members expand into `lab_order_items`, which snapshot the test code, name, and definition version. | Existing order semantics are appropriate for clinical requests. Reagents and equipment should not be added as clinician order items. |
| Catalog authoring | A Laboratory catalog workspace supports authorized staff creating and updating tests and panels; Tenant Admins grant or revoke the dedicated catalog-editor role for active Laboratory Technicians. | Catalog and method/material configuration are available without console access. |
| Inventory catalog | `inv_items` has item type, category, unit of measure, batch/serial/expiry tracking, and active/version fields. Types include `CONSUMABLE` and `EQUIPMENT`; categories include `DIAGNOSTIC_REAGENT`. Inventory exposes item reads and stock-consumption operations. | Method requirements reference validated Inventory items; execution records actual, lot-aware consumption through the Inventory API. |
| Equipment and instruments | Inventory can classify an item as equipment. Laboratory also has a facility instrument registry with active/out-of-service/decommissioned states and supported-method snapshots. | Installed instruments are modeled separately from generic Inventory equipment. Calibration and maintenance lifecycle remain outside this phase. |
| Quality control | QC runs can link to an active facility instrument while preserving the configured identifier in `analyzer_ref`. | Control material remains descriptive text and is not yet linked to an Inventory item or consumed lot. |
| Availability | Test definitions include `AVAILABLE`, `TEMPORARILY_UNAVAILABLE`, `REFERRAL_ONLY`, and `DISABLED`. The order handler and clinician picker now expose/accept only active `AVAILABLE` tests; panels with non-orderable members are excluded. | The agreed policy is enforced both in the clinician workspace and on the server. |
| Result parameters | The existing JSON field stores parameter definitions. Phase 1 validates structured codes, names, value types, coded options, units, and reference-range bounds. | Result entry and result validation during laboratory execution remain outside Phase 1. |
| Tenant/facility scope | Test and panel catalogs are tenant-wide; orders and QC runs are facility-scoped. | A method may be tenant-defined, but actual availability of a method or instrument should be configurable at the performing facility. |

## Evidence reviewed

- [Lab test-definition migration](app/Domains/Laboratory/Database/Migrations/2026_09_06_000010_create_lab_test_definitions_table.php)
- [Lab panel migration](app/Domains/Laboratory/Database/Migrations/2026_09_06_000015_create_lab_panels_table.php)
- [Lab panel-member migration](app/Domains/Laboratory/Database/Migrations/2026_09_06_000016_create_lab_panel_members_table.php)
- [Lab order-item migration](app/Domains/Laboratory/Database/Migrations/2026_09_06_000040_create_lab_order_items_table.php)
- [Clinician lab-order handler](app/Domains/Laboratory/Application/Command/CreateLabOrderHandler.php)
- [Clinician lab-order request](app/Domains/Laboratory/Presentation/Http/Request/CreateClinicianLabOrderRequest.php)
- [Lab test-definition command](app/Domains/Laboratory/Console/DefineLabTestCommand.php)
- [Lab panel command](app/Domains/Laboratory/Console/DefineLabPanelCommand.php)
- [Inventory item migration](app/Domains/Inventory/Database/Migrations/2026_09_07_000010_create_inv_items_table.php)
- [Inventory item types](app/Domains/Inventory/Domain/ValueObject/ItemType.php)
- [Inventory item categories](app/Domains/Inventory/Domain/ValueObject/ItemCategory.php)
- [Inventory API](app/Domains/Inventory/Contracts/InventoryApi.php)
- [Lab QC-run migration](app/Domains/Laboratory/Database/Migrations/2026_09_06_000100_create_lab_qc_runs_table.php)

This is a static backend audit of the current working tree, not a workflow,
permissions, or clinical-policy certification.

## Recommended implementation sequence

### Phase 0 — Confirm policy and ownership (decisions agreed)

The following Phase 0 decisions are agreed:

| Decision | Agreed direction |
|---|---|
| Catalog ownership | Tenant Admin governs access; specifically designated lab staff author tenant-wide test definitions and panels. The existing role/permission system must provide an explicit, auditable way to designate these editors. |
| Facility scope for Phase 1 | No facility-specific availability or panel configuration. Phase 1 manages the tenant-wide catalog only. |
| Direct orderability | Only active tests with `AVAILABLE` status are directly orderable. `TEMPORARILY_UNAVAILABLE`, `REFERRAL_ONLY`, and `DISABLED` tests are not directly orderable; the server must enforce this as well as the picker. |
| Minimum test definition | An orderable test has code, name, category, specimen/container configuration, and structured result parameters where applicable. A panel must contain at least one test. |
| Change governance | Designated editors publish changes directly. Every change is audited and versioned; referenced definitions are deactivated rather than deleted, and historical orders retain snapshots. No Tenant Admin approval step is required for each catalog edit. |

The execution policy is now agreed: Inventory is authoritative, each movement
uses a stable idempotency key for retries, and aborting an execution does not
automatically restore previously consumed stock. Calibration and maintenance
remain outside the Laboratory instrument registry until a shared asset
lifecycle is established.

**Exit criteria:** met. Owners, scope, orderability rules, audit requirements,
catalog minimum fields, and execution/consumption behavior are agreed.

### Phase 1 — Governed laboratory catalog management (implemented)

- Provide authorized staff workflows for creating, updating, and retiring test
  definitions and panels, with a specific catalog-management permission for
  designated lab staff governed by Tenant Admin access administration.
- Keep definitions tenant-wide; do not add facility-level controls in this
  phase.
- Require code, name, category, specimen/container configuration, and
  applicable structured result parameters before a test can be marked
  `AVAILABLE`; require at least one member for an orderable panel.
- Enforce the agreed availability policy server-side: only active
  `AVAILABLE` tests can be directly ordered.
- Publish authorized changes directly, audit and version them, and preserve
  existing order definition-version snapshots.
- Use structured, validated parameter definitions rather than treating
  parameters as documentation-only JSON.
- Tenant Admins can grant or revoke the dedicated `laboratory.catalog.manage`
  role for active Laboratory Technicians; Facility Admins do not automatically
  receive tenant-wide catalog write access.
- The workflow uses the existing tenant-wide catalog schema; no migration was
  required. Catalog edits are transactional, versioned, and audited.
- Focused tests cover editor assignment, authorization, catalog validation,
  panel membership, orderability, and clinician picker filtering.

**Exit criteria:** met. An authorized operator can maintain the orderable
catalog without direct database edits or console access, and the focused
catalog, ordering, and clinician-workspace tests pass.

### Phase 2 — Test methods and material requirements (implemented)

- Define one or more methods/assays for a test when material or equipment
  requirements differ.
- Configure each method's required or optional Inventory items, their roles,
  and usage quantity/unit per performed test.
- Resolve Inventory IDs through the Inventory API; do not make Laboratory
  directly query Inventory tables.
- Validate that configured items exist in the tenant, are active, and have
  appropriate types/categories and usable units.
- Methods support multiple active/inactive assay configurations per test,
  optional method-specific specimen/container overrides, and versioning.
- Material requirements reference active Inventory `SUPPLY` or `CONSUMABLE`
  items. Reagents must use the `DIAGNOSTIC_REAGENT` category. The configured
  quantity is explicitly scoped per test or per run and uses the Inventory
  item's base unit; no stock is reserved or consumed.
- Each method revision retains its own material snapshots, including the
  Inventory item identity, type/category, unit, role, and quantity. Changes
  are audited; the catalog reads only the current method version.

**Exit criteria:** met. Catalog editors can configure methods and materials;
invalid, inactive, wrong-type, and cross-tenant Inventory references are
rejected. Method history is preserved and covered by focused feature tests.

### Phase 3 — Facility instrument configuration

- Configure instruments available at each facility and associate them with
  supported methods.
- Model instrument identity and operational status separately from a generic
  stocked equipment item.
- Link new QC runs to an active facility instrument and preserve the configured
  identifier as a historical snapshot; existing QC rows remain valid.
- Preserve method compatibility by instrument and method version, and reject
  QC when the instrument or its configured method/test is no longer current.
- Defer Inventory control-material/lot linkage until execution records actual
  consumption; the existing QC control-material description remains available.

**Exit criteria:** met. Authorized facility staff can register and version
instruments, associate only active methods for available tests, and record
test-specific QC only against an active instrument with current method
compatibility. Authorization and tenant/facility isolation are covered by
feature tests.

### Phase 4 — Execution and stock traceability (implemented)

- Each run captures an accepted same-order specimen, method/version, compatible
  facility instrument when selected, and snapshots of method/material
  configuration.
- Actual quantity, stock location, batch/lot, operator, and a stable movement
  identifier are recorded through the Inventory API. Pending movements remain
  retryable after authorization denial or an interrupted cross-domain call.
- Duplicate execution identifiers are accepted only when the complete
  specimen/method/instrument selection matches. A completed test line leaves
  the execution worklist; an aborted line may be attempted again.
- Aborting does not reverse consumed stock. Reversal is a deliberate separate
  Inventory action, not an automatic side effect of stopping laboratory work.
- Test-method specimen and container constraints inherit from the test
  definition unless the method supplies an override.
- Order creation remains separate from reservation and consumption; no stock
  reservation is introduced.

**Exit criteria:** met. The focused Laboratory feature suite passes, including
material consumption, retry/idempotency, abort-without-restoration,
authorization-denied retry, specimen/container compatibility, and instrument
compatibility (86 tests, 533 assertions).

## Constraints and non-goals

- Do not add reagents, supplies, or equipment to the clinician-facing test
  picker or `lab_order_items`.
- Do not duplicate Inventory stock quantities, batch records, or movement
  history inside Laboratory.
- Do not represent an instrument's calibration, current status, or physical
  facility assignment solely as an `inv_items` row.
- Do not add stock reservation unless a later workflow specifically requires
  it.

## Validation expectations for implementation

- Preserve tenant isolation and facility authorization.
- Phase 1 tests cover catalog authorization, audit/version behavior, parameter
  validation, panel membership, orderability statuses, and clinician picker
  filtering.
- Focused coverage verifies test-to-method configuration, invalid Inventory
  references, instrument compatibility, execution snapshots, actual use,
  stable retry behavior, permission denial and retry, abort stock policy,
  completed queue removal, and specimen/container compatibility.
- A separate future QC integration may link control-material consumption to
  Inventory lots; it is not part of the current patient-test execution flow.

The separate [recommended design](laboratory-items-recommended-design.md)
describes the target domain boundaries and conceptual data model.
