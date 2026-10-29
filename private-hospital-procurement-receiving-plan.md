# Private Hospital Procurement & Goods Receipt — Complete Implementation Plan

**Status:** Approved — Phases 0, 1 and 2A plus Phase 3 invoice capture/matching, supplier returns/quarantine and supplier licence reminders (P3-01/P3-02/P3-04/P3-05) implemented; the legacy single-line receiving path was removed on 2026-10-22; conditional Phase 2B and Phase 4 remain
**Date:** 2026-10-22
**Scope:** Private for-profit hospital, local Tanzanian suppliers, one facility
**Decision:** `1A, 2A, 3A`, Mainland Tanzania, VAT excluded, serial-tracked items deferred
**Stack:** Laravel 13 / PHP 8.5, PostgreSQL, Inertia + React 19, Carbon Design System

---

## Executive summary

- **Baseline finding:** receiving originally accepted one item per submit and did not send the selected PO line ID, so the backend could post to the wrong line.
- **Fix correctness first.** Phase 0 makes `po_line_id` authoritative, removes the cap, adds server-side search and stops full reloads.
- **Then replace the single-line panel with a receipt workbench.** Phase 1 delivers one header, many lines, batch/expiry, accepted/rejected quantities, one submit, one GRN and a printable document.
- **Then add buying and finance controls.** Phase 2 adds price references, duplicate prevention and cost centres. Phase 3 adds invoice matching and returns.
- **Target:** 500-line delivery in under 2 hours, one GRN, zero wrong-line postings, under 15 seconds to submit.
- **Decisions locked:** VAT is out of scope; serial-tracked items are out of scope for this delivery and keep the current clear block.
- **Current receiving workflow:** use **Receive delivery** only. The legacy **Receive stock** panel, shortcuts and HTTP adapter have been removed.

---

## 0. How to use this document

1. Read Sections 1–5 before changing code.
2. Execute **Phase 0 → Phase 4** in order. Do not start a phase until the previous gate passes.
3. Every ticket lists the files to touch and the acceptance test.
4. Every phase ends with a gate: tests, static checks, and a real workflow test.
5. No feature is “done” until it works for a **300–500 line** purchase order.

This plan is deliberately explicit. It is the single source of truth for what changes, where, and why.

---

## 1. Locked scope and decisions

| Decision | Locked value | Consequence |
|---|---|---|
| Hospital type | Private, for-profit | No MSD/eLMIS/MEMS integration, no public-procurement workflow |
| Buying model | Local Tanzanian suppliers/distributors | TZS only; no foreign currency, freight, clearing or landed cost |
| Facilities | One hospital | No branch-to-branch transfers or central warehouse |
| Purchase order currency | TZS | No multi-currency |
| UI system | Carbon Design System | Use Carbon components and existing `afy-*` layout classes |
| Receiving document | One delivery = one receipt = one GRN | No per-line GRNs |
| Stock authority | Inventory owns stock and receipts | Procurement projects PO fulfilment; it never writes stock |
| Line matching | `po_line_id` is authoritative | Never match a receipt by item code alone |
| Stock posting | Only accepted quantity posts | Rejected/damaged quantity is recorded but not stocked |
| Invoice | Captured, not posted to a ledger | No full accounts-payable module |
| Returns | Phase 3 | Damaged goods after posting become a supplier return, not an edit |
| Serial numbers | Out of scope for this delivery | Keep the current clear block; document the exception process; revisit only on approval |
| VAT / tax | Out of scope | Do not add tax fields, rates, codes, totals or tax-based matching anywhere |
| RFQ / quotation / contract UI | Conditional Phase 2B | Backend already exists; only presentation is missing |

### 1.1 Explicitly out of scope

Do **not** build in this plan:

- MSD, eLMIS, MEMS, Jazia or PPRA integration
- Import purchase orders, foreign currency, freight, port clearing or landed cost
- Branch transfers, group purchasing or central warehouse
- Full accounts payable, general ledger or payment runs
- Barcode-first receiving, ASN, EDI or supplier portal
- Consignment stock
- Offline-first architecture
- A full budget/accounting engine
- Serial-number receiving (deferred; keep the current block unless separately approved)

Intra-facility transfers between stores and wards already exist and stay.

---

## 2. Target outcome and success metrics

### 2.1 Target workflow

A store clerk receives a local supplier delivery against a 500-line purchase order:

1. Opens **Receive delivery**.
2. Searches for and selects the purchase order.
3. Enters delivery note, invoice number/date, destination store and remarks.
4. Loads the open lines, searches/filters, and uses **Receive all remaining**.
5. Fixes exceptions: short quantity, rejected/damaged quantity, batch, expiry, substitution.
6. Submits **once**.
7. Gets one **GRN** number.
8. Prints one GRN.
9. PO line quantities and stock update correctly, with no wrong-line postings.

### 2.2 Success metrics

| Metric | Target |
|---|---|
| Open a 500-line receipt workbench | Under 1 second p95 |
| Search an item or PO line | Under 300 ms p95 |
| Load a page of 100 lines | Under 500 ms p95 |
| Submit a 500-line receipt | Under 15 seconds p95, one transaction |
| Wrong-line postings in duplicate-item test | Zero |
| GRNs per delivery | Exactly one |
| Full page reload after submit | None |
| Clerk time for 100 lines | Under 30 minutes |
| Clerk time for 500 lines | Under 2 hours |
| Invoice reference visible on GRN | Always for PO receipts, or explicit “pending” |

---

## 3. Original baseline before implementation

The tables in this section record the system state when this plan was written; they
are not a description of the current application. The current receiving workflow
is **Receive delivery** only (see the executive summary).

### 3.1 What existed at plan baseline

| Area | Current capability |
|---|---|
| Purchase requisitions | Create, edit draft, submit, approve, reject, cancel, export, print |
| Purchase orders | Create direct or from approved request, submit, approve, issue, cancel, export, print |
| Suppliers | Register, update, change status, blacklist |
| RFQ / quotations / contracts | Domain commands, queries and models exist; **no UI or HTTP routes** |
| Receiving | Single-line receipt panel against an optional PO |
| Stock | Immutable movement ledger, batches, expiry, balances, transfers, counts, adjustments |
| Approvals | TZS one-person limits, department scoping, audit trail |
| Pack handling | Buying unit, pack size, base-unit quantities |
| Invoice matching (quantity/price) | Not present; VAT excluded |
| GRN document | Not present |

### 3.2 Defects identified at plan baseline

| ID | Severity | Defect | Evidence | Impact | Fix phase |
|---|---|---|---|---|---|
| R-01 | Critical | Purchase order and requisition lines are capped at 50 | `StorePurchaseOrderRequest.php`, `StorePurchaseRequisitionRequest.php` | 300–500 line orders cannot be raised | P0 |
| R-02 | Critical | Selected PO line ID is never sent | `ReceiveStockPanel.tsx` form payload has `po_ref` and `item_id`, no `po_line_id` | Receipt can post to the wrong line | P0 |
| R-03 | Critical | Backend matches the first open line by item | `RecordPurchaseOrderReceiptHandler.php` uses `where('item_ref', …)->orderBy('line_no')->first()` | Duplicate items post to the first line | P0 |
| R-04 | Critical | No receipt header or GRN | Only `pcr_po_receipts` per stock event | No delivery document, no invoice/delivery-note traceability | P1 |
| R-05 | Critical | One item and one quantity per submit | `ReceiveStockPanel.tsx` | 100–500 lines require 100–500 submits | P1 |
| R-06 | High | Panel closes and reloads after every success | `ReceiveStockController.php` redirects; panel calls `onClose()` | Clerk loses context and repeats work | P0/P1 |
| R-07 | High | All open POs and all lines are injected into page props | `InventoryWorkspaceController.php` maps every open PO and line | Page payload grows with every order | P0 |
| R-08 | High | Partial receipt works, but there is no backorder/close-short workflow | PO line status only `OPEN`/`CANCELLED` | Undelivered balance never closes cleanly | P1 |
| R-09 | High | No invoice number or invoice date | `pcr_purchase_orders`, `pcr_po_lines` | Finance cannot reconcile delivery to invoice | P3 |
| R-10 | High | `supplier_ref` is not traceable on the receipt | `ReceiveStockHandler.php` passes it only to `Batch::register()`; it is not stored on the movement or a receipt header | Supplier/delivery reference cannot be audited from the receipt | P1 |
| R-11 | High | No RFQ/quotation/contract UI | Backend commands exist; routes absent | Buyers cannot compare quotes or use contract prices in the UI | P2B |
| R-12 | High | Serial-tracked items are blocked | `ReceiveStockRequest.php` adds “cannot be received”; panel filters `tracksSerial` | Implants and serialised equipment cannot be received | P3 |
| R-13 | Medium | Direct purchase orders are not checked for duplicates | Requisition duplicate guard exists; direct POs have no guard | Accidental duplicate commitments | P2 |
| R-14 | Medium | No GRN register or print | No receipt list/detail/PDF | Cannot audit or reprint a delivery | P1 |
| R-15 | Medium | No supplier licence/qualification expiry | Supplier master has status but no licence dates | Expired suppliers can still be ordered from | P3 |
| R-16 | Low | Cross-facility transfers rejected | `TransferStockHandler.php` | Out of scope for 1A/2A/3A; do not change | — |

### 3.3 Baseline work already completed — do not redo

These changes are already in the working tree:

- Procurement row actions redesigned in `OrdersSection.tsx`, `RequestsSection.tsx`, `SuppliersSection.tsx`.
- Export buttons restyled and moved in Orders and Requests.
- Procurement section headings converted to Inventory-style `afy-section__heading`, `afy-section__title`, `afy-section__description`.
- Persistent explanation alerts removed from Procurement sections.
- Statistics cards added to Orders, Requests, Suppliers.
- `approvalLimit` crash guard added in `OrdersSection.tsx`.
- Historical: the legacy Receive stock panel was widened; that panel has since been removed.
- Quantity `invalidText` fixed to include `exceedsPurchaseOrderQuantity`.
- Receive summary converted to compact `.afy-facts--inline`, square, no tags.
- Procurement tests and Inventory panel tests pass.

Retain the changes above where they remain applicable. The legacy Receive stock panel
and its sizing/summary work are retired; do not restore that workflow.

