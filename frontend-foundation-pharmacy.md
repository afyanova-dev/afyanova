# Pharmacy Frontend Foundation

## 1. Purpose

This document defines the frontend architecture and implementation boundaries
for the **Pharmacy** workspace, based on the currently verified backend
capabilities (`app/Domains/Pharmacy/`).

The goal is to close the clinical loop — Nurse Station / Clinician produce
prescriptions that currently dead-end — without duplicating clinical
prescribing, queue management, inventory administration, or billing.

```text
Patient → Appointment → Arrival → Check-in → Queue → Service
                                                          ↓
                                                   Prescription
                                                          ↓
                                                  Pharmacy order
                                                          ↓
                                                     Dispensing
                                                          ↓
                                                       Pickup
```

Mental model:

```text
Patients      = WHO?
Appointments  = WHEN?
Front Desk    = ARRIVED?
Queues        = WHERE?
Service       = WHAT IS BEING DONE?
Pharmacy      = WHAT WAS GIVEN TO TAKE AWAY?
```

---

## 2. Canonical Workspace

The verified backend serves one Inertia page:

```text
GET /workspaces/pharmacy → Pharmacy/Index
```

There are no REST show routes. Detail is **URL-owned state** on the same
page (backend `PharmacyController`, `PharmacyDashboardProps` + extras):

| Query param | Meaning |
|---|---|
| `view=orders\|prescriptions\|patient` | Board view (default `orders`) |
| `order_id`, `rx_id`, `item_id`, `patient_id` | Deep selection (UUID; `order_id` forces `orders`, `rx_id` forces `prescriptions`, `patient_id` forces `patient`) |
| `rx_status`, `order_status` | Status filter (uppercased, validated server-side, else null) |
| `q` | Text search, ≤80 chars (ignored when `patient_id` is set) |

Gate: `pharmacy.access` (403). Facility scope required (409/403 without an
active facility).

Therefore the frontend must not invent:

```text
/workspaces/pharmacy/orders/<id>        # no such route
/workspaces/pharmacy/prescriptions/<id> # no such route
```

Detail opens as a workspace panel / contextual section driven by the query
params above, reusing the shared `DockedPanel` from `resources/js/components/panels`
with the appropriate edge placement.
The Pharmacy-specific patient medicine view is `PharmacyPatientPanel`; it
composes that shell with bottom placement rather than defining another panel
container.

**Frontend plumbing (Phase 0):**

1. Add `pharmacy` to `BUILT_WORKSPACES` (`layouts/AppLayout/navigation.ts:38`)
   — currently hidden from `visibleWorkspaces`; the `app.tsx` resolver would
   throw on visit.
2. Add write-path helpers to `app/routes.ts` (today only
   `workspaces.pharmacy: '/workspaces/pharmacy'` exists). Mirror the backend
   route names 1:1 — never invent paths.
3. Add the five verified permission keys to `lib/authorization/permissions.ts`
   (`Authorization/permissions.php:58-65` is the verified source):
   `pharmacy.prescriptions.accept`, `pharmacy.orders.manage`,
   `pharmacy.dispensing.record`, `pharmacy.dispensing.reverse`,
   `pharmacy.pickup.record`.
4. Create `resources/js/pages/Pharmacy/Index.tsx` (thin shell, owns the page
   header and layout) and `resources/js/features/pharmacy/` (components,
   types, reason-code module).

---

## 3. Ownership

Pharmacy owns:

- Prescription intake for external paper prescriptions; electronic ingest display
- Prescription cancellation (with reason)
- Pharmacy order creation from an `ACTIVE` prescription
- Order validation / rejection / hold / release / cancellation
- Dispensing against `VALIDATED` orders (item + location + quantity)
- Dispensing reversal
- Pickup recording, not-collected, and pickup cancellation
- Live, per-location and per-batch stock-availability display for the selected
  item (`stockByLocation` + `availabilityStatus` props)

Pharmacy does **not** own:

- Prescribing decisions (clinical — `clinical.PrescriptionIssued` is the
  source of electronic scripts; pharmacy ingests, never authors)
- Queue calling, recall, hold/release, transfer, service lifecycle
- Inventory catalogue, stock-location, or batch administration (uses
  `items` / `locations` options from props; stock data is display-only)
- Billing, tariffs, cashier, payments
- Full patient registration, demographics, chart ownership (external pharmacy
  intake may create a provisional identity through the Patient domain)

---

## 4. Navigation

