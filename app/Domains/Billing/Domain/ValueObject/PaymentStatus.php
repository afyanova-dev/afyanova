<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * Status set for a Payment (20-billing-and-payments-domain.md §61 / §297;
 * ADR-191 B5-C):
 *
 *   RECORDED — confirmed money received; the ONLY creation state (no PENDING /
 *              AUTHORIZED / FAILED — Bill.5 records confirmed cash only).
 *   REVERSED — terminal; the accounting effect was undone (§70). A compensating
 *              ledger DEBIT + the historical row + the reversal metadata stay.
 *
 * A REFUND (§71 — money returned to the payer) is a distinct concern with its
 * own aggregate + workflow, deferred past Bill.5. Backend-only string-constant
 * holder.
 */
final class PaymentStatus
{
    public const RECORDED = 'RECORDED';

    public const REVERSED = 'REVERSED';

    /** @var list<string> */
    public const ALL = [self::RECORDED, self::REVERSED];
}
