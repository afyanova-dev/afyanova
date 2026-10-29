<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CorrectTriageAssessment` was attempted against a triage assessment that has
 * already been superseded by a prior correction / re-triage (ADR-145 D-F — the
 * supersession metadata is a ONE-TIME mutation).
 *
 * Corrections chain forward: to further correct / re-triage, supersede the
 * CURRENT (non-superseded) assessment in the chain, not an already-superseded
 * one. Maps to HTTP 409 (IC §15). (The triage analogue of
 * `DiagnosisAlreadySuperseded`.)
 */
final class TriageAssessmentAlreadySuperseded extends DomainException
{
    public static function for(string $triageAssessmentId, string $supersededByTriageAssessmentId): self
    {
        return new self(sprintf(
            'TriageAssessment [%s] was already superseded by [%s] — correct the current assessment in the chain.',
            $triageAssessmentId,
            $supersededByTriageAssessmentId,
        ));
    }

    public function errorCode(): string
    {
        return 'triage_assessment_already_superseded';
    }
}