Primary global navigation gains nothing new — Pharmacy already has its
Switcher entry (`Pills` icon) once `BUILT_WORKSPACES` includes it.

Sidebar (extend `workspaceNavigation`, mirroring the patients pattern):

```text
Pharmacy — no contextual sidebar. Views switch through Carbon line tabs
inside the page: Orders / Active prescriptions / All prescriptions, plus
a contextual Patient tab only when ?patient_id= is set. The URL stays the
source of truth (deep-linkable, backend-validated); all in-page movement
— tab switches, filters, detail open/close — uses background prop refresh
(preserveState + replace) so the page never remounts.
```

The backend supports `?view=patient` + `viewPatient` stack, but the current
sidebar omits it. Add it as **contextual-only** navigation (present when a
patient is selected, absent otherwise) — same rule as the patient chart.

Every user-facing view must be reachable: boards from the sidebar, order /
prescription detail from row selection, patient stack from search results and
row patient links, transcribe from the prescriptions board (and contextually
from the chart — §10).

---

## 5. Information Architecture

```text
Pharmacy
├── Orders (?view=orders)
│   ├── status filter (RECEIVED, VALIDATED, PARTIALLY_DISPENSED,
│   │   DISPENSED, ON_HOLD, REJECTED, CANCELLED)
│   ├── search (q)
│   ├── patient-grouped worklist columns: Order #, Created, Patient, Drugs,
│   │   Order status
│   ├── patient row → expand → individual order lifecycle and dispensing details
│   └── dispensing history per order (RxDispensingItem[] + pickupStatus)
├── Prescriptions (?view=prescriptions)
│   ├── status filter (rx_status)
│   ├── transcribe paper prescription (panel)
│   ├── patient-grouped worklist columns: Created, Patient, Prescriber, Drugs,
│   │   Last dispenser, Status
│   ├── prescription rows → expand → prescription details
│   └── create order from ACTIVE prescription (panel)
└── Patient (?view=patient&patient_id=, contextual)
    ├── prescription + order stacks for the patient
    ├── allergy strip (selectedPatient.allergies — display-only,
    │   safety context for dispensing)
    └── no direct link to the patient chart
```

Row identity follows the patient pattern: photo is not part of the pharmacy
contract — rows show name, number, age, and sex on a single non-wrapping line.
Patient details arrive via props (`patientDisplayName`, `patientNumber`,
`patientAge`, `patientSex`); never refetch identity client-side.

---

## 6. Lifecycle (backend-authoritative — never reimplement)

Use only these statuses and transitions. Do not create frontend-only states.

**Prescription** (`RECEIVED → ACTIVE`; `RECEIVED|ACTIVE → CANCELLED`):

```text
RECEIVED ── accept ──→ ACTIVE ── cancel ──→ CANCELLED
    └── cancel ──→ CANCELLED
```

**Order** (one non-terminal order per prescription — enforced server-side):

```text
RECEIVED ── validate ──→ VALIDATED ── dispense ──→ PARTIALLY_DISPENSED ──→ DISPENSED
    │                        │  hold ↑↓ release (release forces re-validate)
    ├── reject ──→ REJECTED (terminal)
    └── cancel ──→ CANCELLED (terminal, from RECEIVED|VALIDATED|ON_HOLD)
```

Dispensable = `VALIDATED | PARTIALLY_DISPENSED`. Reversal requires
`PARTIALLY_DISPENSED | DISPENSED`.

**Pickup** (auto-created `READY_FOR_PICKUP` on dispense; auto-cancelled on
reversal):

```text
READY_FOR_PICKUP ── record ──→ PICKED_UP
    ├── not-collected ──→ NOT_COLLECTED ── record (late) ──→ PICKED_UP
    └── cancel ──→ CANCELLED
```

`PICKED_UP` / `CANCELLED` are hard-terminal.

---

## 7. Supported Actions (one canonical workflow each)

