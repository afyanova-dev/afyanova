<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts;

use App\Domains\Encounter\Contracts\Dto\EncounterContext;

/**
 * The ONLY Encounter surface other modules may import (IC §2.1; ADR-124,
 * ADR-130, ADR-135). Published so downstream domains have an import target;
 * grows per concrete consumer — do not add a method without a caller (IC §26).
 *
 * `recordEncounterRelationship` (ADR-151 D-C) is the first WRITE this port
 * exposes — added after finding that a cross-module command dispatch (Care
 * Coordination calling Encounter's internal `RecordEncounterRelationship`
 * directly via the CommandBus) violates the Contracts-only import rule
 * (IC §2.1; the same fix Theatre's `BedWardApi::assignBed` needed).
 */
interface EncounterApi
{
    /** Unprotected existence check — no PHI exposed (mere existence). */
    public function encounterExists(string $encounterId): bool;

    /**
     * Record a relationship from `$encounterId` to `$relatedEncounterId`
     * (11-encounter-domain.md §172; ADR-151 D-C). First consumer: Care
     * Coordination's `LinkReferralEncounterHandler` (writes REFERRED_FROM).
     * Throws the same authorization/NotFound semantics as any other
     * CommandBus dispatch.
     */
    public function recordEncounterRelationship(
        string $relationshipId,
        string $encounterId,
        string $relatedEncounterId,
        string $relationshipType,
        string $actorId,
    ): void;

    /**
     * The encounter's cross-domain context — identity link + the two coded
     * lifecycle values a consumer gates on (ADR-135; E.13). First consumer:
     * `CreateClinicalNoteHandler` (Clinical C.1), for the patient cross-check,
     * the ADR-133 per-encounter-facility authorization refinement, and the
     * encounter-state gate.
     *
     * PROTECTED: delegates to the E.12 `GetEncounter` query, so the caller must
     * hold `encounter.encounters.read` and be scoped to the encounter's
     * facility. Throws `App\Shared\Domain\NotFound` when the encounter is
     * absent from the caller's tenant scope OR outside the caller's facility
     * scope (never an authorization disclosure).
     */
    public function getEncounterContext(string $encounterId): EncounterContext;

    /**
     * Find the active encounter (ARRIVED or ACTIVE) for a patient at a given facility.
     * Backs ADR-121 Invariant 12b active attendance check.
     */
    public function findActiveEncounterForPatient(string $facilityId, string $patientId): ?Dto\EncounterSummary;

    /**
     * Active encounters (ARRIVED by default) across a facility, oldest first.
     * First consumer: the Attention board's "arrived, not queued" list.
     *
     * @param  list<string>  $statuses
     * @return list<Dto\EncounterSummary>
     */
    public function activeEncountersForFacility(
        string $facilityId,
        array $statuses = ['ARRIVED'],
        int $limit = 200,
    ): array;
}
