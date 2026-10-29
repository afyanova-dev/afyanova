<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An authorization command attempted an illegal status transition
 * (ADR-202 D-B). Maps to HTTP 409.
 */
final class IllegalAuthorizationTransition extends DomainException
{
    public static function fromStatus(string $command, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot execute [%s] on authorization in status [%s].',
            $command,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_authorization_transition';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
