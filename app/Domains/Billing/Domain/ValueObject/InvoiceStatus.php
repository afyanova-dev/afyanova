<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * Status set for an Invoice (20-billing-and-payments-domain.md §36 / §296;
 * ADR-190 D-A):
 *
 *   DRAFT   — assembling lines; mutable.
 *   ISSUED  — a request for payment; number minted, lines + `net` FROZEN.
 *   VOIDED  — terminal; the document is withdrawn (§136). History preserved.
 *
 * `PARTIALLY_PAID` / `PAID` are NOT Bill.4 states — they are a Bill.5-derived
 * figure from payment allocation (§139). `OVERDUE` needs a payment-terms model
 * (later). Backend-only string-constant holder.
 */
final class InvoiceStatus
{
    public const DRAFT = 'DRAFT';

    public const ISSUED = 'ISSUED';

    public const VOIDED = 'VOIDED';

    /** @var list<string> */
    public const ALL = [self::DRAFT, self::ISSUED, self::VOIDED];

    /** DRAFT is the only status in which lines / totals may change. */
    public const MUTABLE = [self::DRAFT];

    /** VoidInvoice acts on these. */
    public const VOIDABLE = [self::DRAFT, self::ISSUED];
}
