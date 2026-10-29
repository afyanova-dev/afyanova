<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/** One RFQ line (ADR-173 D-F). */
final readonly class RfqLineSummary
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
        public ?string $requiredDate,
        public string $awardedQty,
        public string $outstandingQty,
    ) {}
}
