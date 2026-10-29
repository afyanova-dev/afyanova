<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * Status set for a captured billable event
 * (20-billing-and-payments-domain.md §10 / §126; ADR-189 D-B):
 *
 *   PENDING    — captured but not yet charged (a `pending_reason` says why).
 *   CHARGED    — a bill_charges row exists.
 *   REVERSED   — the charge was reversed (the source dispensing was reversed).
 *   CANCELLED  — reversed while still PENDING (nothing was charged — §112).
 *
 * Backend-only string-constant holder.
 */
final class BillableEventStatus
{
    public const PENDING = 'PENDING';

    public const CHARGED = 'CHARGED';

    public const REVERSED = 'REVERSED';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [self::PENDING, self::CHARGED, self::REVERSED, self::CANCELLED];

    /** @var list<string> — a re-delivered / retried event on one of these is a no-op. */
    public const TERMINAL = [self::CHARGED, self::REVERSED, self::CANCELLED];
}