---

## 4. Target operating model

### 4.1 End-to-end flow

```text
Request → Approve → Source (direct / RFQ / contract) → Purchase order
   → Supplier delivers
   → Receive workbench (one header + many lines)
   → GRN + stock movements + batches
   → PO receipt projection
   → Invoice match / variance flag
   → Export to finance
```

### 4.2 Receiving sequence

```text
1. Clerk opens Receive delivery.
2. Clerk selects PO or “No purchase order”.
3. Clerk completes delivery header.
4. System loads open lines with search and pagination.
5. Clerk receives all, or edits exceptions line by line.
6. Client validates; server revalidates.
7. One submit creates:
   - inv_goods_receipts (header + GRN number)
   - inv_goods_receipt_lines (one per delivered line)
   - inv_stock_movements (one per accepted line)
   - batch rows where needed
   - one GoodsReceiptPosted event
8. Procurement listener updates PO lines and PO status in one batch.
9. Clerk prints the GRN.
10. Stock balances and receipts register refresh by partial reload.
```

### 4.3 Roles

| Role | Can do |
|---|---|
| Store clerk | Receive deliveries, view/print GRNs, view stock |
| Store manager | Receive, override over-receipt, close short, view all receipts |
| Procurement officer | Requests, POs, suppliers, RFQ/quotes/contracts, price references |
| Finance | View GRNs, attach/match invoices, export, flag variances |
| Facility admin | Configure permissions, stores, item master |

---

## 5. Architecture and invariants

### 5.1 Ownership

| Domain | Owns |
|---|---|
| Inventory | `inv_goods_receipts`, `inv_goods_receipt_lines`, `inv_stock_movements`, batches, balances |
| Procurement | `pcr_purchase_orders`, `pcr_po_lines`, `pcr_po_receipts`, suppliers, RFQs, quotes, contracts |
| Identity | Users, roles, permissions |
| Organization | Facility and department structure |

No cross-domain foreign keys. Cross-domain references are opaque UUIDs or document numbers, consistent with the existing architecture.

### 5.2 Invariants

1. **One delivery = one receipt = one GRN.**
2. **`po_line_id` is authoritative.** A PO receipt without a valid line ID is rejected.
3. **Only accepted quantity posts to stock.** `accepted = delivered − rejected`.
4. **One transaction** creates the header, lines, batches, movements and balances.
5. **One PO projection per receipt**, not one PO-update job per line. Per-line stock facts may still emit their own lightweight events.
6. **No full page reload** after submit.
7. **No cap below 500 lines** on requisitions or purchase orders.
8. **No edit of a posted stock movement.** Corrections are new documents.
9. **No invoice = finance follow-up, not receiving block.** Receiving may be marked invoice pending.
10. **Idempotency:** retrying the same receipt returns the same GRN and never doubles stock.

### 5.3 Event flow

```text
RecordGoodsReceipt (Inventory command)
  ├─ writes inv_goods_receipts
  ├─ writes inv_goods_receipt_lines
  ├─ writes inv_stock_movements
  ├─ posts StockLedgerService for each accepted line
  ├─ records StockReceived per accepted line (stock fact)
  └─ records GoodsReceiptPosted once (document fact)

GoodsReceiptPosted
  └─ OnGoodsReceiptPosted (Procurement)
       └─ RecordPurchaseOrderReceiptBatch
            ├─ locks the PO
            ├─ writes pcr_po_receipts per line
            ├─ increments pcr_po_lines.received_qty
            └─ rolls PARTIALLY_RECEIVED / FULLY_RECEIVED / CLOSED_SHORT
```

`OnStockReceived` remains for historical stock-movement events. It **skips** events that carry a `goodsReceiptRef`, because the batch event already projected them. This avoids double counting and 500 queued jobs.

### 5.4 Why this design

- It preserves the existing event-driven boundary.
- It removes the per-item guessing bug.
- It supports 500 lines with one PO projection job. `StockReceived` remains a per-line stock fact; its Procurement listener exits immediately for these events.
- It keeps stock and procurement consistent under retries.
- It adds a real GRN without turning Inventory into an accounting system.

---

## 6. Data model changes

### 6.1 New Inventory tables

#### `inv_goods_receipts`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | uuidv7 |
| `tenant_id` | uuid | indexed |
| `facility_id` | uuid | indexed |
| `receipt_number` | string | `GRN-%08d`, unique per tenant |
| `source_type` | string | `PROCUREMENT`, `DONATION`, `OPENING_BALANCE`, `OTHER` |
| `po_ref` | string nullable | PO number; opaque, no FK |
| `supplier_ref` | uuid nullable | Supplier ID; opaque, no FK |
| `location_id` | uuid | FK `inv_stock_locations` |
| `delivery_note_number` | string nullable | Required for PO unless “no note” reason |
| `delivery_note_missing_reason` | text nullable | Required when no delivery note |
| `invoice_number` | string nullable | Nullable when pending |
| `invoice_date` | date nullable | |
| `invoice_status` | string | `RECEIVED`, `PENDING`, `NOT_REQUIRED` |
| `invoice_currency` | string | Default `TZS` |
| `invoice_total` | decimal(18,2) nullable | |
| `received_at` | timestamptz | Effective delivery date/time |
| `received_by_user_id` | uuid | |
| `remarks` | text nullable | Row-only |
| `status` | string | `POSTED` initially |
| `match_status` | string | `UNMATCHED`, `MATCHED`, `VARIANCE` (P3) |
| `matched_at` | timestamptz nullable | P3 |
| `matched_by_user_id` | uuid nullable | P3 |
| `occurred_at` | timestamptz | |
| `recorded_at` | timestamptz | |
| `version` | bigint | Optimistic/audit shape |
| timestamps | timestamptz | |

Indexes:

- unique `(tenant_id, receipt_number)`
- `(tenant_id, facility_id, received_at)`
- `(tenant_id, po_ref)`
- `(tenant_id, supplier_ref)`
- `(tenant_id, invoice_number)`

#### `inv_goods_receipt_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | uuidv7 |
| `tenant_id` | uuid | |
| `facility_id` | uuid | |
| `receipt_id` | uuid | FK `inv_goods_receipts` |
| `line_no` | integer | Unique per receipt |
| `po_line_ref` | uuid nullable | Opaque `pcr_po_lines.id` |
| `item_id` | uuid | FK `inv_items` |
| `batch_id` | uuid nullable | FK `inv_batches` |
| `quantity_delivered` | decimal(18,4) | Must be > 0 |
| `quantity_rejected` | decimal(18,4) | Default 0 |
| `quantity_accepted` | decimal(18,4) generated | `delivered − rejected`, stored |
| `uom` | string | Base unit |
| `entered_unit` | string nullable | Buying unit |
| `entered_quantity` | decimal(18,4) nullable | |
| `pack_size` | decimal(18,4) nullable | |
| `batch_number` | string nullable | Snapshot |
| `expiry_date` | date nullable | Snapshot |
| `manufacture_date` | date nullable | Snapshot |
| `condition` | string | `GOOD`, `DAMAGED`, `SHORT`, `WRONG_ITEM`, `EXPIRED` |
| `substituted_for_item_id` | uuid nullable | When a substitute item arrives |
| `notes` | text nullable | Row-only |
| `movement_id` | uuid nullable | Null when accepted = 0 |
| `po_unit` | string nullable | P3; snapshot from the exact PO line at receipt time |
| `po_unit_price` | decimal(18,2) nullable | P3; immutable comparison price snapshot |
| `invoice_quantity` | decimal(18,4) nullable | P3; quantity as invoiced in the receipt entry unit |
| `invoice_unit_price` | decimal(18,2) nullable | P3; no VAT fields |
| `discount_amount` | decimal(18,2) nullable | P3 |
| `version` | bigint | |
| timestamps | timestamptz | |

Constraints:

- `quantity_delivered > 0`
- `quantity_rejected >= 0`
- `quantity_rejected <= quantity_delivered`
- `quantity_accepted >= 0`
- `quantity_accepted = 0` with null `movement_id`, or `quantity_accepted > 0` with a non-null `movement_id`
- unique `(tenant_id, receipt_id, line_no)`

Indexes:

- `(tenant_id, receipt_id)`
- `(tenant_id, po_line_ref)`
- `(tenant_id, item_id)`

#### `inv_goods_receipt_number_counters`

Same shape as `inv_movement_number_counters`:

- `id`, `tenant_id`, `next_value`, `version`, timestamps
- unique `tenant_id`
- written only by `GoodsReceiptNumberSequence`

#### `inv_item_serials` — Phase 3, only if serials are in scope

| Column | Notes |
|---|---|
| `id`, `tenant_id`, `facility_id` | |
| `item_id`, `batch_id`, `location_id` | |
| `serial_number` | unique per tenant + item |
| `receipt_line_id` | Opaque link to `inv_goods_receipt_lines` |
| `movement_in_id`, `movement_out_id` | Opaque stock movement links |
| `status` | `IN_STOCK`, `ISSUED`, `RETURNED`, `SCRAPPED` |
| `expiry_date`, `notes`, timestamps | |

### 6.2 Modified Inventory tables

#### `inv_stock_movements`

Add:

- `po_line_ref` uuid nullable, indexed
- `goods_receipt_ref` uuid nullable, indexed

Set for new receipts:

- `source_type = PROCUREMENT | DONATION | OPENING_BALANCE | OTHER`
- `source_ref = inv_goods_receipts.id`
- `po_ref = PO number`
- `po_line_ref = pcr_po_lines.id`
- `goods_receipt_ref = inv_goods_receipts.id`

No FK to Procurement.

#### `StockReceived` event

Add:

- `?string $poLineRef`
- `?string $goodsReceiptRef`

`StockBalance::applyReceipt()` copies both from the movement.

### 6.3 Modified Procurement tables

#### `pcr_po_receipts`

Add:

- `goods_receipt_ref` uuid nullable
- `goods_receipt_line_ref` uuid nullable
- `exception_code` string nullable

Change idempotency:

- Backfill `goods_receipt_line_ref = source_event_id` for legacy rows.
- Drop unique `(tenant_id, source_event_id)`.
- Add unique `(tenant_id, goods_receipt_line_ref)`.
- Keep `source_event_id` indexed for audit.

#### `pcr_po_lines`

