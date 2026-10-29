<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;

/**
 * Read-side port for ClinicalProcedure (IC §6.2 / §6.3; ADR-146 D-Q).
 * Implementation: `Infrastructure/Reader/EloquentClinicalProcedureReader`.
 * Returns DTOs only — never an Eloquent model (IC §9).
 */
interface ClinicalProcedureReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the procedure does not exist in
     * the current tenant scope (RLS). No facility-scope check here — that is
     * `GetProcedureHandler`'s per-caller refinement (the E.12 R-1 pattern).
     */
    public function summary(string $procedureId): ProcedureSummary;

    /**
     * One encounter's procedures, filtered to procedures whose ORIGINATING
     * `facility_id` is in `$inScopeFacilityIds` (ADR-146 D-Q). Deterministic
     * ordering (`occurred_at`, then `id`); NO pagination.
     *
     * @param  list<string>  $inScopeFacilityIds
     * @return list<ProcedureSummary>
     */
    public function forEncounter(string $encounterId, array $inScopeFacilityIds): array;

    /**
     * One patient's procedure history across encounters, filtered to
     * procedures whose ORIGINATING `facility_id` is in `$inScopeFacilityIds`
     * (ADR-146 D-Q). Deterministic ordering (`occurred_at`, then `id`); NO
     * pagination.
     *
     * @param  list<string>  $inScopeFacilityIds
     * @return list<ProcedureSummary>
     */
    public function forPatient(string $patientId, array $inScopeFacilityIds): array;
}
