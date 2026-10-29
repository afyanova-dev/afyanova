# Inventory, Pharmacy Supply, and Billing Frontend Roadmap

## 1. Purpose

This roadmap sequences frontend and any required backend-enabling work for a
reliable medicine-supply and dispensing journey:

```text
Define medicine + facility stock location
    → receive stock (opening balance, donation, or procurement)
    → inspect on-hand / available stock, batch, and movement history
    → dispense against a validated Pharmacy order
    → capture and resolve the medicine charge
    → issue/pay the dispensing-specific invoice
    → record pickup
```

The goal is to make the next implementation step unambiguous without building
duplicate domain logic. Inventory is a **generic stock-management capability**:
its item catalogue and stock workflows must not be designed as medicine-only.
The first operational rollout should nevertheless be **medicine-first**, to
solve the current Pharmacy stock blocker without delaying the generic
foundation. Pharmacy-specific dispensing and medicine billing are the first
consumer workflows; other item types can follow when their operational
consumers and requirements are ready.

Inventory owns the item catalogue, stock locations, receipts, balances,
adjustments, reservations, and movement history. Pharmacy owns
prescription/order/dispensing/pickup workflows. Billing owns billing services,
tariffs, item mappings, charges, invoices, and payments. Procurement owns
purchase orders; Inventory remains the writer of received stock.

The Item Master's controlled vocabularies, generic-name rule, immutability
guardrails, and the Tanzania-fit decisions (TMDA/NEMLIT/NHIF) are recorded in
`inventory-item-master-spec.md` (the "now" slice plus the deferred Inv.0/Phase 8
work).

This is an implementation roadmap, not a claim that every backend gap is
already resolved. Confirm each phase's contract and exit criteria before
starting its UI work.

## 2. Current state from the backend audit

### Inventory backend present

- Tenant-scoped item catalogue and facility-scoped stock locations.
- The item catalogue is generic: `item_type` and category distinguish medicines
  from other inventory items. Pharmacy enforces its own `MEDICATION` constraint
  when dispensing; that restriction must not be applied to Inventory-wide item
  setup, stock receipt, balance, ledger, or adjustment screens.
- Console commands `inventory:define-item` and `inventory:define-location`;
  item/location authoring has no HTTP route or permission-gated UI.
- `ReceiveStock` records stock receipts and creates/updates the batch,
  append-only movement, and materialized balance transactionally. Supported
  receipt sources include `PROCUREMENT`, `DONATION`, `OPENING_BALANCE`, and
  `OTHER`. Batch number and expiry are enforced when the item requires them.
- Queries exist for items, locations, per-location/per-batch balances,
  facility/item availability, movement ledger, adjustments, and reservations.
- Stock adjustments use request plus separate approval/rejection; reservations
  use reserve/release/cancel/expire/fulfil lifecycle.
- Ledger append-only behavior, transaction locking, non-negative stock guard,
  audit, idempotency support, and reconciliation are implemented.
- There is no Inventory HTTP controller, request layer, route group, or
  Inventory workspace today.

### Pharmacy frontend and backend present

- Pharmacy workspace, prescription/order/transcription, dispensing, reversal,
  pickup, and patient-context frontend phases are recorded as implemented in
  [frontend-foundation-pharmacy.md](./frontend-foundation-pharmacy.md).
- Backend dispensing validates the order/item/facility, reserves and consumes
  inventory stock transactionally, and records the dispensing.
- Pharmacy reads medication catalogue items and active facility locations.
  Current availability is live for the selected item, but surfaced as one
  facility-wide `availableQty`, not availability for the selected location
  and batch.
- Pharmacists need Pharmacy permissions plus `inventory.access`,
  `inventory.reservations.manage`, and `inventory.consumption.record`.
- The Pharmacy plan identified a missing electronic-prescription accept route.
  Resolved in Phase 4 item 6: `POST /workspaces/pharmacy/prescriptions/{id}/accept`
  dispatches `AcceptPrescription` under `pharmacy.prescriptions.accept`, and the
  prescription detail panel offers it for a `RECEIVED` electronic prescription.

### Billing and Procurement present

- Billing cashier, catalog, tariffs, charges, invoices, payments, and payment
  allocation backend/frontend surfaces exist.
- Billing captures a billable event after dispensing. Item-to-service mapping
  and tariff resolution are backend capabilities, but the Billing UI/routes do
  not expose item-to-service mapping.
- An unmapped item or unpriced/inactive service can leave a billable event
  pending. Mapping an item retries pending `ITEM_UNMAPPED` events; setting a
  tariff alone does not retry `SERVICE_UNPRICED` events.
- Procurement handles purchase orders in the backend and reacts to Inventory's
  `StockReceived` event for PO receipt reconciliation, but no Procurement
  workspace/routes were found in the audited presentation layer.
- The demo database seeder does not create Inventory items, locations, or
  starting stock. A fresh seeded environment should not be expected to support
  dispensing until configured and stocked. This describes source code; it does
  not assert the state of any deployed database.

## 3. Scope rules and design constraints

1. Use existing commands, queries, DTOs, policies, and domain contracts. Do not
   write directly to Inventory or Billing tables from presentation code.
2. Add route/request/controller/presenter adapters only after confirming
   authorization, facility scope, validation, audit, idempotency, and error
   contracts. Reuse domain commands; do not replace them with generic CRUD.
3. Server-side availability and movement commands are authoritative. Frontend
   availability is advisory and must be revalidated during dispense.
4. Do not report a receipt, adjustment, dispense, charge, payment, or pickup as
   successful until the server confirms it.
5. Preserve audit/history. Stock balance is not directly editable; corrections
   use receipts, approved adjustments, or compensating movements as appropriate.
6. Do not let the Pharmacy frontend become Inventory administration or Billing
   configuration. Use contextual links to their canonical workspaces.
7. Build Inventory lists and stock operations for generic item types; do not
   hard-code medicine fields or filter the Inventory catalogue to
   `MEDICATION`. For the first rollout, configure and validate medicines first
   because Pharmacy is the immediate consumer. Other categories may be
   onboarded later without changing Inventory ownership or duplicating its
   workflows.
8. Maintain one base unit per item for the initial release. Unit conversion
   and serial-level operations are not supported by the audited backend.
9. Resolve expiry safety before operational use of expiry-tracked medicine:
   current availability sums stock without excluding expired batches, and the
   consumption path does not establish an expiry exclusion contract.

## 4. Phase map and dependencies

| Phase | Name | Status | Depends on | Primary outcome |
|---|---|---|---|---|
| 0 | Contract and operational decisions | Complete: catalogue ownership, receipt-source/opening-balance policy, item governance, Procurement ownership, stock-disposition policy, and demo/test-data strategy all decided | None | Resolve safety/product decisions and freeze scope |
| 1 | Generic Inventory workspace foundation and master data | Complete: authorized setup, Carbon table search/filter, safe lifecycle controls, controlled item vocabularies (type/category/base unit), and reclassification guardrails | Phase 0 | Authorized, type-neutral item/location read and setup |
| 2 | Medicine-first stock receiving and opening balances | Complete: manual receipt path, HTTP acceptance coverage, and the Phase 0 receipt-source/opening-balance policy; PO-linked receiving deferred to Phase 7 | Phase 1 | Users can put trusted medicine stock into the ledger |
| 3 | Stock-on-hand, ledger, and adjustment controls | Complete: location-first and facility-wide stock-on-hand, batch detail, movement ledger, adjustment request/approve/reject, and reservation visibility | Phase 1; Phase 2 for meaningful balances | Users can inspect and correct stock safely |
| 4 | Pharmacy stock-aware dispensing readiness | Complete: backend expiry safety, the stock-aware dispense UI, and electronic prescription acceptance | Phase 0; Phases 1–2 | UI and backend agree on location/batch availability |
| 5 | Billing item mapping and charge resolution | Complete: Billing price-list page, medication → service mapping worklist, and pending-charge resolution | Inventory item catalogue; Billing catalog | Every medicine can resolve to a priced billable service |
| 6 | End-to-end financial clearance and pickup | Complete: the real receive → dispense → charge → dedicated invoice/payment or confirmed coverage → pickup journey is verified end to end | Phases 2, 4, 5 | Receive-to-paid-pickup journey works end to end |
| 7 | Procurement receiving integration UI | Complete: PO-aware receiving in the Inventory workspace via a validated Procurement selection; PO lifecycle and reconciliation stay in Procurement | Phases 1–2; Procurement scope decision | Purchase-order receipts create stock and reconcile POs |
| 8 | Operational hardening and deferred inventory capabilities | Not started / future | Phases 1–7 as applicable | Production readiness and explicitly selected extensions |

Phases 3 and 5 can proceed in parallel after Phase 1 if ownership and API
contracts are agreed. Phase 4's frontend contract should be agreed early, but
its production-ready acceptance depends on resolving expiry handling and
location/batch-specific availability.

## 5. Phased implementation

### Phase 0 — Contract and operational decisions

