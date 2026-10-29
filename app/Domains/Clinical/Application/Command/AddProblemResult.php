<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `AddProblem` (the `RecordDiagnosisResult` /
 * `RecordVitalSignsResult` precedent) — the client-minted problem id, echoed
 * back for confirmation.
 */
final readonly class AddProblemResult
{
    public function __construct(
        public string $problemId,
    ) {}
}