Add:

- `status` accepts `CLOSED_SHORT`
- `closed_short_at` timestamptz nullable
- `closed_short_by_user_id` uuid nullable
- `close_short_reason` text nullable

#### `pcr_purchase_orders`

Add:

- `status` accepts `CLOSED_SHORT`
- `cost_centre_ref` string nullable (P2)
- `budget_ref` string nullable (P2)

#### `pcr_purchase_requisitions`

Add:

- `cost_centre_ref` string nullable (P2)
- `budget_ref` string nullable (P2)

#### `pcr_suppliers` — Phase 3

Add:

- `license_number` string nullable
- `license_expiry_date` date nullable
- `license_document_ref` string nullable

### 6.4 Migration order

Use new migrations dated after the current latest (`2026_10_20`):

```text
2026_10_21_000010_add_po_line_ref_to_inv_stock_movements.php
2026_10_21_000020_create_inv_goods_receipt_number_counters_table.php
2026_10_21_000030_create_inv_goods_receipts_table.php
2026_10_21_000040_create_inv_goods_receipt_lines_table.php
2026_10_21_000050_add_receipt_links_to_pcr_po_receipts.php
2026_10_21_000060_add_closed_short_to_pcr_po_lines.php
2026_10_21_000070_add_cost_centre_to_procurement_documents.php
2026_10_21_000090_add_license_fields_to_pcr_suppliers.php
2026_10_21_000100_create_inv_item_serials_table.php
```

Only ship the migrations for the phase being executed. Do not apply Phase 3 migrations before Phase 3 is approved.

### 6.5 RLS and immutability

- `inv_goods_receipts`: `Rls::enable`; no direct updates after `POSTED`; invoice attachment uses a command with audit.
- `inv_goods_receipt_lines`: `Rls::enable`; append-only after posting.
- `inv_stock_movements`: existing `Rls::enableAppendOnly` unchanged.
- `pcr_po_receipts`: existing append-only unchanged.
- All new tables get tenant RLS.
- No PHI in receipt remarks. Remarks are row-only, never event/audit metadata.

### 6.6 Backfill and legacy data

1. Do **not** invent GRNs for historical receipts.
2. Backfill `pcr_po_receipts.goods_receipt_line_ref = source_event_id` so the new unique index can be created.
3. Leave historical `pcr_po_receipts.po_line_id` as-is; ambiguous matches stay null and are labelled **Legacy receipt**.
4. Do not backfill `inv_stock_movements.po_line_ref` from item matches; duplicate items make it unsafe.
5. Add a read-only “Legacy receipts” filter in the GRN register.
6. Add a reconciliation report: PO received quantity vs sum of legacy receipts; investigate only differences.

---

## 7. Backend changes

### 7.1 Inventory command and service

#### New: `RecordGoodsReceipt`

File:

```text
app/Domains/Inventory/Application/Command/RecordGoodsReceipt.php
```

Fields:

```text
receiptId (uuid, client-minted, idempotency)
actorId
locationId
sourceType
poRef? (PO number)
supplierRef?
deliveryNoteNumber?
invoiceNumber?
invoiceDate?
invoiceStatus
invoiceTotal?
receivedAt?
remarks?
lines[]:
  receiptLineId
  poLineRef?
  itemId
  quantityDelivered
  quantityRejected
  uom?
  enteredUnit?
  enteredQuantity?
  packSize?
  batchNumber?
  expiryDate?
  manufactureDate?
  condition?
  substitutedForItemId?
  notes?
```

Rules:

- `receiptId` and every `receiptLineId` must be UUIDs.
- If `sourceType = PROCUREMENT`, `poRef` is required and every line must have `poLineRef`.
- If `sourceType != PROCUREMENT`, `poRef` and `poLineRef` must be null.
- `quantityDelivered > 0`; `quantityRejected >= 0`; `quantityRejected <= quantityDelivered`.
- `enteredUnit`, `enteredQuantity`, `packSize` must agree with the base quantity.
- Batch/expiry rules are enforced per item.
- Expired batch is rejected as of `receivedAt`.
- Backdating more than 24 hours requires a reason.
- A substitute item requires `substitutedForItemId` and a reason in notes.

#### New: `RecordGoodsReceiptHandler`

File:

```text
app/Domains/Inventory/Application/Command/RecordGoodsReceiptHandler.php
```

Flow:

1. Resolve active location; derive facility.
2. Authorize `inventory.receipts.create` for that facility.
3. Allocate `GRN-%08d` inside the transaction.
4. Create `inv_goods_receipts`.
5. For each line:
   - Validate item is active and stockable.
   - Resolve batch or create it.
   - Validate pack entry.
   - Compute accepted quantity.
   - If accepted > 0:
     - allocate `MOV-%08d`
     - create `inv_stock_movements`
     - load/create balance `FOR UPDATE`
     - post via `StockLedgerService`
   - Create `inv_goods_receipt_lines`.
6. Keep a balance map keyed by `(item, location, batch)` so two lines hitting the same balance post sequentially on the same locked row.
7. Save all balances.
8. Record `StockReceived` per accepted line.
9. Record one `GoodsReceiptPosted` event with all accepted lines.
10. Commit.

Two receipt lines may share the same PO line with different batches. Aggregate accepted quantity per PO line and validate the total against the remaining quantity; do not reject valid split-batch receipts.

#### New shared service: `GoodsReceiptRecorder`

File:

```text
app/Domains/Inventory/Application/Service/GoodsReceiptRecorder.php
```

Purpose: keep the existing single-line `ReceiveStockHandler` working by delegating to the same posting logic. Avoid two copies of batch/expiry/pack validation.

Modify:

```text
app/Domains/Inventory/Application/Command/ReceiveStockHandler.php
```

Make it create a one-line goods receipt and call the shared recorder. Keep the `ReceiveStock` command and endpoint for backward compatibility during the transition.

Modify:

```text
app/Domains/Inventory/Application/Command/ReceiveStock.php
```

Add `?string $purchaseOrderLineRef` and `?string $goodsReceiptRef` only if needed for the compatibility adapter. Prefer passing them through the recorder.

#### New: `GoodsReceipt` aggregate

Files:

```text
app/Domains/Inventory/Domain/Model/GoodsReceipt.php
app/Domains/Inventory/Domain/Model/GoodsReceiptLine.php
app/Domains/Inventory/Domain/GoodsReceiptRepository.php
app/Domains/Inventory/Infrastructure/Repository/EloquentGoodsReceiptRepository.php
```

`GoodsReceipt` is the header; `GoodsReceiptLine` is a plain child. The header records `GoodsReceiptPosted` after the lines are persisted.

#### New: `GoodsReceiptNumberSequence`

Files:

```text
app/Domains/Inventory/Infrastructure/Sequence/GoodsReceiptNumberSequence.php
app/Domains/Inventory/Database/Migrations/2026_10_21_000020_create_inv_goods_receipt_number_counters_table.php
```

Number format: `GRN-%08d`, tenant-wide.

### 7.2 Inventory queries and readers

Add:

```text
app/Domains/Inventory/Application/Query/SearchReceivablePurchaseOrders.php
app/Domains/Inventory/Application/Query/SearchReceivablePurchaseOrderLines.php
app/Domains/Inventory/Application/Query/GetGoodsReceipts.php
app/Domains/Inventory/Application/Query/GetGoodsReceipt.php
app/Domains/Inventory/Contracts/Dto/GoodsReceiptSummary.php
app/Domains/Inventory/Contracts/Dto/GoodsReceiptLineSummary.php
app/Domains/Inventory/Contracts/Dto/ReceivablePurchaseOrderSummary.php
app/Domains/Inventory/Contracts/Dto/ReceivablePurchaseOrderLineSummary.php
app/Domains/Inventory/Infrastructure/Reader/EloquentGoodsReceiptReader.php
```

`SearchReceivablePurchaseOrders`:

- facility-scoped
- only `ISSUED` and `PARTIALLY_RECEIVED`
- only POs with at least one open stock line
- search by PO number, supplier name, item code, item name
- paginated; default 20 per page
- returns line count and remaining line count, **not every line**

`SearchReceivablePurchaseOrderLines`:

- selected PO
- only open stock lines with remaining quantity > 0
- search by line number, item code, item name
- paginated; default 100 per page
- returns item tracking flags: `tracksBatch`, `tracksExpiry`, `tracksSerial`, `packUnit`, `packSize`, `active`

`GetGoodsReceipts`:

- filters: date range, supplier, PO number, invoice number, GRN number, match status
- paginated

### 7.3 Inventory HTTP

Add controllers:

```text
app/Domains/Inventory/Presentation/Http/Controller/StoreGoodsReceiptController.php
app/Domains/Inventory/Presentation/Http/Controller/SearchReceivablePurchaseOrdersController.php
app/Domains/Inventory/Presentation/Http/Controller/SearchReceivablePurchaseOrderLinesController.php
app/Domains/Inventory/Presentation/Http/Controller/GoodsReceiptIndexController.php
app/Domains/Inventory/Presentation/Http/Controller/ShowGoodsReceiptController.php
app/Domains/Inventory/Presentation/Http/Controller/PrintGoodsReceiptController.php
app/Domains/Inventory/Presentation/Http/Controller/ImportGoodsReceiptController.php
```

Add requests:

```text
app/Domains/Inventory/Presentation/Http/Request/StoreGoodsReceiptRequest.php
app/Domains/Inventory/Presentation/Http/Request/ImportGoodsReceiptRequest.php
```

`StoreGoodsReceiptRequest` must validate:

- header fields
- line array `min:1`, `max:500`
- each line’s item, quantities, PO line, batch/expiry
- aggregate accepted quantity per `po_line_ref` does not exceed remaining
- serial-tracked item policy
- invoice status consistency
- delivery note required for PO unless `delivery_note_missing_reason` is present

`StoreGoodsReceiptController`:

- dispatch `RecordGoodsReceipt`
- on success: redirect back with `status` and `goodsReceiptId`, not a full page reload
- on domain error: `back()->withErrors(...)->withInput()`

`PrintGoodsReceiptController`:

- Dompdf, same pattern as `PrintPurchaseOrderController`
- view: `resources/views/inventory/documents/goods-receipt.blade.php`
- A4 portrait; include signatures and page numbers

