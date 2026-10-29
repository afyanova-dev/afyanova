<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * One stock-count document for the Stock counts screen (Phase 8).
 *
 * `lineCount` is how many (item, batch) rows the sheet holds; `countedCount` is
 * how many have a quantity written down; `varianceCount` is how many of those
 * disagree with the system snapshot. All quantities are base units. NOT an
 * event payload.
 */
final readonly class StockCountSummary
{
    public function __construct(
        public string $countId,
        public string $countNumber,
        public string $facilityId,
        public string $locationId,
        public string $locationCode,
        public string $locationName,
        public string $status,
        public ?string $notes,
        public string $startedByUserId,
        public string $startedAt,
        public ?string $closedAt,
        public int $lineCount,
        public int $countedCount,
        public int $varianceCount,
    ) {}
}