**Status: Complete (2026-10-18).** Every Phase 0 decision is recorded:
catalogue-authoring ownership (tenant-wide item catalogue mutations require
General context; facility-owned locations require the active facility context);
the **receipt-source
and opening-balance policy**, including the role matrix that makes the Facility
Admin the only initial-release receipt authority (see below); **item field
governance / immutability** (see `inventory-item-master-spec.md`); the **stock
status and disposition policy**, including its four rollout parameters (see
below); the **demo and test data strategy** (see below); and **Procurement
ownership**, resolved by Phase 7.

**Decided — receipt sources and opening balances (2026-10-18).**

The manual receipt form offers exactly three sources. `PROCUREMENT` is reserved
for the Phase 7 purchase-order receiving path and is never free text.

| Source | When it is used | Evidence required |
|---|---|---|
| `OPENING_BALANCE` | Seeding the ledger with a **verified physical count** at go-live or after a supervised stock-take. A controlled setup step, not a routine operation. | A `reason` naming the source document (count-sheet / cut-over reference). |
| `DONATION` | Donated goods received from a donor. | `supplier_ref` (donor) and a `reason`. |
| `OTHER` | Any receipt that is none of the above (e.g. a ward return recorded manually). | A `reason`. |
| `PROCUREMENT` | **Phase 7 only** — receiving against a validated purchase order. | A validated `po_ref`; never free text. |

Rules that apply to every receipt:

- **Authority.** Only a holder of `inventory.receipts.create` may record a
  receipt. In the initial release that is the **Facility Admin**, scoped to the
  active facility — deliberately *not* the Tenant Admin (who owns the catalogue)
  or the pharmacist (who consumes). A dedicated storekeeper role is a future
  role-definition change, not part of the initial release.
- **Opening balance is a controlled setup step.** Record it during a defined
  go-live / stock-take window and reconcile the ledger before routine dispensing
  begins. The system does not yet hard-limit it to once; re-baselining is a
  documented, reasoned operation, never a daily one.
- **Backdating.** `occurred_at` must not be in the future; a receipt dated more
  than 24 hours ago requires a `reason` (enforced in request and command).
- **Idempotency.** Each submission carries a client-minted `movement_id` and an
  `idempotency_key`; a retried submission is a no-op. Operators must not mint a
  second key for the same physical receipt — the UI mints both per submission.
- **Tracking rules.** A `track_batch` item requires a batch number; a
  `track_expiry` item also requires an expiry date (enforced in the request and
  the handler). Serial-tracked items are excluded from receiving until serial
  capture exists.

**Decided — stock status and disposition (expired / near-expiry / damaged /
quarantined) (2026-10-18).**

*Principle.* The **ledger** is the only truth about *quantity*; a batch's
**usability** is a separate question. Quantity never changes except through a
ledger-backed movement, and unusable stock is removed by an **approved
adjustment** — never by editing a balance, deleting a row, or recording a
negative receipt. Phase 4 already decides what may be *dispensed* (expired
batches are excluded from availability); this policy decides what happens to
stock that must no longer be used.

| State | Meaning | Represented today |
|---|---|---|
| **Expired** | `expiry_date` earlier than the as-of date. | Yes — the balance row is `eligible = false`, so it is excluded from `AvailableStockSummary`; reserving/consuming it raises `StockBatchExpired`, and receiving an already-expired batch raises `StockBatchAlreadyExpired`. It still counts in **on-hand**. |
| **Near-expiry** | `expiry_date` inside the alert window (below). | **Worklist, no push** — the Phase 8 **Expiry** section lists every batch inside the 90-day window (already expired first) with exact days remaining; there is no notification and nothing is blocked for being near expiry. |
| **Damaged** | Physically unusable (broken, contaminated, cold-chain breach). | **No state** — expressed only as a *removal*: a `DECREASE` adjustment with `reason_code = DAMAGED`. |
| **Quarantined** | Held pending a decision (recall, suspect quality, awaiting destruction). | **No state** — see the quarantine rule below. |
| **Returned** | Returned from a ward / patient area. | **No workflow** — a `DECREASE`/`INCREASE` adjustment with `reason_code = OTHER` and a note. |
| **Disposal / write-off** | Terminal removal of unusable stock from the ledger. | Yes, as an approved `DECREASE` adjustment. |
| **In-transit** | Between locations. | **No state** — a transfer posts both location sides together (Phase 8), so nothing is ever in an intermediate bucket. |

Rules:

- **R1 — Expired is never dispensable.** Enforced today (`eligible = false`,
  `StockBatchExpired`). No change.
- **R2 — Expired is never received.** Enforced today (`StockBatchAlreadyExpired`).
  No change. A backdated receipt is still allowed when the batch was valid on the
  receipt date.
- **R3 — Removal is a two-person adjustment.** Expired stock is disposed with
  `reason_code = EXPIRY`, damage with `DAMAGED`, unexplained loss with `LOST`,
  and recall/return/other with `OTHER` plus a note. Where the item tracks
  batches, the adjustment is **batch-scoped** (`batch_number`). Never a balance
  edit.
- **R4 — Separation of duties is mandatory.** The requester and the approver must
  differ (`SelfApprovalNotPermitted`); `inventory.adjustments.create` and
  `inventory.adjustments.approve` are distinct authorities. **One person may not
  both declare and write off** the same stock.
- **R5 — Disposal carries evidence.** The adjustment note must name the source
  document (stock-take sheet, destruction/incineration record, incident report)
  for `EXPIRY` / `DAMAGED` / `LOST`. Today `reason_text` is optional at the
  domain boundary; requiring it for disposal codes is a **Phase 8** hardening
  item, enforced operationally in the meantime.
- **R6 — Quarantine is a handling instruction, not an enforced system state.**
  There is still no batch status. Stock *can* now be moved between two locations
  of a facility (the Phase 8 transfers slice), including into a `QUARANTINE`
  location, but nothing marks those units as held: no gate treats a quarantine
  location as unusable, so a batch sitting there is still available to dispense
  or reserve. The move is only as strong as the people following the instruction.
  Real quarantine needs a batch status that extends `eligible` (still open). A
  facility may also receive **newly arriving** suspect goods directly into a
  `QUARANTINE` location; that must not imply anything about already-held stock.
  For stock already on hand, the honest options remain: **leave it** (it remains
  on-hand; if expired it is already unavailable) or **remove it** (R3).
- **R7 — Expired stock is not silently carried.** A periodic (monthly) review of
  batches past or near expiry drives disposal per R3. Expired stock remaining
  on-hand is expected until that review, and is never a dispensing error because
  R1 blocks it.
- **R8 — Near-expiry is reporting-only.** The recommended window is **90 days**
  for medicines (see parameters below). Nothing is blocked for being near expiry.
- **R9 — Disposal is immutable.** Corrections are compensating `INCREASE`
  adjustments with `reason_code = DATA_ERROR`, also two-person — never an edit of
  the original.

**What this policy deliberately does not claim.** There is no quarantine bucket,
no damaged/in-transit/expired balance bucket, no disposal workflow, and no expiry
report. Transfers between two locations now exist, but they do not create a
held/quarantined state — the roadmap must keep that distinction visible rather
than implied.

**Phase 8 deliverables that make the states real** (each needs an owner,
contract, and tests before UI):

- A **batch/stock status** (`AVAILABLE | QUARANTINE | DAMAGED | EXPIRED |
  IN_TRANSIT | RETURNED`) that **extends the existing `eligible` flag** rather
  than adding a parallel one.
- A built-in **`QUARANTINE` location type**, so quarantine means stock actually
  moved and segregated. **Transfers landed (Phase 8)**, so stock can be moved
  there — but without the batch status above the location still cannot enforce a
  held state.
- A **disposal / write-off workflow** with evidence, witness, effective date and
  audit.
- **Near-expiry / expired reporting and alerts**, and reorder / safety-stock
  alerts.
- **Recall** support (a batch-level recall that immediately withdraws stock from
  dispensable).
- Dedicated permissions for quarantine / disposal decisions instead of
  overloading `inventory.adjustments.*`, and first-class `QUARANTINE` / `RECALL`
  / `RETURN` reason codes.

**Pack conversion — built (2026-10-20), outside the state list above.** An item
may carry one optional default buying unit (`pack_unit` + `pack_size`, e.g.
1 BOX = 100 CAPSULE); the base unit remains what stock is held, dispensed and
expired in. Receiving accepts the unit the delivery was counted in and records
`entered_unit` / `entered_quantity` / `pack_size` alongside the base quantity,
which the server verifies rather than derives. Purchase-order lines record the
entered unit, its pack size and a generated base quantity, with the price per
entered unit. Partial packs are accepted at both boundaries. Deliberately not
built: packs at dispensing, supplier-specific pack catalogues, and costing in
pack units.

**Rollout parameters — decided (2026-10-18).** None blocks go-live.

1. **Near-expiry window** — 90 days for medicines (tiered 30/60/90 later).
2. **Disposal witness** — required for controlled drugs, in addition to the second
   approver.
3. **Quarantine shape in Phase 8** — a batch status **and** a quarantine location
   (segregation), not one or the other.
4. **Damage handling** — quarantine first, then dispose after review; remove
   immediately only when the damage is obviously unrecoverable.

**Decided — demo and test data strategy (2026-10-18).**

