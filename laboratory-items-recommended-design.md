# Laboratory Items and Test-Method Configuration — Recommended Design

**Status:** Phases 1–4 implemented
**Reviewed:** 2026-11-05
**Related audit:** [Laboratory backend audit and implementation plan](laboratory-backend-audit-plan.md)

## Phase 0 decisions agreed

- Tenant Admin governs catalog access; specifically designated lab staff
  author the tenant-wide test and panel catalog.
- Phase 1 has no facility-specific catalog or availability controls.
- Only active tests with `AVAILABLE` status are directly orderable.
  `TEMPORARILY_UNAVAILABLE`, `REFERRAL_ONLY`, and `DISABLED` are not directly
  orderable.
- An orderable test requires code, name, category, specimen/container
  configuration, and structured result parameters where applicable. A panel
  must contain at least one test.
- Designated editors publish changes directly. Changes are audited and
  versioned; referenced definitions are deactivated rather than deleted.
  Tenant Admin approval is not required for every edit.

## 1. Design goal

Allow a laboratory to configure what is required to perform a test—such as
analyzer/method, diagnostic reagents, kits, controls, and other consumables—
while preserving the distinction between:

1. **The clinical service requested:** a test or panel.
2. **The procedure used to perform it:** a method/assay and compatible
   instrument.
3. **The physical items used:** Inventory-managed materials, quantities, and
   lots.

The clinician orders the service. Laboratory staff configure and perform the
procedure. Inventory remains authoritative for stock and stock movements.

## 2. Core design decisions

### 2.1 Keep the clinician order picker clinical

The picker should continue to show lab tests and panels only. Reagents,
consumables, controls, and instruments are not clinical requests and must not
become `lab_order_items`.

The existing `lab_test_definitions`, `lab_panels`, and panel-member structure
are the right concepts for the orderable service catalog. Keep the existing
test code/name/version snapshot on order lines so later catalog edits do not
rewrite historical requests.

### 2.2 Inventory owns stockable products and movement history

Create reagents, kits, controls, specimen collection supplies, and disposable
consumables as Inventory items. Use current item types and categories where
they express the item correctly; in particular, `DIAGNOSTIC_REAGENT` already
exists as an Inventory category.

Inventory remains the authority for:

- Item identity and active status.
- Unit of measure and purchasing pack.
- Facility/location stock.
- Batch/lot, expiry, and serial tracking.
- Stock reservation, consumption, and reversal.

Laboratory configuration should store opaque Inventory item identifiers and
resolve/validate them through `InventoryApi`. Do not add cross-domain database
foreign keys or read Inventory tables directly from Laboratory.

### 2.3 A test may have multiple methods

Do not attach a single universal reagent list directly to every test unless the
domain has established that a test can never vary by method or analyzer. Model
the performing method separately so different sites, platforms, or assays can
have different requirements for the same clinical test.

A method/assay configuration should identify:

- The test definition it performs.
- A method code/name and version.
- The analyzer/platform compatibility requirements.
- The expected specimen/container constraints where they vary by method.
- Required and optional material requirements.
- Whether the method is active and available at a particular facility.

If the domain later confirms that every test has exactly one method, the
relationship can still be presented simply while retaining a schema that does
not block future alternatives.

## 3. Conceptual data model

This is a domain-level proposal, not a finalized migration specification.
Names and exact field types should be confirmed during the later method and
material-configuration phase.

### 3.1 Tenant-wide method catalog

`lab_test_methods` (or an equivalent Laboratory-owned structure):

- Tenant scope.
- Test-definition reference.
- Method code/name and version.
- Description and active state.
- Optional method-specific specimen/container and capability requirements.

This defines how a test can be performed, independent of where a particular
facility performs it.

### 3.2 Method material requirements

`lab_method_inventory_requirements` (or an equivalent child structure):

- Tenant scope and method reference.
- Opaque `inventory_item_id`.
- Requirement role, such as reagent, kit, calibrator, control, or consumable.
- Required/optional flag.
- Quantity per performed test or run, with an explicit unit.
- Optional notes, such as preparation or compatibility guidance.
- Requirement version/effective status sufficient to preserve execution
  history.

Each requirement should be validated against Inventory at configuration time.
The relationship is configuration, not stock: do not store on-hand quantities
or lot balances here.

Where dosage/consumption depends on a batch, analyzer, run size, or dilution,
the model should permit a method/run-level quantity rule instead of assuming
that a single scalar is clinically accurate. The first implementation should
use a simple explicit quantity only after laboratory workflow owners validate
that assumption.