`ImportGoodsReceiptController`:

- accepts CSV/XLSX
- parses with native CSV or PhpSpreadsheet
- returns a preview: resolved lines, errors, unmatched items, quantity totals
- does not post stock

### 7.4 Events and listeners

#### New Inventory event: `GoodsReceiptPosted`

File:

```text
app/Domains/Inventory/Contracts/Event/GoodsReceiptPosted.php
```

Payload:

```text
eventId
tenantId
facilityId
goodsReceiptId
receiptNumber
poRef?
supplierRef?
sourceType
occurredAt
correlationId
causationId
lines[]:
  receiptLineId
  poLineRef?
  itemId
  batchId?
  movementId
  movementNumber
  quantity
  uom
```

No notes, no invoice text, no PHI.

#### Modify `StockReceived`

Add:

```text
?string $poLineRef
?string $goodsReceiptRef
```

Both must be nullable with default `null` so historical outbox payloads still hydrate after deployment.

Modify `StockBalance::applyReceipt()` to populate them from the movement.

#### Modify `OnStockReceived`

If `goodsReceiptRef !== null`, return. The batch listener owns the projection.

#### New Procurement listener: `OnGoodsReceiptPosted`

File:

```text
app/Domains/Procurement/Application/Listener/OnGoodsReceiptPosted.php
```

Dispatches one `RecordPurchaseOrderReceiptBatch` command with the receipt header and lines.

Register in:

```text
app/Domains/Procurement/ProcurementServiceProvider.php
```

### 7.5 Procurement projection

#### New: `RecordPurchaseOrderReceiptBatch`

Files:

```text
app/Domains/Procurement/Application/Command/RecordPurchaseOrderReceiptBatch.php
app/Domains/Procurement/Application/Command/RecordPurchaseOrderReceiptBatchHandler.php
```

Flow:

1. Lock the PO by number `FOR UPDATE`.
2. Assert status allows receipt (`ISSUED` or `PARTIALLY_RECEIVED`).
3. For each line:
   - Load `PoLine` by `poLineId`.
   - Verify it belongs to the PO, is open, and matches the item or an approved substitute.
   - Append `pcr_po_receipts` with `goods_receipt_ref` and `goods_receipt_line_ref`.
   - Increment `received_qty`.
4. Roll the PO status once.
5. If a line is invalid, log `procurement.receipt.line_exception` and record the receipt with `exception_code`; do not update that line.
6. Idempotency: unique `(tenant_id, goods_receipt_line_ref)` plus the command idempotency key.

#### Modify `PoReceipt`

Add fields and casts:

```text
goods_receipt_ref
goods_receipt_line_ref
exception_code
```

#### Modify `PoLine`

Add `STATUS_CLOSED_SHORT`.

#### Modify `PurchaseOrderLifecycle` / rollup

- Treat `CLOSED_SHORT` lines as satisfied.
- If any line is closed short and all lines are satisfied, status is `CLOSED_SHORT`.
- If all lines are fully received, status is `FULLY_RECEIVED`.
- Never let a receipt move a `CLOSED_SHORT` or `CANCELLED` line.

#### New: `ClosePurchaseOrderShort`

Files:

```text
app/Domains/Procurement/Application/Command/ClosePurchaseOrderShort.php
app/Domains/Procurement/Application/Command/ClosePurchaseOrderShortHandler.php
app/Domains/Procurement/Presentation/Http/Controller/ClosePurchaseOrderShortController.php
```

Rules:

- Requires `procurement.orders.amend`.
- Closes selected open lines or all remaining lines.
- Requires a reason.
- Writes audit and amendment history.
- Recalculates PO status.

### 7.6 Procurement search and price references

Modify:

```text
app/Domains/Procurement/Contracts/ProcurementApi.php
app/Domains/Procurement/Application/ProcurementApiService.php
app/Domains/Procurement/Infrastructure/Reader/EloquentPurchaseOrderReader.php
```

Add:

```text
searchOpenPurchaseOrders(facilityId, query, page, perPage)
searchOpenPurchaseOrderLines(poId, query, page, perPage)
getItemPriceReferences(supplierId, itemId)
```

`getItemPriceReferences` returns:

- last purchase price and date for the supplier/item
- active contract price and remaining ceiling
- last purchase price across all suppliers
- currency (always TZS)

No new table is required for price history; `pcr_po_lines` and `pcr_contract_lines` already hold it.

### 7.7 Procurement HTTP additions

#### Receipt search endpoints (Inventory presentation, Procurement data)

```text
GET  /workspaces/inventory/receipts/open-orders
GET  /workspaces/inventory/receipts/open-orders/{purchaseOrderId}/lines
```

These are JSON endpoints used by the workbench; they do not render Inertia pages.

#### Goods receipt endpoints

```text
POST /workspaces/inventory/goods-receipts
GET  /workspaces/inventory/goods-receipts
GET  /workspaces/inventory/goods-receipts/{goodsReceiptId}
GET  /workspaces/inventory/goods-receipts/{goodsReceiptId}/pdf
POST /workspaces/inventory/goods-receipts/import
POST /workspaces/inventory/goods-receipts/{goodsReceiptId}/invoice        (P3)
POST /workspaces/inventory/goods-receipts/{goodsReceiptId}/match          (P3)
```

Keep the existing route for one release:

```text
POST /workspaces/inventory/receipts
```

It becomes a compatibility adapter that creates a one-line GRN.

#### Procurement additions

```text
GET  /workspaces/procurement/prices
POST /workspaces/procurement/orders/{purchaseOrderId}/close-short
POST /workspaces/procurement/orders/import-preview
POST /workspaces/procurement/requests/import-preview
GET  /workspaces/procurement/rfqs                              (P2B)
POST /workspaces/procurement/rfqs                              (P2B)
POST /workspaces/procurement/rfqs/{rfqId}/issue                (P2B)
POST /workspaces/procurement/rfqs/{rfqId}/quotations           (P2B)
POST /workspaces/procurement/rfqs/{rfqId}/award                (P2B)
GET  /workspaces/procurement/rfqs/{rfqId}/compare              (P2B)
GET  /workspaces/procurement/contracts                         (P2B)
POST /workspaces/procurement/contracts                         (P2B)
POST /workspaces/procurement/contracts/{contractId}/activate   (P2B)
```

### 7.8 Permissions

Existing permissions stay:

```text
inventory.access
inventory.receipts.create
procurement.access
procurement.suppliers.manage
procurement.requisitions.create
procurement.requisitions.approve
procurement.orders.create
procurement.orders.approve
procurement.orders.issue
procurement.orders.amend
```

Add:

| Permission | Purpose | Default role |
|---|---|---|
| `inventory.receipts.override` | Over-receipt, substitution, expired-batch override with reason | Store manager, facility admin |
| `inventory.receipts.view` | View GRN register/detail/print | Store clerk, store manager, finance |
| `procurement.invoices.match` | Attach/match invoices and clear variances | Finance |
| `procurement.contracts.manage` | Create/activate/amend/renew contracts | Procurement officer |
| `procurement.rfqs.manage` | Create/issue/award RFQs and record quotations | Procurement officer |

Update:

```text
app/Domains/Inventory/Authorization/permissions.php
app/Domains/Procurement/Authorization/permissions.php
app/Domains/Identity/Contracts/FacilityAdminRoleDefinition.php
```

Add a migration to grant new permissions to existing facility-admin roles, following the existing `grant_inventory_access_to_facility_admins` pattern.

### 7.9 Authorization rules

- JSON open-order/line endpoints: `inventory.receipts.create` + `procurement.access`.
- GRN register/detail/print: `inventory.receipts.view` or `inventory.access`.
- Invoice match: `procurement.invoices.match`.
- Close short: `procurement.orders.amend`.
- Every query is facility-scoped; a PO from another facility returns 404, not 403.
- All writes go through the command bus, which re-checks policy.

---

## 8. Frontend changes

### 8.1 Component structure

The implemented receipt flow is the multi-line goods-receipt workbench:

```text
resources/js/features/inventory/components/panels/GoodsReceiptWorkbenchPanel.tsx
POST /workspaces/inventory/goods-receipts
```

The former single-line **Receive stock** panel, its item/location shortcuts,
and its `POST /workspaces/inventory/receipts` HTTP adapter have been removed.
Use the accessible panel name **“Receive delivery”** and primary action
**“Post goods receipt”**.

### 8.2 Panel behavior

`DockedPanel`:

```text
heading="Receive delivery"
width="min(96rem, 100%)"
expandedWidth="100vw"
expandable
primaryLabel="Post goods receipt"
```

On open:

- do not use the full `openPurchaseOrders` page prop
- fetch open orders from `GET /workspaces/inventory/receipts/open-orders`
- fetch lines only after a PO is selected

On success:

- do **not** close
- show a success notification with the GRN number
- show **Print GRN**
- show **Receive another delivery**
- refresh only `balances`, `locationItems`, `receipts`, `openPurchaseOrders` via Inertia partial reload

### 8.3 Header form

Carbon components:

| Field | Component | Notes |
|---|---|---|
| Purchase order | `SearchableComboBox` | Optional; server search |
| Supplier | read-only text or `SearchableComboBox` | Auto from PO; manual for non-PO |
| Destination store | `Select` | Active locations only |
| Delivery note | `TextInput` | Required for PO unless “No delivery note” + reason |
| Invoice number | `TextInput` | Optional; status `PENDING` if blank |
| Invoice date | `TextInput type="date"` or Carbon `DatePicker` | Optional |
| Invoice total | `TextInput type="number"` | Optional; P3 |
| Received on | `TextInput type="datetime-local"` | Defaults to now |
| Received by | read-only | Current user |
| Remarks | `TextArea` | Row-only |

Use a two-column responsive layout; stack on narrow screens.

### 8.4 Lines table

Columns:

```text
Line | Item | Ordered | Received before | Remaining | Delivered now | Rejected | Accepted | Unit | Batch | Expiry | Condition | Notes
```

Behavior:

- Sticky header and first column.
- Page size 50 or 100; pagination is client-side for the loaded receipt draft.
- Search/filter applies to the current PO lines.
- **Receive all remaining** fills `Delivered now` with `remaining`.
- **Clear quantities** sets all to zero.
- **Import CSV/XLSX** opens a preview dialog.
- **Add manual line** is available for non-PO receipts and substitutions.
- Batch/expiry inputs appear only when the item requires them.
- Serial items show a clear “serial receiving not enabled” warning until Phase 3.
- Each cell error is inline; a summary shows `N lines need attention`.

