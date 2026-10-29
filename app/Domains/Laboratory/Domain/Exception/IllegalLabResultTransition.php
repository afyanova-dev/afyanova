<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A laboratory-result status transition was illegal (14-laboratory-domain.md
 * §57, §157; ADR-159 D-E). Maps to HTTP 409.
 */
final class IllegalLabResultTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on laboratory result currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_lab_result_transition';
    }
}
