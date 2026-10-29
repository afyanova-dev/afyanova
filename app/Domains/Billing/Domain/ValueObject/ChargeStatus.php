<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * Status set for a Charge (20-billing-and-payments-domain.md §299; ADR-189 D-C):
 * POSTED | REVERSED.
 *
 * PENDING (§243 — the future estimate path) is not modelled — Bill.3 creates a
 * Charge POSTED directly from a completed dispensing. Backend-only
 * string-constant holder.
 */
final class ChargeStatus
{
    public const POSTED = 'POSTED';

    public const REVERSED = 'REVERSED';

    /** @var list<string> */
    public const ALL = [self::POSTED, self::REVERSED];
}
