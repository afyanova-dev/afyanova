<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class SlotNotAvailableException extends DomainException
{
    public static function forSlot(string $id, string $status): self
    {
        return new self("Slot [{$id}] is not available for booking/hold (current status: [{$status}]).");
    }
}
