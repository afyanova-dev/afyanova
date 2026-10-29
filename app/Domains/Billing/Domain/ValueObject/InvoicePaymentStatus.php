<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * The DERIVED payment status of an Invoice (20-billing-and-payments-domain.md
 * §139 / §296; ADR-190 D1 — never stored on the aggregate; ADR-191 B5-D).
 *
 *   UNPAID          — no active payment allocation.
 *   PARTIALLY_PAID  — some allocated, the effective obligation not yet met.
 *   PAID            — the effective obligation (net − reversed charges) is met.
 *
 * Computed by `InvoiceReader` from `bill_payment_allocations` + the invoice's
 * reversed-charge lines. Backend-only string-constant holder.
 */
final class InvoicePaymentStatus
{
    public const UNPAID = 'UNPAID';

    public const PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const PAID = 'PAID';

    /** @var list<string> */
    public const ALL = [self::UNPAID, self::PARTIALLY_PAID, self::PAID];
}
