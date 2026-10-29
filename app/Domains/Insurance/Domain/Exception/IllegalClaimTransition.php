<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An attempted claim lifecycle transition is illegal (ADR-204).
 * Maps to HTTP 422.
 */
final class IllegalClaimTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot [%s] claim in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_claim_transition';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
