<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A quotation was recorded / revised / withdrawn while the RFQ was not ISSUED
 * (19-procurement-domain.md §31; ADR-173 D-D). Maps to HTTP 409.
 */
final class RfqNotOpenForQuotation extends DomainException
{
    public static function status(string $rfqId, string $status): self
    {
        return new self(sprintf('RFQ [%s] is [%s] — quotations may only be recorded / changed while it is ISSUED.', $rfqId, $status));
    }

    public function errorCode(): string
    {
        return 'rfq_not_open_for_quotation';
    }
}
