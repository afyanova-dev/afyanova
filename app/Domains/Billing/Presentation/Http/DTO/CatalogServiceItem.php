<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the price list — a billing service with its currently effective
 * base tariff at the facility (ADR-188). `amount` null = unpriced (returned
 * as data, never an error — §28). Money as scale-2 strings.
 */
#[TypeScript]
final readonly class CatalogServiceItem
{
    public function __construct(
        public string $billingServiceId,
        public string $code,
        public string $name,
        public string $category,
        public string $status,
        public ?string $amount,
        public string $resolvedScope,
        public string $currency,
        public ?string $effectiveFrom,
    ) {}
}
