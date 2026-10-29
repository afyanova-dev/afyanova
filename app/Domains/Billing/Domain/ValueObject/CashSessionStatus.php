<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * Lifecycle status set for a Cash Session (20-billing-and-payments-domain.md
 * §153 / §154; ADR-191 C6-A):
 *
 *   OPEN       — a cashier opened the till with an opening float; no payments yet.
 *   ACTIVE     — the till has taken at least one payment (set by `RecordPayment`).
 *   CLOSED     — the drawer was counted; `expected_cash` / `counted_cash` /
 *                `variance` are FROZEN (§155 / §156).
 *   RECONCILED — a supervisor reviewed the variance (§157); terminal.
 *
 * `LIVE` = the partial-unique predicate — one live session per (cashier,
 * facility). Backend-only string-constant holder.
 */
final class CashSessionStatus
{
    public const OPEN = 'OPEN';

    public const ACTIVE = 'ACTIVE';

    public const CLOSED = 'CLOSED';

    public const RECONCILED = 'RECONCILED';

    /** @var list<string> */
    public const ALL = [self::OPEN, self::ACTIVE, self::CLOSED, self::RECONCILED];

    /** A session that may still take payments / be closed. */
    public const LIVE = [self::OPEN, self::ACTIVE];
}
