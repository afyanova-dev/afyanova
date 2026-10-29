<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * BedReservation status transition was illegal (24-bed-and-ward-management-domain.md §8.3;
 * ADR-149 D-B). Maps to HTTP 409.
 */
final class IllegalBedReservationTransition extends DomainException
{
    public static function forAction(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on reservation currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public static function expired(string $reservationId): self
    {
        return new self(sprintf(
            'Reservation [%s] has passed its hold_until and was expired.',
            $reservationId,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_bed_reservation_transition';
    }
}
