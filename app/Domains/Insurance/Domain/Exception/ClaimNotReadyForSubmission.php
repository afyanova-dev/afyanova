<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A claim cannot be submitted because readiness validation failed (ADR-204; domain 21 §217, §218).
 * Maps to HTTP 422.
 */
final class ClaimNotReadyForSubmission extends DomainException
{
    /**
     * @param  list<string>  $blockingReasons
     */
    public static function withReasons(string $claimId, array $blockingReasons): self
    {
        return new self(sprintf(
            'Claim [%s] is not ready for submission: %s.',
            $claimId,
            implode('; ', $blockingReasons),
        ));
    }

    public function errorCode(): string
    {
        return 'claim_not_ready_for_submission';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
