<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\Exception\IllegalQuotationTransition;
use App\Domains\Procurement\Domain\Model\Quotation;

/**
 * Quotation lifecycle rules (19-procurement-domain.md §32, §233; ADR-173 D-C).
 * Pure domain logic, no I/O.
 *
 *   RECEIVED -> SUBMITTED (at CloseRfq) -> AWARDED | NOT_AWARDED (at AwardRfq)
 *   RECEIVED -> WITHDRAWN
 * Revise is legal only while RECEIVED (the RFQ-ISSUED check is `RfqLifecycle`).
 */
final class QuotationLifecycle
{
    public function assertCanRevise(string $status): void
    {
        if ($status !== Quotation::STATUS_RECEIVED) {
            throw IllegalQuotationTransition::fromStatus('ReviseQuotation', $status);
        }
    }

    public function assertCanWithdraw(string $status): void
    {
        if ($status !== Quotation::STATUS_RECEIVED) {
            throw IllegalQuotationTransition::fromStatus('WithdrawQuotation', $status);
        }
    }

    /** Only a SUBMITTED quotation can be awarded / marked NOT_AWARDED (`CloseRfq` submits them). */
    public function assertSubmittedForAward(string $status): void
    {
        if ($status !== Quotation::STATUS_SUBMITTED) {
            throw IllegalQuotationTransition::fromStatus('AwardRfq', $status);
        }
    }
}
