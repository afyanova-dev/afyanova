<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Bed already has an active occupancy (24-bed-and-ward-management-domain.md §7 Inv 2;
 * ADR-147 D-D, ADR-148 D-C). Maps to HTTP 409.
 */
final class BedAlreadyOccupied extends DomainException
{
    public static function forBed(string $bedId): self
    {
        return new self(sprintf(
            'Bed [%s] already has an active occupancy (Invariant 2; ADR-148 D-C).',
            $bedId,
        ));
    }

    public function errorCode(): string
    {
        return 'bed_already_occupied';
    }
}
