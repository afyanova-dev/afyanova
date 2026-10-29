<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CorrectDiagnosis` was attempted against a diagnosis that has already been
 * superseded by a prior correction (12-clinical-domain.md §94; ADR-139 D4 —
 * the supersession metadata is a ONE-TIME mutation).
 *
 * Corrections chain forward: to further correct, supersede the CURRENT
 * (non-superseded) diagnosis in the chain, not an already-superseded one.
 * Maps to HTTP 409 (IC §15).
 */
final class DiagnosisAlreadySuperseded extends DomainException
{
    public static function for(string $diagnosisId, string $supersededByDiagnosisId): self
    {
        return new self(sprintf(
            'Diagnosis [%s] was already superseded by [%s] — correct the current diagnosis in the chain.',
            $diagnosisId,
            $supersededByDiagnosisId,
        ));
    }

    public function errorCode(): string
    {
        return 'diagnosis_already_superseded';
    }
}
