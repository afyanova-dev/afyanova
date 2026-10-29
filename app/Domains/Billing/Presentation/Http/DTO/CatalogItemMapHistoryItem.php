<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One immutable item → billing-service mapping row on the price-history rail
 * (ADR-189 D-A). Newest first. An inactive row is superseded history; exactly
 * one row per item is ever active. Rows are never mutated, so the rail is
 * read-only by construction.
 */
#[TypeScript]
final readonly class CatalogItemMapHistoryItem
{
    public function __construct(
        public string $mapId,
        public string $invItemId,
        public string $billingServiceId,
        public ?string $serviceName,
        public bool $active,
        public string $occurredAt,
    ) {}
}
