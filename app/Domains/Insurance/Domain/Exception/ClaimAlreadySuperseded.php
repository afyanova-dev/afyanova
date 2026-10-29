<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;
use Throwable;

/**
 * A claim has already been superseded by a successor claim (ADR-204).
 * Single-successor invariant violation.
 * Maps to HTTP 409.
 */
final class ClaimAlreadySuperseded extends DomainException
{
    public static function forClaim(string $claimId, ?string $successorId = null, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Claim [%s] has already been superseded%s.',
                $claimId,
                $successorId !== null ? " by claim [{$successorId}]" : '',
            ),
            previous: $previous,
        );
    }

    public function errorCode(): string
    {
        return 'claim_already_superseded';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
