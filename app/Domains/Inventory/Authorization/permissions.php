<?php

declare(strict_types=1);

/**
 * Inventory & Warehousing permission keys
 * (18-inventory-and-warehousing-domain.md §222, §223; ADR-170 D-O).
 *
 * Canonical 3-segment `{domain}.{resource}.{action}` per IC §3 / §12 + ADR-122.
 * §223's sketch is already canonical for every operational key; the ONLY
 * reconciliation is `inventory.read` → `inventory.access` (the Bed & Ward /
 * Care Coordination / Radiology `.read` → `.access` precedent).
 * Aggregated into PermissionRegistry by `InventoryServiceProvider::boot()`.
 *
 * Keys:
 *   - inventory.access               — read the module (catalog + balances + ledger + adjustments)
 *   - inventory.receipts.create      — record a goods receipt
 *   - inventory.consumption.record   — record a stock consumption
 *   - inventory.transfers.create     — move stock between two locations of one
 *                                      facility (Phase 8)
 *   - inventory.counts.manage        — start and work a physical stock count
 *                                      at one location (Phase 8)
 *   - inventory.adjustments.create   — raise a stock adjustment
 *   - inventory.adjustments.approve  — approve / reject a stock adjustment (a
 *                                      genuinely different actor — §224 / Inv 12)
 *
 * Inv.2 (ADR-180 D-G) — §227-canon; §223's list has no reserve key:
 *   - inventory.reservations.manage  — reserve / release / cancel a stock
 *                                      reservation (§112–§116; the handler adds
 *                                      the ADR-133 per-facility refinement).
 *                                      `inventory:expire-reservations` is
 *                                      system-only — no key.
 *
 * Catalog authoring (`inventory:define-item` / `inventory:define-location`) is
 * console-only (ADR-170 D-C / D-D) — NO permission key ("Inv.0" deferred).
 *
 * @return list<string>
 */
return [
    'inventory.access',
    'inventory.items.manage',
    'inventory.locations.manage',
    'inventory.location_types.manage',
    'inventory.receipts.create',
    'inventory.consumption.record',
    'inventory.transfers.create',
    'inventory.counts.manage',
    'inventory.adjustments.create',
    'inventory.adjustments.approve',

    'inventory.reservations.manage',
];
