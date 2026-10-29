<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * RecoveryHandoff status transition was illegal (17-theatre-domain.md §90;
 * ADR-156 D-D). Maps to HTTP 409.
 */
final class IllegalRecoveryHandoffTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on recovery handoff currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_recovery_handoff_transition';
    }
}