Phase 2 stores an explicit quantity per test or per run in the Inventory
item's base unit. It is a configuration estimate only; it is not a stock
reservation. At execution, the method override for specimen/container type is
used when present; otherwise the test definition's constraint applies.

### 3.3 Facility instrument registry

Create a facility-scoped lab instrument/asset concept when instrument
assignment, downtime, calibration, maintenance, or QC compatibility is needed.
It should carry operational identity (for example, instrument identifier,
model/serial reference, facility, and status) and supported methods.

An Inventory `EQUIPMENT` item can identify the equipment product for
procurement/stock purposes, but it does not identify a particular installed
instrument. If a shared Asset domain is introduced, Laboratory should refer to
its public contract rather than duplicating that asset lifecycle.

### 3.4 Execution and consumption records

At performance time, the lab execution/run should capture:

- The ordered test line and the method/version actually used.
- The instrument instance, when applicable.
- Snapshot of the material requirements or actual items used.
- Actual quantity, stock location, and lot/batch for consumed materials.
- Status, operator, and relevant timestamps.

Actual stock decrements should be recorded with `InventoryApi::recordConsumption`
and a stable lab execution reference. The implementation persists a pending
material-use attempt before calling Inventory and reuses the same movement ID
and idempotency key on retry, so an interrupted call can be reconciled without
double consumption. Inventory authorization failures leave the attempt pending
for an authorized retry. Aborting does not automatically reverse consumed
stock; any correction is an explicit Inventory operation. Completed test lines
leave the execution queue, while aborted lines can be attempted again.

## 4. Quality control alignment

Replace free-text-only instrument and control references over time:

- Associate `analyzer_ref` with the facility instrument registry.
- Associate control material with a configured inventory item and actual lot
  when the control is consumed.
- Preserve the human-readable snapshots needed to interpret historical QC
  records if the instrument or catalog configuration changes.
- Keep QC results separate from patient results, as the current Lab domain
  intends.

The transition should preserve existing records and allow a deliberate legacy
reference strategy rather than attempting an unsafe automatic mapping.

## 5. Configuration and authorization

Configuration belongs to a Laboratory management workflow, not the clinician
order endpoint. The write surface should:

- Enforce tenant scope for catalog and method configuration.
- Enforce facility scope for instrument and facility availability setup.
- Require a dedicated server-side catalog-management permission assigned to
  designated lab staff through Tenant Admin governance. Facility Admins do not
  manage the tenant-wide catalog in Phase 1.
- Keep facility instrument and availability setup out of Phase 1.
- Audit creation, edits, deactivation, requirement changes, and instrument
  status transitions.
- Prevent destructive changes from invalidating historical orders,
  executions, QC, or stock movements.
- Use versioned snapshots for the test definition, method, requirements, and
  instrument identity actually used.
- Allow designated catalog editors to publish changes directly; do not add a
  per-change Tenant Admin approval step in Phase 1.

The existing `laboratory:define-test` and `laboratory:define-panel` console
commands remain operator-only paths. Phase 1 now provides a staff-facing
workflow with explicit governance rather than exposing those commands through
an unaudited generic editor.

### Phase 1 implementation

- The catalog workspace creates and updates tenant-wide test definitions and
  panels using the existing schema; no migration was needed.
- Tenant Admins explicitly grant or revoke the protected
  `laboratory.catalog.manage` role. Grants are limited to active users who are
  active Laboratory Technicians; the role is tenant-wide and does not grant
  broader Laboratory or administrative permissions. Facility Admins do not
  automatically receive catalog write access.
- Catalog writes validate structured result parameters, panel membership, and
  tenant ownership, then persist changes transactionally with version bumps
  and audit records. Referenced definitions are deactivated rather than
  deleted.
- Result parameters support numeric, coded, and text values; coded options are
  required for coded parameters, and reversed numeric reference limits are
  rejected. Historical parameters without a value type must be explicitly
  classified by an editor rather than silently assigned a clinical type.
- The clinician picker and order handler both restrict direct ordering to
  active `AVAILABLE` tests. Panels are not offered when any member is not
  orderable.
- Test methods, Inventory material requirements, facility instrument setup,
  and actual material consumption are delivered in Phases 2–4.

### Phase 2 implementation

- Each test may have multiple tenant-wide methods/assays with code, name,
  description, optional specimen/container overrides, active status, and a
  monotonically increasing version.
