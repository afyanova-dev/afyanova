<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/** One purchase-requisition line (ADR-171 D-J). NOT an event payload. */
final readonly class RequisitionLineSummary
{
    public function __construct(
        public string $lineId,
        public int $lineNo,
        public string $lineType,
        public ?string $itemRef,
        public ?string $itemCode,
        public ?string $itemName,
        public ?string $description,
        public string $quantity,
        public string $uom,
        public string $unit,
        public ?string $packSize,
        public ?string $requiredDate,
        public string $status,
    ) {}
}
