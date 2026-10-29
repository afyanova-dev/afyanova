<?php

declare(strict_types=1);

/**
 * Procurement permission keys (19-procurement-domain.md §227; ADR-171 D-L).
 *
 * Canonical 3-segment `{domain}.{resource}.{action}` per IC §3 / §12 + ADR-122.
 * §227's sketch is already canonical for every operational key; the ONLY
 * reconciliation is `procurement.read` → `procurement.access` (the Bed & Ward /
 * Care Coordination / Radiology / Inventory `.read` → `.access` precedent).
 * Aggregated into PermissionRegistry by `ProcurementServiceProvider::boot()`.
 *
 * Keys:
 *   - procurement.access               — read the module (suppliers + requisitions + POs).
 *                                        At facility scope this includes all facility POs;
 *                                        at department scope it includes only POs linked to
 *                                        that department's requisitions. The supplier master
 *                                        is tenant-wide and shared read-only.
 *   - procurement.suppliers.manage     — register / update / change-status / blacklist a supplier
 *   - procurement.requisitions.create  — create / submit / cancel a purchase requisition
 *   - procurement.requisitions.approve — approve / reject a requisition (§24 — a
 *                                        genuinely different actor from the requester)
 *   - procurement.orders.create        — create / submit a purchase order
 *   - procurement.orders.approve       — approve a PO (§45 / §228 — different actor)
 *   - procurement.orders.issue         — issue an approved PO (§228 — different actor from the approver)
 *   - procurement.orders.amend         — amend / cancel an issued PO
 *   - procurement.emergency.manage     — mark a requisition / PO as emergency (an
 *                                        ADDITIONAL check on create when `emergency` — F15)
 *
 * Proc.2a (ADR-173 D-G) — the sourcing keys (§227 canonicalised):
 *   - procurement.rfqs.manage          — create / issue / close / cancel an RFQ
 *   - procurement.quotations.record    — enter / revise / withdraw a supplier quotation
 *   - procurement.quotations.view      — read competing supplier pricing AND evaluation
 *                                        scores (§101 / §207 / §208 — strictly STRONGER
 *                                        than `procurement.access`)
 *   - procurement.awards.decide        — award an RFQ (§37 — a different actor from
 *                                        the RFQ creator, enforced in the handler)
 *
 * Proc.2b (ADR-174 D-G) — the evaluation write key (§227's `.quotations.evaluate`):
 *   - procurement.quotations.evaluate  — record / revise / submit a structured
 *                                        quotation evaluation (§36 / §206; the
 *                                        handler adds an ADR-133 refinement + the
 *                                        author check)
 *
 * Proc.3a (ADR-176 D-J) — the purchase-contract write key (§227's `.contracts.manage`):
 *   - procurement.contracts.manage     — create / activate / terminate a purchase
 *                                        contract (§40; a tenant-wide commercial
 *                                        record — no facility scope; activation is
 *                                        a different actor from the creator, §24 /
 *                                        §228, enforced in the handler). Reads of a
 *                                        contract stay on `procurement.access`.
 *                                        Proc.3c (ADR-178) reuses this key for
 *                                        `AmendContract` / `RenewContract` — no new
 *                                        key.
 *   - procurement.invoices.match       — attach and match supplier invoices to GRNs
 *
 * NO catalog console command / key — a Supplier has a governed lifecycle with
 * events (§12 / §122), so it is HTTP-command authored, not seed data (ADR-171 D-A).
 *
 * @return list<string>
 */
return [
    'procurement.access',
    'procurement.suppliers.manage',
    'procurement.requisitions.create',
    'procurement.requisitions.approve',
    'procurement.orders.create',
    'procurement.orders.approve',
    'procurement.orders.issue',
    'procurement.orders.amend',
    'procurement.emergency.manage',

    'procurement.rfqs.manage',
    'procurement.quotations.record',
    'procurement.quotations.view',
    'procurement.awards.decide',

    'procurement.quotations.evaluate',

    'procurement.contracts.manage',
    'procurement.invoices.match',

    // Shape B — the single-actor approval limit is operator policy, so it is
    // edited (never hard-coded) per facility / per department by an
    // administrator. Tenant Admin sees every facility; a Facility Admin sees
    // only the facilities they are assigned to (permission checked PER
    // facility through `ScopeChecker::effectivePermissions`).
    'procurement.approval_limits.manage',
];
