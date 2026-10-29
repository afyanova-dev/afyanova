<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A second quotation was recorded for the same (RFQ, supplier) pair
 * (19-procurement-domain.md §32; ADR-173 D-C — `unique(tenant_id, rfq_id,
 * supplier_id)`). Maps to HTTP 409.
 */
final class QuotationAlreadyExists extends DomainException
{
    public static function forRfqAndSupplier(string $rfqId, string $supplierId): self
    {
        return new self(sprintf('Supplier [%s] already has a quotation on RFQ [%s] — revise it instead (§233; ADR-173 D-D).', $supplierId, $rfqId));
    }

    public function errorCode(): string
    {
        return 'quotation_already_exists';
    }
}
