<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A claim cannot be adjudicated because an active adjudication already exists (ADR-205).
 * Maps to HTTP 422.
 */
final class ClaimAlreadyAdjudicated extends DomainException
{
    public static function forClaim(string $claimId, string $adjudicationId): self
    {
        return new self(sprintf(
            'Claim [%s] has already been adjudicated under adjudication [%s].',
            $claimId,
            $adjudicationId,
        ));
    }

    public function errorCode(): string
    {
        return 'claim_already_adjudicated';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
