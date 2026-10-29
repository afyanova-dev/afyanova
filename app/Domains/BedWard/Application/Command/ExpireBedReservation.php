<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * System-only expiry entry point for the optional, deferred hygiene job
 * (24-bed-and-ward-management-domain.md §16; ADR-149 D-C/D-F). Not exposed
 * to a human actor — no permission key, plain command (the event-originated
 * / system-command precedent, IC §7.3), never dispatched from an HTTP path.
 * `HoldBedReservation` and `AssignBed` already self-heal a lapsed hold on
 * touch; this command exists only for a future periodic sweep of
 * reservations nobody ever touches again.
 */
final readonly class ExpireBedReservation
{
    public function __construct(
        public string $reservationId,
    ) {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('ExpireBedReservation::$reservationId must be a UUID (IC §3).');
        }
    }
}
