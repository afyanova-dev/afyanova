<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `RecordVitalSigns` (the `CreateClinicalNoteResult`
 * precedent) — the client-minted observation id, echoed back for confirmation.
 */
final readonly class RecordVitalSignsResult
{
    public function __construct(
        public string $vitalSignsObservationId,
    ) {}
}
