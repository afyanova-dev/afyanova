<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one Item Master catalog entry
 * (ADR-170 D-C / D-M). Returned by `GetItem` / `GetItems` and consumed
 * intra-domain by the movement handlers to snapshot the `uom` and inspect the
 * tracking rules. NOT an event payload.
 */
final readonly class ItemSummary
{
    public function __construct(
        public string $itemId,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $category,
        public string $itemType,
        public string $uom,
        public ?string $packUnit,
        public ?string $packSize,
        public bool $trackBatch,
        public bool $trackSerial,
        public bool $trackExpiry,
        public bool $isControlled,
        public bool $active,
        public int $version,
    ) {}
}
