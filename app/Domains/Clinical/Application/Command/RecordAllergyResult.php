<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `RecordAllergy` (the `AddProblemResult` /
 * `RecordDiagnosisResult` precedent) — the client-minted allergy id, echoed
 * back for confirmation.
 */
final readonly class RecordAllergyResult
{
    public function __construct(
        public string $allergyId,
    ) {}
}
