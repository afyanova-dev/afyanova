<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * The usable price on an ACTIVE supplier contract (P2-02). Contract lines are
 * always quoted in the base `uom`; `remaining` is the uncommitted ceiling
 * quantity, or null when the line has no ceiling.
 */
final readonly class ContractPriceReference
{
    public function __construct(
        public string $contractId,
        public string $contractNumber,
        public string $unitPrice,
        public string $uom,
        public ?string $remaining,
        public string $effectiveDate,
        public string $expiryDate,
    ) {}
}
