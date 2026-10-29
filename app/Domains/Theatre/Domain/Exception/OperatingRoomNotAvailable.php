<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Operating room is not available for assignment (17-theatre-domain.md §18;
 * ADR-150 D-C). Maps to HTTP 409.
 */
final class OperatingRoomNotAvailable extends DomainException
{
    public static function forStatus(string $roomId, string $currentStatus): self
    {
        return new self(sprintf(
            'Operating room [%s] is in status [%s] — only AVAILABLE rooms can be assigned.',
            $roomId,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'operating_room_not_available';
    }
}
