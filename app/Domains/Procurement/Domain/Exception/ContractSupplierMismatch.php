<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreatePurchaseOrder(contractId)` named a supplier different from the
 * contract's supplier (19-procurement-domain.md §40, §42; ADR-176 D-D). Maps to
 * HTTP 409.
 */
final class ContractSupplierMismatch extends DomainException
{
    public static function forContract(string $contractId, string $poSupplierId, string $contractSupplierId): self
    {
        return new self(sprintf(
            'PO supplier [%s] does not match contract [%s] supplier [%s].',
            $poSupplierId,
            $contractId,
            $contractSupplierId,
        ));
    }

    public function errorCode(): string
    {
        return 'contract_supplier_mismatch';
    }
}
