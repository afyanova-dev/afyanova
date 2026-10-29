<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Domains\HR\Domain\ValueObject\RosterEntryStatus;
use App\Shared\Domain\DomainException;

final class InvalidRosterStatusTransitionException extends DomainException
{
    public static function forTransition(RosterEntryStatus $current, RosterEntryStatus $target): self
    {
        return new self(sprintf(
            'Cannot transition roster entry from status [%s] to status [%s].',
            $current->value,
            $target->value
        ));
    }

    public function errorCode(): string
    {
        return 'invalid_roster_status_transition';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
