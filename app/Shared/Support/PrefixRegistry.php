<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * The canonical table-prefix registry (IMPLEMENTATION-CONTRACTS.md Appendix B).
 * A migration filename / table name must use one of these prefixes; the
 * conformance command enforces it. Adding a module updates this map in the
 * same change.
 */
final class PrefixRegistry
{
    /** @var array<string, string>  prefix => owning module */
    public const MAP = [
        'org_' => 'Organization',
        'idn_' => 'Identity & RBAC',
        'aut_' => 'Authentication',
        'pat_' => 'Patient',
        'enc_' => 'Encounter',
        'clin_' => 'Clinical',
        'cproc_' => 'Clinical Procedures',
        'lab_' => 'Laboratory',
        'rad_' => 'Radiology',
        'rx_' => 'Pharmacy',
        'thr_' => 'Theatre',
        'inv_' => 'Inventory & Warehousing',
        'pcr_' => 'Procurement',
        'bill_' => 'Billing & Payments',
        'ins_' => 'Insurance & Payer',
        'sch_' => 'Scheduling & Appointments',
        'bw_' => 'Bed & Ward Management',
        'hr_' => 'HR & Workforce',
        'care_' => 'Care Coordination',
        'queue_' => 'Queueing & Patient Flow (service)',
        'qms_' => 'Queueing & Patient Flow (service)',
        'notif_' => 'Notifications (service)',
        'doc_' => 'Documents (service)',
        'rpt_' => 'Reporting & Analytics (service)',
        'int_' => 'Integration (service)',
        'intg_' => 'Integration (service)',
        'shared_' => 'Shared kernel',
    ];

    /** @return list<string> */
    public static function prefixes(): array
    {
        return array_keys(self::MAP);
    }

    public static function isKnown(string $table): bool
    {
        foreach (self::prefixes() as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        // framework-owned tables that predate the module system
        return in_array($table, ['migrations', 'users', 'password_reset_tokens', 'sessions'], true);
    }
}
