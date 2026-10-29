<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\Exception\IllegalRfqTransition;
use App\Domains\Procurement\Domain\Exception\RfqNotAwardable;
use App\Domains\Procurement\Domain\Exception\RfqNotOpenForQuotation;
use App\Domains\Procurement\Domain\Model\Rfq;

/**
 * RFQ lifecycle rules (19-procurement-domain.md §31, §37; ADR-173 D-B). Pure
 * domain logic, no I/O.
 *
 *   DRAFT -> ISSUED -> CLOSED -> AWARDED
 *   DRAFT | ISSUED | CLOSED -> CANCELLED   (NOT from AWARDED)
 *   Quotations may be recorded / revised / withdrawn only while ISSUED.
 */
final class RfqLifecycle
{
    public function assertCanIssue(string $status): void
    {
        if ($status !== Rfq::STATUS_DRAFT) {
            throw IllegalRfqTransition::fromStatus('IssueRfq', $status);
        }
    }

    public function assertCanClose(string $status): void
    {
        if ($status !== Rfq::STATUS_ISSUED) {
            throw IllegalRfqTransition::fromStatus('CloseRfq', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if (! in_array($status, [
            Rfq::STATUS_DRAFT,
            Rfq::STATUS_ISSUED,
            Rfq::STATUS_CLOSED,
        ], true)) {
            throw IllegalRfqTransition::fromStatus('CancelRfq', $status);
        }
    }

    public function assertCanAward(string $rfqId, string $status): void
    {
        if ($status !== Rfq::STATUS_CLOSED) {
            throw RfqNotAwardable::status($rfqId, $status);
        }
    }

    public function assertOpenForQuotation(string $rfqId, string $status): void
    {
        if ($status !== Rfq::STATUS_ISSUED) {
            throw RfqNotOpenForQuotation::status($rfqId, $status);
        }
    }
}
