<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `AddProcedureNote` — the id of the `ClinicalNote`
 * created and linked (ADR-146 D-L).
 */
final readonly class AddProcedureNoteResult
{
    public function __construct(
        public string $clinicalNoteId,
    ) {}
}
