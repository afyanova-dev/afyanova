<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An `AwardRfq` allocation was structurally invalid (19-procurement-domain.md
 * §37, §38, §39; ADR-173 D-D / D-E). Maps to HTTP 409.
 */
final class RfqAwardInvalid extends DomainException
{
    public static function empty(): self
    {
        return new self('An award must allocate at least one (rfq_line, quotation, qty) entry.');
    }

    public static function duplicateEntry(string $rfqLineId, string $quotationId): self
    {
        return new self(sprintf('RFQ line [%s] is awarded to quotation [%s] more than once in this allocation.', $rfqLineId, $quotationId));
    }

    public static function unknownRfqLine(string $rfqLineId): self
    {
        return new self(sprintf('Award entry references RFQ line [%s], which is not on this RFQ.', $rfqLineId));
    }

    public static function quotationNotSubmitted(string $quotationId): self
    {
        return new self(sprintf('Award entry references quotation [%s], which is not a SUBMITTED quotation for this RFQ.', $quotationId));
    }

    public static function unquotedLine(string $quotationId, string $rfqLineId): self
    {
        return new self(sprintf('Quotation [%s] did not quote RFQ line [%s] — it cannot be awarded that line (§34; ADR-173 D-D).', $quotationId, $rfqLineId));
    }

    public static function overQuotationLine(string $rfqLineId, string $quotationId): self
    {
        return new self(sprintf('Award for RFQ line [%s] from quotation [%s] exceeds the offered quantity (§39; ADR-173 D-D).', $rfqLineId, $quotationId));
    }

    public static function overRfqLine(string $rfqLineId): self
    {
        return new self(sprintf('The total awarded quantity for RFQ line [%s] exceeds the requested quantity (§39; ADR-173 D-D).', $rfqLineId));
    }

    public static function nonPositiveQty(string $rfqLineId): self
    {
        return new self(sprintf('Award entry for RFQ line [%s] has a non-positive quantity.', $rfqLineId));
    }

    public static function rfqNotAwarded(string $rfqId, string $status): self
    {
        return new self(sprintf('RFQ [%s] is [%s] — a PO can only be seeded from an AWARDED RFQ.', $rfqId, $status));
    }

    public static function noAwardForSupplier(string $rfqId, string $supplierId): self
    {
        return new self(sprintf('RFQ [%s] has no award rows for supplier [%s].', $rfqId, $supplierId));
    }

    public function errorCode(): string
    {
        return 'rfq_award_invalid';
    }
}
