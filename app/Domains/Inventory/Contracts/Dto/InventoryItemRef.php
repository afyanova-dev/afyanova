<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * The NARROW cross-domain view of one Item Master entry — only what another
 * domain needs to reference a canonical inventory item (ADR-171 D-N / F7),
 * including its item type for a safe domain-specific selection and its optional
 * default buying unit so Procurement can record what was ordered. Deliberately
 * NOT `ItemSummary` — it does not leak Inventory's internal tracking model
 * (`trackBatch` / `trackSerial` / `trackExpiry` / `version`).
 */
final readonly class InventoryItemRef
{
    public function __construct(
        public string $itemId,
        public string $code,
        public string $name,
        public string $uom,
        public ?string $packUnit,
        public ?string $packSize,
        public bool $active,
        public ?string $category,
        public string $itemType,
    ) {}
}