| Action | Route | Permission (`can*` prop) | Inputs |
|---|---|---|---|
| Record external paper prescription | `pharmacy.prescriptions.transcribe` | `pharmacy.prescriptions.accept` (`canTranscribe`) | facility-registered patient, outside prescriber name, catalog medication selection, dose, frequency, duration, quantity, instructions, route, urgency, transcription note; no encounter |
| Accept electronic prescription | `pharmacy.prescriptions.accept` | `accept` (`canTranscribe`) | none — the clinical snapshot is fetched server-side under the pharmacist's `clinical.prescribing.read`; offered only for a `RECEIVED` `ELECTRONIC` prescription (paper is typed and activated at transcribe time) |
| Cancel prescription | `pharmacy.prescriptions.cancel` | `accept` | reason (required, min 3, max 500) |
| Create order | `pharmacy.orders.store` | `pharmacy.orders.manage` (`canManageOrders`) | prescription_id, priority (`ROUTINE\|URGENT\|STAT`, default `ROUTINE`) |
| Validate order | `pharmacy.orders.validate` | `orders.manage` | none |
| Reject order | `pharmacy.orders.reject` | `orders.manage` | reason_code (domain set) + note (≤500) |
| Hold order | `pharmacy.orders.hold` | `orders.manage` | reason_code (domain set) + note |
| Release hold | `pharmacy.orders.release` | `orders.manage` | none |
| Cancel order | `pharmacy.orders.cancel` | `orders.manage` | reason_code (domain set) + note |
| Dispense | `pharmacy.dispensings.store` | `pharmacy.dispensing.record` (`canDispense`) | order, item, location, quantity (0.01–999999.99), batch (optional) |
| Reverse dispensing | `pharmacy.dispensings.reverse` | `pharmacy.dispensing.reverse` (`canReverse`) | order, reason_code (6 fixed reasons) + note |
| Record pickup | `pharmacy.pickups.store` | `pharmacy.pickup.record` (`canRecordPickup`) | order, dispensing, recipient type (5 fixed) + name/verification note |
| Mark not-collected | `pharmacy.pickups.not-collected` | `pickup.record` | order, dispensing, reason_code (6 fixed outcomes) + note |
| Cancel pickup | `pharmacy.pickups.cancel` | `pickup.record` | order, dispensing, reason_code + note |

All writers return redirects: `back()->withErrors(<bag>)` (`prescription`,
`order`, `dispense` bags) or `back()->with('status', …)`. The frontend
renders bag errors with the existing `FormErrors` + toast pattern, keyed to
the open panel — never a second error implementation.

Row actions mirror the appointment-lifecycle pattern: ghost buttons gated by
status *and* the matching `can*` flag (e.g. Validate only on `RECEIVED` +
`canManageOrders`; Dispense only on dispensable + `canDispense`). Disabled
with visible reason where the state forbids it; hidden where permission
forbids it.

---

## 8. Reason Codes (accepted duplication, one module)

`PharmacyOrderReason` (reject 6 / hold 7 / cancel 6), `DispensingReversalReason`
(6), and `PickupCode` (5 recipient types, 6 outcomes) are backend-only string
constants — intentionally not `#[TypeScript]` — and priority / recipient /
reversal sets are duplicated in FormRequest `in:` rules.

The frontend mirrors them in **one** module
(`features/pharmacy/pharmacyCodes.ts`) as labelled option lists. The backend
remains authoritative: unknown codes fail server-side and surface through the
error bags. If the backend later exposes an options endpoint, only that
module changes.

---

## 9. External Paper Prescription Context

Afyanova prescriptions are selected from the existing Active prescriptions
board; they must not be transcribed again. The external intake panel is only
for a paper prescription issued outside Afyanova. It uses the shared,
active-facility-scoped patient search. An existing patient must be registered
at the active facility; an authorized user may create a provisional,
pharmacy-only identity there when no match exists. This path does not require
or create an encounter.

The server validates active-facility registration independently of the UI.
New external records use `EXTERNAL_PAPER` provenance and no clinical decision
or encounter link. Each drug must be selected from the active `MEDICATION`
entries in Inventory's Item Master; its name, code, and item id are saved from
that trusted catalog record, never accepted as client-entered drug identity.
The catalog is shared at tenant scope; actual supply remains facility-scoped
and availability is checked in the dispensing flow. Pharmacy can record the
external prescription even if stock is unavailable so the pharmacy team can
resolve supply without inventing an item.

External intake submits the complete medicine basket as one request. Each line
creates an active external prescription and a linked `RECEIVED` pharmacy
order in one transaction; an error leaves no partial basket behind. Replayed
submissions use stable client-generated IDs and command idempotency keys to
avoid duplicates. Orders appear in the pharmacy queue for explicit pharmacist
validation. Creating the prescription or order does not deduct stock; stock
changes only when the pharmacist records a dispense. Intake requires both
prescription-acceptance and pharmacy-order-management permissions.

