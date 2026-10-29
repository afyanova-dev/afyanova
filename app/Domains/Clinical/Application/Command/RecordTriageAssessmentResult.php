<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `RecordTriageAssessment` (the `RecordDiagnosisResult`
 * precedent) — the client-minted triage assessment id, echoed back for
 * confirmation.
 */
final readonly class RecordTriageAssessmentResult
{
    public function __construct(
        public string $triageAssessmentId,
    ) {}
}