*Goal.* Synthetic stock must never be mistaken for real stock — by a user reading
the workspace or by an engineer reading the database. The system has no `is_demo`
flag and will not grow one: the guarantee is **tenant isolation plus a visible
naming convention**, so production tables keep no demo-only column and no code
path has to special-case demo rows.

*Where synthetic stock may exist.*

- **Only in the demo tenant** (slug `afyanova-demo` — the tenant created by
  `DatabaseSeeder`). A real tenant is never seeded with synthetic stock; tenant
  isolation is the primary guarantee.
- Only in the demo hospital/dispensary that the seeder creates, never in a
  facility that could hold real stock even inside that tenant.

*How it is made visible.*

- A **`DEMO-` code prefix** on every synthetic item and stock location (codes are
  unique per tenant/facility and appear in the catalogue and the receive/stock
  views) plus a **`[DEMO]` name prefix** on the same rows.
- **Round, obviously-synthetic quantities** (e.g. 1000, 500) — never the small
  irregular numbers a real dispensary accumulates.
- Every synthetic receipt uses `source_type = OPENING_BALANCE` with a `reason`
  that names the demo seed ("Demo seed — synthetic opening balance"), so the
  ledger itself records that the row is synthetic.

*How it is created.*

- **Only through the receiving path** — the same `ReceiveStock` command the UI
  uses, with `OPENING_BALANCE`. Never a direct `inv_stock_balances` /
  `inv_stock_movements` write and never an edited row, so demo stock obeys the
  same rules as real stock and demo behaviour (availability, expiry exclusion, PO
  reconciliation) stays representative.
- The catalogue is created with the console-only `inventory:define-item` /
  `inventory:define-location` commands or through the Inventory workspace; both
  are audited.

*Documented setup path* (repeatable, in the demo tenant only):

1. `php artisan db:seed` — creates the `afyanova-demo` tenant, organisations,
   facilities, roles, and users. **It seeds no stock.**
2. In General context, as an authorized catalogue manager
   (`inventory.items.manage`), define the synthetic items —
   e.g. `php artisan inventory:define-item define --tenant=<demo-tenant-uuid>
   --code=DEMO-PARA500 --name="[DEMO] Paracetamol 500mg" --type=MEDICATION
   --uom=tablet --track-batch=1 --track-expiry=1`.
3. In the target facility context, as an authorized facility manager
   (`inventory.locations.manage`), define the synthetic store — e.g.
   `php artisan inventory:define-location define
   --tenant=<demo-tenant-uuid> --facility=<demo-hospital-uuid> --code=DEMO-STORE
   --name="[DEMO] Demo Store" --type=STORE`.
4. Record opening stock through the Inventory receive UI (as the demo Facility
   Admin, `inventory.receipts.create`): each `DEMO-` item, in the `DEMO-` store,
   source `OPENING_BALANCE`, reason "Demo seed — synthetic opening balance".

*Deliberately deferred.* A single `inventory:seed-demo-stock` command that folds
steps 2–4 together is **not built**: it needs both General and facility scope
transitions (and the separately authorized receipt flow) and is an operator
convenience, not a go-live requirement. If it is ever added it must be
demo-tenant-guarded and must dispatch `ReceiveStock` — never write balances.

*Test data.* Automated tests never depend on demo-tenant data. Each test builds
its own fixtures through the shared helpers (`seedInventoryItem`,
`seedStockLocation`, `inventoryFixture`) inside explicit tenant/facility context,
so the suite is independent of any seeded demo stock.

**Operational decisions — all closed (2026-10-18)**

- ~~Disposition policy for expired / near-expiry / damaged / quarantined stock.~~
  **Resolved (2026-10-18):** the policy is decided above — unusable stock is
  removed by a two-person `DECREASE` adjustment (`EXPIRY` / `DAMAGED` / `LOST` /
  `OTHER`), quarantine is still not a system state (a location is not a hold),
  and the states (quarantine / damaged / in-transit buckets, push expiry alerts,
  a disposal workflow) remain Phase 8 deliverables. Transfers between locations
  landed in Phase 8, but they do not create an in-transit or held state. The
  blocking dispensing safety is already in Phase 4. **Implemented:** a batch must
  not already be expired as of
  its received date (`occurred_at`, default now) — `StockBatchAlreadyExpired`,
  enforced in the request and the handler — so a *backdated* receipt is still
  allowed when the batch was valid on that date.
- ~~Procurement ownership and the Phase 7 bring-forward decision.~~ **Resolved
  (Phase 7 complete, 2026-10-18):** receiving starts from Inventory and consumes
  Procurement through a validated `ProcurementApi` selection; the PO lifecycle and
  the receipt reconciliation stay in Procurement.
- ~~Demo/test-data strategy.~~ **Resolved (2026-10-18):** the strategy is decided
  above — synthetic stock exists only in the `afyanova-demo` tenant, carries a
  `DEMO-`/`[DEMO]` convention, and is created only through `ReceiveStock` with an
  `OPENING_BALANCE` source and a demo-naming reason. A guarded one-command
  seeder is deliberately deferred.

**Deliverables**

- Approved permissions and role matrix, including the pharmacist's Inventory
  permission dependencies.
- Explicit initial-release and deferred-scope decisions.
- Defined request/response/error shapes for new HTTP adapters; documented
  source of truth for item/location/availability/batch data.
- Decision on the expired-stock safety blocker before expiry-tracked stock can
  be considered dispensable.

**Exit criteria:** Each operation has a named owner, permission, audit
expectation, domain command/query, and a written operational rule. No unsafe
expiry assumption remains hidden in the UI.

### Phase 1 — Inventory workspace foundation and master data

**Status: Complete for the initial workspace scope — type-neutral lookup, permission-controlled item/location creation, Carbon table search/filter, and safe active/inactive lifecycle controls are implemented. Contextual links from Pharmacy and Billing remain with their consumer phases.**

Build the canonical, **generic** Inventory workspace and the minimum capability
to identify items and storage locations. Medicine is the first configured
category and the first operational use case, not a special-case Inventory
implementation.

**Backend/presentation work**

- Implemented: authenticated Inventory route/controller, Inertia workspace,
  typed frontend route and permission helpers, and workspace navigation.
- Implemented: active `GetItems` and active-facility `GetStockLocations`
  reads; stock lookup is explicitly by selected item and location.
- Implemented: item catalogue search across code, name, category, and
  description; item-type filtering; location search by code/name and location
  type filtering. Search and filters use Carbon's table toolbar. Tracking
  flags, base unit, and active status are visible.
- Implemented: audited item creation through a domain command; item definitions
  are tenant-wide, and catalogue mutations are available only in General
  context to users with `inventory.items.manage`. Facility views read the
  shared active catalogue.
- Implemented: audited location creation through a domain command;
  Facility Admin is restricted to the active facility through
  `inventory.locations.manage`. Location codes and types use the domain model.
- Decision implemented: new or revised locations across facilities use only
  built-in `WAREHOUSE` and `STORE`, plus saved custom type codes; legacy
  `LOCATION`/`BIN` rows remain readable but cannot be newly created without
  first defining an authorized custom type.
- Implemented: custom location-type scope follows the working context, not the
  administrator's role name. General-context authorized managers create
  tenant-wide types; facility-context authorized managers create types for
  only the active facility. Custom types have a code/name catalog, are audited,
  and are available from the location selector only within their scope.
- Implemented: the location form accepts only built-in or saved type codes;
  type creation is separately permission-gated and rejects reserved built-ins
  and duplicate visible codes.
- Implemented: audited lifecycle commands and status controls for items and
  locations. Deactivation is blocked while any on-hand or reserved quantity
  remains; reactivation is blocked when the code has been reused by another
  active record. Catalog rows are locked during stock operations so a status
  change cannot race a receipt, reservation, or consumption. Inactive records
  are visible only to users with the matching management permission and are
  excluded from receipt choices. Do not implement generic table CRUD.
  Location hierarchy editing is not in the initial simple-store form.
- Implemented: item type/UOM and applicable batch/expiry tracking details are
  visible in the catalogue and during receipt. Serial-tracked items are blocked
  with an explanation; serial operations and unit conversion remain unavailable.
- Implemented (item-master "now" slice, `inventory-item-master-spec.md`): **Type,
  Category, and Base unit are server-authoritative controlled vocabularies**
  (`ItemType` / `ItemCategory` / `UnitOfMeasure`), validated on the HTTP request
  and asserted in the `Item` aggregate (so the console path is covered too), and
  surfaced as pickers. The Name field is presented as the **generic (INN) name**.
  A non-blocking **duplicate-name warning** appears in the Add-item panel. **Type
  and Base unit are immutable once the item has stock movements**
  (`InventoryItemReclassificationNotAllowed`, guarded via
  `StockLedgerReader::hasMovementsForItem`). No schema migration.
- Deferred to consumer phases: add links from Pharmacy and Billing to Inventory
  context without duplicating ownership.

**Tests**

