<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A quotation line was structurally invalid — it cites an `rfq_line_id` that is
 * not on the RFQ, or the revision targets a line that is not on the quotation
 * (19-procurement-domain.md §34; ADR-173 D-D). Maps to HTTP 409.
 */
final class QuotationLineInvalid extends DomainException
{
    public static function notOnRfq(int $lineNo): self
    {
        return new self(sprintf('Quotation line %d cites an `rfqLineId` that is not on the RFQ (§34; ADR-173 D-D).', $lineNo));
    }

    public static function notOnQuotation(string $quotationLineId): self
    {
        return new self(sprintf('Revision targets line [%s], which is not on this quotation.', $quotationLineId));
    }

    public function errorCode(): string
    {
        return 'quotation_line_invalid';
    }
}
