<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A quotation-evaluation command was dispatched while the RFQ is not CLOSED, or
 * against a quotation that is not SUBMITTED (19-procurement-domain.md §31, §36;
 * ADR-174 D-B / D-C). Evaluation is legal only on a SUBMITTED quotation of a
 * CLOSED RFQ. Maps to HTTP 409.
 */
final class RfqNotOpenForEvaluation extends DomainException
{
    public static function status(string $rfqId, string $status): self
    {
        return new self(sprintf(
            'RFQ [%s] is [%s] — quotation evaluation is legal only while the RFQ is CLOSED.',
            $rfqId,
            $status,
        ));
    }

    public static function quotationNotSubmitted(string $quotationId, string $status): self
    {
        return new self(sprintf(
            'Quotation [%s] is [%s] — only a SUBMITTED quotation can be evaluated.',
            $quotationId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'rfq_not_open_for_evaluation';
    }
}
