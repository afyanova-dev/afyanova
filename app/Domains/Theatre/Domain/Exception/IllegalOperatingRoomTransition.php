<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * OperatingRoom status transition was illegal (17-theatre-domain.md §18;
 * ADR-150 D-C). Maps to HTTP 409.
 */
final class IllegalOperatingRoomTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on operating room currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_operating_room_transition';
    }
}
