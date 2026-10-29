<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One medication on the item → billing-service mapping worklist (ADR-189 D-A;
 * frontend-foundation-inventory-operations.md Phase 5). Item identity comes from
 * Inventory's narrow `InventoryItemRef` (never the raw item table); service
 * identity + the currently effective tariff come from Billing. `mapped` is the
 * presence of an ACTIVE mapping; a mapped service that is INACTIVE or unpriced
 * (`amount` null) is shown as data, never hidden — the exception the admin
 * resolves.
 */
#[TypeScript]
final readonly class CatalogItemMapItem
{
    public function __construct(
        public string $invItemId,
        public string $code,
        public string $name,
        public string $itemType,
        public bool $itemActive,
        public bool $mapped,
        public ?string $billingServiceId,
        public ?string $serviceCode,
        public ?string $serviceName,
        public ?string $serviceStatus,
        public ?string $amount,
        public ?string $resolvedScope,
        public ?string $currency,
        public ?string $mappedAt,
    ) {}
}