Validation:

- `Delivered now` must be numeric and `>= 0`.
- A line with `Delivered now = 0` and `Rejected = 0` is ignored.
- `Rejected <= Delivered`.
- Aggregate `Accepted` per PO line must not exceed remaining unless override permission is used with a reason.
- Expiry cannot be in the past.
- Substitution requires a reason.

### 8.5 Summary and submit

Sticky footer summary:

```text
42 lines · 1,200 accepted · 12 rejected · 3 exceptions
```

Buttons:

```text
Cancel        Record receipt
```

Submit disabled while:

- no location
- no lines with accepted quantity
- any blocking validation error
- processing

### 8.6 Receipts register

Add an Inventory section:

```text
Receipts
```

Files:

```text
resources/js/features/inventory/components/ReceiptsSection.tsx
resources/js/features/inventory/components/ReceiptDetailPanel.tsx
```

Features:

- list GRNs with date, supplier, PO, delivery note, invoice, lines, status
- search by GRN/PO/invoice/delivery note/supplier
- filter by date, match status
- open detail; print GRN
- label legacy receipts clearly

Add `receipts` to:

```text
resources/js/layouts/AppLayout/navigation.ts
app/Domains/Inventory/Presentation/Http/Controller/InventoryWorkspaceController.php
resources/js/features/inventory/components/InventoryWorkspace.tsx
```

### 8.7 Procurement UI updates

#### Requests and Orders

- Raise the visible line counter to 500.
- Add **Import lines** (CSV/XLSX) with a preview and error report.
- Keep add/remove line, but support 500 lines without rendering all rows at once; paginate the editor.
- Show cost centre/budget reference fields (P2).
- Show last price and contract price when an item is selected; auto-fill unit price (P2).
- Warn on a suspected duplicate direct PO (P2).
- Add **Close short** action on partially received POs (P1/P2).
- Preserve exact row-action labels:

```text
Submit for approval
Approve
Issue to supplier
Cancel
Reject
Edit draft
Create purchase order
Manage
Export Excel
```

#### RFQ / Quotations / Contracts — Phase 2B, conditional

Only build if the hospital actually runs competitive quotations. The backend already has:

- `CreateRfq`, `IssueRfq`, `CloseRfq`, `CancelRfq`, `AwardRfq`
- `RecordQuotation`, `ReviseQuotation`, `WithdrawQuotation`
- `CompareQuotations`, `SubmitEvaluation`, `ReviseEvaluation`
- `CreateContract`, `ActivateContract`, `AmendContract`, `RenewContract`, `TerminateContract`, `ExpireContract`

Add presentation sections only:

```text
resources/js/features/procurement/RfqSection.tsx
resources/js/features/procurement/QuotationComparison.tsx
resources/js/features/procurement/ContractsSection.tsx
```

Add controllers/routes for the existing commands. Do not rebuild the domain.

### 8.8 Carbon and layout rules

- Use Carbon `Button`, `IconButton`, `TextInput`, `TextArea`, `Select`, `SearchableComboBox`, `InlineNotification`, `Tag`, `Table`, `Pagination`, `Tabs`, `Modal`, `FileUploader`.
- Use existing `afy-section__heading`, `afy-section__title`, `afy-section__description` for section headings.
- Use `.afy-facts--inline` for compact summaries.
- No persistent explanation alerts. Use helper text or inline validation.
- No nested cards. One section, one table.
- Square corners; no rounded card stacks.
- Keep the panel expand icon beside the close icon.

### 8.9 Accessibility

- Every input has a visible or `hideLabel` label.
- Table has `aria-label="Receipt lines"`.
- Error text is linked with `aria-describedby`.
- Keyboard order follows the visual order.
- Success/error notifications are announced.
- Do not remove the accessible names used by tests unless the test is deliberately updated.

### 8.10 Frontend types and routes

Modify:

```text
resources/js/features/inventory/types.ts
resources/js/app/routes.ts
resources/js/features/procurement/types.ts
resources/js/features/procurement/index.ts
resources/js/pages/Inventory/Index.tsx
resources/js/pages/Procurement/Index.tsx
```

Add types:

```text
GoodsReceipt
GoodsReceiptLine
ReceiptDraft
ReceiptLineDraft
ReceivablePurchaseOrderSummary
ReceivablePurchaseOrderLine
PriceReference
```

Add routes:

```text
inventoryGoodsReceiptsStore
inventoryGoodsReceiptsIndex
inventoryGoodsReceipt
inventoryGoodsReceiptPdf
inventoryOpenOrders
inventoryOpenOrderLines
inventoryGoodsReceiptImport
procurementPrices
procurementOrderCloseShort
procurementOrderImportPreview
procurementRequestImportPreview
```

Remove `openPurchaseOrders` from `InventoryWorkspaceProps` after the workbench uses the JSON endpoints. Keep it optional for one release if needed.

---

## 9. Phase plan

### Phase 0 — Correctness first (Week 1)

**Goal:** stop wrong-line postings, remove the 50-line cap, and stop full reloads.

**Progress (2026-10-21):**
- **P0-01 complete.** The receiving form now sends `po_line_id`; the request requires it for `PROCUREMENT`, validates it against the selected PO, and the stock movement persists it as `po_line_ref`. The Procurement projection uses that exact line and only falls back to item matching for legacy events without a line reference.
- **P0-02 complete.** Purchase-order and requisition validation now accepts up to 500 lines; both editors show a live line count and disable “Add line” at 500. Regression coverage proves 500 lines are accepted and 501 are rejected.
- **P0-03 complete.** The Inventory page no longer receives every open PO/line. `GET /workspaces/inventory/receipts/open-orders` searches PO number, supplier, item code/name and line number with compact counts; `GET /workspaces/inventory/receipts/open-orders/{id}/lines` pages the selected order's open STOCK lines. The receiving panel debounces both searches.
- **P0-04 complete.** A successful receipt uses `preserveState` plus a partial reload (`only`) and keeps the panel open. Entry fields, batch/expiry/supplier reference and idempotency keys reset; the selected PO line remains selected and its open lines refresh. A fully received line is cleared cleanly.
- **P0-05 complete.** Both search endpoints require `inventory.receipts.create` and an active facility; `procurement.access` is enforced through the PO `view` policy. Cross-facility PO IDs return 404 and are absent from search results.
- **P0-06 complete for the changed surfaces.** New backend coverage (`ReceivingSearchHttpTest`, 7 tests) covers search, paging, zero-remaining exclusion, permissions and cross-facility isolation. Existing Inventory/Procurement suites and all 548 frontend tests pass. The full backend suite still has unrelated pre-existing failures (arch/security/encounter); no Inventory or Procurement failures remain. Full `npx eslint resources/js` and `npx tsc --noEmit` pass.
- **VAT removed.** No VAT/tax fields, rates, codes, totals or tax-based matching are part of this plan.

| ID | Ticket | Files | Acceptance | Est. |
|---|---|---|---|---|
| P0-01 | Make `po_line_id` authoritative end-to-end | `ReceiveStockPanel.tsx`, `ReceiveStockRequest.php`, `ReceiveStock.php`, `ReceiveStockHandler.php`, `StockMovement.php`, `StockBalance.php`, `StockReceived.php`, `OnStockReceived.php`, `RecordPurchaseOrderReceipt.php`, handler, migration | Two lines with the same item on one PO receive to the selected line; wrong line ID is rejected | 2d |
| P0-02 | Remove the 50-line cap | `StorePurchaseOrderRequest.php`, `StorePurchaseRequisitionRequest.php` | 500 lines accepted; 501 rejected; UI counter shows 500 max | 1d |
| P0-03 | Server-side open PO/line search | `ProcurementApi.php`, `ProcurementApiService.php`, `EloquentPurchaseOrderReader.php`, new Inventory queries/controllers | 500-line PO searchable in pages; no all-PO injection | 2d |
| P0-04 | Partial reload after receipt | `ReceiveStockPanel.tsx`, `InventoryWorkspace.tsx`, `ReceiveStockController.php` | Panel stays open; only balances/lines/receipts refresh | 1d |
| P0-05 | Authorization and facility guards | new controllers/requests, routes | No permission 403; other facility 404; no cross-facility leakage | 1d |
| P0-06 | Regression tests for baseline UI | `inventory-catalogue.test.tsx`, `inventory-pack.test.tsx`, procurement tests | Existing 33 procurement + 47 inventory panel tests still pass or are deliberately updated | 1d |

**Phase 0 gate:**

- `npx tsc --noEmit`
- `npx eslint resources/js --max-warnings=0`
- `npx vitest run`
- `php artisan test --filter=ReceiveAgainstPurchaseOrder`
- `php artisan test --filter=PurchaseOrderReceipt`
- A 100-line PO receives to the selected line with no full reload.

### Phase 1 — Receipt workbench (Weeks 2–4)

**Goal:** one delivery, one header, many lines, one submit, one GRN.

