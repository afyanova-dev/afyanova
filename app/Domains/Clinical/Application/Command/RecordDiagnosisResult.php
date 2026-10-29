<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `RecordDiagnosis` (the `CreateClinicalNoteResult` /
 * `RecordVitalSignsResult` precedent) — the client-minted diagnosis id, echoed
 * back for confirmation.
 */
final readonly class RecordDiagnosisResult
{
    public function __construct(
        public string $diagnosisId,
    ) {}
}
