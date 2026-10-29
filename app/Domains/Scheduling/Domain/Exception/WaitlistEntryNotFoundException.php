<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class WaitlistEntryNotFoundException extends DomainException
{
    public static function forId(string $id): self
    {
        return new self("Waitlist entry [{$id}] not found.");
    }
}
