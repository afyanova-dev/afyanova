<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A claim was not found in the current tenant scope (ADR-204).
 * Maps to HTTP 404.
 */
final class ClaimNotFound extends DomainException
{
    public static function id(string $claimId): self
    {
        return new self(sprintf('Claim [%s] not found.', $claimId));
    }

    public function errorCode(): string
    {
        return 'claim_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
