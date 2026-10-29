<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts;

use App\Domains\BedWard\Contracts\Dto\BedOccupancyRef;

/**
 * Published Bed & Ward Management port (ADR-147 D-H, ADR-148 D-K).
 *
 * Grown per concrete consumer (IC §26). `assignBed` added for ADR-150 D-H —
 * Theatre's `CompleteCase` recovery handoff — after finding that dispatching
 * `App\Domains\BedWard\Application\Command\AssignBed` directly from another
 * module violates the Contracts-only cross-module import rule (IC §2.1); a
 * module's own internal `CommandBus` dispatch is legal only from within that
 * same module, so the write must be exposed here instead.
 */
interface BedWardApi
{
    /**
     * Retrieve active occupancy reference for an encounter, if any.
     */
    public function activeOccupancyFor(string $encounterId): ?BedOccupancyRef;

    /**
     * Assign an encounter to a bed (ADR-148 D-F/D-G's `AssignBed`, exposed
     * for cross-module callers). Throws the same domain exceptions as
     * `AssignBed` itself (`BedNotAvailable`, `EncounterNotEligibleForBedAssignment`,
     * `BedAlreadyOccupied`, `EncounterAlreadyHasActiveOccupancy`).
     */
    public function assignBed(string $occupancyId, string $encounterId, string $bedId, string $actorId): void;
}
