<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\ValueObject;

/**
 * Lifecycle status set for the Medication Pickup (16-pharmacy-domain.md §29;
 * ADR-186 D-D).
 *
 *   READY_FOR_PICKUP  — auto-created when the Dispensing completes; awaiting collection.
 *   PICKED_UP         — terminal; the recipient collected the medication.
 *   NOT_COLLECTED     — non-terminal; not collected yet / the pharmacy gave up waiting
 *                       (may still reach PICKED_UP for a late collection).
 *   CANCELLED         — terminal; the pickup will not happen (dispensing reversed,
 *                       order cancelled, duplicate).
 *
 * Backend-only string-constant holder (NOT a PHP enum, NOT #[TypeScript]).
 */
final class PickupStatus
{
    public const READY_FOR_PICKUP = 'READY_FOR_PICKUP';

    public const PICKED_UP = 'PICKED_UP';

    public const NOT_COLLECTED = 'NOT_COLLECTED';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::READY_FOR_PICKUP,
        self::PICKED_UP,
        self::NOT_COLLECTED,
        self::CANCELLED,
    ];
}
