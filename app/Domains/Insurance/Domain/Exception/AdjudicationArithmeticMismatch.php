<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Adjudication line arithmetic validation failed:
 * claimed != approved + patient_resp + contractual_adj + denied.
 * Maps to HTTP 422.
 */
final class AdjudicationArithmeticMismatch extends DomainException
{
    public static function forLine(
        int $lineNumber,
        string $claimed,
        string $approved,
        string $patientResp,
        string $contractualAdj,
        string $denied,
    ): self {
        return new self(sprintf(
            'Adjudication line [%d] arithmetic mismatch: claimed [%s] != approved [%s] + patient [%s] + contractual [%s] + denied [%s].',
            $lineNumber,
            $claimed,
            $approved,
            $patientResp,
            $contractualAdj,
            $denied,
        ));
    }

    public function errorCode(): string
    {
        return 'adjudication_arithmetic_mismatch';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
