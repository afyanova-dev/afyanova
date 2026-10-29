<?php

declare(strict_types=1);

/**
 * Pharmacy permission keys (16-pharmacy-domain.md §180, §181; ADR-181 D-F).
 *
 * A NEW `pharmacy` permission segment. Canonical 3-segment
 * `{domain}.{resource}.{action}` per IC §3 / §12 + ADR-122.
 * Aggregated into PermissionRegistry by `PharmacyServiceProvider::boot()`.
 *
 * Keys:
 *   - pharmacy.access                — read the module (prescription + order queue + records)
 *   - pharmacy.prescriptions.accept  — accept a prescription into the pharmacy
 *                                      (RECEIVED → ACTIVE) OR administratively
 *                                      cancel it. The handler-side ADR-133
 *                                      refinement scopes it to the prescription's
 *                                      originating facility. (Pharm.1 — ADR-181)
 *   - pharmacy.orders.manage         — create / validate / reject / hold /
 *                                      release / cancel a Pharmacy Order. The
 *                                      handler / policy per-facility refinement
 *                                      scopes it to the order's facility.
 *                                      (Pharm.2a — ADR-182)
 *   - pharmacy.dispensing.record     — dispense medication against a VALIDATED
 *                                      Pharmacy Order (`Dispense`). Per-subject
 *                                      at the order's facility
 *                                      (`PharmacyOrderPolicy::dispense`).
 *                                      Reconciles §180's `pharmacy.dispense` /
 *                                      `pharmacy.partial-dispense` sketch to one
 *                                      canonical key. (Pharm.3 — ADR-184)
 *   - pharmacy.dispensing.reverse    — reverse a COMPLETED dispensing
 *                                      (`ReverseDispensing`). A distinct
 *                                      higher-trust key (§110 "high-risk
 *                                      overrides must be audited") — a facility
 *                                      may grant dispensing without granting
 *                                      reversal. Per-subject at the order's
 *                                      facility (`PharmacyOrderPolicy::reverse`).
 *                                      (Pharm.3b — ADR-185)
 *   - pharmacy.pickup.record         — record a medication pickup / mark it
 *                                      not-collected / cancel it (`RecordPickup`
 *                                      / `MarkPickupNotCollected` / `CancelPickup`).
 *                                      Per-subject at the order's facility
 *                                      (`PharmacyOrderPolicy::pickup`).
 *                                      (Pharm.3c — ADR-186)
 *
 * The dispensing pharmacist ALSO holds the Inventory keys the nested
 * `InventoryApi` calls need (`inventory.access` / `inventory.reservations.manage`
 * / `inventory.consumption.record`) and `clinical.prescribing.read` (the
 * first-event authorised-quantity read) — ADR-184 HD-5; those keys are NOT
 * re-declared here.
 *
 * `IngestPrescription` is system-only (the `OnPrescriptionIssued` listener) —
 * NO permission key. Substitution / return keys land with their increments
 * (Pharm.3c+).
 *
 * @return list<string>
 */
return [
    'pharmacy.access',
    'pharmacy.prescriptions.accept',
    'pharmacy.orders.manage',
    'pharmacy.dispensing.record',
    'pharmacy.dispensing.reverse',
    'pharmacy.pickup.record',
];
