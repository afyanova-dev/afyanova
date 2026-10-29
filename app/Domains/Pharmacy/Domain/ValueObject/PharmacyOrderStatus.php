<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle status set for the Pharmacy Order
 * (16-pharmacy-domain.md §122 / §249; ADR-182 D-C + ADR-184 D-C):
 * RECEIVED | VALIDATED | REJECTED | ON_HOLD | CANCELLED |
 * PARTIALLY_DISPENSED | DISPENSED.
 *
 * RECEIVED           — created from an ACTIVE prescription; awaiting validation.
 * VALIDATED          — a pharmacist attested the prescription is cleared for fulfilment.
 * REJECTED           — terminal; the pharmacist rejected it (coded reason).
 * ON_HOLD            — parked; releasing it returns to RECEIVED (must re-validate).
 * CANCELLED          — terminal; administratively cancelled.
 * PARTIALLY_DISPENSED — one or more dispensing events; more remains (ADR-184 D-C).
 * DISPENSED          — terminal; the authorised quantity has been fully dispensed.
 *
 * `READY` is folded into VALIDATED for Pharm.3 (no explicit "ready" step);
 * `DISPENSING` / `REVERSED` are not modelled (Pharm.3 dispenses atomically).
 * Backend-only string-constant holder (NOT a PHP enum, NOT #[TypeScript]).
 */
final class PharmacyOrderStatus
{
    public const RECEIVED = 'RECEIVED';

    public const VALIDATED = 'VALIDATED';

    public const REJECTED = 'REJECTED';

    public const ON_HOLD = 'ON_HOLD';

    public const CANCELLED = 'CANCELLED';

    public const PARTIALLY_DISPENSED = 'PARTIALLY_DISPENSED';

    public const DISPENSED = 'DISPENSED';

    /** @var list<string> */
    public const ALL = [
        self::RECEIVED,
        self::VALIDATED,
        self::REJECTED,
        self::ON_HOLD,
        self::CANCELLED,
        self::PARTIALLY_DISPENSED,
        self::DISPENSED,
    ];

    /**
     * Terminal states — excluded from the "one non-terminal order per
     * prescription" rule (must match the partial unique index predicate on
     * `rx_pharmacy_orders`). A DISPENSED order is NOT here: it stays "the one
     * order" for its prescription, so a second order (a cross-order §112 leak)
     * cannot be created — a refill is a new prescription (§132; ADR-181 C7).
     */
    public const TERMINAL = [
        self::REJECTED,
        self::CANCELLED,
    ];

    /** Statuses a `Dispense` may act on (ADR-184 D-C). */
    public const DISPENSABLE = [
        self::VALIDATED,
        self::PARTIALLY_DISPENSED,
    ];

    public static function assert(string $value): string
    {
        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid PharmacyOrderStatus [%s]. Permitted: %s.',
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
