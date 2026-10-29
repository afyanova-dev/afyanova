<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AmendContract` tried to lower a contract line's `quantity_ceiling` below the
 * quantity already committed against it (19-procurement-domain.md §198, §303
 * Invariant 33; ADR-178 D-E). INV-33a must never be retroactively violated.
 * Maps to HTTP 409.
 */
final class ContractCeilingBelowCommitted extends DomainException
{
    public static function forContractLine(string $contractLineId, string $newCeiling, string $committed): self
    {
        return new self(sprintf(
            'Contract line [%s] cannot have its ceiling lowered to [%s] — [%s] is already committed (§198 / Invariant 33; ADR-178 D-E).',
            $contractLineId,
            $newCeiling,
            $committed,
        ));
    }

    public function errorCode(): string
    {
        return 'contract_ceiling_below_committed';
    }
}