- Implemented tests: authorized/unauthorized item and location creation,
  tracking-rule validation, audit records, role-scoped access, workspace page
  props, item/location search and type filters, inactive-record visibility,
  lifecycle audit and permissions, stock-blocked deactivation, and duplicate
  code conflicts during reactivation. Existing creation tests cover duplicate
  codes and facility isolation. `ItemMasterControlledVocabularyTest` covers the
  controlled vocabularies (HTTP + console rejection, case normalisation, exposed
  pickers) and the reclassification guardrails. The inventory-catalogue Vitest
  suite asserts the Add-item pickers are fed by the server vocabularies (with
  sensible defaults) and that the duplicate generic-name warning appears.

**Exit criteria:** An authorized user can find and safely administer generic
item definitions and facility locations through the canonical workspace.
Medicine is configured first; Pharmacy continues to consume only active
`MEDICATION` items from the same trusted catalogue.

### Phase 2 — Stock receiving and opening balances

**Status: Complete — the manual receipt path, its HTTP acceptance coverage, and
the Phase 0 receipt-source / opening-balance policy are all in place.
Purchase-order-linked receiving is deferred to Phase 7.**

Make it possible to populate the database with accountable, usable stock using
the existing `ReceiveStock` command.

**Frontend**

- Implemented: receive form for an active item, active-facility location,
  positive base-UOM quantity, receipt source, supplier reference, and
  occurrence time.
- Implemented: batch and required expiry collection for tracked items;
  manufacture date is available for batch-tracked items.
- Implemented/decided: opening stock uses an explicit `OPENING_BALANCE` source
  with an approved initial-stock procedure (Phase 0 policy), distinguished from
  routine `DONATION`/`OTHER` receipts.
- Implemented: `DONATION` and `OTHER` are available alongside
  `OPENING_BALANCE`; confirm this source policy in Phase 0 before operational
  rollout.
- If selecting a PO, use a validated Procurement selection/contract; do not
  trust a free-form PO identifier as proof of a valid PO. Otherwise defer PO
  linking to Phase 7.
- Implemented: backdated-reason validation (over 24 hours), future timestamp
  rejection, domain/validation feedback, and client-minted movement UUID plus
  idempotency key.
- Implemented: serial-tracked items are excluded from receiving until serial
  capture is supported. Unit conversion remains unavailable.

**Backend/presentation work**

- Implemented: thin HTTP adapter to `ReceiveStock`; actor is server-derived,
  and facility is resolved from the selected stock location/domain context.
- Implemented: `inventory.receipts.create` authorization, active facility
  location reads, input validation, and the domain command's audit/outbox and
  idempotency behavior.
- Decided: the receipt-source / opening-balance policy is recorded in Phase 0.
- Implemented: an already-expired batch at the receipt's effective date is
  rejected (`StockBatchAlreadyExpired`) in both the request and the handler,
  while a valid backdated receipt passes.

**Tests**

- Implemented tests: authorized/unauthorized workspace access, facility
  location options, successful movement/balance receipt, receipt permission,
  backdate reason, and serial-tracked item rejection. Existing domain tests
  cover batch/expiry requirements.
- Implemented (HTTP acceptance, `ReceiveStockHttpAcceptanceTest`): retired and
  unknown item, retired and unknown location, facility mismatch (a location
  outside the actor's facility), no partial writes on a failed receipt (a
  batch-tracked item missing its batch number), audit + reconciliation through
  the route, and transaction rollback of a failed receipt leaving the existing
  balance intact. Duplicate retry/idempotency is covered by the receipt-and-retry
  workspace test and the domain idempotency test.

**Exit criteria:** An authorized user can record opening or incoming stock and
see the resulting persisted movement and balance. Pharmacy can dispense from
that balance only after its own server-side checks.

### Phase 3 — Stock-on-hand, movement ledger, and adjustment controls

**Status: Complete — location-first stock-on-hand with inline batch detail and server-side search, sorting, and pagination, a read-only movement ledger (running balance and drift) for a selected item and location, a facility-wide adjustment worklist with request capture and approve/reject decisions, read-only facility-wide reservation visibility, and a facility-wide stock-on-hand read (`GetStockByFacility`) are all implemented.**

Provide inventory staff a reliable view of current stock and an audited
correction process.

**Implementation order.** Build each item as a thin adapter over the existing
command/query; add no domain logic in presentation. Every item ships with
allowed-and-denied path tests.

1. Movement ledger read adapter + ledger drawer over `GetStockLedger`
   (running balance, drift, source/reference, actor, occurred time).
   **Implemented:** read-only drawer on the workspace stock section
   (`?section=stock&item_id&location_id&view=ledger`), showing current on-hand,
   ledger sum, drift, per-movement running balance, batch, source/reference,
   reason, and occurred time. Actor is shown as the staff id only; name
   resolution is deferred. Covered by workspace and frontend tests.
2. Adjustments worklist read over `GetStockAdjustments` (`inventory.access`).
   **Implemented:** read-only worklist section
   (`?section=adjustments&adjustment_status=`), listing item, location,
   direction and quantity, reason, status, requested time, and decision
   (approver time or rejection reason) for the active facility. The status
   filter is server-side (validated to `PENDING_APPROVAL` / `APPROVED` /
   `REJECTED`, else all). Item and location names resolve from the already-loaded
   workspace props, falling back to the id. The worklist itself never edits
   stock; the request and decision controls (items 3–4) share the same section.
   Covered by workspace and frontend tests.
3. Adjustment request over `CreateStockAdjustment` (client-minted UUIDv7,
   idempotency key, batch by number for batch-tracked items,
   `inventory.adjustments.create`).
   **Implemented:** `POST /workspaces/inventory/adjustments` →
   `CreateStockAdjustmentController` + `CreateStockAdjustmentRequest`, with a
   "Request adjustment" panel on the adjustment worklist section (item,
   location, direction, quantity, reason code, notes, batch number when the item
   tracks batches). The client mints `adjustment_id` and `idempotency_key`
   (UUIDv7/`crypto.randomUUID`); the handler stays authoritative and the
   adjustment enters `PENDING_APPROVAL`. The request mirrors the handler's
   preconditions into form errors (batch number required for batch-tracked
   items; serial-tracked items deferred), validates the seeded reason codes, and
   is idempotent under replay. The action is gated by `canRequestAdjustment`
   (`inventory.adjustments.create`). Covered by workspace (allowed/denied,
   batch-required, reason-code, idempotency) and frontend tests.
4. Adjustment approve/reject over `ApproveStockAdjustment` /
   `RejectStockAdjustment` (`inventory.adjustments.approve`, separation of
   duties).
   **Implemented:** `POST /workspaces/inventory/adjustments/{id}/approve` and
   `/reject` → `ApproveStockAdjustmentController` / `RejectStockAdjustmentController`
   with matching requests (approve: `idempotency_key`; reject: required
   `reason` + `idempotency_key`). Approval posts the compensating movement and
   updates the balance server-side; rejection leaves stock unchanged. The
   worklist section shows an Actions column (pending rows only) with
   approve/reject for `inventory.adjustments.approve` holders, hides decisions on
   the viewer's own requests ("Awaiting another approver"), and collects the
   rejection note in a Carbon modal. Self-approval and lifecycle transitions are
   enforced by the handler regardless of the UI. Covered by workspace
   (approve, deny, self-approval, reject with/without note) and frontend tests.
5. Reservation read-only visibility over `SearchStockReservations`.
   **Implemented:** read-only worklist section
   (`?section=reservations&reservation_status=`), listing item, location,
   quantity, consumer (type + opaque ref), reason, hold-until, and lifecycle
   status for the active facility. The status filter is server-side (validated to
   `ACTIVE` / `FULFILLED` / `RELEASED` / `EXPIRED` / `CANCELLED`, else all) and
   the reader is facility-scoped. Names resolve from the already-loaded workspace
   props, falling back to the id. No reservation mutations are exposed — the
   lifecycle stays with its owning domains (Pharmacy fulfilment, expiry sweep).
   Covered by workspace (list, status filter, invalid-filter fallback, cross-
   facility isolation) and frontend tests.
6. Facility-wide stock-on-hand read. This was the only genuine backend read gap —
   `GetAvailableStock` is per-item, so a facility-wide listing needed a new
   reader/query.
   **Implemented:** new `GetStockByFacility` query + handler and
   `StockBalanceReader::forFacility` (Eloquent), returning paged
   `StockBalanceSummary` rows across every location of the active facility. The
   read-only section (`?section=stock-on-hand`) lists item, location, batch
   (number and expiry where present), on-hand, reserved, available, and UOM, with
   server-side search over item code/name and location code/name, an optional
   location filter, sorting, and pagination. The read runs only for its own
   section and is facility-scoped; available quantity here does not by itself
   confirm dispensing eligibility (see Phase 4). Covered by workspace (list,
   location filter, search, sort, cross-facility isolation, section gating) and
   frontend tests.

**Stock views**

- Implemented: location-first per-item totals with inline per-batch on-hand,
  reserved, available, batch number, and expiry details.
- Implemented: server-side search, sorting, and pagination for the
  location-first item list; only the current page's batch details are loaded.
- Implemented: facility stock-on-hand by item, location, and batch
  (`GetStockByFacility`), with on-hand, reserved, available, UOM, batch number,
  and expiry date where present.
- Implemented: server-side item/location search, an optional location filter,
  sorting, and pagination, with a clear zero-stock/empty state.
