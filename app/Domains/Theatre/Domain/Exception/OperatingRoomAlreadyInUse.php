<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Operating room already has an active (READY|IN_PROGRESS) surgical case
 * (17-theatre-domain.md §299 Invariant 13; ADR-150 D-E). Maps to HTTP 409.
 */
final class OperatingRoomAlreadyInUse extends DomainException
{
    public static function forRoom(string $roomId): self
    {
        return new self(sprintf(
            'Operating room [%s] already has an active surgical case.',
            $roomId,
        ));
    }

    public function errorCode(): string
    {
        return 'operating_room_already_in_use';
    }
}
