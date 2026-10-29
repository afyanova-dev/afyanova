<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One purchase-contract line (ADR-176 D-F; ADR-177 D-G).
 *
 * `committed` / `received` / `remaining` are a read-side projection (ADR-177 D-C
 * — never stored): `committed` = Σ `ordered_qty` and `received` = Σ
 * `received_qty` over every non-CANCELLED PO line carrying this line's
 * `contract_line_id`; `remaining` = `quantityCeiling − committed` (clamped ≥ 0),
 * null when the line has no ceiling. `received` is informational — it never
 * gates.
 */
final readonly class ContractLineSummary
{
    public function __construct(
        public string $lineId,
        public int $lineNo,
        public string $lineType,
        public ?string $itemRef,
        public ?string $itemCode,
        public ?string $itemName,
        public ?string $description,
        public string $agreedUnitPrice,
        public ?string $quantityCeiling,
        public string $uom,
        public string $committed,
        public string $received,
        public ?string $remaining,
    ) {}
}
