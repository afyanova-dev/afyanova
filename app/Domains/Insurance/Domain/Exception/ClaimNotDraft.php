<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An action requires the claim to be in DRAFT status (ADR-204).
 * Maps to HTTP 422.
 */
final class ClaimNotDraft extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot [%s] claim in status [%s]; claim must be DRAFT.',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'claim_not_draft';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
