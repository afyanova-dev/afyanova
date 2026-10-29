<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\ReceiptSummary;

/**
 * Read-side port for receipts (IC §6.2; ADR-191 B5-C) — the reprint source
 * (§93). Returns DTOs only.
 */
interface ReceiptReader
{
    /** Throws `App\Domains\Billing\Domain\Exception\ReceiptNotFound` if the payment has none. */
    public function forPayment(string $paymentId): ReceiptSummary;
}
