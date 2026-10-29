<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

/**
 * Read-side port for the per-tenant financial-control thresholds (IC §6.3;
 * ADR-191 G-3a). Read READ-ONLY intra-domain by the write-off / adjustment
 * handlers (the `EvaluationSettingsReader` precedent — no `#[ProtectedQuery]`
 * surface).
 */
interface FinancialControlSettingsReader
{
    /**
     * The dual-control threshold for a kind (`FinancialControlSettings::KIND_*`) —
     * an amount `<= ` it MAY be request-and-applied by one actor; above it a
     * distinct approver is mandatory. `null` = always dual-control (the default,
     * and for any tenant with no row). RLS-scoped to the current tenant.
     *
     * @return numeric-string|null
     */
    public function dualControlThreshold(string $kind): ?string;
}
