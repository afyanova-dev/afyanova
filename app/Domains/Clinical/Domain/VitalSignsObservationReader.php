<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;

/**
 * Read-side port for VitalSignsObservation (IC §6.2 / §6.3). Implementation:
 * `Infrastructure/Reader/EloquentVitalSignsObservationReader`. Returns a DTO
 * only — never an Eloquent model (IC §9).
 *
 * Only `summary` (backing the `GetVitalSignsObservation` protected query,
 * ADR-138 D15) and `forPatient` (backing the `GetPatientVitalSigns` protected
 * list query) — C.2 publishes no `ClinicalApi` method, so there is no
 * reference-style existence read. Grows per concrete consumer.
 */
interface VitalSignsObservationReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the observation does not exist in
     * the current tenant scope (RLS). No facility-scope check here — that is
     * `GetVitalSignsObservationHandler`'s per-caller refinement (ADR-133 / the
     * E.12 R-1 pattern).
     */
    public function summary(string $vitalSignsObservationId): VitalSignsObservationSummary;

    /**
     * One patient's observations, newest first, confined to the given
     * in-scope facilities (empty scope yields no rows — never an exception).
     *
     * @param  list<string>  $inScopeFacilityIds
     * @return list<VitalSignsObservationSummary>
     */
    public function forPatient(string $patientId, array $inScopeFacilityIds): array;

    /**
     * Encounter ids with a current vital observation in the given facilities.
     *
     * @param  list<string>  $encounterIds
     * @param  list<string>  $facilityIds
     * @return list<string>
     */
    public function recordedEncounterIds(array $encounterIds, array $facilityIds): array;
}
