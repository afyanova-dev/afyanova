<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `CreateClinicalNote` (the `CreateEncounterResult`
 * precedent). Carries the note id (client-minted, echoed back for confirmation)
 * and its `DRAFT` status.
 */
final readonly class CreateClinicalNoteResult
{
    public function __construct(
        public string $clinicalNoteId,
        public string $status,
    ) {}
}
