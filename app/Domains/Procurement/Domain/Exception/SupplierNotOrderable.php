<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A purchase order was created against a supplier that is not ACTIVE
 * (19-procurement-domain.md §14, §15; ADR-171 D-H — F5c). SUSPENDED / INACTIVE /
 * BLACKLISTED all block new POs. Maps to HTTP 409.
 */
final class SupplierNotOrderable extends DomainException
{
    public static function status(string $supplierId, string $status): self
    {
        return new self(sprintf(
            'Supplier [%s] is [%s] — a purchase order may only be created for an ACTIVE supplier (§14 / §15; ADR-171 D-H).',
            $supplierId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'supplier_not_orderable';
    }
}
