<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * BedOccupancy status transition was illegal (24-bed-and-ward-management-domain.md §8.2;
 * ADR-147 D-C, ADR-148 D-D). Maps to HTTP 409.
 */
final class IllegalOccupancyTransition extends DomainException
{
    public static function forAction(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on occupancy currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_occupancy_transition';
    }
}
