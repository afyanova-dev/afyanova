<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain;

use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use Carbon\CarbonImmutable;

/**
 * Read-side port for Encounter (IMPLEMENTATION-CONTRACTS.md §6.2 / §6.3).
 * Implementation: Infrastructure/Reader/EloquentEncounterReader. Returns
 * scalars / DTOs only — never an Eloquent model (IC §9).
 *
 * - `exists` — backs the `EncounterApi::encounterExists` reference-style read
 *   (no PHI, no bus — IC §6.2 carve-out), since E.1.
 * - `summary` — backs the `GetEncounter` protected query (IC §6.2; ADR-130 —
 *   "GetEncounter is built for the cross-domain test / EncounterApi need"),
 *   since E.12.
 * - `forFacility` — backs the `ListFacilityEncounters` protected collection query
 *   (11-encounter-domain.md §99, §102; ADR-130), since E.13.
 *
 * Grows per concrete consumer.
 */
interface EncounterReader
{
    public function exists(string $encounterId): bool;

    /**
     * Throws App\Shared\Domain\NotFound if the encounter does not exist in the
     * current tenant scope (RLS). No facility-scope check here — that is
     * GetEncounterHandler's per-caller refinement (R-1, §114 / Invariant 24/25).
     */
    public function summary(string $encounterId): EncounterSummary;

    /**
     * Read facility encounters matching optional date and status filters.
     *
     * @param  list<EncounterStatus|string>|null  $statuses
     * @return list<EncounterSummary>
     */
    public function forFacility(
        string $facilityId,
        ?CarbonImmutable $date = null,
        ?array $statuses = null,
        int $limit = 100,
    ): array;

    /**
     * Count facility encounters with a recorded arrival on the given date.
     *
     * @param  list<EncounterStatus|string>|null  $statuses
     */
    public function countForFacility(
        string $facilityId,
        CarbonImmutable $date,
        ?array $statuses = null,
    ): int;

    /**
     * Read the active encounter (status ARRIVED or ACTIVE) for a patient at a facility, if one exists.
     * Backs ADR-121 Invariant 12b active attendance check.
     */
    public function activeForPatient(string $facilityId, string $patientId): ?EncounterSummary;

    /**
     * Read a patient's encounters at a facility, most recent first.
     * Backs the patient chart Visits section.
     *
     * @param  list<EncounterStatus|string>|null  $statuses
     * @return list<EncounterSummary>
     */
    public function forPatient(
        string $facilityId,
        string $patientId,
        ?array $statuses = null,
        int $limit = 100,
    ): array;
}
