<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * Status set for a Receipt (20-billing-and-payments-domain.md §91 / §93 / §94;
 * ADR-191 B5-C):
 *
 *   ISSUED   — the payment confirmation document; reprint = a query, never a
 *              new row (§93).
 *   REVERSED — the payment it confirms was reversed; the receipt row is
 *              PRESERVED and flagged, never deleted (§94 — the reversal stays
 *              traceable).
 *
 * Backend-only string-constant holder.
 */
final class ReceiptStatus
{
    public const ISSUED = 'ISSUED';

    public const REVERSED = 'REVERSED';

    /** @var list<string> */
    public const ALL = [self::ISSUED, self::REVERSED];
}
