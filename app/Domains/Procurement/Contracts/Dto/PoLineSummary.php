<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One purchase-order line with its computed line total (ADR-171 D-J / F13).
 *
 * `orderedQty` is what the buyer ENTERED in `unit` (e.g. 3 BOX) and `unitPrice`
 * is the price of that unit; `orderedBaseQty` is the generated base-unit
 * equivalent (300 CAPSULE) that stock is received against. `packSize` is null
 * for a line written in the base unit (`unit === uom`).
 */
final readonly class PoLineSummary
{
    public function __construct(
        public string $lineId,
        public int $lineNo,
        public string $lineType,
        public ?string $itemRef,
        public ?string $itemCode,
        public ?string $itemName,
        public ?string $description,
        public string $orderedQty,
        public string $uom,
        public string $unit,
        public ?string $packSize,
        public string $orderedBaseQty,
        public string $unitPrice,
        public string $lineTotal,
        public ?string $deliveryLocationRef,
        public ?string $requiredDate,
        public string $receivedQty,
        public string $status,
    ) {}
}
