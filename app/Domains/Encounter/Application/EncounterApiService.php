<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application;

use App\Domains\Encounter\Application\Command\RecordEncounterRelationship;
use App\Domains\Encounter\Application\Query\GetEncounter;
use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Encounter\Domain\EncounterReader;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;

/**
 * Thin port implementation (IC §5.2).
 *
 * `encounterExists` is an unprotected reference-style read — it delegates
 * straight to the Reader (IC §6.2's reference-read carve-out: no bus ceremony
 * for data that carries no PHI), matching `PatientApiService::patientExists`.
 *
 * `getEncounterContext` (E.13; ADR-135) crosses the `QueryBus` via the existing
 * E.12 `GetEncounter` query — it exposes the patient link + facility + lifecycle
 * state (more than "mere existence"), so the bus's `#[ProtectedQuery]`
 * authorization + the `GetEncounterHandler` facility refinement must never be
 * skipped (the `PatientApiService::getPatientSummary` precedent). It adds no new
 * query, reader, policy, repository, migration or read model — pure delegation,
 * mapping `EncounterSummary` down to the five ADR-135 fields.
 */
final class EncounterApiService implements EncounterApi
{
    public function __construct(
        private readonly EncounterReader $reader,
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
    ) {}

    public function encounterExists(string $encounterId): bool
    {
        return $this->reader->exists($encounterId);
    }

    public function recordEncounterRelationship(
        string $relationshipId,
        string $encounterId,
        string $relatedEncounterId,
        string $relationshipType,
        string $actorId,
    ): void {
        $this->commandBus->dispatch(new RecordEncounterRelationship(
            relationshipId: $relationshipId,
            encounterId: $encounterId,
            relatedEncounterId: $relatedEncounterId,
            relationshipType: $relationshipType,
            actorId: $actorId,
        ));
    }

    public function getEncounterContext(string $encounterId): EncounterContext
    {
        /** @var EncounterSummary $summary */
        $summary = $this->queryBus->dispatch(new GetEncounter($encounterId));

        return new EncounterContext(
            encounterId: $summary->encounterId,
            patientId: $summary->patientId,
            facilityId: $summary->facilityId,
            status: $summary->status,
            encounterType: $summary->encounterType,
        );
    }

    public function findActiveEncounterForPatient(string $facilityId, string $patientId): ?EncounterSummary
    {
        return $this->reader->activeForPatient($facilityId, $patientId);
    }

    public function activeEncountersForFacility(
        string $facilityId,
        array $statuses = ['ARRIVED'],
        int $limit = 200,
    ): array {
        return $this->reader->forFacility($facilityId, null, $statuses, $limit);
    }
}
