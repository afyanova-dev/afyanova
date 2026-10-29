<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle status set for the Financial Account
 * (20-billing-and-payments-domain.md §300 / §301; ADR-187 D-B):
 * OPEN | ACTIVE | SETTLED | CLOSED.
 *
 * OPEN     — created; no posted transactions yet.
 * ACTIVE   — has at least one posted ledger entry (set by Bill.3's first charge).
 * SETTLED  — outstanding balance is zero after activity (set by Bill.4/Bill.5).
 * CLOSED   — terminal; the account is closed. History stays queryable (§301).
 *
 * Bill.1 only ever holds OPEN or CLOSED (no charge / payment writer yet); the
 * ACTIVE / SETTLED transitions arrive with their first callers. Backend-only
 * string-constant holder (NOT a PHP enum, NOT #[TypeScript]).
 */
final class FinancialAccountStatus
{
    public const OPEN = 'OPEN';

    public const ACTIVE = 'ACTIVE';

    public const SETTLED = 'SETTLED';

    public const CLOSED = 'CLOSED';

    /** @var list<string> */
    public const ALL = [
        self::OPEN,
        self::ACTIVE,
        self::SETTLED,
        self::CLOSED,
    ];

    /**
     * Terminal states — excluded from the "one non-closed account per patient
     * per facility" rule (must match the partial unique index predicate
     * `status <> 'CLOSED'` on `bill_financial_accounts`).
     *
     * @var list<string>
     */
    public const TERMINAL = [
        self::CLOSED,
    ];

    /** Statuses a `CloseFinancialAccount` may act on. */
    public const CLOSABLE = [
        self::OPEN,
        self::ACTIVE,
        self::SETTLED,
    ];

    public static function assert(string $value): string
    {
        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid FinancialAccountStatus [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
