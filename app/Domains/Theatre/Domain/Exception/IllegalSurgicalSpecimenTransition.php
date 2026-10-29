<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A surgical-specimen chain-of-custody transition was illegal
 * (17-theatre-domain.md §74, §318; ADR-164 D-B). Maps to HTTP 409.
 */
final class IllegalSurgicalSpecimenTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on surgical specimen currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_surgical_specimen_transition';
    }
}
