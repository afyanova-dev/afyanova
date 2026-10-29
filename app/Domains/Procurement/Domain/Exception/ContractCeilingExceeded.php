<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * INV-33a (19-procurement-domain.md §196–§198, §303 Invariant 33; ADR-177 D-D /
 * D-F). A `CreatePurchaseOrder(contractId)` or a quantity-increasing
 * `AmendPurchaseOrder` on a contract-linked line would drive the CUMULATIVE
 * Σ `ordered_qty` over all non-CANCELLED PO lines for a contract line past that
 * line's `quantity_ceiling`. §198's "Approval / New Contract" — raise the
 * ceiling via `AmendContract` (Proc.3c) or source the excess off-contract.
 * Maps to HTTP 409.
 */
final class ContractCeilingExceeded extends DomainException
{
    public static function forContractLine(string $contractLineId, string $committed, string $ceiling): self
    {
        return new self(sprintf(
            'Contract line [%s] would be committed [%s] against a ceiling of [%s] (§198 / Invariant 33; ADR-177 INV-33a).',
            $contractLineId,
            $committed,
            $ceiling,
        ));
    }

    public function errorCode(): string
    {
        return 'contract_ceiling_exceeded';
    }
}