| ID | Ticket | Files | Acceptance | Est. | Status |
|---|---|---|---|---|---|
| P1-01 | Create goods receipt schema and GRN counter | migrations, `GoodsReceipt`, `GoodsReceiptLine`, repository, sequence | Header/lines persist; GRN number is unique and sequential | 3d | Done |
| P1-02 | Build `RecordGoodsReceipt` and shared recorder | command, handler, `GoodsReceiptRecorder` | One transaction posts header + lines + movements + batches; any line failure rolls back all | 3d | Done |
| P1-03 | Add `GoodsReceiptPosted` batch projection | event, listener, `RecordPurchaseOrderReceiptBatch`, `OnStockReceived` guard | 500-line receipt produces one PO projection job; per-line `StockReceived` listeners exit immediately; PO lines update exactly | 2d | Done |
| P1-04 | Workbench shell and header form | `GoodsReceiptWorkbenchPanel.tsx` | Panel opens, loads PO, header validates | 3d | Done |
| P1-05 | Editable lines table | `ReceiptLinesTable.tsx`, `ReceiptLineRow.tsx` | Receive all, edit quantities, batch/expiry, inline errors | 5d | Done |
| P1-06 | Bulk import and validation | workbench paste dialog, client-side validation | CSV paste preview; 300 lines imported in under 2 minutes | 3d | Done — CSV paste; XLSX/file upload deferred |
| P1-07 | Submit, success and GRN print | controller, print controller, blade view, `ReceiptSuccess.tsx` | One submit; GRN shown; PDF prints | 3d | Done |
| P1-08 | Receipts register and detail | `ReceiptsSection.tsx`, `ReceiptDetailPanel.tsx`, queries | Search/filter GRNs; open detail; reprint | 2d | Done |
| P1-09 | Short delivery and accepted/rejected quantities | handler, line table, projection | Delivered, rejected and accepted are distinct; rejected does not post | 2d | Done |
| P1-10 | Close short | `ClosePurchaseOrderShort` command/controller, PO UI | Remaining balance closes with reason; PO status `CLOSED_SHORT` | 2d | Done |
| P1-11 | Substitution | receipt line, validator, UI | Substitute item posts; original PO line still receives the quantity; reason required | 2d | Done |
| P1-12 | Bulk PO/requisition line import | `OrdersSection.tsx`, `RequestsSection.tsx`, paste dialogs | 300-line PO created from CSV in under 5 minutes | 3d | Done — CSV paste; XLSX/file upload deferred |
| P1-13 | Performance and tests | feature tests, frontend tests, 500-line fixture | All Phase 1 gates pass | 3d | Done — automated gates; manual 100/500-line UI timing pending |

**Phase 1 gate:**

- 100-line delivery in under 30 minutes.
- 500-line delivery in under 2 hours.
- One GRN per delivery.
- Zero wrong-line postings in a duplicate-item test.
- `accepted` only posts; `rejected` is visible on the GRN.
- Print, search, register and partial reload work.
- All backend and frontend tests pass.

**Phase 1 implementation status (2026-10-21):**

- All Phase 1 tickets are implemented. A 500-line delivery posts as one GRN
  (500 lines and 500 movements) in roughly 10 seconds in the feature test, and
  one `GoodsReceiptPosted` projection updates all 500 PO lines and rolls the PO
  to `FULLY_RECEIVED` within the 15-second scenario gate.
- The duplicate-item test posts to the selected `po_line_id` only; the sibling
  line stays at zero. Accepted-only posting, rejected-line visibility, batch
  creation, close-short, substitution, register, detail, print and partial
  reload are covered by backend and frontend tests.
- Bulk entry is implemented as CSV paste in the receipt workbench and in the PO
  and requisition editors. XLSX/file-upload parsing is deliberately deferred;
  paste handles the 300-line operational case without adding a spreadsheet
  dependency.
- The manual 100-line (<30 min) and 500-line (<2 h) UI timing gate still needs
  a real browser run with a warehouse user. The server-side gate is automated.
- Targeted suites pass: 336 Inventory/Procurement backend tests and 63
  inventory/procurement frontend tests. All 553 frontend tests report passing,
  but the Vitest runner still exits 1 on its known worker `onTaskUpdate`
  timeout. The full backend suite still has unrelated pre-existing
  architecture/security/encounter failures.

### Phase 2 — Buying controls (Weeks 5–6)

**Goal:** prevent duplicate commitments and make price entry fast and traceable.

| ID | Ticket | Files | Acceptance | Est. |
|---|---|---|---|---|
| P2-01 | Add cost centre / budget reference | requisition/PO migrations, commands, types, forms | Cost centre saved and shown on PO; exported | 1d |
| P2-02 | Add price references and auto-fill | `getItemPriceReferences`, `OrdersSection.tsx` | Last price and contract price shown; unit price auto-fills | 3d |
| P2-03 | Prevent duplicate direct POs | `CreatePurchaseOrderHandler.php`, UI warning | Exact duplicate blocked or warned with reason and audit | 1d |
| P2-04 | Expose RFQ/quotation/contract UI | new sections, controllers, routes | Create RFQ, record 3 quotes, compare, award, create PO | 5–8d |
| P2-05 | Contract price/ceiling in PO | contract reader/API, UI | Contract price wins; ceiling enforced | 2d |

**Phase 2 gate:**

- Contract price fills automatically.
- Duplicate direct PO is blocked or explicitly overridden.
- Cost centre appears on the PO and export.
- If Phase 2B is in scope: three quotes compared in one screen.

Phase 2B (RFQ/contract UI) is conditional. If the hospital buys directly from local distributors without formal quotes, ship P2-01 to P2-03 and defer P2-04/P2-05.

**Phase 2A implementation status (2026-10-21):**

- P2-01: Buyers can capture cost-centre and budget references on requests and
  POs, and retain them when editing a draft request. References are persisted,
  inherited from linked requisitions when omitted, shown on expanded requests
  and POs/printable POs, and included in exports.
- P2-02: Buyers can request facility-scoped price references per item. The
  workbench shows the last supplier price, last any-supplier price, and active
  contract price/remaining ceiling; an empty unit price is filled from the
  latest available historical price. Contract references remain advisory here;
  contract-sourced ordering is required to enforce the contracted price/ceiling.
- P2-03: Exact duplicate active direct POs are blocked unless the buyer enters
  a reason. Overrides are audited. The create form now sends that reason on
  retry.
- Focused verification passes: 5 purchase-order backend feature tests
  (32 assertions, including duplicate blocking/auditing and cost-centre
  persistence), 30 purchase-order/requisition frontend tests, TypeScript
  checking, and targeted ESLint.
- P2-04/P2-05 remain deferred with conditional Phase 2B pending the formal
  RFQ/contract scope decision. The Phase 2 gate is therefore not yet complete.

### Phase 3 — Finance and exceptions (Weeks 7–8)

**Goal:** match delivery to invoice and handle returns without editing history.

| ID | Ticket | Files | Acceptance | Est. |
|---|---|---|---|---|
| P3-01 | Invoice capture and attachment | receipt header/lines, `AttachGoodsReceiptInvoice` | Invoice number/date/total attached; pending status visible | 2d |
| ~~P3-02~~ | Invoice matching and variance flags | `MatchGoodsReceiptInvoice`, queries, UI | **Implemented** — quantity and price variances flagged; CSV export available | 3d |
| ~~P3-03~~ | ~~Tax fields~~ | **Dropped — VAT excluded** | Do not add tax fields, rates, codes or totals | — |
| ~~P3-04~~ | Supplier returns / quarantine | new return document, movement type, UI | **Implemented** — damaged goods after posting produce an immutable return and stock OUT | 4d |
| ~~P3-05~~ | Supplier licence expiry | supplier fields, UI reminders | **Implemented** — missing, expired, and next-30-day expiry states shown; no PO block | 2d |
| ~~P3-06~~ | ~~Serial receiving~~ | **Deferred — not authorized** | Keep the current clear block and exception process | — |

Serial receiving is **out of scope for this delivery**. Do not build `inv_item_serials` or remove the current serial block unless the hospital separately approves it.

**Phase 3 scope decisions (2026-10-21):**

- One invoice belongs to one GRN in this MVP. A single invoice covering multiple
  deliveries is deferred until Finance confirms it is needed.
- Capture relevant supplier licence details and show expiry warnings. Do not
  blanket-block purchase orders; any future category-specific block requires
  compliance confirmation.
- Prioritize invoice review/matching, immutable supplier returns/quarantine,
  and licence reminders. Keep the RFQ/contract UI conditional and deferred.

**Phase 3 implementation status (2026-10-22):**

- P3-01 invoice metadata capture is implemented at receipt time and can now be
  attached later to an already-posted GRN (invoice number, date, TZS total).
  This stores invoice references only; it does not upload/store a scanned
  invoice file or post anything to a ledger. Updating invoice details does not
  modify receipt lines or stock movements.
- P3-02 matches each invoice line's quantity to accepted receipt quantity in
  the recorded buying unit and compares the invoice unit price to a PO unit
  price snapshot from the exact PO line. Variances are visible in the GRN
  detail and exportable as a finance-only CSV. Unit mismatches and older GRNs
  without price snapshots are flagged for manual review; they are never treated
  as a successful price match. Attaching/replacing invoice details clears
  previous match values and status. Matching does not modify posted stock.
- Since VAT and tax calculations are out of scope, invoice total remains a
  captured reference; P3-02 does not claim to reconcile tax, freight, or other
  invoice-level charges to the line subtotal.
- P3-04 supplier returns/quarantine is implemented. Returns are posted from a
  GRN detail against accepted receipt lines, with a required reason and source
  location. Each return is an append-only `RET-*` document linked to its GRN
  and exact receipt lines; the compensating `RETURN_OUT` movement reduces
  on-hand stock without editing the GRN or prior movements. Returns cannot
  exceed the accepted quantity across all prior returns or available stock in
  the selected location. The built-in `QUARANTINE` location type allows stock
  to be transferred into quarantine before a return is posted.
- P3-05 supplier licence number, issuing authority, and expiry date are recorded
  on the supplier master. The supplier list distinguishes missing, expired,
  expiring within 30 days, and current licences; authorised supplier managers
  can update details. These are reminders only and do not change PO eligibility.
- Focused verification: 14 goods-receipt backend feature tests pass, including
  invoice matching/CSV and immutable return history, 148 assertions; 35
  inventory frontend tests pass. Frontend TypeScript, targeted ESLint and PHP
  formatting checks pass.
- P3-05 verification: supplier HTTP and domain suites pass (14 tests, 102
  assertions), including proof that an expired licence does not make an active
  supplier unorderable; supplier and purchase-order frontend suites pass (22
  tests). TypeScript, targeted ESLint, and PHP formatting checks pass.

#### Supplier return document (P3)

Add:

```text
inv_supplier_returns
  id, tenant_id, facility_id, return_number, receipt_id, supplier_ref,
  location_id, reason, status, occurred_at, recorded_at, version, timestamps

inv_supplier_return_lines
  id, tenant_id, facility_id, return_id, receipt_line_id, line_no, item_id, batch_id,
  quantity, uom, movement_id, credit_note_ref, notes
```

Return types:

- `DAMAGED_ON_ARRIVAL` — usually handled at receipt, no stock posted
- `DAMAGED_AFTER_RECEIPT` — stock OUT to quarantine/supplier
- `WRONG_ITEM` — stock OUT and replacement receipt later
- `EXPIRED` — stock OUT and disposal/quarantine

