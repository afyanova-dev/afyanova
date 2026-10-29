<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * A tracked item minimum at one facility, without its on-hand figures
 * (frontend-foundation-inventory-operations.md Phase 8). The Running low screen
 * uses this to pre-fill the level editor and to say "Already tracked at X".
 * Read-only.
 */
final readonly class ItemLevelSummary
{
    public function __construct(
        public string $itemId,
        public string $minLevel,
    ) {}
}
