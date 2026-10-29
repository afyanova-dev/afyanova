<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A claim cannot be superseded because it is not in SUBMITTED status (ADR-204).
 * Only historically submitted claims may be superseded by a replacement claim.
 * Maps to HTTP 422.
 */
final class ClaimNotSubmitted extends DomainException
{
    public static function forClaim(string $claimId, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot supersede claim [%s] in status [%s]; only SUBMITTED claims can be superseded.',
            $claimId,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'claim_not_submitted';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
