<?php

declare(strict_types=1);

/**
 * Radiology permission keys (15-radiology-domain.md §120; ADR-165 D-K).
 *
 * Lower snake case, canonical 3-segment `{domain}.{resource}.{action}` per
 * IC §3/§12 + ADR-122. The §120 sketch is reconciled to this shape by ADR-165
 * D-A: `radiology.read` → `radiology.access`,
 * `radiology.critical-findings.manage` → `radiology.critical.manage` (the Bed &
 * Ward / Care Coordination hyphen-reconciliation precedent).
 * Aggregated into PermissionRegistry by `RadiologyServiceProvider::boot()`.
 *
 * Keys:
 *   - radiology.access          — read the module (orders + catalog + studies + reports) (Rad.1, ADR-165)
 *   - radiology.orders.create   — the ordering clinician: create / submit / cancel  (Rad.1, ADR-165)
 *   - radiology.orders.accept   — Radiology: accept / reject an order (§22)          (Rad.1, ADR-165)
 *   - radiology.studies.perform — schedule / ready / start / complete / cancel /
 *                                 no-show / abort an imaging study (§120 verbatim)   (Rad.2, ADR-166)
 *   - radiology.reports.create  — the reporting clinician: create / update / review  (Rad.3a, ADR-167)
 *   - radiology.reports.verify  — verify / finalize a report (§42, §119)             (Rad.3a, ADR-167)
 *   - radiology.reports.release — release a verified report (§52)                    (Rad.3a, ADR-167)
 *   - radiology.reports.amend   — correction / addendum / cancellation (§44, §164)   (Rad.3b, ADR-168)
 *   - radiology.critical.manage — critical-finding identify + acknowledge (§46, §167) (Rad.3c, ADR-169;
 *                                 §120's `radiology.critical-findings.manage` reconciled — ADR-165 D-A)
 *
 * Catalog authoring (`radiology:define-modality` / `radiology:define-imaging-service`)
 * is console-only (ADR-165 D-C / D-D) — NO permission key.
 *
 * @return list<string>
 */
return [
    'radiology.access',
    'radiology.orders.create',
    'radiology.orders.accept',
    'radiology.studies.perform',
    'radiology.reports.create',
    'radiology.reports.verify',
    'radiology.reports.release',
    'radiology.reports.amend',
    'radiology.critical.manage',
];
