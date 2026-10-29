<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A QC-run transition was illegal (14-laboratory-domain.md §83; ADR-163 D-E).
 * Maps to HTTP 409.
 */
final class IllegalQcRunTransition extends DomainException
{
    public static function alreadyReviewed(string $qcRunId): self
    {
        return new self(sprintf('QC run [%s] has already been reviewed.', $qcRunId));
    }

    public static function passingRunNeedsNoReview(string $qcRunId): self
    {
        return new self(sprintf('QC run [%s] passed — there is nothing to review.', $qcRunId));
    }

    public function errorCode(): string
    {
        return 'illegal_qc_run_transition';
    }
}
