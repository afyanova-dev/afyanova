<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Bed is not available for assignment or transfer (24-bed-and-ward-management-domain.md §7 Inv 10;
 * ADR-147 D-C, ADR-148 D-D). Maps to HTTP 409.
 */
final class BedNotAvailable extends DomainException
{
    public static function forStatus(string $bedId, string $currentStatus): self
    {
        return new self(sprintf(
            'Bed [%s] is in status [%s] — only AVAILABLE beds can be assigned or targeted for transfer.',
            $bedId,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'bed_not_available';
    }
}
