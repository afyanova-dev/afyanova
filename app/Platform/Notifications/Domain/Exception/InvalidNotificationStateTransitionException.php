<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Exception;

use DomainException;

final class InvalidNotificationStateTransitionException extends DomainException
{
    public static function transition(string $from, string $to): self
    {
        return new self("Cannot transition notification from [{$from}] to [{$to}].");
    }
}
