<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AwardRfq` was dispatched against an RFQ where quotation evaluation has begun
 * (≥ 1 SUBMITTED evaluation exists) but at least one SUBMITTED quotation still
 * carries no SUBMITTED evaluation (19-procurement-domain.md §36, §37; ADR-174
 * D-F — the SOFT award gate; rajani's D2 clarification: every SUBMITTED
 * quotation is checked, none skipped). Maps to HTTP 409.
 *
 * A DRAFT evaluation never triggers or satisfies the gate (no deadlock).
 */
final class RfqEvaluationIncomplete extends DomainException
{
    public static function quotationNotEvaluated(string $rfqId, string $quotationId): self
    {
        return new self(sprintf(
            'RFQ [%s] cannot be awarded — quotation [%s] is SUBMITTED but has no SUBMITTED evaluation, and evaluation has begun for this RFQ.',
            $rfqId,
            $quotationId,
        ));
    }

    public function errorCode(): string
    {
        return 'rfq_evaluation_incomplete';
    }
}