- Facility-level availability and location-level balance are not conflated: this
  view reads recorded balances across locations and is distinct from the
  item-scoped `GetAvailableStock` used for dispensing.
- Implemented: read-only movement ledger drawer for the selected item and
  location, with running balance, reconciliation drift, source/reference, batch,
  reason, and occurred time from authorized DTOs. Entries are append-only.
- Show reconciliation drift or a clear unavailable/error state where supported;
  never silently replace failed reads with zero.

**Adjustments**

- Request adjustment with direction, positive quantity, item, location, batch
  where required, reason code, and reason text.
- Separate approval/rejection worklist; respect separation of duties and
  prohibit self-approval.
- Display requested, approver, status, resulting movement, and rejection
  reason; refresh from backend state after actions.
- No direct balance edit. Approved adjustment is the only normal correction
  workflow; reversals follow the appropriate compensating-movement command.

**Reservation visibility**

- Surface active/terminal reservation status, item/location/batch, quantity,
  consumer, hold-until, and lifecycle outcome where operational staff need it.
- Do not expose Pharmacy-owned reservation mutations as general inventory
  edits. Add inventory-side release/cancel controls only if Phase 0 identifies
  an authorized operational use case and the corresponding commands/permissions
  are selected.

**Tests**

- Implemented: Inventory workspace Inertia props include location-first item
  totals, batches, and pagination metadata; frontend tests render the Carbon
  location stock table, expand batch details, and exercise server query controls.
- Implemented: movement ledger props (current on-hand, ledger sum, drift,
  entries) for a selected item and location; the drawer stays hidden until a
  ledger is loaded; tests assert the ledger read adapter, the no-selection
  null state, running balance/drift rendering, and the zero-drift vs.
  reconciliation-required states.
- Implemented: adjustment worklist props (`adjustments`,
  `adjustmentStatusFilter`); the workspace renders the worklist table with
  status tags and decision, and the status filter issues a server query bounded
  by `only`. Tests assert the worklist read adapter, the allowed status-filter
  values, the invalid-filter fallback to all, and the empty state.
- Implemented: adjustment request capture — the create route accepts the client
  ids plus item/location/direction/quantity/reason/batch, is idempotent under
  replay, denies without `inventory.adjustments.create`, requires an existing
  batch number for batch-tracked items, and rejects unknown reason codes; the
  frontend test opens/closes the request drawer and hides it without permission.
- Implemented: adjustment decisions — approve posts the movement and updates the
  balance, approve is denied without `inventory.adjustments.approve` and for the
  requester (self-approval), and reject requires a decision note and leaves stock
  unchanged; frontend tests assert the approve/reject affordances appear only to
  other approvers, approve posts to the approve route, and the reject modal
  requires the note. The worklist table uses the Carbon table toolbar idiom
  (search + status overflow-menu filter + primary action) and the shared
  `EmptyState`, not a bare form control or header row. The reservation worklist
  reuses the same toolbar idiom (search + status overflow-menu filter), shows the
  consumer, hold-until, and lifecycle tag, and exposes no mutation controls.
- Implemented: facility-wide stock-on-hand props (`facilityStock`,
  `facilityStockPagination`); the workspace renders the Carbon table with
  search, a location overflow-menu filter, sorting, and pagination, and issues
  the server query bounded by `only`. Tests assert the facility read (list,
  location filter, search, sort, cross-facility isolation, section gating) and
  the frontend table and filter forwarding.
- Balance/ledger agreement, batch grouping, permission/scope, adjustments
  request/approve/reject, separation of duties, concurrency and stale UI,
  terminal states, reservations and empty/error states.

**Exit criteria:** Users can answer “what stock is where, what is available,
and why did it change?” and request/approve corrections without bypassing the
ledger.

### Phase 4 — Pharmacy stock-aware dispensing readiness

**Status: Complete — backend expiry safety, the stock-aware dispense UI, and
electronic prescription acceptance (items 1–6).**

Improve the existing dispense journey rather than rebuilding it.

**Implementation order and guardrail (satisfied).** Backend items 1–3 landed
before the dispense UI (4–5). A frontend-only warning is not an expiry control.

1. Expiry exclusion in `EloquentStockBalanceReader::availableForItem` and the
   per-location summaries (currently all balances are summed with no expiry
   filter), plus the agreed damaged/quarantined handling.
   **Implemented (expiry):** `Batch::isExpired()` is the single rule — a batch is
   usable through the END of its `expiry_date` and expired from the next day;
   `EloquentStockBalanceReader::availableForItem` sums ELIGIBLE rows only, and
   each `StockBalanceSummary` carries an `eligible` flag. **Deferred
   (damaged/quarantined):** no batch status (quarantine / recall) exists in the
   model yet — that lifecycle arrives with Inv.6 / Phase 8 — so no such marker is
   fabricated; when it lands it extends `Batch::isExpired()` rather than adding a
   parallel filter.
2. A per-location, per-batch availability contract for the selected item,
   carrying expiry and an eligibility flag.
   **Implemented:** `AvailableStockSummary::byLocation` (per-batch
   `StockBalanceSummary` rows) carries `expiryDate` and `eligible`, and the
   facility totals are the sum of eligible rows only. `byLocation` still lists
   every batch (flagged), so the physical picture is not hidden. The dispense UI
   (items 4–5) is the consumer.
3. A consume-time expiry gate inside the reserve/consume/dispense transaction.
   **Implemented:** `ReserveStockHandler` and `RecordConsumptionHandler` refuse
   an expired batch after resolving it (`StockBatchExpired`, HTTP 409), so a
   `Dispense` that selects an expired batch rolls back whole.
   `ReverseConsumption` is unaffected — a reversal of a prior consumption must
   remain possible.
4. Explicit location and batch selection in the dispense UI, with
   per-location/per-batch availability; replace the single facility-wide
   `availableQty` display.
   **Implemented:** the workspace props now carry `availabilityStatus` and
   `stockByLocation` (per-location, per-batch `RxStockEntry` rows with
   `onHand` / `reserved` / `available` / `uom` / `expiryDate` / `eligible`),
   replacing `availableQty`. `RxItemOption` carries `trackBatch`; a
   batch-tracked item renders an explicit Batch select (expired batches shown
   disabled) and cannot be submitted without an expiry-eligible batch. The panel
   shows the SELECTED location's eligible on-hand / reserved / available — never
   a facility sum.
5. Truthful UI states: zero available vs. availability-not-checked vs.
   read-failed; never turn a caught read error into zero or success.
   **Implemented:** the presenter maps the read to `AVAILABLE` / `FAILED` /
   `NOT_CHECKED` — a thrown `GetAvailableStock` becomes `FAILED`, never zero —
   and the panel renders four distinct states: loading (“Checking stock…”),
   read-failed (“Stock could not be checked…”), zero eligible (“No eligible
   stock at this location”, noting expired exclusion), and positive
   availability.
6. Electronic prescription acceptance route/permissioned action, or documented
   acceptance point.
   **Implemented (route + permissioned action + UI):**
   `POST /workspaces/pharmacy/prescriptions/{prescriptionId}/accept`
   (`pharmacy.prescriptions.accept`) dispatches the existing
   `AcceptPrescription` (RECEIVED → ACTIVE; the handler fetches the prescribing
   decision through the PROTECTED `ClinicalApi` under the acting pharmacist's
   own `clinical.prescribing.read`, so no clinical payload crosses this route).
   The prescription detail panel shows **Accept prescription** for a `RECEIVED`
   `ELECTRONIC` prescription when the pharmacist holds the key; it is never
   offered for paper (typed and activated at transcribe time via
   `TranscriptionController`) or for an already-active prescription.

- Keep Pharmacy responsible for dispense selection and action; Inventory
  remains responsible for stock facts and receipt/adjustment.
- Return/show stock availability by selected location and batch, not only
  facility-wide total. For tracked items, make batch/expiry selection explicit.
  **Implemented** — the panel reads the selected location's rows and, for
  `trackBatch` items, requires an explicit eligible batch.
- Include available versus reserved quantities and UOM. Do not infer a
  location's availability from a facility sum. **Implemented** — the panel shows
  the selected location's on-hand / reserved / available and the item UOM.
- Implemented: backend-authoritative exclusion of expired stock before it is
  eligible for dispensing — the availability reader sums eligible rows only and
  the reserve/consume handlers refuse an expired batch (`StockBatchExpired`).
  This is a server-side gate plus tests, not a frontend-only warning.
  Damaged/quarantined handling waits on the Inv.6 batch-status lifecycle.
- Keep server-side revalidation, reservation, consumption, and dispensing in
  one transaction. Handle insufficient stock, stale balance, wrong facility,
  wrong medicine, batch tracking, authorization, and concurrent dispensing as
  explicit errors.
- Distinguish “zero available,” “availability not checked,” and “read failed”
  in the page contract; do not turn a caught read error into an apparent
  success or zero. **Implemented** — `availabilityStatus` carries the three
  states and the panel renders each distinctly.
- Preserve the currently implemented Pharmacy order state matrix, quantity
  ceiling behavior, reversal, pickup lifecycle, and audit trail.
