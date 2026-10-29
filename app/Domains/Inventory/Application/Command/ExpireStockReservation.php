<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * System-only entry point for the `inventory:expire-reservations` sweep
 * (18-inventory-and-warehousing-domain.md §114, Inv 18; ADR-180 D-E). Releases
 * an ACTIVE reservation whose `hold_until` has lapsed.
 *
 * Not exposed to a human actor — no permission key, plain command (the
 * `ExpireBedReservation` / `ExpireContract` / IC §7.3 system-command precedent),
 * never dispatched from an HTTP path. No `idempotencyKey` — a re-dispatch is a
 * handler no-op.
 */
final readonly class ExpireStockReservation
{
    public function __construct(
        public string $reservationId,
    ) {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('ExpireStockReservation::$reservationId must be a UUID (IC §3).');
        }
    }
}