- A method's materials are versioned snapshots. A revision writes a new set
  while retaining the prior set, including Inventory item code/name,
  item type/category, base unit, requirement role, required/optional flag,
  per-test/per-run scope, and quantity.
- Requirements may use active Inventory `SUPPLY` and `CONSUMABLE` items.
  `REAGENT` specifically requires the `DIAGNOSTIC_REAGENT` category. Item
  references are resolved through `InventoryApi`; Laboratory does not query
  Inventory tables or store stock balances.
- Supported roles are reagent, kit, calibrator, control, and consumable.
  Quantities are explicit and positive, with up to four decimal places.
- This phase does not reserve or consume stock; consumption is recorded by
  Phase 4 executions.

### Phase 3 implementation

- Each facility has its own operational instrument records with active,
  out-of-service, and decommissioned states. Instrument identity is separate
  from Inventory; an optional active `EQUIPMENT` item link stores a descriptive
  snapshot without making Inventory the instrument registry.
- Supported method associations are immutable snapshots of the instrument
  version and method version. Only active methods for active, available tests
  can be configured or used for test-specific QC.
- New QC runs require an active instrument in the current facility and retain
  its configured identifier in `analyzer_ref`. The nullable instrument link
  preserves existing QC history.
- QC control material remains descriptive text for now. Linking an Inventory
  control item and lot requires a separate QC-consumption integration beyond
  the patient-test execution flow in Phase 4.

### Phase 4 implementation

- Each execution is tied to an accepted specimen from its order and snapshots
  the order line, test, method/version, instrument/version, and material
  requirements.
- Method-specific specimen and container settings override the test
  definition's constraints; mismatches are rejected by the backend and
  filtered from the operator's specimen choices.
- Actual reagent/consumable use is recorded through Inventory with quantity,
  stock location, batch/lot, and a stable movement identifier. Inventory
  remains authoritative for balances and movement history.
- Duplicate execution IDs are idempotent only for the same order line,
  specimen, method, and instrument. A completed test line is not silently
  restarted; aborted work can be retried as a new attempt.
- No automatic stock restoration occurs when execution is aborted.

## 6. Orderability and availability

The agreed Phase 0 policy for direct clinician ordering is:

- `AVAILABLE`: orderable at a facility that can perform or route the test.
- `TEMPORARILY_UNAVAILABLE`: not directly orderable.
- `REFERRAL_ONLY`: not directly orderable. Referral ordering requires a
  separately designed referral workflow and destination.
- `DISABLED` or inactive: not orderable.

For Phase 1, only active `AVAILABLE` tests are directly orderable. The server
must enforce this policy regardless of what the clinician picker displays.
Facility-specific capability and method availability remain out of scope until
a later facility-configuration phase.

## 7. User workflows

### Laboratory catalog administrator

1. Create/update test definitions and panels.
2. Ensure an orderable test has code, name, category, specimen/container
   configuration, and structured result parameters where applicable. A panel
   must contain at least one test.
3. Define available methods for tests and associate validated reagent and
   consumable requirements.
4. Review the Inventory item name, type/category, unit, and tracking settings
   when selecting each requirement.
5. Configure facility-specific instrument availability and method compatibility
   in the Phase 3 instrument workspace.
6. Publish authorized changes directly; review the audit history and deactivate
   obsolete definitions instead of deleting referenced records.

### Clinician

1. Search and select a test or panel.
2. See availability consistent with facility policy.
3. Submit the clinical order without selecting operational supplies or
   analyzers.

### Laboratory operator

1. Accept and perform the ordered test using an available method/instrument.
2. Record execution and actual material usage with lot/batch traceability.
3. Record QC against the instrument and control material as applicable.

## 8. Suggested delivery order

1. Agree on authorization, availability, and execution/consumption policy.
2. Deliver a governed staff workflow for tests, parameters, and panels.
3. Add method and Inventory-item requirements with cross-domain validation.
4. Add facility instrument registry and connect QC references.
5. Record actual test execution and Inventory consumption with retry and
   explicit no-automatic-restoration coverage.

This sequencing provides a usable catalog-management capability before
introducing stock movements, and avoids coupling test ordering to the physical
supplies required to perform the order.

## 9. Explicit non-goals

- Showing reagents, controls, consumables, or equipment in the clinician test
  picker.
- Copying Inventory balances, batches, or stock ledgers into Laboratory.
- Decrementing stock when an order is created or submitted.
- Treating a generic equipment stock item as an installed/calibrated
  instrument.
- Automatically translating unstructured legacy analyzer/control text into
  new instrument or Inventory references.
