<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Bed status transition was illegal (24-bed-and-ward-management-domain.md §8.1;
 * ADR-147 D-C, ADR-148 D-D). Maps to HTTP 409.
 */
final class IllegalBedTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on bed currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public static function cannotTransition(string $fromStatus, string $toStatus): self
    {
        return new self(sprintf(
            'Cannot transition bed from status [%s] to status [%s].',
            $fromStatus,
            $toStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_bed_transition';
    }
}
