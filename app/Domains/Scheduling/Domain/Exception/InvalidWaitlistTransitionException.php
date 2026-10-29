<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class InvalidWaitlistTransitionException extends DomainException
{
    public static function from(string $currentStatus, string $attemptedAction): self
    {
        return new self("Cannot perform [{$attemptedAction}] on waitlist entry in [{$currentStatus}] status.");
    }
}
