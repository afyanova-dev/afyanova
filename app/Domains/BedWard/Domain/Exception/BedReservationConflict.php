<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A bed already has an open (REQUESTED or HELD) reservation
 * (24-bed-and-ward-management-domain.md §7 (extended); ADR-149 D-D). Maps to HTTP 409.
 */
final class BedReservationConflict extends DomainException
{
    public static function forBed(string $bedId): self
    {
        return new self(sprintf(
            'Bed [%s] already has an open reservation.',
            $bedId,
        ));
    }

    public function errorCode(): string
    {
        return 'bed_reservation_conflict';
    }
}