Add movement type `RETURN_OUT` and a `QUARANTINE` stock-location type. Never edit the original GRN or stock movement.

**Phase 3 gate:**

- Invoice variance is visible and exportable.
- A damaged delivery after posting creates a return, not an edit.
- Invoice quantities and PO unit prices are compared per line; tax and other
  invoice-level charges are explicitly not calculated.
- Serial-tracked items remain clearly blocked with a documented exception process.

### Phase 4 — Verify and roll out (Week 9)

| ID | Ticket | Acceptance |
|---|---|---|
| P4-01 | Load test | 500-line fixture meets the metrics in Section 2.2 |
| P4-02 | UAT with store staff | Clerk completes a real 300-line delivery unaided |
| P4-03 | Training and documentation | One-page clerk guide, one-page finance guide, GRN sample |
| P4-04 | Pilot and cutover | One week parallel run; no stock differences; rollback tested |
| P4-05 | Post-go-live reconciliation | Daily PO vs GRN vs stock report for two weeks |

**Final gate:** the definition of done in Section 15 is fully checked.

---

## 10. Test plan

### 10.1 Backend feature tests

Add or update:

```text
tests/Feature/Domains/Inventory/GoodsReceiptHttpTest.php
tests/Feature/Domains/Inventory/GoodsReceiptTest.php
tests/Feature/Domains/Inventory/GoodsReceiptSearchTest.php
tests/Feature/Domains/Inventory/GoodsReceiptPrintTest.php
tests/Feature/Domains/Inventory/GoodsReceiptImportTest.php
tests/Feature/Domains/Inventory/ReceiveAgainstPurchaseOrderHttpTest.php
tests/Feature/Domains/Inventory/ReceiveStockTest.php
tests/Feature/Domains/Procurement/PurchaseOrderReceiptTest.php
tests/Feature/Domains/Procurement/ProcurementOrdersHttpTest.php
tests/Feature/Domains/Procurement/ProcurementRequestsHttpTest.php
tests/Feature/Domains/Procurement/SupplierTest.php
```

Must cover:

1. 500-line PO accepted; 501 rejected.
2. 500-line receipt posts 500 movements and one GRN.
3. Same item on two PO lines posts to the selected `po_line_id`.
4. Wrong `po_line_id` rejected; no stock written.
5. Partial receipt leaves the correct remaining.
6. Delivered 10, rejected 2 → accepted 8 posts; 2 rejected does not.
7. Batch/expiry required where item flags require it.
8. Expired batch rejected; no partial writes.
9. Duplicate submit returns the same GRN; no double stock.
10. GRN number unique and sequential.
11. Invoice number/date persisted; pending status allowed.
12. Close short closes remaining and sets PO status.
13. Substitution posts the substitute item and satisfies the original line with reason.
14. Search by PO number, supplier, item code, item name; pagination.
15. Facility isolation and permission failures.
16. GRN PDF contains the GRN, PO, supplier, delivery note, invoice and totals.
17. Import preview returns row-level errors; no stock posted on preview.
18. 500-line performance test below targets.

### 10.2 Frontend tests

Add or update:

```text
resources/js/test/goods-receipt-workbench.test.tsx
resources/js/test/inventory-catalogue.test.tsx
resources/js/test/inventory-pack.test.tsx
resources/js/test/navigation.test.tsx
resources/js/test/procurement-orders.test.tsx
resources/js/test/procurement-requests.test.tsx
```

Must cover:

- panel opens and loads orders from the endpoint
- search and pagination
- receive all / clear
- inline validation
- one submit payload with header + lines
- success shows GRN and print
- partial reload called with `only`
- panel stays open
- 500-line editor renders one page at a time
- import preview dialog
- accessible names and keyboard flow

### 10.3 End-to-end tests

Add Playwright scenarios:

```text
tests/e2e/receiving/100-line-delivery.spec.ts
tests/e2e/receiving/500-line-delivery.spec.ts
tests/e2e/receiving/invoice-match.spec.ts
```

Scenario:

1. Create/seed a 100-line PO.
2. Open Receive delivery.
3. Select PO, enter header.
4. Receive all.
5. Submit.
6. Print GRN.
7. Verify PO line quantities and stock balances.

Run the 500-line scenario nightly or before release, not on every commit.

### 10.4 Commands

```bash
# Backend
php artisan migrate
php artisan test --filter=GoodsReceipt
php artisan test --filter=PurchaseOrderReceipt
php artisan test --filter=Inventory
php artisan test --filter=Procurement

# Static / conformance
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
php artisan typescript:transform
php artisan afyanova:conformance

# Frontend
npx tsc --noEmit
npx eslint resources/js --max-warnings=0
npx vitest run
npm run build
```

---

## 11. Rollout and migration

### 11.1 Pre-flight

- The working directory is not a git repository. Create a version-control baseline or archive before code changes:
  ```bash
  git init
  git add -A
  git commit -m "baseline before receiving workbench"
  ```
  If git is not desired, create a timestamped archive outside the project.
- Run the current baseline checks and record the results.
- Confirm the database is migrated to the latest existing migration.
- Confirm queue workers and `outbox:relay` are running in the target environment.

### 11.2 Deployment order

1. Deploy migrations for Phase 0 (line refs and counters only).
2. Deploy backend commands/events with feature flag off.
3. Deploy frontend workbench behind a config flag.
4. Enable for one store.
5. Run a real 100-line delivery in parallel with the old flow.
6. Enable for all stores.
7. The legacy single-line panel and HTTP adapter were removed on 2026-10-22
   following the user's approval; there is no parallel receiving workflow.

Suggested config:

```php
// config/inventory.php
return [
    'receiving' => [
        'workbench' => env('INVENTORY_RECEIVING_WORKBENCH', false),
        'max_lines' => env('INVENTORY_RECEIVING_MAX_LINES', 500),
        'allow_over_receipt' => env('INVENTORY_ALLOW_OVER_RECEIPT', false),
    ],
];
```

### 11.3 Pilot

- One store, one supplier, three deliveries: small, medium, 300+ lines.
- Compare GRN totals to delivery note and invoice.
- Compare PO `received_qty` to the GRN.
- Compare stock ledger sum to on-hand balance.
- Collect clerk feedback on search, keyboard flow and error messages.

### 11.4 Rollback

- If the workbench is unavailable, pause receiving and restore the workbench
  release; the retired single-line endpoint is not a rollback path.
- Do not delete posted GRNs or stock movements.
- If a projection fails, replay the outbox event; do not manually edit `received_qty`.
- If a stock movement is wrong, post a compensating return/adjustment with reason.

---

## 12. Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Stock posts but PO projection fails | PO shows less received than stock | Outbox retry, idempotency, daily reconciliation report |
| Two clerks receive the same PO line | Over-receipt | Request-time remaining check, line-level aggregate validation, optional override permission |
| 500-line transaction is slow | Timeout | Single transaction but batch-load items/batches; measure; 30s+ timeout; no N+1 |
| 500 per-line outbox events add queue overhead | Slow projection | Measure; `OnStockReceived` exits immediately for new receipts; if overhead is material, add a batch stock-fact event or suppress per-line events for goods receipts |
| Batch/expiry data is wrong | Expired stock admitted | Server-side validation, expiry check, GRN print for verification |
| Invoice arrives after goods | Finance cannot match | `PENDING` invoice status; attach later; do not block receiving |
| Duplicate direct PO | Double commitment | Duplicate warning/block with audit |
| Users keep using old panel | Two workflows | Resolved 2026-10-22: legacy panel and HTTP adapter removed |
| Serial items blocked | Implants cannot be received | Out of scope; keep the clear block and document the exception process |
| VAT/tax scope creep | Tax fields reappear | Out of scope; do not add tax fields, rates, codes or totals without a new decision |
| Legacy receipts lack GRN | Audit gap | Label as legacy; do not invent documents; reconciliation report |

---

## 13. Decisions and remaining questions

### 13.1 Resolved

1. **VAT:** Excluded by user decision. Do not add VAT fields, rates, codes, totals or tax-based matching. Mainland Tanzania remains the operational context only.
2. **Serial-tracked items:** Out of scope for this delivery. Keep the current clear block and document the exception process. Do not build serial receiving unless separately approved.

### 13.2 Remaining product decisions

Answer these before the phase that needs them:

1. **Should over-receipt be blocked?**
   - Recommended: block by default; allow `inventory.receipts.override` with reason.
2. **Is a delivery note mandatory for PO receipts?**
   - Recommended: yes, with an explicit “no delivery note” reason.
3. **Is one destination store per GRN acceptable?**
   - Recommended: yes for Phase 1. Line-level delivery locations can be added later.
4. **Does one invoice cover multiple deliveries?**
   - If yes, Phase 3 needs an invoice header that can link to multiple GRNs. If no, one invoice per GRN is enough.
5. **Do you use formal RFQs/contracts?**
   - If no, skip Phase 2B and use last-price/contract-price references only.
6. **Do you need budget enforcement or only cost-centre capture?**
   - Recommended: capture and export now; no budget ledger until finance defines it.

---

## 14. Do not build

- MSD/eLMIS/MEMS/Jazia/PPRA integration
- Import/FX/freight/landed cost
- Branch or central-warehouse transfers
- Full accounts payable or general ledger
- Barcode-first receiving, ASN, EDI, supplier portal
- Consignment stock
- Offline-first mode
- Multi-currency
- A full budget engine
- Serial-number receiving (deferred)
- A custom PDF engine; use the existing Dompdf pattern

---

## 15. Definition of done

A release is done only when all of these are true:

- [ ] A 500-line purchase order can be created or imported.
- [ ] A 500-line delivery can be received in one workbench.
- [ ] One submit creates one GRN, all stock movements and all batches.
- [ ] The selected `po_line_id` is always the line updated.
- [ ] Same-item duplicate lines post correctly.
- [ ] Partial, short, rejected and substituted quantities are recorded.
- [ ] Accepted quantity only posts to stock.
- [ ] Batch and expiry rules are enforced server-side.
- [ ] Invoice number/date are captured; pending invoices are visible.
- [ ] GRN can be printed and reprinted.
- [ ] Receipts register search works by GRN, PO, invoice and supplier.
- [ ] No full page reload after submit.
- [ ] 100 lines < 30 minutes; 500 lines < 2 hours.
- [ ] 500-line submit < 15 seconds p95.
- [ ] All backend, frontend, static and conformance checks pass.
- [ ] One real 300-line delivery completed by a store clerk unaided.
- [ ] Rollback and reconciliation steps tested.

