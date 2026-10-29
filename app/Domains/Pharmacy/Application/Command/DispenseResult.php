<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

/**
 * The `Dispense` command result (ADR-184 D-H) — the ids a caller needs to read
 * back the dispensing.
 */
final readonly class DispenseResult
{
    public function __construct(
        public string $dispensingId,
        public string $dispensingNumber,
        public string $reservationId,
        public string $movementId,
        public string $orderStatus,
        public string $pickupId,
    ) {}
}
