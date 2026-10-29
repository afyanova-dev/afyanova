<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\PaymentSummary;

/**
 * Read-side port for payments (IC §6.2; ADR-191 B5-B). Returns DTOs only.
 */
interface PaymentReader
{
    /** Throws `App\Domains\Billing\Domain\Exception\PaymentNotFound` if absent (RLS). */
    public function summary(string $paymentId): PaymentSummary;

    /**
     * The payments on one Financial Account, newest first — also the soft
     * duplicate-payment check surface (§64; ADR-191 G-2 note).
     *
     * @return list<PaymentSummary>
     */
    public function forAccount(string $financialAccountId): array;
}
