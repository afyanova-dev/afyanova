<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One immutable tariff row on the price-history rail. `current` marks the row
 * in force today — every other row is superseded or ended history.
 */
#[TypeScript]
final readonly class CatalogTariffHistoryItem
{
    public function __construct(
        public string $tariffId,
        public string $resolvedScope,
        public string $amount,
        public string $currency,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $status,
        public bool $current,
    ) {}
}
