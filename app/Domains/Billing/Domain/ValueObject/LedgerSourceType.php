<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * The coded `bill_account_ledger_entries.source_type` values — the KIND of the
 * originating row a ledger entry was posted for (20-billing-and-payments-domain.md
 * §86–§88; ADR-187 D-C; ADR-191 G-1 / G-2).
 *
 * Unlike {@see LedgerEntryType} this set GROWS one increment at a time (each
 * new fact adds its `_type` + its `_REVERSAL` counterpart). It is NOT asserted
 * in `LedgerPoster` — it is the reference list for `(tenant_id, source_type,
 * source_id)`, the G-2 per-fact idempotency key. `source_id` is the originating
 * aggregate's id (the charge / reversal / payment / write-off row).
 *
 * Bill.3 (shipped): CHARGE, CHARGE_REVERSAL.
 * Bill.5:  PAYMENT, PAYMENT_REVERSAL.
 * Bill.7:  WRITE_OFF, WRITE_OFF_REVERSAL, ADJUSTMENT, ADJUSTMENT_REVERSAL.
 * Deferred (Bill.8+): REFUND.
 */
final class LedgerSourceType
{
    public const CHARGE = 'CHARGE';

    public const CHARGE_REVERSAL = 'CHARGE_REVERSAL';

    public const PAYMENT = 'PAYMENT';

    public const PAYMENT_REVERSAL = 'PAYMENT_REVERSAL';

    public const WRITE_OFF = 'WRITE_OFF';

    public const WRITE_OFF_REVERSAL = 'WRITE_OFF_REVERSAL';

    public const ADJUSTMENT = 'ADJUSTMENT';

    public const ADJUSTMENT_REVERSAL = 'ADJUSTMENT_REVERSAL';

    /** @var list<string> the values a ledger row may currently carry */
    public const KNOWN = [
        self::CHARGE,
        self::CHARGE_REVERSAL,
        self::PAYMENT,
        self::PAYMENT_REVERSAL,
        self::WRITE_OFF,
        self::WRITE_OFF_REVERSAL,
        self::ADJUSTMENT,
        self::ADJUSTMENT_REVERSAL,
    ];
}
