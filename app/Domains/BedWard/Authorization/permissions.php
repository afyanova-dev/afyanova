<?php

declare(strict_types=1);

/**
 * Bed & Ward Management permission keys (24-bed-and-ward-management-domain.md §13;
 * ADR-147 D-I, ADR-148 D-G/D-H, ADR-149 D-F).
 *
 * Lower snake case per IC §3/§12 + ADR-122. Aggregated into PermissionRegistry
 * by BedWardServiceProvider::boot(). `reservations.manage` was named by
 * ADR-147 but never actually added here during B.1 — added now (B.2).
 *
 * @return list<string>
 */
return [
    'bed_ward.access',
    'bed_ward.wards.configure',
    'bed_ward.beds.assign',
    'bed_ward.beds.release',
    'bed_ward.beds.block',
    'bed_ward.occupancy.correct',
    'bed_ward.reservations.manage',
];
