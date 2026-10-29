<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One invoice row on the cashier board — Bill.4 `InvoiceSummary` trimmed to
 * document identity + derived settlement figures (all reader-derived, §139).
 */
#[TypeScript]
final readonly class CashierInvoiceItem
{
    public function __construct(
        public string $invoiceId,
        public ?string $invoiceNumber,
        public string $status,
        public string $net,
        public string $paidAmount,
        public string $paymentStatus,
        public string $derivedOutstanding,
        public string $currency,
        public int $lineCount,
        public ?string $issuedAt,
    ) {}
}
