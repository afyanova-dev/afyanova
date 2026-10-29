<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A replacement claim cannot be cancelled because its creation atomically retired the
 * superseded claim's active lines (ADR-204; Invariant 7, domain 21 §272).
 * Maps to HTTP 422.
 */
final class ReplacementClaimCannotBeCancelled extends DomainException
{
    public static function forClaim(string $claimId): self
    {
        return new self(sprintf(
            'Replacement claim [%s] cannot be cancelled; doing so would abandon superseded charges.',
            $claimId,
        ));
    }

    public function errorCode(): string
    {
        return 'replacement_claim_cannot_be_cancelled';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
