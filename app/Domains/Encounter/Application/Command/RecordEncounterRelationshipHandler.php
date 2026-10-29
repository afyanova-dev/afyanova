<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Model\EncounterRelationship;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `RecordEncounterRelationship` (ADR-151 D-C). Writes the plain
 * `enc_encounter_relationships` child row directly — not an AggregateRoot,
 * no domain event (the Room precedent).
 */
final class RecordEncounterRelationshipHandler
{
    public function __construct(
        private readonly EncounterRepository $encounters,
    ) {}

    public function __invoke(RecordEncounterRelationship $command): void
    {
        $encounter = $this->encounters->getById($command->encounterId);

        EncounterRelationship::create([
            'id' => $command->relationshipId,
            'tenant_id' => $encounter->tenant_id,
            'facility_id' => $encounter->facility_id,
            'encounter_id' => $encounter->id,
            'related_encounter_id' => $command->relatedEncounterId,
            'relationship_type' => strtoupper(trim($command->relationshipType)),
            'created_by_user_id' => $command->actorId,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
