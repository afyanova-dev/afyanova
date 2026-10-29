<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * OperativeNote status transition (or a content write after signing) was
 * illegal (17-theatre-domain.md §79–§81, §303 Invariant 31; ADR-152 D-D).
 * Maps to HTTP 409.
 */
final class IllegalOperativeNoteTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on operative note currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_operative_note_transition';
    }
}
