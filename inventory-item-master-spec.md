# Inventory Item Master — Tanzania Fit and Controlled Vocabulary Spec

**Status:** Active. The "now" slice (see §6) is implemented; the deferred slice
(§7) is recorded here and tracked under the inventory roadmap's Inv.0 / Phase 8.

**Companions:** `frontend-foundation-inventory-operations.md` (phased roadmap),
`frontend-foundation-pharmacy.md` (§9 pickup clearance).

---

## 1. Why this doc exists

The Item Master (`inv_items`) carries three reference fields in every row:
`item_type` ("Type"), `category` ("Category"), and `uom` ("Base unit"), plus a
free-text `name`. Before this slice, all of these were **free text** accepted
verbatim by the console command and the HTTP `POST /workspaces/inventory/items`
route. Free text on master data is a known data-quality failure: typo variants
(`MEDICINE` vs `MEDICATION`, `TABLET` vs `TABLETS`) silently break downstream
logic — most sharply, Pharmacy refuses to dispense any item whose `item_type`
is not exactly `MEDICATION`.

This doc records (a) the design decisions for the item master, (b) what is
being fixed **now**, and (c) what is deliberately deferred — with Tanzania's
own regulatory and reimbursement reality as the deciding context.

---

## 2. Design principles

1. **Controlled vocabularies, not free text.** Type, Category, and Base unit
   are server-authoritative lists. The server validates; the UI offers a
   picker. The list is the single source of truth.
2. **Type is a closed, behaviour-driving enum.** `MEDICATION` gates dispensing
   and prescribing; the set is small and stable.
3. **Identity is the generic name.** The item's `name` is the **INN / generic
   name**, because that is what NHIF reimburses on. No brand-name-first
   identity.
4. **Immutability where it is a one-way door.** `item_type` and `uom` cannot be
   changed once the item has stock history (see §5).
5. **Single-owner governance.** One authorized role owns the tenant-wide
   catalogue; duplicate codes are already rejected, duplicate names are warned.

---

## 3. Tanzania context (what actually decides the design)

| Fact | Source | Consequence |
|---|---|---|
| TMDA publishes the **List of Registered Medicines** (brand, INN, dosage form, strength, registration no.) | TMDA, Government Notice (e.g. GN. 702, 2024) | Tanzania's drug dictionary is the **TMDA list + INN**. RxNorm/NDC are irrelevant. |
| **NEMLIT + STG** list essential medicines by **pharmacological group**, in **generic (INN) names**; antibiotics tagged **AWaRe** | MoH STG/NEMLIT, 6th ed. 2021 (2026 ed. published) | Category's eventual taxonomy is **NEMLIT groups**; a useful flag set is `on_nemlit` + `aware_group`. |
| **NHIF reimburses medicines prescribed in generic names, as per NEMLIT** (public and private facilities) | NHIF benefit package / price schedule, 2024 | **Generic-name-first is mandatory** for reimbursement, not a preference. |
| NHIF bills from its **own benefit-package item codes + tariffs** | NHIF price schedule, 2024 | Medicine/service → **NHIF code mapping** is a Billing concern (target of the Phase 5 mapping work). |
| Claims must be posted to the NHIF **electronic system within 24 hours** | NHIF benefit package, 2024 | Claims workflow is a later, separate concern. |
| TMDA-regulated medicines require **batch + expiry traceability** | TMDA Act | `track_batch` / `track_expiry` already exist — keep them. |

**Decision:** adopt Tanzania's own standards. Explicitly **do not** adopt
RxNorm / NDC, ATC / UNSPSC / GS1, multi-level unit conversions, or a multi-step
approval workflow.

---

## 4. The controlled vocabularies

Implemented as `App\Domains\Inventory\Domain\ValueObject\{ItemType, ItemCategory,
UnitOfMeasure}` — the `BillingServiceCategory` precedent (set-validated, not a
PHP enum). Each exposes `ALL` and `assert(string): string` (upper-cases, trims,
and rejects anything outside the set).

**ItemType (closed, behaviour-driving):**
`MEDICATION`, `SUPPLY`, `PPE`, `EQUIPMENT`, `DEVICE`, `CONSUMABLE`

**UnitOfMeasure (base stock unit):**
`TABLET`, `CAPSULE`, `VIAL`, `AMPOULE`, `BOTTLE`, `BOX`, `PACK`, `PIECE`,
`EACH`, `PAIR`, `SACHET`, `TUBE`, `ROLL`, `SET`, `KIT`, `ML`, `L`, `MG`, `G`, `KG`

