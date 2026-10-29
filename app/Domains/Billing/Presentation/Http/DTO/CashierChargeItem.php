<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One uninvoiced charge row — the "what can be invoiced" worklist
 * (`GetUninvoicedCharges`: POSTED + not reversed + not claimed by a live line).
 */
#[TypeScript]
final readonly class CashierChargeItem
{
    public function __construct(
        public string $chargeId,
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $amount,
        public string $status,
        public string $currency,
        public string $occurredAt,
    ) {}
}
