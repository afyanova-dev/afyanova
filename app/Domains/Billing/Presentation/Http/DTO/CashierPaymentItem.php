<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One payment row on the cashier board — Bill.5 `PaymentSummary` trimmed to
 * receipt identity + method + settlement remainder.
 */
#[TypeScript]
final readonly class CashierPaymentItem
{
    public function __construct(
        public string $paymentId,
        public string $receiptNumber,
        public string $method,
        public string $amount,
        public string $status,
        public string $unallocatedAmount,
        public string $currency,
        public string $receivedAt,
    ) {}
}
