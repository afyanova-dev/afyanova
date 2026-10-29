<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The `bill_account_ledger_entries.entry_type` set (20-billing-and-payments-domain.md
 * §81 / §290; ADR-187 D-C; ADR-191 G-1).
 *
 * FROZEN at seven values for the whole Bill.5–7 financial-control sequence — a
 * new fact reuses an existing type + a coded `source_type` (the Bill.3
 * `CHARGE` / `source_type 'CHARGE_REVERSAL'` precedent), it never adds an
 * eighth `entry_type`. Standalone `CREDIT` / `DEBIT` are reserved and unused by
 * Bill.5–7. Backend-only string-constant holder (NOT a PHP enum, NOT
 * `#[TypeScript]`).
 *
 * G-1 posting matrix (the sign a fact carries):
 *   Charge posted / reversed        CHARGE      DEBIT  / CREDIT
 *   Payment recorded / reversed     PAYMENT     CREDIT / DEBIT      (Bill.5)
 *   Write-off applied / reversed    WRITE_OFF   CREDIT / DEBIT      (Bill.7)
 *   Adjustment applied / reversed   ADJUSTMENT  CREDIT | DEBIT (signed)   (Bill.7)
 *   Refund disbursed                REFUND      DEBIT              (deferred — Bill.8+)
 */
final class LedgerEntryType
{
    public const CHARGE = 'CHARGE';

    public const PAYMENT = 'PAYMENT';

    public const CREDIT = 'CREDIT';

    public const DEBIT = 'DEBIT';

    public const WRITE_OFF = 'WRITE_OFF';

    public const REFUND = 'REFUND';

    public const ADJUSTMENT = 'ADJUSTMENT';

    /** @var list<string> */
    public const ALL = [
        self::CHARGE,
        self::PAYMENT,
        self::CREDIT,
        self::DEBIT,
        self::WRITE_OFF,
        self::REFUND,
        self::ADJUSTMENT,
    ];

    public static function assert(string $value): string
    {
        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ledger entry_type [%s]. Frozen set: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