- Resolve the existing electronic prescription acceptance gap separately:
  add the backend route and permissioned action or document where acceptance
  occurs; do not imply the UI can accept electronic scripts today.
  **Resolved** — the route and permissioned action exist (item 6) and the panel
  accepts a `RECEIVED` electronic prescription in place; the snapshot is fetched
  server-side, so the UI still never authors clinical content.

**Tests**

- Implemented: expiry exclusion and eligibility — `GetAvailableStock` totals
  exclude an expired batch and flag it ineligible while a usable batch stays
  eligible; `ReserveStock` / `RecordConsumption` refuse an expired batch
  (`StockBatchExpired`) with no movement or reservation written; a Pharmacy
  `Dispense` of an expired-only item is `DispensingStockUnavailable`, and a
  chosen expired batch is blocked by the consume-time gate even when eligible
  stock exists elsewhere in the facility (the whole transaction rolls back).
- Implemented: dispense-UI contract — the workspace exposes
  `availabilityStatus` plus per-location, per-batch `stockByLocation` (an
  expired batch is `eligible: false`); the panel distinguishes loading,
  read-failed, zero eligible, and positive availability per SELECTED location,
  and batch-tracked items require an explicit expiry-eligible batch before the
  action enables.
- Selected location/batch availability matches dispense eligibility; no-stock
  and insufficient-location stock errors; concurrent requests cannot overspend;
  whole dispense transaction rolls back if stock consumption fails; existing
  Pharmacy contract tests remain valid.
- Implemented: electronic acceptance — the HTTP route moves a `RECEIVED`
  electronic prescription to `ACTIVE`; a repeat accept is refused on the
  `prescription` error bag, and a user without `pharmacy.prescriptions.accept`
  is forbidden before dispatch. The UI test asserts the accept action posts to
  the accept route and is absent for an active prescription and without the
  permission.

**Exit criteria:** The pharmacist sees the actual eligible stock at the
selected source and a successful dispense atomically deducts that same stock.

### Phase 5 — Billing item mapping and charge resolution

**Status: Complete.** The Billing price list (`Billing/Catalog`), the
medication → service mapping worklist, and the pending-charge resolution path
all ship; the catalog route is no longer a missing page.

Complete the setup that makes medicine dispensing chargeable and diagnosable.

- Add item-to-billing-service mapping to the canonical Billing catalog, not
  Inventory or Pharmacy.
- Reuse backend commands/queries: map, inspect active/history mappings, and
  deactivate. Restrict mapping to active billing services and
  `billing.catalog.manage`; reads must respect Billing access.
- Present item identity from Inventory through a safe contract, and service
  identity/category/status/tariff from Billing. Avoid exposing raw foreign
  tables or requiring manual UUID entry.
- Clearly show whether each medication is mapped, service active, and tariff
  effective at the relevant facility/date/currency.
- Provide a pending billable event worklist and reason states such as
  `ITEM_UNMAPPED`, `SERVICE_UNPRICED`, and `SERVICE_INACTIVE`. Support the
  existing permitted retry path with `billing.charges.manage`.
- Explain that mapping automatically retries unmapped-item events, but a
  tariff change does not automatically retry unpriced events; provide an
  explicit retry path and feedback.
- Maintain effective tariff history and do not overwrite historical prices.

**Delivered.** `resources/js/pages/Billing/Catalog.tsx` (plus
`resources/js/features/billing/**`) renders the services/tariff price list
(category/status filters, the selected service's facility/default tariff,
immutable tariff history, and add-service / set-price / end-price /
activate-deactivate panels), the medication mapping worklist (Inventory item
identity plus mapped service identity/status/tariff, map/change/remove, and
per-item mapping history), and the pending-charge worklist with the permitted
retry action. The page reads the medication list through a new narrow
`InventoryApi::getItems(itemType, active)` method (unprotected item identity,
the `getItem` precedent) and never touches a foreign table or asks for a manual
UUID. The mapping and status/tariff writers reuse `MapItemToBillingService`,
`DeactivateItemBillingMap`, and the existing catalog commands, gated to
`billing.catalog.manage`; the worklist read is gated to `billing.access`.
`EloquentItemBillingMapReader::search` now tie-breaks newest-first on the UUIDv7
id, so the history rail stays truthful when two mappings land in the same
second.

**Tests**

- Mapping/unmapping, remapping history, inactive service rejection, permission
  denial, pending event recovery, tariff-effective and unpriced states,
  idempotent charge capture, and audit.
- `tests/Feature/Domains/Billing/Presentation/CatalogPresentationTest.php`
  (price list, tariffs, retry, and mapping/mapping-history/inactive-service/
  permission-denial) and `resources/js/test/billing-catalog.test.tsx`
  (services table, filters, mapping panel, remove, retry, and denied states).

**Exit criteria:** Every billable medicine can be mapped to an active service
with an effective tariff, and staff can resolve exceptions without inventing
charges or silently losing a dispensing event.

### Phase 6 — End-to-end financial clearance and pickup

**Status: Complete.** The pickup gate and the Billing cashier/catalog/coverage
surfaces already existed; this phase verified the complete journey end to end
against real stock and real mappings and added the missing integration tests.
No production code changed — the seam already composed correctly.

- Confirm every dispensing generates exactly one billable event/charge, or a
  visible pending exception with a recovery route.
- Verify dedicated dispensing invoice requirements and invoice creation,
  issue, payment, allocation, and coverage behavior in existing Billing
  workflows.
- Verify pickup remains blocked until the server confirms the applicable
  self-pay or insurance clearance. Do not reimplement Billing calculations in
  Pharmacy.
- Ensure reversal behavior correctly reverses or flags related financial
  records according to existing Billing policy; test late pickup and reversal
  interactions.
- Test outbox delays/retries and show truthful charge status; do not block
  stock dispensing based on an assumed synchronous charge unless the
  authoritative backend contract requires that.
- Verify role permissions across pharmacist, cashier, Billing catalog
  manager, and approver.

**Verified.** The whole journey is demonstrated by
`tests/Feature/Domains/Billing/PharmacyClearanceJourneyTest.php`, which drives
the real path (no mocked `BillingApi`): a stocked MEDICATION item → a real
Pharmacy `Dispense` (writes `MedicationDispensed`) → the outbox relay and the
real `OnMedicationDispensed` listener → Billing charge capture → a dedicated
invoice/issue/payment/allocation or a confirmed coverage → the real
`BillingApi::dispensingClearedForHandover` gate → a real `RecordPickup`. The
piece-level suites (`ChargeCaptureTest`, `DispensingHandoverClearanceTest`,
`PickupTest`) already proved the parts; this file proves they compose.

Findings (behaviour confirmed, not changed):

- **Charge capture is asynchronous.** `Dispense` commits stock and the pickup
  row and writes `MedicationDispensed` to the outbox; the charge is captured
  when the relay runs the listener. Before the relay the dispensing is complete,
  the pickup is `READY_FOR_PICKUP`, and handover is blocked (recoverable) —
  never a dispense failure. Re-delivery of the same event never double-charges.
- **Exactly-once capture with a visible exception path.** A mapped and priced
  item yields exactly one `POSTED` charge; an unmapped item is a `PENDING`
  `ITEM_UNMAPPED` billable event visible in the pending-charge worklist, and
  mapping the item (with a tariff) sweeps it to `CHARGED` — no silent loss.
- **Handover needs a *dedicated* one-charge invoice.** Because payment
  allocations are invoice-level, `dispensingClearedForHandover` requires the
  invoice to carry exactly one active, non-reversed line; a shared invoice that
  is fully paid still blocks every dispensing on it (the cashier UI already
  tells staff to "invoice the dispensing charge by itself").
- **Self-pay vs insurance.** Self-pay clears when active payment allocations
  cover the net; insurance clears only with a `CONFIRMED` coverage whose line is
  `COVERED`/`PARTIALLY_COVERED` and the patient responsibility paid. `UNKNOWN`
  coverage never clears, even fully paid. A write-off/adjustment is a Billing
  settlement but does not by itself satisfy the paid gate — its dual-control
  approver boundary is verified in `WriteOffTest`/`AdjustmentTest`.
- **Reversal is financially truthful.** Reversing an un-picked-up dispensing
  cancels the pickup, and the relayed `MedicationDispensingReversed` reverses
  the charge (the ledger nets to zero); a picked-up dispensing cannot be
  reversed.
- **Role boundaries hold.** `pharmacy.pickup.record` gates pickup,
  `billing.payments.manage` gates payment, and `billing.catalog.manage` gates
  mapping; the approver separation of duties is covered by the write-off and
  adjustment suites.

**Tests**

- `tests/Feature/Domains/Billing/PharmacyClearanceJourneyTest.php` — the
  real-stock, real-mapping journey plus asynchronous-pending visibility,
  re-delivery idempotency, the dedicated-invoice rule, `CONFIRMED` vs `UNKNOWN`
  coverage, reversal and late-pickup/handover interaction, and the
  pharmacist/cashier/catalog role boundaries (9 tests).

**Exit criteria:** Demonstrated journey from stocked item through dispense,
charge, dedicated invoice/payment or confirmed coverage, and recorded pickup;
all errors and asynchronous pending states are recoverable and visible. **Met**
— see the journey file above.

