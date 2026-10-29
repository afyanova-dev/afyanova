<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One `inv_items.id → billing_service` mapping (20-billing-and-payments-domain.md
 * §237; ADR-189 D-A). Returned by `GetItemBillingMap` / `SearchItemBillingMap`
 * via `ItemBillingMapReader`. NOT `#[TypeScript]`.
 */
final readonly class ItemBillingMapSummary
{
    public function __construct(
        public string $mapId,
        public string $invItemId,
        public string $billingServiceId,
        public bool $active,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