**ItemCategory (optional — *interim* pharmacological/supply groups):**
`ANAESTHETIC`, `ANALGESIC`, `ANTIALLERGIC`, `ANTIBIOTIC`, `ANTICONVULSANT`,
`ANTIDIABETIC`, `ANTIFUNGAL`, `ANTIMALARIAL`, `ANTIPARASITIC`,
`ANTIRETROVIRAL`, `ANTITUBERCULOSIS`, `ANTIVIRAL`, `CARDIOVASCULAR`,
`DERMATOLOGICAL`, `GASTROINTESTINAL`, `HAEMATOLOGICAL`, `HORMONAL`,
`IMMUNOLOGICAL`, `MUSCULOSKELETAL`, `NEUROLOGICAL`, `OPHTHALMIC`,
`PSYCHOTHERAPEUTIC`, `RESPIRATORY`, `VACCINE`, `VITAMIN_MINERAL`,
`FLUID_ELECTROLYTE`, `CONSUMABLE`, `DRESSING`, `DIAGNOSTIC_REAGENT`,
`EQUIPMENT`, `DEVICE`, `OTHER`

> The category list is deliberately **interim**. It is NEMLIT-shaped so the
> values entered now are broadly compatible with the full NEMLIT group list
> that Inv.0 will curate. It is not the final taxonomy.

---

## 5. Immutability guardrails (one-way doors)

Stock balances are interpreted in the item's `uom`; movements snapshot `uom` at
post time. Changing an item's base unit after movements exist silently
reinterprets history. Likewise, flipping `item_type` between `SUPPLY` and
`MEDICATION` after dispensing history changes what Pharmacy may dispense.

**Rule:** once an item has any stock movement, `item_type` and `uom` are
**immutable**. The check is enforced on the write path
(`inventory:define-item update`) via
`StockLedgerReader::hasMovementsForItem()` and throws
`InventoryItemReclassificationNotAllowed`. Any future edit path MUST reuse this
guard. Descriptive fields (`name`, `description`, `category`) remain editable.

---

## 6. The "now" slice (implemented)

Cheap, no schema migration, and does not disturb the in-progress Phase 2
receiving work.

1. **Controlled vocabularies** (§4) as server-authoritative lists.
2. **Server validation** on `POST /workspaces/inventory/items`: `item_type`,
   `uom`, and `category` validated against the lists (case-insensitively, via
   `prepareForValidation`). The domain aggregate asserts as a backstop, so the
   console command is covered too.
3. **Generic (INN) name** surfaced as the required Name field with guidance.
4. **Duplicate-name warning** in the Add-item panel (advisory; non-blocking,
   because the same generic name legitimately recurs across forms).
5. **Immutability guardrails** (§5).
6. **UI pickers** — Type / Category / Base unit render as pickers fed by the
   server lists.
7. **Controlled-medicine flag** (`is_controlled`) — a boolean on the item, added
   in the Phase 8 expiry/disposal slice because the Phase 0 disposition policy
   requires a witness when controlled stock is destroyed. The system can then
   *tell* which removals need a witness instead of relying on staff memory: a
   `DECREASE` stock adjustment on a controlled item is refused without a named
   witness (`ControlledStockWitnessRequired`), and the witness is stored on the
   adjustment row. It is deliberately a plain flag — the TMDA/NEMLIT schedule
   classification stays deferred below.

Files touched: see the implementation section of
`frontend-foundation-inventory-operations.md`.

---

## 7. Deferred — Inv.0 / Phase 8

Recorded now, built later, because each needs curated data or cross-domain
integration and would otherwise be rework while Phase 2 is mid-flight.

- **NEMLIT group content + `on_nemlit` flag** and **`aware_group`** for
  antibiotics.
- **NHIF item-code mapping** — align the Billing item→service mapping (Phase 5)
  targets with NHIF benefit-package codes and tariffs.
- **Pack → unit factor** — **built in two steps (2026-10-19/20).** One optional
  default pack per item (`pack_unit` + `pack_size`, e.g. 1 BOX = 100 CAPSULE);
  the base unit stays the unit counted, dispensed and expired. Receiving records
  the unit and size it was counted in (`entered_unit` / `entered_quantity` /
  `pack_size`, verified against the base quantity) and purchase-order lines keep
  the entered unit and price with a generated base quantity
  (`ordered_base_qty = ordered_qty × pack_size`). Partial packs are normal
  everywhere — ordering, delivering and receiving part of a box — and prices are
  never divided or rounded. Still deferred: packs at dispensing,
  supplier-specific pack catalogues, and costing/valuation in pack units.
- **TMDA / NEMLIT catalogue seed import** — bulk-load registered medicines and
  NEMLIT groupings once fields are frozen.
- **Controlled-medicine classification** — the `is_controlled` flag answers
  "does this need a witness?" but not "which schedule?" (narcotic /
  psychotropic / precursor). A schedule value, the TMDA register number, and
  any per-schedule handling rules belong with the NEMLIT/TMDA work above.
- **Reference-table editing** — moving category/unit from code constants to
  tenant-extensible reference tables (if a facility needs to extend them
  without a deploy).

---

## 8. References

- TMDA, *List of Registered Medicines* (Government Notice, e.g. GN. 702, 2024).
- MoH, *Standard Treatment Guidelines and National Essential Medicines List
  (STG/NEMLIT)*, 6th edition 2021 (2026 edition published).
- NHIF, *Price Schedule / Benefit Package for Certified Health Facilities*,
  2024.
- `BillingServiceCategory` — the in-repo controlled-vocabulary precedent.
