<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A quotation was recorded for a supplier that was not invited to the RFQ
 * (19-procurement-domain.md §30, §33; ADR-173 D-D). Maps to HTTP 409.
 */
final class SupplierNotInvited extends DomainException
{
    public static function forRfq(string $rfqId, string $supplierId): self
    {
        return new self(sprintf('Supplier [%s] was not invited to RFQ [%s].', $supplierId, $rfqId));
    }

    public function errorCode(): string
    {
        return 'supplier_not_invited';
    }
}
