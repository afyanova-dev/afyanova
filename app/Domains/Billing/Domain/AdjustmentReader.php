<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\AdjustmentSummary;

/**
 * Read-side port for adjustments (IC §6.2; ADR-191 D7-D). Returns DTOs only.
 */
interface AdjustmentReader
{
    /** Throws `App\Domains\Billing\Domain\Exception\AdjustmentNotFound` if absent (RLS). */
    public function summary(string $adjustmentId): AdjustmentSummary;

    /**
     * @return list<AdjustmentSummary> newest first
     */
    public function forAccount(string $financialAccountId): array;

    /**
     * REQUESTED adjustments — the approver worklist (§165), optionally scoped to
     * a facility; oldest first.
     *
     * @return list<AdjustmentSummary>
     */
    public function pending(?string $facilityId): array;

    /**
     * The SIGNED net of the APPLIED adjustments tagged to an invoice —
     * `Σ(CREDIT amount) − Σ(DEBIT amount)` (a positive result LOWERS the
     * invoice's effective obligation). ADR-191 D7-A.
     *
     * @return numeric-string scale-2, may be negative
     */
    public function appliedNetForInvoice(string $invoiceId): string;
}
