<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\PatientRelationshipRepository;
use App\Domains\Patient\Domain\PatientRepository;

/**
 * PatientRelationshipRepository is listed first so
 * AuthorizeCommand::ownerRepositoryType() (IC §5.1) resolves the subject
 * aggregate as PatientRelationship. PatientRepository enforces ADR-128 M-9.
 */
final class RemovePatientRelationshipHandler
{
    public function __construct(
        private readonly PatientRelationshipRepository $relationships,
        private readonly PatientRepository $patients,
    ) {}

    public function __invoke(RemovePatientRelationship $command): void
    {
        $relationship = $this->relationships->getById($command->relationshipId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->patients->getById((string) $relationship->patient_id)->assertMutable(); // ADR-128 M-9 (blocks MERGED)

        $relationship->deactivate($command->actorId); // ADR-126 guard; records PatientRelationshipRemoved
        $this->relationships->save($relationship);
    }
}
