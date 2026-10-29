<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A `CreatePurchaseOrder(contractId)` line cited an unknown contract line, or a
 * quantity above that line's ceiling (19-procurement-domain.md §41, §198,
 * §303 Inv 33 [per-PO]; ADR-176 D-D). Maps to HTTP 409.
 */
final class ContractLineInvalid extends DomainException
{
    public static function notOnContract(string $contractLineId): self
    {
        return new self(sprintf('PO line cites contract line [%s], which is not on the contract.', $contractLineId));
    }

    public static function ceilingExceeded(string $contractLineId, string $quantity, string $ceiling): self
    {
        return new self(sprintf(
            'PO quantity [%s] on contract line [%s] exceeds the line ceiling [%s] (§198 / Inv 33 per-PO; ADR-176 D-D / D5).',
            $quantity,
            $contractLineId,
            $ceiling,
        ));
    }

    public function errorCode(): string
    {
        return 'contract_line_invalid';
    }
}