### Phase 7 — Procurement receiving integration UI

**Status: Complete.**

**Design decision (the phase's own fork).** The workflow starts from
**Inventory's receiving flow**, not a Procurement workspace. Receiving is where
stock lives, and Procurement publishes no inbound write: `RecordPurchaseOrderReceipt`
is a SYSTEM command dispatched only by `OnStockReceived`, so the PO projection is
already automatic and idempotent. Standing up a Procurement workspace would have
duplicated the receipt form and split the operation across two modules; instead
Inventory offers the PO as a **validated selection** read from Procurement. The
PO lifecycle and the receipt reconciliation stay in Procurement; stock movement
creation stays in Inventory.

- **`ProcurementApi` — the first cross-domain Procurement surface** (IC §2.1;
  `app/Domains/Procurement/Contracts/ProcurementApi.php`). One read,
  `getOpenPurchaseOrders(facilityId)`, delegating to the existing
  `GetOpenPurchaseOrders` `#[ProtectedQuery]` (so the caller's own
  `procurement.access` runs) and reducing each PO to its OPEN STOCK lines as
  narrow `OpenPurchaseOrderRef` / `OpenPurchaseOrderLineRef` DTOs — no supplier,
  price, notes or amendment history crosses the boundary.
- **Validated PO selection on the receipt.** `ReceiveStockRequest` now admits
  `source_type=PROCUREMENT`, which **requires** a `po_ref` resolving to an open
  PO at the active facility with `item_id` on an OPEN STOCK line; a `po_ref` is
  **prohibited** on every other source because `OnStockReceived` reconciles on
  `poRef` alone. The domain stays tolerant (a receipt is inventory truth — an
  unmatched `poRef` never blocks the movement); this is the receiving surface's
  gate, not a domain rule. `ReceiveStockController` passes `po_ref` through.
- **Permission.** `FacilityAdminRoleDefinition` gains `procurement.access` — the
  receiving actor must be able to read the order they receive against. The role
  definition is the source of truth; an already-provisioned tenant is reconciled
  with `php artisan identity:sync-role-permissions` (additive, audited as
  `identity.role.definition_reconciled`) — the `queue:sync-role-permissions` /
  `clinical:sync-role-permissions` precedent, needed because a real tenant cannot
  be re-seeded. The demo tenant (`afyanova-demo`) was reconciled this way.
- **Inventory workspace props.** `openPurchaseOrders` is loaded through the
  contract only when the user can receive; a user without `procurement.access`
  (or a facility with no open POs) gets `[]` and the picker is simply absent. The
  PO picker restricts the catalogue choice to the order's open lines and shows
  ordered / received / remaining; choosing a PO replaces the manual source picker.
- **Over-receipt is deliberately permitted** — the pre-existing explicit domain
  rule (`PurchaseOrderReceiptTest` pins "over-receipt is recorded faithfully").
  Partial and final receipts roll the PO to `PARTIALLY_RECEIVED` /
  `FULLY_RECEIVED`; redelivery cannot double-count (event-id + unique
  `source_event_id`).
- For an initial release without POs, `DONATION`, `OTHER` and `OPENING_BALANCE`
  remain available under Phase 0 policy; they are never represented as PO
  receipts.

**Deferred (not needed for go-live):** a Procurement-side PO worklist/receiving
page; receipt-discrepancy capture; an asynchronous-projection state banner.

**Tests**

- `tests/Feature/Domains/Procurement/ProcurementApiTest.php` (3) — open-PO
  reduction to OPEN STOCK lines with a computed remaining, the SERVICE-line
  exclusion, partial-receipt remaining, full-receipt drop-out, and the
  no-`procurement.access` denial.
- `tests/Feature/Domains/Inventory/ReceiveAgainstPurchaseOrderHttpTest.php` (6)
  — PO receipt writes with `po_ref` and reconciles through the relay, unknown
  PO / off-order item / missing `po_ref` / `po_ref` on a non-PO source / no
  procurement scope are all rejected with no writes.
- `tests/Feature/Domains/Procurement/PurchaseOrderReceiptTest.php` — the
  pre-existing partial/final/unmatched/item-mismatch/redelivery/status-transition
  coverage that this phase builds on.

**Exit criteria:** A valid PO can be received into Inventory once, partial
receipts reconcile correctly, and redelivery cannot double-count stock or PO
quantity. **Met** — see the two files above.

### Phase 8 — Operational hardening and selected extensions

**Status: In progress; select only with an owner, backend contract, and need.**

**Landed (expiry worklist, first slice):** the Inventory workspace now has an
**Expiry** section (`?section=expiry`) listing every batch with stock on hand
whose expiry date is inside the decided **90-day** window, soonest first, with
already-expired batches first and exact day counts (a batch remains usable
through the end of its expiry date). It is facility-scoped and read-only, and
it adds **no new write path**: "Remove batch" opens a panel that pre-fills the
existing two-person `DECREASE` adjustment (`reason_code = EXPIRY`, the full
remaining batch quantity, and an optional note for the destruction method and
witness). Stock only moves when a second authorised person approves the request
in Adjustments; the same batch is then marked "Removal requested" so the removal
cannot be raised twice from this screen. The optional note is where the
controlled-drug witness is recorded today — it is **guidance, not an enforced
field**, because the item master has no controlled-item flag yet.

**Landed (controlled-medicine witness, second slice):** the item master now
carries **`is_controlled`** (set when defining or revising an item, via the
screen or `inventory:define-item`). When an item is controlled, a `DECREASE`
stock adjustment — disposal, loss, damage, count variance, correction — is
**refused without a named witness** (`ControlledStockWitnessRequired`); the
witness is stored on the adjustment row and shown in the Adjustments worklist.
The rule is enforced in the domain, not just the form: the form mirrors it into
a field error, and the aggregate/handler refuses it even when the form is
bypassed. Raising controlled stock, and every movement of an uncontrolled item,
is unaffected. The expiry removal panel and the adjustment panel ask for the
witness when the item is controlled, and the Expiry worklist marks controlled
batches.

**Landed (low-stock alerts, third slice):** the Inventory workspace now has a
**Running low** section (`?section=low-stock`) listing every item whose usable
on-hand quantity is **at or below** the minimum set for the current facility,
worst first (out of stock, then the largest shortfall), with the exact amount to
order. The minimum is **per item PER FACILITY**, not on the shared item master:
the same item is reordered at totally different scales at a dispensary and a
hospital (the approval-limit reasoning). An item with no minimum is **not
tracked**, so an untouched catalogue never raises false alarms; setting `0`
clears the level and the audit entry keeps the history. Quantities are base
units (CAPSULE, not BOX); the item's pack size is shown beside the level as a
counting help, and partial packs are normal. On-hand counts **usable** stock
only: expired batches are excluded by the same `Batch::isExpired()` rule the
dispense gate uses and are reported separately ("+ 10 expired"), so a
seemingly stocked item is never unexplained. The section is read-only with
respect to stock: ordering still happens in Procurement, and nothing here edits
a balance. Setting or clearing a level needs `inventory.items.manage` **in a
facility context**; reading needs `inventory.access`. The write is a domain
command (`SetItemLevel`) with an audit record.

**Landed (stock transfers, fourth slice):** stock can now be **moved between
two locations of the same facility** — main store to pharmacy counter, or a ward
returning what it did not use — from a new **Transfers** section
(`?section=transfers`) and a "Move stock" panel. A transfer is one document
(human number `TRF-%08d`, reason, who, when) with **two ledger sides** written in
one transaction: `TRANSFER_OUT` at the source and `TRANSFER_IN` at the
destination, both carrying the transfer id as `source_ref`, so the existing
`balance = Σ movements` reconciliation still holds per location. The facility
total never changes and nothing is gained or lost, so no second approver is
needed. Rules: the two locations must differ and belong to the same facility
(cross-facility movement is refused); the source must have the quantity
**available** — reserved stock stays where it is reserved; a batch-tracked item
names a batch that exists and is not expired (expired stock still leaves through
the two-person `DECREASE`, not a transfer). The source is chosen first and the
server sends the stock actually held there (item, batches, expiry, available),
so the panel never asks for a batch blindly; quantities are base units, with the
pack shown as a counting help only. Writes need `inventory.transfers.create`
(Facility Admin and Pharmacist roles); reads need `inventory.access`; the command
is audited as `inventory.stock_transferred`. **Still open:** cross-facility
transfers, and moving stock into quarantine (batch status + quarantine location
still do not exist).

**Landed (stock counts, fifth slice):** a physical count can now be taken at
**one location** from a new **Stock counts** section (`?section=counts`), and
worked on a count sheet panel. Starting a count takes a **fixed snapshot** of
what the system believes is at that location, one row per (item, batch) —
`system_qty` never moves afterwards. The counter writes down what they find; a
blank box means **not counted yet**, while `0` is a real count (the shelf is
empty), and the difference (`counted − system`) is calculated for the screen
and never stored. A line the system did not list can be added when something is
found on the shelf; it snapshots the current on-hand (usually 0), and a
batch-tracked item must name a batch that already exists — a count never creates
a batch, and it cannot move stock between batches. Only **one count may be open
at a location at a time**, checked by the server and backed by a partial unique
index, so two people cannot walk the same shelf into two sheets. A count can be **completed** (kept as a record, even with uncounted
lines — an honest "not counted" beats a false zero) or **discarded**; both
freeze the sheet, and the location is then free for the next count. Numbers are
`CNT-%08d` per tenant. **A count never edits a stock balance directly.** On
completion, each non-zero
variance now raises its own pending `CreateStockAdjustment`
(`reason_code = COUNT_VARIANCE`) for a second person to approve. The completion
flow guards against a stale stock snapshot; approval rechecks that a decrease
will not make stock negative, and a controlled-stock decrease requires a named
witness. Draft counts can be submitted with completion so the sheet and its
adjustments cannot disagree. Writes need `inventory.counts.manage` (Facility
Admin and Pharmacist roles), reads need `inventory.access`, and the lifecycle
is audited as `inventory.stock_count_started` / `_recorded` / `_completed` /
`_discarded`.

**Step 2 (variance adjustments): complete.** The count and variance-adjustment
flow is implemented and covered by Inventory feature and UI tests.

**Still open in this area:** a real disposal record (method, and a witness that
is a linked staff identity rather than a typed name) alongside the free-text
note; a controlled-substance **schedule** value (the flag says "witness needed",
not "which class"); batch status + quarantine location; near-expiry **alerts**
(this worklist is pull, not push); and recall support.

- Expiry reporting/near-expiry alerts and expired-stock quarantine or
  disposition, beyond the blocking dispensing safety behavior in Phase 4 — the
  deliverables that implement the Phase 0 disposition policy above.
- Reorder/safety stock configuration beyond the per-facility minimum level
  above (e.g. a suggested order quantity or supplier lead time), and
  **push** low-stock alerts (the Running low list is pull, like Expiry).
- Unit conversions and package-level dispensing, if operationally required.
- Item-master extension (Tanzania fit — recorded in
  `inventory-item-master-spec.md` §7): the NEMLIT pharmacological-group list and
  `on_nemlit` / `aware_group` flags; NHIF benefit-package item-code mapping
  (with Billing); the pack→unit conversion factor wired through
  receiving/ledger/costing; and a TMDA/NEMLIT catalogue seed import.
- Serial-number tracking and serial operations.
- Transfers between locations/facilities and central/organization-wide
  warehouses; the present location model is facility-scoped.
- Quarantined, damaged, in-transit, expired, return, disposal and write-off
  buckets.
- Broader procurement lifecycle UI (suppliers, requisitions, RFQs, contracts,
  purchase orders) if the business needs procurement authoring, not only PO
  receiving.
- Reporting/export and inventory valuation, if approved as separate domain
  capabilities.

For every selected extension, define backend owner, permission, lifecycle,
auditability, effective dates, migration/data backfill, failure behavior, and
tests before frontend work.

**Exit criteria:** Each selected extension has an explicit scope and owner;
deferred capabilities remain clearly labelled as unavailable rather than
being implied by catalogue fields.

## 6. Cross-phase acceptance checklist

- [ ] Workspace and route are canonical, authenticated, and authorized.
- [ ] Tenant and facility scope are enforced server-side; client-supplied
      facility IDs are not trusted.
- [ ] Every write uses a domain command, validated request, audit, and
      idempotency strategy appropriate to the operation.
- [ ] Item, location, batch, UOM, tracking flags, and source are from trusted
      domain data or constrained input.
- [ ] Stock quantity changes only through ledger-backed domain operations.
- [ ] Available stock is correctly distinguished by facility, location, batch,
      reservation, and expiry eligibility.
- [ ] No broad catch, silent fallback, or success-shaped error is introduced.
- [ ] Frontend mutation state reconciles from server truth; stale/concurrent
      writes show actionable errors.
- [ ] Permissions and separation of duties are tested at each operation.
- [ ] Empty, loading, validation, authorization, not-found, concurrency,
      domain-conflict, and asynchronous-pending states are represented.
- [ ] Generated TypeScript props and route helpers match the server contract.
- [ ] Cross-domain data flows use published APIs/events, not direct foreign
      table access.
- [ ] Feature and contract tests cover both allowed and denied paths.
- [ ] A fresh development/test environment has a documented setup path and
      visibly synthetic test stock, never confused with production stock.

## 7. Recommended next step

The Inventory feature work discussed in this plan is implemented, including
stock-count variance adjustments and section-scoped loading. The next
recommended work is an end-to-end Procurement readiness check, not another
Inventory feature: finish and verify requisition pack-unit support, then walk
through a pack-based request, approval by a different user, purchase-order
creation with the entered unit and pack size preserved, and receipt into
Inventory with the resulting stock reconciled in base units. This should
exercise the existing permission, separation-of-duties, audit, and
cross-domain contracts as one user journey.

Optional Inventory extensions remain deferred until there is an operational
owner and need. In particular, fetching open purchase orders only when the
Receive Stock panel opens is a possible performance refinement, not required
for the section-loading work to be complete. The facility-context rules and
inventory-specific changes are tracked in
[`facility-context-scope-alignment-plan.md`](./facility-context-scope-alignment-plan.md).

**Phase 3 is complete.** The adjustment workflow (worklist read, request
capture, and approve/reject), **reservation visibility** as a read-only
facility-wide worklist over `SearchStockReservations`, and the **facility-wide
stock-on-hand read** over the new `GetStockByFacility` query all sit on top of
the location-first stock lookup and movement ledger, reusing the existing
`GetStockAdjustments`, `CreateStockAdjustment`, `ApproveStockAdjustment`,
`RejectStockAdjustment`, and `SearchStockReservations` contracts through thin
HTTP adapters, plus the one new read port method
(`StockBalanceReader::forFacility`). Phase 1 catalogue
lifecycle is complete and is not a prerequisite for this work. **Phase 4 is
complete** — expiry exclusion, the per-location/per-batch eligibility contract,
the consume-time expiry gate, the stock-aware dispense UI (explicit
location/batch selection with truthful availability states), and electronic
prescription acceptance (item 6: the accept route plus the panel action).
**Phase 5 is complete** — the Billing price-list page (services, tariffs, and
immutable history), the medication → service mapping worklist (reading item
identity through the new `InventoryApi::getItems`), and the pending-charge
worklist with the permitted retry. **Phase 6 is complete** — the full
receive → dispense → charge → dedicated invoice/payment (or confirmed coverage)
→ pickup journey is verified end to end against real stock and mappings (see
`PharmacyClearanceJourneyTest`), with no production change required. Bring
Phase 7 forward only if the first operating facility must receive against
purchase orders.

**Phase 1 is complete**, including the item-master "now" slice: Type / Category
/ Base unit are now server-authoritative controlled vocabularies (validated on
the HTTP request and asserted in the `Item` aggregate), the catalogue Name field
is the generic (INN) name, the Add-item panel warns on a duplicate name, and
Type / Base unit are immutable once the item has stock movements. The slice
required no schema migration and does not disturb Phase 2 receiving. The
Tanzania-fit decisions and the deferred Inv.0/Phase 8 item-master work are
recorded in `inventory-item-master-spec.md`.

**Phase 2 is complete.** The HTTP adapter's remaining edges (retired/unknown item
and location, facility mismatch, no partial writes, audit + reconciliation, and
rollback) are covered by `ReceiveStockHttpAcceptanceTest`, and the Phase 0
receipt-source / opening-balance policy is recorded above. Purchase-order-linked
receiving remains deferred to Phase 7.

