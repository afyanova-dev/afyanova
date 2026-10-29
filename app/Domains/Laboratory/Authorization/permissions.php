<?php

declare(strict_types=1);

/**
 * Laboratory permission keys (14-laboratory-domain.md §128; ADR-157 D-J).
 *
 * Lower snake case, canonical 3-segment `{domain}.{resource}.{action}` per
 * IC §3/§12 + ADR-122 — the domain doc's own §128 sketch already matches this
 * shape (no reconciliation needed, unlike Bed & Ward / Care Coordination).
 * Aggregated into PermissionRegistry by `LaboratoryServiceProvider::boot()`.
 *
 * Keys:
 *   - laboratory.access             — read the module (orders + catalog + specimens)
 *   - laboratory.orders.create      — the ordering clinician: create / submit / cancel   (Lab.1, ADR-157)
 *   - laboratory.orders.accept      — the laboratory: accept / reject an order            (Lab.1, ADR-157)
 *   - laboratory.specimens.collect  — collection-side: collect / request recollection     (Lab.2, ADR-158)
 *   - laboratory.specimens.receive  — reception-side: receive / accept / reject a specimen (Lab.2, ADR-158)
 *   - laboratory.results.enter      — result entry + review                                (Lab.3a, ADR-159)
 *   - laboratory.results.verify     — result verification (a distinct responsibility, §57) (Lab.3a, ADR-159)
 *   - laboratory.results.release    — result release to clinical users (§60)               (Lab.3a, ADR-159)
 *   - laboratory.results.amend      — result correction + cancellation (§61–§64)            (Lab.3b, ADR-160)
 *   - laboratory.critical.manage    — critical-result identification + acknowledgement (§54–§56) (Lab.3c, ADR-161)
 *   - laboratory.quality.manage     — QC run recording + review (§83–§85)                       (Lab.5, ADR-163)
 *
 * Catalog authoring (`laboratory:define-test` / `laboratory:define-panel`) is
 * console-only (ADR-157 D-C / ADR-162 D-C) — NO permission key.
 *
 * @return list<string>
 */
return [
    'laboratory.access',
    'laboratory.orders.create',
    'laboratory.orders.accept',
    'laboratory.specimens.collect',
    'laboratory.specimens.receive',
    'laboratory.results.enter',
    'laboratory.results.verify',
    'laboratory.results.release',
    'laboratory.results.amend',
    'laboratory.critical.manage',
    'laboratory.quality.manage',
];
