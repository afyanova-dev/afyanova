<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * SurgicalCase status transition was illegal (17-theatre-domain.md §299
 * Invariant 14; ADR-150 D-D). Maps to HTTP 409.
 */
final class IllegalSurgicalCaseTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on surgical case currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_surgical_case_transition';
    }
}
