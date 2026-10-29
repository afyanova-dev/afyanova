<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * SurgicalProcedure status transition was illegal (17-theatre-domain.md §10;
 * ADR-156 D-C). Maps to HTTP 409.
 */
final class IllegalSurgicalProcedureTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on surgical procedure currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_surgical_procedure_transition';
    }
}
