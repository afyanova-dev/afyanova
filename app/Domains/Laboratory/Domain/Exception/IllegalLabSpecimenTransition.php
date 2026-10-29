<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A laboratory-specimen status transition was illegal (14-laboratory-domain.md
 * §156; ADR-158 D-E). Maps to HTTP 409.
 */
final class IllegalLabSpecimenTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on specimen currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_lab_specimen_transition';
    }
}
