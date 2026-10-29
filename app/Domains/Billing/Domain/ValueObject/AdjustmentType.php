<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded type of a post-charge account-level financial adjustment
 * (20-billing-and-payments-domain.md §81 / §82 / §137 / §138; ADR-191 D7-D).
 *
 * Each type maps DETERMINISTICALLY to a ledger direction: `ADMIN_DEBIT` (§138 —
 * an additional obligation / debit note) is a `DEBIT` that RAISES the balance;
 * every other type is a `CREDIT` that LOWERS it (a correction / goodwill in the
 * patient's favour). An adjustment that raises the balance for a
 * price-correction / rounding reason is filed as `ADMIN_DEBIT` with a note.
 *
 * Bill.7 adjustments are ACCOUNT-LEVEL money only — point-of-charge DISCOUNT,
 * price override at capture, and NO_CHARGE (§168 / §169) are OUT of scope (a
 * later pricing / override increment amending Bill.2 / Bill.3).
 */
final class AdjustmentType
{
    public const ADMIN_CREDIT = 'ADMIN_CREDIT';

    public const ADMIN_DEBIT = 'ADMIN_DEBIT';

    public const GOODWILL = 'GOODWILL';

    public const ROUNDING = 'ROUNDING';

    public const PRICE_CORRECTION = 'PRICE_CORRECTION';

    public const CONTRACTUAL_ALLOWANCE = 'CONTRACTUAL_ALLOWANCE';

    public const OTHER = 'OTHER';

    /** @var list<string> */
    public const ALL = [
        self::ADMIN_CREDIT,
        self::ADMIN_DEBIT,
        self::GOODWILL,
        self::ROUNDING,
        self::PRICE_CORRECTION,
        self::CONTRACTUAL_ALLOWANCE,
        self::OTHER,
    ];

    public static function assert(string $type): string
    {
        $type = strtoupper(trim($type));

        if (! in_array($type, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid adjustment type [%s]. Permitted: %s (§81 / ADR-191 D7-D).',
                $type,
                implode(', ', self::ALL),
            ));
        }

        return $type;
    }

    /** The ledger direction the type posts: `DEBIT` raises the balance, `CREDIT` lowers it. */
    public static function direction(string $type): string
    {
        return $type === self::ADMIN_DEBIT ? 'DEBIT' : 'CREDIT';
    }
}
