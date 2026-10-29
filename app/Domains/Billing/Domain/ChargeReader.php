<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Contracts\Dto\PendingBillableEventSummary;

/**
 * Read-side port for charges + the pending billable-event worklist (IC §6.2;
 * ADR-189 D-G). Returns DTOs only.
 */
interface ChargeReader
{
    /** Throws `App\Domains\Billing\Domain\Exception\ChargeNotFound` if absent (RLS). */
    public function summary(string $chargeId): ChargeSummary;

    /**
     * @return list<ChargeSummary> newest first
     */
    public function forAccount(string $financialAccountId): array;

    /**
     * PENDING billable events — the revenue-leakage worklist (§130 / §131),
     * optionally scoped to a facility; oldest first.
     *
     * @return list<PendingBillableEventSummary>
     */
    public function pendingBillableEvents(?string $facilityId): array;
}
