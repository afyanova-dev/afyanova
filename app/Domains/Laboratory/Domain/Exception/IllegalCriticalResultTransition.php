<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A critical-result transition was illegal (14-laboratory-domain.md §160;
 * ADR-161 D-E). Maps to HTTP 409.
 */
final class IllegalCriticalResultTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on critical result currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public static function resultNotFlaggable(string $resultStatus): self
    {
        return new self(sprintf(
            'A result in status [%s] cannot be flagged critical — requires VERIFIED, RELEASED or AMENDED.',
            $resultStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_critical_result_transition';
    }
}
