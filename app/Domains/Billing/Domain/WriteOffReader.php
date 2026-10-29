<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\WriteOffSummary;

/**
 * Read-side port for write-offs (IC §6.2; ADR-191 D7-A). Returns DTOs only.
 */
interface WriteOffReader
{
    /** Throws `App\Domains\Billing\Domain\Exception\WriteOffNotFound` if absent (RLS). */
    public function summary(string $writeOffId): WriteOffSummary;

    /**
     * @return list<WriteOffSummary> newest first
     */
    public function forAccount(string $financialAccountId): array;

    /**
     * REQUESTED write-offs — the approver worklist (§165), optionally scoped to
     * a facility; oldest first.
     *
     * @return list<WriteOffSummary>
     */
    public function pending(?string $facilityId): array;

    /**
     * Σ(amount) of the APPLIED write-offs tagged to an invoice (net of REVERSED).
     * The invoice written-off derivation — ADR-191 D7-A.
     *
     * @return numeric-string scale-2
     */
    public function appliedAmountForInvoice(string $invoiceId): string;
}
