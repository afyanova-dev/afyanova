<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class SlotHoldExpiredException extends DomainException
{
    public static function forSlot(string $id): self
    {
        return new self("Slot hold on [{$id}] has expired.");
    }
}