The cashier workspace can issue an invoice for one charge at a time, record a
payment, and allocate it to an issued invoice. This supports the pickup gate's
dedicated one-dispensing invoice requirement. The pickup command checks Billing
clearance before recording handover. Self-pay requires a paid, dedicated
invoice for that dispensing; insurance requires confirmed coverage and payment
of the patient responsibility. Unpriced, uninvoiced, unpaid, or unconfirmed
charges remain blocked.

---

## 10. Patient Chart Relationship

The chart's **Medications** section stays display-only. From it, Pharmacy may
be reached by contextual link (patient pharmacy view URL) — never by
duplicating dispense/pickup actions in the chart. Prescribing entry stays
clinical; pharmacy transcribes paper and fulfills ingested electronic
scripts.

---

## 11. Queue Relationship

None. The Pharmacy domain holds no queue linkage — there is no calling,
serving, or transfer of pharmacy "tickets." Do not build a pharmacy queue
board. If dispensing demand ever needs queueing, that is a backend contract
change first.

---

## 12. Cross-Module Ownership Rule

> **One canonical owner per business workflow; many contextual entry points
> are allowed.**

| Workflow | Canonical owner | Contextual entry |
|---|---|---|
| Patient registration | Patients | — |
| Prescribing decision | Clinical | — |
| Paper transcription | Pharmacy | Patient chart |
| Order lifecycle | Pharmacy | Patient stack |
| Dispensing / reversal | Pharmacy | Validated order only |
| Pickup lifecycle | Pharmacy | Dispensing only |
| Stock catalogue | Inventory | Display in dispense panel |
| Payment / tariffs | Billing | — |

---

## 13. Error and State Handling

Handle: loading, empty board, no selection, filter with zero matches,
search too short/unresolvable patient, backend validation (error bags),
terminal-state actions (never offered), concurrency (order already
transitioned — refresh from backend truth), permission denial, stale
availability (display-only; backend revalidates stock, expiry, and ceiling at
dispense).

Never claim a mutation succeeded when the backend failed. After successful
mutations, reconcile from the re-rendered props (Inertia redirect) — no
client-side state machine.

---

## 14. Suggested Frontend Structure

```text
resources/js/
├── pages/
│   └── Pharmacy/
│       └── Index.tsx            # Inertia page: header, sidebar state, panels
└── features/
    └── pharmacy/
        ├── components/
        │   ├── PharmacyBoard.tsx        # orders | prescriptions tables + filters
        │   ├── OrderDetailPanel.tsx     # selected order + lifecycle actions
        │   ├── PrescriptionDetailPanel.tsx
        │   ├── TranscribePanel.tsx      # paper transcription form
        │   ├── DispensePanel.tsx        # item/location/qty + stock display
        │   ├── ReasonDialog.tsx         # shared reason-code confirm (reject/hold/
        │   │                            # cancel/reverse/not-collected)
        │   └── PatientStack.tsx         # contextual patient view + allergies
        ├── pharmacyCodes.ts             # mirrored reason/priority/recipient lists
        └── types/
            └── pharmacy.ts              # dashboard props mirror (backend DTOs
                                         # are #[TypeScript]-marked)
```

Follow the existing slice conventions: `DockedPanel` sheets with
`offsetInlineEnd` for the rail pattern only if a rail is warranted (defer —
boards carry their actions inline like Service Queues); `CarbonDataTable`
for boards; `EmptyState`/`ErrorState` for empty/unavailable; `useWorkspaceGuard`
+ `DiscardChangesModal` for multi-field panels (transcribe, dispense).

---

## 15. Phased Implementation

### Phase 0 — Plumbing + contract shell
- `BUILT_WORKSPACES`, routes helpers, 5 permission keys, sidebar
  (Orders / Prescriptions + contextual patient).
- `Pharmacy/Index` shell: header, boards with real props, empty states,
  read-only lists (row actions and detail panels land with Phases 1–3;
  the `can*` permission flags are plumbed through props and pinned by
  contract tests).
- Contract tests: props shape, gating flags, sidebar behavior.
- **Exit:** visiting `/workspaces/pharmacy` renders; unauthorized users are
  denied; no invented endpoints.

### Phase 1 — Orders board + order lifecycle
**Status: implemented 2026-10-14** — `OrderDetailPanel` (?order_id=), shared
`OrderReasonDialog`, `CreateOrderPanel`, `PrescriptionDetailPanel`,
server-side status filters, page error alert; 18 frontend contract tests.
Dispensing/pickup UI remains Phase 3.
- List, status filter, `q` search, row select → detail panel.
- Create order (from prescription), validate, hold, release, reject, cancel
  (shared reason dialog).