Phase 3 implementation order (each item ships with allowed and denied path
tests): (1) **done** — ledger read adapter and ledger drawer; (2) **done** —
adjustments worklist read; (3) **done** — adjustment request; (4) **done** —
adjustment approve/reject; (5) **done** — reservation read-only visibility; (6)
**done** — facility-wide stock-on-hand read (`GetStockByFacility` plus
`StockBalanceReader::forFacility`, the one new backend read this phase
required). **Phase 4 is done (items 1–6)** — expiry exclusion in
`EloquentStockBalanceReader::availableForItem`, a per-location/per-batch
availability contract, a consume-time expiry gate, the stock-aware dispense UI,
and electronic prescription acceptance (the accept route plus the panel action).
**Phase 5 is done** — the Billing price-list page and tariff history, the
medication → service mapping worklist (the new `InventoryApi::getItems` read),
and the pending-charge worklist with the permitted retry. **Phase 6 is done** —
the receive → dispense → charge → dedicated invoice/payment or confirmed
coverage → pickup journey is verified end to end against real stock and
mappings, including the asynchronous charge, the pending `ITEM_UNMAPPED`
recovery, the dedicated one-charge invoice rule, and reversal/handover.

## 8. Definition of roadmap completion

The initial roadmap is complete when an authorized team can set up the
medicine/location catalogue, enter auditable stock, inspect stock and
movements, dispense eligible stock from the correct location/batch, resolve
the resulting billing event, settle the dedicated bill or confirm coverage,
and record pickup—with documented permission boundaries and tested failure
paths. Deferred capabilities in Phase 8 are not prerequisites unless Phase 0
decisions make them operationally mandatory.
