<?php

declare(strict_types=1);

/**
 * PHASE 11 (increment HR.1) — permission definitions for Domain 25 (HR & Workforce).
 * (25-hr-and-workforce-domain.md §10; ADR-206).
 *
 * Keys:
 *   - hr.access                 — base domain access
 *   - hr.person.manage          — manage human person demographic records
 *   - hr.staff.read             — read staff records
 *   - hr.staff.manage           — manage staff records (create, onboard, suspend, reinstate, terminate)
 *   - hr.employment.manage      — manage employment contracts (create, start, suspend, reinstate, amend, end)
 *   - hr.cadre_position.manage  — manage cadre classifications and job positions
 *   - hr.assignment.manage      — manage organizational assignments
 *   - hr.registration.manage    — manage professional council registrations
 *   - hr.staff_user_link.manage — manage staff to Identity user links
 *
 * @return list<string>
 */
return [
    'hr.access',
    'hr.person.manage',
    'hr.staff.read',
    'hr.staff.manage',
    'hr.employment.manage',
    'hr.cadre_position.manage',
    'hr.assignment.manage',
    'hr.registration.manage',
    'hr.staff_user_link.manage',
    'hr.shift_pattern.manage',
    'hr.roster.manage',
    'hr.attendance.ingest',
    'hr.attendance.manage',
    'hr.attendance.reconcile',
    'hr.leave_type.manage',
    'hr.leave_ledger.post',
    'hr.leave.request',
    'hr.leave.approve',
];