---

## 16. File change index

### New backend files

```text
app/Domains/Inventory/Application/Command/RecordGoodsReceipt.php
app/Domains/Inventory/Application/Command/RecordGoodsReceiptHandler.php
app/Domains/Inventory/Application/Service/GoodsReceiptRecorder.php
app/Domains/Inventory/Application/Query/SearchReceivablePurchaseOrders.php
app/Domains/Inventory/Application/Query/SearchReceivablePurchaseOrderLines.php
app/Domains/Inventory/Application/Query/GetGoodsReceipts.php
app/Domains/Inventory/Application/Query/GetGoodsReceipt.php
app/Domains/Inventory/Contracts/Dto/GoodsReceiptSummary.php
app/Domains/Inventory/Contracts/Dto/GoodsReceiptLineSummary.php
app/Domains/Inventory/Contracts/Dto/ReceivablePurchaseOrderSummary.php
app/Domains/Inventory/Contracts/Dto/ReceivablePurchaseOrderLineSummary.php
app/Domains/Inventory/Contracts/Event/GoodsReceiptPosted.php
app/Domains/Inventory/Domain/Model/GoodsReceipt.php
app/Domains/Inventory/Domain/Model/GoodsReceiptLine.php
app/Domains/Inventory/Domain/GoodsReceiptRepository.php
app/Domains/Inventory/Infrastructure/Repository/EloquentGoodsReceiptRepository.php
app/Domains/Inventory/Infrastructure/Reader/EloquentGoodsReceiptReader.php
app/Domains/Inventory/Infrastructure/Sequence/GoodsReceiptNumberSequence.php
app/Domains/Inventory/Presentation/Http/Controller/StoreGoodsReceiptController.php
app/Domains/Inventory/Presentation/Http/Controller/SearchReceivablePurchaseOrdersController.php
app/Domains/Inventory/Presentation/Http/Controller/SearchReceivablePurchaseOrderLinesController.php
app/Domains/Inventory/Presentation/Http/Controller/GoodsReceiptIndexController.php
app/Domains/Inventory/Presentation/Http/Controller/ShowGoodsReceiptController.php
app/Domains/Inventory/Presentation/Http/Controller/PrintGoodsReceiptController.php
app/Domains/Inventory/Presentation/Http/Controller/ImportGoodsReceiptController.php
app/Domains/Inventory/Presentation/Http/Request/StoreGoodsReceiptRequest.php
app/Domains/Inventory/Presentation/Http/Request/ImportGoodsReceiptRequest.php
app/Domains/Procurement/Application/Command/RecordPurchaseOrderReceiptBatch.php
app/Domains/Procurement/Application/Command/RecordPurchaseOrderReceiptBatchHandler.php
app/Domains/Procurement/Application/Command/ClosePurchaseOrderShort.php
app/Domains/Procurement/Application/Command/ClosePurchaseOrderShortHandler.php
app/Domains/Procurement/Application/Listener/OnGoodsReceiptPosted.php
app/Domains/Procurement/Presentation/Http/Controller/ClosePurchaseOrderShortController.php
resources/views/inventory/documents/goods-receipt.blade.php
```

### Modified backend files

```text
app/Domains/Inventory/Application/Command/ReceiveStockHandler.php
app/Domains/Inventory/Domain/Model/StockBalance.php
app/Domains/Inventory/Domain/Model/StockMovement.php
app/Domains/Inventory/Contracts/Event/StockReceived.php
app/Domains/Inventory/Presentation/Http/Controller/InventoryWorkspaceController.php
app/Domains/Inventory/Presentation/Http/Request/ReceiveStockRequest.php
app/Domains/Inventory/Presentation/Http/routes.php
app/Domains/Inventory/Authorization/permissions.php
app/Domains/Procurement/Application/Listener/OnStockReceived.php
app/Domains/Procurement/Application/Command/RecordPurchaseOrderReceipt.php
app/Domains/Procurement/Application/Command/RecordPurchaseOrderReceiptHandler.php
app/Domains/Procurement/Contracts/ProcurementApi.php
app/Domains/Procurement/Application/ProcurementApiService.php
app/Domains/Procurement/Infrastructure/Reader/EloquentPurchaseOrderReader.php
app/Domains/Procurement/Domain/Model/PoLine.php
app/Domains/Procurement/Domain/Model/PoReceipt.php
app/Domains/Procurement/Domain/Model/PurchaseOrder.php
app/Domains/Procurement/Presentation/Http/routes.php
app/Domains/Procurement/Authorization/permissions.php
app/Domains/Procurement/Presentation/Http/Request/StorePurchaseOrderRequest.php
app/Domains/Procurement/Presentation/Http/Request/StorePurchaseRequisitionRequest.php
app/Domains/Procurement/Presentation/Http/Controller/ProcurementWorkspaceController.php
app/Domains/Identity/Contracts/FacilityAdminRoleDefinition.php
config/inventory.php
```

### New frontend files

```text
resources/js/features/inventory/components/receiving/ReceiptHeaderForm.tsx
resources/js/features/inventory/components/receiving/ReceiptLinesTable.tsx
resources/js/features/inventory/components/receiving/ReceiptLineRow.tsx
resources/js/features/inventory/components/receiving/ReceiptSummary.tsx
resources/js/features/inventory/components/receiving/ReceiptImportDialog.tsx
resources/js/features/inventory/components/receiving/ReceiptSuccess.tsx
resources/js/features/inventory/components/receiving/useReceivableOrders.ts
resources/js/features/inventory/components/receiving/useReceiptDraft.ts
resources/js/features/inventory/components/ReceiptsSection.tsx
resources/js/features/inventory/components/ReceiptDetailPanel.tsx
resources/js/features/procurement/RfqSection.tsx
resources/js/features/procurement/QuotationComparison.tsx
resources/js/features/procurement/ContractsSection.tsx
```

### Modified frontend files

```text
resources/js/features/inventory/components/panels/ReceiveStockPanel.tsx
resources/js/features/inventory/components/InventoryWorkspace.tsx
resources/js/features/inventory/types.ts
resources/js/features/procurement/OrdersSection.tsx
resources/js/features/procurement/RequestsSection.tsx
resources/js/features/procurement/SuppliersSection.tsx
resources/js/features/procurement/types.ts
resources/js/features/procurement/index.ts
resources/js/pages/Inventory/Index.tsx
resources/js/pages/Procurement/Index.tsx
resources/js/layouts/AppLayout/navigation.ts
resources/js/app/routes.ts
resources/js/test/inventory-catalogue.test.tsx
resources/js/test/inventory-pack.test.tsx
resources/js/test/navigation.test.tsx
```

---

## 17. Appendices

### Appendix A — GRN layout

Header:

```text
Hospital name / logo
GOODS RECEIPT NOTE
GRN-00000123
Received: 21 Oct 2026 10:30
Facility / store
Supplier
PO number
Delivery note
Invoice number / date / status
```

Lines table:

```text
# | Item code | Item | Ordered | Previously received | Delivered | Rejected | Accepted | Unit | Batch | Expiry
```

Footer:

```text
Total accepted
Total rejected
Remarks
Received by: __________________  Signature: __________
Store manager: _______________  Signature: __________
Supplier representative: _____  Signature: __________
Page X of Y
```

### Appendix B — CSV import template

```csv
line_no,item_code,delivered_qty,rejected_qty,unit,pack_size,batch_number,expiry_date,manufacture_date,condition,notes
1,PARA-500,100,0,TABLET,,B-2026-01,2027-12-31,2026-01-01,GOOD,
2,GLOVE-M,20,2,PAIR,,B-2026-02,2028-06-30,2026-02-01,DAMAGED,2 pairs torn
```

Rules:

- `line_no` or `item_code` must resolve to a line on the selected PO.
- Unknown or ambiguous item codes are reported as row errors.
- Preview never posts stock.
- Maximum 500 rows per import.

### Appendix C — Sample acceptance scenarios

**Scenario 1 — duplicate item lines**

- PO has line 1 and line 5 for the same item.
- Receive 10 against line 5.
- Assert stock movement and `pcr_po_receipts.po_line_id` point to line 5.
- Assert line 1 `received_qty` remains unchanged.

**Scenario 2 — partial delivery**

- PO line: 100 ordered, 0 received.
- Deliver 60, reject 0.
- Assert line received = 60, remaining = 40, PO = `PARTIALLY_RECEIVED`.

**Scenario 3 — damaged delivery**

- Deliver 100, reject 5 as damaged.
- Assert accepted = 95, stock +95, GRN shows 5 rejected.
- Assert rejected quantity creates no stock movement.

**Scenario 4 — short close**

- PO line: 100 ordered, 60 received.
- Close short the remaining 40 with reason.
- Assert line status `CLOSED_SHORT`, PO status `CLOSED_SHORT`.
- Assert no further receipt can post to that line.

**Scenario 5 — duplicate submit**

- Submit the same `receipt_id` twice.
- Assert one GRN and one set of movements.

**Scenario 6 — 500 lines**

- Seed 500-line PO.
- Receive all, submit.
- Assert 500 movements, 500 receipt lines, one GRN, PO `FULLY_RECEIVED` within 15 seconds.

### Appendix D — Implementation order summary

```text
P0: line ID → cap → search → partial reload → tests
P1: schema → command → batch projection → workbench → import → print → register → close short → substitution → bulk PO import
P2: cost centre → price references → duplicate guard → RFQ/contract UI (conditional)
P3: invoice capture → match → returns → licence → serials (conditional)
P4: load test → UAT → training → pilot → cutover
```

### Appendix E — What not to change

- Carbon row-action labels.
- Existing TZS approval-limit rules.
- Existing immutable stock ledger behavior.
- Existing intra-facility transfer behavior.
- Existing `afy-section__*` heading pattern.
- Existing `approvalLimit` `typeof` guard.
- Existing tests for unrelated Inventory or Procurement behavior.

---

**End of plan.**
