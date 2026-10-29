<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;

/**
 * Read-side port for ClinicalNote (IMPLEMENTATION-CONTRACTS.md §6.2 / §6.3).
 * Implementation: `Infrastructure/Reader/EloquentClinicalNoteReader`. Returns
 * scalars / DTOs only — never an Eloquent model (IC §9).
 *
 * - `exists` — backs the `ClinicalApi::clinicalNoteExists` reference-style read
 *   (no PHI, no bus — IC §6.2 carve-out).
 * - `summary` — backs the `GetClinicalNote` protected query (IC §6.2; ADR-134).
 *
 * Grows per concrete consumer.
 */
interface ClinicalNoteReader
{
    public function exists(string $clinicalNoteId): bool;

    /**
     * Throws `App\Shared\Domain\NotFound` if the note does not exist in the
     * current tenant scope (RLS). No facility-scope check here — that is
     * `GetClinicalNoteHandler`'s per-caller refinement (ADR-133; the E.12 R-1
     * pattern).
     */
    public function summary(string $clinicalNoteId): ClinicalNoteSummary;
}
