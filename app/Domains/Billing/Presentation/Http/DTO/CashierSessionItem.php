<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The cashier's own open till session (or the most recent closed one when
 * none is open) — a Bill.6 `CashSessionSummary` trimmed to what the cashier
 * workspace renders. Money as scale-2 strings; never floats.
 */
#[TypeScript]
final readonly class CashierSessionItem
{
    public function __construct(
        public string $cashSessionId,
        public string $status,
        public string $openingFloat,
        public string $cashPaymentsTotal,
        public string $expectedCash,
        public ?string $countedCash,
        public ?string $variance,
        public string $currency,
        public string $openedAt,
        public ?string $closedAt,
    ) {}
}
