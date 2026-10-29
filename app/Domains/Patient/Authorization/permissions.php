<?php

declare(strict_types=1);

/**
 * Patient permission keys (docs/00-foundation/03-identity-rbac.md sections
 * 31-33 and 66. L1 `.access` + L2 `{resource}.{action}`. Aggregated into
 * PermissionRegistry by PatientServiceProvider::boot(). `patient.access` is
 * the module's L1 coarse key (every module registers one). Registers only the
 * L2 keys the shipped commands/queries reference; later increments add more
 * as their commands/queries land (identifiers, merge, search, ...).
 *
 * @return list<string>
 */
return [
    'patient.access',
    'patient.patients.create',
    'patient.patients.create_pharmacy_only',
    'patient.patients.read',
    'patient.patients.update', // core demographics (later) + contacts / relationships (ADR-126)
    // Patient-level operational sticky notes: read with patient access, mutate with patient update.
    'patient.patients.deactivate',
    'patient.patients.mark_deceased',
    'patient.patients.merge', // ADR-128 — MergePatients + RequestUnmerge (identity-layer)
    'patient.patients.override_duplicate', // ADR-117 — re-submit past a BLOCK / REVIEW
    'patient.registrations.create',
    'patient.identifiers.manage',
    'patient.identifiers.view_sensitive', // ADR-122 — unmasked display; enforced in Presentation (PHASE 16)
];