- **Exit:** full order state machine operable per status × permission matrix,
  covered by tests.

### Phase 2 — Prescriptions + transcription
**Status: implemented 2026-10-15** — `TranscribePanel` for external paper
intake, facility-scoped patient search, optional provisional pharmacy-only
patient creation, allergies, searchable Inventory medication catalog, editable
drug basket, atomic linked prescription/order creation, and prescription
cancellation. Afyanova prescriptions are found in the Active prescriptions
board and are never duplicated. External intake does not require an encounter.
Electronic scripts can now be accepted from the prescription detail panel
(Phase 4 item 6 of the inventory roadmap — see §7 and §16).
- Prescriptions board, filter, detail panel, accept (electronic `RECEIVED`),
  cancel with reason.
- External-paper panel (active-facility patient cards, optional minimal
  pharmacy-only patient creation, allergy summary, catalog-backed multi-drug
  basket, no encounter required).
- **Exit:** external paper → ACTIVE external prescription + RECEIVED linked
  pharmacy order → validation; pickup
  blocked until the dispensing-specific bill is paid or confirmed coverage is
  in place.

### Phase 3 — Dispensing + pickup
**Status: implemented 2026-10-15** — `DispensePanel` (item/location/qty, per-location + per-batch availability with expiry and an explicit batch select, stock-aware since Phase 4 of the inventory roadmap), `ReverseDispensingDialog` (6 fixed reasons), `PickupPanel` (recipient codes + billing-gate message), `PickupOutcomeDialog` (not-collected/cancel with 6 outcome codes), wired into compact `OrderDetailPanel` per status × permission matrix; 9 frontend contract tests.
- Dispense panel (item/location/qty, per-location + per-batch availability, explicit batch for tracked items).
- Reverse with reason codes; pickup record / not-collected / cancel with
  recipient + outcome codes.
- Pickup recording requires a paid dedicated invoice or confirmed coverage
  with the patient responsibility settled.
- **Exit:** validated order → dispensed → picked-up (and late pickup,
  reversal auto-cancel), tested per status matrix.

### Phase 4 — Patient medication profile
- **Status: implemented 2026-10-15.** Pharmacy patient rows open a contextual
  patient stack with facility-scoped prescriptions and orders, active allergy
  context. The direct Pharmacy-to-chart link is deferred; the chart links
  prescriptions back to Pharmacy only when they belong to the active facility.
  Pharmacy record links reopen and expand the matching patient row.
- **Exit:** every view reachable; no orphan pages; full journey
  register → visit → prescribe → dispense → pickup demonstrable.

---

## 16. Open Gaps and Backend Follow-ups (do not paper over)

1. **Electronic-script accept route — resolved** (Phase 4 item 6 of the
   inventory roadmap, 2026-10-18). `POST
   /workspaces/pharmacy/prescriptions/{prescriptionId}/accept`
   (`pharmacy.prescriptions.accept`) dispatches `AcceptPrescription`
   (`RECEIVED → ACTIVE`); the prescription detail panel offers **Accept
   prescription** for a `RECEIVED` `ELECTRONIC` prescription. Paper still types
   and activates at transcribe time (`TranscriptionController`), so the action
   is never shown for paper.
2. **No `patient` sidebar entry** (backend supports the view). Resolved here
   as contextual-only navigation — revisit if product wants it pinned.
3. **Pickup queue is not a prop** (`pickupStatus` rides on dispensing rows;
   no pickup ids). A dedicated pickups view needs a backend addition.
4. **Reason-code duplication** (§8) — accepted, single-mirrored module.
5. **`searchError` is always null** currently — render the slot anyway so a
   future backend signal has a home.

---

## 17. Acceptance Criteria

- `/workspaces/pharmacy` is the canonical workspace; no invented routes.
- Boards use verified props; filters/search match backend semantics.
- Every mutation uses its canonical endpoint, permission, validation, and
  reason-code set; backend failures surface correctly.
- No frontend-only lifecycle states; terminal states never offer actions.
- Prescribing, queues, inventory admin, and billing are never duplicated.
- Permissions respected; loading, empty, error, and stale states handled.
- Every view reachable; no orphan pages.
- Full journey demonstrable: register → visit → prescribe → dispense → pickup.
